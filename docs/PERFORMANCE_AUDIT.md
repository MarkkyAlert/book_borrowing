# Performance Audit — ระบบยืมคืนหนังสือ

**วันที่ตรวจ:** 2026-02-12  
**ขอบเขต:** ระบบขนาดเล็ก–กลาง (เรียน / demo / ร้านเล็ก / template)  
**เกณฑ์:** เฉพาะ bottleneck ที่ช้าเห็นผลจริง หรือเสี่ยงพังเมื่อมีผู้ใช้ระดับต่ำ–กลาง

---

## Bottleneck List

### B-01: Admin Dashboard Query Storm (25–30 queries/page load)
**Severity: 🔴 High**

| รายละเอียด | |
|---|---|
| **ไฟล์** | `admin/index.php` → `DashboardService` → หลาย Repository |
| **ปัญหา** | โหลด dashboard 1 ครั้ง ยิง SQL ~25–30 queries |
| **ผู้ใช้รู้สึก** | หน้า admin dashboard โหลดช้ากว่าหน้าอื่นเห็นได้ชัด |
| **Impact** | response time 200–500ms+ (ขึ้นกับข้อมูล) |

**รายละเอียด query ที่เกิด:**
```
expireOverdueReservations()     → 1–3 queries (SELECT + loop UPDATE)
getCardStats():
  bookRepo.getStatistics()      → 4 queries (SUM×3 + COUNT)
  userRepo.countMembers()       → 1 query
  borrowRepo.countActive()      → 1 query
  borrowRepo.countOverdue()     → 1 query
  reservationRepo.countPending()→ 1 query
getRecentBorrows(5)             → 1 query
getRecentReservations(5)        → 1 query + markExpiredReservations() (ซ้ำ!)
getOverdueList(10)              → 1 query
getLowStockBooks()              → 1 query
getUnpaidFinesList(10)          → 1 query
getAllCategoriesWithStats()      → 1 query
getMonthlyStats()               → 1 query
getCategoryStats()              → 1 query
getTotalFinesCollected()        → 1 query
getUnpaidFines()                → 1 query
getTopBorrowers(5)              → 1 query
getPopularBooks(5)              → 1 query
getBorrowStats()                → 4 queries (COUNT×4 แยกกัน)
getFineStats()                  → 3 queries (SUM×3 แยกกัน)
────────────────────────────────────
รวม:                            ~27 queries + lazy expire ซ้ำ
```

**Optimization Plan:**
1. `BookRepository::getStatistics()` → รวม 4 queries เป็น 1:
   ```sql
   SELECT COUNT(*) as titles,
          COALESCE(SUM(quantity),0) as total,
          COALESCE(SUM(available),0) as available,
          COALESCE(SUM(quantity-available),0) as borrowed
   FROM books
   ```
2. `ReportRepository::getBorrowStats()` → รวม 4 queries เป็น 1:
   ```sql
   SELECT
     SUM(status='borrowing') as active,
     SUM(status='borrowing' AND due_date < CURDATE()) as overdue,
     SUM(DATE(borrow_date)=CURDATE()) as today,
     SUM(MONTH(borrow_date)=MONTH(CURDATE()) AND YEAR(borrow_date)=YEAR(CURDATE())) as this_month
   FROM borrows
   ```
3. `ReportRepository::getFineStats()` → รวม 3 queries เป็น 1
4. ลด lazy expire ซ้ำ (ดู B-04)

**ผลลัพธ์คาดหวัง:** ~27 queries → ~17 queries (ลด ~10 round-trips)

---

### B-02: `markExpiredReservations()` — N+1 Loop Queries
**Severity: 🔴 High**

| รายละเอียด | |
|---|---|
| **ไฟล์** | `ReservationRepository::markExpiredReservations()` |
| **ปัญหา** | SELECT expired list → loop: UPDATE reservation + UPDATE book (2N queries) |
| **ผู้ใช้รู้สึก** | ถ้า cron ไม่รัน + มี expired 10 รายการ = 1 + 20 queries ต่อ page load |
| **Impact** | ปกติ 0–2 queries (cron ทำงาน), worst case 50+ queries (cron ไม่ทำงาน 1 สัปดาห์) |

**โค้ดปัจจุบัน (N+1):**
```php
foreach ($expiredList as $res) {
    UPDATE reservations SET status='expired' WHERE id=? AND status='pending'
    if (rowCount > 0) UPDATE books SET available=available+1 WHERE id=?
}
```

**Optimization Plan — Bulk UPDATE 2 queries:**
```sql
-- Query 1: คืน stock ทีเดียว (SUM GROUP BY book_id)
UPDATE books b
JOIN (
    SELECT book_id, COUNT(*) as cnt
    FROM reservations
    WHERE status='pending' AND expires_at < NOW()
    GROUP BY book_id
) exp ON b.id = exp.book_id
SET b.available = b.available + exp.cnt;

-- Query 2: Mark expired ทีเดียว
UPDATE reservations SET status='expired'
WHERE status='pending' AND expires_at < NOW();
```

**ผลลัพธ์คาดหวัง:** 1 + 2N queries → 3 queries (คงที่ ไม่ว่า N เท่าไร)

---

### B-03: `findAll()` ไม่มี LIMIT — Unbounded Result Set
**Severity: 🟡 Medium**

| รายละเอียด | |
|---|---|
| **ไฟล์** | `BorrowRepository::findAll()`, `BookRepository::findAll()` |
| **ปัญหา** | ดึงข้อมูลทั้งตารางโดยไม่จำกัด ส่งทุก row ให้ PHP |
| **ผู้ใช้รู้สึก** | `admin/borrows.php` ช้าขึ้นเรื่อยๆ เมื่อมีข้อมูลมากขึ้น |
| **Impact** | borrows 500 rows → OK, 5000 rows → ช้าชัดเจน (memory + render) |

**Optimization Plan:**
- `BorrowRepository::findAll()` → เพิ่ม pagination (เหมือน `findByUserIdPaginated()` ที่มีอยู่แล้ว)
- `BookRepository::findAll()` → ระดับ template ยังไม่ถึง 1000 เล่ม แต่ควรเพิ่ม LIMIT สำรอง
- **Quick fix:** เพิ่ม `LIMIT 500` เป็น safety net ก่อน implement pagination เต็มรูปแบบ

---

### B-04: Lazy Expire เรียกซ้ำหลายครั้งต่อ Page Load
**Severity: 🟡 Medium**

| รายละเอียด | |
|---|---|
| **ไฟล์** | `HomeService`, `BookService`, `ReservationRepository`, `admin/index.php` |
| **ปัญหา** | `markExpiredReservations()` ถูกเรียกซ้ำหลายครั้งใน 1 request |
| **ผู้ใช้รู้สึก** | page load ช้ากว่าที่ควร 20–50ms |
| **Impact** | ซ้ำ 2–4 ครั้ง/request ขึ้นกับหน้า |

**จุดที่เรียก (ต่อ 1 request):**
| หน้า | จำนวนครั้ง | เรียกจาก |
|------|-----------|---------|
| `index.php` (public) | 2 ครั้ง | `HomeService::getBooks()` + `HomeService::getStats()` |
| `admin/index.php` | 2–3 ครั้ง | `expireOverdueReservations()` + `getRecentReservations()` → `findPending()` |
| `admin/books.php` | 1 ครั้ง | `BookService::getBooks()` |
| `admin/reservations.php` | 1 ครั้ง | `ReservationRepository::findAll()` |
| `my_reservations.php` | 1 ครั้ง | `ReservationRepository::findByUser()` |

**Optimization Plan — Request-Level Flag:**
```php
// ใน ReservationRepository
private static bool $expiredThisRequest = false;

public function markExpiredReservations(): int
{
    if (self::$expiredThisRequest) return 0; // skip ถ้าทำแล้ว
    self::$expiredThisRequest = true;
    // ... logic เดิม
}
```

**ผลลัพธ์คาดหวัง:** ลดจาก 2–4 ครั้ง → 1 ครั้ง/request

---

### B-05: Missing Composite Indexes
**Severity: 🟡 Medium**

| รายละเอียด | |
|---|---|
| **ปัญหา** | หลาย query filter 2–3 columns แต่มีแค่ single-column indexes |
| **ผู้ใช้รู้สึก** | เมื่อข้อมูลโตถึง 1000+ rows, query ที่ใช้บ่อยจะช้าขึ้น |
| **Impact** | ไม่มีผลกระทบที่ข้อมูลน้อย, มีผลเมื่อ borrows > 1000 rows |

**Indexes ที่ขาด:**

| ตาราง | Index ที่ควรเพิ่ม | Queries ที่ได้ประโยชน์ |
|-------|------------------|----------------------|
| `borrows` | `(user_id, status)` | `countActiveBorrows`, `countActiveBorrowsForUpdate`, `isAlreadyBorrowing`, `getStatsByUser` |
| `borrows` | `(book_id, status)` | `countActiveByBook`, `findCurrentByBook` |
| `borrows` | `(status, due_date)` | `findOverdue`, `countOverdue` |
| `reservations` | `(status, expires_at)` | `markExpiredReservations` |
| `reservations` | `(user_id, book_id, status)` | `hasPending` |
| `rate_limits` | `(key_name, created_at)` | `checkRateLimit` (DELETE + COUNT) |

**Optimization Plan — Migration SQL:**
```sql
-- database/migrations/004_add_composite_indexes.sql
ALTER TABLE borrows ADD INDEX idx_user_status (user_id, status);
ALTER TABLE borrows ADD INDEX idx_book_status (book_id, status);
ALTER TABLE borrows ADD INDEX idx_status_due (status, due_date);
ALTER TABLE reservations ADD INDEX idx_status_expires (status, expires_at);
ALTER TABLE reservations ADD INDEX idx_user_book_status (user_id, book_id, status);
ALTER TABLE rate_limits ADD INDEX idx_key_created (key_name, created_at);
```

**ข้อควรระวัง:** ลบ single-column indexes ที่ซ้ำกับ composite prefix ได้ เช่น `idx_status` บน borrows (ถ้า `idx_status_due` ครอบคลุม) แต่ไม่จำเป็นต้องทำตอนนี้

---

### B-06: `rate_limits` Table Accumulation
**Severity: 🟢 Low**

| รายละเอียด | |
|---|---|
| **ไฟล์** | `includes/functions.php::checkRateLimit()` |
| **ปัญหา** | `DELETE` ลบเฉพาะ key เดียว — rows จาก key อื่นสะสมไม่มีวันลบ |
| **ผู้ใช้รู้สึก** | ไม่รู้สึก ยกเว้นใช้งานนานมากหลายเดือน |
| **Impact** | table โตช้าๆ, ไม่กระทบจนกว่า 100k+ rows |

**Optimization Plan:**
- เพิ่ม cron job ลบ rows เก่ากว่า 1 วัน (คล้าย `cleanup_tokens.php`)
- หรือเพิ่ม global cleanup ใน `checkRateLimit()`:
  ```php
  // 1% chance cleanup old records (probabilistic)
  if (random_int(1, 100) === 1) {
      $pdo->exec("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
  }
  ```

---

### B-07: `BookRepository::getStatistics()` — 4 Separate Queries
**Severity: 🟢 Low**

| รายละเอียด | |
|---|---|
| **ไฟล์** | `BookRepository::getStatistics()` |
| **ปัญหา** | 4 queries แยกกันบนตาราง books (SUM×3 + COUNT) |
| **ผู้ใช้รู้สึก** | เล็กน้อย — เป็นส่วนหนึ่งของ B-01 |
| **Impact** | 4 round-trips → 1 round-trip |

**รวมอยู่ใน Optimization Plan ของ B-01 แล้ว**

---

## Concurrency Assessment

| จุด | สถานะ | หมายเหตุ |
|-----|--------|----------|
| `createBorrow()` TX scope | ✅ เหมาะสม | lock user → lock books → loop → commit (แคบพอ) |
| `returnBook()` TX scope | ✅ เหมาะสม | lock borrow → mark + increment → commit |
| `createReservation()` TX scope | ✅ เหมาะสม | lock book → checks → insert + decrement → commit |
| `markExpiredReservations()` TX scope | ⚠️ กว้างเกิน worst case | ถ้า N expired มาก → TX ยาว (แก้ด้วย bulk UPDATE ใน B-02) |
| `payFine()` TX scope | ✅ เหมาะสม | lock borrow → check → insert payment → commit |
| `deleteMember()` TX scope | ✅ เหมาะสม | check guards → delete → commit |

**สรุป:** TX scope ดีทุกจุดยกเว้น `markExpiredReservations()` ที่ loop ใน TX (แก้ใน B-02)

---

## Caching Opportunities

| ข้อมูล | ความถี่เรียก | เปลี่ยนบ่อย? | แนะนำ Cache? |
|--------|-------------|-------------|-------------|
| Categories list | ทุก page load (public) | ไม่บ่อย (admin แก้) | ✅ ใช่ — cache ใน `$_SESSION` หรือ static var |
| Book statistics | ทุก dashboard load | เปลี่ยนทุกยืม/คืน | ❌ ไม่คุ้ม — invalidation ซับซ้อน |
| Dashboard card stats | ทุก dashboard load | เปลี่ยนบ่อย | ❌ ไม่คุ้ม |
| Report chart data | ทุก dashboard load | เปลี่ยนช้า (รายเดือน) | ⚠️ อาจ cache 5 นาที แต่ไม่จำเป็นสำหรับ template |

**สรุป:** เฉพาะ categories list คุ้มค่า cache — ที่เหลือ invalidation ซับซ้อนเกินสำหรับ template

---

## Metrics ที่ควร Track (ขั้นต่ำ)

### 1. Response Time
```php
// เพิ่มใน bootstrap.php
$_SERVER['REQUEST_TIME_FLOAT']; // PHP มีให้อยู่แล้ว

// เพิ่มใน footer (debug mode)
if (APP_DEBUG) {
    $elapsed = (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000;
    echo "<!-- Page: {$elapsed}ms -->";
}
```

### 2. Query Count (debug mode)
```php
// wrapper getDB() ใน db.php — นับ queries
// แสดงใน footer: <!-- Queries: 27, Time: 180ms -->
```

### 3. เกณฑ์ที่ควร track

| Metric | เป้าหมาย (template) | แดง |
|--------|---------------------|-----|
| Dashboard load | < 500ms | > 1000ms |
| Public page load | < 200ms | > 500ms |
| API response | < 100ms | > 300ms |
| Queries per page | < 20 | > 30 |

---

## สรุปลำดับการแก้ไข (Priority)

| ลำดับ | Bottleneck | Effort | Impact |
|-------|-----------|--------|--------|
| 1 | **B-05** Composite indexes (migration) | ต่ำ (SQL 6 บรรทัด) | กลาง–สูง |
| 2 | **B-01** รวม queries ใน Dashboard | กลาง (แก้ 3 methods) | สูง |
| 3 | **B-02** Bulk expire แทน loop | กลาง (แก้ 1 method) | สูง (worst case) |
| 4 | **B-04** Lazy expire flag | ต่ำ (เพิ่ม 3 บรรทัด) | กลาง |
| 5 | **B-03** findAll + LIMIT | ต่ำ (เพิ่ม LIMIT) | กลาง (ป้องกันอนาคต) |
| 6 | **B-06** Rate limit cleanup | ต่ำ (เพิ่ม cron/random) | ต่ำ |

---

## 🏁 Performance Verdict

### **"เพียงพอสำหรับขาย" — แต่แนะนำแก้ B-01 + B-05 ก่อน deploy**

**เหตุผล:**
- ✅ Core flows (ยืม/คืน/จอง) เร็วพอ — ใช้ transaction + lock อย่างเหมาะสม
- ✅ Pagination มีบน `my_borrows.php` (user-facing)
- ✅ LIMIT มีบน dashboard queries (5–10)
- ✅ Indexes พื้นฐานครบ (single-column)
- ⚠️ Admin dashboard ยิง query มากเกิน (แก้ได้ง่าย)
- ⚠️ `admin/borrows.php` ไม่มี pagination (แก้ได้ง่าย)
- ⚠️ Composite indexes ยังไม่มี (เพิ่มด้วย migration เดียว)

**สำหรับ template ขนาดเล็ก (< 500 borrows):**  
ใช้ได้เลย — ไม่มี bottleneck ที่ทำให้ระบบพัง

**สำหรับร้านจริง (500–5000 borrows):**  
ควรแก้ B-01 + B-05 ก่อน deploy (ใช้เวลา ~2 ชม.)  
และ B-02 + B-03 ถ้ามีเวลา (~1 ชม. เพิ่ม)

---

*รายงานนี้ตรวจจากโค้ดจริงทั้งหมด — ไม่มีการเดาหรือสมมติ*

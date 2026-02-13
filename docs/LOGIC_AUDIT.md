# System Logic Audit Report
**ระบบยืมคืนหนังสือ — Full Business Logic Analysis**
**Date:** 2026-02-12 | **Auditor Role:** Senior System Analyst & Backend Architect

---

## Table of Contents
1. [A) Business Flows — ทุก Flow ครบถ้วน](#a-business-flows)
2. [B) Happy Path Validation](#b-happy-path-validation)
3. [C) Edge Cases](#c-edge-cases)
4. [D) State Machines](#d-state-machines)
5. [E) Data Integrity](#e-data-integrity)
6. [F) Issues Found — พร้อมรายละเอียดและวิธีแก้](#f-issues-found)

---

# A) Business Flows

## Flow 1: User Registration
```
register.php POST
  ├─ Input: name, email, password, phone
  ├─ Step 1: CSRF check
  ├─ Step 2: Global rate limit (checkRateLimit('register'))
  ├─ Step 3: validateMemberData() — shared validation
  ├─ Step 4: Password match check
  ├─ Step 5: AuthService::register()
  │   └─ MemberService::createMember()
  │       ├─ validateMemberData() (ซ้ำ — double validation, ไม่เป็นปัญหา)
  │       ├─ emailExists() check
  │       ├─ hashPassword()
  │       └─ UserRepository::create() → INSERT users
  ├─ Output(success): redirect → login.php + flash message
  └─ Output(fail): re-render form + error
```
**Critical Conditions:** email UNIQUE constraint (DB), rate limit (DB-based), password hash (bcrypt)

## Flow 2: User Login
```
login.php POST
  ├─ Input: email, password
  ├─ Step 1: CSRF check
  ├─ Step 2: Rate limit per email (checkRateLimit('login_' . email))
  ├─ Step 3: AuthService::login()
  │   ├─ findByEmail() → user row (with hash)
  │   └─ password_verify()
  ├─ Step 4(success): session_regenerate_id() → ป้องกัน session fixation
  │   ├─ $_SESSION[user_id, user_name, user_role, user_email]
  │   └─ resetRateLimit('login_' . email)
  ├─ Step 5: redirect by role (admin/staff → admin/, member → index.php)
  ├─ Output(fail): incrementRateLimit() + generic error message
  └─ Security: ไม่แยก "email ไม่พบ" กับ "password ผิด"
```

## Flow 3: Create Reservation (จอง)
```
api/reserve_book.php POST
  ├─ Input: book_id (user_id จาก session)
  ├─ Step 1: Auth check (ต้อง login)
  ├─ Step 2: CSRF check
  ├─ Step 3: Rate limit
  ├─ Step 4: Idempotency key check (ป้องกัน double submit)
  ├─ Step 5: ReservationService::createReservation()
  │   ├─ markExpiredReservations() — lazy expire (นอก TX)
  │   ├─ BEGIN TX
  │   ├─ bookRepo::findByIdForUpdate(bookId) — ROW LOCK
  │   ├─ check: available > 0
  │   ├─ check: hasPending(userId, bookId) — ไม่จองซ้ำ
  │   ├─ check: isAlreadyBorrowing(userId, bookId) — ไม่ยืมอยู่
  │   ├─ check: activeBorrows + pendingReservations < MAX_BORROW_BOOKS
  │   ├─ reservationRepo::create() → INSERT (status='pending')
  │   ├─ bookRepo::decrementAvailable() — หัก stock ทันที
  │   └─ COMMIT
  ├─ Output(success): JSON {success, message, expires_at}
  └─ Output(fail): JSON {success:false, message} + ROLLBACK
```
**Critical Conditions:** stock หักตอนจอง, FOR UPDATE lock บน book, quota check (borrows + reservations)

## Flow 4: Fulfill Reservation (อนุมัติจอง → สร้าง borrow)
```
admin/reservations.php POST action=approve
  ├─ Input: reservation_id
  ├─ Step 1: Staff auth check
  ├─ Step 2: CSRF + idempotency check
  ├─ Step 3: ReservationService::fulfillReservation()
  │   ├─ BEGIN TX
  │   ├─ findPendingForUpdate(reservationId) — ROW LOCK
  │   ├─ check: isAlreadyBorrowing(userId, bookId)
  │   ├─ check: countActiveBorrowsForUpdate(userId) < MAX_BORROW_BOOKS
  │   ├─ borrowRepo::create() → INSERT borrow (status='borrowing')
  │   ├─ updateStatusWithBorrow() → pending → fulfilled + link borrow_id
  │   ├─ ⚠️ ไม่หัก stock (หักไว้แล้วตอนจอง)
  │   └─ COMMIT
  ├─ Output: redirect + flash message
  └─ Rollback: ยังเป็น pending + ไม่มี borrow
```
**Critical Conditions:** ไม่ decrement stock อีกรอบ (ถูกต้อง เพราะหักตอนจอง)

## Flow 5: Cancel Reservation (ยกเลิกจอง)
```
api/cancel_reservation.php POST (member)
admin/reservations.php POST action=cancel (admin)
  ├─ Input: reservation_id (+ userId สำหรับ member ownership check)
  ├─ Step 1: Auth + CSRF + idempotency
  ├─ Step 2: ReservationService::cancelReservation()
  │   ├─ BEGIN TX
  │   ├─ findPendingForUpdate(id, userId) — ROW LOCK + ownership
  │   ├─ updateStatus(id, 'cancelled')
  │   ├─ bookRepo::incrementAvailable() — คืน stock
  │   └─ COMMIT
  └─ Output: redirect + flash message
```

## Flow 6: Expire Reservations (หมดอายุ)
### 6a: Lazy Expire (เรียกทุกครั้งที่ดูข้อมูล)
```
ReservationRepository::markExpiredReservations()
  เรียกจาก: findAll(), findByUser(), BookService::getBooks(), getBookById(), getAvailableBooks()
  ├─ SELECT pending + expires_at < NOW() (ไม่ lock)
  ├─ ถ้าพบ: BEGIN TX
  ├─ Loop: UPDATE status='expired' + increment available
  └─ COMMIT
```

### 6b: Cron Expire (batch job)
```
cron/expire_reservations.php
  ├─ ReservationService::expireOverdueReservations()
  │   ├─ BEGIN TX
  │   ├─ findExpiredForUpdate() — FOR UPDATE lock ทั้ง batch
  │   ├─ Loop: updateStatus('expired') + incrementAvailable()
  │   └─ COMMIT
  └─ Output: count expired
```

## Flow 7: Create Borrow (ยืมหนังสือ — staff บันทึก)
```
admin/borrow_form.php POST
  ├─ Input: user_id, book_ids[], borrow_days
  ├─ Step 1: Staff auth + CSRF + idempotency
  ├─ Step 2: BorrowService::createBorrow()
  │   ├─ Validate: userId > 0, bookIds not empty, 1 <= borrowDays <= 30
  │   ├─ Check: findMemberById(userId) — ต้องเป็น member/staff (ไม่ใช่ admin)
  │   ├─ BEGIN TX
  │   ├─ userRepo::lockById(userId) — lock user row
  │   ├─ countActiveBorrowsForUpdate(userId) — lock + count quota
  │   ├─ Check: availableSlots > 0 + count(bookIds) <= availableSlots
  │   ├─ Loop per bookId → borrowSingleBook():
  │   │   ├─ bookRepo::findByIdForUpdate(bookId) — ROW LOCK
  │   │   ├─ check: available > 0
  │   │   ├─ check: isAlreadyBorrowing(userId, bookId)
  │   │   ├─ bookRepo::decrementAvailable() — WHERE available > 0
  │   │   └─ borrowRepo::create() → INSERT (status='borrowing')
  │   └─ COMMIT
  ├─ Output: success + borrowed[] + skipped[] + due_date
  └─ Rollback: ทุกอย่างย้อนกลับ (stock ไม่ถูกหัก)
```

## Flow 8: Return Book (คืนหนังสือ)
```
admin/borrows.php POST action=return
  ├─ Input: borrow_id, pay_now (optional)
  ├─ Step 1: Staff auth + CSRF + idempotency
  ├─ Step 2: BorrowService::returnBook()
  │   ├─ BEGIN TX
  │   ├─ findByIdForUpdate(borrowId) — lock WHERE status='borrowing'
  │   ├─ calculateFine(due_date, today)
  │   ├─ markAsReturned(borrowId, fineAmount) — status → returned
  │   ├─ bookRepo::incrementAvailable() — คืน stock +1
  │   ├─ if payNow && fine > 0: paymentRepo::create()
  │   └─ COMMIT
  └─ Output: success + fine info + paid status
```

## Flow 9: Pay Fine (ชำระค่าปรับทีหลัง)
```
admin/payments.php POST action=pay_fine
  ├─ Input: borrow_id
  ├─ Step 1: Staff auth + CSRF + idempotency
  ├─ Step 2: BorrowService::payFine()
  │   ├─ BEGIN TX
  │   ├─ findByIdForUpdateAnyStatus(borrowId) — lock (ทุก status)
  │   ├─ check: fine_amount > 0
  │   ├─ check: findByBorrowId() — ยังไม่มี payment
  │   ├─ paymentRepo::create(borrowId, amount, staffId)
  │   └─ COMMIT
  └─ Output: success + amount
  └─ DB guard: UNIQUE(borrow_id) บน payments
```

## Flow 10: Password Reset
```
forgot_password.php POST → requestPasswordReset()
  ├─ Step 1: CSRF + rate limit
  ├─ Step 2: findByEmail → ถ้าไม่พบ → return success (anti-enumeration)
  ├─ Step 3: Rate limit per email (max 3/hour)
  ├─ Step 4: Generate token (random_bytes(32) → 64 hex)
  ├─ Step 5: Save token (expires +1 hour)
  └─ Output: success + token (debug mode แสดง link)

reset_password.php POST → resetPassword()
  ├─ Step 1: Validate token (findValidToken: exists + not used + not expired)
  ├─ Step 2: BEGIN TX
  │   ├─ updatePassword(userId, hash)
  │   └─ markUsed(tokenId)
  ├─ Step 3: COMMIT
  └─ Output: success → redirect login
```

## Flow 11: Delete Book
```
admin/books.php POST action=delete
  ├─ Input: book_id
  ├─ Step 1: Staff auth + CSRF + idempotency
  ├─ Step 2: BookService::deleteBook()
  │   ├─ BEGIN TX
  │   ├─ findByIdForUpdate(id) — ROW LOCK
  │   ├─ Guard #1: isBeingBorrowed() → มีคนยืมอยู่?
  │   ├─ Guard #2: hasBorrowHistory() → เคยถูกยืม?
  │   ├─ Guard #3: countPendingByBook() → มี pending reservation?
  │   ├─ bookRepo::delete(id)
  │   ├─ COMMIT
  │   └─ Delete cover image from disk (หลัง commit)
  └─ Output: redirect + flash
```

## Flow 12: Delete Member
```
admin/members.php POST action=delete
  ├─ Step 1: Staff auth + CSRF + idempotency
  ├─ Step 2: MemberService::deleteMember()
  │   ├─ BEGIN TX
  │   ├─ Guard #1: countByUser() > 0 → มีประวัติยืม?
  │   ├─ Guard #2: countPendingByUser() > 0 → มี pending reservation?
  │   ├─ userRepo::deleteMember(id) → WHERE role IN ('member','staff')
  │   └─ COMMIT
  └─ Output: redirect + flash
```

---

# B) Happy Path Validation

| Flow | Happy Path | Status | Notes |
|------|-----------|--------|-------|
| Registration | register → login redirect | ✅ OK | Double validation (register + service) = safe redundancy |
| Login | email+pass → session → redirect by role | ✅ OK | session_regenerate_id() ✓ |
| Create Reservation | member จอง → stock -1 → pending | ✅ OK | Stock หักทันที, expires_at set |
| Fulfill Reservation | admin approve → borrow created → fulfilled | ✅ OK | ไม่หัก stock ซ้ำ ✓ |
| Cancel Reservation | user/admin cancel → stock +1 → cancelled | ✅ OK | stock คืนทุกครั้ง ✓ |
| Expire Reservation | lazy/cron → stock +1 → expired | ✅ OK | ทั้ง 2 path ทำงาน |
| Create Borrow | staff scan → stock -1 → borrowing | ✅ OK | Multi-book support ✓ |
| Return Book | staff return → stock +1 → returned + fine | ✅ OK | Fine calculation correct |
| Pay Fine | staff pay → payment created | ✅ OK | UNIQUE guard on borrow_id ✓ |
| Password Reset | forgot → token → reset → login | ✅ OK | Token one-time-use ✓ |
| Delete Book | staff delete → guards → remove | ✅ OK | 3 guards + cover cleanup ✓ |
| Delete Member | staff delete → guards → remove | ✅ OK | 2 guards ✓ |

**Verdict: ทุก Happy Path ผ่านครบโดยไม่ติดขัด**

---

# C) Edge Cases

## C.1 Double Submit (กดส่งฟอร์มซ้ำ)

| Flow | Protection | Level | Verdict |
|------|-----------|-------|---------|
| Reserve book | Idempotency key (session-based) | ✅ Strong | Key ถูกลบหลังใช้ |
| Fulfill reservation | Idempotency key | ✅ Strong | |
| Cancel reservation | Idempotency key | ✅ Strong | |
| Return book | Idempotency key | ✅ Strong | |
| Pay fine | Idempotency key + UNIQUE(borrow_id) | ✅ Strong | Double guard |
| Create borrow | Idempotency key | ✅ Strong | |
| Delete book | Idempotency key | ✅ Strong | |
| Login | Rate limit (per email+IP) | ✅ OK | |
| Register | Rate limit (global) | ✅ OK | |

## C.2 Concurrent Users (2+ คนทำพร้อมกัน)

| Scenario | Protection | Verdict |
|----------|-----------|---------|
| 2 คนจองเล่มสุดท้าย | FOR UPDATE lock on book row | ✅ Safe — คนที่ 2 ต้องรอ, เห็น available=0 |
| 2 admin อนุมัติ reservation เดียว | FOR UPDATE lock + WHERE status='pending' | ✅ Safe — คนที่ 2 ได้ null |
| 2 admin กดคืนหนังสือเดียวกัน | FOR UPDATE lock + WHERE status='borrowing' | ✅ Safe — คนที่ 2 ได้ null |
| 2 admin ยืมให้ member เดียวกัน | lockById(userId) + countForUpdate | ✅ Safe — quota enforced |
| 2 admin จ่ายค่าปรับเดียวกัน | FOR UPDATE lock + findByBorrowId check + UNIQUE | ✅ Safe — triple guard |
| Lazy expire + user cancel พร้อมกัน | ⚠️ **ISSUE L-01** | ดูรายละเอียดด้านล่าง |
| Cron expire + lazy expire พร้อมกัน | Cron ใช้ FOR UPDATE, Lazy ไม่ lock | ⚠️ **ISSUE L-02** | ดูด้านล่าง |

## C.3 Multi-Tab

| Scenario | Protection | Verdict |
|----------|-----------|---------|
| เปิด 2 tab กดจอง | Idempotency key ใน session (shared) | ✅ Tab แรกสำเร็จ, Tab 2 idempotency fail |
| เปิด 2 tab กดคืน | Idempotency key (shared session) | ✅ Safe |
| 2 tab login ต่าง account | session_regenerate_id() | ✅ Tab เก่าหลุด session |

## C.4 Network Failure

| Scenario | Impact | Verdict |
|----------|--------|---------|
| DB crash ระหว่าง transaction | PDO::rollBack() ใน catch | ✅ Atomic — ไม่มี partial state |
| Network drop หลัง commit ก่อน response | Client ไม่เห็น success แต่ DB สมบูรณ์ | ✅ Idempotency key ช่วย retry ได้ |
| Rate limit DB fail | catch → return true (allow) | ✅ Best-effort — ไม่ lock out ทุกคน |

## C.5 Invalid Sequence

| Scenario | Protection | Verdict |
|----------|-----------|---------|
| จอง → (หมดอายุ) → admin approve | findPendingForUpdate returns null (status='expired') | ✅ Safe |
| คืน borrow ที่คืนแล้ว | findByIdForUpdate WHERE status='borrowing' → null | ✅ Safe |
| จ่ายค่าปรับที่จ่ายแล้ว | findByBorrowId check + UNIQUE constraint | ✅ Safe |
| ลบหนังสือที่มีคนยืม | 3 guards inside TX | ✅ Safe |
| จองหนังสือที่ available=0 | check inside FOR UPDATE lock | ✅ Safe |

---

# D) State Machines

## D.1 Borrow State Machine

```
                 borrowing ─────────► returned
                 (สร้างใหม่)          (คืน + fine)

States: { borrowing, returned }
Valid transitions:
  ✅ borrowing → returned  (via BorrowService::returnBook)
Forbidden:
  🚫 returned → borrowing  (SQL guard: WHERE status='borrowing')
  🚫 returned → returned   (SQL guard: WHERE status='borrowing' → null)
```

**Guard Analysis:**
- `findByIdForUpdate()` มี `WHERE status = 'borrowing'` — ถ้า returned แล้ว query คืน null
- `markAsReturned()` ไม่มี WHERE status guard ❗ แต่เรียกหลัง findByIdForUpdate ที่กรองแล้ว — ✅ Safe in practice
- **No bypass vulnerability found**

## D.2 Reservation State Machine

```
                    ┌──────► fulfilled (admin อนุมัติ → สร้าง borrow)
                    │
  pending ──────────┼──────► cancelled (user/admin ยกเลิก → คืน stock)
  (สร้างใหม่)       │
                    └──────► expired   (lazy/cron หมดอายุ → คืน stock)

States: { pending, fulfilled, cancelled, expired }
Valid transitions:
  ✅ pending → fulfilled  (via fulfillReservation)
  ✅ pending → cancelled  (via cancelReservation)
  ✅ pending → expired    (via markExpiredReservations / expireOverdueReservations)
Terminal states: fulfilled, cancelled, expired (ไม่สามารถเปลี่ยนกลับ)
```

**Guard Analysis:**
- `updateStatus()`: PHP whitelist `['fulfilled', 'cancelled', 'expired']` + SQL `WHERE status='pending'`
- `updateStatusWithBorrow()`: SQL `WHERE status='pending'`
- `findPendingForUpdate()`: `WHERE status='pending' FOR UPDATE`
- **Double guard (PHP + SQL) — No bypass vulnerability found**

## D.3 Password Reset Token State Machine

```
  active ──────────► used
  (สร้างใหม่)        (ใช้รีเซ็ตแล้ว)
      │
      └──────────► expired (เลยเวลา — ไม่มี explicit status, ใช้ expires_at < NOW())
```

**Guard Analysis:**
- `findValidToken()`: checks `used = 0 AND expires_at > NOW()` ใน query เดียว
- `markUsed()`: ใน transaction เดียวกับ updatePassword — ✅ Atomic
- **No bypass vulnerability found**

## D.4 Payment State (Implicit — ไม่มี status column)

```
  unpaid (ไม่มี record ใน payments) → paid (มี record ใน payments)

Guard: UNIQUE constraint on payments.borrow_id
       + findByBorrowId() check ก่อน INSERT ใน payFine()
```

---

# E) Data Integrity

## E.1 Duplicate Records

| Entity | Duplicate Guard | Level | Verdict |
|--------|----------------|-------|---------|
| User email | UNIQUE constraint on `users.email` + `emailExists()` check | DB + App | ✅ Strong |
| Borrow (same user+book active) | `isAlreadyBorrowing()` check inside TX | App | ✅ OK (no DB UNIQUE — see ISSUE I-01) |
| Reservation (same user+book pending) | `hasPending()` check inside TX | App | ✅ OK (no DB UNIQUE — see ISSUE I-02) |
| Payment per borrow | `UNIQUE(borrow_id)` + `findByBorrowId()` | DB + App | ✅ Strong |
| ISBN | `isbnExists()` check in app layer | App only | ⚠️ No UNIQUE index (see ISSUE I-03) |
| Category name | `UNIQUE(name)` on categories | DB | ✅ Strong |

## E.2 Orphan Records

| Scenario | Protection | Verdict |
|----------|-----------|---------|
| ลบ user → borrows? | `ON DELETE CASCADE` on borrows.user_id | ⚠️ CASCADE ลบ borrows → stock ไม่ถูกคืน (ISSUE I-04) |
| ลบ user → reservations? | `ON DELETE CASCADE` on reservations.user_id | ⚠️ CASCADE ลบ pending → stock ไม่ถูกคืน (ISSUE I-04) |
| ลบ user → payments? | borrows CASCADE → payments CASCADE | ⚠️ สูญเสียข้อมูลการเงิน (ISSUE I-04) |
| ลบ book → borrows? | `ON DELETE CASCADE` on borrows.book_id | ⚠️ เช่นกัน (ISSUE I-04) |
| ลบ book → reservations? | `ON DELETE CASCADE` on reservations.book_id | ⚠️ เช่นกัน (ISSUE I-04) |
| ลบ category → books? | `ON DELETE SET NULL` on books.category_id | ✅ Safe — book ยังอยู่ |
| Cover image orphan | Delete file after DB commit | ✅ Safe — ลบหลัง commit |
| Cover image lost (DB fail) | File exists but DB rollback | ✅ Minor — file ยังอยู่ ไม่เป็นอันตราย |

**Note on ISSUE I-04:** Guards ใน `deleteMember()` และ `deleteBook()` ป้องกัน CASCADE ในทุกกรณีที่มีข้อมูลสัมพันธ์ ดังนั้น CASCADE จะไม่ถูก trigger ภายใต้ normal flow แต่ถ้ามีคนเข้า DB โดยตรงหรือเพิ่ม endpoint ใหม่ที่ลบโดยไม่ผ่าน guard → ปัญหาจะเกิด

## E.3 Inconsistent State

| Scenario | Protection | Verdict |
|----------|-----------|---------|
| stock ติดลบ (available < 0) | DB CHECK constraint `available >= 0` + WHERE guard ใน decrement | ✅ Double guard |
| available > quantity | DB CHECK constraint `quantity >= available` | ✅ DB-level |
| Reservation expired but stock ไม่ถูกคืน | Lazy expire + cron expire | ⚠️ ดู ISSUE L-01, L-02 |
| Fine calculated but not stored | `markAsReturned()` stores fine in same TX as status change | ✅ Atomic |
| Payment amount ≠ fine amount | `payFine()` reads `borrow.fine_amount` under lock | ✅ Consistent |

---

# F) Issues Found

## 🔴 Critical Issues

### ISSUE L-01: Lazy Expire Race Condition — stock อาจถูกคืนซ้ำ
- **Severity:** 🔴 HIGH (data integrity)
- **Flow กระทบ:** Flow 5 (Cancel) + Flow 6a (Lazy Expire)
- **Step ที่ผิด:** `markExpiredReservations()` **ไม่ใช้ FOR UPDATE lock**
- **Expected:** Reservation ถูก expire หรือ cancel เพียงครั้งเดียว stock +1 ครั้งเดียว
- **Actual:** Race condition ทำให้ stock อาจถูก +1 สองครั้ง

**Scenario:**
```
Time  Thread A (lazy expire)              Thread B (user cancel)
T1    SELECT id=5 (pending, expired_at<NOW)
T2                                         BEGIN TX
T3                                         SELECT id=5 FOR UPDATE → ได้
T4                                         UPDATE status='cancelled'
T5                                         available +1 (คืน stock)
T6                                         COMMIT
T7    BEGIN TX
T8    UPDATE id=5 SET status='expired'
          WHERE status='pending'
          → rowCount=0 (เพราะ status='cancelled' แล้ว)
T9    แต่! if ($updateStmt->rowCount() > 0) ✅ ป้องกันได้
```

**Actual Analysis:** ตรวจโค้ดอีกครั้ง — **line 114 ใน `markExpiredReservations()`** มีการตรวจ `rowCount() > 0` ก่อน increment stock → **ปลอดภัยในกรณีนี้**

**แต่ยังมีปัญหา:**
```
Time  Thread A (lazy expire)              Thread B (lazy expire - อีก request)
T1    SELECT expired list = [id=5]
T2                                         SELECT expired list = [id=5]
T3    BEGIN TX
T4    UPDATE id=5 status='expired'         
          WHERE status='pending' → rowCount=1 ✅
T5    available +1
T6    COMMIT
T7                                         BEGIN TX
T8                                         UPDATE id=5 status='expired'
                                               WHERE status='pending' → rowCount=0
T9                                         rowCount check → skip ✅
T10                                        COMMIT
```

**Re-analysis:** `rowCount()` guard **ป้องกันได้ในทุกกรณี** เพราะ SQL `WHERE status='pending'` จะ match 0 rows ถ้าถูก expire/cancel ไปแล้ว

**⚠️ DOWNGRADE to LOW:** Logic ถูกต้อง — `rowCount()` guard ทำงาน แต่ lazy expire ไม่ใช้ lock ทำให้:
- 2 requests อาจทำ SELECT เดียวกัน → วน loop ซ้ำ → waste work (performance, ไม่ใช่ correctness)
- **คำแนะนำ:** ใช้ `FOR UPDATE SKIP LOCKED` ใน lazy expire เพื่อลด contention

**จุดในโค้ด:** `@ReservationRepository.php:81-133`

**วิธีแก้เชิงโครงสร้าง:**
```php
// เปลี่ยน markExpiredReservations() ให้ใช้ single bulk UPDATE + subquery
// แทน SELECT → loop → UPDATE
$stmt = $this->pdo->prepare("
    UPDATE reservations SET status = 'expired'
    WHERE status = 'pending' AND expires_at < NOW()
");
$stmt->execute();
$affected = $stmt->rowCount();
// แล้ว increment stock ด้วย JOIN UPDATE
```

**Test cases:**
1. 2 requests เรียก lazy expire พร้อมกัน → stock ไม่ถูกคืนซ้ำ
2. Cancel + lazy expire พร้อมกัน → stock +1 ครั้งเดียว
3. Cron + lazy expire พร้อมกัน → stock +1 ครั้งเดียว

---

### ISSUE L-02: Cron Expire + Lazy Expire Race — ซ้ำซ้อนแต่ไม่ผิด
- **Severity:** 🟡 LOW (performance waste, not correctness)
- **Flow กระทบ:** Flow 6a + Flow 6b
- **Analysis:** Cron ใช้ `FOR UPDATE`, Lazy ไม่ lock → Lazy อาจเห็น rows ที่ Cron กำลัง lock อยู่ → Lazy จะรอ lock หรือเห็น status เปลี่ยนแล้ว → `rowCount() = 0` → skip
- **Verdict:** ✅ **Correct** but wasteful
- **คำแนะนำ:** เพิ่ม request-level flag ป้องกันเรียก lazy expire ซ้ำหลายครั้งใน request เดียว

---

## 🟡 Medium Issues

### ISSUE I-01: ไม่มี DB-level UNIQUE สำหรับ active borrow (user + book)
- **Severity:** 🟡 MEDIUM
- **Flow กระทบ:** Flow 7 (Create Borrow)
- **Step ที่ผิด:** `isAlreadyBorrowing()` เป็น app-level check ไม่มี DB constraint
- **Expected:** User ยืมหนังสือเล่มเดียวกันซ้ำไม่ได้
- **Actual:** App guard ทำงานภายใต้ FOR UPDATE lock → **ปลอดภัยในทางปฏิบัติ** แต่ไม่มี defense-in-depth ระดับ DB

**จุดในโค้ด:** `@BorrowService.php:434` + `@schema.sql:68-86`

**วิธีแก้เชิงโครงสร้าง:**
```sql
-- ไม่สามารถใช้ UNIQUE(user_id, book_id) ตรงๆ เพราะต้อง allow ยืมซ้ำหลัง returned
-- ต้องใช้ partial unique index (MySQL 8.0+):
-- หรือใช้ UNIQUE functional index:
ALTER TABLE borrows ADD UNIQUE INDEX uq_active_borrow 
    ((CASE WHEN status = 'borrowing' THEN user_id END), 
     (CASE WHEN status = 'borrowing' THEN book_id END));
-- หมายเหตุ: MySQL <8.0 ไม่รองรับ — app guard เพียงพอ
```

**Test cases:**
1. Concurrent API calls ยืมเล่มเดียวกัน → เล่มที่ 2 ถูก reject
2. ยืม → คืน → ยืมใหม่ → ต้องสำเร็จ

---

### ISSUE I-02: ไม่มี DB-level UNIQUE สำหรับ pending reservation (user + book)
- **Severity:** 🟡 MEDIUM  
- **Flow กระทบ:** Flow 3 (Create Reservation)
- **Analysis:** เหมือน I-01 — `hasPending()` check ทำภายใต้ book FOR UPDATE lock → ปลอดภัยในทางปฏิบัติ
- **จุดในโค้ด:** `@ReservationService.php:118` + `@schema.sql:91-105`
- **วิธีแก้:** เหมือน I-01 ใช้ partial unique index ถ้า MySQL 8.0+

---

### ISSUE I-03: ISBN ไม่มี UNIQUE constraint ระดับ DB
- **Severity:** 🟡 MEDIUM
- **Flow กระทบ:** Book creation/import
- **Step ที่ผิด:** `isbnExists()` เป็น app-level check เท่านั้น
- **Expected:** ISBN ต้อง unique ทั้งระบบ
- **Actual:** ถ้า 2 admin สร้างหนังสือ ISBN เดียวกันพร้อมกัน (ไม่มี TX lock) → duplicate ได้

**จุดในโค้ด:** `@BookRepository.php` `isbnExists()` + `@schema.sql:46-63`

**วิธีแก้เชิงโครงสร้าง:**
```sql
ALTER TABLE books ADD UNIQUE INDEX uq_isbn (isbn);
-- isbn อาจเป็น NULL → UNIQUE อนุญาต multiple NULL (MySQL behavior)
```

**Test cases:**
1. 2 admin สร้างหนังสือ ISBN เดียวกันพร้อมกัน → ต้องมีแค่ 1 record
2. ISBN = NULL ต้องอนุญาตได้หลายรายการ

---

### ISSUE I-04: CASCADE DELETE บน borrows/reservations เสี่ยง stock leak
- **Severity:** 🟡 MEDIUM (ป้องกันด้วย app guard แล้ว แต่ DB-level ไม่ safe)
- **Flow กระทบ:** Flow 11 (Delete Book), Flow 12 (Delete Member)
- **Analysis:** 
  - ถ้าลบ user/book ผ่าน app → guard ป้องกันได้ (ห้ามลบถ้ามี active borrow/pending reservation)
  - ถ้าลบ user/book ผ่าน DB โดยตรง → CASCADE ลบ pending reservation แต่ **ไม่คืน stock** → stock หาย
  - ถ้าลบ user ที่มี **active borrow** → CASCADE ลบ borrow แต่ **stock ไม่ถูกคืน** → `available` ไม่เพิ่ม

**จุดในโค้ด:** `@schema.sql:84-85` (borrows FK), `@schema.sql:102-103` (reservations FK)

**วิธีแก้เชิงโครงสร้าง:**
```sql
-- เปลี่ยน CASCADE เป็น RESTRICT สำหรับ borrows + reservations
ALTER TABLE borrows DROP FOREIGN KEY borrows_ibfk_1;
ALTER TABLE borrows ADD CONSTRAINT fk_borrows_user 
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT;

ALTER TABLE borrows DROP FOREIGN KEY borrows_ibfk_2;
ALTER TABLE borrows ADD CONSTRAINT fk_borrows_book 
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT;

-- เช่นเดียวกันกับ reservations
```
**ข้อดี:** DB ป้องกัน delete ที่จะทำให้ stock leak — ต้องจัดการ borrows/reservations ให้หมดก่อน
**ข้อเสีย:** Code ที่ลบ user/book ต้อง handle FK error

**Test cases:**
1. ลบ book ที่มี active borrow ผ่าน SQL → ต้อง error (RESTRICT)
2. ลบ user ที่มี pending reservation ผ่าน SQL → ต้อง error
3. ลบ user ที่ไม่มี borrow/reservation → สำเร็จ

---

### ISSUE I-05: `markExpiredReservations()` ไม่ใช้ `incrementAvailable()` ที่มี guard
- **Severity:** 🟡 MEDIUM
- **Flow กระทบ:** Flow 6a (Lazy Expire)
- **Step ที่ผิด:** Line 115-118 ใช้ raw SQL `available = available + 1` แทน `BookRepository::incrementAvailable()` ที่มี `WHERE available < quantity` guard

**จุดในโค้ด:** `@ReservationRepository.php:115-118`

```php
// ปัจจุบัน (ไม่มี guard):
$stockStmt = $this->pdo->prepare("
    UPDATE books SET available = available + 1 WHERE id = ?
");

// ควรเป็น (มี guard):
$stockStmt = $this->pdo->prepare("
    UPDATE books SET available = available + 1 
    WHERE id = ? AND available < quantity
");
```

**Expected:** stock +1 แต่ไม่เกิน quantity
**Actual:** ถ้ามี bug ทำให้ expire ซ้ำ (แม้ rowCount guard ป้องกันแล้ว) → available อาจเกิน quantity
**Protection ที่มี:** DB CHECK constraint `quantity >= available` จะ reject UPDATE → exception → rollback ✅

**วิธีแก้:** เพิ่ม `AND available < quantity` guard ใน SQL สำหรับ defense-in-depth
**หรือ:** Inject `BookRepository` เข้า `ReservationRepository` แล้วเรียก `incrementAvailable()`

**Test cases:**
1. Book quantity=5, available=5 → expire reservation → available ต้องยังเป็น 5 (ไม่เกิน)
2. Verify DB CHECK constraint rejects available > quantity

---

### ISSUE I-06: `updateBook()` quantity change — race condition กับ concurrent borrow/reserve
- **Severity:** 🟡 MEDIUM
- **Flow กระทบ:** Book update + concurrent borrow/reserve
- **Step ที่ผิด:** `BookService::updateBook()` reads book → calculates new available → writes back — **ไม่ใช้ FOR UPDATE lock**

**จุดในโค้ด:** `@BookService.php:151-187`

**Scenario:**
```
Time  Admin (updateBook)              User (reserve)
T1    getBookById() → available=3
T2                                     BEGIN TX → lock book → available=3
T3                                     decrement → available=2
T4                                     COMMIT
T5    newAvailable = 3 + (10 - 5) = 8
T6    bookRepo::update(available=8)  ← ❌ ควรเป็น 7 (สูญหาย 1 stock)
```

**Expected:** available คำนวณจากค่าปัจจุบัน
**Actual:** คำนวณจากค่าที่อ่านก่อน concurrent operation → stock inconsistency

**วิธีแก้เชิงโครงสร้าง:**
```php
// ใช้ atomic UPDATE แทน read-modify-write:
$this->pdo->beginTransaction();
$book = $this->bookRepo->findByIdForUpdate($id); // LOCK
$quantityDiff = $newQuantity - $book['quantity'];
$newAvailable = max(0, $book['available'] + $quantityDiff);
$this->bookRepo->update($id, [..., 'available' => $newAvailable]);
$this->pdo->commit();
```

**Test cases:**
1. Admin update quantity ขณะ user จองพร้อมกัน → available ต้อง consistent
2. Admin ลด quantity ขณะมีคนยืมออก → ต้อง reject ถ้า newQuantity < currentlyOut

---

### ISSUE I-07: `createReservation()` quota check ไม่ lock borrows
- **Severity:** 🟡 MEDIUM
- **Flow กระทบ:** Flow 3 (Create Reservation)
- **Step ที่ผิด:** `countActiveBorrows()` (ไม่มี FOR UPDATE) + `countPendingByUser()` (ไม่มี FOR UPDATE)

**จุดในโค้ด:** `@ReservationService.php:132-134`

```php
$activeBorrows = $this->borrowRepo->countActiveBorrows($userId);  // ไม่ lock
$pendingReservations = $this->reservationRepo->countPendingByUser($userId);  // ไม่ lock
```

**Expected:** Quota enforced correctly under concurrency
**Actual:** ถ้า admin สร้าง borrow ให้ user ขณะเดียวกับที่ user จอง → ทั้งคู่อาจเห็น count ต่ำกว่าจริง → เกินโควต้าได้

**เปรียบเทียบกับ `createBorrow()`:** ใช้ `lockById(userId)` + `countActiveBorrowsForUpdate()` → ✅ Safe

**วิธีแก้:** Lock user row ก่อน check quota ใน `createReservation()` เช่นเดียวกับ `createBorrow()`

**Test cases:**
1. User จอง + admin ยืมให้ user พร้อมกัน → รวมต้องไม่เกิน MAX_BORROW_BOOKS

---

## 🟢 Low Issues

### ISSUE I-08: `fulfillReservation()` quota check ไม่นับ pending reservations อื่น
- **Severity:** 🟢 LOW
- **Flow กระทบ:** Flow 4 (Fulfill Reservation)  
- **Analysis:** ตรวจเฉพาะ `countActiveBorrowsForUpdate()` < MAX → ถ้า user มี pending reservation อื่นที่กำลังจะถูก fulfill → อาจเกินโควต้าได้
- **Practical risk:** ต่ำมาก เพราะ admin approve ทีละรายการ

**จุดในโค้ด:** `@ReservationService.php:257-259`

**วิธีแก้:**
```php
$currentBorrows = $this->borrowRepo->countActiveBorrowsForUpdate($reservation['user_id']);
$pendingReservations = $this->reservationRepo->countPendingByUser($reservation['user_id']);
// ลบ 1 เพราะ reservation ที่กำลัง fulfill นับอยู่ใน pending
if (($currentBorrows + $pendingReservations - 1) >= MAX_BORROW_BOOKS) {
    throw new Exception('...');
}
```

---

### ISSUE I-09: `importMember()` password default '123456' — weak but documented
- **Severity:** 🟢 LOW
- **จุดในโค้ด:** `@MemberService.php:327`
- **Analysis:** Default password `123456` สำหรับ import — ควรบังคับเปลี่ยนหลัง login แรก แต่ระบบไม่มี "force password change" flag
- **วิธีแก้:** เพิ่ม `must_change_password` column ใน users table

---

### ISSUE I-10: `findByUserAndBook()` ไม่ sort → อาจคืนผิด record ถ้ามีหลาย rows
- **Severity:** 🟢 LOW
- **จุดในโค้ด:** `@ReservationRepository.php:235-251`
- **Analysis:** ถ้า user มีหลาย reservations สำหรับ book เดียวกัน (เช่น pending → cancelled → จองใหม่) → `fetch()` อาจคืน record เก่าที่ cancelled แทนที่จะเป็น pending ใหม่
- **Current usage:** เรียกด้วย `$status = 'pending'` เสมอ → filter ถูกต้อง → **ไม่เป็นปัญหาในทางปฏิบัติ**
- **วิธีแก้:** เพิ่ม `ORDER BY created_at DESC LIMIT 1`

---

### ISSUE I-11: Lazy expire ถูกเรียกซ้ำหลายครั้งใน request เดียว
- **Severity:** 🟢 LOW (performance)
- **Flow กระทบ:** ทุก page ที่โหลดข้อมูล book + reservation
- **Analysis:** `markExpiredReservations()` ถูกเรียกจาก `findAll()`, `findByUser()`, `getBooks()`, `getBookById()`, `getAvailableBooks()` → page เดียวอาจเรียก 2-3 ครั้ง
- **วิธีแก้:** เพิ่ม static/instance flag:
```php
private bool $expiredMarked = false;
public function markExpiredReservations(): int {
    if ($this->expiredMarked) return 0;
    $this->expiredMarked = true;
    // ... existing logic
}
```

---

# Summary Matrix

| ID | Severity | Category | Issue | Fix Complexity |
|----|----------|----------|-------|---------------|
| I-06 | 🟡 MEDIUM | Race Condition | updateBook() read-modify-write ไม่ lock | Medium (wrap in TX + FOR UPDATE) |
| I-07 | 🟡 MEDIUM | Race Condition | createReservation() quota ไม่ lock user | Medium (add lockById) |
| I-01 | 🟡 MEDIUM | Data Integrity | No DB UNIQUE for active borrow | Low (add partial index if MySQL 8+) |
| I-02 | 🟡 MEDIUM | Data Integrity | No DB UNIQUE for pending reservation | Low (add partial index if MySQL 8+) |
| I-03 | 🟡 MEDIUM | Data Integrity | ISBN no UNIQUE constraint | Low (ALTER TABLE) |
| I-04 | 🟡 MEDIUM | Schema Design | CASCADE DELETE risks stock leak | Medium (change to RESTRICT) |
| I-05 | 🟡 MEDIUM | Defense-in-Depth | Lazy expire raw SQL ไม่มี available<quantity guard | Low (add WHERE clause) |
| I-08 | 🟢 LOW | Logic | fulfillReservation quota ไม่นับ pending อื่น | Low |
| I-09 | 🟢 LOW | Security | Import default password no force-change | Medium |
| I-10 | 🟢 LOW | Query | findByUserAndBook ไม่ sort | Low |
| I-11 | 🟢 LOW | Performance | Lazy expire ซ้ำหลายครั้ง/request | Low |
| L-01/L-02 | 🟢 LOW | Race | Lazy expire contention (correct but wasteful) | Medium |

---

# Overall Verdict

## Strengths (จุดแข็ง)
1. **Transaction + FOR UPDATE lock** ใช้ถูกต้องทุก write flow หลัก (borrow, return, pay, cancel, fulfill)
2. **State machine guards** ทั้ง PHP whitelist + SQL WHERE — double protection
3. **Idempotency keys** ป้องกัน double-submit ครบทุก mutation endpoint
4. **DB CHECK constraints** (`available >= 0`, `quantity >= available`) เป็น safety net สุดท้าย
5. **CSRF protection** ครบทุก POST endpoint
6. **Anti-enumeration** ใน login + forgot password
7. **Atomic operations** — ทุก flow ใช้ transaction ครอบ write cluster

## Weaknesses (จุดอ่อน)
1. **ไม่มี DB-level UNIQUE** สำหรับ active borrow / pending reservation — พึ่ง app guard อย่างเดียว
2. **CASCADE DELETE** ใน FK อาจทำให้ stock inconsistent ถ้า bypass app guard
3. **`updateBook()` race condition** — ไม่ lock ก่อนคำนวณ available ใหม่
4. **`createReservation()` quota check** ไม่ lock user row — เกินโควต้าได้ภายใต้ concurrency

## Production Readiness Score: **8.5/10**

ระบบมีการออกแบบ concurrency control ที่ดีมากสำหรับ core flows (borrow, return, pay) แต่ยังมีช่องว่างใน secondary flows (reservation quota, book update) และขาด defense-in-depth ระดับ DB สำหรับ uniqueness constraints บาง entities

**Priority Fix Order:**
1. 🔴 I-06: `updateBook()` race condition (stock inconsistency risk)
2. 🔴 I-07: `createReservation()` quota lock (quota bypass risk)
3. 🟡 I-03: ISBN UNIQUE constraint (quick ALTER TABLE)
4. 🟡 I-05: Lazy expire available guard (one-line fix)
5. 🟡 I-04: CASCADE → RESTRICT (schema migration)
6. 🟢 I-01/I-02: Partial unique indexes (MySQL 8+ only)

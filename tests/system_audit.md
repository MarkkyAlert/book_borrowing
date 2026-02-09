# Code Audit Report — ระบบยืมคืนหนังสือ (Scan #2)

**วันที่ตรวจ:** 2026-02-09  
**บริบท:** PHP page-based + API, small system (เรียน / demo / template)  
**มาตรฐาน:** Production สำหรับ small system  
**วิธีตรวจ:** อ่านโค้ดจริงทุกไฟล์ที่เกี่ยวข้อง ไม่เดา  
**โฟกัส:** runtime crashes, null/key-missing, validation gaps, security, transactions, race conditions

**ไฟล์ที่สแกน:**
- `api/*` (5 files), `admin/*` (19 files), root `*.php` (10 files)
- `app/Services/*` (5 files), `app/Repositories/*` (8 files)
- `includes/*` (config, db, functions, report_helper)

---

## รายการปัญหาที่พบ (เรียงตามความร้ายแรง)

---

### ❌ #1 — CSV Import สมาชิก crash เมื่อเจอ email ซ้ำ (Missing array key)

- **ไฟล์:** `app/Services/MemberService.php` → `importMember()` ~line 239
- **ปัญหา:** เมื่อ CSV import เจอสมาชิกที่มี email อยู่แล้ว เรียก `userRepo->update()` แต่ไม่ส่ง key `'email'` ใน array ทำให้ `UserRepository::update()` อ่าน `$data['email']` ได้ `null` → SQL `UPDATE users SET email = NULL` → ละเมิด NOT NULL constraint → **PDOException → transaction rollBack → import ทั้งไฟล์ล้มเหลว**
- **หลักฐาน:**
  ```php
  // MemberService::importMember() line 239
  $this->userRepo->update($existing['id'], [
      'name' => $name,
      'phone' => $phone
      // ❌ ขาด 'email' => $email
  ]);
  ```
  ```php
  // UserRepository::update() ต้องการ $data['email']
  $stmt->execute([$data['name'], $data['email'], $data['phone'] ?? null, $id]);
  ```
- **ผลกระทบจริง:** Staff กด Import CSV → มีชื่อซ้ำในระบบ → ทั้งไฟล์ import ไม่เข้า → flash error
- **วิธีแก้:**
  ```php
  $this->userRepo->update($existing['id'], [
      'name' => $name,
      'email' => $email,   // ← เพิ่มบรรทัดนี้
      'phone' => $phone
  ]);
  ```
- **Test:** Import CSV ที่มี email ซ้ำกับสมาชิกในระบบ → ต้อง update ชื่อ/เบอร์ได้ ไม่ crash

---

### ⚠️ #2 — Cover image ถูกลบก่อน DB save สำเร็จ (Orphan risk)

- **ไฟล์:** `admin/book_form.php` ~line 118-126
- **ปัญหา:** เมื่ออัปโหลดรูปปกใหม่ ไฟล์เก่าถูก `unlink()` ทันทีที่ upload สำเร็จ (line 121-124) **ก่อนที่** DB update จะทำ (line 146) ถ้า DB update ล้มเหลว → รูปเก่าหายแล้ว + DB ยังชี้ไปรูปเก่าที่ไม่มี + รูปใหม่กลายเป็น orphan บน disk
- **ผลกระทบจริง:** ต่ำ — เกิดเฉพาะเมื่อ upload สำเร็จแต่ DB save ล้มเหลว (hiมาก) แต่ถ้าเกิด จะเห็นรูปแตก
- **วิธีแก้:** ย้าย `unlink()` ไปหลัง DB save สำเร็จ:
  ```php
  // หลัง try { $bookService->updateBook(...); } สำเร็จ
  if (!empty($oldCoverImage) && $oldCoverImage !== $coverImage) {
      @unlink($uploadDir . $oldCoverImage);
  }
  ```
- **Test:** แก้ไขหนังสือ + upload รูปใหม่ + จำลอง DB error → รูปเก่าต้องยังอยู่

---

### ⚠️ #3 — AJAX scan endpoint ไม่ตรวจ CSRF

- **ไฟล์:** `admin/borrow_form.php` ~line 27-53
- **ปัญหา:** POST handler `action=scan` (ค้นหา user/book) ทำงานก่อน CSRF check ที่อยู่ใน main POST handler (line 59) ทำให้ endpoint นี้ไม่มี CSRF protection
- **ผลกระทบจริง:** ต่ำ — เป็น read-only operation + อยู่หลัง `requireStaff()` → attacker ต้องหลอกให้ staff เปิดหน้าที่ทำ cross-origin POST ซึ่งถูก SameSite=Lax cookie block อยู่แล้ว แต่ผิดหลัก defense-in-depth
- **วิธีแก้:**
  ```php
  if (isset($_POST['action']) && $_POST['action'] === 'scan') {
      if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {  // ← เพิ่ม
          echo json_encode(['success' => false, 'message' => 'Invalid token']);
          exit;
      }
      // ... existing scan logic
  ```
- **Test:** ส่ง AJAX scan โดยไม่มี csrf_token → ต้องได้ error response

---

### ⚠️ #4 — `member_history.php` ส่ง JSON ด้วย Content-Type ผิด

- **ไฟล์:** `api/member_history.php` ~line 15-35
- **ปัญหา:** Early exit paths (line 15, 22, 28) ส่ง `json_encode([])` แต่ไม่ set `Content-Type: application/json` — header ถูก set เฉพาะ success path (line 35) ทำให้ error responses ถูกส่งด้วย `text/html`
- **ผลกระทบจริง:** ต่ำมาก — client ใช้ `r.json()` parse อยู่แล้ว ไม่ crash แต่ผิด HTTP standard
- **วิธีแก้:** ย้าย `header('Content-Type: application/json; charset=utf-8');` ไปบรรทัดแรกหลัง `require bootstrap`:
  ```php
  require_once __DIR__ . '/../bootstrap.php';
  header('Content-Type: application/json; charset=utf-8');  // ← ย้ายมาบนสุด
  ```
- **Test:** เรียก API โดยไม่ login → response Content-Type ต้องเป็น `application/json`

---

### ⚠️ #5 — member_card.php ใช้ `die()` แทน error page

- **ไฟล์:** `admin/member_card.php` ~line 18
- **ปัญหา:** `die("ไม่พบสมาชิก")` ส่ง raw text ไม่มี HTML structure ไม่มี status code
- **ผลกระทบจริง:** ต่ำ — UX ไม่สวยเมื่อเปิด URL ที่ member id ไม่มี
- **วิธีแก้:**
  ```php
  if (!$member) {
      http_response_code(404);
      exit('<h3>ไม่พบสมาชิก</h3><script>setTimeout(()=>window.close(),2000)</script>');
  }
  ```
- **Test:** เปิด `member_card.php?id=999999` → ต้องเห็นข้อความ error + HTTP 404

---

### ⚠️ #6 — member_card.php CSS injection (defense-in-depth)

- **ไฟล์:** `admin/member_card.php` ~line 41-43
- **ปัญหา:** `$colorPrimary` / `$colorSecondary` จาก DB ถูก output ตรงใน `<style>` block โดยไม่ escape:
  ```php
  --primary: <?= $colorPrimary ?>;    // ← ไม่มี e()
  --secondary: <?= $colorSecondary ?>; // ← ไม่มี e()
  ```
  `settings.php` validate ด้วย regex `^#[0-9A-Fa-f]{6}$` ก่อนบันทึก แต่ output point ไม่มี defense-in-depth
- **ผลกระทบจริง:** ต่ำมาก — ต้องแก้ DB โดยตรง + admin-only page + popup window
- **วิธีแก้:**
  ```php
  --primary: <?= e($colorPrimary) ?>;
  --secondary: <?= e($colorSecondary) ?>;
  ```
- **Test:** ใส่ค่า `"; alert(1); "` ใน DB โดยตรง → ต้องไม่ execute JavaScript

---

## ✅ ผ่านทั้งหมด (ไม่พบปัญหา)

| หมวด | ไฟล์ / จุด |
|------|-----------|
| **Core Flows** | ยืม, คืน, จอง, ค่าปรับ, Auth → logic ถูกต้องครบ |
| **Race Condition** | `FOR UPDATE` lock ทุกจุดเสี่ยง (ยืมเกินโควต้า, stock, คืนซ้ำ, จ่ายซ้ำ, approve ซ้ำ) |
| **Double-submit** | Session idempotency key ทุก POST action |
| **State Machine** | `WHERE status = 'borrowing'`/`'pending'` guard ทุก transition |
| **Data Integrity** | DB CHECK constraints + FK + UNIQUE + application validation |
| **Security** | CSRF (hash_equals), prepared statements, XSS (e()), session fixation, rate limit, file upload |
| **Transaction** | beginTransaction/commit/rollBack ครบทุก multi-step operation |
| **Auth/Authz** | `requireStaff()`/`requireAdmin()`/`requireLogin()` ทุกหน้าที่ต้องการ |
| **API endpoints** | `reserve_book.php`, `cancel_reservation.php`, `add_member.php`, `search_books.php` → method guard + auth + CSRF ครบ |
| **CSV Import (books)** | Transaction wrap, skip invalid rows, ISBN duplicate check, file handle cleanup in finally |
| **Password Reset** | random_bytes token, 1hr expiry, rate limit, used flag, no enumeration |

---

## สรุป

| ระดับ | จำนวน | รายละเอียด |
|-------|--------|-----------|
| ❌ ต้องแก้ก่อนขาย | **1** | CSV import member crash (#1) |
| ⚠️ ควรปรับ | **5** | Cover image timing (#2), CSRF scan (#3), Content-Type (#4), die() (#5), CSS escape (#6) |
| ✅ ผ่าน | ที่เหลือทั้งหมด | Core flows, security, transactions, race conditions |

### คำตัดสิน: **แก้ ❌ #1 แล้วพร้อมขาย**

ปัญหา #1 เป็น crash จริงที่เกิดได้ในการใช้งานปกติ (import CSV ที่มี email ซ้ำ) ต้องแก้ก่อน  
ปัญหา ⚠️ #2-#6 ไม่ block การขาย แต่แก้ได้ง่ายและเพิ่มคุณภาพ

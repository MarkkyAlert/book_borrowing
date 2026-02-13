# 🧪 Test Cases: Logical Consistency & Causality

> เอกสารนี้ออกแบบ Test Cases สำหรับตรวจสอบความเป็นเหตุเป็นผลของระบบ
> **Test Cases เป็นตัวตัดสินว่า Logic ถูกหรือผิด - ไม่ใช่โค้ด**

---

## 📋 Test Data Setup

```sql
-- ข้อมูลเริ่มต้นสำหรับทุก test
-- Users
INSERT INTO users (id, name, email, password, role) VALUES
(100, 'Test Admin', 'admin@test.com', '$2y$10$...', 'admin'),
(101, 'Test Staff', 'staff@test.com', '$2y$10$...', 'staff'),
(102, 'Test Member A', 'member.a@test.com', '$2y$10$...', 'member'),
(103, 'Test Member B', 'member.b@test.com', '$2y$10$...', 'member');

-- Books
INSERT INTO books (id, title, author, quantity, available, category_id) VALUES
(200, 'Book Alpha', 'Author A', 3, 3, 1),
(201, 'Book Beta', 'Author B', 1, 1, 1),  -- มีเล่มเดียว
(202, 'Book Gamma', 'Author C', 2, 0, 1); -- หมด stock
```

---

## 1️⃣ HAPPY PATH TEST CASES

### HP-01: ยืมหนังสือ 1 เล่มสำเร็จ

| Item | Detail |
|------|--------|
| **Precondition** | Member A ยังไม่มีการยืม, Book Alpha available = 3 |
| **Steps** | 1. Staff เปิด borrow_form.php<br>2. เลือก user_id = 102 (Member A)<br>3. เลือก book_ids = [200] (Book Alpha)<br>4. กดบันทึก |
| **Input** | `user_id=102, book_ids=[200], borrow_days=14` |
| **Expected Result** | Flash message: "บันทึกการยืมสำเร็จ 1 เล่ม", redirect to borrows.php |
| **DB Verification** | `SELECT * FROM borrows WHERE user_id=102 AND book_id=200` → มี 1 row, status='borrowing'<br>`SELECT available FROM books WHERE id=200` → **2** (ลดลง 1) |

---

### HP-02: ยืมหนังสือหลายเล่มพร้อมกัน

| Item | Detail |
|------|--------|
| **Precondition** | Member A ยังไม่มีการยืม, MAX_BORROW_BOOKS = 5 |
| **Steps** | 1. Staff เปิด borrow_form.php<br>2. เลือก user_id = 102<br>3. เลือก book_ids = [200, 201] (2 เล่ม)<br>4. กดบันทึก |
| **Input** | `user_id=102, book_ids=[200,201], borrow_days=7` |
| **Expected Result** | Flash: "บันทึกการยืมสำเร็จ 2 เล่ม" |
| **DB Verification** | `SELECT COUNT(*) FROM borrows WHERE user_id=102 AND status='borrowing'` → **2**<br>`SELECT available FROM books WHERE id=200` → **2**<br>`SELECT available FROM books WHERE id=201` → **0** |

---

### HP-03: คืนหนังสือก่อนกำหนด (ไม่มีค่าปรับ)

| Item | Detail |
|------|--------|
| **Precondition** | Member A มี borrow_id=1 ที่ due_date = วันพรุ่งนี้ |
| **Steps** | 1. Staff เปิด borrows.php<br>2. กดปุ่ม "คืน" ที่ borrow_id=1<br>3. Confirm |
| **Input** | `borrow_id=1, action=return` |
| **Expected Result** | Flash: "บันทึกการคืนหนังสือสำเร็จ" |
| **DB Verification** | `SELECT status, return_date, fine_amount FROM borrows WHERE id=1`<br>→ status='returned', return_date=TODAY, fine_amount=**0**<br>`SELECT available FROM books WHERE id=200` → **เพิ่มขึ้น 1** |

---

### HP-04: คืนหนังสือเกินกำหนด (มีค่าปรับ)

| Item | Detail |
|------|--------|
| **Precondition** | Member A มี borrow_id=2 ที่ due_date = 5 วันก่อน, FINE_PER_DAY = 10 |
| **Steps** | 1. Staff เปิด borrows.php<br>2. กดปุ่ม "คืน" ที่ borrow_id=2<br>3. เลือก pay_now = true |
| **Input** | `borrow_id=2, action=return, pay_now=1` |
| **Expected Result** | Flash: "ค่าปรับ: 50 บาท (เกิน 5 วัน) [รับชำระเงินแล้ว]" |
| **DB Verification** | `SELECT fine_amount FROM borrows WHERE id=2` → **50**<br>`SELECT * FROM payments WHERE borrow_id=2` → มี 1 row, amount=50 |

---

### HP-05: จองหนังสือสำเร็จ

| Item | Detail |
|------|--------|
| **Precondition** | Member A login, Book Alpha available = 3 |
| **Steps** | 1. Member A เปิดหน้า book.php?id=200<br>2. กดปุ่ม "จอง" |
| **Input** | `POST /api/reserve_book.php { book_id: 200 }` |
| **Expected Result** | JSON: `{ success: true, message: "จองสำเร็จ!..." }` |
| **DB Verification** | `SELECT * FROM reservations WHERE user_id=102 AND book_id=200` → status='pending'<br>`SELECT available FROM books WHERE id=200` → **2** (ลดลง 1 ทันที) |

---

### HP-06: อนุมัติการจอง → สร้าง Borrow อัตโนมัติ

| Item | Detail |
|------|--------|
| **Precondition** | Reservation id=1 status='pending' สำหรับ Member A + Book Alpha |
| **Steps** | 1. Staff เปิด reservations.php<br>2. กดปุ่ม "อนุมัติ" ที่ reservation_id=1 |
| **Input** | `id=1, action=approve` |
| **Expected Result** | Flash: "อนุมัติการจองสำเร็จ! สร้างรายการยืมแล้ว" |
| **DB Verification** | `SELECT status, borrow_id FROM reservations WHERE id=1` → status='fulfilled', borrow_id=**NOT NULL**<br>`SELECT * FROM borrows WHERE id=<borrow_id>` → มี row ใหม่, status='borrowing'<br>**books.available ไม่เปลี่ยน** (หักไปตอนจองแล้ว) |

---

### HP-07: ชำระค่าปรับทีหลัง

| Item | Detail |
|------|--------|
| **Precondition** | borrow_id=3 คืนแล้ว, fine_amount=30, ยังไม่มี payment |
| **Steps** | 1. Staff เปิด payments.php<br>2. กดปุ่ม "รับชำระ" ที่ borrow_id=3 |
| **Input** | `borrow_id=3, action=pay_fine` |
| **Expected Result** | Flash: "รับชำระค่าปรับ 30 บาท เรียบร้อยแล้ว" |
| **DB Verification** | `SELECT * FROM payments WHERE borrow_id=3` → มี 1 row, amount=30 |

---

## 2️⃣ DUPLICATE / RETRY TEST CASES

### DR-01: กดปุ่มยืมซ้ำ 2 ครั้งติดกัน (Double Submit)

| Item | Detail |
|------|--------|
| **Precondition** | Book Alpha available = 3 |
| **Steps** | 1. Staff เปิด borrow_form.php<br>2. เลือก user_id=102, book_ids=[200]<br>3. **กดปุ่มบันทึก 2 ครั้งเร็วๆ** (ภายใน 1 วินาที) |
| **Input** | Same request × 2 |
| **Expected Result** | ครั้งแรก: สำเร็จ<br>ครั้งที่ 2: Flash "รายการนี้ถูกบันทึกไปแล้ว" หรือ redirect ไม่ทำซ้ำ |
| **DB Verification** | `SELECT COUNT(*) FROM borrows WHERE user_id=102 AND book_id=200` → **1** (ไม่ใช่ 2)<br>`SELECT available FROM books WHERE id=200` → **2** (ลด 1 ไม่ใช่ 2) |

---

### DR-02: Refresh หน้าหลัง Submit การยืม

| Item | Detail |
|------|--------|
| **Precondition** | เพิ่งยืมสำเร็จ, อยู่ที่ borrows.php |
| **Steps** | 1. ยืมหนังสือสำเร็จ → redirect to borrows.php<br>2. **กด F5 refresh หน้า** |
| **Input** | Browser refresh |
| **Expected Result** | ไม่มี POST data ถูก re-submit (PRG pattern) |
| **DB Verification** | `SELECT COUNT(*) FROM borrows WHERE user_id=102` → ไม่เพิ่มขึ้น |

---

### DR-03: กดปุ่มคืนซ้ำ 2 ครั้ง

| Item | Detail |
|------|--------|
| **Precondition** | borrow_id=1 status='borrowing', Book available=2 |
| **Steps** | 1. Staff เปิด borrows.php<br>2. กดปุ่ม "คืน" ที่ borrow_id=1<br>3. **เปิดอีกแท็บ กดคืน borrow_id=1 อีกครั้ง** |
| **Input** | `borrow_id=1, action=return` × 2 |
| **Expected Result** | ครั้งแรก: สำเร็จ<br>ครั้งที่ 2: Error "ไม่พบรายการยืมหรือคืนหนังสือแล้ว" |
| **DB Verification** | `SELECT status FROM borrows WHERE id=1` → 'returned'<br>`SELECT available FROM books WHERE id=200` → **3** (เพิ่ม 1 ไม่ใช่ 2) |

---

### DR-04: กดชำระค่าปรับซ้ำ 2 ครั้ง

| Item | Detail |
|------|--------|
| **Precondition** | borrow_id=3 มี fine_amount=50, ยังไม่มี payment |
| **Steps** | 1. Staff กดชำระค่าปรับ borrow_id=3<br>2. **เปิดอีกแท็บ กดชำระอีกครั้ง** |
| **Input** | `borrow_id=3, action=pay_fine` × 2 |
| **Expected Result** | ครั้งแรก: สำเร็จ<br>ครั้งที่ 2: Error "รายการนี้ชำระค่าปรับแล้ว" |
| **DB Verification** | `SELECT COUNT(*) FROM payments WHERE borrow_id=3` → **1** (ไม่ใช่ 2) |

---

### DR-05: จองหนังสือเล่มเดิมซ้ำ 2 ครั้ง

| Item | Detail |
|------|--------|
| **Precondition** | Member A ยังไม่มี pending reservation สำหรับ Book Alpha |
| **Steps** | 1. Member A กดจอง Book Alpha<br>2. **กดจองอีกครั้งทันที** |
| **Input** | `POST /api/reserve_book.php { book_id: 200 }` × 2 |
| **Expected Result** | ครั้งแรก: success<br>ครั้งที่ 2: "คุณได้จองหนังสือเล่มนี้ไว้แล้ว" |
| **DB Verification** | `SELECT COUNT(*) FROM reservations WHERE user_id=102 AND book_id=200 AND status='pending'` → **1** |

---

### DR-06: Approve reservation ซ้ำ 2 ครั้ง

| Item | Detail |
|------|--------|
| **Precondition** | reservation_id=1 status='pending' |
| **Steps** | 1. Staff A กด Approve reservation_id=1<br>2. Staff B กด Approve reservation_id=1 (อีกแท็บ) |
| **Input** | `id=1, action=approve` × 2 |
| **Expected Result** | ครั้งแรก: สำเร็จ<br>ครั้งที่ 2: Error "ไม่พบรายการจองหรือไม่อยู่ในสถานะรอรับ" |
| **DB Verification** | `SELECT COUNT(*) FROM borrows WHERE id IN (SELECT borrow_id FROM reservations WHERE id=1)` → **1** borrow เท่านั้น |

---

## 3️⃣ INVALID SEQUENCE TEST CASES

### IS-01: คืนหนังสือที่คืนไปแล้ว

| Item | Detail |
|------|--------|
| **Precondition** | borrow_id=5 มี status='returned' |
| **Steps** | 1. Staff พยายามส่ง POST คืนหนังสือ borrow_id=5 โดยตรง |
| **Input** | `borrow_id=5, action=return` |
| **Expected Result** | Error: "ไม่พบรายการยืมหรือคืนหนังสือแล้ว" |
| **DB Verification** | status ยังเป็น 'returned'<br>books.available **ไม่เปลี่ยน** |

---

### IS-02: ยืมหนังสือที่ไม่มี stock

| Item | Detail |
|------|--------|
| **Precondition** | Book Gamma (id=202) มี available = 0 |
| **Steps** | 1. Staff พยายามยืม book_id=202 ให้ Member A |
| **Input** | `user_id=102, book_ids=[202]` |
| **Expected Result** | Error/Skip: "Book Gamma (ไม่มีเล่มว่าง)" |
| **DB Verification** | ไม่มี borrow record ใหม่<br>books.available ยัง = 0 (ไม่ติดลบ) |

---

### IS-03: ยืมเล่มเดิมที่ยืมอยู่แล้ว

| Item | Detail |
|------|--------|
| **Precondition** | Member A มี active borrow สำหรับ Book Alpha (id=200) |
| **Steps** | 1. Staff พยายามยืม book_id=200 ให้ Member A อีกครั้ง |
| **Input** | `user_id=102, book_ids=[200]` |
| **Expected Result** | Skip: "Book Alpha (ยืมอยู่แล้ว)" |
| **DB Verification** | `SELECT COUNT(*) FROM borrows WHERE user_id=102 AND book_id=200 AND status='borrowing'` → **1** (ไม่เพิ่ม) |

---

### IS-04: ยืมเกินโควต้า (MAX_BORROW_BOOKS)

| Item | Detail |
|------|--------|
| **Precondition** | MAX_BORROW_BOOKS = 5, Member A มี active borrows = 5 เล่มแล้ว |
| **Steps** | 1. Staff พยายามยืมหนังสือเพิ่มให้ Member A |
| **Input** | `user_id=102, book_ids=[201]` |
| **Expected Result** | Error: "ผู้ยืมถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว (5 เล่ม)" |
| **DB Verification** | `SELECT COUNT(*) FROM borrows WHERE user_id=102 AND status='borrowing'` → **5** (ไม่เพิ่ม) |

---

### IS-05: ชำระค่าปรับที่ไม่มี (fine_amount = 0)

| Item | Detail |
|------|--------|
| **Precondition** | borrow_id=6 มี fine_amount = 0 (คืนตรงเวลา) |
| **Steps** | 1. Staff พยายามส่ง POST ชำระค่าปรับ borrow_id=6 |
| **Input** | `borrow_id=6, action=pay_fine` |
| **Expected Result** | Error: "รายการนี้ไม่มีค่าปรับ" |
| **DB Verification** | ไม่มี payment record สำหรับ borrow_id=6 |

---

### IS-06: Cancel reservation ที่ fulfilled แล้ว

| Item | Detail |
|------|--------|
| **Precondition** | reservation_id=2 มี status='fulfilled' |
| **Steps** | 1. Staff พยายามส่ง POST cancel reservation_id=2 |
| **Input** | `id=2, action=cancel` |
| **Expected Result** | Error: "ไม่พบรายการจองหรือยกเลิกไปแล้ว" |
| **DB Verification** | status ยัง = 'fulfilled'<br>books.available **ไม่เปลี่ยน** |

---

### IS-07: Approve reservation ที่ user ถึง quota แล้ว

| Item | Detail |
|------|--------|
| **Precondition** | reservation_id=3 status='pending' สำหรับ Member A<br>Member A มี active borrows = 5 เล่มแล้ว |
| **Steps** | 1. Staff กด Approve reservation_id=3 |
| **Input** | `id=3, action=approve` |
| **Expected Result** | Error: "ผู้จองถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว (5 เล่ม)" |
| **DB Verification** | reservation.status ยัง = 'pending'<br>ไม่มี borrow record ใหม่ |

---

## 4️⃣ CONCURRENCY TEST CASES

### CC-01: 2 คนยืมหนังสือเล่มสุดท้ายพร้อมกัน

| Item | Detail |
|------|--------|
| **Precondition** | Book Beta (id=201) มี available = 1 (เล่มสุดท้าย) |
| **Steps** | 1. **พร้อมกัน:** Staff A ยืมให้ Member A, Staff B ยืมให้ Member B<br>2. ทั้งสองกด Submit ในเวลาเดียวกัน |
| **Input** | Thread A: `user_id=102, book_ids=[201]`<br>Thread B: `user_id=103, book_ids=[201]` |
| **Expected Result** | 1 คนสำเร็จ, อีก 1 คนได้ Error "ไม่มีเล่มว่าง" หรือ "stock หมดระหว่างดำเนินการ" |
| **DB Verification** | `SELECT COUNT(*) FROM borrows WHERE book_id=201 AND status='borrowing'` → **1** (ไม่ใช่ 2)<br>`SELECT available FROM books WHERE id=201` → **0** (ไม่ใช่ -1) |

---

### CC-02: 2 Staff คืนหนังสือเล่มเดียวกันพร้อมกัน

| Item | Detail |
|------|--------|
| **Precondition** | borrow_id=10 status='borrowing' |
| **Steps** | 1. Staff A และ Staff B เปิด borrows.php พร้อมกัน<br>2. ทั้งสองกดปุ่ม "คืน" ที่ borrow_id=10 พร้อมกัน |
| **Input** | Thread A & B: `borrow_id=10, action=return` |
| **Expected Result** | 1 คนสำเร็จ, อีก 1 คนได้ Error "ไม่พบรายการยืมหรือคืนหนังสือแล้ว" |
| **DB Verification** | `SELECT status FROM borrows WHERE id=10` → 'returned'<br>books.available เพิ่มขึ้น **1** (ไม่ใช่ 2) |

---

### CC-03: ยืมจาก 2 แท็บพร้อมกันเกิน Quota

| Item | Detail |
|------|--------|
| **Precondition** | Member A มี active borrows = 4 (เหลือ slot 1), MAX_BORROW_BOOKS = 5 |
| **Steps** | 1. Staff เปิด 2 แท็บ<br>2. แท็บ A เลือกยืม 1 เล่มให้ Member A<br>3. แท็บ B เลือกยืม 1 เล่มให้ Member A<br>4. **กด Submit พร้อมกัน** |
| **Input** | Thread A: `user_id=102, book_ids=[200]`<br>Thread B: `user_id=102, book_ids=[201]` |
| **Expected Result** | 1 สำเร็จ (ครบ 5), อีก 1 Error "ผู้ยืมถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว" |
| **DB Verification** | `SELECT COUNT(*) FROM borrows WHERE user_id=102 AND status='borrowing'` → **5** (ไม่ใช่ 6) |

---

### CC-04: Approve + Cancel reservation พร้อมกัน

| Item | Detail |
|------|--------|
| **Precondition** | reservation_id=5 status='pending' |
| **Steps** | 1. Staff A กด Approve<br>2. Staff B กด Cancel<br>3. **พร้อมกัน** |
| **Input** | Thread A: `id=5, action=approve`<br>Thread B: `id=5, action=cancel` |
| **Expected Result** | มีแค่ 1 action สำเร็จ, อีก 1 ได้ Error |
| **DB Verification** | `SELECT status FROM reservations WHERE id=5` → 'fulfilled' **หรือ** 'cancelled' (ไม่ใช่อยู่สถานะกลางๆ)<br>ถ้า fulfilled → มี borrow record<br>ถ้า cancelled → books.available เพิ่มขึ้น 1 |

---

## 5️⃣ AUTHORIZATION TEST CASES

### AZ-01: Member พยายามเข้าหน้า Admin

| Item | Detail |
|------|--------|
| **Precondition** | Login เป็น Member A (role='member') |
| **Steps** | 1. พยายามเข้า URL /admin/borrows.php โดยตรง |
| **Input** | GET /admin/borrows.php |
| **Expected Result** | Redirect ไป login.php หรือ 403 Forbidden |
| **DB Verification** | N/A |

---

### AZ-02: Staff พยายามเข้าหน้า Settings (Admin only)

| Item | Detail |
|------|--------|
| **Precondition** | Login เป็น Staff (role='staff') |
| **Steps** | 1. พยายามเข้า URL /admin/settings.php |
| **Input** | GET /admin/settings.php |
| **Expected Result** | Redirect หรือ 403 Forbidden |
| **DB Verification** | N/A |

---

### AZ-03: ยืมหนังสือให้ Admin/Staff (ไม่ใช่ Member)

| Item | Detail |
|------|--------|
| **Precondition** | user_id=100 เป็น Admin, user_id=101 เป็น Staff |
| **Steps** | 1. Staff พยายามยืมหนังสือให้ user_id=100 (Admin) |
| **Input** | `user_id=100, book_ids=[200]` |
| **Expected Result** | Error: "ไม่พบสมาชิกที่เลือก" (เพราะ findMemberById กรอง role IN member/staff — admin ไม่ได้อยู่ใน list) |
| **DB Verification** | ไม่มี borrow record สำหรับ user_id=100 |

---

### AZ-04: จองหนังสือโดยไม่ login

| Item | Detail |
|------|--------|
| **Precondition** | ไม่ได้ login (session ว่าง) |
| **Steps** | 1. POST ไปที่ /api/reserve_book.php โดยตรง |
| **Input** | `POST { book_id: 200 }` |
| **Expected Result** | HTTP 401: "กรุณาเข้าสู่ระบบก่อนจองหนังสือ" |
| **DB Verification** | ไม่มี reservation record ใหม่ |

---

### AZ-05: CSRF Token ไม่ถูกต้อง

| Item | Detail |
|------|--------|
| **Precondition** | Login เป็น Staff |
| **Steps** | 1. POST ไปที่ /admin/borrows.php ด้วย csrf_token ผิด |
| **Input** | `action=return, borrow_id=1, csrf_token=invalid_token` |
| **Expected Result** | Flash error: "คำขอไม่ถูกต้อง กรุณาลองใหม่" |
| **DB Verification** | borrow ไม่ถูก return |

---

### AZ-06: Member ยกเลิก Reservation ของคนอื่น

| Item | Detail |
|------|--------|
| **Precondition** | reservation_id=10 เป็นของ Member B (user_id=103)<br>Login เป็น Member A (user_id=102) |
| **Steps** | 1. Member A พยายาม cancel reservation_id=10 (ถ้ามี endpoint) |
| **Input** | `id=10, action=cancel` (จาก session ของ Member A) |
| **Expected Result** | Error: "ไม่พบรายการจองหรือยกเลิกไปแล้ว" (เพราะ filter by user_id) |
| **DB Verification** | reservation_id=10 status ยัง = 'pending' |

---

### AZ-07: แก้ไข user_id ใน request เพื่อยืมให้คนอื่น (Impersonation)

| Item | Detail |
|------|--------|
| **Precondition** | Login เป็น Member A, พยายามจองแทน Member B |
| **Steps** | 1. POST ไป /api/reserve_book.php พร้อมแก้ user_id ใน request |
| **Input** | `POST { book_id: 200, user_id: 103 }` (แก้ user_id) |
| **Expected Result** | ระบบต้องใช้ user_id จาก SESSION ไม่ใช่จาก POST |
| **DB Verification** | ถ้าสำเร็จ → reservation.user_id = 102 (จาก session) ไม่ใช่ 103 |

---

## 📊 Test Summary Checklist

| Category | Total Cases | Pass Criteria |
|----------|:-----------:|---------------|
| Happy Path | 7 | ทุก case ต้องได้ผลตาม expected |
| Duplicate/Retry | 6 | ไม่มี duplicate records, stock ถูกต้อง |
| Invalid Sequence | 7 | ทุก invalid action ถูก reject |
| Concurrency | 4 | ไม่มี race condition ทำให้ data ผิด |
| Authorization | 7 | ไม่มี unauthorized access |
| **TOTAL** | **31** | |

---

## 🔧 How to Run Tests

### Manual Testing
1. Setup test data ตาม SQL ด้านบน
2. ทำตาม Steps ของแต่ละ case
3. Verify DB ตาม DB Verification

### Automated Testing (แนะนำ)
```bash
# PHPUnit
php vendor/bin/phpunit tests/

# หรือใช้ไฟล์ test ที่มี
php tests/borrow_test.php
php tests/reservation_test.php
```

---

*Document created: 2026-02-02*
*Total Test Cases: 31*

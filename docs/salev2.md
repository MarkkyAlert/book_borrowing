# ฟีเจอร์หลักของระบบ — ระบบยืมคืนหนังสือ

---

## 1. ภาพรวม

ระบบยืมคืนหนังสือ เขียนด้วย PHP ล้วน ครอบคลุมงานห้องสมุดตั้งแต่การจัดการหนังสือ สมาชิก การยืม-คืน การจอง การคำนวณค่าปรับ ไปจนถึงรายงานสรุป
แบ่งสิทธิ์การใช้งาน 3 ระดับ (Admin / Staff / Member) แต่ละ role เห็นเมนูและทำงานได้ต่างกัน

---

## 2. Feature Overview

- ระบบยืม-คืนหนังสือ (บันทึกวันยืม กำหนดคืน วันคืนจริง)
- ระบบจองหนังสือ (จองล่วงหน้า มีวันหมดอายุ + expire อัตโนมัติผ่าน cron)
- ระบบคำนวณค่าปรับอัตโนมัติ (คืนเกินกำหนด คิดตามจำนวนวัน)
- ระบบชำระค่าปรับ (บันทึกการจ่าย ป้องกันจ่ายซ้ำด้วย UNIQUE constraint)
- ระบบจัดการหนังสือ (CRUD + upload รูปปก + หมวดหมู่ + ISBN)
- ระบบจัดการสมาชิก (CRUD + เปลี่ยน role + auto-generate password)
- ระบบ Import CSV (หนังสือ + สมาชิก รองรับ BOM, upsert, validation)
- ระบบรายงานและสถิติ (ยืมรายเดือน รายได้ค่าปรับ หนังสือยอดนิยม หนังสือค้างคืน)
- พิมพ์บัตรสมาชิก + ป้ายบาร์โค้ดหนังสือ
- ค้นหา + กรองหนังสือ (ชื่อ ผู้แต่ง หมวดหมู่ สถานะว่าง)
- ตั้งค่าระบบ (ชื่อหน่วยงาน สีธีม)

---

## 3. สิทธิ์การใช้งานแยกตาม Role

### 📌 Admin (ผู้ดูแลระบบ)

Admin มีสิทธิ์ทุกอย่างที่ Staff ทำได้ และเพิ่มเติมส่วนที่เกี่ยวกับการควบคุมระบบ

**เฉพาะ Admin เท่านั้น:**
- ดูรายงานและสถิติ (`admin/reports.php` → `requireAdmin()`)
- Export รายงานเป็น PDF (`admin/export_pdf.php` → `requireAdmin()`)
- ตั้งค่าระบบ เช่น ชื่อหน่วยงาน สีธีม (`admin/settings.php` → `requireAdmin()`)
- เปลี่ยน role ของสมาชิก (member ↔ staff) ผ่าน dropdown ใน `admin/member_form.php`

**ทำได้เหมือน Staff:**
- จัดการหนังสือ สมาชิก ยืม-คืน จอง ค่าปรับ ทุกอย่าง

---

### 📌 Staff (เจ้าหน้าที่)

Staff เป็น role สำหรับงานประจำวันของห้องสมุด เข้าถึง admin panel ได้ แต่ไม่เห็นเมนูรายงานและตั้งค่า

**จัดการหนังสือ:**
- เพิ่ม แก้ไข ลบหนังสือ + upload รูปปก (`admin/books.php`, `admin/book_form.php`)
- จัดการหมวดหมู่ (`admin/categories.php`)
- Import หนังสือจาก CSV (`admin/import_books.php`)
- พิมพ์ป้ายบาร์โค้ดหนังสือ (`admin/book_labels.php`)

**จัดการสมาชิก:**
- ดูรายชื่อ เพิ่ม แก้ไข ลบสมาชิก (`admin/members.php`, `admin/member_form.php`)
- Import สมาชิกจาก CSV (`admin/import_members.php`)
- พิมพ์บัตรสมาชิก (`admin/member_card.php`)
- ไม่สามารถเปลี่ยน role ได้ (dropdown ไม่แสดง — เฉพาะ Admin)

**ยืม-คืน:**
- บันทึกการยืม เลือกสมาชิก + หนังสือ + กำหนดวันคืน (`admin/borrow_form.php`)
- บันทึกการคืน + คำนวณค่าปรับอัตโนมัติ (`admin/borrows.php`)
- ดูรายการยืมทั้งหมด กรองตามสถานะ/สมาชิก

**การจอง:**
- ดูรายการจองทั้งหมด (`admin/reservations.php`)
- อนุมัติการจอง (fulfill → สร้างรายการยืมให้สมาชิก)
- ยกเลิกการจอง (cancel → คืน stock)

**ค่าปรับ:**
- ดูประวัติการชำระ + รายการค้างชำระ (`admin/payments.php`)
- บันทึกการชำระค่าปรับ

**Dashboard:**
- ดูสรุปภาพรวม: จำนวนหนังสือ สมาชิก การยืมวันนี้ หนังสือค้างคืน (`admin/index.php`)

---

### 📌 Member (สมาชิก)

Member เข้าถึงเฉพาะหน้า public และหน้าส่วนตัว ไม่สามารถเข้า admin panel ได้

**หน้า public (ไม่ต้อง login):**
- ดูรายการหนังสือทั้งหมด + ค้นหา + กรอง (`index.php`)
- ดูรายละเอียดหนังสือ (`book.php`)
- สมัครสมาชิก (`register.php`)
- ลืมรหัสผ่าน (`forgot_password.php`)

**หน้าส่วนตัว (ต้อง login → `requireLogin()`):**
- ดูรายการยืมของตัวเอง สถานะ กำหนดคืน ค่าปรับ (`my_borrows.php`)
- ดูรายการจองของตัวเอง สถานะ วันหมดอายุ (`my_reservations.php`)
- จองหนังสือ (ผ่าน API `api/reserve_book.php`)
- ยกเลิกการจองของตัวเอง (ผ่าน API `api/cancel_reservation.php`)
- แก้ไขข้อมูลส่วนตัว + เปลี่ยนรหัสผ่าน (`profile.php`)

**สิ่งที่ Member ทำไม่ได้:**
- เข้า admin panel → redirect กลับหน้าแรก
- ยืม-คืนเอง → ต้องผ่าน Staff/Admin เท่านั้น
- เปลี่ยน role ของตัวเอง → กำหนดจาก DB ตอน login

---

## 4. หมายเหตุเชิงเทคนิค

**การแบ่ง role ทำงาน 3 ชั้น:**

| ชั้น | กลไก | ไฟล์ |
|------|------|------|
| 1. Session | เก็บ `role` จาก DB ตอน login → ตรวจทุก request | `login.php` |
| 2. Function Guard | `requireAdmin()` / `requireStaff()` / `requireLogin()` → redirect ถ้าไม่มีสิทธิ์ | `includes/functions.php` |
| 3. Template Safety Net | `admin/header.php` เรียก `requireStaff()` ซ้ำอีกครั้ง — กัน dev ลืมใส่ guard | `admin/header.php` |

**สิทธิ์จากโค้ดจริง:**

| หน้า | Guard | Admin | Staff | Member |
|------|-------|-------|-------|--------|
| `admin/index.php` | `requireStaff()` | ✅ | ✅ | ❌ |
| `admin/books.php` | `requireStaff()` | ✅ | ✅ | ❌ |
| `admin/borrows.php` | `requireStaff()` | ✅ | ✅ | ❌ |
| `admin/reservations.php` | `requireStaff()` | ✅ | ✅ | ❌ |
| `admin/payments.php` | `requireStaff()` | ✅ | ✅ | ❌ |
| `admin/members.php` | `requireStaff()` | ✅ | ✅ | ❌ |
| `admin/categories.php` | `requireStaff()` | ✅ | ✅ | ❌ |
| `admin/import_books.php` | `requireStaff()` | ✅ | ✅ | ❌ |
| `admin/import_members.php` | `requireStaff()` | ✅ | ✅ | ❌ |
| `admin/book_labels.php` | `requireStaff()` | ✅ | ✅ | ❌ |
| `admin/member_card.php` | `requireStaff()` | ✅ | ✅ | ❌ |
| `admin/reports.php` | `requireAdmin()` | ✅ | ❌ | ❌ |
| `admin/export_pdf.php` | `requireAdmin()` | ✅ | ❌ | ❌ |
| `admin/settings.php` | `requireAdmin()` | ✅ | ❌ | ❌ |
| `my_borrows.php` | `requireLogin()` | ✅ | ✅ | ✅ |
| `my_reservations.php` | `requireLogin()` | ✅ | ✅ | ✅ |
| `profile.php` | `requireLogin()` | ✅ | ✅ | ✅ |
| `index.php` | ไม่มี | ✅ | ✅ | ✅ |
| `book.php` | ไม่มี | ✅ | ✅ | ✅ |

การแบ่ง role แบบนี้ทำให้:
- **ปลอดภัย** — Member ไม่มีทางเข้าถึงข้อมูลที่ไม่ใช่ของตัวเอง
- **ต่อยอดง่าย** — เพิ่ม role ใหม่ได้โดยเพิ่มค่าใน ENUM + เขียน guard function
- **อธิบายได้** — นักศึกษาชี้โค้ดได้เลยว่า "บรรทัดนี้ตรวจสิทธิ์"

# FEATURE MATRIX

> ใช้ตอบคำถามแบบ "ลูกค้าถามว่าทำ X ได้ไหม" ตามกติกา Context §20
> **A = มีอยู่แล้ว** · **B = ยังไม่มี แต่ต่อยอดได้** · **C = ไม่เหมาะกับ Use case นี้**

## A — มีอยู่แล้วในระบบ (ชี้ไฟล์ได้)

### หนังสือ
| ฟีเจอร์ | ไฟล์ |
|---------|------|
| เพิ่ม/แก้/ลบหนังสือ (พร้อม guard กันลบข้อมูลที่มีประวัติ) | `admin/books.php`, `admin/book_form.php` → `BookService` |
| อัปโหลดรูปปก (ตรวจ MIME จริง, ≤2MB) | `admin/book_form.php:104` |
| หมวดหมู่ (CRUD + นับจำนวนหนังสือ) | `admin/categories.php` → `CategoryRepository` |
| ค้นหา/กรอง (คำค้น, หมวดหมู่, สถานะ stock, เรียงลำดับ) | `BookRepository::findAll()` |
| ค้นหาสดแบบ AJAX บนหน้าแรก | `api/search_books.php` + `includes/book_grid.php` |
| ซ่อน/แสดงหนังสือจากหน้า public | `is_visible` toggle ใน `admin/book_form.php` |
| Import หนังสือจาก CSV (merge + auto-create category) | `admin/import_books.php`, ตัวอย่าง `docs/samples/books_sample.csv` |
| พิมพ์ฉลาก Barcode (CODE128 จาก ISBN) | `admin/book_labels.php` |
| หน้ารายละเอียดหนังสือ + รายชื่อผู้ยืมปัจจุบัน (เห็นเฉพาะ admin) | `book.php` |

### สมาชิก
| ฟีเจอร์ | ไฟล์ |
|---------|------|
| สมัครสมาชิกเอง / staff เพิ่มให้ | `register.php`, `admin/member_form.php`, `api/add_member.php` |
| Login / Logout / Session timeout | `login.php`, `logout.php`, `functions.php::startSession()` |
| ลืมรหัสผ่าน + รีเซ็ตด้วย token (ไม่ส่งอีเมลจริง) | `forgot_password.php`, `reset_password.php` |
| Profile + เปลี่ยนรหัสผ่าน + ดูประวัติ/ค่าปรับค้างของตัวเอง | `profile.php` |
| จัดการสมาชิก + ค้นหา + สถิติการยืมรายคน | `admin/members.php` |
| เปลี่ยน role member ⇄ staff | `admin/member_form.php` |
| Import สมาชิกจาก CSV | `admin/import_members.php` |
| พิมพ์บัตรสมาชิก (QR + Barcode จาก user id, สีปรับได้) | `admin/member_card.php` + `admin/settings.php` |

### ยืม–คืน–ค่าปรับ
| ฟีเจอร์ | ไฟล์ |
|---------|------|
| บันทึกการยืม (หลายเล่มพร้อมกัน, atomic) | `admin/borrow_form.php` → `BorrowService::createBorrow()` |
| รับค่าจาก Barcode Scanner (สแกนบัตรสมาชิก + สันหนังสือ) | `admin/borrow_form.php` AJAX `action=scan` |
| คืนหนังสือ + คำนวณค่าปรับอัตโนมัติ | `admin/borrows.php` → `returnBook()` |
| รับชำระค่าปรับ (ตอนคืน หรือ ทีหลัง) | `admin/borrows.php`, `admin/payments.php` |
| รายการเกินกำหนด / ค้างชำระ | `admin/borrows.php`, `admin/payments.php`, Dashboard |
| ประวัติการยืมของสมาชิก (มุม staff) | `api/member_history.php` |
| ประวัติของตัวเอง (มุม member) | `my_borrows.php` |

### จอง
| ฟีเจอร์ | ไฟล์ |
|---------|------|
| สมาชิกจองหนังสือ (หัก stock ทันที, หมดอายุ 2 วัน) | `api/reserve_book.php` |
| ยกเลิกการจอง (member = ของตัวเอง / staff = ของใครก็ได้) | `api/cancel_reservation.php`, `admin/reservations.php` |
| อนุมัติการจอง → สร้างรายการยืมอัตโนมัติ | `admin/reservations.php` → `fulfillReservation()` |
| หมดอายุอัตโนมัติ (lazy + cron) | `ReservationRepository::markExpiredReservations()`, `cron/expire_reservations.php` |

### Dashboard / รายงาน / ตั้งค่า
| ฟีเจอร์ | ไฟล์ |
|---------|------|
| Dashboard 7 การ์ด + กราฟรายเดือน + กราฟหมวดหมู่ (Chart.js) | `admin/index.php` → `DashboardService` |
| หนังสือยอดนิยม / สมาชิกยืมบ่อย / stock ใกล้หมด | `DashboardService`, `ReportRepository` |
| รายงาน 6 ชนิด: หนังสือยอดนิยม, สมาชิก, รายได้ค่าปรับ, ค้างส่ง, ยืม-คืน, ค้างชำระ | `admin/reports.php` + `includes/report_helper.php` |
| Export CSV (มี UTF-8 BOM ให้ Excel อ่านไทยได้) | `admin/reports.php:84` |
| "Export PDF" = หน้า print-friendly + `window.print()` (ไม่มี PDF library) | `admin/export_pdf.php` |
| ตั้งค่า: ชื่อหน่วยงาน + สีบัตรสมาชิก 2 สี | `admin/settings.php` (แค่ 3 key) |

## B — ยังไม่มี แต่ต่อยอดได้ (ต้องเขียนเพิ่ม)

| สิ่งที่ลูกค้ามักถาม | สถานะจริง | ต้องทำอะไร | ระดับงาน |
|---------------------|-----------|------------|----------|
| ส่งอีเมลแจ้งเตือน / อีเมลรีเซ็ตรหัสผ่าน | สร้าง token ได้ แต่ **ไม่ส่งอีเมล** | เพิ่ม `NotificationService` + PHPMailer/SMTP | กลาง |
| แจ้งเตือน LINE / Web push | ไม่มี | `NotificationService` แยก (ห้ามยัด API call ใน Controller) | กลาง |
| ต่ออายุการยืม (renew) | ไม่มี | เพิ่ม method ใน `BorrowService` + ปุ่ม + กติกาว่าต่อได้กี่ครั้ง | เล็ก–กลาง |
| ค่าปรับสะสมของรายการที่ยังไม่คืน | คำนวณตอนคืนเท่านั้น | เพิ่ม query คำนวณ on-the-fly หรือ cron snapshot | เล็ก |
| จ่ายค่าปรับบางส่วน / คืนเงิน | 1 borrow = 1 payment (UNIQUE) | ต้องถอด UNIQUE + ทำ ledger จริง | กลาง |
| กลุ่มสมาชิกที่มีโควตา/วันยืมต่างกัน (นักศึกษา/อาจารย์) | มีแค่ 3 role ด้าน "สิทธิ์" ไม่มี borrow policy ต่อกลุ่ม | เพิ่มตาราง `member_groups` + policy + แก้ `BorrowService`/`ReservationService` | กลาง |
| ระงับ/พักสิทธิ์สมาชิก | ไม่มีคอลัมน์ `is_active` | เพิ่มคอลัมน์ + guard ใน login และ borrow | เล็ก |
| หลายสาขา (multi-branch) | ไม่มี | เพิ่ม `branches` + branch_id ในเกือบทุกตาราง + scope ทุก query + stock ต่อสาขา | **ใหญ่** |
| Audit trail / ใครแก้อะไรเมื่อไหร่ | มีแค่ `recorded_by` ใน payments | เพิ่มตาราง `audit_logs` + hook ที่ Service | กลาง |
| PDF จริง (ไม่ผ่าน browser print) | ใช้ print view | ใส่ mPDF/TCPDF + ฟอนต์ไทย | เล็ก–กลาง |
| Friendly URL (`/books/123`) | ใช้ `book.php?id=123` | `.htaccess` rewrite + แก้จุด generate link ทั้งหมด | เล็ก |
| ระบบยืม–คืนอุปกรณ์/ครุภัณฑ์ | Flow ยืม-คืน reuse ได้เกือบทั้งหมด | rename entity + เพิ่ม asset code/serial/location/สถานะ | กลาง |
| แจ้งซ่อม | ไม่มี | สร้าง Module/Service/Repository ของ Repair **แยก** — ห้ามยัดใน BorrowService | กลาง |
| Wallet / เงินประกัน | มีแค่ fine/payment ไม่ใช่ wallet | ต้องออกแบบ ledger จริง (account, transactions, credit/debit, reference, idempotency, refund) | **ใหญ่** |

## C — ไม่เหมาะกับ Codebase นี้ (Domain ต่างกันมาก)

Trading bot · Payment gateway / wallet เต็มรูปแบบ · E-learning · HR/Payroll · Accounting · ERP · ห้องสมุดมหาวิทยาลัยหลายหมื่นคน · ระบบที่ต้องการ real-time push (ไม่มี WebSocket/SSE)

> คำเตือนการใช้คำ: ระบบนี้ **ไม่ใช่ real-time** — หลายเครื่องเห็นข้อมูลตรงกันเพราะใช้ **Database กลางชุดเดียว** ไม่ใช่เพราะ push

# โครงสร้างโปรเจกต์ (Project Structure)

เอกสารฉบับนี้อธิบายหน้าที่และความสำคัญของแต่ละไฟล์/โฟลเดอร์ในโปรเจกต์ เพื่อให้ทีมงานเข้าใจภาพรวมระบบได้ง่ายขึ้น

---

## 📂 โฟลเดอร์หลัก (Root Directory)

| ไฟล์ / โฟลเดอร์ | รายละเอียด |
| :--- | :--- |
| **`app/`** | **[ใหม่]** หัวใจหลักของระบบ (Core Logic) โค้ดที่ clean และ testable อยู่ที่นี่ |
| **`admin/`** | **[เดิม]** หน้าจัดการสำหรับแอดมิน (Controller + View ในตัว) |
| **`api/`** | **[เดิม]** Endpoint สำหรับ AJAX request ต่างๆ |
| **`database/`** | **[ใหม่]** ไฟล์ SQL สำหรับ Setup Database และ Migration |
| **`docs/`** | **[ใหม่]** คู่มือการใช้งานและเอกสารสำหรับ Developer |
| **`includes/`** | **[เดิม]** เลิกใช้เร็วๆ นี้ (Legacy Config & DB Connection) |
| **`tests/`** | **[ใหม่]** ไฟล์ทดสอบระบบอัตโนมัติ (Automated Tests) |
| `.env.example` | แม่แบบค่า Config (Database, URL) ต้อง copy เป็น `.env` ก่อนใช้งาน |
| `bootstrap.php` | ตัวช่วยโหลด Class และ Environment (Autoloader) |
| `index.php` | หน้าแรกของเว็บไซต์ (Landing Page) |
| `install.php` | สคริปต์ติดตั้งระบบ (สร้าง Database อัตโนมัติ) |

---

## 🧠 Business Logic (`app/`)

เป็นส่วนที่สำคัญที่สุด เก็บกฎของธุรกิจ (Business Rules) ทั้งหมดไว้ที่นี่

### `app/Services/` (บริการและคำนวณ)
- **`BorrowService.php`**: จัดการเรื่องยืม-คืน, คำนวณค่าปรับ, ตรวจโควต้าการยืม (แก้ Race Condition แล้ว)
- **`ReservationService.php`**: จัดการเรื่องจองหนังสือ, ล็อคสต็อก
- **`MemberService.php`**: จัดการสมาชิก, ตรวจสอบสิทธิ์
- **`BookService.php`**: ค้นหาหนังสือ, ตัดสต็อก
- **`ReportService.php`**: สร้างรายงานสรุปยอดต่าง ๆ

### `app/Repositories/` (ติดต่อฐานข้อมูล)
- **`BorrowRepository.php`**: คำสั่ง SQL เกี่ยวกับการยืมคืน
- (และ Repository อื่นๆ สำหรับดึงข้อมูลเพียวๆ)

### `app/Config/` & `Helpers/`
- **`settings.php`**: ค่าคงที่ระบบ
- **`functions.php`**: ฟังก์ชันช่วยเหลือทั่วไป

---

## 🛠️ Admin Panel (`admin/`)

หน้าจอการทำงานของผู้ดูแลระบบ

- **`index.php`**: หน้า Dashboard สรุปภาพรวม
- **`borrows.php`**: ตารางรายการยืม-คืน (กดคืนหนังสือที่นี่)
- **`borrow_form.php`**: ฟอร์มยืมหนังสือ (รองรับการยิงบาร์โค้ด)
- **`books.php` / `book_form.php`**: จัดการหนังสือ (เพิ่ม/ลบ/แก้ไข)
- **`members.php` / `member_form.php`**: จัดการสมาชิก
- **`reports.php`**: ดูรายงานสถิติ และ Export
- **`settings.php`**: ตั้งค่าระบบทั่วไป (ค่าปรับ, จำนวนวันยืม)

---

## 🔌 API & AJAX (`api/`)

- **`reserve_book.php`**: รับ request จองหนังสือจากหน้าเว็บ
- **`search_books.php`**: ค้นหาหนังสือแบบ Real-time (ใช้ในหน้าแรก)

---

## 🧪 Tests (`tests/`)

- **`test_quota.php`**: สคริปต์ทดสอบระบบโควต้าการยืม (ใช้ Verify ว่าแก้บั๊กแล้ว)
- **`reservation_test.php`**: ทดสอบระบบจอง

---
*เอกสารนี้อัปเดตล่าสุด: 31 มกราคม 2026*

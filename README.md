# 📚 ระบบยืมคืนหนังสือ (Book Borrowing System)

ระบบจัดการยืม-คืนหนังสือ พัฒนาด้วย PHP 8 และ MySQL  
ออกแบบสำหรับการเรียนรู้ โปรเจกต์การศึกษา และธุรกิจขนาดเล็ก

---

## ✨ ฟีเจอร์หลัก

### 👥 สำหรับสมาชิก
- สมัครสมาชิก / เข้าสู่ระบบ
- ค้นหาและเรียกดูหนังสือ
- ดูประวัติการยืมของตัวเอง
- แก้ไขโปรไฟล์และเปลี่ยนรหัสผ่าน

### 🔧 สำหรับผู้ดูแลระบบ (Admin)
- Dashboard แสดงสถิติภาพรวม
- จัดการหมวดหมู่หนังสือ (CRUD)
- จัดการหนังสือ (CRUD)
- บันทึกการยืม-คืนหนังสือ
- ดูรายการสมาชิกและประวัติการยืม
- แจ้งเตือนหนังสือเกินกำหนด

---

## 🛠️ ความต้องการระบบ

| รายการ | เวอร์ชันขั้นต่ำ |
|--------|----------------|
| PHP | 8.0+ |
| MySQL | 5.7+ / MariaDB 10.3+ |
| Web Server | Apache (XAMPP/WAMP/LAMP) |

---

## 🚀 การติดตั้ง

### 1. คัดลอกไฟล์
```
คัดลอกโฟลเดอร์ทั้งหมดไปที่ htdocs
ตัวอย่าง: C:\xampp\htdocs\book\
```

### 2. ตั้งค่าฐานข้อมูล
แก้ไขไฟล์ `includes/config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'book_borrowing');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### 3. รันการติดตั้ง
เปิดเบราว์เซอร์ไปที่:
```
http://localhost/book/install.php
```
กดปุ่ม "เริ่มติดตั้ง" เพื่อสร้างฐานข้อมูลอัตโนมัติ

### 4. เข้าใช้งาน
```
📧 Email: admin@library.com
🔑 Password: 123456
```

> ⚠️ **สำคัญ:** ลบไฟล์ `install.php` หลังติดตั้งเสร็จ

---

## 📁 โครงสร้างโฟลเดอร์

```
book/
├── admin/                  # หน้าผู้ดูแลระบบ
│   ├── index.php          # Dashboard
│   ├── categories.php     # จัดการหมวดหมู่
│   ├── books.php          # รายการหนังสือ
│   ├── book_form.php      # ฟอร์มเพิ่ม/แก้ไขหนังสือ
│   ├── borrows.php        # รายการยืม-คืน
│   ├── borrow_form.php    # ฟอร์มบันทึกการยืม
│   ├── members.php        # รายการสมาชิก
│   ├── header.php         # Template header
│   └── footer.php         # Template footer
├── includes/
│   ├── config.php         # ตั้งค่าระบบ
│   ├── db.php             # เชื่อมต่อฐานข้อมูล
│   ├── functions.php      # ฟังก์ชันช่วยเหลือ
│   ├── header.php         # Template header (หน้าบ้าน)
│   └── footer.php         # Template footer (หน้าบ้าน)
├── css/
│   └── style.css          # สไตล์ทั้งหมด
├── uploads/               # สำหรับอัพโหลดไฟล์
├── index.php              # หน้าแรก
├── book.php               # รายละเอียดหนังสือ
├── login.php              # เข้าสู่ระบบ
├── register.php           # สมัครสมาชิก
├── profile.php            # โปรไฟล์ผู้ใช้
├── logout.php             # ออกจากระบบ
└── install.php            # ติดตั้งระบบ
```

---

## 🔐 ความปลอดภัย

| มาตรการ | สถานะ |
|---------|--------|
| Password Hashing (bcrypt) | ✅ |
| SQL Injection Protection (PDO) | ✅ |
| XSS Protection (htmlspecialchars) | ✅ |
| CSRF Token | ✅ |
| Session Security | ✅ |
| Login Rate Limiting | ✅ |

---

## 📊 ฐานข้อมูล

### ตาราง
- `users` - ข้อมูลผู้ใช้งาน
- `categories` - หมวดหมู่หนังสือ
- `books` - ข้อมูลหนังสือ
- `borrows` - ประวัติการยืม-คืน

### ER Diagram
```
users (1) ──< (M) borrows (M) >── (1) books
                                        │
                                        └── (M:1) categories
```

---

## 🎨 เทคโนโลยีที่ใช้

- **Backend:** PHP 8, PDO
- **Database:** MySQL / MariaDB
- **Frontend:** Bootstrap 5, Bootstrap Icons
- **Font:** Google Fonts (Sarabun)

---

## ⚠️ ข้อจำกัด

ระบบนี้ออกแบบสำหรับ:
- ✅ การเรียนรู้ PHP/MySQL
- ✅ โปรเจกต์ส่งอาจารย์
- ✅ Demo ระบบ
- ✅ ธุรกิจขนาดเล็ก

**ไม่เหมาะสำหรับ:**
- ❌ Production ขนาดใหญ่
- ❌ ระบบที่ต้องการ High Availability
- ❌ ระบบที่มีผู้ใช้พร้อมกันหลายร้อยคน

---

## 📝 License

MIT License - ใช้งานได้อย่างอิสระทั้งส่วนตัวและเชิงพาณิชย์

---

## 👨‍💻 พัฒนาโดย

สร้างด้วย ❤️ สำหรับการเรียนรู้และใช้งานจริง

---

## 📞 การสนับสนุน

หากพบปัญหาหรือต้องการความช่วยเหลือ กรุณาติดต่อผู้พัฒนา

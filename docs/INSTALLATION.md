# 🚀 คู่มือการติดตั้ง (Installation Guide)

---

## ความต้องการระบบ

| รายการ | เวอร์ชันขั้นต่ำ |
|--------|----------------|
| PHP | 8.0+ |
| MySQL | 5.7+ / MariaDB 10.3+ |
| Web Server | Apache (XAMPP/WAMP/LAMP) |

---

## ขั้นตอนการติดตั้ง

### 1. คัดลอกไฟล์

```bash
# Clone หรือ extract ไฟล์ไปที่ htdocs
cd C:\xampp\htdocs
# วางโฟลเดอร์ที่นี่ เช่น book_borrowing/
```

### 2. ตั้งค่า Environment

```bash
# คัดลอกไฟล์ config ตัวอย่าง
cp .env.example .env

# แก้ไข .env ตามความต้องการ
```

**ค่าที่ต้องแก้:**
```env
DB_HOST=localhost
DB_NAME=book_borrowing
DB_USER=root
DB_PASS=your_password
APP_URL=http://localhost/book_borrowing
```

### 3. รันการติดตั้ง

เปิดเบราว์เซอร์ไปที่:
```
http://localhost/book_borrowing/install.php
```

กดปุ่ม **"เริ่มติดตั้ง"** เพื่อสร้างฐานข้อมูลอัตโนมัติ

### 4. เข้าใช้งาน

**Admin Account:**
```
📧 Email: admin@library.com
🔑 Password: 123456
```

---

## ⚠️ สิ่งสำคัญหลังติดตั้ง

### 1. ลบไฟล์ install.php
```bash
rm install.php
# หรือลบผ่าน File Explorer
```

### 2. เปลี่ยนรหัสผ่าน Admin
1. เข้าสู่ระบบด้วย admin@library.com
2. ไปที่ Profile
3. เปลี่ยนรหัสผ่านใหม่

### 3. ตั้งค่า File Permissions (Linux/Mac)
```bash
chmod 755 uploads/
chmod 600 .env
```

---

## การติดตั้งบน Production Server

### 1. อัปโหลดไฟล์
- ใช้ FTP/SFTP อัปโหลดทั้งโฟลเดอร์

### 2. สร้างฐานข้อมูล
- สร้าง database ผ่าน cPanel/phpMyAdmin
- แก้ไข `.env` ให้ตรงกับ database ที่สร้าง

### 3. ตั้งค่า HTTPS
- แนะนำให้ใช้ SSL Certificate
- แก้ `APP_URL` ใน `.env` เป็น `https://`

### 4. ปิด Debug Mode
```env
APP_DEBUG=false
```

---

## การแก้ไขปัญหา

### ❌ Database connection failed
- ตรวจสอบค่า DB_* ใน `.env`
- ตรวจสอบว่า MySQL service รันอยู่

### ❌ 404 Not Found
- ตรวจสอบ `APP_URL` ใน `.env`
- ตรวจสอบ Apache mod_rewrite

### ❌ Permission denied (uploads)
```bash
chmod -R 755 uploads/
chown -R www-data:www-data uploads/
```

---

## ขั้นตอนถัดไป

หลังติดตั้งเสร็จ:
1. อ่าน [CUSTOMIZATION.md](CUSTOMIZATION.md) เพื่อปรับแต่งระบบ
2. อ่าน [API.md](API.md) หากต้องการใช้ API

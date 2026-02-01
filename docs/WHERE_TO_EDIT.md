# 📍 จุดที่ควรแก้ไข (WHERE TO EDIT)

คู่มือนี้สำหรับผู้ซื้อ template ที่ต้องการปรับแต่งระบบ

---

## ⚙️ ตั้งค่าทั่วไป

| ต้องการแก้ | ไฟล์ | ตัวอย่าง |
|-----------|------|---------|
| Database connection | `.env` | `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` |
| URL ของระบบ | `.env` | `APP_URL=https://yourdomain.com` |
| ชื่อระบบ | `.env` | `APP_NAME="ห้องสมุดโรงเรียน"` |
| Timezone | `.env` | `TIMEZONE=Asia/Bangkok` |

---

## 📚 กฎการยืม-คืน

| ต้องการแก้ | ไฟล์ | ค่า |
|-----------|------|-----|
| จำนวนวันยืมเริ่มต้น | `.env` | `DEFAULT_BORROW_DAYS=7` |
| ยืมได้สูงสุดกี่เล่ม | `.env` | `MAX_BORROW_BOOKS=3` |
| ค่าปรับต่อวัน (บาท) | `.env` | `FINE_PER_DAY=10` |
| สูตรคำนวณค่าปรับ | `app/Services/BorrowService.php` | function `calculateFine()` |

---

## 🎨 หน้าตา (UI)

| ต้องการแก้ | ไฟล์ |
|-----------|------|
| Logo, ชื่อเว็บ (หน้าบ้าน) | `includes/header.php` |
| Logo, เมนู (Admin) | `admin/header.php` |
| สี, font, spacing | `css/style.css` |
| Footer | `includes/footer.php`, `admin/footer.php` |

---

## 🔐 ความปลอดภัย

| ต้องการแก้ | ไฟล์ | ค่า |
|-----------|------|-----|
| รหัสผ่านขั้นต่ำ | `.env` | `MIN_PASSWORD_LENGTH=6` |
| จำนวนครั้งที่ผิดก่อน lock | `.env` | `RATE_LIMIT_MAX_ATTEMPTS=5` |
| เวลา lock (นาที) | `.env` | `RATE_LIMIT_WINDOW_MINUTES=15` |
| อายุ session (วินาที) | `.env` | `SESSION_LIFETIME=3600` |

---

## 📧 อีเมล Admin เริ่มต้น

| ต้องการแก้ | ไฟล์ |
|-----------|------|
| อีเมล admin ตอนติดตั้ง | `install.php` (บรรทัด ~200) |

> ⚠️ หลังติดตั้งแล้ว ให้เปลี่ยนรหัสผ่านผ่านหน้า Profile

---

## 🗂️ โครงสร้างไฟล์สำคัญ

```
book_borrowing/
├── .env                    ← ⭐ ตั้งค่าหลักทั้งหมด
├── includes/
│   ├── config.php          ← อ่านค่าจาก .env (ไม่ต้องแก้)
│   ├── header.php          ← Header หน้าบ้าน
│   └── footer.php          ← Footer หน้าบ้าน
├── admin/
│   ├── header.php          ← Header admin
│   └── footer.php          ← Footer admin
├── app/Services/
│   ├── BorrowService.php   ← กฎการยืม/ค่าปรับ
│   └── AuthService.php     ← Login/Register logic
└── css/
    └── style.css           ← สไตล์ทั้งหมด
```

---

## ⚠️ จุดที่ห้ามแก้ (ถ้าไม่เข้าใจ)

| ไฟล์ | เหตุผล |
|------|--------|
| `includes/functions.php` → `e()` | ป้องกัน XSS ทั้งระบบ |
| `includes/functions.php` → `validateCSRFToken()` | ป้องกัน CSRF |
| `app/Repositories/*.php` → `FOR UPDATE` | ป้องกัน race condition |
| `bootstrap.php` | จุดเริ่มต้นระบบ |

---

## 🧪 Test หลังแก้ไข

หลังแก้ไขค่าใดๆ ควรทดสอบ:

1. **Login/Logout** - เข้าสู่ระบบได้ปกติ
2. **ยืมหนังสือ** - สต็อกลดถูกต้อง
3. **คืนหนังสือ** - สต็อกเพิ่ม + ค่าปรับถูกต้อง
4. **สมัครสมาชิก** - validation ทำงาน

---

## 📞 ต้องการความช่วยเหลือ?

ดูเอกสารเพิ่มเติม:
- [INSTALLATION.md](INSTALLATION.md) - การติดตั้ง
- [CUSTOMIZATION.md](CUSTOMIZATION.md) - การปรับแต่งขั้นสูง
- [DATABASE.md](DATABASE.md) - โครงสร้างฐานข้อมูล

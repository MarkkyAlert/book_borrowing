# 🛠️ คู่มือการปรับแต่งระบบ (Customization Guide)

คู่มือนี้อธิบายวิธีการปรับแต่งระบบยืมคืนหนังสือสำหรับการใช้งานจริง

---

## 📋 สารบัญ

1. [ตั้งค่าพื้นฐาน (.env)](#1-ตั้งค่าพื้นฐาน-env)
2. [แก้ไขกฎการยืม](#2-แก้ไขกฎการยืม)
3. [แก้ไข UI และ Branding](#3-แก้ไข-ui-และ-branding)
4. [แก้ไขข้อความในระบบ](#4-แก้ไขข้อความในระบบ)
5. [เพิ่ม Field ใหม่](#5-เพิ่ม-field-ใหม่)

---

## 1. ตั้งค่าพื้นฐาน (.env)

### ขั้นตอน:
1. คัดลอกไฟล์ `.env.example` เป็น `.env`
2. แก้ไขค่าตามต้องการ

### ค่าที่สำคัญ:

```env
# ฐานข้อมูล
DB_HOST=localhost
DB_NAME=book_borrowing
DB_USER=root
DB_PASS=your_password

# URL ของเว็บไซต์ (ไม่ต้องมี / ต่อท้าย)
APP_URL=http://your-domain.com/library

# ชื่อระบบ (แสดงใน header)
APP_NAME="ห้องสมุดโรงเรียน ABC"

# กฎการยืม
DEFAULT_BORROW_DAYS=7      # วันที่ยืมได้
MAX_BORROW_BOOKS=3         # จำนวนเล่มสูงสุด
FINE_PER_DAY=10            # ค่าปรับต่อวัน (บาท)
```

---

## 2. แก้ไขกฎการยืม

### 2.1 เปลี่ยนจำนวนวันยืม

**วิธีง่าย:** แก้ใน `.env`
```env
DEFAULT_BORROW_DAYS=14
```

**วิธีเดิม:** แก้ใน `includes/config.php`
```php
define('DEFAULT_BORROW_DAYS', 14);
```

### 2.2 เปลี่ยนค่าปรับ

**วิธีง่าย:** แก้ใน `.env`
```env
FINE_PER_DAY=20
```

**วิธีเดิม:** แก้ใน `includes/config.php`
```php
define('FINE_PER_DAY', 20);
```

### 2.3 เปลี่ยนจำนวนหนังสือที่ยืมได้

**วิธีง่าย:** แก้ใน `.env`
```env
MAX_BORROW_BOOKS=5
```

### 2.4 สูตรคำนวณค่าปรับ (Advanced)

ถ้าต้องการเปลี่ยนสูตรคำนวณค่าปรับ (เช่น คิดแบบขั้นบันได)

📁 **ไฟล์:** `app/Services/BorrowService.php`  
📍 **เมธอด:** `calculateFine()`

> **หมายเหตุ:** ระบบใหม่ใช้ BorrowService แทน functions.php

```php
function calculateFine(string $dueDate, ?string $returnDate = null): array
{
    $due = new DateTime($dueDate);
    $returnDateStr = (!empty($returnDate)) ? $returnDate : date('Y-m-d');
    $return = new DateTime($returnDateStr);
    
    if ($return > $due) {
        $daysOverdue = $return->diff($due)->days;
        
        // === แก้ไขสูตรตรงนี้ ===
        // ตัวอย่าง: คิดแบบขั้นบันได
        // 1-3 วัน: 10 บาท/วัน
        // 4-7 วัน: 20 บาท/วัน
        // 8+ วัน: 30 บาท/วัน
        
        if ($daysOverdue <= 3) {
            $fineAmount = $daysOverdue * 10;
        } elseif ($daysOverdue <= 7) {
            $fineAmount = (3 * 10) + (($daysOverdue - 3) * 20);
        } else {
            $fineAmount = (3 * 10) + (4 * 20) + (($daysOverdue - 7) * 30);
        }
        // === จบส่วนแก้ไข ===
        
        return ['days' => $daysOverdue, 'amount' => $fineAmount];
    }
    
    return ['days' => 0, 'amount' => 0];
}
```

---

## 3. แก้ไข UI และ Branding

### 3.1 เปลี่ยนชื่อระบบ

แก้ใน `.env`:
```env
APP_NAME="ห้องสมุดโรงเรียน XYZ"
```

### 3.2 เปลี่ยน Logo

📁 **ไฟล์:** `includes/header.php` (หน้าบ้าน)  
📁 **ไฟล์:** `admin/header.php` (หน้า Admin)

ค้นหาและแก้ไข:
```html
<!-- เปลี่ยนจาก icon เป็นรูป logo -->
<img src="<?= APP_URL ?>/images/logo.png" alt="Logo" class="h-8">
```

### 3.3 เปลี่ยนสี Theme

📁 **ไฟล์:** `css/style.css`

ระบบใช้ Tailwind CSS ผ่าน CDN ถ้าต้องการเปลี่ยนสี primary:

```css
/* เพิ่มที่ท้ายไฟล์ */
:root {
    --color-primary: #your-color;
}
```

### 3.4 เปลี่ยน Footer

📁 **ไฟล์:** `includes/footer.php`

---

## 4. แก้ไขข้อความในระบบ

### ข้อความ Flash Messages

📁 **ไฟล์:** แต่ละหน้าที่ใช้ `setFlash()`

ตัวอย่างใน `admin/borrows.php`:
```php
setFlash('success', 'บันทึกการคืนหนังสือสำเร็จ');
```

### ข้อความ Validation

📁 **ไฟล์:** แต่ละหน้าที่มี form เช่น `login.php`, `register.php`

---

## 5. เพิ่ม Field ใหม่

### ตัวอย่าง: เพิ่ม field "สำนักพิมพ์" ในหนังสือ

#### ขั้นตอนที่ 1: แก้ฐานข้อมูล
```sql
ALTER TABLE books ADD COLUMN publisher VARCHAR(100) DEFAULT NULL AFTER author;
```

#### ขั้นตอนที่ 2: แก้ฟอร์ม
📁 **ไฟล์:** `admin/book_form.php`

เพิ่ม input field:
```html
<div class="mb-4">
    <label class="block text-sm font-medium text-gray-700">สำนักพิมพ์</label>
    <input type="text" name="publisher" value="<?= e($book['publisher'] ?? '') ?>" 
           class="mt-1 block w-full rounded-md border-gray-300">
</div>
```

#### ขั้นตอนที่ 3: แก้ SQL INSERT/UPDATE
📁 **ไฟล์:** `admin/book_form.php`

เพิ่ม `publisher` ใน query

---

## ⚠️ คำเตือน

1. **สำรองข้อมูลก่อนแก้ไขเสมอ**
2. **ทดสอบใน localhost ก่อน deploy**
3. **อย่าแก้ไฟล์ใน `includes/db.php`** ถ้าไม่จำเป็น
4. **เก็บ `.env` ให้ปลอดภัย** - อย่า commit ขึ้น git

---

## 📞 ต้องการความช่วยเหลือ?

หากต้องการปรับแต่งเพิ่มเติมที่ซับซ้อน กรุณาติดต่อผู้พัฒนา

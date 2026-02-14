# Database Model — ระบบยืมคืนหนังสือ

> เอกสารนี้อธิบายโครงสร้างฐานข้อมูลทั้งหมด วิเคราะห์จาก `database/schema.sql` โดยตรง  
> Database: MySQL 5.7+ / MariaDB 10.3+ | Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci

---

## สารบัญ

1. [ภาพรวมตาราง](#1-ภาพรวมตาราง)
2. [Entity Relationship Diagram (Text)](#2-entity-relationship-diagram)
3. [รายละเอียดแต่ละตาราง](#3-รายละเอียดแต่ละตาราง)
4. [Foreign Keys ทั้งหมด](#4-foreign-keys-ทั้งหมด)
5. [Constraints ทั้งหมด](#5-constraints-ทั้งหมด)
6. [Indexes ทั้งหมด](#6-indexes-ทั้งหมด)
7. [ความสัมพันธ์ระหว่างตาราง](#7-ความสัมพันธ์ระหว่างตาราง)
8. [จุดเสี่ยงด้าน Data Integrity](#8-จุดเสี่ยงด้าน-data-integrity)
9. [แหล่งอ้างอิง](#9-แหล่งอ้างอิง)

---

## 1. ภาพรวมตาราง

| # | ตาราง | คำอธิบาย | จำนวน Fields |
|---|-------|---------|-------------|
| 1 | `users` | ผู้ใช้งาน (admin, staff, member) | 8 |
| 2 | `categories` | หมวดหมู่หนังสือ | 4 |
| 3 | `books` | หนังสือ | 11 |
| 4 | `borrows` | รายการยืม | 10 |
| 5 | `reservations` | รายการจอง | 7 |
| 6 | `payments` | การชำระค่าปรับ | 5 |
| 7 | `password_resets` | Token รีเซ็ตรหัสผ่าน | 6 |
| 8 | `settings` | ตั้งค่าระบบ (key-value) | 4 |
| 9 | `rate_limits` | จำกัดจำนวนครั้งต่อ IP (brute force) | 3 |

**รวม: 9 ตาราง, 58 fields**

---

## 2. Entity Relationship Diagram

```
                          ┌──────────────┐
                          │  categories  │
                          │──────────────│
                          │ id (PK)      │
                          │ name (UQ)    │
                          └──────┬───────┘
                                 │ 1
                                 │
                                 │ 0..N
                          ┌──────┴───────┐
    ┌─────────────┐       │    books     │       ┌──────────────┐
    │   users     │       │──────────────│       │   settings   │
    │─────────────│       │ id (PK)      │       │──────────────│
    │ id (PK)     │       │ isbn (UQ)    │       │ id (PK)      │
    │ email (UQ)  │       │ category_id  │──FK──→│ setting_key  │
    │ role (ENUM) │       │ quantity     │       └──────────────┘
    └──┬──┬──┬────┘       │ available    │
       │  │  │            │ [CHECK ≥ 0]  │       ┌──────────────┐
       │  │  │            │ [CHECK ≤ qty]│       │ rate_limits  │
       │  │  │            └──┬────┬──────┘       │──────────────│
       │  │  │               │    │              │ id (PK)      │
       │  │  │    ┌──────────┘    │              │ key_name     │
       │  │  │    │               │              └──────────────┘
       │  │  │    │ 0..N          │ 0..N
       │  │  │    │               │         ┌────────────────┐
       │  │  │  ┌─┴───────────┐   │         │password_resets │
       │  │  │  │   borrows   │   │         │────────────────│
       │  │  │  │─────────────│   │         │ id (PK)        │
       │  │  └──│ user_id     │   │         │ email           │
       │  │     │ book_id     │──FK         │ token (UQ)     │
       │  │     │ status(ENUM)│             └────────────────┘
       │  │     │ fine_amount │
       │  │     └──┬──┬───────┘
       │  │        │  │
       │  │        │  │ 0..1 (UNIQUE)
       │  │        │  │
       │  │        │ ┌┴────────────┐
       │  │        │ │  payments   │
       │  │        │ │─────────────│
       │  │        │ │ borrow_id   │──FK──→ borrows.id
       │  │        │ │ recorded_by │──FK──→ users.id
       │  │        │ │ amount      │
       │  │        │ └─────────────┘
       │  │        │
       │  │        │ 0..1
       │  │        │
       │  │  ┌─────┴────────────┐
       │  │  │  reservations    │
       │  └──│─────────────────│
       │     │ user_id          │──FK──→ users.id
       │     │ book_id          │──FK──→ books.id
       │     │ borrow_id        │──FK──→ borrows.id
       │     │ status (ENUM)    │
       │     └──────────────────┘
       │
       │ (email ใช้อ้างอิง ไม่ใช่ FK)
       │
       └─ password_resets.email
```

---

## 3. รายละเอียดแต่ละตาราง

### 3.1 `users` — ผู้ใช้งาน

| Field | Type | Null | Default | คำอธิบาย |
|-------|------|------|---------|---------|
| `id` | INT | NO | AUTO_INCREMENT | **PK** |
| `name` | VARCHAR(100) | NO | — | ชื่อ-นามสกุล |
| `email` | VARCHAR(100) | NO | — | อีเมล **(UNIQUE)** |
| `password` | VARCHAR(255) | NO | — | รหัสผ่าน (bcrypt hash) |
| `phone` | VARCHAR(20) | YES | NULL | เบอร์โทรศัพท์ |
| `role` | ENUM('member','admin','staff') | NO | 'member' | บทบาท |
| `created_at` | DATETIME | YES | CURRENT_TIMESTAMP | วันสร้าง |
| `updated_at` | DATETIME | YES | CURRENT_TIMESTAMP ON UPDATE | วันแก้ไข |

---

### 3.2 `categories` — หมวดหมู่หนังสือ

| Field | Type | Null | Default | คำอธิบาย |
|-------|------|------|---------|---------|
| `id` | INT | NO | AUTO_INCREMENT | **PK** |
| `name` | VARCHAR(100) | NO | — | ชื่อหมวดหมู่ **(UNIQUE)** |
| `created_at` | DATETIME | YES | CURRENT_TIMESTAMP | วันสร้าง |
| `updated_at` | DATETIME | YES | CURRENT_TIMESTAMP ON UPDATE | วันแก้ไข |

---

### 3.3 `books` — หนังสือ

| Field | Type | Null | Default | คำอธิบาย |
|-------|------|------|---------|---------|
| `id` | INT | NO | AUTO_INCREMENT | **PK** |
| `title` | VARCHAR(200) | NO | — | ชื่อหนังสือ |
| `author` | VARCHAR(100) | NO | — | ผู้แต่ง |
| `isbn` | VARCHAR(20) | YES | NULL | รหัส ISBN **(UNIQUE)** |
| `category_id` | INT | YES | NULL | **FK** → `categories.id` |
| `description` | TEXT | YES | NULL | รายละเอียด |
| `cover_image` | VARCHAR(255) | YES | NULL | ชื่อไฟล์รูปปก |
| `quantity` | INT | NO | 1 | จำนวนทั้งหมด |
| `available` | INT | NO | 1 | จำนวนที่ว่าง |
| `created_at` | DATETIME | YES | CURRENT_TIMESTAMP | วันสร้าง |
| `updated_at` | DATETIME | YES | CURRENT_TIMESTAMP ON UPDATE | วันแก้ไข |

**CHECK Constraints:**
- `chk_books_available_non_negative`: `available >= 0`
- `chk_books_quantity_gte_available`: `quantity >= available`

---

### 3.4 `borrows` — รายการยืม

| Field | Type | Null | Default | คำอธิบาย |
|-------|------|------|---------|---------|
| `id` | INT | NO | AUTO_INCREMENT | **PK** |
| `user_id` | INT | NO | — | **FK** → `users.id` |
| `book_id` | INT | NO | — | **FK** → `books.id` |
| `borrow_date` | DATE | NO | — | วันที่ยืม |
| `due_date` | DATE | NO | — | กำหนดคืน |
| `return_date` | DATE | YES | NULL | วันที่คืนจริง (NULL = ยังไม่คืน) |
| `status` | ENUM('borrowing','returned') | NO | 'borrowing' | สถานะ |
| `fine_amount` | DECIMAL(10,2) | YES | 0 | ค่าปรับ (บาท) |
| `notes` | TEXT | YES | NULL | หมายเหตุ |
| `created_at` | DATETIME | YES | CURRENT_TIMESTAMP | วันสร้าง |
| `updated_at` | DATETIME | YES | CURRENT_TIMESTAMP ON UPDATE | วันแก้ไข |

---

### 3.5 `reservations` — รายการจอง

| Field | Type | Null | Default | คำอธิบาย |
|-------|------|------|---------|---------|
| `id` | INT | NO | AUTO_INCREMENT | **PK** |
| `user_id` | INT | NO | — | **FK** → `users.id` |
| `book_id` | INT | NO | — | **FK** → `books.id` |
| `borrow_id` | INT | YES | NULL | **FK** → `borrows.id` (เฉพาะ fulfilled) |
| `status` | ENUM('pending','fulfilled','expired','cancelled') | NO | 'pending' | สถานะ |
| `expires_at` | DATETIME | NO | — | วันหมดอายุการจอง |
| `created_at` | DATETIME | YES | CURRENT_TIMESTAMP | วันสร้าง |

> **หมายเหตุ:** ไม่มี `updated_at` — สถานะเปลี่ยนแบบ one-way (pending → fulfilled/expired/cancelled)

---

### 3.6 `payments` — การชำระค่าปรับ

| Field | Type | Null | Default | คำอธิบาย |
|-------|------|------|---------|---------|
| `id` | INT | NO | AUTO_INCREMENT | **PK** |
| `borrow_id` | INT | NO | — | **FK** → `borrows.id` **(UNIQUE)** |
| `amount` | DECIMAL(10,2) | NO | — | จำนวนเงิน (บาท) |
| `recorded_by` | INT | YES | NULL | **FK** → `users.id` (ผู้บันทึก) |
| `created_at` | DATETIME | YES | CURRENT_TIMESTAMP | วันบันทึก |

> **UNIQUE** บน `borrow_id` → 1 การยืม = ชำระค่าปรับได้ 1 ครั้งเท่านั้น

---

### 3.7 `password_resets` — Token รีเซ็ตรหัสผ่าน

| Field | Type | Null | Default | คำอธิบาย |
|-------|------|------|---------|---------|
| `id` | INT | NO | AUTO_INCREMENT | **PK** |
| `email` | VARCHAR(100) | NO | — | อีเมลที่ขอรีเซ็ต |
| `token` | VARCHAR(64) | NO | — | Token สำหรับรีเซ็ต **(UNIQUE)** |
| `expires_at` | DATETIME | NO | — | วันหมดอายุ |
| `used` | TINYINT(1) | NO | 0 | ใช้แล้วหรือยัง (0=ยังไม่ใช้, 1=ใช้แล้ว) |
| `created_at` | DATETIME | YES | CURRENT_TIMESTAMP | วันสร้าง |

> **หมายเหตุ:** `email` ไม่ได้เป็น FK ไปยัง `users.email` — ใช้ค่า string อ้างอิงโดยตรง

---

### 3.8 `settings` — ตั้งค่าระบบ

| Field | Type | Null | Default | คำอธิบาย |
|-------|------|------|---------|---------|
| `id` | INT | NO | AUTO_INCREMENT | **PK** |
| `setting_key` | VARCHAR(50) | NO | — | Key **(UNIQUE)** |
| `setting_value` | TEXT | YES | NULL | Value |
| `created_at` | DATETIME | YES | CURRENT_TIMESTAMP | วันสร้าง |
| `updated_at` | DATETIME | YES | CURRENT_TIMESTAMP ON UPDATE | วันแก้ไข |

---

### 3.9 `rate_limits` — จำกัดจำนวนครั้งต่อ IP

| Field | Type | Null | Default | คำอธิบาย |
|-------|------|------|---------|---------|
| `id` | INT | NO | AUTO_INCREMENT | **PK** |
| `key_name` | VARCHAR(255) | NO | — | action + IP เช่น `login_127.0.0.1` |
| `created_at` | DATETIME | YES | CURRENT_TIMESTAMP | เวลาที่เกิด attempt |

> **กลไก:** แต่ละ attempt สร้าง 1 row → นับจำนวน row ใน window เวลา → ถ้าเกิน limit จะบล็อก  
> **Cleanup:** `bootstrap.php` ลบ row เก่ากว่า 1 วันแบบ probabilistic (~1% ของ request)

---

## 4. Foreign Keys ทั้งหมด

| # | ตารางลูก | คอลัมน์ | ตารางแม่ | คอลัมน์ | ON DELETE | ON UPDATE |
|---|---------|---------|---------|---------|-----------|-----------|
| 1 | `books` | `category_id` | `categories` | `id` | **SET NULL** | CASCADE |
| 2 | `borrows` | `user_id` | `users` | `id` | **RESTRICT** | CASCADE |
| 3 | `borrows` | `book_id` | `books` | `id` | **RESTRICT** | CASCADE |
| 4 | `reservations` | `user_id` | `users` | `id` | **RESTRICT** | CASCADE |
| 5 | `reservations` | `book_id` | `books` | `id` | **RESTRICT** | CASCADE |
| 6 | `reservations` | `borrow_id` | `borrows` | `id` | **SET NULL** | CASCADE |
| 7 | `payments` | `borrow_id` | `borrows` | `id` | **CASCADE** | CASCADE |
| 8 | `payments` | `recorded_by` | `users` | `id` | **SET NULL** | CASCADE |

### สรุป ON DELETE Strategy

| Strategy | ใช้ที่ | เหตุผล |
|----------|-------|--------|
| **RESTRICT** | borrows → users, borrows → books, reservations → users, reservations → books | ห้ามลบ user/book ที่มีประวัติยืม/จอง — ป้องกันข้อมูลหาย |
| **SET NULL** | books → categories, reservations → borrows, payments → users | ลบได้ แต่อ้างอิงเปลี่ยนเป็น NULL |
| **CASCADE** | payments → borrows | ลบ borrow → ลบ payment ที่ผูกอยู่ด้วย |

---

## 5. Constraints ทั้งหมด

### Primary Keys

| ตาราง | คอลัมน์ | Type |
|-------|---------|------|
| ทุกตาราง | `id` | INT AUTO_INCREMENT |

### UNIQUE Constraints

| # | ตาราง | คอลัมน์ | ชื่อ Index |
|---|-------|---------|-----------|
| 1 | `users` | `email` | (implicit) |
| 2 | `categories` | `name` | (implicit) |
| 3 | `books` | `isbn` | `uq_isbn` |
| 4 | `payments` | `borrow_id` | `unique_borrow_payment` |
| 5 | `password_resets` | `token` | (implicit) |
| 6 | `settings` | `setting_key` | (implicit) |

### CHECK Constraints

| # | ตาราง | ชื่อ | เงื่อนไข | ป้องกันอะไร |
|---|-------|------|---------|------------|
| 1 | `books` | `chk_books_available_non_negative` | `available >= 0` | stock ติดลบ |
| 2 | `books` | `chk_books_quantity_gte_available` | `quantity >= available` | available เกิน quantity |

### ENUM Constraints

| # | ตาราง | คอลัมน์ | ค่าที่รับ |
|---|-------|---------|----------|
| 1 | `users` | `role` | `member`, `admin`, `staff` |
| 2 | `borrows` | `status` | `borrowing`, `returned` |
| 3 | `reservations` | `status` | `pending`, `fulfilled`, `expired`, `cancelled` |

---

## 6. Indexes ทั้งหมด

| # | ตาราง | ชื่อ Index | คอลัมน์ | ประเภท |
|---|-------|-----------|---------|--------|
| 1 | `users` | PRIMARY | `id` | PK |
| 2 | `users` | `email` | `email` | UNIQUE |
| 3 | `users` | `idx_email` | `email` | INDEX |
| 4 | `users` | `idx_role` | `role` | INDEX |
| 5 | `categories` | PRIMARY | `id` | PK |
| 6 | `categories` | `name` | `name` | UNIQUE |
| 7 | `books` | PRIMARY | `id` | PK |
| 8 | `books` | `uq_isbn` | `isbn` | UNIQUE |
| 9 | `books` | `idx_available` | `available` | INDEX |
| 10 | `books` | `idx_category` | `category_id` | INDEX |
| 11 | `borrows` | PRIMARY | `id` | PK |
| 12 | `borrows` | `idx_status` | `status` | INDEX |
| 13 | `borrows` | `idx_user` | `user_id` | INDEX |
| 14 | `borrows` | `idx_book` | `book_id` | INDEX |
| 15 | `borrows` | `idx_due_date` | `due_date` | INDEX |
| 16 | `reservations` | PRIMARY | `id` | PK |
| 17 | `reservations` | `idx_status` | `status` | INDEX |
| 18 | `reservations` | `idx_user` | `user_id` | INDEX |
| 19 | `reservations` | `idx_book` | `book_id` | INDEX |
| 20 | `payments` | PRIMARY | `id` | PK |
| 21 | `payments` | `unique_borrow_payment` | `borrow_id` | UNIQUE |
| 22 | `payments` | `idx_borrow` | `borrow_id` | INDEX |
| 23 | `password_resets` | PRIMARY | `id` | PK |
| 24 | `password_resets` | `token` | `token` | UNIQUE |
| 25 | `password_resets` | `idx_email` | `email` | INDEX |
| 26 | `password_resets` | `idx_token` | `token` | INDEX |
| 27 | `password_resets` | `idx_expires` | `expires_at` | INDEX |
| 28 | `settings` | PRIMARY | `id` | PK |
| 29 | `settings` | `setting_key` | `setting_key` | UNIQUE |
| 30 | `rate_limits` | PRIMARY | `id` | PK |
| 31 | `rate_limits` | `idx_key_name` | `key_name` | INDEX |
| 32 | `rate_limits` | `idx_created_at` | `created_at` | INDEX |

**รวม: 32 indexes**

---

## 7. ความสัมพันธ์ระหว่างตาราง

### ตาราง `users` (ศูนย์กลาง)

```
users 1 ──→ N borrows         (ผู้ใช้ยืมหนังสือได้หลายครั้ง)
users 1 ──→ N reservations    (ผู้ใช้จองหนังสือได้หลายครั้ง)
users 1 ──→ N payments        (ผู้ใช้บันทึกค่าปรับได้หลายรายการ — ในฐานะ recorded_by)
```

### ตาราง `books` (ศูนย์กลาง)

```
categories 1 ──→ N books      (หมวดหมู่มีหนังสือได้หลายเล่ม)
books 1 ──→ N borrows         (หนังสือถูกยืมได้หลายครั้ง)
books 1 ──→ N reservations    (หนังสือถูกจองได้หลายครั้ง)
```

### วงจรการยืม (Borrow Lifecycle)

```
users ─┐
       ├──→ borrows ──→ payments        (ยืม → ชำระค่าปรับ)
books ─┘        ↑
                │
       reservations.borrow_id           (จอง → ยืม เมื่อ fulfilled)
```

### ตารางอิสระ (ไม่มี FK)

| ตาราง | เหตุผล |
|-------|--------|
| `password_resets` | ใช้ `email` (string) อ้างอิงผู้ใช้ ไม่ได้ใช้ FK |
| `settings` | key-value store สำหรับ config ระบบ |
| `rate_limits` | เก็บ attempt แยกตาม IP ไม่ผูกกับ user |

---

## 8. จุดเสี่ยงด้าน Data Integrity

### 8.1 ⚠️ `password_resets.email` ไม่ผูก FK กับ `users.email`

| | |
|---|---|
| **สถานะ** | ยอมรับได้ — เป็น design choice |
| **ความเสี่ยง** | ถ้า user เปลี่ยน email → token เก่ายังอ้างอิง email เดิม |
| **เหตุผลที่ยอมรับ** | token มีอายุสั้น (หมดเวลาใน 1 ชม.) + cron ลบ token หมดอายุทุกวัน |
| **ไฟล์ที่จัดการ** | `cron/cleanup_tokens.php` |

### 8.2 ⚠️ `payments` → `borrows` ใช้ ON DELETE CASCADE

| | |
|---|---|
| **สถานะ** | ควรระวัง |
| **ความเสี่ยง** | ถ้าลบ borrow → payment จะถูกลบด้วย → ประวัติการชำระเงินหายไป |
| **การป้องกันปัจจุบัน** | FK RESTRICT บน `borrows` → `users` และ `borrows` → `books` ทำให้ลบ borrow ยากอยู่แล้ว (ต้องไม่มี user/book ที่ผูกอยู่) |
| **คำแนะนำ** | ในทางปฏิบัติ borrow แทบไม่ถูกลบ — ระบบไม่มี UI สำหรับลบ borrow |

### 8.3 ⚠️ `borrows` ไม่มี UNIQUE constraint ป้องกันยืมซ้ำ

| | |
|---|---|
| **สถานะ** | ยอมรับได้ — มีการป้องกันระดับ application |
| **ความเสี่ยง** | ทาง DB ไม่ได้บังคับว่า user เดียวกันยืม book เดียวกันซ้ำไม่ได้ (ขณะสถานะ borrowing) |
| **การป้องกันปัจจุบัน** | `BorrowService::createBorrow()` ตรวจ `MAX_BORROW_BOOKS` + `available > 0` ด้วย `SELECT FOR UPDATE` |
| **ไฟล์ที่จัดการ** | `app/Services/BorrowService.php` |

### 8.4 ⚠️ `reservations` ไม่มี UNIQUE constraint ป้องกันจองซ้ำ

| | |
|---|---|
| **สถานะ** | ยอมรับได้ — มีการป้องกันระดับ application |
| **ความเสี่ยง** | ทาง DB ไม่ได้บังคับว่า user เดียวกันจอง book เดียวกันซ้ำไม่ได้ (ขณะสถานะ pending) |
| **การป้องกันปัจจุบัน** | `ReservationService` ตรวจซ้ำก่อน INSERT |
| **ไฟล์ที่จัดการ** | `app/Services/ReservationService.php` |

### 8.5 ⚠️ `books.available` ต้อง sync กับจำนวนยืม/จองจริง

| | |
|---|---|
| **สถานะ** | ต้องระวัง — เป็น denormalized counter |
| **ความเสี่ยง** | ถ้า `available` ไม่ตรงกับ `quantity - (active borrows + pending reservations)` → stock ผิดพลาด |
| **การป้องกันปัจจุบัน** | CHECK constraint ป้องกันค่าติดลบ/เกิน + row-level locking (`SELECT FOR UPDATE`) ป้องกัน race condition |
| **ไฟล์ที่จัดการ** | `app/Services/BorrowService.php`, `app/Services/ReservationService.php` |
| **ถ้าเกิดปัญหา** | ต้อง recalculate available ด้วย SQL: `UPDATE books SET available = quantity - (SELECT COUNT(*) FROM borrows WHERE book_id = books.id AND status = 'borrowing') - (SELECT COUNT(*) FROM reservations WHERE book_id = books.id AND status = 'pending')` |

### 8.6 ⚠️ `rate_limits.key_name` ไม่มี UNIQUE constraint

| | |
|---|---|
| **สถานะ** | ตั้งใจ — 1 attempt = 1 row |
| **ความเสี่ยง** | ไม่มี — ตารางนี้ใช้นับจำนวน row ไม่ใช่ update counter |
| **Cleanup** | `bootstrap.php` ลบ row เก่าอัตโนมัติ + `cron/cleanup_tokens.php` ไม่ได้ลบตารางนี้ (ลบเฉพาะ `password_resets`) |

### 8.7 ℹ️ CHECK constraints ขึ้นกับเวอร์ชัน DB

| | |
|---|---|
| **สถานะ** | ต้องตรวจสอบ |
| **รายละเอียด** | CHECK constraints ทำงานจริงบน MySQL 8.0.16+ / MariaDB 10.2.1+ เท่านั้น |
| **ถ้าเวอร์ชันต่ำกว่า** | MySQL จะยอมรับ syntax แต่ไม่บังคับ → `available` อาจติดลบได้ |
| **การป้องกันเสริม** | Application layer ตรวจ `available > 0` ก่อน INSERT ทุกครั้ง |

---

## 9. แหล่งอ้างอิง

| เอกสารนี้อ้างอิงจาก | Path |
|---------------------|------|
| Schema หลัก | `database/schema.sql` |
| ตัวติดตั้ง (SQL ใน PHP) | `install.php` |
| Migration 001 | `database/migrations/001_add_borrow_id_to_reservations.sql` |
| Migration 002 | `database/migrations/002_add_unique_borrow_id_to_payments.sql` |
| Migration 003 | `database/migrations/003_add_check_constraint_books_available.sql` |
| Migration 004 | `database/migrations/004_logic_audit_fixes.sql` |

> **หมายเหตุ:** migrations ทั้ง 4 ไฟล์ถูกรวมเข้า `schema.sql` แล้ว — ไม่ต้องรัน migrations แยก

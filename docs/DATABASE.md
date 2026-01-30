# 🗄️ Database Documentation

---

## ER Diagram

```
┌─────────────┐       ┌─────────────┐       ┌─────────────┐
│   users     │       │   borrows   │       │    books    │
├─────────────┤       ├─────────────┤       ├─────────────┤
│ id (PK)     │◄──────│ user_id(FK) │       │ id (PK)     │
│ name        │       │ book_id(FK) │──────►│ title       │
│ email       │       │ borrow_date │       │ author      │
│ password    │       │ due_date    │       │ isbn        │
│ phone       │       │ return_date │       │ category_id │──┐
│ role        │       │ status      │       │ description │  │
│ created_at  │       │ fine_amount │       │ cover_image │  │
│ updated_at  │       │ notes       │       │ quantity    │  │
└─────────────┘       │ created_at  │       │ available   │  │
                      └─────────────┘       │ created_at  │  │
                                            └─────────────┘  │
┌─────────────┐       ┌─────────────┐       ┌─────────────┐  │
│ reservations│       │  payments   │       │ categories  │◄─┘
├─────────────┤       ├─────────────┤       ├─────────────┤
│ id (PK)     │       │ id (PK)     │       │ id (PK)     │
│ user_id(FK) │       │ borrow_id   │       │ name        │
│ book_id(FK) │       │ amount      │       │ created_at  │
│ status      │       │ recorded_by │       │ updated_at  │
│ expires_at  │       │ created_at  │       └─────────────┘
│ created_at  │       └─────────────┘
└─────────────┘

┌─────────────┐
│  settings   │
├─────────────┤
│ id (PK)     │
│ setting_key │
│ setting_val │
│ created_at  │
│ updated_at  │
└─────────────┘
```

---

## Tables

### 1. users (ผู้ใช้งาน)

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| id | INT | NO | Primary Key, Auto Increment |
| name | VARCHAR(100) | NO | ชื่อผู้ใช้ |
| email | VARCHAR(100) | NO | อีเมล (Unique) |
| password | VARCHAR(255) | NO | รหัสผ่าน (bcrypt hash) |
| phone | VARCHAR(20) | YES | เบอร์โทรศัพท์ |
| role | ENUM | NO | 'member', 'admin', 'staff' |
| created_at | DATETIME | NO | วันที่สร้าง |
| updated_at | DATETIME | NO | วันที่แก้ไขล่าสุด |

**Indexes:** `idx_email`, `idx_role`

---

### 2. categories (หมวดหมู่)

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| id | INT | NO | Primary Key |
| name | VARCHAR(100) | NO | ชื่อหมวดหมู่ (Unique) |
| created_at | DATETIME | NO | วันที่สร้าง |
| updated_at | DATETIME | NO | วันที่แก้ไข |

---

### 3. books (หนังสือ)

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| id | INT | NO | Primary Key |
| title | VARCHAR(200) | NO | ชื่อหนังสือ |
| author | VARCHAR(100) | NO | ผู้แต่ง |
| isbn | VARCHAR(20) | YES | รหัส ISBN |
| category_id | INT | YES | FK → categories.id |
| description | TEXT | YES | รายละเอียด |
| cover_image | VARCHAR(255) | YES | ชื่อไฟล์รูปปก |
| quantity | INT | NO | จำนวนทั้งหมด (default: 1) |
| available | INT | NO | จำนวนที่ว่าง (default: 1) |
| created_at | DATETIME | NO | วันที่สร้าง |
| updated_at | DATETIME | NO | วันที่แก้ไข |

**Indexes:** `idx_available`, `idx_category`  
**Foreign Keys:** `category_id` → `categories(id)` ON DELETE SET NULL

---

### 4. borrows (การยืม)

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| id | INT | NO | Primary Key |
| user_id | INT | NO | FK → users.id |
| book_id | INT | NO | FK → books.id |
| borrow_date | DATE | NO | วันที่ยืม |
| due_date | DATE | NO | กำหนดคืน |
| return_date | DATE | YES | วันที่คืนจริง |
| status | ENUM | NO | 'borrowing', 'returned' |
| fine_amount | DECIMAL(10,2) | NO | ค่าปรับ (default: 0) |
| notes | TEXT | YES | หมายเหตุ |
| created_at | DATETIME | NO | วันที่สร้าง |
| updated_at | DATETIME | NO | วันที่แก้ไข |

**Indexes:** `idx_status`, `idx_user`, `idx_book`, `idx_due_date`  
**Foreign Keys:** 
- `user_id` → `users(id)` ON DELETE CASCADE
- `book_id` → `books(id)` ON DELETE CASCADE

---

### 5. reservations (การจอง)

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| id | INT | NO | Primary Key |
| user_id | INT | NO | FK → users.id |
| book_id | INT | NO | FK → books.id |
| status | ENUM | NO | 'pending', 'fulfilled', 'expired', 'cancelled' |
| expires_at | DATETIME | NO | วันหมดอายุการจอง |
| created_at | DATETIME | NO | วันที่จอง |

---

### 6. payments (การชำระค่าปรับ)

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| id | INT | NO | Primary Key |
| borrow_id | INT | NO | FK → borrows.id |
| amount | DECIMAL(10,2) | NO | จำนวนเงิน |
| recorded_by | INT | YES | FK → users.id (ผู้บันทึก) |
| created_at | DATETIME | NO | วันที่ชำระ |

---

### 7. settings (ตั้งค่าระบบ)

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| id | INT | NO | Primary Key |
| setting_key | VARCHAR(50) | NO | Key (Unique) |
| setting_value | TEXT | YES | Value |
| created_at | DATETIME | NO | วันที่สร้าง |
| updated_at | DATETIME | NO | วันที่แก้ไข |

---

## Common Queries

### ดูหนังสือที่ยังว่างอยู่
```sql
SELECT * FROM books WHERE available > 0;
```

### ดูรายการยืมที่เกินกำหนด
```sql
SELECT b.*, u.name, bk.title 
FROM borrows b
JOIN users u ON b.user_id = u.id
JOIN books bk ON b.book_id = bk.id
WHERE b.status = 'borrowing' AND b.due_date < CURDATE();
```

### สถิติการยืมรายเดือน
```sql
SELECT 
    DATE_FORMAT(borrow_date, '%Y-%m') as month,
    COUNT(*) as total
FROM borrows
GROUP BY month
ORDER BY month DESC;
```

---

## Backup & Restore

### Backup
```bash
mysqldump -u root -p book_borrowing > backup.sql
```

### Restore
```bash
mysql -u root -p book_borrowing < backup.sql
```

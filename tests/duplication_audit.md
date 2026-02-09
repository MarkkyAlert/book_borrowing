# Duplication & Structure Audit — TOP 10

**วันที่:** 2026-02-09  
**เป้าหมาย:** หา code ซ้ำที่เสี่ยงจริง → แนะนำ minimal refactor  
**ขอบเขต:** api/*, admin/*, root/*, app/Services/*, app/Repositories/*, includes/*

---

## #1 — HIGH: Member validation ซ้ำ 4 จุด (divergence risk สูงสุด)

**จุดที่ซ้ำ:**
| # | ไฟล์ | validates |
|---|------|----------|
| A | `register.php:43-65` | name(empty+maxLen), email(empty+format), phone(format), password, confirm |
| B | `member_form.php:57-79` | name(empty+maxLen), email(empty+format+dup), password |
| C | `MemberService::createMember():80-100` | name(empty+maxLen), email(empty+format+dup) |
| D | `AuthService::register():90-106` | name+email+pw(empty), email(format), password |

**behavior diff:**
- A ตรวจ `validateMaxLength(name, 100)` แต่ D ไม่ตรวจ → ชื่อเกิน 100 ตัวอักษรผ่าน D ได้ (แต่ A กั้นไว้ก่อน)
- A ตรวจ phone format แต่ D ไม่ตรวจ phone เลย
- B ตรวจ email duplicate ก่อนเรียก C → C ตรวจอีกครั้ง (double DB hit)
- A ไม่ตรวจ email duplicate (ฝากไว้ที่ D) → ถูกต้อง แต่ pattern ต่างกับ B

**ความเสี่ยง:** ถ้าเพิ่ม validation rule ใหม่ (เช่น blacklist email domain) ต้องแก้ 4 จุด → ลืม 1 จุดก็พังทันที

**ควรรวมเป็น:** `Validator` helper + ให้ Service เป็น single source of truth

**โครงสร้างใหม่:**
```
register.php      → AuthService::register($data)      → validates internally
member_form.php   → MemberService::createMember($data) → validates internally
                  ↘ ลบ validation ซ้ำใน page ออก (ยกเว้น confirm_password)
```

**ตัวอย่าง refactor:**
```php
// includes/functions.php — เพิ่ม helper รวม
function validateMemberData(array $data, bool $isEdit = false, ?int $excludeId = null): array
{
    $errors = [];
    if (empty($data['name'])) {
        $errors[] = 'กรุณากรอกชื่อ-นามสกุล';
    } elseif ($err = validateMaxLength($data['name'], 100, 'ชื่อ')) {
        $errors[] = $err;
    }
    if (empty($data['email'])) {
        $errors[] = 'กรุณากรอกอีเมล';
    } elseif (!isValidEmail($data['email'])) {
        $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    }
    if (!empty($data['phone']) && !isValidPhone($data['phone'])) {
        $errors[] = 'เบอร์โทรต้องเป็นตัวเลข 9-10 หลัก';
    }
    if (isset($data['password'])) {
        if ($err = validatePassword($data['password'], $isEdit)) {
            $errors[] = $err;
        }
    }
    return $errors;
}
```
```php
// MemberService::createMember() — ใช้ helper
$errors = validateMemberData($data);
if (!empty($errors)) throw new Exception(implode(', ', $errors));
if ($this->emailExists($data['email'])) throw new Exception('อีเมลซ้ำ');
```
```php
// register.php — เหลือแค่ page-specific logic
$errors = validateMemberData(['name'=>$name, 'email'=>$email, 'phone'=>$phone, 'password'=>$password]);
if ($password !== $confirmPassword) $errors[] = 'รหัสผ่านไม่ตรงกัน';
if (empty($errors)) { $authService->register($data); }
```

**Test:** เปลี่ยน maxLength name → 50 ที่เดียว → ทุก entrypoint ต้อง reject ชื่อ 60 ตัวอักษร

---

## #2 — HIGH: สอง Service สร้าง member ด้วย logic คนละชุด

**จุดที่ซ้ำ:**
- `AuthService::register()` — self-registration ผ่านหน้าเว็บ
- `MemberService::createMember()` — staff สร้างให้

**behavior diff:**
| เรื่อง | AuthService | MemberService |
|--------|------------|---------------|
| Return format | `['success'=>bool, 'error'=>string]` | throws Exception |
| Name maxLength | ❌ ไม่ตรวจ | ✅ ตรวจ 100 chars |
| Phone validation | ❌ ไม่ตรวจ | ❌ ไม่ตรวจ (ไม่มี phone param) |
| Password | required | optional (auto-generate) |
| Email duplicate | `userRepo->emailExists()` | `$this->emailExists()` (wrapper) |

**ความเสี่ยง:** ถ้าเพิ่ม business rule (เช่น phone required, email domain whitelist) ต้องแก้ 2 Service → ลืม 1 จุดได้

**ควรรวมเป็น:** `MemberService` เป็น single source สำหรับสร้าง member, `AuthService` เรียกผ่าน

**โครงสร้างใหม่:**
```
AuthService::register()
  → validate confirm_password (page-specific)
  → MemberService::createMember($data)  ← delegate
  → set session / redirect
```

**ตัวอย่าง refactor:**
```php
// AuthService::register() — delegate ไปที่ MemberService
public function register(array $data): array
{
    try {
        $memberService = new MemberService($this->pdo);
        $result = $memberService->createMember([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? '',
            'password' => $data['password']
        ]);
        return ['success' => true, 'user_id' => $result['id']];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
```

**Test:** สมัครสมาชิกด้วยชื่อ 150 ตัวอักษร → ทั้ง register.php และ member_form.php ต้อง reject

---

## #3 — MEDIUM: `importMember()` bypass validation pipeline ทั้งหมด

**จุดที่ซ้ำ:**
- `MemberService::createMember():78-118` — validate name, email, format, duplicate → `userRepo->create()`
- `MemberService::importMember():228-255` — ตรวจแค่ empty → `userRepo->create()` ตรง

**behavior diff:**
- `createMember()`: ตรวจ name empty, maxLength, email format, email duplicate
- `importMember()`: ตรวจแค่ empty ใน `import_members.php` (line 60) ก่อนเรียก, ไม่ตรวจ maxLength, ไม่ตรวจ email format

**ความเสี่ยง:** CSV import ที่มี email ผิด format (เช่น "not-an-email") จะเข้า DB ได้ → ข้อมูลเสีย

**ควรรวมเป็น:** ให้ `importMember()` เรียก validation helper ตัวเดียวกับ `createMember()`

**ตัวอย่าง refactor:**
```php
public function importMember(array $data, string $defaultPassword = '123456'): array
{
    $email = trim($data['email']);
    $name = trim($data['name']);
    $phone = trim($data['phone'] ?? '');

    $existing = $this->userRepo->findByEmail($email);

    if ($existing) {
        $this->userRepo->update($existing['id'], [
            'name' => $name, 'email' => $email, 'phone' => $phone
        ]);
        return ['action' => 'updated', 'id' => $existing['id']];
    }

    // ← ใช้ createMember() แทน userRepo->create() ตรง
    $result = $this->createMember([
        'name' => $name, 'email' => $email,
        'phone' => $phone, 'password' => $defaultPassword
    ]);
    return ['action' => 'created', 'id' => $result['id']];
}
```

**Test:** CSV import ที่มี email = "invalid" → ต้อง skip row นั้น ไม่ใช่ insert เข้า DB

---

## #4 — MEDIUM: Email duplicate ตรวจ 2 รอบใน member_form.php flow

**จุดที่ซ้ำ:**
- `member_form.php:70-74` — `memberService->emailExists(email, id)` → DB query
- `MemberService::createMember():98` — `$this->emailExists(email)` → DB query อีกครั้ง
- `MemberService::updateMember():136-137` — `$this->emailExists(email)` → DB query อีกครั้ง

**behavior diff:** ไม่ต่างกัน — เป็น query เดียวกันทุกประการ แค่ทำ 2 รอบ

**ความเสี่ยง:** Low — เปลือง DB query 1 round trip, ไม่มี data risk แต่ถ้าเปลี่ยน logic duplicate check (เช่น case-insensitive) ต้องแก้ทั้ง 2 จุด

**ควรรวมเป็น:** ลบ email duplicate check ออกจาก `member_form.php` ให้ Service จัดการฝ่ายเดียว

**ตัวอย่าง refactor:**
```php
// member_form.php — ลบบรรทัด 69-74 ออก (email duplicate check)
// ให้ MemberService::createMember/updateMember จัดการ เป็น single source
```

**Test:** สร้างสมาชิกด้วย email ซ้ำ → error จาก Service ต้องถูก catch แสดงผลเหมือนเดิม

---

## #5 — MEDIUM: `BookService::getBooks()` filter/sort ใน PHP ซ้ำกับ SQL

**จุดที่ซ้ำ:**
- `BookRepository::findAll()` — SQL WHERE search + category_id
- `BookService::getBooks():76-105` — PHP `array_filter` (status) + `usort` (sort)

**behavior diff:** search/category กรองที่ DB, status/sort กรองที่ PHP → logic ข้ามชั้น

**ความเสี่ยง:** Low สำหรับ small system — แต่ถ้าข้อมูลโต จะ fetch ทุก row แล้วกรองใน PHP (O(n))

**ควรรวมเป็น:** ย้าย status filter + sort ไปใน `BookRepository::findAll()`

**ตัวอย่าง refactor:**
```php
// BookRepository::findAll() — เพิ่ม filter
if (!empty($filters['status'])) {
    match ($filters['status']) {
        'available' => $where[] = "b.available > 0",
        'out_of_stock' => $where[] = "b.available = 0",
        'low_stock' => $where[] = "b.available > 0 AND b.available <= 2",
    };
}
$orderBy = match ($filters['sort'] ?? 'newest') {
    'oldest' => 'b.created_at ASC',
    'az' => 'b.title ASC',
    default => 'b.created_at DESC',
};
```

**Test:** เพิ่มหนังสือ 100 เล่ม → filter `status=available&sort=az` ผลต้องเหมือนเดิม

---

## #6 — MEDIUM: Root pages ใช้ manual `require_once` แทน autoloader

**จุดที่ซ้ำ:**
- `admin/*` — ใช้ `use App\Repositories\...;` (autoloader จาก bootstrap.php)
- `profile.php:15-17`, `my_borrows.php:13`, `my_reservations.php:14` — ใช้ `require_once __DIR__ . '/app/...'`

**behavior diff:** ทำงานเหมือนกัน แต่ pattern ต่างกัน — เพิ่มความสับสนสำหรับ developer ใหม่

**ความเสี่ยง:** Low — ไม่ crash แต่ถ้าย้ายไฟล์จะพังเฉพาะ root pages (admin ใช้ autoloader ไม่พัง)

**ควรรวมเป็น:** ลบ `require_once` ออก ใช้ `use` statement เหมือน admin pages

**ตัวอย่าง refactor:**
```php
// profile.php — before
require_once __DIR__ . '/app/Repositories/BorrowRepository.php';
$borrowRepo = new \App\Repositories\BorrowRepository($pdo);

// profile.php — after
use App\Repositories\BorrowRepository;
$borrowRepo = new BorrowRepository($pdo);
```

**Test:** เปิด profile.php, my_borrows.php, my_reservations.php → ต้องทำงานปกติ

---

## #7 — MEDIUM: API auth check ใช้ inline logic แทน helper

**จุดที่ซ้ำ:**
- `admin/*` (ทุกไฟล์) — `requireStaff();` → redirect + flash message
- `api/add_member.php:27`, `api/member_history.php:15` — inline `if (!isAdmin() && !isStaff())`

**behavior diff:**
- `requireStaff()` → redirect ไป login.php + flash message (สำหรับ HTML pages)
- inline check → `http_response_code(403)` + JSON (สำหรับ API)

**ความเสี่ยง:** Low — behavior ต่างกันเพราะ context ต่าง (HTML vs JSON) แต่ถ้าเพิ่ม role ใหม่ ต้องแก้ทั้ง `requireStaff()` และ inline checks

**ควรรวมเป็น:** เพิ่ม `requireStaffApi()` helper ใน functions.php

**ตัวอย่าง refactor:**
```php
// includes/functions.php
function requireStaffApi(): void {
    if (!isAdmin() && !isStaff()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}
```

**Test:** เพิ่ม role ใหม่ → แก้ที่ `isStaff()`/`isAdmin()` → ทั้ง admin pages และ API ต้อง authorize ถูก

---

## #8 — LOW: Error response format ไม่สม่ำเสมอระหว่าง Services

**จุดที่ซ้ำ:**
- `AuthService::register()` — return `['success'=>false, 'error'=>'...']`
- `AuthService::changePassword()` — return `['success'=>false, 'error'=>'...']`
- `MemberService::createMember()` — `throw new Exception('...')`
- `BorrowService::createBorrow()` — `throw new Exception('...')`
- `ReservationService::*()` — `throw new Exception('...')`

**behavior diff:** AuthService return array, ที่เหลือ throw Exception — caller ต้องจำว่า Service ไหนใช้ pattern ไหน

**ความเสี่ยง:** Low — ไม่ crash เพราะ callers จัดการถูกแล้ว แต่เพิ่ม cognitive load

**ควรรวมเป็น:** ยังไม่ต้องแก้ — ทั้ง 2 patterns ทำงานได้ดี แต่ถ้า refactor AuthService ให้ throw Exception เหมือนที่อื่นจะสม่ำเสมอกว่า

**Test:** N/A (ไม่ต้องแก้)

---

## สรุป

| # | Severity | จุดที่ซ้ำ | ความเสี่ยงจริง |
|---|----------|----------|---------------|
| 1 | **HIGH** | Member validation × 4 จุด | เพิ่ม rule ใหม่ลืม 1 จุด → bypass |
| 2 | **HIGH** | AuthService vs MemberService สร้าง member | validation diverge (maxLength ไม่ตรง) |
| 3 | **MEDIUM** | importMember() bypass validation | invalid email เข้า DB ได้ |
| 4 | **MEDIUM** | Email dup check × 2 รอบ | เปลือง query + เปลี่ยน logic ต้อง 2 จุด |
| 5 | **MEDIUM** | BookService filter ใน PHP vs SQL | O(n) scaling + logic ข้ามชั้น |
| 6 | **MEDIUM** | require_once vs autoloader | สับสน + ย้ายไฟล์พัง |
| 7 | **MEDIUM** | API auth inline vs helper | เพิ่ม role ต้องแก้หลายจุด |
| 8 | **LOW** | Error format (array vs Exception) | cognitive load |

---

## คำตัดสิน: **structure พร้อมขาย**

**เหตุผล:**
- ไม่มี HIGH ที่ทำให้ระบบ crash ณ ปัจจุบัน — validation ซ้ำ = ซ้ำเกิน ไม่ใช่ขาด
- HIGH #1-#2 เป็น maintenance risk ไม่ใช่ runtime risk
- จุดซ้ำทั้งหมดทำงานถูกต้องในสถานะปัจจุบัน
- สำหรับ template/demo/ร้านเล็ก: ซ้ำแต่ถูก ดีกว่า refactor แล้วพัง

**แนะนำ (ถ้ามีเวลา):** แก้ #1 + #2 + #3 จะลดจุดซ้ำจาก 4→1 สำหรับ member validation ทั้งระบบ

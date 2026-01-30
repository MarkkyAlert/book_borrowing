# API Layer Guidelines

## กติกาสำหรับ `api/` folder

### ⚠️ ข้อห้าม
- ❌ ห้ามใส่ business logic ใน `api/`
- ❌ ห้ามตัดสินใจเชิงกฎธุรกิจใน `api/`
- ❌ ห้ามเขียน SQL query โดยตรง
- ❌ ห้ามเข้าถึง `$pdo` โดยตรง (ยกเว้นส่งให้ Service/Repository)

### ✅ สิ่งที่ทำได้
1. **ตรวจ HTTP Method** - GET, POST, PUT, DELETE
2. **ตรวจ Authentication** - `isLoggedIn()`, `isAdmin()`
3. **รับ Input** - `$_GET`, `$_POST`, `$_FILES`
4. **Validate Input เบื้องต้น** - required fields, data types
5. **เรียก Service หรือ Repository**
6. **ส่ง JSON/HTML Response**

---

## โครงสร้าง Template

```php
<?php
/**
 * API: [ชื่อ Endpoint]
 * 
 * ⚠️ กติกา: ไฟล์นี้ทำหน้าที่ Controller เท่านั้น
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../app/Services/XxxService.php';

use App\Services\XxxService;

header('Content-Type: application/json');

// ========== 1. ตรวจ Auth ==========
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ========== 2. ตรวจ Method ==========
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ========== 3. รับ & Validate Input ==========
$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

// ========== 4. เรียก Service ==========
try {
    $pdo = getDB();
    $service = new XxxService($pdo);
    $result = $service->doSomething($id);
    
    // ========== 5. ส่ง Response ==========
    echo json_encode(['success' => true, 'data' => $result]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
```

---

## Layer Responsibilities

| Layer | หน้าที่ | ตัวอย่าง |
|-------|--------|---------|
| **api/** | Controller - รับ request, เรียก Service, ส่ง response | `api/reserve_book.php` |
| **app/Services/** | Business Logic - ตัดสินใจ, คำนวณ, transactions | `BorrowService::returnBook()` |
| **app/Repositories/** | Data Access - SQL queries | `BookRepository::findAll()` |
| **app/Helpers/** | Utilities - format, escape, validate | `formatDate()`, `e()` |

---

## HTTP Status Codes

| Code | ใช้เมื่อ |
|------|---------|
| `200` | Success |
| `400` | Bad Request (validation error) |
| `401` | Unauthorized (not logged in) |
| `403` | Forbidden (no permission) |
| `404` | Not Found |
| `405` | Method Not Allowed |
| `500` | Server Error |

---

## ตัวอย่างการตัดสินใจ

### ❌ ผิด - ตัดสินใจใน API
```php
// api/borrow.php
if ($book['available'] <= 0) {
    echo json_encode(['error' => 'หนังสือหมด']);
}
```

### ✅ ถูก - ตัดสินใจใน Service
```php
// api/borrow.php
$result = $borrowService->createBorrow($userId, $bookId);

// app/Services/BorrowService.php
public function createBorrow($userId, $bookId) {
    if ($book['available'] <= 0) {
        throw new Exception('หนังสือหมด');
    }
    // ...
}
```

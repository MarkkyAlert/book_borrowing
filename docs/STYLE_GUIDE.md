# PHP Code Style Guide - Book Borrowing System

**Version:** 1.0  
**Last Updated:** 2026-02-01

---

## 1. File & Naming Conventions

### 1.1 File Naming
| Type | Pattern | Example |
|------|---------|---------|
| Classes | `PascalCase.php` | `BookRepository.php`, `BorrowService.php` |
| API endpoints | `snake_case.php` | `add_member.php`, `reserve_book.php` |
| Pages (root/admin) | `snake_case.php` | `forgot_password.php`, `book_form.php` |
| Config/Utilities | `snake_case.php` | `functions.php`, `config.php` |

### 1.2 Class & Method Naming
```php
// ✅ Good
class BookRepository { }
class BorrowService { }
public function findById(int $id): ?array { }
public function createBorrow(int $userId, array $bookIds): array { }

// ❌ Bad
class book_repository { }  // snake_case class
public function FindById() { }  // PascalCase method
public function get_by_id() { }  // snake_case method
```

### 1.3 Variable Naming
```php
// ✅ Good - camelCase
$userId = $_SESSION['user_id'];
$bookRepo = new BookRepository($pdo);
$isEdit = false;

// ❌ Bad
$user_id = $_SESSION['user_id'];  // snake_case
$BookRepo = new BookRepository($pdo);  // PascalCase
```

---

## 2. File Structure & Imports

### 2.1 Standard File Header Order
```php
<?php
/**
 * [File Description - Thai/English]
 */

// 1. require bootstrap OR legacy includes
require_once __DIR__ . '/../bootstrap.php';
// OR (legacy - avoid in new code)
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

// 2. use statements
use App\Repositories\BookRepository;
use App\Services\BorrowService;

// 3. Auth guards (for pages)
requireStaff();  // or requireLogin() / requireAdmin()

// 4. Initialize repositories/services
$pdo = getDB();
$bookRepo = new BookRepository($pdo);

// 5. Main logic...
```

### 2.2 Prefer `bootstrap.php` over legacy includes
```php
// ✅ Good (preferred)
require_once __DIR__ . '/../bootstrap.php';

// ⚠️ Legacy (avoid in new code)
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
```

---

## 3. API Response Standards

### 3.1 JSON Schema
```typescript
interface APIResponse {
  success: boolean;       // Required
  message: string;        // Required - user-safe message
  data?: any;             // Optional - payload
  errors?: string[];      // Optional - validation errors
}
```

### 3.2 HTTP Status Codes
| Code | When to Use |
|------|-------------|
| 200 | Success (GET/POST successful) |
| 400 | Bad Request (validation error, business rule violation) |
| 401 | Unauthorized (not logged in) |
| 403 | Forbidden (CSRF invalid, no permission) |
| 404 | Not Found (resource doesn't exist) |
| 405 | Method Not Allowed (wrong HTTP method) |
| 500 | Internal Server Error (exception caught) |

### 3.3 Standard API Template
```php
<?php
header('Content-Type: application/json');

// 1. Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// 2. Auth check
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit;
}

// 3. CSRF check
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

// 4. Validation
$errors = [];
if (empty($input)) {
    $errors[] = 'กรุณากรอกข้อมูล';
}
if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
    exit;
}

// 5. Business logic (try-catch)
try {
    $result = $service->doSomething();
    echo json_encode(['success' => true, 'message' => 'สำเร็จ', 'data' => $result]);
} catch (Exception $e) {
    http_response_code(400);  // or 500 for unexpected errors
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
```

---

## 4. Auth & Permission Guards

### 4.1 Standard Guard Functions
```php
// Check login status (boolean)
isLoggedIn()    // true if user logged in
isAdmin()       // true if role === 'admin'
isStaff()       // true if role in ['admin', 'staff']

// Redirect guards (exit if not authorized)
requireLogin()  // Redirect to login if not logged in
requireAdmin()  // Redirect to home if not admin
requireStaff()  // Redirect to home if not staff/admin
```

### 4.2 Guard Order
```php
// ✅ Good - consistent order
requireStaff();  // 1. Auth guard FIRST

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {  // 2. CSRF second
        // ...
    }
}

// ❌ Bad - CSRF before auth
if (!validateCSRFToken($_POST['csrf_token'])) { }  // CSRF first
requireStaff();  // Auth second
```

---

## 5. Validation Messages (Thai)

### 5.1 Standard Patterns
```php
// Required field
'กรุณากรอก[ชื่อฟิลด์]'           // กรุณากรอกอีเมล

// Invalid format
'รูปแบบ[ชื่อฟิลด์]ไม่ถูกต้อง'    // รูปแบบอีเมลไม่ถูกต้อง

// Length constraint
'[ฟิลด์]ต้องมีความยาว X-Y ตัวอักษร'  // ชื่อต้องมีความยาว 2-100 ตัวอักษร

// Duplicate/exists
'[ฟิลด์]นี้ถูกใช้งานแล้ว'         // อีเมลนี้ถูกใช้งานแล้ว

// Not found
'ไม่พบ[รายการ]ที่เลือก'          // ไม่พบหนังสือที่เลือก

// Success
'[action]สำเร็จ'                  // บันทึกสำเร็จ, เพิ่มสมาชิกสำเร็จ
```

---

## 6. Repository & Service Patterns

### 6.1 Repository (Data Access Only)
```php
// ✅ Good - pure data access
public function findById(int $id): ?array { }
public function create(array $data): int { }
public function update(int $id, array $data): bool { }
public function delete(int $id): bool { }

// ❌ Bad - business logic in repository
public function createIfNotExists($data) {
    if ($this->findByEmail($data['email'])) {  // ❌ Decision logic
        throw new Exception('Email exists');
    }
}
```

### 6.2 Service (Business Logic)
```php
// ✅ Good - orchestrates repositories
public function createBorrow(int $userId, array $bookIds): array {
    $user = $this->userRepo->findMemberById($userId);
    if (!$user) {
        throw new Exception('ไม่พบสมาชิกที่เลือก');
    }
    // ...business rules...
}
```

---

## 7. Transaction Pattern

```php
// ✅ Good - consistent pattern
$this->pdo->beginTransaction();
try {
    // ... multiple operations ...
    $this->pdo->commit();
    return ['success' => true, ...];
} catch (Exception $e) {
    $this->pdo->rollBack();
    throw $e;  // Re-throw for caller to handle
}

// ❌ Bad - swallowing exception
try {
    // ...
} catch (Exception $e) {
    $pdo->rollBack();
    return ['success' => false];  // ❌ Lost error info
}
```

---

## 8. Comment Standards

### 8.1 Docblocks (Required for public methods)
```php
/**
 * ดึงหนังสือตาม ID
 * 
 * @param int $id ID หนังสือ
 * @return array|null ข้อมูลหนังสือ หรือ null ถ้าไม่พบ
 */
public function findById(int $id): ?array { }
```

### 8.2 Inline Comments (Security/Business tags)
```php
// [SECURITY] Rate limiting ป้องกัน brute force
// [AUTH] ต้อง login เท่านั้น
// [BUSINESS RULE] ยืมได้ไม่เกิน MAX_BORROW_BOOKS เล่ม
// [NOTE] role hardcode เป็น 'member'
```

---

## 9. Prohibited Patterns

```php
// ❌ Never use in production
die('error');
var_dump($data);
print_r($array);
echo "Debug: $var";
error_log($sensitive_data);  // Don't log passwords/tokens
```

# QA Test Cases - Book Borrowing System

## Test Configuration
- **Base URL:** `http://localhost/book_borrowing`
- **Auth Method:** Cookie session (PHPSESSID)
- **CSRF:** Required for all POST requests
- **Admin Account:** admin@gmail.com / 123456
- **Test User:** (created during test)

---

## Test Cases (55 Total)

### A. HAPPY PATH (17 cases)

| ID | Category | Description | Method | Endpoint | Expected |
|----|----------|-------------|--------|----------|----------|
| HP-01 | Auth | Register new user | POST | /register.php | 302 redirect to login |
| HP-02 | Auth | Login as user | POST | /login.php | 302 redirect + session |
| HP-03 | Auth | Login as admin | POST | /login.php | 302 redirect to /admin/ |
| HP-04 | Auth | Logout | GET | /logout.php | 302 redirect + session cleared |
| HP-05 | Books | Search books (no filter) | GET | /api/search_books.php | 200 + HTML |
| HP-06 | Books | Search books with keyword | GET | /api/search_books.php?search=test | 200 + HTML |
| HP-07 | Books | View book detail | GET | /book.php?id=1 | 200 |
| HP-08 | Reserve | Reserve a book (user) | POST | /api/reserve_book.php | 200 JSON success |
| HP-09 | Profile | Update profile | POST | /profile.php | 302 redirect + success |
| HP-10 | Profile | Change password | POST | /profile.php | 302 redirect + success |
| HP-11 | Admin | Create book | POST | /admin/book_form.php | 302 redirect + success |
| HP-12 | Admin | Update book | POST | /admin/book_form.php | 302 redirect + success |
| HP-13 | Admin | Create member | POST | /admin/member_form.php | 302 redirect |
| HP-14 | Admin | Create borrow | POST | /admin/borrow_form.php | 302 redirect |
| HP-15 | Admin | Return book | POST | /admin/borrows.php | 302 redirect |
| HP-16 | Admin | Add category | POST | /admin/categories.php | 302 redirect |
| HP-17 | Admin | Update settings | POST | /admin/settings.php | 302 redirect |

### B. VALIDATION (16 cases)

| ID | Category | Description | Method | Endpoint | Expected |
|----|----------|-------------|--------|----------|----------|
| VL-01 | Auth | Register - empty email | POST | /register.php | 200 + error message |
| VL-02 | Auth | Register - invalid email | POST | /register.php | 200 + error message |
| VL-03 | Auth | Register - short password | POST | /register.php | 200 + error message |
| VL-04 | Auth | Register - password mismatch | POST | /register.php | 200 + error message |
| VL-05 | Auth | Register - duplicate email | POST | /register.php | 200 + error message |
| VL-06 | Auth | Login - wrong password | POST | /login.php | 200 + error message |
| VL-07 | Auth | Login - non-existent email | POST | /login.php | 200 + error message |
| VL-08 | Reserve | Reserve - invalid book_id | POST | /api/reserve_book.php | 400 JSON error |
| VL-09 | Reserve | Reserve - book not available | POST | /api/reserve_book.php | 400 JSON error |
| VL-10 | Profile | Update - empty name | POST | /profile.php | 200 + error |
| VL-11 | Profile | Change pwd - wrong current | POST | /profile.php | 200 + error |
| VL-12 | Admin | Book - empty title | POST | /admin/book_form.php | 200 + error |
| VL-13 | Admin | Book - empty author | POST | /admin/book_form.php | 200 + error |
| VL-14 | Admin | Member - invalid email | POST | /admin/member_form.php | 200 + error |
| VL-15 | Admin | Category - empty name | POST | /admin/categories.php | 200 + error |
| VL-16 | Admin | Category - duplicate name | POST | /admin/categories.php | 200 + error |

### C. EDGE CASES (12 cases)

| ID | Category | Description | Method | Endpoint | Expected |
|----|----------|-------------|--------|----------|----------|
| EC-01 | Books | Search - SQL injection attempt | GET | /api/search_books.php?search=' OR 1=1-- | 200 + no injection |
| EC-02 | Books | Search - XSS attempt | GET | /api/search_books.php?search=<script> | 200 + escaped |
| EC-03 | Books | View - non-existent book | GET | /book.php?id=99999 | 302 redirect + error |
| EC-04 | Books | View - negative ID | GET | /book.php?id=-1 | 302 redirect |
| EC-05 | Books | View - string ID | GET | /book.php?id=abc | 302 redirect |
| EC-06 | Reserve | Reserve same book twice | POST | /api/reserve_book.php | 400 already reserved |
| EC-07 | Admin | Delete book with borrows | POST | /admin/books.php | error message |
| EC-08 | Admin | Delete category with books | POST | /admin/categories.php | error message |
| EC-09 | Profile | Update with XSS in name | POST | /profile.php | 200 + escaped output |
| EC-10 | Admin | Borrow - exceed max books | POST | /admin/borrow_form.php | error message |
| EC-11 | Password | Reset with expired token | POST | /reset_password.php | error message |
| EC-12 | Password | Reset with used token | POST | /reset_password.php | error message |

### D. SECURITY (10 cases)

| ID | Category | Description | Method | Endpoint | Expected |
|----|----------|-------------|--------|----------|----------|
| SC-01 | CSRF | POST without csrf_token | POST | /profile.php | 302 + error |
| SC-02 | CSRF | POST with invalid csrf_token | POST | /profile.php | 302 + error |
| SC-03 | Auth | Access admin without login | GET | /admin/index.php | 302 to login |
| SC-04 | Auth | Access admin as user | GET | /admin/index.php | 302 to index |
| SC-05 | Method | GET on POST-only endpoint | GET | /api/reserve_book.php | 405 |
| SC-06 | IDOR | View other user's profile | GET | /profile.php | only own data |
| SC-07 | Brute | Login rate limiting | POST | /login.php (x6) | rate limit error |
| SC-08 | Session | Access after logout | GET | /profile.php | 302 to login |
| SC-09 | API | Reserve without login | POST | /api/reserve_book.php | 401 |
| SC-10 | Admin | AJAX add member without admin | POST | /admin/ajax_add_member.php | 403 |

---

## Test Data

### Test User (created in HP-01)
```
name: Test User QA
email: testuser_qa_{{timestamp}}@test.com
password: Test123456
```

### Test Book (created in HP-11)
```
title: QA Test Book {{timestamp}}
author: QA Author
quantity: 5
```

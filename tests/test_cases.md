# QA Test Cases - Book Borrowing System

## Test Configuration
- **Base URL:** `http://localhost/book_borrowing`
- **Auth Method:** Cookie session (PHPSESSID)
- **CSRF:** Per-session token, required for all POST requests (`csrf_token`)
- **Admin Account:** admin@library.com / (set during install)
- **Test User:** Created during test run
- **Logout:** POST-only with CSRF

---

## Test Cases (55 Total)

### A. HAPPY PATH (17 cases)

| ID | Description | Method | Endpoint | Expected |
|----|-------------|--------|----------|----------|
| HP-01 | Register new user | POST | /register.php | 302 redirect |
| HP-02 | Login as user | POST | /login.php | 302 redirect + session |
| HP-03 | Login as admin | POST | /login.php | 302 redirect |
| HP-04 | Logout (POST+CSRF) | POST | /logout.php | 302 redirect |
| HP-05 | Search books (no filter) | GET | /api/search_books.php | 200 HTML |
| HP-06 | Search books with keyword | GET | /api/search_books.php?search=X | 200 HTML |
| HP-07 | View book detail | GET | /book.php?id=1 | 200 |
| HP-08 | Reserve a book (user) | POST | /api/reserve_book.php | 200 JSON |
| HP-09 | Cancel reservation (user) | POST | /api/cancel_reservation.php | 302 |
| HP-10 | Update profile | POST | /profile.php | 302 |
| HP-11 | Change password | POST | /profile.php | 302 |
| HP-12 | Admin: create book | POST | /admin/book_form.php | 302 |
| HP-13 | Admin: add category | POST | /admin/categories.php | 302 |
| HP-14 | Admin: create member (quick) | POST | /api/add_member.php | 200 JSON |
| HP-15 | Admin: view member history | GET | /api/member_history.php | 200 JSON |
| HP-16 | Admin: update settings | POST | /admin/settings.php | 302 |
| HP-17 | Forgot password request | POST | /forgot_password.php | 200/302 |

### B. VALIDATION (16 cases)

| ID | Description | Method | Endpoint | Expected |
|----|-------------|--------|----------|----------|
| VL-01 | Register: empty email | POST | /register.php | 200 + error |
| VL-02 | Register: invalid email | POST | /register.php | 200 + error |
| VL-03 | Register: short password (<6) | POST | /register.php | 200 + error |
| VL-04 | Register: password mismatch | POST | /register.php | 200 + error |
| VL-05 | Register: duplicate email | POST | /register.php | 200 + error |
| VL-06 | Login: wrong password | POST | /login.php | 200 + error |
| VL-07 | Login: non-existent email | POST | /login.php | 200 + error |
| VL-08 | Reserve: book_id=0 | POST | /api/reserve_book.php | 400 JSON |
| VL-09 | Reserve: non-existent book | POST | /api/reserve_book.php | 400 JSON |
| VL-10 | Profile: empty name | POST | /profile.php | 200 + error |
| VL-11 | Profile: wrong current password | POST | /profile.php | 200 + error |
| VL-12 | Admin: book empty title | POST | /admin/book_form.php | 200 + error |
| VL-13 | Admin: book empty author | POST | /admin/book_form.php | 200 + error |
| VL-14 | Admin: member invalid email | POST | /api/add_member.php | 200 JSON error |
| VL-15 | Admin: category empty name | POST | /admin/categories.php | 200 + error |
| VL-16 | Admin: category duplicate name | POST | /admin/categories.php | 200 + error |

### C. EDGE CASES (12 cases)

| ID | Description | Method | Endpoint | Expected |
|----|-------------|--------|----------|----------|
| EC-01 | Search: SQL injection `' OR 1=1--` | GET | /api/search_books.php | 200, no injection |
| EC-02 | Search: XSS `<script>` | GET | /api/search_books.php | 200, escaped |
| EC-03 | View non-existent book | GET | /book.php?id=99999 | 302 |
| EC-04 | View negative book ID | GET | /book.php?id=-1 | 302 |
| EC-05 | View string book ID | GET | /book.php?id=abc | 302 |
| EC-06 | Reserve same book twice | POST | /api/reserve_book.php | 400 already reserved |
| EC-07 | XSS in profile name | POST | /profile.php | 302, escaped on render |
| EC-08 | Reset password: invalid token | POST | /reset_password.php | 200 + error |
| EC-09 | Category: delete with books | POST | /admin/categories.php | 200 + error |
| EC-10 | Forgot password: non-existent email | POST | /forgot_password.php | 200 (no user enum) |
| EC-11 | Search: rate limit header present | GET | /api/search_books.php | 200 |
| EC-12 | Homepage loads without auth | GET | /index.php | 200 |

### D. SECURITY (10 cases)

| ID | Description | Method | Endpoint | Expected |
|----|-------------|--------|----------|----------|
| SC-01 | POST without csrf_token | POST | /profile.php | 302 (rejected) |
| SC-02 | POST with invalid csrf_token | POST | /profile.php | 302 (rejected) |
| SC-03 | Admin page without login | GET | /admin/index.php | 302 to login |
| SC-04 | Access admin as member | GET | /admin/index.php | 302 |
| SC-05 | GET on POST-only endpoint | GET | /api/reserve_book.php | 405 |
| SC-06 | Member history API without admin | GET | /api/member_history.php | 302/403 |
| SC-07 | Login brute force (6+ attempts) | POST | /login.php | rate limit error |
| SC-08 | Access profile after logout | GET | /profile.php | 302 to login |
| SC-09 | Reserve without login | POST | /api/reserve_book.php | 401 |
| SC-10 | Add member API without admin | POST | /api/add_member.php | 302/403/401 |

---

## Notes

- **EC-07b** is a sub-check of EC-07 (verify XSS escaped on render), counted separately.
- **EC-09** may be skipped if no deletable category exists in DB.
- **SC-07** may fail if `rate_limits` table is missing from DB.
- Admin password defaults to `password` (matching `sample_data.sql` hash).

## Test Data

### Test User (created in HP-01)
```
name: QA User
email: qa_user_{{timestamp}}@test.com
password: Test123456
```

### Test Book (created in HP-12)
```
title: QA Book {{timestamp}}
author: QA Author
quantity: 3
```

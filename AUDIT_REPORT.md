# Book Borrowing System — Deep Audit Report

**Date:** 2025  
**Auditor:** Cascade AI  
**Scope:** Full codebase — every entry point, service, repository, and template traced line-by-line.

---

## 1. Architecture Overview

```
Public Pages          Admin Pages (staff+)       API (JSON)            Cron
─────────────        ──────────────────         ──────────            ────
index.php            admin/index.php            api/reserve_book.php  cron/expire_reservations.php
book.php             admin/books.php            api/cancel_reservation.php  cron/cleanup_tokens.php
login.php            admin/book_form.php        api/add_member.php
register.php         admin/borrows.php          api/search_books.php
forgot_password.php  admin/borrow_form.php      api/member_history.php
reset_password.php   admin/reservations.php
profile.php          admin/payments.php
my_borrows.php       admin/members.php
my_reservations.php  admin/member_form.php
                     admin/member_card.php
                     admin/categories.php
                     admin/settings.php
                     admin/import_books.php
                     admin/import_members.php
                     admin/reports.php
                     admin/export_pdf.php
                     admin/book_labels.php
```

**Layering:** Controller (page) → Service → Repository → PDO (prepared statements)  
**DB:** MySQL via PDO singleton, `EMULATE_PREPARES=false`, `ERRMODE_EXCEPTION`  
**Session:** PHP native, `SESSION_LIFETIME` inactivity timeout, `session_regenerate_id()` on login  

---

## 2. State Machines

### 2.1 `borrows.status`
```
borrowing  ──(returnBook)──►  returned
```
- **Transitions guarded by:** row lock (`FOR UPDATE`), status check in service
- **Fine calculated on return:** `FINE_PER_DAY × overdue days`

### 2.2 `reservations.status`
```
pending  ──(fulfillReservation)──►  fulfilled   (creates borrow, no stock change — already decremented)
pending  ──(cancelReservation)──►   cancelled   (stock +1)
pending  ──(lazy expire / cron)──►  expired     (stock +1)
```
- **Transitions guarded by:** row lock, `updateStatus()` with allowed-from-status guard
- **Lazy expiration:** `markExpiredReservations()` called before reads in `BookService`, `HomeService`, `ReservationService`

### 2.3 `payments`
```
(no payment)  ──(payFine)──►  payment record created
```
- **1 borrow = 1 payment** enforced by `UNIQUE(borrow_id)` at DB level
- **Guard:** Service checks `findByBorrowId()` + `fine_amount > 0` before insert

### 2.4 `password_resets`
```
created (used=0)  ──(resetPassword)──►  used=1
                  ──(expire/cron)──►    deleted
```
- **Token:** 64-char hex (`random_bytes(32)`), 1-hour expiry, one-time-use
- **Rate limit:** 3 requests/hour per email + global rate limit on forgot_password page

---

## 3. Security Audit

### 3.1 SQL Injection — ✅ PASS
- **All** user-facing queries use PDO prepared statements with `?` placeholders.
- `EMULATE_PREPARES=false` ensures native prepared statements.
- `ORDER BY` clauses use whitelisted values via `match()` / `switch-case` — no raw user input.
- Dynamic `WHERE` clauses built from code-internal arrays, user values always bound via `?`.

### 3.2 XSS — ✅ PASS
- Output escaping via `e()` → `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` used consistently in templates.
- Flash messages with `$isHtml=true` used only for admin-generated content (import results).
- CSS color injection in `member_card.php` mitigated by regex validation (`/^#[0-9A-Fa-f]{6}$/`).

### 3.3 CSRF — ✅ PASS
- Every POST form and AJAX endpoint validates `csrf_token` via `validateCSRFToken()`.
- Token stored in `$_SESSION`, regenerated per-form via `generateCSRFToken()`.

### 3.4 Authentication & Authorization — ✅ PASS
- `requireLogin()`, `requireStaff()`, `requireAdmin()` guards on every protected page.
- API endpoints use `requireStaffApi()` or `isLoggedIn()` check with JSON error response.
- `user_id` always sourced from `$_SESSION` — never from user input (prevents impersonation).
- `session_regenerate_id(true)` on login prevents session fixation.
- Session inactivity timeout enforced in `startSession()`.

### 3.5 Password Security — ✅ PASS
- `password_hash(PASSWORD_DEFAULT)` (bcrypt) used everywhere.
- `findById()` never returns password hash; only `findByEmail()` does (for login only).
- Password change requires current password verification.
- Login error message is generic ("email or password incorrect") — prevents enumeration.
- Password reset returns success even for non-existent emails — prevents enumeration.

### 3.6 Rate Limiting — ✅ PASS
- Login: per-email (`md5(email)` key), configurable max attempts + window.
- Registration: global key (prevents bypass via new emails).
- Forgot password: global key + per-email (3/hour in `PasswordResetRepository`).
- Reserve book: per-user key, 10 requests / 5 minutes.
- Password change: per-session key.

### 3.7 File Upload — ✅ PASS
- MIME type verified via `finfo_file()` (not trusting `$_FILES['type']`).
- Extension derived from verified MIME (whitelist: jpeg/png/gif/webp).
- Filename randomized (`cover_` + `time()` + `uniqid()`) — prevents path traversal and overwrite.
- Max size enforced (2MB).

### 3.8 Idempotency / Double-Submit — ✅ PASS
- Session-based idempotency keys on all write operations (borrow, return, pay, reserve, cancel, delete, approve).
- PRG (Post-Redirect-Get) pattern used on all admin form submissions.

---

## 4. Data Integrity Audit

### 4.1 Stock Management (books.available)

| Operation | Stock Change | Guard |
|-----------|-------------|-------|
| `createBorrow()` | `decrementAvailable()` -1 per book | `WHERE available > 0` (DB level) |
| `returnBook()` | `incrementAvailable()` +1 | `WHERE available < quantity` (DB level) |
| `createReservation()` | `decrementAvailable()` -1 | `WHERE available > 0` (DB level) |
| `cancelReservation()` | `updateAvailable(+1)` | ⚠️ No guard (see Issue #1) |
| `expireReservation (lazy)` | `updateAvailable(+1)` | ⚠️ No guard (see Issue #1) |
| `fulfillReservation()` | No change (stock already decremented at reservation time) | ✅ Correct |
| `updateBook()` | Recalculates `available` from quantity diff | `max(0, ...)` safety net + `currentlyOut` check |
| `addQuantity()` (CSV import) | `+N` to both quantity and available | ✅ Correct (new stock not yet lent) |

### 4.2 Transaction & Locking Coverage

| Flow | Transaction | Row Lock | Status |
|------|------------|----------|--------|
| `createBorrow()` | ✅ | ✅ user + books FOR UPDATE | PASS |
| `returnBook()` | ✅ | ✅ borrow + book FOR UPDATE | PASS |
| `payFine()` | ✅ | ✅ borrow FOR UPDATE | PASS |
| `createReservation()` | ✅ | ✅ book FOR UPDATE | PASS |
| `cancelReservation()` | ✅ | ✅ reservation FOR UPDATE | PASS |
| `fulfillReservation()` | ✅ | ✅ reservation FOR UPDATE | PASS |
| `deleteBook()` | ✅ | ✅ book FOR UPDATE | PASS |
| `deleteMember()` | ✅ | ❌ No row lock (see Issue #2) | MINOR |
| `resetPassword()` | ✅ | ❌ No lock, but token is one-time-use + atomic | ACCEPTABLE |
| `markExpiredReservations()` | ✅ per-row | ✅ per-row lock | PASS |
| `import_books.php` | ✅ whole batch | ❌ No row locks | See Issue #3 |

---

## 5. Issues Found

### Issue #1 — LOW RISK: `updateAvailable()` has no DB-level guard
**Location:** `BookRepository::updateAvailable()` (line 532)  
**Problem:** Unlike `incrementAvailable()` / `decrementAvailable()` which have `WHERE available < quantity` / `WHERE available > 0`, the generic `updateAvailable()` has no guard. If called with a wrong value, `available` could go negative or exceed `quantity`.  
**Impact:** Low — only called by `ReservationService` for `+1` (cancel/expire), and the calling code is correct. But it's a latent risk if reused elsewhere.  
**Fix:** Add guard: `WHERE available + ? >= 0 AND available + ? <= quantity`  

### Issue #2 — LOW RISK: `deleteMember()` transaction doesn't lock the user row
**Location:** `MemberService::deleteMember()` (line 201)  
**Problem:** The transaction checks `countByUser()` and `countPendingByUser()` then deletes, but doesn't lock the user row. Theoretically, a borrow could be created between the guard check and the delete. However, `BorrowService::createBorrow()` locks the user row with `FOR UPDATE`, so the borrow would wait for the delete transaction to complete. If the delete commits first, the borrow would fail on FK constraint. This is acceptable.  
**Impact:** Negligible — the existing lock in `createBorrow()` effectively serializes the race.  
**Fix:** Optional — add `SELECT id FROM users WHERE id = ? FOR UPDATE` before guards for defense-in-depth.

### Issue #3 — LOW RISK: CSV import has no row locks on existing books
**Location:** `admin/import_books.php` (line 101-106)  
**Problem:** `findByTitleAndAuthor()` and `addQuantity()` don't use `FOR UPDATE`. If two admins import simultaneously, they could both `addQuantity()` for the same book. Since `addQuantity()` uses `SET quantity = quantity + ?` (atomic SQL), the final result is correct — both additions apply. No data corruption occurs.  
**Impact:** None in practice (atomic SQL arithmetic is safe).

### Issue #4 — INFORMATIONAL: `books.php` processes POST after GET data fetch
**Location:** `admin/books.php` (line 36-41 vs 48-74)  
**Problem:** The GET data (`$books = $bookService->getBooks(...)`) is fetched at line 36 before the POST handler at line 48. If a delete POST happens, the redirect at line 73 means the stale `$books` is never rendered. However, if the redirect somehow fails, the page would show stale data.  
**Impact:** None in practice — the redirect always fires on POST.  
**Fix:** Move POST handler above GET fetch (already done in `borrows.php` and `payments.php`). This is a style inconsistency, not a bug.

### Issue #5 — INFORMATIONAL: Password reset token stored as plaintext in DB
**Location:** `PasswordResetRepository::create()` (line 121)  
**Problem:** The token is stored as-is in the `password_resets` table. In production, storing `hash('sha256', $token)` and comparing hashes would be more secure — if an attacker gains read access to the DB, they could use unexpired tokens.  
**Impact:** Low for this use case (demo/template app, tokens expire in 1 hour, one-time-use). Standard practice for production apps is to hash tokens.  
**Fix:** Store `hash('sha256', $token)` in DB, compare `hash('sha256', $inputToken)` in `findValidToken()`.

### Issue #6 — INFORMATIONAL: `generateRandomPassword()` uses `str_shuffle()` (not cryptographically secure)
**Location:** `MemberService::generateRandomPassword()` (line 375)  
**Problem:** `str_shuffle()` uses `rand()` internally, which is not cryptographically secure. For a temporary password that the user should change immediately, this is acceptable.  
**Impact:** Very low — temporary password, visible only to admin at creation time.  
**Fix:** Replace with `random_bytes()` + base conversion if higher entropy is desired.

### Issue #7 — INFORMATIONAL: `processed_actions` session array grows unbounded
**Location:** All controllers that use `$_SESSION['processed_actions'][$key] = time()`  
**Problem:** The idempotency keys accumulate in the session without cleanup. Over a long session, this array grows. The bootstrap does clean up keys older than 5 minutes, but only for keys that store timestamps.  
**Impact:** Negligible — sessions are short-lived (`SESSION_LIFETIME` = 3600s default), and the cleanup in bootstrap handles it.

### Issue #8 — INFORMATIONAL: `findMembers()` returns `SELECT u.*` including password hash
**Location:** `UserRepository::findMembers()` (line 503)  
**Problem:** The query uses `SELECT u.*` which includes the `password` column. While this data is only used in `admin/members.php` (server-side, never sent to client), it's inconsistent with the security-by-design approach used in `findById()` and `findMemberById()` which explicitly exclude password.  
**Impact:** Low — data stays server-side, never echoed to HTML. But it's a defense-in-depth gap.  
**Fix:** Change to explicit column list: `SELECT u.id, u.name, u.email, u.phone, u.role, u.created_at, ...`

---

## 6. Positive Findings

1. **Consistent security patterns**: CSRF, auth guards, rate limiting, and idempotency applied uniformly across all entry points.
2. **Clean layering**: Controllers never touch DB directly. Business logic centralized in Services. SQL isolated in Repositories.
3. **Row-level locking**: Critical flows (borrow, return, reserve, cancel, fulfill, pay, delete) properly use `SELECT ... FOR UPDATE` within transactions.
4. **DB-level guards**: `decrementAvailable()` and `incrementAvailable()` have `WHERE` guards that prevent stock from going negative or exceeding quantity, even under race conditions.
5. **Lazy expiration pattern**: Reservations expire on read (before displaying data), ensuring accurate stock counts without requiring a running cron job.
6. **Dual cron + lazy**: Both cron jobs and lazy expiration exist, providing redundancy.
7. **Input validation**: Shared `validateMemberData()` used across register, admin create, and import — single source of truth.
8. **File upload security**: MIME verified from file content (not client header), extension derived from verified MIME, filename randomized.
9. **No SQL injection vectors**: 100% prepared statements, no string concatenation of user input into SQL.
10. **Session security**: `session_regenerate_id(true)` on login, inactivity timeout, secure session configuration.

---

## 7. Final Verdict

| Category | Rating |
|----------|--------|
| **SQL Injection** | ✅ Secure |
| **XSS** | ✅ Secure |
| **CSRF** | ✅ Secure |
| **Authentication** | ✅ Secure |
| **Authorization** | ✅ Secure |
| **Session Management** | ✅ Secure |
| **Rate Limiting** | ✅ Implemented |
| **File Upload** | ✅ Secure |
| **Data Integrity (Stock)** | ✅ Correct (minor latent risk in `updateAvailable()`) |
| **Transaction Safety** | ✅ Correct |
| **Concurrency (Race Conditions)** | ✅ Handled via row locks |
| **State Machine Consistency** | ✅ Correct |
| **Code Quality** | ✅ Clean, well-documented |

**Overall: The application is well-engineered with strong security practices and correct data integrity handling.** The issues found are all low-risk or informational — no critical or high-severity vulnerabilities were identified. The codebase demonstrates professional-level patterns for a PHP application: proper separation of concerns, transaction management, row-level locking, and defense-in-depth security measures.

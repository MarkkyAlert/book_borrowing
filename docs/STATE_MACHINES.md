# 📊 State Machines - ระบบยืมคืนหนังสือ

> เอกสารนี้อธิบาย State Machine ของ Entity หลักทั้งหมดในระบบ
> รวมถึง transitions ที่อนุญาต, ต้องห้าม, guards และ side effects

---

## 1️⃣ BORROW Entity

### States

| State | Description | DB Value |
|-------|-------------|----------|
| `BORROWING` | กำลังยืมอยู่ | `status = 'borrowing'` |
| `RETURNED` | คืนแล้ว (Terminal) | `status = 'returned'` |

### State Diagram

```
    ┌─────────────┐
    │   (init)    │
    └──────┬──────┘
           │ createBorrow()
           ▼
    ┌─────────────┐
    │  BORROWING  │◄──── ไม่มี transition กลับมา
    └──────┬──────┘
           │ returnBook()
           ▼
    ┌─────────────┐
    │  RETURNED   │ [Terminal State]
    └─────────────┘
```

### Allowed Transitions

| From | Action | To | Guard Conditions | Side Effects |
|------|--------|----|------------------|--------------|
| (init) | `createBorrow()` | BORROWING | user เป็น member, book.available > 0, user ไม่ถึง quota, ไม่ยืมเล่มนี้อยู่แล้ว | `books.available -= 1`, INSERT borrow, SET borrow_date, due_date |
| BORROWING | `returnBook()` | RETURNED | borrow exists, status = 'borrowing' | `books.available += 1`, SET return_date, คำนวณ fine_amount, (optional) INSERT payment |

### Forbidden Transitions

| From | Action | Why Forbidden | Protection |
|------|--------|---------------|------------|
| RETURNED | returnBook() | คืนแล้วคืนอีกไม่ได้ | `findByIdForUpdate()` filter `status='borrowing'` |
| RETURNED | → BORROWING | ห้ามย้อนสถานะ | ไม่มี method ให้ทำ |
| BORROWING | createBorrow() (same book) | ยืมเล่มเดิมซ้ำไม่ได้ | `isAlreadyBorrowing()` check |

### Invariants (กฎที่ต้องจริงเสมอ)

```
✅ SUM(active borrows per user) <= MAX_BORROW_BOOKS
✅ borrow.status ∈ {'borrowing', 'returned'}
✅ borrow.return_date = NULL iff status = 'borrowing'
✅ borrow.fine_amount >= 0
```

### ⚠️ Potential State Jump Points

| Risk | Location | Status |
|------|----------|--------|
| Direct UPDATE status via SQL | ไม่มี API expose | ✅ Safe |
| markAsReturned() ไม่ check status | `BorrowRepository:166` | ⚠️ **แต่ถูก guard โดย Service layer** |

---

## 2️⃣ RESERVATION Entity

### States

| State | Description | DB Value |
|-------|-------------|----------|
| `PENDING` | รอรับหนังสือ | `status = 'pending'` |
| `FULFILLED` | อนุมัติแล้ว → สร้าง borrow | `status = 'fulfilled'` |
| `CANCELLED` | ยกเลิกโดย user/admin | `status = 'cancelled'` |
| `EXPIRED` | หมดอายุ (ไม่มารับ) | `status = 'expired'` |

### State Diagram

```
                    ┌─────────────┐
                    │   (init)    │
                    └──────┬──────┘
                           │ createReservation()
                           ▼
                    ┌─────────────┐
         ┌──────────│   PENDING   │──────────┐
         │          └──────┬──────┘          │
         │                 │                 │
    cancelReservation()    │           expireOverdue()
         │                 │ fulfillReservation()
         ▼                 ▼                 ▼
    ┌─────────────┐  ┌─────────────┐  ┌─────────────┐
    │  CANCELLED  │  │  FULFILLED  │  │   EXPIRED   │
    └─────────────┘  └─────────────┘  └─────────────┘
         [Terminal]       [Terminal]       [Terminal]
```

### Allowed Transitions

| From | Action | To | Guard Conditions | Side Effects |
|------|--------|----|------------------|--------------|
| (init) | `createReservation()` | PENDING | user logged in, book.available > 0, ไม่มี pending ซ้ำ | `books.available -= 1`, INSERT reservation, SET expires_at |
| PENDING | `fulfillReservation()` | FULFILLED | reservation exists, status = 'pending', user ไม่ถึง quota | INSERT borrow, SET borrow_id, **ไม่ต้อง update stock (หักไปแล้วตอนจอง)** |
| PENDING | `cancelReservation()` | CANCELLED | reservation exists, status = 'pending', (ถ้า member: ต้องเป็นเจ้าของ) | `books.available += 1` |
| PENDING | `expireOverdueReservations()` | EXPIRED | expires_at < NOW(), status = 'pending' | `books.available += 1` |

### Forbidden Transitions

| From | Action | Why Forbidden | Protection |
|------|--------|---------------|------------|
| FULFILLED/CANCELLED/EXPIRED | ใดๆ | Terminal states | `updateStatus()` check `status = 'pending'` |
| PENDING → PENDING | re-reserve | ซ้ำไม่ได้ | `hasPending()` check |

### Invariants

```
✅ reservation.status ∈ {'pending', 'fulfilled', 'cancelled', 'expired'}
✅ reservation.borrow_id != NULL iff status = 'fulfilled'
✅ ถ้า status = 'pending' → มี 1 copy ของ book ถูก hold ไว้
```

### ⚠️ Potential State Jump Points

| Risk | Location | Status |
|------|----------|--------|
| `updateStatus()` ไม่ check from state | `ReservationRepository:123` | ✅ **มี guard `status = 'pending'` ใน SQL** |
| Lazy expire ไม่คืน stock | `markExpiredReservations()` | ✅ **แก้แล้ว: คืน stock ใน transaction เดียวกัน** |

---

## 3️⃣ BOOK (Stock) Entity

### States (Implicit via `available` field)

| Condition | Logical State |
|-----------|---------------|
| `available > 0` | IN_STOCK |
| `available = 0` | OUT_OF_STOCK |
| `available < 0` | ❌ INVALID (ป้องกันแล้ว) |

### State Diagram

```
                 ┌─────────────────┐
                 │    IN_STOCK     │◄─────────────────┐
                 │  (available > 0)│                  │
                 └────────┬────────┘                  │
                          │                           │
    borrow / reserve      │       return / cancel / expire
    (decrement)           │       (increment)
                          ▼                           │
                 ┌─────────────────┐                  │
                 │  OUT_OF_STOCK   │──────────────────┘
                 │  (available = 0)│
                 └─────────────────┘
```

### Transitions (Stock Changes)

| Action | Effect | Guard | Side Effect Location |
|--------|--------|-------|---------------------|
| `createBorrow()` | available -= 1 | available > 0 | `BookRepository::decrementAvailable()` |
| `createReservation()` | available -= 1 | available > 0 | `BookRepository::decrementAvailable()` |
| `returnBook()` | available += 1 | - | `BookRepository::incrementAvailable()` |
| `cancelReservation()` | available += 1 | - | `BookRepository::incrementAvailable()` |
| `expireReservation()` | available += 1 | - | `BookRepository::incrementAvailable()` |

### Invariants

```
✅ available >= 0  (enforced by CHECK constraint + conditional UPDATE)
✅ available <= quantity
✅ borrowed_count = quantity - available
```

### ⚠️ Potential State Jump Points

| Risk | Location | Status |
|------|----------|--------|
| decrement ติดลบ | `BookRepository::decrementAvailable()` | ✅ **แก้แล้ว: conditional UPDATE** |
| increment เกิน quantity | `BookRepository::incrementAvailable()` | ⚠️ ไม่มี guard แต่ไม่เกิดถ้า flow ถูก |
| Manual UPDATE available | Direct SQL | ⚠️ CHECK constraint ป้องกัน (ถ้ารัน migration) |

---

## 4️⃣ PAYMENT Entity

### States (Implicit - No status field)

| Condition | Logical State |
|-----------|---------------|
| Row exists | PAID |
| Row not exists (for borrow with fine) | UNPAID |

### State Diagram

```
    ┌─────────────────┐
    │     UNPAID      │  (borrow.fine_amount > 0 AND no payment row)
    │  (implicit)     │
    └────────┬────────┘
             │ payFine() / returnBook(payNow=true)
             ▼
    ┌─────────────────┐
    │      PAID       │  (payment row exists)
    │   [Terminal]    │
    └─────────────────┘
```

### Transitions

| From | Action | To | Guard Conditions | Side Effects |
|------|--------|----|------------------|--------------|
| UNPAID | `payFine()` | PAID | borrow.fine_amount > 0, no existing payment | INSERT payment, SET recorded_by |
| UNPAID | `returnBook(payNow=true)` | PAID | fine calculated > 0 | INSERT payment |

### Invariants

```
✅ UNIQUE(borrow_id) - ชำระได้ครั้งเดียวต่อ borrow
✅ payment.amount > 0
✅ payment exists → borrow.fine_amount > 0
```

### ⚠️ Potential State Jump Points

| Risk | Location | Status |
|------|----------|--------|
| ชำระซ้ำ | `PaymentRepository::create()` | ✅ **UNIQUE INDEX ป้องกัน** |
| ชำระโดยไม่มี fine | `BorrowService::payFine()` | ✅ **check fine_amount > 0** |

---

## 5️⃣ PASSWORD_RESET Entity

### States

| State | Description | DB Columns |
|-------|-------------|------------|
| ACTIVE | ใช้ได้ | `used = 0, expires_at > NOW()` |
| USED | ใช้แล้ว | `used = 1` |
| EXPIRED | หมดอายุ | `expires_at < NOW()` |

### State Diagram

```
    ┌─────────────┐
    │   (init)    │
    └──────┬──────┘
           │ createToken()
           ▼
    ┌─────────────┐
    │   ACTIVE    │──────────────┐
    └──────┬──────┘              │
           │ resetPassword()     │ time passes
           ▼                     ▼
    ┌─────────────┐       ┌─────────────┐
    │    USED     │       │   EXPIRED   │
    └─────────────┘       └─────────────┘
    [Terminal]            [Terminal]
```

### Invariants

```
✅ token is UNIQUE
✅ used ∈ {0, 1}
✅ expires_at > created_at
```

---

## 🔴 สรุปช่องโหว่ที่อาจกระโดดสถานะ

### ✅ ป้องกันแล้ว

| Entity | Risk | Protection |
|--------|------|------------|
| Borrow | คืนซ้ำ | `findByIdForUpdate()` filter status |
| Borrow | ยืมเกิน quota | `lockById()` + `countActiveBorrowsForUpdate()` |
| Reservation | approve/cancel ซ้ำ | `updateStatus()` check `status = 'pending'` |
| Payment | ชำระซ้ำ | `UNIQUE INDEX (borrow_id)` |
| Book | available ติดลบ | conditional UPDATE + CHECK constraint |

### ⚠️ ต้องระวัง

| Entity | Risk | Mitigation |
|--------|------|------------|
| Reservation | Lazy expire ไม่คืน stock | ✅ **แก้แล้ว** - คืน stock ใน transaction |
| Book | increment เกิน quantity | ไม่มี guard แต่ไม่เกิดถ้า flow ถูกต้อง + มี CHECK constraint |

### 🛡️ Defense in Depth Layers

```
Layer 1: Service layer validation (business rules)
Layer 2: Repository layer guards (SQL WHERE clauses)
Layer 3: Database constraints (UNIQUE, CHECK, FK)
Layer 4: Transaction isolation (FOR UPDATE locks)
```

---

## 📋 Quick Reference: All Valid Transitions

```
BORROW:
  (new) --createBorrow()--> BORROWING --returnBook()--> RETURNED

RESERVATION:
  (new) --createReservation()--> PENDING --fulfillReservation()--> FULFILLED
                                    |----cancelReservation()----> CANCELLED
                                    |----expireOverdue()--------> EXPIRED

BOOK STOCK:
  IN_STOCK <--increment/decrement--> OUT_OF_STOCK

PAYMENT:
  (implicit UNPAID) --payFine()--> PAID

PASSWORD_RESET:
  (new) --create()--> ACTIVE --use()--> USED
                         |--timeout()--> EXPIRED
```

---

*Document generated: 2026-02-02*
*System: Book Borrowing System v1.0*

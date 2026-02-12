-- Migration 004: Logic Audit Fixes
-- Ref: docs/LOGIC_AUDIT.md
-- Date: 2026-02-12
--
-- แก้ไข 3 ปัญหาระดับ DB schema:
--   I-03: ISBN ไม่มี UNIQUE constraint → duplicate ISBN ได้
--   I-04: CASCADE DELETE บน borrows/reservations → stock leak ถ้า bypass app guard
--   I-01/I-02: (หมายเหตุ) partial unique index ต้อง MySQL 8.0+ จึงไม่รวมที่นี่
--
-- ⚠️ ก่อนรัน: backup ฐานข้อมูลก่อนเสมอ
-- ⚠️ I-04: ถ้ามี orphan records ที่ FK ชี้ไป user/book ที่ไม่มีแล้ว → migration จะ fail
--          ต้องจัดการ orphan records ก่อน

-- =====================================================
-- I-03 FIX: เพิ่ม UNIQUE index บน books.isbn
-- =====================================================
-- ป้องกัน 2 admin สร้างหนังสือ ISBN เดียวกันพร้อมกัน
-- NULL อนุญาตได้หลายค่า (MySQL UNIQUE ยอมให้ NULL ซ้ำ)
-- ถ้ามี duplicate ISBN อยู่แล้ว → migration จะ fail → ต้องจัดการ duplicate ก่อน
ALTER TABLE books ADD UNIQUE INDEX uq_isbn (isbn);

-- =====================================================
-- I-04 FIX: เปลี่ยน CASCADE → RESTRICT บน borrows FK
-- =====================================================
-- ป้องกัน: ลบ user/book ผ่าน DB โดยตรง → CASCADE ลบ active borrows → stock ไม่ถูกคืน
-- App guard (deleteMember/deleteBook) ป้องกันอยู่แล้ว แต่ RESTRICT เพิ่ม defense-in-depth
-- ถ้าลบ user/book ที่มี borrows → DB จะ error แทนที่จะ CASCADE ลบ

-- borrows.user_id: CASCADE → RESTRICT
ALTER TABLE borrows DROP FOREIGN KEY borrows_ibfk_1;
ALTER TABLE borrows ADD CONSTRAINT fk_borrows_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- borrows.book_id: CASCADE → RESTRICT
ALTER TABLE borrows DROP FOREIGN KEY borrows_ibfk_2;
ALTER TABLE borrows ADD CONSTRAINT fk_borrows_book
    FOREIGN KEY (book_id) REFERENCES books(id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- =====================================================
-- I-04 FIX: เปลี่ยน CASCADE → RESTRICT บน reservations FK
-- =====================================================
-- เหตุผลเดียวกัน: ป้องกัน CASCADE ลบ pending reservation → stock ไม่ถูกคืน

-- reservations.user_id: CASCADE → RESTRICT
ALTER TABLE reservations DROP FOREIGN KEY reservations_ibfk_1;
ALTER TABLE reservations ADD CONSTRAINT fk_reservations_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- reservations.book_id: CASCADE → RESTRICT
ALTER TABLE reservations DROP FOREIGN KEY reservations_ibfk_2;
ALTER TABLE reservations ADD CONSTRAINT fk_reservations_book
    FOREIGN KEY (book_id) REFERENCES books(id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- หมายเหตุ: reservations.borrow_id ยังคงเป็น ON DELETE SET NULL (ถูกต้อง)
--   เพราะถ้าลบ borrow → reservation ควรยังอยู่ แค่ borrow_id เป็น NULL

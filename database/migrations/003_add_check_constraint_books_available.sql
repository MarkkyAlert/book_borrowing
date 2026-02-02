-- =====================================================
-- Migration: Add CHECK constraint for books.available
-- =====================================================
-- ป้องกัน available ติดลบ (defense in depth)
-- 
-- หมายเหตุ: MySQL 8.0.16+ รองรับ CHECK constraints
-- หากใช้ MySQL เวอร์ชันเก่ากว่า constraint จะถูกสร้างแต่ไม่ enforce
-- =====================================================

-- เพิ่ม CHECK constraint สำหรับ books.available >= 0
ALTER TABLE `books` 
ADD CONSTRAINT `chk_books_available_non_negative` 
CHECK (`available` >= 0);

-- เพิ่ม CHECK constraint สำหรับ books.quantity >= available
ALTER TABLE `books` 
ADD CONSTRAINT `chk_books_quantity_gte_available` 
CHECK (`quantity` >= `available`);

-- =====================================================
-- Verify: ตรวจสอบว่า constraint ถูกสร้าง
-- =====================================================
-- SELECT CONSTRAINT_NAME, CHECK_CLAUSE 
-- FROM information_schema.CHECK_CONSTRAINTS 
-- WHERE CONSTRAINT_SCHEMA = 'book_borrowing';

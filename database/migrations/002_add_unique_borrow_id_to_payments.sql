-- =====================================================
-- Migration: Add UNIQUE constraint on payments.borrow_id
-- =====================================================
-- วัตถุประสงค์: ป้องกัน duplicate payment records สำหรับ borrow เดียวกัน
-- เป็น safety net เพิ่มเติมจาก application-level check
-- 
-- วิธีใช้: 
-- 1. รัน SQL นี้ใน phpMyAdmin หรือ MySQL client
-- 2. ตรวจสอบว่าไม่มี duplicate ก่อนรัน (ถ้ามีจะ error)
-- =====================================================

-- ตรวจสอบ duplicate ก่อน (รันดูก่อน ถ้ามีผลลัพธ์ต้องแก้ข้อมูลก่อน)
-- SELECT borrow_id, COUNT(*) as cnt FROM payments GROUP BY borrow_id HAVING cnt > 1;

-- เพิ่ม UNIQUE constraint
ALTER TABLE `payments` 
ADD UNIQUE INDEX `unique_borrow_payment` (`borrow_id`);

-- =====================================================
-- Rollback (ถ้าต้องการย้อนกลับ):
-- ALTER TABLE `payments` DROP INDEX `unique_borrow_payment`;
-- =====================================================

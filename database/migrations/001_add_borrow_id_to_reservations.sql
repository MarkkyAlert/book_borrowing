-- =====================================================
-- Migration: Add borrow_id to reservations table
-- Purpose: Link fulfilled reservations to their borrow records
-- =====================================================

-- Add borrow_id column
ALTER TABLE reservations 
ADD COLUMN borrow_id INT DEFAULT NULL COMMENT 'รายการยืมที่สร้างจากการจอง (เฉพาะ fulfilled)',
ADD FOREIGN KEY (borrow_id) REFERENCES borrows(id) ON DELETE SET NULL ON UPDATE CASCADE;

-- Add check constraint for available <= quantity (High #4)
-- Note: MySQL 8.0.16+ supports CHECK constraints
ALTER TABLE books
ADD CONSTRAINT chk_available_quantity CHECK (available >= 0 AND available <= quantity);

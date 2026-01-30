-- Migration SQL for Book Quantity System
-- Run this in phpMyAdmin if you have existing data

-- Step 1: Add new columns
ALTER TABLE books 
  ADD COLUMN quantity INT NOT NULL DEFAULT 1 AFTER cover_image,
  ADD COLUMN available INT NOT NULL DEFAULT 1 AFTER quantity;

-- Step 2: Set available based on current borrow status
-- If a book is borrowed, available = 0, otherwise = 1
UPDATE books SET 
  quantity = 1,
  available = CASE 
    WHEN id IN (SELECT book_id FROM borrows WHERE status = 'borrowing') THEN 0 
    ELSE 1 
  END;

-- Step 3: Add index for performance
ALTER TABLE books ADD INDEX idx_available (available);

-- Step 4 (Optional): Drop status column after verifying everything works
-- ALTER TABLE books DROP COLUMN status;

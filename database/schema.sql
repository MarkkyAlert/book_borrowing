-- =====================================================
-- ระบบยืมคืนหนังสือ - Database Schema
-- =====================================================
-- ใช้ไฟล์นี้สำหรับสร้างฐานข้อมูลด้วยตนเอง
-- หรือใช้ install.php สำหรับติดตั้งอัตโนมัติ
--
-- ⚠️ ไฟล์นี้สร้าง database ชื่อ `book_borrowing` ให้เลย (2 บรรทัดด้านล่าง)
--    ถ้าตั้ง DB_NAME ใน .env เป็นชื่ออื่น ต้องแก้ชื่อในบรรทัด CREATE DATABASE + USE
--    ให้ตรงกันก่อนรัน ไม่งั้นตารางจะไปอยู่ผิดฐานข้อมูล
--    (ต่างจาก sample_data.sql ที่ไม่ระบุชื่อ DB — เลือก database เองก่อน import)
-- =====================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- สร้างฐานข้อมูล
CREATE DATABASE IF NOT EXISTS `book_borrowing` 
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `book_borrowing`;

-- =====================================================
-- ตาราง: users (ผู้ใช้งาน)
-- =====================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT 'ชื่อ-นามสกุล',
    `email` VARCHAR(100) NOT NULL UNIQUE COMMENT 'อีเมล',
    `password` VARCHAR(255) NOT NULL COMMENT 'รหัสผ่าน (bcrypt)',
    `phone` VARCHAR(20) DEFAULT NULL COMMENT 'เบอร์โทรศัพท์',
    `role` ENUM('member', 'admin', 'staff') NOT NULL DEFAULT 'member' COMMENT 'บทบาท',
    `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = ต้องเปลี่ยนรหัสผ่านก่อนใช้งาน (ตั้งตอนนำเข้า/admin สร้างให้)',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_email` (`email`),
    INDEX `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ตาราง: categories (หมวดหมู่)
-- =====================================================
CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE COMMENT 'ชื่อหมวดหมู่',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ตาราง: books (หนังสือ)
-- =====================================================
CREATE TABLE IF NOT EXISTS `books` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL COMMENT 'ชื่อหนังสือ',
    `author` VARCHAR(100) NOT NULL COMMENT 'ผู้แต่ง',
    `isbn` VARCHAR(20) DEFAULT NULL COMMENT 'รหัส ISBN',
    `call_number` VARCHAR(50) DEFAULT NULL COMMENT 'เลขเรียกหนังสือ — ที่อยู่บนชั้น (รูปแบบอิสระ แต่ละห้องสมุดกำหนดเอง)',
    -- 🔎 index ค้นหา: trigram ของ title+author+isbn สร้างโดย PHP (buildSearchTokens())
    --    ⚠️ ถ้า INSERT หนังสือด้วย SQL ตรง ๆ คอลัมน์นี้จะว่าง → ค้นหาเล่มนั้นไม่เจอ
    --       ต้องรัน `php database/rebuild_search_index.php` ตามหลังเสมอ
    `search_tokens` TEXT DEFAULT NULL COMMENT 'trigram สำหรับ FULLTEXT (สร้างโดย buildSearchTokens())',
    `category_id` INT DEFAULT NULL COMMENT 'หมวดหมู่',
    `description` TEXT DEFAULT NULL COMMENT 'รายละเอียด',
    `cover_image` VARCHAR(255) DEFAULT NULL COMMENT 'ชื่อไฟล์รูปปก',
    `quantity` INT NOT NULL DEFAULT 1 COMMENT 'จำนวนทั้งหมด',
    `price` DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'ราคาปก — ใช้ตั้งต้นค่าชดใช้ตอนแจ้งหาย (NULL = ยังไม่ระบุ)',
    `available` INT NOT NULL DEFAULT 1 COMMENT 'จำนวนที่ว่าง',
    `is_visible` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'แสดงให้สาธารณะเห็น',
    `is_reference` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'หนังสืออ้างอิง — อ่านในห้องสมุดเท่านั้น ยืม/จองไม่ได้',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_available` (`available`),
    INDEX `idx_category` (`category_id`),
    UNIQUE INDEX `uq_isbn` (`isbn`),
    -- 📇 สำหรับ "อ่านชั้น" (เรียงตามเลขเรียก) และค้นหาด้วยเลขเรียก
    INDEX `idx_call_number` (`call_number`),
    -- 🔎 FULLTEXT บน trigram ไม่ใช่บน title/author โดยตรง
    --    เพราะ MySQL ตัดคำด้วยช่องว่าง ภาษาไทยไม่มีช่องว่าง → ค้นคำกลางชื่อเรื่องไม่เจอ
    FULLTEXT KEY `ft_books_search` (`search_tokens`),
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_books_available_non_negative` CHECK (`available` >= 0),
    CONSTRAINT `chk_books_quantity_gte_available` CHECK (`quantity` >= `available`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ตาราง: borrows (การยืม)
-- =====================================================
CREATE TABLE IF NOT EXISTS `borrows` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL COMMENT 'ผู้ยืม',
    `book_id` INT NOT NULL COMMENT 'หนังสือที่ยืม',
    `borrow_date` DATE NOT NULL COMMENT 'วันที่ยืม',
    `due_date` DATE NOT NULL COMMENT 'กำหนดคืน',
    `renew_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ต่ออายุไปแล้วกี่ครั้ง',
    `return_date` DATE DEFAULT NULL COMMENT 'วันที่คืนจริง',
    `status` ENUM('borrowing', 'returned', 'lost', 'damaged') NOT NULL DEFAULT 'borrowing' COMMENT 'borrowing=ยังไม่คืน / returned=คืนแล้ว / lost=หาย / damaged=ชำรุดจนใช้ไม่ได้',
    `fine_amount` DECIMAL(10,2) DEFAULT 0 COMMENT 'ค่าปรับ',
    `lost_reported_at` DATETIME NULL COMMENT 'เวลาที่แจ้งหาย/ชำรุด (แยกจาก return_date เพราะรายงานนับ "คืนแล้ว" จาก return_date โดยไม่กรอง status)',
    `lost_reported_by` INT NULL COMMENT 'ผู้แจ้ง',
    `lost_note` VARCHAR(255) NULL COMMENT 'รายละเอียด/เหตุผล',
    `fine_waived_at` DATETIME NULL COMMENT 'เวลาที่ยกเว้นค่าปรับ (NULL = ยังไม่ยกเว้น)',
    `fine_waived_by` INT NULL COMMENT 'ผู้ยกเว้น',
    `fine_waived_note` VARCHAR(255) NULL COMMENT 'เหตุผลที่ยกเว้น',
    `notes` TEXT DEFAULT NULL COMMENT 'หมายเหตุ',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_book` (`book_id`),
    INDEX `idx_due_date` (`due_date`),
    -- 📇 หน้ารายการค้างชำระกรอง fine_waived_at IS NULL ทุกครั้ง
    INDEX `idx_fine_waived` (`fine_waived_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (`book_id`) REFERENCES `books`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    -- 🧠 ตั้งชื่อ constraint เองเพราะ migration อ้างชื่อนี้ตอนเช็คว่าเคยเพิ่มไปแล้วหรือยัง
    --    ON DELETE SET NULL = ลบบัญชีเจ้าหน้าที่แล้วประวัติยังอยู่ แค่ไม่รู้ว่าใครทำ
    CONSTRAINT `fk_borrows_waived_by` FOREIGN KEY (`fine_waived_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_borrows_lost_reported_by` FOREIGN KEY (`lost_reported_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ตาราง: reservations (การจอง)
-- =====================================================
CREATE TABLE IF NOT EXISTS `reservations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL COMMENT 'ผู้จอง',
    `book_id` INT NOT NULL COMMENT 'หนังสือที่จอง',
    `borrow_id` INT DEFAULT NULL COMMENT 'รายการยืมที่สร้างจากการจอง (เฉพาะ fulfilled)',
    `status` ENUM('waiting', 'pending', 'fulfilled', 'expired', 'cancelled') NOT NULL DEFAULT 'pending' COMMENT 'waiting=เข้าคิวรอ ไม่กินสต็อก / pending=ของพร้อม รอมารับ กินสต็อก',
    `queued_at` DATETIME NULL DEFAULT NULL COMMENT 'เวลาเข้าคิว — ใช้เรียงลำดับ',
    `expires_at` DATETIME NULL DEFAULT NULL COMMENT 'วันหมดอายุ — NULL สำหรับคิวรอ (waiting) ที่ไม่มีวันหมดอายุ',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `active_slot` TINYINT(1) GENERATED ALWAYS AS (CASE WHEN `status` IN ('waiting','pending') THEN 1 ELSE NULL END) VIRTUAL COMMENT 'ตัวช่วยกันจองซ้ำ — NULL เมื่อการจองปิดแล้ว ทำให้ unique ไม่ชน',
    INDEX `idx_status` (`status`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_book` (`book_id`),
    INDEX `idx_queue` (`book_id`, `status`, `queued_at`),
    -- 🛡️ กันจองซ้ำเล่มเดิม (KNOWN_LIMITATIONS §4) — ใช้ได้เพราะ MySQL มอง NULL ว่าไม่ชนกัน
    --    การจองที่ปิดแล้ว active_slot = NULL จึงจองเล่มเดิมใหม่ได้
    UNIQUE KEY `uq_reservation_active` (`user_id`, `book_id`, `active_slot`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (`book_id`) REFERENCES `books`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (`borrow_id`) REFERENCES `borrows`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ตาราง: payments (การชำระค่าปรับ)
-- =====================================================
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `borrow_id` INT NOT NULL COMMENT 'รายการยืมที่ชำระ',
    `amount` DECIMAL(10,2) NOT NULL COMMENT 'จำนวนเงิน',
    `recorded_by` INT DEFAULT NULL COMMENT 'ผู้บันทึก',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX `unique_borrow_payment` (`borrow_id`),
    INDEX `idx_borrow` (`borrow_id`),
    FOREIGN KEY (`borrow_id`) REFERENCES `borrows`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ตาราง: password_resets (รีเซ็ตรหัสผ่าน)
-- =====================================================
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(100) NOT NULL COMMENT 'อีเมลที่ขอรีเซ็ต',
    `token` VARCHAR(64) NOT NULL UNIQUE COMMENT 'Token สำหรับรีเซ็ต',
    `expires_at` DATETIME NOT NULL COMMENT 'วันหมดอายุ',
    `used` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'ใช้แล้วหรือยัง',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_email` (`email`),
    INDEX `idx_token` (`token`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ตาราง: settings (ตั้งค่าระบบ)
-- =====================================================
-- ==========================================
-- ตาราง closed_days — วันที่ห้องสมุดไม่เปิดทำการ
-- ==========================================
-- 🎯 ใช้หักออกจากการคิดค่าปรับ — ยืมคร่อมวันหยุดยาวแล้วโดนปรับทั้งที่ไม่มีวันให้มาคืน
-- 🧠 เก็บเป็น "ช่วงวัน" ไม่ใช่วันเดี่ยว เพราะปิดปรับปรุง 60 วันจะกลายเป็น 60 แถว
--    วันเดียว = ใส่ start_date เท่ากับ end_date
CREATE TABLE IF NOT EXISTS `closed_days` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `start_date` DATE NOT NULL COMMENT 'วันแรกที่ปิด',
    `end_date` DATE NOT NULL COMMENT 'วันสุดท้ายที่ปิด (วันเดียว = ใส่ค่าเดียวกับ start_date)',
    `note` VARCHAR(255) NOT NULL COMMENT 'เหตุผล เช่น วันหยุดนักขัตฤกษ์ / ปิดปรับปรุง',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_closed_range` (`start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Key',
    `setting_value` TEXT DEFAULT NULL COMMENT 'Value',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ตาราง: rate_limits (จำกัดจำนวนครั้งต่อ IP)
-- =====================================================
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `key_name` VARCHAR(255) NOT NULL COMMENT 'action + IP เช่น login_127.0.0.1',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_key_name` (`key_name`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- สร้างตาราง password_resets
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

-- หมายเหตุ: ต้องใช้ collation เดียวกับตาราง users (utf8mb4_unicode_ci)
-- ถ้ามี error เรื่อง collation ให้รัน:
-- ALTER TABLE password_resets CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Add 'staff' role to users table
ALTER TABLE `users` MODIFY COLUMN `role` ENUM('member', 'admin', 'staff') NOT NULL DEFAULT 'member';

-- Create payments table
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `borrow_id` INT NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `payment_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `recorded_by` INT, -- Staff/Admin who received the payment
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`borrow_id`) REFERENCES `borrows`(`id`),
    FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

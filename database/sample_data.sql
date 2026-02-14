-- =====================================================
-- ระบบยืมคืนหนังสือ - Sample Data
-- =====================================================
-- ข้อมูลตัวอย่างสำหรับทดสอบระบบ
-- รันไฟล์นี้หลังจาก schema.sql
-- =====================================================

SET NAMES utf8mb4;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;

USE `book_borrowing`;

-- ล้างข้อมูลเดิม (ถ้ามี) - ใช้ DELETE เพราะ TRUNCATE ติด FK
-- ⚠️ เก็บ admin (id=1) ไว้ เพื่อไม่ให้รหัสผ่านที่ตั้งผ่าน install.php หาย
DELETE FROM `payments` WHERE 1=1;
DELETE FROM `reservations` WHERE 1=1;
DELETE FROM `borrows` WHERE 1=1;
DELETE FROM `books` WHERE 1=1;
DELETE FROM `categories` WHERE 1=1;
DELETE FROM `users` WHERE id != 1;

-- Reset AUTO_INCREMENT
ALTER TABLE `categories` AUTO_INCREMENT = 1;
ALTER TABLE `books` AUTO_INCREMENT = 1;
ALTER TABLE `users` AUTO_INCREMENT = 1;
ALTER TABLE `borrows` AUTO_INCREMENT = 1;
ALTER TABLE `payments` AUTO_INCREMENT = 1;
ALTER TABLE `reservations` AUTO_INCREMENT = 1;

-- =====================================================
-- Users (รหัสผ่านทุกคน: 123456)
-- กำหนด ID ชัดเจนเพื่อให้ FK ทำงานถูกต้อง
-- =====================================================
INSERT INTO `users` (`id`, `name`, `email`, `password`, `phone`, `role`) VALUES
(1, 'ผู้ดูแลระบบ', 'admin@library.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0812345678', 'admin'),
(2, 'เจ้าหน้าที่ห้องสมุด', 'staff@library.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0898765432', 'staff'),
(3, 'สมชาย ใจดี', 'somchai@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0891234567', 'member'),
(4, 'สมหญิง รักเรียน', 'somying@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0897654321', 'member'),
(5, 'วิชัย อ่านเก่ง', 'wichai@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0823456789', 'member')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `role` = VALUES(`role`);

-- =====================================================
-- Categories (หมวดหมู่)
-- =====================================================
INSERT INTO `categories` (`id`, `name`) VALUES
(1, 'นิยาย'),
(2, 'วิชาการ'),
(3, 'การ์ตูน'),
(4, 'จิตวิทยา'),
(5, 'ธุรกิจ'),
(6, 'วรรณกรรม'),
(7, 'ประวัติศาสตร์'),
(8, 'เทคโนโลยี')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- =====================================================
-- Sample Books (หนังสือตัวอย่าง)
-- =====================================================
INSERT INTO `books` (`title`, `author`, `isbn`, `category_id`, `description`, `quantity`, `available`) VALUES
('เกมล่าสังหาร', 'ซูซาน คอลลินส์', '978-616-XXX-001', 1, 'นิยายดิสโทเปียที่ได้รับความนิยมอย่างล้นหลาม', 3, 3),
('Atomic Habits', 'James Clear', '978-0-7352-1131-3', 4, 'หนังสือพัฒนาตนเองที่ขายดีที่สุดในโลก เกี่ยวกับการสร้างนิสัยที่ดี', 5, 5),
('พ่อรวยสอนลูก', 'Robert Kiyosaki', '978-616-XXX-003', 5, 'หนังสือการเงินส่วนบุคคลที่ขายดีตลอดกาล', 2, 2),
('วัยรุ่นพันล้าน', 'ท็อป จิรายุส', '978-616-XXX-004', 5, 'แรงบันดาลใจจากผู้ประกอบการรุ่นใหม่', 4, 4),
('ฟิสิกส์มหัศจรรย์', 'รศ.ดร.เจษฎา', '978-616-XXX-005', 2, 'หนังสือวิทยาศาสตร์สำหรับผู้เริ่มต้น', 1, 1),
('แฮร์รี่ พอตเตอร์ กับศิลาอาถรรพ์', 'J.K. Rowling', '978-616-XXX-006', 1, 'นิยายแฟนตาซีที่มีผู้อ่านมากที่สุดในโลก', 3, 3),
('คิดเป็น รวยเป็น', 'Napoleon Hill', '978-616-XXX-007', 5, 'หนังสือแรงบันดาลใจคลาสสิก', 2, 2),
('ศิลปะแห่งการไม่แคร์', 'Mark Manson', '978-616-XXX-008', 4, 'มุมมองใหม่ในการใช้ชีวิต', 3, 3),
('โดราเอมอน เล่ม 1', 'ฟุจิโกะ ฟุจิโอะ', '978-616-XXX-009', 3, 'การ์ตูนญี่ปุ่นยอดนิยมตลอดกาล', 5, 5),
('Python Programming', 'Eric Matthes', '978-1-59327-584-3', 8, 'หนังสือเรียนรู้ Python สำหรับผู้เริ่มต้น', 2, 2)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

-- =====================================================
-- Sample Settings
-- =====================================================
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('library_name', 'ห้องสมุดประชาชน'),
('library_address', '123 ถนนหนังสือ แขวงอ่านเพลิน เขตรักการอ่าน กรุงเทพฯ 10100'),
('library_phone', '02-123-4567'),
('library_email', 'contact@library.com'),
('org_name', 'DEMO LIBRARY'),
('card_color_primary', '#1e3a8a'),
('card_color_secondary', '#3b82f6')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- =====================================================
-- Sample Borrows (การยืม) - สำหรับ Demo
-- =====================================================
-- หมายเหตุ: ต้องรันหลังจากมี users และ books แล้ว

-- ยืมปกติ (กำลังยืม)
INSERT INTO `borrows` (`user_id`, `book_id`, `borrow_date`, `due_date`, `status`, `fine_amount`) VALUES
(3, 1, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 9 DAY), 'borrowing', 0),
(3, 2, DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_ADD(CURDATE(), INTERVAL 11 DAY), 'borrowing', 0),
(4, 3, DATE_SUB(CURDATE(), INTERVAL 7 DAY), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'borrowing', 0),
(5, 6, DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 12 DAY), 'borrowing', 0);

-- ยืมเกินกำหนด (overdue)
INSERT INTO `borrows` (`user_id`, `book_id`, `borrow_date`, `due_date`, `status`, `fine_amount`) VALUES
(4, 4, DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'borrowing', 0),
(5, 9, DATE_SUB(CURDATE(), INTERVAL 18 DAY), DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'borrowing', 0);

-- คืนแล้ว (ไม่มีค่าปรับ)
INSERT INTO `borrows` (`user_id`, `book_id`, `borrow_date`, `due_date`, `return_date`, `status`, `fine_amount`) VALUES
(3, 5, DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_SUB(CURDATE(), INTERVAL 16 DAY), DATE_SUB(CURDATE(), INTERVAL 18 DAY), 'returned', 0),
(4, 7, DATE_SUB(CURDATE(), INTERVAL 25 DAY), DATE_SUB(CURDATE(), INTERVAL 11 DAY), DATE_SUB(CURDATE(), INTERVAL 12 DAY), 'returned', 0),
(5, 8, DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_SUB(CURDATE(), INTERVAL 6 DAY), DATE_SUB(CURDATE(), INTERVAL 7 DAY), 'returned', 0);

-- คืนแล้ว (มีค่าปรับ - ชำระแล้ว)
INSERT INTO `borrows` (`user_id`, `book_id`, `borrow_date`, `due_date`, `return_date`, `status`, `fine_amount`) VALUES
(3, 10, DATE_SUB(CURDATE(), INTERVAL 40 DAY), DATE_SUB(CURDATE(), INTERVAL 26 DAY), DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'returned', 120),
(4, 1, DATE_SUB(CURDATE(), INTERVAL 35 DAY), DATE_SUB(CURDATE(), INTERVAL 21 DAY), DATE_SUB(CURDATE(), INTERVAL 14 DAY), 'returned', 140);

-- คืนแล้ว (มีค่าปรับ - ยังไม่ชำระ)
INSERT INTO `borrows` (`user_id`, `book_id`, `borrow_date`, `due_date`, `return_date`, `status`, `fine_amount`) VALUES
(5, 2, DATE_SUB(CURDATE(), INTERVAL 45 DAY), DATE_SUB(CURDATE(), INTERVAL 31 DAY), DATE_SUB(CURDATE(), INTERVAL 25 DAY), 'returned', 120),
(3, 4, DATE_SUB(CURDATE(), INTERVAL 50 DAY), DATE_SUB(CURDATE(), INTERVAL 36 DAY), DATE_SUB(CURDATE(), INTERVAL 21 DAY), 'returned', 300);

-- =====================================================
-- Update Book Availability (ปรับ stock ตามการยืม)
-- =====================================================
UPDATE `books` SET `available` = `available` - 1 WHERE `id` = 1;
UPDATE `books` SET `available` = `available` - 1 WHERE `id` = 2;
UPDATE `books` SET `available` = `available` - 1 WHERE `id` = 3;
UPDATE `books` SET `available` = `available` - 1 WHERE `id` = 4;
UPDATE `books` SET `available` = `available` - 1 WHERE `id` = 6;
UPDATE `books` SET `available` = `available` - 1 WHERE `id` = 9;

-- =====================================================
-- Sample Payments (การชำระค่าปรับ)
-- =====================================================
-- หมายเหตุ: borrow_id ต้องตรงกับ borrows ที่มีค่าปรับและชำระแล้ว
INSERT INTO `payments` (`borrow_id`, `amount`, `recorded_by`, `created_at`) VALUES
(10, 120, 2, DATE_SUB(NOW(), INTERVAL 19 DAY)),
(11, 140, 2, DATE_SUB(NOW(), INTERVAL 13 DAY));

-- =====================================================
-- Sample Reservations (การจอง)
-- =====================================================
-- จองรอดำเนินการ
INSERT INTO `reservations` (`user_id`, `book_id`, `status`, `expires_at`, `created_at`) VALUES
(5, 1, 'pending', DATE_ADD(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
(3, 4, 'pending', DATE_ADD(NOW(), INTERVAL 3 DAY), NOW());

-- จองสำเร็จแล้ว (fulfilled)
INSERT INTO `reservations` (`user_id`, `book_id`, `borrow_id`, `status`, `expires_at`, `created_at`) VALUES
(4, 3, 3, 'fulfilled', DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 8 DAY));

-- จองหมดอายุ
INSERT INTO `reservations` (`user_id`, `book_id`, `status`, `expires_at`, `created_at`) VALUES
(5, 7, 'expired', DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY));

-- จองยกเลิก
INSERT INTO `reservations` (`user_id`, `book_id`, `status`, `expires_at`, `created_at`) VALUES
(3, 8, 'cancelled', DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY));

-- =====================================================
-- เสร็จสิ้น
-- =====================================================
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SELECT 'Sample data imported successfully!' AS status;

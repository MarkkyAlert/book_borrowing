-- =====================================================
-- ระบบยืมคืนหนังสือ - Sample Data
-- =====================================================
-- ข้อมูลตัวอย่างสำหรับทดสอบระบบ
-- รันไฟล์นี้หลังจาก schema.sql
-- =====================================================

USE `book_borrowing`;

-- =====================================================
-- Admin Account (รหัสผ่าน: 123456)
-- =====================================================
INSERT INTO `users` (`name`, `email`, `password`, `phone`, `role`) VALUES
('ผู้ดูแลระบบ', 'admin@library.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0812345678', 'admin')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- =====================================================
-- Sample Staff Account (รหัสผ่าน: 123456)
-- =====================================================
INSERT INTO `users` (`name`, `email`, `password`, `phone`, `role`) VALUES
('เจ้าหน้าที่ห้องสมุด', 'staff@library.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0898765432', 'staff')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- =====================================================
-- Sample Members (รหัสผ่าน: 123456)
-- =====================================================
INSERT INTO `users` (`name`, `email`, `password`, `phone`, `role`) VALUES
('สมชาย ใจดี', 'somchai@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0891234567', 'member'),
('สมหญิง รักเรียน', 'somying@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0897654321', 'member'),
('วิชัย อ่านเก่ง', 'wichai@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0823456789', 'member')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- =====================================================
-- Categories (หมวดหมู่)
-- =====================================================
INSERT INTO `categories` (`name`) VALUES
('นิยาย'),
('วิชาการ'),
('การ์ตูน'),
('จิตวิทยา'),
('ธุรกิจ'),
('วรรณกรรม'),
('ประวัติศาสตร์'),
('เทคโนโลยี')
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
('library_email', 'contact@library.com')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

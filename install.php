<?php

/**
 * Database Installation Script
 * เข้าถึง: {APP_URL}/install.php
 * 
 * ⚠️ ควรลบไฟล์นี้หลังติดตั้งเสร็จ
 */

// 🔌 โหลดเฉพาะ config (constants) — ไม่ใช้ bootstrap เพราะ DB ยังไม่มี
require_once __DIR__ . '/includes/config.php';
// 🔎 ต้องใช้ buildSearchTokens() ตอนใส่หนังสือตัวอย่าง
//    functions.php ไม่แตะ DB ตอนโหลด (แค่ startSession()) จึงเรียกก่อนสร้างฐานข้อมูลได้
//    🧠 ห้าม copy สูตร trigram มาไว้ที่นี่ — ต้องเป็นสูตรเดียวกับตอนค้นหาเสมอ
require_once __DIR__ . '/includes/functions.php';

// =====================================================
// 🔒 INSTALL LOCK — ป้องกันการติดตั้งซ้ำ (2 ชั้น)
// =====================================================
// 🛡️ [SECURITY] ล็อค 2 ชั้นเพราะชั้นไฟล์อย่างเดียวไม่พอ:
//    ถ้า web server รันคนละ user กับเจ้าของไฟล์ (เช่น Apache = daemon/www-data)
//    จะเขียน .installed ไม่ได้ → ตัวติดตั้งขึ้นว่า "สำเร็จ" แต่ไม่ได้ล็อกตัวเอง
//    → ใครก็เปิด install.php ซ้ำแล้วสร้างบัญชี admin ของตัวเองได้
//    ชั้นที่ 2 จึงเก็บสถานะไว้ในตาราง settings ซึ่งเขียนได้แน่นอน (ตัวติดตั้งเพิ่งเขียน DB มา)
$lockFile = __DIR__ . '/.installed';

/**
 * 🎯 ตรวจจาก DB ว่าเคยติดตั้งไปแล้วหรือยัง
 * 🧠 ครั้งแรกสุด database/ตารางยังไม่มี → ต่อไม่ได้ ถือว่ายังไม่ติดตั้ง
 */
function isInstalledInDatabase(): bool
{
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'installed_at' LIMIT 1");
        return $stmt !== false && $stmt->fetchColumn() !== false;
    } catch (\Throwable $e) {
        // 📝 ยังไม่มี database/ตาราง settings → ยังไม่เคยติดตั้ง
        return false;
    }
}

$isInstalled = file_exists($lockFile) || isInstalledInDatabase();

// ถ้าติดตั้งแล้ว แสดงข้อความเตือน
if ($isInstalled) {
?>
    <!DOCTYPE html>
    <html lang="th">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ติดตั้งแล้ว - <?= APP_NAME ?></title>
        <?php // 🔌 [OFFLINE] ใช้ path สัมพัทธ์ ไม่ใช่ APP_URL
        //    ตอนติดตั้งลูกค้าอาจยังไม่ได้ตั้ง APP_URL ให้ถูก แต่ install.php อยู่ราก
        //    โปรเจกต์เสมอ path สัมพัทธ์จึงชี้ถูกแน่นอนไม่ว่าจะวางไว้ที่ไหน
        ?>
        <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
        <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    </head>

    <body class="bg-light">
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="card shadow-sm border-warning">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0"><i class="bi bi-shield-lock me-2"></i>ระบบติดตั้งแล้ว</h5>
                        </div>
                        <div class="card-body text-center py-5">
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                            <h4 class="mt-3">ระบบได้รับการติดตั้งเรียบร้อยแล้ว</h4>
                            <p class="text-muted">เพื่อความปลอดภัย กรุณาลบไฟล์ <code>install.php</code></p>
                            <hr>
                            <a href="index.php" class="btn btn-primary me-2">
                                <i class="bi bi-house me-1"></i>หน้าแรก
                            </a>
                            <a href="admin/" class="btn btn-outline-primary">
                                <i class="bi bi-gear me-1"></i>เข้า Admin
                            </a>
                        </div>
                        <div class="card-footer bg-light">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                หากต้องการติดตั้งใหม่ ให้ลบไฟล์ <code>.installed</code> หรือเพิ่ม <code>?force=1</code>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>

    </html>
<?php
    exit;
}

$messages = [];
$success = false;

// ── POST: เริ่มติดตั้ง ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 🔌 เชื่อมต่อ MySQL โดยไม่ระบุ database (เพราะยังไม่มี)
        $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        // 🗄️ สร้าง database + เลือกใช้
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `" . DB_NAME . "`");
        $messages[] = "✅ สร้างฐานข้อมูล `" . DB_NAME . "` สำเร็จ";

        // 📝 สร้างตาราง users — เก็บข้อมูลสมาชิก/เจ้าหน้าที่
        //    email UNIQUE = ป้องกันซ้ำ, role ENUM = จำกัดค่าที่รับ
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `users` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `email` VARCHAR(100) NOT NULL UNIQUE,
                `password` VARCHAR(255) NOT NULL,
                `phone` VARCHAR(20) DEFAULT NULL,
                `role` ENUM('member', 'admin', 'staff') NOT NULL DEFAULT 'member',
                `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_email` (`email`),
                INDEX `idx_role` (`role`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "✅ สร้างตาราง `users` สำเร็จ";

        // 📝 สร้างตาราง categories — name UNIQUE ป้องกันหมวดหมู่ซ้ำ
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `categories` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL UNIQUE,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "✅ สร้างตาราง `categories` สำเร็จ";

        // 📝 สร้างตาราง books
        //    CHECK constraints: available >= 0 + quantity >= available (ป้องกัน stock ติดลบ)
        //    FK category_id ON DELETE SET NULL = ลบหมวดหมู่ได้โดยหนังสือไม่หาย
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `books` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(200) NOT NULL,
                `author` VARCHAR(100) NOT NULL,
                `isbn` VARCHAR(20) DEFAULT NULL,
                `search_tokens` TEXT DEFAULT NULL COMMENT 'trigram สำหรับ FULLTEXT (สร้างโดย buildSearchTokens())',
                `category_id` INT DEFAULT NULL,
                `description` TEXT DEFAULT NULL,
                `cover_image` VARCHAR(255) DEFAULT NULL,
                `quantity` INT NOT NULL DEFAULT 1,
                `price` DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'ราคาปก — ใช้ตั้งต้นค่าชดใช้ตอนแจ้งหาย',
                `available` INT NOT NULL DEFAULT 1,
                `is_visible` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'แสดงให้สาธารณะเห็น',
                `is_reference` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'หนังสืออ้างอิง — อ่านในห้องสมุดเท่านั้น ยืม/จองไม่ได้',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_available` (`available`),
                INDEX `idx_category` (`category_id`),
                UNIQUE INDEX `uq_isbn` (`isbn`),
                FULLTEXT KEY `ft_books_search` (`search_tokens`),
                FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `chk_books_available_non_negative` CHECK (`available` >= 0),
                CONSTRAINT `chk_books_quantity_gte_available` CHECK (`quantity` >= `available`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "✅ สร้างตาราง `books` สำเร็จ";

        // 📝 สร้างตาราง borrows — เก็บรายการยืม/คืน
        //    [I-04 FIX] FK ON DELETE RESTRICT = ห้ามลบ user/book ถ้ายังมี borrow อยู่ (ป้องกัน stock leak)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `borrows` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `book_id` INT NOT NULL,
                `borrow_date` DATE NOT NULL,
                `due_date` DATE NOT NULL,
                `renew_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ต่ออายุไปแล้วกี่ครั้ง',
                `return_date` DATE DEFAULT NULL,
                `lost_reported_at` DATETIME NULL DEFAULT NULL COMMENT 'เวลาที่แจ้งหาย/ชำรุด',
                `lost_reported_by` INT NULL DEFAULT NULL COMMENT 'ผู้แจ้ง',
                `lost_note` VARCHAR(255) NULL DEFAULT NULL COMMENT 'รายละเอียด/เหตุผล',
                `status` ENUM('borrowing', 'returned', 'lost', 'damaged') NOT NULL DEFAULT 'borrowing',
                `fine_amount` DECIMAL(10,2) DEFAULT 0,
                `fine_waived_at` DATETIME NULL DEFAULT NULL COMMENT 'เวลาที่ยกเว้นค่าปรับ (NULL = ยังไม่ยกเว้น)',
                `fine_waived_by` INT NULL DEFAULT NULL COMMENT 'ผู้ยกเว้น',
                `fine_waived_note` VARCHAR(255) NULL DEFAULT NULL COMMENT 'เหตุผลที่ยกเว้น',
                `notes` TEXT DEFAULT NULL,
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
                CONSTRAINT `fk_borrows_waived_by` FOREIGN KEY (`fine_waived_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `fk_borrows_lost_reported_by` FOREIGN KEY (`lost_reported_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "✅ สร้างตาราง `borrows` สำเร็จ";

        // 📝 สร้างตาราง rate_limits — นับ attempt ตาม key_name
        //    ใช้ DB แทน session เพื่อให้ rate limit คงอยู่แม้เปลี่ยน browser
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `rate_limits` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `key_name` VARCHAR(255) NOT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_key_name` (`key_name`),
                INDEX `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "✅ สร้างตาราง `rate_limits` สำเร็จ";

        // 📝 สร้างตาราง reservations — เก็บรายการจองหนังสือ
        //    borrow_id เชื่อมกับ borrows เมื่อจองถูก fulfill
        $pdo->exec("
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
                UNIQUE KEY `uq_reservation_active` (`user_id`, `book_id`, `active_slot`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
                FOREIGN KEY (`book_id`) REFERENCES `books`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
                FOREIGN KEY (`borrow_id`) REFERENCES `borrows`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "✅ สร้างตาราง `reservations` สำเร็จ";

        // 📝 สร้างตาราง payments — เก็บการชำระค่าปรับ
        //    UNIQUE(borrow_id) = จ่ายได้ครั้งเดียวต่อ borrow (ป้องกัน double pay)
        $pdo->exec("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "✅ สร้างตาราง `payments` สำเร็จ";

        // 📝 สร้างตาราง password_resets — เก็บ token สำหรับ reset password
        //    token UNIQUE, used = ใช้ได้ครั้งเดียว (one-time-use)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `password_resets` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(100) NOT NULL,
                `token` VARCHAR(64) NOT NULL UNIQUE,
                `expires_at` DATETIME NOT NULL,
                `used` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_email` (`email`),
                INDEX `idx_token` (`token`),
                INDEX `idx_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "✅ สร้างตาราง `password_resets` สำเร็จ";

        // 📝 สร้างตาราง settings — key-value store สำหรับการตั้งค่า (สีธีม, ชื่อห้องสมุด, ...)
        //    setting_key UNIQUE = upsert pattern (INSERT ON DUPLICATE UPDATE)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `setting_key` VARCHAR(50) NOT NULL UNIQUE,
                `setting_value` TEXT DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "✅ สร้างตาราง `settings` สำเร็จ";

        // 📝 สร้างตาราง closed_days — วันที่ห้องสมุดไม่เปิดทำการ
        //    ใช้หักออกจากการคิดค่าปรับ · เก็บเป็นช่วงวัน วันเดียว = start = end
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `closed_days` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `start_date` DATE NOT NULL,
                `end_date` DATE NOT NULL,
                `note` VARCHAR(255) NOT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_closed_range` (`start_date`, `end_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "✅ สร้างตาราง `closed_days` สำเร็จ";

        // ── สร้างบัญชี Admin เริ่มต้น ──
        //    ใช้ password จาก form หรือสร้าง random (ถ้าเว้นว่างหรือสั้นเกินไป)
        $adminEmail = trim($_POST['admin_email'] ?? 'admin@library.com');
        $adminPlainPassword = $_POST['admin_password'] ?? '';

        if (empty($adminPlainPassword) || strlen($adminPlainPassword) < MIN_PASSWORD_LENGTH) {
            // 🔐 สร้าง random password ที่ปลอดภัย (12 hex chars)
            $adminPlainPassword = bin2hex(random_bytes(6));
        }

        // 🔒 hash password ด้วย bcrypt (PASSWORD_DEFAULT)
        $adminPassword = password_hash($adminPlainPassword, PASSWORD_DEFAULT);

        // ตรวจว่ามี admin อยู่แล้วหรือยัง (ป้องกันติดตั้งซ้ำสร้าง admin ซ้ำ)
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$adminEmail]);

        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, role) VALUES (?, ?, ?, ?, 'admin')");
            $stmt->execute(['ผู้ดูแลระบบ', $adminEmail, $adminPassword, '0812345678']);
            $messages[] = "✅ สร้างบัญชี Admin สำเร็จ";
        } else {
            $messages[] = "ℹ️ บัญชี Admin มีอยู่แล้ว";
        }

        // 🏷️ เพิ่มหมวดหมู่ตัวอย่าง (INSERT IGNORE = ข้ามถ้ามีแล้ว)
        $categories = ['นิยาย', 'วิชาการ', 'การ์ตูน', 'จิตวิทยา', 'ธุรกิจ'];
        $stmt = $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
        foreach ($categories as $cat) {
            $stmt->execute([$cat]);
        }
        $messages[] = "✅ เพิ่มหมวดหมู่ตัวอย่าง " . count($categories) . " หมวด";

        // 📚 เพิ่มหนังสือตัวอย่าง (ตรวจซ้ำก่อน insert, quantity = available เริ่มต้น)
        $books = [
            ['เกมล่าสังหาร', 'ซูซาน คอลลินส์', 'นิยาย', 3],
            ['Atomic Habits', 'James Clear', 'จิตวิทยา', 5],
            ['พ่อรวยสอนลูก', 'Robert Kiyosaki', 'ธุรกิจ', 2],
            ['วัยรุ่นพันล้าน', 'ท็อป จิรายุส', 'ธุรกิจ', 4],
            ['ฟิสิกส์มหัศจรรย์', 'รศ.ดร.เจษฎา', 'วิชาการ', 1],
        ];

        foreach ($books as $book) {
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
            $stmt->execute([$book[2]]);
            $cat = $stmt->fetch();

            $stmt = $pdo->prepare("SELECT id FROM books WHERE title = ?");
            $stmt->execute([$book[0]]);
            if (!$stmt->fetch()) {
                $qty = $book[3] ?? 1;
                // 🔎 ต้องเติม search_tokens ด้วย ไม่งั้นหนังสือตัวอย่างจะค้นหาไม่เจอ
                $stmt = $pdo->prepare("INSERT INTO books (title, author, search_tokens, category_id, quantity, available) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $book[0],
                    $book[1],
                    buildSearchTokens($book[0] . ' ' . $book[1]),
                    $cat['id'] ?? null,
                    $qty,
                    $qty
                ]);
            }
        }
        $messages[] = "✅ เพิ่มหนังสือตัวอย่าง " . count($books) . " เล่ม";

        // 📝 บันทึกว่า migration ทุกไฟล์ "รันแล้ว" โดยไม่ต้องรันจริง (baseline)
        //    🧠 ตารางที่ install.php สร้างเป็นโครงสร้างล่าสุดอยู่แล้ว ถ้าไม่ทำ baseline
        //       ระบบที่ติดตั้งใหม่จะพยายามรัน migration เก่าซ้ำ (ไม่พังเพราะเขียนให้รันซ้ำได้
        //       แต่ทำให้ log สับสนว่าเพิ่งอัปเกรดมา)
        //    ดูรายละเอียดที่ database/migrate.php
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `migration` VARCHAR(191) NOT NULL UNIQUE COMMENT 'ชื่อไฟล์ migration',
                `applied_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $migrationFiles = glob(__DIR__ . '/database/migrations/*.php') ?: [];
        $markStmt = $pdo->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
        foreach ($migrationFiles as $file) {
            $markStmt->execute([basename($file)]);
        }
        $messages[] = "✅ ตั้งค่าเวอร์ชันฐานข้อมูล (" . count($migrationFiles) . " migration)";

        // 🔒 ล็อคชั้นที่ 1: เก็บใน DB — เขียนได้เสมอ ไม่ขึ้นกับสิทธิ์ไฟล์
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('installed_at', ?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([date('Y-m-d H:i:s')]);

        // 🔒 ล็อคชั้นที่ 2: ไฟล์ .installed (ตรวจได้เร็วโดยไม่ต้องต่อ DB)
        //    ⚠️ เขียนไม่ได้ถ้า web server รันคนละ user กับเจ้าของโฟลเดอร์ — ต้องเตือนให้รู้
        //       ไม่งั้นจะเข้าใจผิดว่าล็อคแล้วทั้งที่ยังไม่ได้ล็อค
        $lockWritten = @file_put_contents($lockFile, date('Y-m-d H:i:s') . "\nInstalled successfully.") !== false;
        if (!$lockWritten) {
            $messages[] = "⚠️ เขียนไฟล์ .installed ไม่ได้ (web server ไม่มีสิทธิ์เขียนโฟลเดอร์นี้)";
            $messages[] = "   ระบบล็อคด้วยข้อมูลใน database แทนแล้ว — แต่ควรลบ install.php ทิ้งเพื่อความปลอดภัย";
        }

        // 📁 [F-54] ตรวจว่า web server เขียนโฟลเดอร์รูปปกได้จริงไหม
        //    🔴 **ต้องไม่ทำให้การติดตั้งล้มเหลว** — ฐานข้อมูลกับบัญชี admin เสร็จแล้ว
        //       ระบบใช้งานได้เกือบทุกอย่าง ติดแค่อัปโหลดรูปปก
        //       ล้มการติดตั้งเพราะเรื่องนี้จะแย่กว่าปล่อยผ่านมาก — เตือนแบบเดียวกับ .installed
        //
        //    🧠 ไม่ตรวจ logs/ เพราะ web server ไม่ได้เขียน — มีแต่ cron/*.php ที่เขียน
        //       ซึ่งรันเป็น user คนละคน (ดู docs/INSTALL.md:269)
        //       ตรวจไปก็เป็นการเตือนเท็จ ทำให้ลูกค้าไปตั้งสิทธิ์ที่ไม่จำเป็น
        $coversDir = __DIR__ . '/uploads/covers';
        if (!is_dir($coversDir)) {
            @mkdir($coversDir, 0755, true);
        }
        if (!isDirActuallyWritable($coversDir)) {
            $messages[] = "";
            $messages[] = "⚠️ โฟลเดอร์ `uploads/covers/` เขียนไม่ได้ — อัปโหลดรูปปกหนังสือจะไม่สำเร็จ";
            $messages[] = "   ส่วนอื่นของระบบใช้งานได้ตามปกติ แก้ได้ทีหลังด้วยคำสั่งนี้:";
            $messages[] = "   " . writablePermissionHint('uploads/covers');
        }

        $success = true;
        $messages[] = "";
        $messages[] = "🎉 ติดตั้งระบบเรียบร้อยแล้ว!";
        $messages[] = "📧 Email: " . $adminEmail;
        $messages[] = "🔑 Password: " . $adminPlainPassword;
        $messages[] = "";
        $messages[] = "⚠️ กรุณาลบไฟล์ install.php เพื่อความปลอดภัย";
    } catch (PDOException $e) {
        $messages[] = "❌ เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ติดตั้งระบบ - <?= APP_NAME ?></title>
    <?php // 🔌 [OFFLINE] path สัมพัทธ์ — ดูเหตุผลที่หน้า "ติดตั้งแล้ว" ด้านบน ?>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .install-card {
            max-width: 600px;
            width: 100%;
            margin: 20px;
        }

        .message-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            font-family: monospace;
            white-space: pre-line;
        }
    </style>
</head>

<body>
    <div class="card install-card shadow-lg">
        <div class="card-header bg-primary text-white text-center py-4">
            <h3><i class="bi bi-database-gear me-2"></i>ติดตั้งระบบ</h3>
            <p class="mb-0"><?= APP_NAME ?></p>
        </div>
        <div class="card-body p-4">
            <?php if (empty($messages)): ?>
                <div class="text-center">
                    <i class="bi bi-exclamation-triangle text-warning" style="font-size: 4rem;"></i>
                    <h4 class="mt-3">พร้อมติดตั้งระบบ</h4>
                    <p class="text-muted">การติดตั้งจะสร้างฐานข้อมูลและตารางที่จำเป็น</p>

                    <div class="alert alert-info text-start">
                        <strong>ตั้งค่าการเชื่อมต่อ:</strong><br>
                        Host: <?= DB_HOST ?><br>
                        Database: <?= DB_NAME ?><br>
                        User: <?= DB_USER ?>
                    </div>

                    <form method="POST">
                        <div class="text-start mb-3">
                            <label class="form-label fw-bold">อีเมล Admin</label>
                            <input type="email" name="admin_email" class="form-control" value="admin@library.com" required>
                        </div>
                        <div class="text-start mb-4">
                            <label class="form-label fw-bold">รหัสผ่าน Admin (ขั้นต่ำ <?= MIN_PASSWORD_LENGTH ?> ตัวอักษร)</label>
                            <input type="text" name="admin_password" class="form-control" placeholder="เว้นว่าง = สร้าง random password" minlength="<?= MIN_PASSWORD_LENGTH ?>">
                            <div class="form-text">ถ้าเว้นว่าง ระบบจะสร้างรหัสผ่านให้อัตโนมัติ</div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="bi bi-play-circle me-2"></i>เริ่มติดตั้ง
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="message-box">
                    <?php foreach ($messages as $msg): ?>
                        <?= htmlspecialchars($msg) . "\n" ?>
                    <?php endforeach; ?>
                </div>

                <?php if ($success): ?>
                    <div class="text-center mt-4">
                        <a href="index.php" class="btn btn-success btn-lg me-2">
                            <i class="bi bi-house me-2"></i>หน้าแรก
                        </a>
                        <a href="admin/index.php" class="btn btn-primary btn-lg">
                            <i class="bi bi-gear me-2"></i>เข้าหน้า Admin
                        </a>
                    </div>

                    <div class="alert alert-warning mt-4">
                        <i class="bi bi-shield-exclamation me-2"></i>
                        <strong>คำแนะนำ:</strong> ควรลบไฟล์ install.php หลังติดตั้งเสร็จ
                    </div>
                <?php else: ?>
                    <div class="text-center mt-4">
                        <button onclick="location.reload()" class="btn btn-secondary">
                            <i class="bi bi-arrow-clockwise me-2"></i>ลองใหม่
                        </button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
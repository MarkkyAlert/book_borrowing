<?php
/**
 * Database Installation Script
 * เข้าถึง: {APP_URL}/install.php
 * 
 * ⚠️ ควรลบไฟล์นี้หลังติดตั้งเสร็จ
 */

require_once __DIR__ . '/includes/config.php';

// =====================================================
// 🔒 INSTALL LOCK - ป้องกันการติดตั้งซ้ำ
// =====================================================
$lockFile = __DIR__ . '/.installed';
$isInstalled = file_exists($lockFile);

// ถ้าติดตั้งแล้ว แสดงข้อความเตือน
if ($isInstalled) {
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ติดตั้งแล้ว - <?= APP_NAME ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
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

// Process installation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Connect without database
        $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        // Create database
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `" . DB_NAME . "`");
        $messages[] = "✅ สร้างฐานข้อมูล `" . DB_NAME . "` สำเร็จ";

        // Create users table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `users` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `email` VARCHAR(100) NOT NULL UNIQUE,
                `password` VARCHAR(255) NOT NULL,
                `phone` VARCHAR(20) DEFAULT NULL,
                `role` ENUM('member', 'admin', 'staff') NOT NULL DEFAULT 'member',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_email` (`email`),
                INDEX `idx_role` (`role`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "✅ สร้างตาราง `users` สำเร็จ";

        // Create categories table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `categories` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL UNIQUE,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "✅ สร้างตาราง `categories` สำเร็จ";

        // Create books table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `books` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(200) NOT NULL,
                `author` VARCHAR(100) NOT NULL,
                `isbn` VARCHAR(20) DEFAULT NULL,
                `category_id` INT DEFAULT NULL,
                `description` TEXT DEFAULT NULL,
                `cover_image` VARCHAR(255) DEFAULT NULL,
                `quantity` INT NOT NULL DEFAULT 1,
                `available` INT NOT NULL DEFAULT 1,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_available` (`available`),
                INDEX `idx_category` (`category_id`),
                FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `chk_books_available_non_negative` CHECK (`available` >= 0),
                CONSTRAINT `chk_books_quantity_gte_available` CHECK (`quantity` >= `available`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "✅ สร้างตาราง `books` สำเร็จ";

        // Create borrows table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `borrows` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `book_id` INT NOT NULL,
                `borrow_date` DATE NOT NULL,
                `due_date` DATE NOT NULL,
                `return_date` DATE DEFAULT NULL,
                `status` ENUM('borrowing', 'returned') NOT NULL DEFAULT 'borrowing',
                `fine_amount` DECIMAL(10,2) DEFAULT 0,
                `notes` TEXT DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_status` (`status`),
                INDEX `idx_user` (`user_id`),
                INDEX `idx_book` (`book_id`),
                INDEX `idx_due_date` (`due_date`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY (`book_id`) REFERENCES `books`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "✅ สร้างตาราง `borrows` สำเร็จ";

        // Create rate_limits table (for DB-based rate limiting)
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

        // Create reservations table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `reservations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL COMMENT 'ผู้จอง',
                `book_id` INT NOT NULL COMMENT 'หนังสือที่จอง',
                `borrow_id` INT DEFAULT NULL COMMENT 'รายการยืมที่สร้างจากการจอง (เฉพาะ fulfilled)',
                `status` ENUM('pending', 'fulfilled', 'expired', 'cancelled') NOT NULL DEFAULT 'pending',
                `expires_at` DATETIME NOT NULL COMMENT 'วันหมดอายุการจอง',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_status` (`status`),
                INDEX `idx_user` (`user_id`),
                INDEX `idx_book` (`book_id`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY (`book_id`) REFERENCES `books`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY (`borrow_id`) REFERENCES `borrows`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "✅ สร้างตาราง `reservations` สำเร็จ";

        // Create payments table
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

        // Create password_resets table
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

        // Create settings table
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

        // Insert default admin (ใช้ password จาก form หรือสร้าง random)
        $adminEmail = trim($_POST['admin_email'] ?? 'admin@library.com');
        $adminPlainPassword = $_POST['admin_password'] ?? '';
        
        if (empty($adminPlainPassword) || strlen($adminPlainPassword) < MIN_PASSWORD_LENGTH) {
            // สร้าง random password ที่ปลอดภัย
            $adminPlainPassword = bin2hex(random_bytes(6)); // 12 chars
        }
        
        $adminPassword = password_hash($adminPlainPassword, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$adminEmail]);
        
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, role) VALUES (?, ?, ?, ?, 'admin')");
            $stmt->execute(['ผู้ดูแลระบบ', $adminEmail, $adminPassword, '0812345678']);
            $messages[] = "✅ สร้างบัญชี Admin สำเร็จ";
        } else {
            $messages[] = "ℹ️ บัญชี Admin มีอยู่แล้ว";
        }

        // Insert sample categories
        $categories = ['นิยาย', 'วิชาการ', 'การ์ตูน', 'จิตวิทยา', 'ธุรกิจ'];
        $stmt = $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
        foreach ($categories as $cat) {
            $stmt->execute([$cat]);
        }
        $messages[] = "✅ เพิ่มหมวดหมู่ตัวอย่าง " . count($categories) . " หมวด";

        // Insert sample books (with quantity)
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
                $stmt = $pdo->prepare("INSERT INTO books (title, author, category_id, quantity, available) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$book[0], $book[1], $cat['id'] ?? null, $qty, $qty]);
            }
        }
        $messages[] = "✅ เพิ่มหนังสือตัวอย่าง " . count($books) . " เล่ม";

        // สร้าง lock file เพื่อป้องกันการติดตั้งซ้ำ
        file_put_contents($lockFile, date('Y-m-d H:i:s') . "\nInstalled successfully.");
        
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
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

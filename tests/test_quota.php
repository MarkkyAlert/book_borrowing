<?php
require_once __DIR__ . '/../bootstrap.php';

use App\Services\BorrowService;

echo "🧪 กำลังทดสอบ Logic การจำกัดจำนวนยืม (Quota Limit testing)...\n";

$pdo = getDB();
$service = new BorrowService($pdo);

// 1. สร้าง User จำลองสำหรับทดสอบ (Temporary Test User)
$email = 'test_quota_' . time() . '@example.com';
$pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'member')")
    ->execute(['Test User', $email, password_hash('password', PASSWORD_DEFAULT)]);
$userId = $pdo->lastInsertId();

echo "✅ Created User ID: $userId\n";

try {
    // 2. เคลียร์ข้อมูลการยืมเก่า (ถ้ามี)
    // (User ใหม่ไม่ควรมีอยู่แล้ว แต่กันพลาด)
    
    // 3. ลองยืมหนังสือจนเต็มโควต้า (3 เล่ม)
    $bookIds = [1, 2, 3]; // สมมติว่ามีหนังสือ ID 1, 2, 3 อยู่ในระบบ
    
    echo "📚 กำลังพยายามยืม " . count($bookIds) . " เล่ม...\n";
    try {
        $result = $service->createBorrow($userId, $bookIds);
        echo "   Result: " . ($result['success'] ? 'Success' : 'Failed') . "\n";
    } catch (Exception $e) {
        echo "   Error: " . $e->getMessage() . "\n";
    }

    // 4. ลองยืมเพิ่มอีก 1 เล่ม (ควรจะต้องยืมไม่ได้)
    echo "🚫 กำลังพยายามยืมเล่มที่ 4 (ต้องล้มเหลว)...\n";
    try {
        $result = $service->createBorrow($userId, [4]);
        echo "   ❌ ล้มเหลว: ระบบยอมให้ยืมเกินโควต้า! (มีบั๊ก)\n";
    } catch (Exception $e) {
        echo "   ✅ ผ่าน: ระบบบล็อคสำเร็จ - \"" . $e->getMessage() . "\"\n";
    }

} catch (Exception $e) {
    echo "❌ Unexpected Error: " . $e->getMessage() . "\n";
} finally {
    // Cleanup
    $pdo->prepare("DELETE FROM borrows WHERE user_id = ?")->execute([$userId]);
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
    echo "🧹 Cleanup complete.\n";
}

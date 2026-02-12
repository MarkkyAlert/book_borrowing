<?php
require_once __DIR__ . '/../bootstrap.php';

use App\Services\BorrowService;

echo "🧪 กำลังทดสอบ Logic การจำกัดจำนวนยืม (Quota Limit testing)...\n";

$pdo = getDB();
$service = new BorrowService($pdo);

// 1. สร้าง User จำลอง
$email = 'test_quota_' . time() . '@example.com';
$pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'member')")
    ->execute(['Test Quota User', $email, password_hash('password', PASSWORD_DEFAULT)]);
$userId = (int) $pdo->lastInsertId();
echo "✅ Created User ID: $userId\n";

// 2. สร้างหนังสือจำลอง 4 เล่ม (ให้มี stock แน่นอน)
$bookIds = [];
for ($i = 1; $i <= 4; $i++) {
    $pdo->prepare("INSERT INTO books (title, author, quantity, available) VALUES (?, ?, 5, 5)")
        ->execute(["QuotaTest Book $i", "Author $i"]);
    $bookIds[] = (int) $pdo->lastInsertId();
}
echo "✅ Created Books: " . implode(', ', $bookIds) . "\n";

try {
    // 3. ยืม 3 เล่มแรก (เต็มโควต้า)
    $first3 = array_slice($bookIds, 0, 3);
    echo "📚 กำลังพยายามยืม 3 เล่ม (ID: " . implode(',', $first3) . ")...\n";
    try {
        $result = $service->createBorrow($userId, $first3);
        $borrowedCount = count($result['borrowed'] ?? []);
        echo "   Result: borrowed=$borrowedCount\n";
        if ($borrowedCount !== 3) {
            echo "   ⚠️ คาดว่ายืมได้ 3 เล่ม แต่ได้ $borrowedCount\n";
        }
    } catch (Exception $e) {
        echo "   ❌ Error: " . $e->getMessage() . "\n";
    }

    // 4. ลองยืมเล่มที่ 4 (ต้องล้มเหลว — เกินโควต้า)
    echo "🚫 กำลังพยายามยืมเล่มที่ 4 (ต้องล้มเหลว)...\n";
    try {
        $result = $service->createBorrow($userId, [$bookIds[3]]);
        echo "   ❌ ล้มเหลว: ระบบยอมให้ยืมเกินโควต้า! (มีบั๊ก)\n";
    } catch (Exception $e) {
        echo "   ✅ ผ่าน: ระบบบล็อคสำเร็จ - \"" . $e->getMessage() . "\"\n";
    }

} catch (Exception $e) {
    echo "❌ Unexpected Error: " . $e->getMessage() . "\n";
} finally {
    // Cleanup: ลบ borrows → books → user
    $pdo->prepare("DELETE FROM borrows WHERE user_id = ?")->execute([$userId]);
    foreach ($bookIds as $bid) {
        $pdo->prepare("DELETE FROM books WHERE id = ?")->execute([$bid]);
    }
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
    echo "🧹 Cleanup complete.\n";
}

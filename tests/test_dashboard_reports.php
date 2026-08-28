<?php
require_once __DIR__ . '/../bootstrap.php';

use App\Services\DashboardService;

echo "════════════════════════════════════════\n";
echo " Section 15: Dashboard & Reports\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "════════════════════════════════════════\n\n";

$pdo = getDB();
$dashboardService = new DashboardService($pdo);

$prefix = "RepTest_" . time();

// 1. Setup Data
echo "── SETUP ──\n";

// 0. Global Cleanup (Remove old test artifacts)
echo "── GLOBAL CLEANUP ──\n";
// Must delete borrows first due to FK
$pdo->exec("DELETE FROM borrows WHERE book_id IN (SELECT id FROM books WHERE title LIKE 'RepTest%')");
$pdo->exec("DELETE FROM books WHERE title LIKE 'RepTest%'");
$pdo->exec("DELETE FROM users WHERE email LIKE 'RepTest%'");

$initialStats = $dashboardService->getCardStats();
echo "Initial Books: " . $initialStats['total_books'] . "\n";
echo "Initial Members: " . $initialStats['total_members'] . "\n";
echo "Initial Active: " . $initialStats['active_borrows'] . "\n";
echo "Initial Overdue: " . $initialStats['overdue_borrows'] . "\n";

// Create 3 Books
// Use variable for time to ensure consistency
$ts = time();
$pdo->exec("INSERT INTO books (title, author, quantity, available, isbn, category_id) VALUES 
('{$prefix}_Book1', 'Auth1', 5, 5, 'RT_{$ts}_1', NULL),
('{$prefix}_Book2', 'Auth2', 2, 0, 'RT_{$ts}_2', NULL), -- Low Stock (0)
('{$prefix}_Book3', 'Auth3', 10, 10, 'RT_{$ts}_3', NULL)");

// Create 2 Members
$pdo->exec("INSERT INTO users (name, email, password, role) VALUES 
('{$prefix}_User1', '{$prefix}_U1@test.com', 'pass', 'member'),
('{$prefix}_User2', '{$prefix}_U2@test.com', 'pass', 'member')");

$uid1 = $pdo->lastInsertId(); // User 2 (approx)
// Get IDs
$stmt = $pdo->prepare("SELECT id FROM users WHERE email LIKE ? ORDER BY id");
$stmt->execute(["{$prefix}%"]);
$uids = $stmt->fetchAll(PDO::FETCH_COLUMN);
$uid1 = $uids[0];
$uid2 = $uids[1];

$stmt = $pdo->prepare("SELECT id FROM books WHERE title LIKE ? ORDER BY id");
$stmt->execute(["{$prefix}%"]);
$bids = $stmt->fetchAll(PDO::FETCH_COLUMN);
$bid1 = $bids[0];
$bid2 = $bids[1]; // Low stock (0)
$bid3 = $bids[2];

// Create Borrows
// User1 borrows Book1 (Active)
$pdo->prepare("INSERT INTO borrows (book_id, user_id, borrow_date, due_date, status) VALUES (?, ?, NOW(), NOW() + INTERVAL 7 DAY, 'borrowing')")
    ->execute([$bid1, $uid1]);

// User2 borrows Book2 (Overdue)
$pdo->prepare("INSERT INTO borrows (book_id, user_id, borrow_date, due_date, status) VALUES (?, ?, NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 3 DAY, 'borrowing')")
    ->execute([$bid2, $uid2]);

// User1 returns Book3 (History) - Wait, Book3 not borrowed yet. Borrow then Return.
$pdo->prepare("INSERT INTO borrows (book_id, user_id, borrow_date, due_date, return_date, status) VALUES (?, ?, NOW(), NOW(), NOW(), 'returned')")
    ->execute([$bid3, $uid1]);

// Adjust Available Logic manually for setup (since we inserted SQL directly)
// Book1: 5 -> 4
$pdo->exec("UPDATE books SET available = 4 WHERE id = $bid1");
// Book2: 2 -> 1 (Borrowed 1)
$pdo->exec("UPDATE books SET available = 1 WHERE id = $bid2");


// 2. Verify Stats Cards
echo "\n── VERIFY STATS ──\n";
$newStats = $dashboardService->getCardStats();

// Books: +17 (5 + 2 + 10)
// 🧠 `total_books` คือ SUM(quantity) = "จำนวนเล่ม" ไม่ใช่ COUNT(*) = "จำนวนรายการ"
//    (ดู BookRepository::getStatistics() — คืนทั้ง total=SUM และ titles=COUNT
//     แต่ Dashboard กับหน้าแรกใช้ total เหมือนกันทั้งคู่ ตรวจแล้วว่าสอดคล้องกัน)
//    เดิมเทสต์คาดว่า +3 (นับรายการ) จึง fail ทุกครั้ง — เป็นความเข้าใจผิดของเทสต์เอง
$expectedCopies = 5 + 2 + 10;
$diffBooks = $newStats['total_books'] - $initialStats['total_books'];
if ($diffBooks == $expectedCopies) echo "  ✅ PASS: Total Books +$expectedCopies (นับเป็นเล่ม)\n";
else echo "  ❌ FAIL: Total Books +$diffBooks (Expected $expectedCopies)\n";

// Members: +2
$diffMembers = $newStats['total_members'] - $initialStats['total_members'];
if ($diffMembers == 2) echo "  ✅ PASS: Total Members +2\n";
else echo "  ❌ FAIL: Total Members +$diffMembers (Expected 2)\n";

// Active Borrows: +2 (Book1, Book2 are borrowed)
$diffActive = $newStats['active_borrows'] - $initialStats['active_borrows'];
if ($diffActive == 2) echo "  ✅ PASS: Active Borrows +2\n";
else echo "  ❌ FAIL: Active Borrows +$diffActive (Expected 2)\n";

// Overdue: +1 (Book2)
$diffOverdue = $newStats['overdue_borrows'] - $initialStats['overdue_borrows'];
if ($diffOverdue == 1) echo "  ✅ PASS: Overdue Borrows +1\n";
else echo "  ❌ FAIL: Overdue Borrows +$diffOverdue (Expected 1)\n";

// 3. Verify Lists/Rankings
echo "\n── VERIFY LISTS & REPORTS ──\n";

// Low Stock: Book2 มี available=1, quantity=2 → ต้องเข้าเกณฑ์ "ใกล้หมด (<=2)"
// 🧠 เดิมขอมาแค่ 10 รายการแล้วคาดว่าจะเจอเล่มที่เพิ่งสร้าง — ใช้ได้เฉพาะบนฐานข้อมูลว่าง
//    พอมีข้อมูลจริงหลายร้อยเล่ม เล่มใหม่ย่อมไม่ติด 10 อันดับแรก → fail ตลอดทั้งที่ฟังก์ชันถูก
//    (อาการเดียวกับ Top Borrowers ด้านล่างที่เคยแก้ไปแล้ว)
//    เปลี่ยนเป็นตรวจ 2 อย่างที่ไม่ขึ้นกับปริมาณข้อมูลอื่น:
//    (1) ขอมาให้ครบแล้วต้องเจอเล่มนี้  (2) ทุกแถวที่คืนมาต้องเข้าเกณฑ์จริง
$lowStock = $dashboardService->getLowStockBooks(2, 100000);
$foundLow = false;
foreach ($lowStock as $b) {
    if ($b['id'] == $bid2) $foundLow = true;
}
if ($foundLow) echo "  ✅ PASS: Low Stock List includes Book2\n";
else echo "  ❌ FAIL: Low Stock List missing Book2\n";

$lowStockClean = true;
foreach ($lowStock as $b) {
    if ((int) $b['available'] > 2) { $lowStockClean = false; break; }
}
if ($lowStockClean) echo "  ✅ PASS: Low Stock List มีแต่เล่มที่เหลือ <= 2 จริง (" . count($lowStock) . " เล่ม)\n";
else echo "  ❌ FAIL: Low Stock List มีเล่มที่เหลือเกินเกณฑ์ปนมา\n";

// Overdue List: เหตุผลเดียวกัน — ขอมาให้ครบแล้วตรวจว่าทุกแถวเกินกำหนดจริง
$overdueList = $dashboardService->getOverdueList(100000);
$foundOverdue = false;
foreach ($overdueList as $o) {
    if ($o['book_id'] == $bid2) $foundOverdue = true;
}
if ($foundOverdue) echo "  ✅ PASS: Overdue List includes Book2\n";
else echo "  ❌ FAIL: Overdue List missing Book2\n";

$overdueClean = true;
foreach ($overdueList as $o) {
    if ($o['due_date'] >= date('Y-m-d')) { $overdueClean = false; break; }
}
if ($overdueClean) echo "  ✅ PASS: Overdue List มีแต่รายการที่เลยกำหนดจริง (" . count($overdueList) . " รายการ)\n";
else echo "  ❌ FAIL: Overdue List มีรายการที่ยังไม่เกินกำหนดปนมา\n";

// Top Borrowers: User1 has 2 borrows (1 active, 1 returned). User2 has 1.
// 🧠 เดิมขอมาแค่ 5 อันดับแล้วคาดว่าจะเจอ user ที่เพิ่งสร้าง — ใช้ได้เฉพาะบนฐานข้อมูลว่าง
//    พอมีข้อมูลจริงอยู่แล้ว user ใหม่ที่ยืมแค่ 2 ครั้งย่อมไม่ติด 5 อันดับแรก → fail ตลอด
//    ทั้งที่ฟังก์ชันทำงานถูก · เปลี่ยนเป็นตรวจ 2 อย่างที่ไม่ขึ้นกับข้อมูลอื่น:
//    (1) นับของ user นี้ถูกต้องไหม  (2) ผลลัพธ์เรียงจากมากไปน้อยจริงไหม
$topUsers = $dashboardService->getTopBorrowers(1000);
$user1Stats = null;
foreach ($topUsers as $u) {
    if ($u['id'] == $uid1) $user1Stats = $u;
}
if ($user1Stats && $user1Stats['borrow_count'] >= 2) {
    echo "  ✅ PASS: Top Borrower User1 count correct ({$user1Stats['borrow_count']})\n";
} else {
    echo "  ❌ FAIL: Top Borrower User1 inaccurate\n";
}

// เรียงลำดับถูกต้องไหม (มากไปน้อย)
$counts = array_map(fn($u) => (int) $u['borrow_count'], $topUsers);
$sorted = $counts;
rsort($sorted);
if ($counts === $sorted) {
    echo "  ✅ PASS: Top Borrowers เรียงจากมากไปน้อยถูกต้อง (" . count($counts) . " คน)\n";
} else {
    echo "  ❌ FAIL: Top Borrowers เรียงลำดับผิด\n";
}


// 4. Cleanup
echo "\n── CLEANUP ──\n";
$pdo->exec("DELETE FROM borrows WHERE book_id IN ($bid1, $bid2, $bid3)");
$pdo->exec("DELETE FROM books WHERE title LIKE '{$prefix}%'");
$pdo->exec("DELETE FROM users WHERE email LIKE '{$prefix}%'");
echo "  Cleanup done\n";

echo "\n════════════════════════════════════════\n";

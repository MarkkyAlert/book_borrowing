<?php
require_once __DIR__ . '/../bootstrap.php';

use App\Repositories\CategoryRepository;

echo "════════════════════════════════════════\n";
echo " Section 11: Category Management\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "════════════════════════════════════════\n\n";

$pdo = getDB();
$categoryRepo = new CategoryRepository($pdo);
$prefix = "TestCat_" . time();

// 1. Setup
echo "── SETUP ──\n";
$nameA = $prefix . "_A";
$nameB = $prefix . "_B";
$isbnTemp = "978-CAT-" . time();

// 2. Happy Path
echo "── HAPPY PATH ──\n";

// CT-01: Create Category
echo "CT-01: Create Category\n";
try {
    $idA = $categoryRepo->create($nameA);
    echo "  ✅ PASS: Created '$nameA' (ID: $idA)\n";
} catch (Exception $e) {
    echo "  ❌ FAIL: " . $e->getMessage() . "\n";
    exit(1);
}

// CT-02: Find All & Count
echo "CT-02: Find All & Count\n";
$stats = $categoryRepo->findAllWithBookCount();
$found = false;
foreach ($stats as $cat) {
    if ($cat['id'] == $idA) {
        $found = true;
        echo "  ✅ PASS: Found in list\n";
        echo ($cat['book_count'] == 0) ? "  ✅ PASS: Book count is 0\n" : "  ❌ FAIL: Book count is " . $cat['book_count'] . "\n";
    }
}
if (!$found) echo "  ❌ FAIL: Category not found in list\n";

// CT-03: Update Name
echo "CT-03: Update Name\n";
$newNameA = $nameA . "_Updated";
// Check nameExists logic first (Controller logic simulation)
if (!$categoryRepo->nameExists($newNameA, $idA)) {
    $categoryRepo->update($idA, $newNameA);
    $updated = $categoryRepo->findById($idA);
    echo ($updated['name'] === $newNameA) ? "  ✅ PASS: Renamed to '$newNameA'\n" : "  ❌ FAIL: Rename mismatch\n";
} else {
    echo "  ❌ FAIL: nameExists returned true for unused name\n";
}

// 3. Logic & Constraints
echo "\n── LOGIC & CONSTRAINTS ──\n";

// CT-04: Duplicate Name Check
echo "CT-04: Duplicate Name Check\n";
$categoryRepo->create($nameB); // Create second cat
if ($categoryRepo->nameExists($nameB)) {
    echo "  ✅ PASS: nameExists detected duplicate '$nameB'\n";
} else {
    echo "  ❌ FAIL: nameExists failed\n";
}

// Try Update Duplicate
if ($categoryRepo->nameExists($nameB, $idA)) {
    echo "  ✅ PASS: nameExists (update) detected duplicate\n";
} else {
    echo "  ❌ FAIL: nameExists (update) failed\n";
}

// Try Create Duplicate (DB Constraint)
try {
    $categoryRepo->create($nameB);
    echo "  ❌ FAIL: DB allowed duplicate name\n";
} catch (PDOException $e) {
    echo "  ✅ PASS: DB Unique Constraint blocked duplicate\n";
}

// CT-05: Delete with Books (DB behavior: ON DELETE SET NULL)
echo "CT-05: Delete with Books (Expect SET NULL)\n";
// Insert temp book
$pdo->prepare("INSERT INTO books (title, author, category_id, quantity, available, isbn, cover_image) VALUES (?, ?, ?, 1, 1, ?, '')")
    ->execute(["Test Book Cat", "Tester", $idA, $isbnTemp]);

// Check hasBooks
if ($categoryRepo->hasBooks($idA)) {
    echo "  ✅ PASS: hasBooks returned true\n";
} else {
    echo "  ❌ FAIL: hasBooks returned false\n";
}

// Try Delete - Application blocks via hasBooks(), but DB allows via SET NULL.
// We verify DB behavior.
if ($categoryRepo->delete($idA)) {
    echo "  ✅ PASS: DB allowed delete (ON DELETE SET NULL)\n";

    // Verify book is now uncategorized
    $stmt = $pdo->prepare("SELECT category_id FROM books WHERE isbn = ?");
    $stmt->execute([$isbnTemp]);
    $bookCat = $stmt->fetchColumn();

    if ($bookCat === null) {
        echo "  ✅ PASS: Book category set to NULL\n";
    } else {
        echo "  ❌ FAIL: Book category NOT set to NULL (Val: $bookCat)\n";
    }
} else {
    echo "  ❌ FAIL: Delete returned false\n";
}

// 4. Cleanup
echo "\n── CLEANUP ──\n";
// Delete books created
$pdo->exec("DELETE FROM books WHERE isbn = '$isbnTemp'");
$pdo->exec("DELETE FROM categories WHERE name LIKE '$prefix%'");
echo "  Cleanup done\n";

echo "\n════════════════════════════════════════\n";

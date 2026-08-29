<?php

/**
 * Logical Consistency Test Suite
 * 
 * ทดสอบความเป็นเหตุเป็นผลของระบบ
 * รันด้วย: php tests/logical_consistency_test.php
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Services\BorrowService;
use App\Services\ReservationService;
use App\Repositories\BookRepository;
use App\Repositories\BorrowRepository;
use App\Repositories\UserRepository;
use App\Repositories\ReservationRepository;
use App\Repositories\PaymentRepository;

class LogicalConsistencyTest
{
    private PDO $pdo;
    private BorrowService $borrowService;
    private ReservationService $reservationService;
    private BookRepository $bookRepo;
    private BorrowRepository $borrowRepo;
    private UserRepository $userRepo;
    private ReservationRepository $reservationRepo;
    private PaymentRepository $paymentRepo;

    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    // Test data IDs
    private int $testMemberA = 0;
    private int $testMemberB = 0;
    private int $testBookAlpha = 0;
    private int $testBookBeta = 0;
    private int $testBookGamma = 0;

    public function __construct()
    {
        $this->pdo = getDB();
        $this->borrowService = new BorrowService($this->pdo);
        $this->reservationService = new ReservationService($this->pdo);
        $this->bookRepo = new BookRepository($this->pdo);
        $this->borrowRepo = new BorrowRepository($this->pdo);
        $this->userRepo = new UserRepository($this->pdo);
        $this->reservationRepo = new ReservationRepository($this->pdo);
        $this->paymentRepo = new PaymentRepository($this->pdo);
    }

    public function run(): void
    {
        $this->printHeader("🧪 Logical Consistency Test Suite");

        $this->setupTestData();

        // Run test categories
        $this->printHeader("1️⃣ HAPPY PATH TESTS");
        $this->testHP01_BorrowSingleBook();
        $this->testHP02_BorrowMultipleBooks();
        $this->testHP03_ReturnBookNoFine();
        $this->testHP04_ReturnBookWithFine();
        $this->testHP05_CreateReservation();

        $this->printHeader("2️⃣ DUPLICATE/RETRY TESTS");
        $this->testDR01_DoubleBorrowSameBook();
        $this->testDR03_DoubleReturn();
        $this->testDR04_DoublePayment();
        $this->testDR05_DoubleReservation();

        $this->printHeader("3️⃣ INVALID SEQUENCE TESTS");
        $this->testIS01_ReturnAlreadyReturned();
        $this->testIS02_BorrowNoStock();
        $this->testIS03_BorrowSameBookAgain();
        $this->testIS04_BorrowExceedQuota();
        $this->testIS05_PayFineNoFine();

        $this->printHeader("4️⃣ CONCURRENCY SIMULATION");
        $this->testCC01_TwoBorrowLastBook();

        $this->printHeader("5️⃣ DATA INTEGRITY TESTS");
        $this->testDI01_AvailableNeverNegative();
        $this->testDI02_ReturnRestoresStock();

        $this->cleanupTestData();
        $this->printSummary();
    }

    // ==================== SETUP ====================

    private function setupTestData(): void
    {
        $this->printInfo("Setting up test data...");

        // Create test members
        $this->pdo->exec("INSERT INTO users (name, email, password, role) VALUES 
            ('Test Member A', 'test.member.a@test.com', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'member'),
            ('Test Member B', 'test.member.b@test.com', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'member')
        ");

        $this->testMemberA = (int) $this->pdo->query("SELECT id FROM users WHERE email='test.member.a@test.com'")->fetchColumn();
        $this->testMemberB = (int) $this->pdo->query("SELECT id FROM users WHERE email='test.member.b@test.com'")->fetchColumn();

        // Create test books
        $this->pdo->exec("INSERT INTO books (title, author, quantity, available) VALUES 
            ('Test Book Alpha', 'Test Author A', 3, 3),
            ('Test Book Beta', 'Test Author B', 1, 1),
            ('Test Book Gamma', 'Test Author C', 2, 0)
        ");

        $this->testBookAlpha = (int) $this->pdo->query("SELECT id FROM books WHERE title='Test Book Alpha'")->fetchColumn();
        $this->testBookBeta = (int) $this->pdo->query("SELECT id FROM books WHERE title='Test Book Beta'")->fetchColumn();
        $this->testBookGamma = (int) $this->pdo->query("SELECT id FROM books WHERE title='Test Book Gamma'")->fetchColumn();

        $this->printInfo("Test data created: Members({$this->testMemberA}, {$this->testMemberB}), Books({$this->testBookAlpha}, {$this->testBookBeta}, {$this->testBookGamma})");
    }

    private function cleanupTestData(): void
    {
        $this->printInfo("Cleaning up test data...");

        // Delete in correct order (FK constraints)
        $this->pdo->exec("DELETE FROM payments WHERE borrow_id IN (SELECT id FROM borrows WHERE user_id IN ({$this->testMemberA}, {$this->testMemberB}))");
        $this->pdo->exec("DELETE FROM reservations WHERE user_id IN ({$this->testMemberA}, {$this->testMemberB})");
        $this->pdo->exec("DELETE FROM borrows WHERE user_id IN ({$this->testMemberA}, {$this->testMemberB})");
        $this->pdo->exec("DELETE FROM books WHERE id IN ({$this->testBookAlpha}, {$this->testBookBeta}, {$this->testBookGamma})");
        $this->pdo->exec("DELETE FROM users WHERE id IN ({$this->testMemberA}, {$this->testMemberB})");

        $this->printInfo("Test data cleaned up");
    }

    // ==================== HAPPY PATH TESTS ====================

    private function testHP01_BorrowSingleBook(): void
    {
        $testName = "HP-01: ยืมหนังสือ 1 เล่มสำเร็จ";

        try {
            $beforeAvailable = $this->getBookAvailable($this->testBookAlpha);

            $result = $this->borrowService->createBorrow($this->testMemberA, [$this->testBookAlpha], 14);

            $afterAvailable = $this->getBookAvailable($this->testBookAlpha);
            $borrowExists = $this->borrowExists($this->testMemberA, $this->testBookAlpha);

            $this->assert(
                $testName,
                $result['success'] === true
                    && $afterAvailable === $beforeAvailable - 1
                    && $borrowExists,
                "success={$result['success']}, available: {$beforeAvailable}→{$afterAvailable}, borrowExists={$borrowExists}"
            );
        } catch (Exception $e) {
            $this->fail($testName, $e->getMessage());
        }
    }

    private function testHP02_BorrowMultipleBooks(): void
    {
        $testName = "HP-02: ยืมหนังสือหลายเล่มพร้อมกัน";

        try {
            // Reset: return previous borrow
            $this->returnAllBorrows($this->testMemberA);

            $beforeAlpha = $this->getBookAvailable($this->testBookAlpha);
            $beforeBeta = $this->getBookAvailable($this->testBookBeta);

            $result = $this->borrowService->createBorrow($this->testMemberA, [$this->testBookAlpha, $this->testBookBeta], 7);

            $afterAlpha = $this->getBookAvailable($this->testBookAlpha);
            $afterBeta = $this->getBookAvailable($this->testBookBeta);

            $this->assert(
                $testName,
                $result['success'] === true
                    && count($result['borrowed']) === 2
                    && $afterAlpha === $beforeAlpha - 1
                    && $afterBeta === $beforeBeta - 1,
                "borrowed=" . count($result['borrowed']) . ", Alpha: {$beforeAlpha}→{$afterAlpha}, Beta: {$beforeBeta}→{$afterBeta}"
            );
        } catch (Exception $e) {
            $this->fail($testName, $e->getMessage());
        }
    }

    private function testHP03_ReturnBookNoFine(): void
    {
        $testName = "HP-03: คืนหนังสือก่อนกำหนด (ไม่มีค่าปรับ)";

        try {
            // Get active borrow
            $borrow = $this->getActiveBorrow($this->testMemberA, $this->testBookAlpha);
            if (!$borrow) {
                $this->skip($testName, "No active borrow found");
                return;
            }

            $beforeAvailable = $this->getBookAvailable($this->testBookAlpha);

            $result = $this->borrowService->returnBook($borrow['id']);

            $afterAvailable = $this->getBookAvailable($this->testBookAlpha);
            $borrowStatus = $this->getBorrowStatus($borrow['id']);

            $this->assert(
                $testName,
                $result['success'] === true
                    && $result['fine']['amount'] == 0
                    && $afterAvailable === $beforeAvailable + 1
                    && $borrowStatus === 'returned',
                "fine={$result['fine']['amount']}, available: {$beforeAvailable}→{$afterAvailable}, status={$borrowStatus}"
            );
        } catch (Exception $e) {
            $this->fail($testName, $e->getMessage());
        }
    }

    private function testHP04_ReturnBookWithFine(): void
    {
        $testName = "HP-04: คืนหนังสือเกินกำหนด (มีค่าปรับ)";

        try {
            // Create borrow with past due date
            $this->pdo->exec("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status) VALUES 
                ({$this->testMemberA}, {$this->testBookAlpha}, DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'borrowing')");
            $borrowId = (int) $this->pdo->lastInsertId();
            $this->pdo->exec("UPDATE books SET available = available - 1 WHERE id = {$this->testBookAlpha}");

            $result = $this->borrowService->returnBook($borrowId, true, 1);

            $fineAmount = $result['fine']['amount'];
            $expectedFine = 3 * FINE_PER_DAY; // 3 days overdue

            $paymentExists = $this->paymentExists($borrowId);

            $this->assert(
                $testName,
                $result['success'] === true
                    && $fineAmount == $expectedFine
                    && $result['paid'] === true
                    && $paymentExists,
                "fine={$fineAmount} (expected {$expectedFine}), paid={$result['paid']}, paymentExists={$paymentExists}"
            );
        } catch (Exception $e) {
            $this->fail($testName, $e->getMessage());
        }
    }

    private function testHP05_CreateReservation(): void
    {
        $testName = "HP-05: จองหนังสือสำเร็จ";

        try {
            $beforeAvailable = $this->getBookAvailable($this->testBookAlpha);

            $result = $this->reservationService->createReservation($this->testMemberB, $this->testBookAlpha);

            $afterAvailable = $this->getBookAvailable($this->testBookAlpha);
            $reservationExists = $this->reservationExists($this->testMemberB, $this->testBookAlpha, 'pending');

            $this->assert(
                $testName,
                $result['success'] === true
                    && $afterAvailable === $beforeAvailable - 1
                    && $reservationExists,
                "available: {$beforeAvailable}→{$afterAvailable}, reservationExists={$reservationExists}"
            );
        } catch (Exception $e) {
            $this->fail($testName, $e->getMessage());
        }
    }

    // ==================== DUPLICATE/RETRY TESTS ====================

    private function testDR01_DoubleBorrowSameBook(): void
    {
        $testName = "DR-01: ยืมเล่มเดียวกันซ้ำ 2 ครั้ง";

        try {
            // Reset: return all borrows and restore Book Alpha stock
            $this->returnAllBorrows($this->testMemberA);
            $this->pdo->exec("UPDATE books SET available = 3 WHERE id = {$this->testBookAlpha}");

            // First borrow (use Alpha which has stock=3)
            $result1 = $this->borrowService->createBorrow($this->testMemberA, [$this->testBookAlpha], 7);

            // Second borrow (same book - should throw exception with atomic behavior)
            $secondFailed = false;
            try {
                $this->borrowService->createBorrow($this->testMemberA, [$this->testBookAlpha], 7);
            } catch (Exception $e) {
                $secondFailed = true;
            }

            $borrowCount = $this->countBorrows($this->testMemberA, $this->testBookAlpha);

            $this->assert(
                $testName,
                $result1['success'] === true
                    && $secondFailed === true
                    && $borrowCount === 1,
                "first={$result1['success']}, secondFailed={$secondFailed}, borrowCount={$borrowCount}"
            );
        } catch (Exception $e) {
            $this->fail($testName, $e->getMessage());
        }
    }

    private function testDR03_DoubleReturn(): void
    {
        $testName = "DR-03: คืนหนังสือซ้ำ 2 ครั้ง";

        try {
            // Setup: create a borrow
            $this->pdo->exec("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status) VALUES 
                ({$this->testMemberA}, {$this->testBookAlpha}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'borrowing')");
            $borrowId = (int) $this->pdo->lastInsertId();

            $beforeAvailable = $this->getBookAvailable($this->testBookAlpha);

            // First return
            $result1 = $this->borrowService->returnBook($borrowId);

            // Second return (should fail)
            $result2Success = false;
            $result2Error = '';
            try {
                $result2 = $this->borrowService->returnBook($borrowId);
                $result2Success = $result2['success'];
            } catch (Exception $e) {
                $result2Error = $e->getMessage();
            }

            $afterAvailable = $this->getBookAvailable($this->testBookAlpha);

            $this->assert(
                $testName,
                $result1['success'] === true
                    && $result2Success === false
                    && $afterAvailable === $beforeAvailable + 1, // เพิ่มแค่ 1 ไม่ใช่ 2
                "first={$result1['success']}, second={$result2Success}, available: {$beforeAvailable}→{$afterAvailable}, error: {$result2Error}"
            );
        } catch (Exception $e) {
            $this->fail($testName, $e->getMessage());
        }
    }

    private function testDR04_DoublePayment(): void
    {
        $testName = "DR-04: ชำระค่าปรับซ้ำ 2 ครั้ง";

        try {
            // Setup: create returned borrow with fine
            $this->pdo->exec("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, return_date, status, fine_amount) VALUES 
                ({$this->testMemberA}, {$this->testBookAlpha}, DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY), CURDATE(), 'returned', 50)");
            $borrowId = (int) $this->pdo->lastInsertId();

            // First payment
            $result1 = $this->borrowService->payFine($borrowId, 1);

            // Second payment (should fail)
            $result2Success = false;
            $result2Error = '';
            try {
                $result2 = $this->borrowService->payFine($borrowId, 1);
                $result2Success = $result2['success'];
            } catch (Exception $e) {
                $result2Error = $e->getMessage();
            }

            $paymentCount = (int) $this->pdo->query("SELECT COUNT(*) FROM payments WHERE borrow_id = {$borrowId}")->fetchColumn();

            $this->assert(
                $testName,
                $result1['success'] === true
                    && $result2Success === false
                    && $paymentCount === 1,
                "first={$result1['success']}, second={$result2Success}, paymentCount={$paymentCount}, error: {$result2Error}"
            );
        } catch (Exception $e) {
            $this->fail($testName, $e->getMessage());
        }
    }

    private function testDR05_DoubleReservation(): void
    {
        $testName = "DR-05: จองเล่มเดียวกันซ้ำ 2 ครั้ง";

        try {
            // Cancel existing reservation
            $this->pdo->exec("UPDATE reservations SET status = 'cancelled' WHERE user_id = {$this->testMemberA} AND book_id = {$this->testBookAlpha}");

            // Clear active borrows for this book (upstream isAlreadyBorrowing guard blocks reservation)
            $this->pdo->exec("UPDATE borrows SET status = 'returned' WHERE user_id = {$this->testMemberA} AND book_id = {$this->testBookAlpha} AND status = 'borrowing'");

            // First reservation
            $result1 = $this->reservationService->createReservation($this->testMemberA, $this->testBookAlpha);

            // Second reservation (should fail)
            $result2Success = false;
            $result2Error = '';
            try {
                $result2 = $this->reservationService->createReservation($this->testMemberA, $this->testBookAlpha);
                $result2Success = $result2['success'];
            } catch (Exception $e) {
                $result2Error = $e->getMessage();
            }

            $reservationCount = (int) $this->pdo->query("SELECT COUNT(*) FROM reservations WHERE user_id = {$this->testMemberA} AND book_id = {$this->testBookAlpha} AND status = 'pending'")->fetchColumn();

            $this->assert(
                $testName,
                $result1['success'] === true
                    && $result2Success === false
                    && $reservationCount === 1,
                "first={$result1['success']}, second={$result2Success}, pendingCount={$reservationCount}"
            );
        } catch (Exception $e) {
            $this->fail($testName, $e->getMessage());
        }
    }

    // ==================== INVALID SEQUENCE TESTS ====================

    private function testIS01_ReturnAlreadyReturned(): void
    {
        $testName = "IS-01: คืนหนังสือที่คืนไปแล้ว";

        try {
            // Get a returned borrow
            $borrowId = (int) $this->pdo->query("SELECT id FROM borrows WHERE status = 'returned' LIMIT 1")->fetchColumn();

            if (!$borrowId) {
                $this->skip($testName, "No returned borrow found");
                return;
            }

            $beforeAvailable = $this->getBookAvailable($this->testBookAlpha);

            $success = false;
            $error = '';
            try {
                $result = $this->borrowService->returnBook($borrowId);
                $success = $result['success'];
            } catch (Exception $e) {
                $error = $e->getMessage();
            }

            $afterAvailable = $this->getBookAvailable($this->testBookAlpha);

            $this->assert(
                $testName,
                $success === false
                    && $afterAvailable === $beforeAvailable,
                "success={$success}, available unchanged: {$beforeAvailable}={$afterAvailable}, error: {$error}"
            );
        } catch (Exception $e) {
            $this->fail($testName, $e->getMessage());
        }
    }

    private function testIS02_BorrowNoStock(): void
    {
        $testName = "IS-02: ยืมหนังสือที่ไม่มี stock";

        try {
            $beforeAvailable = $this->getBookAvailable($this->testBookGamma); // available = 0

            // Atomic behavior: should throw exception
            $failed = false;
            try {
                $this->borrowService->createBorrow($this->testMemberB, [$this->testBookGamma], 7);
            } catch (Exception $e) {
                $failed = true;
            }

            $afterAvailable = $this->getBookAvailable($this->testBookGamma);
            $borrowExists = $this->borrowExists($this->testMemberB, $this->testBookGamma);

            $this->assert(
                $testName,
                $failed === true
                    && $afterAvailable === 0
                    && !$borrowExists,
                "failed={$failed}, available={$afterAvailable}, borrowExists={$borrowExists}"
            );
        } catch (Exception $e) {
            $this->fail($testName, $e->getMessage());
        }
    }

    private function testIS03_BorrowSameBookAgain(): void
    {
        $testName = "IS-03: ยืมเล่มเดิมที่ยืมอยู่แล้ว";

        try {
            // Ensure member has active borrow for this book
            $activeBorrow = $this->getActiveBorrow($this->testMemberA, $this->testBookAlpha);
            if (!$activeBorrow) {
                $this->borrowService->createBorrow($this->testMemberA, [$this->testBookAlpha], 7);
            }

            $beforeCount = $this->countBorrows($this->testMemberA, $this->testBookAlpha);

            // Try to borrow again — atomic behavior: should throw exception
            $failed = false;
            try {
                $this->borrowService->createBorrow($this->testMemberA, [$this->testBookAlpha], 7);
            } catch (Exception $e) {
                $failed = true;
            }

            $afterCount = $this->countBorrows($this->testMemberA, $this->testBookAlpha);

            $this->assert(
                $testName,
                $failed === true
                    && $afterCount === $beforeCount,
                "failed={$failed}, borrowCount: {$beforeCount}→{$afterCount}"
            );
        } catch (Exception $e) {
            $this->fail($testName, $e->getMessage());
        }
    }

    private function testIS04_BorrowExceedQuota(): void
    {
        $testName = "IS-04: ยืมเกินโควต้า (MAX_BORROW_BOOKS)";

        try {
            // Return all borrows first
            $this->returnAllBorrows($this->testMemberB);

            // Create MAX_BORROW_BOOKS borrows
            for ($i = 0; $i < MAX_BORROW_BOOKS; $i++) {
                $this->pdo->exec("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status) VALUES 
                    ({$this->testMemberB}, {$this->testBookAlpha}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'borrowing')");
            }

            $beforeCount = $this->countActiveBorrows($this->testMemberB);

            // Try to borrow one more
            $success = false;
            $error = '';
            try {
                $result = $this->borrowService->createBorrow($this->testMemberB, [$this->testBookBeta], 7);
                $success = $result['success'];
            } catch (Exception $e) {
                $error = $e->getMessage();
            }

            $afterCount = $this->countActiveBorrows($this->testMemberB);

            // 🧠 ตรวจ "ความหมาย" ไม่ใช่ถ้อยคำเดิม —
            //    ถูกปฏิเสธจริง · จำนวนที่ยืมไม่เพิ่ม · ข้อความบอกเพดานเป็นตัวเลข
            //    [F-41] ข้อความเปลี่ยนจาก "ถึงจำนวนหนังสือที่ยืมได้สูงสุดแล้ว (3 เล่ม)"
            //    เป็น "เต็มโควตาแล้ว — ยืมอยู่ 2 เล่ม + จองรอรับอีก 1 เล่ม = 3 จาก 3 เล่ม"
            //    เพื่อให้เจ้าหน้าที่อธิบายให้สมาชิกได้ว่าโควตาถูกใช้ไปกับอะไร
            //    เดิมเช็คคำว่า "สูงสุด" ตรงตัว จึงแดงทั้งที่พฤติกรรมยังถูก
            $mentionsQuota = strpos($error, 'โควตา') !== false || strpos($error, 'สูงสุด') !== false;
            $mentionsLimit = strpos($error, (string) MAX_BORROW_BOOKS) !== false;

            $this->assert(
                $testName,
                $success === false
                    && $afterCount === MAX_BORROW_BOOKS
                    && $mentionsQuota
                    && $mentionsLimit,
                "success={$success}, count: {$beforeCount}→{$afterCount}, error: {$error}"
            );
        } catch (Exception $e) {
            $this->fail($testName, $e->getMessage());
        }
    }

    private function testIS05_PayFineNoFine(): void
    {
        $testName = "IS-05: ชำระค่าปรับที่ไม่มี (fine_amount = 0)";

        try {
            // Create borrow with no fine
            $this->pdo->exec("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, return_date, status, fine_amount) VALUES 
                ({$this->testMemberA}, {$this->testBookAlpha}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), CURDATE(), 'returned', 0)");
            $borrowId = (int) $this->pdo->lastInsertId();

            $success = false;
            $error = '';
            try {
                $result = $this->borrowService->payFine($borrowId, 1);
                $success = $result['success'];
            } catch (Exception $e) {
                $error = $e->getMessage();
            }

            $paymentExists = $this->paymentExists($borrowId);

            $this->assert(
                $testName,
                $success === false
                    && !$paymentExists
                    && strpos($error, 'ไม่มีค่าปรับ') !== false,
                "success={$success}, paymentExists={$paymentExists}, error: {$error}"
            );
        } catch (Exception $e) {
            $this->fail($testName, $e->getMessage());
        }
    }

    // ==================== CONCURRENCY TESTS ====================

    private function testCC01_TwoBorrowLastBook(): void
    {
        $testName = "CC-01: 2 คนยืมหนังสือเล่มสุดท้ายพร้อมกัน (Simulated)";

        try {
            // Reset book Beta to have 1 available
            $this->pdo->exec("UPDATE books SET available = 1 WHERE id = {$this->testBookBeta}");
            $this->returnAllBorrows($this->testMemberA);
            $this->returnAllBorrows($this->testMemberB);

            // Simulate concurrent borrows (sequential but testing the logic)
            $result1 = $this->borrowService->createBorrow($this->testMemberA, [$this->testBookBeta], 7);

            $result2Success = false;
            $result2Skipped = 0;
            try {
                $result2 = $this->borrowService->createBorrow($this->testMemberB, [$this->testBookBeta], 7);
                $result2Success = $result2['success'];
                $result2Skipped = count($result2['skipped']);
            } catch (Exception $e) {
                // Expected
            }

            $finalAvailable = $this->getBookAvailable($this->testBookBeta);
            $borrowCount = (int) $this->pdo->query("SELECT COUNT(*) FROM borrows WHERE book_id = {$this->testBookBeta} AND status = 'borrowing'")->fetchColumn();

            $this->assert(
                $testName,
                $result1['success'] === true
                    && ($result2Success === false || $result2Skipped > 0)
                    && $finalAvailable === 0
                    && $finalAvailable >= 0 // ไม่ติดลบ
                    && $borrowCount === 1,
                "first={$result1['success']}, second={$result2Success}, available={$finalAvailable}, borrowCount={$borrowCount}"
            );
        } catch (Exception $e) {
            $this->fail($testName, $e->getMessage());
        }
    }

    // ==================== DATA INTEGRITY TESTS ====================

    private function testDI01_AvailableNeverNegative(): void
    {
        $testName = "DI-01: books.available ไม่ติดลบ";

        try {
            $negativeCount = (int) $this->pdo->query("SELECT COUNT(*) FROM books WHERE available < 0")->fetchColumn();

            $this->assert(
                $testName,
                $negativeCount === 0,
                "negativeCount={$negativeCount}"
            );
        } catch (Exception $e) {
            $this->fail($testName, $e->getMessage());
        }
    }

    private function testDI02_ReturnRestoresStock(): void
    {
        $testName = "DI-02: คืนหนังสือแล้ว stock เพิ่มขึ้น";

        try {
            // Create and return a borrow
            $this->pdo->exec("UPDATE books SET available = 2 WHERE id = {$this->testBookAlpha}");
            $beforeAvailable = $this->getBookAvailable($this->testBookAlpha);

            $this->pdo->exec("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status) VALUES 
                ({$this->testMemberA}, {$this->testBookAlpha}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'borrowing')");
            $borrowId = (int) $this->pdo->lastInsertId();
            $this->pdo->exec("UPDATE books SET available = available - 1 WHERE id = {$this->testBookAlpha}");

            $duringBorrow = $this->getBookAvailable($this->testBookAlpha);

            $this->borrowService->returnBook($borrowId);

            $afterReturn = $this->getBookAvailable($this->testBookAlpha);

            $this->assert(
                $testName,
                $duringBorrow === $beforeAvailable - 1
                    && $afterReturn === $beforeAvailable,
                "before={$beforeAvailable}, during={$duringBorrow}, after={$afterReturn}"
            );
        } catch (Exception $e) {
            $this->fail($testName, $e->getMessage());
        }
    }

    // ==================== HELPER METHODS ====================

    private function getBookAvailable(int $bookId): int
    {
        return (int) $this->pdo->query("SELECT available FROM books WHERE id = {$bookId}")->fetchColumn();
    }

    private function borrowExists(int $userId, int $bookId): bool
    {
        return (bool) $this->pdo->query("SELECT id FROM borrows WHERE user_id = {$userId} AND book_id = {$bookId} AND status = 'borrowing'")->fetchColumn();
    }

    private function getActiveBorrow(int $userId, int $bookId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM borrows WHERE user_id = ? AND book_id = ? AND status = 'borrowing' LIMIT 1");
        $stmt->execute([$userId, $bookId]);
        return $stmt->fetch() ?: null;
    }

    private function getBorrowStatus(int $borrowId): string
    {
        return $this->pdo->query("SELECT status FROM borrows WHERE id = {$borrowId}")->fetchColumn() ?: '';
    }

    private function paymentExists(int $borrowId): bool
    {
        return (bool) $this->pdo->query("SELECT id FROM payments WHERE borrow_id = {$borrowId}")->fetchColumn();
    }

    private function reservationExists(int $userId, int $bookId, string $status): bool
    {
        return (bool) $this->pdo->query("SELECT id FROM reservations WHERE user_id = {$userId} AND book_id = {$bookId} AND status = '{$status}'")->fetchColumn();
    }

    private function countBorrows(int $userId, int $bookId): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM borrows WHERE user_id = {$userId} AND book_id = {$bookId} AND status = 'borrowing'")->fetchColumn();
    }

    private function countActiveBorrows(int $userId): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM borrows WHERE user_id = {$userId} AND status = 'borrowing'")->fetchColumn();
    }

    private function returnAllBorrows(int $userId): void
    {
        $this->pdo->exec("UPDATE borrows SET status = 'returned', return_date = CURDATE() WHERE user_id = {$userId} AND status = 'borrowing'");
    }

    // ==================== OUTPUT METHODS ====================

    private function assert(string $testName, bool $condition, string $details = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo "  ✅ PASS: {$testName}\n";
            if ($details) echo "     └─ {$details}\n";
        } else {
            $this->failed++;
            $this->failures[] = $testName;
            echo "  ❌ FAIL: {$testName}\n";
            if ($details) echo "     └─ {$details}\n";
        }
    }

    private function fail(string $testName, string $error): void
    {
        $this->failed++;
        $this->failures[] = $testName;
        echo "  ❌ FAIL: {$testName}\n";
        echo "     └─ Exception: {$error}\n";
    }

    private function skip(string $testName, string $reason): void
    {
        echo "  ⏭️ SKIP: {$testName}\n";
        echo "     └─ {$reason}\n";
    }

    private function printHeader(string $title): void
    {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo $title . "\n";
        echo str_repeat("=", 60) . "\n";
    }

    private function printInfo(string $message): void
    {
        echo "  ℹ️ {$message}\n";
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $this->printHeader("📊 TEST SUMMARY");

        echo "  Total:  {$total}\n";
        echo "  Passed: {$this->passed} ✅\n";
        echo "  Failed: {$this->failed} ❌\n";
        echo "\n";

        if ($this->failed > 0) {
            echo "  Failed Tests:\n";
            foreach ($this->failures as $failure) {
                echo "    - {$failure}\n";
            }
            echo "\n";
        }

        $status = $this->failed === 0 ? "🎉 ALL TESTS PASSED!" : "⚠️ SOME TESTS FAILED";
        echo "  {$status}\n";
        echo str_repeat("=", 60) . "\n";
    }
}

// Run tests
$test = new LogicalConsistencyTest();
$test->run();

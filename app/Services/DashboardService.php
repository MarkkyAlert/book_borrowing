<?php
/**
 * DashboardService - Business Logic สำหรับ Admin Dashboard
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Service นี้เป็น read-only aggregator — รวมสถิติจากหลาย Repository
 * ไม่มี write operation ใดๆ (ไม่ INSERT/UPDATE/DELETE)
 * ทุก method เป็น "ดึงข้อมูล" สำหรับแสดงผลบน dashboard
 *
 * 🏗️ สถาปัตยกรรม:
 * admin/index.php → DashboardService → BookRepository
 *                                      → BorrowRepository
 *                                      → UserRepository
 *                                      → CategoryRepository
 *                                      → ReservationRepository
 *                                      → PaymentRepository
 *                                      → ReportRepository
 *
 * 📍 Entrypoint:
 * - admin/index.php → ทุก method
 *
 * 🛡️ Security: read-only — ไม่มี side effect
 *
 * @package App\Services
 */

namespace App\Services;

require_once __DIR__ . '/../Repositories/BookRepository.php';
require_once __DIR__ . '/../Repositories/BorrowRepository.php';
require_once __DIR__ . '/../Repositories/UserRepository.php';
require_once __DIR__ . '/../Repositories/CategoryRepository.php';
require_once __DIR__ . '/../Repositories/ReservationRepository.php';
require_once __DIR__ . '/../Repositories/PaymentRepository.php';
require_once __DIR__ . '/../Repositories/ReportRepository.php';

use App\Repositories\BookRepository;
use App\Repositories\BorrowRepository;
use App\Repositories\UserRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\ReservationRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\ReportRepository;
use PDO;

class DashboardService
{
    // 🗄️ PDO + Repositories ทั้งหมด (read-only — ไม่มี write)
    private PDO $pdo;
    private BookRepository $bookRepo;
    private BorrowRepository $borrowRepo;
    private UserRepository $userRepo;
    private CategoryRepository $categoryRepo;
    private ReservationRepository $reservationRepo;
    private PaymentRepository $paymentRepo;
    private ReportRepository $reportRepo;
    
    // 🏗️ Constructor: สร้าง repo ทั้งหมด — ใช้ PDO เดียวกัน
    //    ไม่ต้องการ transaction เพราะเป็น read-only service
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->bookRepo = new BookRepository($pdo);
        $this->borrowRepo = new BorrowRepository($pdo);
        $this->userRepo = new UserRepository($pdo);
        $this->categoryRepo = new CategoryRepository($pdo);
        $this->reservationRepo = new ReservationRepository($pdo);
        $this->paymentRepo = new PaymentRepository($pdo);
        $this->reportRepo = new ReportRepository($pdo);
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สถิติ summary cards (book/borrow/member/reservation)
     * ==========================================================================
     *
     * 📤 Output: @return array {total_books, total_titles, available_books, borrowed_books,
     *          total_members, active_borrows, overdue_borrows, due_soon_borrows,
     *          pending_reservations}
     * ✅ Use case: admin/index.php → stat cards ด้านบน
     */
    /**
     * ==========================================================================
     * 🔔 จุดประสงค์: ตัวเลขสำหรับ "กระดิ่งแจ้งเตือน" บนหัวหน้าแอดมิน
     * ==========================================================================
     *
     * 🔴 [PERFORMANCE] เมธอดนี้ถูกเรียกจาก `admin/header.php` ซึ่ง **ทุกหน้าแอดมิน
     *    include** (16 หน้า) — จะช้าไม่ได้
     *    - `getCardStats()` ใช้ ~22 ms และดึงของที่กระดิ่งไม่ต้องใช้
     *      (จำนวนหนังสือ/สมาชิก/ยืมทั้งหมด) จึง **ห้ามเอามาใช้ซ้ำที่นี่**
     *    - ตัวนี้รวม 4 ตัวเลขไว้ใน **round-trip เดียว** วัดได้ ~10 ms
     *    - cache ระดับ request เพราะบางหน้าอาจเรียกซ้ำ
     *
     * 🧠 เลือกเฉพาะเรื่องที่ **ต้องลงมือทำ** ไม่ใช่ทุกตัวเลขที่มี
     *    "หนังสือทั้งหมด 1,187 เล่ม" ไม่ใช่การแจ้งเตือน มันคือสถิติ
     *    ถ้าใส่ของที่ไม่ต้องทำอะไรเข้าไป กระดิ่งจะแดงตลอดจนคนเลิกสนใจ
     *    ซึ่งเป็นปัญหาเดิมของกระดิ่งหลอกที่มีจุดแดงตายตัวอยู่ก่อนหน้านี้
     *
     * 📤 @return array{overdue:int, due_soon:int, pending_reservations:int, unpaid_people:int, total:int}
     */
    public function getAlertCounts(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        // 📝 เงื่อนไขแต่ละตัวต้องตรงกับหน้าที่กระดิ่งพาไป ไม่งั้นกดแล้วเจอคนละจำนวน
        //    overdue        → admin/borrows.php?filter=overdue
        //    due_soon       → reports.php?report=due_soon (admin) / borrows.php?filter=due_today (staff)
        //    pending        → admin/reservations.php
        //    unpaid_people  → admin/payments.php  (นับเป็น "คน" ให้ตรงกับหน้านั้นซึ่งแบ่งหน้าเป็นคน)
        $days = (int) DUE_SOON_DAYS;
        $stmt = $this->pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM borrows
                  WHERE status = 'borrowing' AND due_date < CURDATE())                      AS overdue,
                (SELECT COUNT(*) FROM borrows
                  WHERE status = 'borrowing' AND due_date >= CURDATE()
                    AND due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY))                    AS due_soon,
                (SELECT COUNT(*) FROM reservations WHERE status = 'pending')                AS pending_reservations,
                (SELECT COUNT(DISTINCT b.user_id) FROM borrows b
                   LEFT JOIN payments p ON p.borrow_id = b.id
                  WHERE b.fine_amount > 0 AND p.id IS NULL AND b.fine_waived_at IS NULL)    AS unpaid_people
        ");
        $stmt->bindValue(1, $days, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $counts = [
            'overdue'              => (int) ($row['overdue'] ?? 0),
            'due_soon'             => (int) ($row['due_soon'] ?? 0),
            'pending_reservations' => (int) ($row['pending_reservations'] ?? 0),
            'unpaid_people'        => (int) ($row['unpaid_people'] ?? 0),
        ];
        $counts['total'] = array_sum($counts);

        return $cache = $counts;
    }

    public function getCardStats(): array
    {
        // 📝 รวมสถิติจากหลาย repo เป็น 1 array
        //    แต่ละ key เป็น 1 stat card บน dashboard
        $bookStats = $this->bookRepo->getStatistics();
        return [
            'total_books' => $bookStats['total'],           // 📚 จำนวน **เล่ม** (SUM(quantity))
            // 🔴 [F-50] จำนวน **ชื่อเรื่อง** — Repository คำนวณไว้อยู่แล้วแต่ไม่เคยส่งต่อ
            //    ตอนทำสำมะโนหนังสือต้องใช้ทั้งสองตัว: 1,187 เล่ม จาก 406 ชื่อเรื่อง
            //    เดิมหน้าจอมีแต่ตัวเลขเล่ม ติดป้ายว่า "หนังสือทั้งหมด" ซึ่งอ่านได้สองแบบ
            'total_titles' => $bookStats['titles'],         // 📖 จำนวน **ชื่อเรื่อง** (COUNT(*))
            'available_books' => $bookStats['available'],    // หนังสือว่าง
            'borrowed_books' => $bookStats['borrowed'],      // หนังสือถูกยืม
            'total_members' => $this->userRepo->countMembers(),             // สมาชิกทั้งหมด
            'active_borrows' => $this->borrowRepo->countActive(),           // ยืมค้างอยู่
            'overdue_borrows' => $this->borrowRepo->countOverdue(),         // เกินกำหนด
            // 📞 ใกล้ครบกำหนด — ยังตามทันก่อนจะกลายเป็น overdue
            //    จำนวนวันมาจากกฎ DUE_SOON_DAYS ที่ผู้ดูแลตั้งได้ในหน้าตั้งค่า
            'due_soon_borrows' => $this->borrowRepo->countDueSoon(DUE_SOON_DAYS),
            'pending_reservations' => $this->reservationRepo->countPending() // จองรอรับ
        ];
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รายการยืมล่าสุด (pass-through)
     * ==========================================================================
     */
    public function getRecentBorrows(int $limit = 5): array
    {
        // 📝 Pass-through → borrows ล่าสุด
        return $this->borrowRepo->findRecent($limit);
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รายการจองล่าสุด (pass-through)
     * ==========================================================================
     */
    public function getRecentReservations(int $limit = 5): array
    {
        // 📝 Pass-through → pending reservations ล่าสุด
        return $this->reservationRepo->findPending($limit);
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รายการเกินกำหนดคืน (pass-through)
     * ==========================================================================
     */
    public function getOverdueList(int $limit = 10): array
    {
        // 📝 Pass-through → borrows ที่ due_date < today
        return $this->borrowRepo->findOverdue($limit);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รายการ "ใกล้ครบกำหนด" สำหรับโทรตามก่อนจะสาย
     * ==========================================================================
     * 🧠 คู่กับ getOverdueList() — ตัวนั้นตามหลัง ตัวนี้ตามก่อน
     *    ระบบไม่ส่งอีเมล การเตือนจึงเป็นรายชื่อให้บรรณารักษ์โทร/LINE เอง
     * 📝 ใช้กฎ DUE_SOON_DAYS เป็นค่าตั้งต้น ไม่รับจาก URL — กันไม่ให้ตัวเลขบนการ์ด
     *    ต่างจากในรายงานที่ใช้กฎเดียวกัน
     */
    public function getDueSoonList(int $limit = 10): array
    {
        return $this->borrowRepo->findDueSoon(DUE_SOON_DAYS, $limit);
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สถิติรายเดือน (สำหรับ Chart)
     * ==========================================================================
     */
    public function getMonthlyStats(int $months = 6): array
    {
        // 📝 Pass-through → สถิติรายเดือน (สำหรับ Chart.js)
        return $this->reportRepo->getMonthlyReport($months);
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สถิติหมวดหมู่ (สำหรับ Chart)
     * ==========================================================================
     */
    public function getCategoryStats(int $limit = 6): array
    {
        // 📝 Pass-through → หมวดหมู่ + จำนวนยืม (สำหรับ Chart.js)
        return $this->categoryRepo->getStatistics($limit);
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ยอดค่าปรับที่รับชำระแล้ว (pass-through)
     * ==========================================================================
     */
    public function getTotalFinesCollected(): float
    {
        // 📝 Pass-through → SUM(amount) จาก payments
        return $this->paymentRepo->getTotalCollected();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ยอดค่าปรับค้างชำระ (pass-through)
     * ==========================================================================
     */
    public function getUnpaidFines(): float
    {
        // 📝 Pass-through → SUM(fine_amount) ที่ยังไม่มี payment
        return $this->paymentRepo->getUnpaidTotal();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สมาชิกยืมมากที่สุด (pass-through)
     * ==========================================================================
     */
    public function getTopBorrowers(int $limit = 5): array
    {
        // 📝 Pass-through → สมาชิกยืมมากที่สุด
        return $this->reportRepo->getTopBorrowers($limit);
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: หนังสือยอดนิยม (pass-through)
     * ==========================================================================
     */
    public function getPopularBooks(int $limit = 5): array
    {
        // 📝 Pass-through → หนังสือยอดนิยม
        return $this->reportRepo->getPopularBooks($limit);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: หนังสือใกล้หมด stock (pass-through)
     * ==========================================================================
     *
     * 📥 Input: @param int $threshold, @param int $limit
     */
    public function getLowStockBooks(int $threshold = 2, int $limit = 5): array
    {
        // 📝 Pass-through → หนังสือที่ available <= threshold
        return $this->bookRepo->findLowStock($threshold, $limit);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รายการค้างชำระค่าปรับ (pass-through)
     * ==========================================================================
     */
    public function getUnpaidFinesList(int $limit = 10): array
    {
        // 📝 Pass-through → borrows ที่มี fine_amount > 0 แต่ยังไม่มี payment
        return $this->borrowRepo->getUnpaidFinesList($limit);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: หมวดหมู่ทั้งหมด + สถิติ (สำหรับ PDF report)
     * ==========================================================================
     */
    public function getAllCategoriesWithStats(): array
    {
        // 📝 Pass-through → หมวดหมู่ทั้งหมด + สถิติ (สำหรับ PDF report)
        return $this->reportRepo->getAllCategoriesWithStats();
    }
}

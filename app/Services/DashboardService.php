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
     *          total_members, active_borrows, overdue_borrows, pending_reservations}
     * ✅ Use case: admin/index.php → stat cards ด้านบน
     */
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

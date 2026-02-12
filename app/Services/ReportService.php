<?php
/**
 * ReportService - Business Logic สำหรับรายงานและสถิติ
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Service นี้เป็น read-only aggregator สำหรับรายงานเชิงลึก
 * คล้าย DashboardService แต่เน้น report รายละเอียดมากกว่า
 * ข้อมูลจริงอยู่ที่ ReportRepository — Service นี้เป็นตัวกลาง
 *
 * 🏗️ สถาปัตยกรรม:
 * Controller → ReportService → ReportRepository
 *                             → BorrowRepository
 *                             → BookRepository
 *                             → UserRepository
 *
 * 📍 Entrypoints:
 * - admin/reports.php     → ผ่าน ReportRepository โดยตรง (ไม่ผ่าน Service นี้)
 * - admin/export_pdf.php  → ผ่าน report_helper.php
 * - admin/index.php       → ผ่าน DashboardService
 *
 * 🛡️ Security: read-only — ไม่มี side effect
 *
 * @package App\Services
 */

namespace App\Services;

require_once __DIR__ . '/../Repositories/ReportRepository.php';
require_once __DIR__ . '/../Repositories/BorrowRepository.php';
require_once __DIR__ . '/../Repositories/BookRepository.php';
require_once __DIR__ . '/../Repositories/UserRepository.php';

use App\Repositories\ReportRepository;
use App\Repositories\BorrowRepository;
use App\Repositories\BookRepository;
use App\Repositories\UserRepository;
use PDO;

class ReportService
{
    // 🗄️ PDO + Repositories (read-only — ไม่มี write)
    private PDO $pdo;
    private ReportRepository $reportRepo;
    private BorrowRepository $borrowRepo;
    private BookRepository $bookRepo;
    private UserRepository $userRepo;

    // 🏗️ Constructor: สร้าง repo ทั้งหมด — read-only service
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->reportRepo = new ReportRepository($pdo);
        $this->borrowRepo = new BorrowRepository($pdo);
        $this->bookRepo = new BookRepository($pdo);
        $this->userRepo = new UserRepository($pdo);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สถิติรวมสำหรับ Dashboard (books + members + borrows + fines)
     * ==========================================================================
     *
     * 📤 Output: @return array {books, members, borrows, fines}
     */
    public function getDashboardStatistics(): array
    {
        // 📝 รวมสถิติ 4 กลุ่มเป็น 1 array สำหรับ dashboard
        return [
            'books' => $this->getBookStatistics(),      // หนังสือ
            'members' => $this->getMemberStatistics(),    // สมาชิก
            'borrows' => $this->getBorrowStatistics(),    // การยืม
            'fines' => $this->getFineStatistics(),        // ค่าปรับ
        ];
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สถิติหนังสือ (pass-through ไป BookRepository)
     * ==========================================================================
     */
    public function getBookStatistics(): array
    {
        // 📝 Pass-through → {total, available, borrowed, titles}
        return $this->bookRepo->getStatistics();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สถิติสมาชิก (total + new_this_month)
     * ==========================================================================
     */
    public function getMemberStatistics(): array
    {
        // 📝 รวม 2 ค่าจาก UserRepository
        return [
            'total' => $this->userRepo->countMembers(),            // สมาชิกทั้งหมด
            'new_this_month' => $this->userRepo->countNewThisMonth() // สมัครเดือนนี้
        ];
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สถิติการยืม (pass-through ไป ReportRepository)
     * ==========================================================================
     */
    public function getBorrowStatistics(): array
    {
        // 📝 Pass-through → {total, active, returned, overdue}
        return $this->reportRepo->getBorrowStats();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สถิติค่าปรับ (pass-through ไป ReportRepository)
     * ==========================================================================
     */
    public function getFineStatistics(): array
    {
        // 📝 Pass-through → {total_fines, collected, unpaid}
        return $this->reportRepo->getFineStats();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รายงานยืมรายเดือน (สำหรับ Chart)
     * ==========================================================================
     */
    public function getMonthlyBorrowReport(int $months = 6): array
    {
        // 📝 Pass-through → สถิติรายเดือน (สำหรับ Chart.js)
        return $this->reportRepo->getMonthlyReport($months);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รายงานหมวดหมู่ยอดนิยม (สำหรับ Chart)
     * ==========================================================================
     */
    public function getCategoryDistribution(int $limit = 6): array
    {
        // 📝 Pass-through → หมวดหมู่ + สัดส่วนการยืม (สำหรับ pie chart)
        return $this->reportRepo->getCategoryDistribution($limit);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: หนังสือยอดนิยม (pass-through)
     * ==========================================================================
     */
    public function getPopularBooks(int $limit = 10): array
    {
        // 📝 Pass-through → หนังสือยอดนิยม
        return $this->reportRepo->getPopularBooks($limit);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สมาชิกยืมมากที่สุด (pass-through)
     * ==========================================================================
     */
    public function getTopBorrowers(int $limit = 10): array
    {
        // 📝 Pass-through → สมาชิกยืมมากที่สุด
        return $this->reportRepo->getTopBorrowers($limit);
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
     * 🎯 จุดประสงค์: รายการเกินกำหนดคืน (pass-through)
     * ==========================================================================
     */
    public function getOverdueBorrows(int $limit = 10): array
    {
        // 📝 Pass-through → borrows เกินกำหนด
        return $this->borrowRepo->findOverdue($limit);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: รายงานรายวัน (pass-through)
     * ==========================================================================
     *
     * 📥 Input: @param string|null $date (null = วันนี้)
     */
    public function getDailyReport(string $date = null): array
    {
        // 📝 Pass-through → สถิติรายวัน (null = วันนี้)
        return $this->reportRepo->getDailyReport($date);
    }
}

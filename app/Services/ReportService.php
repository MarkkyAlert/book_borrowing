<?php
/**
 * ReportService - Business Logic สำหรับรายงานและสถิติ
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
    private PDO $pdo;
    private ReportRepository $reportRepo;
    private BorrowRepository $borrowRepo;
    private BookRepository $bookRepo;
    private UserRepository $userRepo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->reportRepo = new ReportRepository($pdo);
        $this->borrowRepo = new BorrowRepository($pdo);
        $this->bookRepo = new BookRepository($pdo);
        $this->userRepo = new UserRepository($pdo);
    }

    /**
     * ดึงสถิติรวมสำหรับ Dashboard
     */
    public function getDashboardStatistics(): array
    {
        return [
            'books' => $this->getBookStatistics(),
            'members' => $this->getMemberStatistics(),
            'borrows' => $this->getBorrowStatistics(),
            'fines' => $this->getFineStatistics(),
        ];
    }

    /**
     * สถิติหนังสือ (ใช้ BookRepository เป็น single source)
     */
    public function getBookStatistics(): array
    {
        return $this->bookRepo->getStatistics();
    }

    /**
     * สถิติสมาชิก (ใช้ UserRepository เป็น single source)
     */
    public function getMemberStatistics(): array
    {
        return [
            'total' => $this->userRepo->countMembers(),
            'new_this_month' => $this->userRepo->countNewThisMonth()
        ];
    }

    /**
     * สถิติการยืม
     */
    public function getBorrowStatistics(): array
    {
        return $this->reportRepo->getBorrowStats();
    }

    /**
     * สถิติค่าปรับ
     */
    public function getFineStatistics(): array
    {
        return $this->reportRepo->getFineStats();
    }

    /**
     * รายงานการยืมรายเดือน (สำหรับ Chart)
     */
    public function getMonthlyBorrowReport(int $months = 6): array
    {
        return $this->reportRepo->getMonthlyReport($months);
    }

    /**
     * รายงานหมวดหมู่ยอดนิยม (สำหรับ Chart)
     */
    public function getCategoryDistribution(int $limit = 6): array
    {
        return $this->reportRepo->getCategoryDistribution($limit);
    }

    /**
     * หนังสือยอดนิยม
     */
    public function getPopularBooks(int $limit = 10): array
    {
        return $this->reportRepo->getPopularBooks($limit);
    }

    /**
     * สมาชิกที่ยืมมากที่สุด
     */
    public function getTopBorrowers(int $limit = 10): array
    {
        return $this->reportRepo->getTopBorrowers($limit);
    }

    /**
     * รายการยืมล่าสุด
     */
    public function getRecentBorrows(int $limit = 5): array
    {
        return $this->borrowRepo->findRecent($limit);
    }

    /**
     * รายการเกินกำหนดคืน
     */
    public function getOverdueBorrows(int $limit = 10): array
    {
        return $this->borrowRepo->findOverdue($limit);
    }

    /**
     * รายงานรายวัน
     */
    public function getDailyReport(string $date = null): array
    {
        return $this->reportRepo->getDailyReport($date);
    }
}

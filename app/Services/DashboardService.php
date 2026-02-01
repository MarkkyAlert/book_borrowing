<?php
/**
 * DashboardService - Business Logic สำหรับ Admin Dashboard
 * 
 * Service นี้รวม queries สำหรับแสดงผล Dashboard ทั้งหมด
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
    private PDO $pdo;
    private BookRepository $bookRepo;
    private BorrowRepository $borrowRepo;
    private UserRepository $userRepo;
    private CategoryRepository $categoryRepo;
    private ReservationRepository $reservationRepo;
    private PaymentRepository $paymentRepo;
    private ReportRepository $reportRepo;
    
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
     * ดึงสถิติ summary cards (book/borrow/members stats)
     */
    public function getCardStats(): array
    {
        $bookStats = $this->bookRepo->getStatistics();
        return [
            'total_books' => $bookStats['total'],
            'available_books' => $bookStats['available'],
            'borrowed_books' => $bookStats['borrowed'],
            'total_members' => $this->userRepo->countMembers(),
            'active_borrows' => $this->borrowRepo->countActive(),
            'overdue_borrows' => $this->borrowRepo->countOverdue(),
            'pending_reservations' => $this->reservationRepo->countPending()
        ];
    }
    
    /**
     * ดึงรายการยืมล่าสุด
     */
    public function getRecentBorrows(int $limit = 5): array
    {
        return $this->borrowRepo->findRecent($limit);
    }
    
    /**
     * ดึงรายการจองล่าสุด
     */
    public function getRecentReservations(int $limit = 5): array
    {
        return $this->reservationRepo->findPending($limit);
    }
    
    /**
     * ดึงรายการเกินกำหนด
     */
    public function getOverdueList(int $limit = 10): array
    {
        return $this->borrowRepo->findOverdue($limit);
    }
    
    /**
     * ดึงสถิติรายเดือน (สำหรับ Chart)
     * ใช้ ReportRepository เป็น single source of truth
     */
    public function getMonthlyStats(int $months = 6): array
    {
        return $this->reportRepo->getMonthlyReport($months);
    }
    
    /**
     * ดึงสถิติหมวดหมู่ (สำหรับ Chart)
     */
    public function getCategoryStats(int $limit = 6): array
    {
        return $this->categoryRepo->getStatistics($limit);
    }
    
    /**
     * ดึงยอดค่าปรับที่รับชำระแล้ว
     */
    public function getTotalFinesCollected(): float
    {
        return $this->paymentRepo->getTotalCollected();
    }
}

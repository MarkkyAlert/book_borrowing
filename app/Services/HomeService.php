<?php
/**
 * HomeService - Business Logic สำหรับหน้าแรก (public, ไม่ต้อง login)
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Service นี้ดึงข้อมูลหนังสือ/หมวดหมู่/สถิติ สำหรับหน้า public (ไม่ต้อง login)
 * ⚠️ มี side effect: markExpiredReservations() เป็น write operation (lazy expire)
 *    ทำให้ stock ที่จองหมดอายุถูกคืนก่อนแสดงผล
 *
 * 🏗️ สถาปัตยกรรม:
 * index.php → HomeService → BookRepository
 *                          → CategoryRepository
 *                          → UserRepository
 *
 * 📍 Entrypoint:
 * - index.php → getBooks(), getStats(), getCategories()
 *
 * 🛡️ Security: ไม่ต้อง auth — แต่มี lazy expire (write) เป็น side effect
 *
 * @package App\Services
 */

namespace App\Services;

require_once __DIR__ . '/../Repositories/BookRepository.php';
require_once __DIR__ . '/../Repositories/CategoryRepository.php';
require_once __DIR__ . '/../Repositories/UserRepository.php';
require_once __DIR__ . '/../Repositories/ReservationRepository.php';

use App\Repositories\BookRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\UserRepository;
use App\Repositories\ReservationRepository;
use PDO;

class HomeService
{
    // 🗄️ PDO + Repositories
    private PDO $pdo;
    private BookRepository $bookRepo;
    private CategoryRepository $categoryRepo;
    private UserRepository $userRepo;
    private ReservationRepository $reservationRepo;
    
    // 🏗️ Constructor: สร้าง repo ทั้งหมด
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->bookRepo = new BookRepository($pdo);
        $this->categoryRepo = new CategoryRepository($pdo);
        $this->userRepo = new UserRepository($pdo);
        $this->reservationRepo = new ReservationRepository($pdo);
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงหนังสือ + categories สำหรับหน้าแรก
     * ==========================================================================
     *
     * 📥 Input: @param array $filters {search?, category_id?, status?}
     * 📤 Output: @return array {books: array, categories: array}
     *
     * 🧠 เหตุผล: คืนทั้ง books + categories ในครั้งเดียว
     *   เพื่อลด round-trip (หน้าแรกต้องแสดงทั้ง dropdown กรอง + รายการ)
     *
     * ✅ Use case: index.php
     */
    public function getBooks(array $filters = []): array
    {
        // � [LAZY EXPIRE] คืน stock จาก reservation ที่หมดอายุก่อนดึงข้อมูล
        //    ถ้าไม่ทำ → หนังสือที่จองหมดอายุแล้วจะยังแสดงว่า "หมด" อยู่
        $this->reservationRepo->markExpiredReservations();

        // �� แปลง request params เป็น repo filters
        //    กรองเฉพาะค่าที่ไม่ว่างออก (ป้องกันส่งค่าว่างไป DB)
        $bookFilters = [];
        
        if (!empty($filters['search'])) {
            $bookFilters['search'] = $filters['search'];
        }
        
        if (!empty($filters['category_id'])) {
            $bookFilters['category_id'] = (int) $filters['category_id'];
        }
        
        if (!empty($filters['status']) && $filters['status'] === 'available') {
            $bookFilters['available'] = true;
        }
        
        // 📤 คืนทั้ง books + categories ในครั้งเดียว
        //    ลด round-trip: หน้าแรกต้องใช้ทั้ง dropdown กรอง + รายการหนังสือ
        return [
            'books' => $this->bookRepo->findAll($bookFilters),
            'categories' => $this->categoryRepo->findAll()
        ];
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สถิติหน้าแรก (public dashboard)
     * ==========================================================================
     *
     * 📤 Output: @return array {total_books, available_books, total_members}
     * ✅ Use case: index.php → stat cards
     */
    public function getStats(): array
    {
        // 🔄 [LAZY EXPIRE] คืน stock ก่อนนับสถิติ (ให้ตัวเลข available ถูกต้อง)
        $this->reservationRepo->markExpiredReservations();

        // 📝 รวมสถิติสำหรับแสดงหน้าแรก (public dashboard)
        $bookStats = $this->bookRepo->getStatistics();
        return [
            'total_books' => $bookStats['total'],        // หนังสือทั้งหมด
            'available_books' => $bookStats['available'],  // หนังสือว่าง
            'total_members' => $this->userRepo->countMembers()  // สมาชิกทั้งหมด
        ];
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงหมวดหมู่ทั้งหมด (pass-through)
     * ==========================================================================
     */
    public function getCategories(): array
    {
        // 📝 Pass-through → หมวดหมู่ทั้งหมด (ORDER BY name)
        return $this->categoryRepo->findAll();
    }
}

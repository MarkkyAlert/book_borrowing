<?php
/**
 * HomeService - Business Logic สำหรับหน้าแรก
 * 
 * Service นี้จัดการ:
 * - ดึงรายการหนังสือพร้อม filters
 * - ดึงสถิติสำหรับ Dashboard
 * 
 * @package App\Services
 */

namespace App\Services;

require_once __DIR__ . '/../Repositories/BookRepository.php';
require_once __DIR__ . '/../Repositories/CategoryRepository.php';
require_once __DIR__ . '/../Repositories/UserRepository.php';

use App\Repositories\BookRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\UserRepository;
use PDO;

class HomeService
{
    private PDO $pdo;
    private BookRepository $bookRepo;
    private CategoryRepository $categoryRepo;
    private UserRepository $userRepo;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->bookRepo = new BookRepository($pdo);
        $this->categoryRepo = new CategoryRepository($pdo);
        $this->userRepo = new UserRepository($pdo);
    }
    
    /**
     * ดึงรายการหนังสือพร้อม filters (สำหรับหน้าแรก)
     * 
     * @param array $filters {
     *     search?: string,      // คำค้นหา (title, author)
     *     category_id?: int,    // ID หมวดหมู่
     *     status?: string       // 'available' = มีให้ยืม
     * }
     * @return array { books: array, categories: array }
     */
    public function getBooks(array $filters = []): array
    {
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
        
        return [
            'books' => $this->bookRepo->findAll($bookFilters),
            'categories' => $this->categoryRepo->findAll()
        ];
    }
    
    /**
     * ดึงสถิติสำหรับ Dashboard
     * 
     * @return array {
     *     total_books: int,      // จำนวนหนังสือทั้งหมด (รวม quantity)
     *     available_books: int,  // จำนวนหนังสือที่ยังมีให้ยืม
     *     total_members: int     // จำนวนสมาชิก
     * }
     */
    public function getStats(): array
    {
        $bookStats = $this->bookRepo->getStatistics();
        return [
            'total_books' => $bookStats['total'],
            'available_books' => $bookStats['available'],
            'total_members' => $this->userRepo->countMembers()
        ];
    }
    
    /**
     * ดึงหมวดหมู่ทั้งหมด
     */
    public function getCategories(): array
    {
        return $this->categoryRepo->findAll();
    }
}

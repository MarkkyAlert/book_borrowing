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
     * 📥 Input: @param array $filters {search?, category_id?, status?, page?, per_page?}
     * 📤 Output: @return array {books: array, categories: array, pagination: array}
     *   - books      = เฉพาะหนังสือของหน้านั้น (ไม่ใช่ทั้งหมด)
     *   - pagination = ผลจาก paginate() → page, total, total_pages, from, to, pages
     *
     * 🧠 เหตุผล: คืนทั้ง books + categories ในครั้งเดียว
     *   เพื่อลด round-trip (หน้าแรกต้องแสดงทั้ง dropdown กรอง + รายการ)
     *
     * ⚠️ books ถูกจำกัดจำนวนแล้ว — ถ้าต้องการยอดรวมจริงให้ใช้ pagination['total']
     *   ห้ามใช้ count($books) เพราะจะได้แค่จำนวนในหน้านั้น
     *
     * ✅ Use case: index.php, api/search_books.php
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

        // 👁️ ซ่อนหนังสือที่ถูกปิดการแสดงผลจากหน้า public
        // 🛡️ [SECURITY] F-01: บรรทัดนี้คือด่านเดียวที่กันหนังสือซ่อนไม่ให้หลุดสู่หน้าสาธารณะ
        //    index.php และ api/search_books.php เรียกผ่านที่นี่ทั้งคู่ → ห้ามข้าม
        $bookFilters['visible_only'] = true;

        // 📄 แบ่งหน้า — นับยอดรวมก่อน แล้วค่อยดึงเฉพาะหน้าที่ขอ
        // 🧠 ต้องนับด้วย $bookFilters ชุดเดียวกัน (มี visible_only แล้ว)
        //    ไม่งั้นจะบอกจำนวนเล่มรวมหนังสือที่ซ่อนอยู่ด้วย = เปิดเผยว่ามีของซ่อนอยู่กี่เล่ม
        $total      = $this->bookRepo->countAll($bookFilters);
        $perPage    = max(1, (int) ($filters['per_page'] ?? BOOKS_PER_PAGE));
        $pagination = paginate($total, $filters['page'] ?? 1, $perPage);

        $bookFilters['limit']  = $pagination['per_page'];
        $bookFilters['offset'] = $pagination['offset'];

        // 📤 คืนทั้ง books + categories + ข้อมูลแบ่งหน้า ในครั้งเดียว
        //    ลด round-trip: หน้าแรกต้องใช้ทั้ง dropdown กรอง + รายการหนังสือ
        return [
            'books' => $this->bookRepo->findAll($bookFilters),
            'categories' => $this->categoryRepo->findAll(),
            'pagination' => $pagination
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

        // 👁️ นับเฉพาะหนังสือที่เปิดให้เห็น (is_visible = 1) สำหรับหน้า public
        $pdo = $this->pdo;
        $row = $pdo->query("
            SELECT 
                COALESCE(SUM(quantity), 0) as total,
                COALESCE(SUM(available), 0) as available
            FROM books
            WHERE is_visible = 1
        ")->fetch();

        return [
            'total_books' => (int) $row['total'],
            'available_books' => (int) $row['available'],
            'total_members' => $this->userRepo->countMembers()
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

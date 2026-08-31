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
     * 📤 @return array{overdue:int, due_soon:int, pending_reservations:int, unpaid_people:int, total:int,
     *                 due_today:int, expiring_reservations:int}
     *    🔴 due_today/expiring_reservations ไม่ถูกนับใน total (เป็นส่วนย่อยของตัวอื่น)
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
                (SELECT COUNT(*) FROM borrows
                  WHERE status = 'borrowing' AND due_date = CURDATE())                      AS due_today,
                (SELECT COUNT(*) FROM reservations r
                  WHERE " . \App\Repositories\ReservationRepository::EXPIRING_SOON_CONDITION . ")     AS expiring_reservations,
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

        /**
         * 🔴 due_today และ expiring_reservations เป็น "ส่วนย่อย" ของสองตัวข้างบน
         *    ครบกำหนดวันนี้ 20 รายการ อยู่ในกลุ่มใกล้ครบกำหนด 74 รายการด้วย
         *    จองที่ใกล้หมดอายุ ก็อยู่ในกลุ่มจองรอมารับด้วย
         *
         * 🧠 จึงเก็บแยกไว้ "หลัง" คำนวณ total — ป้ายแดงต้องนับ 74 ไม่ใช่ 94
         *    การยืมรายการเดียวถูกนับสองรอบ = ตัวเลขโกหก
         *
         * 🧠 ทำไมไม่แยก due_soon ให้ไม่รวมวันนี้แทน: รายงาน "ใบรายชื่อโทรตาม"
         *    (report_helper.php → getDueSoonReport) ใช้ due_date >= CURDATE() รวมวันนี้
         *    ถ้าตัดวันนี้ออก เลขในกระดิ่งจะไม่ตรงกับรายงานที่มันลิงก์ไป
         *    และการตัดคนที่ครบกำหนดวันนี้ออกจากใบโทรตามก็ผิดเจตนาของใบนั้น
         */
        $counts['total'] = array_sum($counts);
        $counts['due_today']             = (int) ($row['due_today'] ?? 0);
        $counts['expiring_reservations'] = (int) ($row['expiring_reservations'] ?? 0);

        return $cache = $counts;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: [H1-H5] ตรวจ "สุขภาพระบบ" — สภาวะที่ตรวจได้แต่ไม่เคยมีใครบอก
     * ==========================================================================
     *
     * 📤 Output: @return array {items: [{key,label,detail,how,severity,admin_only,url}], total, admin_total}
     *
     * 🧠 ปกติต้องได้ 0 ข้อทั้งหมด จึงไม่สร้าง noise ให้กระดิ่ง
     *    ต่างจาก "หนังสือไม่มีเลขเรียก 405 เล่ม" ซึ่งเป็นงานค้างถาวร ใส่กระดิ่งแล้วแดงตลอดกาล
     *    จนคนเลิกมอง — ของพวกนั้นอยู่ที่ตัวกรองในหน้ารายการ ไม่ใช่ที่นี่
     *
     * ⚠️ [H1] เตือนอย่างเดียว ไม่ซ่อม available ให้อัตโนมัติ เหตุผล 3 ข้อ:
     *    1. กลบหลักฐาน — ถ้าแถวใน borrows หายไปจริง การคำนวณใหม่จะทำให้ตัวเลขสวย
     *       แล้วซ่อนข้อมูลที่หายไปตลอดกาล เลขที่เพี้ยนคืออาการ ไม่ใช่โรค
     *    2. เป็นการเขียน DB ระหว่าง GET ที่ต้องไปแย่ง lock กับธุรกรรมยืมคืนจริง
     *       จะต้อง FOR UPDATE แถว books ทุกครั้งที่โหลดหน้าแอดมิน เพื่อประโยชน์ที่ไม่มี
     *    3. ถ้ามันเพี้ยนได้ทั้งที่มี CHECK constraint + FOR UPDATE 87 จุด แปลว่ามีบั๊ก
     *       ที่ต้องตามหา การซ่อมเงียบ ๆ ทำให้บั๊กนั้นทำงานต่อไปโดยไม่มีใครรู้
     *
     * 🧠 แบ่งเป็น 2 กลุ่มตามราคาโดยเจตนา:
     *    - ถูก (H2/H3/H4): ตรวจสดทุกครั้ง → แก้เสร็จแล้วรีเฟรชเห็นผลทันที
     *    - แพง (H1/H5): cache ใน session 5 นาที → H1 คิวรีย์ ~20ms ต่อ 405 เล่ม
     *      (หมื่นเล่ม ≈ ครึ่งวินาที) และ H5 เขียนไฟล์จริงลงดิสก์ทุกครั้งที่เรียก
     *      สภาวะพวกนี้ "ไม่ควรเกิด" อยู่แล้ว ช้าไป 5 นาทีไม่มีผลอะไร
     *
     * ✅ Use case: admin/header.php → กลุ่ม "สุขภาพระบบ" ในกระดิ่ง
     */
    public function getSystemHealth(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $items = [];

        // ── ตรวจสด (ราคาเกือบศูนย์) ────────────────────────────────────────
        try {
            // 🔴 [H2] เปิดอีเมลไว้แต่ส่งไม่ออก
            //    เดิมความล้มเหลวลง error_log อย่างเดียว หน้าเว็บเงียบสนิท (กันเดาบัญชี — ถูกแล้ว)
            //    แต่ผู้ดูแลก็ไม่เห็นด้วย → ลูกค้าตั้ง SMTP ผิด สมาชิกไม่ได้ลิงก์รีเซ็ตสักคน
            //    แล้วไม่มีใครรู้จนกว่าจะมีคนเดินมาบ่นที่เคาน์เตอร์
            $mailError = getSetting('mail_last_error', '');
            if ($mailError !== '') {
                $when = getSetting('mail_last_error_at', '');
                $items[] = [
                    'key'        => 'mail_failing',
                    'label'      => 'ส่งอีเมลไม่สำเร็จ',
                    'detail'     => $when !== '' ? 'ล่าสุด ' . $when : '',
                    'how'        => 'ตรวจการตั้งค่า SMTP แล้วกด "ทดสอบส่ง" ในหน้าตั้งค่า',
                    'severity'   => 'danger',
                    'admin_only' => false,
                    'url'        => 'settings.php#mail',
                ];
            }

            // 🔴 [H3] ไฟล์ติดตั้งยังอยู่ — เห็นเฉพาะ admin
            //    🧠 ไม่ทำปุ่ม "ลบให้เลย" โดยเจตนา: การลบไฟล์ดูสิทธิ์ที่ "โฟลเดอร์" ไม่ใช่ที่ไฟล์
            //       เว็บเซิร์ฟเวอร์มักไม่มีสิทธิ์เขียนโฟลเดอร์โปรเจกต์ (เช่น XAMPP รันเป็น daemon
            //       แต่โฟลเดอร์เป็นของ user) → ปุ่มจะ "บางโฮสต์ได้ บางโฮสต์ไม่ได้"
            //       ปุ่มที่ทำงานบ้างไม่ทำงานบ้าง แย่กว่าไม่มีปุ่ม
            if (is_file(dirname(__DIR__, 2) . '/install.php')) {
                $items[] = [
                    'key'        => 'installer_present',
                    'label'      => 'ยังไม่ได้ลบไฟล์ติดตั้ง',
                    'detail'     => 'install.php ยังอยู่บนเซิร์ฟเวอร์',
                    'how'        => 'ลบไฟล์ install.php ออกจากโฟลเดอร์เว็บ',
                    'severity'   => 'warning',
                    'admin_only' => true,
                    'url'        => null,
                ];
            }

            // 🔴 [H4] โหมดพัฒนาเปิดอยู่บนเครื่องจริง
            //    error จะโชว์ path เซิร์ฟเวอร์ + คำสั่ง SQL ให้คนนอกเห็น
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $items[] = [
                    'key'        => 'debug_on',
                    'label'      => 'เปิดโหมดพัฒนาอยู่',
                    'detail'     => 'ข้อความ error จะเปิดเผยโครงสร้างเซิร์ฟเวอร์',
                    'how'        => 'ตั้ง APP_DEBUG=false ในไฟล์ .env',
                    'severity'   => 'warning',
                    'admin_only' => true,
                    'url'        => null,
                ];
            }

            // ── ตรวจแบบมี cache (ราคาแพง) ──────────────────────────────────
            foreach ($this->expensiveHealthChecks() as $item) {
                $items[] = $item;
            }
        } catch (\Throwable $e) {
            // 🛡️ ห้ามล้มทั้งหน้าแอดมินเพราะตัวตรวจสุขภาพพัง
            //    เจตนาเดียวกับ try/catch รอบ getAlertCounts() ใน admin/header.php
            //    กระดิ่ง 4 ข้อเดิมต้องยังขึ้นได้ตามปกติ
            error_log('[DashboardService::getSystemHealth] ' . $e->getMessage());
        }

        return $cache = [
            'items'       => $items,
            // 📊 total = ทุกคนเห็นกี่ข้อ · admin_total = admin เห็นกี่ข้อ (รวม admin_only)
            'total'       => count(array_filter($items, fn($i) => !$i['admin_only'])),
            'admin_total' => count($items),
        ];
    }

    /**
     * 🎯 [H1/H5] ตัวตรวจที่ราคาแพง — cache ใน session 5 นาที
     *
     * 🧠 ทำไม session ไม่ใช่ไฟล์: ตัวตรวจ H5 คือ "โฟลเดอร์เขียนได้ไหม"
     *    ถ้าไป cache ลงไฟล์ก็ต้องมีโฟลเดอร์ที่เขียนได้ก่อน = วนเป็นงูกินหาง
     * 🧠 ทำไมไม่ cache ลง settings: จะกลายเป็นเขียน DB ทุก 5 นาทีจากทุก request
     *    ที่โหลดหน้าแอดมิน ทั้งที่เป็นแค่ค่าชั่วคราวของคนที่ล็อกอินอยู่
     */
    private function expensiveHealthChecks(): array
    {
        $ttl = 300; // 5 นาที
        $now = time();

        if (isset($_SESSION['sys_health_cache'], $_SESSION['sys_health_cache_at'])
            && ($now - (int) $_SESSION['sys_health_cache_at']) < $ttl) {
            return $_SESSION['sys_health_cache'];
        }

        $items = [];

        // 🔴 [H1] สต็อกไม่ตรงสูตร — นิยามอยู่ที่ BookRepository::STOCK_ANOMALY_CONDITION
        $anomalies = $this->bookRepo->countStockAnomalies();
        if ($anomalies > 0) {
            $items[] = [
                'key'        => 'stock_anomaly',
                'label'      => 'สต็อกไม่ตรงกับการยืมจริง',
                'detail'     => number_format($anomalies) . ' เล่ม',
                'how'        => 'เปิดดูว่าเล่มไหน แล้วตรวจว่ามีรายการยืมหายไปหรือไม่',
                'severity'   => 'danger',
                'admin_only' => false,
                'url'        => 'books.php?stock_anomaly=1',
            ];
        }

        // 🔴 [H5] โฟลเดอร์ปกเขียนไม่ได้ — เดิมรู้ตอนกดอัปโหลดแล้วไม่ขึ้นเท่านั้น
        //    ⚠️ ห้ามใส่ path เต็มลงในข้อความ — จะกลายเป็นการเปิดเผยโครงสร้างเซิร์ฟเวอร์
        //       ซึ่งคือสิ่งที่ H4 พยายามกันอยู่พอดี
        $coversDir = dirname(__DIR__, 2) . '/uploads/covers';
        if (!isDirActuallyWritable($coversDir)) {
            $items[] = [
                'key'        => 'covers_not_writable',
                'label'      => 'อัปโหลดปกหนังสือไม่ได้',
                'detail'     => 'โฟลเดอร์ uploads/covers/ เขียนไม่ได้',
                'how'        => 'ให้สิทธิ์เขียนโฟลเดอร์ uploads/covers/ แก่เว็บเซิร์ฟเวอร์',
                'severity'   => 'warning',
                'admin_only' => false,
                'url'        => null,
            ];
        }

        $_SESSION['sys_health_cache']    = $items;
        $_SESSION['sys_health_cache_at'] = $now;

        return $items;
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

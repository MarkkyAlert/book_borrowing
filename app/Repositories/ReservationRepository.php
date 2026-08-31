<?php
/**
 * ReservationRepository - Database Access สำหรับการจอง
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Repository นี้จัดการ SQL queries สำหรับตาราง reservations (การจองหนังสือ)
 * มีระบบ "lazy expiration" — expire ตอนที่มีคน query ไม่ใช้ cron job
 *
 * 📚 โครงสร้างตาราง reservations:
 * +--------------+--------------+----------------------------------------------+
 * | Column       | Type         | อธิบาย                                       |
 * +--------------+--------------+----------------------------------------------+
 * | id           | INT AUTO PK  | Primary Key                                  |
 * | user_id      | INT FK       | ผู้จอง → users.id                         |
 * | book_id      | INT FK       | หนังสือ → books.id                        |
 * | status       | ENUM         | 'pending','fulfilled','cancelled','expired'  |
 * | expires_at   | DATETIME     | เวลาหมดอายุ                                |
 * | borrow_id    | INT FK NULL  | ถ้า fulfilled → borrows.id                  |
 * | created_at   | DATETIME     | เวลาสร้าง                                  |
 * +--------------+--------------+----------------------------------------------+
 *
 * 📍 Entrypoints:
 * - ReservationService    → create(), updateStatus(), findPendingForUpdate(),
 *                           hasPending(), findByUserAndBook()
 * - admin/reservations.php → findAll(), findPending()
 * - my_reservations.php   → findByUser()
 * - DashboardService      → countPending()
 * - BookService           → countPendingByBook()
 * - MemberService         → countPendingByUser()
 *
 * 🛡️ Security Design:
 * - findPendingForUpdate() = row lock ป้องกัน double approve / cancel after approve
 * - updateStatus() มี state guard: เปลี่ยนได้เฉพาะจาก pending
 * - markExpiredReservations() ใช้ transaction เพื่อ atomic expire + คืน stock
 *
 * ⚠️ ห้ามแก้:
 * - markExpiredReservations() ต้องคืน stock ด้วยเสมอ
 * - findPendingForUpdate() ต้องเรียกใน transaction
 *
 * @package App\Repositories
 */

namespace App\Repositories;

use PDO;

class ReservationRepository
{
    /**
     * 🔴 นิยาม "การจองที่ใกล้หมดอายุ" — แหล่งความจริงเดียวของทั้งระบบ
     *
     * 🧠 ยกเกณฑ์มาจาก my_reservations.php ที่ติดป้ายแดง "ใกล้หมดอายุ!" อยู่แล้ว:
     *        strtotime($r['expires_at']) < strtotime('+1 day')
     *    ถ้ากระดิ่งใช้เกณฑ์ต่างออกไป จะเกิดอาการกระดิ่งขึ้น 1
     *    แต่เปิดหน้าไปแล้วไม่มีรายการไหนติดป้ายอะไรเลย — แย่กว่าไม่เตือน
     *
     * ⚠️ ต้องมี expires_at > NOW() ด้วย — ตัดรายการที่ "หมดอายุไปแล้วแต่ยังไม่ถูกล้าง" ออก
     *    เจอตอนทดสอบบน clone: กระดิ่งบอก 16 แต่กดเข้าไปเจอ 11
     *    เพราะ countExpiringSoon() ตั้งใจไม่เรียก markExpiredReservations()
     *    (ไม่อยากเขียน DB ทุกครั้งที่โหลดหน้าแอดมิน) แต่ countAll() เรียก
     *    → ตอนนับยังเห็น 5 รายการที่เลยเวลาแล้ว พอกดเข้าไปหน้านั้น lazy expire ล้างทิ้ง
     *    เงื่อนไขนี้ทำให้ทั้งสองฝั่งเห็นตรงกันโดยไม่ต้องเขียน DB เพิ่ม
     *    และทำให้ป้ายพูดตรงความหมาย: "ใกล้หมดอายุ" ไม่ใช่ "หมดอายุไปแล้ว"
     *    (รายการที่หมดอายุแล้วมีที่อยู่ของตัวเองคือแท็บ "ไม่มารับ" — F-42)
     *
     * ⚠️ ต้องเช็ค expires_at IS NOT NULL ด้วย
     *    คิวรอ (status = 'waiting') ไม่มีวันหมดอายุ เก็บเป็น NULL
     *    ถ้าไม่กรอง MySQL จะเทียบ NULL แล้วได้ NULL (ไม่ใช่ TRUE/FALSE) — เงียบ ๆ นับหาย
     *
     * 📌 ใช้ที่: buildListConditions() · countExpiringSoon()
     *           DashboardService::getAlertCounts() · BorrowRepository::getMemberAlertCounts()
     */
    public const EXPIRING_SOON_CONDITION =
        "r.status = 'pending' AND r.expires_at IS NOT NULL
         AND r.expires_at > NOW() AND r.expires_at < NOW() + INTERVAL 1 DAY";

    // 🗄️ PDO connection — inject ผ่าน constructor ใช้ร่วมกันทุกเมธอด
    private PDO $pdo;

    // 🛡️ [I-11 FIX] flag ป้องกันเรียก lazy expire ซ้ำหลายครั้งใน request เดียว
    //    findAll(), findByUser(), BookService::getBooks() ฯลฯ เรียก markExpiredReservations()
    //    page เดียวอาจเรียก 2-3 ครั้ง → flag นี้ทำให้รันจริงแค่ครั้งแรก
    private bool $expiredMarked = false;

    // 🏗️ Constructor: รับ PDO จากภายนอก (Dependency Injection)
    // → ใช้ connection เดียวกับ ReservationService
    // → ทำให้ transaction + FOR UPDATE lock ทำงานถูกต้อง
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: [LAZY EXPIRE] expire reservation หมดอายุ + คืน stock
     * ==========================================================================
     * ถูกเรียกอัตโนมัติจาก findAll() / findByUser() ก่อน query
     * ไม่ใช้ cron job — expire ตอนที่มีคนเข้ามาดู
     *
     * 🔄 Flow:
     * 1. SELECT pending + expires_at < NOW()
     * 2. BEGIN TRANSACTION
     * 3. วน loop: UPDATE status='expired' + books.available +1
     * 4. COMMIT
     *
     * 📤 Output: @return int จำนวนที่ถูก expire
     *
     * 🛡️ Security: transaction เพื่อ atomic (expire + คืน stock พร้อมกัน)
     * ⚠️ ห้ามแก้: ต้องคืน stock ด้วยเสมอ (ตอนจองหัก stock ไว้)
     * ✅ Use case: findAll(), findByUser() เรียกก่อน query
     */
    public function markExpiredReservations(): int
    {
        // 🛡️ [I-11 FIX] ถ้าเคย expire แล้วใน request นี้ → skip (ลด query ซ้ำ)
        if ($this->expiredMarked) {
            return 0;
        }
        $this->expiredMarked = true;

        // 📝 Step 1: ดึงรายการที่หมดอายุก่อน (pending + expires_at < NOW)
        //    ดึงเฉพาะ id + book_id (ใช้คืน stock)
        $expiredStmt = $this->pdo->prepare("
            SELECT id, book_id FROM reservations 
            WHERE status = 'pending' AND expires_at < NOW()
        ");
        $expiredStmt->execute();
        $expiredList = $expiredStmt->fetchAll();
        
        // ✅ ไม่มีรายการหมดอายุ → ไม่ต้องทำอะไร
        if (empty($expiredList)) {
            return 0;
        }
        
        // 📝 Step 2: เปิด transaction เพื่อให้ atomic
        //    ต้อง expire + คืน stock พร้อมกัน (ไม่ทำครึ่งๆ ได้)
        $this->pdo->beginTransaction();
        
        try {
            // 📝 Step 3: วน loop แต่ละรายการที่หมดอายุ
            foreach ($expiredList as $res) {
                // 🔄 UPDATE status = 'expired' (เฉพาะที่ยัง pending)
                // 🛡️ WHERE status='pending' = guard ป้องกัน expire ซ้ำ
                $updateStmt = $this->pdo->prepare("
                    UPDATE reservations SET status = 'expired' 
                    WHERE id = ? AND status = 'pending'
                ");
                $updateStmt->execute([$res['id']]);
                
                // 📦 คืน stock +1 (เพราะตอนจองหัก stock ไว้)
                // ⚠️ ห้ามลบขั้นตอนนี้! ถ้าไม่คืน stock → หนังสือจะหายไป 1 เล่ม
                if ($updateStmt->rowCount() > 0) {
                    // 🛡️ [I-05 FIX] เพิ่ม AND available < quantity ป้องกัน available เกิน quantity
                    //    เทียบกับ BookRepository::incrementAvailable() ที่มี guard เดียวกัน
                    //    defense-in-depth ร่วมกับ DB CHECK constraint (quantity >= available)
                    $stockStmt = $this->pdo->prepare("
                        UPDATE books SET available = available + 1
                        WHERE id = ? AND available < quantity
                    ");
                    $stockStmt->execute([$res['book_id']]);

                    // 🔄 คนแรกไม่มารับ → เล่มต้องตกไปที่คนถัดไปในคิว ไม่ใช่ขึ้นชั้นให้ใครก็ได้
                    //    อยู่ในทรานแซกชันเดียวกับการ expire อยู่แล้ว
                    //    ⚠️ ห้ามลบ — ถ้าลบ คิวจะขยับเฉพาะตอนคืนหนังสือ
                    //       ส่วนคนที่รอต่อจากคนที่ไม่มารับจะค้างอยู่ตลอดกาล
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . RESERVATION_EXPIRE_DAYS . ' days'));
                    $this->promoteNextInQueue((int) $res['book_id'], $expiresAt);
                }
            }
            
            // 📝 Step 4: commit ทั้งหมดพร้อมกัน (atomic)
            $this->pdo->commit();
            return count($expiredList);
            
        } catch (\Exception $e) {
            // ❌ rollback ทั้งหมดถ้ามี error
            $this->pdo->rollBack();
            // 🧠 Silent fail — lazy expire ไม่ควร block การทำงานหลัก
            //    ถ้าล้มเหลว ครั้งหน้าจะลองอีกครั้ง
            return 0;
        }
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการจองทั้งหมด (auto-expire ก่อน query)
     * ==========================================================================
     *
     * 📥 Input:
     * @param array $filters {
     *     status?: string  — 'pending','fulfilled','cancelled','expired'
     *     user_id?: int    — กรองเฉพาะ user
     *     book_id?: int    — กรองเฉพาะหนังสือ
     * }
     *
     * 📤 Output:
     * @return array [{reservation row + user_name, email, book_title, cover_image}, ...]
     *
     * 🧠 เหตุผล: เรียก markExpiredReservations() ก่อน query (lazy expire)
     * ✅ Use case: admin/reservations.php GET
     */
    public function findAll(array $filters = []): array
    {
        // 🔄 [LAZY EXPIRE] expire รายการหมดอายุก่อนจะ query
        //    ทำให้ข้อมูลที่แสดงเป็นปัจจุบันเสมอ
        //    📌 countAll() ก็เรียกตัวนี้ด้วย — ถ้าหน้าเรียกนับก่อน การ expire จะเกิดไปแล้ว
        //       ตรงนี้จึงมักไม่มีอะไรให้ทำ (idempotent) แต่ต้องคงไว้เพราะมีที่เรียก findAll ตรง ๆ
        $this->markExpiredReservations();

        // 🔧 สร้างเงื่อนไข (ใช้ร่วมกับ countAll ให้ผลตรงกันเสมอ)
        [$whereSQL, $params] = $this->buildListConditions($filters);

        // 📄 แบ่งหน้า — ใส่ LIMIT/OFFSET เฉพาะตอนที่ผู้เรียกส่ง limit มาเท่านั้น
        // 🧠 ไม่ส่ง limit = ดึงทั้งหมดเหมือนเดิม (สคริปต์/เทสต์ยังต้องได้ครบทุกแถว)
        // 🛡️ [SECURITY] cast เป็น int + clamp → ปลอดภัยแม้ค่ามาจาก $_GET
        $limitSQL = '';
        if (isset($filters['limit'])) {
            $limitSQL = 'LIMIT ? OFFSET ?';
            $params[] = max(1, (int) $filters['limit']);
            $params[] = max(0, (int) ($filters['offset'] ?? 0));
        }

        // ⏰ กรองเฉพาะที่ใกล้หมดอายุ → เรียงตามวันหมดอายุ ด่วนสุดอยู่บนสุด
        //    เรียงตามวันที่จองเหมือนเดิมจะทำให้คนที่เหลือ 2 ชั่วโมงไปอยู่ท้ายรายการ
        // 🧠 `, r.id ASC/DESC` คือตัวตัดสินเมื่อค่าเท่ากัน — ถ้าไม่มี การเรียงจะไม่คงที่
        //    ทำให้กดหน้า 2 เจอรายการซ้ำจากหน้า 1 หรือบางรายการหายไปเลย
        $orderSQL = !empty($filters['expiring'])
            ? 'ORDER BY r.expires_at ASC, r.id ASC'
            : 'ORDER BY r.created_at DESC, r.id DESC';

        // 📝 SQL: ดึงการจอง + ข้อมูล user + book
        $stmt = $this->pdo->prepare("
            SELECT r.*, u.name as user_name, u.email, b.title as book_title, b.cover_image
            FROM reservations r
            JOIN users u ON r.user_id = u.id
            JOIN books b ON r.book_id = b.id
            {$whereSQL}
            {$orderSQL}
            {$limitSQL}
        ");
        $stmt->execute($params);
        // 📤 คืน array การจองในหน้านั้น (หรือทั้งหมดถ้าไม่ได้ส่ง limit)
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับ "จองแล้วไม่มารับ" ในเดือนนี้ — F-42
     * ==========================================================================
     *
     * 📤 Output: @return int จำนวนการจองที่หมดอายุในเดือนปัจจุบัน
     *
     * 🧠 ทำไมต้องมี — lazy expire เคลียร์การจองที่หมดอายุก่อนหน้าจอจะ render
     *    สภาพ "จองแล้วไม่มารับ" จึงมองไม่เห็นเลยระหว่างใช้งานปกติ
     *    บรรณารักษ์ที่อยากรู้ว่าปัญหานี้เกิดบ่อยแค่ไหน ไม่มีตัวเลขให้ดู
     *
     * ⚠️ ไม่เรียก markExpiredReservations() ที่นี่ — ตัวนี้เป็นแค่การอ่านสถิติ
     *    ผู้เรียกที่ต้องการข้อมูลสดจะเรียกผ่าน countAll()/findAll() ซึ่ง expire ให้อยู่แล้ว
     */
    public function countExpiredThisMonth(): int
    {
        return (int) $this->pdo->query("
            SELECT COUNT(*) FROM reservations
            WHERE status = 'expired'
              AND MONTH(expires_at) = MONTH(CURDATE())
              AND YEAR(expires_at) = YEAR(CURDATE())
        ")->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับจำนวนการจองที่ตรงเงื่อนไข (ไม่สนใจ LIMIT)
     * ==========================================================================
     * ✅ Use case: admin/reservations.php ต้องรู้ยอดรวมเพื่อคำนวณจำนวนหน้า
     *
     * ⚠️ ห้ามสับสนกับ countPending() ที่นับเฉพาะสถานะ pending สำหรับ dashboard
     *
     * 🧠 ต้อง expire ก่อนนับ ไม่ใช่ปล่อยให้ findAll() เป็นคนทำ:
     *    หน้าเว็บเรียกนับก่อนแล้วค่อยดึงรายการ ถ้ารายการหมดอายุระหว่างนั้น
     *    (กรอง status=pending) จะได้ "นับ N แต่แสดง N-1" ซึ่งทำให้จำนวนหน้าเพี้ยน
     */
    public function countAll(array $filters = []): int
    {
        $this->markExpiredReservations();

        [$whereSQL, $params] = $this->buildListConditions($filters);

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM reservations r
            JOIN users u ON r.user_id = u.id
            JOIN books b ON r.book_id = b.id
            {$whereSQL}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับการจองที่กันเล่มไว้และกำลังจะหมดอายุ (สำหรับกระดิ่ง)
     * ==========================================================================
     *
     * 📥 @param int|null $userId  null = ทั้งระบบ (ฝั่งเจ้าหน้าที่) · มีค่า = เฉพาะคนนั้น
     * 📤 @return int
     *
     * ⚠️ ไม่เรียก markExpiredReservations() เพราะถูกเรียกทุกครั้งที่โหลดแดชบอร์ดอยู่แล้ว
     *    (admin/index.php:37) การเรียกซ้ำที่นี่จะกลายเป็นเขียน DB ทุกการโหลดหน้า
     */
    public function countExpiringSoon(?int $userId = null): int
    {
        $sql = "SELECT COUNT(*) FROM reservations r WHERE " . self::EXPIRING_SOON_CONDITION;
        if ($userId !== null) {
            $stmt = $this->pdo->prepare($sql . " AND r.user_id = ?");
            $stmt->execute([$userId]);
            return (int) $stmt->fetchColumn();
        }
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: แปลง $filters → WHERE clause + params
     * ==========================================================================
     * 🧠 ทำไมแยกออกมา: findAll() กับ countAll() ต้องกรองเหมือนกันเป๊ะ
     *    ไม่งั้นจะเจออาการ "บอกว่ามี 137 รายการ แต่หน้าสุดท้ายว่างเปล่า"
     *
     * 🛡️ [SECURITY] ค่าทุกตัว bind ผ่าน ? → ปลอดภัยจาก SQL Injection
     */
    private function buildListConditions(array $filters): array
    {
        // 📦 สร้างเงื่อนไข WHERE จาก filters
        $where = [];
        $params = [];

        // 🏷️ Filter: สถานะ (pending/fulfilled/cancelled/expired)
        if (!empty($filters['status'])) {
            $where[] = "r.status = ?";
            $params[] = $filters['status'];
        }

        // 👤 Filter: เฉพาะ user
        if (!empty($filters['user_id'])) {
            $where[] = "r.user_id = ?";
            $params[] = $filters['user_id'];
        }

        // 📖 Filter: เฉพาะหนังสือ
        if (!empty($filters['book_id'])) {
            $where[] = "r.book_id = ?";
            $params[] = $filters['book_id'];
        }

        // ⏰ Filter: เฉพาะที่ใกล้หมดอายุ — ปลายทางของกระดิ่ง "จองหมดอายุวันนี้"
        // 🛡️ whitelist: รับเฉพาะ '1' ค่าอื่นถือว่าไม่กรอง
        // 🧠 ใช้ const ตัวเดียวกับตัวนับ เลขในกระดิ่งกับจำนวนแถวในหน้าจึงตรงกันเสมอ
        if (!empty($filters['expiring'])) {
            $where[] = '(' . self::EXPIRING_SOON_CONDITION . ')';
        }

        // 🔗 ประกอบ WHERE clause
        $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        return [$whereSQL, $params];
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงการจองตาม ID (พร้อม user_name, book_title)
     * ==========================================================================
     *
     * 📥 Input: @param int $id Reservation ID
     * 📤 Output: @return array|null reservation row + user + book หรือ null
     * ✅ Use case: admin/reservations.php?view=ID
     */
    public function findById(int $id): ?array
    {
        // 📝 SQL: ดึงการจองตาม ID + JOIN user + book
        $stmt = $this->pdo->prepare("
            SELECT r.*, u.name as user_name, u.email, b.title as book_title
            FROM reservations r
            JOIN users u ON r.user_id = u.id
            JOIN books b ON r.book_id = b.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        // 📤 คืน reservation row + user + book หรือ null
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงการจองของ user+book (เฉพาะเล่ม)
     * ==========================================================================
     *
     * 📥 Input:
     * @param int         $userId ผู้จอง
     * @param int         $bookId หนังสือ
     * @param string|null $status กรอง status (null = ทุก status)
     *
     * 📤 Output: @return array|null reservation record หรือ null
     * ✅ Use case: ReservationService เช็คก่อน fulfill
     */
    public function findByUserAndBook(int $userId, int $bookId, ?string $status = null): ?array
    {
        // 📝 SQL: ค้นการจองของ user+book (เฉพาะเล่ม)
        $sql = "SELECT * FROM reservations WHERE user_id = ? AND book_id = ?";
        $params = [$userId, $bookId];

        // 🏷️ ถ้าระบุ status → กรองเพิ่ม (null = ทุก status)
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        // 🛡️ [I-10 FIX] เรียงจากใหม่สุด + LIMIT 1 ป้องกันคืนผิด record
        //    กรณี user มีหลาย reservations สำหรับ book เดียวกัน
        //    (เช่น cancelled → จองใหม่) → ต้องคืน record ล่าสุด
        $sql .= " ORDER BY created_at DESC, id DESC LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        // 📤 คืน reservation record ล่าสุด หรือ null
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: สร้างการจองใหม่ (status='pending' อัตโนมัติ)
     * ==========================================================================
     *
     * 📥 Input:
     * @param int    $userId    ผู้จอง
     * @param int    $bookId    หนังสือ
     * @param string $expiresAt Y-m-d H:i:s เวลาหมดอายุ
     *
     * 📤 Output: @return int Reservation ID ที่สร้าง
     *
     * 🧠 เหตุผล: INSERT เท่านั้น — decrement stock ทำใน Service layer
     * ✅ Use case: ReservationService::createReservation()
     */
    public function create(int $userId, int $bookId, string $expiresAt): int
    {
        // 📝 SQL: INSERT การจองใหม่ (status = 'pending' อัตโนมัติ)
        // 🧠 INSERT เท่านั้น — decrement stock ทำใน Service layer
        //    Repository ทำแค่ SQL ไม่มี business logic
        $stmt = $this->pdo->prepare("
            INSERT INTO reservations (user_id, book_id, expires_at, status)
            VALUES (?, ?, ?, 'pending')
        ");
        // 🚀 bind: [$userId, $bookId, $expiresAt]
        $stmt->execute([$userId, $bookId, $expiresAt]);
        // 📤 คืน Reservation ID ที่สร้าง
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: เปลี่ยน status (พร้อม state transition guard)
     * ==========================================================================
     *
     * 📥 Input:
     * @param int    $id        Reservation ID
     * @param string $newStatus 'fulfilled' | 'cancelled' | 'expired'
     *
     * 📤 Output: @return bool true = สำเร็จ, false = ไม่สามารถเปลี่ยนได้
     *
     * 🛡️ Security:
     * - PHP guard: in_array($newStatus, allowedTransitions)
     * - SQL guard: WHERE status='pending' — เปลี่ยนได้เฉพาะจาก pending
     *
     * ⚠️ State machine: pending → {fulfilled, cancelled, expired}
     *    fulfilled/cancelled/expired → (ไม่สามารถเปลี่ยน)
     * ✅ Use case: ReservationService::cancelReservation(),
     *            markExpiredReservations()
     */
    public function updateStatus(int $id, string $newStatus): bool
    {
        // 🛡️ [PHP GUARD] whitelist: อนุญาตเฉพาะ 3 สถานะเท่านั้น
        //    ค่าอื่น → return false ทันที (ปลอดภัย)
        $allowedTransitions = ['fulfilled', 'cancelled', 'expired'];
        
        if (!in_array($newStatus, $allowedTransitions)) {
            return false;
        }
        
        // 📝 SQL: เปลี่ยน status (เฉพาะจากสถานะที่ยัง "มีชีวิต")
        // 🛡️ [SQL GUARD] WHERE status IN ('waiting','pending') = ป้องกัน state machine
        //    waiting → {cancelled}                 (ยกเลิกคิว)
        //    waiting → pending                     ทำผ่าน promoteToPending() ไม่ใช่ที่นี่
        //    pending → {fulfilled, cancelled, expired}
        //    fulfilled/cancelled/expired → ไม่สามารถเปลี่ยน (0 rows affected)
        // 🧠 ต้องรับ waiting ด้วย ไม่งั้นสมาชิกยกเลิกคิวของตัวเองไม่ได้
        $stmt = $this->pdo->prepare("
            UPDATE reservations 
            SET status = ? 
            WHERE id = ? AND status IN ('waiting', 'pending')
        ");
        $stmt->execute([$newStatus, $id]);
        
        // 📤 rowCount() > 0 = เปลี่ยนสำเร็จ, = 0 = ไม่ได้ (ไม่ใช่ pending)
        return $stmt->rowCount() > 0;
    }
    
    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: เปลี่ยน status + link borrow_id (สำหรับ fulfill)
     * ==========================================================================
     * เมื่อ admin อนุมัติการจอง → สร้าง borrow + link กลับมา
     *
     * 📥 Input:
     * @param int    $id       Reservation ID
     * @param string $status   'fulfilled'
     * @param int    $borrowId Borrow ID ที่สร้าง
     *
     * 📤 Output: @return bool true = สำเร็จ
     * ✅ Use case: ReservationService::fulfillReservation()
     */
    public function updateStatusWithBorrow(int $id, string $status, int $borrowId): bool
    {
        // 📝 SQL: เปลี่ยน status + link borrow_id พร้อมกัน
        // 🧠 ใช้ตอน fulfill: admin อนุมัติการจอง → สร้าง borrow → link กลับมา
        // 🛡️ WHERE status='pending' = guard ป้องกัน double approve
        $stmt = $this->pdo->prepare("
            UPDATE reservations 
            SET status = ?, borrow_id = ? 
            WHERE id = ? AND status = 'pending'
        ");
        // 🚀 bind: [$status, $borrowId, $id]
        $stmt->execute([$status, $borrowId, $id]);
        
        // 📤 rowCount() > 0 = สำเร็จ
        return $stmt->rowCount() > 0;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับ pending reservations ทั้งระบบ (สำหรับ badge)
     * ==========================================================================
     *
     * 📤 Output: @return int จำนวนที่รอดำเนินการ
     * ✅ Use case: admin/header.php badge, DashboardService
     */
    public function countPending(): int
    {
        // 📝 SQL: นับ pending reservations ทั้งระบบ
        // 🧠 ใช้แสดง badge ใน admin header + Dashboard
        return (int) $this->pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการที่หมดอายุ (ยังไม่ถูก expire)
     * ==========================================================================
     *
     * 📤 Output: @return array[] pending + expires_at < NOW()
     * ✅ Use case: ตรวจสอบก่อน batch expire
     */
    public function findExpired(): array
    {
        // 📝 SQL: ดึงรายการที่หมดอายุแต่ยังไม่ถูก mark เป็น 'expired'
        // 🧠 ใช้ตรวจสอบก่อน batch expire
        return $this->pdo->query("
            SELECT * FROM reservations 
            WHERE status = 'pending' AND expires_at < NOW()
        ")->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ล็อกการจองที่ยัง "มีชีวิต" — ทั้งคิวรอและของที่พร้อมแล้ว
     * ==========================================================================
     *
     * 📥 Input: @param int $id, @param int|null $userId (null = admin ยกเลิกได้ทุกคน)
     * 📤 Output: @return array|null แถวที่ถูกล็อก
     *
     * 🧠 ต่างจาก findPendingForUpdate() ที่รับเฉพาะ pending —
     *    เมธอดนี้รับ waiting ด้วย เพราะสมาชิกต้องยกเลิกคิวของตัวเองได้
     *    ผู้เรียกต้องดู status ที่คืนมาเพื่อตัดสินว่าต้องคืนสต็อกไหม
     *    (waiting ไม่ได้กินสต็อก จึงไม่มีอะไรให้คืน)
     *
     * ✅ Use case: ReservationService::cancelReservation()
     */
    public function findActiveForUpdate(int $id, ?int $userId = null): ?array
    {
        $sql = "SELECT * FROM reservations WHERE id = ? AND status IN ('waiting','pending') FOR UPDATE";
        $params = [$id];

        if ($userId !== null) {
            $sql = "SELECT * FROM reservations WHERE id = ? AND user_id = ? AND status IN ('waiting','pending') FOR UPDATE";
            $params = [$id, $userId];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: Lock reservation pending + SELECT FOR UPDATE
     * ==========================================================================
     * ป้องกัน: double approve / cancel หลัง approve
     *
     * 📥 Input:
     * @param int      $id     Reservation ID
     * @param int|null $userId ถ้าระบุ → กรองว่าเป็นของ user นี้ (member cancel)
     *
     * 📤 Output: @return array|null reservation row (ถูก lock) หรือ null
     *
     * 🛡️ Security:
     * - FOR UPDATE = row lock
     * - AND status='pending' = กรองเฉพาะที่ยัง pending
     * - $userId เพิ่มเพื่อให้ member ยกเลิกได้เฉพาะของตัวเอง
     *
     * ✅ Use case: ReservationService::fulfillReservation(),
     *            ReservationService::cancelReservation()
     */
    public function findPendingForUpdate(int $id, ?int $userId = null): ?array
    {
        // 📝 SQL: SELECT + FOR UPDATE = ดึง + ล็อกแถว
        //    เฉพาะ pending เท่านั้น (fulfilled/cancelled ไม่คืน)
        // 🛡️ FOR UPDATE = row lock ป้องกัน double approve / cancel after approve
        // ⚠️ ต้องเรียกใน transaction!
        $sql = "SELECT * FROM reservations WHERE id = ? AND status = 'pending' FOR UPDATE";
        $params = [$id];

        // 👤 ถ้าระบุ $userId → เพิ่มเงื่อนไขว่าเป็นของ user นี้
        //    ใช้ตอน member ยกเลิกการจองของตัวเอง (ป้องกันยกเลิกของคนอื่น)
        if ($userId !== null) {
            $sql = "SELECT * FROM reservations WHERE id = ? AND user_id = ? AND status = 'pending' FOR UPDATE";
            $params = [$id, $userId];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        // 📤 คืน reservation row (ถูกล็อก) หรือ null
        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: Lock รายการหมดอายุ + SELECT FOR UPDATE (batch expire)
     * ==========================================================================
     *
     * 📤 Output: @return array[] [{id, book_id}, ...] (ถูก lock)
     * ✅ Use case: batch expire process ใน transaction
     */
    public function findExpiredForUpdate(): array
    {
        // 📝 SQL: ดึงรายการหมดอายุ + lock ทั้งหมดพร้อมกัน
        // 🛡️ FOR UPDATE = ล็อกทุกแถวที่คืน — ป้องกัน expire ซ้ำจาก 2 process
        // ⚠️ ต้องเรียกใน transaction!
        $stmt = $this->pdo->prepare("
            SELECT id, book_id FROM reservations 
            WHERE status = 'pending' AND expires_at < NOW()
            FOR UPDATE
        ");
        $stmt->execute();
        // 📤 คืน [{id, book_id}, ...] (ถูกล็อก)
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงการจองของ user (auto-expire ก่อน query)
     * ==========================================================================
     *
     * 📥 Input:
     * @param int         $userId
     * @param string|null $status กรอง (null = ทั้งหมด)
     *
     * 📤 Output: @return array [{reservation row + book_title, book_author}, ...]
     *
     * 🧠 เหตุผล: เรียก markExpiredReservations() ก่อน (lazy expire)
     * ✅ Use case: my_reservations.php
     */
    public function findByUser(int $userId, ?string $status = null): array
    {
        // 🔄 [LAZY EXPIRE] expire รายการหมดอายุก่อนจะ query
        $this->markExpiredReservations();
        
        // 📝 SQL: ดึงการจองของ user + JOIN book
        $sql = "
            SELECT r.*, b.title as book_title, b.author as book_author
            FROM reservations r
            JOIN books b ON r.book_id = b.id
            WHERE r.user_id = ?
        ";
        $params = [$userId];

        // 🏷️ ถ้าระบุ status → กรองเพิ่ม (null = ทุก status)
        if ($status) {
            $sql .= " AND r.status = ?";
            $params[] = $status;
        }

        // เรียงจากใหม่สุดก่อน
        $sql .= " ORDER BY r.created_at DESC, r.id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        // 📤 คืน array การจองของ user + ข้อมูลหนังสือ
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงรายการ pending (สำหรับ admin dashboard)
     * ==========================================================================
     *
     * 📥 Input: @param int $limit (default: 10)
     * 📤 Output: @return array [{reservation row + user_name, user_phone, book_title}, ...]
     * ✅ Use case: admin/index.php → DashboardService → "รายการรอดำเนินการ"
     */
    public function findPending(int $limit = 10): array
    {
        // 📝 SQL: ดึง pending reservations + user + book
        // ORDER BY created_at ASC = เก่าสุดก่อน (คนที่จองก่อนขึ้นก่อน)
        // LIMIT ? = จำกัดจำนวน (default: 10 สำหรับ Dashboard)
        $stmt = $this->pdo->prepare("
            SELECT r.*, u.name as user_name, u.phone as user_phone,
                   b.title as book_title
            FROM reservations r
            JOIN users u ON r.user_id = u.id
            JOIN books b ON r.book_id = b.id
            WHERE r.status = 'pending'
            ORDER BY r.created_at ASC, r.id ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        // 📤 คืน array รายการรอดำเนินการ → Dashboard
        return $stmt->fetchAll();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับ pending reservations ของหนังสือ (ตรวจก่อนลบ)
     * ==========================================================================
     *
     * 📥 Input: @param int $bookId
     * 📤 Output: @return int จำนวนที่รอ pending
     * ✅ Use case: BookService::deleteBook() เช็คก่อนลบ
     */
    public function countPendingByBook(int $bookId): int
    {
        // 📝 SQL: นับเฉพาะ pending — คือการจองที่ **กินสต็อก** อยู่จริง
        // 🔴 ห้ามเพิ่ม waiting เข้ามาที่เมธอดนี้
        //    ถ้าต้องการ "มีใครรอเล่มนี้อยู่ไหม" ให้ใช้ countActiveByBook() แทน
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM reservations 
            WHERE book_id = ? AND status = 'pending'
        ");
        $stmt->execute([$bookId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: เข้าคิวรอหนังสือที่ถูกยืมหมด (ไม่แตะสต็อก)
     * ==========================================================================
     *
     * 📥 Input: @param int $userId, @param int $bookId
     * 📤 Output: @return int Reservation ID
     *
     * ⚠️ **ไม่หัก available** — คิวรอไม่กินสต็อก เพราะหนังสือยังอยู่กับคนอื่น
     *    ต่างจาก create() ที่เป็นการจองเล่มที่ว่างอยู่แล้ว จึงต้องกันเล่มไว้
     * ⚠️ **expires_at = NULL** — คิวรอไม่มีวันหมดอายุ (ตกลงไว้ใน ROADMAP)
     *    หนังสือดังอาจรอเป็นเดือน ถ้าให้หมดอายุคนต้องมากดใหม่เรื่อย ๆ ซึ่งไร้ประโยชน์
     *
     * ✅ Use case: ReservationService::joinQueue()
     */
    public function createWaiting(int $userId, int $bookId): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO reservations (user_id, book_id, status, queued_at, expires_at)
            VALUES (?, ?, 'waiting', NOW(), NULL)
        ");
        $stmt->execute([$userId, $bookId]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ล็อกคิวคนถัดไปของหนังสือเล่มนี้ (FOR UPDATE)
     * ==========================================================================
     *
     * 📥 Input: @param int $bookId
     * 📤 Output: @return array|null แถวคิวคนแรก หรือ null ถ้าไม่มีใครรอ
     *
     * 🧠 เรียงด้วย COALESCE(queued_at, created_at) แล้วค่อย id
     *    - COALESCE เพราะแถวที่สร้างก่อน migration ไม่มี queued_at
     *    - ต่อท้ายด้วย id เพราะ 2 คนอาจเข้าคิววินาทีเดียวกัน ต้องมีตัวตัดสินที่แน่นอน
     *      ไม่งั้นลำดับคิวจะสลับไปมาได้ทุกครั้งที่ query
     *
     * 🔒 FOR UPDATE — ต้องเรียกใน transaction เดียวกับการคืนหนังสือเสมอ
     *    ไม่งั้นจะเกิดช่วงที่ available = 1 แล้วคนอื่นชิงยืมไปก่อนคนที่รอคิว
     *
     * ✅ Use case: ReservationService::promoteNextInQueue()
     */
    public function findNextInQueueForUpdate(int $bookId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM reservations
            WHERE book_id = ? AND status = 'waiting'
            ORDER BY COALESCE(queued_at, created_at) ASC, id ASC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$bookId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: เลื่อนคิวเป็น "ของพร้อมแล้ว" (waiting → pending)
     * ==========================================================================
     *
     * 📥 Input: @param int $reservationId, @param string $expiresAt
     * 📤 Output: @return bool true = เลื่อนสำเร็จ
     *
     * 🛡️ WHERE status = 'waiting' → กันเลื่อนซ้ำแม้ยิงพร้อมกัน
     *    สำคัญมาก เพราะผู้เรียกจะหัก available ตามผลลัพธ์นี้
     *    ถ้าเลื่อนซ้ำได้ สต็อกจะถูกหัก 2 ครั้งสำหรับเล่มเดียว
     *
     * ⚠️ **ไม่แตะ available ที่นี่** — ให้ Service เป็นคนหักในทรานแซกชันเดียวกัน
     *    เพื่อให้เห็นชัดว่าสต็อกถูกแตะที่ไหนบ้าง (Repository ตัวอื่นก็ทำแบบนี้)
     *
     * ✅ Use case: ReservationService::promoteNextInQueue()
     */
    public function promoteToPending(int $reservationId, string $expiresAt): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE reservations
            SET status = 'pending', expires_at = ?
            WHERE id = ? AND status = 'waiting'
        ");
        $stmt->execute([$expiresAt, $reservationId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: เลื่อนคิวคนถัดไป + กันเล่มไว้ให้ — **ที่เดียวในระบบ**
     * ==========================================================================
     *
     * 📥 Input: @param int $bookId, @param string $expiresAt
     * 📤 Output: @return array|null ข้อมูลคนที่ถูกเลื่อน หรือ null ถ้าไม่มีใครรอ
     *
     * 🔴 **ต้องเรียกใน transaction ที่เปิดไว้แล้วเท่านั้น** — ไม่เปิด/ปิดเอง
     *    เพราะต้องอยู่ทรานแซกชันเดียวกับเหตุการณ์ที่ทำให้หนังสือว่าง
     *    ไม่งั้นจะมีช่วงที่ available > 0 แล้วคนนอกคิวชิงยืมไปก่อน
     *
     * 🧠 **ทำไมอยู่ที่ Repository ไม่ใช่ Service**
     *    markExpiredReservations() ซึ่งอยู่ชั้น Repository ต้องใช้ตัวนี้ด้วย
     *    (มันเป็น lazy expire ที่ถูกเรียกจาก 8 ที่ทั่วระบบ)
     *    ถ้าเขียนแยกไว้ที่ Service ชั้น Repository จะต้องมีสำเนาของตรรกะเดียวกัน
     *    ซึ่งวันหนึ่งจะแก้ไม่ครบทั้งสองที่ — จึงรวมไว้ที่นี่แล้วให้ Service เรียกใช้
     *    (ตัวไฟล์นี้ยุ่งกับตาราง books อยู่แล้วตั้งแต่ markExpiredReservations)
     *
     * ⚠️ ถ้าหัก available ไม่สำเร็จ = โยน exception ให้ทรานแซกชัน rollback
     *    ห้ามปล่อยผ่าน ไม่งั้นจะได้ pending ที่ไม่มีเล่มรองรับ
     */
    public function promoteNextInQueue(int $bookId, string $expiresAt): ?array
    {
        // 🔒 ล็อกแถวคิวคนแรก — กัน 2 เหตุการณ์พร้อมกันเลื่อนคนเดียวกัน 2 รอบ
        $next = $this->findNextInQueueForUpdate($bookId);
        if (!$next) {
            return null;
        }

        if (!$this->promoteToPending((int) $next['id'], $expiresAt)) {
            // 🛡️ มีคนเลื่อนไปแล้วระหว่างที่เรากำลังทำ → ห้ามหัก available ซ้ำ
            return null;
        }

        // 📦 กันเล่มไว้ให้คนในคิว
        // 🛡️ WHERE available > 0 = ด่านเดียวกับ BookRepository::decrementAvailable()
        $stock = $this->pdo->prepare("
            UPDATE books SET available = available - 1
            WHERE id = ? AND available > 0
        ");
        $stock->execute([$bookId]);

        if ($stock->rowCount() === 0) {
            throw new \Exception('เลื่อนคิวไม่สำเร็จ — ไม่มีเล่มว่างให้กันไว้สำหรับคิวถัดไป');
        }

        return [
            'reservation_id' => (int) $next['id'],
            'user_id'        => (int) $next['user_id'],
            'expires_at'     => $expiresAt,
        ];
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดูว่าอยู่คิวที่เท่าไรของหนังสือเล่มนี้
     * ==========================================================================
     *
     * 📥 Input: @param int $reservationId
     * 📤 Output: @return int ลำดับคิว (1 = คนถัดไป) หรือ 0 ถ้าไม่ได้อยู่ในคิว
     *
     * 🧠 นับว่ามีคนเข้าคิวก่อนหน้ากี่คน แล้ว +1
     *    ใช้เกณฑ์เรียงชุดเดียวกับ findNextInQueueForUpdate() เป๊ะ
     *    ไม่งั้นเลขที่โชว์ให้สมาชิกดูจะไม่ตรงกับลำดับที่ระบบเลื่อนจริง
     *
     * ✅ Use case: book.php, my_reservations.php
     */
    public function getQueuePosition(int $reservationId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) + 1
            FROM reservations r
            JOIN reservations me ON me.id = ?
            WHERE r.book_id = me.book_id
              AND r.status = 'waiting'
              AND (
                    COALESCE(r.queued_at, r.created_at) < COALESCE(me.queued_at, me.created_at)
                 OR (COALESCE(r.queued_at, r.created_at) = COALESCE(me.queued_at, me.created_at) AND r.id < me.id)
              )
              AND me.status = 'waiting'
        ");
        $stmt->execute([$reservationId]);
        $row = $stmt->fetch(PDO::FETCH_NUM);

        return $row ? (int) $row[0] : 0;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับจำนวนคนที่ต่อคิวรอหนังสือเล่มนี้
     * ==========================================================================
     * ✅ Use case: book.php แสดง "มีคนรออยู่ N คน"
     */
    public function countWaitingByBook(int $bookId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM reservations WHERE book_id = ? AND status = 'waiting'
        ");
        $stmt->execute([$bookId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับการจองที่ยัง "มีชีวิต" ของหนังสือ — รวมคิวรอด้วย
     * ==========================================================================
     *
     * 📥 Input: @param int $bookId
     * 📤 Output: @return int จำนวน waiting + pending
     *
     * 🧠 **ต่างจาก countPendingByBook() ตรงไหน**
     *    countPendingByBook() ตอบว่า "กันสต็อกไว้กี่เล่ม" → ใช้กับสูตรสต็อก
     *    เมธอดนี้ตอบว่า "มีคนรอเล่มนี้อยู่ไหม" → ใช้กับด่านที่ห้ามทำอะไรกับเล่มนั้น
     *    สองคำถามนี้ให้คำตอบต่างกันตั้งแต่มีคิวรอ ห้ามใช้สลับกัน
     *
     * ✅ Use case: BookService::deleteBook() (ห้ามลบเล่มที่มีคนรอ)
     *              BorrowService::renewBorrow() (ห้ามต่ออายุถ้ามีคนรอ)
     */
    public function countActiveByBook(int $bookId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM reservations 
            WHERE book_id = ? AND status IN ('waiting', 'pending')
        ");
        $stmt->execute([$bookId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับ pending reservations ของ user (ตรวจก่อนลบสมาชิก)
     * ==========================================================================
     *
     * 📥 Input: @param int $userId
     * 📤 Output: @return int จำนวนที่รอ pending
     * ✅ Use case: MemberService::deleteMember() เช็คก่อนลบ
     */
    public function countPendingByUser(int $userId): int
    {
        // 📝 SQL: นับเฉพาะ pending — คือการจองที่จะกลายเป็นการยืม
        // 🔴 ห้ามเพิ่ม waiting เข้ามาที่เมธอดนี้ — เมธอดนี้ใช้คิด **โควตา**
        //    และกติกาที่ตกลงไว้คือ "คิวรอไม่กินโควตา"
        //    (ถ้ากิน คนที่เข้าคิวรอ 3 เล่มจะยืมหนังสือที่วางบนชั้นตรงหน้าไม่ได้
        //     ทั้งที่ยังไม่ได้ถืออะไรเลย)
        //    ถ้าต้องการ "มีการจองค้างอยู่ไหม" ให้ใช้ countActiveByUser() แทน
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM reservations 
            WHERE user_id = ? AND status = 'pending'
        ");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับการจองที่ยัง "มีชีวิต" ของสมาชิก — รวมคิวรอด้วย
     * ==========================================================================
     * 📥 Input: @param int $userId
     * 📤 Output: @return int จำนวน waiting + pending
     * ✅ Use case: MemberService::deleteMember() (ห้ามลบคนที่ยังมีคิวค้าง)
     */
    public function countActiveByUser(int $userId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM reservations 
            WHERE user_id = ? AND status IN ('waiting', 'pending')
        ");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับคิวรอของสมาชิก (ใช้จำกัดจำนวนคิวต่อคน)
     * ==========================================================================
     * 📥 Input: @param int $userId
     * 📤 Output: @return int จำนวน waiting
     * ✅ Use case: ReservationService::joinQueue() — จำกัดคิวต่อคน
     */
    public function countWaitingByUser(int $userId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'waiting'
        ");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ตรวจว่า user จองหนังสือนี้อยู่แล้วหรือไม่ (ป้องกันจองซ้ำ)
     * ==========================================================================
     *
     * 📥 Input: @param int $userId, @param int $bookId
     * 📤 Output: @return bool true = มี pending อยู่แล้ว (ห้ามจองซ้ำ)
     * ✅ Use case: ReservationService::createReservation() เช็คก่อนจอง
     */
    public function hasPending(int $userId, int $bookId): bool
    {
        // 📝 SQL: ตรวจว่า user มีการจองเล่มนี้ค้างอยู่ไหม — **รวมคิวรอด้วย**
        // 🧠 ใช้เป็น guard ก่อนสร้างการจองใหม่ — ป้องกันจองซ้ำ
        // 🔴 ต้องรวม waiting ไม่งั้นคนที่เข้าคิวอยู่จะกดจองซ้ำเล่มเดิมได้
        //    (และจะไปชน UNIQUE uq_reservation_active ที่ระดับ DB แทน ซึ่งข้อความ error อ่านไม่รู้เรื่อง)
        $stmt = $this->pdo->prepare("
            SELECT id FROM reservations 
            WHERE user_id = ? AND book_id = ? AND status IN ('waiting', 'pending')
        ");
        $stmt->execute([$userId, $bookId]);
        // 📤 fetch() !== false = มี pending อยู่ (true → ห้ามจองซ้ำ)
        return $stmt->fetch() !== false;
    }
}


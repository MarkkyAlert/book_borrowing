<?php

namespace App\Repositories;

use PDO;

/**
 * ==========================================================================
 * 📅 ClosedDayRepository — วันที่ห้องสมุดไม่เปิดทำการ
 * ==========================================================================
 *
 * 🎯 ใช้หักออกจากการคิดค่าปรับ — ยืมคร่อมวันหยุดยาวแล้วโดนปรับ
 *    ทั้งที่ไม่มีวันไหนให้มาคืนได้เลย
 *
 * 📋 โครงสร้างตาราง `closed_days`
 * | คอลัมน์      | ชนิด         | ความหมาย                                   |
 * |-------------|-------------|-------------------------------------------|
 * | start_date  | DATE        | วันแรกที่ปิด                                |
 * | end_date    | DATE        | วันสุดท้ายที่ปิด (วันเดียว = เท่ากับ start)    |
 * | note        | VARCHAR(255)| เหตุผล                                     |
 *
 * ✅ Use case:
 *   BorrowService::calculateFine()  → หักวันปิดออกจากวันที่เกินกำหนด
 *   admin/settings.php              → หน้าจัดการวันปิด
 */
class ClosedDayRepository
{
    private PDO $pdo;

    /**
     * 🗓️ cache ของวันปิดทั้งหมด (เป็น 'Y-m-d' => true)
     *
     * 🔴 [PERFORMANCE] จำเป็นจริง ไม่ใช่ optimize ก่อนเวลา
     *    `admin/borrows.php` เรียก calculateFine() **ทีละแถว** สูงสุด 20 ครั้ง/หน้า
     *    ถ้าแต่ละครั้งยิง query จะกลายเป็น 20 query ต่อการโหลด 1 หน้า
     *    โหลดครั้งเดียวต่อ request แล้วใช้ซ้ำ
     *
     * ⚠️ เป็น cache ระดับ request เท่านั้น — ไม่ข้าม request
     *    เจ้าหน้าที่แก้วันปิดแล้วโหลดหน้าใหม่จะเห็นผลทันที
     */
    private ?array $dayCache = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * 🎯 ดึงช่วงวันปิดทั้งหมด (เรียงตามวันที่)
     * 📤 @return array [{id, start_date, end_date, note}, ...]
     */
    public function findAll(): array
    {
        return $this->pdo->query("
            SELECT id, start_date, end_date, note
            FROM closed_days
            ORDER BY start_date DESC, id DESC
        ")->fetchAll();
    }

    /**
     * 🎯 เพิ่มช่วงวันปิด
     *
     * 📥 @param string $start 'Y-m-d', @param string $end 'Y-m-d', @param string $note
     * 📤 @return int id ที่สร้าง
     *
     * ⚠️ ผู้เรียกต้อง validate ว่า $start <= $end มาแล้ว (ดู admin/settings.php)
     *    Repository ไม่ตัดสินกฎธุรกิจ
     */
    public function create(string $start, string $end, string $note): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO closed_days (start_date, end_date, note) VALUES (?, ?, ?)
        ");
        $stmt->execute([$start, $end, $note]);
        $this->dayCache = null;   // 🔄 cache เก่าใช้ไม่ได้แล้ว
        return (int) $this->pdo->lastInsertId();
    }

    /** 🎯 ลบช่วงวันปิด */
    public function delete(int $id): bool
    {
        $ok = $this->pdo->prepare("DELETE FROM closed_days WHERE id = ?")->execute([$id]);
        $this->dayCache = null;
        return $ok;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: นับว่าในช่วง (เริ่ม, จบ] มีวันที่ห้องสมุดปิดกี่วัน
     * ==========================================================================
     *
     * 📥 Input:
     * @param string $afterDate  วันที่ **ไม่นับ** (วันครบกำหนด — วันนั้นยังไม่ถือว่าสาย)
     * @param string $untilDate  วันที่ **นับ** (วันที่คืนจริง)
     *
     * 📤 Output: @return int จำนวนวันปิดในช่วงนั้น
     *
     * 🧠 ทำไมช่วงเป็น (เริ่ม, จบ] ไม่ใช่ [เริ่ม, จบ]
     *    สูตรค่าปรับเดิมนับ "จำนวนวันที่เลยกำหนด" = วันครบกำหนดไม่ถูกนับ
     *    ตัวนี้ต้องนับช่วงเดียวกันเป๊ะ ไม่งั้นหักเกิน/ขาดไป 1 วัน
     *
     * 🧠 ใช้ cache รายวัน แทนการคำนวณจากช่วงโดยตรง
     *    เพราะช่วงวันปิดซ้อนทับกันได้ (เช่น "ปิดปรับปรุง 1-30 มิ.ย." กับ "วันหยุด 5 มิ.ย.")
     *    ถ้าบวกความยาวของแต่ละช่วงตรง ๆ วันที่ซ้อนกันจะถูกนับสองครั้ง
     */
    public function countClosedDaysBetween(string $afterDate, string $untilDate): int
    {
        $days = $this->closedDaySet();
        if (!$days) return 0;

        $cursor = new \DateTime($afterDate);
        $end    = new \DateTime($untilDate);
        $count  = 0;

        // 🛡️ กันลูปไม่รู้จบถ้าได้วันที่กลับด้านมา
        if ($cursor >= $end) return 0;

        while (true) {
            $cursor->modify('+1 day');
            if ($cursor > $end) break;
            if (isset($days[$cursor->format('Y-m-d')])) $count++;
        }
        return $count;
    }

    /**
     * 🗓️ กาง "ช่วงวันปิด" ออกเป็นเซ็ตของวัน เพื่อไม่ให้ช่วงที่ซ้อนกันถูกนับซ้ำ
     *
     * ⚠️ ช่วงที่ยาวมากจะกินหน่วยความจำตามจำนวนวัน — จำกัดไว้ที่ 5 ปีต่อช่วง
     *    ห้องสมุดไม่มีเหตุผลจะปิดยาวกว่านั้น และเป็นการกันข้อมูลผิดพลาด
     *    (เช่น กรอกปีผิดเป็น 2926) ไม่ให้ทำให้ทั้งระบบค้าง
     */
    private function closedDaySet(): array
    {
        if ($this->dayCache !== null) return $this->dayCache;

        $this->dayCache = [];
        foreach ($this->findAll() as $row) {
            $cursor = new \DateTime($row['start_date']);
            $end    = new \DateTime($row['end_date']);
            if ($cursor > $end) continue;   // ข้อมูลกลับด้าน — ข้ามไป ไม่ทำให้ระบบพัง

            $guard = 0;
            while ($cursor <= $end && $guard < 1826) {   // 1826 = 5 ปี
                $this->dayCache[$cursor->format('Y-m-d')] = true;
                $cursor->modify('+1 day');
                $guard++;
            }
        }
        return $this->dayCache;
    }
}

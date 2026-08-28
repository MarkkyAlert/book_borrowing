<?php

/**
 * Business Rules — กฎการยืม-คืนที่ลูกค้าแก้เองได้
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * นิยาม constant ของ "กฎห้องสมุด" (ค่าปรับ / วันยืม / โควตา / วันหมดอายุการจอง)
 * โดยอ่านค่าเรียงตาม 3 ชั้น:
 *
 *     1️⃣ ตาราง settings   ← ลูกค้าแก้เองได้จากหน้า "ตั้งค่าระบบ"
 *     2️⃣ ไฟล์ .env         ← ค่าที่ผู้ติดตั้งกำหนด (ชั้นสำรอง)
 *     3️⃣ ค่า default       ← ในไฟล์นี้ กันระบบพังถ้าไม่มีทั้งสองชั้นบน
 *
 * 🧠 ทำไมไม่อยู่ใน config.php เหมือนค่าอื่น:
 *    `bootstrap.php` โหลด config → db → functions ตามลำดับ (ห้ามสลับ)
 *    ตอน config.php ทำงาน **ยังต่อฐานข้อมูลไม่ได้** จึงอ่านตาราง settings ไม่ได้
 *    ไฟล์นี้จึงถูก require ท้าย functions.php ซึ่งเป็นจุดที่ getDB() พร้อมใช้แล้ว
 *    (ทั้งเส้นทาง bootstrap และสคริปต์ CLI ที่ require config → db → functions เหมือนกัน)
 *
 * 🔴 [ข้อบังคับ] ไฟล์นี้ต้องไม่ require db.php เอง
 *    เพราะ `install.php` โหลดแค่ config + functions (ตอนนั้นฐานข้อมูลยังไม่มี)
 *    ถ้าไปบังคับต่อฐานข้อมูล ลูกค้าจะติดตั้งระบบไม่ได้เลย
 *    → เช็คด้วย function_exists('getDB') ก่อนเสมอ
 *
 * ⚙️ เพิ่มกฎใหม่: เพิ่ม 1 แถวใน ruleDefinitions() แล้วจบ
 *    หน้า admin/settings.php สร้างฟอร์มและตรวจค่าจากตารางนี้ให้เอง
 */

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ทะเบียนกฎทั้งหมด — แหล่งความจริงแหล่งเดียว
 * ==========================================================================
 * ใช้ร่วมกัน 2 ที่: ไฟล์นี้ (นิยาม constant) และ admin/settings.php (ฟอร์ม + ตรวจค่า)
 * 📌 min/max อยู่ที่เดียว จะได้ไม่มีทางที่ฟอร์มยอมรับค่าที่ระบบใช้ไม่ได้
 *
 * @return array<string, array{setting:string, env:string, default:int, min:int, max:int, label:string, unit:string, help:string}>
 */
function ruleDefinitions(): array
{
    return [
        'DEFAULT_BORROW_DAYS' => [
            'setting' => 'rule_borrow_days',
            'env'     => 'DEFAULT_BORROW_DAYS',
            'default' => 7,
            'min'     => 1,
            'max'     => 365,
            'label'   => 'จำนวนวันยืมเริ่มต้น',
            'unit'    => 'วัน',
            'help'    => 'ใช้เป็นค่าตั้งต้นตอนบันทึกการยืม เจ้าหน้าที่ปรับเป็นรายครั้งได้',
        ],
        'MAX_BORROW_BOOKS' => [
            'setting' => 'rule_max_books',
            'env'     => 'MAX_BORROW_BOOKS',
            'default' => 3,
            'min'     => 1,
            'max'     => 100,
            'label'   => 'ยืมได้สูงสุดต่อคน',
            'unit'    => 'เล่ม',
            'help'    => 'นับรวมทั้งที่ยืมอยู่และที่จองไว้',
        ],
        'FINE_PER_DAY' => [
            'setting' => 'rule_fine_per_day',
            'env'     => 'FINE_PER_DAY',
            'default' => 10,
            'min'     => 0,          // 📝 0 = ไม่คิดค่าปรับ — เป็นค่าที่ใช้ได้จริง ไม่ใช่ค่าผิด
            'max'     => 10000,
            'label'   => 'ค่าปรับต่อวัน',
            'unit'    => 'บาท',
            'help'    => 'คิดตอนคืนหนังสือ นับทุกวันตามปฏิทิน (ยังไม่มีตารางวันหยุดทำการ)',
        ],
        'MAX_RENEW_COUNT' => [
            'setting' => 'rule_max_renew',
            'env'     => 'MAX_RENEW_COUNT',
            'default' => 1,
            'min'     => 0,          // 📝 0 = ปิดการต่ออายุทั้งระบบ
            'max'     => 10,
            'label'   => 'ต่ออายุการยืมได้',
            'unit'    => 'ครั้ง',
            'help'    => 'ต่อได้เฉพาะตอนยังไม่เกินกำหนด และต้องไม่มีคนจองเล่มนั้นรออยู่ · ตั้ง 0 = ปิดการต่ออายุ',
        ],
        'LOST_BOOK_FEE' => [
            'setting' => 'rule_lost_book_fee',
            'env'     => 'LOST_BOOK_FEE',
            'default' => 0,
            'min'     => 0,
            'max'     => 100000,
            'label'   => 'ค่าดำเนินการหนังสือหาย',
            'unit'    => 'บาท',
            'help'    => 'บวกเพิ่มจากราคาปกตอนแจ้งหาย/ชำรุด · ตั้ง 0 = คิดเท่าราคาปกเฉย ๆ · ไม่คิดค่าปรับเกินกำหนดซ้ำ',
        ],
        'FINE_WAIVE_STAFF_LIMIT' => [
            'setting' => 'rule_waive_staff_limit',
            'env'     => 'FINE_WAIVE_STAFF_LIMIT',
            'default' => 200,
            'min'     => 0,          // 📝 0 = เจ้าหน้าที่ยกเว้นไม่ได้เลย ต้องให้ผู้ดูแลทำ
            'max'     => 100000,
            'label'   => 'เจ้าหน้าที่ยกเว้นค่าปรับได้ไม่เกิน',
            'unit'    => 'บาท',
            'help'    => 'เกินจำนวนนี้ต้องให้ผู้ดูแลระบบเป็นคนยกเว้น · ตั้ง 0 = เจ้าหน้าที่ยกเว้นไม่ได้เลย',
        ],
        'RESERVATION_EXPIRE_DAYS' => [
            'setting' => 'rule_reservation_days',
            'env'     => 'RESERVATION_EXPIRE_DAYS',
            'default' => 2,
            'min'     => 1,
            'max'     => 30,
            'label'   => 'จองแล้วต้องมารับภายใน',
            'unit'    => 'วัน',
            'help'    => 'เกินกำหนดแล้วหนังสือจะกลับขึ้นชั้นอัตโนมัติ',
        ],
    ];
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: อ่านค่ากฎที่ลูกค้าตั้งไว้จากตาราง settings (ทีเดียวทั้งก้อน)
 * ==========================================================================
 * 🧠 อ่านครั้งเดียวต่อ request แล้วเก็บใน static — ไม่ยิงทีละ key
 *
 * 🛡️ ต้องไม่ทำให้ระบบพังในกรณีเหล่านี้:
 *    - ยังไม่ได้ติดตั้ง (install.php โหลดแค่ config + functions ยังไม่มี getDB)
 *    - ตาราง settings ยังไม่ถูกสร้าง (ตอนรัน migrate ครั้งแรก)
 *    ทั้งสองกรณีให้คืน array ว่าง แล้วปล่อยให้ตกไปใช้ .env
 *
 * @return array<string, string>
 */
function loadRuleOverrides(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];

    // 📝 ยังไม่มี db.php = ยังไม่ถึงจุดที่ต่อฐานข้อมูลได้ → ใช้ .env ไปก่อน
    if (!function_exists('getDB')) {
        return $cache;
    }

    try {
        // 📝 ดึงทั้งตารางทีเดียว (ทั้งหมดไม่กี่สิบแถว) แล้วกรองใน PHP
        //    ไม่ใช้ LIKE 'rule_%' เพราะ `_` เป็น wildcard ของ LIKE ต้อง escape ซึ่งพลาดง่าย
        $rows = getDB()->query("SELECT setting_key, setting_value FROM settings")
                       ->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as $key => $value) {
            if (str_starts_with((string) $key, 'rule_')) {
                $cache[$key] = (string) $value;
            }
        }
    } catch (\Throwable $e) {
        // 📝 ตารางยังไม่มี / query พัง → เงียบไว้แล้วใช้ .env
        //    ⚠️ ห้าม rethrow — ไม่งั้นหน้าเว็บตายก่อนถึงหน้า 503 ที่ db.php เตรียมไว้
        $cache = [];
    }

    return $cache;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: หาค่าที่จะใช้จริงของกฎหนึ่งข้อ ตามลำดับ settings → .env → default
 * ==========================================================================
 * 🛡️ ค่าที่อยู่นอกช่วง min–max จะถูกทิ้งแล้วตกไปชั้นถัดไป
 *    กันกรณีมีคนไปแก้ฐานข้อมูลตรง ๆ ใส่ค่าติดลบหรือค่ามหาศาล
 */
function resolveRuleValue(array $rule, array $overrides): int
{
    $inRange = static fn(int $v): bool => $v >= $rule['min'] && $v <= $rule['max'];

    // 1️⃣ ตาราง settings
    $fromDb = $overrides[$rule['setting']] ?? null;
    if ($fromDb !== null && $fromDb !== '' && ctype_digit((string) $fromDb)) {
        $value = (int) $fromDb;
        if ($inRange($value)) {
            return $value;
        }
    }

    // 2️⃣ ไฟล์ .env
    $fromEnv = env($rule['env'], null);
    if ($fromEnv !== null && $fromEnv !== '' && ctype_digit((string) $fromEnv)) {
        $value = (int) $fromEnv;
        if ($inRange($value)) {
            return $value;
        }
    }

    // 3️⃣ ค่า default ในโค้ด
    return $rule['default'];
}

// ============================================================
// นิยาม constant ทั้งหมด
// ============================================================
// 📝 defined() guard — เผื่อมีเทสต์หรือสคริปต์ที่ define ไว้เองก่อนแล้ว
$ruleOverrides = loadRuleOverrides();
foreach (ruleDefinitions() as $constant => $rule) {
    if (!defined($constant)) {
        define($constant, resolveRuleValue($rule, $ruleOverrides));
    }
}
unset($ruleOverrides, $constant, $rule);

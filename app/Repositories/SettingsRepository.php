<?php
/**
 * SettingsRepository - Database Access สำหรับ Settings
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * Repository นี้จัดการ "settings ที่ admin ปรับได้ผ่านหน้าเว็บ" เช่น ชื่อหน่วยงาน, สีบัตรสมาชิก
 * เก็บเป็น key-value pairs ในตาราง settings
 *
 * ⚠️ สำคัญ: ไฟล์นี้คนละสับกับ .env config:
 * - .env / config.php = ค่าระบบ (DB host, app URL, session timeout) — แก้โดย dev
 * - settings table   = ค่าที่ admin ปรับได้ (ชื่อหน่วยงาน, สีบัตร) — แก้ผ่านหน้า admin
 *
 * 📚 โครงสร้างตาราง settings:
 * +---------------+-------------------+--------------------------------------+
 * | Column        | Type              | อธิบาย                               |
 * +---------------+-------------------+--------------------------------------+
 * | setting_key   | VARCHAR PK UNIQUE | ชื่อ key เช่น "org_name"             |
 * | setting_value | TEXT              | ค่า เช่น "ห้องสมุด XYZ"             |
 * +---------------+-------------------+--------------------------------------+
 *
 * 📍 Entrypoints:
 * - admin/settings.php → get(), set(), all()
 * - admin/member_card.php → get() (ดึงสีบัตร)
 * - includes/functions.php → getSetting() helper → get()
 *
 * 🏗️ สถาปัตยกรรม:
 * Controller (admin/settings.php) → Repository นี้โดยตรง (ไม่ผ่าน Service)
 * เพราะ logic ง่ายมาก (แค่ get/set key-value) ไม่ต้องการ Service layer
 *
 * @package App\Repositories
 */

namespace App\Repositories;

use PDO;

class SettingsRepository
{
    // 🗄️ PDO connection — inject ผ่าน constructor ใช้ร่วมกันทุกเมธอด
    private PDO $pdo;

    // 🏗️ Constructor: รับ PDO จากภายนอก (Dependency Injection)
    // → ใช้ connection เดียวกับ Controller ที่เรียก
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึงค่า setting ตาม key (พร้อมค่า default ถ้าไม่พบ)
     * ==========================================================================
     * เมธอดหลักที่ใช้บ่อยที่สุด — ดึงค่า setting ตัวเดียว
     *
     * 🔄 Flow: SELECT setting_value WHERE setting_key = ? → คืนค่า หรือ $default
     *
     * 📥 Input:
     * @param string $key     ชื่อ setting เช่น "org_name", "card_color_primary"
     *                        - มาจาก: admin/settings.php, includes/functions.php::getSetting()
     * @param mixed  $default ค่าเริ่มต้นถ้าไม่พบ key นี้ใน DB (default: '')
     *
     * 📤 Output:
     * @return mixed ค่า setting_value หรือ $default
     *               - ใช้ต่อ: แสดงใน form, ใช้ใน member_card, ใช้ใน header
     *
     * 🧠 เหตุผลเชิงออกแบบ:
     * - ทำไมมี $default? เพราะครั้งแรกที่ติดตั้ง ยังไม่มีค่าใน DB — ต้องมี fallback
     * - fetchColumn() คืน false ถ้าไม่พบ — เลยเช็ค !== false
     *
     * 🛡️ Security: prepared statement ป้องกัน SQL Injection
     * ⚠️ Edge case: key ไม่มีอยู่ → คืน $default (ไม่ error)
     *
     * ✅ Use case: getSetting('org_name', 'ห้องสมุด') → คืนชื่อหน่วยงาน
     */
    public function get(string $key, mixed $default = ''): mixed
    {
        // 📝 SQL: ดึงค่า setting_value ตาม key
        $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        // 📌 fetchColumn() คืนค่า column แรกของ row แรก
        //    ถ้าไม่เจอ key → คืน false
        $result = $stmt->fetchColumn();

        // 📤 ถ้าเจอ → คืนค่า | ไม่เจอ → คืน $default
        //    ป้องกันระบบพังเพราะค่ายังไม่มีใน DB
        return $result !== false ? $result : $default;
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: บันทึกค่า setting (upsert: มีแล้ว update / ยังไม่มี insert)
     * ==========================================================================
     * เมธอดนี้ใช้ MySQL "ON DUPLICATE KEY UPDATE" — ไม่ต้องเช็คก่อนว่ามีหรือยัง
     *
     * 🔄 Flow:
     * Step 1 → INSERT INTO settings (key, value) VALUES (?, ?)
     * Step 2 → ถ้า key ซ้ำ (UNIQUE) → ON DUPLICATE KEY UPDATE value = ?
     *
     * 📥 Input:
     * @param string $key   ชื่อ setting เช่น "org_name"
     *                      - มาจาก: admin/settings.php (POST form)
     * @param mixed  $value ค่าที่ต้องการบันทึก เช่น "ห้องสมุด ABC"
     *
     * 📤 Output:
     * @return bool true = สำเร็จ
     *
     * 🧠 เหตุผลเชิงออกแบบ:
     * - ทำไมใช้ upsert? เพราะไม่ต้องเช็คก่อนว่ามีหรือยัง — ลด query 1 ครั้ง
     * - ทำไมส่ง $value 2 ครั้ง? เพราะ ON DUPLICATE KEY ต้องการค่าแยกต่างหาก
     *   (VALUES คือ $value สำหรับ INSERT, ตัวที่ 3 คือ $value สำหรับ UPDATE)
     *
     * 🛡️ Security: prepared statement ป้องกัน SQL Injection
     * ⚠️ Edge case: key ใหม่ → INSERT, key มีอยู่แล้ว → UPDATE — ไม่มี error ทั้ง 2 กรณี
     *
     * ✅ Use case: admin/settings.php POST → set('org_name', 'ห้องสมุด ABC')
     */
    public function set(string $key, mixed $value): bool
    {
        // 📝 SQL: Upsert (INSERT หรือ UPDATE ใน query เดียว)
        // 🧠 ON DUPLICATE KEY UPDATE:
        //    - key ใหม่ → INSERT (สร้าง row ใหม่)
        //    - key มีอยู่แล้ว → UPDATE setting_value (แก้ไขค่าเดิม)
        //    ไม่ต้องเช็คก่อนว่ามีหรือไม่มี → ลด query 1 ครั้ง
        $stmt = $this->pdo->prepare("
            INSERT INTO settings (setting_key, setting_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = ?
        ");
        // 🚀 bind: [$key, $value, $value]
        //    ส่ง $value 2 ครั้ง: ครั้งแรกสำหรับ INSERT, ครั้งที่ 2 สำหรับ UPDATE
        return $stmt->execute([$key, $value, $value]);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ลบ setting ตาม key
     * ==========================================================================
     *
     * 🔄 Flow: DELETE FROM settings WHERE setting_key = ?
     *
     * 📥 Input:
     * @param string $key ชื่อ setting ที่ต้องการลบ
     *
     * 📤 Output:
     * @return bool true = สำเร็จ
     *
     * 🛡️ Security: prepared statement
     * ⚠️ Edge case: key ไม่มีอยู่ → DELETE 0 rows แต่ execute() ยังคืน true
     *
     * ✅ Use case: ปัจจุบันยังไม่ได้ใช้ในระบบ (เตรียมไว้สำหรับอนาคต)
     */
    public function delete(string $key): bool
    {
        // 📝 SQL: ลบ setting ตาม key
        // ⚠️ key ไม่มีอยู่ → DELETE 0 rows แต่ execute() ยังคืน true (ไม่ error)
        // 📌 ปัจจุบันยังไม่ได้ใช้ในระบบ (เตรียมไว้สำหรับอนาคต)
        $stmt = $this->pdo->prepare("DELETE FROM settings WHERE setting_key = ?");
        return $stmt->execute([$key]);
    }

    /**
     * ==========================================================================
     * 🎯 จุดประสงค์: ดึง settings ทั้งหมดเป็น key-value map
     * ==========================================================================
     * ดึงทุก row แล้วแปลงเป็น associative array { key => value }
     *
     * 🔄 Flow: SELECT ทั้งตาราง → loop สร้าง map
     *
     * 📤 Output:
     * @return array<string, string> เช่น ['org_name' => 'ห้องสมุด ABC', 'card_color_primary' => '#1e3a8a']
     *         - ใช้ใน: admin/settings.php โหลดค่าทั้งหมดเข้า form
     *
     * ⚠️ Edge case: ตารางว่าง → คืน [] (ไม่ error)
     *
     * ✅ Use case: admin/settings.php GET → $settings = $repo->all()
     */
    public function all(): array
    {
        // 📝 SQL: ดึง settings ทั้งหมด
        // 🧠 ใช้ query() เพราะไม่มี user input
        $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM settings");
        // 🔄 แปลงจาก rows เป็น key-value map
        //    [{setting_key: 'org_name', setting_value: 'ห้องสมุด'}]
        //    → ['org_name' => 'ห้องสมุด']
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        // 📤 คืน associative array {key => value}
        return $settings;
    }
}

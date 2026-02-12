<?php
/**
 * Database Connection using PDO
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้ทำอะไร?
 * ==========================================================================
 * จัดการการเชื่อมต่อฐานข้อมูล MySQL ผ่าน PDO
 * ใช้ Singleton pattern — connection เดียวตลอด request (ประหยัด resource)
 *
 * 🏗️ สถาปัตยกรรม:
 * bootstrap.php → require db.php → getDB() พร้อมใช้ทั่วระบบ
 *
 * 📍 Entrypoints:
 * - ทุกที่ที่ต้องการ PDO → getDB()
 * - install.php → getDBWithoutDatabase()
 *
 * 🛡️ Security:
 * - EMULATE_PREPARES=false → native prepared statements (ป้องกัน SQL injection)
 * - production ซ่อน error details (ไม่โชว์ DSN/credentials)
 *
 * ⚠️ ห้ามแก้:
 * - EMULATE_PREPARES ต้องเป็น false เสมอ
 * - ERRMODE_EXCEPTION ต้องเปิดไว้ (ให้ try/catch จับ error ได้)
 */

require_once __DIR__ . '/config.php';

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: สร้างหรือดึง PDO connection (Singleton)
 * ==========================================================================
 *
 * 📤 Output: @return PDO instance ที่เชื่อมต่อแล้ว
 *
 * 🧠 เหตุผล:
 * - Singleton (static $pdo) — สร้างครั้งเดียว ใช้ซ้ำตลอด request
 * - ERRMODE_EXCEPTION: throw exception เมื่อ query error
 * - FETCH_ASSOC: ผลลัพธ์เป็น associative array
 * - EMULATE_PREPARES=false: native prepared statements (ป้องกัน SQL injection)
 *
 * 🛡️ Security: production ซ่อน error details (die ด้วยข้อความทั่วไป)
 * ✅ Use case: ทุก Repository + Service ที่ต้องการ DB
 */
function getDB(): PDO
{
    // 📝 Singleton: static $pdo เก็บค่าข้าม call — สร้างครั้งเดียวต่อ request
    static $pdo = null;
    
    if ($pdo === null) {
        // 📝 DSN: host + dbname + charset (utf8mb4 รองรับ emoji)
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        
        // 📝 PDO options:
        //    ERRMODE_EXCEPTION → throw exception เมื่อ query error (จับได้ด้วย try/catch)
        //    FETCH_ASSOC → ผลลัพธ์เป็น ['col' => 'val'] (ไม่มี index ตัวเลข)
        //    EMULATE_PREPARES=false → 🔴 ห้ามเปลี่ยนเป็น true!
        //       native prepared statements = ป้องกัน SQL injection ระดับ driver
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // 🛡️ [SECURITY] production ซ่อน error details
            //    ป้องกันแสดง DSN/credentials ให้ผู้ใช้เห็น
            if (defined('APP_DEBUG') && APP_DEBUG) {
                die("Database connection failed: " . $e->getMessage());
            } else {
                // 📝 เขียน log แทน (ดูใน php error log)
                error_log("DB Connection Error: " . $e->getMessage());
                die("ระบบขัดข้อง กรุณาติดต่อผู้ดูแลระบบ");
            }
        }
    }
    
    // 📤 คืน PDO instance เดิม (Singleton)
    return $pdo;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: สร้าง PDO โดยไม่เลือก database (สำหรับ install.php)
 * ==========================================================================
 *
 * 📤 Output: @return PDO instance (ยังไม่ได้เลือก database)
 * @throws PDOException
 * ✅ Use case: install.php → CREATE DATABASE IF NOT EXISTS
 */
function getDBWithoutDatabase(): PDO
{
    // 📝 DSN ไม่มี dbname — ใช้สำหรับ CREATE DATABASE
    $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
    
    // 📝 options เหมือน getDB() — native prepared statements
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    // 📝 ไม่ใช้ Singleton เพราะใช้ครั้งเดียวตอน install
    return new PDO($dsn, DB_USER, DB_PASS, $options);
}

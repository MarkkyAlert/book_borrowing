<?php
/**
 * Database Connection using PDO
 * 
 * ไฟล์นี้จัดการการเชื่อมต่อฐานข้อมูล MySQL ผ่าน PDO
 * ใช้ Singleton pattern เพื่อใช้ connection เดียวตลอด request
 */

require_once __DIR__ . '/config.php';

/**
 * สร้างหรือดึง PDO connection (Singleton)
 * 
 * @return PDO instance ที่เชื่อมต่อกับฐานข้อมูลแล้ว
 * 
 * @throws ไม่ throw แต่ die() พร้อม error message ถ้าเชื่อมต่อไม่ได้
 * 
 * @note - ใช้ Singleton: connection เดียวตลอด request (ประหยัด resource)
 *       - ERRMODE_EXCEPTION: throw exception เมื่อ query error
 *       - FETCH_ASSOC: ผลลัพธ์เป็น associative array
 *       - EMULATE_PREPARES=false: ใช้ native prepared statements (ปลอดภัยกว่า)
 * 
 * @example $pdo = getDB();
 *          $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
 *          $stmt->execute([1]);
 */
function getDB(): PDO
{
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    return $pdo;
}

/**
 * สร้าง PDO connection โดยไม่เลือก database (สำหรับ install.php)
 * 
 * @return PDO instance ที่ยังไม่ได้เลือก database
 * 
 * @throws PDOException ถ้าเชื่อมต่อไม่ได้
 * 
 * @usecase ใช้ใน install.php เพื่อสร้าง database ใหม่
 *          เช่น: CREATE DATABASE IF NOT EXISTS library
 */
function getDBWithoutDatabase(): PDO
{
    $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
    
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    return new PDO($dsn, DB_USER, DB_PASS, $options);
}

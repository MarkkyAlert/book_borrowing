<?php
/**
 * SettingsRepository - Database Access สำหรับ Settings
 * 
 * สำหรับ settings ที่เก็บในฐานข้อมูล (ตาราง settings)
 * ไม่เกี่ยวกับ .env config (ใช้ includes/config.php แทน)
 * 
 * @package App\Repositories
 */

namespace App\Repositories;

use PDO;

class SettingsRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ดึงค่า setting จากฐานข้อมูล
     * 
     * @param string $key     ชื่อ setting
     * @param mixed  $default ค่าเริ่มต้นถ้าไม่พบ
     */
    public function get(string $key, mixed $default = ''): mixed
    {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();

        return $result !== false ? $result : $default;
    }

    /**
     * บันทึกค่า setting (insert หรือ update ถ้ามีอยู่แล้ว)
     */
    public function set(string $key, mixed $value): bool
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO settings (setting_key, setting_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = ?
        ");
        return $stmt->execute([$key, $value, $value]);
    }

    /**
     * ลบ setting
     */
    public function delete(string $key): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM settings WHERE setting_key = ?");
        return $stmt->execute([$key]);
    }

    /**
     * ดึง settings ทั้งหมด
     */
    public function all(): array
    {
        $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM settings");
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }
}

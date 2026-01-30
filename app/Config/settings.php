<?php
/**
 * Settings Loader
 * โหลดค่าจาก .env ถ้ามี หรือใช้ค่า default จาก includes/config.php
 * 
 * ไฟล์นี้ทำหน้าที่เป็น bridge ระหว่างระบบเก่า (config.php) และระบบใหม่ (.env)
 * เพื่อให้ backward compatible และค่อยๆ migrate ได้
 */

class Settings
{
    private static ?array $env = null;
    private static bool $loaded = false;

    /**
     * Load .env file if exists
     */
    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        $envPath = dirname(__DIR__, 2) . '/.env';
        
        if (file_exists($envPath)) {
            self::$env = self::parseEnvFile($envPath);
        } else {
            self::$env = [];
        }

        self::$loaded = true;
    }

    /**
     * Parse .env file
     */
    private static function parseEnvFile(string $path): array
    {
        $env = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            // Parse KEY=VALUE
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove quotes if present
                if (preg_match('/^(["\'])(.*)\\1$/', $value, $matches)) {
                    $value = $matches[2];
                }

                // Convert boolean strings
                if (strtolower($value) === 'true') {
                    $value = true;
                } elseif (strtolower($value) === 'false') {
                    $value = false;
                }

                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * Get setting value
     * Priority: .env > constant (config.php) > default
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        // 1. Check .env first
        if (isset(self::$env[$key])) {
            return self::$env[$key];
        }

        // 2. Check if constant defined (from config.php)
        if (defined($key)) {
            return constant($key);
        }

        // 3. Return default
        return $default;
    }

    /**
     * Check if .env file exists
     */
    public static function hasEnvFile(): bool
    {
        return file_exists(dirname(__DIR__, 2) . '/.env');
    }

    /**
     * Get all settings (for debugging)
     */
    public static function all(): array
    {
        self::load();
        return self::$env;
    }
}

/**
 * Helper function to get settings
 * Usage: setting('DB_HOST', 'localhost')
 */
function setting(string $key, mixed $default = null): mixed
{
    return Settings::get($key, $default);
}

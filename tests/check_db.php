<?php
// Quick DB connection test
require_once __DIR__ . '/../includes/config.php';

echo "DB_HOST: " . DB_HOST . "\n";
echo "DB_NAME: " . DB_NAME . "\n";
echo "DB_USER: " . DB_USER . "\n";
echo "DB_PASS: " . (DB_PASS === '' ? '(empty)' : '***') . "\n";

try {
    $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "\nMySQL connection: OK\n";
    
    // Check if database exists
    $stmt = $pdo->query("SHOW DATABASES LIKE '" . DB_NAME . "'");
    if ($stmt->rowCount() > 0) {
        echo "Database '" . DB_NAME . "': EXISTS\n";
    } else {
        echo "Database '" . DB_NAME . "': NOT FOUND\n";
    }
} catch (PDOException $e) {
    echo "\nMySQL connection: FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
}

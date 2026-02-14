<?php
require_once __DIR__ . '/../bootstrap.php';
$pdo = getDB();
try {
    $stmt = $pdo->query("SHOW CREATE TABLE books");
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $res['Create Table'];
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

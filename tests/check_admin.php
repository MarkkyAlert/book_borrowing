<?php
require_once __DIR__ . '/../includes/db.php';
$pdo = getDB();
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute(['qa_admin@library.com']);
echo $stmt->fetchColumn() ? 'FOUND' : 'MISSING';

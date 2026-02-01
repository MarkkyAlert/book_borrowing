<?php
/**
 * Logout - ออกจากระบบ
 */

require_once __DIR__ . '/bootstrap.php';

// Clear all session data
$_SESSION = [];

// Destroy session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Start new session for flash message
session_start();
setFlash('success', 'ออกจากระบบเรียบร้อยแล้ว');

redirect(APP_URL . '/login.php');

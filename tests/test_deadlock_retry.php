<?php

/**
 * Deadlock Retry Tests — ตรรกะของ runWithDeadlockRetry() / isDeadlockException()
 *
 * ทดสอบ helper ที่ครอบ transaction ของ BorrowService / ReservationService
 * โดยไม่ต้องพึ่ง race จริง (ซึ่งเกิดไม่แน่นอน) — จำลอง PDOException แต่ละแบบแทน
 *
 * ครอบคลุม:
 * - deadlock แล้วหายเอง → ต้อง retry จนสำเร็จ
 * - deadlock ตลอด → ต้องหยุดที่ maxAttempts แล้วคืนข้อความไทย ไม่ปล่อย SQLSTATE
 * - PDOException อื่น (UNIQUE/FK) → ห้าม retry เพราะลองใหม่ก็ผลเดิม
 * - Exception ทางธุรกิจ (หนังสือหมด/เกินโควตา) → ห้าม retry และข้อความเดิมต้องไม่ถูกกลืน
 *
 * Usage: php tests/test_deadlock_retry.php
 * ⚠️ รันบน CLI เท่านั้น — ไม่ต้องใช้ Apache และไม่แตะข้อมูลใด ๆ
 *
 * 🧠 ทำไมต้องมี: การพิสูจน์ด้วย race จริง (tests/test_concurrency_http.php)
 *    ขึ้นกับจังหวะ ทำให้ทดสอบ "กรณีลองครบแล้วยังไม่สำเร็จ" ไม่ได้เลย
 *    ชุดนี้จึงยิงตรงเข้า helper เพื่อคุมทุกสาขาของตรรกะ
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// 📝 functions.php เรียก startSession() ท้ายไฟล์ — ต้องมี superglobal ให้ครบก่อน
$_SESSION = [];
$_SERVER['REMOTE_ADDR']   = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF']       = 'tests/test_deadlock_retry.php';

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$results = ['passed' => 0, 'failed' => 0, 'total' => 0];

function pass(string $id, string $msg = 'OK'): void
{
    global $results;
    $results['total']++;
    $results['passed']++;
    echo "  \033[32m✅ $id\033[0m: $msg\n";
}

function fail(string $id, string $msg): void
{
    global $results;
    $results['total']++;
    $results['failed']++;
    echo "  \033[31m❌ $id\033[0m: $msg\n";
}

/** จำลอง PDOException ของ deadlock (1213 / SQLSTATE 40001) */
function makeDeadlock(): PDOException
{
    $e = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
    $e->errorInfo = ['40001', 1213, 'Deadlock found'];
    return $e;
}

/** จำลอง lock wait timeout (1205) — ก็ควร retry เหมือนกัน */
function makeLockTimeout(): PDOException
{
    $e = new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded');
    $e->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];
    return $e;
}

/** จำลอง UNIQUE ซ้ำ (1062) — ห้าม retry */
function makeDuplicate(): PDOException
{
    $e = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
    $e->errorInfo = ['23000', 1062, 'Duplicate entry'];
    return $e;
}

$pdo = getDB();

echo "\n╔════════════════════════════════════════════════════════════╗\n";
echo "║  Deadlock Retry Tests — ตรรกะ runWithDeadlockRetry()       ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// ── DR-01: แยกแยะชนิด exception ──
echo "── การแยกแยะชนิด exception ──\n";
$ok = isDeadlockException(makeDeadlock())
    && isDeadlockException(makeLockTimeout())
    && !isDeadlockException(makeDuplicate())
    && !isDeadlockException(new Exception('หนังสือหมด'));
$ok ? pass('DR-01', 'deadlock/lock-timeout = retry ได้ · UNIQUE และ error ทางธุรกิจ = ไม่ retry')
    : fail('DR-01', 'isDeadlockException แยกแยะผิด');

// ── DR-02: deadlock ครั้งแรก แล้วครั้งที่ 2 ผ่าน ──
echo "\n── พฤติกรรมการ retry ──\n";
$calls = 0;
try {
    $out = runWithDeadlockRetry($pdo, function () use (&$calls) {
        $calls++;
        if ($calls < 2) {
            throw makeDeadlock();
        }
        return 'สำเร็จ';
    }, 'DR-02');
    ($out === 'สำเร็จ' && $calls === 2)
        ? pass('DR-02', "deadlock ครั้งแรก → ลองใหม่แล้วสำเร็จ (เรียก $calls ครั้ง)")
        : fail('DR-02', "ผลลัพธ์ไม่ตรง: out=$out calls=$calls");
} catch (Exception $e) {
    fail('DR-02', 'ไม่ควร throw: ' . $e->getMessage());
}

// ── DR-03: deadlock ตลอด → หยุดที่ maxAttempts + ข้อความไทย ──
$calls = 0;
try {
    runWithDeadlockRetry($pdo, function () use (&$calls) {
        $calls++;
        throw makeDeadlock();
    }, 'DR-03', 3);
    fail('DR-03', 'ควร throw เมื่อลองครบแล้วยังไม่สำเร็จ');
} catch (Exception $e) {
    $msg = $e->getMessage();
    if ($calls !== 3) {
        fail('DR-03', "ควรเรียก 3 ครั้ง แต่เรียก $calls ครั้ง");
    } elseif (str_contains($msg, 'SQLSTATE') || str_contains($msg, 'Deadlock')) {
        fail('DR-03', "ข้อความดิบของ DB หลุดออกมา: $msg");
    } elseif (!str_contains($msg, 'ลองใหม่')) {
        fail('DR-03', "ข้อความไม่ได้บอกให้ผู้ใช้ลองใหม่: $msg");
    } else {
        pass('DR-03', "หยุดที่ 3 ครั้ง → \"$msg\"");
    }
}

// ── DR-04: PDOException อื่น ห้าม retry และห้ามหลุดข้อความดิบ ──
echo "\n── error ที่ห้าม retry ──\n";
$calls = 0;
try {
    runWithDeadlockRetry($pdo, function () use (&$calls) {
        $calls++;
        throw makeDuplicate();
    }, 'DR-04', 3);
    fail('DR-04', 'ควร throw');
} catch (Exception $e) {
    ($calls === 1 && !str_contains($e->getMessage(), 'SQLSTATE'))
        ? pass('DR-04', 'UNIQUE ซ้ำ → ไม่ retry (เรียก 1 ครั้ง) และไม่หลุดข้อความดิบ')
        : fail('DR-04', "calls=$calls msg={$e->getMessage()}");
}

// ── DR-05: error ทางธุรกิจต้องผ่านออกไปตามเดิม ──
$calls = 0;
try {
    runWithDeadlockRetry($pdo, function () use (&$calls) {
        $calls++;
        throw new Exception('หนังสือหมด ไม่สามารถจองได้');
    }, 'DR-05', 3);
    fail('DR-05', 'ควร throw');
} catch (Exception $e) {
    ($calls === 1 && $e->getMessage() === 'หนังสือหมด ไม่สามารถจองได้')
        ? pass('DR-05', 'error ทางธุรกิจไม่ถูก retry และข้อความเดิมไม่ถูกกลืน')
        : fail('DR-05', "calls=$calls msg={$e->getMessage()}");
}

// ── DR-06: ไม่ทิ้ง transaction ค้างไว้ ──
echo "\n── ความสะอาดของ transaction ──\n";
// 🧠 จำลองกรณีที่ operation เปิด transaction แล้วเจอ deadlock โดยยังไม่ได้ rollback เอง
//    helper ต้อง rollback ให้ก่อนลองใหม่ ไม่งั้นรอบถัดไปจะ beginTransaction ซ้อนแล้วพัง
$calls = 0;
try {
    runWithDeadlockRetry($pdo, function () use (&$calls, $pdo) {
        $calls++;
        $pdo->beginTransaction();
        if ($calls < 2) {
            throw makeDeadlock();   // จงใจไม่ rollback
        }
        $pdo->commit();
        return 'ok';
    }, 'DR-06');
    (!$pdo->inTransaction() && $calls === 2)
        ? pass('DR-06', 'helper เคลียร์ transaction ที่ค้างก่อนลองใหม่')
        : fail('DR-06', 'ยังมี transaction ค้างหลังจบ');
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail('DR-06', 'ไม่ควร throw: ' . $e->getMessage());
}

// ── SUMMARY ──
$pct = $results['total'] > 0 ? round($results['passed'] / $results['total'] * 100, 1) : 0;
echo "\n══════════════════════════════════════\n";
echo " RESULTS: {$results['passed']}/{$results['total']} passed ($pct%)";
if ($results['failed'] > 0) {
    echo " | {$results['failed']} FAILED";
}
echo "\n══════════════════════════════════════\n\n";

exit($results['failed'] > 0 ? 1 : 0);

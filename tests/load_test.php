<?php

/**
 * Load Test — วัดว่ารับผู้ใช้พร้อมกันได้จริงกี่คน
 *
 * ==========================================================================
 * 🎯 ทำไมต้องมี
 * ==========================================================================
 * `docs/FAQ_FOR_SALER.md` บอกลูกค้าว่า "รับได้ไม่เกิน 10-30 คนพร้อมกัน"
 * แต่ตัวเลขนั้นไม่เคยมีการวัดรองรับ — เป็นการเดาที่ถูกนำไปบอกลูกค้าเหมือนข้อเท็จจริง
 * ไฟล์นี้ทำให้ตัวเลขในสคริปต์ขายมีหลักฐานจริงอยู่เบื้องหลัง
 *
 * 📌 การใช้งาน (CLI เท่านั้น):
 *   php tests/load_test.php                 วัดตามระดับมาตรฐาน 5/10/20/30/50
 *   php tests/load_test.php --levels=5,100  กำหนดระดับเอง
 *   php tests/load_test.php --no-reset      ไม่ล้าง rate limit ก่อนวัด (ดูผลตอนชน rate limit)
 *
 * ⚠️ ไม่ได้ต่อเข้า run_all_tests.php โดยตั้งใจ — ยิงโหลดหนักและใช้เวลานาน
 *    ควรรันเองตอนที่อยากได้ตัวเลข ไม่ใช่ทุกครั้งที่แก้โค้ด
 *
 * 🧠 ยิงเฉพาะ request แบบ "อ่าน" (หน้าแรก/ค้นหา/รายละเอียด/dashboard)
 *    ไม่สร้างหรือแก้ข้อมูล — แต่ยังตรวจ stock invariant ตอนจบเพื่อความแน่ใจ
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$_SESSION = [];
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF']       = 'tests/load_test.php';

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$BASE = rtrim(APP_URL, '/');
$opts = getopt('', ['levels::', 'no-reset']);
$levels = isset($opts['levels'])
    ? array_map('intval', explode(',', $opts['levels']))
    : [5, 10, 20, 30, 50];
$resetRateLimit = !isset($opts['no-reset']);

/**
 * 🎯 ยิง N request พร้อมกันจริง ๆ ด้วย curl_multi
 * 🧠 ต้องใช้ curl_multi ไม่ใช่ลูป curl ธรรมดา — ลูปธรรมดาคือ "ทีละคน N ครั้ง"
 *    ซึ่งไม่ได้วัดอะไรเกี่ยวกับความพร้อมกันเลย
 */
function fireConcurrent(array $urls): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $i => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    $start = microtime(true);
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);
    $wall = (microtime(true) - $start) * 1000;

    $times = [];
    $codes = [];
    foreach ($handles as $ch) {
        $times[] = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
        $codes[] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);

    sort($times);
    $n = count($times);
    return [
        'wall'  => $wall,
        'p50'   => $times[(int) ($n * 0.50)] ?? 0,
        'p95'   => $times[min($n - 1, (int) ($n * 0.95))] ?? 0,
        'max'   => $times[$n - 1] ?? 0,
        'codes' => array_count_values($codes),
    ];
}

/** 🧹 ล้าง rate limit เพื่อวัด "เพดานของเซิร์ฟเวอร์" ไม่ใช่ "เพดานของ rate limit" */
function clearRateLimit(PDO $pdo): void
{
    $pdo->exec("DELETE FROM rate_limits");
}

$pdo = getDB();

echo "\n╔════════════════════════════════════════════════════════════════════╗\n";
echo "║  Load Test — รับผู้ใช้พร้อมกันได้จริงกี่คน                        ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";
echo "  เป้าหมาย: $BASE\n";
echo "  ล้าง rate limit ก่อนวัด: " . ($resetRateLimit ? 'ใช่ (วัดเพดานเซิร์ฟเวอร์)' : 'ไม่ (วัดสภาพจริงที่มี rate limit)') . "\n\n";

// 📝 หน้าที่ผู้ใช้จริงเปิดบ่อยที่สุด — ผสมกันให้เหมือนการใช้งานจริง
$bookId = (int) $pdo->query("SELECT id FROM books WHERE is_visible = 1 ORDER BY id LIMIT 1")->fetchColumn();
$scenarios = [
    'หน้าแรก'          => "$BASE/index.php",
    'ค้นหา (AJAX)'     => "$BASE/api/search_books.php?search=" . urlencode('การ'),
    'รายละเอียดหนังสือ' => "$BASE/book.php?id=$bookId",
];

foreach ($scenarios as $name => $url) {
    echo "\033[1m── $name ──\033[0m\n";
    printf("  %8s %10s %10s %10s %10s  %s\n", 'พร้อมกัน', 'รวม(ms)', 'p50', 'p95', 'สูงสุด', 'HTTP');
    printf("  %s\n", str_repeat('─', 72));

    foreach ($levels as $c) {
        if ($resetRateLimit) {
            clearRateLimit($pdo);
        }
        $r = fireConcurrent(array_fill(0, $c, $url));

        // 📝 สรุปสถานะ: 200 = ปกติ · 429 = ชน rate limit · 5xx = เซิร์ฟเวอร์รับไม่ไหว
        $codeStr = [];
        foreach ($r['codes'] as $code => $n) {
            // 🧠 ต้องใช้ {} ครอบ — "$code×$n" จะถูก PHP อ่านเป็นตัวแปรชื่อ $code× เพราะ × เป็น multibyte
            $codeStr[] = "{$code}×{$n}";
        }
        $bad = ($r['codes'][500] ?? 0) + ($r['codes'][503] ?? 0) + ($r['codes'][0] ?? 0);
        $mark = $bad > 0 ? "\033[31m ← มี error\033[0m" : '';

        printf("  %8d %10.0f %10.0f %10.0f %10.0f  %s%s\n",
            $c, $r['wall'], $r['p50'], $r['p95'], $r['max'], implode(' ', $codeStr), $mark);
    }
    echo "\n";
}

// ── ตรวจว่าข้อมูลไม่เสียหลังยิงโหลด ──
echo "\033[1m── ความถูกต้องของข้อมูลหลังยิงโหลด ──\033[0m\n";
$broken = (int) $pdo->query("
    SELECT COUNT(*) FROM books b
    WHERE b.available <> b.quantity
      - (SELECT COUNT(*) FROM borrows br WHERE br.book_id = b.id AND br.status = 'borrowing')
      - (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.id AND r.status = 'pending')
")->fetchColumn();
echo $broken === 0
    ? "  ✅ stock invariant ถูกต้องทุกเล่ม\n\n"
    : "  ❌ stock ผิด $broken เล่ม\n\n";

echo "  📌 อ่านผลอย่างไร\n";
echo "     - 429 = ชน rate limit (ระบบป้องกันตัวเองทำงาน ไม่ใช่เซิร์ฟเวอร์รับไม่ไหว)\n";
echo "     - 500/503 หรือ code 0 = เซิร์ฟเวอร์รับไม่ไหวจริง\n";
echo "     - p95 คือตัวเลขที่ควรใช้ตอบลูกค้า ไม่ใช่ค่าเฉลี่ย (ผู้ใช้ที่ช้าที่สุดคือคนที่บ่น)\n\n";

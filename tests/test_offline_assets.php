<?php

/**
 * Offline Assets Tests — ระบบต้องใช้งานได้โดยไม่ต่ออินเทอร์เน็ต (F-09)
 *
 * ==========================================================================
 * 🎯 ชุดนี้กันอะไร
 * ==========================================================================
 * ลูกค้าหลายรายเป็นห้องสมุดโรงเรียน/ราชการที่เป็น intranet ไม่ต่อเน็ต
 * ถ้ามีใครเผลอเพิ่ม <script src="https://cdn..."> กลับเข้ามา ระบบจะพังที่เครื่องลูกค้า
 * แต่บนเครื่องคนพัฒนา (ที่มีเน็ต) จะดูปกติทุกอย่าง — **ไม่มีทางรู้ตัวจนกว่าจะส่งมอบ**
 *
 * ชุดนี้จึงตรวจให้ว่า:
 *  1. ไม่มีไฟล์ PHP ไหนอ้าง asset จากภายนอกอีก
 *  2. ไฟล์ที่โค้ดอ้างถึงมีอยู่จริงในโปรเจกต์
 *  3. ฟอนต์อยู่ครบ (CSS อ้าง path สัมพัทธ์ ถ้าย้ายไฟล์จะเงียบ ๆ ไม่มีไอคอน)
 *
 * Usage: php tests/test_offline_assets.php
 * ⚠️ รันบน CLI เท่านั้น — อ่านไฟล์อย่างเดียว ไม่แตะ DB
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$ROOT = dirname(__DIR__);
$results = ['passed' => 0, 'failed' => 0, 'total' => 0];

function pass(string $id, string $msg = 'OK'): void
{
    global $results;
    $results['total']++; $results['passed']++;
    echo "  \033[32m✅ $id\033[0m: $msg\n";
}
function fail(string $id, string $msg): void
{
    global $results;
    $results['total']++; $results['failed']++;
    echo "  \033[31m❌ $id\033[0m: $msg\n";
}

echo "\n╔════════════════════════════════════════════════════════════╗\n";
echo "║  Offline Assets Tests — ใช้งานได้โดยไม่ต่อเน็ต (F-09)     ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

/** 🎯 ไล่ไฟล์ PHP ทั้งโปรเจกต์ (ข้าม tests/ กับ assets/) */
function projectPhpFiles(string $root): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function ($file) {
                $name = $file->getFilename();
                // 📝 ข้าม tests/ (มีสคริปต์ที่เขียน URL ไว้ในคอมเมนต์) และ .git
                return !in_array($name, ['tests', '.git', 'uploads', 'logs', 'assets', 'docs'], true);
            }
        )
    );
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $out[] = $file->getPathname();
        }
    }
    return $out;
}

$files = projectPhpFiles($ROOT);

// ═══════════════════════════════════════════════
// OA-01: ไม่มี asset จากภายนอกใน src= / href=
// ═══════════════════════════════════════════════
echo "── ไม่พึ่ง CDN ──\n";
$offenders = [];
foreach ($files as $file) {
    $src = file_get_contents($file);
    // 🧠 จับเฉพาะที่โหลดมาใช้จริง (src=/href=) ไม่จับ URL ในคอมเมนต์หรือข้อความ
    if (preg_match_all('/(?:src|href)\s*=\s*["\']https?:\/\/([^"\'\/]+)/i', $src, $m)) {
        foreach ($m[1] as $host) {
            $offenders[] = str_replace($ROOT . '/', '', $file) . " → $host";
        }
    }
}
empty($offenders)
    ? pass('OA-01', 'ตรวจ ' . count($files) . ' ไฟล์ — ไม่มีไฟล์ไหนโหลด CSS/JS/ฟอนต์จากภายนอก')
    : fail('OA-01', 'ยังพึ่งภายนอก: ' . implode(' · ', array_slice(array_unique($offenders), 0, 5)));

// ═══════════════════════════════════════════════
// OA-02: ไฟล์ที่โค้ดอ้างถึงมีอยู่จริง
// ═══════════════════════════════════════════════
echo "\n── ไฟล์ที่อ้างถึงมีอยู่จริง ──\n";
$referenced = [];
foreach ($files as $file) {
    $src = file_get_contents($file);
    if (preg_match_all('#assets/vendor/[A-Za-z0-9._/-]+\.(?:js|css)#', $src, $m)) {
        foreach ($m[0] as $path) {
            $referenced[$path] = true;
        }
    }
}
$missing = array_values(array_filter(array_keys($referenced), fn($p) => !is_file("$ROOT/$p")));
empty($missing)
    ? pass('OA-02', 'อ้างถึง ' . count($referenced) . ' ไฟล์ — มีครบทุกไฟล์')
    : fail('OA-02', 'ไฟล์หาย: ' . implode(' · ', $missing));

// ═══════════════════════════════════════════════
// OA-03: ฟอนต์ของ Bootstrap Icons อยู่ข้าง CSS
// ═══════════════════════════════════════════════
echo "\n── ฟอนต์ ──\n";
// 🧠 bootstrap-icons.css อ้างฟอนต์ด้วย path สัมพัทธ์ './fonts/…'
//    ถ้าย้ายไฟล์ CSS ออกจากโฟลเดอร์นี้ ไอคอนจะหายหมดโดยไม่มี error ใด ๆ
$iconFonts = ['woff2', 'woff'];
$lostFonts = array_values(array_filter(
    $iconFonts,
    fn($ext) => !is_file("$ROOT/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.$ext")
));
empty($lostFonts)
    ? pass('OA-03', 'ไฟล์ฟอนต์ไอคอนอยู่ครบใน bootstrap-icons/fonts/')
    : fail('OA-03', 'ฟอนต์ไอคอนหาย: ' . implode(', ', $lostFonts) . ' → ไอคอนจะหายทุกหน้า');

// ═══════════════════════════════════════════════
// OA-04: sarabun.css ต้องชี้ไฟล์ในเครื่อง
// ═══════════════════════════════════════════════
$sarabun = "$ROOT/assets/vendor/fonts/sarabun.css";
if (!is_file($sarabun)) {
    fail('OA-04', 'ไม่พบ assets/vendor/fonts/sarabun.css');
} else {
    $css = file_get_contents($sarabun);
    $external = substr_count($css, 'fonts.gstatic.com');
    preg_match_all('#url\(\./files/([^)]+)\)#', $css, $m);
    $fontFiles = array_unique($m[1]);
    $lost = array_values(array_filter($fontFiles, fn($f) => !is_file("$ROOT/assets/vendor/fonts/files/$f")));

    if ($external > 0) {
        fail('OA-04', "sarabun.css ยังชี้ไป gstatic $external จุด — ไม่ต่อเน็ตแล้วฟอนต์ไทยจะหาย");
    } elseif ($lost) {
        fail('OA-04', 'ไฟล์ฟอนต์หาย ' . count($lost) . ' ไฟล์: ' . implode(', ', array_slice($lost, 0, 3)));
    } else {
        pass('OA-04', 'sarabun.css ชี้ไฟล์ในเครื่องครบ ' . count($fontFiles) . ' ไฟล์');
    }
}

// ═══════════════════════════════════════════════
// OA-05: .htaccess ของ assets/ ยังกันการรัน PHP
// ═══════════════════════════════════════════════
echo "\n── ความปลอดภัยของโฟลเดอร์ assets ──\n";
$ht = "$ROOT/assets/.htaccess";
if (!is_file($ht)) {
    fail('OA-05', 'ไม่มี assets/.htaccess — โฟลเดอร์นี้ควรรัน PHP ไม่ได้');
} else {
    $conf = file_get_contents($ht);
    (str_contains($conf, 'engine off') && str_contains($conf, 'Require all denied'))
        ? pass('OA-05', 'assets/.htaccess ปิดการรัน PHP ไว้แล้ว')
        : fail('OA-05', 'assets/.htaccess ไม่ได้ปิดการรัน PHP');
}

$pct = $results['total'] > 0 ? round($results['passed'] / $results['total'] * 100, 1) : 0;
echo "\n══════════════════════════════════════\n";
echo " RESULTS: {$results['passed']}/{$results['total']} passed ($pct%)";
if ($results['failed'] > 0) echo " | {$results['failed']} FAILED";
echo "\n══════════════════════════════════════\n\n";

exit($results['failed'] > 0 ? 1 : 0);

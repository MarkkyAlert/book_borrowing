<?php

/**
 * เอกสารต้องตรงกับโค้ด
 *
 * ==========================================================================
 * 🔴 ปัญหาที่ชุดนี้เกิดมาเพื่อแก้
 * ==========================================================================
 * ROADMAP ข้อ 0 ย้ายกฎการยืมจากไฟล์ `.env` ขึ้นไปไว้ที่ **หน้าตั้งค่าระบบ**
 * แต่เอกสารยังบอกลูกค้าให้ไปแก้ไฟล์อยู่ — ซึ่งวันนี้ทำแล้ว **ไม่มีผลอะไรเลย**
 * เพราะระบบอ่านเรียง settings → .env → default พอเคยกดบันทึกในหน้าเว็บครั้งแรก
 * ค่าใน `.env` จะไม่ถูกใช้อีก
 *
 * 🔴 ทำไมต้องเป็นเทสต์ ไม่ใช่ไล่แก้ด้วยมือ
 *    ไล่แก้ด้วยมือมาแล้ว 1 รอบ (commit 47cb7a9) แล้ว **ยังพลาด**:
 *    แก้หัวข้อ "การตั้งค่า" ใน README แต่ตกตาราง "แก้ได้อย่างปลอดภัย" ท้ายไฟล์เดียวกัน
 *    ต่อมาเจอเพิ่มอีก 5 ไฟล์ · เอกสารในโปรเจกต์นี้มีเกิน 8,000 บรรทัด
 *    ตาคนอ่านไม่ไหว ต้องให้เครื่องอ่าน
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร — ทุกข้อดึง "ความจริง" จากโค้ด ไม่ใช่จากตัวเลขที่พิมพ์ไว้
 * ==========================================================================
 * A. ไม่มีเอกสารไหนบอกให้ตั้งกฎการยืมในไฟล์ `.env` เป็นวิธีหลัก
 * B. จำนวนกฎที่เอกสารอ้าง == จำนวนกฎจริงใน ruleDefinitions()
 * C. จำนวนรายงานที่เอกสารอ้าง == จำนวน case จริงใน report_helper.php
 * D. ไม่มีเอกสารไหนอ้างว่า "ยังไม่มี full-text search" ขณะที่โค้ดใช้ MATCH() อยู่
 * E. ทุกกฎใน ruleDefinitions() ต้องมีชื่ออยู่ในตารางของ README
 *
 * 🧠 ชุดนี้ไม่แตะฐานข้อมูลเลย — อ่านไฟล์กับทะเบียนกฎเท่านั้น ไม่ต้องล้างอะไร
 *
 * 📌 การใช้งาน: php tests/test_docs_match_code.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$ROOT    = dirname(__DIR__);
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

function check(string $id, bool $ok, string $okMsg, string $failMsg): void
{
    $ok ? pass($id, $okMsg) : fail($id, $failMsg);
}

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  เอกสารต้องตรงกับโค้ด                                     ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

/**
 * 📄 เอกสารที่ลูกค้า/ผู้ขายอ่าน — ไม่รวม docs/ai-context/ ซึ่งเป็นบันทึกภายใน
 *    ที่ **ต้อง** พูดถึงประวัติของบั๊กได้ (เช่นอ้างข้อความผิดที่เคยมี)
 */
$docFiles = array_values(array_filter(
    array_merge([$ROOT . '/README.md'], glob($ROOT . '/docs/*.md') ?: []),
    fn($f) => !str_contains($f, '/ai-context/')
));
$rel = fn(string $path) => str_replace($ROOT . '/', '', $path);

echo '── เอกสารที่ตรวจ: ' . count($docFiles) . " ไฟล์ ──\n";
foreach ($docFiles as $f) echo '     ' . $rel($f) . "\n";

// ============================================================
// A. ห้ามบอกให้ตั้งกฎการยืมในไฟล์ .env
// ============================================================
echo "\n── A. กฎการยืมต้องชี้ไปที่หน้าตั้งค่า ไม่ใช่ไฟล์ ──\n";

$ruleKeys = array_keys(ruleDefinitions());

/**
 * 🧠 **ไม่ได้ห้ามพูดถึง `.env` เลย** — `.env` ยังเป็นชั้นสำรองจริง ๆ
 *    การเขียนอธิบายลำดับ 3 ชั้นให้ถูกต้องเป็นสิ่งที่ **ควร** ทำ
 *    จึงยอมให้บรรทัดที่เอ่ยถึงคีย์กฎ ผ่านได้ถ้าบริบทรอบ ๆ อธิบายไว้ครบ
 *
 * 🔴 ถ้าเขียนเป็น "ห้ามมีคำว่า .env ใกล้คีย์กฎ" จะกลายเป็นบังคับให้เอกสาร
 *    ปกปิดความจริงอีกด้าน = แก้ปัญหาหนึ่งแล้วสร้างอีกปัญหา
 */
// 🧠 คำที่ถือว่า "อธิบายลำดับชั้นไว้แล้ว" — เขียนได้หลายสำนวน ต้องรับให้ครบ
//    เคยตกสำนวน "หน้า Settings ... ค่าอ่านเรียง settings → .env → default"
//    ซึ่งอธิบายถูกต้องครบถ้วน แต่ไม่มีคำว่า "หน้าตั้งค่า" เลยโดนจับผิด
$contextMarkers = ['หน้าตั้งค่า', 'ตั้งค่าระบบ', 'หน้า Settings', 'settings →',
                   'ชั้นสำรอง', '3 ชั้น', 'สามชั้น', 'ไม่มีผลอีก', 'ค่าตั้งต้น'];
$lookBehind = 8;   // บรรทัดก่อนหน้าที่นับเป็นบริบทเดียวกัน

$offenders = [];
foreach ($docFiles as $file) {
    $lines = explode("\n", (string) file_get_contents($file));
    foreach ($lines as $i => $line) {
        $hitKeys = array_values(array_filter($ruleKeys, fn($k) => str_contains($line, $k)));
        if (!$hitKeys) continue;
        // 📝 ต้องมีคำว่า .env หรือ "ไฟล์" อยู่ในบรรทัดด้วย ถึงจะถือว่า "ชี้ไปที่ไฟล์"
        if (!str_contains($line, '.env') && !str_contains($line, 'ไฟล์ตั้งค่า')) continue;

        $context = implode("\n", array_slice($lines, max(0, $i - $lookBehind), $lookBehind + 1));
        $explained = false;
        foreach ($contextMarkers as $m) {
            if (str_contains($context, $m)) { $explained = true; break; }
        }
        if (!$explained) {
            $offenders[] = sprintf('%s:%d → %s', $rel($file), $i + 1,
                mb_substr(trim(preg_replace('/\s+/', ' ', $line)), 0, 70));
        }
    }
}
check('DOC-A1', !$offenders,
    'ไม่มีเอกสารไหนบอกให้ตั้งกฎการยืมในไฟล์โดยไม่อธิบายว่าหน้าตั้งค่ามาก่อน',
    "🔴 พบ " . count($offenders) . " จุดที่จะทำให้ลูกค้าแก้ไฟล์แล้วงงว่าทำไมไม่เปลี่ยน:\n       "
        . implode("\n       ", $offenders));

/**
 * 🔴 A2 — จับกรณีที่เขียนเป็นร้อยแก้ว **ไม่ได้เอ่ยชื่อคีย์**
 *    เช่น "แก้ค่าปรับต่อวันตรงไหน? → แก้ในไฟล์ .env ได้เลย"
 *    A1 จับไม่ได้เพราะไม่มีคำว่า FINE_PER_DAY อยู่ในบรรทัด
 *    แต่นี่คือรูปแบบที่ **อันตรายที่สุด** เพราะเป็นภาษาที่ลูกค้ากับผู้ขายอ่านแล้วทำตามทันที
 */
$conceptWords = ['ค่าปรับ', 'จำนวนวันยืม', 'วันยืม', 'ยืมได้กี่เล่ม', 'ยืมสูงสุด',
                 'จำนวนเล่มสูงสุด', 'โควตา', 'ต่ออายุการยืม', 'วันหมดอายุการจอง'];

$proseOffenders = [];
foreach ($docFiles as $file) {
    $lines = explode("\n", (string) file_get_contents($file));
    foreach ($lines as $i => $line) {
        if (!str_contains($line, '.env')) continue;
        $hit = false;
        foreach ($conceptWords as $w) {
            if (str_contains($line, $w)) { $hit = true; break; }
        }
        if (!$hit) continue;

        $context = implode("\n", array_slice($lines, max(0, $i - $lookBehind), $lookBehind + 1));
        $explained = false;
        foreach ($contextMarkers as $m) {
            if (str_contains($context, $m)) { $explained = true; break; }
        }
        if (!$explained) {
            $proseOffenders[] = sprintf('%s:%d → %s', $rel($file), $i + 1,
                mb_substr(trim(preg_replace('/\s+/', ' ', $line)), 0, 70));
        }
    }
}
check('DOC-A2', !$proseOffenders,
    'ไม่มีเอกสารไหนบอกเป็นภาษาคนว่า "แก้ค่าปรับ/วันยืมในไฟล์ .env"',
    "🔴 พบ " . count($proseOffenders) . " จุด (แบบร้อยแก้ว — อันตรายกว่าแบบมีชื่อคีย์):\n       "
        . implode("\n       ", $proseOffenders));

// ============================================================
// B. จำนวนกฎที่เอกสารอ้าง
// ============================================================
echo "\n── B. จำนวนกฎในเอกสาร ──\n";

$realRuleCount = count($ruleKeys);
$wrongCounts = [];
foreach ($docFiles as $file) {
    $lines = explode("\n", (string) file_get_contents($file));
    foreach ($lines as $i => $line) {
        if (preg_match('/กฎการยืม[^\d]{0,12}(\d+)\s*ข้อ/u', $line, $m)
            && (int) $m[1] !== $realRuleCount) {
            $wrongCounts[] = sprintf('%s:%d → เขียนว่า %d ข้อ', $rel($file), $i + 1, (int) $m[1]);
        }
    }
}
check('DOC-B1', !$wrongCounts,
    "จำนวนกฎในเอกสารตรงกับ ruleDefinitions() ({$realRuleCount} ข้อ)",
    "🔴 ของจริงมี {$realRuleCount} ข้อ แต่เอกสารเขียนว่า:\n       " . implode("\n       ", $wrongCounts));

// ============================================================
// C. จำนวนรายงานที่เอกสารอ้าง
// ============================================================
echo "\n── C. จำนวนรายงานในเอกสาร ──\n";

// 📝 นับจาก case จริงใน getReportConfig() ซึ่งเป็น Single Source of Truth
//    (default: ไม่นับ เพราะเป็น fallback ไม่ใช่รายงาน)
$helper = (string) file_get_contents($ROOT . '/includes/report_helper.php');
preg_match_all("/^\s*case\s+'([a-z_]+)':/m", $helper, $m);
$realReportTypes = array_unique($m[1] ?? []);
$realReportCount = count($realReportTypes);

check('DOC-C1', $realReportCount >= 8,
    "ดึงชนิดรายงานจาก report_helper.php ได้ {$realReportCount} แบบ: " . implode(', ', $realReportTypes),
    "🔴 ดึงได้แค่ {$realReportCount} แบบ — รูปแบบไฟล์เปลี่ยนไป ต้องแก้ตัวดึงในเทสต์นี้ ไม่ใช่แก้เอกสาร");

$wrongReports = [];
foreach (array_merge($docFiles, [$ROOT . '/admin/reports.php']) as $file) {
    $lines = explode("\n", (string) file_get_contents($file));
    foreach ($lines as $i => $line) {
        if (preg_match('/รายงาน[^\d]{0,20}(\d+)\s*(แบบ|ประเภท)/u', $line, $mm)
            && (int) $mm[1] !== $realReportCount) {
            $wrongReports[] = sprintf('%s:%d → เขียนว่า %d %s', $rel($file), $i + 1, (int) $mm[1], $mm[2]);
        }
    }
}
check('DOC-C2', !$wrongReports,
    "จำนวนรายงานในเอกสารและคอมเมนต์โค้ดตรงกับของจริง ({$realReportCount} แบบ)",
    "🔴 ของจริงมี {$realReportCount} แบบ แต่เขียนว่า:\n       " . implode("\n       ", $wrongReports));

/**
 * 🔴 C3 — รายงานที่มีอยู่จริงต้อง **กดถึงได้จากหน้าเว็บ**
 *    เจอตอนทดสอบ clone สด: `report=borrows` ทำงานครบทั้งหน้าเว็บ/CSV/หน้าพิมพ์
 *    แต่ **ไม่เคยมีแท็บให้กดเลย** ตั้งแต่แรก (git log -S ยืนยันว่าไม่เคยมี)
 *    = ฟีเจอร์ที่มีอยู่แต่ไม่มีใครเห็น และเอกสารที่เขียนว่า "รายงาน 8 แบบ"
 *      จะกลายเป็นคำอวดอ้างทันที เพราะผู้ใช้กดได้จริงแค่ 7
 *
 * 🧠 นับจำนวน case อย่างเดียวไม่พอ ต้องเทียบกับ "ทางเข้า" ที่ผู้ใช้มีจริง
 */
$reportsPage = (string) file_get_contents($ROOT . '/admin/reports.php');
preg_match_all('/reports\.php\?report=([a-z_]+)"/', $reportsPage, $tabMatch);
$tabTypes = array_values(array_unique($tabMatch[1] ?? []));
$noTab = array_values(array_diff($realReportTypes, $tabTypes));

check('DOC-C3', !$noTab,
    'ทุกชนิดรายงานมีแท็บให้กดจากหน้าเว็บ (' . count($tabTypes) . ' แท็บ)',
    "🔴 มีในโค้ดแต่กดไม่ถึง " . count($noTab) . " แบบ: " . implode(', ', $noTab)
        . "\n       เข้าได้ทางเดียวคือพิมพ์ URL เอง — ผู้ใช้จะไม่มีวันเจอ");

// ============================================================
// D. คำอ้างที่ขัดกับโค้ดโดยตรง
// ============================================================
echo "\n── D. คำอ้างที่ขัดกับโค้ด ──\n";

// 🔎 ค้นหาไทยใช้ FULLTEXT (trigram) จริงตั้งแต่ F-24
$repoSrc  = (string) file_get_contents($ROOT . '/app/Repositories/BookRepository.php');
$hasFullText = str_contains($repoSrc, 'MATCH(') && str_contains($repoSrc, 'AGAINST');

$staleClaims = [];
if ($hasFullText) {
    foreach ($docFiles as $file) {
        $lines = explode("\n", (string) file_get_contents($file));
        foreach ($lines as $i => $line) {
            // 📝 จับเฉพาะประโยคที่บอกว่า "ยังไม่มี" — ไม่ใช่ทุกบรรทัดที่เอ่ยถึง full-text
            if (preg_match('/(ยังไม่มี|ไม่มี)\s*full[- ]?text/iu', $line)) {
                $staleClaims[] = sprintf('%s:%d → %s', $rel($file), $i + 1,
                    mb_substr(trim(preg_replace('/\s+/', ' ', $line)), 0, 70));
            }
        }
    }
}
check('DOC-D1', $hasFullText && !$staleClaims,
    'ไม่มีเอกสารไหนอ้างว่ายังไม่มี full-text search (โค้ดใช้ MATCH...AGAINST อยู่จริง)',
    $hasFullText
        ? "🔴 โค้ดมี FULLTEXT แล้ว แต่เอกสารยังเขียนว่าไม่มี:\n       " . implode("\n       ", $staleClaims)
        : '🔴 หา MATCH...AGAINST ใน BookRepository ไม่เจอ — ถ้าถอด FULLTEXT ออกจริงต้องแก้เทสต์นี้');

// ============================================================
// E. ทุกกฎต้องมีชื่ออยู่ในตารางของ README
// ============================================================
echo "\n── E. README ต้องลิสต์กฎครบทุกข้อ ──\n";

// 🧠 เทียบด้วย **ป้ายกำกับภาษาไทย** ที่ผู้ดูแลเห็นบนหน้าตั้งค่า ไม่ใช่ชื่อคีย์
//    เพราะ README เขียนให้คนที่ไม่ได้เขียนโค้ดอ่าน
$readme  = (string) file_get_contents($ROOT . '/README.md');
$missing = [];
foreach (ruleDefinitions() as $key => $rule) {
    if (!str_contains($readme, $rule['label'])) {
        $missing[] = "{$rule['label']} ({$key})";
    }
}
check('DOC-E1', !$missing,
    'README ลิสต์กฎครบทั้ง ' . count($ruleKeys) . ' ข้อ ตามป้ายกำกับที่ผู้ดูแลเห็นจริง',
    "🔴 README ยังไม่ได้พูดถึง " . count($missing) . " กฎ:\n       " . implode("\n       ", $missing));

// ============================================================
// F. เอกสารภายใน (docs/ai-context/) — ส่วน "สถานะปัจจุบัน"
// ============================================================
echo "\n── F. เอกสารส่งต่อต้องไม่ขัดกันเอง ──\n";

/**
 * 🧠 ทำไมแยกจากข้อ A–E: `docs/ai-context/` เป็น **บันทึกอดีต** ที่ต้องอ้าง
 *    ข้อความผิดที่เคยมีได้ (เช่น "เอกสารเคยเขียนว่า ... ซึ่งผิด")
 *    ถ้าเอากฎของข้อ A ไปครอบทั้งโฟลเดอร์ จะบังคับให้ลบประวัติทิ้ง — ผิดวัตถุประสงค์
 *
 * 🔴 แต่ "สถานะปัจจุบัน" ในไฟล์ส่งต่อต้องเชื่อถือได้ เพราะเป็นไฟล์แรกที่คนมาต่ออ่าน
 *    เคยเจอจริง: AI_HANDOFF บอก "F-35 … F-52 ยังไม่ได้แก้" ที่บรรทัด 332
 *    แล้วบอก "F-35…F-54 ปิดครบทุกข้อแล้ว" ที่บรรทัด 410 — ห่างกัน 78 บรรทัดในไฟล์เดียว
 *    คนที่เชื่อบรรทัดแรกจะไปนั่งไล่แก้ของที่แก้ไปแล้ว 18 ข้อ
 */
$ctxDir   = $ROOT . '/docs/ai-context';
$ctxFiles = glob($ctxDir . '/*.md') ?: [];

// F1 — ลิงก์ไปไฟล์เอกสารต้องมีอยู่จริง (เทียบแบบ path สัมพัทธ์กับไฟล์ที่ลิงก์)
$brokenLinks = [];
foreach (array_merge($docFiles, $ctxFiles) as $file) {
    $dir = dirname($file);
    foreach (explode("\n", (string) file_get_contents($file)) as $i => $line) {
        // 🔴 ตัดสิ่งที่อยู่ใน `backtick` ออกก่อน — เอกสารมีการ **ยกตัวอย่างรูปแบบลิงก์**
        //    เช่นประโยคที่เล่าว่า "ตรวจด้วยสคริปต์ไล่ทุก `[...](....md)`"
        //    ถ้าไม่ตัด จะจับตัวอย่างมาเป็นลิงก์เสีย แล้วบังคับให้ลบคำอธิบายที่ถูกต้องทิ้ง
        $line = preg_replace('/`[^`]*`/u', ' ', $line);
        if (!preg_match_all('/\[[^\]]*\]\(([^)#\s]+\.md)\)/u', $line, $m)) continue;
        foreach ($m[1] as $target) {
            if (preg_match('#^https?://#', $target)) continue;
            if (!file_exists($dir . '/' . $target)) {
                $brokenLinks[] = sprintf('%s:%d → %s', $rel($file), $i + 1, $target);
            }
        }
    }
}
check('DOC-F1', !$brokenLinks,
    'ลิงก์ไปเอกสารอื่นชี้ไปไฟล์ที่มีอยู่จริงทุกอัน',
    "🔴 ลิงก์เสีย " . count($brokenLinks) . " จุด:\n       " . implode("\n       ", $brokenLinks));

/**
 * F2 — ห้ามบอกว่าช่วง F-xx "ยังไม่ได้แก้" ถ้าอีกบรรทัดในไฟล์เดียวกันบอกว่าปิดแล้ว
 *
 * 🧠 เทียบเป็น **ช่วงตัวเลข** ไม่ใช่ข้อความ เพราะสองประโยคเขียนคนละแบบ
 *    ("F-35 … F-52" กับ "F-35…F-54") แต่พูดถึงของกองเดียวกัน
 */
$contradictions = [];
foreach ($ctxFiles as $file) {
    $text = (string) file_get_contents($file);

    /**
     * 🔴 ตัด "ข้อความที่ยกมาอ้าง" ออกก่อน — ทั้งใน `backtick` และในเครื่องหมายคำพูด
     *    บันทึกอดีตต้อง **อ้างข้อความผิดที่เคยมี** ได้ เช่นตารางที่เล่าว่า
     *    บรรทัด 332 เคยเขียนว่า "F-35 … F-52 ยังไม่ได้แก้"
     *    ถ้าไม่ตัด ตัวตรวจจะจับคำพูดที่ยกมาเป็นคำกล่าวอ้างของเอกสารเอง
     *    แล้วบังคับให้ลบหลักฐานทิ้ง = ทำลายเหตุผลว่าทำไมถึงแก้
     *
     * 🧠 คำพูดที่ยกมา = การอ้างอิง ไม่ใช่การประกาศสถานะ
     */
    $text = preg_replace('/`[^`]*`/u', ' ', $text);
    $text = preg_replace('/"[^"]{0,200}"/u', ' ', $text);

    $ranges = function (string $pattern) use ($text): array {
        $out = [];
        if (preg_match_all($pattern, $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) $out[] = [(int) $hit[1], (int) $hit[2]];
        }
        return $out;
    };
    // "F-35 … F-52 ยังไม่ได้แก้"  /  "F-35…F-54 ปิดครบ"
    $open   = $ranges('/F-(\d+)\s*(?:…|\.\.\.|-)\s*F-(\d+)[^\n]{0,40}?ยังไม่ได้แก้/u');
    $closed = $ranges('/F-(\d+)\s*(?:…|\.\.\.|-)\s*F-(\d+)[^\n]{0,40}?ปิดครบ/u');

    foreach ($open as [$oa, $ob]) {
        foreach ($closed as [$ca, $cb]) {
            if ($oa <= $cb && $ca <= $ob) {   // ช่วงซ้อนกัน
                $contradictions[] = sprintf('%s → บอกว่า F-%d…F-%d ยังไม่ได้แก้ แต่ก็บอกว่า F-%d…F-%d ปิดครบ',
                    $rel($file), $oa, $ob, $ca, $cb);
            }
        }
    }
}
check('DOC-F2', !$contradictions,
    'ไม่มีไฟล์ส่งต่อไหนบอกสถานะช่วง F-xx ขัดกันเอง',
    "🔴 ขัดกันเอง " . count($contradictions) . " จุด — คนมาต่อจะไล่แก้ของที่แก้ไปแล้ว:\n       "
        . implode("\n       ", $contradictions));

/**
 * F3 — ไฟล์ "อ่านก่อนเพื่อน" ต้องไม่สอนให้ตั้งกฎการยืมในไฟล์ `.env`
 *    ใช้กฎเดียวกับข้อ A แต่จำกัดเฉพาะ 2 ไฟล์ที่เป็นจุดตั้งหลัก
 *    ไม่ครอบ FINDINGS/ROADMAP ซึ่งเป็นบันทึกอดีตล้วน
 */
$entryDocs = array_values(array_filter($ctxFiles,
    fn($f) => in_array(basename($f), ['00_INDEX.md', 'AI_HANDOFF.md'], true)));
$entryOffenders = [];
foreach ($entryDocs as $file) {
    $lines = explode("\n", (string) file_get_contents($file));
    foreach ($lines as $i => $line) {
        if (!str_contains($line, '.env')) continue;
        $hitKey = false;
        foreach ($ruleKeys as $k) if (str_contains($line, $k)) { $hitKey = true; break; }
        foreach ($conceptWords as $w) if (str_contains($line, $w)) { $hitKey = true; break; }
        if (!$hitKey) continue;
        $context = implode("\n", array_slice($lines, max(0, $i - $lookBehind), $lookBehind + 1));
        $explained = false;
        foreach (array_merge($contextMarkers, ['บันทึกอดีต', '📜']) as $m) {
            if (str_contains($context, $m)) { $explained = true; break; }
        }
        if (!$explained) {
            $entryOffenders[] = sprintf('%s:%d → %s', $rel($file), $i + 1,
                mb_substr(trim(preg_replace('/\s+/', ' ', $line)), 0, 70));
        }
    }
}
check('DOC-F3', !$entryOffenders,
    'ไฟล์ตั้งหลัก (00_INDEX, AI_HANDOFF) ไม่ได้สอนให้ตั้งกฎการยืมในไฟล์',
    "🔴 พบ " . count($entryOffenders) . " จุด:\n       " . implode("\n       ", $entryOffenders));

// ============================================================
// ============================================================
// G. แผนที่โครงสร้างต้องครบตามโค้ดจริง
// ============================================================
echo "\n── G. แผนที่โครงสร้าง (DATABASE_MAP / PROJECT_MAP) ──\n";

/**
 * 🔴 ที่มา: เพิ่มตาราง `closed_days` + `ClosedDayRepository` ตอนทำ "วันปิดทำการ"
 *    แล้วอัปเดตแค่ FINDINGS · **DATABASE_MAP ไม่มีตารางนี้เลย** ทั้งที่หน้าที่ของไฟล์
 *    คืออธิบายทุกตาราง · PROJECT_MAP ก็ไม่มี Repository ตัวนี้
 *    และ 00_INDEX ยังบอก "9 ตาราง / 9 Repository" ผิด 3 จุด
 *
 * 🧠 ดึงรายชื่อตารางจาก **schema.sql** ไม่ใช่จาก DB ที่รันอยู่
 *    ไม่งั้นผลจะเปลี่ยนตามเครื่องที่รัน (เช่นเครื่องที่ยังไม่ได้ migrate)
 */
$schemaSql = (string) file_get_contents($ROOT . '/database/schema.sql');
preg_match_all('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`([a-z_]+)`/i', $schemaSql, $tm);
$schemaTables = array_values(array_diff(array_unique($tm[1] ?? []), ['schema_migrations']));

$dbMap = (string) file_get_contents($ctxDir . '/DATABASE_MAP.md');
$missingTables = array_values(array_filter($schemaTables, fn($t) => !str_contains($dbMap, '`' . $t . '`')));

check('DOC-G1', $schemaTables && !$missingTables,
    'DATABASE_MAP พูดถึงครบทั้ง ' . count($schemaTables) . ' ตารางใน schema.sql',
    $schemaTables
        ? '🔴 ตารางที่ไม่มีใน DATABASE_MAP: ' . implode(', ', $missingTables)
            . "\n       ไฟล์นี้มีหน้าที่อธิบายทุกตาราง — ตารางที่หายไปเท่ากับไม่มีใครรู้ว่ามันมี"
        : '🔴 ดึงรายชื่อตารางจาก schema.sql ไม่ได้ — รูปแบบไฟล์เปลี่ยน ต้องแก้เทสต์นี้');

// G2 — ทุก Service / Repository ต้องมีชื่ออยู่ใน PROJECT_MAP
$classFiles = array_merge(glob($ROOT . '/app/Services/*.php') ?: [], glob($ROOT . '/app/Repositories/*.php') ?: []);
$classNames = array_map(fn($f) => basename($f, '.php'), $classFiles);
$projMap    = (string) file_get_contents($ctxDir . '/PROJECT_MAP.md');
$missingCls = array_values(array_filter($classNames, fn($c) => !str_contains($projMap, $c)));

check('DOC-G2', $classNames && !$missingCls,
    'PROJECT_MAP พูดถึงครบทั้ง ' . count($classNames) . ' คลาส (Service + Repository)',
    '🔴 คลาสที่ไม่มีใน PROJECT_MAP: ' . implode(', ', $missingCls));

// G3 — ตัวเลขที่เอกสารอ้าง ต้องตรงกับของจริง
$realCounts = [
    'ตาราง'      => count($schemaTables),
    'Repository' => count(glob($ROOT . '/app/Repositories/*.php') ?: []),
    'Service'    => count(glob($ROOT . '/app/Services/*.php') ?: []),
];
$wrongNums = [];
foreach ($ctxFiles as $file) {
    foreach (explode("\n", (string) file_get_contents($file)) as $i => $line) {
        // 🔴 ข้ามข้อความที่ยกมาอ้างและบล็อกบันทึกอดีต ด้วยเหตุผลเดียวกับข้อ F
        $clean = preg_replace('/`[^`]*`/u', ' ', $line);
        $clean = preg_replace('/"[^"]{0,200}"/u', ' ', $clean);
        if (str_contains($line, '📜')) continue;
        foreach ($realCounts as $word => $real) {
            /**
             * 🔴 คำว่า "ตาราง" ในภาษาไทยหมายได้ทั้ง **ตารางฐานข้อมูล** และ **ตาราง HTML บนหน้าจอ**
             *    เคยจับผิด 3 จุดที่พูดถึง "6 ตาราง" ซึ่งหมายถึงตารางบนหน้าแอดมิน (งาน F-49 มือถือ)
             *    จึงนับเป็นคำกล่าวอ้างเรื่องฐานข้อมูล **เฉพาะเมื่อบรรทัดนั้นมีบริบท DB กำกับ**
             */
            if ($word === 'ตาราง') {
                $dbContext = false;
                foreach (['FK', 'Constraint', 'schema', 'ฐานข้อมูล', 'Repository', 'ติดตั้ง'] as $marker) {
                    if (str_contains($clean, $marker)) { $dbContext = true; break; }
                }
                if (!$dbContext) continue;
            }
            if (preg_match('/(\d+)\s*' . preg_quote($word, '/') . '\b/u', $clean, $m)
                && (int) $m[1] !== $real) {
                $wrongNums[] = sprintf('%s:%d → เขียนว่า %d %s (จริง %d)',
                    $rel($file), $i + 1, (int) $m[1], $word, $real);
            }
        }
    }
}
// 📌 หัวข้อของ DATABASE_MAP เขียนเป็น "ตารางทั้งหมด (N)" คนละรูปแบบกับบรรทัดอื่น
if (preg_match('/ตารางทั้งหมด\s*\((\d+)\)/u', $dbMap, $m)
    && (int) $m[1] !== $realCounts['ตาราง']) {
    $wrongNums[] = sprintf('docs/ai-context/DATABASE_MAP.md → หัวข้อเขียนว่า %d ตาราง (จริง %d)',
        (int) $m[1], $realCounts['ตาราง']);
}

check('DOC-G3', !$wrongNums,
    'ตัวเลขที่เอกสารอ้างตรงกับของจริง (ตาราง ' . $realCounts['ตาราง']
        . ' · Repository ' . $realCounts['Repository'] . ' · Service ' . $realCounts['Service'] . ')',
    "🔴 ตัวเลขไม่ตรง " . count($wrongNums) . " จุด:\n       " . implode("\n       ", $wrongNums));

// ============================================================
// H. อีเมล — เอกสารต้องไม่พูดต่ำกว่าหรือสูงกว่าที่โค้ดทำได้
// ============================================================
echo "\n── H. เอกสารกับความสามารถเรื่องอีเมลตรงกัน ──\n";

/**
 * 🔴 ความจริงต้องมาจากโค้ด ไม่ใช่ฝังไว้ในเทสต์
 *    sendMail() ถูกเรียกจากไฟล์ไหนบ้าง = ระบบส่งเมลในสถานการณ์ไหนได้บ้างจริง ๆ
 */
$mailerExists = is_file($ROOT . '/includes/mailer.php')
    && str_contains((string) file_get_contents($ROOT . '/includes/mailer.php'), 'function sendMail');
$mailCallers = [];
foreach (['forgot_password.php', 'admin/settings.php', 'admin/borrows.php', 'admin/members.php',
          'cron/expire_reservations.php', 'cron/cleanup_tokens.php'] as $cand) {
    $f = $ROOT . '/' . $cand;
    if (is_file($f) && preg_match('/(?<!function )\bsendMail\s*\(/', (string) file_get_contents($f))) {
        $mailCallers[] = $cand;
    }
}
check('DOC-H0', $mailerExists && $mailCallers,
    'ดึงความจริงจากโค้ดได้: mailer.php มีจริง · ถูกเรียกจาก ' . implode(', ', $mailCallers),
    '🔴 หา sendMail() ในโค้ดไม่เจอ — ต้องแก้ตัวดึงในเทสต์นี้ ไม่ใช่แก้เอกสาร');

/**
 * 🔴 ห้ามบอกว่า "ไม่มีอีเมลเลย" ในเมื่อส่งลิงก์รีเซ็ตรหัสผ่านได้แล้ว
 *    เอกสารที่พูดต่ำกว่าความจริงทำให้คนขายพูดตามแล้วเสียของที่มีอยู่
 * 🧠 ยกเว้นบรรทัดที่กำลัง "ห้ามพูด" ประโยคนั้นอยู่ — คือคำสั่งให้คนขาย ไม่ใช่ข้อความเท็จ
 */
$deniesMail = [];
foreach ($docFiles as $file) {
    foreach (explode("\n", (string) file_get_contents($file)) as $i => $line) {
        if (preg_match('/ไม่ส่งอีเมลเลย|ไม่มีอีเมลเลย|ไม่มีระบบส่ง\s*email|ระบบนี้ไม่ส่งอีเมล/u', $line)
            && !preg_match('/ห้ามพูด|ไม่จริงแล้ว|เคยเขียน/u', $line)) {
            $deniesMail[] = sprintf('%s:%d', $rel($file), $i + 1);
        }
    }
}
check('DOC-H1', !$mailerExists || !$deniesMail,
    'ไม่มีเอกสารไหนบอกว่า "ไม่มีอีเมลเลย" ทั้งที่ส่งลิงก์รีเซ็ตได้แล้ว',
    "🔴 ยังเขียนว่าไม่มีอีเมลที่:\n       " . implode("\n       ", $deniesMail));

/**
 * 🔴 กลับกัน — ห้ามอ้างว่าส่งอีเมล "แจ้งเตือน" ได้ ตราบที่ยังไม่มีจริง
 *    ตรวจว่าทุกครั้งที่พูดถึงอีเมลแจ้งเตือน ต้องมีคำปฏิเสธอยู่ในบรรทัดเดียวกัน
 */
$notifyClaims = [];
foreach ($docFiles as $file) {
    foreach (explode("\n", (string) file_get_contents($file)) as $i => $line) {
        if (preg_match('/(อีเมล|เมล|email)[^\n]{0,12}(แจ้งเตือน|เตือน)|แจ้งเตือน[^\n]{0,12}(ทางอีเมล|ทางเมล)/u', $line)
            && !preg_match('/ไม่|ห้าม|ยังไม่|ตั้งใจไม่/u', $line)) {
            $notifyClaims[] = sprintf('%s:%d → %s', $rel($file), $i + 1, mb_substr(trim($line), 0, 70));
        }
    }
}
check('DOC-H2', !$notifyClaims,
    'ไม่มีเอกสารไหนอ้างว่าส่งอีเมลแจ้งเตือนได้ — ของจริงส่งได้แค่ลิงก์รีเซ็ตรหัสผ่าน',
    "🔴 อ้างเกินจริงที่:\n       " . implode("\n       ", $notifyClaims));

// 🔴 ต้องบอกด้วยว่าปิดเป็นค่าเริ่มต้น ไม่งั้นลูกค้าติดตั้งแล้วรอเมลที่ไม่มีวันมา
$saysDefaultOff = false;
foreach ($docFiles as $file) {
    if (preg_match('/ปิด(ไว้)?เป็นค่าเริ่มต้น|ค่าเริ่มต้น(คือ)?ปิด/u', (string) file_get_contents($file))) {
        $saysDefaultOff = true;
    }
}
check('DOC-H3', !$mailerExists || $saysDefaultOff,
    'เอกสารบอกชัดว่าอีเมลปิดไว้เป็นค่าเริ่มต้น',
    '🔴 ไม่มีที่ไหนบอกว่าปิดเป็นค่าเริ่มต้น — ลูกค้าจะติดตั้งแล้วรอเมลที่ไม่มีวันมา');

// 🔴 DEPLOYMENT ห้ามเสนอการแก้ DB เป็นทางเดียวของการรีเซ็ตรหัสผ่าน
$depFile = $ROOT . '/docs/DEPLOYMENT.md';
$depLine = '';
foreach (explode("\n", (string) @file_get_contents($depFile)) as $line) {
    if (str_starts_with($line, '- **Password Reset:**')) { $depLine = $line; }
}
check('DOC-H4', $depLine === '' || preg_match('/SMTP|ตั้งค่าระบบ/u', $depLine),
    'DEPLOYMENT.md ชี้ไปที่การตั้งค่า SMTP ก่อน ไม่ได้บอกให้ไปแก้ฐานข้อมูลเป็นทางเดียว',
    '🔴 DEPLOYMENT.md ยังบอกให้รีเซ็ตรหัสผ่านผ่าน DB โดยตรง ทั้งที่ไม่ต้องแล้ว');

/**
 * 🔴 ชื่อหัวข้อที่เอกสารบอกให้ไปกด ต้องมีอยู่จริงบนหน้าจอ
 *
 * 🧠 เจอตอนทดสอบบน clone: เอกสารเขียนว่า "ตั้งค่าระบบ → การส่งอีเมล"
 *    แต่หัวข้อจริงคือ "อีเมล (สำหรับลิงก์รีเซ็ตรหัสผ่าน)" — ลูกค้าจะหาไม่เจอ
 *    เป็นบั๊กที่เพิ่งสร้างขึ้นเองตอนแก้เอกสารรอบนี้ ไม่ใช่ของเก่า
 * 🧠 ดึงหัวข้อจริงจาก settings.php ไม่ฝังรายชื่อไว้ — เปลี่ยนชื่อหัวข้อเมื่อไหร่
 *    เทสต์จะชี้ไปที่เอกสารที่ยังใช้ชื่อเก่าให้เอง
 */
$settingsSrc = (string) file_get_contents($ROOT . '/admin/settings.php');
preg_match_all('/><\/i>([^<]+)</u', $settingsSrc, $hm);
$realHeadings = array_values(array_filter(array_map('trim', $hm[1] ?? []), fn($h) => mb_strlen($h) >= 4));
check('DOC-J0', count($realHeadings) >= 3,
    'ดึงหัวข้อจริงจากหน้าตั้งค่าได้ ' . count($realHeadings) . ' หัวข้อ: ' . implode(' · ', array_slice($realHeadings, 0, 5)),
    '🔴 ดึงหัวข้อไม่ได้ — รูปแบบไฟล์เปลี่ยนไป ต้องแก้ตัวดึงในเทสต์นี้ ไม่ใช่แก้เอกสาร');

$badLabels = [];
foreach ($docFiles as $file) {
    foreach (explode("\n", (string) file_get_contents($file)) as $i => $line) {
        if (preg_match_all('/ตั้งค่าระบบ\s*→\s*([^|·<*\n]{2,40})/u', $line, $lm)) {
            foreach ($lm[1] as $label) {
                $label = trim(str_replace('**', '', $label));
                $found = false;
                foreach ($realHeadings as $h) {
                    if ($h === $label || str_starts_with($h, $label)) { $found = true; break; }
                }
                if (!$found) {
                    $badLabels[] = sprintf('%s:%d → "%s" ไม่มีบนหน้าจอ', $rel($file), $i + 1, $label);
                }
            }
        }
    }
}
check('DOC-J1', !$badLabels,
    'ทุกชื่อหัวข้อที่เอกสารบอกให้ไปกด มีอยู่จริงในหน้าตั้งค่า',
    "🔴 เอกสารบอกให้ไปกดหัวข้อที่ไม่มีอยู่จริง:\n       " . implode("\n       ", $badLabels)
        . "\n       หัวข้อที่มีจริง: " . implode(' · ', $realHeadings));

// ============================================================
// I. กระดิ่งแจ้งเตือนในหน้าเว็บ
// ============================================================
echo "\n── I. เอกสารรู้จักกระดิ่ง ──\n";

// 🔢 นับแถวจริงจากโค้ด — ห้ามฝังตัวเลขไว้ในเทสต์ ไม่งั้นเพิ่มแถวใหม่แล้วเทสต์จะฟ้องเอกสารที่ถูกอยู่
$adminBell  = substr_count((string) file_get_contents($ROOT . '/admin/header.php'), '$alertItems[] =');
$memberBell = substr_count((string) file_get_contents($ROOT . '/includes/header.php'), '$memberAlertItems[] =');
$healthRows = substr_count((string) file_get_contents($ROOT . '/app/Services/DashboardService.php'), "'severity'");
check('DOC-I0', $adminBell > 0 && $memberBell > 0,
    "ดึงจากโค้ดได้: กระดิ่งเจ้าหน้าที่ {$adminBell} แถว + สุขภาพระบบ {$healthRows} แถว · สมาชิก {$memberBell} แถว",
    '🔴 นับแถวกระดิ่งจากโค้ดไม่ได้ — ต้องแก้ตัวดึงในเทสต์นี้');

$mentionsBell = [];
foreach ($docFiles as $file) {
    if (preg_match('/กระดิ่ง/u', (string) file_get_contents($file))) { $mentionsBell[] = $rel($file); }
}
check('DOC-I1', !$adminBell || $mentionsBell,
    'เอกสารพูดถึงกระดิ่งแล้วที่: ' . implode(', ', $mentionsBell),
    "🔴 มีกระดิ่ง {$adminBell} แถวในโค้ด แต่ไม่มีเอกสารไหนพูดถึงเลย — ลูกค้าและคนขายไม่มีทางรู้ว่ามี");

// 🔴 ห้ามบอกลอย ๆ ว่า "ไม่มีการแจ้งเตือน" — ต้องระบุว่าไม่มีทางไหน (อีเมล/LINE/push)
$deniesAlerts = [];
foreach ($docFiles as $file) {
    foreach (explode("\n", (string) file_get_contents($file)) as $i => $line) {
        if (preg_match('/ไม่มี(ระบบ)?(การ)?แจ้งเตือน/u', $line)
            && !preg_match('/อีเมล|เมล|email|LINE|push|ทางไปรษณีย์|ห้ามพูด/u', $line)) {
            $deniesAlerts[] = sprintf('%s:%d → %s', $rel($file), $i + 1, mb_substr(trim($line), 0, 70));
        }
    }
}
check('DOC-I2', !$deniesAlerts,
    'ไม่มีที่ไหนบอกลอย ๆ ว่า "ไม่มีการแจ้งเตือน" — ระบุช่องทางเสมอ',
    "🔴 บอกลอย ๆ ว่าไม่มีการแจ้งเตือน ทั้งที่มีกระดิ่ง:\n       " . implode("\n       ", $deniesAlerts));

// 🔴 กระดิ่งคำนวณตอนโหลดหน้า ห้ามเขียนว่าอัปเดตเอง/เรียลไทม์
$hasPolling = preg_match('/setInterval|EventSource|WebSocket/', 
    (string) file_get_contents($ROOT . '/admin/header.php'));
$claimsLive = [];
foreach ($docFiles as $file) {
    foreach (explode("\n", (string) file_get_contents($file)) as $i => $line) {
        if (preg_match('/กระดิ่ง/u', $line)
            && preg_match('/เรียลไทม์|real-?time|อัปเดต(ให้)?เอง|อัตโนมัติทุก/u', $line)
            && !preg_match('/ไม่|ห้าม/u', $line)) {
            $claimsLive[] = sprintf('%s:%d', $rel($file), $i + 1);
        }
    }
}
check('DOC-I3', $hasPolling || !$claimsLive,
    'ไม่มีเอกสารไหนอ้างว่ากระดิ่งเรียลไทม์ — โค้ดคำนวณตอนโหลดหน้าเท่านั้น',
    "🔴 อ้างว่าอัปเดตเองที่:\n       " . implode("\n       ", $claimsLive));

// ============================================================
// ============================================================
echo "\n══════════════════════════════════════\n";
printf(" RESULTS: %d/%d passed (%.1f%%)%s\n",
    $results['passed'], $results['total'],
    $results['total'] ? $results['passed'] / $results['total'] * 100 : 0,
    $results['failed'] ? ' | ' . $results['failed'] . ' FAILED' : '');
echo "══════════════════════════════════════\n";

exit($results['failed'] > 0 ? 1 : 0);

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
$contextMarkers = ['หน้าตั้งค่า', 'ตั้งค่าระบบ', 'ชั้นสำรอง', '3 ชั้น', 'สามชั้น', 'ไม่มีผลอีก', 'ค่าตั้งต้น'];
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
echo "\n══════════════════════════════════════\n";
printf(" RESULTS: %d/%d passed (%.1f%%)%s\n",
    $results['passed'], $results['total'],
    $results['total'] ? $results['passed'] / $results['total'] * 100 : 0,
    $results['failed'] ? ' | ' . $results['failed'] . ' FAILED' : '');
echo "══════════════════════════════════════\n";

exit($results['failed'] > 0 ? 1 : 0);

<?php
/**
 * Admin: System Settings - ตั้งค่าระบบ
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้จัดการค่า settings ที่เก็บใน DB (ตาราง settings)
 * - มี 2 ฟอร์มแยกกัน แยกด้วย hidden field `form`:
 *     form=rules → กฎการยืม-คืน (ค่าปรับ/วันยืม/โควตา/วันหมดอายุการจอง)
 *     form=card  → บัตรสมาชิก (ชื่อหน่วยงาน + สี 2 สี)
 * - สิทธิ์: admin เท่านั้น (staff แก้ไม่ได้)
 * 
 * 🧠 กฎการยืมต่างจากค่าอื่นตรงที่อ่านเรียง 3 ชั้น: settings → .env → default
 *    ทะเบียนกฎ (label/หน่วย/min/max) อยู่ที่ ruleDefinitions() ใน includes/rules.php
 *    **ที่เดียว** — หน้านี้ทั้งสร้างฟอร์มและตรวจค่าจากทะเบียนตัวเดียวกัน
 *    จะได้ไม่มีทางที่ฟอร์มยอมรับค่าที่ระบบเอาไปใช้ไม่ได้
 *    ⚙️ เพิ่มกฎใหม่ → เพิ่มใน ruleDefinitions() อย่างเดียว หน้านี้ไม่ต้องแก้
 * 
 * 📂 Flow:
 * 1. POST → validate + บันทึกผ่าน SettingsRepository::set() (upsert)
 * 2. GET → โหลดค่าจาก SettingsRepository แสดงใน form
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/../bootstrap.php';
// 🔒 [AUTH] Admin only — staff ไม่ควรเปลี่ยนการตั้งค่าระบบ (เช่น ชื่อหน่วยงาน, สีบัตร)
requireAdmin();

require_once __DIR__ . '/../app/Repositories/ClosedDayRepository.php';
$pdo = getDB();
// 📅 วันที่ห้องสมุดไม่เปิดทำการ — ใช้หักออกจากการคิดค่าปรับ
$closedDayRepo = new \App\Repositories\ClosedDayRepository($pdo);

// ── POST: บันทึกการตั้งค่า ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ [SECURITY] CSRF — ป้องกันถูกหลอกให้เปลี่ยนค่าระบบโดยไม่รู้ตัว
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token ไม่ถูกต้อง');
    } elseif (($_POST['form'] ?? 'card') === 'closed_add') {
        // ══════════════════════════════════════════════════
        // 📅 [วันปิดทำการ] เพิ่มช่วงวันที่ห้องสมุดไม่เปิด
        // ══════════════════════════════════════════════════
        $start = trim($_POST['start_date'] ?? '');
        $end   = trim($_POST['end_date'] ?? '');
        $note  = trim($_POST['note'] ?? '');
        $errors = [];

        // 🛡️ [VALIDATION] รูปแบบวันที่ต้องเป็น Y-m-d จริง ๆ
        //    checkdate() กันวันที่ที่มีอยู่ในรูปแบบแต่ไม่มีจริง เช่น 2026-02-30
        $parse = function (string $d): ?array {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return null;
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $m : null;
        };
        if (!$parse($start)) $errors[] = 'วันเริ่มต้นไม่ถูกต้อง';
        if (!$parse($end))   $errors[] = 'วันสิ้นสุดไม่ถูกต้อง';
        if ($note === '')    $errors[] = 'กรุณากรอกเหตุผล (เช่น วันหยุดนักขัตฤกษ์ / ปิดปรับปรุง)';
        if (mb_strlen($note) > 255) $errors[] = 'เหตุผลต้องไม่เกิน 255 ตัวอักษร';

        // 🔴 วันสิ้นสุดต้องไม่มาก่อนวันเริ่ม — ไม่งั้นได้ช่วงที่ไม่มีวันไหนอยู่ในนั้นเลย
        //    บันทึกไปก็ไม่มีผลกับค่าปรับ แต่ขึ้นในตารางเหมือนตั้งค่าสำเร็จ = เข้าใจผิด
        if (!$errors && $start > $end) {
            $errors[] = 'วันสิ้นสุดต้องไม่มาก่อนวันเริ่มต้น';
        }

        if ($errors) {
            setFlash('error', implode(' | ', $errors));
        } else {
            $closedDayRepo->create($start, $end, $note);
            setFlash('success', 'บันทึกวันปิดทำการแล้ว — มีผลกับการคิดค่าปรับทันที รวมรายการที่ยืมไปก่อนหน้านี้');
        }
        redirect('settings.php');
    } elseif (($_POST['form'] ?? 'card') === 'closed_delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $closedDayRepo->delete($id);
            setFlash('success', 'ลบวันปิดทำการแล้ว');
        }
        redirect('settings.php');
    } elseif (($_POST['form'] ?? 'card') === 'mail') {
        // ══════════════════════════════════════════════════
        // 📧 ฟอร์ม "อีเมล (SMTP)" — ใช้ส่งลิงก์รีเซ็ตรหัสผ่านเท่านั้น
        // ══════════════════════════════════════════════════
        // 🔴 ปิดเป็นค่าเริ่มต้นเสมอ · ลูกค้าที่ไม่ตั้งค่าต้องใช้ระบบได้ครบเหมือนเดิม
        $mailEnabled = isset($_POST['mail_enabled']) ? '1' : '0';
        $mailHost    = trim($_POST['mail_host'] ?? '');
        $mailPort    = trim($_POST['mail_port'] ?? '587');
        $mailSecure  = in_array($_POST['mail_secure'] ?? 'tls', ['tls', 'ssl', 'none'], true)
                     ? $_POST['mail_secure'] : 'tls';
        $mailUser    = trim($_POST['mail_username'] ?? '');
        $mailFrom    = trim($_POST['mail_from_email'] ?? '');
        $mailFromNm  = trim($_POST['mail_from_name'] ?? '');

        $errors = [];
        if ($mailEnabled === '1') {
            if ($mailHost === '')  $errors[] = 'กรุณากรอกเซิร์ฟเวอร์อีเมล (SMTP host)';
            if (!ctype_digit($mailPort) || (int) $mailPort < 1 || (int) $mailPort > 65535) {
                $errors[] = 'พอร์ตต้องเป็นตัวเลข 1–65535';
            }
            if (!filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'อีเมลผู้ส่งไม่ถูกต้อง';
            }
        }

        if ($errors) {
            setFlash('error', implode(' | ', $errors));
            redirect('settings.php');
        }

        updateSetting('mail_enabled',    $mailEnabled);
        updateSetting('mail_host',       $mailHost);
        updateSetting('mail_port',       (string) (int) $mailPort);
        updateSetting('mail_secure',     $mailSecure);
        updateSetting('mail_username',   $mailUser);
        updateSetting('mail_from_email', $mailFrom);
        updateSetting('mail_from_name',  $mailFromNm !== '' ? $mailFromNm : APP_NAME);

        // 🔴 รหัสผ่านอัปเดตเฉพาะตอนกรอกใหม่ — เว้นว่าง = เก็บของเดิม
        //    ฟอร์มไม่เคยแสดงรหัสเดิมกลับมา ถ้าเขียนทับด้วยค่าว่างทุกครั้งที่บันทึก
        //    ลูกค้าจะแก้แค่พอร์ตแล้วรหัสหายโดยไม่รู้ตัว
        if (($_POST['mail_password'] ?? '') !== '') {
            updateSetting('mail_password', $_POST['mail_password']);
        }

        setFlash('success', $mailEnabled === '1'
            ? 'บันทึกการตั้งค่าอีเมลแล้ว — กดปุ่ม "ทดสอบส่งเมล" เพื่อยืนยันว่าตั้งถูก'
            : 'ปิดการส่งอีเมลแล้ว — ระบบจะให้ติดต่อเคาน์เตอร์แทน');
        redirect('settings.php');

    } elseif (($_POST['form'] ?? 'card') === 'mail_test') {
        // ══════════════════════════════════════════════════
        // 📨 ปุ่ม "ทดสอบส่งเมล"
        // ══════════════════════════════════════════════════
        // 🔴 มีไว้เพื่อให้ลูกค้ารู้ **ตอนตั้งค่า** ว่าตั้งถูกไหม
        //    ไม่ใช่ไปรู้ตอนสมาชิกยืนรอเมลที่ไม่มีวันมา
        require_once __DIR__ . '/../includes/mailer.php';
        $target = trim($_POST['test_email'] ?? '');
        if (!filter_var($target, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'กรุณากรอกอีเมลปลายทางที่จะใช้ทดสอบ');
            redirect('settings.php');
        }
        $res = sendMail(
            $target,
            'ทดสอบการส่งอีเมล — ' . APP_NAME,
            "อีเมลฉบับนี้ส่งจากระบบห้องสมุดเพื่อทดสอบการตั้งค่า
"
            . "ถ้าคุณได้รับฉบับนี้ แปลว่าตั้งค่าถูกต้องแล้ว

"
            . "ส่งเมื่อ: " . date('d/m/Y H:i:s')
        );
        setFlash($res['success'] ? 'success' : 'error', $res['success']
            ? "ส่งอีเมลทดสอบไปที่ {$target} สำเร็จ — ตรวจกล่องจดหมาย (รวมถังสแปม)"
            : 'ส่งไม่สำเร็จ: ' . $res['error']);
        redirect('settings.php');

    } elseif (($_POST['form'] ?? 'card') === 'rules') {
        // ══════════════════════════════════════════════════
        // 📥 ฟอร์ม "กฎการยืม-คืน"
        // ══════════════════════════════════════════════════
        // 🧠 วนตามทะเบียนกฎ — ไม่ hardcode ชื่อ field ทีละอัน
        //    เพิ่มกฎใหม่ใน ruleDefinitions() แล้วส่วนนี้รองรับเองทันที
        $errors  = [];
        $toSave  = [];

        foreach (ruleDefinitions() as $constant => $rule) {
            $raw = trim((string) ($_POST[$rule['setting']] ?? ''));

            if ($raw === '') {
                $errors[] = 'กรุณากรอก' . $rule['label'];
                continue;
            }
            // 🛡️ [VALIDATION] ต้องเป็นจำนวนเต็มไม่ติดลบเท่านั้น
            //    ctype_digit ปฏิเสธทั้งค่าติดลบ ทศนิยม และตัวอักษรในคราวเดียว
            if (!ctype_digit($raw)) {
                $errors[] = $rule['label'] . ' ต้องเป็นตัวเลขจำนวนเต็ม';
                continue;
            }

            $value = (int) $raw;
            if ($value < $rule['min'] || $value > $rule['max']) {
                $errors[] = sprintf(
                    '%s ต้องอยู่ระหว่าง %s ถึง %s %s',
                    $rule['label'],
                    number_format($rule['min']),
                    number_format($rule['max']),
                    $rule['unit']
                );
                continue;
            }

            $toSave[$rule['setting']] = (string) $value;
        }

        if (!empty($errors)) {
            setFlash('error', implode(' | ', $errors));
        } else {
            foreach ($toSave as $key => $value) {
                updateSetting($key, $value);
            }
            setFlash('success', 'บันทึกกฎการยืม-คืนเรียบร้อยแล้ว — มีผลกับรายการที่บันทึกหลังจากนี้');
        }
        redirect('settings.php');
    } else {
        // 📥 รับค่าจาก form + validate ก่อนบันทึก
        $orgName = trim($_POST['org_name'] ?? '');
        $colorPrimary = trim($_POST['card_color_primary'] ?? '#1e3a8a');
        $colorSecondary = trim($_POST['card_color_secondary'] ?? '#3b82f6');
        
        $errors = [];
        
        if (empty($orgName)) {
            $errors[] = 'กรุณากรอกชื่อหน่วยงาน';
        } elseif (mb_strlen($orgName) > 100) {
            $errors[] = 'ชื่อหน่วยงานต้องไม่เกิน 100 ตัวอักษร';
        }
        
        // 🎨 [VALIDATION] ตรวจ format สี (#RRGGBB) — ป้องกัน XSS ผ่าน CSS injection
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $colorPrimary)) {
            $errors[] = 'รูปแบบสีหลักไม่ถูกต้อง';
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $colorSecondary)) {
            $errors[] = 'รูปแบบสีรองไม่ถูกต้อง';
        }
        
        if (!empty($errors)) {
            setFlash('error', implode(' | ', $errors));
        } else {
            // [WRITE] บันทึกผ่าน updateSetting() — upsert pattern (INSERT ... ON DUPLICATE KEY UPDATE)
            updateSetting('org_name', $orgName);
            updateSetting('card_color_primary', $colorPrimary);
            updateSetting('card_color_secondary', $colorSecondary);
            
            setFlash('success', 'บันทึกการตั้งค่าเรียบร้อยแล้ว');
        }
        redirect('settings.php');
    }
}

// ── GET: โหลดค่าปัจจุบันจาก DB ──
// 🧠 ค่าเหล่านี้อยู่ในตาราง settings (ไม่ใช่ .env) — admin ปรับได้ผ่าน UI
$orgName = getSetting('org_name', 'LIBRARY CARD');
$cardColorPrimary = getSetting('card_color_primary', '#1e3a8a');
$cardColorSecondary = getSetting('card_color_secondary', '#3b82f6');

$pageTitle = 'ตั้งค่าระบบ';
require_once __DIR__ . '/header.php';
?>

<div class="mb-6">
    <h3 class="text-2xl font-bold text-gray-800 flex items-center">
        <i class="bi bi-gear-fill mr-3 text-primary-600"></i>
        ตั้งค่าระบบ (System Settings)
    </h3>
    <p class="text-gray-500">ปรับแต่งค่าต่างๆ ของระบบ</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Settings Forms -->
    <div class="lg:col-span-2 space-y-6">

        <!-- ══ กฎการยืม-คืน ══ -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                <h5 class="font-bold text-gray-800 flex items-center">
                    <i class="bi bi-sliders mr-2 text-primary-600"></i>กฎการยืม-คืน
                </h5>
                <p class="text-xs text-gray-500 mt-0.5">แก้แล้วมีผลกับรายการที่บันทึกหลังจากนี้ — ไม่ย้อนไปแก้รายการเดิม</p>
            </div>

            <div class="p-6">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="form" value="rules">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <?php foreach (ruleDefinitions() as $constant => $rule): ?>
                            <div>
                                <label for="<?= e($rule['setting']) ?>" class="block text-sm font-medium text-gray-700 mb-1">
                                    <?= e($rule['label']) ?>
                                </label>
                                <div class="flex items-center space-x-2">
                                    <input type="number"
                                           id="<?= e($rule['setting']) ?>"
                                           name="<?= e($rule['setting']) ?>"
                                           value="<?= e((string) constant($constant)) ?>"
                                           min="<?= (int) $rule['min'] ?>"
                                           max="<?= (int) $rule['max'] ?>"
                                           step="1" required
                                           class="flex-1 rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm">
                                    <span class="text-sm text-gray-500 whitespace-nowrap w-10"><?= e($rule['unit']) ?></span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500"><?= e($rule['help']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 text-xs text-blue-800 leading-relaxed">
                        <i class="bi bi-info-circle mr-1"></i>
                        ค่าที่กรอกที่นี่จะถูกใช้ก่อนค่าในไฟล์ตั้งค่าของระบบเสมอ
                        ถ้าอยากกลับไปใช้ค่าเดิมของผู้ติดตั้ง ให้ติดต่อผู้ดูแลระบบเพื่อล้างค่าที่บันทึกไว้
                    </div>

                    <div class="pt-4 border-t border-gray-100 flex justify-end">
                        <button type="submit" class="px-6 py-2.5 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-xl shadow-lg shadow-primary-500/30 transition-all transform hover:scale-105">
                            <i class="bi bi-save mr-2"></i>บันทึกกฎการยืม-คืน
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ══ อีเมล (SMTP) ══ -->
        <?php // 📧 ระบบส่งอีเมล **อย่างเดียว** คือลิงก์รีเซ็ตรหัสผ่าน
              //    การแจ้งเตือนใกล้ครบกำหนดตั้งใจไม่ทำผ่านอีเมล (ต้องใช้ cron + ล้มเหลวเงียบ)
              //    ใช้ "ใบรายชื่อโทรตาม" กับ "กระดิ่ง" แทน — ดู docs/LIMITATIONS.md
              require_once __DIR__ . '/../includes/mailer.php';
              $mailCfg = mailSettings();
              // 🔴 ไม่ดึงรหัสผ่านมาแสดงในฟอร์ม — แสดงแค่ว่า "ตั้งไว้แล้วหรือยัง"
              $hasMailPassword = (string) getSetting('mail_password', '') !== ''; ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                <h5 class="font-bold text-gray-800 flex items-center">
                    <i class="bi bi-envelope-at mr-2 text-primary-600"></i>อีเมล (สำหรับลิงก์รีเซ็ตรหัสผ่าน)
                </h5>
                <p class="text-xs text-gray-500 mt-0.5">
                    ปิดไว้ก็ใช้ระบบได้ครบทุกอย่าง — สมาชิกที่ลืมรหัสจะให้ติดต่อเคาน์เตอร์แทน
                </p>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="form" value="mail">

                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="mail_enabled" value="1" <?= $mailCfg['enabled'] ? 'checked' : '' ?>
                           class="mt-1 w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500">
                    <span>
                        <span class="text-sm font-medium text-gray-800">เปิดให้ระบบส่งลิงก์รีเซ็ตรหัสผ่านทางอีเมล</span>
                        <span class="block text-xs text-gray-500">ต้องใช้บัญชีอีเมลจริงของห้องสมุด (Gmail / Google Workspace / เมลองค์กร)</span>
                    </span>
                </label>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">เซิร์ฟเวอร์ (SMTP host)</label>
                        <input type="text" name="mail_host" value="<?= e($mailCfg['host']) ?>"
                               placeholder="smtp.gmail.com"
                               class="w-full rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">พอร์ต</label>
                        <input type="text" name="mail_port" value="<?= e((string) $mailCfg['port']) ?>"
                               class="w-full rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">การเข้ารหัส</label>
                        <select name="mail_secure" class="w-full rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm">
                            <option value="tls"  <?= $mailCfg['secure'] === 'tls'  ? 'selected' : '' ?>>STARTTLS (พอร์ต 587)</option>
                            <option value="ssl"  <?= $mailCfg['secure'] === 'ssl'  ? 'selected' : '' ?>>SSL (พอร์ต 465)</option>
                            <option value="none" <?= $mailCfg['secure'] === 'none' ? 'selected' : '' ?>>ไม่เข้ารหัส</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อผู้ใช้</label>
                        <input type="text" name="mail_username" value="<?= e($mailCfg['username']) ?>"
                               placeholder="library@school.ac.th" autocomplete="off"
                               class="w-full rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">รหัสผ่าน</label>
                        <input type="password" name="mail_password" autocomplete="new-password"
                               placeholder="<?= $hasMailPassword ? 'ตั้งไว้แล้ว — เว้นว่างถ้าไม่เปลี่ยน' : 'ยังไม่ได้ตั้ง' ?>"
                               class="w-full rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">อีเมลผู้ส่ง</label>
                        <input type="text" name="mail_from_email" value="<?= e($mailCfg['from_email']) ?>"
                               placeholder="library@school.ac.th"
                               class="w-full rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อผู้ส่งที่แสดง</label>
                        <input type="text" name="mail_from_name" value="<?= e($mailCfg['from_name']) ?>"
                               class="w-full rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm">
                    </div>
                </div>

                <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-xs text-amber-900 leading-relaxed">
                    <i class="bi bi-exclamation-triangle mr-1"></i>
                    <strong>อ่านก่อนเปิดใช้</strong><br>
                    • Gmail ใช้รหัสผ่านปกติไม่ได้ ต้องสร้าง <strong>App Password</strong> (ต้องเปิด 2FA ก่อน)<br>
                    • รหัสผ่านนี้เก็บในฐานข้อมูลเพื่อให้ส่งเมลได้ — ใครเข้าถึงฐานข้อมูลได้จะเห็น
                      <strong>ควรใช้บัญชีที่สร้างไว้สำหรับส่งเมลระบบโดยเฉพาะ</strong><br>
                    • ระบบส่งอีเมล<strong>อย่างเดียว</strong>คือลิงก์รีเซ็ตรหัสผ่าน ไม่มีการแจ้งเตือนครบกำหนดทางอีเมล
                </div>

                <button type="submit" class="px-5 py-2.5 bg-primary-600 text-white rounded-xl hover:bg-primary-700 font-medium text-sm">
                    <i class="bi bi-save mr-1"></i>บันทึกการตั้งค่าอีเมล
                </button>
            </form>

            <?php // 📨 ปุ่มทดสอบแยกฟอร์ม — ต้องกดได้โดยไม่ต้องบันทึกซ้ำ ?>
            <form method="POST" class="px-6 pb-6 pt-0 border-t border-gray-100 mt-2">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="form" value="mail_test">
                <p class="text-sm font-medium text-gray-700 mt-4 mb-2">ทดสอบส่งเมล</p>
                <div class="flex flex-col sm:flex-row gap-2">
                    <input type="text" name="test_email" placeholder="กรอกอีเมลของคุณเพื่อทดสอบ"
                           class="flex-1 rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm">
                    <button type="submit" class="px-5 py-2.5 bg-gray-800 text-white rounded-xl hover:bg-gray-900 font-medium text-sm whitespace-nowrap">
                        <i class="bi bi-send mr-1"></i>ส่งทดสอบ
                    </button>
                </div>
                <p class="text-xs text-gray-500 mt-2">
                    ระบบจะบอกผลตามจริง ถ้าส่งไม่สำเร็จจะแสดงสาเหตุ ไม่บอกว่า "ส่งแล้ว" ลอย ๆ
                </p>
            </form>
        </div>

        <!-- ══ วันปิดทำการ ══ -->
        <?php // 📅 ค่าปรับเดิมนับทุกวันตามปฏิทิน ไม่สนใจว่าห้องสมุดเปิดหรือไม่
              //    ยืมก่อนหยุดยาว ครบกำหนดระหว่างที่ปิด กลับมาคืนวันแรกที่เปิด → โดนปรับ
              //    ทั้งที่ไม่มีวันไหนให้มาคืนได้เลย ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                <h5 class="font-bold text-gray-800 flex items-center">
                    <i class="bi bi-calendar-x mr-2 text-primary-600"></i>วันที่ห้องสมุดไม่เปิดทำการ
                </h5>
                <p class="text-xs text-gray-500 mt-0.5">
                    วันที่ระบุไว้ที่นี่จะ<strong>ไม่ถูกคิดค่าปรับ</strong> — ใช้ได้ทั้งวันหยุดวันเดียวและช่วงปิดปรับปรุงยาว
                </p>
            </div>

            <div class="p-6 space-y-6">
                <form method="POST" class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="form" value="closed_add">

                    <div class="sm:col-span-3">
                        <label class="block text-sm font-medium text-gray-700 mb-1">ตั้งแต่วันที่</label>
                        <input type="date" name="start_date" required
                               class="w-full rounded-xl border-gray-300 focus:ring-primary-500 focus:border-primary-500 sm:text-sm h-11">
                    </div>
                    <div class="sm:col-span-3">
                        <label class="block text-sm font-medium text-gray-700 mb-1">ถึงวันที่</label>
                        <input type="date" name="end_date" required
                               class="w-full rounded-xl border-gray-300 focus:ring-primary-500 focus:border-primary-500 sm:text-sm h-11">
                        <p class="mt-1 text-xs text-gray-500">ปิดวันเดียว = ใส่วันเดียวกันทั้งสองช่อง</p>
                    </div>
                    <div class="sm:col-span-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">เหตุผล</label>
                        <input type="text" name="note" required maxlength="255"
                               placeholder="เช่น วันหยุดนักขัตฤกษ์ / ปิดปรับปรุง"
                               class="w-full rounded-xl border-gray-300 focus:ring-primary-500 focus:border-primary-500 sm:text-sm h-11">
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="w-full h-11 px-4 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-xl transition-colors">
                            <i class="bi bi-plus-lg mr-1"></i>เพิ่ม
                        </button>
                    </div>
                </form>

                <?php $closedDays = $closedDayRepo->findAll(); ?>
                <?php if (!$closedDays): ?>
                    <div class="text-center py-8 text-gray-400 border border-dashed border-gray-200 rounded-xl">
                        <i class="bi bi-calendar-check text-4xl mb-2 inline-block text-gray-300"></i>
                        <p class="text-sm">ยังไม่ได้ระบุวันปิด — ตอนนี้ค่าปรับนับทุกวันตามปฏิทิน</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto border border-gray-100 rounded-xl">
                        <table class="w-full text-sm text-left sticky-action">
                            <thead class="text-xs text-gray-500 uppercase bg-gray-50 border-b border-gray-100">
                                <tr>
                                    <th class="px-4 py-3 font-medium">ช่วงวันที่</th>
                                    <th class="px-4 py-3 font-medium">จำนวนวัน</th>
                                    <th class="px-4 py-3 font-medium">เหตุผล</th>
                                    <th class="px-4 py-3 font-medium text-right">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($closedDays as $cd): ?>
                                    <?php
                                    $days = (new DateTime($cd['start_date']))->diff(new DateTime($cd['end_date']))->days + 1;
                                    $rangeText = $cd['start_date'] === $cd['end_date']
                                        ? formatDate($cd['start_date'])
                                        : formatDate($cd['start_date']) . ' – ' . formatDate($cd['end_date']);
                                    ?>
                                    <tr class="hover:bg-gray-50/50 transition-colors">
                                        <td class="px-4 py-3 whitespace-nowrap font-medium text-gray-800"><?= e($rangeText) ?></td>
                                        <td class="px-4 py-3 whitespace-nowrap text-gray-600"><?= number_format($days) ?> วัน</td>
                                        <td class="px-4 py-3 text-gray-600"><?= e($cd['note']) ?></td>
                                        <td class="px-4 py-3 whitespace-nowrap text-right">
                                            <?php // 🔴 [F-47] กล่องยืนยันต้องบอกว่าลบช่วงไหน ?>
                                            <form method="POST" class="inline-block"
                                                  onsubmit="return confirmSubmit(this, <?= jsString("ลบวันปิดทำการ
{$rangeText}
{$cd['note']}

ค่าปรับของช่วงนี้จะถูกคิดใหม่ทันที") ?>, {title: 'ลบวันปิดทำการ', confirmText: 'ลบ', confirmClass: 'danger'})">
                                                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                                <input type="hidden" name="form" value="closed_delete">
                                                <input type="hidden" name="id" value="<?= (int) $cd['id'] ?>">
                                                <button type="submit" class="p-1.5 text-red-500 hover:text-red-700 hover:bg-red-50 rounded-lg transition-colors" title="ลบ">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-amber-50 border border-amber-100 rounded-xl p-4 text-xs text-amber-800 leading-relaxed">
                        <i class="bi bi-info-circle mr-1"></i>
                        <strong>หักเฉพาะวันที่เลยกำหนดคืนแล้ว</strong> — ไม่ได้เลื่อนวันครบกำหนดให้
                        ถ้าครบกำหนดตรงกับวันปิดพอดี ระบบยังถือว่าครบกำหนดวันนั้น
                        แต่วันถัดไปที่ปิดจะไม่ถูกคิดเงิน
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══ สำรองข้อมูล ══ -->
        <?php // 🔴 [UAT รอบ 3 ต.3] เดิมไม่มีปุ่มสำรองข้อมูลเลย เอกสารบอกให้ลูกค้า
              //    ไปใช้ mysqldump/phpMyAdmin เอง ซึ่งบรรณารักษ์ที่ดูแลคนเดียวทำไม่ได้ ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                <h5 class="font-bold text-gray-800"><i class="bi bi-shield-check text-slate-600 mr-2"></i>สำรองข้อมูล</h5>
                <p class="text-xs text-gray-500 mt-0.5">
                    ดาวน์โหลดข้อมูลทั้งระบบเก็บไว้ — ถ้าเครื่องนี้พัง จะเอาไฟล์นี้กู้คืนได้
                </p>
            </div>

            <div class="p-6">
                <div class="rounded-lg bg-amber-50 border border-amber-200 p-4 mb-4 text-sm text-amber-900">
                    <p class="font-semibold mb-1">อ่านก่อนกด</p>
                    <ul class="list-disc list-inside space-y-1 text-xs leading-relaxed">
                        <li>ไฟล์ที่ได้มี<strong>ข้อมูลส่วนตัวของสมาชิกทุกคน</strong> — อีเมล เบอร์โทร
                            รหัสผ่านที่เข้ารหัสไว้ และรหัสผ่านอีเมลของห้องสมุด</li>
                        <li>เก็บไว้ในที่ปลอดภัย <strong>อย่าส่งต่อทางแชทหรืออีเมล</strong>โดยไม่ใส่รหัส</li>
                        <li>ควรสำรองอย่างน้อย<strong>เดือนละครั้ง</strong> และก่อนปิดเทอมทุกครั้ง
                            แล้วเก็บสำเนาไว้คนละที่กับเครื่องนี้ (เช่น แฟลชไดรฟ์)</li>
                        <li>วิธีกู้คืนเขียนไว้ในหัวไฟล์แล้ว เปิดด้วย Notepad อ่านได้</li>
                    </ul>
                </div>

                <form method="POST" action="<?= APP_URL ?>/admin/backup.php">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <button type="submit" class="inline-flex items-center px-4 py-2.5 bg-slate-700 hover:bg-slate-800 text-white text-sm font-medium rounded-lg transition-colors">
                        <i class="bi bi-download mr-2"></i>ดาวน์โหลดไฟล์สำรองข้อมูล
                    </button>
                </form>
                <p class="text-xs text-gray-400 mt-3">
                    ไฟล์จะถูกส่งให้ดาวน์โหลดทันที ไม่มีการเก็บสำเนาไว้บนเซิร์ฟเวอร์
                </p>
            </div>
        </div>

        <!-- ══ บัตรสมาชิก ══ -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                <h5 class="font-bold text-gray-800">ตั้งค่าบัตรสมาชิก (Member Card)</h5>
            </div>
            
            <div class="p-6">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="form" value="card">
                    
                    <div>
                        <label for="org_name" class="block text-sm font-medium text-gray-700 mb-1">
                            ชื่อหน่วยงาน / หัวบัตร
                        </label>
                        <input type="text" id="org_name" name="org_name" value="<?= e($orgName) ?>" required
                               class="w-full rounded-xl border-gray-300 focus:border-primary-500 focus:ring-primary-500 shadow-sm"
                               placeholder="เช่น A.B.C. SCHOOL LIBRARY">
                        <p class="mt-1 text-xs text-gray-500">ข้อความที่จะแสดงบนหัวบัตรสมาชิก</p>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="card_color_primary" class="block text-sm font-medium text-gray-700 mb-1">
                                สีธีมหลัก (Primary Color)
                            </label>
                            <div class="flex items-center space-x-2">
                                <input type="color" id="card_color_primary" name="card_color_primary" value="<?= e($cardColorPrimary) ?>" 
                                       class="h-10 w-14 p-1 rounded border border-gray-300 cursor-pointer">
                                <input type="text" value="<?= e($cardColorPrimary) ?>" readonly 
                                       class="flex-1 rounded-xl border-gray-300 bg-gray-50 text-gray-500 text-sm">
                            </div>
                        </div>
                        
                        <div>
                            <label for="card_color_secondary" class="block text-sm font-medium text-gray-700 mb-1">
                                สีธีมรอง (Secondary Color)
                            </label>
                            <div class="flex items-center space-x-2">
                                <input type="color" id="card_color_secondary" name="card_color_secondary" value="<?= e($cardColorSecondary) ?>" 
                                       class="h-10 w-14 p-1 rounded border border-gray-300 cursor-pointer">
                                <input type="text" value="<?= e($cardColorSecondary) ?>" readonly 
                                       class="flex-1 rounded-xl border-gray-300 bg-gray-50 text-gray-500 text-sm">
                            </div>
                        </div>
                    </div>
                    
                    <div class="pt-4 border-t border-gray-100 flex justify-end">
                        <button type="submit" class="px-6 py-2.5 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-xl shadow-lg shadow-primary-500/30 transition-all transform hover:scale-105">
                            <i class="bi bi-save mr-2"></i>บันทึกการตั้งค่า
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Preview Card -->
    <div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden sticky top-6">
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                <h5 class="font-bold text-gray-800">ตัวอย่าง (Preview)</h5>
            </div>
            <div class="p-6 flex justify-center bg-gray-100 min-h-[300px] items-center">
                <!-- CSS Filter to simulate card look tailored to settings -->
                <!-- Note: Ideally we'd use iframe, but let's approximate with inline css js -->
                <div id="cardPreview" class="relative bg-white rounded-lg shadow-md overflow-hidden border border-gray-200" style="width: 320px; height: 200px;">
                    <div id="previewSideBar" style="position: absolute; top: 0; left: 0; width: 15px; height: 100%; background: linear-gradient(180deg, <?= $cardColorPrimary ?> 0%, <?= $cardColorSecondary ?> 100%);"></div>
                    
                    <div style="margin-left: 20px; padding: 15px; height: 100%; display: flex; flex-direction: column;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 5px;">
                            <i class="bi bi-book-half text-xl" style="color: <?= $cardColorPrimary ?>;"></i>
                            <div id="previewOrgName" style="font-weight: 800; font-size: 14px; text-transform: uppercase; color: <?= $cardColorPrimary ?>;">
                                <?= e($orgName) ?>
                            </div>
                        </div>
                        
                        <div style="margin-top: 10px; padding-left: 10px;">
                            <div style="font-size: 10px; background: #e0f2fe; color: #0369a1; padding: 1px 6px; border-radius: 4px; display: inline-block; margin-bottom: 5px; font-weight: 600;">MEMBER</div>
                            <div style="font-size: 8px; color: #64748b;">NAME</div>
                            <div style="font-size: 14px; font-weight: 700; color: #0f172a;">Somchai Jaidee</div>
                            <div style="font-size: 14px; font-weight: 700; color: #0f172a;">ID: 000001</div>
                        </div>
                        
                        <div style="margin-top: auto; padding-left: 5px; opacity: 0.5;">
                            [Barcode Area]
                        </div>
                    </div>
                </div>
            </div>
            <p class="text-center text-xs text-gray-500 py-3">ตัวอย่างการแสดงผลเบื้องต้น</p>
        </div>
    </div>
</div>

<script>
    // Real-time Preview Logic
    const orgInput = document.getElementById('org_name');
    const color1Input = document.getElementById('card_color_primary');
    const color2Input = document.getElementById('card_color_secondary');
    
    const previewOrg = document.getElementById('previewOrgName');
    const previewBar = document.getElementById('previewSideBar');
    const previewIcon = document.querySelector('#cardPreview i');

    function updatePreview() {
        previewOrg.textContent = orgInput.value || 'LIBRARY CARD';
        previewOrg.style.color = color1Input.value;
        previewIcon.style.color = color1Input.value;
        previewBar.style.background = `linear-gradient(180deg, ${color1Input.value} 0%, ${color2Input.value} 100%)`;
    }

    orgInput.addEventListener('input', updatePreview);
    color1Input.addEventListener('input', updatePreview);
    color2Input.addEventListener('input', updatePreview);
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

<?php

/**
 * ==========================================================================
 * 📧 ตัวส่งอีเมล — SMTP client ขนาดเล็ก เขียนเอง ไม่พึ่งไลบรารีภายนอก
 * ==========================================================================
 *
 * 🧠 ทำไมไม่ใช้ `mail()` ของ PHP
 *    `mail()` ส่งผ่าน sendmail ในเครื่อง ซึ่ง **ไม่มีการยืนยันตัวตน (SMTP AUTH)**
 *    เมลจากเครื่องลูกค้าที่ไม่มีโดเมน/SPF/DKIM ของตัวเองจะถูกตีกลับหรือลงถังสแปม
 *    ผลคือ "ระบบบอกว่าส่งแล้ว แต่ไม่มีใครได้รับ" ซึ่งแย่กว่าไม่มีระบบส่งเลย
 *    → ต้องส่งผ่านบัญชีจริงของลูกค้า (Gmail / Google Workspace / เมลองค์กร)
 *
 * 🧠 ทำไมไม่ใช้ PHPMailer
 *    โปรเจกต์นี้ **ไม่มี Composer และต้องใช้งานออฟไลน์ได้** (ดู F-09)
 *    การเพิ่มไลบรารีขัดกับข้อนั้น · SMTP ที่ต้องใช้จริงมีไม่กี่คำสั่ง เขียนเองได้
 *
 * 🛡️ ขอบเขตที่ตั้งใจ **ไม่** รองรับ — บอกไว้ให้ชัดว่าไม่ใช่ของที่ลืม
 *    - ไฟล์แนบ · HTML mail · หลายผู้รับ · คิวส่ง · ลองส่งซ้ำอัตโนมัติ
 *    - ระบบนี้ส่งอีเมลอยู่อย่างเดียวคือ **ลิงก์รีเซ็ตรหัสผ่าน** ซึ่งเป็นข้อความล้วน 1 ฉบับ
 *    - การแจ้งเตือนใกล้ครบกำหนดตั้งใจไม่ทำ (ต้องใช้ cron + ล้มเหลวเงียบ)
 *      ใช้ "ใบรายชื่อโทรตาม" กับ "กระดิ่ง" แทน — ดู docs/LIMITATIONS.md
 */

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: อ่านการตั้งค่า SMTP จากตาราง settings
 * ==========================================================================
 * 📤 @return array{enabled:bool, host:string, port:int, secure:string,
 *                  username:string, password:string, from_email:string, from_name:string}
 *
 * 🔴 คืน `enabled = false` เมื่อตั้งค่าไม่ครบ — **ปิดไว้เป็นค่าเริ่มต้นเสมอ**
 *    ลูกค้าที่ไม่ตั้งค่าต้องใช้ระบบได้ครบเหมือนเดิม ไม่มีอะไรพัง
 */
function mailSettings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $defaults = [
        'enabled'    => false,
        'host'       => '',
        'port'       => 587,
        'secure'     => 'tls',   // tls (STARTTLS) | ssl | none
        'username'   => '',
        'password'   => '',
        'from_email' => '',
        'from_name'  => APP_NAME,
    ];

    try {
        // 📝 ยังไม่มี DB (ตอนติดตั้ง) → ปิดไว้ ไม่ใช่พัง
        if (!function_exists('getDB')) {
            return $cache = $defaults;
        }
        $rows = getDB()->query("SELECT setting_key, setting_value FROM settings")
                       ->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (\Throwable $e) {
        return $cache = $defaults;
    }

    $get = fn(string $k, $d) => (isset($rows["mail_{$k}"]) && $rows["mail_{$k}"] !== '') ? $rows["mail_{$k}"] : $d;

    $cfg = [
        'enabled'    => ($rows['mail_enabled'] ?? '0') === '1',
        'host'       => (string) $get('host', ''),
        'port'       => (int) $get('port', 587),
        'secure'     => (string) $get('secure', 'tls'),
        'username'   => (string) $get('username', ''),
        'password'   => (string) $get('password', ''),
        'from_email' => (string) $get('from_email', ''),
        'from_name'  => (string) $get('from_name', APP_NAME),
    ];

    // 🔴 เปิดสวิตช์แต่กรอกไม่ครบ = ถือว่าปิด — ดีกว่าพยายามส่งแล้วค้าง
    if ($cfg['host'] === '' || $cfg['from_email'] === '') {
        $cfg['enabled'] = false;
    }

    return $cache = $cfg;
}

/** 🎯 ระบบพร้อมส่งอีเมลไหม — ใช้ตัดสินใจว่าจะโชว์ทางเลือก "ส่งลิงก์" หรือไม่ */
function mailEnabled(): bool
{
    return mailSettings()['enabled'];
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: เข้ารหัสหัวเรื่องภาษาไทยให้ถูกตามมาตรฐาน
 * ==========================================================================
 * 🔴 หัวเรื่องอีเมลรับได้แค่ ASCII — ภาษาไทยต้องเข้ารหัสแบบ RFC 2047
 *    ไม่ทำ = หัวเรื่องกลายเป็นอักขระเพี้ยนในโปรแกรมอ่านเมลทุกตัว
 */
function mailEncodeHeader(string $text): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $text)) {
        return $text;   // ASCII ล้วน ไม่ต้องเข้ารหัส
    }
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: [H2] ส่งเมล + บันทึกไว้ว่าครั้งล่าสุดสำเร็จหรือไม่
 * ==========================================================================
 *
 * 🧠 ทำไมต้องมีชั้นนี้ ไม่แก้ใน sendMailRaw() ตรง ๆ:
 *    sendMailRaw() มีจุด return 12 จุด (แต่ละขั้นของ SMTP ล้มได้คนละแบบ)
 *    การไปแทรกโค้ดบันทึกทุกจุด = โอกาสตกหล่นสูงและอ่านยาก
 *    ห่อชั้นเดียวจบ ครอบคลุมทุกทางออกโดยอัตโนมัติ
 *
 * 🔴 บันทึกเฉพาะตอนใช้ค่าที่บันทึกไว้จริง ($cfg === null) เท่านั้น
 *    ปุ่ม "ทดสอบส่ง" ในหน้าตั้งค่าส่ง $cfg ที่ยังไม่ได้บันทึกเข้ามา
 *    ถ้าเอาผลนั้นมาบันทึกด้วย = ผู้ดูแลกำลังลองค่าผิด ๆ อยู่ แต่ระบบขึ้นเตือน
 *    ว่า "อีเมลของระบบพัง" ทั้งที่ค่าที่ใช้จริงยังทำงานปกติ (ผลการทดสอบโชว์บนหน้าอยู่แล้ว)
 *
 * 🛡️ ห้ามให้รหัสผ่าน SMTP หลุดลง settings — ข้อความ error มาจากเซิร์ฟเวอร์ปลายทาง
 *    ปกติไม่มีรหัสผ่านติดมา แต่ "ปกติ" ไม่ใช่หลักประกัน จึงกรองออกตรง ๆ (เคส SYS-C2)
 */
function sendMail(string $to, string $subject, string $body, ?array $cfg = null): array
{
    $usingSavedConfig = ($cfg === null);
    $result = sendMailRaw($to, $subject, $body, $cfg);

    if (!$usingSavedConfig) {
        return $result;
    }

    require_once __DIR__ . '/functions.php';

    try {
        if ($result['success']) {
            // 🧠 เขียนก็ต่อเมื่อเคยมีค่าค้างอยู่ — ไม่งั้นจะยิง UPDATE ทุกครั้งที่ส่งเมลสำเร็จ
            if (getSetting('mail_last_error', '') !== '') {
                updateSetting('mail_last_error', '');
                updateSetting('mail_last_error_at', '');
            }
        } else {
            $saved  = mailSettings();
            $reason = (string) $result['error'];

            // 🛡️ ตัดรหัสผ่านออกก่อนเก็บ (ถ้าเซิร์ฟเวอร์สะท้อนกลับมาด้วยเหตุใดก็ตาม)
            $password = (string) ($saved['password'] ?? '');
            if ($password !== '') {
                $reason = str_replace($password, '***', $reason);
            }

            // ✂️ ตัดความยาว — ข้อความจากเซิร์ฟเวอร์ยาวได้ไม่จำกัด
            //    mb_substr เพราะข้อความเป็นภาษาไทย ตัดด้วย substr จะได้ตัวอักษรพัง
            updateSetting('mail_last_error', mb_substr($reason, 0, 200));
            updateSetting('mail_last_error_at', date('d/m/Y H:i'));
        }
    } catch (Throwable $e) {
        // 🛡️ บันทึกไม่ได้ ต้องไม่ทำให้การส่งเมลล้มตาม — ผลการส่งจริงสำคัญกว่าการจดบันทึก
        error_log('[sendMail] บันทึกสถานะไม่สำเร็จ: ' . $e->getMessage());
    }

    return $result;
}

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: ส่งอีเมลข้อความล้วน 1 ฉบับผ่าน SMTP
 * ==========================================================================
 *
 * 📥 @param string $to      อีเมลผู้รับ
 * @param string $subject   หัวเรื่อง (ภาษาไทยได้ — เข้ารหัสให้เอง)
 * @param string $body      เนื้อความ (ข้อความล้วน UTF-8)
 * @param array|null $cfg   ตั้งค่า SMTP (ไม่ส่ง = อ่านจาก settings) — ใช้ตอน "ทดสอบส่ง"
 *
 * 📤 @return array{success:bool, error:string}
 *
 * 🔴 **ไม่ throw** — ผู้เรียกต้องรู้ผลเพื่อบอกผู้ใช้ตามจริง
 *    ห้ามกลืน error แล้วบอกว่า "ส่งแล้ว" เด็ดขาด
 *
 * 🔴 timeout สั้น (8 วินาที) — SMTP ที่ตั้งค่าผิดจะรอจนหมดเวลา
 *    ผู้ใช้ยืนรออยู่หน้าจอ ปล่อยให้ค้าง 60 วินาทีคือทำหน้าเว็บพัง
 */
function sendMailRaw(string $to, string $subject, string $body, ?array $cfg = null): array
{
    $cfg = $cfg ?? mailSettings();

    if (empty($cfg['host']) || empty($cfg['from_email'])) {
        return ['success' => false, 'error' => 'ยังไม่ได้ตั้งค่าเซิร์ฟเวอร์อีเมล'];
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'อีเมลผู้รับไม่ถูกต้อง'];
    }

    $timeout = 8;
    $secure  = strtolower((string) ($cfg['secure'] ?? 'tls'));
    $host    = (string) $cfg['host'];
    $port    = (int) $cfg['port'];

    // 📝 ssl = เข้ารหัสตั้งแต่เชื่อมต่อ (มักใช้พอร์ต 465)
    //    tls = ต่อธรรมดาก่อนแล้วยกระดับด้วย STARTTLS (มักใช้พอร์ต 587)
    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;

    $errNo = 0; $errStr = '';
    $sock = @stream_socket_client($remote, $errNo, $errStr, $timeout);
    if (!$sock) {
        return ['success' => false, 'error' => "เชื่อมต่อ {$host}:{$port} ไม่ได้ ({$errStr})"];
    }
    stream_set_timeout($sock, $timeout);

    /** อ่านคำตอบจากเซิร์ฟเวอร์ — SMTP ตอบหลายบรรทัดได้ บรรทัดสุดท้ายใช้ช่องว่างหลังรหัส */
    $read = function () use ($sock): string {
        $out = '';
        while (($line = fgets($sock, 515)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return $out;
    };
    /** ส่งคำสั่งแล้วตรวจว่ารหัสตอบกลับขึ้นต้นตามที่คาด */
    $cmd = function (?string $line, string $expect) use ($sock, $read): array {
        if ($line !== null) fwrite($sock, $line . "\r\n");
        $res = $read();
        $ok  = str_starts_with(trim($res), $expect);
        return [$ok, trim($res)];
    };

    try {
        [$ok, $res] = $cmd(null, '220');
        if (!$ok) return ['success' => false, 'error' => "เซิร์ฟเวอร์ไม่ตอบรับ: {$res}"];

        $helo = 'EHLO ' . (parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost');
        [$ok, $res] = $cmd($helo, '250');
        if (!$ok) return ['success' => false, 'error' => "EHLO ไม่ผ่าน: {$res}"];

        if ($secure === 'tls') {
            [$ok, $res] = $cmd('STARTTLS', '220');
            if (!$ok) return ['success' => false, 'error' => "STARTTLS ไม่ผ่าน: {$res}"];
            if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return ['success' => false, 'error' => 'ยกระดับเป็น TLS ไม่สำเร็จ'];
            }
            // 📌 หลัง STARTTLS ต้อง EHLO ใหม่ตามมาตรฐาน
            [$ok, $res] = $cmd($helo, '250');
            if (!$ok) return ['success' => false, 'error' => "EHLO หลัง TLS ไม่ผ่าน: {$res}"];
        }

        if (($cfg['username'] ?? '') !== '') {
            [$ok, $res] = $cmd('AUTH LOGIN', '334');
            if (!$ok) return ['success' => false, 'error' => "เซิร์ฟเวอร์ไม่รับ AUTH LOGIN: {$res}"];
            [$ok, $res] = $cmd(base64_encode((string) $cfg['username']), '334');
            if (!$ok) return ['success' => false, 'error' => "ชื่อผู้ใช้ไม่ถูกต้อง: {$res}"];
            [$ok, $res] = $cmd(base64_encode((string) $cfg['password']), '235');
            if (!$ok) return ['success' => false, 'error' => "รหัสผ่านไม่ถูกต้อง หรือบัญชีต้องใช้ App Password: {$res}"];
        }

        [$ok, $res] = $cmd('MAIL FROM:<' . $cfg['from_email'] . '>', '250');
        if (!$ok) return ['success' => false, 'error' => "ผู้ส่งถูกปฏิเสธ: {$res}"];
        [$ok, $res] = $cmd('RCPT TO:<' . $to . '>', '250');
        if (!$ok) return ['success' => false, 'error' => "ผู้รับถูกปฏิเสธ: {$res}"];
        [$ok, $res] = $cmd('DATA', '354');
        if (!$ok) return ['success' => false, 'error' => "เซิร์ฟเวอร์ไม่รับข้อมูล: {$res}"];

        $headers = [
            'From: ' . mailEncodeHeader((string) $cfg['from_name']) . ' <' . $cfg['from_email'] . '>',
            'To: <' . $to . '>',
            'Subject: ' . mailEncodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Date: ' . date('r'),
        ];

        // 🔴 เนื้อความเข้ารหัส base64 — กันบรรทัดยาวเกิน 998 ตัวอักษรและอักขระไทย
        //    และตัดปัญหา "dot-stuffing" (บรรทัดที่ขึ้นต้นด้วย . จะทำให้ SMTP จบข้อความก่อนเวลา)
        $payload = implode("\r\n", $headers) . "\r\n\r\n"
                 . chunk_split(base64_encode($body), 76, "\r\n");

        fwrite($sock, $payload . "\r\n.\r\n");
        [$ok, $res] = $cmd(null, '250');
        if (!$ok) return ['success' => false, 'error' => "ส่งไม่สำเร็จ: {$res}"];

        $cmd('QUIT', '221');
        return ['success' => true, 'error' => ''];
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => 'ผิดพลาดระหว่างส่ง: ' . $e->getMessage()];
    } finally {
        @fclose($sock);
    }
}

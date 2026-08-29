<?php
/**
 * Admin: Member Card Generator - พิมพ์บัตรสมาชิก
 * 
 * ⭐ สำหรับคนมาใหม่:
 * - หน้านี้แสดง member card สำหรับพิมพ์ (ใช้ window.print())
 * - สีบัตรมาจาก settings (card_color_primary, card_color_secondary)
 * - สิทธิ์: staff ขึ้นไป
 * 
 * 📂 Flow:
 * GET ?id=X → UserRepository::findMemberById() → render card → window.print()
 */

// 🔌 โหลด bootstrap (autoload, config, session, DB)
require_once __DIR__ . '/../bootstrap.php';
// 🔒 [AUTH] staff/admin เท่านั้น — สมาชิกพิมพ์บัตรเองไม่ได้
requireStaff();

use App\Repositories\UserRepository;

// 📥 รับ member ID จาก query string
$id = (int)($_GET['id'] ?? 0);
$userRepo = new UserRepository(getDB());

// 🔍 ดึงข้อมูลสมาชิกจาก DB
$member = $userRepo->findMemberById($id);

// ⚠️ ถ้าไม่พบสมาชิก → แสดง error แล้วปิดหน้าต่างอัตโนมัติหลัง 2 วินาที
//    หน้านี้เปิดใน popup window — ไม่ใช้ admin layout
if (!$member) {
    http_response_code(404);
    exit('<h3 style="font-family:sans-serif;text-align:center;margin-top:40px;">ไม่พบสมาชิก</h3><script>setTimeout(()=>window.close(),2000)</script>');
}

// 🎨 ดึงค่าจาก settings (DB) — admin ปรับได้ที่หน้า settings.php
// 🧠 ค่าเหล่านี้ถูกใช้ใน CSS variable (--primary, --secondary) และ inline style
// 🏛️ ชื่อหน่วยงานบนหัวบัตร — ไล่หา 3 ชั้น
//    🧠 ตาราง settings มี 2 คีย์ที่หมายถึงสิ่งเดียวกัน:
//       org_name     — ตั้งจากหน้า "ตั้งค่าบัตรสมาชิก"
//       library_name — มากับข้อมูลตัวอย่าง (sample_data.sql) และหน้าตั้งค่าทั่วไป
//    เดิมบัตรอ่านแค่ org_name แล้ว fallback เป็น 'LIBRARY CARD'
//    ลูกค้าที่ติดตั้งใหม่จึงได้บัตรหัวภาษาอังกฤษ ทั้งที่ library_name มีชื่อไทยอยู่แล้ว
//    ⚠️ ลำดับสำคัญ — org_name ต้องมาก่อน ระบบที่ตั้งไว้แล้วจะได้ค่าเดิม ไม่ถูกทับ
$orgName = getSetting('org_name', '');
if ($orgName === '') {
    $orgName = getSetting('library_name', 'บัตรสมาชิกห้องสมุด');
}
$colorPrimary = getSetting('card_color_primary', '#1e3a8a');
$colorSecondary = getSetting('card_color_secondary', '#3b82f6');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>บัตรสมาชิก - <?= e($member['name']) ?></title>
    <!-- Google Fonts -->
    <link href="<?= APP_URL ?>/assets/vendor/fonts/sarabun.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.css">
    
    <!-- QRCode.js & JsBarcode -->
    <script src="<?= APP_URL ?>/assets/vendor/qrcode/qrcode.min.js"></script>
    <script src="<?= APP_URL ?>/assets/vendor/jsbarcode/JsBarcode.all.min.js"></script>
    
    <style>
        :root {
            --primary: <?= e($colorPrimary) ?>;
            --secondary: <?= e($colorSecondary) ?>;
        }

        body {
            font-family: 'Sarabun', sans-serif;
            background: #f3f4f6;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px;
        }

        .card {
            width: 85.6mm;
            height: 53.98mm;
            background: white;
            border-radius: 12px;
            position: relative;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            overflow: hidden;
            display: flex;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
            border: 1px solid #e2e8f0;
        }

        /* Gradient Sidebar */
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 8px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary), var(--secondary));
            z-index: 10;
        }

        /* Top Header Bar */
        .card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 8px;
            right: 0;
            height: 6px;
            background: var(--primary);
            opacity: 0.1;
        }

        .main-content {
            flex: 1;
            padding: 20px 15px 15px 20px; /* Left padding accounts for sidebar */
            display: flex;
            flex-direction: column;
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 2px;
        }

        .org-name {
            font-size: 14px;
            font-weight: 800;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }

        .member-details {
            margin-top: 15px;
        }

        .role-badge {
            display: inline-block;
            font-size: 8px;
            font-weight: 700;
            background: var(--primary);
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        /* 👤 ชื่อสมาชิก — ต้องอ่านได้ครบทั้งชื่อและนามสกุล
           🔴 เดิมใช้ white-space: nowrap + text-overflow: ellipsis บรรทัดเดียว
              ชื่อ 55 ตัวอักษรเหลือ "เด็กหญิงพิมพ์ณดาภรณ์ช…" — ไม่มีนามสกุลเลย ระบุตัวไม่ได้
           🧠 บัตรมีขนาดตายตัว 85.6 × 53.98 มม. (ขนาดบัตรประชาชน) ขยายไม่ได้
              จึงต้องขึ้นบรรทัดใหม่ + ลดฟอนต์ แทนการตัดทิ้ง
           🧠 จำกัด 3 บรรทัดเป็นด่านสุดท้าย ไม่งั้นชื่อยาวมากจะไปทับบาร์โค้ดข้างล่าง */
        .member-name {
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.15;
            margin-bottom: 2px;
            max-width: 180px;
            /* ✂️ ตัดที่ 3 บรรทัด แล้วค่อยใส่ … (ยังดีกว่าตัดกลางชื่อบรรทัดเดียว) */
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            /* 🧠 ชื่อไทยไม่มีช่องว่างระหว่างคำ ต้องยอมให้ตัดกลางคำได้
               ไม่งั้นคำยาว ๆ จะดันล้นกรอบออกไปแทนที่จะขึ้นบรรทัดใหม่ */
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        /* 📏 ยิ่งชื่อยาว ฟอนต์ยิ่งเล็กลง — คำนวณที่ฝั่งเซิร์ฟเวอร์ ไม่พึ่ง JS
           หน้านี้ต้องพิมพ์ได้แม้ JS ไม่ทำงาน (บาร์โค้ด/QR พึ่ง JS อยู่แล้ว ชื่อไม่ควรพึ่งอีก) */
        .member-name.len-md { font-size: 14px; }
        .member-name.len-lg { font-size: 12px; }
        .member-name.len-xl { font-size: 10.5px; line-height: 1.1; }

        .member-id {
            font-size: 11px;
            color: #64748b;
            font-family: monospace;
            letter-spacing: 0.5px;
        }

        .barcode-section {
            margin-top: auto;
            width: 100%;
        }

        .sidebar-right {
            width: 85px;
            background: #f8fafc;
            border-left: 1px dashed #cbd5e1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 10px;
        }

        #qrcode {
            margin-bottom: 5px;
        }

        #qrcode img {
            width: 65px !important;
            height: 65px !important;
            display: block;
        }

        .scan-text {
            font-size: 8px;
            font-weight: 600;
            color: #64748b;
            /* 🧠 ตัด letter-spacing ออกเพราะข้อความเป็นภาษาไทยแล้ว
               การถ่างช่องไฟทำให้สระบนล่างกับวรรณยุกต์ลอยออกจากพยัญชนะ
               (uppercase ไม่มีผลกับภาษาไทย จึงตัดออกด้วยให้เหลือแต่ที่ใช้จริง) */
        }

        /* Barcode SVG customization */
        svg.barcode {
            width: 100%;
            height: 35px;
            display: block;
        }

        /* Controls */
        .controls {
            margin-top: 30px;
            display: flex;
            gap: 15px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-print {
            background: #1e3a8a;
            color: white;
            box-shadow: 0 4px 10px rgba(30,58,138,0.3);
        }
        
        .btn-print:hover { background: #1e40af; }

        .btn-close {
            background: white;
            color: #374151;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .btn-close:hover { background: #f9fafb; }

        @media print {
            body { 
                background: white; 
                padding: 0; 
                align-items: flex-start;
            }
            .controls { display: none; }
            .card {
                box-shadow: none;
                border: 1px solid #ccc;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>

    <div class="card">
        <div class="main-content">
            <div class="card-header">
                <i class="bi bi-book-half" style="color: var(--primary); font-size: 1.2rem;"></i>
                <div class="org-name"><?= e($orgName) ?></div>
            </div>

            <div class="member-details">
                <?php
                // 📏 เลือกขนาดฟอนต์ตามความยาวชื่อจริง — mb_strlen เพราะภาษาไทยเป็น multibyte
                //    (strlen จะนับเป็นไบต์ ชื่อไทย 20 ตัวอักษรได้ 60 ไบต์ → เลือกผิดขนาด)
                $nameLen = mb_strlen($member['name'], 'UTF-8');
                $nameClass = match (true) {
                    $nameLen > 40 => 'len-xl',
                    $nameLen > 28 => 'len-lg',
                    $nameLen > 18 => 'len-md',
                    default       => '',
                };
                ?>
                <div class="role-badge">สมาชิก</div>
                <div class="member-name <?= $nameClass ?>"><?= e($member['name']) ?></div>
                <div class="member-id">รหัส <?= str_pad($member['id'], 6, '0', STR_PAD_LEFT) ?></div>
            </div>

            <div class="barcode-section">
                <svg class="barcode"
                    jsbarcode-format="CODE128"
                    jsbarcode-value="<?= $member['id'] ?>"
                    jsbarcode-textmargin="0"
                    jsbarcode-fontoptions="bold"
                    jsbarcode-displayValue="false"
                    jsbarcode-height="35"
                    jsbarcode-width="1.8"
                    jsbarcode-background="transparent"
                    jsbarcode-lineColor="#000"
                    jsbarcode-marginTop="0"
                    jsbarcode-marginBottom="0">
                </svg>
            </div>
        </div>

        <div class="sidebar-right">
            <div id="qrcode"></div>
            <div class="scan-text">สแกนที่นี่</div>
        </div>
    </div>

    <div class="controls">
        <button onclick="window.print()" class="btn btn-print">
            <i class="bi bi-printer-fill"></i> พิมพ์บัตร
        </button>
        <button onclick="window.close()" class="btn btn-close">
            ปิดหน้าต่าง
        </button>
    </div>

    <script>
        // Generate QR Code
        new QRCode(document.getElementById("qrcode"), {
            text: "<?= $member['id'] ?>",
            width: 70, // 65px + padding
            height: 70,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.H
        });

        // Generate Barcode
        JsBarcode(".barcode").init();
    </script>
</body>
</html>

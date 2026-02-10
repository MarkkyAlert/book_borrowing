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

require_once __DIR__ . '/../bootstrap.php';

requireStaff(); // Staff ต้องพิมพ์บัตรสมาชิกได้

use App\Repositories\UserRepository;

$id = (int)($_GET['id'] ?? 0);
$userRepo = new UserRepository(getDB());

$member = $userRepo->findMemberById($id);

if (!$member) {
    http_response_code(404);
    exit('<h3 style="font-family:sans-serif;text-align:center;margin-top:40px;">ไม่พบสมาชิก</h3><script>setTimeout(()=>window.close(),2000)</script>');
}

// Get Settings
$orgName = getSetting('org_name', 'LIBRARY CARD');
$colorPrimary = getSetting('card_color_primary', '#1e3a8a');
$colorSecondary = getSetting('card_color_secondary', '#3b82f6');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>บัตรสมาชิก - <?= e($member['name']) ?></title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- QRCode.js & JsBarcode -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.0/dist/JsBarcode.all.min.js"></script>
    
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

        .member-name {
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.2;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 180px;
        }

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
            text-transform: uppercase;
            letter-spacing: 1px;
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
                <div class="role-badge">MEMBER</div>
                <div class="member-name"><?= e($member['name']) ?></div>
                <div class="member-id">ID: <?= str_pad($member['id'], 6, '0', STR_PAD_LEFT) ?></div>
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
            <div class="scan-text">SCAN ME</div>
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

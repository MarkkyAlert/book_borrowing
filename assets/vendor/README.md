# assets/vendor — ไลบรารีภายนอกที่เก็บไว้ในเครื่อง

## 🔴 ทำไมต้องเก็บไว้เอง ห้ามกลับไปใช้ CDN

ลูกค้ากลุ่มหลักของระบบนี้คือห้องสมุดโรงเรียน/หน่วยงานราชการ ซึ่งหลายแห่งเป็น
**intranet ไม่ต่ออินเทอร์เน็ต** หรือเน็ตไม่เสถียร

ถ้าโหลด asset จาก CDN แล้วต่อเน็ตไม่ได้ ระบบจะ**ใช้งานไม่ได้เลย** ไม่ใช่แค่ช้า:

| ไลบรารี | ถ้าโหลดไม่ได้ |
|---------|---------------|
| Tailwind | หน้าเว็บไม่มี style เลย อ่านไม่รู้เรื่อง |
| Bootstrap Icons | ไอคอนหายหมดทุกหน้า |
| Select2 | หน้าบันทึกการยืม เลือกสมาชิก/หนังสือไม่ได้ |
| Chart.js | กราฟใน Dashboard หายไป |
| JsBarcode / QRCode | พิมพ์บาร์โค้ดหนังสือและบัตรสมาชิกไม่ได้ |
| Flatpickr | เลือกช่วงวันในหน้ารายงานไม่ได้ |

**ดังนั้น: ห้ามเพิ่ม `<script src="https://...">` หรือ `<link href="https://...">` ลงในโค้ดอีก**
ถ้าต้องใช้ไลบรารีใหม่ ให้โหลดมาไว้ในโฟลเดอร์นี้แล้วอ้างผ่าน `<?= APP_URL ?>/assets/vendor/...`

## 📦 รายการที่เก็บไว้ (ตรึงเวอร์ชัน)

| โฟลเดอร์ | เวอร์ชัน | ที่มา |
|----------|----------|-------|
| `tailwind/` | Play CDN (ล่าสุด ณ 2026-08-27) | `https://cdn.tailwindcss.com` |
| `bootstrap/` | 5.3.2 | `https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/` |
| `bootstrap-icons/` | 1.11.1 | `https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/` |
| `jquery/` | 3.7.1 | `https://code.jquery.com/jquery-3.7.1.min.js` |
| `chartjs/` | latest (npm `chart.js`) | `https://cdn.jsdelivr.net/npm/chart.js` |
| `select2/` | 4.1.0-rc.0 | `https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/` |
| `flatpickr/` | latest + locale ไทย | `https://cdn.jsdelivr.net/npm/flatpickr` |
| `jsbarcode/` | 3.11.0 | `https://cdn.jsdelivr.net/npm/jsbarcode@3.11.0/dist/` |
| `qrcode/` | qrcodejs 1.0.0 | `https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/` |
| `fonts/` | Google Fonts — Sarabun 300–700 | `https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700` |

## 🧠 หมายเหตุที่ต้องรู้ก่อนแก้

**Tailwind** เป็นตัวคอมไพล์ที่ทำงานในเบราว์เซอร์ (Play CDN) ไม่ใช่ CSS สำเร็จรูป
จงใจใช้แบบนี้เพราะโปรเจกต์นี้**ไม่มี build step** (ไม่มี Node/npm/Composer)
ถ้าจะเปลี่ยนไปคอมไพล์เป็น CSS นิ่ง ต้องเพิ่ม Node เข้ามาซึ่งขัดกับแนวทางของโปรเจกต์
· `tailwind.config` (สี `primary` + ฟอนต์) อยู่ใน `includes/header.php` และ `admin/header.php`
· สคริปต์ Tailwind ต้องโหลด**ก่อน** `tailwind.config` เสมอ

**Bootstrap Icons** — ไฟล์ CSS อ้างฟอนต์ด้วย path สัมพัทธ์ `./fonts/...`
ห้ามย้าย `bootstrap-icons.css` ออกจากโฟลเดอร์ที่มี `fonts/` อยู่ ไม่งั้นไอคอนหายหมด

**ฟอนต์ Sarabun** — `sarabun.css` ถูกแก้ URL ให้ชี้ `./files/*.woff2` ในเครื่องแล้ว
มี 20 ไฟล์ครอบคลุม subset ไทย/latin/latin-ext/vietnamese × 5 น้ำหนัก

**`assets/.htaccess`** ปิดการรัน PHP + ตั้ง MIME type ของฟอนต์ + cache 1 ปี
⚠️ เพราะ cache ยาว ถ้าอัปเดตไลบรารีต้องเปลี่ยนชื่อไฟล์/โฟลเดอร์ด้วย ไม่งั้นผู้ใช้เดิมจะได้ของเก่า

## 🔄 วิธีอัปเดตไลบรารี

1. โหลดไฟล์ใหม่ทับ (ใช้ URL ในตารางด้านบน เปลี่ยนเลขเวอร์ชัน)
2. **ทดสอบหน้าที่ใช้ไลบรารีนั้นจริง** — ดูตารางผลกระทบด้านบนว่าหน้าไหนบ้าง
3. อัปเดตเลขเวอร์ชันในตารางนี้
4. ตรวจว่าไม่มี URL ภายนอกหลุดกลับเข้ามา:

```bash
grep -rn "https://cdn\|https://fonts.googleapis\|https://code.jquery" --include="*.php" . | grep -v tests/
```

ต้องไม่มีผลลัพธ์

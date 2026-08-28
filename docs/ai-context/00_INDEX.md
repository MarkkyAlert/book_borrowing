# AI Context Pack — ระบบยืมคืนหนังสือ

> เอกสารชุดนี้สร้างจากการอ่าน **Source Code + Database Schema จริง** (ไม่ใช่จากเอกสารเดิม)
> วันที่ตรวจ: 2026-08-26 | Commit ฐาน: `1453c01`
> อัปเดตล่าสุด: 2026-08-28 — ปิด F-01…F-34 ครบทุกข้อ + ROADMAP ข้อ 0–4 (ชุดทดสอบ 536/536 · 35 ชุด)
> · **F-35…F-52 ยังไม่ได้แก้** — เจอจากการทดสอบสวมบทบาทบรรณารักษ์บนข้อมูลขนาดจริง (ส่วนใหญ่เป็น UX ไม่ใช่ความถูกต้องของข้อมูล)
> ✅ ผ่านการทดสอบ **ติดตั้งจาก clone สด** แล้ว — ทั้งติดตั้งใหม่, import ข้อมูลตัวอย่าง และอัปเกรดจากเวอร์ชันเก่า
> ทุกข้อความในชุดนี้อ้างอิง `file:line` ได้ — ถ้าโค้ดเปลี่ยน เอกสารนี้ต้องอัปเดตตาม

## ลำดับการอ่าน

| ลำดับ | เอกสาร | ใช้ตอบคำถามแบบไหน |
|-------|--------|-------------------|
| **0** | **[AI_HANDOFF.md](AI_HANDOFF.md)** | **🔴 อ่านก่อนเพื่อน** — กติกาของเจ้าของ, กับดัก 8 ข้อที่เสียเวลาค้นพบมาแล้ว, สิ่งที่พูดได้/ห้ามพูดตอนขาย, วิธีรันเทสต์ |
| 1 | [PROJECT_MAP.md](PROJECT_MAP.md) | "โค้ดอยู่ตรงไหน" — โครงสร้าง, Page→Service→Repository→Table |
| 2 | [DATABASE_MAP.md](DATABASE_MAP.md) | "ข้อมูลเก็บยังไง" — 9 ตาราง, FK, Constraint, โมเดล stock |
| 3 | [FEATURE_MATRIX.md](FEATURE_MATRIX.md) | "ทำ X ได้ไหม" — มีแล้ว / ต่อยอดได้ / ไม่เหมาะ |
| 4 | [BUSINESS_RULES.md](BUSINESS_RULES.md) | "กติกาของระบบคืออะไร" — ทุกกฎพร้อมที่มาในโค้ด |
| 5 | [SECURITY_CHECKLIST.md](SECURITY_CHECKLIST.md) | "ปลอดภัยแค่ไหน" — control ที่มีจริง + วิธีที่ยืนยัน |
| 6 | [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md) | "ข้อจำกัดคืออะไร" — ขอบเขตที่พูดตรง ๆ |
| 7 | [WHERE_TO_EDIT_MAP.md](WHERE_TO_EDIT_MAP.md) | "จะแก้ X ต้องแตะไฟล์ไหน" |
| 8 | [FINDINGS.md](FINDINGS.md) | จุดที่ Context/Comment **ไม่ตรง** กับโค้ด + บั๊กที่เจอจากการทดสอบ · **F-01…F-34 ปิดครบ** (แก้โค้ด 31 · อัปเดตเอกสาร 3) · **F-35…F-52 ยังค้าง** (🔴 3 · 🟠 9 · 🟡 6) + รายการ 8 งานประจำของห้องสมุดที่ยังไม่มี |
| 9 | [ROADMAP.md](ROADMAP.md) | **"ตกลงแล้วว่าจะทำอะไรต่อ"** — 5 ฟีเจอร์ที่เจ้าของสั่งให้เข้าคิวแก้ พร้อมคำตอบ 8 ข้อตาม `AI_HANDOFF §21` ครบทุกข้อ (ต่ออายุการยืม · หนังสือหาย/ชำรุด · ยกเว้นค่าปรับ · จองรอคิว · หนังสืออ้างอิงห้ามยืม) |

## สรุประบบใน 5 บรรทัด

- Pure PHP 8.1+ / MySQL(MariaDB) / PDO — ไม่มี framework, ไม่มี composer, ไม่มี build step
- Layered: `Page/API → Service → Repository → PDO → MySQL` + autoloader ใน `bootstrap.php`
- 8 Service / 9 Repository / 9 ตาราง / 3 role (admin, staff, member)
- ทุก write flow สำคัญใช้ **Transaction + `SELECT ... FOR UPDATE`** จริง (ไม่ใช่แค่เคลม)
- 18,500 บรรทัด PHP + ชุดทดสอบ 536 เคส ใน 35 ชุด (**ผ่าน 536/536**) + ชุดทดสอบ concurrency แยกอีก 12 เคส

## ชุดข้อมูลสำหรับทดสอบ

| ชั้น | ไฟล์ | เนื้อหา | วิธีรัน |
|------|------|---------|---------|
| **L0** ห้องสมุดปกติ | `database/sample_data.sql` | 5 users / 10 books / 13 borrows / 5 reservations — ไว้เดโมและดูหน้าจอ | เลือก DB แล้ว import |
| **L1** ขอบ/กรณีพิเศษ | `tests/fixtures/seed_test_data.php` | 15 users / 19 books / 27 borrows / 7 reservations / 1 payment / 6 reset token — สภาพที่จงใจสร้างเพื่อทดสอบกฎธุรกิจที่ขอบ | `php tests/fixtures/seed_test_data.php` |
| **L2** ห้องสมุดโรงเรียนขนาดจริง | `tests/fixtures/seed_school_library.php` | 204 นักเรียน / 405 เล่ม / 12 หมวด / 2,000 การยืม / 154 ยืมค้าง / 400 ค่าปรับค้าง / 31 การจอง — ขนาดและหน้าตาเหมือนของจริง ใช้ทดสอบ UX ("หาของเจอไหม" / การแบ่งหน้า 100 หน้า) · **เป็นโปรไฟล์ห้องสมุดโรงเรียน 1 แบบเท่านั้น ไม่ได้แปลว่าสินค้าเจาะกลุ่มโรงเรียน** — สินค้าวางตัวเป็นห้องสมุดขนาดเล็กทั่วไป (`README.md §1`) | `php tests/fixtures/seed_school_library.php` |
| **L3** ปริมาณมาก (วัด perf) | `tests/fixtures/seed_bulk_data.php` | สร้างหนังสือ/สมาชิก/การยืมตามจำนวนที่สั่ง — ใช้ตอบว่า "รับได้กี่เล่ม" | `php tests/fixtures/seed_bulk_data.php --books=500 --members=200` |

L1/L3 กำกับข้อมูลของตัวเองด้วย `[TEST] ` / `[BULK] ` และ `@test.local` → `--reset` ลบเฉพาะของตัวเอง ไม่แตะ L0 หรือข้อมูลลูกค้า

**L2 ต่างจากชั้นอื่น** — ห้ามมีแท็กโผล่บนหน้าจอ เพราะใช้ประเมินคำศัพท์และความสมจริงของ UI
จึงจดทะเบียน id ที่สร้างไว้ใน `tests/fixtures/.school_seed.json` แทน (อีเมลนักเรียนลงท้าย `@sk.local` เป็น fallback)
L2 รวมหมวดเก่าของ L0 เข้ากับ 12 หมวดมาตรฐานและลบหมวดซ้ำทิ้ง — `--reset` ไม่คืนหมวดเหล่านั้นกลับมา
⚠️ รัน L1 หรือ L3 หลัง L2 จะทำให้มีแถว `[TEST]`/`[BULK]` ปนขึ้นหน้าจอ → รัน L2 ใหม่เพื่อล้าง
`--verify` ตรวจว่าสภาพข้อมูล 21 ข้อยังอยู่ครบหรือไม่ (บางสภาพถูก "ใช้ไป" ระหว่างทดสอบ เช่น การจองหมดอายุที่ถูก lazy expire → รันใหม่เพื่อคืนสภาพ)

## สถานะที่ตรวจสอบแล้วบนเครื่องนี้

| รายการ | ผล |
|--------|-----|
| ติดตั้ง (`install.php`) | ✅ สำเร็จ — 9 ตาราง, admin, ตัวอย่าง 5 เล่ม/5 หมวด |
| Test suite (`php tests/run_all_tests.php 123456`) | **536/536 ผ่าน (100%)** — 35 ชุด (service, DB constraint, deadlock, pagination, search index, offline assets, security/data-integrity/concurrency gap analysis, HTTP, upload security ฯลฯ) ใช้เวลา ~8 วินาที + ล้างข้อมูลให้อัตโนมัติเมื่อจบ |
| `.htaccess` ป้องกันไฟล์สำคัญ | ✅ `.env`, `app/`, `includes/*.php`, `tests/`, `database/`, `*.sql`, `*.md`, `.installed` → 403 ทั้งหมด |
| XSS escaping (probe จริง) | ✅ `?search=<script>` ถูก escape เป็น `&lt;script&gt;` |
| Rate limit login (probe จริง) | ✅ บล็อกตามค่าใน `.env` (ตอนนี้ = 5 ครั้ง / 15 นาที) |
| หนังสือที่ซ่อน (`is_visible=0`) รั่วออกหน้า public | ✅ ปิดช่องแล้ว — `index.php` และ AJAX search เดินผ่าน `HomeService` ตัวเดียวกัน |
| ข้อมูลตัวอย่าง (`sample_data.sql`) | ✅ import ได้ทุกชื่อ DB · รหัสทุกบัญชี = `123456` ตามคู่มือ · stock ตรงกับ invariant 10/10 เล่ม |

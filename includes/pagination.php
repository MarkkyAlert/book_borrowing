<?php
/**
 * ==========================================================================
 * 📄 HTML partial: แถบเลือกหน้า (ใช้ร่วมกันทุกหน้าที่แบ่งหน้า)
 * ==========================================================================
 * 🧠 ทำไมแยกไฟล์: index.php, admin/books.php, admin/borrows.php, admin/members.php
 *    ใช้หน้าตาเดียวกันหมด — ถ้า copy ไปไว้ 4 ที่ พอแก้ทีเดียวจะลืมแก้ให้ครบ
 *
 * 📥 ต้องมีตัวแปรพวกนี้ก่อน require:
 *    $pagination       array จาก paginate() — page, total_pages, from, to, total, pages
 *    $paginationParams array query string เดิม (ไม่ต้องมี key 'page') เพื่อคง filter ไว้
 *    $paginationAjax   bool (ไม่บังคับ) — true = ใส่ data-page ให้ JS ดักคลิกแทนการโหลดหน้าใหม่
 *    $paginationUnit   string (ไม่บังคับ) — หน่วยนับ เช่น 'เล่ม' / 'คน' (default 'รายการ')
 *    $paginationKey    string (ไม่บังคับ) — ชื่อพารามิเตอร์หน้าใน URL (default 'page')
 *                      ใช้เมื่อหน้าหนึ่งมี 2 ตารางที่แบ่งหน้าแยกกัน
 *
 * ⚠️ ไม่มีข้อมูลเลย หรือมีหน้าเดียว → ไม่แสดงอะไร (แต่ยังบอกยอดรวมถ้ามีข้อมูล)
 */

// 📥 อ่านตัวเลือกเข้าตัวแปรของรอบนี้ **แล้วล้างของเดิมทิ้งทันที**
//    🔴 ต้องล้างตรงนี้ ไม่ใช่ท้ายไฟล์ เพราะด้านล่างมี `return` ตอนมีหน้าเดียว
//       ถ้าล้างท้ายไฟล์ ตารางแรกที่มีหน้าเดียวจะ return ไปก่อน แล้ว unit/key ของมัน
//       จะรั่วไปให้ตารางที่ 2 บนหน้าเดียวกัน (admin/payments.php มี 2 ตาราง)
$pgParams = $paginationParams ?? [];
$pgAjax   = $paginationAjax ?? false;
// 📝 หน่วยนับให้ตรงกับสิ่งที่แสดง — "2,027 เล่ม" อ่านเป็นธรรมชาติกว่า "2,027 รายการ"
$pgUnit   = $paginationUnit ?? 'รายการ';
$pgKey    = $paginationKey ?? 'page';
unset($paginationParams, $paginationAjax, $paginationUnit, $paginationKey);

// 📝 หน้าเดียวและข้อมูลน้อย → ไม่ต้องรบกวนหน้าจอด้วยแถบเลือกหน้า
if (($pagination['total_pages'] ?? 1) <= 1) {
    return;
}

$pgCurrent = $pagination['page'];
$pgLast    = $pagination['total_pages'];

// 🧩 class ของปุ่มตัวเลข — แยกตัวแปรไว้กันบรรทัดยาวจนอ่านไม่ออก
$pgBase   = 'inline-flex items-center justify-center min-w-[2.5rem] h-10 px-3 rounded-lg text-sm font-medium transition-colors';
$pgIdle   = "$pgBase bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 hover:border-primary-300";
$pgActive = "$pgBase bg-primary-600 border border-primary-600 text-white shadow-sm shadow-primary-500/30";
$pgOff    = "$pgBase bg-gray-50 border border-gray-200 text-gray-300 cursor-not-allowed";
?>
<nav class="flex flex-col sm:flex-row items-center justify-between gap-4 mt-8" aria-label="แบ่งหน้า">
    <!-- 📝 สรุปช่วงที่กำลังดูอยู่ — ช่วยให้รู้ว่าข้อมูลไม่ได้หายไปไหน แค่อยู่หน้าอื่น -->
    <p class="text-sm text-gray-500">
        แสดง <span class="font-semibold text-gray-700"><?= number_format($pagination['from']) ?>–<?= number_format($pagination['to']) ?></span>
        จากทั้งหมด <span class="font-semibold text-gray-700"><?= number_format($pagination['total']) ?></span> <?= e($pgUnit) ?>
    </p>

    <div class="flex items-center gap-1.5 flex-wrap justify-center">
        <?php // ── ปุ่มก่อนหน้า ── ?>
        <?php if ($pgCurrent > 1): ?>
            <a href="<?= e(paginationUrl($pgParams, $pgCurrent - 1, $pgKey)) ?>"
               <?= $pgAjax ? 'data-page="' . ($pgCurrent - 1) . '"' : '' ?>
               class="<?= $pgIdle ?>" aria-label="หน้าก่อนหน้า">
                <i class="bi bi-chevron-left"></i>
            </a>
        <?php else: ?>
            <span class="<?= $pgOff ?>" aria-hidden="true"><i class="bi bi-chevron-left"></i></span>
        <?php endif; ?>

        <?php // ── เลขหน้า (null = จุดไข่ปลา) ── ?>
        <?php foreach ($pagination['pages'] as $pgNum): ?>
            <?php if ($pgNum === null): ?>
                <span class="px-1 text-gray-400 select-none">…</span>
            <?php elseif ($pgNum === $pgCurrent): ?>
                <span class="<?= $pgActive ?>" aria-current="page"><?= $pgNum ?></span>
            <?php else: ?>
                <a href="<?= e(paginationUrl($pgParams, $pgNum, $pgKey)) ?>"
                   <?= $pgAjax ? 'data-page="' . $pgNum . '"' : '' ?>
                   class="<?= $pgIdle ?>"><?= $pgNum ?></a>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php // ── ปุ่มถัดไป ── ?>
        <?php if ($pgCurrent < $pgLast): ?>
            <a href="<?= e(paginationUrl($pgParams, $pgCurrent + 1, $pgKey)) ?>"
               <?= $pgAjax ? 'data-page="' . ($pgCurrent + 1) . '"' : '' ?>
               class="<?= $pgIdle ?>" aria-label="หน้าถัดไป">
                <i class="bi bi-chevron-right"></i>
            </a>
        <?php else: ?>
            <span class="<?= $pgOff ?>" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
        <?php endif; ?>
    </div>
</nav>

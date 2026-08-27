            </div> <?php // ปิด content container ที่เปิดใน header.php ?>
        </main> <?php // ปิด <main> ที่เปิดใน header.php ?>
    </div> <?php // ปิด flex wrapper (sidebar + main) ?>
    
    <?php // 📦 Global JS Libraries — โหลดท้ายสุดเพื่อไม่บล็อก rendering ?>
    <?php // jQuery: ใช้โดย Select2, DataTables, AJAX calls ในหน้า admin ต่างๆ ?>
    <script src="<?= APP_URL ?>/assets/vendor/jquery/jquery.min.js"></script>
    <?php // Bootstrap JS: legacy utility (admin ใช้ Tailwind CSS modals เป็นหลัก) ?>
    <script src="<?= APP_URL ?>/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>

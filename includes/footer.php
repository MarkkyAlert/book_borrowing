    </main>
    
    <!-- 📝 Footer: ปิด </main> ที่เปิดจาก includes/header.php -->
    <footer class="bg-white border-t border-gray-100 mt-12 py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="md:flex md:items-center md:justify-between">
                <div class="flex justify-center md:justify-start space-x-6 md:order-2">
                    <a href="#" class="text-gray-400 hover:text-gray-500">
                        <span class="sr-only">Facebook</span>
                        <i class="bi bi-facebook text-xl"></i>
                    </a>
                    <a href="#" class="text-gray-400 hover:text-gray-500">
                        <span class="sr-only">Twitter</span>
                        <i class="bi bi-twitter text-xl"></i>
                    </a>
                    <a href="#" class="text-gray-400 hover:text-gray-500">
                        <span class="sr-only">GitHub</span>
                        <i class="bi bi-github text-xl"></i>
                    </a>
                </div>
                <div class="mt-8 md:mt-0 md:order-1">
                    <p class="text-center text-sm text-gray-500">
                        &copy; <?= date('Y') ?> <?= APP_NAME ?>. Developed with PHP & MySQL.
                    </p>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- 📝 Script: ลบ loading overlay หลัง page load เสร็จ (ถ้ามี) -->
    <script>
        window.addEventListener('load', function() {
            const overlay = document.getElementById('loading-overlay');
            if (overlay) {
                // 📝 fade out 500ms แล้วลบออกจาก DOM
                overlay.style.opacity = '0';
                setTimeout(() => {
                    overlay.remove();
                }, 500);
            }
        });
    </script>
</body>
</html>

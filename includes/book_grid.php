<?php // 📝 HTML partial: ถูก require จาก api/search_books.php + index.php
//    ต้องมีตัวแปร $books (array) ก่อน require
?>
<?php if (empty($books)): ?>
    <!-- 📝 Empty state: ไม่พบหนังสือ -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-16 text-center animate-fade-in-down">
        <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-6 text-gray-400">
            <i class="bi bi-search" style="font-size: 3rem;"></i>
        </div>
        <h4 class="text-2xl font-bold text-gray-800 mb-2">ไม่พบหนังสือที่ค้นหา</h4>
        <p class="text-gray-500">ลองค้นหาด้วยคำอื่น หรือปรับเปลี่ยนตัวกรองของคุณใหม่</p>
    </div>
<?php else: ?>
    <!-- 📝 Grid: 1 col (mobile) → 2 col (tablet) → 4 col (desktop) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
        <?php foreach ($books as $index => $book): ?>
            <?php // 📝 animation-delay ตาม index — card โผล่ทีละใบ ?>
            <div class="group bg-white rounded-2xl shadow-sm hover:shadow-xl hover:-translate-y-2 transition-all duration-300 border border-gray-100 overflow-hidden flex flex-col h-full animate-fade-in-up" style="animation-delay: <?= $index * 50 ?>ms">
                <!-- Cover Image -->
                <div class="relative h-64 overflow-hidden bg-gray-100">
                    <?php if (!empty($book['cover_image'])): ?>
                        <?php // 📝 แสดงรูปปกจาก uploads/covers/ + e() ป้องกัน XSS ?>
                        <img src="<?= APP_URL ?>/uploads/covers/<?= e($book['cover_image']) ?>" 
                             class="w-full h-full object-cover transform group-hover:scale-110 transition-transform duration-500" 
                             alt="<?= e($book['title']) ?>">
                    <?php else: ?>
                        <div class="flex flex-col items-center justify-center h-full text-gray-300 bg-gradient-to-br from-gray-50 to-gray-200">
                            <i class="bi bi-book text-6xl mb-3 drop-shadow-md"></i>
                            <span class="text-sm font-medium">ไม่มีรูปปก</span>
                        </div>
                    <?php endif; ?>
                    
                    <!-- 📝 Status Badge: ว่าง/หมด (ตาม available) -->
                    <div class="absolute top-4 right-4">
                        <?php if ($book['available'] > 0): ?>
                            <span class="px-3 py-1 bg-green-500/90 backdrop-blur-sm text-white text-xs font-bold rounded-full shadow-lg flex items-center">
                                <span class="w-2 h-2 bg-white rounded-full mr-1.5 animate-pulse"></span>
                                ว่าง <?= $book['available'] ?>
                            </span>
                        <?php else: ?>
                            <span class="px-3 py-1 bg-red-500/90 backdrop-blur-sm text-white text-xs font-bold rounded-full shadow-lg">
                                หมด
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Category Badge -->
                    <div class="absolute bottom-4 left-4">
                        <span class="px-3 py-1 bg-white/90 backdrop-blur-md text-gray-800 text-xs font-bold rounded-full shadow-md">
                            <?= e($book['category_name'] ?? 'ไม่ระบุ') ?>
                        </span>
                    </div>
                </div>
                
                <div class="p-5 flex-1 flex flex-col">
                    <h5 class="text-lg font-bold text-gray-900 mb-2 line-clamp-2 leading-snug group-hover:text-primary-600 transition-colors" title="<?= e($book['title']) ?>">
                        <?= e($book['title']) ?>
                    </h5>
                    <p class="text-sm text-gray-500 mb-4 flex items-center">
                        <i class="bi bi-person-circle mr-2 text-primary-400"></i>
                        <?= e($book['author']) ?>
                    </p>
                    
                    <div class="mt-auto pt-4 border-t border-gray-100">
                        <a href="book.php?id=<?= $book['id'] ?>" class="block w-full py-2.5 rounded-lg bg-gray-50 text-gray-600 text-center text-sm font-semibold hover:bg-primary-600 hover:text-white transition-colors">
                            ดูรายละเอียด
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

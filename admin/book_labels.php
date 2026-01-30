<?php
/**
 * Admin: Book Barcode Labels
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireAdmin();

$pdo = getDB();

// Fetch all books
$stmt = $pdo->query("SELECT id, title, isbn FROM books ORDER BY id DESC");
$books = $stmt->fetchAll();

$pageTitle = 'พิมพ์รหัสบาร์โค้ด';
require_once __DIR__ . '/header.php';
?>

<!-- No Print Section -->
<div class="no-print">
    <div class="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h3 class="text-2xl font-bold text-gray-800 flex items-center">
                <i class="bi bi-upc-scan mr-3 text-primary-600"></i>
                พิมพ์รหัสบาร์โค้ด (Book Labels)
            </h3>
            <p class="text-gray-500">เลือกหนังสือที่ต้องการพิมพ์สติ๊กเกอร์บาร์โค้ด</p>
        </div>
        <div class="flex gap-2">
            <button onclick="selectAll()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-colors text-sm font-medium">
                เลือกทั้งหมด
            </button>
            <button onclick="generateLabels()" class="px-4 py-2 bg-primary-600 text-white rounded-xl hover:bg-primary-700 transition-colors text-sm font-medium shadow-lg shadow-primary-500/30 flex items-center">
                <i class="bi bi-printer-fill mr-2"></i>พิมพ์ที่เลือก
            </button>
        </div>
    </div>

    <!-- Book Selection Table -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-8">
        <div class="overflow-x-auto max-h-[600px]">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50 sticky top-0 z-10">
                    <tr>
                        <th class="px-6 py-3 text-left w-10">
                            <input type="checkbox" id="checkAll" onclick="toggleAll(this)" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 cursor-pointer">
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ชื่อหนังสือ</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ISBN</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($books as $book): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <input type="checkbox" name="book_ids[]" value="<?= $book['id'] ?>" 
                                       data-title="<?= e($book['title']) ?>"
                                       data-isbn="<?= e($book['isbn']) ?>"
                                       class="book-checkbox rounded border-gray-300 text-primary-600 focus:ring-primary-500 cursor-pointer">
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                #<?= $book['id'] ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900 line-clamp-1 max-w-sm">
                                <?= e($book['title']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 font-mono">
                                <?= e($book['isbn'] ?: '-') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Printable Area (Hidden by default, shown when printing) -->
<div id="printArea" class="hidden">
    <div id="labelsGrid" class="labels-grid">
        <!-- Labels will be injected here -->
    </div>
</div>

<!-- JsBarcode -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.0/dist/JsBarcode.all.min.js"></script>

<script>
function toggleAll(source) {
    const checkboxes = document.querySelectorAll('.book-checkbox');
    checkboxes.forEach(cb => cb.checked = source.checked);
}

function selectAll() {
    const checkboxes = document.querySelectorAll('.book-checkbox');
    checkboxes.forEach(cb => cb.checked = true);
    document.getElementById('checkAll').checked = true;
}

function generateLabels() {
    const selected = document.querySelectorAll('.book-checkbox:checked');
    if (selected.length === 0) {
        alert('กรุณาเลือกหนังสืออย่างน้อย 1 เล่ม');
        return;
    }

    const grid = document.getElementById('labelsGrid');
    grid.innerHTML = ''; // Clear previous

    selected.forEach(cb => {
        const id = cb.value;
        const title = cb.dataset.title.substring(0, 25) + (cb.dataset.title.length > 25 ? '...' : ''); // Truncate title
        
        const label = document.createElement('div');
        label.className = 'label-item';
        
        // Structure: Title + Canvas for Barcode + ID Text
        label.innerHTML = `
            <div class="label-title">${title}</div>
            <svg class="barcode"
                jsbarcode-format="CODE128"
                jsbarcode-value="${id}"
                jsbarcode-textmargin="0"
                jsbarcode-fontoptions="bold"
                jsbarcode-height="40"
                jsbarcode-width="2"
                jsbarcode-displayValue="true"
                jsbarcode-fontSize="14">
            </svg>
        `;
        
        grid.appendChild(label);
    });

    // Initialize Barcodes
    JsBarcode(".barcode").init();

    // Trigger Print
    window.print();
}
</script>

<style>
    /* Print Styles */
    @media print {
        body * {
            visibility: hidden;
        }
        #printArea, #printArea * {
            visibility: visible;
        }
        #printArea {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            display: block !important;
        }
        .no-print {
            display: none !important;
        }
        
        /* Layout for A4 Stickers (3 columns x 8 rows approx) */
        .labels-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr); /* 3 Columns */
            gap: 5mm;
            padding: 5mm;
        }
        
        .label-item {
            border: 1px dotted #ccc; /* Dotted line to help cutting */
            padding: 5px;
            text-align: center;
            height: 35mm; /* Approx sticky height */
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            page-break-inside: avoid;
        }
        
        .label-title {
            font-size: 10px;
            margin-bottom: 2px;
            font-weight: bold;
        }
        
        svg.barcode {
            max-width: 100%;
            height: auto;
        }
    }
</style>

<?php require_once __DIR__ . '/footer.php'; ?>

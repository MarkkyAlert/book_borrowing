<?php
$content = "Title,Author,ISBN,Category,Quantity\nBOM Book,BOM Author,978-3333333333,BOM Cat,5";
$file = __DIR__ . '/books_bom.csv';
file_put_contents($file, "\xEF\xBB\xBF" . $content);
echo "Created $file with BOM.";

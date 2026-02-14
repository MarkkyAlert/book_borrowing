<?php

/**
 * Test Search API via Curl
 * 
 * Objectives:
 * 1. GET /api/search_books.php?q=test -> 200 OK (HTML in body)
 * 2. POST /api/search_books.php -> 405 Method Not Allowed
 * 3. GET /api/search_books.php?q=longstring -> 200 OK (No crash)
 * 4. GET /api/search_books.php?status=available -> 200 OK
 */

$baseUrl = 'http://localhost/book_borrowing';

function curlRequest($url, $method = 'GET', $data = [])
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true); // We need headers for status code

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($response, $headerSize);

    curl_close($ch);

    return ['code' => $httpCode, 'body' => $body];
}

echo "Testing Search API...\n\n";

// 1. Valid Search
echo "1. GET /api/search_books.php?search=Test ... ";
$res = curlRequest($baseUrl . '/api/search_books.php?search=Test');
if ($res['code'] === 200 && strpos($res['body'], '<div') !== false) {
    echo "✅ Passed (Got HTML)\n";
} else {
    echo "❌ Failed (Code: {$res['code']})\n";
}

// 2. Invalid Method
echo "2. POST /api/search_books.php ... ";
$res = curlRequest($baseUrl . '/api/search_books.php', 'POST', ['search' => 'test']);
if ($res['code'] === 405) {
    echo "✅ Passed (405 Method Not Allowed)\n";
} else {
    echo "❌ Failed (Code: {$res['code']}) - Expected 405\n";
}

// 3. Long String
$longString = str_repeat('A', 1000);
echo "3. Long String Search (1000 chars) ... ";
$res = curlRequest($baseUrl . '/api/search_books.php?search=' . $longString);
if ($res['code'] === 200) {
    echo "✅ Passed (No Crash)\n";
} else {
    echo "❌ Failed (Code: {$res['code']})\n";
}

// 4. Filter by Status
echo "4. Filter Status=available ... ";
$res = curlRequest($baseUrl . '/api/search_books.php?status=available');
if ($res['code'] === 200) {
    echo "✅ Passed\n";
} else {
    echo "❌ Failed\n";
}

echo "\nDone.\n";

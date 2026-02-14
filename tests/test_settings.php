<?php

/**
 * Test Script for Settings Feature (Section 16)
 * Verifies:
 * 1. SettingsRepository::set (Create & Update)
 * 2. SettingsRepository::get (Retrieve & Default)
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Repositories\SettingsRepository;

echo "════════════════════════════════════════\n";
echo " Section 16: Admin Settings Verification\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "════════════════════════════════════════\n\n";

$repo = new SettingsRepository(getDB());

// 1. Test Default Value
echo "Testing Default Value...\n";
$randomKey = 'test_key_' . uniqid();
$val = $repo->get($randomKey, 'default_val');
if ($val === 'default_val') {
    echo "  ✅ PASS: Returns default value when key missing\n";
} else {
    echo "  ❌ FAIL: Expected 'default_val', got '$val'\n";
}

// 2. Test Set (Create)
echo "\nTesting Create Setting...\n";
$orgName = 'Test Library ' . uniqid();
$repo->set('test_org_name', $orgName);

$fetched = $repo->get('test_org_name');
if ($fetched === $orgName) {
    echo "  ✅ PASS: Setting created and retrieved\n";
} else {
    echo "  ❌ FAIL: Expected '$orgName', got '$fetched'\n";
}

// 3. Test Set (Update)
echo "\nTesting Update Setting (Upsert)...\n";
$newOrgName = 'New Library ' . uniqid();
$repo->set('test_org_name', $newOrgName);

$fetched2 = $repo->get('test_org_name');
if ($fetched2 === $newOrgName) {
    echo "  ✅ PASS: Setting updated correctly\n";
} else {
    echo "  ❌ FAIL: Expected '$newOrgName', got '$fetched2'\n";
}

// 4. Test Special Characters
echo "\nTesting Special Characters...\n";
$special = "Library's \"Cool\" Name <script>";
$repo->set('test_special', $special);
$fetchedSpecial = $repo->get('test_special');

if ($fetchedSpecial === $special) {
    echo "  ✅ PASS: Special characters handled correctly in DB\n";
} else {
    echo "  ❌ FAIL: Special characters mismatch\n";
}

// 5. Cleanup
echo "\nCleaning up...\n";
$pdo = getDB();
$pdo->prepare("DELETE FROM settings WHERE setting_key LIKE 'test_%'")->execute();
echo "  Cleanup done\n";

echo "\n════════════════════════════════════════\n";

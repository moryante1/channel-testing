<?php
// orig 74-99
/* ════════════════════ نهاية تحسينات شاشتي المدمجة ════════════════════ */

$license_key = getLicenseKey();
if (!$license_key) { header('Location: activate.php'); exit; }
$license_result = verifyLicenseFromServer($license_key);
if (!$license_result['success'] || !$license_result['valid']) { header('Location: activate.php'); exit; }
$_SESSION['license_info'] = $license_result['license'] ?? [];
$_SESSION['license_days_left'] = $license_result['license']['days_left'] ?? 0;
if(!isAdminLoggedIn()) { redirect('login.php'); }

// التأكد من وجود جدول الإعدادات
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT
    )");
} catch(PDOException $e) {}

// جلب الإعدادات الحالية من قاعدة البيانات
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}


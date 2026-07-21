<?php
/**
 * رفع شعارات القنوات
 * Channel Logo Upload Handler
 */

session_start();
require_once 'config.php';

if(!isAdminLoggedIn()) {
    die(json_encode(['success' => false, 'error' => 'غير مصرح']));
}

header('Content-Type: application/json');

if(!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
    die(json_encode(['success' => false, 'error' => 'لم يتم رفع الملف']));
}

$file = $_FILES['logo'];

// التحقق من نوع الملف
$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if(!in_array($mime, $allowed)) {
    die(json_encode(['success' => false, 'error' => 'نوع الملف غير مسموح']));
}

// التحقق من حجم الملف (2MB)
if($file['size'] > 2 * 1024 * 1024) {
    die(json_encode(['success' => false, 'error' => 'حجم الملف كبير جداً (الحد الأقصى 2MB)']));
}

// إنشاء مجلد الرفع
$uploadDir = 'uploads/logos/';
if(!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// إنشاء اسم ملف فريد
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = uniqid('logo_') . '.' . $extension;
$filepath = $uploadDir . $filename;

// رفع الملف
if(move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode([
        'success' => true,
        'url' => $filepath,
        'filename' => $filename
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'فشل رفع الملف']);
}

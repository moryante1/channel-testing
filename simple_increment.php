<?php
/**
 * زيادة المشاهدات - بسيط ومباشر
 * Simple Views Increment
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'config.php';

$channel_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($channel_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'معرف غير صالح']);
    exit;
}

try {
    // زيادة مباشرة
    $stmt = $pdo->prepare("UPDATE channels SET views_count = views_count + 1 WHERE id = ?");
    $success = $stmt->execute([$channel_id]);
    
    if ($success) {
        // الحصول على القيمة الجديدة
        $getStmt = $pdo->prepare("SELECT views_count FROM channels WHERE id = ?");
        $getStmt->execute([$channel_id]);
        $new_count = $getStmt->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'channel_id' => $channel_id,
            'views_count' => $new_count
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'فشل التحديث']);
    }
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

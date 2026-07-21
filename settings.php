<?php
/**
 * معالج حفظ إعدادات الموقع
 * Shashety IPTV - Site Settings Handler
 */

require_once 'config.php';

if(!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}

header('Content-Type: application/json');

if($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة غير صحيحة']);
    exit;
}

$action = $_POST['action'] ?? '';

switch($action) {
    case 'save_settings':
        try {
            $settings = [
                'site_name' => sanitizeInput($_POST['site_name'] ?? 'Shashety IPTV'),
                'site_description' => sanitizeInput($_POST['site_description'] ?? 'نظام IPTV احترافي'),
                'site_logo' => sanitizeInput($_POST['site_logo'] ?? ''),
                'welcome_title' => sanitizeInput($_POST['welcome_title'] ?? 'مرحباً بك في عالم البث المباشر'),
                'welcome_subtitle' => sanitizeInput($_POST['welcome_subtitle'] ?? 'شاهد آلاف القنوات من جميع أنحاء العالم'),
                'footer_text' => sanitizeInput($_POST['footer_text'] ?? 'جميع الحقوق محفوظة'),
                'contact_phone' => sanitizeInput($_POST['contact_phone'] ?? ''),
                'contact_email' => sanitizeInput($_POST['contact_email'] ?? ''),
                'contact_facebook' => sanitizeInput($_POST['contact_facebook'] ?? ''),
                'contact_whatsapp' => sanitizeInput($_POST['contact_whatsapp'] ?? ''),
                'theme_color' => sanitizeInput($_POST['theme_color'] ?? '#4cc9f0'),
                'show_categories_count' => isset($_POST['show_categories_count']) ? 1 : 0,
                'show_channels_count' => isset($_POST['show_channels_count']) ? 1 : 0,
            ];
            
            // حفظ الإعدادات في قاعدة البيانات
            foreach($settings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                                      ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
            
            echo json_encode(['success' => true, 'message' => 'تم حفظ الإعدادات بنجاح']);
            
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    case 'get_settings':
        try {
            $stmt = $pdo->query("SELECT * FROM settings");
            $settings = [];
            while($row = $stmt->fetch()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            
            echo json_encode(['success' => true, 'settings' => $settings]);
            
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'إجراء غير معروف']);
}
?>

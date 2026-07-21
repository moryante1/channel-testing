<?php
// orig 100-234

// ══ نظام إدارة المستخدمين والصلاحيات ══
try {
    ("INSERT IGNORE INTO login_logs (ip_address, username, status) VALUES ('127.0.0.1', 'admin', 'success')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip_address VARCHAR(45) NOT NULL,
        username VARCHAR(100),
        status VARCHAR(50) DEFAULT 'failed',
        attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        display_name VARCHAR(100) DEFAULT '',
        role ENUM('administrator','super','normal','custom') DEFAULT 'normal',
        allowed_sections TEXT DEFAULT '[]',
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_login TIMESTAMP NULL
    )");
    // بذر المدير الأول إن كان الجدول فارغاً
    $__au_cnt = $pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
    if ($__au_cnt == 0 && !empty($_SESSION['admin_username'])) {
        $__au_hash = password_hash('admin', PASSWORD_DEFAULT);
        $__au_name = $_SESSION['admin_username'];
        $pdo->prepare("INSERT INTO admin_users (username, password_hash, display_name, role, allowed_sections) VALUES (?, ?, ?, 'administrator', '[]')")
            ->execute([$__au_name, $__au_hash, $__au_name]);
    }
} catch(PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE channels ADD COLUMN subtitle_url VARCHAR(1000) DEFAULT '' AFTER stream_url");
} catch(PDOException $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS series (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL UNIQUE,
        description TEXT,
        poster_url VARCHAR(500),
        logo_icon VARCHAR(100) DEFAULT 'fas fa-film',
        display_order INT DEFAULT 0,
        views_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS episodes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        series_id INT NOT NULL,
        episode_number INT DEFAULT 1,
        title VARCHAR(255) NOT NULL,
        stream_url VARCHAR(1000) NOT NULL,
        subtitle_url VARCHAR(1000),
        duration VARCHAR(50),
        description TEXT,
        display_order INT DEFAULT 0,
        views_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch(PDOException $e) {}

define('VID_UPLOAD_DIR',   __DIR__ . '/uploads/videos/');
define('VID_SUB_DIR',      __DIR__ . '/uploads/subtitles/');
define('VID_MERGED_DIR',   __DIR__ . '/uploads/merged/');
define('SERIES_DIR',       __DIR__ . '/uploads/series/');
define('MUSIC_DIR',        __DIR__ . '/uploads/music/');

$_base = rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'])),'/');
define('VID_UPLOAD_URL',   $_base . '/uploads/videos/');
define('VID_SUB_URL',      $_base . '/uploads/subtitles/');
define('VID_MERGED_URL',   $_base . '/uploads/merged/');
define('SERIES_URL',       $_base . '/uploads/series/');
define('POSTERS_DIR',      __DIR__ . '/uploads/posters/');
define('POSTERS_URL',      $_base . '/uploads/posters/');
define('MUSIC_URL',        $_base . '/uploads/music/');
define('OS_API', 'https://api.opensubtitles.com/api/v1');
define('OS_UA',  'ShashetyIPTV v2.0');

foreach ([VID_UPLOAD_DIR,VID_SUB_DIR,VID_MERGED_DIR,SERIES_DIR,POSTERS_DIR,MUSIC_DIR] as $_d)
    if(!is_dir($_d)) @mkdir($_d,0755,true);

/**
 * حذف آمن لملف محلي فقط (فيديو/ترجمة/بوستر).
 * - يتجاهل الروابط الخارجية (http/https لمضيف آخر) فلا تُحذف.
 * - يحوّل رابط الموقع إلى مسار على القرص.
 * - يتحقق أن المسار النهائي يقع داخل مجلد uploads قبل الحذف (حماية من الخروج بـ ../).
 * يُرجع true إذا حُذف الملف فعلياً.
 */
function shashetyDeleteLocalFile($url) {
    $url = trim((string)$url);
    if ($url === '') return false;

    $uploadsBase = realpath(__DIR__ . '/uploads');
    if ($uploadsBase === false) return false;

    $path = null;

    // 1) رابط كامل: اقبله فقط إن كان يشير لنفس مجلد uploads على هذا الخادم
    if (preg_match('#^https?://#i', $url)) {
        $p = parse_url($url, PHP_URL_PATH);
        if ($p === false || $p === null) return false;
        $p = urldecode($p);
        $pos = strpos($p, '/uploads/');
        if ($pos === false) return false;            // رابط خارجي لا يخص مجلداتنا → اتركه
        $rel = substr($p, $pos + strlen('/uploads/')); // الجزء بعد uploads/
        $path = __DIR__ . '/uploads/' . $rel;
    }
    // 2) مسار يبدأ بـ /uploads/ أو فيه /uploads/
    elseif (strpos($url, '/uploads/') !== false) {
        $pos  = strpos($url, '/uploads/');
        $rel  = substr($url, $pos + strlen('/uploads/'));
        $path = __DIR__ . '/uploads/' . urldecode($rel);
    }
    // 3) اسم ملف مجرّد (بحث في المجلدات المعروفة)
    else {
        $name = basename($url);
        foreach ([SERIES_DIR, VID_UPLOAD_DIR, VID_SUB_DIR, VID_MERGED_DIR, POSTERS_DIR] as $dir) {
            if (is_file($dir . $name)) { $path = $dir . $name; break; }
        }
        if ($path === null) return false;
    }

    if (!is_file($path)) return false;
    $real = realpath($path);
    if ($real === false) return false;

    // تأكيد أن الملف داخل مجلد uploads فقط (منع حذف أي شيء خارجه)
    if (strpos($real, $uploadsBase) !== 0) return false;

    return @unlink($real);
}


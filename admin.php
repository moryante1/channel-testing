<?php if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) { ob_start("ob_gzhandler"); } else { ob_start(); } ?>
<?php
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
require_once 'config.php';
require_once 'client_config.php';

/* ════════════ تحسينات شاشتي المدمجة (أمان + كاش) — إضافة آمنة ════════════ */
if (!defined('SHASHETY_IMPROVE_LOADED')) {
    define('SHASHETY_IMPROVE_LOADED', true);

    // رؤوس أمان (لا تُرسل إن سبق إرسال المحتوى)
    if (!headers_sent()) {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header_remove('X-Powered-By');
    }

    // توكن CSRF (اختياري الاستخدام)
    if (!function_exists('shashety_csrf_token')) {
        function shashety_csrf_token() {
            if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
            if (empty($_SESSION['shashety_csrf'])) {
                $_SESSION['shashety_csrf'] = bin2hex(random_bytes(32));
            }
            return $_SESSION['shashety_csrf'];
        }
        function shashety_csrf_check($stop_on_fail = true) {
            $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            $real = $_SESSION['shashety_csrf'] ?? '';
            if ($real !== '' && hash_equals($real, (string)$sent)) { return true; }
            if ($stop_on_fail) {
                if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
                echo json_encode(['success'=>false,'error'=>'انتهت صلاحية الجلسة، أعد تحميل الصفحة']);
                exit;
            }
            return false;
        }
    }

    // كاش بسيط على القرص للاستعلامات الثقيلة (اختياري)
    if (!function_exists('shashety_cache_get')) {
        function shashety_cache_dir() {
            $d = __DIR__ . '/cache';
            if (!is_dir($d)) @mkdir($d, 0755, true);
            return $d;
        }
        function shashety_cache_get($key, $ttl_seconds, callable $producer) {
            $key  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
            $file = shashety_cache_dir() . '/' . $key . '.json';
            if (is_file($file) && (time() - filemtime($file) < $ttl_seconds)) {
                $raw = @file_get_contents($file);
                if ($raw !== false) {
                    $data = json_decode($raw, true);
                    if ($data !== null) return $data;
                }
            }
            $data = $producer();
            @file_put_contents($file, json_encode($data), LOCK_EX);
            return $data;
        }
        function shashety_cache_clear($key = null) {
            $dir = shashety_cache_dir();
            if ($key) {
                $key = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
                @unlink($dir . '/' . $key . '.json');
                return;
            }
            foreach (glob($dir . '/*.json') as $f) @unlink($f);
        }
    }
}
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

if (isset($_POST['ajax_action'])) {
    while(ob_get_level()) ob_end_clean();
    ob_start();
    @set_time_limit(0);
    @session_write_close(); // تحرير الجلسة مبكراً لمنع تعليق المتصفح أثناء العمليات الطويلة كالاستيراد
    @ini_set('memory_limit','-1');
    /* ملاحظة: upload_max_filesize و post_max_size من نوع PHP_INI_PERDIR
       ولا يمكن تغييرهما من الكود وقت التشغيل — يجب ضبطهما في php.ini
       أو .htaccess. القيمة '0' كانت تعني "صفر بايت" ولم تكن تُطبَّق أصلاً. */
    @ini_set('max_input_time','-1');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    $act = $_POST['ajax_action'];

    if($act==='debug_upload'){
        $maxPost=ini_get('post_max_size');
        $maxFile=ini_get('upload_max_filesize');
        $uploadDir=VID_UPLOAD_DIR;
        $dirExists=is_dir($uploadDir);
        $dirWrite=$dirExists&&is_writable($uploadDir);
        jOk(['post_max_size'=>$maxPost,'upload_max_filesize'=>$maxFile,'upload_dir'=>$uploadDir,'dir_exists'=>$dirExists,'dir_writable'=>$dirWrite,'php_version'=>PHP_VERSION,'extensions'=>['fileinfo'=>extension_loaded('fileinfo'),'gd'=>extension_loaded('gd')]]);
    }

    if($act === 'get_channels'){
        $channelsList = $pdo->query("SELECT ch.*,c.name as cat_name FROM channels ch LEFT JOIN categories c ON ch.category_id=c.id ORDER BY ch.category_id,ch.display_order,ch.id")->fetchAll(PDO::FETCH_ASSOC);
        while(ob_get_level()) ob_end_clean();
        $json = json_encode(['success' => true, 'channels' => $channelsList], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            // Fallback if JSON_INVALID_UTF8_SUBSTITUTE fails or isn't enough (e.g. older PHP)
            array_walk_recursive($channelsList, function(&$item, $key) {
                if (is_string($item)) $item = mb_convert_encoding($item, 'UTF-8', 'UTF-8');
            });
            $json = json_encode(['success' => true, 'channels' => $channelsList]);
        }
        echo $json;
        exit;
    }

    function jOk($d=[]){while(ob_get_level())ob_end_clean();echo json_encode(array_merge(['success'=>true],$d));exit;}
    function jErr($e,$dbg=''){while(ob_get_level())ob_end_clean();$r=['success'=>false,'error'=>$e];if($dbg)$r['debug']=$dbg;echo json_encode($r);exit;}
    function mvFile($tmp,$dest){return move_uploaded_file($tmp,$dest);}

    /**
     * تحليل محتوى ملف M3U/M3U8 واستخراج القنوات منه.
     * يرجع مصفوفة عناصر: ['name'=>..,'logo'=>..,'group'=>..,'url'=>..]
     */
    function parseM3UPlaylist($content){
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // إزالة BOM إن وجد
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $items = [];
        $cur = null;
        foreach($lines as $line){
            $line = trim($line);
            if($line === '') continue;
            if(stripos($line, '#EXTM3U') === 0) continue;
            if(stripos($line, '#EXTINF') === 0){
                $name = '';
                if(preg_match('/,(?!.*,)(.*)$/', $line, $m)) $name = trim($m[1]);
                $logo = '';
                if(preg_match('/tvg-logo="([^"]*)"/i', $line, $m)) $logo = trim($m[1]);
                $group = '';
                if(preg_match('/group-title="([^"]*)"/i', $line, $m)) $group = trim($m[1]);
                if($name === '' && preg_match('/tvg-name="([^"]*)"/i', $line, $m)) $name = trim($m[1]);
                $cur = ['name'=>$name ?: 'بدون اسم', 'logo'=>$logo, 'group'=>$group, 'url'=>''];
                continue;
            }
            if($line[0] === '#') continue; // أسطر تعليقات/خصائص أخرى نتجاهلها
            if($cur !== null){
                $cur['url'] = $line;
                $items[] = $cur;
                $cur = null;
            }
        }
        return $items;
    }

    /** تنزيل محتوى رابط M3U عن بعد عبر cURL */
    function m3uFetchUrl($url, &$err = null){
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (ShashetyIPTV M3U Importer)',
        ]);
        $content = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        return $content;
    }

    /** إدخال قنوات قائمة M3U محلّلة داخل قاعدة البيانات، مع إنشاء/مطابقة الأقسام تلقائياً من group-title */
    function m3uInsertChannels($pdo, $items, $playlistId){
        $catCache = [];
        $stmtIns = $pdo->prepare("INSERT INTO channels (category_id, name, stream_url, logo_icon, logo_url, backup_url, quality, is_active, playlist_id) VALUES (?,?,?,?,?,?,?,1,?)");
        $inserted = 0;
        foreach($items as $it){
            if(empty($it['url'])) continue;
            $groupName = trim($it['group']);
            if($groupName === '') $groupName = 'قنوات مستوردة';
            $key = mb_strtolower($groupName);
            if(isset($catCache[$key])){
                $catId = $catCache[$key];
            } else {
                $stmt = $pdo->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
                $stmt->execute([$groupName]);
                $catId = $stmt->fetchColumn();
                if(!$catId){
                    $slug = "cat-".time()."-".rand(1000,9999);
                    $pdo->prepare("INSERT INTO categories (name, icon, slug) VALUES (?, 'fas fa-th-large', ?)")->execute([$groupName, $slug]);
                    $catId = $pdo->lastInsertId();
                }
                $catCache[$key] = $catId;
            }
            $name = htmlspecialchars(strip_tags($it['name']));
            $stmtIns->execute([$catId, $name, $it['url'], 'fas fa-tv', $it['logo'], '', 'HD 720', $playlistId]);
            $inserted++;
        }
        return $inserted;
    }
    function slugU($s){return strtolower(trim(preg_replace('/[^a-z0-9\-]/','',str_replace([' ','_'],'-',$s)),'-'));}
    function osBase(){ return $_SESSION['os_base'] ?? OS_API; }

    function osH($auth=true){
        $h = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Api-Key: '.($_SESSION['os_api_key'] ?? ''),
            'User-Agent: '.OS_UA
        ];
        if($auth && !empty($_SESSION['os_token'])) $h[] = 'Authorization: Bearer '.$_SESSION['os_token'];
        return $h;
    }

    function osReq($url,$m='GET',$body=null,$auth=true){
        $ch = curl_init($url);
        $o = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => osH($auth),
            CURLOPT_USERAGENT      => OS_UA
        ];
        if($m === 'POST'){
            $o[CURLOPT_POST] = true;
            $o[CURLOPT_POSTFIELDS] = $body ? json_encode($body) : '{}';
        } elseif($m === 'DELETE'){
            $o[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }
        curl_setopt_array($ch,$o);
        $r = curl_exec($ch);
        $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $e = curl_error($ch);
        curl_close($ch);
        return [$c,$r,$e];
    }

    // يعيد تسجيل الدخول تلقائياً من البيانات المحفوظة في جدول settings
    function osAutoLogin(){
        global $pdo;
        try {
            $st = $pdo->query("SELECT setting_key, setting_value FROM settings
                               WHERE setting_key IN ('os_api_key','os_username','os_password')");
            $cfg = [];
            foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
                $cfg[$row['setting_key']] = $row['setting_value'];
            }
        } catch(Exception $ex){ return false; }

        $k = trim($cfg['os_api_key']  ?? '');
        $u = trim($cfg['os_username'] ?? '');
        $p = trim($cfg['os_password'] ?? '');
        if($k === '' || $u === '' || $p === '') return false;

        $_SESSION['os_api_key'] = $k;
        unset($_SESSION['os_base'], $_SESSION['os_token']);

        [$c,$r,$e] = osReq(OS_API.'/login','POST',['username'=>$u,'password'=>$p],false);
        if($e){ unset($_SESSION['os_api_key']); return false; }

        $d = json_decode($r,true);
        if($c === 200 && !empty($d['token'])){
            $_SESSION['os_token']    = $d['token'];
            $_SESSION['os_username'] = $u;
            $_SESSION['os_api_key']  = $k;
            if(!empty($d['base_url'])){
                $b = preg_replace('#^https?://#','',rtrim($d['base_url'],'/'));
                $_SESSION['os_base'] = 'https://'.$b.'/api/v1';
            }
            return true;
        }
        unset($_SESSION['os_api_key'], $_SESSION['os_base']);
        return false;
    }

    function osGuard(){
        if(!empty($_SESSION['os_api_key']) && !empty($_SESSION['os_token'])) return;
        if(osAutoLogin()) return;
        jErr('تعذّر الاتصال بـ OpenSubtitles — افتح الإعدادات وسجّل الدخول مرة واحدة');
    }

    // ====== START ADMIN AUDIO PLAYER AJAX ======
    if ($act === 'upload_admin_music') {
        if (!isset($_FILES['music_file'])) jErr('لم يتم رفع أي ملف');
        $f = $_FILES['music_file'];
        if ($f['error'] !== UPLOAD_ERR_OK) jErr('خطأ في الرفع: ' . $f['error']);
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($ext !== 'mp3') jErr('عذراً، النظام يقبل ملفات mp3 فقط.');
        // Secure file name
        $original = str_replace('.mp3', '', basename($f['name']));
        $clean = preg_replace('/[^\p{L}\p{N}_\- ]/u', '', $original);
        if (!$clean) $clean = 'track';
        $newName = uniqid() . '_' . str_replace(' ', '_', $clean) . '.mp3';
        if (mvFile($f['tmp_name'], MUSIC_DIR . $newName)) {
            jOk(['file' => $newName, 'url' => MUSIC_URL . $newName]);
        } else jErr('فشل في نقل الملف');
    }
    if ($act === 'delete_admin_music') {
        $file = $_POST['file'] ?? '';
        $file = basename($file);
        if ($file && file_exists(MUSIC_DIR . $file)) {
            @unlink(MUSIC_DIR . $file);
            jOk();
        }
        jErr('الملف غير موجود');
    }
    if ($act === 'list_admin_music') {
        $files = [];
        if (is_dir(MUSIC_DIR)) {
            $globFiles = glob(MUSIC_DIR . '*.mp3');
            if ($globFiles) {
                // ترتيب حسب تاريخ الإضافة (الأحدث أولاً)
                usort($globFiles, function($a, $b) { return filemtime($b) - filemtime($a); });
                foreach($globFiles as $file) {
                    $files[] = ['name' => basename($file), 'url' => MUSIC_URL . basename($file)];
                }
            }
        }
        jOk(['tracks' => $files]);
    }
    // ====== END ADMIN AUDIO PLAYER AJAX ======

// ====== START TAILSCALE PYTHON CONTROLLER (UBUNTU LINUX ONLY) ======
    if ($act === 'tailscale_command') {
        $ts_cmd = $_POST['ts_action'] ?? 'status';
        $py_path = __DIR__ . DIRECTORY_SEPARATOR . 'tailscale_control.py';
        
        if (!file_exists($py_path)) { jErr("تعذر إيجاد سكربت البايثون في مسار الخادم."); }
        
        // استدعاء البايثون بأسلوب اللينكس المباشر (استخدام python3)
        $command = "/usr/bin/env python3 " . escapeshellarg($py_path) . " " . escapeshellarg($ts_cmd) . " 2>&1";
        $output = shell_exec($command);
        
        $result = json_decode($output, true);
        if(is_array($result)){
            jOk($result);
        }
    }
    // ====== END TAILSCALE PYTHON CONTROLLER ======
    
    // ====== START SERVER STATS ======
    if ($act === 'get_server_stats') {
        // Disk Stats
        $diskTotal = @disk_total_space("/");
        $diskFree = @disk_free_space("/");
        $diskUsed = $diskTotal - $diskFree;
        $diskPercent = ($diskTotal > 0) ? round(($diskUsed / $diskTotal) * 100) : 0;
        
        $formatBytes = function($bytes) {
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $bytes = max($bytes, 0);
            $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
            $pow = min($pow, count($units) - 1);
            return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
        };

        // RAM Stats (Linux fallback)
        $ramTotal = 0; $ramUsed = 0; $ramPercent = 0;
        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $totalMatch);
            preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $availMatch);
            if(isset($totalMatch[1]) && isset($availMatch[1])) {
                $ramTotal = $totalMatch[1] * 1024;
                $ramAvail = $availMatch[1] * 1024;
                $ramUsed = $ramTotal - $ramAvail;
                $ramPercent = round(($ramUsed / $ramTotal) * 100);
            }
        } else {
            // Windows Fallback
            $ramTotal = 8 * 1024 * 1024 * 1024;
            $ramUsed = memory_get_usage(true) * 50; 
            $ramPercent = rand(30, 60);
        }

        // CPU Stats
        $cpuPercent = 0;
        if (function_exists('sys_getloadavg') && is_readable('/proc/cpuinfo')) {
            $load = sys_getloadavg();
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            $cores = preg_match_all('/^processor/m', $cpuinfo, $matches);
            if ($cores > 0 && isset($load[0])) {
                $cpuPercent = min(100, round(($load[0] / $cores) * 100));
            }
        } else {
            $cpuPercent = rand(10, 45); // Windows fallback
        }

        jOk([
            'disk' => [
                'total' => $formatBytes($diskTotal),
                'used' => $formatBytes($diskUsed),
                'free' => $formatBytes($diskFree),
                'percent' => $diskPercent
            ],
            'ram' => [
                'total' => $formatBytes($ramTotal),
                'used' => $formatBytes($ramUsed),
                'percent' => $ramPercent
            ],
            'cpu' => [
                'percent' => $cpuPercent
            ]
        ]);
    }
    // ====== END SERVER STATS ======
        if($act==='save_api_settings'){
        global $pdo;
        $tmdb = $_POST['tmdb_key'] ?? '';
        $os_user = $_POST['os_user'] ?? '';
        $os_pass = $_POST['os_pass'] ?? '';
        $os_key = $_POST['os_key'] ?? '';
        $omdb = $_POST['omdb_key'] ?? '';

        $upsert = function($k, $v) use ($pdo) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
            $stmt->execute([$k]);
            if ($stmt->fetchColumn() > 0) {
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$v, $k]);
            } else {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
            }
        };

        $upsert('tmdb_api_key', $tmdb);
        $upsert('os_username', $os_user);
        $upsert('os_password', $os_pass);
        $upsert('os_api_key', $os_key);
        $upsert('omdb_api_key', $omdb);
        jOk(['message' => 'تم حفظ إعدادات الـ API بنجاح']);
    }

    // ══ حفظ الثيم المخصص في قاعدة البيانات ══
    if ($act === 'save_custom_css') {
        $css       = $_POST['custom_css']  ?? '';
        $theme_key = $_POST['theme_key']   ?? 'default';
        $upsert = function($k, $v) use ($pdo) {
            $s = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
            $s->execute([$k]);
            if ($s->fetchColumn() > 0) {
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$v, $k]);
            } else {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
            }
        };
        $upsert('custom_css',   $css);
        $upsert('active_theme', $theme_key);
        jOk(['message' => 'تم حفظ الثيم بنجاح في قاعدة البيانات']);
    }

    // ══ حفظ إعدادات إخفاء عناصر الواجهة الأمامية (index.php) ══
    if ($act === 'save_frontend_toggles') {
        $upsert = function($k, $v) use ($pdo) {
            $s = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
            $s->execute([$k]);
            if ($s->fetchColumn() > 0) {
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$v, $k]);
            } else {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
            }
        };
        $keys = ['hide_search','hide_notifications','hide_favorites','hide_music',
                 'hide_admin_btn','hide_social','hide_download','hide_cast',
                 'hide_most_watched','hide_suggestions','hide_screensaver'];
        foreach ($keys as $k) {
            $upsert($k, (isset($_POST[$k]) && $_POST[$k] === '1') ? '1' : '0');
        }
        jOk(['message' => 'تم حفظ إعدادات الواجهة بنجاح']);
    }

    /* ═══════════════════════════════════════════════════════════════════════
       ║  استرجاع القيم الأصلية للإعدادات (حذف التكوينات المخصصة)              ║
       ═══════════════════════════════════════════════════════════════════════ */
    if ($act === 'restore_default_settings') {
        $prefixes = ['st_', 'ui_', 'perf_', 'sub_', 'sr_', 'mv_', 'ch_', 'pl_', 'usr_'];
        $sql = "DELETE FROM settings WHERE ";
        $conditions = [];
        $params = [];
        foreach ($prefixes as $p) {
            $conditions[] = "setting_key LIKE ?";
            $params[] = $p . '%';
        }
        $frontend_keys = ['hide_search','hide_notifications','hide_favorites','hide_music',
                 'hide_admin_btn','hide_social','hide_download','hide_cast',
                 'hide_most_watched','hide_suggestions','hide_screensaver'];
        foreach ($frontend_keys as $k) {
            $conditions[] = "setting_key = ?";
            $params[] = $k;
        }
        $sql .= implode(' OR ', $conditions);
        $pdo->prepare($sql)->execute($params);
        jOk(['message' => 'تم استرجاع القيم الأصلية بنجاح']);
    }

    /* ═══════════════════════════════════════════════════════════════════════
       ║  حفظ الإعدادات العامة الحساسة (تُطبَّق على index.php مباشرة)          ║
       ║  كل هذه الإعدادات تُخزَّن في جدول settings ولا تحتاج تعديل أي ملف      ║
       ═══════════════════════════════════════════════════════════════════════ */
    if ($act === 'save_general_settings') {
        $upsert = function($k, $v) use ($pdo) {
            $s = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
            $s->execute([$k]);
            if ($s->fetchColumn() > 0) {
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$v, $k]);
            } else {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
            }
        };

        // ── القيم النصية (تُحفظ كما هي) ──
        // gs_text_keys: مفاتيح نصية عامة يُتحكم بها من قسم الإعدادات العامة
        $gs_text_keys = [
            'site_name',          // اسم الموقع الظاهر في العنوان و og:title
            'site_description',   // وصف الموقع (SEO / meta description)
            'welcome_title',      // عنوان الترحيب في الصفحة الرئيسية
            'welcome_subtitle',   // العنوان الفرعي للترحيب
            'footer_text',        // نص حقوق الفوتر
            'theme_color',        // اللون الأساسي للثيم (accent)
            'site_logo',          // رابط شعار الموقع
            'contact_whatsapp',   // رقم واتساب للتواصل (حساس)
            'contact_facebook',   // رابط صفحة الفيسبوك
            'contact_email',      // بريد التواصل
            'maintenance_message',// نص رسالة وضع الصيانة الظاهر للزوار
            'announcement_text',  // نص الشريط الإعلاني العلوي
            'announcement_link',  // رابط اختياري للشريط الإعلاني
            'custom_head_code',   // كود مخصص يُحقن داخل <head> (تحليلات/بكسل/سكربت) — حساس جداً
            'custom_body_code',   // كود مخصص يُحقن قبل </body> (شات/سكربتات) — حساس جداً
            'gate_password',      // كلمة مرور قفل الموقع الكامل (حماية بكلمة سر) — حساس جداً
        ];
        foreach ($gs_text_keys as $k) {
            if (array_key_exists($k, $_POST)) $upsert($k, (string)$_POST[$k]);
        }

        // ── القيم المفتاحية (تبديل ON/OFF: '1' أو '0') ──
        // gs_bool_keys: مفاتيح تشغيل/إيقاف حساسة تتحكم بسلوك الموقع بالكامل
        $gs_bool_keys = [
            'maintenance_mode',    // وضع الصيانة: عند التفعيل يُغلق الموقع أمام الزوار — حساس جداً
            'gate_enabled',        // تفعيل قفل الموقع بكلمة مرور — حساس جداً
            'announcement_enabled',// إظهار الشريط الإعلاني العلوي
            'force_https',         // إجبار إعادة التوجيه إلى HTTPS
            'block_devtools',      // تعطيل أدوات المطور والنقر الأيمن على الواجهة
            'disable_download',    // منع تحميل الفيديوهات عالمياً
        ];
        foreach ($gs_bool_keys as $k) {
            $upsert($k, (isset($_POST[$k]) && $_POST[$k] === '1') ? '1' : '0');
        }

        // مسح الكاش بعد تغيير إعدادات حساسة (إن كان نظام الكاش مفعّلاً)
        if (function_exists('shashety_cache_clear')) { @shashety_cache_clear(); }

        jOk(['message' => 'تم حفظ الإعدادات العامة بنجاح']);
    }

    // ══ جلب الإعدادات العامة الحالية (لتعبئة النموذج) ══
    if ($act === 'get_general_settings') {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        jOk(['settings' => $rows]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
       ║  حفظ مجموعة إعدادات (كل مجموعة لها زر خاص) — نفس آلية الإعدادات العامة ║
       ║  كل مفتاح مسموح ضمن allowlist لكل مجموعة، ويُحفظ في جدول settings.     ║
       ║  التعليق بجانب كل مفتاح = القيمة الأصلية/الافتراضية المستخدمة في index. ║
       ═══════════════════════════════════════════════════════════════════════ */
    if ($act === 'save_settings_group') {
        $group = $_POST['group'] ?? '';

        // خريطة المجموعات → المفاتيح المسموحة (allowlist أمني لكل مجموعة)
        $GROUPS = [

            // ── إعدادات البث الخادمية (تُخزَّن لربطها بخادم FFmpeg/البث) ──
            'streaming_server' => [
                'srv_hls_segment_duration', // الأصلي: 6 (ثواني لكل جزء HLS)
                'srv_playlist_length',      // الأصلي: 5 (عدد الأجزاء في playlist)
                'srv_llhls_enable',         // الأصلي: 0 (LL-HLS معطّل)
                'srv_ffmpeg_params',        // الأصلي: فارغ (باراميترات FFmpeg إضافية)
                'srv_hwaccel',              // الأصلي: none (none/nvenc/vaapi/qsv)
                'srv_thread_count',         // الأصلي: 0 (0 = تلقائي)
                'srv_tcp_udp_buffer',       // الأصلي: 8192 (كيلوبايت)
                'srv_socket_buffer',        // الأصلي: 65536 (بايت)
                'srv_cdn_failover',         // الأصلي: 0 (معطّل)
                'srv_stream_priority',      // الأصلي: normal (low/normal/high)
                'srv_health_check_interval',// الأصلي: 30 (ثانية)
                'srv_auto_restart_stream',  // الأصلي: 1 (مفعّل)
                'srv_stream_timeout',       // الأصلي: 20 (ثانية)
                'srv_packet_loss_recovery', // الأصلي: 1 (مفعّل)
                'srv_jitter_buffer',        // الأصلي: 500 (مللي ثانية)
                'srv_abr_enable',           // الأصلي: 1 (ABR مفعّل)
                'srv_max_bitrate',          // الأصلي: 8000 (كيلوبت/ث)
                'srv_min_bitrate',          // الأصلي: 800 (كيلوبت/ث)
                'srv_gop_size',             // الأصلي: 48 (إطار)
                'srv_keyframe_interval',    // الأصلي: 2 (ثانية)
            ],

            // ── إعدادات الواجهة ──
            'ui' => [
                'ui_theme',            // الأصلي: dark (dark/light/netflix/...)
                'theme_color',         // الأصلي: #e50914 (اللون الأساسي)
                'ui_font',             // الأصلي: Tajawal (اسم الخط)
                'ui_font_size',        // الأصلي: 16 (px)
                'ui_transitions',      // الأصلي: 1 (تأثيرات الانتقال مفعّلة)
                'ui_banner',           // الأصلي: فارغ (رابط بانر أعلى الصفحة)
                'ui_icon_style',       // الأصلي: solid (solid/regular/duotone)
            ],

            // ── إعدادات الصور ──
            'images' => [
                'img_default_channel', // الأصلي: فارغ (صورة افتراضية للقنوات)
                'img_default_movie',   // الأصلي: فارغ (صورة افتراضية للأفلام)
                'img_default_series',  // الأصلي: فارغ (صورة افتراضية للمسلسلات)
                'img_quality',         // الأصلي: 85 (جودة 1-100)
                'img_compression',     // الأصلي: 1 (ضغط الصور مفعّل)
            ],

            // ── إعدادات المستخدم ──
            'user' => [
                'usr_save_last_watch', // الأصلي: 1 (حفظ آخر مشاهدة)
                'usr_autoplay',        // الأصلي: 1 (التشغيل التلقائي)
                'usr_dark_mode',       // الأصلي: 1 (الوضع الليلي افتراضياً)
                'usr_language',        // الأصلي: ar (اللغة)
                'usr_notifications',   // الأصلي: 1 (الإشعارات)
                'usr_favorites',       // الأصلي: 1 (المفضلة مفعّلة)
                'usr_watch_history',   // الأصلي: 1 (سجل المشاهدة مفعّل)
            ],

            // ── الأداء (Performance) ──
            'performance' => [
                'perf_cache_duration', // الأصلي: 3600 (ثانية)
                'perf_image_cache',    // الأصلي: 1 (كاش الصور مفعّل)
                'perf_api_cache',      // الأصلي: 1 (كاش API مفعّل)
                'perf_gzip_brotli',    // الأصلي: 1 (ضغط مفعّل)
                'perf_lazy_loading',   // الأصلي: 1 (Lazy Loading مفعّل)
                'perf_http_version',   // الأصلي: 2 (HTTP/2)
                'perf_prefetch',       // الأصلي: 1 (Prefetch مفعّل)
                'perf_preconnect',     // الأصلي: 1 (Preconnect مفعّل)
            ],

            // ── إعدادات الترجمة العامة ──
            'subtitles' => [
                'sub_default_language',// الأصلي: ar (اللغة الافتراضية للترجمة)
                'sub_font_size',       // الأصلي: 18 (px)
                'sub_font_color',      // الأصلي: #ffffff (لون الخط)
                'sub_bg_color',        // الأصلي: #000000 (لون الخلفية)
                'sub_position',        // الأصلي: bottom (top/center/bottom)
                'sub_bg_opacity',      // الأصلي: 60 (شفافية الخلفية 0-100)
            ],

            // ── إعدادات المسلسلات ──
            'series' => [
                'sr_resume_last_ep',   // الأصلي: 1 (التشغيل من آخر حلقة)
                'sr_auto_next_ep',     // الأصلي: 1 (الانتقال للحلقة التالية)
                'sr_skip_intro',       // الأصلي: 0 (تخطي المقدمة)
                'sr_skip_outro',       // الأصلي: 0 (تخطي الشارة الختامية)
                'sr_season_order',     // الأصلي: asc (ترتيب المواسم asc/desc)
            ],

            // ── إعدادات الأفلام ──
            'movies' => [
                'mv_per_page',         // الأصلي: 24 (عدد الأفلام في الصفحة)
                'mv_default_quality',  // الأصلي: auto (auto/480/720/1080)
                'mv_auto_subtitle',    // الأصلي: 0 (تشغيل الترجمة تلقائياً)
                'mv_subtitle_language',// الأصلي: ar (اللغة الافتراضية للترجمة)
                'mv_play_trailer',     // الأصلي: 1 (تشغيل التريلر)
                'mv_show_similar',     // الأصلي: 1 (عرض الأفلام المشابهة)
                'mv_resume_watch',     // الأصلي: 1 (استكمال المشاهدة)
            ],

            // ── إعدادات القنوات ──
            'channels' => [
                'ch_per_page',         // الأصلي: 40 (عدد القنوات في الصفحة)
                'ch_order',            // الأصلي: display_order (ترتيب القنوات)
                'ch_group_order',      // الأصلي: display_order (ترتيب المجموعات)
                'ch_hide_offline',     // الأصلي: 0 (إخفاء غير المتصلة)
                'ch_auto_status',      // الأصلي: 0 (تحديث الحالة تلقائياً)
                'ch_check_interval',   // الأصلي: 60 (ثانية فترة الفحص)
                'ch_resume_last',      // الأصلي: 1 (تشغيل آخر قناة)
            ],

            // ── إعدادات مشغّل الفيديو ──
            'player' => [
                'pl_autoplay',         // الأصلي: 1 (التشغيل التلقائي)
                'pl_mute_on_start',    // الأصلي: 0 (كتم عند التشغيل)
                'pl_auto_fullscreen',  // الأصلي: 0 (ملء الشاشة تلقائياً)
                'pl_pip',              // الأصلي: 1 (Picture in Picture)
                'pl_webcast',          // الأصلي: 1 (webcast مفعّل)
                'pl_seek_buttons',     // الأصلي: 1 (أزرار التقديم/الترجيع)
                'pl_playback_speed',   // الأصلي: 1 (سرعة التشغيل الافتراضية)
                'pl_thumbnails',       // الأصلي: 1 (معاينة الصور)
                'pl_show_channel_logo',// الأصلي: 1 (إظهار شعار القناة)
                'pl_show_channel_name',// الأصلي: 1 (إظهار اسم القناة)
                'pl_show_clock',       // الأصلي: 0 (إظهار الساعة)
                'pl_show_viewers',     // الأصلي: 0 (عداد المشاهدين)
                'pl_show_share',       // الأصلي: 1 (زر المشاركة)
                'pl_show_report',      // الأصلي: 1 (زر الإبلاغ)
            ],

            // ── إعدادات البث (Streaming — جهة العميل/المشغّل) ──
            'streaming_client' => [
                'st_low_latency',      // الأصلي: 0 (Low Latency Mode)
                'st_buffer_size',      // الأصلي: 30 (ثانية 1-30)
                'st_startup_buffer',   // الأصلي: 2 (ثانية)
                'st_max_buffer',       // الأصلي: 60 (ثانية Max Buffer Length)
                'st_back_buffer',      // الأصلي: 90 (ثانية Back Buffer)
                'st_live_sync',        // الأصلي: 3 (Live Sync Duration)
                'st_auto_quality',     // الأصلي: 1 (Auto Quality ABR)
                'st_default_quality',  // الأصلي: auto (الجودة الافتراضية)
                'st_allow_quality_change',// الأصلي: 1 (السماح بتغيير الجودة)
                'st_auto_reconnect',   // الأصلي: 1 (إعادة الاتصال التلقائي)
                'st_reconnect_attempts',// الأصلي: 5 (عدد المحاولات)
                'st_reconnect_timeout',// الأصلي: 3 (ثانية قبل إعادة الاتصال)
                'st_failover',         // الأصلي: 1 (الانتقال لرابط احتياطي)
                'st_protocol',         // الأصلي: hls (hls/dash)
                'st_llhls_support',    // الأصلي: 0 (دعم LL-HLS)
                'st_playlist_refresh', // الأصلي: 6 (ثانية مدة تحديث Playlist)
                'st_stream_cache',     // الأصلي: 1 (Cache للبث مفعّل)
            ],
        ];

        if (!isset($GROUPS[$group])) jErr('مجموعة غير معروفة');

        $allowed = $GROUPS[$group];
        $upsert = function($k, $v) use ($pdo) {
            $s = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
            $s->execute([$k]);
            if ($s->fetchColumn() > 0) {
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$v, $k]);
            } else {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
            }
        };

        // كل مفتاح مسموح: إن كان checkbox (يُرسل '1'/'0' من الواجهة) يُحفظ كما هو،
        // وأي حقل نصي/رقمي يُحفظ كنص. الواجهة ترسل كل المفاتيح المسموحة دائماً.
        foreach ($allowed as $k) {
            if (array_key_exists($k, $_POST)) {
                $upsert($k, (string)$_POST[$k]);
            }
        }

        if (function_exists('shashety_cache_clear')) { @shashety_cache_clear(); }
        jOk(['message' => 'تم حفظ إعدادات المجموعة بنجاح', 'group' => $group]);
    }

    // ══ تفعيل / تعطيل ظهور قسم في الواجهة الأمامية (index.php) ══
    if ($act === 'toggle_category_active') {
        $cid = (int)($_POST['category_id'] ?? 0);
        $active = (isset($_POST['is_active']) && $_POST['is_active'] === '1') ? 1 : 0;
        if (!$cid) jErr('قسم غير صالح');
        try {
            $pdo->prepare("UPDATE categories SET is_active = ? WHERE id = ?")->execute([$active, $cid]);
            jOk(['message' => $active ? 'تم تفعيل ظهور القسم' : 'تم إخفاء القسم من الواجهة الأمامية', 'is_active' => $active]);
        } catch (PDOException $e) {
            jErr('تعذّر تحديث حالة القسم');
        }
    }

    // ══ تفعيل / تعطيل قناة (نشطة - غير نشطة) في الواجهة الأمامية (index.php) ══
    if ($act === 'toggle_channel_active') {
        $chid = (int)($_POST['channel_id'] ?? 0);
        $active = (isset($_POST['is_active']) && $_POST['is_active'] === '1') ? 1 : 0;
        if (!$chid) jErr('قناة غير صالحة');
        try {
            $pdo->prepare("UPDATE channels SET is_active = ? WHERE id = ?")->execute([$active, $chid]);
            jOk(['message' => $active ? 'تم تفعيل القناة' : 'تم تعطيل القناة', 'is_active' => $active]);
        } catch (PDOException $e) {
            jErr('تعذّر تحديث حالة القناة');
        }
    }

    // ══ وظائف التحميل الذكي (صُححت وباتت أكثر كفاءة) ══
    if($act === 'abort_smart_dl') {
        $n = trim($_POST['filename'] ?? '');
        if($n) @file_put_contents(VID_UPLOAD_DIR . $n . '.abort', '1');
        jOk();
    }

    if($act === 'prep_smart_dl') {
        $url = trim($_POST['url'] ?? '');
        if(!$url || !filter_var($url, FILTER_VALIDATE_URL)) jErr('الرابط المُدخل غير صالح!');
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if(!$ext || !in_array($ext, ['mp4','mkv','avi','mov','webm','ts','flv'])) $ext = 'mp4'; 

        $n = uniqid('vid_dl_').'.'.$ext;
        
        $headers = @get_headers($url, true);
        $totalSize = 0;
        if($headers) {
            $cl = $headers['Content-Length'] ?? ($headers['content-length'] ?? 0);
            $totalSize = is_array($cl) ? end($cl) : $cl;
        }

        $progFile = VID_UPLOAD_DIR . $n . '.prog';
        @file_put_contents($progFile, json_encode(['total' => (int)$totalSize, 'loaded' => 0]));
        @unlink(VID_UPLOAD_DIR . $n . '.abort'); 

        $original = basename(parse_url($url, PHP_URL_PATH));
        if(!$original || strlen($original) < 3) $original = $n;

        jOk(['filename' => $n, 'original' => $original, 'total' => (int)$totalSize]);
    }

    if($act === 'do_smart_dl') {
        @session_write_close(); // إغلاق الجلسة مهم ليعمل السيرفر بالخلفية بشكل متوازي

        $url = trim($_POST['url'] ?? '');
        $n = trim($_POST['filename'] ?? '');
        if(!$url || !$n) jErr('البيانات المُرسلة للتحميل غير مكتملة.');

        $dest = VID_UPLOAD_DIR . $n;
        $progFile = VID_UPLOAD_DIR . $n . '.prog';
        $abortFile = VID_UPLOAD_DIR . $n . '.abort';

        $ch = curl_init($url);
        $wh = @fopen($dest, 'wb');
        
        if(!$wh) { @unlink($progFile); jErr('المسار المختار على السيرفر لا يدعم الكتابة!'); }

        $total = 0;
        $lastUpdate = time();

        if(file_exists($progFile)) {
            $pf = json_decode(@file_get_contents($progFile), true);
            $total = $pf['total'] ?? 0;
        }

        curl_setopt($ch, CURLOPT_FILE, $wh);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0); 
        curl_setopt($ch, CURLOPT_ENCODING, ""); 
        curl_setopt($ch, CURLOPT_BUFFERSIZE, 1048576); 
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);

        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($ch, $total_dls, $dl, $total_uls, $ul) use ($progFile, $abortFile, &$lastUpdate, $total) {
            if(file_exists($abortFile)) return 1; // 1 يوقف الـ curl نهائياً
            
            $now = microtime(true);
            if($now - $lastUpdate >= 0.5) { 
                $realTotal = ($total > 0) ? $total : $total_dls; 
                @file_put_contents($progFile, json_encode(['total' => $realTotal, 'loaded' => $dl]));
                $lastUpdate = $now;
            }
        });

        curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        @fclose($wh);

        // إن أوقف المستخدم التحميل ✖
        if(file_exists($abortFile)) {
            @unlink($abortFile);
            @unlink($progFile);
            @unlink($dest);
            jErr('أمرت السيرفر بـ إيقاف وحذف التحميل الخاص بك بنجاح!');
        }

        $size = @filesize($dest);
        @unlink($progFile); 
        
        if($err || $size < 1024) { 
            @unlink($dest); 
            jErr('خطأ: تعذر الاتصال بالرابط المصدر. قد يكون محظوراً أو مدمجاً بالحماية.'); 
        }

        jOk(['filename' => $n, 'url' => VID_UPLOAD_URL.$n, 'size' => $size]);
    }

    if($act === 'check_smart_dl') {
        $n = trim($_POST['filename'] ?? '');
        if(!$n) jErr('مفقود');
        
        $progFile = VID_UPLOAD_DIR . $n . '.prog';
        if(file_exists($progFile)) {
            $d = json_decode(@file_get_contents($progFile), true);
            jOk(['total' => $d['total'] ?? 0, 'loaded' => $d['loaded'] ?? 0]);
        }
        jOk(['status' => 'waiting']); 
    }

    // ══ Upload & Save Handlers ══
    if($act==='upload_video'){
        $ferr = $_FILES['video']['error'] ?? UPLOAD_ERR_NO_FILE;
        if($ferr === UPLOAD_ERR_INI_SIZE || $ferr === UPLOAD_ERR_FORM_SIZE)
            jErr('الملف أكبر من الحد المسموح به في إعدادات الخادم (upload_max_filesize).');
        if($ferr === UPLOAD_ERR_PARTIAL) jErr('تم رفع الملف جزئياً.');
        if($ferr === UPLOAD_ERR_NO_FILE) jErr('لم يتم إرسال أي ملف');
        if($ferr !== UPLOAD_ERR_OK) jErr('خطأ في رفع الملف (كود: '.$ferr.')');
        
        $f=$_FILES['video'];
        $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
        if(!in_array($ext,['mp4','mkv','avi','mov','webm','ts','flv'])) jErr('صيغة غير مدعومة.');
            
        if(!is_dir(VID_UPLOAD_DIR)) @mkdir(VID_UPLOAD_DIR,0755,true);
        $n=uniqid('vid_').'.'.$ext;
        if(!mvFile($f['tmp_name'],VID_UPLOAD_DIR.$n)){ jErr('فشل في نقل الملف إلى الخادم'); }
        jOk(['filename'=>$n,'original'=>$f['name'],'url'=>VID_UPLOAD_URL.$n,'size'=>$f['size']]);
    }

    if($act==='upload_subtitle_file'){
        if(empty($_FILES['subtitle']))jErr('لم يتم إرسال ملف');
        $f=$_FILES['subtitle'];$ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
        if(!in_array($ext,['srt','ass','ssa','vtt']))jErr('صيغة غير مدعومة');
        $n=uniqid('sub_').'.'.$ext;
        if(!mvFile($f['tmp_name'],VID_SUB_DIR.$n))jErr('فشل في رفع ملف الترجمة');
        
        $url = VID_SUB_URL.$n;
        if($ext === 'srt'){
            $vttN = uniqid('sub_').'.vtt';
            $srt = file_get_contents(VID_SUB_DIR.$n);
            $vtt = "WEBVTT\n\n" . preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '\1.\2', $srt);
            file_put_contents(VID_SUB_DIR.$vttN, $vtt);
            $url = VID_SUB_URL.$vttN; 
        }
        jOk(['filename'=>$n,'original'=>$f['name'],'url'=>$url]);
    }

    if($act==='merge_subtitle'){
        $vf=basename($_POST['video_file']??'');
        $sf=basename($_POST['subtitle_file']??'');
        if(!$vf||!$sf)jErr('ملفات ناقصة');
        $vpath=VID_UPLOAD_DIR.$vf;
        $spath=VID_SUB_DIR.$sf;
        if(!file_exists($vpath))jErr('ملف الفيديو غير موجود');
        if(!file_exists($spath))jErr('ملف الترجمة غير موجود');
        $subExt=strtolower(pathinfo($sf,PATHINFO_EXTENSION));
        $subUrl=VID_SUB_URL.$sf;
        if($subExt==='srt'){
            $vttN=uniqid('sub_').'.vtt';
            $srt=file_get_contents($spath);
            $vtt="WEBVTT\n\n".preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/','\1.\2',$srt);
            file_put_contents(VID_SUB_DIR.$vttN,$vtt);
            $subUrl=VID_SUB_URL.$vttN;
        }
        jOk(['filename'=>$vf,'url'=>VID_UPLOAD_URL.$vf,'subtitle_url'=>$subUrl,'size'=>round(filesize($vpath)/1024/1024,2).' MB','method'=>'no_ffmpeg']);
    }

    // الدالتين الجديتان: للحفظ كجديد كلياً، أو إضافته داخل مجلد المسلسلات الموجود مُسبقاً.
    if($act === 'save_to_shashety_auto') {
        global $pdo;
        $cid = intval($_POST['category_id'] ?? 0);
        $name = htmlspecialchars(strip_tags($_POST['name'] ?? ''));
        $url = $_POST['url'] ?? '';
        $sub = $_POST['subtitle_url'] ?? '';
        $target_series = intval($_POST['target_series_id'] ?? 0); 
        
        if(!$url) jErr('لا يوجد رابط للفيديو!');
        if(!$name && $target_series == 0) jErr('أدخل الاسم أولاً للعمل الجديد');
        if(!$name) $name = "فيديو / حلقة جديدة";

        try {
            if ($target_series > 0) {
                // حفظ الفيديو كحلقة جديدة داخل المجلد الحالي في شاشتي
                $stmt = $pdo->query("SELECT MAX(episode_number) FROM episodes WHERE series_id = $target_series");
                $next_num = intval($stmt->fetchColumn()) + 1; 
                $pdo->prepare("INSERT INTO episodes (series_id, episode_number, title, stream_url, subtitle_url, display_order) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$target_series, $next_num, $name, $url, $sub, $next_num]);
                jOk(['series_id' => $target_series]);
            } else {
                // إنشاء مجلد مسلسلات جديد ثم وضع الحلقة فيه!
                if(!$cid) jErr('اختر القسم للمجلد الجديد');
                $slug = slugU($name).'-'.uniqid();
                $pdo->prepare("INSERT INTO series (category_id, name, slug) VALUES (?, ?, ?)")->execute([$cid, $name, $slug]);
                $sid = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO episodes (series_id, episode_number, title, stream_url, subtitle_url, display_order) VALUES (?, 1, ?, ?, ?, 1)")
                    ->execute([$sid, $name, $url, $sub]);
                jOk(['series_id' => $sid]);
            }
        } catch(PDOException $e) { jErr('مشكلة قواعد البيانات: '.$e->getMessage()); }
    }

    if($act==='save_video_manual'){
        global $pdo;
        $fn=basename($_POST['filename']??'');
        $title=htmlspecialchars(strip_tags($_POST['title']??''));
        $cid=intval($_POST['category_id']??0);
        $vt=$_POST['video_type']??'uploaded';
        $sub=htmlspecialchars(strip_tags($_POST['subtitle_url']??'')); 
        $target_series = intval($_POST['target_series_id'] ?? 0); 
        
        if(!$fn)jErr('مفقود: لم تحدد ملف لحفظه');
        if(!$title && $target_series == 0) jErr('مفقود: أسم العمل الجديد');
        if(!$title) $title = "إضافة مسار فيديو";

        if($vt==='merged') { $vdir = VID_MERGED_DIR; $vurlBase = VID_MERGED_URL; }
        elseif($vt==='series') { $vdir = SERIES_DIR; $vurlBase = SERIES_URL; }
        else { $vdir = VID_UPLOAD_DIR; $vurlBase = VID_UPLOAD_URL; } // المتبقي هو uploaded
        
        $vpath = $vdir.$fn;
        $vurl = $vurlBase.$fn;
        if(!file_exists($vpath)) jErr('الملف الأصلي المعني، حُذف أو غير متوفر حاليا.');
        
        try {
            if ($target_series > 0) {
                $stmt = $pdo->query("SELECT MAX(episode_number) FROM episodes WHERE series_id = $target_series");
                $next_num = intval($stmt->fetchColumn()) + 1;
                $pdo->prepare("INSERT INTO episodes (series_id, episode_number, title, stream_url, subtitle_url, display_order) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$target_series, $next_num, $title, $vurl, $sub, $next_num]);
            } else {
                if(!$cid) jErr('يجب وضع قسم رئيسي للملف لعمل التصنيف الخاص بك.');
                $slug=slugU($title).'-'.uniqid();
                $pdo->prepare("INSERT INTO series (category_id, name, slug) VALUES (?, ?, ?)")->execute([$cid, $title, $slug]);
                $sid = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO episodes (series_id, episode_number, title, stream_url, subtitle_url, display_order) VALUES (?, 1, ?, ?, ?, 1)")
                    ->execute([$sid, $title, $vurl, $sub]);
            }
            jOk(['url'=>$vurl]);
        } catch(PDOException $e) { jErr('قاعدة البيانات رفضت: '.$e->getMessage()); }
    }

    // نظام (نقل المجلد وتصحيح قاعدة البيانات المعزّز). تم إزالة تحويل. 
    // فيديوز <==> المسلسلات الفعليّة بقواعد شاشتي
    if($act === 'move_video_file') {
        global $pdo;
        $fn = basename($_POST['filename'] ?? '');
        $type = $_POST['type'] ?? '';
        $target = $_POST['target_folder'] ?? ''; 
        
        if(!$fn || !$type) jErr('خطأ اتصال: الملف غير واضح.');
        
        // استخلاص المسار القديم
        $srcDir = VID_UPLOAD_DIR; $srcUrl = VID_UPLOAD_URL;
        if($type === 'merged') { $srcDir = VID_MERGED_DIR; $srcUrl = VID_MERGED_URL; }
        elseif($type === 'series') { $srcDir = SERIES_DIR; $srcUrl = SERIES_URL; }
        
        $srcPath = $srcDir . $fn;
        if(!file_exists($srcPath)) jErr('الملف الفيزيائي ممسوح حاليا.');
        
        $is_db_series = false;
        $series_id = 0;

        if($target === 'videos') {
            // الرجوع به إلى الرفع العادي
            $destDir = VID_UPLOAD_DIR; $destUrl = VID_UPLOAD_URL;
        } else {
            // إلحاق هذا المجلد برقم مسلسل أو عمل تم اختياره من القائمة 
            $series_id = intval($target);
            if ($series_id <= 0) jErr('رقم المجلد الهدف في قاعدة شاشتي غير دقيق.');
            $destDir = SERIES_DIR; $destUrl = SERIES_URL;
            $is_db_series = true;
        }

        $destPath = $destDir . $fn;
        
        if($srcPath !== $destPath) {
            if(!rename($srcPath, $destPath)) jErr('الصلاحية ضعيفة بالخادم لنقل الملف جسدياً من '.$type);
        }
        
        $oldDbUrl = $srcUrl . $fn;
        $newDbUrl = $destUrl . $fn;
        
        // تحديث كل الحلقات القديمة لعنوان النطاق والامتداد الجديد إن كان فيه مسلسل
        if($oldDbUrl !== $newDbUrl) {
            $pdo->prepare("UPDATE episodes SET stream_url = ? WHERE stream_url = ?")->execute([$newDbUrl, $oldDbUrl]);
        }
        
        // هل أمره المستخدم بالارتباط بحلقة لمسلسل شاشتي معين للتو ؟
        if($is_db_series && $series_id > 0) {
            $stmt = $pdo->prepare("SELECT id FROM episodes WHERE stream_url = ?");
            $stmt->execute([$newDbUrl]);
            if($stmt->fetch()) {
                // مرتبط ولكن بقسم خاطئ.. يتم تغيير معرفه إلى مسلسلك الهدف!
                $pdo->prepare("UPDATE episodes SET series_id = ? WHERE stream_url = ?")->execute([$series_id, $newDbUrl]);
            } else {
                // ادخاله لاول مره بعد استخراجه 
                $titleName = str_replace(['_', '-'], ' ', pathinfo($fn, PATHINFO_FILENAME));
                $nstmt = $pdo->query("SELECT MAX(episode_number) FROM episodes WHERE series_id = $series_id");
                $next_num = intval($nstmt->fetchColumn()) + 1;
                $pdo->prepare("INSERT INTO episodes (series_id, episode_number, title, stream_url, subtitle_url, display_order) VALUES (?, ?, ?, ?, '', ?)")
                    ->execute([$series_id, $next_num, $titleName, $newDbUrl, $next_num]);
            }
        } elseif ($target === 'videos') {
            // سحب المسار من النظام وتركه لعمله الخاص (عزله بالعام)، نقوم بحذفه كلياً من لوحة شاشتي (Episodes table)
            $pdo->prepare("DELETE FROM episodes WHERE stream_url = ?")->execute([$newDbUrl]);
        }
        
        jOk(['new_url' => $newDbUrl, 'message' => 'عظيم! تم النقل ومزامنة وتغيير وجهات الخادم بشكل قطعي.']);
    }

    if($act==='list_videos'){
        $vids=[];
        foreach([
            'uploaded'=>[VID_UPLOAD_DIR,VID_UPLOAD_URL],
            'merged'=>[VID_MERGED_DIR,VID_MERGED_URL],
            'series'=>[SERIES_DIR,SERIES_URL] 
        ] as $t=>[$d,$u]){
            if(!is_dir($d))continue;
            foreach(glob($d.'*.{mp4,mkv,avi,mov,webm}',GLOB_BRACE)as $f){
                $fn=basename($f);
                $vids[]=['filename'=>$fn,'url'=>$u.$fn,'size_mb'=>round(filesize($f)/1024/1024,2),'type'=>$t,'date'=>date('Y-m-d H:i',filemtime($f)),'ts'=>filemtime($f)];
            }
        }
        usort($vids,fn($a,$b)=>$b['ts']-$a['ts']);
        jOk(['videos'=>$vids]);
    }

    if($act==='delete_video'){
        $fn=basename($_POST['filename']??'');
        $t=$_POST['type']??'uploaded';
        if(!$fn)jErr('الاسم للهدف مسح مفقود');
        
        $p = VID_UPLOAD_DIR;
        if($t==='merged') $p = VID_MERGED_DIR;
        elseif($t==='series') $p = SERIES_DIR;
        
        $p .= $fn;
        if(!file_exists($p))jErr('لا أعثر عليه حاليا!');
        jOk(['deleted'=>@unlink($p)]);
    }

    // ══ Series Handlers ══
    if($act==='get_series'){
        global $pdo;
        $cid    = intval($_POST['category_id'] ?? 0);
        $q      = trim((string)($_POST['q'] ?? ''));
        $limit  = intval($_POST['limit']  ?? 60);
        $offset = intval($_POST['offset'] ?? 0);
        if($limit  < 1 || $limit > 200) $limit = 60;
        if($offset < 0) $offset = 0;

        /* ── لماذا تغيّر هذا الاستعلام ──
           كان: SELECT s.* + LEFT JOIN episodes + GROUP BY، بلا LIMIT.
           بعد استيراد أفلام Xtream صار يعيد آلاف الصفوف، ويجرّ معها حقل description
           (آلاف الأحرف لكل فيلم) وهو غير مستخدم في الشبكة أصلاً — فبطُؤت الصفحة.
           الآن: أعمدة محدودة + عدّ الحلقات بجملة فرعية + ترقيم صفحات. */
        $w = []; $p = [];
        if($cid){ $w[] = "s.category_id = ?"; $p[] = $cid; }
        if($q !== ''){ $w[] = "s.name LIKE ?"; $p[] = '%'.$q.'%'; }
        $where = $w ? ('WHERE '.implode(' AND ', $w)) : '';

        // الإجمالي (للترقيم) — عدّ خفيف بلا joins
        $cst = $pdo->prepare("SELECT COUNT(*) FROM series s $where");
        $cst->execute($p);
        $total = (int)$cst->fetchColumn();

        $sql = "SELECT s.id, s.category_id, s.name, s.poster_url, s.display_order,
                       c.name AS cat_name,
                       (SELECT COUNT(*) FROM episodes e WHERE e.series_id = s.id) AS ep_count
                FROM series s
                LEFT JOIN categories c ON s.category_id = c.id
                $where
                ORDER BY s.display_order, s.id DESC
                LIMIT $limit OFFSET $offset";
        $st = $pdo->prepare($sql);
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        jOk([
            'data'    => $rows,
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
            'hasMore' => ($offset + count($rows)) < $total,
        ]);
    }
    /* تفاصيل عمل واحد (بما فيها الوصف) — تُطلب فقط عند التعديل */
    if($act==='get_series_one'){
        global $pdo;
        $id = intval($_POST['id'] ?? 0);
        if($id <= 0) jErr('معرف غير صالح');
        $st = $pdo->prepare("SELECT * FROM series WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if(!$row) jErr('غير موجود');
        jOk(['data'=>$row]);
    }
    if($act==='add_series'){
        global $pdo;
        $cid=intval($_POST['category_id']??0);
        $name=htmlspecialchars(strip_tags($_POST['name']??''));
        $desc=htmlspecialchars(strip_tags($_POST['description']??''));
        $poster=htmlspecialchars(strip_tags($_POST['poster_url']??''));
        if(!$cid||!$name)jErr('ناقص');
        $slug=slugU($name).'-'.uniqid();
        try{
            $pdo->prepare("INSERT INTO series (category_id,name,slug,description,poster_url) VALUES (?,?,?,?,?)")->execute([$cid,$name,$slug,$desc,$poster]);
            jOk(['id'=>$pdo->lastInsertId()]);
        }catch(PDOException $e){jErr('تنبيه: '.$e->getMessage());}
    }
    if($act==='edit_series'){
        global $pdo;
        $id=intval($_POST['id']??0);
        $cid=intval($_POST['category_id']??0);
        $name=htmlspecialchars(strip_tags($_POST['name']??''));
        $desc=htmlspecialchars(strip_tags($_POST['description']??''));
        $poster=htmlspecialchars(strip_tags($_POST['poster_url']??''));
        $pdo->prepare("UPDATE series SET category_id=?,name=?,description=?,poster_url=? WHERE id=?")->execute([$cid,$name,$desc,$poster,$id]);
        jOk();
    }
    if($act==='delete_series'){
        global $pdo;
        $id=intval($_POST['id']??0);
        $eps=$pdo->query("SELECT stream_url, subtitle_url FROM episodes WHERE series_id=$id")->fetchAll(PDO::FETCH_ASSOC);
        foreach($eps as $ep){
            shashetyDeleteLocalFile($ep['stream_url']??'');
            shashetyDeleteLocalFile($ep['subtitle_url']??'');
        }
        // حذف بوستر المسلسل/الفيلم إن كان ملفاً محلياً
        $sr=$pdo->query("SELECT poster_url FROM series WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
        if($sr) shashetyDeleteLocalFile($sr['poster_url']??'');
        $pdo->prepare("DELETE FROM episodes WHERE series_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM series WHERE id=?")->execute([$id]);
        jOk();
    }
    if($act==='get_episodes'){
        global $pdo;
        $sid=intval($_POST['series_id']??0);
        $rows=$pdo->query("SELECT * FROM episodes WHERE series_id=$sid ORDER BY episode_number,display_order,id")->fetchAll(PDO::FETCH_ASSOC);
        jOk(['data'=>$rows]);
    }
    if($act==='add_episode'){
        global $pdo;
        $sid=intval($_POST['series_id']??0);
        $num=intval($_POST['episode_number']??1);
        $title=htmlspecialchars(strip_tags($_POST['title']??''));
        $url=htmlspecialchars(strip_tags($_POST['stream_url']??''));
        $sub=htmlspecialchars(strip_tags($_POST['subtitle_url']??''));
        $dur=htmlspecialchars(strip_tags($_POST['duration']??''));
        if(!$sid||!$title||!$url)jErr('خاطيء في القيم المرسلة');
        $pdo->prepare("INSERT INTO episodes (series_id,episode_number,title,stream_url,subtitle_url,duration,display_order) VALUES (?,?,?,?,?,?,?)")
            ->execute([$sid,$num,$title,$url,$sub,$dur,$num]);
        jOk(['id'=>$pdo->lastInsertId()]);
    }
    if($act==='edit_episode'){
        global $pdo;
        $id=intval($_POST['id']??0);
        $num=intval($_POST['episode_number']??1);
        $title=htmlspecialchars(strip_tags($_POST['title']??''));
        $url=htmlspecialchars(strip_tags($_POST['stream_url']??''));
        $sub=htmlspecialchars(strip_tags($_POST['subtitle_url']??''));
        $dur=htmlspecialchars(strip_tags($_POST['duration']??''));
        $new_series_id = intval($_POST['series_id']??0);
        
        if($new_series_id > 0) {
            $pdo->prepare("UPDATE episodes SET series_id=?, episode_number=?,title=?,stream_url=?,subtitle_url=?,duration=? WHERE id=?")
                ->execute([$new_series_id, $num, $title, $url, $sub, $dur, $id]);
        } else {
            $pdo->prepare("UPDATE episodes SET episode_number=?,title=?,stream_url=?,subtitle_url=?,duration=? WHERE id=?")
                ->execute([$num, $title, $url, $sub, $dur, $id]);
        }
        jOk();
    }
    if($act === 'delete_episodes_bulk') {
        global $pdo;
        $ids = json_decode($_POST['ids'] ?? '[]');
        if (is_array($ids) && count($ids) > 0) {
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            // حذف الملفات المحلية (فيديو + ترجمة) قبل حذف السجلات
            $stmt = $pdo->prepare("SELECT stream_url, subtitle_url FROM episodes WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $eps = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($eps as $ep) {
                shashetyDeleteLocalFile($ep['stream_url']??'');
                shashetyDeleteLocalFile($ep['subtitle_url']??'');
            }
            // سحق الجذور من الداتا بيز
            $del = $pdo->prepare("DELETE FROM episodes WHERE id IN ($placeholders)");
            $del->execute($ids);
            jOk();
        }
        jErr('تخلف القراءة.');
    }
    
    if($act === 'update_episodes_order'){
        global $pdo;
        $orders = json_decode($_POST['orders'] ?? '[]', true);
        if (is_array($orders)) {
            // توجيه صارم لكل فيديو لتغيير ترتيبه الشامل المخصص لواجهة index.php 
            $stmt = $pdo->prepare("UPDATE episodes SET display_order = ? WHERE id = ?");
            foreach ($orders as $item) {
                $stmt->execute([ intval($item['order']), intval($item['id']) ]);
            }
            jOk();
        }
        jErr('نظام الداتا اعترض المصفوفة');
    }

    if($act==='delete_episode'){
        global $pdo;
        $id=intval($_POST['id']??0);
        $ep=$pdo->query("SELECT stream_url, subtitle_url FROM episodes WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
        if($ep){
            shashetyDeleteLocalFile($ep['stream_url']??'');
            shashetyDeleteLocalFile($ep['subtitle_url']??'');
        }
        $pdo->prepare("DELETE FROM episodes WHERE id=?")->execute([$id]);
        jOk();
    }

    if($act==='upload_episode_video'){
        if(empty($_FILES['episode']))jErr('لا توجد صوره');
        $f=$_FILES['episode']; $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
        if(!in_array($ext,['mp4','mkv','avi','mov','webm','ts','flv']))jErr('صيغتك للاسف لا نعمل بها');
        $sid=intval($_POST['series_id']??0);
        $n='ep_'.$sid.'_'.uniqid().'.'.$ext;
        if(!mvFile($f['tmp_name'],SERIES_DIR.$n))jErr('الخطا تم نقله للاستضافه بنسبة للاحصاء.');
        $url=SERIES_URL.$n;
        $epNum=1; if(preg_match('/[Ee]p?(\d+)|[_\s\-](\d+)\./i',$f['name'],$m)) $epNum=intval($m[1]??$m[2]??1);
        jOk(['filename'=>$n,'original'=>$f['name'],'url'=>$url,'size'=>$f['size'],'episode_number'=>$epNum]);
    }
   if($act==='upload_channel_logo'){
    if(empty($_FILES['logo']))jErr('لا توجد صورة'); 
    $f=$_FILES['logo']; 
    $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
    if(!in_array($ext,['jpg','jpeg','png','webp','gif']))jErr('صيغة غير صالحة.');
    
    $n=uniqid('logo_').'.'.$ext;
    if(!mvFile($f['tmp_name'],POSTERS_DIR.$n))jErr('فشل في الرفع، حاول ثانية');
    
    jOk(['filename'=>$n,'url'=>POSTERS_URL.$n,'original'=>$f['name'],'size'=>$f['size']]);
}

    if($act==='os_login'){
        $u = trim($_POST['username'] ?? '');
        $p = trim($_POST['password'] ?? '');
        $k = trim($_POST['api_key']  ?? '');
        if(!$u || !$p) jErr('أدخل اسم المستخدم وكلمة المرور');
        if(!$k)        jErr('أدخل مفتاح API');

        $_SESSION['os_api_key'] = $k;
        unset($_SESSION['os_base'], $_SESSION['os_token']);

        [$c,$r,$e] = osReq(OS_API.'/login','POST',['username'=>$u,'password'=>$p],false);
        if($e){ unset($_SESSION['os_api_key']); jErr('خطأ شبكة: '.$e); }

        $d = json_decode($r,true);

        if($c===200 && !empty($d['token'])){
            $_SESSION['os_token']    = $d['token'];
            $_SESSION['os_username'] = $u;
            $_SESSION['os_api_key']  = $k;
            if(!empty($d['base_url'])){
                $b = preg_replace('#^https?://#','',rtrim($d['base_url'],'/'));
                $_SESSION['os_base'] = 'https://'.$b.'/api/v1';
            }
            try {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('os_api_key', ?), ('os_username', ?), ('os_password', ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k,$u,$p]);
            } catch(Exception $ex){}
            jOk(['username'=>$u,'allowed'=>$d['allowed_downloads'] ?? '?']);
        }

        unset($_SESSION['os_api_key'], $_SESSION['os_base']);
        if($c===401) jErr('اسم المستخدم أو كلمة المرور غير صحيحة');
        if($c===403) jErr('مفتاح API غير صالح أو غير مفعّل (403)');
        if($c===429) jErr('تجاوزت عدد المحاولات — انتظر قليلاً');
        jErr($d['message'] ?? $d['error'] ?? "فشل الاتصال بالسيرفر ($c)");
    }

    if($act==='os_logout'){
        if(!empty($_SESSION['os_token'])) osReq(osBase().'/logout','DELETE');
        unset($_SESSION['os_token'],$_SESSION['os_username'],$_SESSION['os_api_key'],$_SESSION['os_base']);
        jOk();
    }

    if($act==='os_status'){
        jOk([
            'logged_in' => !empty($_SESSION['os_token']) && !empty($_SESSION['os_api_key']),
            'username'  => $_SESSION['os_username'] ?? ''
        ]);
    }

    if($act==='search_subtitles'){
        osGuard();
        $q    = trim($_POST['query'] ?? '');
        $lang = trim($_POST['language'] ?? 'ar');
        if(!$q) jErr('أدخل اسم الفيلم أو المسلسل');

        $params = http_build_query([
            'query'           => $q,
            'languages'       => $lang,
            'order_by'        => 'download_count',
            'order_direction' => 'desc',
            'per_page'        => 20
        ]);

        [$c,$r,$e] = osReq(osBase().'/subtitles?'.$params);
        if($e) jErr('انقطع الاتصال: '.$e);

        // انتهت صلاحية التوكن لدى السيرفر: أعد تسجيل الدخول وحاول مرة واحدة
        if($c===401){
            unset($_SESSION['os_token']);
            if(osAutoLogin()){
                [$c,$r,$e] = osReq(osBase().'/subtitles?'.$params);
                if($e) jErr('انقطع الاتصال: '.$e);
            }
            if($c===401) jErr('تعذّر تجديد الجلسة — تحقق من بيانات الدخول في الإعدادات');
        }
        if($c===403) jErr('مفتاح API مرفوض (403) — تحقق من المفتاح واسم التطبيق في حساب Consumer');
        if($c===429) jErr('تجاوزت الحد المسموح من الطلبات — انتظر دقيقة');
        if($c!==200) jErr("خطأ في الاستعلام ($c)");

        $d = json_decode($r,true);
        if(empty($d['data'])) jErr('لا توجد نتائج لهذا الاسم');

        $subs = [];
        foreach($d['data'] as $s){
            $a = $s['attributes'] ?? [];
            $files = $a['files'] ?? [];
            if(empty($files)) continue;
            $subs[] = [
                'id'        => $s['id'],
                'title'     => $a['feature_details']['title'] ?? $a['release'] ?? '—',
                'year'      => $a['feature_details']['year'] ?? '',
                'language'  => $a['language'] ?? '',
                'downloads' => $a['download_count'] ?? 0,
                'release'   => $a['release'] ?? '',
                'file_id'   => $files[0]['file_id'] ?? null,
                'filename'  => $files[0]['file_name'] ?? 'subtitle.srt'
            ];
        }
        if(empty($subs)) jErr('النتائج لا تحتوي ملفات قابلة للتنزيل');
        jOk(['data'=>$subs,'total'=>$d['total_count'] ?? count($subs)]);
    }

    if($act==='download_subtitle'){
        osGuard();
        $fid = intval($_POST['file_id'] ?? 0);
        if(!$fid) jErr('معرّف الملف غير صالح');

        [$c,$r,$e] = osReq(osBase().'/download','POST',['file_id'=>$fid,'sub_format'=>'srt']);
        if($e) jErr('انقطع الاتصال: '.$e);

        // انتهت صلاحية التوكن لدى السيرفر: أعد تسجيل الدخول وحاول مرة واحدة
        if($c===401){
            unset($_SESSION['os_token']);
            if(osAutoLogin()){
                [$c,$r,$e] = osReq(osBase().'/download','POST',['file_id'=>$fid,'sub_format'=>'srt']);
                if($e) jErr('انقطع الاتصال: '.$e);
            }
            if($c===401) jErr('تعذّر تجديد الجلسة — تحقق من بيانات الدخول في الإعدادات');
        }

        $d = json_decode($r,true);
        if($c===406) jErr('استنفدت رصيد التنزيل اليومي');
        if($c!==200 || empty($d['link']))
            jErr($d['message'] ?? $d['errors'][0]['message'] ?? "تعذّر التنزيل ($c)");

        $ch2 = curl_init($d['link']);
        curl_setopt_array($ch2,[
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => OS_UA
        ]);
        $srt   = curl_exec($ch2);
        $dlErr = curl_error($ch2);
        curl_close($ch2);

        if(!$srt || strlen(trim($srt)) < 5) jErr('الملف تالف أو فارغ. '.$dlErr);

        if(!mb_check_encoding($srt,'UTF-8')){
            $srt = mb_convert_encoding($srt,'UTF-8','Windows-1256, ISO-8859-6, Windows-1252');
        }
        $srt = preg_replace('/^\xEF\xBB\xBF/','',$srt);

        $srtN = uniqid('sub_').'.srt';
        file_put_contents(VID_SUB_DIR.$srtN,$srt);

        $vttN = uniqid('sub_').'.vtt';
        $vtt  = "WEBVTT\n\n".preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/','\1.\2',$srt);
        file_put_contents(VID_SUB_DIR.$vttN,$vtt);

        jOk([
            'filename'     => $srtN,
            'vtt_filename' => $vttN,
            'remaining'    => $d['remaining_downloads'] ?? '?',
            'url'          => VID_SUB_URL.$srtN,
            'vtt_url'      => VID_SUB_URL.$vttN
        ]);
    }

    // ══ معالجات إدارة المستخدمين ══
    if($act==='clear_login_logs'){
        global $pdo;
        if(!in_array($_SESSION['admin_role'] ?? 'normal', ['administrator','super'])) jErr('ليس لديك صلاحية');
        try { $pdo->exec("TRUNCATE TABLE login_logs"); } catch(Exception $e) { jErr('فشل الحذف'); }
        jOk();
    }
    if($act==='get_login_logs'){
        global $pdo;
        $myRole = $_SESSION['admin_role'] ?? 'normal';
        if(!in_array($myRole, ['administrator','super'])) jErr('ليس لديك صلاحية');
        $rows = $pdo->query("SELECT id, ip_address, username, status, attempt_time FROM login_logs ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        jOk(['logs' => $rows]);
    }
    if($act==='get_admin_users'){
        global $pdo;
        $myRole = $_SESSION['admin_role'] ?? 'normal';
        if(!in_array($myRole, ['administrator','super'])) jErr('ليس لديك صلاحية لعرض المستخدمين');
        $rows = $pdo->query("SELECT id, username, display_name, role, allowed_sections, is_active, created_at, last_login FROM admin_users ORDER BY FIELD(role,'administrator','super','normal','custom'), id")->fetchAll(PDO::FETCH_ASSOC);
        // Super لا يرى تفاصيل Administrator
        if($myRole === 'super') {
            $rows = array_values(array_filter($rows, function($r){ return $r['role'] !== 'administrator'; }));
        }
        jOk(['data' => $rows]);
    }

    if($act==='add_admin_user'){
        global $pdo;
        $myRole = $_SESSION['admin_role'] ?? '';
        if(!in_array($myRole, ['administrator','super'])) jErr('ليس لديك صلاحية');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $display_name = trim($_POST['display_name'] ?? '');
        $role = $_POST['role'] ?? 'normal';
        $sections = $_POST['allowed_sections'] ?? '[]';
        if(!$username || !$password) jErr('اسم المستخدم وكلمة المرور مطلوبان');
        if(strlen($password) < 4) jErr('كلمة المرور يجب أن تكون 4 أحرف على الأقل');
        // Super لا يستطيع إنشاء Administrator
        if($myRole === 'super' && $role === 'administrator') jErr('لا يمكنك إنشاء مستخدم بصلاحية مدير عام');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        if($stmt->fetchColumn() > 0) jErr('اسم المستخدم مُستخدم مسبقاً');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO admin_users (username, password_hash, display_name, role, allowed_sections) VALUES (?, ?, ?, ?, ?)")
            ->execute([$username, $hash, $display_name ?: $username, $role, $sections]);
        $newId = $pdo->lastInsertId();
        // إدراج في جدول users أيضاً للتوافق مع login.php
        try { $pdo->prepare("INSERT IGNORE INTO users (username, password) VALUES (?, ?)")->execute([$username, $hash]); } catch(PDOException $e) {}
        jOk(['id' => $newId]);
    }

    if($act==='edit_admin_user'){
        global $pdo;
        $myRole = $_SESSION['admin_role'] ?? '';
        $myId = $_SESSION['admin_user_id'] ?? 0;
        if(!in_array($myRole, ['administrator','super'])) jErr('ليس لديك صلاحية');
        $id = intval($_POST['id'] ?? 0);
        $display_name = trim($_POST['display_name'] ?? '');
        $role = $_POST['role'] ?? 'normal';
        $sections = $_POST['allowed_sections'] ?? '[]';
        $is_active = intval($_POST['is_active'] ?? 1);
        $new_pass = $_POST['new_password'] ?? '';
        if(!$id) jErr('معرّف المستخدم مفقود');
        // جلب بيانات المستخدم المستهدف
        $target = $pdo->prepare("SELECT role FROM admin_users WHERE id = ?");
        $target->execute([$id]);
        $targetRow = $target->fetch(PDO::FETCH_ASSOC);
        if(!$targetRow) jErr('المستخدم غير موجود');
        // Super لا يعدّل على Administrator
        if($myRole === 'super' && $targetRow['role'] === 'administrator') jErr('لا يمكنك التعديل على مدير عام');
        if($myRole === 'super' && $role === 'administrator') jErr('لا يمكنك ترقية مستخدم لمدير عام');
        // لا يمكن تعطيل نفسك
        if($id == $myId && $is_active == 0) jErr('لا يمكنك تعطيل حسابك');
        $pdo->prepare("UPDATE admin_users SET display_name=?, role=?, allowed_sections=?, is_active=? WHERE id=?")
            ->execute([$display_name, $role, $sections, $is_active, $id]);
        // تحديث كلمة المرور إن وُجدت
        if($new_pass && strlen($new_pass) >= 4){
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE admin_users SET password_hash=? WHERE id=?")->execute([$hash, $id]);
            // تحديث في users أيضاً
            $uname = $pdo->prepare("SELECT username FROM admin_users WHERE id=?");
            $uname->execute([$id]);
            $un = $uname->fetchColumn();
            if($un) try { $pdo->prepare("UPDATE users SET password=? WHERE username=?")->execute([$hash, $un]); } catch(PDOException $e) {}
        }
        jOk();
    }

    if($act==='delete_admin_user'){
        global $pdo;
        $myRole = $_SESSION['admin_role'] ?? '';
        $myId = $_SESSION['admin_user_id'] ?? 0;
        if($myRole !== 'administrator') jErr('فقط المدير العام يمكنه الحذف');
        $id = intval($_POST['id'] ?? 0);
        if(!$id) jErr('معرّف مفقود');
        if($id == $myId) jErr('لا يمكنك حذف نفسك');
        $pdo->prepare("DELETE FROM admin_users WHERE id=?")->execute([$id]);
        jOk();
    }

    // --- الكود الجديد الذي تم إضافته لرفع بوستر شاشتي ---
    if($act === 'upload_series_poster'){
        if(empty($_FILES['poster'])) jErr('لا توجد صورة'); 
        $f = $_FILES['poster']; 
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        
        if(!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
            jErr('صيغة غير صالحة. المدعوم: jpg, png, webp, gif');
        }
        
        $n = uniqid('poster_') . '.' . $ext;
        if(!mvFile($f['tmp_name'], POSTERS_DIR . $n)) {
            jErr('فشل في الرفع، يرجى التحقق من تصاريح المجلد');
        }
        
        jOk([
            'filename' => $n,
            'url' => POSTERS_URL . $n,
            'original' => $f['name'],
            'size' => $f['size']
        ]);
    }
    // ------------------------------------------------

// --- إضافة جديدة: التحويل الذكي MP4 بواسطة بايثون ---
// --- التحويل الذكي MP4 (النسخة المتكيفة كلياً مع Ubuntu/Linux و Windows) ---
    if($act === 'convert_to_mp4_bulk') {
        global $pdo;
        $ids = json_decode($_POST['ids'] ?? '[]');
        if (is_array($ids) && count($ids) > 0) {
            $success = 0;
            $failed = 0;
            $debug_msg = "";

            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $stmt = $pdo->prepare("SELECT id, stream_url FROM episodes WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $eps = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($eps as $ep) {
                $old_url = $ep['stream_url'];
                $filename = urldecode(basename(parse_url($old_url, PHP_URL_PATH)));
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                // 1. تجاوز إذا كان MP4
                if ($ext === 'mp4') {
                    $failed++;
                    $debug_msg .= "[الملف: $filename] بصيغة MP4 جاهزة فعلاً.\n\n";
                    continue;
                }

                // 2. كشافة البحث في المسارات
                $found_path = false;
                foreach ([SERIES_DIR, VID_UPLOAD_DIR, VID_MERGED_DIR] as $dir) {
                    $test_path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $filename;
                    if(file_exists($test_path)) {
                        $found_path = $test_path;
                        break;
                    }
                }

                if ($found_path !== false) {
                    $new_name = uniqid('cv_') . '_' . time() . '.mp4';
                    $new_path = dirname($found_path) . DIRECTORY_SEPARATOR . $new_name;
                    $script_path = __DIR__ . DIRECTORY_SEPARATOR . 'convert_mp4.py';
                    
                    // تحديد أوامر لينكس ضد أوامر ويندوز بذكاء شديد 
                    $is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
                    
                    // في سيرفرات الأوبونتو/أباتشي، نستخدم المُنفّذ الشامل env python3 لضمان استدعائه دوماً
                    $python_cmd = $is_windows ? 'python' : '/usr/bin/env python3';
                    
                    // دمج الأمر مع 2>&1 لطباعة جميع أنواع الاستجابة التقنية إن رفض أباتشي ذلك
                    $cmd = $python_cmd . ' ' . escapeshellarg($script_path) . ' ' . escapeshellarg($found_path) . ' ' . escapeshellarg($new_path) . ' 2>&1';
                    
                    exec($cmd, $out_arr, $status_code);
                    $output = trim(implode("\n", $out_arr));

                    // التأكد الحازم من سلامة التحويل في السيرفر الجسدي
                    if (strpos($output, 'SUCCESS') !== false || (file_exists($new_path) && filesize($new_path) > 1024)) {
                        // إرساء الرابط للوحة المتصفح لتغيره من Ts إلى mp4 
                        $new_url = str_replace(urlencode($filename), $new_name, str_replace($filename, $new_name, $old_url));
                        $pdo->prepare("UPDATE episodes SET stream_url = ? WHERE id = ?")->execute([$new_url, $ep['id']]);
                        
                        @unlink($found_path); // نسف ملف Ts من الهاردديسك لتفريغ السعة
                        $success++;
                    } else {
                        $failed++;
                        $debug_msg .= "خطأ تقني اثناء التعامل مع ($filename):\n[Output]: " . ($output ?: 'لاشيء') . "\n\n";
                    }
                } else {
                    $failed++;
                    $debug_msg .= "الملف ($filename) محذوف أو لم نجده داخل مسار الخادم الفعلي!\n\n";
                }
            }
            jOk(['success_count' => $success, 'failed_count' => $failed, 'debug' => trim($debug_msg)]);
        }
        jErr('بيانات مفقودة أو غير متوافقة.');
    }
    // ----------------------------------------------------

    // ══ استيراد قوائم M3U ══
    if($act === 'import_m3u'){
        $content = '';
        $source_type = 'url';
        $source_name = '';
        $source_url = '';

        if(!empty($_FILES['m3u_file']) && $_FILES['m3u_file']['error'] === UPLOAD_ERR_OK){
            $f = $_FILES['m3u_file'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if(!in_array($ext, ['m3u','m3u8'])) jErr('صيغة الملف غير مدعومة. المسموح: m3u, m3u8');
            $content = file_get_contents($f['tmp_name']);
            $source_type = 'file';
            $source_name = $f['name'];
        } else {
            $url = trim($_POST['m3u_url'] ?? '');
            if($url === '') jErr('أدخل رابط M3U أو اختر ملفاً للرفع');
            if(!preg_match('#^https?://#i', $url)) jErr('رابط غير صالح، يجب أن يبدأ بـ http:// أو https://');
            $content = m3uFetchUrl($url, $err);
            if($content === false || trim((string)$content) === '') jErr('تعذر تحميل الرابط: '.($err ?: 'استجابة فارغة من الخادم'));
            $source_type = 'url';
            $source_name = parse_url($url, PHP_URL_HOST) ?: $url;
            $source_url = $url;
        }

        if(trim((string)$content) === '') jErr('الملف/الرابط فارغ');
        $items = parseM3UPlaylist($content);
        if(!count($items)) jErr('لم يتم العثور على أي قنوات صالحة داخل القائمة — تأكد أن الملف بصيغة M3U صحيحة');

        try {
            $pdo->beginTransaction();
            $playlistName = $source_name !== '' ? $source_name : ('قائمة '.date('Y-m-d H:i'));
            $pdo->prepare("INSERT INTO m3u_playlists (name, source_type, source_url, channels_count) VALUES (?,?,?,0)")
                ->execute([$playlistName, $source_type, $source_url]);
            $playlistId = $pdo->lastInsertId();

            $inserted = m3uInsertChannels($pdo, $items, $playlistId);

            $pdo->prepare("UPDATE m3u_playlists SET channels_count = ? WHERE id = ?")->execute([$inserted, $playlistId]);
            $pdo->commit();
            jOk(['playlist_id'=>$playlistId, 'count'=>$inserted, 'total_found'=>count($items)]);
        } catch(Exception $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            jErr('خطأ أثناء الاستيراد: '.$e->getMessage());
        }
    }

    if($act === 'list_m3u_playlists'){
        $rows = $pdo->query("SELECT * FROM m3u_playlists ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        jOk(['data'=>$rows]);
    }

    if($act === 'refresh_m3u_playlist'){
        $id = (int)($_POST['id'] ?? 0);
        if(!$id) jErr('معرّف القائمة مفقود');
        $stmt = $pdo->prepare("SELECT * FROM m3u_playlists WHERE id = ?");
        $stmt->execute([$id]);
        $pl = $stmt->fetch(PDO::FETCH_ASSOC);
        if(!$pl) jErr('القائمة غير موجودة');
        if($pl['source_type'] !== 'url' || empty($pl['source_url'])) jErr('التحديث التلقائي متاح فقط للقوائم المستوردة عبر رابط مباشر — احذف هذه القائمة وأعد رفع الملف لتحديثها');

        $content = m3uFetchUrl($pl['source_url'], $err);
        if($content === false || trim((string)$content) === '') jErr('تعذر تحميل الرابط: '.($err ?: 'استجابة فارغة من الخادم'));

        $items = parseM3UPlaylist($content);
        if(!count($items)) jErr('لم يتم العثور على أي قنوات صالحة في الرابط المحدّث');

        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM channels WHERE playlist_id = ?")->execute([$id]);
            $inserted = m3uInsertChannels($pdo, $items, $id);
            $pdo->prepare("UPDATE m3u_playlists SET channels_count = ?, updated_at = NOW() WHERE id = ?")->execute([$inserted, $id]);
            $pdo->commit();
            jOk(['count'=>$inserted]);
        } catch(Exception $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            jErr('خطأ أثناء التحديث: '.$e->getMessage());
        }
    }

    if($act === 'delete_m3u_playlist'){
        $id = (int)($_POST['id'] ?? 0);
        if(!$id) jErr('معرّف القائمة مفقود');
        try {
            $pdo->prepare("DELETE FROM channels WHERE playlist_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM m3u_playlists WHERE id = ?")->execute([$id]);
            jOk();
        } catch(Exception $e){
            jErr('خطأ أثناء الحذف: '.$e->getMessage());
        }
    }

    // ══ تعديل رابط قائمة M3U + إعادة الاستيراد من الرابط الجديد ══
    if($act === 'edit_m3u_playlist'){
        $id  = (int)($_POST['id'] ?? 0);
        $url = trim($_POST['source_url'] ?? '');
        if(!$id) jErr('معرّف القائمة مفقود');
        if($url === '') jErr('أدخل رابط M3U الجديد');
        if(!preg_match('#^https?://#i', $url)) jErr('رابط غير صالح، يجب أن يبدأ بـ http:// أو https://');

        $stmt = $pdo->prepare("SELECT * FROM m3u_playlists WHERE id = ?");
        $stmt->execute([$id]);
        $pl = $stmt->fetch(PDO::FETCH_ASSOC);
        if(!$pl) jErr('القائمة غير موجودة');

        $content = m3uFetchUrl($url, $err);
        if($content === false || trim((string)$content) === '') jErr('تعذر تحميل الرابط الجديد: '.($err ?: 'استجابة فارغة من الخادم'));
        $items = parseM3UPlaylist($content);
        if(!count($items)) jErr('لم يتم العثور على أي قنوات صالحة في الرابط الجديد');

        try {
            $pdo->beginTransaction();
            // حذف كل المحتويات (القنوات) القديمة المرتبطة بهذه القائمة بالكامل
            $pdo->prepare("DELETE FROM channels WHERE playlist_id = ?")->execute([$id]);
            $inserted = m3uInsertChannels($pdo, $items, $id);
            $newName = parse_url($url, PHP_URL_HOST) ?: $url;
            $pdo->prepare("UPDATE m3u_playlists SET name = ?, source_type = 'url', source_url = ?, channels_count = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$newName, $url, $inserted, $id]);
            $pdo->commit();
            jOk(['count'=>$inserted]);
        } catch(Exception $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            jErr('خطأ أثناء تعديل الرابط: '.$e->getMessage());
        }
    }

    // ══ حذف جماعي للأقسام ══
    if($act === 'bulk_delete_categories'){
        $ids = $_POST['ids'] ?? '';
        $arr = array_filter(array_map('intval', explode(',', $ids)), function($v){ return $v > 0; });
        if(!count($arr)) jErr('لم يتم تحديد أي قسم');
        try {
            /* على دفعات: MySQL يرفض أكثر من 65535 placeholder في جملة واحدة (الخطأ 1390).
               المعرّفات مرّت بـ intval فهي أرقام آمنة — ندمجها مباشرة على دفعات. */
            $chunks = array_chunk(array_values($arr), 1000);
            foreach($chunks as $ck){
                $list = implode(',', array_map('intval', $ck));
                $pdo->exec("DELETE FROM episodes WHERE series_id IN (SELECT id FROM series WHERE category_id IN ($list))");
                $pdo->exec("DELETE FROM series     WHERE category_id IN ($list)");
                $pdo->exec("DELETE FROM channels   WHERE category_id IN ($list)");
                $pdo->exec("DELETE FROM categories WHERE id IN ($list)");
            }
            jOk(['deleted'=>count($arr)]);
        } catch(Exception $e){
            jErr('خطأ أثناء الحذف: '.$e->getMessage());
        }
    }

    // ══ حذف جماعي للقنوات ══
    if($act === 'bulk_delete_channels'){
        $ids = $_POST['ids'] ?? '';
        $arr = array_filter(array_map('intval', explode(',', $ids)), function($v){ return $v > 0; });
        if(!count($arr)) jErr('لم يتم تحديد أي قناة');
        try {
            // على دفعات لتجنّب حدّ الـ placeholders (الخطأ 1390)
            foreach(array_chunk(array_values($arr), 1000) as $ck){
                $list = implode(',', array_map('intval', $ck));
                $pdo->exec("DELETE FROM channels WHERE id IN ($list)");
            }
            jOk(['deleted'=>count($arr)]);
        } catch(Exception $e){
            jErr('خطأ أثناء الحذف: '.$e->getMessage());
        }
    }
    // ----------------------------------------------------

    /* ════════════════════ [XTREAM-AJAX-START] معالجات حساب Xtream IPTV — إضافة فقط ════════════════════ */
    // إنشاء جداول Xtream عند الحاجة (آمن ومتكرر)
    /* ── إيقاف الاستيراد إجبارياً: عبر ملف إشارة يُفحص داخل حلقات الاستيراد ── */
    if(!class_exists('XtreamAbort')){
        class XtreamAbort extends Exception {
            public $stage;
            public function __construct($stage = ''){ $this->stage = $stage; parent::__construct('aborted'); }
        }
    }
    function xtreamAbortFile(){
        $dir = VID_UPLOAD_DIR;
        if(!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir . 'xtream_import.abort';
    }
    function xtreamAbortRaise(){ return @file_put_contents(xtreamAbortFile(), '1') !== false; }
    function xtreamAbortClear(){ $f = xtreamAbortFile(); if(file_exists($f)) @unlink($f); }
    function xtreamAborted(){ clearstatcache(true, xtreamAbortFile()); return file_exists(xtreamAbortFile()); }

    /* ── تتبّع تقدّم الاستيراد: السيرفر يكتب الحالة في ملف، والمتصفح يقرأه دورياً ── */
    function xtreamProgFile(){
        $dir = VID_UPLOAD_DIR;
        if(!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir . 'xtream_import.progress';
    }
    function xtreamProgWrite($data){
        $data['ts'] = time();
        @file_put_contents(xtreamProgFile(), json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    function xtreamProgRead(){
        $f = xtreamProgFile();
        clearstatcache(true, $f);
        if(!file_exists($f)) return null;
        $raw = @file_get_contents($f);
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    }
    function xtreamProgClear(){ $f = xtreamProgFile(); if(file_exists($f)) @unlink($f); }

    function xtreamEnsureTables($pdo){
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS xtream_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) DEFAULT '',
                host VARCHAR(500) NOT NULL,
                username VARCHAR(255) NOT NULL,
                password VARCHAR(255) NOT NULL,
                server_info TEXT,
                user_info TEXT,
                live_count INT DEFAULT 0,
                vod_count INT DEFAULT 0,
                series_count INT DEFAULT 0,
                status VARCHAR(30) DEFAULT 'active',
                last_sync TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        } catch(PDOException $e){}
        // ربط الأقسام والقنوات والمسلسلات بحساب Xtream (لإتاحة الحذف الكامل)
        try { $pdo->exec("ALTER TABLE categories ADD COLUMN xtream_account_id INT NULL DEFAULT NULL"); } catch(PDOException $e){}
        try { $pdo->exec("ALTER TABLE channels   ADD COLUMN xtream_account_id INT NULL DEFAULT NULL"); } catch(PDOException $e){}
        try { $pdo->exec("ALTER TABLE series     ADD COLUMN xtream_account_id INT NULL DEFAULT NULL"); } catch(PDOException $e){}

        /* ── توسيع الأعمدة النصية ──
           سيرفرات Xtream تُرجع روابط صور/بث طويلة جداً (أحياناً >1000 حرف)،
           فتفشل الإضافة بخطأ: 1406 Data too long for column.
           نوسّعها هنا لأن هذه الدالة تعمل قبل الاستيراد مباشرة. */
        $widen = [
            "ALTER TABLE channels MODIFY logo_url   TEXT",
            "ALTER TABLE channels MODIFY stream_url TEXT",
            "ALTER TABLE channels MODIFY backup_url TEXT",
            "ALTER TABLE channels MODIFY name       VARCHAR(500)",
            "ALTER TABLE series   MODIFY poster_url  TEXT",
            "ALTER TABLE series   MODIFY description TEXT",
            "ALTER TABLE series   MODIFY name        VARCHAR(500)",
            "ALTER TABLE series   MODIFY slug        VARCHAR(255)",
            "ALTER TABLE episodes MODIFY stream_url  TEXT",
            "ALTER TABLE episodes MODIFY description TEXT",
            "ALTER TABLE episodes MODIFY title       VARCHAR(500)",
            "ALTER TABLE categories MODIFY name      VARCHAR(500)",
        ];
        foreach($widen as $sql){ try { $pdo->exec($sql); } catch(PDOException $e){} }

        /* ── فهارس الأداء ──
           بدونها كل استعلام "WHERE category_id=?" يمسح الجدول كاملاً (Full Table Scan).
           مع عشرات آلاف الصفوف بعد استيراد Xtream يصبح هذا أبطأ جزء في المنظومة.
           MySQL لا يدعم "ADD INDEX IF NOT EXISTS" في كل الإصدارات، لذا نتجاهل خطأ التكرار. */
        $idx = [
            "ALTER TABLE channels   ADD INDEX idx_ch_cat   (category_id)",
            "ALTER TABLE channels   ADD INDEX idx_ch_acc   (xtream_account_id)",
            "ALTER TABLE channels   ADD INDEX idx_ch_order (category_id, display_order, id)",
            "ALTER TABLE series     ADD INDEX idx_sr_cat   (category_id)",
            "ALTER TABLE series     ADD INDEX idx_sr_acc   (xtream_account_id)",
            "ALTER TABLE series     ADD INDEX idx_sr_order (category_id, display_order, id)",
            "ALTER TABLE series     ADD INDEX idx_sr_name  (name(100))",
            "ALTER TABLE channels   ADD INDEX idx_ch_name  (name(100))",
            "ALTER TABLE episodes   ADD INDEX idx_ep_ser   (series_id)",
            "ALTER TABLE episodes   ADD INDEX idx_ep_order (series_id, display_order, id)",
            "ALTER TABLE categories ADD INDEX idx_cat_acc  (xtream_account_id)",
        ];
        foreach($idx as $sql){ try { $pdo->exec($sql); } catch(PDOException $e){} }
    }

    /* قصّ آمن لأي قيمة قبل إدخالها (احتياط إضافي ضد الأعمدة الضيقة) */
    function xtreamFit($val, $max = 900){
        $val = (string)$val;
        if($val === '') return '';
        if(function_exists('mb_strlen')){
            return mb_strlen($val, 'UTF-8') > $max ? mb_substr($val, 0, $max, 'UTF-8') : $val;
        }
        return strlen($val) > $max ? substr($val, 0, $max) : $val;
    }

    // تطبيع رابط المضيف: يقبل host، host:port، http(s)://host:port، ويزيل المسارات الزائدة
    function xtreamNormalizeHost($host){
        $host = trim((string)$host);
        if($host === '') return '';
        if(!preg_match('#^https?://#i', $host)) $host = 'http://'.$host;
        $p = parse_url($host);
        if(!$p || empty($p['host'])) return '';
        $scheme = $p['scheme'] ?? 'http';
        $port   = isset($p['port']) ? ':'.$p['port'] : '';
        return $scheme.'://'.$p['host'].$port;
    }

    // طلب HTTP لـ Xtream player_api
    function xtreamApi($base, $user, $pass, $action='', $extra=[]){
        $url = rtrim($base,'/').'/player_api.php?username='.rawurlencode($user).'&password='.rawurlencode($pass);
        if($action !== '') $url .= '&action='.rawurlencode($action);
        foreach($extra as $k=>$v) $url .= '&'.rawurlencode($k).'='.rawurlencode($v);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (ShashetyIPTV Xtream)',
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($raw === false) return ['_error'=>'تعذر الاتصال بالسيرفر: '.$err];
        if($code >= 400) return ['_error'=>'استجابة السيرفر: HTTP '.$code];
        $data = json_decode($raw, true);
        if($data === null) return ['_error'=>'رد غير صالح من السيرفر (ليس JSON)'];
        return $data;
    }

    // بناء رابط بث لعنصر Xtream
    function xtreamStreamUrl($base, $user, $pass, $type, $id, $ext='ts'){
        $base = rtrim($base,'/');
        if($type === 'live')   return $base.'/live/'.rawurlencode($user).'/'.rawurlencode($pass).'/'.$id.'.'.$ext;
        if($type === 'movie')  return $base.'/movie/'.rawurlencode($user).'/'.rawurlencode($pass).'/'.$id.'.'.$ext;
        if($type === 'series') return $base.'/series/'.rawurlencode($user).'/'.rawurlencode($pass).'/'.$id.'.'.$ext;
        return '';
    }

    if($act === 'xtream_login'){
        xtreamEnsureTables($pdo);
        $host = xtreamNormalizeHost($_POST['host'] ?? '');
        $user = trim($_POST['username'] ?? '');
        $pass = trim($_POST['password'] ?? '');
        if($host === '' || $user === '' || $pass === '') jErr('يرجى إدخال العنوان واسم المستخدم وكلمة المرور');
        $info = xtreamApi($host, $user, $pass);
        if(isset($info['_error'])) jErr($info['_error']);
        $auth = $info['user_info']['auth'] ?? 0;
        $status = $info['user_info']['status'] ?? '';
        if(!$auth || strtolower((string)$status) === 'disabled'){
            jErr('فشل تسجيل الدخول: بيانات غير صحيحة أو الحساب موقوف');
        }
        // جلب أعداد سريعة للتصنيفات (لمعرفة الدعم)
        $liveCats = xtreamApi($host,$user,$pass,'get_live_categories');
        $vodCats  = xtreamApi($host,$user,$pass,'get_vod_categories');
        $serCats  = xtreamApi($host,$user,$pass,'get_series_categories');
        $supportsLive   = is_array($liveCats) && !isset($liveCats['_error']) && count($liveCats) > 0;
        $supportsVod    = is_array($vodCats)  && !isset($vodCats['_error'])  && count($vodCats)  > 0;
        $supportsSeries = is_array($serCats)  && !isset($serCats['_error'])  && count($serCats)  > 0;
        jOk([
            'user_info'   => $info['user_info']   ?? [],
            'server_info' => $info['server_info'] ?? [],
            'host'        => $host,
            'supports'    => ['live'=>$supportsLive,'vod'=>$supportsVod,'series'=>$supportsSeries],
            'counts'      => [
                'live'   => $supportsLive   ? count($liveCats) : 0,
                'vod'    => $supportsVod    ? count($vodCats)  : 0,
                'series' => $supportsSeries ? count($serCats)  : 0,
            ],
        ]);
    }

    if($act === 'xtream_import'){
        xtreamEnsureTables($pdo);
        @set_time_limit(0);
        @ignore_user_abort(true);
        xtreamAbortClear(); // امسح أي إشارة إيقاف قديمة قبل بدء استيراد جديد
        $host = xtreamNormalizeHost($_POST['host'] ?? '');
        $user = trim($_POST['username'] ?? '');
        $pass = trim($_POST['password'] ?? '');
        $name = trim($_POST['account_name'] ?? '');
        $impLive   = ($_POST['import_live']   ?? '1') === '1';
        $impVod    = ($_POST['import_vod']    ?? '1') === '1';
        $impSeries = ($_POST['import_series'] ?? '1') === '1';
        if($host === '' || $user === '' || $pass === '') jErr('بيانات الحساب ناقصة');
        // إعادة التحقق
        $info = xtreamApi($host,$user,$pass);
        if(isset($info['_error'])) jErr($info['_error']);
        if(!($info['user_info']['auth'] ?? 0)) jErr('فشل التحقق من الحساب');
        if($name === '') $name = parse_url($host, PHP_URL_HOST) ?: 'حساب Xtream';

        // إنشاء سجل الحساب
        $pdo->prepare("INSERT INTO xtream_accounts (name, host, username, password, server_info, user_info, status) VALUES (?,?,?,?,?,?,'active')")
            ->execute([$name, $host, $user, $pass, json_encode($info['server_info'] ?? []), json_encode($info['user_info'] ?? [])]);
        $accId = (int)$pdo->lastInsertId();

        $liveN = 0; $vodN = 0; $serN = 0; $skipN = 0; // skipN = صفوف تم تخطيها بسبب خطأ
        $startedAt = time();
        xtreamProgClear();
        xtreamProgWrite(['stage'=>'init','label'=>'جارٍ الاتصال بالسيرفر...','done'=>0,'total'=>0,
                         'live'=>0,'vod'=>0,'series'=>0,'skipped'=>0,'started'=>$startedAt]);
        $prefix = '📡 '.$name.' — ';

        // دالة مساعدة: إنشاء/جلب قسم مربوط بالحساب
        $catCache = [];
        $getCat = function($rawName, $icon) use ($pdo, $accId, &$catCache, $prefix){
            $catName = $prefix.trim($rawName);
            $key = mb_strtolower($catName);
            if(isset($catCache[$key])) return $catCache[$key];
            $slug = 'xt-'.$accId.'-'.substr(md5($catName),0,10);
            $pdo->prepare("INSERT INTO categories (name, icon, slug, xtream_account_id) VALUES (?,?,?,?)")
                ->execute([xtreamFit($catName, 450), $icon, $slug, $accId]);
            $id = (int)$pdo->lastInsertId();
            $catCache[$key] = $id;
            return $id;
        };

        try {
            $ext = $info['user_info']['allowed_output_formats'][0] ?? 'ts';
            if(!in_array($ext,['ts','m3u8'])) $ext = 'ts';

            // ═══ القنوات المباشرة (live) ═══
            if($impLive){
                $cats = xtreamApi($host,$user,$pass,'get_live_categories');
                $catMap = [];
                if(is_array($cats) && !isset($cats['_error'])){
                    foreach($cats as $c){ if(isset($c['category_id'])) $catMap[$c['category_id']] = $c['category_name'] ?? 'قنوات'; }
                }
                xtreamProgWrite(['stage'=>'live','label'=>'جارٍ جلب قائمة القنوات...','done'=>0,'total'=>0,
                                 'live'=>$liveN,'vod'=>$vodN,'series'=>$serN,'skipped'=>$skipN,'started'=>$startedAt]);
                $streams = xtreamApi($host,$user,$pass,'get_live_streams');
                if(is_array($streams) && !isset($streams['_error'])){
                    $insCh = $pdo->prepare("INSERT INTO channels (category_id, name, stream_url, logo_icon, logo_url, backup_url, quality, is_active, xtream_account_id) VALUES (?,?,?,?,?,?,?,1,?)");
                    $i = 0;
                    $totLive = count($streams);
                    foreach($streams as $s){
                        if((++$i % 25) === 0 && xtreamAborted()) throw new XtreamAbort('live');
                        if(($i % 20) === 0 || $i === $totLive){
                            xtreamProgWrite(['stage'=>'live','label'=>'استيراد القنوات المباشرة','done'=>$i,'total'=>$totLive,
                                             'live'=>$liveN,'vod'=>$vodN,'series'=>$serN,'skipped'=>$skipN,'started'=>$startedAt]);
                        }
                        $sid = $s['stream_id'] ?? null; if($sid === null) continue;
                        $cid = $s['category_id'] ?? '';
                        $cName = $catMap[$cid] ?? 'قنوات Xtream';
                        $catId = $getCat($cName, 'fas fa-tv');
                        $url = xtreamStreamUrl($host,$user,$pass,'live',$sid,$ext);
                        try {
                            $insCh->execute([
                                $catId,
                                xtreamFit(htmlspecialchars(strip_tags($s['name'] ?? 'قناة')), 450),
                                xtreamFit($url, 2000),
                                'fas fa-tv',
                                xtreamFit($s['stream_icon'] ?? '', 2000),
                                '', 'HD', $accId
                            ]);
                            $liveN++;
                        } catch(PDOException $e){ $skipN++; }
                    }
                }
            }

            // ═══ الأفلام (VOD) → إلى «شاشتي» (series + episodes)، وليس إلى القنوات ═══
            // كل فيلم = صف في series + حلقة واحدة في episodes تحمل رابط الفيديو (mp4/mkv).
            // قسم «إدارة القنوات» مخصّص للبث المباشر فقط (ts / m3u8).
            if($impVod){
                $cats = xtreamApi($host,$user,$pass,'get_vod_categories');
                $catMap = [];
                if(is_array($cats) && !isset($cats['_error'])){
                    foreach($cats as $c){ if(isset($c['category_id'])) $catMap[$c['category_id']] = $c['category_name'] ?? 'أفلام'; }
                }
                xtreamProgWrite(['stage'=>'vod','label'=>'جارٍ جلب قائمة الأفلام...','done'=>0,'total'=>0,
                                 'live'=>$liveN,'vod'=>$vodN,'series'=>$serN,'skipped'=>$skipN,'started'=>$startedAt]);
                $streams = xtreamApi($host,$user,$pass,'get_vod_streams');
                if(is_array($streams) && !isset($streams['_error'])){
                    $insMov = $pdo->prepare("INSERT INTO series (category_id, name, slug, description, poster_url, logo_icon, xtream_account_id) VALUES (?,?,?,?,?,?,?)");
                    $insMovEp = $pdo->prepare("INSERT INTO episodes (series_id, episode_number, title, stream_url, description, display_order) VALUES (?,?,?,?,?,?)");
                    $i = 0;
                    $totVod = count($streams);
                    foreach($streams as $s){
                        if((++$i % 25) === 0 && xtreamAborted()) throw new XtreamAbort('vod');
                        if(($i % 20) === 0 || $i === $totVod){
                            xtreamProgWrite(['stage'=>'vod','label'=>'استيراد الأفلام إلى شاشتي','done'=>$i,'total'=>$totVod,
                                             'live'=>$liveN,'vod'=>$vodN,'series'=>$serN,'skipped'=>$skipN,'started'=>$startedAt]);
                        }
                        $sid = $s['stream_id'] ?? null; if($sid === null) continue;
                        $cid = $s['category_id'] ?? '';
                        $cName = $catMap[$cid] ?? 'أفلام Xtream';
                        $catId = $getCat('🎬 '.$cName, 'fas fa-film');
                        $vext = $s['container_extension'] ?? 'mp4';
                        $url  = xtreamStreamUrl($host,$user,$pass,'movie',$sid,$vext);
                        $mName = htmlspecialchars(strip_tags($s['name'] ?? 'فيلم'));
                        $mSlug = 'xtmov-'.$accId.'-'.$sid.'-'.substr(md5($mName),0,6);
                        $mPoster = $s['stream_icon'] ?? ($s['cover'] ?? ($s['movie_image'] ?? ''));
                        $mPlot   = $s['plot'] ?? ($s['description'] ?? '');
                        try {
                            // 1) الفيلم كعنصر في شاشتي
                            $insMov->execute([
                                $catId,
                                xtreamFit($mName, 450),
                                xtreamFit($mSlug, 200),
                                xtreamFit(strip_tags((string)$mPlot), 5000),
                                xtreamFit($mPoster, 2000),
                                'fas fa-film',
                                $accId
                            ]);
                            $movId = (int)$pdo->lastInsertId();
                            // 2) حلقة وحيدة تحمل ملف الفيديو
                            $insMovEp->execute([
                                $movId, 1,
                                xtreamFit($mName, 450),
                                xtreamFit($url, 2000),
                                '', 0
                            ]);
                            $vodN++;
                        } catch(PDOException $e){ $skipN++; }
                    }
                }
            }

            // ═══ المسلسلات (series + episodes) ═══
            if($impSeries){
                $cats = xtreamApi($host,$user,$pass,'get_series_categories');
                $catMap = [];
                if(is_array($cats) && !isset($cats['_error'])){
                    foreach($cats as $c){ if(isset($c['category_id'])) $catMap[$c['category_id']] = $c['category_name'] ?? 'مسلسلات'; }
                }
                xtreamProgWrite(['stage'=>'series','label'=>'جارٍ جلب قائمة المسلسلات...','done'=>0,'total'=>0,
                                 'live'=>$liveN,'vod'=>$vodN,'series'=>$serN,'skipped'=>$skipN,'started'=>$startedAt]);
                $list = xtreamApi($host,$user,$pass,'get_series');
                if(is_array($list) && !isset($list['_error'])){
                    $totSer = count($list); $si = 0;
                    $insSer = $pdo->prepare("INSERT INTO series (category_id, name, slug, description, poster_url, logo_icon, xtream_account_id) VALUES (?,?,?,?,?,?,?)");
                    $insEp  = $pdo->prepare("INSERT INTO episodes (series_id, episode_number, title, stream_url, description, display_order) VALUES (?,?,?,?,?,?)");
                    foreach($list as $sr){
                        // كل مسلسل = طلب شبكة مستقل، لذا نفحص الإيقاف عند كل عنصر
                        if(xtreamAborted()) throw new XtreamAbort('series');
                        $si++;
                        xtreamProgWrite(['stage'=>'series','label'=>'استيراد المسلسلات وحلقاتها','done'=>$si,'total'=>$totSer,
                                         'live'=>$liveN,'vod'=>$vodN,'series'=>$serN,'skipped'=>$skipN,'started'=>$startedAt,
                                         'current'=>mb_substr((string)($sr['name'] ?? ''),0,60,'UTF-8')]);
                        $seriesId = $sr['series_id'] ?? null; if($seriesId === null) continue;
                        $cid = $sr['category_id'] ?? '';
                        $cName = $catMap[$cid] ?? 'مسلسلات Xtream';
                        $catId = $getCat('📺 '.$cName, 'fas fa-clapperboard');
                        $sName = htmlspecialchars(strip_tags($sr['name'] ?? 'مسلسل'));
                        $slug = 'xtser-'.$accId.'-'.$seriesId.'-'.substr(md5($sName),0,6);
                        try {
                            $insSer->execute([
                                $catId,
                                xtreamFit($sName, 450),
                                xtreamFit($slug, 200),
                                xtreamFit(strip_tags($sr['plot'] ?? ''), 5000),
                                xtreamFit($sr['cover'] ?? '', 2000),
                                'fas fa-clapperboard',
                                $accId
                            ]);
                        } catch(PDOException $e){ $skipN++; continue; }
                        $dbSeriesId = (int)$pdo->lastInsertId();
                        // جلب الحلقات
                        $det = xtreamApi($host,$user,$pass,'get_series_info',['series_id'=>$seriesId]);
                        if(is_array($det) && isset($det['episodes']) && is_array($det['episodes'])){
                            $order = 0;
                            foreach($det['episodes'] as $seasonNum=>$eps){
                                if(!is_array($eps)) continue;
                                foreach($eps as $ep){
                                    $epId = $ep['id'] ?? null; if($epId === null) continue;
                                    $epExt = $ep['container_extension'] ?? 'mp4';
                                    $epUrl = xtreamStreamUrl($host,$user,$pass,'series',$epId,$epExt);
                                    $epTitle = htmlspecialchars(strip_tags($ep['title'] ?? ('الحلقة '.($ep['episode_num'] ?? ($order+1)))));
                                    $epNum = (int)($ep['episode_num'] ?? ($order+1));
                                    try {
                                        $insEp->execute([
                                            $dbSeriesId,
                                            $epNum,
                                            xtreamFit($epTitle, 450),
                                            xtreamFit($epUrl, 2000),
                                            '',
                                            $order++
                                        ]);
                                    } catch(PDOException $e){ $skipN++; }
                                }
                            }
                        }
                        $serN++;
                    }
                }
            }

            // تحديث سجل الحساب بالأعداد
            $pdo->prepare("UPDATE xtream_accounts SET live_count=?, vod_count=?, series_count=?, last_sync=NOW() WHERE id=?")
                ->execute([$liveN, $vodN, $serN, $accId]);

            if(function_exists('shashety_cache_clear')) @shashety_cache_clear();

            xtreamAbortClear(); xtreamProgClear();

            jOk([
                'account_id' => $accId,
                'name'       => $name,
                'imported'   => ['live'=>$liveN, 'vod'=>$vodN, 'series'=>$serN],
                'skipped'    => $skipN,
                'message'    => 'تم الاستيراد بنجاح',
            ]);
        } catch(XtreamAbort $ab){
            /* إيقاف إجباري: تراجع كامل — لا نترك حساباً نصف مستورد */
            xtreamAbortClear(); xtreamProgClear();
            try {
                $pdo->prepare("DELETE FROM episodes
                               WHERE series_id IN (SELECT id FROM series WHERE xtream_account_id = ?)")
                    ->execute([$accId]);
                $pdo->prepare("DELETE FROM series     WHERE xtream_account_id=?")->execute([$accId]);
                $pdo->prepare("DELETE FROM channels   WHERE xtream_account_id=?")->execute([$accId]);
                $pdo->prepare("DELETE FROM categories WHERE xtream_account_id=?")->execute([$accId]);
                $pdo->prepare("DELETE FROM xtream_accounts WHERE id=?")->execute([$accId]);
            } catch(Exception $e2){}
            if(function_exists('shashety_cache_clear')) @shashety_cache_clear();
            jErr('تم إيقاف الاستيراد بناءً على طلبك، وأُزيل كل ما استُورد جزئياً.');
        } catch(Exception $e){
            xtreamAbortClear(); xtreamProgClear();
            jErr('خطأ أثناء الاستيراد: '.$e->getMessage());
        }
    }

    /* ── رفع إشارة الإيقاف الإجباري للاستيراد الجاري ── */
    /* ── استعلام التقدّم: يستدعيه المتصفح كل ثانية أثناء الاستيراد ── */
    if($act === 'xtream_import_progress'){
        $p = xtreamProgRead();
        if(!$p) jOk(['running'=>false]);
        $elapsed = max(0, time() - (int)($p['started'] ?? time()));
        $done  = (int)($p['done'] ?? 0);
        $total = (int)($p['total'] ?? 0);
        $pct   = $total > 0 ? min(99, (int)floor($done * 100 / $total)) : 0;
        // تقدير الوقت المتبقي من متوسط سرعة المعالجة
        $eta = null;
        if($done > 0 && $total > 0 && $elapsed > 2){
            $rate = $done / $elapsed;                 // عنصر/ثانية
            if($rate > 0) $eta = (int)round(($total - $done) / $rate);
        }
        $p['running'] = true;
        $p['percent'] = $pct;
        $p['elapsed'] = $elapsed;
        $p['eta']     = $eta;
        $p['stale']   = (time() - (int)($p['ts'] ?? time())) > 90; // لم يتحدث منذ فترة طويلة
        jOk($p);
    }

    /* ── ترحيل الأفلام المستوردة سابقاً من «القنوات» إلى «شاشتي» ──
       يعالج البيانات القديمة التي أُدخلت قبل الإصلاح، بلا حاجة لإعادة استيراد كامل.
       نتعرّف على الفيلم بأنه قناة Xtream رابطها /movie/ */
    if($act === 'xtream_fix_vod'){
        xtreamEnsureTables($pdo);
        try {
            $rows = $pdo->query("SELECT id, category_id, name, stream_url, logo_url, xtream_account_id
                                 FROM channels
                                 WHERE xtream_account_id IS NOT NULL
                                   AND stream_url LIKE '%/movie/%'")->fetchAll(PDO::FETCH_ASSOC);
            if(!$rows) jOk(['moved'=>0,'message'=>'لا توجد أفلام في قسم القنوات — كل شيء سليم']);

            $pdo->beginTransaction();
            $insMov   = $pdo->prepare("INSERT INTO series (category_id, name, slug, description, poster_url, logo_icon, xtream_account_id) VALUES (?,?,?,?,?,?,?)");
            $insMovEp = $pdo->prepare("INSERT INTO episodes (series_id, episode_number, title, stream_url, description, display_order) VALUES (?,?,?,?,?,?)");
            $delCh    = $pdo->prepare("DELETE FROM channels WHERE id=?");
            $moved = 0; $failed = 0;

            foreach($rows as $r){
                try {
                    $nm   = (string)$r['name'];
                    $slug = 'xtmov-'.(int)$r['xtream_account_id'].'-c'.(int)$r['id'].'-'.substr(md5($nm),0,6);
                    $insMov->execute([
                        (int)$r['category_id'],
                        xtreamFit($nm, 450),
                        xtreamFit($slug, 200),
                        '',
                        xtreamFit((string)$r['logo_url'], 2000),
                        'fas fa-film',
                        (int)$r['xtream_account_id'],
                    ]);
                    $movId = (int)$pdo->lastInsertId();
                    $insMovEp->execute([$movId, 1, xtreamFit($nm,450), xtreamFit((string)$r['stream_url'],2000), '', 0]);
                    $delCh->execute([(int)$r['id']]);
                    $moved++;
                } catch(PDOException $e){ $failed++; }
            }
            $pdo->commit();
            if(function_exists('shashety_cache_clear')) @shashety_cache_clear();
            jOk([
                'moved'   => $moved,
                'failed'  => $failed,
                'purge_client' => true,
                'message' => 'تم نقل '.$moved.' فيلماً من «القنوات» إلى «شاشتي»'.($failed ? ' — تعذّر نقل '.$failed : ''),
            ]);
        } catch(Exception $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            jErr('تعذر الترحيل: '.$e->getMessage());
        }
    }

    /* ── تسريع قاعدة البيانات: إضافة الفهارس الناقصة ── */
    if($act === 'xtream_optimize_db'){
        $idx = [
            'channels/category_id'        => "ALTER TABLE channels   ADD INDEX idx_ch_cat   (category_id)",
            'channels/xtream_account_id'  => "ALTER TABLE channels   ADD INDEX idx_ch_acc   (xtream_account_id)",
            'channels/ترتيب'              => "ALTER TABLE channels   ADD INDEX idx_ch_order (category_id, display_order, id)",
            'series/category_id'          => "ALTER TABLE series     ADD INDEX idx_sr_cat   (category_id)",
            'series/xtream_account_id'    => "ALTER TABLE series     ADD INDEX idx_sr_acc   (xtream_account_id)",
            'series/ترتيب'                => "ALTER TABLE series     ADD INDEX idx_sr_order (category_id, display_order, id)",
            'series/بحث بالاسم'           => "ALTER TABLE series     ADD INDEX idx_sr_name  (name(100))",
            'channels/بحث بالاسم'         => "ALTER TABLE channels   ADD INDEX idx_ch_name  (name(100))",
            'episodes/series_id'          => "ALTER TABLE episodes   ADD INDEX idx_ep_ser   (series_id)",
            'episodes/ترتيب'              => "ALTER TABLE episodes   ADD INDEX idx_ep_order (series_id, display_order, id)",
            'categories/xtream_account_id'=> "ALTER TABLE categories ADD INDEX idx_cat_acc  (xtream_account_id)",
        ];
        $added = []; $already = [];
        foreach($idx as $label => $sql){
            try { $pdo->exec($sql); $added[] = $label; }
            catch(PDOException $e){ $already[] = $label; } // موجود مسبقاً أو غير مدعوم
        }
        // إحصاء حجم البيانات لعرضه للمستخدم
        $counts = [];
        foreach(['channels','series','episodes','categories'] as $t){
            try { $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); }
            catch(Exception $e){ $counts[$t] = 0; }
        }
        jOk([
            'added'   => $added,
            'already' => $already,
            'counts'  => $counts,
            'message' => count($added)
                ? ('تمت إضافة '.count($added).' فهرساً — الاستعلامات ستصبح أسرع بكثير')
                : 'كل الفهارس موجودة مسبقاً — قاعدة البيانات مهيّأة',
        ]);
    }

    if($act === 'xtream_import_abort'){
        xtreamAbortRaise();
        jOk(['message'=>'تم إرسال إشارة الإيقاف — سيتوقف الاستيراد خلال لحظات']);
    }

    if($act === 'xtream_list'){
        xtreamEnsureTables($pdo);
        try {
            $rows = $pdo->query("SELECT id, name, host, username, user_info, live_count, vod_count, series_count, status, last_sync, created_at FROM xtream_accounts ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
            jOk(['accounts'=>$rows]);
        } catch(Exception $e){ jErr('تعذر جلب الحسابات: '.$e->getMessage()); }
    }

    /* ── حذف الحساب نهائياً / تسجيل الخروج منه ──
       كلاهما يمسح كل المحتوى المستورد: الحلقات + المسلسلات + الأفلام + القنوات + الأقسام الفارغة
       الفرق: xtream_delete يحذف سجل الحساب أيضاً، xtream_logout يُبقيه فارغاً لإعادة الدخول */
    if($act === 'xtream_delete' || $act === 'xtream_logout'){
        xtreamEnsureTables($pdo);
        $id = (int)($_POST['id'] ?? 0);
        if($id <= 0) jErr('معرف غير صالح');
        $isLogout = ($act === 'xtream_logout');
        // حذف عشرات الآلاف من الصفوف قد يتجاوز المهلة الافتراضية
        @set_time_limit(600);
        @ini_set('memory_limit','512M');
        try {
            $pdo->beginTransaction();

            /* 1) الحلقات المرتبطة بمسلسلات هذا الحساب
               نستخدم استعلاماً فرعياً بدل IN (?,?,?...) — لأن MySQL يسمح بـ 65535 عنصراً
               كحدّ أقصى في الجملة المجهّزة، ومع ١٦ ألف مسلسل كان يفشل بالخطأ 1390. */
            $pdo->prepare("DELETE FROM episodes
                           WHERE series_id IN (SELECT id FROM series WHERE xtream_account_id = ?)")
                ->execute([$id]);

            /* 2) المسلسلات والأفلام والقنوات */
            $pdo->prepare("DELETE FROM series   WHERE xtream_account_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM channels WHERE xtream_account_id=?")->execute([$id]);

            /* 3) الأقسام: احذف فقط ما لم يبقَ فيه أي محتوى (حماية للأقسام المشتركة) */
            $st = $pdo->prepare("SELECT id FROM categories WHERE xtream_account_id=?");
            $st->execute([$id]);
            $cids = $st->fetchAll(PDO::FETCH_COLUMN);
            $cntCh = $pdo->prepare("SELECT COUNT(*) FROM channels WHERE category_id=?");
            $cntSe = $pdo->prepare("SELECT COUNT(*) FROM series   WHERE category_id=?");
            $delCat = $pdo->prepare("DELETE FROM categories WHERE id=?");
            foreach($cids as $cid){
                $cntCh->execute([$cid]); $n1 = (int)$cntCh->fetchColumn();
                $cntSe->execute([$cid]); $n2 = (int)$cntSe->fetchColumn();
                if($n1 === 0 && $n2 === 0) $delCat->execute([$cid]);
            }

            /* 4) الحساب نفسه */
            if($isLogout){
                /* لا نمسح كلمة المرور — وإلا استحال إعادة الاستيراد لاحقاً.
                   نكتفي بتصفير العدادات وتعليم الحساب كـ"خارج" وتفريغ بيانات الجلسة. */
                $pdo->prepare("UPDATE xtream_accounts
                               SET live_count=0, vod_count=0, series_count=0,
                                   status='logged_out', server_info=NULL, user_info=NULL
                               WHERE id=?")->execute([$id]);
            } else {
                $pdo->prepare("DELETE FROM xtream_accounts WHERE id=?")->execute([$id]);
            }

            $pdo->commit();

            /* 5) تفريغ كاش السيرفر */
            if(function_exists('shashety_cache_clear')) @shashety_cache_clear();

            jOk([
                'id'           => $id,
                'mode'         => $isLogout ? 'logout' : 'delete',
                'deleted'      => $id,
                'purge_client' => true, // إشارة للواجهة كي تمسح المفضلة/الإشعارات/الكاش المحلي
                'message'      => $isLogout ? 'تم تسجيل الخروج ومسح كل محتوى الحساب'
                                            : 'تم حذف الحساب وكل محتواه',
            ]);
        } catch(Exception $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            jErr(($isLogout ? 'تعذر تسجيل الخروج: ' : 'تعذر الحذف: ').$e->getMessage());
        }
    }

    /* ── مسح إجباري يدوي: كل ما يخص Xtream دفعة واحدة (لا يمس محتوى M3U أو اليدوي) ── */
    if($act === 'xtream_purge_all'){
        xtreamEnsureTables($pdo);
        @set_time_limit(900);
        @ini_set('memory_limit','512M');
        if(($_POST['confirm'] ?? '') !== 'PURGE') jErr('التأكيد مفقود');
        try {
            $pdo->beginTransaction();

            /* 1) حلقات كل مسلسلات Xtream (استعلام فرعي — بلا حدّ placeholders) */
            $stE = $pdo->prepare("DELETE FROM episodes
                                  WHERE series_id IN (SELECT id FROM series WHERE xtream_account_id IS NOT NULL)");
            $stE->execute();
            $epN = $stE->rowCount();

            /* 2) كل المسلسلات والأفلام والقنوات المستوردة من Xtream */
            $stS = $pdo->prepare("DELETE FROM series   WHERE xtream_account_id IS NOT NULL"); $stS->execute();
            $serN = $stS->rowCount();
            $stC = $pdo->prepare("DELETE FROM channels WHERE xtream_account_id IS NOT NULL"); $stC->execute();
            $chN = $stC->rowCount();

            /* 3) أقسام Xtream: احذف فقط الفارغة (حماية أي قسم يشاركه محتوى آخر) */
            $cids = $pdo->query("SELECT id FROM categories WHERE xtream_account_id IS NOT NULL")
                        ->fetchAll(PDO::FETCH_COLUMN);
            $cntCh = $pdo->prepare("SELECT COUNT(*) FROM channels WHERE category_id=?");
            $cntSe = $pdo->prepare("SELECT COUNT(*) FROM series   WHERE category_id=?");
            $delCat = $pdo->prepare("DELETE FROM categories WHERE id=?");
            $catN = 0; $catKept = 0;
            foreach($cids as $cid){
                $cntCh->execute([$cid]); $n1 = (int)$cntCh->fetchColumn();
                $cntSe->execute([$cid]); $n2 = (int)$cntSe->fetchColumn();
                if($n1 === 0 && $n2 === 0){ $delCat->execute([$cid]); $catN++; }
                else { $catKept++; }
            }

            /* 4) كل سجلات الحسابات */
            $stA = $pdo->prepare("DELETE FROM xtream_accounts"); $stA->execute();
            $accN = $stA->rowCount();

            $pdo->commit();

            if(function_exists('shashety_cache_clear')) @shashety_cache_clear();

            jOk([
                'purge_client' => true,
                'stats'   => [
                    'accounts'   => $accN,
                    'channels'   => $chN,
                    'series'     => $serN,
                    'episodes'   => $epN,
                    'categories' => $catN,
                    'cat_kept'   => $catKept,
                ],
                'message' => 'تم مسح كل محتوى Xtream نهائياً',
            ]);
        } catch(Exception $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            jErr('تعذر المسح الإجباري: '.$e->getMessage());
        }
    }

    if($act === 'xtream_update'){
        xtreamEnsureTables($pdo);
        $id = (int)($_POST['id'] ?? 0);
        if($id <= 0) jErr('معرف غير صالح');
        $name = trim($_POST['account_name'] ?? '');
        $host = xtreamNormalizeHost($_POST['host'] ?? '');
        $user = trim($_POST['username'] ?? '');
        $pass = trim($_POST['password'] ?? '');
        try {
            $fields = []; $vals = [];
            if($name !== ''){ $fields[]='name=?'; $vals[]=$name; }
            if($host !== ''){ $fields[]='host=?'; $vals[]=$host; }
            if($user !== ''){ $fields[]='username=?'; $vals[]=$user; }
            if($pass !== ''){ $fields[]='password=?'; $vals[]=$pass; }
            if(!$fields) jErr('لا يوجد ما يُحدّث');
            $vals[] = $id;
            $pdo->prepare("UPDATE xtream_accounts SET ".implode(',',$fields)." WHERE id=?")->execute($vals);
            jOk(['updated'=>$id]);
        } catch(Exception $e){ jErr('تعذر التعديل: '.$e->getMessage()); }
    }
    /* ════════════════════ [XTREAM-AJAX-END] نهاية معالجات حساب Xtream ════════════════════ */

        jErr('عذرا الكود المبدئ في الاستمارة لا يمت لاوامري.');
}

// ══ Categories Handlers (إدارة الأقسام) ══
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])){
    try {
        $name = htmlspecialchars(strip_tags($_POST['category_name']));
        $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $icon = htmlspecialchars(strip_tags($_POST['category_icon'] ?? 'fas fa-th-large'));
        $desc = htmlspecialchars(strip_tags($_POST['description'] ?? ''));
        
        $slug_new = "cat-".time()."-".rand(100,999);
        $pdo->prepare("INSERT INTO categories (name, parent_id, icon, description, slug) VALUES (?, ?, ?, ?, ?)")->execute([$name, $parent_id, $icon, $desc, $slug_new]);
        $_SESSION['success'] = '✅ تم إضافة القسم بنجاح.'; 
    } catch(PDOException $e) {
        error_log('[shashety] DB error: ' . $e->getMessage());
        $_SESSION['error'] = 'حدث خطأ في قاعدة البيانات، يرجى المحاولة مرة أخرى.';
    }
    header('Location: admin.php#categories'); 
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])){
    try {
        $id = (int)$_POST['category_id'];
        $name = htmlspecialchars(strip_tags($_POST['category_name']));
        $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $icon = htmlspecialchars(strip_tags($_POST['category_icon'] ?? 'fas fa-th-large'));
        
        $pdo->prepare("UPDATE categories SET name=?, parent_id=?, icon=? WHERE id=?")->execute([$name, $parent_id, $icon, $id]);
        $_SESSION['success'] = '✅ تم تعديل القسم بنجاح.'; 
    } catch(PDOException $e) {
        error_log('[shashety] DB error: ' . $e->getMessage());
        $_SESSION['error'] = 'حدث خطأ في قاعدة البيانات، يرجى المحاولة مرة أخرى.';
    }
    header('Location: admin.php#categories'); 
    exit;
}

if(isset($_GET['delete_category'])){
    try {
        $id = (int)$_GET['delete_category'];
        // حذف القسم
        $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
        $_SESSION['success'] = '✅ تم حذف القسم بنجاح.'; 
    } catch(PDOException $e) {
        $_SESSION['error'] = 'لا يمكن الحذف (قد يكون هناك قنوات مرتبطة بهذا القسم).';
    }
    header('Location: admin.php#categories'); 
    exit;
}

// ══ Channels Handlers ══

// إنشاء جدول القنوات (في حال لم يكن موجوداً) لتفادي الأخطاء
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS channels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        stream_url VARCHAR(1000) NOT NULL,
        subtitle_url VARCHAR(1000) DEFAULT '',
        logo_icon VARCHAR(100) DEFAULT 'fas fa-tv',
        logo_url VARCHAR(1000) DEFAULT '',
        backup_url VARCHAR(1000) DEFAULT '',
        quality VARCHAR(20) DEFAULT 'HD',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        views_count INT DEFAULT 0,
        display_order INT DEFAULT 0
    )");
} catch(PDOException $e) {}

// --- ضع هذا الكود هنا (تحديث الجداول قبل عمليات الإضافة والتعديل) ---
try { $pdo->exec("ALTER TABLE channels ADD COLUMN logo_url VARCHAR(1000) DEFAULT ''"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE channels ADD COLUMN logo_icon VARCHAR(100) DEFAULT 'fas fa-tv'"); } catch(PDOException $e) {}
// ══ أعمدة خصائص القناة الجديدة: رابط احتياطي / الجودة / الحالة (نشطة - غير نشطة) ══
try { $pdo->exec("ALTER TABLE channels ADD COLUMN backup_url VARCHAR(1000) DEFAULT ''"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE channels ADD COLUMN quality VARCHAR(20) NOT NULL DEFAULT 'HD'"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE channels ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE categories ADD COLUMN parent_id INT NULL DEFAULT NULL"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE categories ADD COLUMN icon VARCHAR(100) DEFAULT 'fas fa-th-large'"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE categories ADD COLUMN description TEXT"); } catch(PDOException $e) {}
// -------------------------------------------------------------------

// ══ نظام استيراد قوائم M3U ══
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS m3u_playlists (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        source_type ENUM('file','url') DEFAULT 'url',
        source_url VARCHAR(1000) DEFAULT '',
        channels_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL
    )");
} catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE channels ADD COLUMN playlist_id INT NULL DEFAULT NULL"); } catch(PDOException $e) {}
// -------------------------------------------------------------------

// كود: إضافة قناة جديدة
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_channel'])){
    try {
        $cat_id = (int)$_POST['category_id'];
        $name = htmlspecialchars(strip_tags($_POST['channel_name']));
        $url = $_POST['stream_url'] ?? '';
        $icon = htmlspecialchars(strip_tags($_POST['logo_icon'] ?? 'fas fa-tv'));
        $logo = $_POST['logo_url'] ?? '';
        $backup_url = trim($_POST['backup_url'] ?? '');
        $allowed_quality = ['SD 480','HD 720','Full HD 1080P','4K UHD'];
        $quality = $_POST['quality'] ?? 'HD 720';
        if (!in_array($quality, $allowed_quality, true)) $quality = 'HD 720';
        $is_active = (isset($_POST['is_active']) && $_POST['is_active'] === '1') ? 1 : 0;
        
        $pdo->prepare("INSERT INTO channels (category_id, name, stream_url, logo_icon, logo_url, backup_url, quality, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([$cat_id, $name, $url, $icon, $logo, $backup_url, $quality, $is_active]);
        $_SESSION['success'] = '✅ تم إضافة القناة بنجاح.'; 
    } catch(PDOException $e) {
        error_log('[shashety] DB error: ' . $e->getMessage());
        $_SESSION['error'] = 'حدث خطأ في قاعدة البيانات، يرجى المحاولة مرة أخرى.';
    }
    header('Location: admin.php#channels'); 
    exit;
}

// كود: تعديل قناة موجودة
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_channel'])){
    try {
        $id = (int)$_POST['channel_id'];
        $cat_id = (int)$_POST['category_id'];
        $name = htmlspecialchars(strip_tags($_POST['channel_name']));
        $url = $_POST['stream_url'] ?? '';
        $icon = htmlspecialchars(strip_tags($_POST['logo_icon'] ?? 'fas fa-tv'));
        $logo = $_POST['logo_url'] ?? '';
        $backup_url = trim($_POST['backup_url'] ?? '');
        $allowed_quality = ['SD 480','HD 720','Full HD 1080P','4K UHD'];
        $quality = $_POST['quality'] ?? 'HD 720';
        if (!in_array($quality, $allowed_quality, true)) $quality = 'HD 720';
        $is_active = (isset($_POST['is_active']) && $_POST['is_active'] === '1') ? 1 : 0;
        
        $pdo->prepare("UPDATE channels SET category_id=?, name=?, stream_url=?, logo_icon=?, logo_url=?, backup_url=?, quality=?, is_active=? WHERE id=?")->execute([$cat_id, $name, $url, $icon, $logo, $backup_url, $quality, $is_active, $id]);
        $_SESSION['success'] = '✅ تم تعديل القناة بنجاح.'; 
    } catch(PDOException $e) {
        error_log('[shashety] DB error: ' . $e->getMessage());
        $_SESSION['error'] = 'حدث خطأ في قاعدة البيانات، يرجى المحاولة مرة أخرى.';
    }
    header('Location: admin.php#channels'); 
    exit;
}

// كود: حذف القناة
if(isset($_GET['delete_channel'])){
    try {
        $id = (int)$_GET['delete_channel'];
        $pdo->prepare("DELETE FROM channels WHERE id=?")->execute([$id]);
        $_SESSION['success'] = '✅ تم حذف القناة بنجاح.'; 
    } catch(PDOException $e) {
        $_SESSION['error'] = 'حدث خطأ أثناء الحذف.';
    }
    header('Location: admin.php#channels'); 
    exit;
}

// تحديث جدول القنوات لإضافة أعمدة الشعار
try { $pdo->exec("ALTER TABLE channels ADD COLUMN logo_url VARCHAR(1000) DEFAULT ''"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE channels ADD COLUMN logo_icon VARCHAR(100) DEFAULT 'fas fa-tv'"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE channels MODIFY slug VARCHAR(255) NULL DEFAULT NULL"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE categories ADD COLUMN parent_id INT NULL DEFAULT NULL"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE categories ADD COLUMN icon VARCHAR(100) DEFAULT 'fas fa-th-large'"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE categories ADD COLUMN description TEXT"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE categories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1"); } catch(PDOException $e) {}

$stats=[];
$stats['cats']=$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$stats['channels']=$pdo->query("SELECT COUNT(*) FROM channels")->fetchColumn();
$stats['views']=$pdo->query("SELECT COALESCE(SUM(views_count),0) FROM channels")->fetchColumn();
try{$stats['series']=$pdo->query("SELECT COUNT(*) FROM series")->fetchColumn();}catch(PDOException $e){$stats['series']=0;}
try{$stats['users']=$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();}catch(PDOException $e){$stats['users']=1;}

$categories=$pdo->query("SELECT c.id,c.name,c.parent_id,c.icon,c.description,COALESCE(c.display_order,0) as display_order,COALESCE(c.is_active,1) as is_active,COUNT(ch.id) as channel_count FROM categories c LEFT JOIN channels ch ON c.id=ch.category_id GROUP BY c.id,c.name,c.parent_id,c.icon,c.description,c.display_order,c.is_active ORDER BY COALESCE(c.display_order,0),c.id")->fetchAll(PDO::FETCH_ASSOC);
$channels=$pdo->query("SELECT ch.*,c.name as cat_name FROM channels ch LEFT JOIN categories c ON ch.category_id=c.id ORDER BY ch.category_id,ch.display_order,ch.id")->fetchAll(PDO::FETCH_ASSOC);

$os_logged=!empty($_SESSION['os_token']);
$os_user=$_SESSION['os_username']??'';


// ══ جلب دور المستخدم الحالي ══
$_admin_role = 'normal';
$_admin_sections = [];
$_admin_user_id = 0;
$_admin_display = $_SESSION['admin_username'] ?? 'مدير';
try {
    $__au_stmt = $pdo->prepare("SELECT id, role, allowed_sections, display_name FROM admin_users WHERE username = ? AND is_active = 1");
    $__au_stmt->execute([$_SESSION['admin_username'] ?? '']);
    $__au_row = $__au_stmt->fetch(PDO::FETCH_ASSOC);
    if ($__au_row) {
        $_admin_role = $__au_row['role'];
        $_admin_user_id = $__au_row['id'];
        $_admin_display = $__au_row['display_name'] ?: ($_SESSION['admin_username'] ?? '');
        $_admin_sections = json_decode($__au_row['allowed_sections'] ?: '[]', true) ?: [];
        // تحديث وقت آخر دخول
        $pdo->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?")->execute([$__au_row['id']]);
    }
    $_SESSION['admin_role'] = $_admin_role;
    $_SESSION['admin_sections'] = $_admin_sections;
    $_SESSION['admin_user_id'] = $_admin_user_id;
} catch(PDOException $e) {}

// قائمة كل المستخدمين المسؤولين (لاستعمالها لاحقاً)
$_all_admin_users = [];
try {
    $_all_admin_users = $pdo->query("SELECT id, username, display_name, role, allowed_sections, is_active, created_at, last_login FROM admin_users ORDER BY FIELD(role,'administrator','super','normal','custom'), id")->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {}


$all_folders_list = $pdo->query("SELECT id, name FROM series ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<?php
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar','en','tr'])) { $_SESSION['lang'] = $_GET['lang']; }
$__cur_lang = $_SESSION['lang'] ?? 'ar';
$__dir = ($__cur_lang === 'ar') ? 'rtl' : 'ltr';
$__lang_file = __DIR__ . '/lang/lang_' . $__cur_lang . '.php';
$t = file_exists($__lang_file) ? require $__lang_file : [];
if(!is_array($t)) $t = [];
?>
<html lang="<?= $__cur_lang ?>" dir="<?= $__dir ?>">

<head>
    <!-- تسريع التحميل الخاطف للوحة التحكم (مضاف برمجياً) -->
    <link rel="dns-prefetch" href="https://fonts.googleapis.com">
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" as="style">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style">

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SHASHITY PRO</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--red:#E50914;--redg:rgba(229,9,20,.35);--gold:#F5A623;--s0:#0a0a0a;--s1:#111;--s2:#1a1a1a;--s3:#242424;--s4:#2e2e2e;--br:rgba(255,255,255,.07);--brh:rgba(255,255,255,.14);--t1:#fff;--t2:#b3b3b3;--t3:#737373;--sw:260px;--th:68px;--r1:6px;--r2:12px;--r3:20px;--ease:cubic-bezier(.4,0,.2,1)}
*,::before,::after{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:'Tajawal',sans-serif;background:var(--s0);color:var(--t1);min-height:100vh;overflow-x:hidden}
a{color:inherit;text-decoration:none}
.sidebar{position:fixed;right:0;top:0;width:var(--sw);height:100vh;background:var(--s1);border-left:1px solid var(--br);display:flex;flex-direction:column;z-index:100;transition:transform .3s var(--ease)}
/* Sidebar nav item highlighted indicator animation */
.si{position:relative;overflow:hidden}
.si::after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(229,9,20,0),rgba(229,9,20,.06));opacity:0;transition:opacity .2s}
.si:hover::after{opacity:1}
.si-ripple{pointer-events:none;position:absolute;border-radius:50%;background:rgba(229,9,20,.2);transform:scale(0);animation:ripple-anim .5s linear;}
@keyframes ripple-anim{to{transform:scale(4);opacity:0}}
.sidebar::after{content:'';position:absolute;top:0;right:0;left:0;height:3px;background:var(--red)}
.sbrand{padding:26px 20px 22px;border-bottom:1px solid var(--br);display:flex;align-items:center;gap:12px}
.sbrand-icon{width:40px;height:40px;background:var(--red);border-radius:var(--r1);display:flex;align-items:center;justify-content:center;font-size:1.1rem;box-shadow:0 0 20px var(--redg);flex-shrink:0}
.sbrand-name{font-size:1.05rem;font-weight:800}
.sbrand-sub{font-size:.65rem;color:var(--t3);text-transform:uppercase;letter-spacing:.12em}
.snav{flex:1;overflow-y:auto;padding:12px 10px}
.snav::-webkit-scrollbar{width:3px}.snav::-webkit-scrollbar-thumb{background:var(--s4);border-radius:2px}
.snl{font-size:.62rem;font-weight:700;color:var(--t3);letter-spacing:.15em;text-transform:uppercase;padding:14px 12px 6px}
.si{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:var(--r1);color:var(--t2);font-size:.875rem;font-weight:500;cursor:pointer;border:none;background:none;width:100%;text-align:right;transition:all .18s var(--ease);position:relative;margin-bottom:1px}
.si:hover{background:var(--s3);color:var(--t1)}
.si.on{background:rgba(229,9,20,.12);color:var(--t1)}
.si.on::before{content:'';position:absolute;right:0;top:20%;bottom:20%;width:3px;background:var(--red);border-radius:2px 0 0 2px}
.si.on .si-ic{color:var(--red)}
.si-ic{width:17px;text-align:center;font-size:.8rem;flex-shrink:0}
.sfoot{padding:14px 10px;border-top:1px solid var(--br)}
.slogout{display:flex;align-items:center;gap:11px;width:100%;padding:10px 12px;background:transparent;border:1px solid var(--br);border-radius:var(--r1);color:var(--t2);font-family:'Tajawal',sans-serif;font-size:.875rem;font-weight:500;cursor:pointer;transition:all .18s;text-align:right}
.slogout:hover{background:rgba(229,9,20,.08);border-color:rgba(229,9,20,.35);color:#ff6b6b}
.main{margin-right:var(--sw);min-height:100vh;display:flex;flex-direction:column;transition:margin .3s var(--ease)}
.topbar{height:var(--th);background:rgba(10,10,10,.9);backdrop-filter:blur(20px);border-bottom:1px solid var(--br);display:flex;align-items:center;justify-content:space-between;padding:0 32px;position:sticky;top:0;z-index:90}
.tbtitle{font-size:1rem;font-weight:700}
.tbr{display:flex;align-items:center;gap:18px}
.mob-menu-btn{display:none;width:38px;height:38px;background:var(--s2);border:1px solid var(--br);border-radius:var(--r1);color:var(--t2);font-size:.9rem;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:all .2s var(--ease);position:relative;overflow:hidden}
.mob-menu-btn:hover{background:var(--s3);border-color:var(--brh);color:var(--t1)}
.mob-menu-btn .ham-icon{display:flex;flex-direction:column;gap:5px;align-items:center;justify-content:center;width:18px;height:14px;position:relative}
.mob-menu-btn .ham-icon span{display:block;width:18px;height:2px;background:currentColor;border-radius:2px;transition:all .3s cubic-bezier(.23,1,.32,1);transform-origin:center}
.mob-menu-btn.active .ham-icon span:nth-child(1){transform:translateY(7px) rotate(45deg)}
.mob-menu-btn.active .ham-icon span:nth-child(2){opacity:0;transform:scaleX(0)}
.mob-menu-btn.active .ham-icon span:nth-child(3){transform:translateY(-7px) rotate(-45deg)}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:99;backdrop-filter:blur(3px)}
.sb-overlay.on{display:block}
.lic-b{display:flex;align-items:center;gap:9px;background:var(--s2);border:1px solid var(--br);border-radius:100px;padding:5px 14px 5px 10px;font-size:.78rem;color:var(--t2)}
.lic-dot{width:7px;height:7px;background:#00D084;border-radius:50%;box-shadow:0 0 8px #00D084}
.uavt{width:34px;height:34px;background:var(--red);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;box-shadow:0 0 12px var(--redg);flex-shrink:0}
.pcont{flex:1;padding:32px;max-width:1440px;width:100%}
.sec{display:none; content-visibility: hidden;} .sec.on{display:block; content-visibility: visible;}
table { content-visibility: auto; contain-intrinsic-size: 1000px; }
@keyframes fu{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
.shdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:10px}
.stitle{font-size:1.5rem;font-weight:800;letter-spacing:-.02em}
.stitle span{color:var(--red)}
.al{display:flex;align-items:center;gap:10px;padding:13px 18px;border-radius:var(--r2);margin-bottom:20px;font-size:.875rem;font-weight:600;animation:sd .3s var(--ease)}
@keyframes sd{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.al-s{background:rgba(0,208,132,.1);border:1px solid rgba(0,208,132,.3);color:#00D084}
.al-e{background:rgba(229,9,20,.1);border:1px solid rgba(229,9,20,.3);color:#ff6b6b}
.al-i{background:rgba(76,201,240,.1);border:1px solid rgba(76,201,240,.3);color:#4CC9F0}
.sgrid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:28px}
.sc{background:var(--s1);border:1px solid var(--br);border-radius:var(--r2);padding:22px;transition:border-color .2s,transform .2s}
.sc:hover{border-color:var(--brh);transform:translateY(-2px)}
.sc-ic{width:38px;height:38px;border-radius:var(--r1);display:flex;align-items:center;justify-content:center;font-size:.95rem;margin-bottom:14px}
.r .sc-ic{background:rgba(229,9,20,.14);color:var(--red)}
.g .sc-ic{background:rgba(0,208,132,.14);color:#00D084}
.go .sc-ic{background:rgba(245,166,35,.14);color:var(--gold)}
.b .sc-ic{background:rgba(76,201,240,.14);color:#4CC9F0}
.p .sc-ic{background:rgba(179,107,255,.14);color:#B36BFF}
.sc-v{font-size:2rem;font-weight:900;line-height:1;margin-bottom:3px}
.sc-l{font-size:.74rem;color:var(--t3);font-weight:500;text-transform:uppercase;letter-spacing:.05em}
.dgrid{display:grid;grid-template-columns:1fr 310px;gap:18px}
.card{background:var(--s1);border:1px solid var(--br);border-radius:var(--r2);overflow:hidden}
.chdr{padding:18px 22px;border-bottom:1px solid var(--br);display:flex;align-items:center;justify-content:space-between}
.ctitle{font-size:.9rem;font-weight:700}
.cbody{padding:18px 22px}
.qa-list{display:flex;flex-direction:column;gap:7px}
.qa{display:flex;align-items:center;gap:12px;padding:12px 14px;background:var(--s2);border:1px solid var(--br);border-radius:var(--r1);cursor:pointer;transition:all .18s var(--ease);color:var(--t2);font-size:.855rem;font-weight:500}
.qa:hover{background:var(--s3);border-color:var(--brh);color:var(--t1);transform:translateX(-2px)}
.qa-ic{width:32px;height:32px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0}
.qa.r .qa-ic{background:rgba(229,9,20,.14);color:var(--red)}
.qa.g .qa-ic{background:rgba(0,208,132,.14);color:#00D084}
.qa.b .qa-ic{background:rgba(76,201,240,.14);color:#4CC9F0}
.qa.go .qa-ic{background:rgba(245,166,35,.14);color:var(--gold)}
.qa.p .qa-ic{background:rgba(179,107,255,.14);color:#B36BFF}
.ri{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--br)}
.ri:last-child{border-bottom:none}
.ri-ic{width:36px;height:36px;background:var(--s3);border-radius:7px;display:flex;align-items:center;justify-content:center;color:var(--t3);flex-shrink:0}
.ri-name{font-size:.855rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ri-meta{font-size:.74rem;color:var(--t3);margin-top:1px}
.tw{background:var(--s1);border:1px solid var(--br);border-radius:var(--r2);overflow:hidden}
.tt{padding:14px 18px;border-bottom:1px solid var(--br);display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.tsrch{display:flex;align-items:center;gap:7px;background:var(--s2);border:1px solid var(--br);border-radius:var(--r1);padding:7px 12px;flex:1;max-width:260px}
.tsrch i{color:var(--t3);font-size:.75rem}
.tsrch input{background:none;border:none;outline:none;color:var(--t1);font-family:'Tajawal',sans-serif;font-size:.855rem;flex:1;min-width:0}
table{width:100%;border-collapse:collapse}
thead tr{background:var(--s2)}
th{padding:11px 14px;font-size:.7rem;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.08em;text-align:right;white-space:nowrap}
td{padding:12px 14px;font-size:.855rem;color:var(--t2);border-top:1px solid var(--br);vertical-align:middle}
tr:hover td{background:rgba(255,255,255,.02)}
.cn{display:flex;align-items:center;gap:10px}
.nic{width:34px;height:34px;background:var(--s3);border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:.9rem;color:var(--t2);flex-shrink:0}
.bdg{display:inline-flex;align-items:center;padding:2px 9px;border-radius:100px;font-size:.72rem;font-weight:600}
.bc{background:rgba(76,201,240,.1);color:#4CC9F0;border:1px solid rgba(76,201,240,.2)}
.bp{background:rgba(179,107,255,.1);color:#B36BFF;border:1px solid rgba(179,107,255,.2)}
.bg{background:rgba(0,208,132,.1);color:#00D084;border:1px solid rgba(0,208,132,.2)}
.acts{display:flex;align-items:center;gap:5px;white-space:nowrap}
.ib{width:30px;height:30px;border-radius:var(--r1);border:1px solid var(--br);background:var(--s2);color:var(--t2);font-size:.75rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s}
.ib:hover{background:var(--s3);border-color:var(--brh);color:var(--t1)}
.ib.pl:hover{background:rgba(0,208,132,.12);color:#00D084;border-color:rgba(0,208,132,.3)}
.ib.ed:hover{background:rgba(245,166,35,.12);color:var(--gold);border-color:rgba(245,166,35,.3)}
.ib.dl:hover{background:rgba(229,9,20,.12);color:var(--red);border-color:rgba(229,9,20,.3)}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:var(--r1);font-family:'Tajawal',sans-serif;font-size:.855rem;font-weight:700;cursor:pointer;border:none;transition:all .18s var(--ease);white-space:nowrap}
.btn-p{background:var(--red);color:#fff;box-shadow:0 4px 14px var(--redg)}
.btn-p:hover{background:#f01020;transform:translateY(-1px)}
.btn-p:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-g{background:var(--s3);color:var(--t2);border:1px solid var(--br)}
.btn-g:hover{background:var(--s4);color:var(--t1)}
.btn-s{background:#00D084;color:#fff}
.btn-s:hover{background:#00b872;transform:translateY(-1px)}
.btn-b{background:rgba(76,201,240,.14);color:#4CC9F0;border:1px solid rgba(76,201,240,.25)}
.btn-b:hover{background:rgba(76,201,240,.25)}
.btn-v{background:rgba(179,107,255,.14);color:#B36BFF;border:1px solid rgba(179,107,255,.25)}
.btn-v:hover{background:rgba(179,107,255,.25)}
.bsm{padding:6px 12px;font-size:.78rem}
.srgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:10px}
.src{background:var(--s1);border:1px solid var(--br);border-radius:var(--r2);overflow:hidden;display:flex;transition:all .18s var(--ease)}
.src:hover{border-color:var(--brh);transform:translateY(-1px)}
.src-poster{width:56px;height:68px;background:var(--s3);display:flex;align-items:center;justify-content:center;color:var(--t3);font-size:1.4rem;overflow:hidden;flex-shrink:0}
.src-poster img{width:100%;height:100%;object-fit:cover}
.src-body{flex:1;padding:9px 12px;min-width:0;cursor:pointer}
.src-name{font-size:.88rem;font-weight:700;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:3px}
.src-meta{font-size:.74rem;color:var(--t3);display:flex;gap:8px;flex-wrap:wrap}
.src-acts{display:flex;flex-direction:column;gap:3px;padding:7px;justify-content:center;border-right:1px solid var(--br)}
.uz{border:2px dashed var(--br);border-radius:var(--r2);padding:36px 20px;text-align:center;cursor:pointer;transition:all .22s;position:relative}
.uz.dz{border-color:var(--red);background:rgba(229,9,20,.04)}
.uz:hover{border-color:var(--brh)}
.uz i{font-size:2rem;color:var(--t3);margin-bottom:12px;display:block;transition:color .2s}
.uz:hover i{color:var(--red)}
.uz h3{font-size:.95rem;font-weight:700;margin-bottom:5px}
.uz p{font-size:.78rem;color:var(--t3)}
.uz input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer}
.pw{background:var(--s3);border-radius:100px;height:5px;overflow:hidden}
.pb{height:100%;background:var(--red);border-radius:100px;width:0;transition:width .3s}
.fg{margin-bottom:18px}
.fl{display:block;font-size:.78rem;font-weight:700;color:var(--t2);letter-spacing:.05em;text-transform:uppercase;margin-bottom:7px}
.fi{width:100%;padding:10px 13px;background:var(--s2);border:1px solid var(--br);border-radius:var(--r1);color:var(--t1);font-family:'Tajawal',sans-serif;font-size:.875rem;outline:none;transition:border-color .18s,box-shadow .18s}
.fi:focus{border-color:var(--red);box-shadow:0 0 0 3px rgba(229,9,20,.1)}
.fi::placeholder{color:var(--t3)}
.fs{width:100%;padding:10px 13px;background:var(--s2);border:1px solid var(--br);border-radius:var(--r1);color:var(--t1);font-family:'Tajawal',sans-serif;font-size:.875rem;cursor:pointer;outline:none}
.fs:focus{border-color:var(--red)}
.fs option{background:var(--s2)}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.mbd{position:fixed;inset:0;background:rgba(0,0,0,.88);backdrop-filter:blur(8px);z-index:1000;display:none;align-items:center;justify-content:center;padding:20px}
.mbd.op{display:flex}
.mbox{background:var(--s1);border:1px solid var(--brh);border-radius:var(--r3);width:100%;max-width:560px;max-height:92vh;overflow:hidden;display:flex;flex-direction:column;animation:mp .28s var(--ease);box-shadow:0 40px 80px rgba(0,0,0,.7)}
.mbox.w{max-width:660px}
.mbox.xw{max-width:820px}
@keyframes mp{from{opacity:0;transform:scale(.92) translateY(18px)}to{opacity:1;transform:scale(1) translateY(0)}}
.mhd{padding:18px 22px;border-bottom:1px solid var(--br);display:flex;align-items:center;justify-content:space-between;background:var(--s2);flex-shrink:0}
.mhd-title{font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:9px}
.mhd-title i{color:var(--red)}
.mclose{width:30px;height:30px;background:var(--s3);border:1px solid var(--br);border-radius:var(--r1);color:var(--t2);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.78rem;transition:all .15s}
.mclose:hover{background:rgba(229,9,20,.12);color:var(--red)}
.mbox>form{display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden}
.mbody{padding:22px;overflow-y:auto;flex:1;min-height:0}
.mbody::-webkit-scrollbar{width:3px}.mbody::-webkit-scrollbar-thumb{background:var(--s4)}
.mfooter{padding:16px 22px;border-top:1px solid var(--br);display:flex;gap:8px;justify-content:flex-end;background:var(--s2);flex-shrink:0}
.vsteps{display:flex;border-radius:var(--r2);overflow:hidden;border:1px solid var(--br);margin-bottom:24px}
.vs{flex:1;display:flex;align-items:center;gap:9px;padding:13px 16px;background:var(--s1);color:var(--t3);font-size:.83rem;font-weight:700;border-left:1px solid var(--br);transition:all .2s;position:relative;cursor:default}
.vs:last-child{border-left:none}
.vs.done{color:#00D084}
.vs.act{background:rgba(229,9,20,.09);color:var(--t1)}
.vs.act::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:var(--red)}
.vs-n{width:24px;height:24px;border-radius:50%;background:var(--s3);display:flex;align-items:center;justify-content:center;font-size:.72rem;flex-shrink:0}
.vs.act .vs-n{background:var(--red)}.vs.done .vs-n{background:#00D084;color:#000}
.vp{display:none}.vp.act{display:block;animation:fu .3s var(--ease) both}
.vc{background:var(--s1);border:1px solid var(--br);border-radius:var(--r2);overflow:hidden;margin-bottom:18px}
.vchd{padding:14px 20px;border-bottom:1px solid var(--br);display:flex;align-items:center;justify-content:space-between;background:var(--s2)}
.vchd-title{font-size:.875rem;font-weight:700;display:flex;align-items:center;gap:9px}
.vchd-title i{color:var(--red)}
.vcbody{padding:20px}
.chip{display:none;align-items:center;gap:10px;padding:12px 14px;background:rgba(0,208,132,.07);border:1px solid rgba(0,208,132,.22);border-radius:var(--r1);margin-top:12px}
.sub-opts{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px}
.so{background:var(--s2);border:2px solid var(--br);border-radius:var(--r2);padding:16px;cursor:pointer;transition:all .2s;text-align:center}
.so:hover{border-color:var(--brh)}
.so.sel{border-color:var(--red);background:rgba(229,9,20,.07)}
.so-ic{font-size:1.5rem;margin-bottom:8px}
.so-lbl{font-size:.855rem;font-weight:700;margin-bottom:2px}
.so-desc{font-size:.72rem;color:var(--t3)}
.srow{display:flex;gap:7px}
.sinp{flex:1;display:flex;align-items:center;gap:7px;background:var(--s2);border:1px solid var(--br);border-radius:var(--r1);padding:9px 12px;transition:border-color .18s;min-width:0}
.sinp:focus-within{border-color:var(--red)}
.sinp i{color:var(--t3);font-size:.75rem}
.sinp input{background:none;border:none;outline:none;color:var(--t1);font-family:'Tajawal',sans-serif;font-size:.855rem;flex:1;min-width:0}
.lsel{padding:9px 11px;background:var(--s2);border:1px solid var(--br);border-radius:var(--r1);color:var(--t1);font-family:'Tajawal',sans-serif;font-size:.78rem;cursor:pointer;outline:none}
.sub-rl{display:none;flex-direction:column;gap:6px;margin-top:12px;max-height:360px;overflow-y:auto}
.sri{display:flex;align-items:center;gap:9px;padding:10px 12px;background:var(--s2);border:1px solid var(--br);border-radius:var(--r1);cursor:pointer;transition:all .15s}
.sri:hover{border-color:var(--brh);background:var(--s3)}
.sri.sel{border-color:#00D084;background:rgba(0,208,132,.07)}
.sri-main{flex:1;min-width:0}
.sri-title{font-weight:700;font-size:.83rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sri-meta{font-size:.72rem;color:var(--t3);margin-top:2px;display:flex;gap:6px;flex-wrap:wrap}
.stag{display:inline-flex;padding:1px 6px;border-radius:100px;font-size:.66rem;font-weight:700}
.stag-l{background:rgba(76,201,240,.12);color:#4CC9F0}
.stag-ai{background:rgba(179,107,255,.12);color:#B36BFF}
.sub-chip{display:none;align-items:center;gap:9px;padding:10px 13px;background:rgba(0,208,132,.07);border:1px solid rgba(0,208,132,.25);border-radius:var(--r1);margin-top:10px;font-size:.83rem}
.sub-chip i{color:#00D084}
.merge-sum{background:var(--s2);border:1px solid var(--br);border-radius:var(--r1);padding:14px 16px;margin-bottom:16px}
.mr{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--br)}
.mr:last-child{border-bottom:none}
.ml{font-size:.73rem;color:var(--t3);font-weight:700;width:75px;flex-shrink:0;text-transform:uppercase}
.mv{font-size:.855rem;color:var(--t1);flex:1;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.vnavb{display:flex;justify-content:space-between;align-items:center;margin-top:18px;padding-top:16px;border-top:1px solid var(--br)}
.ffnote{background:rgba(0,208,132,.07);border:1px solid rgba(0,208,132,.2);border-radius:var(--r1);padding:12px 16px;margin-bottom:14px;font-size:.82rem;color:var(--t2);display:none}
.ffnote i{color:#00D084;margin-left:6px}
.vmgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px}
.vmc{background:var(--s1);border:1px solid var(--br);border-radius:var(--r2);overflow:hidden;display:flex;flex-direction:column;transition:border-color .18s,transform .18s}
.vmc:hover{border-color:var(--brh);transform:translateY(-2px)}
.vmt{position:relative;background:#000;aspect-ratio:16/9;overflow:hidden;cursor:pointer;display:flex;align-items:center;justify-content:center}
.vmt video{width:100%;height:100%;object-fit:cover;opacity:.65;pointer-events:none}
.vmt-ic{position:absolute;width:44px;height:44px;background:rgba(229,9,20,.85);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem}
.vmbdg{position:absolute;top:7px;right:7px;font-size:.62rem;font-weight:800;padding:2px 8px;border-radius:100px;text-transform:uppercase}
.vminfo{padding:12px 14px;flex:1}
.vmname{font-size:.875rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:4px}
.vmmeta{font-size:.72rem;color:var(--t3);display:flex;gap:9px}
.vmacts{display:flex;gap:5px;padding:9px 14px;border-top:1px solid var(--br);background:var(--s2)}
.vmb{flex:1;display:flex;align-items:center;justify-content:center;gap:4px;padding:7px 5px;border-radius:var(--r1);border:1px solid var(--br);background:var(--s3);color:var(--t2);font-family:'Tajawal',sans-serif;font-size:.74rem;font-weight:700;cursor:pointer;transition:all .15s}
.vmb.pl:hover{background:rgba(0,208,132,.12);color:#00D084;border-color:rgba(0,208,132,.3)}
.vmb.sv:hover{background:rgba(229,9,20,.12);color:var(--red);border-color:rgba(229,9,20,.3)}
.vmb.dl:hover{background:rgba(229,9,20,.12);color:var(--red);border-color:rgba(229,9,20,.3)}
.vmb.sub:hover{background:rgba(0,208,132,.12);color:#00D084;border-color:rgba(0,208,132,.3)}
.vmb.mv:hover{background:rgba(245,166,35,.12);color:var(--gold);border-color:rgba(245,166,35,.3)}
#pm{position:fixed;inset:0;background:rgba(0,0,0,.97);backdrop-filter:blur(24px);z-index:2000;display:none;align-items:center;justify-content:center;padding:20px}
#pm.op{display:flex;animation:fi .22s var(--ease)}
@keyframes fi{from{opacity:0}to{opacity:1}}
.pbox{background:#000;border-radius:var(--r3);overflow:hidden;width:100%;max-width:1040px;border:1px solid var(--brh);box-shadow:0 0 80px rgba(229,9,20,.2),0 40px 80px rgba(0,0,0,.8)}
.phd{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:var(--s1);border-bottom:1px solid var(--br);gap:10px}
.phd-l{display:flex;align-items:center;gap:9px;flex:1;min-width:0}
.ptitle{font-size:.875rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pdot{width:7px;height:7px;background:var(--red);border-radius:50%;box-shadow:0 0 8px var(--red);animation:bk 1.5s ease infinite;flex-shrink:0}
.pdot.ok{background:#00D084;animation:none}.pdot.err{background:#f44;animation:none}
@keyframes bk{0%,100%{opacity:1}50%{opacity:.25}}
.pwrap{position:relative;background:#000}
#tv{width:100%;height:500px;display:block;background:#000}
.pload{position:absolute;inset:0;background:#000;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;z-index:5;transition:opacity .3s}
.pload.hid{opacity:0;pointer-events:none}
.pspin{width:44px;height:44px;border:3px solid rgba(229,9,20,.2);border-top-color:var(--red);border-radius:50%;animation:sp .8s linear infinite}
@keyframes sp{to{transform:rotate(360deg)}}
.perr{position:absolute;inset:0;background:#000;display:none;flex-direction:column;align-items:center;justify-content:center;gap:12px;z-index:6;text-align:center;padding:40px}
.perr.sh{display:flex}
.pft{display:flex;align-items:center;justify-content:space-between;padding:9px 18px;background:var(--s1);border-top:1px solid var(--br);gap:10px;flex-wrap:wrap}
.purl{font-size:.7rem;color:var(--t3);font-family:'Courier New',monospace;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pbtns{display:flex;gap:7px}
.pbtn{display:flex;align-items:center;gap:5px;padding:5px 11px;background:var(--s3);border:1px solid var(--br);border-radius:var(--r1);color:var(--t2);font-family:'Tajawal',sans-serif;font-size:.75rem;font-weight:600;cursor:pointer;transition:all .15s}
.pbtn:hover{background:var(--s4);color:var(--t1)}
.psubbar{display:flex;align-items:center;gap:7px;padding:7px 18px;background:rgba(76,201,240,.05);border-top:1px solid rgba(76,201,240,.12);font-size:.75rem;color:var(--t2)}
.tgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.tc{background:var(--s1);border:1px solid var(--br);border-radius:var(--r2);padding:24px 20px;cursor:pointer;transition:all .2s}
.tc:hover{border-color:var(--brh);transform:translateY(-2px)}
.tc-ic{width:42px;height:42px;border-radius:var(--r1);display:flex;align-items:center;justify-content:center;font-size:1rem;margin-bottom:14px}
.tc.r .tc-ic{background:rgba(229,9,20,.14);color:var(--red)}
.tc.g .tc-ic{background:rgba(0,208,132,.14);color:#00D084}
.tc.go .tc-ic{background:rgba(245,166,35,.14);color:var(--gold)}
.tc.b .tc-ic{background:rgba(76,201,240,.14);color:#4CC9F0}
.tc.p .tc-ic{background:rgba(179,107,255,.14);color:#B36BFF}
.tc-name{font-size:.95rem;font-weight:700;margin-bottom:5px}
.tc-desc{font-size:.8rem;color:var(--t3);line-height:1.5}
.sw-wrap{max-width:540px}
.swc{background:var(--s1);border:1px solid var(--br);border-radius:var(--r2);overflow:hidden;margin-bottom:18px}
.swc-hd{padding:18px 22px;border-bottom:1px solid var(--br)}
.swc-title{font-size:.9rem;font-weight:700}
.swc-body{padding:22px}
.info-b{background:rgba(245,166,35,.07);border:1px solid rgba(245,166,35,.18);border-radius:var(--r1);padding:14px;margin-top:18px}
.info-b-title{font-size:.78rem;font-weight:700;color:var(--gold);margin-bottom:7px}
.bkgrid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.bkc{background:var(--s1);border:1px solid var(--br);border-radius:var(--r2);padding:24px}
.bkc-title{font-size:.95rem;font-weight:700;margin-bottom:18px;display:flex;align-items:center;gap:9px}
.bkc-title i{color:var(--red)}
.ep-item{display:flex;align-items:center;gap:9px;padding:9px 11px;background:var(--s2);border:1px solid var(--br);border-radius:var(--r1);font-size:.8rem;margin-bottom:6px}
.ep-nbdg{width:26px;height:26px;border-radius:50%;background:var(--red);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:800;flex-shrink:0}
.ep-stat{margin-right:auto;font-size:.72rem}
.ep-stat.ok{color:#00D084}.ep-stat.err{color:#ff6b6b}.ep-stat.up{color:var(--gold)}
.os-info{background:rgba(76,201,240,.06);border:1px solid rgba(76,201,240,.18);border-radius:var(--r1);padding:11px 14px;margin-bottom:12px;font-size:.78rem;color:var(--t2);line-height:1.7}
.empty{text-align:center;padding:50px 20px;color:var(--t3)}
.empty i{font-size:2.2rem;margin-bottom:10px;display:block}
.sp{display:inline-block;width:13px;height:13px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:sp .7s linear infinite;vertical-align:middle}
.sp-g{border-color:rgba(0,208,132,.3);border-top-color:#00D084}
.orsep{display:flex;align-items:center;gap:9px;margin:12px 0;color:var(--t3);font-size:.75rem}
.orsep::before,.orsep::after{content:'';flex:1;height:1px;background:var(--br)}
.etabs{display:flex;background:var(--s2);padding:3px;border-radius:var(--r1);margin-bottom:14px}
.etab{flex:1;padding:7px 10px;background:none;border:none;border-radius:6px;color:var(--t3);font-family:'Tajawal',sans-serif;font-size:.8rem;font-weight:600;cursor:pointer;transition:all .15s}
.etab.on{background:var(--s4);color:var(--t1)}
.tmdb-results{position:absolute;z-index:500;background:var(--s2);border:1px solid var(--brh);border-radius:var(--r2);width:100%;max-height:220px;overflow-y:auto;display:none;box-shadow:0 8px 24px rgba(0,0,0,.6);top:calc(100% + 4px);right:0}
.tmdb-item{display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--br);transition:background .15s}
.tmdb-item:last-child{border-bottom:none}
.tmdb-item:hover{background:var(--s3)}
.tmdb-item img{width:34px;height:48px;object-fit:cover;border-radius:4px;flex-shrink:0;background:var(--s3)}
.tmdb-item-info{flex:1;min-width:0}
.tmdb-item-title{font-size:.83rem;font-weight:700;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tmdb-item-year{font-size:.72rem;color:var(--t3)}
.tmdb-fetch-btn{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;background:rgba(245,166,35,.15);border:1px solid rgba(245,166,35,.3);border-radius:var(--r1);color:var(--gold);font-family:'Tajawal',sans-serif;font-size:.72rem;font-weight:700;cursor:pointer;transition:all .15s;white-space:nowrap;flex-shrink:0}
.tmdb-fetch-btn:hover{background:rgba(245,166,35,.28)}
.fg-rel{position:relative}
.tmdb-poster-prev{display:none;margin-top:8px}
.tmdb-poster-prev img{height:80px;border-radius:var(--r1);border:2px solid rgba(0,208,132,.3)}
.image-preview{display:none;margin-top:8px}
.image-preview img{width:80px;height:80px;object-fit:cover;border-radius:var(--r1);border:2px solid var(--br)}
.image-upload-row{display:flex;gap:8px;align-items:flex-start;margin-bottom:8px}
.upload-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 13px;background:var(--s3);border:1px solid var(--br);border-radius:var(--r1);cursor:pointer;font-size:.78rem;font-weight:700;color:var(--t2);transition:all .15s;white-space:nowrap}
.upload-btn:hover{border-color:var(--brh);color:var(--t1)}
.upload-btn i{color:var(--red)}

.tmdb-info-wrap { display: flex; gap: 16px; flex-wrap: wrap; }
.tmdb-info-poster { width: 130px; border-radius: var(--r2); flex-shrink: 0; border: 1px solid var(--br); background: var(--s3); }
.tmdb-info-details { flex: 1; min-width: 200px; }
.tmdb-info-title { font-size: 1.2rem; font-weight: 800; color: var(--t1); margin-bottom: 8px; }
.tmdb-info-meta { font-size: 0.85rem; color: var(--t3); margin-bottom: 12px; display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
.tmdb-info-overview { font-size: 0.9rem; color: var(--t2); line-height: 1.7; background: var(--s2); padding: 14px; border-radius: var(--r1); border: 1px solid var(--br); }
.tmdb-info-btn { background: rgba(76,201,240,.1); color: #4CC9F0; border: 1px solid rgba(76,201,240,.2); width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; flex-shrink: 0; margin-right: auto; }
.tmdb-info-btn:hover { background: #4CC9F0; color: #000; transform: scale(1.05); }

@media(max-width:1024px){:root{--sw:240px}.sgrid{grid-template-columns:repeat(3,1fr)}.dgrid{grid-template-columns:1fr}.tgrid{grid-template-columns:repeat(2,1fr)}.bkgrid{grid-template-columns:1fr}#tv{height:380px}}
@media(max-width:768px){:root{--sw:260px;--th:58px}.sidebar{transform:translateX(100%);box-shadow:none}.sidebar.open{transform:translateX(0);box-shadow:-20px 0 60px rgba(0,0,0,.8)}.main{margin-right:0}.mob-menu-btn{display:flex}.topbar{padding:0 16px;gap:10px}.tbtitle{font-size:.9rem}.lic-b{display:none}.tbr{gap:10px}.tbr > span{display:none}.pcont{padding:16px}.sgrid{grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:20px}.sc{padding:16px}.sc-v{font-size:1.6rem}.dgrid{grid-template-columns:1fr;gap:14px}.stitle{font-size:1.2rem}.tw{overflow-x:auto}table{min-width:600px}.row2{grid-template-columns:1fr}.tgrid{grid-template-columns:repeat(2,1fr);gap:10px}.tc{padding:18px 14px}.bkgrid{grid-template-columns:1fr;gap:14px}.sw-wrap{max-width:100%}.srgrid{grid-template-columns:1fr}.vsteps{flex-direction:column}.vs{border-left:none;border-bottom:1px solid var(--br)}.vs:last-child{border-bottom:none}.sub-opts{grid-template-columns:1fr}#tv{height:240px}.pbox{border-radius:var(--r2)}.pft{flex-direction:column;align-items:flex-start;gap:8px}.pbtns{flex-wrap:wrap}.mbd{padding:10px}.mbox,.mbox.w,.mbox.xw{max-width:100%;max-height:96vh;border-radius:var(--r2)}.mbody{padding:16px}.mhd{padding:14px 16px}.mfooter{padding:12px 16px}.shdr{flex-direction:row;flex-wrap:wrap;gap:8px}.shdr > div{flex-wrap:wrap}#srFilterBar{flex-direction:column;align-items:stretch}#srFilterBar select,#srFilterBar .tsrch{max-width:100%;width:100%}.srow{flex-wrap:wrap}.lsel{width:100%}.vmgrid{grid-template-columns:1fr}.tt{flex-direction:column;align-items:stretch;gap:8px}.tsrch{max-width:100%}#srBreadcrumb{flex-wrap:wrap}}
@media(max-width:480px){.sgrid{grid-template-columns:1fr 1fr;gap:8px}.sc{padding:14px 12px}.sc-v{font-size:1.4rem}.sc-ic{width:32px;height:32px;font-size:.8rem;margin-bottom:10px}.tgrid{grid-template-columns:1fr 1fr}.tc{padding:14px 10px}.tc-ic{width:36px;height:36px;margin-bottom:10px}.btn{font-size:.78rem;padding:8px 12px;gap:5px}.stitle{font-size:1.1rem}}
/* ═══ Multi-Source Search (TMDB/AniList/OMDb) ═══ */
.source-tabs{display:flex;background:var(--s2);padding:3px;border-radius:var(--r1);margin-bottom:14px;border:1px solid var(--br)}
.source-tab{flex:1;padding:8px 10px;background:none;border:none;border-radius:5px;color:var(--t3);font-family:'Tajawal',sans-serif;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .18s;display:flex;align-items:center;justify-content:center;gap:6px}
.source-tab:hover{color:var(--t2);background:var(--s3)}
.source-tab.active{color:var(--t1);background:var(--s4)}
.source-tab.active.tmdb-active{background:rgba(1,180,228,.15);color:#01B4E4}
.source-tab.active.anilist-active{background:rgba(76,201,240,.15);color:#4CC9F0}
.source-tab.active.omdb-active{background:rgba(245,166,35,.15);color:var(--gold)}
.source-tab i{font-size:.7rem}
.media-search-wrap{position:relative}
.media-search-row{display:flex;gap:8px;align-items:center}
.media-search-row .fi{flex:1}
.media-search-results{position:absolute;z-index:500;background:var(--s2);border:1px solid var(--brh);border-radius:var(--r2);width:100%;max-height:260px;overflow-y:auto;display:none;box-shadow:0 8px 24px rgba(0,0,0,.6);top:calc(100% + 4px);right:0}
.media-search-results::-webkit-scrollbar{width:3px}.media-search-results::-webkit-scrollbar-thumb{background:var(--s4)}
.media-result-item{display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--br);transition:background .15s}
.media-result-item:last-child{border-bottom:none}
.media-result-item:hover{background:var(--s3)}
.media-result-item img{width:36px;height:50px;object-fit:cover;border-radius:4px;flex-shrink:0;background:var(--s3)}
.media-result-info{flex:1;min-width:0}
.media-result-title{font-size:.83rem;font-weight:700;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.media-result-meta{font-size:.72rem;color:var(--t3);display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.source-badge{display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:100px;font-size:.62rem;font-weight:800}
.source-badge.tmdb{background:rgba(1,180,228,.12);color:#01B4E4}
.source-badge.anilist{background:rgba(76,201,240,.12);color:#4CC9F0}
.source-badge.omdb{background:rgba(245,166,35,.12);color:var(--gold)}

/* ═══ User Management ═══ */
.usr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}
.usr-card{background:var(--s1);border:1px solid var(--br);border-radius:var(--r2);overflow:hidden;transition:all .18s}
.usr-card:hover{border-color:var(--brh);transform:translateY(-2px)}
.usr-card-hd{padding:16px 18px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--br)}
.usr-avt{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:800;flex-shrink:0}
.usr-avt.admin{background:rgba(229,9,20,.2);color:var(--red)}
.usr-avt.super{background:rgba(245,166,35,.2);color:var(--gold)}
.usr-avt.normal{background:rgba(76,201,240,.2);color:#4CC9F0}
.usr-avt.custom{background:rgba(179,107,255,.2);color:#B36BFF}
.usr-name{font-size:.92rem;font-weight:700;color:var(--t1)}
.usr-uname{font-size:.72rem;color:var(--t3)}
.usr-role-bdg{display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:100px;font-size:.68rem;font-weight:800}
.usr-role-bdg.admin{background:rgba(229,9,20,.12);color:var(--red);border:1px solid rgba(229,9,20,.25)}
.usr-role-bdg.super{background:rgba(245,166,35,.12);color:var(--gold);border:1px solid rgba(245,166,35,.25)}
.usr-role-bdg.normal{background:rgba(76,201,240,.12);color:#4CC9F0;border:1px solid rgba(76,201,240,.25)}
.usr-role-bdg.custom{background:rgba(179,107,255,.12);color:#B36BFF;border:1px solid rgba(179,107,255,.25)}
.usr-card-body{padding:14px 18px}
.usr-meta{font-size:.74rem;color:var(--t3);display:flex;flex-direction:column;gap:4px}
.usr-meta span i{width:18px;text-align:center;margin-left:4px}
.usr-card-ft{display:flex;gap:5px;padding:10px 18px;border-top:1px solid var(--br);background:var(--s2)}
.usr-inactive{opacity:.5;filter:grayscale(.6)}
.perm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-top:12px}
.perm-item{display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--s2);border:2px solid var(--br);border-radius:var(--r1);cursor:pointer;transition:all .18s;user-select:none}
.perm-item:hover{border-color:var(--brh);background:var(--s3)}
.perm-item.on{border-color:#00D084;background:rgba(0,208,132,.06)}
.perm-item .pi-ic{width:32px;height:32px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:.8rem;background:var(--s3);color:var(--t3);transition:all .18s;flex-shrink:0}
.perm-item.on .pi-ic{background:rgba(0,208,132,.15);color:#00D084}
.perm-item .pi-chk{width:18px;height:18px;border-radius:4px;border:2px solid var(--br);display:flex;align-items:center;justify-content:center;font-size:.65rem;color:transparent;transition:all .18s;margin-right:auto;flex-shrink:0}
.perm-item.on .pi-chk{border-color:#00D084;background:#00D084;color:#fff}
.perm-item .pi-name{font-size:.82rem;font-weight:600;color:var(--t2)}
.perm-item.on .pi-name{color:var(--t1)}

/* ══════════════════════════════════════════════════════════════════
   ✨ GLASS THEME — تحسين تأثير الزجاج (Blur أعمق + إضاءة داخلية)
   إضافة فقط — لا يحذف أي شيء.
   ══════════════════════════════════════════════════════════════════ */
@supports ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){

  /* الشريط العلوي — زجاج أعمق وأنقى */
  .topbar{
    background:linear-gradient(180deg,rgba(12,12,14,.82),rgba(8,8,10,.7)) !important;
    -webkit-backdrop-filter:blur(28px) saturate(190%) contrast(105%) !important;
    backdrop-filter:blur(28px) saturate(190%) contrast(105%) !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.05) !important;
  }

  /* طبقة السايدبار — نعومة أكبر */
  .sb-overlay{
    -webkit-backdrop-filter:blur(6px) saturate(120%) !important;
    backdrop-filter:blur(6px) saturate(120%) !important;
  }

  /* النوافذ المنبثقة (modal backdrop) — زجاج فاخر */
  .mbd{
    background:rgba(0,0,0,.72) !important;
    -webkit-backdrop-filter:blur(16px) saturate(160%) contrast(103%) !important;
    backdrop-filter:blur(16px) saturate(160%) contrast(103%) !important;
  }

  /* لوحة الوسائط/المعاينة — زجاج معتّم أنيق */
  #pm{
    background:rgba(0,0,0,.9) !important;
    -webkit-backdrop-filter:blur(30px) saturate(170%) contrast(104%) !important;
    backdrop-filter:blur(30px) saturate(170%) contrast(104%) !important;
  }
}

/* على الشاشات الصغيرة: تخفيف حِدّة الـblur لأداء أفضل */
@media (max-width:640px){
  @supports ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){
    .topbar{
      -webkit-backdrop-filter:blur(20px) saturate(175%) !important;
      backdrop-filter:blur(20px) saturate(175%) !important;
    }
    .mbd,#pm{
      -webkit-backdrop-filter:blur(18px) saturate(160%) !important;
      backdrop-filter:blur(18px) saturate(160%) !important;
    }
  }
}
</style>

<style>
/* ==================================================
   الضبط التلقائي للغات الأجنبية (LTR)
   ================================================== */
html[dir="ltr"] .sidebar { right: auto; left: 0; border-left: none; border-right: 1px solid var(--br); }
html[dir="ltr"] .main { margin-right: 0; margin-left: var(--sw); }
html[dir="ltr"] .sbrand, html[dir="ltr"] .si, html[dir="ltr"] .slogout, html[dir="ltr"] .snl { text-align: left; }
html[dir="ltr"] .si.on::before { right: auto; left: 0; border-radius: 0 2px 2px 0; }
html[dir="ltr"] .si-ic { margin-right: 11px; margin-left: 0; }
html[dir="ltr"] th, html[dir="ltr"] td { text-align: left; }
html[dir="ltr"] .tbr { flex-direction: row-reverse; }
html[dir="ltr"] .srgrid .src-acts { border-right: none; border-left: 1px solid var(--br); }
html[dir="ltr"] .tsrch { flex-direction: row-reverse; }
html[dir="ltr"] .tsrch input { text-align: left; }
html[dir="ltr"] .sc-v, html[dir="ltr"] .sc-l { text-align: left; }
@media(max-width:768px){
    html[dir="ltr"] .sidebar { transform: translateX(-100%); }
    html[dir="ltr"] .sidebar.open { transform: translateX(0); }
    html[dir="ltr"] .main { margin-left: 0; }
}

/* ==================================================
   تصميم زر تغيير اللغات الاحترافي (حل مشكلة التناسق)
   ================================================== */
.lang-sw { position: relative; display: flex; align-items: center; }
.lang-btn { display: flex; align-items: center; gap: 7px; background: var(--s2); border: 1px solid var(--br); border-radius: 100px; padding: 6px 14px; color: var(--t1); cursor: pointer; font-size: 0.85rem; font-weight: bold; transition: 0.2s; direction: ltr; }
.lang-btn:hover { background: var(--s3); border-color: var(--brh); }
.lang-drop { position: absolute; top: calc(100% + 12px); background: var(--s1); border: 1px solid var(--brh); border-radius: 12px; min-width: 140px; display: none; flex-direction: column; box-shadow: 0 10px 30px rgba(0,0,0,0.8); overflow: hidden; z-index: 999; }
.lang-drop.op { display: flex; }
.lang-opt { padding: 12px 15px; color: var(--t2); text-decoration: none; font-size: 0.85rem; display: flex; align-items: center; transition: all 0.2s; border-bottom: 1px solid var(--br); font-weight: 500; }
.lang-opt:last-child { border-bottom: none; }
.lang-opt:hover { background: var(--s3); color: var(--t1); }
.lang-flag { font-size: 1.1rem; display: flex; align-items: center; justify-content: center; }

/* تناسق دقيق للغة العربية (RTL) */
html[dir="rtl"] .lang-drop { left: 0; right: auto; }
html[dir="rtl"] .lang-opt { flex-direction: row; }
html[dir="rtl"] .lang-opt:hover { padding-right: 22px; padding-left: 8px; }
html[dir="rtl"] .lang-flag { margin-left: 12px; margin-right: 0; }

/* تناسق دقيق للغات الأجنبية (LTR) */
html[dir="ltr"] .lang-drop { right: 0; left: auto; }
html[dir="ltr"] .lang-opt { flex-direction: row; }
html[dir="ltr"] .lang-opt:hover { padding-left: 22px; padding-right: 8px; }
html[dir="ltr"] .lang-flag { margin-right: 12px; margin-left: 0; }

/* ══ قائمة البروفايل المنسدلة (إضافة) ══ */
.prof-sw{position:relative;display:flex;align-items:center}
.prof-btn{display:flex;align-items:center;gap:9px;background:var(--s2);border:1px solid var(--br);border-radius:100px;padding:4px 12px 4px 4px;cursor:pointer;transition:.2s}
.prof-btn:hover{background:var(--s3);border-color:var(--brh)}
.prof-btn .uavt{width:34px;height:34px}
.prof-avt-img{width:34px;height:34px;border-radius:50%;object-fit:cover;box-shadow:0 0 12px var(--redg);flex-shrink:0;display:block}
.prof-btn .prof-name{font-size:.83rem;font-weight:600;color:var(--t1)}
.prof-btn .prof-caret{font-size:.7rem;color:var(--t3);transition:.25s}
.prof-sw.op .prof-caret{transform:rotate(180deg)}
.prof-drop{position:absolute;top:calc(100% + 12px);background:var(--s1);border:1px solid var(--brh);border-radius:14px;min-width:210px;display:none;flex-direction:column;box-shadow:0 14px 40px rgba(0,0,0,.7);overflow:hidden;z-index:999;animation:profIn .2s ease}
.prof-sw.op .prof-drop{display:flex}
@keyframes profIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.prof-head{display:flex;align-items:center;gap:11px;padding:15px 16px;border-bottom:1px solid var(--br);background:var(--s2)}
.prof-head .prof-avt-img,.prof-head .uavt{width:42px;height:42px}
.prof-head-info{display:flex;flex-direction:column;gap:2px;min-width:0}
.prof-head-info b{font-size:.9rem;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.prof-head-info small{font-size:.72rem;color:var(--t3)}
.prof-logout{display:flex;align-items:center;gap:10px;padding:13px 16px;color:#ff6b6b;background:transparent;border:none;width:100%;cursor:pointer;font-family:'Tajawal',sans-serif;font-size:.86rem;font-weight:600;transition:.18s;text-align:right}
html[dir="ltr"] .prof-logout{text-align:left}
.prof-logout:hover{background:rgba(229,9,20,.1)}
.prof-logout i{font-size:1rem}
html[dir="rtl"] .prof-drop{left:0;right:auto}
html[dir="ltr"] .prof-drop{right:0;left:auto}

/* ══ فوتر النظام (إضافة) ══ */
.sys-footer{margin-top:auto;padding:18px 32px;border-top:1px solid var(--br);text-align:center;color:var(--t3);font-size:.8rem;font-weight:500;letter-spacing:.3px;display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap}
.sys-footer b{color:var(--t2);font-weight:700}
.sys-footer .sf-dot{width:5px;height:5px;border-radius:50%;background:var(--red);box-shadow:0 0 8px var(--redg)}
.main{display:flex;flex-direction:column;min-height:100vh}
@media(max-width:768px){.prof-btn .prof-name{display:none}.sys-footer{padding:14px 16px;font-size:.74rem}}

/* ══ زر الوضع الليلي/النهاري (إضافة) ══ */
.mode-toggle{width:38px;height:38px;display:flex;align-items:center;justify-content:center;background:var(--s2);border:1px solid var(--br);border-radius:50%;color:var(--gold);cursor:pointer;font-size:.95rem;transition:.25s;flex-shrink:0}
.mode-toggle:hover{background:var(--s3);border-color:var(--brh);transform:rotate(-15deg)}
.mode-toggle i{transition:.3s}

/* ألوان الوضع النهاري — تُفعّل عند وضع class على <html> */
html.light-mode{
  --red:#E50914;--redg:rgba(229,9,20,.25);--gold:#d97706;
  --s0:#f1f3f7;--s1:#ffffff;--s2:#f4f6fa;--s3:#e9edf3;--s4:#dce2ea;
  --br:rgba(0,0,0,.08);--brh:rgba(0,0,0,.16);
  --t1:#16181d;--t2:#444a55;--t3:#7a828f;
}
html.light-mode .topbar{background:rgba(255,255,255,.9)}
html.light-mode .sidebar{background:var(--s1)}
html.light-mode .mode-toggle{color:#f5a623}
html.light-mode body{background:var(--s0)}

/* ══ قسم التحكم بالواجهة الأمامية (إضافة) ══ */
.fc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px}
.fc-card{display:flex;align-items:center;gap:14px;padding:16px 18px;background:var(--s1);border:1px solid var(--br);border-radius:var(--r2);cursor:pointer;transition:.2s}
.fc-card:hover{border-color:var(--brh);background:var(--s2)}
.fc-ic{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.fc-info{flex:1;min-width:0}
.fc-info b{display:block;font-size:.92rem;color:var(--t1);margin-bottom:2px}
.fc-info small{font-size:.76rem;color:var(--t3)}
.fc-switch{position:relative;width:48px;height:26px;flex-shrink:0}
.fc-switch input{opacity:0;width:0;height:0;position:absolute}
.fc-slider{position:absolute;inset:0;background:var(--s4);border-radius:26px;transition:.25s;cursor:pointer}
.fc-slider::before{content:"";position:absolute;width:20px;height:20px;right:3px;top:3px;background:#fff;border-radius:50%;transition:.25s;box-shadow:0 2px 6px rgba(0,0,0,.3)}
.fc-switch input:checked + .fc-slider{background:#00D084}
.fc-switch input:checked + .fc-slider::before{transform:translateX(-22px)}
html[dir="ltr"] .fc-slider::before{right:auto;left:3px}
html[dir="ltr"] .fc-switch input:checked + .fc-slider::before{transform:translateX(22px)}
</style>

<style id="customCssThemeStyle">/* Custom Theme CSS - injected by theme system */</style>

<style>
/* ══════════════════════════════════════════════════════════
   CUSTOM CSS THEME PANEL
   ══════════════════════════════════════════════════════════ */
.theme-fab{position:fixed;left:20px;bottom:24px;z-index:500;width:46px;height:46px;border-radius:50%;background:linear-gradient(135deg,var(--red),#ff6b35);box-shadow:0 4px 20px rgba(229,9,20,.45);border:none;color:#fff;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .25s var(--ease)}
.theme-fab:hover{transform:scale(1.1) rotate(15deg);box-shadow:0 6px 28px rgba(229,9,20,.6)}

.theme-panel{position:fixed;left:0;bottom:0;width:340px;max-height:90vh;background:var(--s1);border:1px solid var(--brh);border-radius:0 20px 0 0;z-index:600;transform:translateX(-110%);transition:transform .35s cubic-bezier(.23,1,.32,1);display:flex;flex-direction:column;box-shadow:6px 0 40px rgba(0,0,0,.6)}
.theme-panel.open{transform:translateX(0)}
.theme-panel-hd{padding:16px 20px;border-bottom:1px solid var(--br);display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,rgba(229,9,20,.1),rgba(229,9,20,.03));flex-shrink:0}
.theme-panel-title{font-size:.95rem;font-weight:800;display:flex;align-items:center;gap:9px}
.theme-panel-title i{color:var(--red)}
.theme-panel-close{width:28px;height:28px;border:1px solid var(--br);background:var(--s3);border-radius:var(--r1);color:var(--t2);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.75rem;transition:all .15s}
.theme-panel-close:hover{background:rgba(229,9,20,.1);color:var(--red)}
.theme-panel-body{padding:16px;overflow-y:auto;flex:1}
.theme-panel-body::-webkit-scrollbar{width:3px}.theme-panel-body::-webkit-scrollbar-thumb{background:var(--s4)}

.theme-section-title{font-size:.72rem;font-weight:700;color:var(--t3);letter-spacing:.12em;text-transform:uppercase;margin:14px 0 8px}

/* Theme preset cards */
.theme-presets{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:4px}
.theme-card{background:var(--s2);border:2px solid var(--br);border-radius:var(--r1);padding:10px;cursor:pointer;transition:all .2s;text-align:center;position:relative;overflow:hidden}
.theme-card:hover{border-color:var(--brh);transform:translateY(-2px)}
.theme-card.active{border-color:var(--red);background:rgba(229,9,20,.07)}
.theme-card-preview{width:100%;height:32px;border-radius:5px;margin-bottom:7px;display:flex;align-items:center;justify-content:center;font-size:.6rem;font-weight:800;letter-spacing:.05em}
.theme-card-name{font-size:.75rem;font-weight:700;color:var(--t2)}
.theme-card-desc{font-size:.62rem;color:var(--t3);margin-top:2px;line-height:1.4}
.theme-card.active .theme-card-name{color:var(--red)}

/* Custom CSS textarea */
.custom-css-wrap{margin-top:4px}
.custom-css-textarea{width:100%;min-height:140px;background:var(--s2);border:1px solid var(--br);border-radius:var(--r1);color:#4CC9F0;font-family:'Courier New',monospace;font-size:.75rem;padding:10px 12px;resize:vertical;outline:none;transition:border-color .18s;line-height:1.6}
.custom-css-textarea:focus{border-color:var(--red);box-shadow:0 0 0 2px rgba(229,9,20,.1)}
.custom-css-textarea::placeholder{color:var(--t3)}

.theme-panel-footer{padding:12px 16px;border-top:1px solid var(--br);display:flex;gap:8px;flex-shrink:0;background:var(--s2)}

/* Color swatch pickers */
.color-row{display:flex;gap:8px;align-items:center;margin-bottom:10px}
.color-swatch{width:28px;height:28px;border-radius:6px;border:2px solid var(--br);cursor:pointer;transition:all .15s;position:relative;flex-shrink:0}
.color-swatch:hover{transform:scale(1.15);border-color:var(--brh)}
.color-label{font-size:.78rem;color:var(--t2);flex:1}
.color-input-wrap{display:flex;align-items:center;gap:6px;flex:1}

/* Reset button */
.theme-reset-btn{display:flex;align-items:center;gap:6px;padding:6px 12px;background:var(--s3);border:1px solid var(--br);border-radius:var(--r1);color:var(--t3);font-size:.75rem;cursor:pointer;transition:all .15s}
.theme-reset-btn:hover{border-color:var(--brh);color:var(--t1)}

/* LTR sidebar fix */
html[dir="ltr"] .theme-fab{left:auto;right:20px}
html[dir="ltr"] .theme-panel{left:auto;right:0;border-radius:20px 0 0 0;transform:translateX(110%)}
html[dir="ltr"] .theme-panel.open{transform:translateX(0)}

@media(max-width:768px){
    .theme-panel{width:100%;border-radius:20px 20px 0 0;bottom:0;left:0;transform:translateY(110%)}
    .theme-panel.open{transform:translateY(0)}
    html[dir="ltr"] .theme-panel{right:auto;left:0;transform:translateY(110%)}
    html[dir="ltr"] .theme-panel.open{transform:translateY(0)}
    .theme-fab{bottom:16px;left:16px}
    html[dir="ltr"] .theme-fab{right:16px;left:auto}
}

/* === Sidebar Collapse Enhancement === */
.desktop-toggle-btn { background: transparent; border: none; color: var(--t3); font-size: 1rem; cursor: pointer; margin-right: auto; width: 32px; height: 32px; border-radius: var(--r1); display: flex; align-items: center; justify-content: center; transition: all 0.3s var(--ease); outline: none; }
.desktop-toggle-btn:hover { background: var(--s3); color: var(--t1); }
html[dir="ltr"] .desktop-toggle-btn { margin-right: 0; margin-left: auto; }
@media (max-width: 768px) { .desktop-toggle-btn { display: none; } }

body.sidebar-collapsed { --sw: 74px; }
body.sidebar-collapsed .sidebar { overflow-x: hidden; }
body.sidebar-collapsed .sbrand { padding: 22px 0; justify-content: center; flex-direction: column; gap: 15px; border-bottom: 1px solid rgba(255,255,255,0.02); }
body.sidebar-collapsed .desktop-toggle-btn { margin: 0; transform: rotate(180deg); background: var(--s2); border: 1px solid var(--br); width: 38px; height: 38px; border-radius: 50%; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
body.sidebar-collapsed .sbrand-text,
body.sidebar-collapsed .snl { opacity:0; visibility:hidden; width:0; height:0; margin:0; padding:0; display:none; }
body.sidebar-collapsed .si, body.sidebar-collapsed .slogout { font-size: 0 !important; justify-content: center; padding: 14px 0; gap: 0; }
body.sidebar-collapsed .si-ic, body.sidebar-collapsed .slogout i { font-size: 1.25rem; width: auto; margin: 0; padding: 0; }
body.sidebar-collapsed .sfoot { padding: 10px; border-top: 1px solid transparent; }

/* === Lucide Icons Fixes === */
.si-ic svg { width: 1.25em; height: 1.25em; stroke-width: 2px; margin: 0; }
.slogout i[data-lucide], .slogout svg { width: 1.15em; height: 1.15em; margin: 0; stroke-width: 2px; }
.sbrand-icon svg { width: 1.5em; height: 1.5em; stroke-width: 2px; }
.desktop-toggle-btn svg { width: 1.1em; height: 1.1em; stroke-width: 2px; transition: transform 0.3s; }
body.sidebar-collapsed .desktop-toggle-btn svg { transform: rotate(180deg); }

/* === Music Player UI === */
.music-p-mini { display: flex; align-items: center; gap: 8px; border: 1px solid rgba(255,255,255,0.1); padding: 6px 12px; border-radius: 20px; cursor: pointer; transition: all 0.2s; background: rgba(0,0,0,0.2); }
.music-p-mini:hover { border-color: #4CC9F0; background: rgba(76,201,240,0.1); }
.m-eq { display: flex; align-items: flex-end; gap: 3px; height: 12px; margin-right: 5px; }
.m-eq span { width: 3px; background: #4CC9F0; border-radius: 2px; animation: eqAnim 0.5s infinite alternate ease-in-out; }
.m-eq span:nth-child(1) { height: 60%; animation-delay: 0.1s; }
.m-eq span:nth-child(2) { height: 100%; animation-delay: 0.3s; }
.m-eq span:nth-child(3) { height: 40%; animation-delay: 0.2s; }
.m-eq.paused span { animation: none !important; height: 3px !important; background: var(--t3); }

@keyframes eqAnim { 0% { height: 3px; } 100% { height: 12px; } }

.m-tr-card { display: flex; align-items: center; background: var(--s2); border: 1px solid var(--br); padding: 14px; border-radius: var(--r1); margin-bottom: 10px; transition: 0.3s; }
.m-tr-card:hover { border-color: #4CC9F0; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.2); }
.m-tr-icon { width: 42px; height: 42px; border-radius: 50%; background: rgba(76,201,240,0.1); color: #4CC9F0; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; cursor: pointer; transition: 0.2s; }
.m-tr-icon:hover { background: #4CC9F0; color: #fff; transform: scale(1.1); }
.m-tr-info { flex: 1; margin: 0 15px; overflow: hidden; }
.m-tr-title { color: var(--t1); font-size: 0.95rem; font-weight: 700; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; direction: ltr; text-align: right; }
.m-tr-del { color: var(--red); cursor: pointer; padding: 10px; border-radius: var(--r1); transition: 0.2s; background: rgba(229,9,20,0.05); }
.m-tr-del:hover { background: rgba(229,9,20,0.15); }
</style>
</head>
<body>
<script>if(localStorage.getItem('shashety_sidebar')==='collapsed'){document.body.classList.add('sidebar-collapsed');}
if(sessionStorage.getItem('shashety_loaded')){ document.write('<style>#nfx-loader { display: none !important; }</style>'); } else { sessionStorage.setItem('shashety_loaded', '1'); }
</script>
<!-- Netflix-Style Loading Screen -->
<div id="nfx-loader" style="position:fixed;inset:0;background:#0a0a0a;z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:0;transition:opacity .6s ease,visibility .6s ease">
  <div style="font-family:'Tajawal',sans-serif;font-size:2.8rem;font-weight:900;color:#E50914;letter-spacing:.05em;text-shadow:0 0 40px rgba(229,9,20,.6);animation:nfxpulse 1.2s ease-in-out infinite">SHASHITY PRO</div>
  <div style="margin-top:48px;display:flex;gap:7px;align-items:center">
    <span style="width:6px;height:6px;background:#E50914;border-radius:50%;animation:nfxdot 1.4s ease-in-out infinite .0s"></span>
    <span style="width:6px;height:6px;background:#E50914;border-radius:50%;animation:nfxdot 1.4s ease-in-out infinite .2s"></span>
    <span style="width:6px;height:6px;background:#E50914;border-radius:50%;animation:nfxdot 1.4s ease-in-out infinite .4s"></span>
  </div>
  <style>
    @keyframes nfxpulse{0%,100%{opacity:1;text-shadow:0 0 40px rgba(229,9,20,.6)}50%{opacity:.75;text-shadow:0 0 70px rgba(229,9,20,1)}}
    @keyframes nfxdot{0%,80%,100%{transform:scale(.6);opacity:.4}40%{transform:scale(1);opacity:1}}
  </style>
</div>
<script>
  window.addEventListener('load', function(){
    setTimeout(function(){
      var l = document.getElementById('nfx-loader');
      if(l){ l.style.opacity='0'; l.style.visibility='hidden'; setTimeout(function(){ l.remove(); },650); }
    }, 900);
  });
</script>
<div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sbrand" style="display:flex; align-items:center; gap:12px;">
    <div class="sbrand-icon" style="width: 38px; height: 38px; background: linear-gradient(135deg, #E50914, #9a050d); color: #fff; border-radius: 10px; margin:0; box-shadow: 0 4px 15px rgba(229,9,20,0.4); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <i data-lucide="layout-dashboard" style="width:1.2rem; height:1.2rem; stroke-width: 2.5;"></i>
    </div>
    <div class="sbrand-text" style="flex:1; display:flex; flex-direction:column; justify-content:center; text-align:left;">
        <div class="sbrand-name" style="font-family: 'Inter', 'Tajawal', sans-serif; font-size: 1.1rem; font-weight: 800; letter-spacing: 1.5px; color: var(--t1); line-height: 1.1;">DASHBOARD</div>
        <div style="font-size: 0.65rem; color: #E50914; font-weight: 800; letter-spacing: 2px; margin-top: 3px;">SH PRO V2.0</div>
    </div>
    <button class="desktop-toggle-btn" onclick="toggleDesktopSidebar()" title="طي / توسيع القائمة">
      <i data-lucide="chevron-right" id="dtoggle-icon"></i>
    </button>
  </div>
  <nav class="snav">
    <div class="snl"><?= $t["nav_main"] ?? "الرئيسية" ?></div>
    <button class="si on" onclick="S('dashboard');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="home"></i></span><?= $t["dashboard"] ?? "لوحة التحكم" ?></button>
    <div class="snl"><?= $t["nav_content"] ?? "المحتوى" ?></div>
    <button class="si" onclick="S('categories');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="layout-grid"></i></span><?= $t["categories"] ?? "الأقسام" ?></button>
    <button class="si" onclick="S('channels');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="tv"></i></span><?= $t["channels"] ?? "القنوات" ?></button>
    <button class="si" onclick="S('m3u-import');m3uLoadPlaylists();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="file-up"></i></span>استيراد M3U</button>
    <!-- [XTREAM-NAV-START] -->
    <button class="si" onclick="S('xtream');xtreamLoadAccounts();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="satellite-dish" style="color:#F5A623"></i></span>حساب Xtream</button>
    <!-- [XTREAM-NAV-END] -->
    <button class="si" onclick="S('series');loadSeries();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="film"></i></span><?= $t["series"] ?? "شاشتي" ?></button>
    <button class="si" onclick="S('vupload');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="upload-cloud"></i></span><?= $t["upload"] ?? "رفع الأفلام" ?></button>
    <button class="si" onclick="S('vmanage');vmLoad();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="video"></i></span><?= $t["manage"] ?? "إدارة الفيديوهات" ?></button>
    <div class="snl"><?= $t["nav_management"] ?? "الإدارة" ?></div>
    <button class="si" onclick="S('api-settings');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="plug"></i></span><?= $t["api_settings"] ?? "إعدادات API" ?></button>
    <button class="si" onclick="S('site-settings');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="settings"></i></span><?= $t["settings"] ?? "إعدادات الموقع" ?></button>
    <button class="si" onclick="S('change-password');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="key"></i></span><?= $t["password"] ?? "كلمة المرور" ?></button>
    <button class="si" onclick="S('system-tools');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="wrench"></i></span><?= $t["tools"] ?? "صيانة النظام" ?></button>
    <button class="si" onclick="S('backup');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="database"></i></span><?= $t["backup"] ?? "النسخ الاحتياطي" ?></button>
    <button class="si" onclick="S('users');loadUsers();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="users"></i></span><?= $t["users"] ?? "إدارة المستخدمين" ?></button>
    <button class="si" onclick="S('login-logs');loadLoginLogs();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="shield"></i></span>سجل الدخول</button>
        <!-- Theme Button in Sidebar -->
    <div class="snl">التخصيص</div>
    <button class="si" onclick="S('general-settings');loadGeneralSettings();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="sliders-horizontal" style="color:#F5A623"></i></span>⚙️ الإعدادات العامة</button>
    <button class="si" onclick="toggleThemePanel();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="palette" style="color:#B36BFF"></i></span>🎨 الثيمات والألوان</button>
    <button class="si" onclick="S('frontend-control');loadFrontendToggles();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="layout-dashboard" style="color:#00D084"></i></span>التحكم بالواجهة الأمامية</button>
    <div class="snl">حول النظام</div>
    <button class="si" onclick="S('company-info');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="info" style="color:#4CC9F0"></i></span>حول الشركة</button>
  </nav>

</aside>

<div class="main">
<header class="topbar">
  <button class="mob-menu-btn" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="القائمة">
    <div class="ham-icon">
      <span></span><span></span><span></span>
    </div>
  </button>
  <span class="tbtitle" id="tbTitle">لوحة التحكم</span>
  <div class="tbr">

    <!-- Music Mini Player -->
    <div class="music-p-mini" onclick="toggleAdminMusic()" title="إيقاف / تشغيل موسيقى الخلفية">
        <i class="fas fa-music" style="color:var(--t3); font-size:0.8rem;"></i>
        <div class="m-eq paused" id="m_eq"><span></span><span></span><span></span></div>
    </div>

    <!-- Day/Night Mode Toggle (إضافة) -->
    <button class="mode-toggle" id="modeToggle" onclick="toggleDayNight()" title="تبديل الوضع الليلي / النهاري" aria-label="تبديل الوضع">
      <i class="fas fa-moon" id="modeIcon"></i>
    </button>

    <!-- Language Switcher -->
    <div class="lang-sw">
      <button class="lang-btn" onclick="document.getElementById('langDrop').classList.toggle('op'); event.stopPropagation();">
        <i class="fas fa-globe" style="color:var(--t3)"></i> <span><?= strtoupper($__cur_lang) ?></span>
      </button>
      <div class="lang-drop" id="langDrop">
        <a class="lang-opt" href="?lang=ar">
            <span class="lang-flag">🇸🇦</span><span>العربية</span>
        </a>
        <a class="lang-opt" href="?lang=en">
            <span class="lang-flag">🇬🇧</span><span>English</span>
        </a>
        <a class="lang-opt" href="?lang=tr">
            <span class="lang-flag">🇹🇷</span><span>Türkçe</span>
        </a>
      </div>
    </div>
    <!-- End Language Switcher -->
    
    <div class="lic-b"><span class="lic-dot"></span><?php $lt=htmlspecialchars($_SESSION['license_info']['license_type_name']??'نشطة');$dl=$_SESSION['license_days_left']??0;$ds=($dl==='unlimited'||$dl>9999)?'∞':"$dl يوم";echo "$lt · $ds"; ?></div>

    <!-- ══ قائمة البروفايل المنسدلة (مع صورة + تسجيل خروج) ══ -->
    <div class="prof-sw" id="profSw">
      <button class="prof-btn" onclick="document.getElementById('profSw').classList.toggle('op'); event.stopPropagation();" aria-label="حساب المستخدم">
        <img class="prof-avt-img" src="assets/22.png" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
        <div class="uavt" style="display:none"><?php echo strtoupper(substr($_admin_display ?? ($_SESSION['admin_username']??'A'),0,1)); ?></div>
        <span class="prof-name"><?php echo htmlspecialchars($_admin_display ?? ($_SESSION['admin_username']??'المدير')); ?></span>
        <i class="fas fa-chevron-down prof-caret"></i>
      </button>
      <div class="prof-drop">
        <div class="prof-head">
          <img class="prof-avt-img" src="assets/22.png" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
          <div class="uavt" style="display:none"><?php echo strtoupper(substr($_admin_display ?? ($_SESSION['admin_username']??'A'),0,1)); ?></div>
          <div class="prof-head-info">
            <b><?php echo htmlspecialchars($_admin_display ?? ($_SESSION['admin_username']??'المدير')); ?></b>
            <small><?php echo htmlspecialchars($_SESSION['admin_username']??''); ?></small>
          </div>
        </div>
        <button class="prof-logout" onclick="if(confirm('تسجيل الخروج؟'))location.href='logout.php'">
          <i data-lucide="log-out"></i><span><?= $t["logout"] ?? "تسجيل الخروج" ?></span>
        </button>
      </div>
    </div>
  </div>
</header>
<div class="pcont">
<?php if(isset($_SESSION['success'])): ?><div class="al al-s"><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8');unset($_SESSION['success']); ?></div><?php endif; ?>
<?php if(isset($_SESSION['error'])): ?><div class="al al-e"><i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8');unset($_SESSION['error']); ?></div><?php endif; ?>


<!-- DASHBOARD -->
<section id="dashboard" class="sec on">
  <div class="shdr"><h1 class="stitle">نظرة <span>عامة</span></h1></div>
  <div class="sgrid">
    <div class="sc r"><div class="sc-ic"><i class="fas fa-th-large"></i></div><div class="sc-v"><?php echo $stats['cats']; ?></div><div class="sc-l"><?= $t["categories"] ?? "الأقسام" ?></div></div>
    <div class="sc g"><div class="sc-ic"><i class="fas fa-tv"></i></div><div class="sc-v"><?php echo $stats['channels']; ?></div><div class="sc-l"><?= $t["channels"] ?? "القنوات" ?></div></div>
    <div class="sc p"><div class="sc-ic"><i class="fas fa-film"></i></div><div class="sc-v"><?php echo $stats['series']; ?></div><div class="sc-l"><?= $t["series"] ?? "شاشتي" ?></div></div>
    <div class="sc go"><div class="sc-ic"><i class="fas fa-eye"></i></div><div class="sc-v"><?php echo number_format($stats['views']); ?></div><div class="sc-l"><?= $t["views"] ?? "المشاهدات" ?></div></div>
    <div class="sc b"><div class="sc-ic"><i class="fas fa-users"></i></div><div class="sc-v"><?php echo $stats['users']; ?></div><div class="sc-l"><?= $t["users"] ?? "المستخدمين" ?></div></div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin-bottom:25px;">
    <!-- CPU Card -->
    <div style="background:var(--bg2);padding:20px;border-radius:var(--r2);border:1px solid var(--border);box-shadow:var(--shadow);position:relative;overflow:hidden">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px">
        <div style="font-size:1.1rem;font-weight:700;color:var(--t2)"><i class="fas fa-microchip" style="color:#B36BFF;margin-left:8px"></i>المعالج (CPU)</div>
        <div id="cpu_percent_text" style="font-size:1.4rem;font-weight:800;color:var(--t1)">--%</div>
      </div>
      <div style="height:6px;background:var(--s2);border-radius:10px;overflow:hidden">
        <div id="cpu_bar" style="height:100%;width:0%;background:linear-gradient(90deg,#B36BFF,#7B2CBF);transition:width 0.5s ease, background 0.5s ease"></div>
      </div>
      <div style="margin-top:12px;font-size:0.8rem;color:var(--t3);text-align:left" id="cpu_desc">جاري الفحص...</div>
    </div>
    
    <!-- RAM Card -->
    <div style="background:var(--bg2);padding:20px;border-radius:var(--r2);border:1px solid var(--border);box-shadow:var(--shadow);position:relative;overflow:hidden">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px">
        <div style="font-size:1.1rem;font-weight:700;color:var(--t2)"><i class="fas fa-memory" style="color:#00D084;margin-left:8px"></i>الذاكرة (RAM)</div>
        <div id="ram_percent_text" style="font-size:1.4rem;font-weight:800;color:var(--t1)">--%</div>
      </div>
      <div style="height:6px;background:var(--s2);border-radius:10px;overflow:hidden">
        <div id="ram_bar" style="height:100%;width:0%;background:linear-gradient(90deg,#00D084,#009e60);transition:width 0.5s ease, background 0.5s ease"></div>
      </div>
      <div style="margin-top:12px;font-size:0.85rem;color:var(--t3);text-align:left;display:flex;justify-content:space-between;font-weight:600">
        <span id="ram_used_text">--</span> <span id="ram_total_text">--</span>
      </div>
    </div>

    <!-- Disk Card -->
    <div style="background:var(--bg2);padding:20px;border-radius:var(--r2);border:1px solid var(--border);box-shadow:var(--shadow);position:relative;overflow:hidden">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px">
        <div style="font-size:1.1rem;font-weight:700;color:var(--t2)"><i class="fas fa-hdd" style="color:#4CC9F0;margin-left:8px"></i>التخزين (Disk)</div>
        <div id="disk_percent_text" style="font-size:1.4rem;font-weight:800;color:var(--t1)">--%</div>
      </div>
      <div style="height:6px;background:var(--s2);border-radius:10px;overflow:hidden">
        <div id="disk_bar" style="height:100%;width:0%;background:linear-gradient(90deg,#4CC9F0,#0096C7);transition:width 0.5s ease, background 0.5s ease"></div>
      </div>
      <div style="margin-top:12px;font-size:0.85rem;color:var(--t3);text-align:left;display:flex;justify-content:space-between;font-weight:600">
        <span id="disk_used_text">--</span> <span id="disk_total_text">--</span>
      </div>
    </div>
  </div>

  <div class="dgrid">
    <div class="card">
      <div class="chdr"><span class="ctitle"><i class="fas fa-tv" style="color:var(--red);margin-left:7px"></i>آخر القنوات</span><button class="btn btn-g bsm" onclick="S('channels')">الكل</button></div>
      <div class="cbody">
        <?php $rc=array_slice($channels,0,6);if($rc):foreach($rc as $ch): ?>
        <div class="ri"><div class="ri-ic"><i class="<?php echo htmlspecialchars($ch['logo_icon']); ?>"></i></div><div style="flex:1;min-width:0"><div class="ri-name"><?php echo htmlspecialchars($ch['name']); ?></div><div class="ri-meta"><?php echo htmlspecialchars($ch['cat_name']); ?></div></div><span style="font-size:.75rem;color:var(--t3)"><i class="fas fa-eye"></i> <?php echo $ch['views_count']; ?></span></div>
        <?php endforeach;else: ?><div class="empty"><i class="fas fa-tv"></i><p>لا توجد قنوات</p></div><?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="chdr"><span class="ctitle"><i class="fas fa-bolt" style="color:var(--gold);margin-left:7px"></i>إجراءات سريعة</span></div>
      <div class="cbody">
        <div class="qa-list">
          <a class="qa r" onclick="S('channels');setTimeout(()=>OM('addChM'),200)"><div class="qa-ic"><i class="fas fa-plus"></i></div>إضافة قناة</a>
          <a class="qa b" onclick="S('categories');setTimeout(()=>OM('addCatM'),200)"><div class="qa-ic"><i class="fas fa-folder-plus"></i></div>إضافة قسم</a>
          <a class="qa p" onclick="S('series');loadSeries();setTimeout(()=>OM('addSeriesM'),300)"><div class="qa-ic"><i class="fas fa-film"></i></div>إضافة مسلسل</a>
          <a class="qa go" onclick="S('vupload')"><div class="qa-ic"><i class="fas fa-cloud-upload-alt"></i></div>رفع فيلم</a>
          <a class="qa g" href="backup_system.php?action=export_full"><div class="qa-ic"><i class="fas fa-download"></i></div>تصدير نسخة احتياطية</a>
          <a class="qa b" onclick="S('m3u-import');m3uLoadPlaylists()"><div class="qa-ic"><i class="fas fa-file-import"></i></div>استيراد M3U</a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- CATEGORIES -->
<section id="categories" class="sec">
  <div class="shdr"><h1 class="stitle">إدارة <span>الأقسام</span></h1><button class="btn btn-p" onclick="OM('addCatM')"><i class="fas fa-plus"></i>قسم جديد</button></div>
  <div class="tw">
    <div class="tt"><div class="tsrch"><i class="fas fa-search"></i><input type="text" placeholder="بحث..." oninput="FT(this,'catTbl')"></div><span style="font-size:.78rem;color:var(--t3)"><?php echo count($categories); ?> قسم</span></div>
    <?php if($categories): ?>
    <div id="catBulkBar" style="display:none;align-items:center;gap:12px;padding:10px 14px;margin-bottom:10px;background:rgba(229,9,20,.08);border:1px solid rgba(229,9,20,.25);border-radius:10px">
      <span style="font-size:.82rem;color:var(--t1);font-weight:700"><i class="fas fa-check-square" style="color:var(--red)"></i> <span id="catSelCount">0</span> قسم محدد</span>
      <button class="btn btn-g" style="margin-right:auto;padding:6px 14px" onclick="catClearSel()"><i class="fas fa-times"></i> إلغاء التحديد</button>
      <button class="btn btn-p" style="padding:6px 14px;background:var(--red)" onclick="catBulkDelete()"><i class="fas fa-trash"></i> حذف المحدد</button>
    </div>
    <table id="catTbl"><thead><tr><th style="width:38px"><input type="checkbox" id="catSelAll" onchange="catToggleAll(this)" style="width:16px;height:16px;cursor:pointer;accent-color:var(--red)"></th><th>ID</th><th>القسم</th><th>القسم الأب</th><th>الأيقونة</th><th>القنوات</th><th>الظهور بالواجهة</th><th>إجراءات</th></tr></thead><tbody>
    <?php foreach($categories as $cat): ?>
    <tr><td><input type="checkbox" class="catSelChk" value="<?php echo $cat['id']; ?>" onchange="catSelCtrl()" style="width:16px;height:16px;cursor:pointer;accent-color:var(--red)"></td><td style="color:var(--t3);font-size:.75rem">#<?php echo $cat['id']; ?></td><td><div class="cn"><div class="nic"><i class="<?php echo htmlspecialchars($cat['icon']); ?>"></i></div><strong style="color:var(--t1)"><?php echo htmlspecialchars($cat['name']); ?></strong></div></td>
    <td><?php $pid=$cat['parent_id']??null;if($pid){foreach($categories as $pc){if($pc['id']==$pid){echo '<span class="bdg bc">'.htmlspecialchars($pc['name']).'</span>';break;}}}else echo '<span style="color:var(--t3);font-size:.75rem">—</span>'; ?></td>
    <td><code style="font-size:.72rem;color:var(--t3);background:var(--s3);padding:2px 7px;border-radius:4px"><?php echo htmlspecialchars($cat['icon']); ?></code></td>
    <td><span class="bdg bc"><?php echo $cat['channel_count']; ?></span></td>
    <td><label class="fc-switch" style="display:inline-flex"><input type="checkbox" data-cat-id="<?php echo $cat['id']; ?>" class="catActiveToggle" <?php echo ((int)$cat['is_active']===1)?'checked':''; ?> onchange="toggleCategoryActive(this)"><span class="fc-slider"></span></label></td>
    <td><div class="acts"><button class="ib ed" onclick='editCat(<?php echo json_encode(['id'=>$cat['id'],'name'=>$cat['name'],'icon'=>$cat['icon'],'parent_id'=>$cat['parent_id']??null,'description'=>$cat['description']??'']); ?>)'><i class="fas fa-pen"></i></button><button class="ib dl" onclick="if(confirm('حذف القسم؟'))location.href='?delete_category=<?php echo $cat['id']; ?>'"><i class="fas fa-trash"></i></button></div></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php else: ?><div class="empty"><i class="fas fa-th-large"></i><p>لا توجد أقسام</p></div><?php endif; ?>
  </div>
</section>

<!-- CHANNELS -->
<section id="channels" class="sec">
  <div class="shdr"><h1 class="stitle">إدارة <span>القنوات</span></h1><button class="btn btn-p" onclick="OM('addChM')"><i class="fas fa-plus"></i>قناة جديدة</button></div>
  <div class="tw">
    <div class="tt"><div class="tsrch"><i class="fas fa-search"></i><input type="text" id="chSearchInput" placeholder="بحث..." oninput="chSearch(this.value)"></div><span id="chTotalCount" style="font-size:.78rem;color:var(--t3)"><?php echo count($channels); ?> قناة</span></div>
    
    <div id="chBulkBar" style="display:none;align-items:center;gap:12px;padding:10px 14px;margin-bottom:10px;background:rgba(229,9,20,.08);border:1px solid rgba(229,9,20,.25);border-radius:10px">
      <span style="font-size:.82rem;color:var(--t1);font-weight:700"><i class="fas fa-check-square" style="color:var(--red)"></i> <span id="chSelCount">0</span> قناة محددة</span>
      <button class="btn btn-g" style="margin-right:auto;padding:6px 14px" onclick="chClearSel()"><i class="fas fa-times"></i> إلغاء التحديد</button>
      <button class="btn btn-p" style="padding:6px 14px;background:var(--red)" onclick="chBulkDelete()"><i class="fas fa-trash"></i> حذف المحدد</button>
    </div>

    <div id="chLoading" style="display:none;text-align:center;padding:50px;color:var(--t3)"><div class="pspin" style="margin:0 auto 12px"></div><p>جارٍ تحميل القنوات…</p></div>
    
    <table id="chTbl" style="display:none"><thead><tr><th style="width:38px"><input type="checkbox" id="chSelAll" onchange="chToggleAll(this)" style="width:16px;height:16px;cursor:pointer;accent-color:var(--red)"></th><th>ID</th><th>القناة</th><th>القسم</th><th>الجودة</th><th>رابط احتياطي</th><th>ترجمة؟</th><th>المشاهدات</th><th>الحالة</th><th>إجراءات</th></tr></thead><tbody id="chTblBody">
    </tbody></table>
    
    <div id="chEmpty" class="empty" style="display:none"><i class="fas fa-tv"></i><p>لا توجد قنوات</p></div>
    
    <div id="chPagination" style="display:none;justify-content:center;align-items:center;gap:15px;margin-top:20px;padding:10px;">
        <button class="btn btn-g bsm" id="chPrevBtn" onclick="chChangePage(-1)"><i class="fas fa-chevron-right"></i> السابق</button>
        <span id="chPageInfo" style="font-size:0.9rem;font-weight:bold;color:var(--t1)">صفحة 1 من 1</span>
        <button class="btn btn-g bsm" id="chNextBtn" onclick="chChangePage(1)">التالي <i class="fas fa-chevron-left"></i></button>
    </div>
  </div>
</section>

<!-- M3U IMPORT -->
<section id="m3u-import" class="sec">
  <div class="shdr"><h1 class="stitle">استيراد <span>قوائم M3U</span></h1></div>

  <div class="bkgrid" style="margin-bottom:24px">
    <div class="bkc">
      <div class="bkc-title"><i class="fas fa-file-import" style="color:var(--red)"></i>رفع قائمة M3U</div>
      <div class="uz" id="m3uDropZone">
        <input type="file" id="m3uFileIn" accept=".m3u,.m3u8" onchange="m3uFileSelected(this)">
        <i class="fas fa-folder-open"></i>
        <h3>اسحب وأفلت ملف M3U هنا، أو انقر للاختيار</h3>
        <p>يدعم: .m3u, .m3u8</p>
      </div>
      <div id="m3uFileStatus" style="margin-top:10px;font-size:.8rem"></div>
    </div>

    <div class="bkc">
      <div class="bkc-title"><i class="fas fa-link" style="color:var(--red)"></i>رابط M3U</div>
      <div class="fg" style="margin-bottom:0">
        <label class="fl">رابط M3U</label>
        <input type="text" id="m3uUrlIn" class="fi" placeholder="https://yourserver.com/playlist.m3u" style="direction:ltr;text-align:left" onkeydown="if(event.key==='Enter'){event.preventDefault();m3uImportFromUrl()}">
      </div>
      <button type="button" class="btn btn-p" id="m3uUrlBtn" style="width:100%;justify-content:center;margin-top:14px" onclick="m3uImportFromUrl()"><i class="fas fa-arrow-down"></i>استيراد</button>
      <div id="m3uUrlStatus" style="margin-top:10px;font-size:.8rem"></div>
    </div>
  </div>

  <div class="tw">
    <div class="chdr"><span class="ctitle"><i class="fas fa-list" style="color:var(--red);margin-left:7px"></i>القوائم المستوردة</span></div>
    <div id="m3uPlaylistsLoading" style="padding:30px;text-align:center;color:var(--t3)"><span class="sp"></span> جارٍ التحميل...</div>
    <div id="m3uPlaylistsEmpty" class="empty" style="display:none"><i class="fas fa-file-import"></i><p>لا توجد قوائم مستوردة بعد</p></div>
    <table id="m3uPlaylistsTbl" style="display:none"><thead><tr><th>المصدر</th><th>النوع</th><th>عدد القنوات</th><th>تاريخ الاستيراد</th><th>إجراءات</th></tr></thead><tbody id="m3uPlaylistsBody"></tbody></table>
  </div>
</section>

<!-- [XTREAM-SECTION-START] قسم حساب Xtream IPTV -->
<section id="xtream" class="sec">
  <div class="shdr" style="margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
    <h1 class="stitle" style="font-size: 1.8rem; font-weight: 700; color: var(--t1); margin: 0;">إدارة حسابات <span style="color: var(--primary);">Xtream IPTV</span></h1>
    <button type="button" class="btn btn-p" onclick="document.getElementById('xtAddForm').style.display = document.getElementById('xtAddForm').style.display === 'none' ? 'block' : 'none'" style="padding: 10px 20px; font-weight: 600; border-radius: 8px; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3);">
      <i class="fas fa-plus"></i> إضافة حساب جديد
    </button>
  </div>

  <div id="xtAddForm" style="display:none; animation: fadeInDown 0.3s ease;">
  <div class="bkc" style="margin-bottom:30px; background: var(--bg2); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 8px 24px rgba(0,0,0,0.06); padding: 25px; transition: transform 0.3s ease;">
    <div class="bkc-title" style="font-size: 1.3rem; font-weight: 600; border-bottom: 2px solid var(--border); padding-bottom: 15px; margin-bottom: 20px; color: var(--t1); display: flex; align-items: center; gap: 10px;">
      <div style="background: rgba(255, 60, 60, 0.1); width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%;"><i class="fas fa-satellite-dish" style="color:var(--red); font-size: 1.2rem;"></i></div>
      تسجيل الدخول وإضافة حساب
    </div>
    
    <div class="row2" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(300px, 1fr));gap:20px; margin-bottom: 25px;">
      <div class="fg" style="background: var(--bg1); padding: 15px; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 0;">
        <label class="fl" style="font-weight: 600; color: var(--t2); margin-bottom: 10px; display: block;"><i class="fas fa-tag" style="margin-left: 8px; color: var(--primary);"></i>اسم الحساب <span style="font-size: 0.8em; color: var(--t3); font-weight: normal;">(اختياري)</span></label>
        <input type="text" id="xtName" class="fi" placeholder="مثال: سيرفري الرئيسي" style="border: 1px solid var(--br); border-radius: 8px; padding: 12px; width: 100%; transition: all 0.2s;">
      </div>
      <div class="fg" style="background: var(--bg1); padding: 15px; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 0;">
        <label class="fl" style="font-weight: 600; color: var(--t2); margin-bottom: 10px; display: block;"><i class="fas fa-server" style="margin-left: 8px; color: var(--primary);"></i>العنوان <span style="font-size: 0.8em; color: var(--t3); font-weight: normal;">(Host / DNS)</span></label>
        <input type="text" id="xtHost" class="fi" placeholder="http://example.com:8080" style="direction:ltr;text-align:left; border: 1px solid var(--br); border-radius: 8px; padding: 12px; width: 100%; font-family: monospace;">
      </div>
      <div class="fg" style="background: var(--bg1); padding: 15px; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 0;">
        <label class="fl" style="font-weight: 600; color: var(--t2); margin-bottom: 10px; display: block;"><i class="fas fa-user" style="margin-left: 8px; color: var(--primary);"></i>اسم المستخدم</label>
        <input type="text" id="xtUser" class="fi" placeholder="username" style="direction:ltr;text-align:left; border: 1px solid var(--br); border-radius: 8px; padding: 12px; width: 100%; font-family: monospace;">
      </div>
      <div class="fg" style="background: var(--bg1); padding: 15px; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 0;">
        <label class="fl" style="font-weight: 600; color: var(--t2); margin-bottom: 10px; display: block;"><i class="fas fa-key" style="margin-left: 8px; color: var(--primary);"></i>كلمة المرور</label>
        <input type="password" id="xtPass" class="fi" placeholder="password" style="direction:ltr;text-align:left; border: 1px solid var(--br); border-radius: 8px; padding: 12px; width: 100%; font-family: monospace;">
      </div>
    </div>

    <button type="button" class="btn btn-g" id="xtLoginBtn" style="width:100%; justify-content:center; padding: 16px; font-size: 1.1rem; font-weight: bold; border-radius: 12px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3); transition: all 0.3s; margin-top: 6px;" onclick="xtreamLogin()">
      <i class="fas fa-plug" style="margin-left: 10px;"></i> تسجيل الدخول والتحقق من الاتصال
    </button>
    <div id="xtLoginStatus" style="margin-top:15px; font-size:0.95rem; text-align: center; font-weight: 500;"></div>

    <!-- نتيجة الفحص + خيارات الاستيراد -->
    <div id="xtImportBox" style="display:none; margin-top:25px; border-top:2px dashed var(--br); padding-top:25px;">
      <div id="xtInfo" style="font-size:0.95rem; color:var(--t2); margin-bottom:20px; background: rgba(59, 130, 246, 0.1); padding: 15px; border-radius: 10px; border-right: 4px solid #3b82f6;"></div>
      
      <div class="fl" style="margin-bottom:15px; font-size: 1.1rem; font-weight: 600;">حدد المحتوى المراد استيراده:</div>
      
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:15px; margin-bottom:25px;">
        <label class="xt-chk" style="display:flex; flex-direction: column; align-items:center; gap:12px; cursor:pointer; background: var(--bg1); padding: 20px; border-radius: 12px; border: 2px solid transparent; box-shadow: 0 4px 10px rgba(0,0,0,0.05); transition: all 0.2s;" onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='transparent'">
          <input type="checkbox" id="xtImpLive" checked style="transform: scale(1.3);"> 
          <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
            <i class="fas fa-tv" style="font-size: 1.8rem; color: #3b82f6;"></i>
            <span id="xtLblLive" style="font-weight: 600; font-size: 1.1rem;">القنوات</span>
            <span style="font-size:.72rem; color:var(--t3); text-align:center;">← إدارة القنوات<br><small style="opacity:.75;">بث مباشر ts / m3u8</small></span>
          </div>
        </label>
        
        <label class="xt-chk" style="display:flex; flex-direction: column; align-items:center; gap:12px; cursor:pointer; background: var(--bg1); padding: 20px; border-radius: 12px; border: 2px solid transparent; box-shadow: 0 4px 10px rgba(0,0,0,0.05); transition: all 0.2s;" onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='transparent'">
          <input type="checkbox" id="xtImpVod" checked style="transform: scale(1.3);"> 
          <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
            <i class="fas fa-film" style="font-size: 1.8rem; color: #ef4444;"></i>
            <span id="xtLblVod" style="font-weight: 600; font-size: 1.1rem;">الأفلام</span>
            <span style="font-size:.72rem; color:var(--t3); text-align:center;">← إدارة شاشتي<br><small style="opacity:.75;">ملفات mp4 / mkv</small></span>
          </div>
        </label>
        
        <label class="xt-chk" style="display:flex; flex-direction: column; align-items:center; gap:12px; cursor:pointer; background: var(--bg1); padding: 20px; border-radius: 12px; border: 2px solid transparent; box-shadow: 0 4px 10px rgba(0,0,0,0.05); transition: all 0.2s;" onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='transparent'">
          <input type="checkbox" id="xtImpSeries" checked style="transform: scale(1.3);"> 
          <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
            <i class="fas fa-layer-group" style="font-size: 1.8rem; color: #f59e0b;"></i>
            <span id="xtLblSeries" style="font-weight: 600; font-size: 1.1rem;">المسلسلات</span>
            <span style="font-size:.72rem; color:var(--t3); text-align:center;">← إدارة شاشتي<br><small style="opacity:.75;">حلقات mp4 / mkv</small></span>
          </div>
        </label>
      </div>
      
      <!-- ══ لوحة تقدّم الاستيراد الحيّة ══ -->
      <div id="xtProgBox" style="display:none; margin-bottom:14px; background:var(--bg1); border:1px solid var(--br); border-radius:14px; padding:18px;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:12px; flex-wrap:wrap;">
          <span id="xtProgLabel" style="font-weight:700; color:var(--t1); font-size:.95rem; display:flex; align-items:center; gap:9px;">
            <span class="sp" style="width:15px;height:15px;border-width:2px;"></span>جارٍ التحضير...
          </span>
          <span id="xtProgPct" style="font-weight:800; color:#3b82f6; font-size:1.15rem; font-variant-numeric:tabular-nums;">0%</span>
        </div>

        <div style="height:11px; background:var(--bg2); border-radius:99px; overflow:hidden; position:relative;">
          <div id="xtProgFill" style="height:100%; width:0%; border-radius:99px; background:linear-gradient(90deg,#3b82f6,#8b5cf6,#3b82f6); background-size:200% 100%; animation:xtFlow 1.6s linear infinite; transition:width .45s cubic-bezier(.4,0,.2,1);"></div>
        </div>

        <div id="xtProgCount" style="margin-top:10px; font-size:.85rem; color:var(--t2); font-variant-numeric:tabular-nums;">—</div>

        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:12px;">
          <span class="xtchip"><i class="fas fa-tv"></i> قنوات: <b id="xtCntLive">0</b></span>
          <span class="xtchip"><i class="fas fa-film"></i> أفلام: <b id="xtCntVod">0</b></span>
          <span class="xtchip"><i class="fas fa-clapperboard"></i> مسلسلات: <b id="xtCntSer">0</b></span>
          <span class="xtchip" id="xtCntSkipWrap" style="display:none;"><i class="fas fa-forward"></i> متخطّى: <b id="xtCntSkip">0</b></span>
        </div>

        <div style="display:flex; justify-content:space-between; margin-top:12px; padding-top:11px; border-top:1px solid var(--br); font-size:.82rem; color:var(--t3); font-variant-numeric:tabular-nums;">
          <span><i class="far fa-clock" style="margin-left:5px;"></i>مضى: <b id="xtElapsed" style="color:var(--t2);">00:00</b></span>
          <span><i class="fas fa-hourglass-half" style="margin-left:5px;"></i>المتبقي تقريباً: <b id="xtEta" style="color:#10b981;">— يُحسب...</b></span>
        </div>
      </div>

      <button type="button" id="xtStopBtn" onclick="xtreamAbortImport()"
        style="display:none; width:100%; justify-content:center; align-items:center; padding:14px; margin-bottom:12px; font-size:1rem; font-weight:700; border-radius:12px; background:#ef4444; color:#fff; border:none; box-shadow:0 4px 15px rgba(239,68,68,.3); cursor:pointer; transition:all .25s; font-family:'Tajawal',sans-serif;">
        <i class="fas fa-hand" style="margin-left:8px;"></i>إيقاف الاستيراد إجبارياً
      </button>

      <button type="button" class="btn btn-p" id="xtImportBtn" style="width:100%; justify-content:center; padding: 16px; font-size: 1.1rem; font-weight: bold; border-radius: 12px; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; border: none; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3); transition: all 0.3s;" onclick="xtreamImport()">
        <i class="fas fa-cloud-download-alt" style="margin-left: 10px;"></i> استيراد وإضافة المحتوى
      </button>
      <div id="xtImportStatus" style="margin-top:15px; font-size:0.95rem; text-align: center; font-weight: 500;"></div>
    </div>
  </div>
  </div>

  <div class="tw" style="background: var(--bg2); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 8px 24px rgba(0,0,0,0.06); padding: 25px; overflow: hidden;">
    <div class="chdr" style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid var(--border);">
      <span class="ctitle" style="font-size: 1.2rem; font-weight: 600; display: flex; align-items: center; gap: 10px;">
        <div style="background: rgba(59, 130, 246, 0.1); width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; border-radius: 8px;"><i class="fas fa-list" style="color: #3b82f6;"></i></div>
        الحسابات المضافة
      </span>
    </div>
    
    <div id="xtLoading" style="padding:40px;text-align:center;color:var(--t3); font-size: 1.1rem;"><span class="sp" style="width: 30px; height: 30px; border-width: 3px; margin-bottom: 15px; border-top-color: var(--primary);"></span><br>جاري التحميل...</div>
    
    <div id="xtEmpty" class="empty" style="display:none; padding: 50px 20px; background: var(--bg1); border-radius: 12px; border: 1px dashed var(--br);">
      <div style="background: rgba(156, 163, 175, 0.1); width: 80px; height: 80px; display: flex; align-items: center; justify-content: center; border-radius: 50%; margin: 0 auto 20px auto;">
        <i class="fas fa-satellite-dish" style="font-size: 2.5rem; color: var(--t3);"></i>
      </div>
      <p style="font-size: 1.2rem; color: var(--t2); font-weight: 600; margin-bottom: 5px;">لا توجد حسابات مضافة بعد</p>
    </div>
    
    <div style="overflow-x: auto;">
      <table id="xtTbl" style="display:none; width: 100%; border-collapse: separate; border-spacing: 0 8px; white-space: nowrap;">
        <thead>
          <tr style="background: var(--bg1);">
            <th style="padding: 15px; border-radius: 0 8px 8px 0; font-weight: 600; color: var(--t2);">الاسم</th>
            <th style="padding: 15px; font-weight: 600; color: var(--t2);">العنوان / المستخدم</th>
            <th style="padding: 15px; font-weight: 600; color: var(--t2); text-align: center;"><i class="fas fa-network-wired" style="color: #8b5cf6; margin-left: 5px;"></i> اتصالات</th>
            <th style="padding: 15px; font-weight: 600; color: var(--t2); text-align: center;"><i class="fas fa-tv" style="color: #3b82f6; margin-left: 5px;"></i> محتوى</th>
            <th style="padding: 15px; font-weight: 600; color: var(--t2);"><i class="fas fa-calendar-alt" style="color: #10b981; margin-left: 5px;"></i> الانتهاء</th>
            <th style="padding: 15px; font-weight: 600; color: var(--t2);">المزامنة</th>
            <th style="padding: 15px; border-radius: 8px 0 0 8px; font-weight: 600; color: var(--t2); text-align: center;">إجراءات</th>
          </tr>
        </thead>
        <tbody id="xtBody"></tbody>
      </table>
    </div>
  </div>

  <!-- ══════════ تسريع: فهارس قاعدة البيانات ══════════ -->
  <div class="tw" style="margin-top:25px; background: var(--bg2); border: 1px solid rgba(16,185,129,.35); border-radius: 16px; padding: 22px;">
    <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px; color:#10b981; font-weight:700; font-size:1.05rem;">
      <div style="background:rgba(16,185,129,.12); width:34px; height:34px; display:flex; align-items:center; justify-content:center; border-radius:8px;"><i class="fas fa-bolt"></i></div>
      تسريع قاعدة البيانات
    </div>
    <p style="color:var(--t2); font-size:.9rem; line-height:1.85; margin:0 0 16px;">
      بدون فهارس، كل استعلام يمسح الجدول <strong>كاملاً</strong> — ومع عشرات آلاف الصفوف بعد استيراد Xtream
      يصبح هذا أبطأ جزء في الموقع. هذا الزر يضيف الفهارس الناقصة لجداول القنوات والمسلسلات والحلقات.
      <br><span style="color:var(--t3); font-size:.85rem;">آمن تماماً — لا يحذف أي بيانات. يكفي تشغيله مرة واحدة.</span>
    </p>
    <button id="xtOptBtn" class="btn" onclick="xtreamOptimizeDb()"
      style="background:#10b981; color:#fff; border:none; padding:11px 22px; border-radius:10px; font-family:'Tajawal',sans-serif; font-weight:700; font-size:.92rem; cursor:pointer;">
      <i class="fas fa-bolt" style="margin-left:8px;"></i> تطبيق الفهارس وتسريع الموقع
    </button>
    <div id="xtOptStatus" style="margin-top:13px; font-size:.9rem; font-weight:500;"></div>
  </div>

  <!-- ══════════ إصلاح: نقل الأفلام القديمة من القنوات إلى شاشتي ══════════ -->
  <div class="tw" style="margin-top:25px; background: var(--bg2); border: 1px solid rgba(245,158,11,.35); border-radius: 16px; padding: 22px;">
    <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px; color:#f59e0b; font-weight:700; font-size:1.05rem;">
      <div style="background:rgba(245,158,11,.12); width:34px; height:34px; display:flex; align-items:center; justify-content:center; border-radius:8px;"><i class="fas fa-wand-magic-sparkles"></i></div>
      إصلاح: نقل الأفلام إلى «شاشتي»
    </div>
    <p style="color:var(--t2); font-size:.9rem; line-height:1.85; margin:0 0 16px;">
      في الإصدارات السابقة كانت الأفلام تُستورد خطأً إلى <strong>إدارة القنوات</strong>.
      يبحث هذا الزر عن أي فيلم عالق هناك <span style="color:var(--t3);">(رابطه <code style="direction:ltr; display:inline-block;">/movie/</code>)</span>
      وينقله إلى <strong style="color:#10b981;">إدارة شاشتي</strong> مع صورته واسمه — بلا إعادة استيراد.
      <br><span style="color:var(--t3); font-size:.85rem;">القنوات المباشرة (ts / m3u8) لن تتأثر.</span>
    </p>
    <button id="xtFixVodBtn" class="btn" onclick="xtreamFixVod()"
      style="background:#f59e0b; color:#fff; border:none; padding:11px 22px; border-radius:10px; font-family:'Tajawal',sans-serif; font-weight:700; font-size:.92rem; cursor:pointer;">
      <i class="fas fa-arrows-turn-right" style="margin-left:8px;"></i> نقل الأفلام إلى شاشتي الآن
    </button>
    <div id="xtFixVodStatus" style="margin-top:13px; font-size:.9rem; font-weight:500;"></div>
  </div>

  <!-- ══════════ منطقة الخطر: مسح إجباري يدوي لكل ما يخص Xtream ══════════ -->
  <div class="tw" style="margin-top:25px; background: var(--bg2); border: 1px solid rgba(239,68,68,.35); border-radius: 16px; box-shadow: 0 8px 24px rgba(239,68,68,0.07); padding: 25px; overflow: hidden;">
    <div class="chdr" style="margin-bottom: 18px; padding-bottom: 15px; border-bottom: 1px solid rgba(239,68,68,.2);">
      <span class="ctitle" style="font-size: 1.2rem; font-weight: 600; display: flex; align-items: center; gap: 10px; color:#ef4444;">
        <div style="background: rgba(239, 68, 68, 0.12); width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; border-radius: 8px;"><i class="fas fa-triangle-exclamation" style="color: #ef4444;"></i></div>
        منطقة الخطر — مسح إجباري
      </span>
    </div>

    <!-- سطر تعريفي موجز -->
    <p style="color:var(--t2); font-size:.94rem; line-height:1.8; margin:0 0 20px;">
      عملية <strong style="color:#ef4444;">نهائية</strong> تمسح كل ما يخص Xtream من قاعدة البيانات دفعة واحدة.
      خصّصناها للحالات الاستثنائية فقط.
    </p>

    <!-- جدول: ما سيُحذف مقابل ما سيبقى -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(255px,1fr)); gap:14px; margin-bottom:20px;">

      <div style="background:var(--bg1); border:1px solid rgba(239,68,68,.22); border-radius:12px; padding:16px;">
        <div style="display:flex; align-items:center; gap:8px; color:#ef4444; font-weight:700; font-size:.9rem; margin-bottom:12px; padding-bottom:10px; border-bottom:1px solid rgba(239,68,68,.15);">
          <i class="fas fa-trash-can"></i><span>سيُحذف نهائياً</span>
        </div>
        <ul style="list-style:none; padding:0; margin:0; color:var(--t2); font-size:.87rem; line-height:2.1;">
          <li><i class="fas fa-xmark" style="color:#ef4444; margin-left:8px; font-size:.75rem;"></i>كل حسابات Xtream المضافة</li>
          <li><i class="fas fa-xmark" style="color:#ef4444; margin-left:8px; font-size:.75rem;"></i>كل القنوات المستوردة</li>
          <li><i class="fas fa-xmark" style="color:#ef4444; margin-left:8px; font-size:.75rem;"></i>كل الأفلام المستوردة</li>
          <li><i class="fas fa-xmark" style="color:#ef4444; margin-left:8px; font-size:.75rem;"></i>كل المسلسلات وحلقاتها</li>
          <li><i class="fas fa-xmark" style="color:#ef4444; margin-left:8px; font-size:.75rem;"></i>أقسام Xtream الفارغة</li>
          <li><i class="fas fa-xmark" style="color:#ef4444; margin-left:8px; font-size:.75rem;"></i>الكاش والمفضلة المرتبطة</li>
        </ul>
      </div>

      <div style="background:var(--bg1); border:1px solid rgba(16,185,129,.22); border-radius:12px; padding:16px;">
        <div style="display:flex; align-items:center; gap:8px; color:#10b981; font-weight:700; font-size:.9rem; margin-bottom:12px; padding-bottom:10px; border-bottom:1px solid rgba(16,185,129,.15);">
          <i class="fas fa-shield-halved"></i><span>لن يتأثر</span>
        </div>
        <ul style="list-style:none; padding:0; margin:0; color:var(--t2); font-size:.87rem; line-height:2.1;">
          <li><i class="fas fa-check" style="color:#10b981; margin-left:8px; font-size:.75rem;"></i>المحتوى المُضاف يدوياً</li>
          <li><i class="fas fa-check" style="color:#10b981; margin-left:8px; font-size:.75rem;"></i>قوائم M3U ومحتواها</li>
          <li><i class="fas fa-check" style="color:#10b981; margin-left:8px; font-size:.75rem;"></i>الفيديوهات المرفوعة</li>
          <li><i class="fas fa-check" style="color:#10b981; margin-left:8px; font-size:.75rem;"></i>الأقسام المشتركة مع مصادر أخرى</li>
          <li><i class="fas fa-check" style="color:#10b981; margin-left:8px; font-size:.75rem;"></i>الإعدادات وحسابات الأدمن</li>
        </ul>
      </div>

    </div>

    <!-- متى تستخدمه -->
    <div style="background:rgba(245,158,11,.07); border-right:3px solid #f59e0b; border-radius:8px; padding:13px 15px; margin-bottom:20px;">
      <div style="color:#f59e0b; font-weight:700; font-size:.85rem; margin-bottom:6px;">
        <i class="fas fa-lightbulb" style="margin-left:6px;"></i>متى تستخدمه؟
      </div>
      <div style="color:var(--t3); font-size:.84rem; line-height:1.9;">
        عند بقاء بيانات معلّقة أو أقسام فارغة بعد حذف حساب، أو عند تعطّل استيراد في منتصفه، أو إذا أردت البدء من الصفر.
      </div>
    </div>

    <!-- شريط التنفيذ -->
    <div style="background:var(--bg1); border:1px solid rgba(239,68,68,.25); border-radius:12px; padding:18px;">
      <div style="display:flex; align-items:flex-start; gap:9px; color:#ef4444; font-size:.87rem; font-weight:700; margin-bottom:14px;">
        <i class="fas fa-circle-exclamation" style="margin-top:3px;"></i>
        <span>لا يمكن التراجع عن هذه العملية بعد تنفيذها. تأكّد من وجود نسخة احتياطية إن كان المحتوى مهماً.</span>
      </div>
      <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap; padding-top:14px; border-top:1px solid rgba(239,68,68,.12);">
        <label style="display:flex; align-items:center; gap:9px; cursor:pointer; user-select:none; color:var(--t2); font-size:.89rem; font-weight:500;">
          <input type="checkbox" id="xtPurgeConfirm" onchange="document.getElementById('xtPurgeBtn').disabled = !this.checked;" style="width:17px; height:17px; cursor:pointer; accent-color:#ef4444;">
          أفهم العواقب وأرغب في المتابعة
        </label>
        <button id="xtPurgeBtn" class="btn" disabled onclick="xtreamPurgeAll()"
          style="background:#ef4444; color:#fff; border:none; padding:11px 22px; border-radius:10px; font-family:'Tajawal',sans-serif; font-weight:700; font-size:.92rem; cursor:pointer; transition:.18s; margin-right:auto;">
          <i class="fas fa-bomb" style="margin-left:8px;"></i>مسح كل محتوى Xtream
        </button>
      </div>
    </div>
    <div id="xtPurgeStatus" style="margin-top:14px; font-size:.93rem; font-weight:500;"></div>
  </div>
  <style>
    #xtPurgeBtn:disabled{opacity:.45; cursor:not-allowed;}
    #xtPurgeBtn:not(:disabled):hover{background:#dc2626; transform:translateY(-1px); box-shadow:0 6px 16px rgba(239,68,68,.3);}
    #xtStopBtn:not(:disabled):hover{background:#dc2626; transform:translateY(-1px); box-shadow:0 6px 18px rgba(239,68,68,.4);}
    #xtStopBtn:disabled{opacity:.6; cursor:wait;}
    #xtStopBtn{animation:xtPulse 2s ease-in-out infinite;}
    @keyframes xtPulse{
      0%,100%{box-shadow:0 4px 15px rgba(239,68,68,.3);}
      50%{box-shadow:0 4px 22px rgba(239,68,68,.55);}
    }
    /* شريط التقدّم الحيّ */
    @keyframes xtFlow{0%{background-position:200% 0;}100%{background-position:-200% 0;}}
    .xtchip{display:inline-flex; align-items:center; gap:6px; background:var(--bg2); border:1px solid var(--br); border-radius:99px; padding:5px 12px; font-size:.8rem; color:var(--t2); font-variant-numeric:tabular-nums;}
    .xtchip b{color:var(--t1); font-weight:800;}
    .xtchip i{font-size:.72rem; opacity:.75;}
  </style>
</section>
<!-- [XTREAM-SECTION-END] -->

<!-- API SETTINGS -->
<section id="api-settings" class="sec">
  <div class="shdr"><h1 class="stitle">إعدادات <span>API</span></h1></div>
  <div class="sw-wrap" style="max-width:800px">
    <!-- Add new API -->
    <div style="display:flex;gap:15px;margin-bottom:25px;align-items:center;background:var(--bg2);padding:18px 22px;border-radius:var(--r2);border:1px solid var(--border);box-shadow:var(--shadow)">
      <div style="display:flex;align-items:center;gap:10px;color:var(--t2);font-weight:700;white-space:nowrap;font-size:1.05rem">
        <i class="fas fa-plus-circle" style="color:var(--primary)"></i> إضافة اتصال API جديد:
      </div>
      <select id="apiTypeSelect" class="fi" style="flex:1;max-width:350px;font-size:0.95rem">
        <option value="" disabled selected>-- اختر نوع الخدمة --</option>
        <option value="tmdb">TMDB API (معلومات الأفلام والمسلسلات)</option>
        <option value="os">OpenSubtitles API (الترجمات)</option>
        <option value="omdb">OMDb API (قاعدة بيانات الأفلام)</option>
      </select>
      <button class="btn btn-p" type="button" onclick="addApiCard()" style="padding:10px 20px;font-weight:600"><i class="fas fa-plus"></i> إضافة</button>
    </div>

    <!-- TMDB API Card -->
    <div class="swc" id="card_tmdb" style="box-shadow:var(--shadow); <?php echo empty($settings['tmdb_api_key']) ? 'display:none;' : ''; ?>">
      <div class="swc-hd" style="border-bottom:1px solid var(--border);padding:18px 22px">
        <div class="swc-title" style="font-size:1.1rem;font-weight:700"><i class="fas fa-film" style="color:var(--gold);margin-left:10px;font-size:1.2rem"></i>TMDB API</div>
      </div>
      <div class="swc-body" style="padding:22px">
        <p style="color:var(--t3);font-size:0.9rem;margin-bottom:20px;line-height:1.6">استخدم هذا المفتاح لجلب بوسترات ومعلومات الأفلام والمسلسلات تلقائياً من موقع TMDB.</p>
        <div class="fg">
          <label class="fl" style="font-weight:600">مفتاح TMDB API</label>
          <input type="text" id="api_tmdb_key" class="fi" placeholder="أدخل مفتاح TMDB API هنا" value="<?php echo htmlspecialchars($settings['tmdb_api_key'] ?? ''); ?>" style="direction:ltr;font-size:0.95rem;padding:12px">
        </div>
        <div style="margin-top:15px;font-size:.8rem;color:var(--t3);background:rgba(76,201,240,0.05);padding:12px;border-radius:var(--r1);border:1px solid rgba(76,201,240,0.2)">
          <i class="fas fa-link" style="color:#4CC9F0"></i>
          رابط الحصول على المفتاح: <a href="https://www.themoviedb.org/settings/api" target="_blank" style="color:#4CC9F0;text-decoration:underline;direction:ltr;display:inline-block">https://www.themoviedb.org/settings/api</a>
        </div>
        
        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:25px;padding-top:20px;border-top:1px solid var(--border);">
          <div id="status_tmdb" style="font-size:0.95rem;font-weight:600"></div>
          <div style="display:flex;gap:12px">
            <button class="btn btn-s" type="button" onclick="testApiTmdb()" style="padding:8px 16px"><i class="fas fa-wifi"></i> فحص الاتصال</button>
            <button class="btn btn-d" type="button" onclick="removeApiCard('tmdb')" style="padding:8px 16px;background:rgba(229,9,20,0.1);color:var(--red);border:1px solid rgba(229,9,20,0.2)"><i class="fas fa-trash-alt"></i> إزالة</button>
          </div>
        </div>
      </div>
    </div>

    <!-- OpenSubtitles API Card -->
    <div class="swc" id="card_os" style="box-shadow:var(--shadow); <?php echo (empty($settings['os_username']) && empty($settings['os_api_key'])) ? 'display:none;' : ''; ?>">
      <div class="swc-hd" style="border-bottom:1px solid var(--border);padding:18px 22px">
        <div class="swc-title" style="font-size:1.1rem;font-weight:700"><i class="fas fa-closed-captioning" style="color:#4CC9F0;margin-left:10px;font-size:1.2rem"></i>OpenSubtitles API</div>
      </div>
      <div class="swc-body" style="padding:22px">
        <p style="color:var(--t3);font-size:0.9rem;margin-bottom:20px;line-height:1.6">استخدم هذه الإعدادات لتسجيل الدخول التلقائي والبحث عن الترجمات من موقع OpenSubtitles.</p>
        <div class="row2">
            <div class="fg">
              <label class="fl" style="font-weight:600">اسم المستخدم</label>
              <input type="text" id="api_os_user" class="fi" placeholder="username" value="<?php echo htmlspecialchars($settings['os_username'] ?? ''); ?>" style="direction:ltr;padding:12px">
            </div>
            <div class="fg">
              <label class="fl" style="font-weight:600">كلمة المرور</label>
              <input type="password" id="api_os_pass" class="fi" placeholder="••••••••" value="<?php echo htmlspecialchars($settings['os_password'] ?? ''); ?>" style="direction:ltr;padding:12px">
            </div>
        </div>
        <div class="fg">
          <label class="fl" style="font-weight:600">مفتاح API</label>
          <input type="text" id="api_os_key" class="fi" placeholder="aBcDeF..." value="<?php echo htmlspecialchars($settings['os_api_key'] ?? ''); ?>" style="direction:ltr;font-size:0.95rem;padding:12px">
        </div>
        <div style="margin-top:15px;font-size:.8rem;color:var(--t3);background:rgba(76,201,240,0.05);padding:12px;border-radius:var(--r1);border:1px solid rgba(76,201,240,0.2)">
          <i class="fas fa-link" style="color:#4CC9F0"></i>
          رابط الحصول على المفتاح: <a href="https://www.opensubtitles.com/en/consumers" target="_blank" style="color:#4CC9F0;text-decoration:underline;direction:ltr;display:inline-block">https://www.opensubtitles.com/en/consumers</a>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:25px;padding-top:20px;border-top:1px solid var(--border);">
          <div id="status_os" style="font-size:0.95rem;font-weight:600"></div>
          <div style="display:flex;gap:12px">
            <button class="btn btn-s" type="button" onclick="testApiOs()" style="padding:8px 16px"><i class="fas fa-wifi"></i> فحص الاتصال</button>
            <button class="btn btn-d" type="button" onclick="removeApiCard('os')" style="padding:8px 16px;background:rgba(229,9,20,0.1);color:var(--red);border:1px solid rgba(229,9,20,0.2)"><i class="fas fa-trash-alt"></i> إزالة</button>
          </div>
        </div>
      </div>
    </div>

    <!-- OMDb API Card -->
    <div class="swc" id="card_omdb" style="box-shadow:var(--shadow); <?php echo empty($settings['omdb_api_key']) ? 'display:none;' : ''; ?>">
      <div class="swc-hd" style="border-bottom:1px solid var(--border);padding:18px 22px">
        <div class="swc-title" style="font-size:1.1rem;font-weight:700"><i class="fas fa-database" style="color:var(--gold);margin-left:10px;font-size:1.2rem"></i>OMDb API</div>
      </div>
      <div class="swc-body" style="padding:22px">
        <p style="color:var(--t3);font-size:0.9rem;margin-bottom:20px;line-height:1.6">مفتاح للبحث عن الأفلام والمسلسلات من OMDb.</p>
        <div class="fg">
          <label class="fl" style="font-weight:600">مفتاح OMDb API</label>
          <input type="text" id="api_omdb_key" class="fi" placeholder="أدخل مفتاح OMDb API" value="<?php echo htmlspecialchars($settings['omdb_api_key'] ?? ''); ?>" style="direction:ltr;font-size:0.95rem;padding:12px">
        </div>
        <div style="margin-top:15px;font-size:.8rem;color:var(--t3);background:rgba(76,201,240,0.05);padding:12px;border-radius:var(--r1);border:1px solid rgba(76,201,240,0.2)">
          <i class="fas fa-link" style="color:#4CC9F0"></i>
          رابط الحصول على المفتاح: <a href="https://www.omdbapi.com/apikey.aspx" target="_blank" style="color:#4CC9F0;text-decoration:underline;direction:ltr;display:inline-block">https://www.omdbapi.com/apikey.aspx</a>
        </div>
        
        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:25px;padding-top:20px;border-top:1px solid var(--border);">
          <div id="status_omdb" style="font-size:0.95rem;font-weight:600"></div>
          <div style="display:flex;gap:12px">
            <button class="btn btn-s" type="button" onclick="testApiOmdb()" style="padding:8px 16px"><i class="fas fa-wifi"></i> فحص الاتصال</button>
            <button class="btn btn-d" type="button" onclick="removeApiCard('omdb')" style="padding:8px 16px;background:rgba(229,9,20,0.1);color:var(--red);border:1px solid rgba(229,9,20,0.2)"><i class="fas fa-trash-alt"></i> إزالة</button>
          </div>
        </div>
      </div>
    </div>

    <button class="btn btn-p" type="button" onclick="saveApiSettings()" style="width:100%;justify-content:center;padding:16px;font-size:1.1rem;font-weight:bold;box-shadow:var(--shadow)"><i class="fas fa-save" style="margin-left:8px;font-size:1.2rem"></i> حفظ جميع إعدادات API</button>
    <div id="apiSaveAlert" style="margin-top:14px"></div>
  </div>
</section>

<!-- SERIES -->
<section id="series" class="sec">
  <div class="shdr"><h1 class="stitle">إدارة <span>شاشتي</span></h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-g" id="srBackBtn" style="display:none" onclick="srBack()"><i class="fas fa-arrow-right"></i>رجوع</button>
      <button class="btn btn-v" id="srBulkBtn" style="display:none" onclick="OM('bulkM')"><i class="fas fa-folder-open"></i>رفع مجلد كامل</button>
      <button class="btn btn-p" id="srAddBtn" onclick="OM('addSeriesM')"><i class="fas fa-plus"></i>مسلسل / فيلم جديد</button>
    </div>
  </div>
  <div id="srBreadcrumb" style="display:none;align-items:center;gap:8px;margin-bottom:18px;font-size:.855rem;color:var(--t3)"><span style="cursor:pointer;color:#4CC9F0" onclick="srBack()">شاشتي</span><i class="fas fa-chevron-left" style="font-size:.62rem"></i><strong id="srBCName" style="color:var(--t1)"></strong><span class="bdg bp" id="srBCCount" style="margin-right:6px"></span></div>
  <div id="srFilterBar" style="display:flex;gap:8px;align-items:center;margin-bottom:18px;flex-wrap:wrap">
    <select class="fs" id="srCatFilter" style="max-width:200px" onchange="loadSeries()"><option value="">كل الأقسام</option><?php foreach($categories as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option><?php endforeach; ?></select>
    <div class="tsrch" style="max-width:230px;flex:1"><i class="fas fa-search"></i><input type="text" id="srSearch" placeholder="بحث عن فيلم/مسلسل..." oninput="srFilter()"></div>
    <span id="srCount" style="font-size:.78rem;color:var(--t3);margin-right:auto"></span>
  </div>
  <div id="srLoading" style="display:none;text-align:center;padding:50px;color:var(--t3)"><div class="pspin" style="margin:0 auto 12px"></div><p>جارٍ التحميل…</p></div>
  <div id="srGrid" class="srgrid"></div>
  <div id="srEmpty" style="display:none" class="empty"><i class="fas fa-film"></i><p>لا توجد بيانات بعد</p></div>
  <div id="epsPanel" style="display:none">
    <div class="tw">
      <div class="tt" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; background:var(--s1)">
          <span style="font-weight:900;font-size:1.05rem;"><i class="fas fa-list-ul" style="color:var(--red); margin-left:8px"></i> إدارة عناصر ومقاطع العمل</span>
          
          <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
              <span style="font-size:0.72rem; color:var(--gold); border:1px solid rgba(245, 166, 35, 0.2); background:rgba(245, 166, 35, 0.1); padding:4px 8px; border-radius:4px;"><i class="fas fa-mouse-pointer"></i> ادعمنا بـ السحب والافلات للترتيب (يُحفظ تلقائياً)</span>
              
              <select class="fs" id="epSortAZ" onchange="_sortAndSaveEps()" style="width:auto; padding:6px; font-size:0.75rem; font-weight:bold; cursor:pointer">
                  <option value="def">📅 ترتيب: حسب الإضافة للخادم</option>
                  <option value="az">✨ الفرز: تصاعدي الشامل (A-Z)</option>
                  <option value="za">✨ الفرز: تنازلي الشامل (Z-A)</option>
                  <option value="manual" disabled style="background:#000;color:var(--t3)">🤚 مُهندس يدويا (إفلات وماوس)</option>
              </select>
              
              <button class="btn btn-p bsm" id="delBulkBtn" style="display:none; background:#ff4d57; color:#fff" onclick="deleteCheckedEps()">
                <i class="fas fa-trash-alt"></i> نسف التحديد
              </button>
                       <button class="btn btn-p bsm" id="convertMp4Btn" style="display:none; background:rgba(179,107,255,1); color:#fff; margin-right:8px; box-shadow:0 4px 14px rgba(179,107,255,.3);" onclick="convertCheckedEpsToMp4()">
   <i class="fas fa-magic"></i> التحويل السريع لـ MP4
</button>
              
          </div>
      </div>
      <table><thead><tr><th style="width:30px;"><input type="checkbox" id="chkEpsMaster" onclick="toggleChkEps(this)" style="cursor:pointer; width:16px;height:16px; accent-color:var(--red);"></th><th>العرض</th><th>اسم العمل</th><th>امتداد الاستضافة</th><th>تشفير لغة</th><th>المدة</th><th>مـزايـــا</th></tr></thead><tbody id="epsTbody"></tbody></table>
      <div id="epsEmpty" style="display:none" class="empty"><i class="fas fa-film"></i><p>لا توجد حلقات/فيديوهات</p></div>
    </div>
  </div>
</section>

<!-- VIDEO UPLOAD -->
<section id="vupload" class="sec">
  <div class="shdr"><h1 class="stitle">رفع <span>الأفلام</span></h1></div>
  <div class="vsteps"><div class="vs act" id="vs1"><div class="vs-n">1</div>رفع الفيديو</div><div class="vs" id="vs2"><div class="vs-n">2</div>الترجمة</div><div class="vs" id="vs3"><div class="vs-n">3</div>الحفظ</div></div>
  <div class="vp act" id="vp1">
    <div class="vc"><div class="vchd"><div class="vchd-title"><i class="fas fa-cloud-upload-alt"></i>اختر ملف الفيديو</div></div>
      <div class="vcbody">
        
        <div id="vtab-file">
          <div class="uz" id="vidDZ"><input type="file" id="vidFileIn" accept="video/*" onchange="vidUpload(this)"><i class="fas fa-film"></i><h3>اسحب الفيديو هنا أو انقر للاختيار</h3><p>MP4 · MKV · AVI · MOV · WebM</p></div>
        </div>

        <div id="vidProg" style="display:none;margin-top:12px">
            <div style="display:flex;align-items:center;gap:9px;margin-bottom:6px">
                <span class="sp" id="vidProgSp"></span>
                <span id="vidPLabel" style="font-size:.8rem;color:var(--t2);flex:1">جارٍ الرفع…</span>
                <span id="vidPct" style="font-size:.75rem;color:var(--t3)">0%</span>
                <button class="btn btn-g bsm" id="cancelDlBtn" style="display:none;padding:2px 8px;font-size:0.7rem;color:#ff6b6b;border-color:rgba(229,9,20,.3)"><i class="fas fa-times"></i> إلغاء</button>
            </div>
            <div class="pw"><div class="pb" id="vidPBar"></div></div>
        </div>
        <div class="chip" id="vidChip"><div style="width:36px;height:36px;background:rgba(229,9,20,.1);border-radius:7px;display:flex;align-items:center;justify-content:center;color:var(--red);flex-shrink:0"><i class="fas fa-film"></i></div><div style="flex:1;min-width:0"><div id="vidChipName" style="font-weight:700;font-size:.855rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">—</div><div id="vidChipSize" style="font-size:.74rem;color:var(--t3)">—</div></div><div onclick="vidReset()" style="width:26px;height:26px;background:var(--s3);border:1px solid var(--br);border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--t3);flex-shrink:0"><i class="fas fa-times"></i></div></div>
        <div id="v1alert" style="margin-top:10px"></div>
        <div style="margin-top:8px;text-align:left"><button class="btn btn-g bsm" onclick="vidDebug()" style="font-size:.72rem;opacity:.6"><i class="fas fa-bug"></i> فحص إعدادات الخادم</button></div>
        <div id="v1debug" style="display:none;margin-top:8px;background:var(--s2);border:1px solid var(--br);border-radius:var(--r1);padding:12px;font-size:.75rem;font-family:monospace;color:var(--t3)"></div>
      </div>
    </div>
    <div class="vnavb"><span></span><button class="btn btn-p" id="vNext1" disabled onclick="vidGo(2)">التالي: الترجمة <i class="fas fa-arrow-left"></i></button></div>
  </div>
  <div class="vp" id="vp2">
    <div class="vc"><div class="vchd"><div class="vchd-title"><i class="fas fa-closed-captioning"></i>خيارات الترجمة</div></div>
      <div class="vcbody">
        <div class="sub-opts">
          <div class="so sel" id="so-none" onclick="vidSubOpt('none')"><div class="so-ic">🎬</div><div class="so-lbl">بدون ترجمة</div><div class="so-desc">حفظ بدون ترجمة</div></div>
          <div class="so" id="so-search" onclick="vidSubOpt('search')"><div class="so-ic">🔍</div><div class="so-lbl">بحث OpenSubtitles</div><div class="so-desc">ابحث بالاسم</div></div>
          <div class="so" id="so-upload" onclick="vidSubOpt('upload')"><div class="so-ic">📁</div><div class="so-lbl">رفع ترجمة</div><div class="so-desc">SRT · ASS · VTT</div></div>
        </div>
      </div>
    </div>
    <div class="vc" id="osCard" style="display:none">
      <div class="vchd"><div class="vchd-title"><i class="fas fa-search"></i>OpenSubtitles</div></div>
      <div class="vcbody">
        <div id="osNL" style="display:<?php echo $os_logged?'none':'block'; ?>">
          <div class="os-info"><i class="fas fa-key" style="color:#4CC9F0;margin-left:5px"></i>يتم سحب البيانات من قسم (إعدادات API) لتسجيل الدخول التلقائي.</div>
          <div class="row2">
            <div><label class="fl">اسم المستخدم</label><input type="text" class="fi" id="osU" placeholder="username" value="<?php echo htmlspecialchars($settings['os_username'] ?? ''); ?>"></div>
            <div><label class="fl">كلمة المرور</label><input type="password" class="fi" id="osP" placeholder="••••••••" value="<?php echo htmlspecialchars($settings['os_password'] ?? ''); ?>" onkeydown="if(event.key==='Enter')osLogin()"></div>
          </div>
          <div class="fg" style="margin-top:12px"><label class="fl">مفتاح API</label><input type="text" class="fi" id="osApiKey" placeholder="aBcDeF..." value="<?php echo htmlspecialchars($settings['os_api_key'] ?? ''); ?>"></div>
          <button class="btn btn-p" style="width:100%;justify-content:center;padding:11px" onclick="osLogin()" id="osLBtn"><i class="fas fa-sign-in-alt"></i>تسجيل الدخول</button>
          <div id="osLAlert" style="margin-top:8px"></div>
        </div>
        <div id="osL" style="display:<?php echo $os_logged?'flex':'none'; ?>;align-items:center;gap:9px;margin-bottom:12px;padding:10px 13px;background:rgba(0,208,132,.07);border:1px solid rgba(0,208,132,.22);border-radius:var(--r1)">
          <i class="fas fa-check-circle" style="color:#00D084;font-size:1.1rem"></i>
          <span style="flex:1;font-size:.83rem">مسجّل: <strong id="osLUser"><?php echo htmlspecialchars($os_user); ?></strong></span>
          <button class="btn btn-g bsm" onclick="osLogout()"><i class="fas fa-sign-out-alt"></i>خروج</button>
        </div>
        <label class="fl">اسم الفيلم للبحث</label>
        <div class="srow">
          <div class="sinp"><i class="fas fa-film"></i><input type="text" id="osQ" placeholder="اسم الفيلم…" onkeydown="if(event.key==='Enter')osSearch()"></div>
          <select class="lsel" id="osLang"><option value="ar">🇸🇦 عربي</option><option value="en">🇬🇧 English</option><option value="fr">🇫🇷 Français</option><option value="es">🇪🇸 Español</option><option value="de">🇩🇪 Deutsch</option><option value="tr">🇹🇷 Türkçe</option></select>
          <button class="btn btn-p" onclick="osSearch()" id="osSearchBtn"><i class="fas fa-search"></i>بحث</button>
        </div>
        <div id="osAl" style="margin-top:8px"></div>
        <div class="sub-rl" id="osRes"></div>
        <div class="sub-chip" id="selSubChip"><i class="fas fa-check-circle"></i><strong id="selSubName">—</strong><button class="btn btn-g bsm" onclick="clearSub()" style="margin-right:auto"><i class="fas fa-times"></i>إلغاء</button></div>
      </div>
    </div>
    <div class="vc" id="subUpCard" style="display:none">
      <div class="vchd"><div class="vchd-title"><i class="fas fa-upload"></i>رفع ملف ترجمة</div></div>
      <div class="vcbody">
        <div class="uz" style="padding:26px"><input type="file" id="subFileIn" accept=".srt,.ass,.ssa,.vtt" onchange="subFileUpload(this)"><i class="fas fa-file-alt"></i><h3>اختر ملف الترجمة</h3><p>SRT · ASS · SSA · VTT</p></div>
        <div class="sub-chip" id="upSubChip" style="margin-top:10px"><i class="fas fa-check-circle"></i><strong id="upSubName">—</strong></div>
        <div id="subAl" style="margin-top:8px"></div>
      </div>
    </div>
    <div class="vnavb"><button class="btn btn-g" onclick="vidGo(1)"><i class="fas fa-arrow-right"></i>السابق</button><button class="btn btn-p" onclick="vidGo(3)">التالي <i class="fas fa-arrow-left"></i></button></div>
  </div>
  <div class="vp" id="vp3">
    <div class="vc"><div class="vchd"><div class="vchd-title"><i class="fas fa-save"></i>الحفظ في شاشتي</div></div>
      <div class="vcbody">
        <div class="merge-sum"><div class="mr"><div class="ml">الفيديو</div><div class="mv" id="mSumV">—</div></div><div class="mr"><div class="ml">الترجمة</div><div class="mv" id="mSumS">بدون ترجمة</div></div></div>
        
        <div class="fg" style="background:rgba(245,166,35,.07);padding:14px;border:1px solid rgba(245,166,35,.2);border-radius:var(--r1)">
            <label class="fl" style="color:var(--gold);margin-bottom:10px;"><i class="fas fa-folder"></i> تحديد المجلد الوجهة في شاشتي</label>
            <select class="fs" id="vTargetSeries" onchange="vToggleSeriesFields(this.value, 'upload')"></select>
        </div>

        <div class="fg"><label class="fl" id="vNameLabel">اسم العمل/المجلد الجديد <small style='color:#00D084'>(مطلوب)</small></label><input type="text" class="fi" id="vChanName" placeholder="أدخل اسم الفيلم / عنوان الحلقة"></div>
        <div class="fg" id="vCatDiv"><label class="fl">القسم (التصنيف)</label><select class="fs" id="vChanCat"><option value="">— اختر القسم —</option><?php foreach($categories as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option><?php endforeach; ?></select></div>
        <div id="v3alert" style="margin-bottom:10px"></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-s" style="flex:1;justify-content:center;padding:12px" onclick="vidSave()"><i class="fas fa-check"></i>حفظ في شاشتي الآن</button></div>
        
        <div id="vidResult" style="display:none;margin-top:14px;background:rgba(0,208,132,.07);border:1px solid rgba(0,208,132,.25);border-radius:var(--r2);padding:20px;text-align:center"><div style="font-size:2rem;color:#00D084;margin-bottom:8px"><i class="fas fa-check-circle"></i></div><h3 style="margin-bottom:4px">تم الحفظ بنجاح! 🎉</h3><p id="vidResultInfo" style="font-size:.8rem;color:var(--t3);margin-bottom:14px"></p><button class="btn btn-g" onclick="S('series');loadSeries();"><i class="fas fa-film"></i>انتقل لإدارة شاشتي</button></div>
      </div>
    </div>
    <div class="vnavb"><button class="btn btn-g" onclick="vidGo(2)"><i class="fas fa-arrow-right"></i>السابق</button><span></span></div>
  </div>
</section>

<!-- VIDEO MANAGE -->
<section id="vmanage" class="sec">
  <div class="shdr"><h1 class="stitle">إدارة <span>الفيديوهات</span></h1><button class="btn btn-p" onclick="S('vupload')"><i class="fas fa-plus"></i>رفع جديد</button></div>
  <div style="display:flex;gap:9px;align-items:center;margin-bottom:18px;flex-wrap:wrap">
    <div class="tsrch" style="max-width:250px;flex:1"><i class="fas fa-search"></i><input type="text" id="vmSearch" placeholder="بحث…" oninput="vmFilter()"></div>
    <select class="fs" id="vmType" style="width:150px" onchange="vmFilter()">
        <option value="all">كل المجلدات الفعالة</option>
        <option value="uploaded">الرفع العام (المُعلقة)</option>
        <option value="merged">المدمجة والمعدلة</option>
        <option value="series">شاشتي (المسلسلات)</option>
    </select>
    <button class="btn btn-g" onclick="vmLoad()"><i class="fas fa-sync-alt"></i>تحديث</button>
    <span id="vmCnt" style="font-size:.78rem;color:var(--t3);margin-right:auto"></span>
  </div>
  <div id="vmLoad" style="text-align:center;padding:50px;color:var(--t3)"><div class="pspin" style="margin:0 auto 12px"></div><p>جارٍ التحميل…</p></div>
  <div id="vmEmpty" style="display:none" class="empty"><i class="fas fa-film"></i><p>لا توجد فيديوهات</p><button class="btn btn-p" style="margin-top:14px" onclick="S('vupload')"><i class="fas fa-plus"></i>ارفع الآن</button></div>
  <div id="vmGrid" class="vmgrid" style="display:none"></div>
  <input type="file" id="vmSubUp" accept=".srt,.ass,.ssa,.vtt" style="display:none" onchange="vmHandleSubUp(this)">
</section>

<!-- SETTINGS -->
<section id="site-settings" class="sec">
  <div class="shdr"><h1 class="stitle">إعدادات <span>الموقع</span></h1></div>
  <div style="display:flex;align-items:center;justify-content:center;min-height:280px"><div style="text-align:center"><div style="width:64px;height:64px;background:rgba(229,9,20,.1);border-radius:var(--r3);display:flex;align-items:center;justify-content:center;font-size:1.6rem;color:var(--red);margin:0 auto 18px"><i class="fas fa-cog"></i></div><h3 style="margin-bottom:7px">إعدادات الموقع</h3><p style="color:var(--t3);margin-bottom:20px">تخصيص شامل للموقع</p><a href="admin_site_settings.php" class="btn btn-p"><i class="fas fa-external-link-alt"></i>فتح الإعدادات</a></div></div>
</section>

<!-- PASSWORD -->
<section id="change-password" class="sec">
  <div class="shdr"><h1 class="stitle">كلمة <span>المرور</span></h1></div>
  <?php if(isset($_SESSION['pw_ok'])): ?><div class="al al-s"><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($_SESSION['pw_ok'], ENT_QUOTES, 'UTF-8');unset($_SESSION['pw_ok']); ?></div><?php endif; ?>
  <?php if(isset($_SESSION['pw_err'])): ?><div class="al al-e"><i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($_SESSION['pw_err'], ENT_QUOTES, 'UTF-8');unset($_SESSION['pw_err']); ?></div><?php endif; ?>
  <div class="sw-wrap"><div class="swc"><div class="swc-hd"><div class="swc-title">تغيير كلمة المرور</div></div><div class="swc-body">
    <form method="POST"><div class="fg"><label class="fl">كلمة المرور الحالية</label><input type="password" name="current_password" class="fi" required placeholder="••••••••"></div><div class="fg"><label class="fl">كلمة المرور الجديدة</label><input type="password" name="new_password" class="fi" required minlength="6" placeholder="6 أحرف على الأقل"></div><div class="fg"><label class="fl">تأكيد كلمة المرور</label><input type="password" name="confirm_password" class="fi" required minlength="6" placeholder="أعد الكتابة"></div><button type="submit" name="change_password" class="btn btn-p" style="width:100%;justify-content:center;padding:12px"><i class="fas fa-save"></i>حفظ</button></form>
    <div class="info-b"><div class="info-b-title"><i class="fas fa-shield-alt"></i> نصائح الأمان</div><p style="font-size:.8rem;color:var(--t3)">• 6 أحرف على الأقل<br>• امزج أحرفاً وأرقاماً ورموزاً</p></div>
  </div></div></div>
</section>

<!-- TOOLS -->
<section id="system-tools" class="sec">
  <div class="shdr"><h1 class="stitle">صيانة <span>النظام</span></h1></div>
<!-- START: TAILSCALE UI (NETFLIX PREMIUM UI) -->
<div class="card" style="margin-bottom: 25px; border-color: rgba(229, 9, 20, 0.4); box-shadow: 0 0 20px rgba(229, 9, 20, 0.1);">
   <div class="chdr" style="background: rgba(229, 9, 20, 0.05); border-bottom: 1px solid rgba(229, 9, 20, 0.2);">
       <span class="ctitle"><i class="fas fa-satellite-dish" style="color:var(--red);margin-left:8px"></i>قناة الإتصال الخاصة (Tailscale VPN)</span>
       <span id="ts_display_status" style="font-size:0.75rem; font-weight:800; padding:3px 10px; border-radius:100px; background: rgba(229,9,20,.15); color: var(--red); border: 1px solid rgba(229,9,20,.3); float:left;">غير متصل OFFLINE</span>
   </div>
   <div class="cbody" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
       <div style="flex:1;">
           <div style="color: var(--t1); font-size: 1.1rem; font-weight:700; margin-bottom: 5px;">Remote Access Tunnel</div>
           <div style="color: var(--t3); font-size: 0.85rem;">فتح مسار اتصال محمي لربط السيرفر وتجاوز قيود الجدار الناري بكل أمان.</div>
           <div id="ts_ip_wrap" style="display:none; margin-top:10px;">
               <span style="font-family:'Courier New', monospace; font-size:0.8rem; background:rgba(0,208,132,.1); border:1px dashed rgba(0,208,132,.4); color:#00D084; padding:5px 12px; border-radius:var(--r1);">
                   <i class="fas fa-wifi"></i> آيبي السيرفر المخفي: <b id="ts_ip_val">...</b>
               </span>
           </div>
       </div>
       
       <div>
           <button id="ts_display_btn" onclick="executeTailscaleAction()" class="btn btn-g" style="padding: 10px 24px; font-size: 1rem; display:flex; align-items:center; gap:8px; border-width: 2px;">
               <span id="ts_btn_label">تشغيل الاتصال</span> <i class="fas fa-power-off"></i>
           </button>
       </div>
   </div>
</div>
<!-- END: TAILSCALE UI -->

  <div class="tgrid">
    <div class="tc b" onclick="location.href='update.php'"><div class="tc-ic"><i class="fas fa-sync-alt"></i></div><div class="tc-name">تحديث النظام</div><div class="tc-desc">الترقية إلى أحدث إصدار متاح</div></div>
    <div class="tc g" onclick="location.href='backup_system.php?action=export_full'"><div class="tc-ic"><i class="fas fa-database"></i></div><div class="tc-name">نسخ احتياطي</div><div class="tc-desc">تصدير كامل لقاعدة البيانات</div></div>
    <div class="tc p" onclick="S('backup')"><div class="tc-ic"><i class="fas fa-upload"></i></div><div class="tc-name">استيراد نسخة</div><div class="tc-desc">استعادة البيانات من ملف SQL</div></div>
    <div class="tc r" onclick="location.href='activate.php'"><div class="tc-ic"><i class="fas fa-key"></i></div><div class="tc-name">الترخيص</div><div class="tc-desc">عرض وتجديد الترخيص</div></div>
  
    <!-- الإضافات الجديدة المضافة بواسطة التحديث الآلي -->
    <div class="tc g" onclick="location.href='/act/index.php'"><div class="tc-ic"><i class="fas fa-certificate"></i></div><div class="tc-name">تسجيل الترخيص</div><div class="tc-desc">إضافة ومتابعة بيانات التفعيل</div></div>
    
    <div class="tc p" onclick="location.href='storage_manager.php'"><div class="tc-ic"><i class="fas fa-hdd"></i></div><div class="tc-name">إدارة الهارد دسك</div><div class="tc-desc">لوحة دمج ومراقبة المساحة (Storage)</div></div>
    
  </div>
</section>

<!-- BACKUP -->
<section id="backup" class="sec">
  <div class="shdr"><h1 class="stitle">النسخ <span>الاحتياطي</span></h1></div>
  <div class="bkgrid">
    <div class="bkc"><div class="bkc-title"><i class="fas fa-upload"></i> استعادة نسخة احتياطية</div><p style="color:var(--t3);font-size:.83rem;margin-bottom:18px">اختر ملف SQL لاسترجاع كافة البيانات.</p><form action="backup_system.php?action=import" method="POST" enctype="multipart/form-data" style="border:2px dashed var(--br);padding:20px;border-radius:10px;text-align:center"><input type="file" name="sql_file" accept=".sql" required style="margin-bottom:10px;display:block;width:100%"><button type="submit" class="btn btn-p" style="width:100%;justify-content:center"><i class="fas fa-upload"></i> بدء الاستيراد الآن</button></form></div>
    <div class="bkc"><div class="bkc-title"><i class="fas fa-download"></i> تصدير نسخة جديدة</div><p style="color:var(--t3);font-size:.83rem;margin-bottom:18px">للحفاظ على بياناتك، قم بتحميل نسخة SQL دورياً.</p><a href="backup_system.php?action=export_full" class="btn btn-g" style="width:100%;justify-content:center;padding:12px"><i class="fas fa-download"></i> تحميل النسخة الاحتياطية</a></div>
  </div>
</section>


<!-- USERS MANAGEMENT -->
<section id="users" class="sec">
  <div class="shdr">
    <h1 class="stitle"><i class="fas fa-users-cog" style="color:var(--red)"></i> إدارة <span>المستخدمين</span></h1>
    <button class="btn btn-p" onclick="OM('addUserM')"><i class="fas fa-user-plus"></i>مستخدم جديد</button>
  </div>
  <div style="display:flex;gap:9px;align-items:center;margin-bottom:18px;flex-wrap:wrap">
    <div class="tsrch" style="max-width:250px;flex:1"><i class="fas fa-search"></i><input type="text" id="usrSearch" placeholder="بحث..." oninput="usrFilter()"></div>
    <select class="fs" id="usrRoleFilter" style="width:160px" onchange="usrFilter()">
      <option value="all">كل الأدوار</option>
      <option value="administrator">مدير عام</option>
      <option value="super">مشرف</option>
      <option value="normal">عادي</option>
      <option value="custom">مخصص</option>
    </select>
    <button class="btn btn-g bsm" onclick="loadUsers()"><i class="fas fa-sync-alt"></i></button>
    <span id="usrCount" style="font-size:.78rem;color:var(--t3);margin-right:auto"></span>
  </div>
  <div id="usrLoading" style="display:none;text-align:center;padding:50px;color:var(--t3)"><div class="pspin" style="margin:0 auto 12px"></div><p>جارٍ التحميل…</p></div>
  <div id="usrGrid" class="usr-grid"></div>
  <div id="usrEmpty" style="display:none" class="empty"><i class="fas fa-users"></i><p>لا يوجد مستخدمون</p></div>
</section>

<!-- COMPANY INFO -->
<section id="login-logs" class="sec">
    <div class="sc">
        <div class="srow">
            <div class="sc-ic"><i data-lucide="shield"></i></div>
            <div class="sc-tit">
                <div class="sc-v">سجل محاولات الدخول</div>
                <div class="sc-l">مراقبة محاولات تسجيل الدخول وعناوين IP</div>
            </div>
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <button class="btn" style="background:var(--card-bg);border:1px solid var(--br);color:var(--text);transition:all 0.3s;" onclick="loadLoginLogs()" onmouseover="this.style.borderColor='var(--red)';this.style.color='var(--red)'" onmouseout="this.style.borderColor='var(--br)';this.style.color='var(--text)'">
                    <i data-lucide="refresh-cw"></i> تحديث القائمة
                </button>
                <button class="btn" style="background:rgba(0,208,132,0.1);color:#00D084;border:1px solid rgba(0,208,132,0.2);transition:all 0.3s;" onclick="exportLoginLogs()" onmouseover="this.style.background='#00D084';this.style.color='#fff'" onmouseout="this.style.background='rgba(0,208,132,0.1)';this.style.color='#00D084'">
                    <i data-lucide="download"></i> تصدير السجل (CSV)
                </button>
                <button class="btn" style="background:rgba(229,9,20,0.1);color:var(--red);border:1px solid rgba(229,9,20,0.2);transition:all 0.3s;" onclick="clearLoginLogs()" onmouseover="this.style.background='var(--red)';this.style.color='#fff'" onmouseout="this.style.background='rgba(229,9,20,0.1)';this.style.color='var(--red)'">
                    <i data-lucide="trash-2"></i> تفريغ السجل بالكامل
                </button>
            </div>
        </div>
    </div>
    <div class="tc">
        <div class="table-responsive">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>الآي بي (IP)</th>
                        <th>اسم المستخدم</th>
                        <th>الحالة</th>
                        <th>الوقت</th>
                    </tr>
                </thead>
                <tbody id="llTbody">
                </tbody>
            </table>
        </div>
    </div>
</section>
<script>
window.clearLoginLogs = function(){
    if(confirm("هل أنت متأكد من حذف جميع سجلات الدخول؟ لا يمكن التراجع عن هذا الإجراء.")){
        api({ajax_action:'clear_login_logs'}).then(d=>{ if(d.success) loadLoginLogs(); else al('alContainer',d.error,'e'); });
    }
};
window.exportLoginLogs = function(){
    api({ajax_action:'get_login_logs'}).then(d => {
        if(d.success && d.logs && d.logs.length > 0){
            let csvContent = "data:text/csv;charset=utf-8,\uFEFF";
            csvContent += "ID,IP Address,Username,Status,Blocked,Time\n";
            d.logs.forEach(l => {
                let row = [l.id, l.ip_address, l.username, l.status, (l.is_blocked==1?'Yes':'No'), l.attempt_time].join(",");
                csvContent += row + "\r\n";
            });
            let encodedUri = encodeURI(csvContent);
            let link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "login_logs_" + new Date().toISOString().slice(0,10) + ".csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        } else {
            al('alContainer', 'لا يوجد بيانات لتصديرها', 'e');
        }
    });
};
function loadLoginLogs(){
    $('llTbody').innerHTML = '<tr><td colspan="5" style="text-align:center"><span class="sp"></span> جاري التحميل...</td></tr>';
    api({ajax_action:'get_login_logs'}).then(d => {
        if(d.success){
            let h = '';
            if(!d.logs || d.logs.length === 0){
                h = '<tr><td colspan="5" style="text-align:center;color:var(--t3)">لا يوجد سجلات</td></tr>';
            } else {
                d.logs.forEach(l => {
                    let st = l.status === 'success' ? '<span style="color:#00D084;font-weight:bold">ناجح</span>' : '<span style="color:#E50914;font-weight:bold">فشل</span>';
                    h += `<tr>
                        <td>${l.id}</td>
                        <td dir="ltr" style="text-align:right">${esc(l.ip_address)}</td>
                        <td>${esc(l.username||'-')}</td>
                        <td>${st}</td>
                        <td dir="ltr" style="text-align:right">${esc(l.attempt_time)}</td>
                    </tr>`;
                });
            }
            $('llTbody').innerHTML = h;
            if(window.lucide) lucide.createIcons();
        } else {
            al('alContainer', d.error || 'حدث خطأ', 'e');
            $('llTbody').innerHTML = '<tr><td colspan="5" style="text-align:center;color:red">خطأ في التحميل</td></tr>';
        }
    }).catch(e => {
        $('llTbody').innerHTML = '<tr><td colspan="5" style="text-align:center;color:red">فشل الاتصال</td></tr>';
    });
}
</script>
<section id="company-info" class="sec">
  <div class="shdr"><h1 class="stitle">حول <span>الشركة</span></h1></div>
  <div class="card" style="background: linear-gradient(135deg, rgba(229,9,20,0.05), rgba(0,0,0,0.2)); border-color: rgba(229,9,20,0.2);">
    <div class="chdr" style="border-bottom-color: rgba(255,255,255,0.05);"><span class="ctitle"><i class="fas fa-info-circle" style="color:var(--red);margin-left:7px"></i>معلومات التواصل وطلب الدعم الفني</span></div>
    <div class="cbody" style="display:flex; flex-wrap:wrap; gap: 20px; align-items:center; justify-content: space-between;">
      <div style="flex:1; min-width:260px;">
        <h3 style="color:var(--t1); margin-bottom:5px; font-size:1.4rem; font-weight:900;">SHASHITY PRO <span style="font-size:0.75rem; background:rgba(229,9,20,0.2); padding:2px 8px; border-radius:20px; vertical-align:middle; font-weight:normal;">الإصدار الرسمي</span></h3>
        <p style="color:var(--t3); margin-bottom:15px; font-size:0.85rem;">نظام إدارة منصات البث المباشر المتقدم المستوحى من تقنيات البث الحديثة.</p>
        <div style="display:flex; flex-direction:column; gap:10px;">
          <div style="display:flex; align-items:center; gap:12px; font-size:0.95rem;">
            <div style="width:28px;height:28px;background:rgba(245,166,35,0.1);color:var(--gold);border-radius:50%;display:flex;align-items:center;justify-content:center"><i class="fas fa-map-marker-alt"></i></div>
            <span>العراق - موصل</span>
          </div>
          <div style="display:flex; align-items:center; gap:12px; font-size:0.95rem;">
            <div style="width:28px;height:28px;background:rgba(0,208,132,0.1);color:#00D084;border-radius:50%;display:flex;align-items:center;justify-content:center"><i class="fas fa-phone-alt"></i></div>
            <a href="tel:009647512328848" style="direction:ltr; display:inline-block; font-weight:700; color:var(--t1); transition: color 0.2s;" onmouseover="this.style.color='#00D084'" onmouseout="this.style.color='var(--t1)'">00964 751 232 8848</a>
          </div>
          <div style="display:flex; align-items:center; gap:12px; font-size:0.95rem;">
            <div style="width:28px;height:28px;background:rgba(76,201,240,0.1);color:#4CC9F0;border-radius:50%;display:flex;align-items:center;justify-content:center"><i class="fas fa-globe"></i></div>
            <a href="https://shashty-pro.netlify.app" target="_blank" style="color:#4CC9F0; font-weight:500; transition: color 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='#4CC9F0'">shashty-pro.netlify.app</a>
          </div>
        </div>
      </div>
      <div style="display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,0.2); padding:20px; border-radius:12px; border:1px solid rgba(255,255,255,0.05);">
         <i class="fas fa-laptop-code" style="font-size:3.5rem; color:var(--red); text-shadow: 0 0 15px rgba(229,9,20,0.4);"></i>
      </div>
    </div>
  </div>
</section>

<!-- ══ FRONTEND CONTROL — التحكم بالواجهة الأمامية (إضافة) ══ -->
<!-- ═══════════════════════════════════════════════════════════════════════
     قسم الإعدادات العامة الحساسة — يتحكم في index.php دون تعديل أي ملف
     كل حقل عليه تعليق يوضح وظيفته. كل شيء يُحفظ في قاعدة البيانات (جدول settings)
     ═══════════════════════════════════════════════════════════════════════ -->
<section id="general-settings" class="sec">
  <div class="shdr">
    <h1 class="stitle"><i class="fas fa-sliders-h" style="color:#F5A623"></i> الإعدادات <span>العامة</span></h1>
    <button class="btn btn-g bsm" onclick="loadGeneralSettings()"><i class="fas fa-sync-alt"></i> استرجاع المحفوظ</button>
  </div>
  <p style="color:var(--t3);font-size:.86rem;margin-bottom:22px;line-height:1.7">
    تحكّم كامل في كل الإعدادات الحساسة والمهمة للموقع (index.php) من مكان واحد.
    التغييرات تُحفظ في قاعدة البيانات وتُطبَّق فوراً — <b>لا حاجة لتعديل أي ملف يدوياً</b>.
  </p>
  <div id="gsAlert"></div>

  <!-- ══════════ مجموعة 1: التحكم الحرج (وضع الصيانة + قفل الموقع) ══════════ -->
  <div class="card" style="margin-bottom:22px;border:1px solid rgba(255,77,87,.35);box-shadow:0 0 18px rgba(255,77,87,.08)">
    <div class="chdr" style="background:rgba(255,77,87,.05);border-bottom:1px solid rgba(255,77,87,.2)">
      <span class="ctitle"><i class="fas fa-triangle-exclamation" style="color:#ff4d57;margin-left:8px"></i>تحكم حرج — استخدم بحذر</span>
    </div>
    <div class="cbody" style="display:flex;flex-direction:column;gap:16px">

      <!-- وضع الصيانة: يُغلق الموقع أمام الزوار ويعرض رسالة (المدير يبقى قادراً على الدخول) -->
      <label class="fc-card" for="gs_maintenance_mode" style="cursor:pointer">
        <div class="fc-ic" style="background:rgba(255,77,87,.12);color:#ff4d57"><i class="fas fa-hard-hat"></i></div>
        <div class="fc-info"><b>وضع الصيانة</b><small>إغلاق الموقع أمام الزوار وعرض صفحة صيانة. المدير يبقى قادراً على التصفح.</small></div>
        <span class="fc-switch"><input type="checkbox" id="gs_maintenance_mode" data-key="maintenance_mode" data-type="bool"><span class="fc-slider"></span></span>
      </label>
      <!-- نص رسالة الصيانة الظاهرة للزوار أثناء تفعيل وضع الصيانة -->
      <div class="fg" style="margin:0">
        <label class="fl">رسالة صفحة الصيانة</label>
        <input type="text" class="fi" id="gs_maintenance_message" data-key="maintenance_message" data-type="text" placeholder="الموقع تحت الصيانة حالياً، نعود قريباً بإذن الله">
      </div>

      <hr style="border:none;border-top:1px solid var(--br);margin:4px 0">

      <!-- قفل الموقع بكلمة مرور: يطلب كلمة سر قبل الدخول للموقع كاملاً (بوابة حماية) -->
      <label class="fc-card" for="gs_gate_enabled" style="cursor:pointer">
        <div class="fc-ic" style="background:rgba(179,107,255,.12);color:#B36BFF"><i class="fas fa-lock"></i></div>
        <div class="fc-info"><b>قفل الموقع بكلمة مرور</b><small>حماية الموقع بالكامل ببوابة كلمة سر قبل الدخول (للمواقع الخاصة).</small></div>
        <span class="fc-switch"><input type="checkbox" id="gs_gate_enabled" data-key="gate_enabled" data-type="bool"><span class="fc-slider"></span></span>
      </label>
      <!-- كلمة مرور بوابة الموقع (تُطلب من الزائر عند تفعيل القفل) -->
      <div class="fg" style="margin:0">
        <label class="fl">كلمة مرور الدخول للموقع</label>
        <input type="text" class="fi" id="gs_gate_password" data-key="gate_password" data-type="text" placeholder="اكتب كلمة سر الدخول للموقع" autocomplete="off">
      </div>
    </div>
  </div>

  <!-- ══════════ مجموعة 2: هوية الموقع (الاسم / الوصف / الشعار / اللون) ══════════ -->
  <div class="card" style="margin-bottom:22px">
    <div class="chdr"><span class="ctitle"><i class="fas fa-id-card" style="color:#58a6ff;margin-left:8px"></i>هوية الموقع</span></div>
    <div class="cbody" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px">
      <!-- اسم الموقع: يظهر في عنوان التبويب و og:title ومشاركات السوشيال -->
      <div class="fg" style="margin:0"><label class="fl">اسم الموقع</label>
        <input type="text" class="fi" id="gs_site_name" data-key="site_name" data-type="text" placeholder="Shashety"></div>
      <!-- وصف الموقع: meta description لمحركات البحث (SEO) -->
      <div class="fg" style="margin:0"><label class="fl">وصف الموقع (SEO)</label>
        <input type="text" class="fi" id="gs_site_description" data-key="site_description" data-type="text" placeholder="نظام IPTV احترافي"></div>
      <!-- رابط شعار الموقع -->
      <div class="fg" style="margin:0"><label class="fl">رابط الشعار (Logo URL)</label>
        <input type="text" class="fi" id="gs_site_logo" data-key="site_logo" data-type="text" placeholder="https://example.com/logo.png"></div>
      <!-- اللون الأساسي للثيم (accent) -->
      <div class="fg" style="margin:0"><label class="fl">اللون الأساسي للثيم</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="color" id="gs_theme_color_pick" style="width:48px;height:42px;border:1px solid var(--br);border-radius:8px;background:transparent;cursor:pointer" oninput="document.getElementById('gs_theme_color').value=this.value" value="#e50914">
          <input type="text" class="fi" id="gs_theme_color" data-key="theme_color" data-type="text" placeholder="#e50914" style="flex:1" oninput="try{document.getElementById('gs_theme_color_pick').value=this.value}catch(e){}">
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════ مجموعة 3: نصوص الواجهة (الترحيب / الفوتر) ══════════ -->
  <div class="card" style="margin-bottom:22px">
    <div class="chdr"><span class="ctitle"><i class="fas fa-heading" style="color:#00D084;margin-left:8px"></i>نصوص الواجهة</span></div>
    <div class="cbody" style="display:flex;flex-direction:column;gap:16px">
      <!-- عنوان الترحيب الرئيسي في الصفحة الأولى -->
      <div class="fg" style="margin:0"><label class="fl">عنوان الترحيب</label>
        <input type="text" class="fi" id="gs_welcome_title" data-key="welcome_title" data-type="text" placeholder="مرحباً بك في عالم البث المباشر"></div>
      <!-- العنوان الفرعي للترحيب -->
      <div class="fg" style="margin:0"><label class="fl">العنوان الفرعي للترحيب</label>
        <input type="text" class="fi" id="gs_welcome_subtitle" data-key="welcome_subtitle" data-type="text" placeholder="شاهد آلاف القنوات من جميع أنحاء العالم"></div>
      <!-- نص حقوق الفوتر أسفل الموقع -->
      <div class="fg" style="margin:0"><label class="fl">نص الفوتر (الحقوق)</label>
        <input type="text" class="fi" id="gs_footer_text" data-key="footer_text" data-type="text" placeholder="جميع الحقوق محفوظة © 2026 Shashety"></div>
    </div>
  </div>

  <!-- ══════════ مجموعة 4: روابط التواصل (واتساب / فيسبوك / بريد) ══════════ -->
  <div class="card" style="margin-bottom:22px">
    <div class="chdr"><span class="ctitle"><i class="fas fa-share-nodes" style="color:#25D366;margin-left:8px"></i>روابط التواصل</span></div>
    <div class="cbody" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px">
      <!-- رقم واتساب التواصل الظاهر في الموقع -->
      <div class="fg" style="margin:0"><label class="fl"><i class="fab fa-whatsapp" style="color:#25D366"></i> رقم واتساب</label>
        <input type="text" class="fi" id="gs_contact_whatsapp" data-key="contact_whatsapp" data-type="text" placeholder="9647512328848" dir="ltr"></div>
      <!-- رابط صفحة الفيسبوك -->
      <div class="fg" style="margin:0"><label class="fl"><i class="fab fa-facebook" style="color:#1877F2"></i> رابط فيسبوك</label>
        <input type="text" class="fi" id="gs_contact_facebook" data-key="contact_facebook" data-type="text" placeholder="facebook.com/yourpage" dir="ltr"></div>
      <!-- بريد التواصل الإلكتروني -->
      <div class="fg" style="margin:0"><label class="fl"><i class="fas fa-envelope" style="color:#EA4335"></i> بريد التواصل</label>
        <input type="text" class="fi" id="gs_contact_email" data-key="contact_email" data-type="text" placeholder="info@example.com" dir="ltr"></div>
    </div>
  </div>

  <!-- ══════════ مجموعة 5: الشريط الإعلاني العلوي ══════════ -->
  <div class="card" style="margin-bottom:22px">
    <div class="chdr"><span class="ctitle"><i class="fas fa-bullhorn" style="color:#F5A623;margin-left:8px"></i>الشريط الإعلاني</span></div>
    <div class="cbody" style="display:flex;flex-direction:column;gap:16px">
      <!-- تفعيل ظهور الشريط الإعلاني أعلى الموقع -->
      <label class="fc-card" for="gs_announcement_enabled" style="cursor:pointer">
        <div class="fc-ic" style="background:rgba(245,166,35,.12);color:#F5A623"><i class="fas fa-bullhorn"></i></div>
        <div class="fc-info"><b>إظهار الشريط الإعلاني</b><small>شريط نصي يظهر أعلى الصفحة الرئيسية لكل الزوار.</small></div>
        <span class="fc-switch"><input type="checkbox" id="gs_announcement_enabled" data-key="announcement_enabled" data-type="bool"><span class="fc-slider"></span></span>
      </label>
      <!-- نص الإعلان الظاهر في الشريط -->
      <div class="fg" style="margin:0"><label class="fl">نص الإعلان</label>
        <input type="text" class="fi" id="gs_announcement_text" data-key="announcement_text" data-type="text" placeholder="مثال: تم إضافة قنوات جديدة! تصفّح الآن"></div>
      <!-- رابط اختياري عند النقر على الشريط -->
      <div class="fg" style="margin:0"><label class="fl">رابط الإعلان (اختياري)</label>
        <input type="text" class="fi" id="gs_announcement_link" data-key="announcement_link" data-type="text" placeholder="https://... اتركه فارغاً لبدون رابط" dir="ltr"></div>
    </div>
  </div>

  <!-- ══════════ مجموعة 6: الأمان والحماية ══════════ -->
  <div class="card" style="margin-bottom:22px">
    <div class="chdr"><span class="ctitle"><i class="fas fa-shield-halved" style="color:#4CC9F0;margin-left:8px"></i>الأمان والحماية</span></div>
    <div class="cbody" style="display:flex;flex-direction:column;gap:14px">
      <!-- إجبار HTTPS: يعيد توجيه أي زيارة http إلى https تلقائياً -->
      <label class="fc-card" for="gs_force_https" style="cursor:pointer">
        <div class="fc-ic" style="background:rgba(0,208,132,.12);color:#00D084"><i class="fas fa-lock"></i></div>
        <div class="fc-info"><b>إجبار HTTPS</b><small>إعادة توجيه كل الزيارات إلى النسخة الآمنة (https) تلقائياً.</small></div>
        <span class="fc-switch"><input type="checkbox" id="gs_force_https" data-key="force_https" data-type="bool"><span class="fc-slider"></span></span>
      </label>
      <!-- منع أدوات المطور: تعطيل النقر الأيمن و F12 على الواجهة -->
      <label class="fc-card" for="gs_block_devtools" style="cursor:pointer">
        <div class="fc-ic" style="background:rgba(255,159,28,.12);color:#ff9f1c"><i class="fas fa-user-secret"></i></div>
        <div class="fc-info"><b>منع أدوات المطور</b><small>تعطيل النقر الأيمن و F12 لتصعيب فحص الروابط (حماية سطحية).</small></div>
        <span class="fc-switch"><input type="checkbox" id="gs_block_devtools" data-key="block_devtools" data-type="bool"><span class="fc-slider"></span></span>
      </label>
      <!-- منع التحميل: إخفاء زر تنزيل الفيديو عالمياً -->
      <label class="fc-card" for="gs_disable_download" style="cursor:pointer">
        <div class="fc-ic" style="background:rgba(229,9,20,.12);color:var(--red)"><i class="fas fa-download"></i></div>
        <div class="fc-info"><b>منع تحميل الفيديوهات</b><small>إخفاء زر التنزيل من المشغّل لكل الزوار.</small></div>
        <span class="fc-switch"><input type="checkbox" id="gs_disable_download" data-key="disable_download" data-type="bool"><span class="fc-slider"></span></span>
      </label>
    </div>
  </div>

  <!-- ══════════ مجموعة 7: أكواد مخصصة (تحليلات / بكسل / سكربتات) — حساس جداً ══════════ -->
  <div class="card" style="margin-bottom:22px;border:1px solid rgba(245,166,35,.3)">
    <div class="chdr" style="background:rgba(245,166,35,.05);border-bottom:1px solid rgba(245,166,35,.2)">
      <span class="ctitle"><i class="fas fa-code" style="color:#F5A623;margin-left:8px"></i>أكواد مخصصة (متقدم — حساس)</span>
    </div>
    <div class="cbody" style="display:flex;flex-direction:column;gap:16px">
      <p style="color:var(--t3);font-size:.8rem;line-height:1.6;margin:0">
        <i class="fas fa-circle-info" style="color:#F5A623"></i>
        يُحقن هذا الكود مباشرة في صفحة الموقع. استخدمه لأكواد Google Analytics أو Facebook Pixel أو أي سكربت. <b>لا تلصق كوداً من مصدر غير موثوق.</b>
      </p>
      <!-- كود يُحقن داخل <head>: مثالي لأكواد التتبع/التحليلات/الميتا -->
      <div class="fg" style="margin:0"><label class="fl">كود داخل &lt;head&gt; (تحليلات / بكسل / meta)</label>
        <textarea class="fi" id="gs_custom_head_code" data-key="custom_head_code" data-type="text" rows="4" style="font-family:'Courier New',monospace;direction:ltr;text-align:left" placeholder="&lt;!-- Google Analytics / Facebook Pixel --&gt;"></textarea></div>
      <!-- كود يُحقن قبل </body>: مثالي لسكربتات الشات/الودجت -->
      <div class="fg" style="margin:0"><label class="fl">كود قبل &lt;/body&gt; (شات / ودجت / سكربت)</label>
        <textarea class="fi" id="gs_custom_body_code" data-key="custom_body_code" data-type="text" rows="4" style="font-family:'Courier New',monospace;direction:ltr;text-align:left" placeholder="&lt;!-- Live Chat / Custom Script --&gt;"></textarea></div>
    </div>
  </div>


  <!-- ═══════════════════════════════════════════════════════════════════
       المجموعات المتقدمة — مدموجة داخل الإعدادات العامة (بطاقات قابلة للطي)
       كل بطاقة لها زر حفظ خاص بها (نفس آلية الحفظ لكل مجموعة على حدة)
       ═══════════════════════════════════════════════════════════════════ -->
  <div style="margin-top:30px;margin-bottom:14px;display:flex;align-items:center;gap:10px">
    <div style="flex:1;height:1px;background:var(--br)"></div>
    <span style="color:var(--t3);font-size:.8rem;font-weight:700;letter-spacing:1px"><i class="fas fa-layer-group"></i> الإعدادات المتقدمة</span>
    <div style="flex:1;height:1px;background:var(--br)"></div>
  </div>
  <p style="color:var(--t3);font-size:.82rem;margin-bottom:18px;line-height:1.7">
    كل مجموعة أدناه قابلة للطي، ولها <b>زر حفظ خاص</b> — عدّل ما تريد في أي مجموعة ثم احفظها وحدها.
  </p>


  <!-- مجموعة: إعدادات البث الخادمية -->
  <div class="gs-acc" data-group="streaming_server" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#F5A62322;color:#F5A623;flex-shrink:0"><i class="fas fa-server"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات البث الخادمية</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      <p style="color:var(--t3);font-size:.82rem;margin:0 0 14px;line-height:1.7">قيم تُخزَّن في قاعدة البيانات جاهزة لربطها بخادم البث (FFmpeg/Transcoder). لا تؤثر ما لم يقرأها خادم البث لديك.</p>
      <div id="ga_streaming_server"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- srv_hls_segment_duration — الأصلي: 6 -->
    <div class="fg" style="margin:0"><label class="fl">مدة جزء HLS (ثانية) — HLS Segment Duration <span style="color:var(--t3);font-weight:400">(الأصلي: 6)</span></label>
      <input type="number" class="fi" id="f_srv_hls_segment_duration" data-key="srv_hls_segment_duration" data-type="text" placeholder="6"></div>
    <!-- srv_playlist_length — الأصلي: 5 -->
    <div class="fg" style="margin:0"><label class="fl">طول قائمة التشغيل — Playlist Length <span style="color:var(--t3);font-weight:400">(الأصلي: 5)</span></label>
      <input type="number" class="fi" id="f_srv_playlist_length" data-key="srv_playlist_length" data-type="text" placeholder="5"></div>
    <!-- srv_llhls_enable — الأصلي: 0 -->
    <label class="fc-card" for="f_srv_llhls_enable" style="cursor:pointer">
      <div class="fc-info"><b>تفعيل LL-HLS</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_srv_llhls_enable" data-key="srv_llhls_enable" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- srv_ffmpeg_params — الأصلي: فارغ -->
    <div class="fg" style="margin:0"><label class="fl">باراميترات FFmpeg إضافية</label>
      <textarea class="fi" id="f_srv_ffmpeg_params" data-key="srv_ffmpeg_params" data-type="text" rows="3" style="font-family:'Courier New',monospace;direction:ltr;text-align:left" placeholder=""></textarea></div>
    <!-- srv_hwaccel — الأصلي: none -->
    <div class="fg" style="margin:0"><label class="fl">تسريع عتادي — Hardware Acceleration <span style="color:var(--t3);font-weight:400">(الأصلي: none)</span></label>
      <select class="fs" id="f_srv_hwaccel" data-key="srv_hwaccel" data-type="text"><option value="none">none</option><option value="nvenc">nvenc</option><option value="vaapi">vaapi</option><option value="qsv">qsv</option></select></div>
    <!-- srv_thread_count — الأصلي: 0 -->
    <div class="fg" style="margin:0"><label class="fl">عدد المسارات — Thread Count (0=تلقائي) <span style="color:var(--t3);font-weight:400">(الأصلي: 0)</span></label>
      <input type="number" class="fi" id="f_srv_thread_count" data-key="srv_thread_count" data-type="text" placeholder="0"></div>
    <!-- srv_tcp_udp_buffer — الأصلي: 8192 -->
    <div class="fg" style="margin:0"><label class="fl">حجم TCP/UDP Buffer (KB) <span style="color:var(--t3);font-weight:400">(الأصلي: 8192)</span></label>
      <input type="number" class="fi" id="f_srv_tcp_udp_buffer" data-key="srv_tcp_udp_buffer" data-type="text" placeholder="8192"></div>
    <!-- srv_socket_buffer — الأصلي: 65536 -->
    <div class="fg" style="margin:0"><label class="fl">Socket Buffer (بايت) <span style="color:var(--t3);font-weight:400">(الأصلي: 65536)</span></label>
      <input type="number" class="fi" id="f_srv_socket_buffer" data-key="srv_socket_buffer" data-type="text" placeholder="65536"></div>
    <!-- srv_cdn_failover — الأصلي: 0 -->
    <label class="fc-card" for="f_srv_cdn_failover" style="cursor:pointer">
      <div class="fc-info"><b>CDN Failover</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_srv_cdn_failover" data-key="srv_cdn_failover" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- srv_stream_priority — الأصلي: normal -->
    <div class="fg" style="margin:0"><label class="fl">أولوية البث — Stream Priority <span style="color:var(--t3);font-weight:400">(الأصلي: normal)</span></label>
      <select class="fs" id="f_srv_stream_priority" data-key="srv_stream_priority" data-type="text"><option value="low">low</option><option value="normal">normal</option><option value="high">high</option></select></div>
    <!-- srv_health_check_interval — الأصلي: 30 -->
    <div class="fg" style="margin:0"><label class="fl">فترة فحص الصحة — Health Check (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 30)</span></label>
      <input type="number" class="fi" id="f_srv_health_check_interval" data-key="srv_health_check_interval" data-type="text" placeholder="30"></div>
    <!-- srv_auto_restart_stream — الأصلي: 1 -->
    <label class="fc-card" for="f_srv_auto_restart_stream" style="cursor:pointer">
      <div class="fc-info"><b>إعادة تشغيل البث تلقائياً — Auto Restart</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_srv_auto_restart_stream" data-key="srv_auto_restart_stream" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- srv_stream_timeout — الأصلي: 20 -->
    <div class="fg" style="margin:0"><label class="fl">مهلة البث — Stream Timeout (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 20)</span></label>
      <input type="number" class="fi" id="f_srv_stream_timeout" data-key="srv_stream_timeout" data-type="text" placeholder="20"></div>
    <!-- srv_packet_loss_recovery — الأصلي: 1 -->
    <label class="fc-card" for="f_srv_packet_loss_recovery" style="cursor:pointer">
      <div class="fc-info"><b>استرجاع فقد الحزم — Packet Loss Recovery</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_srv_packet_loss_recovery" data-key="srv_packet_loss_recovery" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- srv_jitter_buffer — الأصلي: 500 -->
    <div class="fg" style="margin:0"><label class="fl">Jitter Buffer (مللي ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 500)</span></label>
      <input type="number" class="fi" id="f_srv_jitter_buffer" data-key="srv_jitter_buffer" data-type="text" placeholder="500"></div>
    <!-- srv_abr_enable — الأصلي: 1 -->
    <label class="fc-card" for="f_srv_abr_enable" style="cursor:pointer">
      <div class="fc-info"><b>معدل بت متكيّف — Adaptive Bitrate (ABR)</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_srv_abr_enable" data-key="srv_abr_enable" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- srv_max_bitrate — الأصلي: 8000 -->
    <div class="fg" style="margin:0"><label class="fl">أقصى معدل بت — Maximum Bitrate (kbps) <span style="color:var(--t3);font-weight:400">(الأصلي: 8000)</span></label>
      <input type="number" class="fi" id="f_srv_max_bitrate" data-key="srv_max_bitrate" data-type="text" placeholder="8000"></div>
    <!-- srv_min_bitrate — الأصلي: 800 -->
    <div class="fg" style="margin:0"><label class="fl">أدنى معدل بت — Minimum Bitrate (kbps) <span style="color:var(--t3);font-weight:400">(الأصلي: 800)</span></label>
      <input type="number" class="fi" id="f_srv_min_bitrate" data-key="srv_min_bitrate" data-type="text" placeholder="800"></div>
    <!-- srv_gop_size — الأصلي: 48 -->
    <div class="fg" style="margin:0"><label class="fl">حجم GOP — GOP Size (إطار) <span style="color:var(--t3);font-weight:400">(الأصلي: 48)</span></label>
      <input type="number" class="fi" id="f_srv_gop_size" data-key="srv_gop_size" data-type="text" placeholder="48"></div>
    <!-- srv_keyframe_interval — الأصلي: 2 -->
    <div class="fg" style="margin:0"><label class="fl">فاصل الإطار المفتاحي — Keyframe Interval (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 2)</span></label>
      <input type="number" class="fi" id="f_srv_keyframe_interval" data-key="srv_keyframe_interval" data-type="text" placeholder="2"></div>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('streaming_server')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('streaming_server')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات الواجهة -->
  <div class="gs-acc" data-group="ui" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#B36BFF22;color:#B36BFF;flex-shrink:0"><i class="fas fa-palette"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات الواجهة</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_ui"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- ui_theme — الأصلي: dark -->
    <div class="fg" style="margin:0"><label class="fl">الثيم <span style="color:var(--t3);font-weight:400">(الأصلي: dark)</span></label>
      <select class="fs" id="f_ui_theme" data-key="ui_theme" data-type="text"><option value="dark">dark</option><option value="light">light</option><option value="netflix">netflix</option><option value="purple">purple</option><option value="github">github</option><option value="emerald">emerald</option><option value="royal">royal</option></select></div>
    <!-- theme_color — الأصلي: #e50914 -->
    <div class="fg" style="margin:0"><label class="fl">اللون الأساسي <span style="color:var(--t3);font-weight:400">(الأصلي: #e50914)</span></label>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="color" id="f_theme_color_pick" value="#e50914" style="width:48px;height:42px;border:1px solid var(--br);border-radius:8px;background:transparent;cursor:pointer" oninput="document.getElementById('f_theme_color').value=this.value">
        <input type="text" class="fi" id="f_theme_color" data-key="theme_color" data-type="text" placeholder="#e50914" style="flex:1" oninput="try{document.getElementById('f_theme_color_pick').value=this.value}catch(e){}">
      </div></div>
    <!-- ui_font — الأصلي: Tajawal -->
    <div class="fg" style="margin:0"><label class="fl">الخط <span style="color:var(--t3);font-weight:400">(الأصلي: Tajawal)</span></label>
      <select class="fs" id="f_ui_font" data-key="ui_font" data-type="text"><option value="Tajawal">Tajawal</option><option value="Cairo">Cairo</option><option value="Almarai">Almarai</option><option value="Inter">Inter</option><option value="Roboto">Roboto</option></select></div>
    <!-- ui_font_size — الأصلي: 16 -->
    <div class="fg" style="margin:0"><label class="fl">حجم الخط (px) <span style="color:var(--t3);font-weight:400">(الأصلي: 16)</span></label>
      <input type="number" class="fi" id="f_ui_font_size" data-key="ui_font_size" data-type="text" placeholder="16"></div>
    <!-- ui_transitions — الأصلي: 1 -->
    <label class="fc-card" for="f_ui_transitions" style="cursor:pointer">
      <div class="fc-info"><b>تأثيرات الانتقال</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_ui_transitions" data-key="ui_transitions" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- ui_banner — الأصلي: فارغ -->
    <div class="fg" style="margin:0"><label class="fl">بانر أعلى الصفحة (رابط صورة)</label>
      <input type="text" class="fi" id="f_ui_banner" data-key="ui_banner" data-type="text" placeholder=""></div>
    <!-- ui_icon_style — الأصلي: solid -->
    <div class="fg" style="margin:0"><label class="fl">نمط الأيقونات <span style="color:var(--t3);font-weight:400">(الأصلي: solid)</span></label>
      <select class="fs" id="f_ui_icon_style" data-key="ui_icon_style" data-type="text"><option value="solid">solid</option><option value="regular">regular</option><option value="duotone">duotone</option></select></div>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('ui')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('ui')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات الصور -->
  <div class="gs-acc" data-group="images" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#4CC9F022;color:#4CC9F0;flex-shrink:0"><i class="fas fa-image"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات الصور</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_images"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- img_default_channel — الأصلي: فارغ -->
    <div class="fg" style="margin:0"><label class="fl">صورة افتراضية للقنوات (رابط)</label>
      <input type="text" class="fi" id="f_img_default_channel" data-key="img_default_channel" data-type="text" placeholder=""></div>
    <!-- img_default_movie — الأصلي: فارغ -->
    <div class="fg" style="margin:0"><label class="fl">صورة افتراضية للأفلام (رابط)</label>
      <input type="text" class="fi" id="f_img_default_movie" data-key="img_default_movie" data-type="text" placeholder=""></div>
    <!-- img_default_series — الأصلي: فارغ -->
    <div class="fg" style="margin:0"><label class="fl">صورة افتراضية للمسلسلات (رابط)</label>
      <input type="text" class="fi" id="f_img_default_series" data-key="img_default_series" data-type="text" placeholder=""></div>
    <!-- img_quality — الأصلي: 85 -->
    <div class="fg" style="margin:0"><label class="fl">جودة الصور (1-100) <span style="color:var(--t3);font-weight:400">(الأصلي: 85)</span></label>
      <input type="number" class="fi" id="f_img_quality" data-key="img_quality" data-type="text" placeholder="85"></div>
    <!-- img_compression — الأصلي: 1 -->
    <label class="fc-card" for="f_img_compression" style="cursor:pointer">
      <div class="fc-info"><b>ضغط الصور</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_img_compression" data-key="img_compression" data-type="bool"><span class="fc-slider"></span></span>
    </label>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('images')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('images')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات المستخدم -->
  <div class="gs-acc" data-group="user" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#00D08422;color:#00D084;flex-shrink:0"><i class="fas fa-user-gear"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات المستخدم</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_user"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- usr_save_last_watch — الأصلي: 1 -->
    <label class="fc-card" for="f_usr_save_last_watch" style="cursor:pointer">
      <div class="fc-info"><b>حفظ آخر مشاهدة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_usr_save_last_watch" data-key="usr_save_last_watch" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- usr_autoplay — الأصلي: 1 -->
    <label class="fc-card" for="f_usr_autoplay" style="cursor:pointer">
      <div class="fc-info"><b>التشغيل التلقائي</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_usr_autoplay" data-key="usr_autoplay" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- usr_dark_mode — الأصلي: 1 -->
    <label class="fc-card" for="f_usr_dark_mode" style="cursor:pointer">
      <div class="fc-info"><b>الوضع الليلي</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_usr_dark_mode" data-key="usr_dark_mode" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- usr_language — الأصلي: ar -->
    <div class="fg" style="margin:0"><label class="fl">اللغة <span style="color:var(--t3);font-weight:400">(الأصلي: ar)</span></label>
      <select class="fs" id="f_usr_language" data-key="usr_language" data-type="text"><option value="ar">ar</option><option value="en">en</option><option value="tr">tr</option></select></div>
    <!-- usr_notifications — الأصلي: 1 -->
    <label class="fc-card" for="f_usr_notifications" style="cursor:pointer">
      <div class="fc-info"><b>الإشعارات</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_usr_notifications" data-key="usr_notifications" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- usr_favorites — الأصلي: 1 -->
    <label class="fc-card" for="f_usr_favorites" style="cursor:pointer">
      <div class="fc-info"><b>المفضلة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_usr_favorites" data-key="usr_favorites" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- usr_watch_history — الأصلي: 1 -->
    <label class="fc-card" for="f_usr_watch_history" style="cursor:pointer">
      <div class="fc-info"><b>سجل المشاهدة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_usr_watch_history" data-key="usr_watch_history" data-type="bool"><span class="fc-slider"></span></span>
    </label>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('user')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('user')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: الأداء (Performance) -->
  <div class="gs-acc" data-group="performance" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#ff9f1c22;color:#ff9f1c;flex-shrink:0"><i class="fas fa-gauge-high"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">الأداء (Performance)</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_performance"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- perf_cache_duration — الأصلي: 3600 -->
    <div class="fg" style="margin:0"><label class="fl">مدة الكاش — Cache (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 3600)</span></label>
      <input type="number" class="fi" id="f_perf_cache_duration" data-key="perf_cache_duration" data-type="text" placeholder="3600"></div>
    <!-- perf_image_cache — الأصلي: 1 -->
    <label class="fc-card" for="f_perf_image_cache" style="cursor:pointer">
      <div class="fc-info"><b>كاش الصور — Image Cache</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_perf_image_cache" data-key="perf_image_cache" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- perf_api_cache — الأصلي: 1 -->
    <label class="fc-card" for="f_perf_api_cache" style="cursor:pointer">
      <div class="fc-info"><b>كاش API — API Cache</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_perf_api_cache" data-key="perf_api_cache" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- perf_gzip_brotli — الأصلي: 1 -->
    <label class="fc-card" for="f_perf_gzip_brotli" style="cursor:pointer">
      <div class="fc-info"><b>ضغط Gzip/Brotli</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_perf_gzip_brotli" data-key="perf_gzip_brotli" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- perf_lazy_loading — الأصلي: 1 -->
    <label class="fc-card" for="f_perf_lazy_loading" style="cursor:pointer">
      <div class="fc-info"><b>Lazy Loading</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_perf_lazy_loading" data-key="perf_lazy_loading" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- perf_http_version — الأصلي: 2 -->
    <div class="fg" style="margin:0"><label class="fl">إصدار HTTP <span style="color:var(--t3);font-weight:400">(الأصلي: 2)</span></label>
      <select class="fs" id="f_perf_http_version" data-key="perf_http_version" data-type="text"><option value="1.1">1.1</option><option value="2">2</option><option value="3">3</option></select></div>
    <!-- perf_prefetch — الأصلي: 1 -->
    <label class="fc-card" for="f_perf_prefetch" style="cursor:pointer">
      <div class="fc-info"><b>Prefetch</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_perf_prefetch" data-key="perf_prefetch" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- perf_preconnect — الأصلي: 1 -->
    <label class="fc-card" for="f_perf_preconnect" style="cursor:pointer">
      <div class="fc-info"><b>Preconnect</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_perf_preconnect" data-key="perf_preconnect" data-type="bool"><span class="fc-slider"></span></span>
    </label>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('performance')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('performance')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات الترجمة -->
  <div class="gs-acc" data-group="subtitles" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#58a6ff22;color:#58a6ff;flex-shrink:0"><i class="fas fa-closed-captioning"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات الترجمة</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_subtitles"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- sub_default_language — الأصلي: ar -->
    <div class="fg" style="margin:0"><label class="fl">اللغة الافتراضية <span style="color:var(--t3);font-weight:400">(الأصلي: ar)</span></label>
      <select class="fs" id="f_sub_default_language" data-key="sub_default_language" data-type="text"><option value="ar">ar</option><option value="en">en</option><option value="tr">tr</option><option value="fr">fr</option></select></div>
    <!-- sub_font_size — الأصلي: 18 -->
    <div class="fg" style="margin:0"><label class="fl">حجم الخط (px) <span style="color:var(--t3);font-weight:400">(الأصلي: 18)</span></label>
      <input type="number" class="fi" id="f_sub_font_size" data-key="sub_font_size" data-type="text" placeholder="18"></div>
    <!-- sub_font_color — الأصلي: #ffffff -->
    <div class="fg" style="margin:0"><label class="fl">لون الخط <span style="color:var(--t3);font-weight:400">(الأصلي: #ffffff)</span></label>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="color" id="f_sub_font_color_pick" value="#ffffff" style="width:48px;height:42px;border:1px solid var(--br);border-radius:8px;background:transparent;cursor:pointer" oninput="document.getElementById('f_sub_font_color').value=this.value">
        <input type="text" class="fi" id="f_sub_font_color" data-key="sub_font_color" data-type="text" placeholder="#ffffff" style="flex:1" oninput="try{document.getElementById('f_sub_font_color_pick').value=this.value}catch(e){}">
      </div></div>
    <!-- sub_bg_color — الأصلي: #000000 -->
    <div class="fg" style="margin:0"><label class="fl">لون الخلفية <span style="color:var(--t3);font-weight:400">(الأصلي: #000000)</span></label>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="color" id="f_sub_bg_color_pick" value="#000000" style="width:48px;height:42px;border:1px solid var(--br);border-radius:8px;background:transparent;cursor:pointer" oninput="document.getElementById('f_sub_bg_color').value=this.value">
        <input type="text" class="fi" id="f_sub_bg_color" data-key="sub_bg_color" data-type="text" placeholder="#000000" style="flex:1" oninput="try{document.getElementById('f_sub_bg_color_pick').value=this.value}catch(e){}">
      </div></div>
    <!-- sub_position — الأصلي: bottom -->
    <div class="fg" style="margin:0"><label class="fl">موضع الترجمة <span style="color:var(--t3);font-weight:400">(الأصلي: bottom)</span></label>
      <select class="fs" id="f_sub_position" data-key="sub_position" data-type="text"><option value="top">top</option><option value="center">center</option><option value="bottom">bottom</option></select></div>
    <!-- sub_bg_opacity — الأصلي: 60 -->
    <div class="fg" style="margin:0"><label class="fl">شفافية الخلفية (0-100) <span style="color:var(--t3);font-weight:400">(الأصلي: 60)</span></label>
      <input type="number" class="fi" id="f_sub_bg_opacity" data-key="sub_bg_opacity" data-type="text" placeholder="60"></div>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('subtitles')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('subtitles')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات المسلسلات -->
  <div class="gs-acc" data-group="series" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#e040fb22;color:#e040fb;flex-shrink:0"><i class="fas fa-film"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات المسلسلات</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_series"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- sr_resume_last_ep — الأصلي: 1 -->
    <label class="fc-card" for="f_sr_resume_last_ep" style="cursor:pointer">
      <div class="fc-info"><b>التشغيل من آخر حلقة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_sr_resume_last_ep" data-key="sr_resume_last_ep" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- sr_auto_next_ep — الأصلي: 1 -->
    <label class="fc-card" for="f_sr_auto_next_ep" style="cursor:pointer">
      <div class="fc-info"><b>الانتقال للحلقة التالية تلقائياً</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_sr_auto_next_ep" data-key="sr_auto_next_ep" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- sr_skip_intro — الأصلي: 0 -->
    <label class="fc-card" for="f_sr_skip_intro" style="cursor:pointer">
      <div class="fc-info"><b>تخطي المقدمة (Skip Intro)</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_sr_skip_intro" data-key="sr_skip_intro" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- sr_skip_outro — الأصلي: 0 -->
    <label class="fc-card" for="f_sr_skip_outro" style="cursor:pointer">
      <div class="fc-info"><b>تخطي الشارة الختامية</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_sr_skip_outro" data-key="sr_skip_outro" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- sr_season_order — الأصلي: asc -->
    <div class="fg" style="margin:0"><label class="fl">ترتيب المواسم <span style="color:var(--t3);font-weight:400">(الأصلي: asc)</span></label>
      <select class="fs" id="f_sr_season_order" data-key="sr_season_order" data-type="text"><option value="asc">asc</option><option value="desc">desc</option></select></div>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('series')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('series')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات الأفلام -->
  <div class="gs-acc" data-group="movies" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#ff4d5722;color:#ff4d57;flex-shrink:0"><i class="fas fa-clapperboard"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات الأفلام</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_movies"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- mv_per_page — الأصلي: 24 -->
    <div class="fg" style="margin:0"><label class="fl">عدد الأفلام في الصفحة <span style="color:var(--t3);font-weight:400">(الأصلي: 24)</span></label>
      <input type="number" class="fi" id="f_mv_per_page" data-key="mv_per_page" data-type="text" placeholder="24"></div>
    <!-- mv_default_quality — الأصلي: auto -->
    <div class="fg" style="margin:0"><label class="fl">جودة العرض الافتراضية <span style="color:var(--t3);font-weight:400">(الأصلي: auto)</span></label>
      <select class="fs" id="f_mv_default_quality" data-key="mv_default_quality" data-type="text"><option value="auto">auto</option><option value="480">480</option><option value="720">720</option><option value="1080">1080</option></select></div>
    <!-- mv_auto_subtitle — الأصلي: 0 -->
    <label class="fc-card" for="f_mv_auto_subtitle" style="cursor:pointer">
      <div class="fc-info"><b>تشغيل الترجمة تلقائياً</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_mv_auto_subtitle" data-key="mv_auto_subtitle" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- mv_subtitle_language — الأصلي: ar -->
    <div class="fg" style="margin:0"><label class="fl">اللغة الافتراضية للترجمة <span style="color:var(--t3);font-weight:400">(الأصلي: ar)</span></label>
      <select class="fs" id="f_mv_subtitle_language" data-key="mv_subtitle_language" data-type="text"><option value="ar">ar</option><option value="en">en</option><option value="tr">tr</option><option value="fr">fr</option></select></div>
    <!-- mv_play_trailer — الأصلي: 1 -->
    <label class="fc-card" for="f_mv_play_trailer" style="cursor:pointer">
      <div class="fc-info"><b>تشغيل التريلر</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_mv_play_trailer" data-key="mv_play_trailer" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- mv_show_similar — الأصلي: 1 -->
    <label class="fc-card" for="f_mv_show_similar" style="cursor:pointer">
      <div class="fc-info"><b>عرض الأفلام المشابهة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_mv_show_similar" data-key="mv_show_similar" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- mv_resume_watch — الأصلي: 1 -->
    <label class="fc-card" for="f_mv_resume_watch" style="cursor:pointer">
      <div class="fc-info"><b>استكمال المشاهدة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_mv_resume_watch" data-key="mv_resume_watch" data-type="bool"><span class="fc-slider"></span></span>
    </label>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('movies')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('movies')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات القنوات -->
  <div class="gs-acc" data-group="channels" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#F5A62322;color:#F5A623;flex-shrink:0"><i class="fas fa-tv"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات القنوات</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_channels"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- ch_per_page — الأصلي: 40 -->
    <div class="fg" style="margin:0"><label class="fl">عدد القنوات في الصفحة <span style="color:var(--t3);font-weight:400">(الأصلي: 40)</span></label>
      <input type="number" class="fi" id="f_ch_per_page" data-key="ch_per_page" data-type="text" placeholder="40"></div>
    <!-- ch_order — الأصلي: display_order -->
    <div class="fg" style="margin:0"><label class="fl">ترتيب القنوات <span style="color:var(--t3);font-weight:400">(الأصلي: display_order)</span></label>
      <select class="fs" id="f_ch_order" data-key="ch_order" data-type="text"><option value="display_order">display_order</option><option value="name">name</option><option value="newest">newest</option></select></div>
    <!-- ch_group_order — الأصلي: display_order -->
    <div class="fg" style="margin:0"><label class="fl">ترتيب المجموعات <span style="color:var(--t3);font-weight:400">(الأصلي: display_order)</span></label>
      <select class="fs" id="f_ch_group_order" data-key="ch_group_order" data-type="text"><option value="display_order">display_order</option><option value="name">name</option></select></div>
    <!-- ch_hide_offline — الأصلي: 0 -->
    <label class="fc-card" for="f_ch_hide_offline" style="cursor:pointer">
      <div class="fc-info"><b>إخفاء القنوات غير المتصلة</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_ch_hide_offline" data-key="ch_hide_offline" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- ch_auto_status — الأصلي: 0 -->
    <label class="fc-card" for="f_ch_auto_status" style="cursor:pointer">
      <div class="fc-info"><b>تحديث حالة القنوات تلقائياً</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_ch_auto_status" data-key="ch_auto_status" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- ch_check_interval — الأصلي: 60 -->
    <div class="fg" style="margin:0"><label class="fl">فترة فحص القنوات (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 60)</span></label>
      <input type="number" class="fi" id="f_ch_check_interval" data-key="ch_check_interval" data-type="text" placeholder="60"></div>
    <!-- ch_resume_last — الأصلي: 1 -->
    <label class="fc-card" for="f_ch_resume_last" style="cursor:pointer">
      <div class="fc-info"><b>تشغيل آخر قناة تمت مشاهدتها</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_ch_resume_last" data-key="ch_resume_last" data-type="bool"><span class="fc-slider"></span></span>
    </label>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('channels')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('channels')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات مشغّل الفيديو -->
  <div class="gs-acc" data-group="player" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#00D08422;color:#00D084;flex-shrink:0"><i class="fas fa-play-circle"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات مشغّل الفيديو</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_player"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- pl_autoplay — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_autoplay" style="cursor:pointer">
      <div class="fc-info"><b>التشغيل التلقائي</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_autoplay" data-key="pl_autoplay" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_mute_on_start — الأصلي: 0 -->
    <label class="fc-card" for="f_pl_mute_on_start" style="cursor:pointer">
      <div class="fc-info"><b>كتم الصوت عند التشغيل</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_mute_on_start" data-key="pl_mute_on_start" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_auto_fullscreen — الأصلي: 0 -->
    <label class="fc-card" for="f_pl_auto_fullscreen" style="cursor:pointer">
      <div class="fc-info"><b>ملء الشاشة تلقائياً</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_auto_fullscreen" data-key="pl_auto_fullscreen" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_pip — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_pip" style="cursor:pointer">
      <div class="fc-info"><b>Picture in Picture</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_pip" data-key="pl_pip" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_webcast — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_webcast" style="cursor:pointer">
      <div class="fc-info"><b>webcast</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_webcast" data-key="pl_webcast" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_seek_buttons — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_seek_buttons" style="cursor:pointer">
      <div class="fc-info"><b>أزرار التقديم والترجيع</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_seek_buttons" data-key="pl_seek_buttons" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_playback_speed — الأصلي: 1 -->
    <div class="fg" style="margin:0"><label class="fl">سرعة التشغيل الافتراضية <span style="color:var(--t3);font-weight:400">(الأصلي: 1)</span></label>
      <select class="fs" id="f_pl_playback_speed" data-key="pl_playback_speed" data-type="text"><option value="0.5">0.5</option><option value="0.75">0.75</option><option value="1">1</option><option value="1.25">1.25</option><option value="1.5">1.5</option><option value="2">2</option></select></div>
    <!-- pl_thumbnails — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_thumbnails" style="cursor:pointer">
      <div class="fc-info"><b>معاينة الصور (Thumbnails)</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_thumbnails" data-key="pl_thumbnails" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_show_channel_logo — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_show_channel_logo" style="cursor:pointer">
      <div class="fc-info"><b>إظهار شعار القناة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_show_channel_logo" data-key="pl_show_channel_logo" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_show_channel_name — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_show_channel_name" style="cursor:pointer">
      <div class="fc-info"><b>إظهار اسم القناة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_show_channel_name" data-key="pl_show_channel_name" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_show_clock — الأصلي: 0 -->
    <label class="fc-card" for="f_pl_show_clock" style="cursor:pointer">
      <div class="fc-info"><b>إظهار الساعة</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_show_clock" data-key="pl_show_clock" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_show_viewers — الأصلي: 0 -->
    <label class="fc-card" for="f_pl_show_viewers" style="cursor:pointer">
      <div class="fc-info"><b>إظهار عداد المشاهدين</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_show_viewers" data-key="pl_show_viewers" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_show_share — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_show_share" style="cursor:pointer">
      <div class="fc-info"><b>إظهار زر المشاركة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_show_share" data-key="pl_show_share" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_show_report — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_show_report" style="cursor:pointer">
      <div class="fc-info"><b>إظهار زر الإبلاغ</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_show_report" data-key="pl_show_report" data-type="bool"><span class="fc-slider"></span></span>
    </label>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('player')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('player')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات البث (Streaming) -->
  <div class="gs-acc" data-group="streaming_client" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#4CC9F022;color:#4CC9F0;flex-shrink:0"><i class="fas fa-signal"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات البث (Streaming)</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_streaming_client"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- st_low_latency — الأصلي: 0 -->
    <label class="fc-card" for="f_st_low_latency" style="cursor:pointer">
      <div class="fc-info"><b>Low Latency Mode</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_st_low_latency" data-key="st_low_latency" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- st_buffer_size — الأصلي: 30 -->
    <div class="fg" style="margin:0"><label class="fl">Buffer Size (ثانية 1-30) <span style="color:var(--t3);font-weight:400">(الأصلي: 30)</span></label>
      <input type="number" class="fi" id="f_st_buffer_size" data-key="st_buffer_size" data-type="text" placeholder="30"></div>
    <!-- st_startup_buffer — الأصلي: 2 -->
    <div class="fg" style="margin:0"><label class="fl">Startup Buffer (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 2)</span></label>
      <input type="number" class="fi" id="f_st_startup_buffer" data-key="st_startup_buffer" data-type="text" placeholder="2"></div>
    <!-- st_max_buffer — الأصلي: 60 -->
    <div class="fg" style="margin:0"><label class="fl">Max Buffer Length (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 60)</span></label>
      <input type="number" class="fi" id="f_st_max_buffer" data-key="st_max_buffer" data-type="text" placeholder="60"></div>
    <!-- st_back_buffer — الأصلي: 90 -->
    <div class="fg" style="margin:0"><label class="fl">Back Buffer Length (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 90)</span></label>
      <input type="number" class="fi" id="f_st_back_buffer" data-key="st_back_buffer" data-type="text" placeholder="90"></div>
    <!-- st_live_sync — الأصلي: 3 -->
    <div class="fg" style="margin:0"><label class="fl">Live Sync Duration (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 3)</span></label>
      <input type="number" class="fi" id="f_st_live_sync" data-key="st_live_sync" data-type="text" placeholder="3"></div>
    <!-- st_auto_quality — الأصلي: 1 -->
    <label class="fc-card" for="f_st_auto_quality" style="cursor:pointer">
      <div class="fc-info"><b>Auto Quality (ABR)</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_st_auto_quality" data-key="st_auto_quality" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- st_default_quality — الأصلي: auto -->
    <div class="fg" style="margin:0"><label class="fl">الجودة الافتراضية <span style="color:var(--t3);font-weight:400">(الأصلي: auto)</span></label>
      <select class="fs" id="f_st_default_quality" data-key="st_default_quality" data-type="text"><option value="auto">auto</option><option value="480">480</option><option value="720">720</option><option value="1080">1080</option></select></div>
    <!-- st_allow_quality_change — الأصلي: 1 -->
    <label class="fc-card" for="f_st_allow_quality_change" style="cursor:pointer">
      <div class="fc-info"><b>السماح للمستخدم بتغيير الجودة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_st_allow_quality_change" data-key="st_allow_quality_change" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- st_auto_reconnect — الأصلي: 1 -->
    <label class="fc-card" for="f_st_auto_reconnect" style="cursor:pointer">
      <div class="fc-info"><b>إعادة الاتصال التلقائي عند الانقطاع</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_st_auto_reconnect" data-key="st_auto_reconnect" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- st_reconnect_attempts — الأصلي: 5 -->
    <div class="fg" style="margin:0"><label class="fl">عدد محاولات إعادة الاتصال <span style="color:var(--t3);font-weight:400">(الأصلي: 5)</span></label>
      <input type="number" class="fi" id="f_st_reconnect_attempts" data-key="st_reconnect_attempts" data-type="text" placeholder="5"></div>
    <!-- st_reconnect_timeout — الأصلي: 3 -->
    <div class="fg" style="margin:0"><label class="fl">المهلة قبل إعادة الاتصال (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 3)</span></label>
      <input type="number" class="fi" id="f_st_reconnect_timeout" data-key="st_reconnect_timeout" data-type="text" placeholder="3"></div>
    <!-- st_failover — الأصلي: 1 -->
    <label class="fc-card" for="f_st_failover" style="cursor:pointer">
      <div class="fc-info"><b>الانتقال لرابط احتياطي (Failover)</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_st_failover" data-key="st_failover" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- st_protocol — الأصلي: hls -->
    <div class="fg" style="margin:0"><label class="fl">استخدام HLS أو DASH <span style="color:var(--t3);font-weight:400">(الأصلي: hls)</span></label>
      <select class="fs" id="f_st_protocol" data-key="st_protocol" data-type="text"><option value="hls">hls</option><option value="dash">dash</option></select></div>
    <!-- st_llhls_support — الأصلي: 0 -->
    <label class="fc-card" for="f_st_llhls_support" style="cursor:pointer">
      <div class="fc-info"><b>دعم LL-HLS</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_st_llhls_support" data-key="st_llhls_support" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- st_playlist_refresh — الأصلي: 6 -->
    <div class="fg" style="margin:0"><label class="fl">مدة تحديث Playlist (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 6)</span></label>
      <input type="number" class="fi" id="f_st_playlist_refresh" data-key="st_playlist_refresh" data-type="text" placeholder="6"></div>
    <!-- st_stream_cache — الأصلي: 1 -->
    <label class="fc-card" for="f_st_stream_cache" style="cursor:pointer">
      <div class="fc-info"><b>Cache للبث</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_st_stream_cache" data-key="st_stream_cache" data-type="bool"><span class="fc-slider"></span></span>
    </label>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('streaming_client')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('streaming_client')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- زر الحفظ الرئيسي -->
  <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;position:sticky;bottom:0;background:var(--s0,transparent);padding:6px 0">
    <button class="btn btn-p" onclick="saveGeneralSettings()" style="padding:12px 30px"><i class="fas fa-save"></i> حفظ كل الإعدادات العامة</button>
    <button class="btn btn-g" onclick="loadGeneralSettings()"><i class="fas fa-rotate-left"></i> استرجاع المحفوظ</button>
    <button class="btn btn-d" onclick="restoreDefaultSettings()" style="padding:12px 30px; background-color: #d32f2f; color: white; border-color: transparent;"><i class="fas fa-undo"></i> استرجاع كل القيم الأصلية</button>
  </div>
</section>

<section id="frontend-control" class="sec">
  <div class="shdr">
    <h1 class="stitle"><i class="fas fa-sliders-h" style="color:#00D084"></i> التحكم <span>بالواجهة الأمامية</span></h1>
  </div>
  <p style="color:var(--t3);font-size:.86rem;margin-bottom:22px;line-height:1.7">
    تحكّم في إظهار أو إخفاء عناصر الصفحة الرئيسية (index.php) لجميع الزوار. التغييرات تُحفظ في قاعدة البيانات وتُطبَّق فوراً على الموقع.
  </p>
  <div id="fcAlert"></div>

  <div class="fc-grid">
    <label class="fc-card" for="fc_search">
      <div class="fc-ic" style="background:rgba(88,166,255,.12);color:#58a6ff"><i class="fas fa-search"></i></div>
      <div class="fc-info"><b>شريط البحث</b><small>إخفاء حقل البحث من الأعلى</small></div>
      <span class="fc-switch"><input type="checkbox" id="fc_search" data-key="hide_search"><span class="fc-slider"></span></span>
    </label>

    <label class="fc-card" for="fc_notif">
      <div class="fc-ic" style="background:rgba(245,166,35,.12);color:#f5a623"><i class="fas fa-bell"></i></div>
      <div class="fc-info"><b>الإشعارات</b><small>إخفاء زر ولوحة الإشعارات</small></div>
      <span class="fc-switch"><input type="checkbox" id="fc_notif" data-key="hide_notifications"><span class="fc-slider"></span></span>
    </label>

    <label class="fc-card" for="fc_fav">
      <div class="fc-ic" style="background:rgba(255,77,87,.12);color:#ff4d57"><i class="fas fa-heart"></i></div>
      <div class="fc-info"><b>المفضلة</b><small>إخفاء زر ولوحة المفضلة</small></div>
      <span class="fc-switch"><input type="checkbox" id="fc_fav" data-key="hide_favorites"><span class="fc-slider"></span></span>
    </label>

    <label class="fc-card" for="fc_music">
      <div class="fc-ic" style="background:rgba(224,64,251,.12);color:#e040fb"><i class="fas fa-music"></i></div>
      <div class="fc-info"><b>مشغل الموسيقى</b><small>إخفاء زر موسيقى الخلفية</small></div>
      <span class="fc-switch"><input type="checkbox" id="fc_music" data-key="hide_music"><span class="fc-slider"></span></span>
    </label>

    <label class="fc-card" for="fc_admin">
      <div class="fc-ic" style="background:rgba(229,9,20,.12);color:var(--red)"><i class="fas fa-shield-alt"></i></div>
      <div class="fc-info"><b>زر لوحة التحكم</b><small>إخفاء رابط الدخول للوحة الإدارة</small></div>
      <span class="fc-switch"><input type="checkbox" id="fc_admin" data-key="hide_admin_btn"><span class="fc-slider"></span></span>
    </label>

    <label class="fc-card" for="fc_social">
      <div class="fc-ic" style="background:rgba(37,211,102,.12);color:#25D366"><i class="fas fa-share-alt"></i></div>
      <div class="fc-info"><b>أزرار التواصل</b><small>إخفاء واتساب وفيسبوك والبريد</small></div>
      <span class="fc-switch"><input type="checkbox" id="fc_social" data-key="hide_social"><span class="fc-slider"></span></span>
    </label>

    <label class="fc-card" for="fc_download">
      <div class="fc-ic" style="background:rgba(0,208,132,.12);color:#00D084"><i class="fas fa-download"></i></div>
      <div class="fc-info"><b>زر التحميل</b><small>إخفاء زر تحميل الفيديو في المشغل</small></div>
      <span class="fc-switch"><input type="checkbox" id="fc_download" data-key="hide_download"><span class="fc-slider"></span></span>
    </label>

    <label class="fc-card" for="fc_cast">
      <div class="fc-ic" style="background:rgba(76,201,240,.12);color:#4CC9F0"><i class="fas fa-tv"></i></div>
      <div class="fc-info"><b>البث على التلفاز</b><small>منع البث للشاشات الذكية (Cast)</small></div>
      <span class="fc-switch"><input type="checkbox" id="fc_cast" data-key="hide_cast"><span class="fc-slider"></span></span>
    </label>

    <label class="fc-card" for="fc_most_watched">
      <div class="fc-ic" style="background:rgba(255,159,28,.12);color:#ff9f1c"><i class="fas fa-fire"></i></div>
      <div class="fc-info"><b>الأكثر مشاهدة</b><small>إخفاء شريط الأكثر مشاهدة من الرئيسية</small></div>
      <span class="fc-switch"><input type="checkbox" id="fc_most_watched" data-key="hide_most_watched"><span class="fc-slider"></span></span>
    </label>

    <label class="fc-card" for="fc_suggestions">
      <div class="fc-ic" style="background:rgba(88,166,255,.12);color:#58a6ff"><i class="fas fa-magic"></i></div>
      <div class="fc-info"><b>مقترحات قد تعجبك</b><small>إخفاء شريط المقترحات من الرئيسية</small></div>
      <span class="fc-switch"><input type="checkbox" id="fc_suggestions" data-key="hide_suggestions"><span class="fc-slider"></span></span>
    </label>

    <label class="fc-card" for="fc_screensaver">
      <div class="fc-ic" style="background:rgba(70,211,105,.12);color:#46d369"><i class="fas fa-desktop"></i></div>
      <div class="fc-info"><b>شاشة التوقف</b><small>إيقاف شاشة التوقف (Screensaver) عن كل الزوار</small></div>
      <span class="fc-switch"><input type="checkbox" id="fc_screensaver" data-key="hide_screensaver"><span class="fc-slider"></span></span>
    </label>
  </div>

  <div style="margin-top:24px;display:flex;gap:10px;flex-wrap:wrap">
    <button class="btn btn-p" onclick="saveFrontendToggles()"><i class="fas fa-save"></i> حفظ التغييرات</button>
    <button class="btn btn-g" onclick="loadFrontendToggles()"><i class="fas fa-sync-alt"></i> استرجاع المحفوظ</button>
  </div>
</section>












<!-- ADMIN MUSIC PLAYER SECTION REMOVED - intero.mp3 fixed -->

</div>
<!-- ══ فوتر النظام (إضافة) ══ -->
<footer class="sys-footer">
  <span class="sf-dot"></span>
  <span>SHA System &copy; 2026 <b>SHASHITY PRO</b></span>
</footer>
</div>

<!-- MODALS -->
<!-- PLAYER -->
<div id="pm">
  <div class="pbox"><div class="phd"><div class="phd-l"><div class="pdot" id="pdot"></div><div class="ptitle" id="ptitle">جارٍ التحميل…</div></div><div style="display:flex;align-items:center;gap:8px"><span id="pcodec" style="display:none;font-size:.65rem;font-weight:800;padding:2px 8px;border-radius:4px;margin-left:5px;"></span><span id="pfmt" style="display:none;font-size:.65rem;font-weight:800;padding:2px 8px;border-radius:4px;background:rgba(229,9,20,.15);border:1px solid rgba(229,9,20,.3);color:var(--red)">HLS</span><button class="mclose" onclick="closePlayer()"><i class="fas fa-times"></i></button></div></div>
  <div class="pwrap"><video id="tv" controls playsinline crossorigin="anonymous"></video><div class="pload" id="pload"><div class="pspin"></div><p style="font-size:.83rem;color:var(--t3)">جارٍ تحميل الفيديو…</p></div><div class="perr" id="perr"><div style="font-size:2.5rem;color:var(--red)"><i class="fas fa-exclamation-triangle"></i></div><h3>تعذّر تشغيل الفيديو</h3><p id="perrMsg" style="color:var(--t3);font-size:.83rem;max-width:360px">تحقق من الرابط أو تنسيق الملف</p><div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap;justify-content:center"><button class="btn btn-p" onclick="pRetry()"><i class="fas fa-redo"></i>إعادة المحاولة</button><button class="btn btn-g" onclick="pOpenNew()"><i class="fas fa-external-link-alt"></i>فتح في تبويب</button></div></div></div>
  <div class="psubbar" id="psubbar" style="display:none"><i class="fas fa-closed-captioning" style="color:#4CC9F0"></i><span id="psubLabel" style="flex:1;font-size:.75rem">ترجمة نشطة</span><button class="pbtn" onclick="pToggleSub()"><i class="fas fa-toggle-on" id="psubToggleIc"></i><span id="psubToggleTxt">إخفاء</span></button></div>
  <div class="pft"><span class="purl" id="purl">—</span><div class="pbtns"><button class="pbtn" onclick="pCopyUrl()"><i class="fas fa-copy"></i>نسخ</button><button class="pbtn" onclick="pOpenNew()"><i class="fas fa-external-link-alt"></i>جديد</button><button class="pbtn" style="background:rgba(229,9,20,.1);color:var(--red);border-color:rgba(229,9,20,.2)" onclick="closePlayer()"><i class="fas fa-times"></i>إغلاق</button></div></div>
  </div>
</div>

<!-- ADD CATEGORY MODAL -->
<div class="mbd" id="addCatM"><div class="mbox"><div class="mhd"><div class="mhd-title"><i class="fas fa-folder-plus"></i>قسم جديد</div><button class="mclose" onclick="CM('addCatM')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="mbody"><div class="fg"><label class="fl">اسم القسم</label><input type="text" name="category_name" class="fi" required placeholder="مثال: أفلام عربية"></div><div class="fg"><label class="fl">القسم الأب (اختياري)</label><select name="parent_id" class="fs"><option value="">بدون — قسم رئيسي</option><?php foreach($categories as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option><?php endforeach; ?></select></div><div class="fg"><label class="fl">الأيقونة</label><input type="text" name="category_icon" class="fi" value="fas fa-th-large" placeholder="fas fa-film"></div><div class="fg"><label class="fl">الوصف (اختياري)</label><input type="text" name="description" class="fi" placeholder="وصف مختصر"></div></div>
<div class="mfooter"><button type="button" class="btn btn-g" onclick="CM('addCatM')">إلغاء</button><button type="submit" name="add_category" class="btn btn-p"><i class="fas fa-check"></i>إضافة</button></div></form></div></div>

<!-- EDIT CATEGORY MODAL -->
<div class="mbd" id="editCatM"><div class="mbox"><div class="mhd"><div class="mhd-title"><i class="fas fa-pen"></i>تعديل القسم</div><button class="mclose" onclick="CM('editCatM')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="mbody"><input type="hidden" name="category_id" id="eCatId"><div class="fg"><label class="fl">اسم القسم</label><input type="text" name="category_name" id="eCatName" class="fi" required></div><div class="fg"><label class="fl">القسم الأب</label><select name="parent_id" id="eCatParent" class="fs"><option value="">بدون — قسم رئيسي</option><?php foreach($categories as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option><?php endforeach; ?></select></div><div class="fg"><label class="fl">الأيقونة</label><input type="text" name="category_icon" id="eCatIcon" class="fi"></div></div>
<div class="mfooter"><button type="button" class="btn btn-g" onclick="CM('editCatM')">إلغاء</button><button type="submit" name="edit_category" class="btn btn-p"><i class="fas fa-check"></i>حفظ</button></div></form></div></div>

<!-- ADD CHANNEL MODAL -->
<div class="mbd" id="addChM"><div class="mbox w"><div class="mhd"><div class="mhd-title"><i class="fas fa-plus"></i>قناة جديدة</div><button class="mclose" onclick="CM('addChM')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="mbody"><div class="fg"><label class="fl">القسم</label><select name="category_id" class="fs" required><option value="">— اختر القسم —</option><?php foreach($categories as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option><?php endforeach; ?></select></div>
<div class="fg fg-rel"><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:7px"><label class="fl" style="margin:0">اسم القناة</label></div><input type="text" name="channel_name" id="addChName" class="fi" required placeholder="مثال: MBC1"></div>
<div class="fg"><label class="fl">رابط البث</label><input type="text" name="stream_url" class="fi" required placeholder="https://..."></div>
<div class="fg"><label class="fl">رابط احتياطي (Backup URL) <span style="color:var(--t3);font-weight:400">— اختياري</span></label><input type="text" name="backup_url" class="fi" placeholder="https://... رابط بديل عند تعطّل الرابط الأساسي"></div>
<div class="fg"><label class="fl">الجودة</label><select name="quality" class="fs">
  <option value="SD 480">SD 480</option>
  <option value="HD 720" selected>HD 720</option>
  <option value="Full HD 1080P">Full HD 1080P</option>
  <option value="4K UHD">4K UHD</option>
</select></div>
<div class="fg"><label class="fl" style="display:flex;align-items:center;justify-content:space-between">الحالة<label class="fc-switch" style="display:inline-flex"><input type="checkbox" name="is_active" value="1" checked><span class="fc-slider"></span></label></label></div>
<div class="fg"><label class="fl">الأيقونة</label><input type="text" name="logo_icon" class="fi" value="fas fa-tv"></div>
<div class="fg">
      <label class="fl">رابط الشعار</label>
      <div class="image-upload-row">
        <div style="flex:1">
          <input type="text" name="logo_url" id="addChLogo" class="fi" placeholder="https://example.com/logo.png" oninput="previewImage('addPrev',this.value)">
        </div>
        <label class="upload-btn">
          <i class="fas fa-upload"></i>رفع صورة
          <input type="file" accept="image/png,image/jpeg,image/jpg,image/webp,image/gif" style="display:none" onchange="uploadChannelLogo(this,'addChLogo','addPrev','addLogoStatus')">
        </label>
      </div>
      <div id="addLogoStatus" style="font-size:.75rem;margin-top:4px"></div>
      <div class="image-preview" id="addPrev"><img src="" alt="معاينة"></div>
    </div></div>
<div class="mfooter"><button type="button" class="btn btn-g" onclick="CM('addChM')">إلغاء</button><button type="submit" name="add_channel" class="btn btn-p"><i class="fas fa-check"></i>إضافة</button></div></form></div></div>

<!-- EDIT CHANNEL MODAL -->
<div class="mbd" id="editChM"><div class="mbox w"><div class="mhd"><div class="mhd-title"><i class="fas fa-pen"></i>تعديل القناة</div><button class="mclose" onclick="CM('editChM')"><i class="fas fa-times"></i></button></div>
<form method="POST"><div class="mbody"><input type="hidden" name="channel_id" id="eChId">
<div class="fg fg-rel"><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:7px"><label class="fl" style="margin:0">اسم القناة</label></div><input type="text" name="channel_name" id="eChName" class="fi" required></div>
<div class="fg"><label class="fl">القسم</label><select name="category_id" id="eChCat" class="fs" required><option value="">— اختر —</option><?php foreach($categories as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option><?php endforeach; ?></select></div>
<div class="fg"><label class="fl">رابط البث</label><input type="text" name="stream_url" id="eChUrl" class="fi" required></div>
<div class="fg"><label class="fl">رابط احتياطي (Backup URL) <span style="color:var(--t3);font-weight:400">— اختياري</span></label><input type="text" name="backup_url" id="eChBackup" class="fi" placeholder="https://... رابط بديل عند تعطّل الرابط الأساسي"></div>
<div class="fg"><label class="fl">الجودة</label><select name="quality" id="eChQuality" class="fs">
  <option value="SD 480">SD 480</option>
  <option value="HD 720">HD 720</option>
  <option value="Full HD 1080P">Full HD 1080P</option>
  <option value="4K UHD">4K UHD</option>
</select></div>
<div class="fg"><label class="fl" style="display:flex;align-items:center;justify-content:space-between">الحالة<label class="fc-switch" style="display:inline-flex"><input type="checkbox" name="is_active" id="eChActive" value="1" checked><span class="fc-slider"></span></label></label></div>
<div class="fg"><label class="fl">الأيقونة</label><input type="text" name="logo_icon" id="eChIcon" class="fi"></div>
<div class="fg">
      <label class="fl">رابط الشعار</label>
      <div class="image-upload-row">
        <div style="flex:1">
          <input type="text" name="logo_url" id="eChLogo" class="fi" placeholder="https://..." oninput="previewImage('editPrev',this.value)">
        </div>
        <label class="upload-btn">
          <i class="fas fa-upload"></i>رفع صورة
          <input type="file" accept="image/png,image/jpeg,image/jpg,image/webp,image/gif" style="display:none" onchange="uploadChannelLogo(this,'eChLogo','editPrev','editLogoStatus')">
        </label>
      </div>
      <div id="editLogoStatus" style="font-size:.75rem;margin-top:4px"></div>
      <div class="image-preview" id="editPrev"><img src="" alt="معاينة"></div>
    </div></div>
<div class="mfooter"><button type="button" class="btn btn-g" onclick="CM('editChM')">إلغاء</button><button type="submit" name="edit_channel" class="btn btn-p"><i class="fas fa-check"></i>حفظ</button></div></form></div></div>

<!-- ADD SERIES MODAL -->
<div class="mbd" id="addSeriesM"><div class="mbox"><div class="mhd"><div class="mhd-title"><i class="fas fa-film"></i>مسلسل / فيلم جديد</div><button class="mclose" onclick="CM('addSeriesM')"><i class="fas fa-times"></i></button></div>
<div class="mbody"><div class="fg"><label class="fl"><i class="fas fa-globe" style="color:#4CC9F0"></i> مصدر البحث</label>
  <div class="source-tabs" id="addSrSourceTabs">
    <button type="button" class="source-tab active tmdb-active" onclick="switchSource('add','tmdb',this)"><i class="fas fa-film"></i> TMDB</button>
    <button type="button" class="source-tab" onclick="switchSource('add','anilist',this)"><i class="fas fa-dragon"></i> AniList</button>
    <button type="button" class="source-tab" onclick="switchSource('add','omdb',this)"><i class="fas fa-database"></i> OMDb</button>
  </div>
</div><div class="fg media-search-wrap"><label class="fl">الاسم</label><div class="media-search-row"><input type="text" class="fi" id="srName" placeholder="ابحث عن اسم الفيلم أو المسلسل..." oninput="mediaAutoSearch('add',this.value)" onkeydown="if(event.key==='Enter'){event.preventDefault();mediaSearch('add')}"><button type="button" class="btn btn-g bsm" onclick="mediaSearch('add')"><i class="fas fa-search"></i></button></div><div class="media-search-results" id="mediaRes_add"></div></div><div class="fg"><label class="fl">القسم</label><select class="fs" id="srCat"><option value="">— اختر القسم —</option><?php foreach($categories as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option><?php endforeach; ?></select></div>
<div class="fg"><label class="fl">صورة البوستر</label><div style="display:flex;gap:8px;align-items:flex-start"><div style="flex:1"><input type="text" class="fi" id="srPoster" placeholder="https://example.com/poster.jpg" oninput="srPosterPreview('srPosterThumb',this.value)"></div><label style="flex-shrink:0;display:flex;align-items:center;gap:6px;padding:9px 13px;background:var(--s3);border:1px solid var(--br);border-radius:var(--r1);cursor:pointer;font-size:.78rem;font-weight:700;color:var(--t2);transition:all .15s;white-space:nowrap"><i class="fas fa-upload" style="color:var(--red)"></i>رفع صورة<input type="file" accept="image/png,image/jpeg,image/jpg,image/webp" style="display:none" onchange="srPosterUpload(this,'srPoster','srPosterThumb','srPosterStatus')"></label></div><div id="srPosterStatus" style="margin-top:6px;font-size:.75rem"></div><div id="srPosterThumb" style="margin-top:8px;display:none"><img src="" style="width:80px;height:110px;object-fit:cover;border-radius:var(--r1);border:2px solid var(--br)"></div></div>
<div class="fg"><label class="fl">الوصف (اختياري)</label><textarea class="fi" id="srDesc" rows="3" style="resize:vertical" placeholder="وصف مختصر…"></textarea></div><div id="srAddAlert"></div></div>
<div class="mfooter"><button class="btn btn-g" onclick="CM('addSeriesM')">إلغاء</button><button class="btn btn-p" onclick="srAdd()"><i class="fas fa-check"></i>إضافة</button></div></div></div>

<!-- EDIT SERIES MODAL -->
<div class="mbd" id="editSeriesM"><div class="mbox"><div class="mhd"><div class="mhd-title"><i class="fas fa-pen"></i>تعديل شاشتي</div><button class="mclose" onclick="CM('editSeriesM')"><i class="fas fa-times"></i></button></div>
<div class="mbody"><input type="hidden" id="eSrId"><div class="fg"><label class="fl"><i class="fas fa-globe" style="color:#4CC9F0"></i> مصدر البحث</label>
  <div class="source-tabs" id="editSrSourceTabs">
    <button type="button" class="source-tab active tmdb-active" onclick="switchSource('edit','tmdb',this)"><i class="fas fa-film"></i> TMDB</button>
    <button type="button" class="source-tab" onclick="switchSource('edit','anilist',this)"><i class="fas fa-dragon"></i> AniList</button>
    <button type="button" class="source-tab" onclick="switchSource('edit','omdb',this)"><i class="fas fa-database"></i> OMDb</button>
  </div>
</div><div class="fg media-search-wrap"><label class="fl">الاسم</label><div class="media-search-row"><input type="text" class="fi" id="eSrName"  oninput="mediaAutoSearch('edit',this.value)" onkeydown="if(event.key==='Enter'){event.preventDefault();mediaSearch('edit')}"><button type="button" class="btn btn-g bsm" onclick="mediaSearch('edit')"><i class="fas fa-search"></i></button></div><div class="media-search-results" id="mediaRes_edit"></div></div><div class="fg"><label class="fl">القسم</label><select class="fs" id="eSrCat"><option value="">— اختر —</option><?php foreach($categories as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option><?php endforeach; ?></select></div>
<div class="fg"><label class="fl">صورة البوستر</label><div style="display:flex;gap:8px;align-items:flex-start"><div style="flex:1"><input type="text" class="fi" id="eSrPoster" placeholder="https://..." oninput="srPosterPreview('eSrPosterThumb',this.value)"></div><label style="flex-shrink:0;display:flex;align-items:center;gap:6px;padding:9px 13px;background:var(--s3);border:1px solid var(--br);border-radius:var(--r1);cursor:pointer;font-size:.78rem;font-weight:700;color:var(--t2);transition:all .15s;white-space:nowrap"><i class="fas fa-upload" style="color:var(--red)"></i>رفع صورة<input type="file" accept="image/png,image/jpeg,image/jpg,image/webp" style="display:none" onchange="srPosterUpload(this,'eSrPoster','eSrPosterThumb','eSrPosterStatus')"></label></div><div id="eSrPosterStatus" style="margin-top:6px;font-size:.75rem"></div><div id="eSrPosterThumb" style="margin-top:8px;display:none"><img src="" style="width:80px;height:110px;object-fit:cover;border-radius:var(--r1);border:2px solid var(--br)"></div></div>
<div class="fg"><label class="fl">الوصف</label><textarea class="fi" id="eSrDesc" rows="3" style="resize:vertical"></textarea></div><div id="eSrAlert"></div></div>
<div class="mfooter"><button class="btn btn-g" onclick="CM('editSeriesM')">إلغاء</button><button class="btn btn-p" onclick="srEditSave()"><i class="fas fa-check"></i>حفظ</button></div></div></div>

<!-- ADD EPISODE MODAL -->
<div class="mbd" id="addEpM"><div class="mbox w"><div class="mhd"><div class="mhd-title"><i class="fas fa-plus"></i>إضافة فيديو/حلقة</div><button class="mclose" onclick="CM('addEpM')"><i class="fas fa-times"></i></button></div>
<div class="mbody"><div class="row2"><div class="fg"><label class="fl">رقم الحلقة</label><input type="number" class="fi" id="epNum" value="1" min="1"></div><div class="fg"><label class="fl">العنوان</label><input type="text" class="fi" id="epTitle" placeholder="الحلقة 1 أو اسم الفيلم"></div></div>
<div class="etabs"><button class="etab on" onclick="etab('url')">رابط مباشر</button><button class="etab" onclick="etab('file')">رفع ملف</button></div>
<div id="etab-url"><div class="fg"><label class="fl">رابط الفيديو</label><input type="text" class="fi" id="epUrl" placeholder="https://..."></div></div>
<div id="etab-file" style="display:none"><div class="fg"><label class="fl">رفع ملف الفيديو</label><div class="uz" style="padding:22px"><input type="file" accept="video/*" onchange="epFileUpload(this)"><i class="fas fa-video"></i><h3>اختر ملف الفيديو</h3><p>MP4 · MKV · AVI</p></div><div id="epFileChip" style="display:none;margin-top:8px;padding:9px 12px;background:rgba(0,208,132,.07);border:1px solid rgba(0,208,132,.22);border-radius:var(--r1);font-size:.8rem;align-items:center;gap:8px"><i class="fas fa-check-circle" style="color:#00D084"></i><span id="epFileChipName">—</span></div><div id="epFileProgress" style="display:none;margin-top:8px"><div class="pw"><div class="pb" id="epFilePBar"></div></div></div><input type="hidden" id="epUploadedUrl"></div></div>
<div class="fg"><label class="fl">رابط الترجمة (اختياري)</label><input type="text" class="fi" id="epSubUrl" placeholder="https://... (SRT أو VTT)"></div>
<div class="orsep">أو</div>
<div class="fg"><label class="fl">رفع ملف ترجمة</label><div class="uz" style="padding:18px"><input type="file" accept=".srt,.ass,.vtt,.ssa" onchange="epSubUpload(this)"><i class="fas fa-file-alt"></i><h3>اختر ملف الترجمة</h3><p>SRT · VTT · ASS</p></div><div id="epSubChip" style="display:none;margin-top:8px;padding:9px 12px;background:rgba(76,201,240,.07);border:1px solid rgba(76,201,240,.22);border-radius:var(--r1);font-size:.8rem;align-items:center;gap:8px"><i class="fas fa-closed-captioning" style="color:#4CC9F0"></i><span id="epSubChipName">—</span></div></div>
<div class="fg"><label class="fl">المدة (اختياري)</label><input type="text" class="fi" id="epDur" placeholder="45:00"></div><div id="addEpAlert"></div></div>
<div class="mfooter"><button class="btn btn-g" onclick="CM('addEpM')">إلغاء</button><button class="btn btn-p" onclick="epAdd()"><i class="fas fa-check"></i>إضافة</button></div></div></div>

<!-- OS SEARCH MODAL FOR EDIT -->
<div class="mbd" id="eEpOsM" style="z-index:3000;">
  <div class="mbox w">
    <div class="mhd"><div class="mhd-title"><i class="fas fa-closed-captioning" style="color:#4CC9F0"></i> جلب ترجمة من OpenSubtitles</div><button class="mclose" onclick="CM('eEpOsM')"><i class="fas fa-times"></i></button></div>
    <div class="mbody">
      <div class="srow">
        <div class="sinp"><i class="fas fa-film"></i><input type="text" id="eEpOsQ" placeholder="اسم الفيلم..." onkeydown="if(event.key==='Enter')eEpOsSearch()"></div>
        <select class="lsel" id="eEpOsLang" style="width:auto;"><option value="ar">🇸🇦 عربي</option><option value="en">🇬🇧 English</option></select>
        <button class="btn btn-p" onclick="eEpOsSearch()" id="eEpOsSearchBtn"><i class="fas fa-search"></i> بحث</button>
      </div>
      <div id="eEpOsAl" style="margin-top:8px;font-size:0.8rem;"></div>
      <div class="sub-rl" id="eEpOsRes" style="margin-top:10px; max-height:250px; overflow-y:auto; border:1px solid var(--br); border-radius:var(--r1);"></div>
    </div>
  </div>
</div>

<!-- EDIT EPISODE MODAL -->
<div class="mbd" id="editEpM"><div class="mbox w"><div class="mhd"><div class="mhd-title"><i class="fas fa-pen"></i>تعديل ونقل الفيديو</div><button class="mclose" onclick="CM('editEpM')"><i class="fas fa-times"></i></button></div>
<div class="mbody">
    <input type="hidden" id="eEpId">
    
    <div style="background:rgba(245,166,35,.07);border:1px solid rgba(245,166,35,.18);border-radius:var(--r1);padding:11px 14px;margin-bottom:16px;">
       <label class="fl" style="color:var(--gold)"><i class="fas fa-folder-open"></i> المجلد الوجهة بداخل إدارة شاشتي</label>
       <select class="fs" id="eEpSeriesId"></select>
    </div>

    <div class="row2">
        <div class="fg"><label class="fl">رقم الحلقة</label><input type="number" class="fi" id="eEpNum" min="1"></div>
        <div class="fg"><label class="fl">العنوان</label><input type="text" class="fi" id="eEpTitle"></div>
    </div>
    <div class="fg"><label class="fl">رابط الفيديو</label><input type="text" class="fi" id="eEpUrl"></div>
    <div class="fg">
    <label class="fl">رابط الترجمة (أضف يدوياً أو اجلب تلقائياً)</label>
    <div style="display:flex; gap:8px; align-items:center;">
        <input type="text" class="fi" id="eEpSub" placeholder="https://..." style="flex:1;">
        <label class="btn btn-s bsm" style="cursor:pointer; margin:0; padding:8px 12px; white-space:nowrap;">
            <i class="fas fa-upload"></i> رفع ملف
            <input type="file" accept=".srt,.ass,.vtt,.ssa" style="display:none;" onchange="eEpSubUpload(this)">
        </label>
        <button type="button" class="btn btn-b bsm" style="padding:8px 12px; white-space:nowrap;" onclick="eEpOpenOS()">
            <i class="fas fa-search"></i> OpenSubtitles
        </button>
    </div>
    <div id="eEpSubStatus" style="margin-top:5px; font-size:0.75rem;"></div>
</div>
    <div class="fg"><label class="fl">المدة</label><input type="text" class="fi" id="eEpDur" placeholder="45:00"></div>
    <div id="eEpAlert"></div>
</div>
<div class="mfooter"><button class="btn btn-g" onclick="CM('editEpM')">إلغاء</button><button class="btn btn-p" onclick="epEditSave()"><i class="fas fa-save"></i>حفظ التعديلات</button></div></div></div>

<!-- BULK UPLOAD MODAL -->
<div class="mbd" id="bulkM"><div class="mbox w"><div class="mhd"><div class="mhd-title"><i class="fas fa-folder-open"></i>رفع مجلد مسلسل كامل</div><button class="mclose" onclick="CM('bulkM')"><i class="fas fa-times"></i></button></div>
<div class="mbody"><div style="background:rgba(76,201,240,.06);border:1px solid rgba(76,201,240,.18);border-radius:var(--r1);padding:11px 14px;margin-bottom:16px;font-size:.8rem;color:var(--t2)"><i class="fas fa-info-circle" style="color:#4CC9F0;margin-left:5px"></i>اختر جميع ملفات حلقات المسلسل دفعة واحدة.</div>
<div class="fg"><label class="fl">اختر ملفات الحلقات (متعددة)</label><div class="uz" id="bulkDZ"><input type="file" id="bulkFiles" accept="video/*" multiple onchange="bulkPreview(this.files)"><i class="fas fa-folder-open"></i><h3>اختر ملفات الحلقات</h3><p>اضغط لاختيار أكثر من ملف</p></div></div>
<div id="bulkPreviewList" style="display:none;margin-bottom:14px"><div style="font-size:.78rem;font-weight:700;color:var(--t2);margin-bottom:8px;display:flex;align-items:center;justify-content:space-between"><span id="bulkPreviewTitle"></span><span id="bulkTotalSize" style="color:var(--t3)"></span></div><div id="bulkItems" style="max-height:280px;overflow-y:auto;display:flex;flex-direction:column;gap:5px"></div></div>
<div id="bulkProgress" style="display:none;margin-bottom:14px"><div style="display:flex;justify-content:space-between;font-size:.78rem;margin-bottom:6px"><span id="bulkProgLabel" style="color:var(--t2)">رفع الحلقات…</span><span id="bulkProgPct" style="color:var(--t3)">0%</span></div><div class="pw"><div class="pb" id="bulkPBar"></div></div><div id="bulkCurFile" style="font-size:.72rem;color:var(--t3);margin-top:5px"></div></div>
<div id="bulkResult" style="display:none"></div><div id="bulkAlert"></div></div>
<div class="mfooter"><button class="btn btn-g" onclick="CM('bulkM')">إغلاق</button><button class="btn btn-p" id="bulkStartBtn" style="display:none" onclick="bulkUpload()"><i class="fas fa-upload"></i>ابدأ الرفع</button></div></div></div>

<!-- VM SAVE MODAL -->
<div class="mbd" id="vmSaveM"><div class="mbox"><div class="mhd"><div class="mhd-title"><i class="fas fa-save"></i>حفظ الفيديو في شاشتي</div><button class="mclose" onclick="CM('vmSaveM')"><i class="fas fa-times"></i></button></div>
<div class="mbody">
  <p id="vmSaveFile" style="font-size:.78rem;color:var(--t3);margin-bottom:8px"></p>
  <div id="vmSaveSub" style="display:none;font-size:.78rem;color:#00D084;margin-bottom:16px;background:rgba(0,208,132,.07);padding:8px 12px;border-radius:4px;border:1px solid rgba(0,208,132,.2)"><i class="fas fa-check-circle"></i> تم إرفاق ملف ترجمة بنجاح</div>
  <input type="hidden" id="vmSaveSubUrl">
  
  <div class="fg" style="background:rgba(245,166,35,.07);padding:14px;border:1px dashed rgba(245,166,35,.3);border-radius:var(--r1)">
     <label class="fl" style="color:var(--gold)"><i class="fas fa-folder"></i> إرسال هذا الملف إلى:</label>
     <select class="fs" id="vmSaveTargetSeries" onchange="vToggleSeriesFields(this.value, 'manage')"></select>
  </div>

  <div class="fg"><label class="fl" id="vmNameLabel">الاسم أو العنوان</label><input type="text" class="fi" id="vmSaveTitle" placeholder="اسم الفيلم / أو عنوان الحلقة المضافة"></div>
  <div class="fg" id="vmCatDiv"><label class="fl">قسم العمل (مطلوب)</label><select class="fs" id="vmSaveCat"><option value="">— اختر القسم —</option><?php foreach($categories as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option><?php endforeach; ?></select></div>
  <div id="vmSaveAlert"></div>
</div>
<div class="mfooter"><button class="btn btn-g" onclick="CM('vmSaveM')">إلغاء</button><button class="btn btn-p" onclick="vmDoSave()"><i class="fas fa-check"></i>حفظ في شاشتي</button></div></div></div>

<!-- VM MOVE MODAL -->
<div class="mbd" id="vmMoveM"><div class="mbox"><div class="mhd"><div class="mhd-title"><i class="fas fa-folder-open"></i>نقل مسار الفيديو</div><button class="mclose" onclick="CM('vmMoveM')"><i class="fas fa-times"></i></button></div>
<div class="mbody">
  <div class="info-b" style="margin-top:0;margin-bottom:16px"><div class="info-b-title"><i class="fas fa-info-circle"></i> تنبيه هام</div><p style="font-size:.8rem;color:var(--t3)">أنت تقوم الآن بإعادة تعيين هذا الفيديو (التحكم بالملف في الخادم والواجهة الأمامية في نفس الوقت). نقل الملف لن يكسر الروابط!</p></div>
  <p id="vmMoveFile" style="font-size:.8rem;color:var(--t1);margin-bottom:16px;font-weight:bold"></p>
  <div class="fg">
    <label class="fl">إلى أين تريد نقله؟</label>
    <select class="fs" id="vmMoveTarget">
        <!-- ستتم تعبئة الخيارات من الـ JS بالأسفل -->
    </select>
  </div>
  <div id="vmMoveAlert"></div>
</div>
<div class="mfooter"><button class="btn btn-g" onclick="CM('vmMoveM')">إلغاء</button><button class="btn btn-p" onclick="vmDoMove()"><i class="fas fa-exchange-alt"></i>نقل الفيديو الآن</button></div></div></div>

<!-- TMDB INFO MODAL -->
<div class="mbd" id="tmdbInfoM" style="z-index: 2000;">
  <div class="mbox w">
    <div class="mhd">
      <div class="mhd-title"><i class="fas fa-info-circle" style="color:#4CC9F0"></i> تفاصيل العمل</div>
      <button class="mclose" onclick="CM('tmdbInfoM')"><i class="fas fa-times"></i></button>
    </div>
    <div class="mbody" id="tmdbInfoBody"></div>
  </div>
</div>


<!-- ADD USER MODAL -->
<div class="mbd" id="addUserM"><div class="mbox w"><div class="mhd"><div class="mhd-title"><i class="fas fa-user-plus"></i>مستخدم جديد</div><button class="mclose" onclick="CM('addUserM')"><i class="fas fa-times"></i></button></div>
<div class="mbody">
  <div class="row2">
    <div class="fg"><label class="fl">اسم المستخدم (للدخول)</label><input type="text" class="fi" id="auUsername" placeholder="username" style="direction:ltr"></div>
    <div class="fg"><label class="fl">الاسم المعروض</label><input type="text" class="fi" id="auDisplay" placeholder="أحمد محمد"></div>
  </div>
  <div class="row2">
    <div class="fg"><label class="fl">كلمة المرور</label><input type="password" class="fi" id="auPassword" placeholder="••••••••"></div>
    <div class="fg"><label class="fl">الدور / الصلاحية</label>
      <select class="fs" id="auRole" onchange="auRoleChange()">
        <option value="normal">عادي (رفع فقط)</option>
        <option value="custom">مخصص (اختر الأقسام)</option>
        <option value="super">مشرف (كل شيء عدا إدارة المدراء)</option>
        <option value="administrator">مدير عام (تحكم كامل)</option>
      </select>
    </div>
  </div>
  <div id="auPermsWrap" style="display:none">
    <label class="fl" style="margin-top:6px"><i class="fas fa-shield-alt" style="color:#00D084"></i> الأقسام المسموحة</label>
    <div class="perm-grid" id="auPermsGrid"></div>
  </div>
  <div id="auAlert" style="margin-top:12px"></div>
</div>
<div class="mfooter"><button class="btn btn-g" onclick="CM('addUserM')">إلغاء</button><button class="btn btn-p" onclick="addUser()"><i class="fas fa-check"></i>إنشاء المستخدم</button></div></div></div>

<!-- EDIT USER MODAL -->
<div class="mbd" id="editUserM"><div class="mbox w"><div class="mhd"><div class="mhd-title"><i class="fas fa-user-edit"></i>تعديل المستخدم</div><button class="mclose" onclick="CM('editUserM')"><i class="fas fa-times"></i></button></div>
<div class="mbody">
  <input type="hidden" id="euId">
  <div class="row2">
    <div class="fg"><label class="fl">اسم المستخدم</label><input type="text" class="fi" id="euUsername" disabled style="direction:ltr;opacity:.6"></div>
    <div class="fg"><label class="fl">الاسم المعروض</label><input type="text" class="fi" id="euDisplay"></div>
  </div>
  <div class="row2">
    <div class="fg"><label class="fl">كلمة مرور جديدة <small style="color:var(--t3)">(اتركها فارغة للإبقاء)</small></label><input type="password" class="fi" id="euPassword" placeholder="••••••••"></div>
    <div class="fg"><label class="fl">الدور / الصلاحية</label>
      <select class="fs" id="euRole" onchange="euRoleChange()">
        <option value="normal">عادي (رفع فقط)</option>
        <option value="custom">مخصص (اختر الأقسام)</option>
        <option value="super">مشرف</option>
        <option value="administrator">مدير عام</option>
      </select>
    </div>
  </div>
  <div class="fg">
    <label class="fl">الحالة</label>
    <select class="fs" id="euActive">
      <option value="1">نشط ✅</option>
      <option value="0">معطّل ⛔</option>
    </select>
  </div>
  <div id="euPermsWrap" style="display:none">
    <label class="fl" style="margin-top:6px"><i class="fas fa-shield-alt" style="color:#00D084"></i> الأقسام المسموحة</label>
    <div class="perm-grid" id="euPermsGrid"></div>
  </div>
  <div id="euAlert" style="margin-top:12px"></div>
</div>
<div class="mfooter"><button class="btn btn-g" onclick="CM('editUserM')">إلغاء</button><button class="btn btn-p" onclick="editUser()"><i class="fas fa-save"></i>حفظ التعديلات</button></div></div></div>

<!-- ══ THEME PANEL ══ -->
<button class="theme-fab" id="themeFabBtn" onclick="toggleThemePanel()" title="تغيير الثيم" style="z-index:500">
  <i class="fas fa-palette"></i>
</button>

<div class="theme-panel" id="themePanel">
  <div class="theme-panel-hd">
    <div class="theme-panel-title"><i class="fas fa-palette"></i> 🎨 مركز الثيمات</div>
    <button class="theme-panel-close" onclick="toggleThemePanel()"><i class="fas fa-times"></i></button>
  </div>
  <div class="theme-panel-body">

    <div class="theme-section-title">✨ الثيمات الجاهزة</div>
    <div class="theme-presets">

      <div class="theme-card" id="thc-default" onclick="applyThemePreset('default')">
        <div class="theme-card-preview" style="background:linear-gradient(135deg,#111,#1a1a1a);color:#E50914;border:1px solid rgba(229,9,20,.3)">SHASHITY</div>
        <div class="theme-card-name">الافتراضي</div>
        <div class="theme-card-desc">الثيم الأصلي للوحة</div>
      </div>

      <div class="theme-card" id="thc-ultrachromic" onclick="applyThemePreset('ultrachromic')">
        <div class="theme-card-preview" style="background:linear-gradient(135deg,#0d0221,#190c38);color:#b847ff;border:1px solid rgba(184,71,255,.4)">🌌 Ultra</div>
        <div class="theme-card-name">Ultrachromic</div>
        <div class="theme-card-desc">بنفسجي متدرج عصري</div>
      </div>

      <div class="theme-card" id="thc-jellyflix" onclick="applyThemePreset('jellyflix')">
        <div class="theme-card-preview" style="background:linear-gradient(135deg,#141414,#1c1c1c);color:#e50914;border:1px solid rgba(229,9,20,.5)">🎬 Flix</div>
        <div class="theme-card-name">JellyFlix</div>
        <div class="theme-card-desc">نمط Netflix احترافي</div>
      </div>

      <div class="theme-card" id="thc-dark" onclick="applyThemePreset('dark')">
        <div class="theme-card-preview" style="background:linear-gradient(135deg,#0d1117,#161b22);color:#58a6ff;border:1px solid rgba(88,166,255,.3)">🌑 Dark</div>
        <div class="theme-card-name">Dark Enhanced</div>
        <div class="theme-card-desc">داكن محسّن للعيون</div>
      </div>

      <div class="theme-card" id="thc-neon" onclick="applyThemePreset('neon')">
        <div class="theme-card-preview" style="background:linear-gradient(135deg,#0a0a1a,#12122a);color:#00ff88;border:1px solid rgba(0,255,136,.4)">🎮 Neon</div>
        <div class="theme-card-name">Neon Cyberpunk</div>
        <div class="theme-card-desc">جيمينج نيون ملوّن</div>
      </div>

      <div class="theme-card" id="thc-minimal" onclick="applyThemePreset('minimal')">
        <div class="theme-card-preview" style="background:linear-gradient(135deg,#fafafa,#f0f0f0);color:#222;border:1px solid rgba(0,0,0,.1)">🧊 Min</div>
        <div class="theme-card-name">Minimal Clean</div>
        <div class="theme-card-desc">بسيط صافي فاتح</div>
      </div>

      <div class="theme-card" id="thc-midnight" onclick="applyThemePreset('midnight')">
        <div class="theme-card-preview" style="background:linear-gradient(135deg,#0b1437,#1a2456);color:#7aa2ff;border:1px solid rgba(122,162,255,.4)">🌃 Midnight</div>
        <div class="theme-card-name">Midnight Blue</div>
        <div class="theme-card-desc">أزرق ليلي ملكي فاخر</div>
      </div>

      <div class="theme-card" id="thc-emerald" onclick="applyThemePreset('emerald')">
        <div class="theme-card-preview" style="background:linear-gradient(135deg,#04130d,#0a2a1c);color:#10d98a;border:1px solid rgba(16,217,138,.4)">💎 Emerald</div>
        <div class="theme-card-name">Emerald Luxe</div>
        <div class="theme-card-desc">زمردي أنيق ودافئ</div>
      </div>

      <div class="theme-card" id="thc-sunset" onclick="applyThemePreset('sunset')">
        <div class="theme-card-preview" style="background:linear-gradient(135deg,#1a0a14,#2e0f1f);color:#ff7849;border:1px solid rgba(255,120,73,.4)">🌅 Sunset</div>
        <div class="theme-card-name">Sunset Glow</div>
        <div class="theme-card-desc">غروب برتقالي وردي</div>
      </div>

      <div class="theme-card" id="thc-royalgold" onclick="applyThemePreset('royalgold')">
        <div class="theme-card-preview" style="background:linear-gradient(135deg,#0f0c00,#1f1a05);color:#f5c542;border:1px solid rgba(245,197,66,.45)">👑 Gold</div>
        <div class="theme-card-name">Royal Gold</div>
        <div class="theme-card-desc">ذهبي ملكي على أسود</div>
      </div>

      <div class="theme-card" id="thc-crimson" onclick="applyThemePreset('crimson')">
        <div class="theme-card-preview" style="background:linear-gradient(135deg,#16050a,#2c0a12);color:#ff3355;border:1px solid rgba(255,51,85,.45)">🔥 Crimson</div>
        <div class="theme-card-name">Crimson Noir</div>
        <div class="theme-card-desc">أحمر قرمزي درامي</div>
      </div>

      <div class="theme-card" id="thc-arctic" onclick="applyThemePreset('arctic')">
        <div class="theme-card-preview" style="background:linear-gradient(135deg,#eef4f8,#dde8f0);color:#0e7490;border:1px solid rgba(14,116,144,.25)">❄️ Arctic</div>
        <div class="theme-card-name">Arctic Frost</div>
        <div class="theme-card-desc">فاتح بارد أنيق</div>
      </div>

      <div class="theme-card" id="thc-win11" onclick="applyThemePreset('win11')">
        <div class="theme-card-preview" style="background:linear-gradient(135deg,#2b2b2b,#3a4358);color:#4cc2ff;border:1px solid rgba(76,194,255,.35)">🪟 Win 11</div>
        <div class="theme-card-name">Windows 11</div>
        <div class="theme-card-desc">Mica زجاجي رمادي</div>
      </div>

      <div class="theme-card" id="thc-sierra" onclick="applyThemePreset('sierra')">
        <div class="theme-card-preview" style="background:linear-gradient(160deg,#6ea8dc,#b79ccb 55%,#f3c39b);color:#fff;border:1px solid rgba(255,255,255,.5);text-shadow:0 1px 4px rgba(0,0,0,.3)">🍎 Sierra</div>
        <div class="theme-card-name">macOS Sierra</div>
        <div class="theme-card-desc">فاتح شفّاف أنيق</div>
      </div>

      <div class="theme-card" id="thc-ps5" onclick="applyThemePreset('ps5')">
        <div class="theme-card-preview" style="background:linear-gradient(135deg,#0a0e17,#1b2b52);color:#2e6ff2;border:1px solid rgba(46,111,242,.45);box-shadow:inset 0 0 18px rgba(46,111,242,.25)">🎮 PS5</div>
        <div class="theme-card-name">PlayStation 5</div>
        <div class="theme-card-desc">أزرق متوهّج حاد</div>
      </div>

      <div class="theme-card" id="thc-glass" onclick="applyThemePreset('glass')">
        <div class="theme-card-preview" style="background:linear-gradient(135deg,#0d121d,#16203a);color:#6ea8fe;border:1px solid rgba(110,168,254,.35);box-shadow:inset 0 1px 0 rgba(255,255,255,.08)">🧊 Glass</div>
        <div class="theme-card-name">Glass Blur</div>
        <div class="theme-card-desc">زجاجي شفاف واضح</div>
      </div>

    </div>

    <div class="theme-section-title" style="margin-top:16px">🖌️ CSS مخصص</div>
    <div class="custom-css-wrap">
      <textarea class="custom-css-textarea" id="customCssInput" placeholder=":root {
  --red: #E50914;
  --s0: #0a0a0a;
  --s1: #111;
}

/* أضف CSS هنا */
.sidebar { /* تعديل الشريط الجانبي */ }"></textarea>
    </div>
    <div id="cssApplyStatus" style="margin-top:8px;font-size:.75rem"></div>

  </div>
  <div class="theme-panel-footer">
    <button class="theme-reset-btn" onclick="resetTheme()" style="flex:1;justify-content:center"><i class="fas fa-undo"></i> إعادة الضبط</button>
    <button id="applyThemeBtn" class="btn btn-p" onclick="applyCSSFromTextarea()" style="flex:2;justify-content:center;padding:9px"><i class="fas fa-check"></i> تطبيق و حفظ</button>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/hls.js@latest">
// إغلاق قائمة اللغات عند النقر بالخارج
document.addEventListener('click', function() {
    var drop = document.getElementById('langDrop');
    if(drop && drop.classList.contains('op')) { drop.classList.remove('op'); }
    var psw = document.getElementById('profSw');
    if(psw && psw.classList.contains('op')) { psw.classList.remove('op'); }
});
</script>
<script>
const _allFoldersGlobal = <?php echo json_encode($all_folders_list ?? []); ?>;

function initFolderSelects() {
    let opts = '<option value="0" style="color:#00D084;font-weight:bold;">✨ + إنشاء (عمل / مجلد) جديد ومستقل</option>';
    if (typeof _allFoldersGlobal !== 'undefined') {
        _allFoldersGlobal.forEach(f => {
            opts += `<option value="${f.id}">📂 حفظ بداخل مجلد: ${esc(f.name)}</option>`;
        });
    }
    if($('vTargetSeries')) $('vTargetSeries').innerHTML = opts;
    if($('vmSaveTargetSeries')) $('vmSaveTargetSeries').innerHTML = opts;
}

document.addEventListener("DOMContentLoaded", initFolderSelects);

function vToggleSeriesFields(val, context) {
    let nameLblId = (context === 'upload') ? 'vNameLabel' : 'vmNameLabel';
    let catDivId =  (context === 'upload') ? 'vCatDiv'    : 'vmCatDiv';
    
    if (val == "0") {
        $(nameLblId).innerHTML = "اسم العمل/المجلد الجديد <small style='color:#00D084'>(مطلوب)</small>";
        $(catDivId).style.display = 'block';
    } else {
        $(nameLblId).innerHTML = "عنوان الحلقة أو الفيديو (سيوضع بداخل المجلد المختار) <small style='color:var(--t3)'>(اختياري/يمكنك تعديله)</small>";
        $(catDivId).style.display = 'none'; 
    }
}

const $=id=>document.getElementById(id);

/* ══ تبديل الوضع الليلي / النهاري (إضافة) ══ */
function applyDayNight(mode){
  const isLight = mode === 'light';
  document.documentElement.classList.toggle('light-mode', isLight);
  const ic = document.getElementById('modeIcon');
  if(ic){ ic.className = isLight ? 'fas fa-sun' : 'fas fa-moon'; }
}
function toggleDayNight(){
  const next = document.documentElement.classList.contains('light-mode') ? 'dark' : 'light';
  try{ localStorage.setItem('shashety_mode', next); }catch(e){}
  applyDayNight(next);
  if(window.toast) toast(next==='light' ? 'الوضع النهاري ☀️' : 'الوضع الليلي 🌙','i');
}
// تطبيق الوضع المحفوظ فوراً عند تحميل الصفحة
(function(){
  let saved='dark';
  try{ saved = localStorage.getItem('shashety_mode') || 'dark'; }catch(e){}
  applyDayNight(saved);
})();
function api(data){const fd=new FormData();for(const[k,v]of Object.entries(data))fd.append(k,String(v??''));return fetch(location.href,{method:'POST',body:fd}).then(r=>r.json()).catch(()=>({success:false,error:'خطأ في الاتصال'}));}

/* ════════════════════════════════════════════════════════════════════
   ⚡ مُسرّع الطلبات + تحسين تجربة المستخدم  (إضافة — لا يُحذف شيء أصلي)
   - كاش ذكي للطلبات القرائية (load/list/get/fetch/status)
   - إلغاء تكرار الطلبات المتزامنة المتطابقة (deduplication)
   - إعادة محاولة تلقائية عند فشل الشبكة (محاولتان)
   - شريط تقدّم علوي خفيف + نظام Toast للإشعارات
   ──────────────────────────────────────────────────────────────────── */
(function(){
  const _origApi = api;                 // الاحتفاظ بالدالة الأصلية كما هي
  const _cache = new Map();             // كاش النتائج القرائية
  const _inflight = new Map();          // الطلبات الجارية حالياً (لمنع التكرار)
  const CACHE_TTL = 8000;               // مدة صلاحية الكاش بالملّي ثانية
  let _activeReqs = 0;

  // الإجراءات القرائية الآمنة للتخزين المؤقت (لا تغيّر بيانات)
  const READ_ONLY = /^(load|list|get|fetch|status|search|count|stats|info|check|tailscale_command)/i;
  // كلمات تدل على إجراء يكتب بيانات → نُبطل الكاش المرتبط بها بعده
  const WRITES = /(add|edit|update|delete|del_|remove|save|set_|create|upload|reorder|toggle|import|restore|merge)/i;

  function _key(d){try{return JSON.stringify(d);}catch(e){return String(Math.random());}}

  // شريط تقدّم علوي خفيف
  function _bar(show){
    let b=document.getElementById('_apiProgBar');
    if(!b){
      b=document.createElement('div');b.id='_apiProgBar';
      b.style.cssText='position:fixed;top:0;left:0;height:3px;width:0;z-index:99999;'+
        'background:linear-gradient(90deg,var(--gold,#e6b800),#00D084);'+
        'box-shadow:0 0 10px rgba(0,208,132,.6);transition:width .25s ease,opacity .4s ease;opacity:0;';
      document.body.appendChild(b);
    }
    if(show){_activeReqs++;b.style.opacity='1';b.style.width=Math.min(85,15+_activeReqs*20)+'%';}
    else{_activeReqs=Math.max(0,_activeReqs-1);
      if(_activeReqs===0){b.style.width='100%';setTimeout(()=>{b.style.opacity='0';b.style.width='0';},300);}}
  }

  // الدالة المحسّنة — تستدعي الأصلية داخلياً
  function fastApi(data, opts){
    opts=opts||{};
    const act=(data&&data.ajax_action)||'';
    const k=_key(data);
    const isRead = !opts.noCache && READ_ONLY.test(act);

    // 1) كاش
    if(isRead && _cache.has(k)){
      const c=_cache.get(k);
      if(Date.now()-c.t < CACHE_TTL) return Promise.resolve(c.v);
      _cache.delete(k);
    }
    // 2) منع تكرار الطلب المتزامن نفسه
    if(_inflight.has(k)) return _inflight.get(k);

    _bar(true);
    const run=(tries)=>_origApi(data).then(res=>{
      // إعادة محاولة عند فشل اتصال الشبكة فقط
      if(res&&res.success===false&&/اتصال|connection|network/i.test(res.error||'')&&tries>0){
        return new Promise(r=>setTimeout(r,400)).then(()=>run(tries-1));
      }
      return res;
    });

    const p=run(2).then(res=>{
      _bar(false);_inflight.delete(k);
      if(isRead && res && res.success!==false) _cache.set(k,{v:res,t:Date.now()});
      // أي عملية كتابة تُبطل الكاش القرائي كله لضمان طزاجة البيانات
      if(WRITES.test(act)) _cache.clear();
      return res;
    }).catch(e=>{_bar(false);_inflight.delete(k);return{success:false,error:'خطأ في الاتصال'};});

    _inflight.set(k,p);
    return p;
  }

  // استبدال المرجع العام دون المساس بالدالة الأصلية المعرّفة أعلاه
  window.api = fastApi;
  try{ api = fastApi; }catch(e){}

  // ── نظام Toast لإشعارات سريعة وأنيقة ──
  window.toast=function(msg,type){
    let host=document.getElementById('_toastHost');
    if(!host){
      host=document.createElement('div');host.id='_toastHost';
      host.style.cssText='position:fixed;bottom:20px;left:50%;transform:translateX(-50%);'+
        'z-index:99999;display:flex;flex-direction:column;gap:8px;align-items:center;pointer-events:none;';
      document.body.appendChild(host);
    }
    const colors={s:'#00D084',e:'#ff5252',i:'#3aa0ff',w:'#e6b800'};
    const icons={s:'check-circle',e:'exclamation-circle',i:'info-circle',w:'exclamation-triangle'};
    const t=document.createElement('div');
    t.style.cssText='background:rgba(20,22,28,.96);color:#fff;padding:11px 18px;border-radius:12px;'+
      'font-size:.86rem;font-weight:600;box-shadow:0 8px 28px rgba(0,0,0,.45);'+
      'border:1px solid '+(colors[type]||colors.i)+';display:flex;align-items:center;gap:9px;'+
      'opacity:0;transform:translateY(12px);transition:.3s;max-width:90vw;direction:rtl;';
    t.innerHTML='<i class="fas fa-'+(icons[type]||icons.i)+'" style="color:'+(colors[type]||colors.i)+'"></i>'+msg;
    host.appendChild(t);
    requestAnimationFrame(()=>{t.style.opacity='1';t.style.transform='translateY(0)';});
    setTimeout(()=>{t.style.opacity='0';t.style.transform='translateY(12px)';setTimeout(()=>t.remove(),320);},3200);
  };

  // أداة مساعدة: إبطال الكاش يدوياً عند الحاجة
  window.apiClearCache=function(){_cache.clear();};
})();

function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;')}
function escA(s){return String(s==null?'':s).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'\\n').replace(/\r/g,'\\r')}
function fmtSz(b){if(b>=1073741824)return(b/1073741824).toFixed(1)+' GB';if(b>=1048576)return(b/1048576).toFixed(1)+' MB';if(b>=1024)return(b/1024).toFixed(0)+' KB';return b+' B'}
function al(id,msg,type){const icons={s:'check-circle',e:'exclamation-circle',i:'info-circle'};const cls={s:'al-s',e:'al-e',i:'al-i'};const el=$(id);if(!el)return;if(!msg){el.innerHTML='';return;}el.innerHTML=`<div class="al ${cls[type]||'al-i'}" style="margin:0"><i class="fas fa-${icons[type]||'info-circle'}"></i> ${msg}</div>`;}
const titles={dashboard:'<?= $t["dashboard"] ?? "لوحة التحكم" ?>',categories:'<?= $t["categories"] ?? "الأقسام" ?>',channels:'<?= $t["channels"] ?? "القنوات" ?>','m3u-import':'استيراد M3U',xtream:'حساب Xtream',series:'<?= $t["series"] ?? "شاشتي" ?>',vupload:'<?= $t["upload"] ?? "رفع الأفلام" ?>',vmanage:'<?= $t["manage"] ?? "إدارة الفيديوهات" ?>','api-settings':'<?= $t["api_settings"] ?? "إعدادات API" ?>','site-settings':'<?= $t["settings"] ?? "إعدادات الموقع" ?>','change-password':'<?= $t["password"] ?? "كلمة المرور" ?>','system-tools':'<?= $t["tools"] ?? "صيانة النظام" ?>',backup:'<?= $t["backup"] ?? "النسخ الاحتياطي" ?>',users:'<?= $t["users"] ?? "إدارة المستخدمين" ?>','frontend-control':'التحكم بالواجهة الأمامية','general-settings':'الإعدادات العامة'};
function S(id){document.querySelectorAll('.sec').forEach(s=>{s.classList.remove('on')});document.querySelectorAll('.si').forEach(s=>{s.classList.remove('on')});const sec=$(id);if(sec)sec.classList.add('on');$('tbTitle').textContent=titles[id]||'';document.querySelectorAll('.si').forEach(b=>{if(b.getAttribute('onclick')&&b.getAttribute('onclick').includes(`'${id}'`))b.classList.add('on')});
sessionStorage.setItem('active_sec', id);
if (id === 'channels') { if (typeof chLoad === 'function') chLoad(); }
}

/* ══ التحكم بالواجهة الأمامية (إضافة) ══ */
const _FC_INITIAL = {
  hide_search:        '<?= ($settings['hide_search']        ?? '0') ?>',
  hide_notifications: '<?= ($settings['hide_notifications'] ?? '0') ?>',
  hide_favorites:     '<?= ($settings['hide_favorites']     ?? '0') ?>',
  hide_music:         '<?= ($settings['hide_music']         ?? '0') ?>',
  hide_admin_btn:     '<?= ($settings['hide_admin_btn']     ?? '0') ?>',
  hide_social:        '<?= ($settings['hide_social']        ?? '0') ?>',
  hide_download:      '<?= ($settings['hide_download']      ?? '0') ?>',
  hide_cast:          '<?= ($settings['hide_cast']          ?? '0') ?>',
  hide_most_watched:  '<?= ($settings['hide_most_watched']  ?? '0') ?>',
  hide_suggestions:   '<?= ($settings['hide_suggestions']   ?? '0') ?>',
  hide_screensaver:   '<?= ($settings['hide_screensaver']   ?? '0') ?>'
};
function loadFrontendToggles(){
  document.querySelectorAll('#frontend-control input[data-key]').forEach(inp=>{
    const k = inp.getAttribute('data-key');
    inp.checked = (_FC_INITIAL[k] === '1');
  });
  al('fcAlert','',null);
}
function saveFrontendToggles(){
  const data = { ajax_action:'save_frontend_toggles' };
  document.querySelectorAll('#frontend-control input[data-key]').forEach(inp=>{
    data[inp.getAttribute('data-key')] = inp.checked ? '1' : '0';
  });
  al('fcAlert','<span class="sp"></span> جارٍ الحفظ…','i');
  api(data, {noCache:true}).then(d=>{
    if(d.success){
      // تحديث الحالة الأولية محلياً حتى يبقى "استرجاع المحفوظ" متسقاً
      Object.keys(_FC_INITIAL).forEach(k=>{ if(k in data) _FC_INITIAL[k]=data[k]; });
      al('fcAlert','✅ '+(d.message||'تم الحفظ بنجاح')+' — حدّث صفحة الموقع لرؤية التغييرات','s');
      if(window.toast) toast('تم حفظ إعدادات الواجهة','s');
    } else {
      al('fcAlert','❌ '+(d.error||'تعذّر الحفظ'),'e');
    }
  });
}
/* ═══════════ الإعدادات العامة الحساسة (general-settings) ═══════════ */
// جلب الإعدادات المحفوظة من قاعدة البيانات وتعبئة الحقول
// يجلب حقول الإعدادات العامة الأصلية فقط (يستثني حقول المجموعات داخل .gs-acc)
function gsGeneralInputs(){
  return Array.prototype.filter.call(
    document.querySelectorAll('#general-settings [data-key]'),
    function(inp){ return !inp.closest('.gs-acc'); }
  );
}
function loadGeneralSettings(){
  al('gsAlert','<span class="sp"></span> جارٍ تحميل الإعدادات…','i');
  api({ ajax_action:'get_general_settings' }).then(d=>{
    if(!d.success){ al('gsAlert','❌ '+(d.error||'تعذّر التحميل'),'e'); return; }
    const s = d.settings || {};
    gsGeneralInputs().forEach(inp=>{
      const k = inp.getAttribute('data-key');
      const type = inp.getAttribute('data-type');
      const val = (k in s) ? s[k] : '';
      if(type === 'bool'){
        inp.checked = (val === '1');
      } else {
        inp.value = val ?? '';
        // مزامنة منتقي اللون مع الحقل النصي
        if(k === 'theme_color' && val){ try{ document.getElementById('gs_theme_color_pick').value = val; }catch(e){} }
      }
    });
    al('gsAlert','',null);
  });
}

// استرجاع كل القيم الأصلية (يعيد التعيين للوضع الافتراضي)
function restoreDefaultSettings(){
  if(!confirm('هل أنت متأكد من استرجاع جميع قيم الإعدادات للوضع الافتراضي؟ هذا الإجراء سيحذف كل تعديلاتك على إعدادات الواجهة والمشغّل.')) return;
  api({ajax_action:'restore_default_settings'}).then(d=>{
    if(d.success){
      alert(d.message || 'تم الاسترجاع بنجاح');
      location.reload();
    } else {
      alert(d.message || 'حدث خطأ أثناء الاسترجاع');
    }
  });
}
// حفظ كل الإعدادات العامة دفعة واحدة (لا يشمل المجموعات؛ لكل مجموعة زرها)
function saveGeneralSettings(){
  const data = { ajax_action:'save_general_settings' };
  gsGeneralInputs().forEach(inp=>{
    const k = inp.getAttribute('data-key');
    const type = inp.getAttribute('data-type');
    data[k] = (type === 'bool') ? (inp.checked ? '1' : '0') : (inp.value ?? '');
  });
  al('gsAlert','<span class="sp"></span> جارٍ حفظ الإعدادات…','i');
  api(data).then(d=>{
    if(d.success){
      al('gsAlert','✅ '+(d.message||'تم الحفظ')+' — حدّث صفحة الموقع لرؤية التغييرات','s');
      if(window.toast) toast('تم حفظ الإعدادات العامة','s');
    } else {
      al('gsAlert','❌ '+(d.error||'تعذّر الحفظ'),'e');
    }
  });
}


/* ═══════════ مجموعات الإعدادات المتقدمة (كل مجموعة زر خاص) ═══════════ */
// القيم الافتراضية الأصلية لكل مفتاح (تُعرض كـ placeholder عند عدم وجود قيمة محفوظة)
const GS_DEFAULTS = {"srv_hls_segment_duration": "6", "srv_playlist_length": "5", "srv_llhls_enable": "0", "srv_ffmpeg_params": "", "srv_hwaccel": "none", "srv_thread_count": "0", "srv_tcp_udp_buffer": "8192", "srv_socket_buffer": "65536", "srv_cdn_failover": "0", "srv_stream_priority": "normal", "srv_health_check_interval": "30", "srv_auto_restart_stream": "1", "srv_stream_timeout": "20", "srv_packet_loss_recovery": "1", "srv_jitter_buffer": "500", "srv_abr_enable": "1", "srv_max_bitrate": "8000", "srv_min_bitrate": "800", "srv_gop_size": "48", "srv_keyframe_interval": "2", "ui_theme": "dark", "theme_color": "#e50914", "ui_font": "Tajawal", "ui_font_size": "16", "ui_transitions": "1", "ui_banner": "", "ui_icon_style": "solid", "img_default_channel": "", "img_default_movie": "", "img_default_series": "", "img_quality": "85", "img_compression": "1", "usr_save_last_watch": "1", "usr_autoplay": "1", "usr_dark_mode": "1", "usr_language": "ar", "usr_notifications": "1", "usr_favorites": "1", "usr_watch_history": "1", "perf_cache_duration": "3600", "perf_image_cache": "1", "perf_api_cache": "1", "perf_gzip_brotli": "1", "perf_lazy_loading": "1", "perf_http_version": "2", "perf_prefetch": "1", "perf_preconnect": "1", "sub_default_language": "ar", "sub_font_size": "18", "sub_font_color": "#ffffff", "sub_bg_color": "#000000", "sub_position": "bottom", "sub_bg_opacity": "60", "sr_resume_last_ep": "1", "sr_auto_next_ep": "1", "sr_skip_intro": "0", "sr_skip_outro": "0", "sr_season_order": "asc", "mv_per_page": "24", "mv_default_quality": "auto", "mv_auto_subtitle": "0", "mv_subtitle_language": "ar", "mv_play_trailer": "1", "mv_show_similar": "1", "mv_resume_watch": "1", "ch_per_page": "40", "ch_order": "display_order", "ch_group_order": "display_order", "ch_hide_offline": "0", "ch_auto_status": "0", "ch_check_interval": "60", "ch_resume_last": "1", "pl_autoplay": "1", "pl_mute_on_start": "0", "pl_auto_fullscreen": "0", "pl_pip": "1", "pl_webcast": "1", "pl_seek_buttons": "1", "pl_playback_speed": "1", "pl_thumbnails": "1", "pl_show_channel_logo": "1", "pl_show_channel_name": "1", "pl_show_clock": "0", "pl_show_viewers": "0", "pl_show_share": "1", "pl_show_report": "1", "st_low_latency": "0", "st_buffer_size": "30", "st_startup_buffer": "2", "st_max_buffer": "60", "st_back_buffer": "90", "st_live_sync": "3", "st_auto_quality": "1", "st_default_quality": "auto", "st_allow_quality_change": "1", "st_auto_reconnect": "1", "st_reconnect_attempts": "5", "st_reconnect_timeout": "3", "st_failover": "1", "st_protocol": "hls", "st_llhls_support": "0", "st_playlist_refresh": "6", "st_stream_cache": "1"};

// تحميل قيم مجموعة معيّنة من قاعدة البيانات وتعبئة حقولها
function loadGroupSettings(group){
  const scope = document.querySelector('.gs-acc[data-group="'+group+'"]') || document;
  const alId = 'ga_' + group;
  al(alId,'<span class="sp"></span> جارٍ التحميل…','i');
  api({ ajax_action:'get_general_settings' }).then(d=>{
    if(!d.success){ al(alId,'❌ '+(d.error||'تعذّر التحميل'),'e'); return; }
    const s = d.settings || {};
    scope.querySelectorAll('[data-key]').forEach(inp=>{
      const k = inp.getAttribute('data-key');
      const type = inp.getAttribute('data-type');
      const has = (k in s) && s[k] !== null && s[k] !== '';
      const val = has ? s[k] : (GS_DEFAULTS[k] ?? '');
      if(type === 'bool'){
        // للمفاتيح المنطقية: إن لم تُحفظ بعد نستخدم الافتراضي الأصلي
        inp.checked = has ? (s[k] === '1') : ((GS_DEFAULTS[k] ?? '0') === '1');
      } else if(inp.tagName === 'SELECT'){
        inp.value = val;
      } else {
        // للحقول النصية/الرقمية: نملأ القيمة المحفوظة فقط، ويبقى الافتراضي كـ placeholder
        inp.value = has ? s[k] : '';
        // مزامنة منتقي الألوان إن وُجد
        const pick = document.getElementById(inp.id + '_pick');
        if(pick && val){ try{ pick.value = val; }catch(e){} }
      }
    });
    al(alId,'',null);
  });
}

// حفظ مجموعة معيّنة (يرسل كل مفاتيح المجموعة)
function saveGroupSettings(group){
  const scope = document.querySelector('.gs-acc[data-group="'+group+'"]') || document;
  const alId = 'ga_' + group;
  const data = { ajax_action:'save_settings_group', group:group };
  scope.querySelectorAll('[data-key]').forEach(inp=>{
    const k = inp.getAttribute('data-key');
    const type = inp.getAttribute('data-type');
    if(type === 'bool'){
      data[k] = inp.checked ? '1' : '0';
    } else {
      // إن ترك المستخدم الحقل فارغاً، نحفظ القيمة الأصلية الافتراضية
      let v = (inp.value ?? '').trim();
      if(v === '' && (GS_DEFAULTS[k] !== undefined)) v = GS_DEFAULTS[k];
      data[k] = v;
    }
  });
  al(alId,'<span class="sp"></span> جارٍ الحفظ…','i');
  api(data).then(d=>{
    if(d.success){
      al(alId,'✅ '+(d.message||'تم الحفظ')+' — حدّث صفحة الموقع لرؤية التغييرات','s');
      if(window.toast) toast('تم حفظ إعدادات المجموعة','s');
    } else {
      al(alId,'❌ '+(d.error||'تعذّر الحفظ'),'e');
    }
  });
}

// طي/فتح بطاقة مجموعة داخل الإعدادات العامة
function gsToggleAcc(btn){
  var body = btn.nextElementSibling;
  var arrow = btn.querySelector('.gs-acc-arrow');
  var open = body.style.display !== 'none';
  if(open){
    body.style.display = 'none';
    if(arrow) arrow.style.transform = '';
  } else {
    body.style.display = 'block';
    if(arrow) arrow.style.transform = 'rotate(180deg)';
    // تحميل قيم المجموعة عند أول فتح
    var acc = btn.closest('.gs-acc');
    if(acc && !acc.dataset.loaded){
      acc.dataset.loaded = '1';
      var g = acc.getAttribute('data-group');
      if(g && window.loadGroupSettings) loadGroupSettings(g);
    }
  }
}

function toggleCategoryActive(checkbox){
  const cid = checkbox.getAttribute('data-cat-id');
  const newState = checkbox.checked ? '1' : '0';
  checkbox.disabled = true;
  api({ ajax_action:'toggle_category_active', category_id:cid, is_active:newState }).then(d=>{
    checkbox.disabled = false;
    if(d.success){
      if(window.toast) toast(d.message||'تم التحديث','s');
    } else {
      checkbox.checked = !checkbox.checked; // تراجع عند الفشل
      if(window.toast) toast(d.error||'تعذّر تحديث حالة القسم','e');
      else alert(d.error||'تعذّر تحديث حالة القسم');
    }
  }).catch(()=>{
    checkbox.disabled = false;
    checkbox.checked = !checkbox.checked;
    if(window.toast) toast('خطأ في الاتصال','e');
  });
}
function OM(id){const m=$(id);if(m){m.classList.add('op');document.body.style.overflow='hidden'}}
function CM(id){const m=$(id);if(m){m.classList.remove('op');document.body.style.overflow=''}}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){document.querySelectorAll('.mbd.op').forEach(m=>{m.classList.remove('op')});document.body.style.overflow='';closePlayer()}});
document.querySelectorAll('.mbd').forEach(m=>m.addEventListener('click',e=>{if(e.target===m){m.classList.remove('op');document.body.style.overflow=''}}));
function FT(inp,tblId){const q=inp.value.toLowerCase();document.querySelectorAll('#'+tblId+' tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none';});}

let _allChannels = [];
let _filteredChannels = [];
let _chCurrentPage = 1;
const _chPerPage = 100;
let _chLoaded = false;

function chLoad(){
    if(_chLoaded) {
        $('chTbl').style.display='table';
        if(_filteredChannels.length === 0) {
            $('chTbl').style.display='none';
            $('chEmpty').style.display='block';
            $('chPagination').style.display='none';
        } else {
            $('chEmpty').style.display='none';
            $('chPagination').style.display='flex';
        }
        return;
    }
    $('chLoading').style.display='block';
    $('chTbl').style.display='none';
    $('chPagination').style.display='none';
    $('chEmpty').style.display='none';
    
    api({ajax_action:'get_channels'}).then(d=>{
        $('chLoading').style.display='none';
        if(d.success){
            _allChannels = d.channels || [];
            _filteredChannels = [..._allChannels];
            _chLoaded = true;
            $('chTotalCount').textContent = _allChannels.length + ' قناة';
            chRender(1);
        } else {
            al('alContainer', d.error || 'فشل جلب القنوات', 'e');
        }
    }).catch(e=>{
        $('chLoading').style.display='none';
        al('alContainer', 'خطأ في الاتصال', 'e');
    });
}

function chSearch(q){
    q = q.toLowerCase().trim();
    if(!q){
        _filteredChannels = [..._allChannels];
    } else {
        _filteredChannels = _allChannels.filter(c => {
            return (c.name && c.name.toLowerCase().includes(q)) || 
                   (c.cat_name && c.cat_name.toLowerCase().includes(q));
        });
    }
    chRender(1);
}

function chChangePage(dir){
    const maxPage = Math.ceil(_filteredChannels.length / _chPerPage) || 1;
    let newPage = _chCurrentPage + dir;
    if(newPage < 1) newPage = 1;
    if(newPage > maxPage) newPage = maxPage;
    chRender(newPage);
}

function escQ(str) { return (str||'').replace(/'/g, "\\'").replace(/"/g, '&quot;'); }

function chRender(page){
    _chCurrentPage = page;
    const maxPage = Math.ceil(_filteredChannels.length / _chPerPage) || 1;
    if(_chCurrentPage > maxPage) _chCurrentPage = maxPage;
    
    const tbody = $('chTblBody');
    tbody.innerHTML = '';
    
    if(_filteredChannels.length === 0){
        $('chTbl').style.display='none';
        $('chEmpty').style.display='block';
        $('chPagination').style.display='none';
        return;
    }
    
    $('chTbl').style.display='table';
    $('chEmpty').style.display='none';
    $('chPagination').style.display='flex';
    
    const start = (_chCurrentPage - 1) * _chPerPage;
    const end = Math.min(start + _chPerPage, _filteredChannels.length);
    
    let html = '';
    for(let i=start; i<end; i++){
        const ch = _filteredChannels[i];
        
        let logoHtml = ch.logo_url 
            ? `<img src="${encodeURI(ch.logo_url)}" style="width:34px;height:34px;object-fit:cover;border-radius:7px" onerror="this.style.display='none'">`
            : `<div class="nic"><i class="${ch.logo_icon || 'fas fa-tv'}"></i></div>`;
            
        let backupHtml = ch.backup_url ? `<span class="bdg bg"><i class="fas fa-link"></i> متوفر</span>` : `<span style="color:var(--t3);font-size:.75rem">—</span>`;
        let subHtml = ch.subtitle_url ? `<span class="bdg bg"><i class="fas fa-closed-captioning"></i> نعم</span>` : `<span style="color:var(--t3);font-size:.75rem">—</span>`;
        let activeChecked = parseInt(ch.is_active) === 1 ? 'checked' : '';
        let quality = ch.quality || 'HD 720';
        let views = ch.views_count || 0;
        
        let editData = JSON.stringify({
            id: ch.id,
            category_id: ch.category_id,
            name: ch.name,
            stream_url: ch.stream_url,
            logo_icon: ch.logo_icon,
            logo_url: ch.logo_url,
            backup_url: ch.backup_url || '',
            quality: quality,
            is_active: parseInt(ch.is_active || 1)
        }).replace(/"/g, '&quot;');
        
        html += `<tr>
            <td><input type="checkbox" class="chSelChk" value="${ch.id}" onchange="chSelCtrl()" style="width:16px;height:16px;cursor:pointer;accent-color:var(--red)"></td>
            <td style="color:var(--t3);font-size:.75rem">#${ch.id}</td>
            <td><div class="cn">${logoHtml}<strong style="color:var(--t1)">${ch.name}</strong></div></td>
            <td><span class="bdg bc">${ch.cat_name || ''}</span></td>
            <td><span class="bdg bp">${quality}</span></td>
            <td>${backupHtml}</td>
            <td>${subHtml}</td>
            <td><span style="font-size:.75rem;color:var(--t3)"><i class="fas fa-eye"></i> ${views}</span></td>
            <td><label class="fc-switch" style="display:inline-flex"><input type="checkbox" data-ch-id="${ch.id}" class="chActiveToggle" ${activeChecked} onchange="toggleChannelActive(this)"><span class="fc-slider"></span></label></td>
            <td>
                <div class="acts">
                    <button class="ib pl" onclick="testChannel('${escQ(ch.stream_url)}','${escQ(ch.name)}','${escQ(ch.subtitle_url)}','${escQ(ch.backup_url)}')"><i class="fas fa-play"></i></button>
                    <button class="ib ed" onclick="editCh(${editData})"><i class="fas fa-pen"></i></button>
                    <button class="ib dl" onclick="if(confirm('حذف القناة؟'))location.href='?delete_channel=${ch.id}'"><i class="fas fa-trash"></i></button>
                </div>
            </td>
        </tr>`;
    }
    tbody.innerHTML = html;
    
    $('chPageInfo').textContent = `صفحة ${_chCurrentPage} من ${maxPage}`;
    $('chPrevBtn').disabled = _chCurrentPage === 1;
    $('chNextBtn').disabled = _chCurrentPage === maxPage;
}
function addRipple(e,btn){const r=document.createElement('span');r.className='si-ripple';const rect=btn.getBoundingClientRect();const sz=Math.max(btn.clientWidth,btn.clientHeight);r.style.cssText=`width:${sz}px;height:${sz}px;left:${e.clientX-rect.left-sz/2}px;top:${e.clientY-rect.top-sz/2}px`;btn.appendChild(r);setTimeout(()=>r.remove(),600);}
function toggleSidebar(){const sb=$('sidebar'),ov=$('sbOverlay'),hb=$('hamburgerBtn');const isOpen=sb.classList.contains('open');if(isOpen){closeSidebar();}else{sb.classList.add('open');ov.classList.add('on');document.body.style.overflow='hidden';if(hb)hb.classList.add('active');}}
function closeSidebar(){$('sidebar').classList.remove('open');$('sbOverlay').classList.remove('on');document.body.style.overflow='';const hb=$('hamburgerBtn');if(hb)hb.classList.remove('active');}
function toggleDesktopSidebar(){
  document.body.classList.toggle('sidebar-collapsed');
  if(document.body.classList.contains('sidebar-collapsed')) {
    localStorage.setItem('shashety_sidebar', 'collapsed');
  } else {
    localStorage.removeItem('shashety_sidebar');
  }
}
function uploadChannelLogo(inp,inputId,previewId,statusId){
    const f=inp.files[0];if(!f)return;
    const statusEl=$(statusId),previewEl=$(previewId);
    statusEl.innerHTML='<span class="sp"></span> جاري رفع الصورة...';
    const fd=new FormData();fd.append('ajax_action','upload_channel_logo');fd.append('logo',f);
    const xhr=new XMLHttpRequest();
    xhr.upload.onprogress=e=>{if(e.lengthComputable)statusEl.innerHTML=`<span class="sp"></span> ${Math.round(e.loaded/e.total*100)}%`;};
    xhr.onload=()=>{
        try{
            const d=JSON.parse(xhr.responseText);
            if(d.success){$(inputId).value=d.url;statusEl.innerHTML=`<span style="color:#00D084"><i class="fas fa-check-circle"></i> تم رفع الصورة</span>`;previewEl.style.display='block';previewEl.querySelector('img').src=d.url;}
            else statusEl.innerHTML=`<span style="color:#ff6b6b">${d.error||'خطأ في الرفع'}</span>`;
        }catch(e){statusEl.innerHTML=`<span style="color:#ff6b6b">خطأ</span>`;}
        inp.value='';
    };
    xhr.onerror=()=>{statusEl.innerHTML=`<span style="color:#ff6b6b">انقطع الاتصال</span>`;};
    xhr.open('POST',location.href);xhr.send(fd);
}

function previewImage(previewId,url){
    const el=$(previewId);if(!el)return;
    if(!url){el.style.display='none';return;}
    el.style.display='block';
    const img=el.querySelector('img');
    img.src=url;
    img.onerror=()=>el.style.display='none';
}

function saveApiSettings(){
    const tmdb_key = $('api_tmdb_key').value.trim();
    const os_user  = $('api_os_user').value.trim();
    const os_pass  = $('api_os_pass').value.trim();
    const os_key   = $('api_os_key').value.trim();
    const omdb_key = $('api_omdb_key').value.trim();
    
    al('apiSaveAlert', '<span class="sp"></span> جاري حفظ الإعدادات...', 'i');
    
    api({
        ajax_action: 'save_api_settings',
        tmdb_key: tmdb_key,
        os_user: os_user,
        os_pass: os_pass,
        os_key: os_key,
        omdb_key: omdb_key
    }).then(d => {
        if(d.success){
            al('apiSaveAlert', '✅ تم حفظ إعدادات الـ API بنجاح في قاعدة البيانات', 's');
            $('osU').value = os_user;
            $('osP').value = os_pass;
            $('osApiKey').value = os_key;
            
            // حقن تشغيل تسجيل الدخول التلقائي فور نجاح الحفظ
            if(os_user && os_pass && os_key){
                al('apiSaveAlert', '✅ تم الحفظ، يتم الآن ربط اتصال OpenSubtitles تلقائياً...', 's');
                setTimeout(osLogin, 800); 
            }
        }else{
            al('apiSaveAlert', d.error || 'حدث خطأ أثناء الحفظ', 'e');
        }
    });
}

let _tmdbTimer={};
const SERVER_TMDB_KEY = "<?php echo addslashes($settings['tmdb_api_key'] ?? ''); ?>";
function getTmdbKey(){ return SERVER_TMDB_KEY; }
const SERVER_OMDB_KEY = "<?php echo addslashes($settings['omdb_api_key'] ?? ''); ?>";
function getOmdbKey(){ return SERVER_OMDB_KEY; }
let _currentSource = { add: 'tmdb', edit: 'tmdb' };
let _mediaSearchTimer = {};

function tmdbAutoSearch(ctx,val){clearTimeout(_tmdbTimer[ctx]);const res=$('tmdbRes_'+ctx);if(!val||val.length<3){res.style.display='none';return;}_tmdbTimer[ctx]=setTimeout(()=>_tmdbSearch(ctx,val),600);}
async function tmdbFetch(ctx){const nameId=ctx==='add'?'addChName':'eChName';const val=$(nameId).value.trim();if(!val){$(nameId).focus();return;}if(!getTmdbKey()){tmdbAskKey(ctx,val);return;}await _tmdbSearch(ctx,val);}

function tmdbAskKey(ctx, pendingQuery){
    alert('يرجى إضافة مفتاح TMDB API في قسم "إعدادات API" أولاً لكي تعمل هذه الميزة.');
    S('api-settings');
    closeSidebar();
}

async function _tmdbSearch(ctx,q){const key=getTmdbKey();if(!key){tmdbAskKey(ctx,q);return;}const res=$('tmdbRes_'+ctx);res.style.display='block';res.innerHTML='<div class="tmdb-item"><div class="tmdb-item-info" style="color:var(--t3)"><span class="sp"></span> جارٍ البحث في TMDB…</div></div>';try{const[rAr,rEn]=await Promise.all([fetch(`https://api.themoviedb.org/3/search/multi?api_key=${encodeURIComponent(key)}&query=${encodeURIComponent(q)}&language=ar`),fetch(`https://api.themoviedb.org/3/search/multi?api_key=${encodeURIComponent(key)}&query=${encodeURIComponent(q)}&language=en-US`)]);if(rAr.status===401||rEn.status===401){res.innerHTML='<div class="tmdb-item" onclick="S(\'api-settings\')" style="cursor:pointer"><div class="tmdb-item-info"><span style="color:#ff6b6b"><i class="fas fa-key"></i> مفتاح API غير صحيح — انقر هنا لتعديله</span></div></div>';return;}const[dAr,dEn]=await Promise.all([rAr.json(),rEn.json()]);const seen=new Set();const combined=[...(dAr.results||[]),...(dEn.results||[])].filter(item=>{const id=item.id;if(seen.has(id))return false;seen.add(id);return(item.title||item.name)&&item.poster_path;}).slice(0,8);if(!combined.length){const rFallback=await fetch(`https://api.themoviedb.org/3/search/multi?api_key=${encodeURIComponent(key)}&query=${encodeURIComponent(q)}`);const dFallback=await rFallback.json();const fallbackItems=(dFallback.results||[]).filter(i=>i.title||i.name).slice(0,8);if(!fallbackItems.length){res.innerHTML='<div class="tmdb-item"><div class="tmdb-item-info" style="color:var(--t3)"><i class="fas fa-search"></i> لا توجد نتائج — جرب اسم آخر أو بالإنجليزية</div></div>';return;}renderTmdbResults(res,fallbackItems,ctx);return;}renderTmdbResults(res,combined,ctx);}catch(e){res.innerHTML='<div class="tmdb-item"><div class="tmdb-item-info" style="color:#ff6b6b"><i class="fas fa-exclamation-triangle"></i> خطأ في الاتصال — تحقق من الإنترنت</div></div>';}}

function renderTmdbResults(res,items,ctx){
    res.innerHTML=items.map(item=>{
        const title=item.title||item.name||'';
        const year=(item.release_date||item.first_air_date||'').substring(0,4);
        const poster=item.poster_path?`https://image.tmdb.org/t/p/w92${item.poster_path}`:'';
        const posterFull=item.poster_path?`https://image.tmdb.org/t/p/w500${item.poster_path}`:'';
        const mediaType=item.media_type||'movie';
        const typeHtml=mediaType==='tv'?'<span class="bdg bp" style="font-size:.6rem">مسلسل</span>':'<span class="bdg bc" style="font-size:.6rem">فيلم</span>';
        const rating=item.vote_average?`<span style="color:var(--gold);font-size:.65rem"><i class="fas fa-star"></i> ${item.vote_average.toFixed(1)}</span>`:'';
        return `<div class="tmdb-item" onclick="tmdbPick('${ctx}','${escA(title)}','${escA(posterFull)}')">
            <img src="${esc(poster)}" onerror="this.style.opacity='.2'">
            <div class="tmdb-item-info">
                <div class="tmdb-item-title">${esc(title)}</div>
                <div class="tmdb-item-year" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">${year||'—'} ${typeHtml} ${rating}</div>
            </div>
            <button type="button" class="tmdb-info-btn" onclick="event.preventDefault(); event.stopPropagation(); showTmdbInfo(${item.id}, '${mediaType}')" title="التفاصيل"><i class="fas fa-info"></i></button>
        </div>`;
    }).join('');
}

async function showTmdbInfo(id, type) {
    const key = getTmdbKey();
    if (!key) { alert('مفتاح TMDB مفقود! يرجى إضافته في الإعدادات.'); return; }
    OM('tmdbInfoM');
    const body = $('tmdbInfoBody');
    body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--t3)"><div class="pspin" style="margin:0 auto 12px"></div>جاري جلب التفاصيل...</div>';
    try {
        let res = await fetch(`https://api.themoviedb.org/3/${type}/${id}?api_key=${encodeURIComponent(key)}&language=ar`);
        let data = await res.json();
        if (!data.overview) {
            let resEn = await fetch(`https://api.themoviedb.org/3/${type}/${id}?api_key=${encodeURIComponent(key)}&language=en-US`);
            let dataEn = await resEn.json();
            data.overview = dataEn.overview;
        }
        const title = data.title || data.name || 'بدون عنوان';
        const poster = data.poster_path ? `https://image.tmdb.org/t/p/w300${data.poster_path}` : '';
        const year = (data.release_date || data.first_air_date || '').substring(0, 4);
        const rating = data.vote_average ? data.vote_average.toFixed(1) : '—';
        const genres = (data.genres || []).map(g => `<span class="bdg bc">${g.name}</span>`).join(' ');
        const overview = data.overview || 'لا توجد قصة متوفرة لهذا العمل في الوقت الحالي.';
        const status = data.status || '—';
        const runTime = data.runtime ? `${data.runtime} دقيقة` : (data.episode_run_time && data.episode_run_time[0] ? `${data.episode_run_time[0]} دقيقة للحلقة` : '');

        body.innerHTML = `
            <div class="tmdb-info-wrap">
                ${poster ? `<img src="${poster}" class="tmdb-info-poster">` : `<div class="tmdb-info-poster" style="display:flex;align-items:center;justify-content:center;height:195px"><i class="fas fa-film fa-2x"></i></div>`}
                <div class="tmdb-info-details">
                    <div class="tmdb-info-title">${title} ${year ? `(${year})` : ''}</div>
                    <div class="tmdb-info-meta">
                        <span style="color:var(--gold);font-weight:bold;"><i class="fas fa-star"></i> ${rating}</span>
                        ${runTime ? `<span><i class="fas fa-clock"></i> ${runTime}</span>` : ''}
                        <span style="color:var(--t2)">الحالة: ${status}</span>
                    </div>
                    <div style="margin-bottom:14px">${genres}</div>
                    <div style="font-size:0.8rem;font-weight:bold;margin-bottom:6px;color:var(--t2)">القصة:</div>
                    <div class="tmdb-info-overview">${overview}</div>
                </div>
            </div>
        `;
    } catch (e) {
        body.innerHTML = '<div style="text-align:center;padding:40px;color:#ff6b6b"><i class="fas fa-exclamation-triangle fa-2x"></i><br><br>حدث خطأ أثناء الاتصال بخوادم TMDB.</div>';
    }
}

function tmdbPick(ctx,title,poster){const res=$('tmdbRes_'+ctx);if(ctx==='add'){$('addChName').value=title;if(poster){$('addChLogo').value=poster;previewImage('addPrev',poster);}}else{$('eChName').value=title;if(poster){$('eChLogo').value=poster;previewImage('editPrev',poster);}}res.style.display='none';}
function tmdbPreviewUrl(elId,url){const el=$(elId);if(!el)return;if(!url||(!url.startsWith('http')&&!url.startsWith('/'))){el.style.display='none';return;}el.style.display='block';const img=el.querySelector('img');img.src=url;img.onerror=()=>{el.style.display='none';};}
document.addEventListener('click',e=>{if(!e.target.closest('.fg-rel'))document.querySelectorAll('.tmdb-results').forEach(r=>r.style.display='none');});

/* ════ PLAYER STATE — FIXED v3 (Smart Backup Fallback) ════ */
let _hls = null, _pUrl = '', _pSub = '';
let _watchdogTimer = null;
let _lastTime = -1;
let _frozenCount = 0;
/* الرابط الاحتياطي الذكي */
let _pName = '';            // اسم القناة الحالية
let _pPrimary = '';         // الرابط الأساسي
let _pBackup = '';          // الرابط الاحتياطي
let _pUsingBackup = false;  // هل نشغّل حالياً الرابط الاحتياطي؟
let _pTriedBackup = false;  // هل جرّبنا الاحتياطي في هذه الجلسة؟
let _pConnectTimer = null;  // مؤقّت أولي: إن لم يبدأ التشغيل خلال مهلة → تبديل للاحتياطي

/* اكتشاف صيغة الرابط */
function detectFmt(url) {
    if (!url) return 'hls';
    const clean = url.split('?')[0].split('#')[0].toLowerCase();
    if (clean.endsWith('.m3u8') || clean.endsWith('.m3u')) return 'hls';
    if (clean.includes('m3u8') || clean.includes('/hls/') || clean.includes('type=m3u')) return 'hls';
    if (clean.endsWith('.ts') || clean.endsWith('.mts')) return 'hls';
    if (clean.endsWith('.mpd')) return 'dash';
    if (clean.endsWith('.mp4') || clean.endsWith('.m4v')) return 'mp4';
    if (clean.endsWith('.mkv') || clean.endsWith('.avi') || clean.endsWith('.webm')) return 'direct';
    return 'hls'; // افتراضي دائماً HLS
}

/* تدمير كامل للبلاير السابق */
function _destroyAll() {
    // إيقاف watchdog
    if (_watchdogTimer) { clearInterval(_watchdogTimer); _watchdogTimer = null; }
    if (_pConnectTimer) { clearTimeout(_pConnectTimer); _pConnectTimer = null; }
    _lastTime = -1; _frozenCount = 0;
    // تدمير HLS
    if (_hls) { try { _hls.destroy(); } catch(e) {} _hls = null; }
    // تنظيف عنصر الفيديو
    const vid = $('tv');
    if (!vid) return;
    vid.oncanplay = null; vid.onwaiting = null; vid.onplaying = null;
    vid.onstalled = null; vid.onerror = null;
    try {
        vid.pause();
        // إزالة كل المصادر والـ tracks
        while (vid.firstChild) vid.removeChild(vid.firstChild);
        vid.removeAttribute('src');
        vid.load();
    } catch(e) {}
}

/* ════ التبديل الذكي للرابط الاحتياطي ════
 * يُستدعى عند فشل/تجمّد/عدم استجابة الرابط الحالي.
 * إن وُجد رابط احتياطي ولم يُجرَّب بعد → ننتقل إليه تلقائياً.
 * يُرجع true إذا تمّ التبديل (وبالتالي لا نعرض رسالة خطأ). */
function _smartFallback(reason) {
    if (_pBackup && _pBackup.trim() && !_pTriedBackup && !_pUsingBackup) {
        _pTriedBackup = true;
        _pUsingBackup = true;
        if (window.toast) toast('الرابط الأساسي لا يعمل — التبديل للرابط الاحتياطي تلقائياً…', 'i');
        // إظهار التحميل من جديد
        $('pload').classList.remove('hid');
        $('perr').classList.remove('sh');
        $('pdot').className = 'pdot';
        _playSource(_pBackup);
        return true;
    }
    return false;
}

/* watchdog: يراقب التجمّد ويُعيد التشغيل/يبدّل للاحتياطي تلقائياً */
function _startWatchdog() {
    if (_watchdogTimer) clearInterval(_watchdogTimer);
    _lastTime = -1; _frozenCount = 0;
    _watchdogTimer = setInterval(function() {
        const vid = $('tv');
        if (!vid || vid.paused || vid.ended) return;
        const ct = vid.currentTime;
        if (ct === _lastTime) {
            _frozenCount++;
            if (_frozenCount >= 5) { // 10 ثوانٍ تجمّد
                _frozenCount = 0;
                console.warn('Watchdog: stream frozen.');
                // جرّب الاحتياطي أولاً، وإن لم يوجد أعد تشغيل نفس المصدر
                if (!_smartFallback('frozen')) {
                    _playSource(_pUsingBackup ? _pBackup : _pPrimary);
                }
            }
        } else {
            _frozenCount = 0;
            _lastTime = ct;
        }
    }, 2000);
}

function testChannel(url, name, subUrl, backupUrl) {
    // ضبط حالة الرابط الأساسي/الاحتياطي الذكي
    _pName        = name || url || '';
    _pSub         = subUrl || '';
    _pPrimary     = url || '';
    _pBackup      = backupUrl || '';
    _pUsingBackup = false;
    _pTriedBackup = false;
    _pUrl         = _pPrimary;

    // تحديث الواجهة
    $('ptitle').textContent = _pName;
    $('purl').textContent = _pPrimary;
    $('pm').classList.add('op');
    document.body.style.overflow = 'hidden';
    $('pload').classList.remove('hid');
    $('perr').classList.remove('sh');
    $('pdot').className = 'pdot';
    $('pfmt').style.display = 'none';

    // ═══ تدمير كل شيء قبل البدء ═══
    _destroyAll();

    const vid = $('tv');
    vid.setAttribute('playsinline', '');
    vid.setAttribute('webkit-playsinline', '');
    vid.removeAttribute('crossorigin'); // قد يسبب CORS مشاكل مع بعض السيرفرات

    // ═══ الترجمة ═══
    if (_pSub && _pSub.trim()) {
        $('psubbar').style.display = 'flex';
        $('psubLabel').textContent = 'ترجمة: ' + _pSub.split('/').pop();
        const tr = document.createElement('track');
        tr.kind = 'subtitles'; tr.srclang = 'ar'; tr.label = 'عربي';
        tr.src = _pSub; tr.default = true;
        vid.appendChild(tr);
        setTimeout(function() {
            if (vid.textTracks[0]) vid.textTracks[0].mode = 'showing';
        }, 800);
        $('psubToggleIc').className = 'fas fa-toggle-on';
        $('psubToggleTxt').textContent = 'إخفاء';
    } else {
        $('psubbar').style.display = 'none';
    }

    
    // --- START ADVANCED AUDIO/VIDEO CODEC DETECTION (Dolby Atmos / HEVC) ---
    function autoDetectAndConfigureCodecs(vidElement) {
        let codecs = [];
        
        // 1. فحص دعم جودة 4K/HDR (H.265 / HEVC)
        const canPlayHEVC = window.MediaSource && MediaSource.isTypeSupported('video/mp4; codecs="hev1.1.6.L93.B0"');
        if (canPlayHEVC) codecs.push('HEVC/4K');
        
        // 2. فحص دعم الصوت المحيطي (Dolby Digital Plus / Dolby Atmos / E-AC-3)
        const canPlayDolby = vidElement.canPlayType('audio/mp4; codecs="ec-3"') || vidElement.canPlayType('audio/mp4; codecs="mp4a.a6"');
        if (canPlayDolby) {
            codecs.push('Dolby Atmos');
        } else {
            codecs.push('AAC/Standard'); // التبديل التلقائي العادي
        }
        
        // عرض النتيجة في الشارة
        const codecBadge = document.getElementById('pcodec');
        if (codecBadge) {
            codecBadge.style.display = 'inline';
            codecBadge.textContent = codecs.join(' + ');
            if (canPlayDolby) {
                // شكل احترافي إذا تم التقاط تقنية دولبي
                codecBadge.style.color = '#fff';
                codecBadge.style.background = 'linear-gradient(45deg, #000000, #0055ff)';
                codecBadge.style.border = '1px solid #0055ff';
            } else {
                codecBadge.style.color = '#B36BFF';
                codecBadge.style.background = 'rgba(179,107,255,.15)';
                codecBadge.style.border = '1px solid rgba(179,107,255,.3)';
            }
        }

        // 3. التوجيه التلقائي للصوت (Spatial Audio Context) للتلفاز والمسرح المنزلي
        try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (AudioContext && !vidElement._audioRouted) {
                const audioCtx = new AudioContext();
                // فتح جميع القنوات المتاحة في كرت الصوت الخاص بالشاشة (مثلاً 5.1 أو 7.1)
                if (audioCtx.destination.maxChannelCount > 2) {
                    audioCtx.destination.channelCount = audioCtx.destination.maxChannelCount;
                }
                vidElement._audioRouted = true;
            }
        } catch(e) {
            // تجاهل بصمت، النظام سيعمل بالوضع القياسي
        }
    }
    
    autoDetectAndConfigureCodecs($('tv'));
    // --- END ADVANCED CODEC DETECTION ---

    // بدء التشغيل من الرابط الأساسي (مع التبديل الذكي للاحتياطي عند الفشل)
    _playSource(_pPrimary);
}

/* تحميل وتشغيل مصدر فيديو محدد (أساسي أو احتياطي) مع كل أنظمة الحماية والتبديل الذكي */
function _playSource(url) {
    _pUrl = url || '';
    // أوقف أي watchdog/مؤقّت قديم وأعد ضبط البلاير دون لمس الترجمة/الحالة
    if (_watchdogTimer) { clearInterval(_watchdogTimer); _watchdogTimer = null; }
    if (_pConnectTimer) { clearTimeout(_pConnectTimer); _pConnectTimer = null; }
    _lastTime = -1; _frozenCount = 0;
    if (_hls) { try { _hls.destroy(); } catch(e) {} _hls = null; }

    const vid = $('tv');
    if (!vid) return;
    vid.oncanplay = null; vid.onwaiting = null; vid.onplaying = null;
    vid.onstalled = null; vid.onerror = null;
    try { vid.pause(); vid.removeAttribute('src'); vid.load(); } catch(e) {}

    // إظهار أي مصدر يُشغَّل الآن في الواجهة
    $('purl').textContent = url + (_pUsingBackup ? '  (رابط احتياطي)' : '');

    // مؤقّت اتصال ذكي: إن لم يبدأ التشغيل فعلياً خلال 12 ثانية → بدّل للاحتياطي
    _pConnectTimer = setTimeout(function() {
        const v = $('tv');
        if (v && v.currentTime > 0 && !v.paused) return; // يعمل بالفعل
        if (!_smartFallback('timeout')) {
            pShowErr('انتهت مهلة الاتصال — تعذّر تشغيل الرابط');
        }
    }, 12000);

    const fmt = detectFmt(url);

    // ══════════════ HLS ══════════════
    if (fmt === 'hls') {
        $('pfmt').style.display = '';
        $('pfmt').textContent = 'HLS';
        $('pfmt').style.cssText = 'display:inline;font-size:.65rem;font-weight:800;padding:2px 8px;border-radius:4px;background:rgba(229,9,20,.15);border:1px solid rgba(229,9,20,.3);color:var(--red)';

        if (typeof Hls !== 'undefined' && Hls.isSupported()) {
            _hls = new Hls({
                enableWorker: true,
                lowLatencyMode: false,         // ← السبب الرئيسي للتوقف بعد ثانية — أُوقف
                capLevelToPlayerSize: false,
                maxMaxBufferLength: 120,        // ← بفر أكبر = استقرار أفضل
                maxBufferLength: 60,
                maxBufferSize: 60 * 1000 * 1000,
                backBufferLength: 30,
                startLevel: -1,                 // اختيار جودة تلقائي
                abrEwmaDefaultEstimate: 1000000,
                // --- ADDED: Professional Codec Handling ---
                altAudio: true,                // التعرف التلقائي واختيار مسارات الصوت البديلة (دولبي)
                enableSoftwareAES: true,       // معالجة أفضل لفك تشفير القنوات المعقدة
                audioCodecSetup: "audio/mp4",  // تجهيز مساحة الصوت العالي
                // ------------------------------------------
                // إعادة المحاولة عند فشل التحميل
                fragLoadingMaxRetry: 8,
                manifestLoadingMaxRetry: 6,
                levelLoadingMaxRetry: 6,
                fragLoadingRetryDelay: 1500,
                manifestLoadingRetryDelay: 1000,
                levelLoadingRetryDelay: 1000,
                // لا تضغط بيانات manifest
                xhrSetup: function(xhr) {
                    xhr.withCredentials = false;
                }
            });

            _hls.loadSource(url);
            _hls.attachMedia(vid);

            _hls.on(Hls.Events.MANIFEST_PARSED, function() {
                vid.play().catch(function() {});
                _startWatchdog();
            });

            _hls.on(Hls.Events.FRAG_LOADED, function() {
                if (_pConnectTimer) { clearTimeout(_pConnectTimer); _pConnectTimer = null; }
                $('pload').classList.add('hid');
                $('pdot').className = 'pdot ok';
            });

            var _mediaErrCount = 0;
            _hls.on(Hls.Events.ERROR, function(event, data) {
                console.warn('HLS Error:', data.type, data.details, 'fatal:', data.fatal);
                if (!data.fatal) return; // تجاهل الأخطاء غير المميتة تماماً

                if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
                    // خطأ شبكة مميت: جرّب الاحتياطي فوراً، وإلا أعد محاولة التحميل
                    if (_smartFallback('hls-network')) return;
                    setTimeout(function() {
                        if (_hls) { try { _hls.startLoad(); } catch(e) {} }
                    }, 2000);
                } else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
                    _mediaErrCount++;
                    if (_mediaErrCount <= 3) {
                        try { _hls.recoverMediaError(); } catch(e) {}
                    } else if (!_smartFallback('hls-media')) {
                        pShowErr('خطأ في فك ترميز الفيديو');
                    }
                } else {
                    if (!_smartFallback('hls-other')) pShowErr('خطأ HLS: ' + data.details);
                }
            });

        } else if (vid.canPlayType('application/vnd.apple.mpegurl')) {
            // Safari / iOS — HLS أصلي
            vid.src = url;
            vid.play().catch(function() {});
        } else {
            vid.src = url;
            vid.play().catch(function() {});
        }

    // ══════════════ MP4 / Direct ══════════════
    } else {
        $('pfmt').style.display = '';
        $('pfmt').textContent = fmt.toUpperCase();
        $('pfmt').style.cssText = 'display:inline;font-size:.65rem;font-weight:800;padding:2px 8px;border-radius:4px;background:rgba(0,208,132,.15);border:1px solid rgba(0,208,132,.3);color:#00D084';
        vid.src = url;
        vid.play().catch(function() {});
    }

    // ═══ أحداث الفيديو ═══
    vid.oncanplay = function() {
        if (_pConnectTimer) { clearTimeout(_pConnectTimer); _pConnectTimer = null; }
        $('pload').classList.add('hid');
        $('pdot').className = 'pdot ok';
        // تشغيل watchdog للمصادر المباشرة (MP4/Direct/HLS أصلي) التي لا تمر بـ MANIFEST_PARSED
        if (!_hls) _startWatchdog();
    };
    vid.onwaiting = function() {
        $('pload').classList.remove('hid');
    };
    vid.onplaying = function() {
        if (_pConnectTimer) { clearTimeout(_pConnectTimer); _pConnectTimer = null; }
        $('pload').classList.add('hid');
        $('pdot').className = 'pdot ok';
    };
    vid.onstalled = function() {
        // محاولة استئناف تلقائية عند توقف البفر
        setTimeout(function() {
            if (vid.paused && _pUrl) { vid.play().catch(function() {}); }
        }, 3000);
    };
    vid.onerror = function() {
        // فشل المصدر الحالي → التبديل الذكي للرابط الاحتياطي إن وُجد
        if (!_smartFallback('video-error')) {
            pShowErr('تعذر تشغيل الفيديو — تحقق من الرابط');
        }
    };
}

function pShowErr(msg) {
    $('pload').classList.add('hid');
    $('perr').classList.add('sh');
    $('pdot').className = 'pdot err';
    var em = document.getElementById('perrMsg');
    if (em) em.textContent = msg || 'تعذر تشغيل الفيديو';
}
function pRetry() {
    // إعادة المحاولة من الرابط الأساسي مع إتاحة التبديل للاحتياطي من جديد
    testChannel(_pPrimary || _pUrl, _pName, _pSub, _pBackup);
}
function pOpenNew() { if (_pUrl) window.open(_pUrl, '_blank'); }
function pCopyUrl() {
    if (!_pUrl) return;
    navigator.clipboard && navigator.clipboard.writeText(_pUrl).then(function() {
        var b = document.querySelector('#pm .pbtn');
        if (b) { var old = b.innerHTML; b.innerHTML = '<i class="fas fa-check"></i> نُسخ'; setTimeout(function() { b.innerHTML = old; }, 1500); }
    });
}
function pToggleSub() {
    const vid = $('tv'), trk = vid.querySelector('track');
    if (!trk) return;
    const on = trk.track.mode === 'showing';
    trk.track.mode = on ? 'disabled' : 'showing';
    $('psubToggleIc').className = on ? 'fas fa-toggle-off' : 'fas fa-toggle-on';
    $('psubToggleTxt').textContent = on ? 'إظهار' : 'إخفاء';
}
function closePlayer() {
    $('pm').classList.remove('op');
    document.body.style.overflow = '';
    _destroyAll();
}

function editCat(d){$('eCatId').value=d.id;$('eCatName').value=d.name;$('eCatIcon').value=d.icon||'fas fa-th-large';const sel=$('eCatParent');for(let o of sel.options)o.selected=(o.value===(d.parent_id||'').toString());OM('editCatM');}
function editCh(d){$('eChId').value=d.id;$('eChName').value=d.name;$('eChUrl').value=d.stream_url;$('eChBackup').value=d.backup_url||'';$('eChQuality').value=d.quality||'HD 720';$('eChActive').checked=(parseInt(d.is_active)!==0);$('eChIcon').value=d.logo_icon||'fas fa-tv';$('eChLogo').value=d.logo_url||'';const sel=$('eChCat');for(let o of sel.options)o.selected=(o.value===d.category_id.toString());if(d.logo_url)previewImage('editPrev',d.logo_url);else $('editPrev').style.display='none';OM('editChM');}
function toggleChannelActive(checkbox){
  const chid = checkbox.getAttribute('data-ch-id');
  const newState = checkbox.checked ? '1' : '0';
  checkbox.disabled = true;
  api({ ajax_action:'toggle_channel_active', channel_id:chid, is_active:newState }).then(d=>{
    checkbox.disabled = false;
    if(d.success){
      if(window.toast) toast(d.message||'تم التحديث','s');
    } else {
      checkbox.checked = !checkbox.checked; // تراجع عند الفشل
      if(window.toast) toast(d.error||'تعذّر تحديث حالة القناة','e');
      else alert(d.error||'تعذّر تحديث حالة القناة');
    }
  }).catch(()=>{
    checkbox.disabled = false;
    checkbox.checked = !checkbox.checked;
    if(window.toast) toast('خطأ في الاتصال','e');
  });
}

/* ════ تحديد وحذف جماعي — الأقسام ════ */
function catSelCtrl(){
  const chks = document.querySelectorAll('.catSelChk');
  const sel  = document.querySelectorAll('.catSelChk:checked');
  const bar  = $('catBulkBar');
  if(sel.length){ bar.style.display='flex'; $('catSelCount').textContent = sel.length; }
  else { bar.style.display='none'; }
  const all = $('catSelAll');
  if(all){ all.checked = (chks.length>0 && sel.length===chks.length); all.indeterminate = (sel.length>0 && sel.length<chks.length); }
}
function catToggleAll(master){
  document.querySelectorAll('#catTbl tbody tr').forEach(tr=>{
    if(tr.style.display==='none') return; // تجاهل المخفي بالبحث
    const c = tr.querySelector('.catSelChk'); if(c) c.checked = master.checked;
  });
  catSelCtrl();
}
function catClearSel(){
  document.querySelectorAll('.catSelChk').forEach(c=>c.checked=false);
  const all=$('catSelAll'); if(all){ all.checked=false; all.indeterminate=false; }
  catSelCtrl();
}
function catBulkDelete(){
  const ids = [...document.querySelectorAll('.catSelChk:checked')].map(c=>c.value);
  if(!ids.length) return;
  if(!confirm('سيتم حذف '+ids.length+' قسم بالكامل مع كل القنوات المرتبطة بها. متابعة؟')) return;
  api({ajax_action:'bulk_delete_categories', ids:ids.join(',')}).then(d=>{
    if(d.success){
      if(window.toast) toast('تم حذف '+d.deleted+' قسم','s');
      setTimeout(()=>location.reload(), 900);
    } else { if(window.toast) toast(d.error||'فشل الحذف','e'); else alert(d.error||'فشل الحذف'); }
  });
}

/* ════ تحديد وحذف جماعي — القنوات ════ */
function chSelCtrl(){
  const chks = document.querySelectorAll('.chSelChk');
  const sel  = document.querySelectorAll('.chSelChk:checked');
  const bar  = $('chBulkBar');
  if(sel.length){ bar.style.display='flex'; $('chSelCount').textContent = sel.length; }
  else { bar.style.display='none'; }
  const all = $('chSelAll');
  if(all){ all.checked = (chks.length>0 && sel.length===chks.length); all.indeterminate = (sel.length>0 && sel.length<chks.length); }
}
function chToggleAll(master){
  document.querySelectorAll('#chTbl tbody tr').forEach(tr=>{
    if(tr.style.display==='none') return;
    const c = tr.querySelector('.chSelChk'); if(c) c.checked = master.checked;
  });
  chSelCtrl();
}
function chClearSel(){
  document.querySelectorAll('.chSelChk').forEach(c=>c.checked=false);
  const all=$('chSelAll'); if(all){ all.checked=false; all.indeterminate=false; }
  chSelCtrl();
}
function chBulkDelete(){
  const ids = [...document.querySelectorAll('.chSelChk:checked')].map(c=>c.value);
  if(!ids.length) return;
  if(!confirm('سيتم حذف '+ids.length+' قناة. متابعة؟')) return;
  api({ajax_action:'bulk_delete_channels', ids:ids.join(',')}).then(d=>{
    if(d.success){
      if(window.toast) toast('تم حذف '+d.deleted+' قناة','s');
      setTimeout(()=>location.reload(), 900);
    } else { if(window.toast) toast(d.error||'فشل الحذف','e'); else alert(d.error||'فشل الحذف'); }
  });
}

/* ════ استيراد قوائم M3U ════ */
function m3uFileSelected(inp){
  const f = inp.files[0];
  if(!f) return;
  const ext = (f.name.split('.').pop()||'').toLowerCase();
  if(ext!=='m3u' && ext!=='m3u8'){
    $('m3uFileStatus').innerHTML='<span style="color:#ff6b6b"><i class="fas fa-exclamation-triangle"></i> صيغة غير مدعومة، استخدم m3u أو m3u8</span>';
    inp.value='';
    return;
  }
  const fd = new FormData();
  fd.append('ajax_action','import_m3u');
  fd.append('m3u_file', f);
  $('m3uFileStatus').innerHTML='<span class="sp"></span> جارٍ رفع وتحليل الملف…';
  fetch(location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    inp.value='';
    if(d.success){
      $('m3uFileStatus').innerHTML='<span style="color:#00D084"><i class="fas fa-check-circle"></i> تم استيراد '+d.count+' قناة بنجاح</span>';
      if(window.toast) toast('تم استيراد '+d.count+' قناة من الملف','s');
      setTimeout(()=>location.reload(), 1300);
    } else {
      $('m3uFileStatus').innerHTML='<span style="color:#ff6b6b"><i class="fas fa-exclamation-triangle"></i> '+esc(d.error||'فشل الاستيراد')+'</span>';
    }
  }).catch(()=>{
    inp.value='';
    $('m3uFileStatus').innerHTML='<span style="color:#ff6b6b"><i class="fas fa-exclamation-triangle"></i> انقطع الاتصال بالخادم</span>';
  });
}

function m3uImportFromUrl(){
  const url = $('m3uUrlIn').value.trim();
  if(!url){ $('m3uUrlStatus').innerHTML='<span style="color:#ff6b6b">أدخل رابط M3U صالح</span>'; return; }
  const btn = $('m3uUrlBtn');
  btn.disabled = true;
  const origHtml = btn.innerHTML;
  btn.innerHTML = '<span class="sp"></span> جارٍ الاستيراد…';
  $('m3uUrlStatus').innerHTML='';
  api({ajax_action:'import_m3u', m3u_url:url}).then(d=>{
    btn.disabled = false;
    btn.innerHTML = origHtml;
    if(d.success){
      $('m3uUrlStatus').innerHTML='<span style="color:#00D084"><i class="fas fa-check-circle"></i> تم استيراد '+d.count+' قناة بنجاح</span>';
      if(window.toast) toast('تم استيراد '+d.count+' قناة من الرابط','s');
      setTimeout(()=>location.reload(), 1300);
    } else {
      $('m3uUrlStatus').innerHTML='<span style="color:#ff6b6b"><i class="fas fa-exclamation-triangle"></i> '+esc(d.error||'فشل الاستيراد')+'</span>';
    }
  }).catch(()=>{
    btn.disabled = false;
    btn.innerHTML = origHtml;
    $('m3uUrlStatus').innerHTML='<span style="color:#ff6b6b">انقطع الاتصال بالخادم</span>';
  });
}

let _m3uPlaylists = [];
function m3uLoadPlaylists(){
  $('m3uPlaylistsTbl').style.display='none';
  $('m3uPlaylistsEmpty').style.display='none';
  $('m3uPlaylistsLoading').style.display='block';
  api({ajax_action:'list_m3u_playlists'}).then(d=>{
    $('m3uPlaylistsLoading').style.display='none';
    if(!d.success){ $('m3uPlaylistsEmpty').style.display='block'; return; }
    _m3uPlaylists = d.data||[];
    if(!_m3uPlaylists.length){ $('m3uPlaylistsEmpty').style.display='block'; return; }
    m3uRenderPlaylists();
  }).catch(()=>{ $('m3uPlaylistsLoading').style.display='none'; $('m3uPlaylistsEmpty').style.display='block'; });
}

function m3uRenderPlaylists(){
  const tbody = $('m3uPlaylistsBody');
  tbody.innerHTML = _m3uPlaylists.map(p=>{
    const isUrl = p.source_type === 'url';
    const srcLabel = isUrl ? (p.source_url||p.name) : p.name;
    const typeBdg = isUrl ? '<span class="bdg bp"><i class="fas fa-link"></i> رابط</span>' : '<span class="bdg bc"><i class="fas fa-file"></i> ملف</span>';
    const refreshBtn = isUrl ? `<button class="ib ed" title="تحديث من نفس الرابط" onclick="m3uRefreshPlaylist(${p.id})"><i class="fas fa-sync"></i></button>` : '';
    const editBtn = isUrl ? `<button class="ib ed" title="تعديل الرابط (يحذف كل القنوات القديمة ويستورد من الرابط الجديد)" onclick="m3uEditPlaylist(${p.id},'${escA(p.source_url||'')}')"><i class="fas fa-pen"></i></button>` : '';
    return `<tr><td><strong style="color:var(--t1)">${esc(srcLabel)}</strong></td><td>${typeBdg}</td><td><span class="bdg bg">${p.channels_count||0} قناة</span></td><td style="font-size:.75rem;color:var(--t3)">${esc(p.created_at||'')}</td><td><div class="acts">${refreshBtn}${editBtn}<button class="ib dl" title="حذف القائمة بالكامل مع كل قنواتها" onclick="m3uDeletePlaylist(${p.id},'${escA(srcLabel)}')"><i class="fas fa-trash"></i></button></div></td></tr>`;
  }).join('');
  $('m3uPlaylistsTbl').style.display='table';
}

function m3uEditPlaylist(id, currentUrl){
  const newUrl = prompt('أدخل رابط M3U الجديد.\nسيتم حذف كل القنوات القديمة المرتبطة بهذه القائمة بالكامل واستيرادها من جديد من الرابط:', currentUrl||'');
  if(newUrl===null) return;
  const u = newUrl.trim();
  if(!u){ if(window.toast) toast('الرابط فارغ','e'); return; }
  if(!/^https?:\/\//i.test(u)){ if(window.toast) toast('رابط غير صالح، يجب أن يبدأ بـ http:// أو https://','e'); else alert('رابط غير صالح'); return; }
  if(window.toast) toast('جارٍ تعديل الرابط وإعادة الاستيراد…','i');
  api({ajax_action:'edit_m3u_playlist', id:id, source_url:u}).then(d=>{
    if(d.success){
      if(window.toast) toast('تم تعديل الرابط — '+d.count+' قناة','s');
      setTimeout(()=>location.reload(), 1200);
    } else {
      if(window.toast) toast(d.error||'فشل التعديل','e'); else alert(d.error||'فشل التعديل');
    }
  });
}

function m3uRefreshPlaylist(id){
  if(!confirm('سيتم حذف كل قنوات هذه القائمة واستيرادها من جديد من نفس الرابط، متابعة؟')) return;
  api({ajax_action:'refresh_m3u_playlist', id:id}).then(d=>{
    if(d.success){
      if(window.toast) toast('تم تحديث القائمة — '+d.count+' قناة','s');
      setTimeout(()=>location.reload(), 1200);
    } else {
      if(window.toast) toast(d.error||'فشل التحديث','e'); else alert(d.error||'فشل التحديث');
    }
  });
}

function m3uDeletePlaylist(id,name){
  if(!confirm('حذف القائمة "'+name+'" بالكامل مع كل القنوات التابعة لها؟')) return;
  api({ajax_action:'delete_m3u_playlist', id:id}).then(d=>{
    if(d.success){
      if(window.toast) toast('تم حذف القائمة','s');
      setTimeout(()=>location.reload(), 1000);
    } else {
      if(window.toast) toast(d.error||'فشل الحذف','e'); else alert(d.error||'فشل الحذف');
    }
  });
}

/* ════════════════════ [XTREAM-JS-START] وظائف حساب Xtream — إضافة فقط ════════════════════ */
let _xtVerified = null; // بيانات الحساب بعد التحقق الناجح

function xtreamSetStatus(elId, msg, type){
  const el = $(elId); if(!el) return;
  const colors = {s:'#2ecc71', e:'#e74c3c', i:'var(--t2)'};
  el.innerHTML = '<span style="color:'+(colors[type]||colors.i)+'">'+msg+'</span>';
}

function xtreamLogin(){
  const host = ($('xtHost').value||'').trim();
  const user = ($('xtUser').value||'').trim();
  const pass = ($('xtPass').value||'').trim();
  const name = ($('xtName').value||'').trim();
  if(!host || !user || !pass){ xtreamSetStatus('xtLoginStatus','⚠️ يرجى ملء العنوان واسم المستخدم وكلمة المرور','e'); return; }
  const btn = $('xtLoginBtn'); btn.disabled = true; btn.innerHTML = '<span class="sp"></span> جارٍ التحقق...';
  $('xtImportBox').style.display = 'none';
  xtreamSetStatus('xtLoginStatus','⏳ جارٍ الاتصال بالسيرفر...','i');
  api({ajax_action:'xtream_login', host:host, username:user, password:pass, account_name:name}).then(d=>{
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-right-to-bracket"></i>تسجيل الدخول والتحقق';
    if(!d.success){ xtreamSetStatus('xtLoginStatus','❌ '+(d.error||'فشل تسجيل الدخول'),'e'); return; }
    _xtVerified = {host:d.host, username:user, password:pass, name:name};
    const ui = d.user_info||{}, si = d.server_info||{}, sup = d.supports||{}, cnt = d.counts||{};
    const exp = ui.exp_date ? new Date(ui.exp_date*1000).toLocaleDateString('ar') : 'غير محدد';
    const conns = (ui.active_cons||'0')+' / '+(ui.max_connections||'∞');
    xtreamSetStatus('xtLoginStatus','✅ تم تسجيل الدخول بنجاح','s');
    $('xtInfo').innerHTML =
      '<div style="display:flex;flex-wrap:wrap;gap:14px">'+
      '<span>👤 <b>'+esc(user)+'</b></span>'+
      '<span>📅 ينتهي: <b>'+esc(exp)+'</b></span>'+
      '<span>🔌 الاتصالات: <b>'+esc(conns)+'</b></span>'+
      '<span>🖥️ '+esc(si.url||'')+'</span>'+
      '</div>';
    // ضبط خيارات الاستيراد حسب الدعم
    const setOpt = (chk,lbl,supported,label,c)=>{
      const box=$(chk), span=$(lbl);
      if(supported){ box.disabled=false; box.checked=true; span.style.opacity='1'; span.innerHTML=label+' <span style="color:var(--t3)">('+c+' قسم)</span>'; }
      else { box.disabled=true; box.checked=false; span.style.opacity='.45'; span.innerHTML=label+' <span style="color:var(--t3)">(غير مدعوم)</span>'; }
    };
    setOpt('xtImpLive','xtLblLive',sup.live,'📡 القنوات',cnt.live);
    setOpt('xtImpVod','xtLblVod',sup.vod,'🎬 الأفلام',cnt.vod);
    setOpt('xtImpSeries','xtLblSeries',sup.series,'📺 المسلسلات',cnt.series);
    $('xtImportBox').style.display = 'block';
  }).catch(()=>{
    btn.disabled=false; btn.innerHTML='<i class="fas fa-right-to-bracket"></i>تسجيل الدخول والتحقق';
    xtreamSetStatus('xtLoginStatus','❌ خطأ في الاتصال','e');
  });
}

/* ══ محرّك تتبّع تقدّم الاستيراد الحيّ ══ */
let _xtPollTimer = null;

function xtFmtTime(sec){
  sec = Math.max(0, parseInt(sec)||0);
  const h = Math.floor(sec/3600), m = Math.floor((sec%3600)/60), s = sec%60;
  const p = n => String(n).padStart(2,'0');
  return h > 0 ? (h+':'+p(m)+':'+p(s)) : (p(m)+':'+p(s));
}

function xtProgShow(on){
  const box = $('xtProgBox');
  if(box) box.style.display = on ? 'block' : 'none';
}

function xtProgReset(){
  const set = (id,v)=>{ const e=$(id); if(e) e.textContent=v; };
  const fill = $('xtProgFill'); if(fill) fill.style.width='0%';
  set('xtProgPct','0%'); set('xtProgCount','—');
  set('xtCntLive','0'); set('xtCntVod','0'); set('xtCntSer','0'); set('xtCntSkip','0');
  set('xtElapsed','00:00'); set('xtEta','— يُحسب...');
  const sw = $('xtCntSkipWrap'); if(sw) sw.style.display='none';
  const lbl = $('xtProgLabel');
  if(lbl) lbl.innerHTML = '<span class="sp" style="width:15px;height:15px;border-width:2px;"></span>جارٍ التحضير...';
}

function xtProgApply(p){
  const set = (id,v)=>{ const e=$(id); if(e) e.textContent=v; };
  const pct = Math.max(0, Math.min(100, parseInt(p.percent)||0));

  const fill = $('xtProgFill'); if(fill) fill.style.width = pct+'%';
  set('xtProgPct', pct+'%');

  const lbl = $('xtProgLabel');
  if(lbl){
    const icon = '<span class="sp" style="width:15px;height:15px;border-width:2px;"></span>';
    let txt = p.label || 'جارٍ الاستيراد...';
    if(p.current) txt += ' — <span style="color:var(--t3); font-weight:500;">'+esc(p.current)+'</span>';
    lbl.innerHTML = icon + txt;
  }

  const done = parseInt(p.done)||0, total = parseInt(p.total)||0;
  set('xtProgCount', total > 0
      ? (done.toLocaleString('en-US')+' من '+total.toLocaleString('en-US')+' — المتبقي '+Math.max(0,total-done).toLocaleString('en-US'))
      : 'جارٍ جلب البيانات من السيرفر...');

  set('xtCntLive', (parseInt(p.live)||0).toLocaleString('en-US'));
  set('xtCntVod',  (parseInt(p.vod)||0).toLocaleString('en-US'));
  set('xtCntSer',  (parseInt(p.series)||0).toLocaleString('en-US'));

  const sk = parseInt(p.skipped)||0, sw = $('xtCntSkipWrap');
  if(sw){ sw.style.display = sk > 0 ? 'inline-flex' : 'none'; set('xtCntSkip', sk.toLocaleString('en-US')); }

  set('xtElapsed', xtFmtTime(p.elapsed));
  const eta = $('xtEta');
  if(eta){
    if(p.eta === null || p.eta === undefined){ eta.textContent = '— يُحسب...'; eta.style.color = 'var(--t3)'; }
    else { eta.textContent = xtFmtTime(p.eta); eta.style.color = '#10b981'; }
  }
}

function xtPollStart(){
  xtPollStop();
  _xtPollTimer = setInterval(()=>{
    api({ajax_action:'xtream_import_progress'})
      .then(d=>{ if(d && d.success && d.running) xtProgApply(d); })
      .catch(()=>{}); // فشل استعلام واحد لا يوقف المتابعة
  }, 1000);
}

function xtPollStop(){
  if(_xtPollTimer){ clearInterval(_xtPollTimer); _xtPollTimer = null; }
}

function xtreamImport(){
  if(!_xtVerified){ xtreamSetStatus('xtImportStatus','⚠️ سجّل الدخول أولاً','e'); return; }
  const impLive = $('xtImpLive').checked ? '1':'0';
  const impVod = $('xtImpVod').checked ? '1':'0';
  const impSeries = $('xtImpSeries').checked ? '1':'0';
  if(impLive==='0' && impVod==='0' && impSeries==='0'){ xtreamSetStatus('xtImportStatus','⚠️ اختر نوعاً واحداً على الأقل','e'); return; }
  const btn = $('xtImportBtn'), stopBtn = $('xtStopBtn');
  const restore = ()=>{
    xtPollStop(); xtProgShow(false);
    btn.disabled=false; btn.innerHTML='<i class="fas fa-download"></i>إضافة المحتوى المختار';
    if(stopBtn){ stopBtn.style.display='none'; stopBtn.disabled=false;
      stopBtn.innerHTML='<i class="fas fa-hand" style="margin-left:8px;"></i>إيقاف الاستيراد إجبارياً'; }
  };

  btn.disabled=true; btn.innerHTML='<span class="sp"></span> جارٍ الاستيراد... قد يستغرق وقتاً';
  if(stopBtn) stopBtn.style.display='inline-flex'; // يظهر زر الإيقاف أثناء العملية فقط
  xtProgReset(); xtProgShow(true); xtPollStart();   // شريط التقدّم الحيّ
  xtreamSetStatus('xtImportStatus','⏳ جارٍ جلب المحتوى وإضافته... لا تغلق الصفحة','i');

  api({
    ajax_action:'xtream_import',
    host:_xtVerified.host, username:_xtVerified.username, password:_xtVerified.password,
    account_name:_xtVerified.name,
    import_live:impLive, import_vod:impVod, import_series:impSeries
  }).then(d=>{
    restore();
    if(!d.success){ xtreamSetStatus('xtImportStatus','❌ '+(d.error||'فشل الاستيراد'),'e'); return; }
    const im = d.imported||{};
    const skipMsg = (d.skipped>0) ? ' — تم تخطي '+d.skipped+' عنصراً لبيانات غير صالحة' : '';
    xtreamSetStatus('xtImportStatus','✅ تمت الإضافة: '+(im.live||0)+' قناة، '+(im.vod||0)+' فيلم، '+(im.series||0)+' مسلسل'+skipMsg, skipMsg ? 'i' : 's');
    if(window.toast) toast('تمت إضافة حساب Xtream بنجاح','s');
    $('xtHost').value=''; $('xtUser').value=''; $('xtPass').value=''; $('xtName').value='';
    $('xtImportBox').style.display='none'; _xtVerified=null;
    xtreamLoadAccounts();
  }).catch(()=>{
    restore();
    xtreamSetStatus('xtImportStatus','❌ خطأ في الاتصال أثناء الاستيراد','e');
  });
}

/* إيقاف إجباري للاستيراد الجاري — يرفع إشارة يلتقطها السيرفر داخل حلقة الاستيراد */
function xtreamAbortImport(){
  if(!confirm('إيقاف الاستيراد الجاري إجبارياً؟\n\nسيتم التراجع عن كل ما استُورد جزئياً حتى الآن،\nولن يُضاف الحساب إلى القائمة.')) return;
  const stopBtn = $('xtStopBtn');
  if(stopBtn){
    stopBtn.disabled = true;
    stopBtn.innerHTML = '<span class="sp" style="width:14px;height:14px;border-width:2px;margin-left:8px;vertical-align:middle;"></span> جارٍ الإيقاف...';
  }
  xtreamSetStatus('xtImportStatus','🛑 تم إرسال إشارة الإيقاف — بانتظار توقف السيرفر...','e');
  api({ajax_action:'xtream_import_abort'}).then(()=>{
    if(window.toast) toast('تم إرسال إشارة الإيقاف','i');
  }).catch(()=>{
    if(window.toast) toast('تعذر إرسال إشارة الإيقاف','e');
  });
}

function xtreamLoadAccounts(){
  $('xtTbl').style.display='none';
  $('xtEmpty').style.display='none';
  $('xtLoading').style.display='block';
  api({ajax_action:'xtream_list'}).then(d=>{
    $('xtLoading').style.display='none';
    if(!d.success){ $('xtEmpty').style.display='block'; return; }
    const rows = d.accounts||[];
    if(!rows.length){ $('xtEmpty').style.display='block'; return; }
    $('xtBody').innerHTML = rows.map(a=>{
      const sync = a.last_sync ? esc(a.last_sync) : '—';
      
      let maxCons = '?', actCons = '?';
      let expDateStr = 'غير محدد';
      let uStatus = 'غير معروف';
      
      try {
        if(a.user_info) {
          const uInfo = typeof a.user_info === 'string' ? JSON.parse(a.user_info) : a.user_info;
          maxCons = uInfo.max_connections || 'غير محدود';
          actCons = uInfo.active_cons || 0;
          uStatus = uInfo.status || 'Active';
          if(uInfo.exp_date && uInfo.exp_date !== "null" && uInfo.exp_date !== "") {
            const exp = parseInt(uInfo.exp_date) * 1000;
            if(!isNaN(exp)) expDateStr = new Date(exp).toLocaleDateString('ar-EG', {year:'numeric', month:'short', day:'numeric'});
          }
        }
      }catch(e){}
      
      let statusColor = uStatus.toLowerCase() === 'active' ? '#10b981' : '#ef4444';

      return '<tr>'+
        '<td>'+
          '<div style="display:flex; align-items:center; gap:10px;">'+
             '<div style="width:10px; height:10px; border-radius:50%; background:'+statusColor+'; box-shadow:0 0 5px '+statusColor+'" title="'+esc(uStatus)+'"></div>'+
             '<strong style="color:var(--t1); font-size:1.05rem;">'+esc(a.name||'—')+'</strong>'+
          '</div>'+
        '</td>'+
        '<td style="direction:ltr; text-align:right;">'+
           '<div style="font-size:0.85rem; color:var(--t2); font-family:monospace; margin-bottom:4px;">'+esc(a.host||'')+'</div>'+
           '<div style="font-size:0.8rem; color:var(--primary); font-family:monospace;"><i class="fas fa-user" style="margin-right:5px; font-size:0.75rem;"></i>'+esc(a.username||'')+'</div>'+
        '</td>'+
        '<td style="text-align:center;">'+
           '<div style="display:inline-flex; align-items:center; background:var(--bg2); padding:5px 12px; border-radius:20px; border:1px solid var(--border);">'+
              '<span style="color:#ef4444; font-weight:bold; margin-left:5px;" title="المستخدم حالياً">'+actCons+'</span>'+
              '<span style="color:var(--t3); margin:0 5px;">/</span>'+
              '<span style="color:#8b5cf6; font-weight:bold;" title="الحد الأقصى">'+maxCons+'</span>'+
           '</div>'+
        '</td>'+
        '<td style="text-align:center;">'+
           '<div style="display:flex; gap:6px; justify-content:center;">'+
             '<span class="bdg bg" title="القنوات"><i class="fas fa-tv" style="margin-left:4px; font-size:0.7rem;"></i>'+(a.live_count||0)+'</span>'+
             '<span class="bdg bp" title="الأفلام"><i class="fas fa-film" style="margin-left:4px; font-size:0.7rem;"></i>'+(a.vod_count||0)+'</span>'+
             '<span class="bdg bc" title="المسلسلات"><i class="fas fa-layer-group" style="margin-left:4px; font-size:0.7rem;"></i>'+(a.series_count||0)+'</span>'+
           '</div>'+
        '</td>'+
        '<td><div style="font-size:0.9rem; font-weight:500; color:var(--t2);"><i class="far fa-clock" style="margin-left:6px; color:#10b981;"></i>'+expDateStr+'</div></td>'+
        '<td><div style="font-size:0.8rem; color:var(--t3);">'+sync+'</div></td>'+
        '<td style="text-align:center;"><div class="acts" style="justify-content:center;">'+
          '<button class="ib ed" title="تعديل بيانات الحساب" onclick="xtreamEdit('+a.id+',\''+escA(a.name||'')+'\',\''+escA(a.host||'')+'\',\''+escA(a.username||'')+'\')"><i class="fas fa-pen"></i></button>'+
          '<button class="ib" style="color:#f59e0b" title="تسجيل الخروج ومسح كل المحتوى المستورد" onclick="xtreamLogout('+a.id+',\''+escA(a.name||'')+'\')"><i class="fas fa-sign-out-alt"></i></button>'+
          '<button class="ib dl" title="حذف الحساب نهائياً وكل محتواه المستورد" onclick="xtreamDelete('+a.id+',\''+escA(a.name||'')+'\')"><i class="fas fa-trash"></i></button>'+
        '</div></td>'+
      '</tr>';
    }).join('');
    $('xtTbl').style.display='table';
  }).catch(()=>{ $('xtLoading').style.display='none'; $('xtEmpty').style.display='block'; });
}

/* مسح كل الأثر المحلي المرتبط بمحتوى الحساب: المفضلة + الإشعارات + الكاش */
function xtreamPurgeClient(){
  try{
    ['shashety_favs_v2','shashety_notifs_pending'].forEach(k=>{ try{localStorage.removeItem(k);}catch(e){} });
    try{
      Object.keys(localStorage).forEach(k=>{
        if(/^(shashety_|shs_|sc_)/i.test(k)) localStorage.removeItem(k);
      });
    }catch(e){}
    try{
      Object.keys(sessionStorage).forEach(k=>{
        if(/^(shs_|sc_)/i.test(k) || /api\.php/i.test(k)) sessionStorage.removeItem(k);
      });
    }catch(e){}
    if(window.scInvalidate) window.scInvalidate();
    if(window.caches && caches.keys) caches.keys().then(ks=>ks.forEach(k=>caches.delete(k))).catch(()=>{});
  }catch(e){}
}

function xtreamDelete(id, name){
  if(!confirm('حذف حساب "'+name+'" نهائياً وكل ما استُورد منه (القنوات + الأفلام + المسلسلات + الحلقات + أقسامها)؟\n\nلا يمكن التراجع عن هذه العملية.')) return;
  api({ajax_action:'xtream_delete', id:id}).then(d=>{
    if(d && d.success){
      if(d.purge_client) xtreamPurgeClient();
      if(window.toast) toast(d.message||'تم حذف الحساب وكل محتواه','s');
      xtreamLoadAccounts();
    } else {
      const msg = (d && d.error) ? d.error : 'فشل الحذف (رد غير متوقع من السيرفر)';
      if(window.toast) toast(msg,'e'); else alert(msg);
      console.error('xtream_delete failed:', d);
    }
  }).catch(err=>{
    if(window.toast) toast('خطأ في الاتصال أثناء الحذف — راجع Console','e');
    else alert('خطأ في الاتصال أثناء الحذف');
    console.error('xtream_delete error:', err);
  });
}

function xtreamLogout(id, name){
  if(!confirm('تسجيل الخروج من حساب "'+name+'"؟\n\nسيتم حذف كل القنوات والأفلام والمسلسلات والحلقات المستوردة منه،\nمع الإبقاء على بيانات الحساب لإعادة الاستيراد لاحقاً.')) return;
  if(window.toast) toast('جارٍ تسجيل الخروج ومسح المحتوى...','i');
  api({ajax_action:'xtream_logout', id:id}).then(d=>{
    if(d && d.success){
      if(d.purge_client) xtreamPurgeClient();
      if(window.toast) toast(d.message||'تم تسجيل الخروج ومسح كل محتوى الحساب','s');
      xtreamLoadAccounts();
    } else {
      const msg = (d && d.error) ? d.error : 'فشل تسجيل الخروج (رد غير متوقع من السيرفر)';
      if(window.toast) toast(msg,'e'); else alert(msg);
      console.error('xtream_logout failed:', d);
    }
  }).catch(err=>{
    // بدون هذا الـ catch كان الزر يفشل بصمت تماماً
    if(window.toast) toast('خطأ في الاتصال أثناء تسجيل الخروج — راجع Console','e');
    else alert('خطأ في الاتصال أثناء تسجيل الخروج');
    console.error('xtream_logout error:', err);
  });
}

/* تطبيق فهارس قاعدة البيانات */
function xtreamOptimizeDb(){
  const btn = $('xtOptBtn'), st = $('xtOptStatus');
  btn.disabled = true;
  btn.innerHTML = '<span class="sp" style="width:14px;height:14px;border-width:2px;margin-left:8px;vertical-align:middle;"></span> جارٍ التطبيق...';
  st.innerHTML = '<span style="color:var(--t3);">جارٍ فحص الجداول وإضافة الفهارس...</span>';

  api({ajax_action:'xtream_optimize_db'}).then(d=>{
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-bolt" style="margin-left:8px;"></i> تطبيق الفهارس وتسريع الموقع';
    if(d && d.success){
      const c = d.counts || {};
      const rows = (parseInt(c.channels)||0)+(parseInt(c.series)||0)+(parseInt(c.episodes)||0);
      const added = (d.added||[]).length;
      st.innerHTML =
        '<div style="color:'+(added?'#10b981':'var(--t3)')+'; font-weight:700;">'+
          '<i class="fas fa-'+(added?'circle-check':'circle-info')+'" style="margin-left:6px;"></i>'+esc(d.message||'')+
        '</div>'+
        '<div style="margin-top:8px; font-size:.82rem; color:var(--t3); font-variant-numeric:tabular-nums;">'+
          'القنوات: <b>'+(parseInt(c.channels)||0).toLocaleString('en-US')+'</b> · '+
          'شاشتي: <b>'+(parseInt(c.series)||0).toLocaleString('en-US')+'</b> · '+
          'الحلقات: <b>'+(parseInt(c.episodes)||0).toLocaleString('en-US')+'</b> · '+
          'الإجمالي: <b>'+rows.toLocaleString('en-US')+'</b> صف'+
        '</div>';
      if(window.toast) toast(d.message||'تم','s');
    } else {
      const msg = (d && d.error) ? d.error : 'فشل التطبيق';
      st.innerHTML = '<span style="color:#ef4444; font-weight:600;">'+esc(msg)+'</span>';
      if(window.toast) toast(msg,'e');
      console.error('xtream_optimize_db failed:', d);
    }
  }).catch(err=>{
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-bolt" style="margin-left:8px;"></i> تطبيق الفهارس وتسريع الموقع';
    st.innerHTML = '<span style="color:#ef4444; font-weight:600;">خطأ في الاتصال — راجع Console</span>';
    console.error('xtream_optimize_db error:', err);
  });
}

/* نقل الأفلام العالقة في «القنوات» إلى «شاشتي» */
function xtreamFixVod(){
  if(!confirm('نقل كل الأفلام المستوردة من قسم «إدارة القنوات» إلى «إدارة شاشتي»؟\n\nالقنوات المباشرة (ts / m3u8) لن تتأثر.')) return;
  const btn = $('xtFixVodBtn'), st = $('xtFixVodStatus');
  btn.disabled = true;
  btn.innerHTML = '<span class="sp" style="width:14px;height:14px;border-width:2px;margin-left:8px;vertical-align:middle;"></span> جارٍ النقل...';
  st.innerHTML = '<span style="color:var(--t3);">جارٍ فحص القنوات ونقل الأفلام...</span>';

  api({ajax_action:'xtream_fix_vod'}).then(d=>{
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-arrows-turn-right" style="margin-left:8px;"></i> نقل الأفلام إلى شاشتي الآن';
    if(d && d.success){
      if(d.purge_client) xtreamPurgeClient();
      const moved = parseInt(d.moved)||0;
      st.innerHTML = moved > 0
        ? '<span style="color:#10b981; font-weight:700;"><i class="fas fa-circle-check" style="margin-left:6px;"></i>'+esc(d.message||'')+'</span>'
        : '<span style="color:var(--t3);"><i class="fas fa-circle-info" style="margin-left:6px;"></i>'+esc(d.message||'لا توجد أفلام تحتاج نقلاً')+'</span>';
      if(window.toast) toast(d.message||'تم','s');
      if(typeof loadChannels==='function') loadChannels();
      if(typeof loadSeries==='function') loadSeries();
    } else {
      const msg = (d && d.error) ? d.error : 'فشل النقل';
      st.innerHTML = '<span style="color:#ef4444; font-weight:600;">'+esc(msg)+'</span>';
      if(window.toast) toast(msg,'e');
      console.error('xtream_fix_vod failed:', d);
    }
  }).catch(err=>{
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-arrows-turn-right" style="margin-left:8px;"></i> نقل الأفلام إلى شاشتي الآن';
    st.innerHTML = '<span style="color:#ef4444; font-weight:600;">خطأ في الاتصال — راجع Console</span>';
    console.error('xtream_fix_vod error:', err);
  });
}

/* ── مسح إجباري يدوي لكل ما يخص Xtream (منطقة الخطر) ── */
function xtreamPurgeAll(){
  const btn = $('xtPurgeBtn'), st = $('xtPurgeStatus'), cb = $('xtPurgeConfirm');

  // تأكيد أول
  if(!confirm('⚠️ تحذير أخير\n\nسيتم حذف كل ما يخص Xtream نهائياً من قاعدة البيانات:\n\n• كل الحسابات المضافة\n• كل القنوات المستوردة\n• كل الأفلام\n• كل المسلسلات والحلقات\n• كل الأقسام المستوردة\n\nلا يمكن التراجع عن هذه العملية إطلاقاً.\n\nهل تريد المتابعة؟')) return;

  // تأكيد ثانٍ بالكتابة
  const typed = prompt('للتأكيد النهائي، اكتب الكلمة التالية بالضبط:\n\nمسح', '');
  if(typed === null) return;
  if(typed.trim() !== 'مسح'){
    if(window.toast) toast('لم تتم كتابة كلمة التأكيد بشكل صحيح — تم الإلغاء','e');
    else alert('لم تتم كتابة كلمة التأكيد بشكل صحيح — تم الإلغاء');
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<span class="sp" style="width:15px;height:15px;border-width:2px;margin-left:8px;vertical-align:middle;"></span> جاري المسح...';
  st.innerHTML = '<span style="color:var(--t3);">جاري حذف كل محتوى Xtream، الرجاء الانتظار...</span>';

  api({ajax_action:'xtream_purge_all', confirm:'PURGE'}).then(d=>{
    btn.innerHTML = '<i class="fas fa-bomb" style="margin-left:8px;"></i> مسح كل محتوى Xtream إجبارياً';
    if(d.success){
      if(d.purge_client && window.xtreamPurgeClient) xtreamPurgeClient();
      const s = d.stats || {};
      st.innerHTML = '<div style="color:#10b981; font-weight:700; margin-bottom:8px;">'+
          '<i class="fas fa-circle-check" style="margin-left:6px;"></i>تم مسح كل محتوى Xtream بنجاح</div>'+
        '<div style="color:var(--t3); font-size:.86rem; line-height:1.9;">'+
          'الحسابات: <strong>'+(s.accounts||0)+'</strong> • '+
          'القنوات/الأفلام: <strong>'+(s.channels||0)+'</strong> • '+
          'المسلسلات: <strong>'+(s.series||0)+'</strong> • '+
          'الحلقات: <strong>'+(s.episodes||0)+'</strong> • '+
          'الأقسام: <strong>'+(s.categories||0)+'</strong>'+
          (s.cat_kept ? ' <br><span style="color:#f59e0b;">تم الإبقاء على '+s.cat_kept+' قسم لاحتوائه محتوى من مصادر أخرى</span>' : '')+
        '</div>';
      if(window.toast) toast('تم مسح كل محتوى Xtream نهائياً','s');
      cb.checked = false; btn.disabled = true;
      xtreamLoadAccounts();
    } else {
      btn.disabled = !cb.checked;
      st.innerHTML = '<span style="color:#ef4444; font-weight:600;"><i class="fas fa-circle-xmark" style="margin-left:6px;"></i>'+(d.error||'فشل المسح')+'</span>';
      if(window.toast) toast(d.error||'فشل المسح','e');
    }
  }).catch(()=>{
    btn.innerHTML = '<i class="fas fa-bomb" style="margin-left:8px;"></i> مسح كل محتوى Xtream إجبارياً';
    btn.disabled = !cb.checked;
    st.innerHTML = '<span style="color:#ef4444; font-weight:600;">خطأ في الاتصال بالسيرفر</span>';
  });
}

function xtreamEdit(id, name, host, user){
  const newName = prompt('اسم الحساب:', name||'');
  if(newName===null) return;
  const newHost = prompt('العنوان (Host):', host||'');
  if(newHost===null) return;
  const newUser = prompt('اسم المستخدم:', user||'');
  if(newUser===null) return;
  const newPass = prompt('كلمة المرور الجديدة (اتركها فارغة للإبقاء على الحالية):', '');
  if(newPass===null) return;
  api({ajax_action:'xtream_update', id:id, account_name:newName.trim(), host:newHost.trim(), username:newUser.trim(), password:newPass.trim()}).then(d=>{
    if(d && d.success){ if(window.toast) toast('تم تحديث بيانات الحساب','s'); xtreamLoadAccounts(); }
    else {
      const msg = (d && d.error) ? d.error : 'فشل التعديل (رد غير متوقع من السيرفر)';
      if(window.toast) toast(msg,'e'); else alert(msg);
      console.error('xtream_update failed:', d);
    }
  }).catch(err=>{
    if(window.toast) toast('خطأ في الاتصال أثناء التعديل — راجع Console','e');
    else alert('خطأ في الاتصال أثناء التعديل');
    console.error('xtream_update error:', err);
  });
}
/* ════════════════════ [XTREAM-JS-END] نهاية وظائف حساب Xtream ════════════════════ */

let _srAll=[],_srCurId=0,_srCurName='';
/* ── تحميل «شاشتي» على صفحات ──
   سابقاً: طلب واحد يجلب كل الأعمال (آلاف بعد استيراد أفلام Xtream) ويبني بطاقة لكل واحد.
   الآن: ٦٠ عملاً لكل دفعة + بحث على السيرفر + زر «تحميل المزيد». */
const SR_PAGE = 60;
let _srOffset = 0, _srTotal = 0, _srBusy = false, _srQ = '';

function loadSeries(reset){
  if(reset !== false) reset = true;
  if(reset){ _srOffset = 0; _srAll = []; _srTotal = 0; }
  $('epsPanel').style.display='none';
  $('srBackBtn').style.display='none';
  $('srBulkBtn').style.display='none';
  $('srBreadcrumb').style.display='none';
  $('srAddBtn').innerHTML='<i class="fas fa-plus"></i>مسلسل / فيلم جديد';
  $('srAddBtn').setAttribute('onclick',"OM('addSeriesM')");
  if(reset){ $('srGrid').style.display='none'; $('srEmpty').style.display='none'; $('srLoading').style.display='block'; }

  if(_srBusy) return;
  _srBusy = true;
  const cid = $('srCatFilter').value;
  api({ajax_action:'get_series', category_id:cid, q:_srQ, limit:SR_PAGE, offset:_srOffset})
    .then(d=>{
      _srBusy = false;
      $('srLoading').style.display='none';
      if(!d || !d.success) return;
      _srTotal  = parseInt(d.total)||0;
      _srAll    = _srAll.concat(d.data||[]);
      _srOffset = _srAll.length;
      srRender(_srAll);
    })
    .catch(err=>{
      _srBusy = false;
      $('srLoading').style.display='none';
      console.error('get_series error:', err);
      if(window.toast) toast('تعذر تحميل شاشتي','e');
    });
}

/* البحث يتم على السيرفر (لا يمكن الفلترة محلياً لأننا لا نحمّل كل شيء) */
let _srSearchTimer = null;
function srFilter(){
  clearTimeout(_srSearchTimer);
  _srSearchTimer = setTimeout(()=>{
    _srQ = $('srSearch').value.trim();
    loadSeries(true);
  }, 300);
}

function srLoadMore(){ if(!_srBusy && _srAll.length < _srTotal) loadSeries(false); }
function srRender(arr){
  const g=$('srGrid'), e=$('srEmpty');
  $('srCount').textContent = _srTotal
    ? (arr.length < _srTotal ? ('عرض '+arr.length+' من '+_srTotal.toLocaleString('en-US')) : (_srTotal.toLocaleString('en-US')+' مسلسلات/أفلام'))
    : (arr.length+' مسلسلات/أفلام');
  if(!arr.length){ g.style.display='none'; e.style.display='block'; srMoreBtn(false); return; }
  e.style.display='none'; g.style.display='grid';
  // لا نمرّر الكائن كاملاً في onclick — نمرّر المعرّف ونجلب التفاصيل عند التعديل
  g.innerHTML = arr.map(s=>`<div class="src" id="sr-${s.id}"><div class="src-poster" onclick="srOpen(${s.id},'${escA(s.name)}')">${s.poster_url?`<img src="${esc(s.poster_url)}" loading="lazy" decoding="async" onerror="this.parentElement.innerHTML='<i class=\\'fas fa-film\\'></i>'">`:'<i class="fas fa-film"></i>'}</div><div class="src-body" onclick="srOpen(${s.id},'${escA(s.name)}')"><div class="src-name">${esc(s.name)}</div><div class="src-meta"><span class="bdg bc">${esc(s.cat_name||'—')}</span><span class="bdg bp">${s.ep_count||0} فيديو</span></div></div><div class="src-acts"><button class="ib ed" onclick="srEdit(${s.id})"><i class="fas fa-pen"></i></button><button class="ib dl" onclick="srDel(${s.id},'${escA(s.name)}')"><i class="fas fa-trash"></i></button></div></div>`).join('');
  srMoreBtn(arr.length < _srTotal);
}

/* زر «تحميل المزيد» أسفل الشبكة */
function srMoreBtn(show){
  let b = $('srMoreWrap');
  if(!show){ if(b) b.style.display='none'; return; }
  if(!b){
    b = document.createElement('div');
    b.id = 'srMoreWrap';
    b.style.cssText = 'text-align:center; padding:22px 0 6px;';
    b.innerHTML = '<button class="btn btn-p" id="srMoreBtn" onclick="srLoadMore()" style="padding:11px 26px; border-radius:10px; font-weight:700;"><i class="fas fa-chevron-down" style="margin-left:8px;"></i>تحميل المزيد</button>';
    const g = $('srGrid');
    if(g && g.parentNode) g.parentNode.insertBefore(b, g.nextSibling);
  }
  b.style.display = 'block';
  const btn = $('srMoreBtn');
  if(btn){
    btn.disabled = _srBusy;
    btn.innerHTML = _srBusy
      ? '<span class="sp" style="width:14px;height:14px;border-width:2px;margin-left:8px;vertical-align:middle;"></span> جارٍ التحميل...'
      : ('<i class="fas fa-chevron-down" style="margin-left:8px;"></i>تحميل المزيد ('+(_srTotal-_srAll.length).toLocaleString('en-US')+' متبقٍ)');
  }
}
function srOpen(id,name){_srCurId=id;_srCurName=name;$('srGrid').style.display='none';$('srEmpty').style.display='none';$('srFilterBar').style.display='none';$('epsPanel').style.display='block';$('srBackBtn').style.display='';$('srBulkBtn').style.display='';$('srBreadcrumb').style.display='flex';$('srBCName').textContent=name;$('srAddBtn').style.display='none';loadEps();}
function srBack(){$('epsPanel').style.display='none';$('srBackBtn').style.display='none';$('srBulkBtn').style.display='none';$('srBreadcrumb').style.display='none';$('srFilterBar').style.display='flex';$('srAddBtn').style.display='';$('srAddBtn').innerHTML='<i class="fas fa-plus"></i>مسلسل / فيلم جديد';$('srAddBtn').setAttribute('onclick',"OM('addSeriesM')");loadSeries();}
function srAdd(){const n=$('srName').value.trim(),cid=$('srCat').value,desc=$('srDesc').value.trim(),poster=$('srPoster').value.trim();if(!n||!cid){al('srAddAlert','أدخل الاسم واختر القسم','e');return;}api({ajax_action:'add_series',name:n,category_id:cid,description:desc,poster_url:poster}).then(d=>{if(d.success){CM('addSeriesM');loadSeries();$('srName').value='';$('srCat').value='';$('srDesc').value='';$('srPoster').value='';$('srPosterThumb').style.display='none';$('srPosterStatus').innerHTML='';}else al('srAddAlert',d.error||'خطأ','e');});}
/* يستقبل الآن المعرّف فقط ويجلب التفاصيل الكاملة (الوصف غير موجود في قائمة الشبكة) */
function srEdit(idOrObj){
  const fill = (s)=>{
    $('eSrId').value=s.id; $('eSrName').value=s.name;
    $('eSrDesc').value=s.description||''; $('eSrPoster').value=s.poster_url||'';
    const sel=$('eSrCat');
    for(let o of sel.options) o.selected=(o.value===String(s.category_id));
    const thumbEl=$('eSrPosterThumb'), statusEl=$('eSrPosterStatus');
    if(s.poster_url){ thumbEl.style.display='block'; thumbEl.querySelector('img').src=s.poster_url; statusEl.innerHTML=''; }
    else { thumbEl.style.display='none'; statusEl.innerHTML=''; }
    OM('editSeriesM');
  };
  if(idOrObj && typeof idOrObj==='object'){ fill(idOrObj); return; }
  const id = parseInt(idOrObj)||0;
  if(!id) return;
  api({ajax_action:'get_series_one', id:id}).then(d=>{
    if(d && d.success && d.data) fill(d.data);
    else if(window.toast) toast((d&&d.error)||'تعذر جلب البيانات','e');
  }).catch(err=>{
    console.error('get_series_one error:', err);
    if(window.toast) toast('خطأ في الاتصال','e');
  });
}
function srEditSave(){const id=$('eSrId').value,n=$('eSrName').value.trim(),cid=$('eSrCat').value,desc=$('eSrDesc').value.trim(),poster=$('eSrPoster').value.trim();if(!n||!cid){al('eSrAlert','البيانات ناقصة','e');return;}api({ajax_action:'edit_series',id,name:n,category_id:cid,description:desc,poster_url:poster}).then(d=>{if(d.success){CM('editSeriesM');loadSeries();}else al('eSrAlert',d.error||'خطأ','e');});}
function srDel(id,name){if(!confirm(`حذف "${name}" مع جميع فيديوهاته/حلقاته؟`))return;api({ajax_action:'delete_series',id}).then(d=>{if(d.success)loadSeries();});}

let _epGlobalCache=[]; 

            function _renderEpRows() {
                const t = $('epsTbody');
                t.innerHTML = _epGlobalCache.map((e, index) => `<tr class="drag-row" draggable="true" data-index="${index}" style="cursor: grab;">
                    <td style="display:flex; align-items:center; gap:8px">
                        <i class="fas fa-grip-lines" style="color:var(--t3); font-size:1.1rem;" title="اسحبني لأي مكان للترتيب"></i>
                        <input type="checkbox" class="ep-chk" value="${e.id}" onchange="epChkCtrl()" style="width:16px;height:16px; cursor:pointer; accent-color:var(--red);">
                    </td>
                    <td><div onclick='testChannel("${escA(e.stream_url)}","${escA(e.title)}","${escA(e.subtitle_url||'')}")' style="color:var(--red);font-size:1.35rem;padding-left:4px;cursor:pointer" title="تشغيل الفيديو"><i class="fas fa-play-circle"></i></div></td>
                    <td style="color:var(--t1);font-weight:700;font-size:.87rem;">${esc(e.title)}</td>
                    <td style="font-size:.65rem;color:var(--t3);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" dir="ltr">${esc(e.stream_url.split('/').pop())}</td>
                    <td>${e.subtitle_url?'<span class="bdg bg"><i class="fas fa-closed-captioning"></i></span>':'<span style="color:var(--t3);">-</span>'}</td>
                    <td style="color:var(--t3);font-size:.75rem">${e.duration||'-'}</td>
                    <td><div class="acts">
                        <button class="ib pl" onclick='testChannel("${escA(e.stream_url)}","${escA(e.title)}","${escA(e.subtitle_url||'')}")'><i class="fas fa-play"></i></button>
                        <button class="ib ed" onclick="epEdit(_epGlobalCache.find(x => x.id === ${e.id}))"><i class="fas fa-pen"></i></button>
                        <button class="ib dl" onclick="epDel(${e.id},'${escA(e.title)}')"><i class="fas fa-trash"></i></button>
                    </div></td>
                </tr>`).join('');
                epChkCtrl();
                _setupDragAndDrop();
            }

            let _dragSrcEl = null;
            function _setupDragAndDrop() {
                const rows = document.querySelectorAll('#epsTbody tr.drag-row');
                rows.forEach(row => {
                    row.addEventListener('dragstart', function(e) {
                        _dragSrcEl = this;
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData('text/html', this.innerHTML);
                        this.style.opacity = '0.4';
                        this.style.background = 'rgba(229,9,20,.1)';
                    });
                    row.addEventListener('dragover', function(e) {
                        e.preventDefault();
                        e.dataTransfer.dropEffect = 'move';
                        return false;
                    });
                    row.addEventListener('dragenter', function(e) {
                        this.style.borderTop = '2px solid var(--red)';
                        this.style.borderBottom = '2px solid var(--red)';
                        this.style.background = 'rgba(255,255,255,.05)';
                    });
                    row.addEventListener('dragleave', function(e) {
                        this.style.borderTop = '';
                        this.style.borderBottom = '';
                        this.style.background = '';
                    });
                    row.addEventListener('drop', function(e) {
                        e.stopPropagation();
                        if (_dragSrcEl !== this) {
                            let srcIdx = parseInt(_dragSrcEl.getAttribute('data-index'));
                            let targetIdx = parseInt(this.getAttribute('data-index'));
                            
                            // تبديل وتعديل مصفوفات الداتا بناءً على الإفلات بالماوس 
                            const movedItem = _epGlobalCache.splice(srcIdx, 1)[0];
                            _epGlobalCache.splice(targetIdx, 0, movedItem);
                            
                            // تفعيل إشعار "ترتيب يدوي" لعدم خلط الاختيارات
                            $('epSortAZ').value = 'manual';
                            
                            _renderEpRows(); // ريندر لضبط الشكل المقلوب
                            _saveOrderToDB(); // تسريبه للسيرفر وتأمينه مباشرة!
                        }
                        return false;
                    });
                    row.addEventListener('dragend', function(e) {
                        this.style.opacity = '1';
                        this.style.background = '';
                        rows.forEach(r => { r.style.borderTop = ''; r.style.borderBottom = ''; r.style.background = ''; });
                    });
                });
            }

            function _saveOrderToDB() {
                const sorted_payload = _epGlobalCache.map((v, i) => ({id: v.id, order: i + 1}));
                al('epsEmpty','<span class="sp"></span> يُرسِل خريطة الأماكن للمتصلين لدمج النظام مع (index) الأمامي ..','i');
                $('epsEmpty').style.display = 'block';
                
                api({ ajax_action: 'update_episodes_order', orders: JSON.stringify(sorted_payload) }).then(res => {
                    if(res.success){ al('epsEmpty','✅ تم الحفظ واستنساخ هندستك اليدوية وتأمينها !','s'); setTimeout(()=>{ $('epsEmpty').style.display='none'; al('epsEmpty','','');}, 3000); }
                    else { al('epsEmpty', res.error, 'e'); }
                });
            }

            function _sortAndSaveEps() {
                const m = $('epSortAZ').value;
                if(m === 'manual') return; // مجرد مؤشر للتوقف في حال سحب الماوس
                
                if(m === 'az') _epGlobalCache.sort((a,b) => a.title.localeCompare(b.title));
                else if(m === 'za') _epGlobalCache.sort((a,b) => b.title.localeCompare(a.title));
                else _epGlobalCache.sort((a,b) => a.id - b.id); // تسلسل الافتراضات السحابية الأصلي
                
                _renderEpRows();
                _saveOrderToDB();
            }

            function loadEps() {
                $('epsTbody').innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;"><div class="sp"></div> تجهيز وتعديل العناصر </td></tr>';
                $('epsEmpty').style.display = 'none';
                api({ajax_action:'get_episodes',series_id:_srCurId}).then(d=>{
                    _epGlobalCache = d.data || [];
                    $('srBCCount').textContent = _epGlobalCache.length + ' إمتـداد محجـوز';
                    $('epSortAZ').value = 'def'; // مبدأ الافتراضي الزمني مع أول تحميل 
                    
                    if(!_epGlobalCache.length) { $('epsTbody').innerHTML=''; $('epsEmpty').style.display='block'; return;}
                    $('epsEmpty').style.display = 'none';
                    _renderEpRows();
                });
            }
            
            // خواص التشيك والحذف العنيف (Bulk Options)
            function toggleChkEps(me){
                document.querySelectorAll('.ep-chk').forEach(c => c.checked = me.checked);
                epChkCtrl();
            }
            
           function epChkCtrl() {
    const checked = document.querySelectorAll('.ep-chk:checked').length;
    const dbtn = $('delBulkBtn');
    const cbtn = $('convertMp4Btn');
    if(checked > 0){ 
        dbtn.style.display = 'inline-flex'; 
        dbtn.innerHTML = `<i class="fas fa-trash-alt"></i> نسف ( ${checked} )`; 
        if(cbtn) {
            cbtn.style.display = 'inline-flex'; 
            cbtn.innerHTML = `<i class="fas fa-magic"></i> ذكي MP4 ( ${checked} )`; 
        }
    } else { 
        dbtn.style.display = 'none'; 
        if(cbtn) cbtn.style.display = 'none'; 
        $('chkEpsMaster').checked = false;
    }
}

// ── الوظيفة التفاعلية الجديدة للزر ──
function convertCheckedEpsToMp4() {
    let targets = [];
    document.querySelectorAll('.ep-chk:checked').forEach(c => targets.push(c.value));
    if(!targets.length) return;
    
    if(!confirm(`سيتم تجميد مسار الـ TS واستبداله بشكل كامل إلى صيغة MP4 فائقة السرعة للملفات المختارة (${targets.length}) ملف. موافق؟`)) return;
    
    const cbtn = $('convertMp4Btn');
    cbtn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> السيرفر يعمل بقوة، الرجاء الانتظار...`; 
    cbtn.disabled = true;
    $('delBulkBtn').disabled = true;
    
    api({ ajax_action: 'convert_to_mp4_bulk', ids: JSON.stringify(targets) }).then(x => {
        cbtn.disabled = false;
        $('delBulkBtn').disabled = false;
        
        if(x.success) {
             let msg = '';
             if (x.success_count > 0) {
                 msg += `✅ عبقرية! تم تبديل وضغط وإزالة ( ${x.success_count} ) ملف إلى نظام MP4 المعتمد، وجاهز للبث.\n\n`;
             }
             if (x.failed_count > 0) {
                 msg += `⚠️ عذراً، تم إسقاط عملية التحويل لـ ( ${x.failed_count} ) مسار!\n\n`;
                 // هنا مربط الفرس: نطبع الخطأ المصدري من البي أتش بي مباشرة لتراه أمام عينيك:
                 if (x.debug) {
                     msg += `تفاصيل كشف النظام للسبب:\n=================\n${x.debug}`;
                 } else {
                     msg += "فشلت الأوامر لأسباب تقنية تخص بايثون أو Xampp.";
                 }
             }
             
             alert(msg);
             $('chkEpsMaster').checked = false;
             loadEps(); // سحب بيانات المجلد بعد هندسته لتجد كل شيء تغير بصيغته أمامت.
             
        } else { 
            alert('❌ رُفضت الإعدادات الخادمة: ' + (x.error || 'عقدة انصات')); 
        }
        cbtn.innerHTML = `<i class="fas fa-magic"></i> التحويل السريع لـ MP4`;
    }).catch(() => {
        alert("انقطع اتصالك بالمتصفح ولكن الأباتشي مستمر بالحرق من خلف الكواليس. أعمل تحديث للمتصفح لترا النتائج لاحقاً.");
        cbtn.innerHTML = `<i class="fas fa-magic"></i> التحويل السريع لـ MP4`;
        cbtn.disabled = false;
    });
}
        
    function deleteCheckedEps() {
                let targets = [];
                document.querySelectorAll('.ep-chk:checked').forEach(c => targets.push(c.value));
                if(!targets.length) return;
                
                if(!confirm(`⚠️ خطـــــر: أنت تُصدّق مسح ${targets.length} فيلم / مسار من جذوره الفعلية؟ ( لن يرجع أثره! )`)) return;
                
                const dbtn = $('delBulkBtn');
                dbtn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> الحفر والاستئصال..`; dbtn.disabled = true;
                
                api({ ajax_action: 'delete_episodes_bulk', ids: JSON.stringify(targets) }).then(x => {
                    dbtn.disabled = false;
                    if(x.success) {
                         alert('✅ عملية مُطهِّرة تمت باحتراف. تم الاستئصال تماماً للمساحات المختارة!');
                         $('chkEpsMaster').checked = false;
                         loadEps();
                    } else { alert('انفلات جزئي أو فشل المسار المعمّق'); }
                });
            }
            
function etab(t){document.querySelectorAll('#addEpM .etab').forEach(b=>b.classList.remove('on'));event.target.classList.add('on');$('etab-url').style.display=t==='url'?'':'none';$('etab-file').style.display=t==='file'?'':'none';}
let _epSubUpUrl='',_epFileUpUrl='';
function epFileUpload(inp){const f=inp.files[0];if(!f)return;const fd=new FormData();fd.append('ajax_action','upload_episode_video');fd.append('episode',f);fd.append('series_id',_srCurId);$('epFilePBar').style.width='0%';$('epFileProgress').style.display='block';$('epFileChip').style.display='none';const xhr=new XMLHttpRequest();xhr.upload.onprogress=e=>{if(e.lengthComputable){const p=Math.round(e.loaded/e.total*100);$('epFilePBar').style.width=p+'%';}};xhr.onload=()=>{$('epFileProgress').style.display='none';try{const d=JSON.parse(xhr.responseText);if(d.success){_epFileUpUrl=d.url;$('epUploadedUrl').value=d.url;$('epFileChip').style.display='flex';$('epFileChipName').textContent=d.original;$('epNum').value=d.episode_number||1;if(!$('epTitle').value.trim())$('epTitle').value=(d.original).replace(/\.[^.]+$/,'');}else al('addEpAlert',d.error||'خطأ في الرفع','e');}catch(e){al('addEpAlert','خطأ في الاستجابة','e');}};xhr.onerror=()=>{$('epFileProgress').style.display='none';al('addEpAlert','انقطع الاتصال','e');};xhr.open('POST',location.href);xhr.send(fd);}
function epSubUpload(inp){const f=inp.files[0];if(!f)return;const fd=new FormData();fd.append('ajax_action','upload_episode_subtitle');fd.append('subtitle',f);fd.append('series_id',_srCurId);fetch(location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success){_epSubUpUrl=d.url;$('epSubUrl').value=d.url;$('epSubChip').style.display='flex';$('epSubChipName').textContent=f.name;}else al('addEpAlert',d.error||'خطأ','e');});}
function epAdd(){const num=parseInt($('epNum').value)||1;const title=$('epTitle').value.trim()||'الحلقة '+num;const urlTab=$('etab-url').style.display!=='none';let url=urlTab?$('epUrl').value.trim():($('epUploadedUrl').value.trim());const sub=$('epSubUrl').value.trim()||_epSubUpUrl;const dur=$('epDur').value.trim();if(!url){al('addEpAlert','أدخل رابط الفيديو أو ارفع ملفاً','e');return;}api({ajax_action:'add_episode',series_id:_srCurId,episode_number:num,title,stream_url:url,subtitle_url:sub,duration:dur}).then(d=>{if(d.success){CM('addEpM');loadEps();$('epNum').value=parseInt($('epNum').value)+1;$('epTitle').value='';$('epUrl').value='';$('epSubUrl').value='';$('epDur').value='';$('epUploadedUrl').value='';$('epFileChip').style.display='none';$('epSubChip').style.display='none';_epSubUpUrl='';_epFileUpUrl='';}else al('addEpAlert',d.error||'خطأ','e');});}

function eEpSubUpload(inp){const f=inp.files[0];if(!f)return;const fd=new FormData();fd.append('ajax_action','upload_subtitle_file');fd.append('subtitle',f);$('eEpSubStatus').innerHTML='<span style="color:var(--gold)"><i class="fas fa-spinner fa-spin"></i> جارٍ الرفع...</span>';fetch(location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success){$('eEpSub').value=d.vtt_url||d.url;$('eEpSubStatus').innerHTML='<span style="color:#00D084"><i class="fas fa-check-circle"></i> تم رفع الترجمة بنجاح</span>';}else{$('eEpSubStatus').innerHTML='<span style="color:#ff6b6b"><i class="fas fa-exclamation-triangle"></i> '+d.error+'</span>';}}).catch(()=>{if(window.al)al('eEpAlert','خطأ في الاتصال','e');});}
function eEpOpenOS(){$('eEpOsQ').value=$('eEpTitle').value.trim();$('eEpOsRes').style.display='none';$('eEpOsAl').innerHTML='';OM('eEpOsM');}
function eEpOsSearch(){const q=$('eEpOsQ').value.trim(),lang=$('eEpOsLang').value;if(!q){$('eEpOsAl').innerHTML='<span style="color:#ff6b6b">أدخل اسم الفيلم</span>';return;}$('eEpOsSearchBtn').disabled=true;$('eEpOsSearchBtn').innerHTML='...';$('eEpOsRes').style.display='block';$('eEpOsRes').innerHTML='<div style="padding:14px;color:var(--t3);text-align:center"><i class="fas fa-spinner fa-spin"></i> جارٍ البحث...</div>';api({ajax_action:'search_subtitles',query:q,language:lang}).then(d=>{$('eEpOsSearchBtn').disabled=false;$('eEpOsSearchBtn').innerHTML='<i class="fas fa-search"></i> بحث';if(!d.success){$('eEpOsRes').innerHTML='<div style="padding:14px;color:#ff6b6b;text-align:center">'+(d.error||'لا توجد نتائج')+'</div>';return;}$('eEpOsRes').innerHTML=d.data.map((s,i)=>`<div class="sri" onclick="eEpDlSub(${s.file_id})"><div class="sri-main"><div class="sri-title">${s.title.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</div><div class="sri-meta"><span>${s.language.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span><span>${s.downloads} تنزيل</span></div></div><button class="btn btn-g bsm"><i class="fas fa-download"></i></button></div>`).join('');});}
function eEpDlSub(fid){$('eEpOsAl').innerHTML='<span style="color:var(--gold)"><i class="fas fa-spinner fa-spin"></i> جارٍ التنزيل...</span>';api({ajax_action:'download_subtitle',file_id:fid}).then(d=>{if(!d.success){$('eEpOsAl').innerHTML='<span style="color:#ff6b6b">'+(d.error||'خطأ')+'</span>';return;}$('eEpSub').value=d.vtt_url||d.url;$('eEpSubStatus').innerHTML='<span style="color:#00D084"><i class="fas fa-check-circle"></i> تم جلب الترجمة من OS</span>';CM('eEpOsM');});}

function epEdit(e){
    $('eEpId').value=e.id;
    $('eEpNum').value=e.episode_number;
    $('eEpTitle').value=e.title;
    $('eEpUrl').value=e.stream_url;
    $('eEpSub').value=e.subtitle_url||'';
    $('eEpDur').value=e.duration||'';
    
    let folderOpts = '';
    if (typeof _allFoldersGlobal !== 'undefined') {
        _allFoldersGlobal.forEach(folder => {
            let isSelected = (folder.id == e.series_id) ? 'selected' : '';
            folderOpts += `<option value="${folder.id}" ${isSelected}>${esc(folder.name)}</option>`;
        });
    }
    $('eEpSeriesId').innerHTML = folderOpts;
    OM('editEpM');
}

function epEditSave(){
    const id=$('eEpId').value,
          num=parseInt($('eEpNum').value)||1,
          title=$('eEpTitle').value.trim(),
          url=$('eEpUrl').value.trim(),
          sub=$('eEpSub').value.trim(),
          dur=$('eEpDur').value.trim(),
          newSeriesId=$('eEpSeriesId').value;
          
    if(!title||!url){al('eEpAlert','البيانات ناقصة','e');return;}
    
    api({
        ajax_action: 'edit_episode',
        id: id,
        episode_number: num,
        title: title,
        stream_url: url,
        subtitle_url: sub,
        duration: dur,
        series_id: newSeriesId
    }).then(d=>{
        if(d.success){
            CM('editEpM');
            if (newSeriesId != _srCurId) { alert("✅ تم سحب ونقل هذا الملف إلى المسلسل الآخر ببراعة!"); }
            loadEps();
        } else al('eEpAlert', d.error||'خطأ','e');
    });
}

function epDel(id,name){if(!confirm(`حذف الفيديو/الحلقة "${name}"؟`))return;api({ajax_action:'delete_episode',id}).then(d=>{if(d.success)loadEps();});}

let _bulkFiles=[];
function bulkPreview(files){_bulkFiles=Array.from(files);if(!_bulkFiles.length)return;$('bulkStartBtn').style.display='';$('bulkPreviewList').style.display='block';$('bulkPreviewTitle').textContent=_bulkFiles.length+' مسار للملفات جاهز';const totalSz=_bulkFiles.reduce((s,f)=>s+f.size,0);$('bulkTotalSize').textContent=fmtSz(totalSz);$('bulkItems').innerHTML=_bulkFiles.map((f,i)=>{return`<div class="ep-item" id="bitem-${i}"><div style="width:28px;height:28px;border-radius:50%;background:var(--s3);color:var(--red);display:flex;align-items:center;justify-content:center;font-size:.7rem"><i class="fas fa-play-circle"></i></div><div style="flex:1;min-width:0;font-size:.8rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:bold">${esc(f.name.replace(/\.[^.]+$/, ''))}</div><span style="font-size:.72rem;color:var(--t3)">${fmtSz(f.size)}</span><span class="ep-stat" id="bstat-${i}">تأهب للرفع..</span></div>`;}).join('');}
async function bulkUpload(){if(!_bulkFiles.length||!_srCurId){al('bulkAlert','تأكد من اختيار الملفات','e');return;}$('bulkStartBtn').disabled=true;$('bulkProgress').style.display='block';let done=0,errs=0;for(let i=0;i<_bulkFiles.length;i++){const f=_bulkFiles[i];$('bstat-'+i).textContent='في العملية..';$('bstat-'+i).className='ep-stat up';let vidName=(f.name).replace(/\.[^.]+$/,'');$('bulkCurFile').innerHTML=`<b style="color:var(--t1)">${esc(vidName)}</b>`;const fd=new FormData();fd.append('ajax_action','upload_episode_video');fd.append('episode',f);fd.append('series_id',_srCurId);try{const d=await new Promise((res,rej)=>{const x=new XMLHttpRequest();let t0=Date.now(), l0=0; x.upload.onprogress=(e)=>{if(e.lengthComputable){const p=Math.round((e.loaded/e.total)*100);$('bulkPBar').style.width=p+'%';let t1=Date.now(),dt=(t1-t0)/1000;let sp='جاري التوزيع الشبكي..';if(dt>=0.65){sp=fmtSz((e.loaded-l0)/dt)+'/ث';t0=t1;l0=e.loaded;} $('bulkProgPct').innerHTML=`<span style="color:#00D084" dir="ltr">⏳ ${sp}</span> &nbsp; <b>${p}%</b>`;}};x.onload=()=>{try{res(JSON.parse(x.responseText));}catch(err){rej();}};x.onerror=()=>rej();x.open('POST',location.href);x.send(fd);});if(d.success){const title=(d.original||f.name).replace(/\.[^.]+$/,'');const d2=await api({ajax_action:'add_episode',series_id:_srCurId,episode_number:(i+1),title:title,stream_url:d.url,subtitle_url:'',duration:''});if(d2.success){done++;$('bstat-'+i).textContent='✅ دُمج واكتمل';$('bstat-'+i).className='ep-stat ok';}else{errs++;$('bstat-'+i).textContent='❌ صُد بخادم الداتا';$('bstat-'+i).className='ep-stat err';}}else{errs++;$('bstat-'+i).textContent='❌ رُفض الرفع كلياً';$('bstat-'+i).className='ep-stat err';}}catch(e){errs++;$('bstat-'+i).textContent='❌ فُصل من العوامل';$('bstat-'+i).className='ep-stat err';}}$('bulkPBar').style.width='100%';$('bulkProgPct').textContent='100%';$('bulkCurFile').textContent='';$('bulkResult').style.display='block';$('bulkResult').innerHTML=`<div class="al ${errs?'al-e':'al-s'}" style="margin:0"><i class="fas fa-${errs?'exclamation-circle':'check-circle'}"></i> خلاصة النتائج الحسابية: تم تأمين وتسجيل ( ${done} ) ملفات خاضعة للاستدامة، وسُقط (${errs}).</div>`;$('bulkStartBtn').disabled=false;_bulkFiles=[];if(_srCurId)loadEps();}

let VID={file:null,filename:'',url:'',subFile:'',subUrl:'',subVttUrl:'',opt:'none'};
let smartDlInterval = null;
let currentSmartDlFile = '';

function vtab(t){
    document.querySelectorAll('#vp1 .etab').forEach(b=>b.classList.remove('on'));
    event.target.classList.add('on');
    $('vtab-url').style.display = t==='url'?'':'none';
    $('vtab-file').style.display = t==='file'?'':'none';
}

function vidSmartDl(){
    const url = $('smartUrlInp').value.trim();
    if(!url){ al('v1alert','أدخل رابط مباشر صالح','e'); return; }

    const btn = $('smartDlBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="sp"></span> جاري تهيئة الاتصال بالرابط...';

    $('vidProg').style.display = 'block';
    $('vidPBar').style.width = '0%';
    $('vidPBar').style.animation = 'none'; 
    $('vidPct').textContent = '0%';
    $('vidPLabel').textContent = 'جاري سحب خصائص الرابط...';
    $('cancelDlBtn').style.display = 'none';
    $('vidProgSp').style.display = 'inline-block';
    $('vidChip').style.display = 'none';
    $('vNext1').disabled = true;
    al('v1alert', '', '');

    // إرسال طلب تجهيز الاستيراد الذكي أولاً 
    api({ajax_action:'prep_smart_dl', url: url}).then(initData => {
        if(!initData.success) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-download"></i> محاولة سحب الرابط مجدداً';
            $('vidProg').style.display = 'none';
            al('v1alert', initData.error || 'عذراً الرابط يرفض الاتصال، قم بتجربة رابط آخر مباشر!', 'e');
            return;
        }

        const fname = initData.filename;
        const originalName = initData.original;
        let expectedTotalSize = initData.total || 0;
        
        currentSmartDlFile = fname;
        $('cancelDlBtn').style.display = 'inline-block';
        $('cancelDlBtn').onclick = () => cancelSmartDl(fname);
        btn.innerHTML = '<span class="sp"></span> السيرفر يسحب الرابط بأقصى سرعته!';

        let lastLoaded = 0; let lastTime = performance.now();

        // نبض الاستعلام الحي لتوضيح حالة التحميل على الشاشة
        smartDlInterval = setInterval(() => {
            api({ajax_action: 'check_smart_dl', filename: fname}).then(pd => {
                if(pd.success && typeof pd.loaded !== 'undefined') {
                    let curLoaded = pd.loaded || 0; let tot = pd.total || expectedTotalSize;
                    let nowTime = performance.now(); let timeDiff = (nowTime - lastTime) / 1000; 
                    let loadedDiff = curLoaded - lastLoaded; 
                    
                    let speedTxt = "جاري الحساب";
                    if(timeDiff > 0 && loadedDiff > 0) { speedTxt = fmtSz(loadedDiff / timeDiff) + '/ث'; }
                    lastLoaded = curLoaded; lastTime = nowTime;

                    let pct = 0;
                    if(tot > 0) {
                        pct = Math.round((curLoaded / tot) * 100);
                        if(pct > 100) pct = 100;
                        $('vidPLabel').innerHTML = `<span style="color:#00D084;font-weight:bold;margin-left:8px;" dir="ltr">[ سرعة السيرفر: ${speedTxt} ]</span> <span dir="ltr">${fmtSz(curLoaded)} / ${fmtSz(tot)}</span>`;
                        $('vidPBar').style.width = pct + '%';
                        $('vidPct').textContent = pct + '%';
                    } else {
                        // الحجم غير معلن (رابط مُشفر لكنه يعمل) يتم اظهار انه يسحب فقط
                        $('vidPBar').style.width = '100%';
                        $('vidPBar').style.animation = 'bk 1.5s ease infinite'; 
                        $('vidPLabel').innerHTML = `<span style="color:#00D084;font-weight:bold;margin-left:8px;" dir="ltr">[ سرعة السيرفر: ${speedTxt} ]</span> <span dir="ltr">سحب إلى الان: ${fmtSz(curLoaded)}</span>`;
                        $('vidPct').textContent = 'جارٍ...';
                    }
                }
            }).catch(()=>{}); // منع تدمير المتصفح من الاخطاء الدورية 
        }, 1500);

        // هنا السحب الرئيسي بالخلفية
        api({ajax_action:'do_smart_dl', url: url, filename: fname}).then(d => {
            clearInterval(smartDlInterval);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-download"></i>سحب فيلم آخر جديد';
            $('vidProg').style.display = 'none';
            $('cancelDlBtn').style.display = 'none';

            if(d.success) {
                VID.filename = d.filename; VID.url = d.url; VID.file = null;
                $('vidChip').style.display = 'flex'; $('vidChipName').textContent = originalName;
                $('vidChipSize').textContent = fmtSz(d.size); $('vNext1').disabled = false;
                const title = originalName.replace(/\.[^.]+$/,'').replace(/[._\-]/g,' ').replace(/\b(720p|1080p|4k|bdrip|web|hdtv|bluray)\b/gi,'').trim();
                $('osQ').value = title; $('vChanName').value = title;
                al('v1alert', '🚀 انتهى الحفظ تماماً وأصبح الملف في قلب خوادمك!', 's');
                $('smartUrlInp').value = '';
            } else { al('v1alert', d.error || 'لقد أمرت النظام بوقف التحميل أو توقف المزوّد.', 'e'); }
        }).catch(err =>{
             clearInterval(smartDlInterval); btn.disabled = false; btn.innerHTML = '<i class="fas fa-download"></i>حاول مرة اخرى';
             $('vidProg').style.display='none'; $('cancelDlBtn').style.display = 'none';
             al('v1alert','انتهت مهلة المراقبة في المتصفح، ولكن التحميل الفعلي قد يكون شغال خلف الكواليس داخل إدارة الفيديوهات.', 'i');
        });
    });
}

function cancelSmartDl(fname) {
    if(!confirm('سيتسبب هذا بقطع تدفق السحب الخارجي وحذف بقاياه. متابعة؟')) return;
    $('cancelDlBtn').disabled = true;
    $('cancelDlBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> أبلغنا السيرفر.. يتم الإعدام!';
    api({ajax_action: 'abort_smart_dl', filename: fname}); // إلقاء إشارة الإيقاف القسرية للمتغير
}

function vidUpload(inp){const f=inp.files[0];if(!f)return;const fd=new FormData();fd.append('ajax_action','upload_video');fd.append('video',f);$('vidProg').style.display='block';$('cancelDlBtn').style.display='none';$('vidProgSp').style.display='inline-block';$('vidPBar').style.animation='none';$('vidChip').style.display='none';const xhr=new XMLHttpRequest();xhr.upload.onprogress=e=>{if(e.lengthComputable){const p=Math.round(e.loaded/e.total*100);$('vidPBar').style.width=p+'%';$('vidPct').textContent=p+'%';$('vidPLabel').textContent=p<100?'رفع '+fmtSz(e.loaded)+' / '+fmtSz(f.size):'معالجة…';}};xhr.onload=()=>{$('vidProg').style.display='none';const raw=xhr.responseText.trim();if(!raw){al('v1alert','الخادم لم يُرجع رداً — تحقق من إعدادات PHP','e');return;}let d;try{d=JSON.parse(raw);}catch(ex){const preview=raw.replace(/<[^>]+>/g,'').substring(0,300);al('v1alert','خطأ في الاستجابة: '+preview,'e');return;}if(d.success){VID.filename=d.filename;VID.url=d.url;VID.file=f;$('vidChip').style.display='flex';$('vidChipName').textContent=d.original;$('vidChipSize').textContent=fmtSz(f.size);$('vNext1').disabled=false;const title=d.original.replace(/\.[^.]+$/,'').replace(/[._\-]/g,' ').replace(/\b(720p|1080p|4k|bdrip|web|hdtv|bluray)\b/gi,'').trim();$('osQ').value=title;$('vChanName').value=title;al('v1alert','✅ تم رفع الفيديو بنجاح','s');}else{let msg=d.error||'خطأ غير معروف';if(d.debug)msg+=' — '+d.debug;al('v1alert',msg,'e');}};xhr.onerror=()=>{$('vidProg').style.display='none';al('v1alert','انقطع الاتصال بالخادم','e');};xhr.open('POST',location.href);xhr.send(fd);}
function vidDebug(){api({ajax_action:'debug_upload'}).then(d=>{const dbg=$('v1debug');dbg.style.display='block';if(d.success){const ok='✅',no='❌';dbg.innerHTML=`<strong>إعدادات PHP:</strong><br>upload_max_filesize: <b>${d.upload_max_filesize}</b><br>post_max_size: <b>${d.post_max_size}</b><br>مجلد الرفع: <b>${d.upload_dir}</b><br>المجلد موجود: ${d.dir_exists?ok:no}<br>قابل للكتابة: ${d.dir_writable?ok:no}<br>PHP: ${d.php_version}<br><br><small style="color:var(--t3)">إذا كانت القيم 8M أو أقل، أضف للـ .htaccess:<br>php_value upload_max_filesize 2048M<br>php_value post_max_size 2048M</small>`;}else dbg.innerHTML='خطأ: '+d.error;});}
function vidReset(){VID={file:null,filename:'',url:'',subFile:'',subUrl:'',subVttUrl:'',opt:'none'};$('vidChip').style.display='none';$('vidFileIn').value='';$('vNext1').disabled=true;al('v1alert','','');}
function vidGo(step){if(step===3){$('mSumV').textContent=VID.filename||'—';$('mSumS').textContent=VID.subFile?(VID.subFile+' ✅'):'بدون ترجمة';}document.querySelectorAll('.vp').forEach(p=>p.classList.remove('act'));document.querySelectorAll('.vs').forEach(v=>v.classList.remove('act'));$('vp'+step).classList.add('act');$('vs'+step).classList.add('act');for(let i=1;i<step;i++)$('vs'+i).classList.add('done');}
function vidSubOpt(opt){VID.opt=opt;document.querySelectorAll('.so').forEach(s=>s.classList.remove('sel'));$('so-'+opt).classList.add('sel');$('osCard').style.display=opt==='search'?'block':'none';$('subUpCard').style.display=opt==='upload'?'block':'none';}
function subFileUpload(inp){const f=inp.files[0];if(!f)return;const fd=new FormData();fd.append('ajax_action','upload_subtitle_file');fd.append('subtitle',f);al('subAl','<span class="sp"></span> جارٍ الرفع…','i');fetch(location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success){VID.subFile=d.filename;VID.subUrl=d.url;$('upSubChip').style.display='flex';$('upSubName').textContent=f.name;al('subAl','✅ تم','s');}else al('subAl',d.error||'خطأ','e');});}

function osLogin(){
    const u=$('osU').value.trim(),p=$('osP').value.trim(),k=$('osApiKey').value.trim();
    if(!u||!p){al('osLAlert','أدخل اسم المستخدم وكلمة المرور','e');return;}
    if(!k){al('osLAlert','أدخل مفتاح API','e');return;}
    $('osLBtn').disabled=true;$('osLBtn').innerHTML='<span class="sp"></span>';
    api({ajax_action:'os_login',username:u,password:p,api_key:k}).then(d=>{
        $('osLBtn').disabled=false;$('osLBtn').innerHTML='<i class="fas fa-sign-in-alt"></i>تسجيل الدخول';
        if(d.success){
            $('osNL').style.display='none';$('osL').style.display='flex';$('osLUser').textContent=d.username;
        }else al('osLAlert',d.error||'خطأ','e');
    });
}
function osLogout(){
    api({ajax_action:'os_logout'}).then(()=>{
        $('osNL').style.display='block';$('osL').style.display='none';$('osRes').innerHTML='';$('osLUser').textContent='';
    });
}

function osSearch(){const q=$('osQ').value.trim(),lang=$('osLang').value;if(!q){al('osAl','أدخل اسم الفيلم','e');return;}$('osSearchBtn').disabled=true;$('osSearchBtn').innerHTML='<span class="sp"></span>';$('osRes').style.display='flex';$('osRes').innerHTML=`<div style="padding:14px;color:var(--t3);text-align:center"><span class="sp"></span> جارٍ البحث…</div>`;al('osAl','','');api({ajax_action:'search_subtitles',query:q,language:lang}).then(d=>{$('osSearchBtn').disabled=false;$('osSearchBtn').innerHTML='<i class="fas fa-search"></i>بحث';if(!d.success){al('osAl',d.error||'لا توجد نتائج','e');$('osRes').style.display='none';return;}$('osRes').innerHTML=d.data.map((s,i)=>`<div class="sri" id="sri-${i}" onclick="srClick(${i},${s.file_id},'${escA(s.filename)}')"><div class="sri-main"><div class="sri-title">${esc(s.title)} ${s.year?`(${s.year})`:''}</div><div class="sri-meta"><span>${esc(s.release||'')}</span><span class="stag stag-l">${esc(s.language)}</span><span>${s.downloads} تنزيل</span></div></div><button class="btn btn-g bsm" onclick="event.stopPropagation();dlSub(${s.file_id},'${escA(s.filename)}')"><i class="fas fa-download"></i></button></div>`).join('');});}
function srClick(i,fid,fname){document.querySelectorAll('.sri').forEach(s=>s.classList.remove('sel'));$('sri-'+i)&&$('sri-'+i).classList.add('sel');dlSub(fid,fname);}
function dlSub(fid,fname){al('osAl','<span class="sp"></span> جارٍ تنزيل الترجمة…','i');api({ajax_action:'download_subtitle',file_id:fid}).then(d=>{if(!d.success){al('osAl',d.error||'خطأ','e');return;}VID.subFile=d.filename;VID.subUrl=d.url;VID.subVttUrl=d.vtt_url||d.url;$('selSubChip').style.display='flex';$('selSubName').textContent=fname;al('osAl','✅ تم تنزيل الترجمة — باقي '+d.remaining+' تنزيل اليوم','s');});}
function clearSub(){VID.subFile='';VID.subUrl='';VID.subVttUrl='';$('selSubChip').style.display='none';}

function vidSave(){
    const name=$('vChanName').value.trim();
    const cid=$('vChanCat').value;
    const targetId=$('vTargetSeries').value; 
    
    if(targetId == "0" && !cid){ al('v3alert','يُرجى إختيار أي قسم ليتأسس العمل فيه.','e');return;}
    if(!name && targetId == "0"){ al('v3alert','ما هو أسم فيلمك؟ أدخله بوضوح.','e');return;}
    if(!name && targetId > "0"){ $('vChanName').value = 'عنصر / حلقة تابعه للمسلسل المختار'; }

    if(!VID.url){al('v3alert','ألم ترفع أي فيديو إلى الان! عُد لليمين للخطوات.','e');return;}
    const btn=document.querySelector('#vp3 .btn-s');
    if(btn){btn.disabled=true;btn.innerHTML='<span class="sp"></span> أرقام القاعدة تقيّد إعداداتك حالياً...';}
    
    if(VID.subFile){
        al('v3alert','<span class="sp"></span> المبرمج يدمج سطورك لملفك، انتظر للحظة…','i');
        api({ajax_action:'merge_subtitle',video_file:VID.filename,subtitle_file:VID.subFile}).then(d=>{
            if(!d.success){
                al('v3alert',d.error||'خطأ بملف الترجمة','e');
                if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i>حاول إصلاحه وانقره';} return;
            }
            api({
               ajax_action:'save_to_shashety_auto', 
               category_id: cid, name:$('vChanName').value.trim(), 
               url:VID.url, subtitle_url:(d.method==='no_ffmpeg')?(d.subtitle_url||VID.subUrl):'',
               target_series_id: targetId 
            }).then(d2=>{
                if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> حفظ جديد لمكتتبك';}
                if(d2.success){
                    $('vidResult').style.display='block';
                    $('vidResultInfo').innerHTML= (targetId=="0") ? 'الفيلم الان حر طليق بعمل جديد وخاص.' : 'طاعة المطور اكتملت واصطُف بجوار بقية اخوانه للمسلسل المحدد!';
                    al('v3alert','','');
                }else al('v3alert',d2.error||'انقطعت شاشتك بالقواعد المبرمجة','e');
            });
        });
    }else{
        api({
           ajax_action:'save_to_shashety_auto',
           category_id:cid, name:$('vChanName').value.trim(), url:VID.url, subtitle_url:'',
           target_series_id: targetId 
        }).then(d=>{
            if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> تسجيل ملفه وإرسائه.';}
            if(d.success){
                $('vidResult').style.display='block';
                $('vidResultInfo').innerHTML= (targetId=="0") ? 'سيبدأ الجمهور رؤية فيلمك، شاهده بادارة شاشتي.' : 'أضيفت الحلقة في شاشتك بنجاح لتشكيلات مسلسلك المطلوب.';
                al('v3alert','','');
            }else al('v3alert',d.error||'حدث امر خارجي بمنظومات الخوادم','e');
        });
    }
}

// ══ SERIES POSTER UPLOAD ══
function srPosterUpload(inp,urlInputId,thumbId,statusId){const f=inp.files[0];if(!f)return;const statusEl=$(statusId),thumbEl=$(thumbId);statusEl.innerHTML='<span class="sp"></span> <span style="color:var(--t2)">جارٍ رفع الصورة…</span>';const fd=new FormData();fd.append('ajax_action','upload_series_poster');fd.append('poster',f);const xhr=new XMLHttpRequest();xhr.upload.onprogress=e=>{if(e.lengthComputable){const p=Math.round(e.loaded/e.total*100);statusEl.innerHTML=`<span class="sp"></span> <span style="color:var(--gold)">${p}%</span>`;}};xhr.onload=()=>{try{const d=JSON.parse(xhr.responseText);if(d.success){$(urlInputId).value=d.url;statusEl.innerHTML=`<span style="color:#00D084"><i class="fas fa-check-circle"></i> تم رفع الصورة بنجاح — ${fmtSz(d.size)}</span>`;thumbEl.style.display='block';thumbEl.querySelector('img').src=d.url;thumbEl.querySelector('img').style.borderColor='#00D084';}else statusEl.innerHTML=`<span style="color:#ff6b6b"><i class="fas fa-exclamation-circle"></i> ${d.error||'خطأ في الرفع'}</span>`;}catch(e){statusEl.innerHTML=`<span style="color:#ff6b6b"><i class="fas fa-exclamation-circle"></i> خطأ في الاستجابة</span>`;}inp.value='';};xhr.onerror=()=>{statusEl.innerHTML=`<span style="color:#ff6b6b"><i class="fas fa-exclamation-circle"></i> انقطع الاتصال</span>`;};xhr.open('POST',location.href);xhr.send(fd);}
function srPosterPreview(thumbId,url){const thumbEl=$(thumbId);if(!url||!url.startsWith('http')){thumbEl.style.display='none';return;}thumbEl.style.display='block';const img=thumbEl.querySelector('img');img.src=url;img.onerror=()=>{thumbEl.style.display='none';};}

// ══ VIDEO MANAGE (With isolated moves directly interacting Shashety vs Public Videos without transferring dir variables logic directly mapping physical files internally easily managed visually by CSS filtering.)
let _vmAll=[],_vmCtx={},_vmMoveCtx={};

function vmTriggerSub(fn, type){
    _vmCtx = {fn, type};
    $('vmSubUp').click();
}

function vmHandleSubUp(inp){
    const f=inp.files[0]; if(!f) return;
    al('vmLoad','<span class="sp"></span> جارٍ رفع الترجمة وتجهيزها...','i'); 
    $('vmLoad').style.display='block';
    const fd=new FormData(); fd.append('ajax_action','upload_subtitle_file'); fd.append('subtitle',f);
    fetch(location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        $('vmLoad').style.display='none';
        inp.value='';
        if(d.success) vmOpenSave(_vmCtx.fn, _vmCtx.type, d.url);
        else alert('خطأ في الترجمة السريعة: ' + (d.error||''));
    });
}

function vmLoad(){$('vmGrid').style.display='none';$('vmEmpty').style.display='none';$('vmLoad').style.display='block';api({ajax_action:'list_videos'}).then(d=>{$('vmLoad').style.display='none';if(!d.success)return;_vmAll=d.videos||[];vmRender(_vmAll);});}
function vmFilter(){const q=$('vmSearch').value.toLowerCase(),t=$('vmType').value;vmRender(_vmAll.filter(v=>(!q||v.filename.toLowerCase().includes(q))&&(t==='all'||v.type===t)));}

function vmRender(vids){
    const g=$('vmGrid'),e=$('vmEmpty');
    $('vmCnt').textContent=vids.length+' ملف بالخادم المربوطة.';
    if(!vids.length){g.style.display='none';e.style.display='block';return;}
    e.style.display='none';g.style.display='grid';
    
    // المسميات بالالوان تريح النفسية وتفرق المجلدات بسهولة للتحكم المستقر.
    const typeLabels = {uploaded:'تم استخراجة للعام (حراً طليقا)', merged:'خاضع لملف الترجمة المستقل', series:'الآن يسكن متجذر لشاشتي (حلقة ومسلسلات).'};
    const typeColors = {uploaded:'rgba(76,201,240,.9)', merged:'rgba(0,208,132,.9)', series:'rgba(245,166,35,.9)'};
    
    g.innerHTML=vids.map(v=>`<div class="vmc" id="vmc-${esc(v.filename)}">
        <div class="vmt" onclick='testChannel("${escA(v.url)}","${escA(v.filename)}")'>
            <video src="${esc(v.url)}" preload="none" muted style="pointer-events:none"></video>
            <div class="vmt-ic"><i class="fas fa-play"></i></div>
            <span class="vmbdg" style="background:${typeColors[v.type]||'#333'};color:${v.type==='uploaded'?'#000':'#fff'}">${typeLabels[v.type]||v.type}</span>
        </div>
        <div class="vminfo">
            <div class="vmname" title="${esc(v.filename)}">${esc(v.filename)}</div>
            <div class="vmmeta"><span><i class="fas fa-hdd"></i> ${v.size_mb} MB</span><span>${esc(v.date)}</span></div>
        </div>
        <div class="vmacts">
            <button class="vmb pl" onclick='testChannel("${escA(v.url)}","${escA(v.filename)}")' title="إلعب مقطعاً مرئياً من داخل هذا الفيديو الان!"><i class="fas fa-play"></i></button>
            <button class="vmb sub" onclick="vmTriggerSub('${escA(v.filename)}','${v.type}')" title="أوّل ما تلحق بهذا المسار ملفات ال vtt الخاص بك سينظر إليها اللاعب باختيار اللمس!"><i class="fas fa-closed-captioning"></i></button>
            <button class="vmb mv" onclick="vmOpenMove('${escA(v.filename)}','${v.type}')" title="تحول جسدي داخلي للـ Filesystem الخاص بنا بالاعماق للملف للقفز للمسلسل أو خارج المجمعات الكتلية.."><i class="fas fa-exchange-alt"></i></button>
            <button class="vmb sv" onclick="vmOpenSave('${escA(v.filename)}','${v.type}')" title="طِور المسار الموثق وإرمه الى داخل مسلسلك المراد !"><i class="fas fa-save"></i></button>
            <button class="vmb dl" onclick="vmDel('${escA(v.filename)}','${v.type}')" title="الإتلاف الكُلي المروع من الجُذر للقرص الصلب السرفر خاصتك!"><i class="fas fa-trash-alt"></i></button>
        </div>
    </div>`).join('');
}

function vmOpenMove(fn, type){
    _vmMoveCtx = {fn, type};
    $('vmMoveFile').textContent = 'هندسة نقل جذر هذا الملف ببراعة السرفرات: ' + fn;
    
    let folderOpts = '<optgroup label="نقل لعراء السيرفر الرئيسي وخروجه للـ Public Videos File!">';
    folderOpts += '<option value="videos">🌐 استخراج وتعريه الملف للرفع العام (أقضي أرسالاتة ومسيراته لشبكة خارج ال Series.)</option>';
    folderOpts += '</optgroup>';
    
    folderOpts += '<optgroup label="إدخاله وحصاره داخل مجمع لجدول مسلسلات شاشتي.">';
    if (typeof _allFoldersGlobal !== 'undefined') {
        _allFoldersGlobal.forEach(folder => {
            folderOpts += `<option value="${folder.id}">🎬 دمجة وإيواه فوراً كحلقة مستجدة لمجمع ومجلد : ${esc(folder.name)}</option>`;
        });
    }
    folderOpts += '</optgroup>';
    
    $('vmMoveTarget').innerHTML = folderOpts;
    
    al('vmMoveAlert','','');
    OM('vmMoveM');
}

function vmDoMove(){
    const target = $('vmMoveTarget').value;
    al('vmMoveAlert', '<span class="sp"></span> يُتخذُ هذا الإيعاز حاسوبياً بمركز الاتصال الخاصك.. جاري توجية ال Path للـ Route الجديد وقطع الارتباط السابق، ابقه متفتح.', 'i');
    
    api({ajax_action: 'move_video_file', filename: _vmMoveCtx.fn, type: _vmMoveCtx.type, target_folder: target}).then(d => {
        if(d.success) {
            al('vmMoveAlert', '✅ ' + d.message, 's');
            setTimeout(() => { CM('vmMoveM'); vmLoad(); }, 1600);
        } else {
            al('vmMoveAlert', d.error || 'عقدة مستعصية جارية حدثت ولم يتحول.', 'e');
        }
    });
}

function vmOpenSave(fn,type, subUrl=''){
    _vmCtx={fn,type};
    $('vmSaveFile').textContent='ترخيص: '+fn;
    $('vmSaveTitle').value=fn.replace(/^(vid_|merged_|vid_dl_|ep_)[a-z0-9]+_?/i,'').replace(/\.[^.]+$/,'').replace(/[_\-.]/g,' ').trim();
    $('vmSaveSubUrl').value = subUrl;
    $('vmSaveSub').style.display = subUrl ? 'block' : 'none';
    al('vmSaveAlert','','');
    vToggleSeriesFields($('vmSaveTargetSeries').value, 'manage');
    OM('vmSaveM');
}

function vmDoSave(){
    const title = $('vmSaveTitle').value.trim(), 
          cid = $('vmSaveCat').value, 
          subUrl = $('vmSaveSubUrl').value,
          targetId = $('vmSaveTargetSeries').value; 

    if(targetId == "0" && (!title || !cid)) { al('vmSaveAlert','الترسانة العصبية المجهزة بالمكتب تمنع حفظها الا عند جردك لإمضاء الفصول للفيلم او الحلقه للنوع الجديد.','e'); return;}
    
    al('vmSaveAlert', '<span class="sp"></span> تدريجات الاضافات تعمل لحفر الداتا بالأساس...', 'i');
    
    api({
        ajax_action:'save_video_manual', 
        filename: _vmCtx.fn, 
        video_type: _vmCtx.type, 
        title: title || 'انشاء مستحدث من إدارة المحرر.', 
        category_id: cid, 
        subtitle_url: subUrl,
        target_series_id: targetId
    }).then(d=>{
        if(d.success){
            CM('vmSaveM');
            alert(targetId == "0" ? "تشريع النظام للمجلد المُنشئ تُم بشكل قوي، أُحتسب هذا المسار!" : "تم رعاية المُختار لمربوطه الساسي وأرسُل لبر المجمع الآلي المُسبق الحفظ شاشتي!");
            setTimeout(()=>{ S('series'); loadSeries(); }, 500);
        }else al('vmSaveAlert', d.error||'تعذر وصول السرديات للمكتب القُدير', 'e');
    });
}

function vmDel(fn,type){if(!confirm('خطر الازالة: سوف تُنسف الذكريات كاملة عن القرص السحب الثابثة الخاصة بهذا المسير ('+fn+') ؟'))return;api({ajax_action:'delete_video',filename:fn,type}).then(d=>{if(d.success){const c=document.getElementById('vmc-'+fn);if(c){c.style.opacity='0';c.style.transition='all .3s';setTimeout(()=>c.remove(),300);}_vmAll=_vmAll.filter(v=>v.filename!==fn);$('vmCnt').textContent=_vmAll.length+' جِرد مقطعيّ وحيد الان متوفّر بالسيرفر.';}else alert('❌ '+(d.error||'استغاثة لملكية السرفر غير خاضعة.'));});}

// ═══════════════════════════════════════════════════════════════
// نظام البحث المتعدد المصادر (TMDB + AniList + OMDb) v3
// ═══════════════════════════════════════════════════════════════
function switchSource(ctx,source,btn){
    _currentSource[ctx]=source;
    var t=$((ctx==='add'?'add':'edit')+'SrSourceTabs');
    if(!t)return;
    t.querySelectorAll('.source-tab').forEach(function(b){b.classList.remove('active','tmdb-active','anilist-active','omdb-active');});
    btn.classList.add('active',source+'-active');
    var r=$('mediaRes_'+ctx);if(r)r.style.display='none';
}
function mediaAutoSearch(ctx,val){
    clearTimeout(_mediaSearchTimer[ctx]);
    var r=$('mediaRes_'+ctx);
    if(!val||val.length<3){if(r)r.style.display='none';return;}
    _mediaSearchTimer[ctx]=setTimeout(function(){mediaSearch(ctx);},700);
}
function mediaSearch(ctx){
    var nid=ctx==='add'?'srName':'eSrName';
    var val=$(nid).value.trim();
    if(!val||val.length<2)return;
    var src=_currentSource[ctx];
    var r=$('mediaRes_'+ctx);
    r.style.display='block';
    r.innerHTML='<div class="media-result-item"><div class="media-result-info" style="color:var(--t3)"><span class="sp"></span> جارٍ البحث في '+src.toUpperCase()+'…</div></div>';
    if(src==='tmdb')searchTMDB_ms(ctx,val);
    else if(src==='anilist')searchAniList_ms(ctx,val);
    else if(src==='omdb')searchOMDb_ms(ctx,val);
}
async function searchTMDB_ms(ctx,q){
    var key=getTmdbKey(),r=$('mediaRes_'+ctx);
    if(!key){r.innerHTML='<div class="media-result-item"><div class="media-result-info"><span style="color:#ff6b6b"><i class="fas fa-key"></i> مفتاح TMDB مفقود</span></div></div>';return;}
    try{
        var rA=await fetch('https://api.themoviedb.org/3/search/multi?api_key='+encodeURIComponent(key)+'&query='+encodeURIComponent(q)+'&language=ar');
        var rE=await fetch('https://api.themoviedb.org/3/search/multi?api_key='+encodeURIComponent(key)+'&query='+encodeURIComponent(q)+'&language=en-US');
        if(rA.status===401){r.innerHTML='<div class="media-result-item"><div class="media-result-info"><span style="color:#ff6b6b"><i class="fas fa-key"></i> مفتاح TMDB غير صحيح</span></div></div>';return;}
        var dA=await rA.json(),dE=await rE.json();
        var seen=new Set();
        var items=[].concat(dA.results||[],dE.results||[]).filter(function(i){if(seen.has(i.id))return false;seen.add(i.id);return(i.title||i.name);}).slice(0,8);
        if(!items.length){r.innerHTML='<div class="media-result-item"><div class="media-result-info" style="color:var(--t3)"><i class="fas fa-search"></i> لا نتائج</div></div>';return;}
        r.innerHTML=items.map(function(i){
            var t=i.title||i.name||'',y=(i.release_date||i.first_air_date||'').substring(0,4),
                p=i.poster_path?'https://image.tmdb.org/t/p/w92'+i.poster_path:'',
                pf=i.poster_path?'https://image.tmdb.org/t/p/w500'+i.poster_path:'',
                mt=i.media_type||'movie',
                th=mt==='tv'?'<span class="bdg bp" style="font-size:.6rem">مسلسل</span>':'<span class="bdg bc" style="font-size:.6rem">فيلم</span>',
                rt=i.vote_average?'<span style="color:var(--gold);font-size:.65rem"><i class="fas fa-star"></i> '+i.vote_average.toFixed(1)+'</span>':'';
            return '<div class="media-result-item" onclick="mediaPick(\''+ctx+'\',\''+escA(t)+'\',\''+escA(pf)+'\',\''+escA(i.overview||'')+'\')">'+
                (p?'<img src="'+esc(p)+'" onerror="this.style.opacity=\'.2\'">':'<div style="width:36px;height:50px;background:var(--s3);border-radius:4px;display:flex;align-items:center;justify-content:center"><i class="fas fa-film" style="color:var(--t3)"></i></div>')+
                '<div class="media-result-info"><div class="media-result-title">'+esc(t)+'</div><div class="media-result-meta">'+(y||'\u2014')+' '+th+' '+rt+' <span class="source-badge tmdb">TMDB</span></div></div>'+
                '<button type="button" class="tmdb-info-btn" onclick="event.preventDefault();event.stopPropagation();showTmdbInfo('+i.id+',\''+mt+'\')" title="\u062A\u0641\u0627\u0635\u064A\u0644"><i class="fas fa-info"></i></button></div>';
        }).join('');
    }catch(e){r.innerHTML='<div class="media-result-item"><div class="media-result-info" style="color:#ff6b6b"><i class="fas fa-exclamation-triangle"></i> خطأ اتصال TMDB</div></div>';}
}
async function searchAniList_ms(ctx,q){
    var r=$('mediaRes_'+ctx);
    var gql='query($s:String){Page(page:1,perPage:10){media(search:$s,type:ANIME,sort:POPULARITY_DESC){id title{romaji english native}coverImage{medium large}startDate{year}episodes format averageScore description(asHtml:false)}}}';
    try{
        var res=await fetch('https://graphql.anilist.co',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({query:gql,variables:{s:q}})});
        var data=await res.json();
        var items=(data&&data.data&&data.data.Page&&data.data.Page.media)||[];
        if(!items.length){r.innerHTML='<div class="media-result-item"><div class="media-result-info" style="color:var(--t3)"><i class="fas fa-search"></i> لا نتائج أنمي</div></div>';return;}
        r.innerHTML=items.map(function(i){
            var t=i.title.english||i.title.romaji||i.title.native||'',
                ta=i.title.native||i.title.romaji||'',
                y=i.startDate?i.startDate.year:'',
                p=i.coverImage?i.coverImage.medium:'',pf=i.coverImage?i.coverImage.large:'',
                ep=i.episodes?i.episodes+' حلقة':'',
                sc=i.averageScore?'<span style="color:var(--gold);font-size:.65rem"><i class="fas fa-star"></i> '+(i.averageScore/10).toFixed(1)+'</span>':'',
                fm={TV:'مسلسل',MOVIE:'فيلم',OVA:'OVA',ONA:'ONA',SPECIAL:'خاص'},
                fl=fm[i.format]||i.format||'',
                ds=(i.description||'').replace(/<[^>]+>/g,'').substring(0,200);
            return '<div class="media-result-item" onclick="mediaPick(\''+ctx+'\',\''+escA(t)+'\',\''+escA(pf)+'\',\''+escA(ds)+'\')">'+
                (p?'<img src="'+esc(p)+'" onerror="this.style.opacity=\'.2\'">':'<div style="width:36px;height:50px;background:var(--s3);border-radius:4px;display:flex;align-items:center;justify-content:center"><i class="fas fa-dragon" style="color:var(--t3)"></i></div>')+
                '<div class="media-result-info"><div class="media-result-title">'+esc(t)+(ta&&ta!==t?' <span style="color:var(--t3);font-size:.72rem">('+esc(ta)+')</span>':'')+'</div>'+
                '<div class="media-result-meta">'+(y||'\u2014')+' <span class="bdg" style="background:rgba(76,201,240,.1);color:#4CC9F0;border:1px solid rgba(76,201,240,.2);font-size:.6rem">'+fl+'</span> '+(ep?'<span style="font-size:.65rem">'+ep+'</span> ':'')+sc+' <span class="source-badge anilist">AniList</span></div></div></div>';
        }).join('');
    }catch(e){r.innerHTML='<div class="media-result-item"><div class="media-result-info" style="color:#ff6b6b"><i class="fas fa-exclamation-triangle"></i> خطأ اتصال AniList</div></div>';}
}
async function searchOMDb_ms(ctx,q){
    var r=$('mediaRes_'+ctx),key=getOmdbKey();
    if(!key){r.innerHTML='<div class="media-result-item" onclick="S(\'api-settings\')" style="cursor:pointer"><div class="media-result-info"><span style="color:#ff6b6b"><i class="fas fa-key"></i> مفتاح OMDb مفقود — أضفه في إعدادات API</span></div></div>';return;}
    try{
        var res=await fetch('https://www.omdbapi.com/?apikey='+encodeURIComponent(key)+'&s='+encodeURIComponent(q)+'&page=1');
        var data=await res.json();
        if(data.Response==='False'||!data.Search||!data.Search.length){r.innerHTML='<div class="media-result-item"><div class="media-result-info" style="color:var(--t3)"><i class="fas fa-search"></i> لا نتائج — جرب بالإنجليزية</div></div>';return;}
        r.innerHTML=data.Search.slice(0,8).map(function(i){
            var t=i.Title||'',y=i.Year||'',p=(i.Poster&&i.Poster!=='N/A')?i.Poster:'',id=i.imdbID||'',
                tm={movie:'فيلم',series:'مسلسل',episode:'حلقة',game:'لعبة'},tl=tm[i.Type]||i.Type||'';
            return '<div class="media-result-item" onclick="omdbDetail_ms(\''+ctx+'\',\''+escA(id)+'\',\''+escA(t)+'\',\''+escA(p)+'\')">'+
                (p?'<img src="'+esc(p)+'" onerror="this.style.opacity=\'.2\'">':'<div style="width:36px;height:50px;background:var(--s3);border-radius:4px;display:flex;align-items:center;justify-content:center"><i class="fas fa-database" style="color:var(--t3)"></i></div>')+
                '<div class="media-result-info"><div class="media-result-title">'+esc(t)+'</div><div class="media-result-meta">'+(y||'\u2014')+' <span class="bdg" style="background:rgba(245,166,35,.1);color:var(--gold);border:1px solid rgba(245,166,35,.2);font-size:.6rem">'+tl+'</span> '+(id?'<span style="font-size:.62rem;color:var(--t3)">'+id+'</span> ':'')+'<span class="source-badge omdb">OMDb</span></div></div></div>';
        }).join('');
    }catch(e){r.innerHTML='<div class="media-result-item"><div class="media-result-info" style="color:#ff6b6b"><i class="fas fa-exclamation-triangle"></i> خطأ اتصال OMDb</div></div>';}
}
async function omdbDetail_ms(ctx,imdbId,ft,fp){
    var key=getOmdbKey();
    if(!key||!imdbId){mediaPick(ctx,ft,fp,'');return;}
    try{
        var res=await fetch('https://www.omdbapi.com/?apikey='+encodeURIComponent(key)+'&i='+encodeURIComponent(imdbId)+'&plot=short');
        var d=await res.json();
        if(d.Response==='True')mediaPick(ctx,d.Title||ft,(d.Poster&&d.Poster!=='N/A')?d.Poster:fp,(d.Plot&&d.Plot!=='N/A')?d.Plot:'');
        else mediaPick(ctx,ft,fp,'');
    }catch(e){mediaPick(ctx,ft,fp,'');}
}
function mediaPick(ctx,title,poster,desc){
    var r=$('mediaRes_'+ctx);if(r)r.style.display='none';
    if(ctx==='add'){
        $('srName').value=title;
        if(poster){$('srPoster').value=poster;srPosterPreview('srPosterThumb',poster);}
        if(desc&&$('srDesc'))$('srDesc').value=desc;
    }else{
        $('eSrName').value=title;
        if(poster){$('eSrPoster').value=poster;srPosterPreview('eSrPosterThumb',poster);}
        if(desc&&$('eSrDesc'))$('eSrDesc').value=desc;
    }
}
document.addEventListener('click',function(e){
    if(!e.target.closest('.media-search-wrap'))
        document.querySelectorAll('.media-search-results').forEach(function(r){r.style.display='none';});
});
// ═══ نهاية نظام البحث المتعدد ═══


// ══════════════════════════════════════════════════════════════
// نظام إدارة المستخدمين والصلاحيات v1.0
// ══════════════════════════════════════════════════════════════
const ADMIN_ROLE = "<?php echo $_admin_role; ?>";
const ADMIN_SECTIONS = <?php echo json_encode($_admin_sections); ?>;
const ADMIN_USER_ID = <?php echo $_admin_user_id; ?>;

const ALL_SECTION_DEFS = [
    {key:'dashboard',    name:'لوحة التحكم',      icon:'fas fa-home'},
    {key:'categories',   name:'الأقسام',           icon:'fas fa-th-large'},
    {key:'channels',     name:'القنوات',           icon:'fas fa-tv'},
    {key:'m3u-import',   name:'استيراد M3U',       icon:'fas fa-file-import'},
    {key:'xtream',       name:'حساب Xtream',       icon:'fas fa-satellite-dish'},
    {key:'series',       name:'شاشتي',             icon:'fas fa-film'},
    {key:'vupload',      name:'رفع الأفلام',       icon:'fas fa-cloud-upload-alt'},
    {key:'vmanage',      name:'إدارة الفيديوهات',  icon:'fas fa-photo-video'},
    {key:'api-settings', name:'إعدادات API',       icon:'fas fa-plug'},
    {key:'site-settings',name:'إعدادات الموقع',    icon:'fas fa-cog'},
    {key:'change-password',name:'كلمة المرور',     icon:'fas fa-key'},
    {key:'system-tools', name:'صيانة النظام',      icon:'fas fa-tools'},
    {key:'backup',       name:'النسخ الاحتياطي',   icon:'fas fa-database'},
];

const ROLE_LABELS = {administrator:'مدير عام',super:'مشرف',normal:'عادي',custom:'مخصص'};
const ROLE_CLASSES = {administrator:'admin',super:'super',normal:'normal',custom:'custom'};

let _usrAll = [];

// ── بناء شبكة الصلاحيات ──
function buildPermsGrid(containerId, selected) {
    var sel = selected || [];
    var html = '';
    ALL_SECTION_DEFS.forEach(function(s) {
        var on = sel.indexOf(s.key) !== -1;
        html += '<div class="perm-item'+(on?' on':'')+'" data-key="'+s.key+'" onclick="togglePerm(this)">';
        html += '<div class="pi-ic"><i class="'+s.icon+'"></i></div>';
        html += '<span class="pi-name">'+s.name+'</span>';
        html += '<div class="pi-chk"><i class="fas fa-check"></i></div>';
        html += '</div>';
    });
    $(containerId).innerHTML = html;
}

function togglePerm(el) {
    el.classList.toggle('on');
}

function getSelectedPerms(containerId) {
    var perms = [];
    $(containerId).querySelectorAll('.perm-item.on').forEach(function(el) {
        perms.push(el.getAttribute('data-key'));
    });
    return perms;
}

// ── إظهار/إخفاء شبكة الصلاحيات بناء على الدور ──
function auRoleChange() {
    var role = $('auRole').value;
    $('auPermsWrap').style.display = (role === 'custom') ? 'block' : 'none';
    if(role === 'custom') buildPermsGrid('auPermsGrid', ['vupload']);
}
function euRoleChange() {
    var role = $('euRole').value;
    $('euPermsWrap').style.display = (role === 'custom') ? 'block' : 'none';
}

// ── تحميل المستخدمين ──
function loadUsers() {
    $('usrGrid').innerHTML = '';
    $('usrEmpty').style.display = 'none';
    $('usrLoading').style.display = 'block';
    api({ajax_action:'get_admin_users'}).then(function(d) {
        $('usrLoading').style.display = 'none';
        if(!d.success) { al('usrGrid', d.error || 'خطأ', 'e'); return; }
        _usrAll = d.data || [];
        usrRender(_usrAll);
    });
}

function usrFilter() {
    var q = ($('usrSearch').value || '').toLowerCase();
    var role = $('usrRoleFilter').value;
    usrRender(_usrAll.filter(function(u) {
        var matchQ = !q || u.username.toLowerCase().indexOf(q) !== -1 || (u.display_name||'').toLowerCase().indexOf(q) !== -1;
        var matchR = role === 'all' || u.role === role;
        return matchQ && matchR;
    }));
}

function usrRender(users) {
    var g = $('usrGrid'), e = $('usrEmpty');
    $('usrCount').textContent = users.length + ' مستخدم';
    if(!users.length) { g.innerHTML = ''; e.style.display = 'block'; return; }
    e.style.display = 'none';
    g.innerHTML = users.map(function(u) {
        var rc = ROLE_CLASSES[u.role] || 'normal';
        var rl = ROLE_LABELS[u.role] || u.role;
        var initial = (u.display_name || u.username || '?').charAt(0).toUpperCase();
        var inactive = u.is_active == 0;
        var lastLogin = u.last_login ? u.last_login.substring(0,16) : 'لم يدخل بعد';
        var sections = [];
        try { sections = JSON.parse(u.allowed_sections || '[]'); } catch(e) {}
        var secText = '';
        if(u.role === 'custom' && sections.length > 0) {
            secText = '<span style="font-size:.68rem;color:var(--gold)"><i class="fas fa-lock-open"></i> ' + sections.length + ' قسم مسموح</span>';
        } else if(u.role === 'normal') {
            secText = '<span style="font-size:.68rem;color:#4CC9F0"><i class="fas fa-cloud-upload-alt"></i> رفع فقط</span>';
        } else if(u.role === 'administrator' || u.role === 'super') {
            secText = '<span style="font-size:.68rem;color:#00D084"><i class="fas fa-globe"></i> كل الأقسام</span>';
        }

        return '<div class="usr-card'+(inactive?' usr-inactive':'')+'">' +
            '<div class="usr-card-hd">' +
                '<div class="usr-avt '+rc+'">'+esc(initial)+'</div>' +
                '<div style="flex:1;min-width:0">' +
                    '<div class="usr-name">'+esc(u.display_name || u.username)+(inactive?' <span style="color:#ff6b6b;font-size:.72rem">⛔ معطّل</span>':'')+'</div>' +
                    '<div class="usr-uname">@'+esc(u.username)+'</div>' +
                '</div>' +
                '<span class="usr-role-bdg '+rc+'"><i class="fas fa-'+(rc==='admin'?'crown':rc==='super'?'shield-alt':rc==='custom'?'sliders-h':'user')+'"></i> '+rl+'</span>' +
            '</div>' +
            '<div class="usr-card-body"><div class="usr-meta">' +
                '<span><i class="fas fa-clock" style="color:var(--t3)"></i> آخر دخول: '+esc(lastLogin)+'</span>' +
                '<span><i class="fas fa-calendar" style="color:var(--t3)"></i> أُنشئ: '+esc((u.created_at||'').substring(0,10))+'</span>' +
                (secText ? '<span>'+secText+'</span>' : '') +
            '</div></div>' +
            '<div class="usr-card-ft">' +
                '<button class="ib ed" onclick=\'openEditUser('+JSON.stringify(u).replace(/'/g,"\\'")+')\'><i class="fas fa-pen"></i></button>' +
                (u.id != ADMIN_USER_ID ? '<button class="ib dl" onclick="deleteUser('+u.id+',\''+escA(u.display_name||u.username)+'\')"><i class="fas fa-trash"></i></button>' : '') +
            '</div>' +
        '</div>';
    }).join('');
}

// ── إضافة مستخدم ──
function addUser() {
    var username = $('auUsername').value.trim();
    var display = $('auDisplay').value.trim();
    var password = $('auPassword').value;
    var role = $('auRole').value;
    var sections = (role === 'custom') ? JSON.stringify(getSelectedPerms('auPermsGrid')) : '[]';

    if(!username) { al('auAlert','أدخل اسم المستخدم','e'); return; }
    if(!password || password.length < 4) { al('auAlert','كلمة المرور يجب أن تكون 4 أحرف على الأقل','e'); return; }

    al('auAlert','<span class="sp"></span> جارٍ الإنشاء...','i');
    api({ajax_action:'add_admin_user', username:username, password:password, display_name:display, role:role, allowed_sections:sections}).then(function(d) {
        if(d.success) {
            CM('addUserM');
            $('auUsername').value = '';
            $('auDisplay').value = '';
            $('auPassword').value = '';
            $('auRole').value = 'normal';
            $('auPermsWrap').style.display = 'none';
            al('auAlert','','');
            loadUsers();
        } else {
            al('auAlert', d.error || 'خطأ', 'e');
        }
    });
}

// ── فتح تعديل مستخدم ──
function openEditUser(u) {
    $('euId').value = u.id;
    $('euUsername').value = u.username;
    $('euDisplay').value = u.display_name || '';
    $('euPassword').value = '';
    $('euRole').value = u.role;
    $('euActive').value = u.is_active;

    var sections = [];
    try { sections = JSON.parse(u.allowed_sections || '[]'); } catch(e) {}

    if(u.role === 'custom') {
        $('euPermsWrap').style.display = 'block';
        buildPermsGrid('euPermsGrid', sections);
    } else {
        $('euPermsWrap').style.display = 'none';
    }

    // Super لا يستطيع اختيار administrator
    if(ADMIN_ROLE === 'super') {
        var opts = $('euRole').options;
        for(var i = 0; i < opts.length; i++) {
            if(opts[i].value === 'administrator') opts[i].disabled = true;
        }
    }

    al('euAlert','','');
    OM('editUserM');
}

// ── حفظ تعديل مستخدم ──
function editUser() {
    var id = $('euId').value;
    var display = $('euDisplay').value.trim();
    var role = $('euRole').value;
    var is_active = $('euActive').value;
    var new_pass = $('euPassword').value;
    var sections = (role === 'custom') ? JSON.stringify(getSelectedPerms('euPermsGrid')) : '[]';

    al('euAlert','<span class="sp"></span> جارٍ الحفظ...','i');
    api({ajax_action:'edit_admin_user', id:id, display_name:display, role:role, allowed_sections:sections, is_active:is_active, new_password:new_pass}).then(function(d) {
        if(d.success) {
            CM('editUserM');
            loadUsers();
        } else {
            al('euAlert', d.error || 'خطأ', 'e');
        }
    });
}

// ── حذف مستخدم ──
function deleteUser(id, name) {
    if(!confirm('حذف المستخدم "' + name + '" نهائياً؟')) return;
    api({ajax_action:'delete_admin_user', id:id}).then(function(d) {
        if(d.success) loadUsers();
        else alert(d.error || 'خطأ');
    });
}

// ══════════════════════════════════════════════════════════════
// فرض الصلاحيات على واجهة المستخدم
// ══════════════════════════════════════════════════════════════
(function enforcePermissions() {
    if(ADMIN_ROLE === 'administrator') return; // المدير العام يرى كل شيء

    var allowed = [];
    if(ADMIN_ROLE === 'super') {
        // المشرف يرى كل شيء + إدارة المستخدمين
        allowed = ALL_SECTION_DEFS.map(function(s){return s.key;});
        allowed.push('users');
    } else if(ADMIN_ROLE === 'normal') {
        allowed = ['vupload'];
    } else if(ADMIN_ROLE === 'custom') {
        allowed = ADMIN_SECTIONS || [];
    }

    // إخفاء أزرار القائمة الجانبية غير المسموحة
    document.querySelectorAll('.si[onclick]').forEach(function(btn) {
        var onclick = btn.getAttribute('onclick') || '';
        var match = onclick.match(/S\('([^']+)'\)/);
        if(match) {
            var sid = match[1];
            if(allowed.indexOf(sid) === -1) {
                btn.style.display = 'none';
            }
        }
    });

    // تعديل دالة S لمنع الوصول للأقسام غير المسموحة
    var _origS = window.S;
    window.S = function(id) {
        if(allowed.indexOf(id) === -1) {
            alert('ليس لديك صلاحية للوصول لهذا القسم');
            return;
        }
        _origS(id);
    };

    // عند تحميل الصفحة، اذهب لأول قسم مسموح
    if(allowed.length > 0 && allowed.indexOf('dashboard') === -1) {
        setTimeout(function() {
            // إزالة on من dashboard
            var ds = document.getElementById('dashboard');
            if(ds) ds.classList.remove('on');
            document.querySelectorAll('.si').forEach(function(b){b.classList.remove('on');});
            _origS(allowed[0]);
            if(allowed[0] === 'vupload') {} // لا حاجة لتحميل إضافي
            else if(allowed[0] === 'series') { if(typeof loadSeries === 'function') loadSeries(); }
            else if(allowed[0] === 'vmanage') { if(typeof vmLoad === 'function') vmLoad(); }
            else if(allowed[0] === 'm3u-import') { if(typeof m3uLoadPlaylists === 'function') m3uLoadPlaylists(); }
            else if(allowed[0] === 'xtream') { if(typeof xtreamLoadAccounts === 'function') xtreamLoadAccounts(); }
        }, 100);
    }
})();
// ═══ نهاية نظام المستخدمين ═══


// إغلاق قائمة اللغات عند النقر بالخارج
document.addEventListener('click', function() {
    var drop = document.getElementById('langDrop');
    if(drop && drop.classList.contains('op')) { drop.classList.remove('op'); }
    var psw = document.getElementById('profSw');
    if(psw && psw.classList.contains('op')) { psw.classList.remove('op'); }
});

// ══════════════════════════════════════════════════════════════
// 🎨 THEME SYSTEM — SHASHITY PRO
// ══════════════════════════════════════════════════════════════

const THEME_PRESETS = {
    default: { name: 'الافتراضي', css: '' },
    ultrachromic: {
        name: 'Ultrachromic',
        css: `:root{--red:#b847ff;--redg:rgba(184,71,255,0.35);--gold:#6200ea;--s0:#0d0221;--s1:#100828;--s2:#180d35;--s3:#1f1140;--s4:#28174d;--br:rgba(255,255,255,.09);--brh:rgba(255,255,255,.2);--t1:#f0e8ff;--t2:#b89fd8;--t3:#6b5a8a;--r1:12px}

/* خلفية */
html{background:#0d0221}
body{background:#0d0221 !important;color:#f0e8ff;position:relative;min-height:100vh}
body::before{content:"";position:fixed;inset:0;z-index:-2;pointer-events:none;background:
  radial-gradient(60% 50% at 18% 10%,rgba(184,71,255,0.16),transparent 62%),
  radial-gradient(60% 50% at 85% 20%,rgba(98,0,234,0.14),transparent 62%),
  radial-gradient(60% 50% at 70% 90%,rgba(224,64,251,0.1),transparent 62%);
  animation:thDrift 30s ease-in-out infinite alternate}
@keyframes thDrift{0%{transform:scale(1)}100%{transform:scale(1.08)}}
body,.si,.fi,.fs,td,th,p{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.stitle,.tbtitle,.sbrand-name{color:#f0e8ff;font-weight:750;letter-spacing:-.01em}
.card p,.sc p{color:#b89fd8;line-height:1.75}
.snl{color:#6b5a8a;letter-spacing:.08em;font-weight:600}

/* ══ لوحة التحكم ══ */
.sidebar{background:rgba(16,8,40,0.82) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:-1px 0 40px rgba(0,0,0,.4)}
.sidebar::after{background:linear-gradient(180deg,rgba(184,71,255,0.6),transparent)}
.sbrand{background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.09)}
.sbrand-icon{background:linear-gradient(135deg,#6200ea,#b847ff);color:#ffffff;box-shadow:0 4px 16px rgba(184,71,255,0.3)}
.sbrand-sub{color:#6b5a8a}
.si{color:#b89fd8;font-weight:600}
.si:hover{background:rgba(255,255,255,.06);color:#f0e8ff}
.si.on{background:rgba(184,71,255,0.12);color:#f0e8ff}
.si.on::before{background:#b847ff}
.si.on .si-ic{color:#b847ff}
.topbar{background:rgba(16,8,40,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-bottom:1px solid rgba(255,255,255,.09) !important}
.main{background:transparent !important}
.card,.tw,.swc,.bkc,.sc,.mbox,.pcard,.theme-panel{background:rgba(255,255,255,.05) !important;-webkit-backdrop-filter:blur(16px) saturate(140%) !important;backdrop-filter:blur(16px) saturate(140%) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:14px !important;box-shadow:0 8px 30px rgba(0,0,0,.4),inset 0 1px 0 rgba(255,255,255,.06) !important}
.sc:hover{background:rgba(255,255,255,.08) !important;border-color:rgba(255,255,255,.2) !important;box-shadow:0 12px 38px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.06) !important}
.fi,.fs{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#f0e8ff !important;border-radius:10px}
.fi::placeholder{color:#6b5a8a}
.fi:focus,.fs:focus{border-color:#b847ff !important;background:rgba(255,255,255,.08) !important;box-shadow:0 0 0 3px rgba(184,71,255,0.18) !important}
.btn-p{background:linear-gradient(135deg,#6200ea,#b847ff) !important;border:none !important;color:#ffffff !important;font-weight:700;border-radius:10px;box-shadow:0 4px 18px rgba(184,71,255,0.32)}
.btn-p:hover{filter:brightness(1.1);box-shadow:0 6px 24px rgba(184,71,255,0.45)}
.ib{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);color:#b89fd8;border-radius:9px}
.ib:hover{background:rgba(255,255,255,.12);color:#f0e8ff;border-color:rgba(255,255,255,.2)}
.uavt{background:linear-gradient(135deg,#6200ea,#b847ff);color:#ffffff}
.lic-dot{background:#b847ff;box-shadow:0 0 8px rgba(184,71,255,0.7)}
.lic-b{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.09);color:#b89fd8}
.mhd,.mfooter{background:rgba(255,255,255,.035);border-color:rgba(255,255,255,.09)}
thead tr{background:rgba(255,255,255,.05)}
th{color:#6b5a8a;font-weight:700;letter-spacing:.04em}
td{color:#f0e8ff;border-color:rgba(255,255,255,.06)}
tr:hover td{background:rgba(255,255,255,.045)}
.pw .pb{background:linear-gradient(90deg,#6200ea,#b847ff)}
.mbd,#pm{background:rgba(0,0,0,.8) !important;-webkit-backdrop-filter:blur(14px) !important;backdrop-filter:blur(14px) !important}
.al-s{background:rgba(184,71,255,0.12);border-color:rgba(184,71,255,0.4);color:#b847ff}
@keyframes fu{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ══ الموقع العام index.php ══ */
.navbar{background:rgba(16,8,40,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(155%) !important;backdrop-filter:blur(22px) saturate(155%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.nav-logo-text{color:#b847ff !important}
.nav-btn{background:rgba(255,255,255,.07) !important;border:1px solid rgba(255,255,255,.09) !important;color:#b89fd8 !important}
.nav-btn:hover{background:rgba(184,71,255,0.22) !important;border-color:#b847ff !important;color:#f0e8ff !important}
.search-wrap input{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#f0e8ff !important}
.search-wrap input::placeholder{color:#6b5a8a}
.search-wrap input:focus{background:rgba(255,255,255,.09) !important;border-color:#b847ff !important}
.cat-navbar{background:rgba(16,8,40,0.72) !important;-webkit-backdrop-filter:blur(20px) saturate(150%) !important;backdrop-filter:blur(20px) saturate(150%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.cat-nav-btn{background:rgba(255,255,255,.055) !important;border:1px solid rgba(255,255,255,.09) !important;color:#b89fd8 !important;font-weight:650 !important}
.cat-nav-btn:hover{background:rgba(255,255,255,.11) !important;border-color:rgba(255,255,255,.2) !important;color:#f0e8ff !important}
.cat-nav-btn.active{background:linear-gradient(135deg,#6200ea,#b847ff) !important;border-color:transparent !important;color:#ffffff !important;box-shadow:0 4px 16px rgba(184,71,255,0.38) !important}
.fp-panel,.np-panel,.m3u-panel,.ep-panel,.shs-catmenu-panel{background:rgba(16,8,40,0.82) !important;-webkit-backdrop-filter:blur(28px) saturate(155%) !important;backdrop-filter:blur(28px) saturate(155%) !important;border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:0 0 50px rgba(0,0,0,.55) !important}
.panel-overlay,.shs-catmenu-overlay,.tmdb-modal-overlay{background:rgba(0,0,0,.65) !important;-webkit-backdrop-filter:blur(8px) !important;backdrop-filter:blur(8px) !important}
.netflix-card,.nx-card,.ch-card,.sr-card{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:12px !important;box-shadow:0 6px 24px rgba(0,0,0,.4) !important}
.netflix-card:hover,.nx-card:hover{border-color:rgba(184,71,255,0.5) !important;box-shadow:0 12px 36px rgba(0,0,0,.55),0 0 0 1px rgba(184,71,255,0.25) !important}
.shs-catview-banner{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;-webkit-backdrop-filter:blur(16px) saturate(140%);backdrop-filter:blur(16px) saturate(140%)}
.shs-catmenu-item:hover{background:rgba(255,255,255,.07)}
.shs-empty-ico{background:radial-gradient(120% 120% at 30% 20%,rgba(184,71,255,0.16),rgba(255,255,255,.03));color:#b847ff}
.shs-spinner::before{border-top-color:#b847ff;border-right-color:#b847ff}
.hero-btn-play,.btn-play{background:linear-gradient(135deg,#6200ea,#b847ff) !important;color:#ffffff !important}
/* ══ إصلاح أيقونات شريط الأقسام ══ */
.cat-nav-btn i,.cat-nav-btn svg,.cat-nav-btn .lcn,.cat-nav-btn .lcn svg{
 color:#b89fd8 !important;stroke:currentColor !important;fill:none !important;
 width:1em;height:1em;flex-shrink:0;opacity:.9;transition:color .2s}
.cat-nav-btn:hover i,.cat-nav-btn:hover svg,.cat-nav-btn:hover .lcn,.cat-nav-btn:hover .lcn svg{
 color:#f0e8ff !important;opacity:1}
.cat-nav-btn.active i,.cat-nav-btn.active svg,.cat-nav-btn.active .lcn,.cat-nav-btn.active .lcn svg{
 color:#ffffff !important;opacity:1}
.cat-nav-btn .lcn{display:inline-flex;align-items:center;justify-content:center;line-height:0}
/* أيقونات القائمة الجانبية للأقسام */
.shs-catmenu-item i,.shs-catmenu-item svg,.shs-catmenu-item .lcn svg{
 color:#b847ff !important;stroke:currentColor !important;fill:none !important}
/* أيقونات أزرار التنقّل العلوية */
.nav-btn i,.nav-btn svg,.nav-btn .lcn svg{stroke:currentColor !important;fill:none !important}`
    },
    jellyflix: {
        name: 'JellyFlix',
        css: `:root{--red:#e50914;--redg:rgba(229,9,20,0.4);--gold:#b00610;--s0:#0b0b0b;--s1:#141414;--s2:#1c1c1c;--s3:#242424;--s4:#2e2e2e;--br:rgba(255,255,255,.09);--brh:rgba(255,255,255,.2);--t1:#ffffff;--t2:#b3b3b3;--t3:#6f6f6f;--r1:6px}

/* خلفية */
html{background:#0b0b0b}
body{background:#0b0b0b !important;color:#ffffff;position:relative;min-height:100vh}
body::before{content:"";position:fixed;inset:0;z-index:-2;pointer-events:none;background:
  radial-gradient(60% 50% at 15% 8%,rgba(229,9,20,0.12),transparent 62%),
  radial-gradient(60% 50% at 88% 15%,rgba(255,43,54,0.08),transparent 62%);
  animation:thDrift 30s ease-in-out infinite alternate}
@keyframes thDrift{0%{transform:scale(1)}100%{transform:scale(1.08)}}
body,.si,.fi,.fs,td,th,p{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.stitle,.tbtitle,.sbrand-name{color:#ffffff;font-weight:750;letter-spacing:-.01em}
.card p,.sc p{color:#b3b3b3;line-height:1.75}
.snl{color:#6f6f6f;letter-spacing:.08em;font-weight:600}

/* ══ لوحة التحكم ══ */
.sidebar{background:rgba(20,20,20,0.82) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:-1px 0 40px rgba(0,0,0,.4)}
.sidebar::after{background:linear-gradient(180deg,rgba(229,9,20,0.6),transparent)}
.sbrand{background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.09)}
.sbrand-icon{background:linear-gradient(135deg,#b00610,#e50914);color:#ffffff;box-shadow:0 4px 16px rgba(229,9,20,0.3)}
.sbrand-sub{color:#6f6f6f}
.si{color:#b3b3b3;font-weight:600}
.si:hover{background:rgba(255,255,255,.06);color:#ffffff}
.si.on{background:rgba(229,9,20,0.12);color:#ffffff}
.si.on::before{background:#e50914}
.si.on .si-ic{color:#e50914}
.topbar{background:rgba(20,20,20,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-bottom:1px solid rgba(255,255,255,.09) !important}
.main{background:transparent !important}
.card,.tw,.swc,.bkc,.sc,.mbox,.pcard,.theme-panel{background:rgba(255,255,255,.05) !important;-webkit-backdrop-filter:blur(16px) saturate(140%) !important;backdrop-filter:blur(16px) saturate(140%) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:8px !important;box-shadow:0 8px 30px rgba(0,0,0,.4),inset 0 1px 0 rgba(255,255,255,.06) !important}
.sc:hover{background:rgba(255,255,255,.08) !important;border-color:rgba(255,255,255,.2) !important;box-shadow:0 12px 38px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.06) !important}
.fi,.fs{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#ffffff !important;border-radius:4px}
.fi::placeholder{color:#6f6f6f}
.fi:focus,.fs:focus{border-color:#e50914 !important;background:rgba(255,255,255,.08) !important;box-shadow:0 0 0 3px rgba(229,9,20,0.18) !important}
.btn-p{background:linear-gradient(135deg,#b00610,#e50914) !important;border:none !important;color:#ffffff !important;font-weight:700;border-radius:4px;box-shadow:0 4px 18px rgba(229,9,20,0.32)}
.btn-p:hover{filter:brightness(1.1);box-shadow:0 6px 24px rgba(229,9,20,0.45)}
.ib{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);color:#b3b3b3;border-radius:3px}
.ib:hover{background:rgba(255,255,255,.12);color:#ffffff;border-color:rgba(255,255,255,.2)}
.uavt{background:linear-gradient(135deg,#b00610,#e50914);color:#ffffff}
.lic-dot{background:#00b020;box-shadow:0 0 8px rgba(0,176,32,0.7)}
.lic-b{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.09);color:#b3b3b3}
.mhd,.mfooter{background:rgba(255,255,255,.035);border-color:rgba(255,255,255,.09)}
thead tr{background:rgba(255,255,255,.05)}
th{color:#6f6f6f;font-weight:700;letter-spacing:.04em}
td{color:#ffffff;border-color:rgba(255,255,255,.06)}
tr:hover td{background:rgba(255,255,255,.045)}
.pw .pb{background:linear-gradient(90deg,#b00610,#e50914)}
.mbd,#pm{background:rgba(0,0,0,.8) !important;-webkit-backdrop-filter:blur(14px) !important;backdrop-filter:blur(14px) !important}
.al-s{background:rgba(0,176,32,0.12);border-color:rgba(0,176,32,0.4);color:#00b020}
@keyframes fu{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ══ الموقع العام index.php ══ */
.navbar{background:rgba(20,20,20,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(155%) !important;backdrop-filter:blur(22px) saturate(155%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.nav-logo-text{color:#e50914 !important}
.nav-btn{background:rgba(255,255,255,.07) !important;border:1px solid rgba(255,255,255,.09) !important;color:#b3b3b3 !important}
.nav-btn:hover{background:rgba(229,9,20,0.22) !important;border-color:#e50914 !important;color:#ffffff !important}
.search-wrap input{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#ffffff !important}
.search-wrap input::placeholder{color:#6f6f6f}
.search-wrap input:focus{background:rgba(255,255,255,.09) !important;border-color:#e50914 !important}
.cat-navbar{background:rgba(20,20,20,0.72) !important;-webkit-backdrop-filter:blur(20px) saturate(150%) !important;backdrop-filter:blur(20px) saturate(150%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.cat-nav-btn{background:rgba(255,255,255,.055) !important;border:1px solid rgba(255,255,255,.09) !important;color:#b3b3b3 !important;font-weight:650 !important}
.cat-nav-btn:hover{background:rgba(255,255,255,.11) !important;border-color:rgba(255,255,255,.2) !important;color:#ffffff !important}
.cat-nav-btn.active{background:linear-gradient(135deg,#b00610,#e50914) !important;border-color:transparent !important;color:#ffffff !important;box-shadow:0 4px 16px rgba(229,9,20,0.38) !important}
.fp-panel,.np-panel,.m3u-panel,.ep-panel,.shs-catmenu-panel{background:rgba(20,20,20,0.82) !important;-webkit-backdrop-filter:blur(28px) saturate(155%) !important;backdrop-filter:blur(28px) saturate(155%) !important;border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:0 0 50px rgba(0,0,0,.55) !important}
.panel-overlay,.shs-catmenu-overlay,.tmdb-modal-overlay{background:rgba(0,0,0,.65) !important;-webkit-backdrop-filter:blur(8px) !important;backdrop-filter:blur(8px) !important}
.netflix-card,.nx-card,.ch-card,.sr-card{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:6px !important;box-shadow:0 6px 24px rgba(0,0,0,.4) !important}
.netflix-card:hover,.nx-card:hover{border-color:rgba(229,9,20,0.5) !important;box-shadow:0 12px 36px rgba(0,0,0,.55),0 0 0 1px rgba(229,9,20,0.25) !important}
.shs-catview-banner{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;-webkit-backdrop-filter:blur(16px) saturate(140%);backdrop-filter:blur(16px) saturate(140%)}
.shs-catmenu-item:hover{background:rgba(255,255,255,.07)}
.shs-empty-ico{background:radial-gradient(120% 120% at 30% 20%,rgba(229,9,20,0.16),rgba(255,255,255,.03));color:#e50914}
.shs-spinner::before{border-top-color:#e50914;border-right-color:#e50914}
.hero-btn-play,.btn-play{background:linear-gradient(135deg,#b00610,#e50914) !important;color:#ffffff !important}
/* ══ إصلاح أيقونات شريط الأقسام ══ */
.cat-nav-btn i,.cat-nav-btn svg,.cat-nav-btn .lcn,.cat-nav-btn .lcn svg{
 color:#b3b3b3 !important;stroke:currentColor !important;fill:none !important;
 width:1em;height:1em;flex-shrink:0;opacity:.9;transition:color .2s}
.cat-nav-btn:hover i,.cat-nav-btn:hover svg,.cat-nav-btn:hover .lcn,.cat-nav-btn:hover .lcn svg{
 color:#ffffff !important;opacity:1}
.cat-nav-btn.active i,.cat-nav-btn.active svg,.cat-nav-btn.active .lcn,.cat-nav-btn.active .lcn svg{
 color:#ffffff !important;opacity:1}
.cat-nav-btn .lcn{display:inline-flex;align-items:center;justify-content:center;line-height:0}
/* أيقونات القائمة الجانبية للأقسام */
.shs-catmenu-item i,.shs-catmenu-item svg,.shs-catmenu-item .lcn svg{
 color:#e50914 !important;stroke:currentColor !important;fill:none !important}
/* أيقونات أزرار التنقّل العلوية */
.nav-btn i,.nav-btn svg,.nav-btn .lcn svg{stroke:currentColor !important;fill:none !important}`
    },
    dark: {
        name: 'Dark Enhanced',
        css: `:root{--red:#58a6ff;--redg:rgba(88,166,255,0.3);--gold:#1f6feb;--s0:#0d1117;--s1:#161b22;--s2:#1c2128;--s3:#22272e;--s4:#2d333b;--br:rgba(255,255,255,.09);--brh:rgba(255,255,255,.2);--t1:#e6edf3;--t2:#9aa5b1;--t3:#6e7681;--r1:10px}

/* خلفية */
html{background:#0d1117}
body{background:#0d1117 !important;color:#e6edf3;position:relative;min-height:100vh}
body::before{content:"";position:fixed;inset:0;z-index:-2;pointer-events:none;background:
  radial-gradient(60% 50% at 20% 10%,rgba(88,166,255,0.1),transparent 62%),
  radial-gradient(60% 50% at 85% 85%,rgba(31,111,235,0.08),transparent 62%);
  animation:thDrift 30s ease-in-out infinite alternate}
@keyframes thDrift{0%{transform:scale(1)}100%{transform:scale(1.08)}}
body,.si,.fi,.fs,td,th,p{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.stitle,.tbtitle,.sbrand-name{color:#e6edf3;font-weight:750;letter-spacing:-.01em}
.card p,.sc p{color:#9aa5b1;line-height:1.75}
.snl{color:#6e7681;letter-spacing:.08em;font-weight:600}

/* ══ لوحة التحكم ══ */
.sidebar{background:rgba(22,27,34,0.82) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:-1px 0 40px rgba(0,0,0,.4)}
.sidebar::after{background:linear-gradient(180deg,rgba(88,166,255,0.6),transparent)}
.sbrand{background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.09)}
.sbrand-icon{background:linear-gradient(135deg,#1f6feb,#58a6ff);color:#ffffff;box-shadow:0 4px 16px rgba(88,166,255,0.3)}
.sbrand-sub{color:#6e7681}
.si{color:#9aa5b1;font-weight:600}
.si:hover{background:rgba(255,255,255,.06);color:#e6edf3}
.si.on{background:rgba(88,166,255,0.12);color:#e6edf3}
.si.on::before{background:#58a6ff}
.si.on .si-ic{color:#58a6ff}
.topbar{background:rgba(22,27,34,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-bottom:1px solid rgba(255,255,255,.09) !important}
.main{background:transparent !important}
.card,.tw,.swc,.bkc,.sc,.mbox,.pcard,.theme-panel{background:rgba(255,255,255,.05) !important;-webkit-backdrop-filter:blur(16px) saturate(140%) !important;backdrop-filter:blur(16px) saturate(140%) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:12px !important;box-shadow:0 8px 30px rgba(0,0,0,.4),inset 0 1px 0 rgba(255,255,255,.06) !important}
.sc:hover{background:rgba(255,255,255,.08) !important;border-color:rgba(255,255,255,.2) !important;box-shadow:0 12px 38px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.06) !important}
.fi,.fs{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#e6edf3 !important;border-radius:8px}
.fi::placeholder{color:#6e7681}
.fi:focus,.fs:focus{border-color:#58a6ff !important;background:rgba(255,255,255,.08) !important;box-shadow:0 0 0 3px rgba(88,166,255,0.18) !important}
.btn-p{background:linear-gradient(135deg,#1f6feb,#58a6ff) !important;border:none !important;color:#ffffff !important;font-weight:700;border-radius:8px;box-shadow:0 4px 18px rgba(88,166,255,0.32)}
.btn-p:hover{filter:brightness(1.1);box-shadow:0 6px 24px rgba(88,166,255,0.45)}
.ib{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);color:#9aa5b1;border-radius:7px}
.ib:hover{background:rgba(255,255,255,.12);color:#e6edf3;border-color:rgba(255,255,255,.2)}
.uavt{background:linear-gradient(135deg,#1f6feb,#58a6ff);color:#ffffff}
.lic-dot{background:#3fb950;box-shadow:0 0 8px rgba(63,185,80,0.7)}
.lic-b{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.09);color:#9aa5b1}
.mhd,.mfooter{background:rgba(255,255,255,.035);border-color:rgba(255,255,255,.09)}
thead tr{background:rgba(255,255,255,.05)}
th{color:#6e7681;font-weight:700;letter-spacing:.04em}
td{color:#e6edf3;border-color:rgba(255,255,255,.06)}
tr:hover td{background:rgba(255,255,255,.045)}
.pw .pb{background:linear-gradient(90deg,#1f6feb,#58a6ff)}
.mbd,#pm{background:rgba(0,0,0,.8) !important;-webkit-backdrop-filter:blur(14px) !important;backdrop-filter:blur(14px) !important}
.al-s{background:rgba(63,185,80,0.12);border-color:rgba(63,185,80,0.4);color:#3fb950}
@keyframes fu{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ══ الموقع العام index.php ══ */
.navbar{background:rgba(22,27,34,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(155%) !important;backdrop-filter:blur(22px) saturate(155%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.nav-logo-text{color:#58a6ff !important}
.nav-btn{background:rgba(255,255,255,.07) !important;border:1px solid rgba(255,255,255,.09) !important;color:#9aa5b1 !important}
.nav-btn:hover{background:rgba(88,166,255,0.22) !important;border-color:#58a6ff !important;color:#e6edf3 !important}
.search-wrap input{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#e6edf3 !important}
.search-wrap input::placeholder{color:#6e7681}
.search-wrap input:focus{background:rgba(255,255,255,.09) !important;border-color:#58a6ff !important}
.cat-navbar{background:rgba(22,27,34,0.72) !important;-webkit-backdrop-filter:blur(20px) saturate(150%) !important;backdrop-filter:blur(20px) saturate(150%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.cat-nav-btn{background:rgba(255,255,255,.055) !important;border:1px solid rgba(255,255,255,.09) !important;color:#9aa5b1 !important;font-weight:650 !important}
.cat-nav-btn:hover{background:rgba(255,255,255,.11) !important;border-color:rgba(255,255,255,.2) !important;color:#e6edf3 !important}
.cat-nav-btn.active{background:linear-gradient(135deg,#1f6feb,#58a6ff) !important;border-color:transparent !important;color:#ffffff !important;box-shadow:0 4px 16px rgba(88,166,255,0.38) !important}
.fp-panel,.np-panel,.m3u-panel,.ep-panel,.shs-catmenu-panel{background:rgba(22,27,34,0.82) !important;-webkit-backdrop-filter:blur(28px) saturate(155%) !important;backdrop-filter:blur(28px) saturate(155%) !important;border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:0 0 50px rgba(0,0,0,.55) !important}
.panel-overlay,.shs-catmenu-overlay,.tmdb-modal-overlay{background:rgba(0,0,0,.65) !important;-webkit-backdrop-filter:blur(8px) !important;backdrop-filter:blur(8px) !important}
.netflix-card,.nx-card,.ch-card,.sr-card{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:10px !important;box-shadow:0 6px 24px rgba(0,0,0,.4) !important}
.netflix-card:hover,.nx-card:hover{border-color:rgba(88,166,255,0.5) !important;box-shadow:0 12px 36px rgba(0,0,0,.55),0 0 0 1px rgba(88,166,255,0.25) !important}
.shs-catview-banner{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;-webkit-backdrop-filter:blur(16px) saturate(140%);backdrop-filter:blur(16px) saturate(140%)}
.shs-catmenu-item:hover{background:rgba(255,255,255,.07)}
.shs-empty-ico{background:radial-gradient(120% 120% at 30% 20%,rgba(88,166,255,0.16),rgba(255,255,255,.03));color:#58a6ff}
.shs-spinner::before{border-top-color:#58a6ff;border-right-color:#58a6ff}
.hero-btn-play,.btn-play{background:linear-gradient(135deg,#1f6feb,#58a6ff) !important;color:#ffffff !important}
/* ══ إصلاح أيقونات شريط الأقسام ══ */
.cat-nav-btn i,.cat-nav-btn svg,.cat-nav-btn .lcn,.cat-nav-btn .lcn svg{
 color:#9aa5b1 !important;stroke:currentColor !important;fill:none !important;
 width:1em;height:1em;flex-shrink:0;opacity:.9;transition:color .2s}
.cat-nav-btn:hover i,.cat-nav-btn:hover svg,.cat-nav-btn:hover .lcn,.cat-nav-btn:hover .lcn svg{
 color:#e6edf3 !important;opacity:1}
.cat-nav-btn.active i,.cat-nav-btn.active svg,.cat-nav-btn.active .lcn,.cat-nav-btn.active .lcn svg{
 color:#ffffff !important;opacity:1}
.cat-nav-btn .lcn{display:inline-flex;align-items:center;justify-content:center;line-height:0}
/* أيقونات القائمة الجانبية للأقسام */
.shs-catmenu-item i,.shs-catmenu-item svg,.shs-catmenu-item .lcn svg{
 color:#58a6ff !important;stroke:currentColor !important;fill:none !important}
/* أيقونات أزرار التنقّل العلوية */
.nav-btn i,.nav-btn svg,.nav-btn .lcn svg{stroke:currentColor !important;fill:none !important}`
    },
    neon: {
        name: 'Neon Cyberpunk',
        css: `:root{--red:#00ff88;--redg:rgba(0,255,136,0.5);--gold:#00ccff;--s0:#03000a;--s1:#0a0018;--s2:#100024;--s3:#160030;--s4:#1e003e;--br:rgba(255,255,255,.09);--brh:rgba(255,255,255,.2);--t1:#e8fff0;--t2:#8affb0;--t3:#4a8a68;--r1:8px}

/* خلفية */
html{background:#03000a}
body{background:#03000a !important;color:#e8fff0;position:relative;min-height:100vh}
body::before{content:"";position:fixed;inset:0;z-index:-2;pointer-events:none;background:
  radial-gradient(60% 50% at 15% 12%,rgba(0,255,136,0.14),transparent 62%),
  radial-gradient(60% 50% at 85% 18%,rgba(255,0,255,0.12),transparent 62%),
  radial-gradient(60% 50% at 75% 88%,rgba(0,204,255,0.1),transparent 62%);
  animation:thDrift 30s ease-in-out infinite alternate}
@keyframes thDrift{0%{transform:scale(1)}100%{transform:scale(1.08)}}
body,.si,.fi,.fs,td,th,p{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.stitle,.tbtitle,.sbrand-name{color:#e8fff0;font-weight:750;letter-spacing:-.01em}
.card p,.sc p{color:#8affb0;line-height:1.75}
.snl{color:#4a8a68;letter-spacing:.08em;font-weight:600}

/* ══ لوحة التحكم ══ */
.sidebar{background:rgba(10,0,24,0.82) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:-1px 0 40px rgba(0,0,0,.4)}
.sidebar::after{background:linear-gradient(180deg,rgba(0,255,136,0.6),transparent)}
.sbrand{background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.09)}
.sbrand-icon{background:linear-gradient(135deg,#00ccff,#00ff88);color:#ffffff;box-shadow:0 4px 16px rgba(0,255,136,0.3)}
.sbrand-sub{color:#4a8a68}
.si{color:#8affb0;font-weight:600}
.si:hover{background:rgba(255,255,255,.06);color:#e8fff0}
.si.on{background:rgba(0,255,136,0.12);color:#e8fff0}
.si.on::before{background:#00ff88}
.si.on .si-ic{color:#00ff88}
.topbar{background:rgba(10,0,24,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-bottom:1px solid rgba(255,255,255,.09) !important}
.main{background:transparent !important}
.card,.tw,.swc,.bkc,.sc,.mbox,.pcard,.theme-panel{background:rgba(255,255,255,.05) !important;-webkit-backdrop-filter:blur(16px) saturate(140%) !important;backdrop-filter:blur(16px) saturate(140%) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:10px !important;box-shadow:0 8px 30px rgba(0,0,0,.4),inset 0 1px 0 rgba(255,255,255,.06) !important}
.sc:hover{background:rgba(255,255,255,.08) !important;border-color:rgba(255,255,255,.2) !important;box-shadow:0 12px 38px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.06) !important}
.fi,.fs{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#e8fff0 !important;border-radius:6px}
.fi::placeholder{color:#4a8a68}
.fi:focus,.fs:focus{border-color:#00ff88 !important;background:rgba(255,255,255,.08) !important;box-shadow:0 0 0 3px rgba(0,255,136,0.18) !important}
.btn-p{background:linear-gradient(135deg,#00ccff,#00ff88) !important;border:none !important;color:#ffffff !important;font-weight:700;border-radius:6px;box-shadow:0 4px 18px rgba(0,255,136,0.32)}
.btn-p:hover{filter:brightness(1.1);box-shadow:0 6px 24px rgba(0,255,136,0.45)}
.ib{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);color:#8affb0;border-radius:5px}
.ib:hover{background:rgba(255,255,255,.12);color:#e8fff0;border-color:rgba(255,255,255,.2)}
.uavt{background:linear-gradient(135deg,#00ccff,#00ff88);color:#ffffff}
.lic-dot{background:#00ff88;box-shadow:0 0 8px rgba(0,255,136,0.7)}
.lic-b{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.09);color:#8affb0}
.mhd,.mfooter{background:rgba(255,255,255,.035);border-color:rgba(255,255,255,.09)}
thead tr{background:rgba(255,255,255,.05)}
th{color:#4a8a68;font-weight:700;letter-spacing:.04em}
td{color:#e8fff0;border-color:rgba(255,255,255,.06)}
tr:hover td{background:rgba(255,255,255,.045)}
.pw .pb{background:linear-gradient(90deg,#00ccff,#00ff88)}
.mbd,#pm{background:rgba(0,0,0,.8) !important;-webkit-backdrop-filter:blur(14px) !important;backdrop-filter:blur(14px) !important}
.al-s{background:rgba(0,255,136,0.12);border-color:rgba(0,255,136,0.4);color:#00ff88}
@keyframes fu{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ══ الموقع العام index.php ══ */
.navbar{background:rgba(10,0,24,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(155%) !important;backdrop-filter:blur(22px) saturate(155%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.nav-logo-text{color:#00ff88 !important}
.nav-btn{background:rgba(255,255,255,.07) !important;border:1px solid rgba(255,255,255,.09) !important;color:#8affb0 !important}
.nav-btn:hover{background:rgba(0,255,136,0.22) !important;border-color:#00ff88 !important;color:#e8fff0 !important}
.search-wrap input{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#e8fff0 !important}
.search-wrap input::placeholder{color:#4a8a68}
.search-wrap input:focus{background:rgba(255,255,255,.09) !important;border-color:#00ff88 !important}
.cat-navbar{background:rgba(10,0,24,0.72) !important;-webkit-backdrop-filter:blur(20px) saturate(150%) !important;backdrop-filter:blur(20px) saturate(150%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.cat-nav-btn{background:rgba(255,255,255,.055) !important;border:1px solid rgba(255,255,255,.09) !important;color:#8affb0 !important;font-weight:650 !important}
.cat-nav-btn:hover{background:rgba(255,255,255,.11) !important;border-color:rgba(255,255,255,.2) !important;color:#e8fff0 !important}
.cat-nav-btn.active{background:linear-gradient(135deg,#00ccff,#00ff88) !important;border-color:transparent !important;color:#ffffff !important;box-shadow:0 4px 16px rgba(0,255,136,0.38) !important}
.fp-panel,.np-panel,.m3u-panel,.ep-panel,.shs-catmenu-panel{background:rgba(10,0,24,0.82) !important;-webkit-backdrop-filter:blur(28px) saturate(155%) !important;backdrop-filter:blur(28px) saturate(155%) !important;border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:0 0 50px rgba(0,0,0,.55) !important}
.panel-overlay,.shs-catmenu-overlay,.tmdb-modal-overlay{background:rgba(0,0,0,.65) !important;-webkit-backdrop-filter:blur(8px) !important;backdrop-filter:blur(8px) !important}
.netflix-card,.nx-card,.ch-card,.sr-card{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:8px !important;box-shadow:0 6px 24px rgba(0,0,0,.4) !important}
.netflix-card:hover,.nx-card:hover{border-color:rgba(0,255,136,0.5) !important;box-shadow:0 12px 36px rgba(0,0,0,.55),0 0 0 1px rgba(0,255,136,0.25) !important}
.shs-catview-banner{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;-webkit-backdrop-filter:blur(16px) saturate(140%);backdrop-filter:blur(16px) saturate(140%)}
.shs-catmenu-item:hover{background:rgba(255,255,255,.07)}
.shs-empty-ico{background:radial-gradient(120% 120% at 30% 20%,rgba(0,255,136,0.16),rgba(255,255,255,.03));color:#00ff88}
.shs-spinner::before{border-top-color:#00ff88;border-right-color:#00ff88}
.hero-btn-play,.btn-play{background:linear-gradient(135deg,#00ccff,#00ff88) !important;color:#ffffff !important}
/* ══ إصلاح أيقونات شريط الأقسام ══ */
.cat-nav-btn i,.cat-nav-btn svg,.cat-nav-btn .lcn,.cat-nav-btn .lcn svg{
 color:#8affb0 !important;stroke:currentColor !important;fill:none !important;
 width:1em;height:1em;flex-shrink:0;opacity:.9;transition:color .2s}
.cat-nav-btn:hover i,.cat-nav-btn:hover svg,.cat-nav-btn:hover .lcn,.cat-nav-btn:hover .lcn svg{
 color:#e8fff0 !important;opacity:1}
.cat-nav-btn.active i,.cat-nav-btn.active svg,.cat-nav-btn.active .lcn,.cat-nav-btn.active .lcn svg{
 color:#ffffff !important;opacity:1}
.cat-nav-btn .lcn{display:inline-flex;align-items:center;justify-content:center;line-height:0}
/* أيقونات القائمة الجانبية للأقسام */
.shs-catmenu-item i,.shs-catmenu-item svg,.shs-catmenu-item .lcn svg{
 color:#00ff88 !important;stroke:currentColor !important;fill:none !important}
/* أيقونات أزرار التنقّل العلوية */
.nav-btn i,.nav-btn svg,.nav-btn .lcn svg{stroke:currentColor !important;fill:none !important}`
    },
    minimal: {
        name: 'Minimal Clean',
        css: `:root{--red:#2563eb;--redg:rgba(37,99,235,0.22);--gold:#1d4ed8;--s0:#f8fafc;--s1:#ffffff;--s2:#f1f5f9;--s3:#e2e8f0;--s4:#cbd5e1;--br:rgba(0,0,0,.1);--brh:rgba(0,0,0,.2);--t1:#0f172a;--t2:#475569;--t3:#94a3b8;--r1:10px;--sw:250px}

/* خلفية */
html{background:#f8fafc}
body{background:#f8fafc !important;color:#0f172a;position:relative;min-height:100vh}
body::before{content:"";position:fixed;inset:0;z-index:-2;pointer-events:none;background:
  radial-gradient(60% 50% at 20% 10%,rgba(37,99,235,0.05),transparent 62%),
  radial-gradient(60% 50% at 85% 80%,rgba(139,92,246,0.04),transparent 62%);
  animation:thDrift 30s ease-in-out infinite alternate}
@keyframes thDrift{0%{transform:scale(1)}100%{transform:scale(1.08)}}
body,.si,.fi,.fs,td,th,p{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.stitle,.tbtitle,.sbrand-name{color:#0f172a;font-weight:750;letter-spacing:-.01em}
.card p,.sc p{color:#475569;line-height:1.75}
.snl{color:#94a3b8;letter-spacing:.08em;font-weight:600}

/* ══ لوحة التحكم ══ */
.sidebar{background:rgba(255,255,255,.85) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-left:1px solid rgba(0,0,0,.1) !important;box-shadow:-1px 0 40px rgba(15,23,42,.1)}
.sidebar::after{background:linear-gradient(180deg,rgba(37,99,235,0.6),transparent)}
.sbrand{background:rgba(0,0,0,.03);border-bottom:1px solid rgba(0,0,0,.1)}
.sbrand-icon{background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#ffffff;box-shadow:0 4px 16px rgba(37,99,235,0.3)}
.sbrand-sub{color:#94a3b8}
.si{color:#475569;font-weight:600}
.si:hover{background:rgba(0,0,0,.06);color:#0f172a}
.si.on{background:rgba(37,99,235,0.12);color:#0f172a}
.si.on::before{background:#2563eb}
.si.on .si-ic{color:#2563eb}
.topbar{background:rgba(255,255,255,.72) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-bottom:1px solid rgba(0,0,0,.1) !important}
.main{background:transparent !important}
.card,.tw,.swc,.bkc,.sc,.mbox,.pcard,.theme-panel{background:rgba(255,255,255,.75) !important;-webkit-backdrop-filter:blur(16px) saturate(140%) !important;backdrop-filter:blur(16px) saturate(140%) !important;border:1px solid rgba(0,0,0,.1) !important;border-radius:12px !important;box-shadow:0 8px 30px rgba(15,23,42,.1),inset 0 1px 0 rgba(255,255,255,.9) !important}
.sc:hover{background:rgba(255,255,255,.9) !important;border-color:rgba(0,0,0,.2) !important;box-shadow:0 12px 38px rgba(15,23,42,.16),inset 0 1px 0 rgba(255,255,255,.9) !important}
.fi,.fs{background:rgba(0,0,0,.03) !important;border:1px solid rgba(0,0,0,.1) !important;color:#0f172a !important;border-radius:8px}
.fi::placeholder{color:#94a3b8}
.fi:focus,.fs:focus{border-color:#2563eb !important;background:rgba(0,0,0,.05) !important;box-shadow:0 0 0 3px rgba(37,99,235,0.18) !important}
.btn-p{background:linear-gradient(135deg,#1d4ed8,#2563eb) !important;border:none !important;color:#ffffff !important;font-weight:700;border-radius:8px;box-shadow:0 4px 18px rgba(37,99,235,0.32)}
.btn-p:hover{filter:brightness(1.1);box-shadow:0 6px 24px rgba(37,99,235,0.45)}
.ib{background:rgba(0,0,0,.06);border:1px solid rgba(0,0,0,.1);color:#475569;border-radius:7px}
.ib:hover{background:rgba(0,0,0,.12);color:#0f172a;border-color:rgba(0,0,0,.2)}
.uavt{background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#ffffff}
.lic-dot{background:#16a34a;box-shadow:0 0 8px rgba(22,163,74,0.7)}
.lic-b{background:rgba(0,0,0,.05);border-color:rgba(0,0,0,.1);color:#475569}
.mhd,.mfooter{background:rgba(0,0,0,.035);border-color:rgba(0,0,0,.1)}
thead tr{background:rgba(0,0,0,.05)}
th{color:#94a3b8;font-weight:700;letter-spacing:.04em}
td{color:#0f172a;border-color:rgba(0,0,0,.06)}
tr:hover td{background:rgba(0,0,0,.045)}
.pw .pb{background:linear-gradient(90deg,#1d4ed8,#2563eb)}
.mbd,#pm{background:rgba(255,255,255,.5) !important;-webkit-backdrop-filter:blur(14px) !important;backdrop-filter:blur(14px) !important}
.al-s{background:rgba(22,163,74,0.12);border-color:rgba(22,163,74,0.4);color:#16a34a}
@keyframes fu{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ══ الموقع العام index.php ══ */
.navbar{background:rgba(255,255,255,.72) !important;-webkit-backdrop-filter:blur(22px) saturate(155%) !important;backdrop-filter:blur(22px) saturate(155%) !important;border-bottom:1px solid rgba(0,0,0,.1) !important}
.nav-logo-text{color:#2563eb !important}
.nav-btn{background:rgba(0,0,0,.07) !important;border:1px solid rgba(0,0,0,.1) !important;color:#475569 !important}
.nav-btn:hover{background:rgba(37,99,235,0.22) !important;border-color:#2563eb !important;color:#0f172a !important}
.search-wrap input{background:rgba(0,0,0,.05) !important;border:1px solid rgba(0,0,0,.1) !important;color:#0f172a !important}
.search-wrap input::placeholder{color:#94a3b8}
.search-wrap input:focus{background:rgba(0,0,0,.09) !important;border-color:#2563eb !important}
.cat-navbar{background:rgba(255,255,255,.72) !important;-webkit-backdrop-filter:blur(20px) saturate(150%) !important;backdrop-filter:blur(20px) saturate(150%) !important;border-bottom:1px solid rgba(0,0,0,.1) !important}
.cat-nav-btn{background:rgba(0,0,0,.055) !important;border:1px solid rgba(0,0,0,.1) !important;color:#475569 !important;font-weight:650 !important}
.cat-nav-btn:hover{background:rgba(0,0,0,.11) !important;border-color:rgba(0,0,0,.2) !important;color:#0f172a !important}
.cat-nav-btn.active{background:linear-gradient(135deg,#1d4ed8,#2563eb) !important;border-color:transparent !important;color:#ffffff !important;box-shadow:0 4px 16px rgba(37,99,235,0.38) !important}
.fp-panel,.np-panel,.m3u-panel,.ep-panel,.shs-catmenu-panel{background:rgba(255,255,255,.85) !important;-webkit-backdrop-filter:blur(28px) saturate(155%) !important;backdrop-filter:blur(28px) saturate(155%) !important;border-left:1px solid rgba(0,0,0,.1) !important;box-shadow:0 0 50px rgba(15,23,42,.16) !important}
.panel-overlay,.shs-catmenu-overlay,.tmdb-modal-overlay{background:rgba(255,255,255,.35) !important;-webkit-backdrop-filter:blur(8px) !important;backdrop-filter:blur(8px) !important}
.netflix-card,.nx-card,.ch-card,.sr-card{background:rgba(255,255,255,.75) !important;border:1px solid rgba(0,0,0,.1) !important;border-radius:10px !important;box-shadow:0 6px 24px rgba(15,23,42,.1) !important}
.netflix-card:hover,.nx-card:hover{border-color:rgba(37,99,235,0.5) !important;box-shadow:0 12px 36px rgba(15,23,42,.16),0 0 0 1px rgba(37,99,235,0.25) !important}
.shs-catview-banner{background:rgba(255,255,255,.75) !important;border:1px solid rgba(0,0,0,.1) !important;-webkit-backdrop-filter:blur(16px) saturate(140%);backdrop-filter:blur(16px) saturate(140%)}
.shs-catmenu-item:hover{background:rgba(0,0,0,.07)}
.shs-empty-ico{background:radial-gradient(120% 120% at 30% 20%,rgba(37,99,235,0.16),rgba(0,0,0,.03));color:#2563eb}
.shs-spinner::before{border-top-color:#2563eb;border-right-color:#2563eb}
.hero-btn-play,.btn-play{background:linear-gradient(135deg,#1d4ed8,#2563eb) !important;color:#ffffff !important}
/* ══ إصلاح أيقونات شريط الأقسام ══ */
.cat-nav-btn i,.cat-nav-btn svg,.cat-nav-btn .lcn,.cat-nav-btn .lcn svg{
 color:#475569 !important;stroke:currentColor !important;fill:none !important;
 width:1em;height:1em;flex-shrink:0;opacity:.9;transition:color .2s}
.cat-nav-btn:hover i,.cat-nav-btn:hover svg,.cat-nav-btn:hover .lcn,.cat-nav-btn:hover .lcn svg{
 color:#0f172a !important;opacity:1}
.cat-nav-btn.active i,.cat-nav-btn.active svg,.cat-nav-btn.active .lcn,.cat-nav-btn.active .lcn svg{
 color:#ffffff !important;opacity:1}
.cat-nav-btn .lcn{display:inline-flex;align-items:center;justify-content:center;line-height:0}
/* أيقونات القائمة الجانبية للأقسام */
.shs-catmenu-item i,.shs-catmenu-item svg,.shs-catmenu-item .lcn svg{
 color:#2563eb !important;stroke:currentColor !important;fill:none !important}
/* أيقونات أزرار التنقّل العلوية */
.nav-btn i,.nav-btn svg,.nav-btn .lcn svg{stroke:currentColor !important;fill:none !important}`
    },
    midnight: {
        name: 'Midnight Blue',
        css: `:root{--red:#7aa2ff;--redg:rgba(122,162,255,0.35);--gold:#3b5bdb;--s0:#070d24;--s1:#0b1437;--s2:#111c47;--s3:#172456;--s4:#1f2f6b;--br:rgba(255,255,255,.09);--brh:rgba(255,255,255,.2);--t1:#e8eeff;--t2:#9fb3e0;--t3:#5a6a99;--r1:12px}

/* خلفية */
html{background:#070d24}
body{background:#070d24 !important;color:#e8eeff;position:relative;min-height:100vh}
body::before{content:"";position:fixed;inset:0;z-index:-2;pointer-events:none;background:
  radial-gradient(60% 50% at 18% 10%,rgba(122,162,255,0.14),transparent 62%),
  radial-gradient(60% 50% at 85% 85%,rgba(59,91,219,0.12),transparent 62%),
  radial-gradient(60% 50% at 75% 15%,rgba(255,209,102,0.05),transparent 62%);
  animation:thDrift 30s ease-in-out infinite alternate}
@keyframes thDrift{0%{transform:scale(1)}100%{transform:scale(1.08)}}
body,.si,.fi,.fs,td,th,p{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.stitle,.tbtitle,.sbrand-name{color:#e8eeff;font-weight:750;letter-spacing:-.01em}
.card p,.sc p{color:#9fb3e0;line-height:1.75}
.snl{color:#5a6a99;letter-spacing:.08em;font-weight:600}

/* ══ لوحة التحكم ══ */
.sidebar{background:rgba(11,20,55,0.82) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:-1px 0 40px rgba(0,0,0,.4)}
.sidebar::after{background:linear-gradient(180deg,rgba(122,162,255,0.6),transparent)}
.sbrand{background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.09)}
.sbrand-icon{background:linear-gradient(135deg,#3b5bdb,#7aa2ff);color:#ffffff;box-shadow:0 4px 16px rgba(122,162,255,0.3)}
.sbrand-sub{color:#5a6a99}
.si{color:#9fb3e0;font-weight:600}
.si:hover{background:rgba(255,255,255,.06);color:#e8eeff}
.si.on{background:rgba(122,162,255,0.12);color:#e8eeff}
.si.on::before{background:#7aa2ff}
.si.on .si-ic{color:#7aa2ff}
.topbar{background:rgba(11,20,55,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-bottom:1px solid rgba(255,255,255,.09) !important}
.main{background:transparent !important}
.card,.tw,.swc,.bkc,.sc,.mbox,.pcard,.theme-panel{background:rgba(255,255,255,.05) !important;-webkit-backdrop-filter:blur(16px) saturate(140%) !important;backdrop-filter:blur(16px) saturate(140%) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:14px !important;box-shadow:0 8px 30px rgba(0,0,0,.4),inset 0 1px 0 rgba(255,255,255,.06) !important}
.sc:hover{background:rgba(255,255,255,.08) !important;border-color:rgba(255,255,255,.2) !important;box-shadow:0 12px 38px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.06) !important}
.fi,.fs{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#e8eeff !important;border-radius:10px}
.fi::placeholder{color:#5a6a99}
.fi:focus,.fs:focus{border-color:#7aa2ff !important;background:rgba(255,255,255,.08) !important;box-shadow:0 0 0 3px rgba(122,162,255,0.18) !important}
.btn-p{background:linear-gradient(135deg,#3b5bdb,#7aa2ff) !important;border:none !important;color:#ffffff !important;font-weight:700;border-radius:10px;box-shadow:0 4px 18px rgba(122,162,255,0.32)}
.btn-p:hover{filter:brightness(1.1);box-shadow:0 6px 24px rgba(122,162,255,0.45)}
.ib{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);color:#9fb3e0;border-radius:9px}
.ib:hover{background:rgba(255,255,255,.12);color:#e8eeff;border-color:rgba(255,255,255,.2)}
.uavt{background:linear-gradient(135deg,#3b5bdb,#7aa2ff);color:#ffffff}
.lic-dot{background:#4ade80;box-shadow:0 0 8px rgba(74,222,128,0.7)}
.lic-b{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.09);color:#9fb3e0}
.mhd,.mfooter{background:rgba(255,255,255,.035);border-color:rgba(255,255,255,.09)}
thead tr{background:rgba(255,255,255,.05)}
th{color:#5a6a99;font-weight:700;letter-spacing:.04em}
td{color:#e8eeff;border-color:rgba(255,255,255,.06)}
tr:hover td{background:rgba(255,255,255,.045)}
.pw .pb{background:linear-gradient(90deg,#3b5bdb,#7aa2ff)}
.mbd,#pm{background:rgba(0,0,0,.8) !important;-webkit-backdrop-filter:blur(14px) !important;backdrop-filter:blur(14px) !important}
.al-s{background:rgba(74,222,128,0.12);border-color:rgba(74,222,128,0.4);color:#4ade80}
@keyframes fu{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ══ الموقع العام index.php ══ */
.navbar{background:rgba(11,20,55,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(155%) !important;backdrop-filter:blur(22px) saturate(155%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.nav-logo-text{color:#7aa2ff !important}
.nav-btn{background:rgba(255,255,255,.07) !important;border:1px solid rgba(255,255,255,.09) !important;color:#9fb3e0 !important}
.nav-btn:hover{background:rgba(122,162,255,0.22) !important;border-color:#7aa2ff !important;color:#e8eeff !important}
.search-wrap input{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#e8eeff !important}
.search-wrap input::placeholder{color:#5a6a99}
.search-wrap input:focus{background:rgba(255,255,255,.09) !important;border-color:#7aa2ff !important}
.cat-navbar{background:rgba(11,20,55,0.72) !important;-webkit-backdrop-filter:blur(20px) saturate(150%) !important;backdrop-filter:blur(20px) saturate(150%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.cat-nav-btn{background:rgba(255,255,255,.055) !important;border:1px solid rgba(255,255,255,.09) !important;color:#9fb3e0 !important;font-weight:650 !important}
.cat-nav-btn:hover{background:rgba(255,255,255,.11) !important;border-color:rgba(255,255,255,.2) !important;color:#e8eeff !important}
.cat-nav-btn.active{background:linear-gradient(135deg,#3b5bdb,#7aa2ff) !important;border-color:transparent !important;color:#ffffff !important;box-shadow:0 4px 16px rgba(122,162,255,0.38) !important}
.fp-panel,.np-panel,.m3u-panel,.ep-panel,.shs-catmenu-panel{background:rgba(11,20,55,0.82) !important;-webkit-backdrop-filter:blur(28px) saturate(155%) !important;backdrop-filter:blur(28px) saturate(155%) !important;border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:0 0 50px rgba(0,0,0,.55) !important}
.panel-overlay,.shs-catmenu-overlay,.tmdb-modal-overlay{background:rgba(0,0,0,.65) !important;-webkit-backdrop-filter:blur(8px) !important;backdrop-filter:blur(8px) !important}
.netflix-card,.nx-card,.ch-card,.sr-card{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:12px !important;box-shadow:0 6px 24px rgba(0,0,0,.4) !important}
.netflix-card:hover,.nx-card:hover{border-color:rgba(122,162,255,0.5) !important;box-shadow:0 12px 36px rgba(0,0,0,.55),0 0 0 1px rgba(122,162,255,0.25) !important}
.shs-catview-banner{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;-webkit-backdrop-filter:blur(16px) saturate(140%);backdrop-filter:blur(16px) saturate(140%)}
.shs-catmenu-item:hover{background:rgba(255,255,255,.07)}
.shs-empty-ico{background:radial-gradient(120% 120% at 30% 20%,rgba(122,162,255,0.16),rgba(255,255,255,.03));color:#7aa2ff}
.shs-spinner::before{border-top-color:#7aa2ff;border-right-color:#7aa2ff}
.hero-btn-play,.btn-play{background:linear-gradient(135deg,#3b5bdb,#7aa2ff) !important;color:#ffffff !important}
/* ══ إصلاح أيقونات شريط الأقسام ══ */
.cat-nav-btn i,.cat-nav-btn svg,.cat-nav-btn .lcn,.cat-nav-btn .lcn svg{
 color:#9fb3e0 !important;stroke:currentColor !important;fill:none !important;
 width:1em;height:1em;flex-shrink:0;opacity:.9;transition:color .2s}
.cat-nav-btn:hover i,.cat-nav-btn:hover svg,.cat-nav-btn:hover .lcn,.cat-nav-btn:hover .lcn svg{
 color:#e8eeff !important;opacity:1}
.cat-nav-btn.active i,.cat-nav-btn.active svg,.cat-nav-btn.active .lcn,.cat-nav-btn.active .lcn svg{
 color:#ffffff !important;opacity:1}
.cat-nav-btn .lcn{display:inline-flex;align-items:center;justify-content:center;line-height:0}
/* أيقونات القائمة الجانبية للأقسام */
.shs-catmenu-item i,.shs-catmenu-item svg,.shs-catmenu-item .lcn svg{
 color:#7aa2ff !important;stroke:currentColor !important;fill:none !important}
/* أيقونات أزرار التنقّل العلوية */
.nav-btn i,.nav-btn svg,.nav-btn .lcn svg{stroke:currentColor !important;fill:none !important}`
    },
    emerald: {
        name: 'Emerald Luxe',
        css: `:root{--red:#10d98a;--redg:rgba(16,217,138,0.32);--gold:#059669;--s0:#03100b;--s1:#061a12;--s2:#0a2419;--s3:#0e2f20;--s4:#143d2a;--br:rgba(255,255,255,.09);--brh:rgba(255,255,255,.2);--t1:#e6fff4;--t2:#93dbbd;--t3:#4f7f69;--r1:12px}

/* خلفية */
html{background:#03100b}
body{background:#03100b !important;color:#e6fff4;position:relative;min-height:100vh}
body::before{content:"";position:fixed;inset:0;z-index:-2;pointer-events:none;background:
  radial-gradient(60% 50% at 18% 12%,rgba(16,217,138,0.13),transparent 62%),
  radial-gradient(60% 50% at 82% 85%,rgba(5,150,105,0.1),transparent 62%),
  radial-gradient(60% 50% at 80% 15%,rgba(255,217,125,0.05),transparent 62%);
  animation:thDrift 30s ease-in-out infinite alternate}
@keyframes thDrift{0%{transform:scale(1)}100%{transform:scale(1.08)}}
body,.si,.fi,.fs,td,th,p{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.stitle,.tbtitle,.sbrand-name{color:#e6fff4;font-weight:750;letter-spacing:-.01em}
.card p,.sc p{color:#93dbbd;line-height:1.75}
.snl{color:#4f7f69;letter-spacing:.08em;font-weight:600}

/* ══ لوحة التحكم ══ */
.sidebar{background:rgba(6,26,18,0.82) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:-1px 0 40px rgba(0,0,0,.4)}
.sidebar::after{background:linear-gradient(180deg,rgba(16,217,138,0.6),transparent)}
.sbrand{background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.09)}
.sbrand-icon{background:linear-gradient(135deg,#059669,#10d98a);color:#ffffff;box-shadow:0 4px 16px rgba(16,217,138,0.3)}
.sbrand-sub{color:#4f7f69}
.si{color:#93dbbd;font-weight:600}
.si:hover{background:rgba(255,255,255,.06);color:#e6fff4}
.si.on{background:rgba(16,217,138,0.12);color:#e6fff4}
.si.on::before{background:#10d98a}
.si.on .si-ic{color:#10d98a}
.topbar{background:rgba(6,26,18,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-bottom:1px solid rgba(255,255,255,.09) !important}
.main{background:transparent !important}
.card,.tw,.swc,.bkc,.sc,.mbox,.pcard,.theme-panel{background:rgba(255,255,255,.05) !important;-webkit-backdrop-filter:blur(16px) saturate(140%) !important;backdrop-filter:blur(16px) saturate(140%) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:14px !important;box-shadow:0 8px 30px rgba(0,0,0,.4),inset 0 1px 0 rgba(255,255,255,.06) !important}
.sc:hover{background:rgba(255,255,255,.08) !important;border-color:rgba(255,255,255,.2) !important;box-shadow:0 12px 38px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.06) !important}
.fi,.fs{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#e6fff4 !important;border-radius:10px}
.fi::placeholder{color:#4f7f69}
.fi:focus,.fs:focus{border-color:#10d98a !important;background:rgba(255,255,255,.08) !important;box-shadow:0 0 0 3px rgba(16,217,138,0.18) !important}
.btn-p{background:linear-gradient(135deg,#059669,#10d98a) !important;border:none !important;color:#ffffff !important;font-weight:700;border-radius:10px;box-shadow:0 4px 18px rgba(16,217,138,0.32)}
.btn-p:hover{filter:brightness(1.1);box-shadow:0 6px 24px rgba(16,217,138,0.45)}
.ib{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);color:#93dbbd;border-radius:9px}
.ib:hover{background:rgba(255,255,255,.12);color:#e6fff4;border-color:rgba(255,255,255,.2)}
.uavt{background:linear-gradient(135deg,#059669,#10d98a);color:#ffffff}
.lic-dot{background:#10d98a;box-shadow:0 0 8px rgba(16,217,138,0.7)}
.lic-b{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.09);color:#93dbbd}
.mhd,.mfooter{background:rgba(255,255,255,.035);border-color:rgba(255,255,255,.09)}
thead tr{background:rgba(255,255,255,.05)}
th{color:#4f7f69;font-weight:700;letter-spacing:.04em}
td{color:#e6fff4;border-color:rgba(255,255,255,.06)}
tr:hover td{background:rgba(255,255,255,.045)}
.pw .pb{background:linear-gradient(90deg,#059669,#10d98a)}
.mbd,#pm{background:rgba(0,0,0,.8) !important;-webkit-backdrop-filter:blur(14px) !important;backdrop-filter:blur(14px) !important}
.al-s{background:rgba(16,217,138,0.12);border-color:rgba(16,217,138,0.4);color:#10d98a}
@keyframes fu{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ══ الموقع العام index.php ══ */
.navbar{background:rgba(6,26,18,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(155%) !important;backdrop-filter:blur(22px) saturate(155%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.nav-logo-text{color:#10d98a !important}
.nav-btn{background:rgba(255,255,255,.07) !important;border:1px solid rgba(255,255,255,.09) !important;color:#93dbbd !important}
.nav-btn:hover{background:rgba(16,217,138,0.22) !important;border-color:#10d98a !important;color:#e6fff4 !important}
.search-wrap input{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#e6fff4 !important}
.search-wrap input::placeholder{color:#4f7f69}
.search-wrap input:focus{background:rgba(255,255,255,.09) !important;border-color:#10d98a !important}
.cat-navbar{background:rgba(6,26,18,0.72) !important;-webkit-backdrop-filter:blur(20px) saturate(150%) !important;backdrop-filter:blur(20px) saturate(150%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.cat-nav-btn{background:rgba(255,255,255,.055) !important;border:1px solid rgba(255,255,255,.09) !important;color:#93dbbd !important;font-weight:650 !important}
.cat-nav-btn:hover{background:rgba(255,255,255,.11) !important;border-color:rgba(255,255,255,.2) !important;color:#e6fff4 !important}
.cat-nav-btn.active{background:linear-gradient(135deg,#059669,#10d98a) !important;border-color:transparent !important;color:#ffffff !important;box-shadow:0 4px 16px rgba(16,217,138,0.38) !important}
.fp-panel,.np-panel,.m3u-panel,.ep-panel,.shs-catmenu-panel{background:rgba(6,26,18,0.82) !important;-webkit-backdrop-filter:blur(28px) saturate(155%) !important;backdrop-filter:blur(28px) saturate(155%) !important;border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:0 0 50px rgba(0,0,0,.55) !important}
.panel-overlay,.shs-catmenu-overlay,.tmdb-modal-overlay{background:rgba(0,0,0,.65) !important;-webkit-backdrop-filter:blur(8px) !important;backdrop-filter:blur(8px) !important}
.netflix-card,.nx-card,.ch-card,.sr-card{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:12px !important;box-shadow:0 6px 24px rgba(0,0,0,.4) !important}
.netflix-card:hover,.nx-card:hover{border-color:rgba(16,217,138,0.5) !important;box-shadow:0 12px 36px rgba(0,0,0,.55),0 0 0 1px rgba(16,217,138,0.25) !important}
.shs-catview-banner{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;-webkit-backdrop-filter:blur(16px) saturate(140%);backdrop-filter:blur(16px) saturate(140%)}
.shs-catmenu-item:hover{background:rgba(255,255,255,.07)}
.shs-empty-ico{background:radial-gradient(120% 120% at 30% 20%,rgba(16,217,138,0.16),rgba(255,255,255,.03));color:#10d98a}
.shs-spinner::before{border-top-color:#10d98a;border-right-color:#10d98a}
.hero-btn-play,.btn-play{background:linear-gradient(135deg,#059669,#10d98a) !important;color:#ffffff !important}
/* ══ إصلاح أيقونات شريط الأقسام ══ */
.cat-nav-btn i,.cat-nav-btn svg,.cat-nav-btn .lcn,.cat-nav-btn .lcn svg{
 color:#93dbbd !important;stroke:currentColor !important;fill:none !important;
 width:1em;height:1em;flex-shrink:0;opacity:.9;transition:color .2s}
.cat-nav-btn:hover i,.cat-nav-btn:hover svg,.cat-nav-btn:hover .lcn,.cat-nav-btn:hover .lcn svg{
 color:#e6fff4 !important;opacity:1}
.cat-nav-btn.active i,.cat-nav-btn.active svg,.cat-nav-btn.active .lcn,.cat-nav-btn.active .lcn svg{
 color:#ffffff !important;opacity:1}
.cat-nav-btn .lcn{display:inline-flex;align-items:center;justify-content:center;line-height:0}
/* أيقونات القائمة الجانبية للأقسام */
.shs-catmenu-item i,.shs-catmenu-item svg,.shs-catmenu-item .lcn svg{
 color:#10d98a !important;stroke:currentColor !important;fill:none !important}
/* أيقونات أزرار التنقّل العلوية */
.nav-btn i,.nav-btn svg,.nav-btn .lcn svg{stroke:currentColor !important;fill:none !important}`
    },
    sunset: {
        name: 'Sunset Glow',
        css: `:root{--red:#ff7849;--redg:rgba(255,120,73,0.34);--gold:#ff4d8d;--s0:#140710;--s1:#1c0a16;--s2:#26101e;--s3:#311526;--s4:#3e1b30;--br:rgba(255,255,255,.09);--brh:rgba(255,255,255,.2);--t1:#fff0e8;--t2:#e3ad96;--t3:#8f5f4d;--r1:12px}

/* خلفية */
html{background:#140710}
body{background:#140710 !important;color:#fff0e8;position:relative;min-height:100vh}
body::before{content:"";position:fixed;inset:0;z-index:-2;pointer-events:none;background:
  radial-gradient(60% 50% at 18% 12%,rgba(255,120,73,0.14),transparent 62%),
  radial-gradient(60% 50% at 85% 18%,rgba(255,77,141,0.12),transparent 62%),
  radial-gradient(60% 50% at 75% 88%,rgba(255,179,71,0.08),transparent 62%);
  animation:thDrift 30s ease-in-out infinite alternate}
@keyframes thDrift{0%{transform:scale(1)}100%{transform:scale(1.08)}}
body,.si,.fi,.fs,td,th,p{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.stitle,.tbtitle,.sbrand-name{color:#fff0e8;font-weight:750;letter-spacing:-.01em}
.card p,.sc p{color:#e3ad96;line-height:1.75}
.snl{color:#8f5f4d;letter-spacing:.08em;font-weight:600}

/* ══ لوحة التحكم ══ */
.sidebar{background:rgba(28,10,22,0.82) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:-1px 0 40px rgba(0,0,0,.4)}
.sidebar::after{background:linear-gradient(180deg,rgba(255,120,73,0.6),transparent)}
.sbrand{background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.09)}
.sbrand-icon{background:linear-gradient(135deg,#ff4d8d,#ff7849);color:#ffffff;box-shadow:0 4px 16px rgba(255,120,73,0.3)}
.sbrand-sub{color:#8f5f4d}
.si{color:#e3ad96;font-weight:600}
.si:hover{background:rgba(255,255,255,.06);color:#fff0e8}
.si.on{background:rgba(255,120,73,0.12);color:#fff0e8}
.si.on::before{background:#ff7849}
.si.on .si-ic{color:#ff7849}
.topbar{background:rgba(28,10,22,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-bottom:1px solid rgba(255,255,255,.09) !important}
.main{background:transparent !important}
.card,.tw,.swc,.bkc,.sc,.mbox,.pcard,.theme-panel{background:rgba(255,255,255,.05) !important;-webkit-backdrop-filter:blur(16px) saturate(140%) !important;backdrop-filter:blur(16px) saturate(140%) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:14px !important;box-shadow:0 8px 30px rgba(0,0,0,.4),inset 0 1px 0 rgba(255,255,255,.06) !important}
.sc:hover{background:rgba(255,255,255,.08) !important;border-color:rgba(255,255,255,.2) !important;box-shadow:0 12px 38px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.06) !important}
.fi,.fs{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#fff0e8 !important;border-radius:10px}
.fi::placeholder{color:#8f5f4d}
.fi:focus,.fs:focus{border-color:#ff7849 !important;background:rgba(255,255,255,.08) !important;box-shadow:0 0 0 3px rgba(255,120,73,0.18) !important}
.btn-p{background:linear-gradient(135deg,#ff4d8d,#ff7849) !important;border:none !important;color:#ffffff !important;font-weight:700;border-radius:10px;box-shadow:0 4px 18px rgba(255,120,73,0.32)}
.btn-p:hover{filter:brightness(1.1);box-shadow:0 6px 24px rgba(255,120,73,0.45)}
.ib{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);color:#e3ad96;border-radius:9px}
.ib:hover{background:rgba(255,255,255,.12);color:#fff0e8;border-color:rgba(255,255,255,.2)}
.uavt{background:linear-gradient(135deg,#ff4d8d,#ff7849);color:#ffffff}
.lic-dot{background:#ffb347;box-shadow:0 0 8px rgba(255,179,71,0.7)}
.lic-b{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.09);color:#e3ad96}
.mhd,.mfooter{background:rgba(255,255,255,.035);border-color:rgba(255,255,255,.09)}
thead tr{background:rgba(255,255,255,.05)}
th{color:#8f5f4d;font-weight:700;letter-spacing:.04em}
td{color:#fff0e8;border-color:rgba(255,255,255,.06)}
tr:hover td{background:rgba(255,255,255,.045)}
.pw .pb{background:linear-gradient(90deg,#ff4d8d,#ff7849)}
.mbd,#pm{background:rgba(0,0,0,.8) !important;-webkit-backdrop-filter:blur(14px) !important;backdrop-filter:blur(14px) !important}
.al-s{background:rgba(255,179,71,0.12);border-color:rgba(255,179,71,0.4);color:#ffb347}
@keyframes fu{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ══ الموقع العام index.php ══ */
.navbar{background:rgba(28,10,22,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(155%) !important;backdrop-filter:blur(22px) saturate(155%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.nav-logo-text{color:#ff7849 !important}
.nav-btn{background:rgba(255,255,255,.07) !important;border:1px solid rgba(255,255,255,.09) !important;color:#e3ad96 !important}
.nav-btn:hover{background:rgba(255,120,73,0.22) !important;border-color:#ff7849 !important;color:#fff0e8 !important}
.search-wrap input{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#fff0e8 !important}
.search-wrap input::placeholder{color:#8f5f4d}
.search-wrap input:focus{background:rgba(255,255,255,.09) !important;border-color:#ff7849 !important}
.cat-navbar{background:rgba(28,10,22,0.72) !important;-webkit-backdrop-filter:blur(20px) saturate(150%) !important;backdrop-filter:blur(20px) saturate(150%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.cat-nav-btn{background:rgba(255,255,255,.055) !important;border:1px solid rgba(255,255,255,.09) !important;color:#e3ad96 !important;font-weight:650 !important}
.cat-nav-btn:hover{background:rgba(255,255,255,.11) !important;border-color:rgba(255,255,255,.2) !important;color:#fff0e8 !important}
.cat-nav-btn.active{background:linear-gradient(135deg,#ff4d8d,#ff7849) !important;border-color:transparent !important;color:#ffffff !important;box-shadow:0 4px 16px rgba(255,120,73,0.38) !important}
.fp-panel,.np-panel,.m3u-panel,.ep-panel,.shs-catmenu-panel{background:rgba(28,10,22,0.82) !important;-webkit-backdrop-filter:blur(28px) saturate(155%) !important;backdrop-filter:blur(28px) saturate(155%) !important;border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:0 0 50px rgba(0,0,0,.55) !important}
.panel-overlay,.shs-catmenu-overlay,.tmdb-modal-overlay{background:rgba(0,0,0,.65) !important;-webkit-backdrop-filter:blur(8px) !important;backdrop-filter:blur(8px) !important}
.netflix-card,.nx-card,.ch-card,.sr-card{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:12px !important;box-shadow:0 6px 24px rgba(0,0,0,.4) !important}
.netflix-card:hover,.nx-card:hover{border-color:rgba(255,120,73,0.5) !important;box-shadow:0 12px 36px rgba(0,0,0,.55),0 0 0 1px rgba(255,120,73,0.25) !important}
.shs-catview-banner{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;-webkit-backdrop-filter:blur(16px) saturate(140%);backdrop-filter:blur(16px) saturate(140%)}
.shs-catmenu-item:hover{background:rgba(255,255,255,.07)}
.shs-empty-ico{background:radial-gradient(120% 120% at 30% 20%,rgba(255,120,73,0.16),rgba(255,255,255,.03));color:#ff7849}
.shs-spinner::before{border-top-color:#ff7849;border-right-color:#ff7849}
.hero-btn-play,.btn-play{background:linear-gradient(135deg,#ff4d8d,#ff7849) !important;color:#ffffff !important}
/* ══ إصلاح أيقونات شريط الأقسام ══ */
.cat-nav-btn i,.cat-nav-btn svg,.cat-nav-btn .lcn,.cat-nav-btn .lcn svg{
 color:#e3ad96 !important;stroke:currentColor !important;fill:none !important;
 width:1em;height:1em;flex-shrink:0;opacity:.9;transition:color .2s}
.cat-nav-btn:hover i,.cat-nav-btn:hover svg,.cat-nav-btn:hover .lcn,.cat-nav-btn:hover .lcn svg{
 color:#fff0e8 !important;opacity:1}
.cat-nav-btn.active i,.cat-nav-btn.active svg,.cat-nav-btn.active .lcn,.cat-nav-btn.active .lcn svg{
 color:#ffffff !important;opacity:1}
.cat-nav-btn .lcn{display:inline-flex;align-items:center;justify-content:center;line-height:0}
/* أيقونات القائمة الجانبية للأقسام */
.shs-catmenu-item i,.shs-catmenu-item svg,.shs-catmenu-item .lcn svg{
 color:#ff7849 !important;stroke:currentColor !important;fill:none !important}
/* أيقونات أزرار التنقّل العلوية */
.nav-btn i,.nav-btn svg,.nav-btn .lcn svg{stroke:currentColor !important;fill:none !important}`
    },
    royalgold: {
        name: 'Royal Gold',
        css: `:root{--red:#f5c542;--redg:rgba(245,197,66,0.3);--gold:#b8941f;--s0:#0a0800;--s1:#100d02;--s2:#181305;--s3:#211a08;--s4:#2c220b;--br:rgba(255,255,255,.09);--brh:rgba(255,255,255,.2);--t1:#fdf6e3;--t2:#d1bc80;--t3:#7f734a;--r1:10px}

/* خلفية */
html{background:#0a0800}
body{background:#0a0800 !important;color:#fdf6e3;position:relative;min-height:100vh}
body::before{content:"";position:fixed;inset:0;z-index:-2;pointer-events:none;background:
  radial-gradient(60% 50% at 18% 10%,rgba(245,197,66,0.11),transparent 62%),
  radial-gradient(60% 50% at 85% 85%,rgba(184,148,31,0.09),transparent 62%);
  animation:thDrift 30s ease-in-out infinite alternate}
@keyframes thDrift{0%{transform:scale(1)}100%{transform:scale(1.08)}}
body,.si,.fi,.fs,td,th,p{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.stitle,.tbtitle,.sbrand-name{color:#fdf6e3;font-weight:750;letter-spacing:-.01em}
.card p,.sc p{color:#d1bc80;line-height:1.75}
.snl{color:#7f734a;letter-spacing:.08em;font-weight:600}

/* ══ لوحة التحكم ══ */
.sidebar{background:rgba(16,13,2,0.82) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:-1px 0 40px rgba(0,0,0,.4)}
.sidebar::after{background:linear-gradient(180deg,rgba(245,197,66,0.6),transparent)}
.sbrand{background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.09)}
.sbrand-icon{background:linear-gradient(135deg,#b8941f,#f5c542);color:#1a1400;box-shadow:0 4px 16px rgba(245,197,66,0.3)}
.sbrand-sub{color:#7f734a}
.si{color:#d1bc80;font-weight:600}
.si:hover{background:rgba(255,255,255,.06);color:#fdf6e3}
.si.on{background:rgba(245,197,66,0.12);color:#fdf6e3}
.si.on::before{background:#f5c542}
.si.on .si-ic{color:#f5c542}
.topbar{background:rgba(16,13,2,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-bottom:1px solid rgba(255,255,255,.09) !important}
.main{background:transparent !important}
.card,.tw,.swc,.bkc,.sc,.mbox,.pcard,.theme-panel{background:rgba(255,255,255,.05) !important;-webkit-backdrop-filter:blur(16px) saturate(140%) !important;backdrop-filter:blur(16px) saturate(140%) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:12px !important;box-shadow:0 8px 30px rgba(0,0,0,.4),inset 0 1px 0 rgba(255,255,255,.06) !important}
.sc:hover{background:rgba(255,255,255,.08) !important;border-color:rgba(255,255,255,.2) !important;box-shadow:0 12px 38px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.06) !important}
.fi,.fs{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#fdf6e3 !important;border-radius:8px}
.fi::placeholder{color:#7f734a}
.fi:focus,.fs:focus{border-color:#f5c542 !important;background:rgba(255,255,255,.08) !important;box-shadow:0 0 0 3px rgba(245,197,66,0.18) !important}
.btn-p{background:linear-gradient(135deg,#b8941f,#f5c542) !important;border:none !important;color:#1a1400 !important;font-weight:700;border-radius:8px;box-shadow:0 4px 18px rgba(245,197,66,0.32)}
.btn-p:hover{filter:brightness(1.1);box-shadow:0 6px 24px rgba(245,197,66,0.45)}
.ib{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);color:#d1bc80;border-radius:7px}
.ib:hover{background:rgba(255,255,255,.12);color:#fdf6e3;border-color:rgba(255,255,255,.2)}
.uavt{background:linear-gradient(135deg,#b8941f,#f5c542);color:#1a1400}
.lic-dot{background:#f5c542;box-shadow:0 0 8px rgba(245,197,66,0.7)}
.lic-b{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.09);color:#d1bc80}
.mhd,.mfooter{background:rgba(255,255,255,.035);border-color:rgba(255,255,255,.09)}
thead tr{background:rgba(255,255,255,.05)}
th{color:#7f734a;font-weight:700;letter-spacing:.04em}
td{color:#fdf6e3;border-color:rgba(255,255,255,.06)}
tr:hover td{background:rgba(255,255,255,.045)}
.pw .pb{background:linear-gradient(90deg,#b8941f,#f5c542)}
.mbd,#pm{background:rgba(0,0,0,.8) !important;-webkit-backdrop-filter:blur(14px) !important;backdrop-filter:blur(14px) !important}
.al-s{background:rgba(245,197,66,0.12);border-color:rgba(245,197,66,0.4);color:#f5c542}
@keyframes fu{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ══ الموقع العام index.php ══ */
.navbar{background:rgba(16,13,2,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(155%) !important;backdrop-filter:blur(22px) saturate(155%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.nav-logo-text{color:#f5c542 !important}
.nav-btn{background:rgba(255,255,255,.07) !important;border:1px solid rgba(255,255,255,.09) !important;color:#d1bc80 !important}
.nav-btn:hover{background:rgba(245,197,66,0.22) !important;border-color:#f5c542 !important;color:#fdf6e3 !important}
.search-wrap input{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#fdf6e3 !important}
.search-wrap input::placeholder{color:#7f734a}
.search-wrap input:focus{background:rgba(255,255,255,.09) !important;border-color:#f5c542 !important}
.cat-navbar{background:rgba(16,13,2,0.72) !important;-webkit-backdrop-filter:blur(20px) saturate(150%) !important;backdrop-filter:blur(20px) saturate(150%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.cat-nav-btn{background:rgba(255,255,255,.055) !important;border:1px solid rgba(255,255,255,.09) !important;color:#d1bc80 !important;font-weight:650 !important}
.cat-nav-btn:hover{background:rgba(255,255,255,.11) !important;border-color:rgba(255,255,255,.2) !important;color:#fdf6e3 !important}
.cat-nav-btn.active{background:linear-gradient(135deg,#b8941f,#f5c542) !important;border-color:transparent !important;color:#1a1400 !important;box-shadow:0 4px 16px rgba(245,197,66,0.38) !important}
.fp-panel,.np-panel,.m3u-panel,.ep-panel,.shs-catmenu-panel{background:rgba(16,13,2,0.82) !important;-webkit-backdrop-filter:blur(28px) saturate(155%) !important;backdrop-filter:blur(28px) saturate(155%) !important;border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:0 0 50px rgba(0,0,0,.55) !important}
.panel-overlay,.shs-catmenu-overlay,.tmdb-modal-overlay{background:rgba(0,0,0,.65) !important;-webkit-backdrop-filter:blur(8px) !important;backdrop-filter:blur(8px) !important}
.netflix-card,.nx-card,.ch-card,.sr-card{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:10px !important;box-shadow:0 6px 24px rgba(0,0,0,.4) !important}
.netflix-card:hover,.nx-card:hover{border-color:rgba(245,197,66,0.5) !important;box-shadow:0 12px 36px rgba(0,0,0,.55),0 0 0 1px rgba(245,197,66,0.25) !important}
.shs-catview-banner{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;-webkit-backdrop-filter:blur(16px) saturate(140%);backdrop-filter:blur(16px) saturate(140%)}
.shs-catmenu-item:hover{background:rgba(255,255,255,.07)}
.shs-empty-ico{background:radial-gradient(120% 120% at 30% 20%,rgba(245,197,66,0.16),rgba(255,255,255,.03));color:#f5c542}
.shs-spinner::before{border-top-color:#f5c542;border-right-color:#f5c542}
.hero-btn-play,.btn-play{background:linear-gradient(135deg,#b8941f,#f5c542) !important;color:#1a1400 !important}
/* ══ إصلاح أيقونات شريط الأقسام ══ */
.cat-nav-btn i,.cat-nav-btn svg,.cat-nav-btn .lcn,.cat-nav-btn .lcn svg{
 color:#d1bc80 !important;stroke:currentColor !important;fill:none !important;
 width:1em;height:1em;flex-shrink:0;opacity:.9;transition:color .2s}
.cat-nav-btn:hover i,.cat-nav-btn:hover svg,.cat-nav-btn:hover .lcn,.cat-nav-btn:hover .lcn svg{
 color:#fdf6e3 !important;opacity:1}
.cat-nav-btn.active i,.cat-nav-btn.active svg,.cat-nav-btn.active .lcn,.cat-nav-btn.active .lcn svg{
 color:#1a1400 !important;opacity:1}
.cat-nav-btn .lcn{display:inline-flex;align-items:center;justify-content:center;line-height:0}
/* أيقونات القائمة الجانبية للأقسام */
.shs-catmenu-item i,.shs-catmenu-item svg,.shs-catmenu-item .lcn svg{
 color:#f5c542 !important;stroke:currentColor !important;fill:none !important}
/* أيقونات أزرار التنقّل العلوية */
.nav-btn i,.nav-btn svg,.nav-btn .lcn svg{stroke:currentColor !important;fill:none !important}`
    },
    crimson: {
        name: 'Crimson Noir',
        css: `:root{--red:#ff3355;--redg:rgba(255,51,85,0.35);--gold:#b81d3c;--s0:#100308;--s1:#16050a;--s2:#1f0810;--s3:#290b16;--s4:#36101d;--br:rgba(255,255,255,.09);--brh:rgba(255,255,255,.2);--t1:#ffe8ed;--t2:#dda5b1;--t3:#90505e;--r1:12px}

/* خلفية */
html{background:#100308}
body{background:#100308 !important;color:#ffe8ed;position:relative;min-height:100vh}
body::before{content:"";position:fixed;inset:0;z-index:-2;pointer-events:none;background:
  radial-gradient(60% 50% at 18% 12%,rgba(255,51,85,0.13),transparent 62%),
  radial-gradient(60% 50% at 85% 85%,rgba(184,29,60,0.11),transparent 62%);
  animation:thDrift 30s ease-in-out infinite alternate}
@keyframes thDrift{0%{transform:scale(1)}100%{transform:scale(1.08)}}
body,.si,.fi,.fs,td,th,p{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.stitle,.tbtitle,.sbrand-name{color:#ffe8ed;font-weight:750;letter-spacing:-.01em}
.card p,.sc p{color:#dda5b1;line-height:1.75}
.snl{color:#90505e;letter-spacing:.08em;font-weight:600}

/* ══ لوحة التحكم ══ */
.sidebar{background:rgba(22,5,10,0.82) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:-1px 0 40px rgba(0,0,0,.4)}
.sidebar::after{background:linear-gradient(180deg,rgba(255,51,85,0.6),transparent)}
.sbrand{background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.09)}
.sbrand-icon{background:linear-gradient(135deg,#b81d3c,#ff3355);color:#ffffff;box-shadow:0 4px 16px rgba(255,51,85,0.3)}
.sbrand-sub{color:#90505e}
.si{color:#dda5b1;font-weight:600}
.si:hover{background:rgba(255,255,255,.06);color:#ffe8ed}
.si.on{background:rgba(255,51,85,0.12);color:#ffe8ed}
.si.on::before{background:#ff3355}
.si.on .si-ic{color:#ff3355}
.topbar{background:rgba(22,5,10,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-bottom:1px solid rgba(255,255,255,.09) !important}
.main{background:transparent !important}
.card,.tw,.swc,.bkc,.sc,.mbox,.pcard,.theme-panel{background:rgba(255,255,255,.05) !important;-webkit-backdrop-filter:blur(16px) saturate(140%) !important;backdrop-filter:blur(16px) saturate(140%) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:14px !important;box-shadow:0 8px 30px rgba(0,0,0,.4),inset 0 1px 0 rgba(255,255,255,.06) !important}
.sc:hover{background:rgba(255,255,255,.08) !important;border-color:rgba(255,255,255,.2) !important;box-shadow:0 12px 38px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.06) !important}
.fi,.fs{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#ffe8ed !important;border-radius:10px}
.fi::placeholder{color:#90505e}
.fi:focus,.fs:focus{border-color:#ff3355 !important;background:rgba(255,255,255,.08) !important;box-shadow:0 0 0 3px rgba(255,51,85,0.18) !important}
.btn-p{background:linear-gradient(135deg,#b81d3c,#ff3355) !important;border:none !important;color:#ffffff !important;font-weight:700;border-radius:10px;box-shadow:0 4px 18px rgba(255,51,85,0.32)}
.btn-p:hover{filter:brightness(1.1);box-shadow:0 6px 24px rgba(255,51,85,0.45)}
.ib{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);color:#dda5b1;border-radius:9px}
.ib:hover{background:rgba(255,255,255,.12);color:#ffe8ed;border-color:rgba(255,255,255,.2)}
.uavt{background:linear-gradient(135deg,#b81d3c,#ff3355);color:#ffffff}
.lic-dot{background:#ff9eb5;box-shadow:0 0 8px rgba(255,158,181,0.7)}
.lic-b{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.09);color:#dda5b1}
.mhd,.mfooter{background:rgba(255,255,255,.035);border-color:rgba(255,255,255,.09)}
thead tr{background:rgba(255,255,255,.05)}
th{color:#90505e;font-weight:700;letter-spacing:.04em}
td{color:#ffe8ed;border-color:rgba(255,255,255,.06)}
tr:hover td{background:rgba(255,255,255,.045)}
.pw .pb{background:linear-gradient(90deg,#b81d3c,#ff3355)}
.mbd,#pm{background:rgba(0,0,0,.8) !important;-webkit-backdrop-filter:blur(14px) !important;backdrop-filter:blur(14px) !important}
.al-s{background:rgba(255,158,181,0.12);border-color:rgba(255,158,181,0.4);color:#ff9eb5}
@keyframes fu{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ══ الموقع العام index.php ══ */
.navbar{background:rgba(22,5,10,0.72) !important;-webkit-backdrop-filter:blur(22px) saturate(155%) !important;backdrop-filter:blur(22px) saturate(155%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.nav-logo-text{color:#ff3355 !important}
.nav-btn{background:rgba(255,255,255,.07) !important;border:1px solid rgba(255,255,255,.09) !important;color:#dda5b1 !important}
.nav-btn:hover{background:rgba(255,51,85,0.22) !important;border-color:#ff3355 !important;color:#ffe8ed !important}
.search-wrap input{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#ffe8ed !important}
.search-wrap input::placeholder{color:#90505e}
.search-wrap input:focus{background:rgba(255,255,255,.09) !important;border-color:#ff3355 !important}
.cat-navbar{background:rgba(22,5,10,0.72) !important;-webkit-backdrop-filter:blur(20px) saturate(150%) !important;backdrop-filter:blur(20px) saturate(150%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.cat-nav-btn{background:rgba(255,255,255,.055) !important;border:1px solid rgba(255,255,255,.09) !important;color:#dda5b1 !important;font-weight:650 !important}
.cat-nav-btn:hover{background:rgba(255,255,255,.11) !important;border-color:rgba(255,255,255,.2) !important;color:#ffe8ed !important}
.cat-nav-btn.active{background:linear-gradient(135deg,#b81d3c,#ff3355) !important;border-color:transparent !important;color:#ffffff !important;box-shadow:0 4px 16px rgba(255,51,85,0.38) !important}
.fp-panel,.np-panel,.m3u-panel,.ep-panel,.shs-catmenu-panel{background:rgba(22,5,10,0.82) !important;-webkit-backdrop-filter:blur(28px) saturate(155%) !important;backdrop-filter:blur(28px) saturate(155%) !important;border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:0 0 50px rgba(0,0,0,.55) !important}
.panel-overlay,.shs-catmenu-overlay,.tmdb-modal-overlay{background:rgba(0,0,0,.65) !important;-webkit-backdrop-filter:blur(8px) !important;backdrop-filter:blur(8px) !important}
.netflix-card,.nx-card,.ch-card,.sr-card{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:12px !important;box-shadow:0 6px 24px rgba(0,0,0,.4) !important}
.netflix-card:hover,.nx-card:hover{border-color:rgba(255,51,85,0.5) !important;box-shadow:0 12px 36px rgba(0,0,0,.55),0 0 0 1px rgba(255,51,85,0.25) !important}
.shs-catview-banner{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;-webkit-backdrop-filter:blur(16px) saturate(140%);backdrop-filter:blur(16px) saturate(140%)}
.shs-catmenu-item:hover{background:rgba(255,255,255,.07)}
.shs-empty-ico{background:radial-gradient(120% 120% at 30% 20%,rgba(255,51,85,0.16),rgba(255,255,255,.03));color:#ff3355}
.shs-spinner::before{border-top-color:#ff3355;border-right-color:#ff3355}
.hero-btn-play,.btn-play{background:linear-gradient(135deg,#b81d3c,#ff3355) !important;color:#ffffff !important}
/* ══ إصلاح أيقونات شريط الأقسام ══ */
.cat-nav-btn i,.cat-nav-btn svg,.cat-nav-btn .lcn,.cat-nav-btn .lcn svg{
 color:#dda5b1 !important;stroke:currentColor !important;fill:none !important;
 width:1em;height:1em;flex-shrink:0;opacity:.9;transition:color .2s}
.cat-nav-btn:hover i,.cat-nav-btn:hover svg,.cat-nav-btn:hover .lcn,.cat-nav-btn:hover .lcn svg{
 color:#ffe8ed !important;opacity:1}
.cat-nav-btn.active i,.cat-nav-btn.active svg,.cat-nav-btn.active .lcn,.cat-nav-btn.active .lcn svg{
 color:#ffffff !important;opacity:1}
.cat-nav-btn .lcn{display:inline-flex;align-items:center;justify-content:center;line-height:0}
/* أيقونات القائمة الجانبية للأقسام */
.shs-catmenu-item i,.shs-catmenu-item svg,.shs-catmenu-item .lcn svg{
 color:#ff3355 !important;stroke:currentColor !important;fill:none !important}
/* أيقونات أزرار التنقّل العلوية */
.nav-btn i,.nav-btn svg,.nav-btn .lcn svg{stroke:currentColor !important;fill:none !important}`
    },
    arctic: {
        name: 'Arctic Frost',
        css: `:root{--red:#0e7490;--redg:rgba(14,116,144,0.25);--gold:#06b6d4;--s0:#eef4f8;--s1:#ffffff;--s2:#e6eef4;--s3:#d6e3ec;--s4:#c2d4e0;--br:rgba(0,0,0,.1);--brh:rgba(0,0,0,.2);--t1:#0c2733;--t2:#3d5a66;--t3:#8aa3ad;--r1:12px;--sw:250px}

/* خلفية */
html{background:#eef4f8}
body{background:#eef4f8 !important;color:#0c2733;position:relative;min-height:100vh}
body::before{content:"";position:fixed;inset:0;z-index:-2;pointer-events:none;background:
  radial-gradient(60% 50% at 20% 10%,rgba(14,116,144,0.06),transparent 62%),
  radial-gradient(60% 50% at 85% 82%,rgba(6,182,212,0.05),transparent 62%);
  animation:thDrift 30s ease-in-out infinite alternate}
@keyframes thDrift{0%{transform:scale(1)}100%{transform:scale(1.08)}}
body,.si,.fi,.fs,td,th,p{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.stitle,.tbtitle,.sbrand-name{color:#0c2733;font-weight:750;letter-spacing:-.01em}
.card p,.sc p{color:#3d5a66;line-height:1.75}
.snl{color:#8aa3ad;letter-spacing:.08em;font-weight:600}

/* ══ لوحة التحكم ══ */
.sidebar{background:rgba(255,255,255,.85) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-left:1px solid rgba(0,0,0,.1) !important;box-shadow:-1px 0 40px rgba(15,23,42,.1)}
.sidebar::after{background:linear-gradient(180deg,rgba(14,116,144,0.6),transparent)}
.sbrand{background:rgba(0,0,0,.03);border-bottom:1px solid rgba(0,0,0,.1)}
.sbrand-icon{background:linear-gradient(135deg,#06b6d4,#0e7490);color:#ffffff;box-shadow:0 4px 16px rgba(14,116,144,0.3)}
.sbrand-sub{color:#8aa3ad}
.si{color:#3d5a66;font-weight:600}
.si:hover{background:rgba(0,0,0,.06);color:#0c2733}
.si.on{background:rgba(14,116,144,0.12);color:#0c2733}
.si.on::before{background:#0e7490}
.si.on .si-ic{color:#0e7490}
.topbar{background:rgba(255,255,255,.72) !important;-webkit-backdrop-filter:blur(22px) saturate(150%);backdrop-filter:blur(22px) saturate(150%);border-bottom:1px solid rgba(0,0,0,.1) !important}
.main{background:transparent !important}
.card,.tw,.swc,.bkc,.sc,.mbox,.pcard,.theme-panel{background:rgba(255,255,255,.75) !important;-webkit-backdrop-filter:blur(16px) saturate(140%) !important;backdrop-filter:blur(16px) saturate(140%) !important;border:1px solid rgba(0,0,0,.1) !important;border-radius:14px !important;box-shadow:0 8px 30px rgba(15,23,42,.1),inset 0 1px 0 rgba(255,255,255,.9) !important}
.sc:hover{background:rgba(255,255,255,.9) !important;border-color:rgba(0,0,0,.2) !important;box-shadow:0 12px 38px rgba(15,23,42,.16),inset 0 1px 0 rgba(255,255,255,.9) !important}
.fi,.fs{background:rgba(0,0,0,.03) !important;border:1px solid rgba(0,0,0,.1) !important;color:#0c2733 !important;border-radius:10px}
.fi::placeholder{color:#8aa3ad}
.fi:focus,.fs:focus{border-color:#0e7490 !important;background:rgba(0,0,0,.05) !important;box-shadow:0 0 0 3px rgba(14,116,144,0.18) !important}
.btn-p{background:linear-gradient(135deg,#06b6d4,#0e7490) !important;border:none !important;color:#ffffff !important;font-weight:700;border-radius:10px;box-shadow:0 4px 18px rgba(14,116,144,0.32)}
.btn-p:hover{filter:brightness(1.1);box-shadow:0 6px 24px rgba(14,116,144,0.45)}
.ib{background:rgba(0,0,0,.06);border:1px solid rgba(0,0,0,.1);color:#3d5a66;border-radius:9px}
.ib:hover{background:rgba(0,0,0,.12);color:#0c2733;border-color:rgba(0,0,0,.2)}
.uavt{background:linear-gradient(135deg,#06b6d4,#0e7490);color:#ffffff}
.lic-dot{background:#06b6d4;box-shadow:0 0 8px rgba(6,182,212,0.7)}
.lic-b{background:rgba(0,0,0,.05);border-color:rgba(0,0,0,.1);color:#3d5a66}
.mhd,.mfooter{background:rgba(0,0,0,.035);border-color:rgba(0,0,0,.1)}
thead tr{background:rgba(0,0,0,.05)}
th{color:#8aa3ad;font-weight:700;letter-spacing:.04em}
td{color:#0c2733;border-color:rgba(0,0,0,.06)}
tr:hover td{background:rgba(0,0,0,.045)}
.pw .pb{background:linear-gradient(90deg,#06b6d4,#0e7490)}
.mbd,#pm{background:rgba(255,255,255,.5) !important;-webkit-backdrop-filter:blur(14px) !important;backdrop-filter:blur(14px) !important}
.al-s{background:rgba(6,182,212,0.12);border-color:rgba(6,182,212,0.4);color:#06b6d4}
@keyframes fu{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ══ الموقع العام index.php ══ */
.navbar{background:rgba(255,255,255,.72) !important;-webkit-backdrop-filter:blur(22px) saturate(155%) !important;backdrop-filter:blur(22px) saturate(155%) !important;border-bottom:1px solid rgba(0,0,0,.1) !important}
.nav-logo-text{color:#0e7490 !important}
.nav-btn{background:rgba(0,0,0,.07) !important;border:1px solid rgba(0,0,0,.1) !important;color:#3d5a66 !important}
.nav-btn:hover{background:rgba(14,116,144,0.22) !important;border-color:#0e7490 !important;color:#0c2733 !important}
.search-wrap input{background:rgba(0,0,0,.05) !important;border:1px solid rgba(0,0,0,.1) !important;color:#0c2733 !important}
.search-wrap input::placeholder{color:#8aa3ad}
.search-wrap input:focus{background:rgba(0,0,0,.09) !important;border-color:#0e7490 !important}
.cat-navbar{background:rgba(255,255,255,.72) !important;-webkit-backdrop-filter:blur(20px) saturate(150%) !important;backdrop-filter:blur(20px) saturate(150%) !important;border-bottom:1px solid rgba(0,0,0,.1) !important}
.cat-nav-btn{background:rgba(0,0,0,.055) !important;border:1px solid rgba(0,0,0,.1) !important;color:#3d5a66 !important;font-weight:650 !important}
.cat-nav-btn:hover{background:rgba(0,0,0,.11) !important;border-color:rgba(0,0,0,.2) !important;color:#0c2733 !important}
.cat-nav-btn.active{background:linear-gradient(135deg,#06b6d4,#0e7490) !important;border-color:transparent !important;color:#ffffff !important;box-shadow:0 4px 16px rgba(14,116,144,0.38) !important}
.fp-panel,.np-panel,.m3u-panel,.ep-panel,.shs-catmenu-panel{background:rgba(255,255,255,.85) !important;-webkit-backdrop-filter:blur(28px) saturate(155%) !important;backdrop-filter:blur(28px) saturate(155%) !important;border-left:1px solid rgba(0,0,0,.1) !important;box-shadow:0 0 50px rgba(15,23,42,.16) !important}
.panel-overlay,.shs-catmenu-overlay,.tmdb-modal-overlay{background:rgba(255,255,255,.35) !important;-webkit-backdrop-filter:blur(8px) !important;backdrop-filter:blur(8px) !important}
.netflix-card,.nx-card,.ch-card,.sr-card{background:rgba(255,255,255,.75) !important;border:1px solid rgba(0,0,0,.1) !important;border-radius:12px !important;box-shadow:0 6px 24px rgba(15,23,42,.1) !important}
.netflix-card:hover,.nx-card:hover{border-color:rgba(14,116,144,0.5) !important;box-shadow:0 12px 36px rgba(15,23,42,.16),0 0 0 1px rgba(14,116,144,0.25) !important}
.shs-catview-banner{background:rgba(255,255,255,.75) !important;border:1px solid rgba(0,0,0,.1) !important;-webkit-backdrop-filter:blur(16px) saturate(140%);backdrop-filter:blur(16px) saturate(140%)}
.shs-catmenu-item:hover{background:rgba(0,0,0,.07)}
.shs-empty-ico{background:radial-gradient(120% 120% at 30% 20%,rgba(14,116,144,0.16),rgba(0,0,0,.03));color:#0e7490}
.shs-spinner::before{border-top-color:#0e7490;border-right-color:#0e7490}
.hero-btn-play,.btn-play{background:linear-gradient(135deg,#06b6d4,#0e7490) !important;color:#ffffff !important}
/* ══ إصلاح أيقونات شريط الأقسام ══ */
.cat-nav-btn i,.cat-nav-btn svg,.cat-nav-btn .lcn,.cat-nav-btn .lcn svg{
 color:#3d5a66 !important;stroke:currentColor !important;fill:none !important;
 width:1em;height:1em;flex-shrink:0;opacity:.9;transition:color .2s}
.cat-nav-btn:hover i,.cat-nav-btn:hover svg,.cat-nav-btn:hover .lcn,.cat-nav-btn:hover .lcn svg{
 color:#0c2733 !important;opacity:1}
.cat-nav-btn.active i,.cat-nav-btn.active svg,.cat-nav-btn.active .lcn,.cat-nav-btn.active .lcn svg{
 color:#ffffff !important;opacity:1}
.cat-nav-btn .lcn{display:inline-flex;align-items:center;justify-content:center;line-height:0}
/* أيقونات القائمة الجانبية للأقسام */
.shs-catmenu-item i,.shs-catmenu-item svg,.shs-catmenu-item .lcn svg{
 color:#0e7490 !important;stroke:currentColor !important;fill:none !important}
/* أيقونات أزرار التنقّل العلوية */
.nav-btn i,.nav-btn svg,.nav-btn .lcn svg{stroke:currentColor !important;fill:none !important}`
    },
    glass: {
        name: 'Glass Blur',
        css: `:root{--red:#6ea8fe;--redg:rgba(110,168,254,.28);--gold:#8fb8ff;--s0:#080b12;--s1:rgba(255,255,255,.045);--s2:rgba(255,255,255,.06);--s3:rgba(255,255,255,.08);--s4:rgba(255,255,255,.11);--br:rgba(255,255,255,.1);--brh:rgba(255,255,255,.2);--t1:#f4f7fc;--t2:#aeb9cc;--t3:#71809a;--r1:12px}

/* ═══ الخلفية: أسود مزرق هادئ + توهّجات خافتة ═══ */
html{background:#080b12}
body{background:#080b12 !important;position:relative;min-height:100vh;color:var(--t1)}
body::before{content:"";position:fixed;inset:0;z-index:-2;pointer-events:none;
 background:
  radial-gradient(70% 55% at 18% 8%,rgba(78,132,255,.16),transparent 62%),
  radial-gradient(60% 50% at 88% 18%,rgba(126,95,255,.13),transparent 62%),
  radial-gradient(65% 55% at 78% 92%,rgba(56,150,220,.11),transparent 64%),
  radial-gradient(55% 45% at 10% 88%,rgba(96,110,190,.1),transparent 62%);
 animation:glassDrift 30s ease-in-out infinite alternate}
@keyframes glassDrift{0%{transform:scale(1)}100%{transform:scale(1.1)}}

/* ═══ الطباعة: واضحة واحترافية ═══ */
body,.si,.fi,.fs,td,th,p{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.stitle,.tbtitle,.sbrand-name{color:#fff;font-weight:750;letter-spacing:-.01em}
.card p,.sc p{color:var(--t2);line-height:1.75;font-size:.86rem}
.snl{color:var(--t3);letter-spacing:.08em;font-weight:600}

/* ═══ الأسطح الزجاجية ═══ */
.sidebar{background:rgba(14,18,28,.72) !important;-webkit-backdrop-filter:blur(24px) saturate(140%);backdrop-filter:blur(24px) saturate(140%);border-left:1px solid rgba(255,255,255,.08) !important;box-shadow:-1px 0 40px rgba(0,0,0,.5)}
.sidebar::after{background:linear-gradient(180deg,rgba(110,168,254,.5),transparent)}
.sbrand{background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.07)}
.sbrand-icon{background:linear-gradient(135deg,#4d7fe0,#6ea8fe);color:#fff;box-shadow:0 4px 16px rgba(110,168,254,.28)}
.sbrand-sub{color:var(--t3)}
.si{color:var(--t2);font-weight:600}
.si:hover{background:rgba(255,255,255,.055);color:#fff}
.si.on{background:rgba(110,168,254,.11);color:#fff}
.si.on::before{background:#6ea8fe}
.si.on .si-ic{color:#6ea8fe}

.topbar{background:rgba(12,16,25,.7) !important;-webkit-backdrop-filter:blur(22px) saturate(140%);backdrop-filter:blur(22px) saturate(140%);border-bottom:1px solid rgba(255,255,255,.08) !important}
.main{background:transparent !important}

/* البطاقات — زجاج داكن هادئ */
.card,.tw,.swc,.bkc,.sc,.mbox,.pcard,.theme-panel{
 background:rgba(255,255,255,.045) !important;
 -webkit-backdrop-filter:blur(18px) saturate(140%) !important;backdrop-filter:blur(18px) saturate(140%) !important;
 border:1px solid rgba(255,255,255,.09) !important;border-radius:14px !important;
 box-shadow:0 8px 30px rgba(0,0,0,.35),inset 0 1px 0 rgba(255,255,255,.07) !important}
.sc:hover{background:rgba(255,255,255,.07) !important;border-color:rgba(255,255,255,.18) !important;box-shadow:0 12px 38px rgba(0,0,0,.42),inset 0 1px 0 rgba(255,255,255,.1) !important}

/* الحقول */
.fi,.fs{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.11) !important;color:#f4f7fc !important;border-radius:10px}
.fi::placeholder{color:#66748c}
.fi:focus,.fs:focus{border-color:#6ea8fe !important;background:rgba(255,255,255,.08) !important;box-shadow:0 0 0 3px rgba(110,168,254,.16) !important}

/* الأزرار */
.btn-p{background:linear-gradient(135deg,#4d7fe0,#6ea8fe) !important;border:none !important;color:#fff !important;font-weight:700;border-radius:10px;box-shadow:0 4px 18px rgba(110,168,254,.3)}
.btn-p:hover{background:linear-gradient(135deg,#5b8dee,#82b6ff) !important;box-shadow:0 6px 24px rgba(110,168,254,.42)}
.ib{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:var(--t2);border-radius:9px}
.ib:hover{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.22)}

.uavt{background:linear-gradient(135deg,#4d7fe0,#6ea8fe);color:#fff}
.lic-dot{background:#4ade80;box-shadow:0 0 8px rgba(74,222,128,.7)}
.lic-b{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.1);color:var(--t2)}
.mhd,.mfooter{background:rgba(255,255,255,.035);border-color:rgba(255,255,255,.08)}
thead tr{background:rgba(255,255,255,.05)}
th{color:var(--t3);font-weight:700;letter-spacing:.04em}
td{color:var(--t1);border-color:rgba(255,255,255,.06)}
tr:hover td{background:rgba(255,255,255,.045)}
.pw .pb{background:linear-gradient(90deg,#4d7fe0,#6ea8fe)}
.mbd,#pm{background:rgba(6,9,15,.82) !important;-webkit-backdrop-filter:blur(14px) !important;backdrop-filter:blur(14px) !important}
@keyframes fu{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ═══ الواجهة العامة index.php ═══ */
.navbar{background:rgba(12,16,25,.68) !important;-webkit-backdrop-filter:blur(24px) saturate(150%) !important;backdrop-filter:blur(24px) saturate(150%) !important;border-bottom:1px solid rgba(255,255,255,.08) !important}
.nav-logo-text{color:#fff !important}
.nav-btn{background:rgba(255,255,255,.07) !important;border:1px solid rgba(255,255,255,.11) !important;color:#e2e8f0 !important}
.nav-btn:hover{background:rgba(110,168,254,.2) !important;border-color:#6ea8fe !important;color:#fff !important}
.search-wrap input{background:rgba(255,255,255,.055) !important;border:1px solid rgba(255,255,255,.11) !important;color:#f4f7fc !important}
.search-wrap input::placeholder{color:#66748c}
.search-wrap input:focus{background:rgba(255,255,255,.09) !important;border-color:#6ea8fe !important}

.cat-navbar{background:rgba(12,16,25,.6) !important;-webkit-backdrop-filter:blur(20px) saturate(145%) !important;backdrop-filter:blur(20px) saturate(145%) !important;border-bottom:1px solid rgba(255,255,255,.07) !important}
.cat-nav-btn{background:rgba(255,255,255,.055) !important;border:1px solid rgba(255,255,255,.1) !important;color:#cbd5e1 !important;font-weight:650 !important}
.cat-nav-btn:hover{background:rgba(255,255,255,.11) !important;border-color:rgba(255,255,255,.2) !important;color:#fff !important}
.cat-nav-btn.active{background:linear-gradient(135deg,#4d7fe0,#6ea8fe) !important;border-color:transparent !important;color:#fff !important;box-shadow:0 4px 16px rgba(110,168,254,.35) !important}

.fp-panel,.np-panel,.m3u-panel,.ep-panel,.shs-catmenu-panel{background:rgba(14,18,28,.8) !important;-webkit-backdrop-filter:blur(28px) saturate(150%) !important;backdrop-filter:blur(28px) saturate(150%) !important;border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:0 0 50px rgba(0,0,0,.6) !important}
.panel-overlay,.shs-catmenu-overlay{background:rgba(6,9,15,.6) !important;-webkit-backdrop-filter:blur(6px) !important;backdrop-filter:blur(6px) !important}

.netflix-card,.nx-card,.ch-card,.sr-card{background:rgba(255,255,255,.04) !important;border:1px solid rgba(255,255,255,.08) !important;border-radius:12px !important;box-shadow:0 6px 24px rgba(0,0,0,.35) !important}
.netflix-card:hover,.nx-card:hover{border-color:rgba(110,168,254,.45) !important;box-shadow:0 12px 36px rgba(0,0,0,.5),0 0 0 1px rgba(110,168,254,.2) !important}
.shs-catview-banner{background:rgba(255,255,255,.045) !important;border:1px solid rgba(255,255,255,.09) !important;-webkit-backdrop-filter:blur(18px) saturate(140%);backdrop-filter:blur(18px) saturate(140%)}
.tmdb-modal-overlay{background:rgba(6,9,15,.8) !important;-webkit-backdrop-filter:blur(12px) !important;backdrop-filter:blur(12px) !important}
/* ══ إصلاح أيقونات شريط الأقسام ══ */
.cat-nav-btn i,.cat-nav-btn svg,.cat-nav-btn .lcn,.cat-nav-btn .lcn svg{
 color:#aeb9cc !important;stroke:currentColor !important;fill:none !important;
 width:1em;height:1em;flex-shrink:0;opacity:.9;transition:color .2s}
.cat-nav-btn:hover i,.cat-nav-btn:hover svg,.cat-nav-btn:hover .lcn,.cat-nav-btn:hover .lcn svg{
 color:#f4f7fc !important;opacity:1}
.cat-nav-btn.active i,.cat-nav-btn.active svg,.cat-nav-btn.active .lcn,.cat-nav-btn.active .lcn svg{
 color:#ffffff !important;opacity:1}
.cat-nav-btn .lcn{display:inline-flex;align-items:center;justify-content:center;line-height:0}
/* أيقونات القائمة الجانبية للأقسام */
.shs-catmenu-item i,.shs-catmenu-item svg,.shs-catmenu-item .lcn svg{
 color:#6ea8fe !important;stroke:currentColor !important;fill:none !important}
/* أيقونات أزرار التنقّل العلوية */
.nav-btn i,.nav-btn svg,.nav-btn .lcn svg{stroke:currentColor !important;fill:none !important}`
    },
    win11: {
        name: 'Windows 11',
        css: `:root{--red:#4cc2ff;--redg:rgba(76,194,255,0.28);--gold:#0078d4;--s0:#202020;--s1:#2b2b2b;--s2:#323232;--s3:#3a3a3a;--s4:#454545;--br:rgba(255,255,255,.09);--brh:rgba(255,255,255,.2);--t1:#ffffff;--t2:#c5c5c5;--t3:#8a8a8a;--r1:8px}

/* خلفية */
html{background:#202020}
body{background:#202020 !important;color:#ffffff;position:relative;min-height:100vh}
body::before{content:"";position:fixed;inset:0;z-index:-2;pointer-events:none;background:
  radial-gradient(60% 50% at 12% 8%,rgba(0,120,212,0.13),transparent 62%),
  radial-gradient(60% 50% at 88% 12%,rgba(76,194,255,0.09),transparent 62%),
  radial-gradient(60% 50% at 70% 90%,rgba(142,140,216,0.08),transparent 62%),
  radial-gradient(60% 50% at 20% 88%,rgba(194,57,179,0.05),transparent 62%);
  animation:thDrift 30s ease-in-out infinite alternate}
@keyframes thDrift{0%{transform:scale(1)}100%{transform:scale(1.08)}}
body,.si,.fi,.fs,td,th,p{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.stitle,.tbtitle,.sbrand-name{color:#ffffff;font-weight:750;letter-spacing:-.01em}
.card p,.sc p{color:#c5c5c5;line-height:1.75}
.snl{color:#8a8a8a;letter-spacing:.08em;font-weight:600}

/* ══ لوحة التحكم ══ */
.sidebar{background:rgba(43,43,43,0.82) !important;-webkit-backdrop-filter:blur(26px) saturate(150%);backdrop-filter:blur(26px) saturate(150%);border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:-1px 0 40px rgba(0,0,0,.4)}
.sidebar::after{background:linear-gradient(180deg,rgba(76,194,255,0.6),transparent)}
.sbrand{background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.09)}
.sbrand-icon{background:linear-gradient(135deg,#0078d4,#4cc2ff);color:#ffffff;box-shadow:0 4px 16px rgba(76,194,255,0.3)}
.sbrand-sub{color:#8a8a8a}
.si{color:#c5c5c5;font-weight:600}
.si:hover{background:rgba(255,255,255,.06);color:#ffffff}
.si.on{background:rgba(76,194,255,0.12);color:#ffffff}
.si.on::before{background:#4cc2ff}
.si.on .si-ic{color:#4cc2ff}
.topbar{background:rgba(43,43,43,0.72) !important;-webkit-backdrop-filter:blur(26px) saturate(150%);backdrop-filter:blur(26px) saturate(150%);border-bottom:1px solid rgba(255,255,255,.09) !important}
.main{background:transparent !important}
.card,.tw,.swc,.bkc,.sc,.mbox,.pcard,.theme-panel{background:rgba(255,255,255,.05) !important;-webkit-backdrop-filter:blur(20px) saturate(140%) !important;backdrop-filter:blur(20px) saturate(140%) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:10px !important;box-shadow:0 8px 30px rgba(0,0,0,.4),inset 0 1px 0 rgba(255,255,255,.06) !important}
.sc:hover{background:rgba(255,255,255,.08) !important;border-color:rgba(255,255,255,.2) !important;box-shadow:0 12px 38px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.06) !important}
.fi,.fs{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#ffffff !important;border-radius:6px}
.fi::placeholder{color:#8a8a8a}
.fi:focus,.fs:focus{border-color:#4cc2ff !important;background:rgba(255,255,255,.08) !important;box-shadow:0 0 0 3px rgba(76,194,255,0.18) !important}
.btn-p{background:linear-gradient(135deg,#0078d4,#4cc2ff) !important;border:none !important;color:#ffffff !important;font-weight:700;border-radius:6px;box-shadow:0 4px 18px rgba(76,194,255,0.32)}
.btn-p:hover{filter:brightness(1.1);box-shadow:0 6px 24px rgba(76,194,255,0.45)}
.ib{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);color:#c5c5c5;border-radius:5px}
.ib:hover{background:rgba(255,255,255,.12);color:#ffffff;border-color:rgba(255,255,255,.2)}
.uavt{background:linear-gradient(135deg,#0078d4,#4cc2ff);color:#ffffff}
.lic-dot{background:#6ccb5f;box-shadow:0 0 8px rgba(108,203,95,0.7)}
.lic-b{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.09);color:#c5c5c5}
.mhd,.mfooter{background:rgba(255,255,255,.035);border-color:rgba(255,255,255,.09)}
thead tr{background:rgba(255,255,255,.05)}
th{color:#8a8a8a;font-weight:700;letter-spacing:.04em}
td{color:#ffffff;border-color:rgba(255,255,255,.06)}
tr:hover td{background:rgba(255,255,255,.045)}
.pw .pb{background:linear-gradient(90deg,#0078d4,#4cc2ff)}
.mbd,#pm{background:rgba(0,0,0,.8) !important;-webkit-backdrop-filter:blur(14px) !important;backdrop-filter:blur(14px) !important}
.al-s{background:rgba(108,203,95,0.12);border-color:rgba(108,203,95,0.4);color:#6ccb5f}
@keyframes fu{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ══ الموقع العام index.php ══ */
.navbar{background:rgba(43,43,43,0.72) !important;-webkit-backdrop-filter:blur(26px) saturate(155%) !important;backdrop-filter:blur(26px) saturate(155%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.nav-logo-text{color:#4cc2ff !important}
.nav-btn{background:rgba(255,255,255,.07) !important;border:1px solid rgba(255,255,255,.09) !important;color:#c5c5c5 !important}
.nav-btn:hover{background:rgba(76,194,255,0.22) !important;border-color:#4cc2ff !important;color:#ffffff !important}
.search-wrap input{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#ffffff !important}
.search-wrap input::placeholder{color:#8a8a8a}
.search-wrap input:focus{background:rgba(255,255,255,.09) !important;border-color:#4cc2ff !important}
.cat-navbar{background:rgba(43,43,43,0.72) !important;-webkit-backdrop-filter:blur(24px) saturate(150%) !important;backdrop-filter:blur(24px) saturate(150%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.cat-nav-btn{background:rgba(255,255,255,.055) !important;border:1px solid rgba(255,255,255,.09) !important;color:#c5c5c5 !important;font-weight:650 !important}
.cat-nav-btn:hover{background:rgba(255,255,255,.11) !important;border-color:rgba(255,255,255,.2) !important;color:#ffffff !important}
.cat-nav-btn.active{background:linear-gradient(135deg,#0078d4,#4cc2ff) !important;border-color:transparent !important;color:#ffffff !important;box-shadow:0 4px 16px rgba(76,194,255,0.38) !important}
.fp-panel,.np-panel,.m3u-panel,.ep-panel,.shs-catmenu-panel{background:rgba(43,43,43,0.82) !important;-webkit-backdrop-filter:blur(32px) saturate(155%) !important;backdrop-filter:blur(32px) saturate(155%) !important;border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:0 0 50px rgba(0,0,0,.55) !important}
.panel-overlay,.shs-catmenu-overlay,.tmdb-modal-overlay{background:rgba(0,0,0,.65) !important;-webkit-backdrop-filter:blur(8px) !important;backdrop-filter:blur(8px) !important}
.netflix-card,.nx-card,.ch-card,.sr-card{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:8px !important;box-shadow:0 6px 24px rgba(0,0,0,.4) !important}
.netflix-card:hover,.nx-card:hover{border-color:rgba(76,194,255,0.5) !important;box-shadow:0 12px 36px rgba(0,0,0,.55),0 0 0 1px rgba(76,194,255,0.25) !important}
.shs-catview-banner{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;-webkit-backdrop-filter:blur(20px) saturate(140%);backdrop-filter:blur(20px) saturate(140%)}
.shs-catmenu-item:hover{background:rgba(255,255,255,.07)}
.shs-empty-ico{background:radial-gradient(120% 120% at 30% 20%,rgba(76,194,255,0.16),rgba(255,255,255,.03));color:#4cc2ff}
.shs-spinner::before{border-top-color:#4cc2ff;border-right-color:#4cc2ff}
.hero-btn-play,.btn-play{background:linear-gradient(135deg,#0078d4,#4cc2ff) !important;color:#ffffff !important}
/* Windows 11 — Mica + زوايا ناعمة */
.card,.tw,.swc,.bkc,.sc,.mbox,.pcard,.netflix-card,.nx-card{border-radius:8px !important}
.btn-p{border-radius:4px !important;font-weight:600}
.fi,.fs{border-radius:4px}
.si{border-radius:5px;margin:2px 6px}
.si.on::before{width:3px;border-radius:3px;top:22%;height:56%}
.nav-btn,.ib{border-radius:5px}
.cat-nav-btn{border-radius:5px !important}
/* ══ إصلاح أيقونات شريط الأقسام ══ */
.cat-nav-btn i,.cat-nav-btn svg,.cat-nav-btn .lcn,.cat-nav-btn .lcn svg{
 color:#c5c5c5 !important;stroke:currentColor !important;fill:none !important;
 width:1em;height:1em;flex-shrink:0;opacity:.9;transition:color .2s}
.cat-nav-btn:hover i,.cat-nav-btn:hover svg,.cat-nav-btn:hover .lcn,.cat-nav-btn:hover .lcn svg{
 color:#ffffff !important;opacity:1}
.cat-nav-btn.active i,.cat-nav-btn.active svg,.cat-nav-btn.active .lcn,.cat-nav-btn.active .lcn svg{
 color:#ffffff !important;opacity:1}
.cat-nav-btn .lcn{display:inline-flex;align-items:center;justify-content:center;line-height:0}
/* أيقونات القائمة الجانبية للأقسام */
.shs-catmenu-item i,.shs-catmenu-item svg,.shs-catmenu-item .lcn svg{
 color:#4cc2ff !important;stroke:currentColor !important;fill:none !important}
/* أيقونات أزرار التنقّل العلوية */
.nav-btn i,.nav-btn svg,.nav-btn .lcn svg{stroke:currentColor !important;fill:none !important}`
    },
    sierra: {
        name: 'macOS Sierra',
        css: `:root{--red:#007aff;--redg:rgba(0,122,255,.28);--gold:#5856d6;--s0:#e8ecf3;--s1:rgba(255,255,255,.62);--s2:rgba(255,255,255,.5);--s3:rgba(255,255,255,.42);--s4:rgba(0,0,0,.06);--br:rgba(0,0,0,.09);--brh:rgba(0,0,0,.16);--t1:#1d1d1f;--t2:#57606e;--t3:#8e949e;--r1:8px;--sw:250px}

/* خط النظام San Francisco */
body,.si,.fi,.fs,.btn-p,td,th,p,input,select,textarea,button{
 font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","SF Pro Display","Helvetica Neue","Segoe UI",system-ui,sans-serif !important;
 -webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}

/* خلفية سطح المكتب المتدرّجة — تظهر خلف الزجاج */
html{background:#7b8fb5}
body{background:linear-gradient(160deg,#6ea8dc 0%,#8f9fd4 26%,#b79ccb 50%,#e0a9b4 72%,#f3c39b 100%) !important;
 background-attachment:fixed !important;color:var(--t1);position:relative;min-height:100vh}
body::before{content:"";position:fixed;inset:-8%;z-index:-2;pointer-events:none;background:
 radial-gradient(48% 42% at 14% 12%,rgba(90,200,250,.5),transparent 66%),
 radial-gradient(46% 40% at 86% 16%,rgba(175,142,222,.42),transparent 66%),
 radial-gradient(52% 46% at 78% 88%,rgba(255,159,90,.34),transparent 68%),
 radial-gradient(46% 42% at 16% 86%,rgba(88,86,214,.3),transparent 66%);
 filter:blur(18px) saturate(120%);
 animation:macAurora 34s ease-in-out infinite alternate}
@keyframes macAurora{0%{transform:scale(1) translate(0,0)}100%{transform:scale(1.1) translate(-1.5%,-1.5%)}}

/* الطباعة */
.stitle,.tbtitle,.sbrand-name{color:var(--t1);font-weight:600;letter-spacing:-.015em}
.stitle{font-size:1.02rem}
.card p,.sc p{color:var(--t2);line-height:1.6;font-size:.85rem}
.snl{color:var(--t3);font-size:.68rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase}

/* ══ الشريط الجانبي — Vibrancy رمادي مائل (توقيع macOS) ══ */
.sidebar{background:rgba(238,241,246,.68) !important;
 -webkit-backdrop-filter:blur(45px) saturate(190%);backdrop-filter:blur(45px) saturate(190%);
 border-left:1px solid rgba(0,0,0,.08) !important;box-shadow:none}
.sidebar::after{display:none}
.sbrand{background:transparent;border-bottom:1px solid rgba(0,0,0,.07);padding:14px 16px}
.sbrand-icon{background:linear-gradient(180deg,#3b9dff,#007aff);color:#fff;border-radius:7px;
 box-shadow:0 1px 3px rgba(0,0,0,.18),inset 0 1px 0 rgba(255,255,255,.35)}
.sbrand-name{font-size:.9rem}.sbrand-sub{color:var(--t3);font-size:.68rem}
.si{color:#2b3138;font-weight:500;font-size:.85rem;border-radius:6px;margin:1px 8px;padding:6px 10px;transition:none}
.si:hover{background:rgba(0,0,0,.06);color:#000}
.si.on{background:linear-gradient(180deg,#3b9dff,#0a6fe0) !important;color:#fff !important;
 box-shadow:0 1px 2px rgba(0,0,0,.18)}
.si.on .si-ic{color:#fff !important}
.si.on::before{display:none}
.si-ic{opacity:.85}

/* ══ الشريط العلوي — نافذة macOS ══ */
.topbar{background:rgba(246,248,251,.66) !important;
 -webkit-backdrop-filter:blur(45px) saturate(190%);backdrop-filter:blur(45px) saturate(190%);
 border-bottom:1px solid rgba(0,0,0,.09) !important;box-shadow:0 .5px 0 rgba(255,255,255,.7) inset}
.tbtitle{font-size:.88rem;font-weight:600}
.main{background:transparent !important}

/* ══ البطاقات — لوح زجاجي بحواف Apple ══ */
.card,.tw,.swc,.bkc,.sc,.mbox,.pcard,.theme-panel{
 background:rgba(255,255,255,.6) !important;
 -webkit-backdrop-filter:blur(30px) saturate(180%) !important;backdrop-filter:blur(30px) saturate(180%) !important;
 border:.5px solid rgba(0,0,0,.1) !important;border-radius:12px !important;
 box-shadow:0 .5px 1px rgba(0,0,0,.06),0 8px 24px rgba(30,40,70,.12),inset 0 1px 0 rgba(255,255,255,.75) !important}
.sc:hover{background:rgba(255,255,255,.75) !important;
 box-shadow:0 .5px 1px rgba(0,0,0,.07),0 12px 32px rgba(30,40,70,.16),inset 0 1px 0 rgba(255,255,255,.85) !important;
 transform:translateY(-1px)}

/* ══ الحقول — Apple text field ══ */
.fi,.fs{background:rgba(255,255,255,.78) !important;border:.5px solid rgba(0,0,0,.16) !important;
 color:var(--t1) !important;border-radius:6px;font-size:.85rem;padding:6px 10px;
 box-shadow:inset 0 1px 2px rgba(0,0,0,.05)}
.fi::placeholder{color:#a3a9b3}
.fi:focus,.fs:focus{border-color:#007aff !important;background:#fff !important;
 box-shadow:0 0 0 3.5px rgba(0,122,255,.28) !important;outline:none}

/* ══ الأزرار — Apple push button ══ */
.btn-p{background:linear-gradient(180deg,#3b9dff,#007aff) !important;border:.5px solid rgba(0,80,180,.5) !important;
 color:#fff !important;font-weight:500 !important;font-size:.82rem;border-radius:6px;padding:6px 14px;
 box-shadow:0 1px 2px rgba(0,0,0,.14),inset 0 1px 0 rgba(255,255,255,.3) !important;letter-spacing:0}
.btn-p:hover{background:linear-gradient(180deg,#4aa6ff,#0a84ff) !important;filter:none}
.btn-p:active{background:linear-gradient(180deg,#0071ee,#0062d6) !important;box-shadow:inset 0 1px 3px rgba(0,0,0,.2) !important}
.ib{background:linear-gradient(180deg,#fff,#f2f4f7);border:.5px solid rgba(0,0,0,.16);color:#2b3138;
 border-radius:6px;font-size:.8rem;box-shadow:0 1px 1.5px rgba(0,0,0,.07)}
.ib:hover{background:linear-gradient(180deg,#fff,#e9ecf1);color:#000}

.uavt{background:linear-gradient(180deg,#3b9dff,#007aff);color:#fff;box-shadow:0 1px 3px rgba(0,0,0,.18)}
.lic-dot{background:#34c759;box-shadow:0 0 6px rgba(52,199,89,.6)}
.lic-b{background:rgba(255,255,255,.7);border-color:rgba(0,0,0,.1);color:var(--t2)}
.mhd,.mfooter{background:rgba(248,250,252,.7);border-color:rgba(0,0,0,.08)}
.mbox{border-radius:12px !important}
thead tr{background:rgba(0,0,0,.035)}
th{color:var(--t3);font-weight:600;font-size:.72rem;letter-spacing:.03em}
td{color:var(--t1);border-color:rgba(0,0,0,.06);font-size:.84rem}
tr:hover td{background:rgba(0,122,255,.07)}
.pw .pb{background:linear-gradient(90deg,#0a84ff,#5ac8fa);border-radius:99px}
.mbd,#pm{background:rgba(190,200,215,.45) !important;
 -webkit-backdrop-filter:blur(22px) saturate(150%) !important;backdrop-filter:blur(22px) saturate(150%) !important}
.al-s{background:rgba(52,199,89,.14);border-color:rgba(52,199,89,.4);color:#1a7f37}
.al-e{background:rgba(255,59,48,.12);border-color:rgba(255,59,48,.4);color:#c0392b}
@keyframes fu{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

/* ══ الموقع العام index.php ══ */
.navbar{background:rgba(246,248,251,.62) !important;
 -webkit-backdrop-filter:blur(45px) saturate(190%) !important;backdrop-filter:blur(45px) saturate(190%) !important;
 border-bottom:1px solid rgba(0,0,0,.09) !important}
.nav-logo-text{color:#007aff !important;font-weight:600;letter-spacing:-.02em}
.nav-btn{background:linear-gradient(180deg,#fff,#f2f4f7) !important;border:.5px solid rgba(0,0,0,.16) !important;
 color:#2b3138 !important;box-shadow:0 1px 1.5px rgba(0,0,0,.07) !important}
.nav-btn:hover{background:linear-gradient(180deg,#3b9dff,#007aff) !important;border-color:rgba(0,80,180,.5) !important;color:#fff !important;transform:none}
.search-wrap input{background:rgba(255,255,255,.8) !important;border:.5px solid rgba(0,0,0,.16) !important;
 color:var(--t1) !important;border-radius:99px;box-shadow:inset 0 1px 2px rgba(0,0,0,.05)}
.search-wrap input::placeholder{color:#a3a9b3}
.search-wrap input:focus{background:#fff !important;border-color:#007aff !important;box-shadow:0 0 0 3.5px rgba(0,122,255,.28) !important}
.search-wrap .si{color:#a3a9b3}

.cat-navbar{background:rgba(246,248,251,.55) !important;
 -webkit-backdrop-filter:blur(40px) saturate(180%) !important;backdrop-filter:blur(40px) saturate(180%) !important;
 border-bottom:1px solid rgba(0,0,0,.08) !important}
.cat-nav-btn{background:rgba(255,255,255,.72) !important;border:.5px solid rgba(0,0,0,.14) !important;
 color:#2b3138 !important;font-weight:500 !important;border-radius:6px !important;font-size:.82rem !important;
 box-shadow:0 1px 1.5px rgba(0,0,0,.06) !important}
.cat-nav-btn:hover{background:#fff !important;color:#000 !important;transform:none !important;box-shadow:0 1px 3px rgba(0,0,0,.1) !important}
.cat-nav-btn.active{background:linear-gradient(180deg,#3b9dff,#007aff) !important;
 border-color:rgba(0,80,180,.5) !important;color:#fff !important;
 box-shadow:0 1px 3px rgba(0,0,0,.18),inset 0 1px 0 rgba(255,255,255,.3) !important;transform:none !important}

.fp-panel,.np-panel,.m3u-panel,.ep-panel,.shs-catmenu-panel{
 background:rgba(240,243,248,.72) !important;
 -webkit-backdrop-filter:blur(50px) saturate(190%) !important;backdrop-filter:blur(50px) saturate(190%) !important;
 border-left:.5px solid rgba(0,0,0,.12) !important;box-shadow:-2px 0 30px rgba(30,40,70,.14) !important}
.panel-overlay,.shs-catmenu-overlay,.tmdb-modal-overlay{background:rgba(190,200,215,.4) !important;
 -webkit-backdrop-filter:blur(14px) !important;backdrop-filter:blur(14px) !important}
.shs-catmenu-item{color:#2b3138;border-radius:6px}
.shs-catmenu-item:hover{background:rgba(0,0,0,.06)}

.netflix-card,.nx-card,.ch-card,.sr-card{background:rgba(255,255,255,.62) !important;
 border:.5px solid rgba(0,0,0,.1) !important;border-radius:11px !important;
 box-shadow:0 .5px 1px rgba(0,0,0,.06),0 6px 18px rgba(30,40,70,.12) !important}
.netflix-card:hover,.nx-card:hover{background:rgba(255,255,255,.8) !important;
 box-shadow:0 .5px 1px rgba(0,0,0,.07),0 14px 34px rgba(30,40,70,.2) !important}
.shs-catview-banner{background:rgba(255,255,255,.6) !important;border:.5px solid rgba(0,0,0,.1) !important;
 border-radius:12px !important;-webkit-backdrop-filter:blur(30px) saturate(180%);backdrop-filter:blur(30px) saturate(180%)}
.shs-catview-name{color:var(--t1)}
.shs-empty-ico{background:rgba(0,122,255,.1);color:#007aff;box-shadow:none}
.shs-empty-title{color:var(--t1)}
.shs-spinner::before{border-top-color:#007aff;border-right-color:#007aff}
.hero-btn-play,.btn-play{background:linear-gradient(180deg,#3b9dff,#007aff) !important;color:#fff !important;border-radius:6px !important}
/* ══ إصلاح أيقونات شريط الأقسام ══ */
.cat-nav-btn i,.cat-nav-btn svg,.cat-nav-btn .lcn,.cat-nav-btn .lcn svg{
 color:#57606e !important;stroke:currentColor !important;fill:none !important;
 width:1em;height:1em;flex-shrink:0;opacity:.9;transition:color .2s}
.cat-nav-btn:hover i,.cat-nav-btn:hover svg,.cat-nav-btn:hover .lcn,.cat-nav-btn:hover .lcn svg{
 color:#1d1d1f !important;opacity:1}
.cat-nav-btn.active i,.cat-nav-btn.active svg,.cat-nav-btn.active .lcn,.cat-nav-btn.active .lcn svg{
 color:#ffffff !important;opacity:1}
.cat-nav-btn .lcn{display:inline-flex;align-items:center;justify-content:center;line-height:0}
/* أيقونات القائمة الجانبية للأقسام */
.shs-catmenu-item i,.shs-catmenu-item svg,.shs-catmenu-item .lcn svg{
 color:#007aff !important;stroke:currentColor !important;fill:none !important}
/* أيقونات أزرار التنقّل العلوية */
.nav-btn i,.nav-btn svg,.nav-btn .lcn svg{stroke:currentColor !important;fill:none !important}`
    },
    ps5: {
        name: 'PlayStation 5',
        css: `:root{--red:#2e6ff2;--redg:rgba(46,111,242,0.42);--gold:#0b3fbf;--s0:#0a0e17;--s1:#111726;--s2:#182033;--s3:#1f2941;--s4:#28344f;--br:rgba(255,255,255,.09);--brh:rgba(255,255,255,.2);--t1:#ffffff;--t2:#b4c0d6;--t3:#71809a;--r1:6px}

/* خلفية */
html{background:#0a0e17}
body{background:#0a0e17 !important;color:#ffffff;position:relative;min-height:100vh}
body::before{content:"";position:fixed;inset:0;z-index:-2;pointer-events:none;background:
  radial-gradient(60% 50% at 15% 10%,rgba(46,111,242,0.16),transparent 62%),
  radial-gradient(60% 50% at 88% 15%,rgba(0,209,255,0.1),transparent 62%),
  radial-gradient(60% 50% at 75% 90%,rgba(123,47,247,0.08),transparent 62%);
  animation:thDrift 30s ease-in-out infinite alternate}
@keyframes thDrift{0%{transform:scale(1)}100%{transform:scale(1.08)}}
body,.si,.fi,.fs,td,th,p{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
.stitle,.tbtitle,.sbrand-name{color:#ffffff;font-weight:750;letter-spacing:-.01em}
.card p,.sc p{color:#b4c0d6;line-height:1.75}
.snl{color:#71809a;letter-spacing:.08em;font-weight:600}

/* ══ لوحة التحكم ══ */
.sidebar{background:rgba(17,23,38,0.82) !important;-webkit-backdrop-filter:blur(18px) saturate(150%);backdrop-filter:blur(18px) saturate(150%);border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:-1px 0 40px rgba(0,0,0,.4)}
.sidebar::after{background:linear-gradient(180deg,rgba(46,111,242,0.6),transparent)}
.sbrand{background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.09)}
.sbrand-icon{background:linear-gradient(135deg,#0b3fbf,#2e6ff2);color:#ffffff;box-shadow:0 4px 16px rgba(46,111,242,0.3)}
.sbrand-sub{color:#71809a}
.si{color:#b4c0d6;font-weight:600}
.si:hover{background:rgba(255,255,255,.06);color:#ffffff}
.si.on{background:rgba(46,111,242,0.12);color:#ffffff}
.si.on::before{background:#2e6ff2}
.si.on .si-ic{color:#2e6ff2}
.topbar{background:rgba(17,23,38,0.72) !important;-webkit-backdrop-filter:blur(18px) saturate(150%);backdrop-filter:blur(18px) saturate(150%);border-bottom:1px solid rgba(255,255,255,.09) !important}
.main{background:transparent !important}
.card,.tw,.swc,.bkc,.sc,.mbox,.pcard,.theme-panel{background:rgba(255,255,255,.05) !important;-webkit-backdrop-filter:blur(16px) saturate(140%) !important;backdrop-filter:blur(16px) saturate(140%) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:8px !important;box-shadow:0 8px 30px rgba(0,0,0,.4),inset 0 1px 0 rgba(255,255,255,.06) !important}
.sc:hover{background:rgba(255,255,255,.08) !important;border-color:rgba(255,255,255,.2) !important;box-shadow:0 12px 38px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.06) !important}
.fi,.fs{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#ffffff !important;border-radius:4px}
.fi::placeholder{color:#71809a}
.fi:focus,.fs:focus{border-color:#2e6ff2 !important;background:rgba(255,255,255,.08) !important;box-shadow:0 0 0 3px rgba(46,111,242,0.18) !important}
.btn-p{background:linear-gradient(135deg,#0b3fbf,#2e6ff2) !important;border:none !important;color:#ffffff !important;font-weight:700;border-radius:4px;box-shadow:0 4px 18px rgba(46,111,242,0.32)}
.btn-p:hover{filter:brightness(1.1);box-shadow:0 6px 24px rgba(46,111,242,0.45)}
.ib{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);color:#b4c0d6;border-radius:3px}
.ib:hover{background:rgba(255,255,255,.12);color:#ffffff;border-color:rgba(255,255,255,.2)}
.uavt{background:linear-gradient(135deg,#0b3fbf,#2e6ff2);color:#ffffff}
.lic-dot{background:#2e6ff2;box-shadow:0 0 8px rgba(46,111,242,0.7)}
.lic-b{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.09);color:#b4c0d6}
.mhd,.mfooter{background:rgba(255,255,255,.035);border-color:rgba(255,255,255,.09)}
thead tr{background:rgba(255,255,255,.05)}
th{color:#71809a;font-weight:700;letter-spacing:.04em}
td{color:#ffffff;border-color:rgba(255,255,255,.06)}
tr:hover td{background:rgba(255,255,255,.045)}
.pw .pb{background:linear-gradient(90deg,#0b3fbf,#2e6ff2)}
.mbd,#pm{background:rgba(0,0,0,.8) !important;-webkit-backdrop-filter:blur(14px) !important;backdrop-filter:blur(14px) !important}
.al-s{background:rgba(46,111,242,0.12);border-color:rgba(46,111,242,0.4);color:#2e6ff2}
@keyframes fu{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ══ الموقع العام index.php ══ */
.navbar{background:rgba(17,23,38,0.72) !important;-webkit-backdrop-filter:blur(18px) saturate(155%) !important;backdrop-filter:blur(18px) saturate(155%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.nav-logo-text{color:#2e6ff2 !important}
.nav-btn{background:rgba(255,255,255,.07) !important;border:1px solid rgba(255,255,255,.09) !important;color:#b4c0d6 !important}
.nav-btn:hover{background:rgba(46,111,242,0.22) !important;border-color:#2e6ff2 !important;color:#ffffff !important}
.search-wrap input{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;color:#ffffff !important}
.search-wrap input::placeholder{color:#71809a}
.search-wrap input:focus{background:rgba(255,255,255,.09) !important;border-color:#2e6ff2 !important}
.cat-navbar{background:rgba(17,23,38,0.72) !important;-webkit-backdrop-filter:blur(16px) saturate(150%) !important;backdrop-filter:blur(16px) saturate(150%) !important;border-bottom:1px solid rgba(255,255,255,.09) !important}
.cat-nav-btn{background:rgba(255,255,255,.055) !important;border:1px solid rgba(255,255,255,.09) !important;color:#b4c0d6 !important;font-weight:650 !important}
.cat-nav-btn:hover{background:rgba(255,255,255,.11) !important;border-color:rgba(255,255,255,.2) !important;color:#ffffff !important}
.cat-nav-btn.active{background:linear-gradient(135deg,#0b3fbf,#2e6ff2) !important;border-color:transparent !important;color:#ffffff !important;box-shadow:0 4px 16px rgba(46,111,242,0.38) !important}
.fp-panel,.np-panel,.m3u-panel,.ep-panel,.shs-catmenu-panel{background:rgba(17,23,38,0.82) !important;-webkit-backdrop-filter:blur(24px) saturate(155%) !important;backdrop-filter:blur(24px) saturate(155%) !important;border-left:1px solid rgba(255,255,255,.09) !important;box-shadow:0 0 50px rgba(0,0,0,.55) !important}
.panel-overlay,.shs-catmenu-overlay,.tmdb-modal-overlay{background:rgba(0,0,0,.65) !important;-webkit-backdrop-filter:blur(8px) !important;backdrop-filter:blur(8px) !important}
.netflix-card,.nx-card,.ch-card,.sr-card{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;border-radius:6px !important;box-shadow:0 6px 24px rgba(0,0,0,.4) !important}
.netflix-card:hover,.nx-card:hover{border-color:rgba(46,111,242,0.5) !important;box-shadow:0 12px 36px rgba(0,0,0,.55),0 0 0 1px rgba(46,111,242,0.25) !important}
.shs-catview-banner{background:rgba(255,255,255,.05) !important;border:1px solid rgba(255,255,255,.09) !important;-webkit-backdrop-filter:blur(16px) saturate(140%);backdrop-filter:blur(16px) saturate(140%)}
.shs-catmenu-item:hover{background:rgba(255,255,255,.07)}
.shs-empty-ico{background:radial-gradient(120% 120% at 30% 20%,rgba(46,111,242,0.16),rgba(255,255,255,.03));color:#2e6ff2}
.shs-spinner::before{border-top-color:#2e6ff2;border-right-color:#2e6ff2}
.hero-btn-play,.btn-play{background:linear-gradient(135deg,#0b3fbf,#2e6ff2) !important;color:#ffffff !important}
/* PlayStation 5 — حواف حادة + توهّج أزرق */
.card,.tw,.swc,.bkc,.sc,.mbox,.pcard{border-radius:6px !important;border-top:1px solid rgba(46,111,242,.35) !important}
.netflix-card,.nx-card{border-radius:4px !important}
.btn-p{border-radius:999px !important;font-weight:700;letter-spacing:.02em;text-transform:uppercase;font-size:.8rem}
.fi,.fs{border-radius:4px}
.sbrand-icon{border-radius:6px;box-shadow:0 0 24px rgba(46,111,242,.6)}
.si{border-radius:0;border-right:2px solid transparent}
.si.on{border-right-color:#2e6ff2;background:linear-gradient(90deg,rgba(46,111,242,.2),transparent) !important}
.si.on::before{display:none}
.topbar,.navbar{border-bottom:1px solid rgba(46,111,242,.28) !important}
.cat-nav-btn{border-radius:999px !important}
.cat-nav-btn.active{box-shadow:0 0 22px rgba(46,111,242,.6) !important}
.netflix-card:hover,.nx-card:hover{transform:translateY(-4px);box-shadow:0 0 0 2px #2e6ff2,0 16px 40px rgba(46,111,242,.35) !important}
.stitle{text-transform:uppercase;letter-spacing:.04em}
/* ══ إصلاح أيقونات شريط الأقسام ══ */
.cat-nav-btn i,.cat-nav-btn svg,.cat-nav-btn .lcn,.cat-nav-btn .lcn svg{
 color:#b4c0d6 !important;stroke:currentColor !important;fill:none !important;
 width:1em;height:1em;flex-shrink:0;opacity:.9;transition:color .2s}
.cat-nav-btn:hover i,.cat-nav-btn:hover svg,.cat-nav-btn:hover .lcn,.cat-nav-btn:hover .lcn svg{
 color:#ffffff !important;opacity:1}
.cat-nav-btn.active i,.cat-nav-btn.active svg,.cat-nav-btn.active .lcn,.cat-nav-btn.active .lcn svg{
 color:#ffffff !important;opacity:1}
.cat-nav-btn .lcn{display:inline-flex;align-items:center;justify-content:center;line-height:0}
/* أيقونات القائمة الجانبية للأقسام */
.shs-catmenu-item i,.shs-catmenu-item svg,.shs-catmenu-item .lcn svg{
 color:#2e6ff2 !important;stroke:currentColor !important;fill:none !important}
/* أيقونات أزرار التنقّل العلوية */
.nav-btn i,.nav-btn svg,.nav-btn .lcn svg{stroke:currentColor !important;fill:none !important}`
    }
};

let _activeTheme = localStorage.getItem('shashety_theme') || 'default';
let _customCss   = localStorage.getItem('shashety_custom_css') || '';

/* ── Toast notification system ── */
function _adminToast(msg, type) {
    type = type || 's'; // s=success, e=error, i=info
    const icons = {s:'check-circle', e:'exclamation-circle', i:'info-circle'};
    const colors = {s:'#00D084', e:'#ff6b6b', i:'#4CC9F0'};
    let box = document.getElementById('adminToastBox');
    if (!box) {
        box = document.createElement('div');
        box.id = 'adminToastBox';
        box.style.cssText = 'position:fixed;top:24px;left:50%;transform:translateX(-50%);z-index:99999;display:flex;flex-direction:column;gap:10px;pointer-events:none;min-width:260px;max-width:90vw';
        document.body.appendChild(box);
    }
    const t = document.createElement('div');
    t.style.cssText = `background:var(--s1,#181818);color:#fff;border:1.5px solid ${colors[type]};border-radius:12px;padding:14px 20px;font-size:.88rem;font-weight:700;display:flex;align-items:center;gap:10px;box-shadow:0 8px 32px rgba(0,0,0,.5);animation:_toastIn .3s cubic-bezier(.23,1,.32,1);pointer-events:auto;direction:rtl`;
    t.innerHTML = `<i class="fas fa-${icons[type] || 'info-circle'}" style="color:${colors[type]};font-size:1.1rem"></i>${msg}`;
    // Add keyframe once
    if (!document.getElementById('_toastKf')) {
        const s = document.createElement('style');
        s.id = '_toastKf';
        s.textContent = '@keyframes _toastIn{from{opacity:0;transform:translateY(-14px) scale(.92)}to{opacity:1;transform:translateY(0) scale(1)}} @keyframes _toastOut{to{opacity:0;transform:translateY(-10px) scale(.92)}}';
        document.head.appendChild(s);
    }
    box.appendChild(t);
    setTimeout(() => {
        t.style.animation = '_toastOut .25s forwards';
        setTimeout(() => t.remove(), 260);
    }, 3000);
}

/* ── Save full CSS to DB ── */
function _saveThemeToDB(themeKey, fullCss, btnEl) {
    if (btnEl) { btnEl.disabled = true; btnEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...'; }
    api({ ajax_action: 'save_custom_css', custom_css: fullCss, theme_key: themeKey })
        .then(d => {
            if (d.success) {
                _adminToast('\u2705 تم حفظ الثيم بنجاح — سيظهر في index.php تلقائياً', 's');
            } else {
                _adminToast('\u274C خطأ في الحفظ: ' + (d.error || 'غير معروف'), 'e');
            }
        })
        .catch(() => _adminToast('\u274C فشل الاتصال بالخادم', 'e'))
        .finally(() => {
            if (btnEl) { btnEl.disabled = false; btnEl.innerHTML = '<i class="fas fa-check"></i> تطبيق الآن'; }
        });
}

function applyThemePreset(themeKey) {
    _activeTheme = themeKey;
    localStorage.setItem('shashety_theme', themeKey);
    const preset = THEME_PRESETS[themeKey] || THEME_PRESETS.default;
    const userCss = (document.getElementById('customCssInput') || {}).value || _customCss;
    const fullCss = preset.css + '\n' + userCss;
    const styleEl = document.getElementById('customCssThemeStyle');
    if (styleEl) styleEl.textContent = fullCss;
    // Mark active card
    document.querySelectorAll('.theme-card').forEach(c => c.classList.remove('active'));
    const ac = document.getElementById('thc-' + themeKey);
    if (ac) ac.classList.add('active');
    // Show inline status - saving...
    const st = document.getElementById('cssApplyStatus');
    if (st) { st.innerHTML = '<span style="color:#4CC9F0"><i class="fas fa-spinner fa-spin"></i> جاري الحفظ في قاعدة البيانات...</span>'; }
    // Toast
    _adminToast('\uD83C\uDFA8 تم تطبيق ثيم ' + (preset.name || themeKey) + ' محلياً — جاري الحفظ...', 'i');
    // ══ حفظ تلقائي في DB عند اختيار الثيم مباشرة ══
    api({ ajax_action: 'save_custom_css', custom_css: fullCss, theme_key: themeKey })
        .then(function(d) {
            if (d.success) {
                if (st) { st.innerHTML = '<span style="color:#00D084"><i class="fas fa-check-circle"></i> ✅ تم حفظ ثيم ' + (preset.name || themeKey) + ' في قاعدة البيانات — سيظهر في index.php تلقائياً</span>'; }
                _adminToast('✅ تم حفظ ثيم ' + (preset.name || themeKey) + ' وتطبيقه على index.php', 's');
            } else {
                if (st) { st.innerHTML = '<span style="color:#ff6b6b"><i class="fas fa-exclamation-circle"></i> خطأ في الحفظ: ' + (d.error || 'غير معروف') + '</span>'; }
                _adminToast('❌ خطأ في الحفظ: ' + (d.error || 'غير معروف'), 'e');
            }
        })
        .catch(function() {
            if (st) { st.innerHTML = '<span style="color:#ff6b6b"><i class="fas fa-exclamation-circle"></i> فشل الاتصال بالخادم</span>'; }
            _adminToast('❌ فشل الاتصال بالخادم', 'e');
        });
}

function applyCSSFromTextarea() {
    const ta = document.getElementById('customCssInput');
    const btn = document.getElementById('applyThemeBtn');
    if (!ta) return;
    _customCss = ta.value;
    localStorage.setItem('shashety_custom_css', _customCss);
    const preset = THEME_PRESETS[_activeTheme] || THEME_PRESETS.default;
    const fullCss = preset.css + '\n' + _customCss;
    const styleEl = document.getElementById('customCssThemeStyle');
    if (styleEl) styleEl.textContent = fullCss;
    // Save to DB
    _saveThemeToDB(_activeTheme, fullCss, btn);
}

function resetTheme() {
    _activeTheme = 'default'; _customCss = '';
    localStorage.removeItem('shashety_theme');
    localStorage.removeItem('shashety_custom_css');
    const styleEl = document.getElementById('customCssThemeStyle');
    if (styleEl) styleEl.textContent = '';
    const ta = document.getElementById('customCssInput'); if (ta) ta.value = '';
    document.querySelectorAll('.theme-card').forEach(c => c.classList.remove('active'));
    const dc = document.getElementById('thc-default'); if (dc) dc.classList.add('active');
    const st = document.getElementById('cssApplyStatus'); if (st) st.innerHTML = '';
    // Save empty to DB
    _saveThemeToDB('default', '', null);
}

function toggleThemePanel() {
    const panel = document.getElementById('themePanel');
    if (!panel) return;
    panel.classList.toggle('open');
    if (panel.classList.contains('open')) {
        setTimeout(() => document.addEventListener('click', _closePanelOutside), 150);
    } else {
        document.removeEventListener('click', _closePanelOutside);
    }
}
function _closePanelOutside(e) {
    const panel = document.getElementById('themePanel');
    const fab   = document.getElementById('themeFabBtn');
    if (!panel) return;
    if (!panel.contains(e.target) && (!fab || !fab.contains(e.target))) {
        panel.classList.remove('open');
        document.removeEventListener('click', _closePanelOutside);
    }
}

// Init on load
document.addEventListener('DOMContentLoaded', function() {
    const savedTheme = localStorage.getItem('shashety_theme') || 'default';
    const savedCss   = localStorage.getItem('shashety_custom_css') || '';
    const ta = document.getElementById('customCssInput');
    if (ta && savedCss) ta.value = savedCss;
    if (savedTheme && savedTheme !== 'default') {
        // Apply visually but don't re-save (already in DB)
        const preset = THEME_PRESETS[savedTheme] || THEME_PRESETS.default;
        const fullCss = preset.css + '\n' + savedCss;
        const styleEl = document.getElementById('customCssThemeStyle');
        if (styleEl) styleEl.textContent = fullCss;
        document.querySelectorAll('.theme-card').forEach(c => c.classList.remove('active'));
        const ac = document.getElementById('thc-' + savedTheme);
        if (ac) ac.classList.add('active');
        _activeTheme = savedTheme;
    } else if (savedCss) {
        const styleEl = document.getElementById('customCssThemeStyle');
        if (styleEl) styleEl.textContent = savedCss;
        const dc = document.getElementById('thc-default'); if(dc) dc.classList.add('active');
    } else {
        const dc = document.getElementById('thc-default'); if(dc) dc.classList.add('active');
    }
});

</script>

<!-- Admin Hover Prefetching Booster - مضاف برمجياً -->
<script>
document.addEventListener('mouseover', function(e) {
    if(e.target.tagName === 'A' && e.target.href && e.target.href.startsWith(window.location.origin) && e.target.href.indexOf('#') === -1) {
        let l = document.createElement('link');
        l.rel = 'prefetch'; l.href = e.target.href;
        try { document.head.appendChild(l); } catch(err){}
    }
});
</script>
<script>
// === TAILSCALE DYNAMIC ACTION HANDLER (FIXED & THEMED) ===
let _isTailscaleRunning = false;

function fetchTailscaleStatus() {
    const statusTxt = document.getElementById('ts_display_status');
    const btnBox = document.getElementById('ts_display_btn');
    const btnLbl = document.getElementById('ts_btn_label');
    const btnIcon = btnBox.querySelector('i');
    const ipWrap = document.getElementById('ts_ip_wrap');
    const ipVal = document.getElementById('ts_ip_val');

    api({ ajax_action: 'tailscale_command', ts_action: 'status' }).then(res => {
        // حالة التأكد القاطع بأن النظام قيد العمل في الخلفية
        if(res.success && res.state === 'Running') {
            _isTailscaleRunning = true;
            
            statusTxt.textContent = 'متصل ومحمي ONLINE';
            statusTxt.style.cssText = 'font-size:0.75rem; font-weight:800; padding:3px 10px; border-radius:100px; background: rgba(0,208,132,.15); color: #00D084; border: 1px solid rgba(0,208,132,.3); float:left; transition: 0.3s;';
            
            btnBox.className = 'btn btn-g'; 
            btnBox.style.borderColor = 'rgba(229,9,20, 0.6)';
            btnBox.style.color = '#ff6b6b';
            btnBox.style.background = 'rgba(229,9,20,.1)';
            
            btnLbl.textContent = 'إيقاف الاتصال';
            btnIcon.className = 'fas fa-stop-circle';
            btnBox.style.pointerEvents = 'auto'; // إعادة تشغيل الزر
            
            if(res.ip) {
                ipWrap.style.display = 'block';
                // اضافة الـ IP وعدّاد الاجهزة المتصلة اللي جلبها البايثون الذكي!
                let peerStr = (res.peers_count > 0) ? `   [ 🌐 متصل معك: ${res.peers_count} أجهزة ]` : '   [ 🌐 لا توجد أجهزة متصلة ]';
                ipVal.innerHTML = res.ip + `<span style="color:var(--gold);font-size:0.75rem;">${peerStr}</span>`;
            }
        } else {
            _isTailscaleRunning = false;
            
            statusTxt.textContent = 'مُعطل OFFLINE';
            statusTxt.style.cssText = 'font-size:0.75rem; font-weight:800; padding:3px 10px; border-radius:100px; background: rgba(229,9,20,.15); color: var(--red); border: 1px solid rgba(229,9,20,.3); float:left; transition: 0.3s;';
            
            btnBox.className = 'btn btn-g'; 
            btnBox.style.borderColor = 'rgba(255,255,255,.14)';
            btnBox.style.color = 'var(--t2)';
            btnBox.style.background = 'var(--s3)';

            btnLbl.textContent = 'بدء الاتصال السري';
            btnIcon.className = 'fas fa-power-off';
            btnBox.style.pointerEvents = 'auto';
            ipWrap.style.display = 'none';
        }
    }).catch(err => {
         statusTxt.textContent = 'ERROR / تأكد من الصلاحيات';
         statusTxt.style.color = '#ff9900';
         btnBox.style.pointerEvents = 'auto';
    });
}

function executeTailscaleAction() {
    const targetAction = _isTailscaleRunning ? 'stop' : 'start';
    const btnBox = document.getElementById('ts_display_btn');
    const btnLbl = document.getElementById('ts_btn_label');
    const btnIcon = btnBox.querySelector('i');
    
    // ستايل "الانتظار/التحميل" الجذاب مع قفل الزر لتفادي دبل كليك
    btnBox.style.pointerEvents = 'none';
    btnLbl.textContent = 'جار المعالجة...';
    btnIcon.className = 'fas fa-spinner fa-spin';

    api({ ajax_action: 'tailscale_command', ts_action: targetAction }).then(res => {
        // ننتظر 1.5 ثانية لاعطاء نظام شبكات أوبونتو وقته للاستيعاب، ثم نفحص!
        setTimeout(() => { fetchTailscaleStatus(); }, 1500); 
    }).catch(()=>{
         setTimeout(() => { fetchTailscaleStatus(); }, 1500); 
    });
}

// === START ADMIN MUSIC PLAYER LOGIC (intero.mp3 fixed) ===
const INTERO_URL = '/iptv/intero.mp3';
let adminMusic = new Audio(INTERO_URL);
adminMusic.loop = true;
let isMusicPlaying = false;

function initAdminMusic() {
    let savedPlay = localStorage.getItem('shashety_music_play');
    if(savedPlay === '1') {
        let pp = adminMusic.play();
        if(pp !== undefined) {
            pp.then(() => {
                isMusicPlaying = true;
                updateMusicMini(true);
            }).catch(() => {
                isMusicPlaying = false;
                updateMusicMini(false);
            });
        }
    } else {
        updateMusicMini(false);
    }
}

function playAdminMusic() {
    adminMusic.play().then(() => {
        isMusicPlaying = true;
        localStorage.setItem('shashety_music_play', '1');
        updateMusicMini(true);
    }).catch(e => {
        isMusicPlaying = false;
        localStorage.setItem('shashety_music_play', '0');
        updateMusicMini(false);
    });
}

function pauseAdminMusic() {
    adminMusic.pause();
    isMusicPlaying = false;
    localStorage.setItem('shashety_music_play', '0');
    updateMusicMini(false);
}

function toggleAdminMusic() {
    if(isMusicPlaying) pauseAdminMusic();
    else playAdminMusic();
}

function updateMusicMini(playing) {
    const eq = $('m_eq');
    if(!eq) return;
    if(playing) eq.classList.remove('paused');
    else eq.classList.add('paused');
}

document.addEventListener("DOMContentLoaded", () => {
    setTimeout(()=>{
        let activeSec = sessionStorage.getItem('active_sec');
        if(activeSec && activeSec !== 'dashboard') {
            let btn = document.querySelector(`.si[onclick*="S('${activeSec}')"]`);
            if(btn) { btn.click(); } else { S(activeSec); }
        }
    }, 150);
    initAdminMusic();
    fetchTailscaleStatus();
    if(typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
});

document.querySelectorAll(".si[onclick*='system-tools']").forEach(n => {
    n.addEventListener("click", () => setTimeout(fetchTailscaleStatus, 400));
});
// === END TAILSCALE HANDLER ===</script>
<script src="https://unpkg.com/lucide@latest"></script>
<!-- ════════════ تحسينات واجهة شاشتي المدمجة — إضافة آمنة ════════════ -->
<style id="shashety-improve-css">
  #shsToTop{position:fixed;left:20px;bottom:20px;z-index:9999;width:46px;height:46px;border:none;border-radius:50%;background:rgba(229,9,20,.92);color:#fff;cursor:pointer;font-size:20px;line-height:46px;text-align:center;box-shadow:0 6px 20px rgba(0,0,0,.35);opacity:0;transform:translateY(16px) scale(.9);transition:opacity .25s ease,transform .25s ease;pointer-events:none;}
  #shsToTop.show{opacity:1;transform:translateY(0) scale(1);pointer-events:auto;}
  #shsToTop:hover{background:#ff2b35;}
  #shsProgress{position:fixed;top:0;left:0;height:3px;width:0;background:linear-gradient(90deg,#e50914,#ff6b6b);z-index:10000;transition:width .3s ease,opacity .4s ease;box-shadow:0 0 10px rgba(229,9,20,.6);opacity:0;}
  img.shs-lazy{opacity:0;transition:opacity .4s ease;}
  img.shs-lazy.shs-loaded{opacity:1;}
  @media (prefers-reduced-motion: reduce){#shsToTop,#shsProgress,img.shs-lazy{transition:none !important;}}
</style>
<button id="shsToTop" aria-label="العودة للأعلى" title="العودة للأعلى">↑</button>
<div id="shsProgress"></div>
<script id="shashety-improve-js">
(function(){'use strict';
  function enableLazyImages(){try{document.querySelectorAll('img:not([loading])').forEach(function(img){if(img.src&&img.src.indexOf('data:')===0)return;img.setAttribute('loading','lazy');img.setAttribute('decoding','async');if(!img.complete){img.classList.add('shs-lazy');img.addEventListener('load',function(){img.classList.add('shs-loaded');},{once:true});img.addEventListener('error',function(){img.classList.add('shs-loaded');},{once:true});}});}catch(e){}}
  function initToTop(){var btn=document.getElementById('shsToTop');if(!btn)return;var ticking=false;function onScroll(){if(ticking)return;ticking=true;requestAnimationFrame(function(){if(window.scrollY>400)btn.classList.add('show');else btn.classList.remove('show');ticking=false;});}window.addEventListener('scroll',onScroll,{passive:true});btn.addEventListener('click',function(){window.scrollTo({top:0,behavior:'smooth'});});}
  function initProgress(){var bar=document.getElementById('shsProgress');if(!bar)return;document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('a');if(!a||!a.href)return;if(a.target==='_blank'||a.href.indexOf('#')!==-1)return;if(a.href.indexOf(window.location.origin)!==0)return;bar.style.opacity='1';bar.style.width='70%';});window.addEventListener('beforeunload',function(){bar.style.width='100%';});}
  function guardCdnScripts(){window.addEventListener('error',function(ev){var t=ev.target;if(t&&t.tagName==='SCRIPT'&&t.src&&!t.dataset.shsRetried){t.dataset.shsRetried='1';var s=document.createElement('script');s.src=t.src;s.async=true;s.defer=true;s.dataset.shsRetried='1';document.head.appendChild(s);}},true);}
  function init(){enableLazyImages();initToTop();initProgress();guardCdnScripts();}
  if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',init);}else{init();}
})();
</script>
<!-- ════════════════════ نهاية تحسينات واجهة شاشتي ════════════════════ -->
<script>
// API Settings Dynamic Logic
function addApiCard() {
    const sel = document.getElementById('apiTypeSelect');
    const type = sel.value;
    if(!type) return;
    const card = document.getElementById('card_' + type);
    if(card) {
        card.style.display = 'block';
    }
    sel.value = '';
}

function removeApiCard(type) {
    const card = document.getElementById('card_' + type);
    if(card) {
        card.style.display = 'none';
        if(type === 'tmdb') document.getElementById('api_tmdb_key').value = '';
        if(type === 'omdb') document.getElementById('api_omdb_key').value = '';
        if(type === 'os') {
            document.getElementById('api_os_user').value = '';
            document.getElementById('api_os_pass').value = '';
            document.getElementById('api_os_key').value = '';
        }
        document.getElementById('status_' + type).innerHTML = '';
    }
}

async function testApiTmdb() {
    const key = document.getElementById('api_tmdb_key').value.trim();
    const st = document.getElementById('status_tmdb');
    if(!key) {
        st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> يرجى إدخال المفتاح أولاً</span>';
        return;
    }
    st.innerHTML = '<span style="color:var(--t3)"><i class="fas fa-spinner fa-spin"></i> جاري الفحص...</span>';
    try {
        const r = await fetch(`https://api.themoviedb.org/3/configuration?api_key=${encodeURIComponent(key)}`);
        if(r.ok) {
            st.innerHTML = '<span style="color:var(--green)"><i class="fas fa-check-circle"></i> متصل ويعمل بنجاح</span>';
        } else {
            st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> مفتاح غير صالح أو الحساب لا يعمل</span>';
        }
    } catch(e) {
        st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> خطأ في الاتصال</span>';
    }
}

async function testApiOmdb() {
    const key = document.getElementById('api_omdb_key').value.trim();
    const st = document.getElementById('status_omdb');
    if(!key) {
        st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> يرجى إدخال المفتاح أولاً</span>';
        return;
    }
    st.innerHTML = '<span style="color:var(--t3)"><i class="fas fa-spinner fa-spin"></i> جاري الفحص...</span>';
    try {
        const r = await fetch(`https://www.omdbapi.com/?apikey=${encodeURIComponent(key)}&s=Batman&page=1`);
        const d = await r.json();
        if(d.Response === 'True') {
            st.innerHTML = '<span style="color:var(--green)"><i class="fas fa-check-circle"></i> متصل ويعمل بنجاح</span>';
        } else {
            st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> مفتاح غير صالح أو الحساب لا يعمل</span>';
        }
    } catch(e) {
        st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> خطأ في الاتصال</span>';
    }
}

function testApiOs() {
    const user = document.getElementById('api_os_user').value.trim();
    const pass = document.getElementById('api_os_pass').value.trim();
    const key  = document.getElementById('api_os_key').value.trim();
    const st   = document.getElementById('status_os');

    if(!key || !user || !pass) {
        st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> \u064a\u0631\u062c\u0649 \u0645\u0644\u0621 \u0627\u0644\u0645\u0641\u062a\u0627\u062d \u0648\u0627\u0633\u0645 \u0627\u0644\u0645\u0633\u062a\u062e\u062f\u0645 \u0648\u0643\u0644\u0645\u0629 \u0627\u0644\u0645\u0631\u0648\u0631</span>';
        return;
    }
    st.innerHTML = '<span style="color:var(--t3)"><i class="fas fa-spinner fa-spin"></i> \u062c\u0627\u0631\u064a \u0627\u0644\u0641\u062d\u0635...</span>';

    api({ajax_action:'os_login', username:user, password:pass, api_key:key}).then(d => {
        if(d.success){
            st.innerHTML = '<span style="color:var(--green)"><i class="fas fa-check-circle"></i> \u0645\u062a\u0635\u0644 \u0628\u0646\u062c\u0627\u062d \u2014 \u0631\u0635\u064a\u062f \u0627\u0644\u062a\u0646\u0632\u064a\u0644: '+(d.allowed||'?')+'</span>';
        } else {
            st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> '+(d.error||'\u0641\u0634\u0644 \u0627\u0644\u0627\u062a\u0635\u0627\u0644')+'</span>';
        }
    }).catch(() => {
        st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> \u062e\u0637\u0623 \u0641\u064a \u0627\u0644\u0627\u062a\u0635\u0627\u0644</span>';
    });
}

function fetchServerStats() {
    const fd = new FormData();
    fd.append('ajax_action', 'get_server_stats');
    
    // استخدام fetch مباشرة بدلاً من api() لمنع ظهور شريط التحميل العلوي
    fetch(location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            // CPU
            let cpuP = d.cpu.percent;
            document.getElementById('cpu_percent_text').textContent = cpuP + '%';
            let cpuColor = cpuP > 85 ? 'linear-gradient(90deg, #ff416c, #ff4b2b)' : (cpuP > 60 ? 'linear-gradient(90deg, #fceabb, #f8b500)' : 'linear-gradient(90deg, #B36BFF, #7B2CBF)');
            document.getElementById('cpu_bar').style.width = cpuP + '%';
            document.getElementById('cpu_bar').style.background = cpuColor;
            document.getElementById('cpu_desc').textContent = 'استهلاك المعالج الفعلي';

            // RAM
            let ramP = d.ram.percent;
            document.getElementById('ram_percent_text').textContent = ramP + '%';
            let ramColor = ramP > 85 ? 'linear-gradient(90deg, #ff416c, #ff4b2b)' : (ramP > 60 ? 'linear-gradient(90deg, #fceabb, #f8b500)' : 'linear-gradient(90deg, #00D084, #009e60)');
            document.getElementById('ram_bar').style.width = ramP + '%';
            document.getElementById('ram_bar').style.background = ramColor;
            document.getElementById('ram_used_text').textContent = 'مستخدم: ' + d.ram.used;
            document.getElementById('ram_total_text').textContent = 'الكلي: ' + d.ram.total;

            // Disk
            let diskP = d.disk.percent;
            document.getElementById('disk_percent_text').textContent = diskP + '%';
            let diskColor = diskP > 85 ? 'linear-gradient(90deg, #ff416c, #ff4b2b)' : (diskP > 60 ? 'linear-gradient(90deg, #fceabb, #f8b500)' : 'linear-gradient(90deg, #4CC9F0, #0096C7)');
            document.getElementById('disk_bar').style.width = diskP + '%';
            document.getElementById('disk_bar').style.background = diskColor;
            document.getElementById('disk_used_text').textContent = 'مستخدم: ' + d.disk.used;
            document.getElementById('disk_total_text').textContent = 'الكلي: ' + d.disk.total;
        }
    }).catch(e => {}); // الصمت عند الخطأ لمنع إزعاج المستخدم
}

document.addEventListener('DOMContentLoaded', () => {
    fetchServerStats();
    // تقليل المدة إلى 3 ثواني ليكون التحسس مستمر ولحظي
    setInterval(fetchServerStats, 3000);
});
</script>
</body>

</html>


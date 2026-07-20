<?php if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) { ob_start("ob_gzhandler"); } else { ob_start(); } ?>
<?php
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
            if (!is_dir($d)) {
                @mkdir($d, 0755, true);
            }
            // منع الوصول المباشر لملفات الكاش عبر المتصفح
            if (is_dir($d) && !is_file($d . '/.htaccess')) {
                @file_put_contents($d . '/.htaccess', "Deny from all\nRequire all denied\n");
            }
            return $d;
        }
        function shashety_cache_get($key, $ttl_seconds, callable $producer) {
            $key  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
            $file = shashety_cache_dir() . '/' . $key . '.json';
            if (is_file($file) && (time() - filemtime($file) < $ttl_seconds)) {
                $raw = @file_get_contents($file);
                if ($raw !== false) {
                    $data = json_decode($raw, true);
                    // نتحقق من نجاح فك الترميز بدل الاعتماد على != null
                    if (json_last_error() === JSON_ERROR_NONE) return $data;
                }
            }
            $data = $producer();
            // كتابة ذرّية: نكتب لملف مؤقت ثم نستبدل، لتفادي قراءة ملف ناقص
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                $tmp = $file . '.' . getmypid() . '.tmp';
                if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
                    if (!@rename($tmp, $file)) { @unlink($tmp); }
                }
            }
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

$license_key     = getLicenseKey();
$license_expired = false;
if ($license_key) {
    $license_result = verifyLicenseFromServer($license_key);
    if (!$license_result['success'] || !$license_result['valid']) $license_expired = true;
} else { $license_expired = true; }

$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings = [];
while ($row = $stmt->fetch()) $settings[$row['setting_key']] = $row['setting_value'];

$site_name        = $settings['site_name']        ?? 'Shashety';
$site_description = $settings['site_description'] ?? 'نظام IPTV احترافي';
$site_logo        = $settings['site_logo']        ?? '';
$welcome_title    = $settings['welcome_title']    ?? 'مرحباً بك في عالم البث المباشر';
$welcome_subtitle = $settings['welcome_subtitle'] ?? 'شاهد آلاف القنوات من جميع أنحاء العالم';
$footer_text      = $settings['footer_text']      ?? 'جميع الحقوق محفوظة © 2024 Shashety';
$theme_color      = $settings['theme_color']      ?? '#e50914';
$custom_css_db    = $settings['custom_css']       ?? '';

// ══ إعدادات إخفاء عناصر الواجهة (يُتحكم بها من admin.php) ══
$hide_search        = ($settings['hide_search']        ?? '0') === '1';
$hide_notifications = ($settings['hide_notifications'] ?? '0') === '1';
$hide_favorites     = ($settings['hide_favorites']     ?? '0') === '1';
$hide_music         = ($settings['hide_music']         ?? '0') === '1';
$hide_admin_btn     = ($settings['hide_admin_btn']     ?? '0') === '1';
$hide_social        = ($settings['hide_social']        ?? '0') === '1';
$hide_download      = ($settings['hide_download']      ?? '0') === '1';
$hide_cast          = ($settings['hide_cast']          ?? '0') === '1';
$hide_most_watched  = ($settings['hide_most_watched']  ?? '0') === '1';
$hide_suggestions   = ($settings['hide_suggestions']   ?? '0') === '1';
$hide_screensaver   = ($settings['hide_screensaver']   ?? '0') === '1';

/* ══════════════════════════════════════════════════════════════════════════
   ║  الإعدادات العامة الحساسة (يُتحكم بها من admin.php > الإعدادات العامة)     ║
   ║  كل قيمة معلّقة بوظيفتها. لا حاجة لتعديل أي ملف لتغييرها.                  ║
   ══════════════════════════════════════════════════════════════════════════ */
$gs_maintenance_mode    = ($settings['maintenance_mode']    ?? '0') === '1'; // وضع الصيانة (إغلاق الموقع)
$gs_maintenance_message = $settings['maintenance_message']  ?? 'الموقع تحت الصيانة حالياً، نعود قريباً بإذن الله'; // نص صفحة الصيانة
$gs_gate_enabled        = ($settings['gate_enabled']        ?? '0') === '1'; // قفل الموقع بكلمة مرور
$gs_gate_password       = $settings['gate_password']        ?? '';          // كلمة سر الدخول للموقع
$gs_force_https         = ($settings['force_https']         ?? '0') === '1'; // إجبار HTTPS
$gs_block_devtools      = ($settings['block_devtools']      ?? '0') === '1'; // منع أدوات المطور
$gs_disable_download    = ($settings['disable_download']    ?? '0') === '1'; // منع تحميل الفيديوهات
$gs_announce_enabled    = ($settings['announcement_enabled']?? '0') === '1'; // إظهار الشريط الإعلاني
$gs_announce_text       = $settings['announcement_text']    ?? '';          // نص الإعلان
$gs_announce_link       = $settings['announcement_link']    ?? '';          // رابط الإعلان
$gs_custom_head_code    = $settings['custom_head_code']     ?? '';          // كود مخصص داخل head
$gs_custom_body_code    = $settings['custom_body_code']     ?? '';          // كود مخصص قبل body
$gs_contact_whatsapp    = $settings['contact_whatsapp']     ?? '9647512328848';
$gs_contact_facebook    = $settings['contact_facebook']     ?? 'facebook.com/xxkpq';
$gs_contact_email       = $settings['contact_email']        ?? 'info@shashety-pro.com';

/* ══════════════════════════════════════════════════════════════════════════
   ║  إعدادات المجموعات المتقدمة (يُتحكم بها من admin.php > الإعدادات المتقدمة) ║
   ║  كل قيمة تُقرأ من جدول settings مع قيمتها الأصلية معلّقة بجانبها.          ║
   ║  المتغيّر $cfg يجمع كل الإعدادات؛ استعملها في أي مكان بـ $cfg['key'].     ║
   ══════════════════════════════════════════════════════════════════════════ */
$cfg = [];
// ── إعدادات البث الخادمية (20 إعداد) ──
$cfg['srv_hls_segment_duration'] = $settings['srv_hls_segment_duration'] ?? '6'; // الأصلي: 6
$cfg['srv_playlist_length'] = $settings['srv_playlist_length'] ?? '5'; // الأصلي: 5
$cfg['srv_llhls_enable'] = ($settings['srv_llhls_enable'] ?? '0') === '1'; // الأصلي: 0
$cfg['srv_ffmpeg_params'] = $settings['srv_ffmpeg_params'] ?? ''; // الأصلي: فارغ
$cfg['srv_hwaccel'] = $settings['srv_hwaccel'] ?? 'none'; // الأصلي: none
$cfg['srv_thread_count'] = $settings['srv_thread_count'] ?? '0'; // الأصلي: 0
$cfg['srv_tcp_udp_buffer'] = $settings['srv_tcp_udp_buffer'] ?? '8192'; // الأصلي: 8192
$cfg['srv_socket_buffer'] = $settings['srv_socket_buffer'] ?? '65536'; // الأصلي: 65536
$cfg['srv_cdn_failover'] = ($settings['srv_cdn_failover'] ?? '0') === '1'; // الأصلي: 0
$cfg['srv_stream_priority'] = $settings['srv_stream_priority'] ?? 'normal'; // الأصلي: normal
$cfg['srv_health_check_interval'] = $settings['srv_health_check_interval'] ?? '30'; // الأصلي: 30
$cfg['srv_auto_restart_stream'] = ($settings['srv_auto_restart_stream'] ?? '1') === '1'; // الأصلي: 1
$cfg['srv_stream_timeout'] = $settings['srv_stream_timeout'] ?? '20'; // الأصلي: 20
$cfg['srv_packet_loss_recovery'] = ($settings['srv_packet_loss_recovery'] ?? '1') === '1'; // الأصلي: 1
$cfg['srv_jitter_buffer'] = $settings['srv_jitter_buffer'] ?? '500'; // الأصلي: 500
$cfg['srv_abr_enable'] = ($settings['srv_abr_enable'] ?? '1') === '1'; // الأصلي: 1
$cfg['srv_max_bitrate'] = $settings['srv_max_bitrate'] ?? '8000'; // الأصلي: 8000
$cfg['srv_min_bitrate'] = $settings['srv_min_bitrate'] ?? '800'; // الأصلي: 800
$cfg['srv_gop_size'] = $settings['srv_gop_size'] ?? '48'; // الأصلي: 48
$cfg['srv_keyframe_interval'] = $settings['srv_keyframe_interval'] ?? '2'; // الأصلي: 2

// ── إعدادات الواجهة (7 إعداد) ──
$cfg['ui_theme'] = $settings['ui_theme'] ?? 'dark'; // الأصلي: dark
$cfg['theme_color'] = $settings['theme_color'] ?? '#e50914'; // الأصلي: #e50914
$cfg['ui_font'] = $settings['ui_font'] ?? 'Tajawal'; // الأصلي: Tajawal
$cfg['ui_font_size'] = $settings['ui_font_size'] ?? '16'; // الأصلي: 16
$cfg['ui_transitions'] = ($settings['ui_transitions'] ?? '1') === '1'; // الأصلي: 1
$cfg['ui_banner'] = $settings['ui_banner'] ?? ''; // الأصلي: فارغ
$cfg['ui_icon_style'] = $settings['ui_icon_style'] ?? 'solid'; // الأصلي: solid

// ── إعدادات الصور (5 إعداد) ──
$cfg['img_default_channel'] = $settings['img_default_channel'] ?? ''; // الأصلي: فارغ
$cfg['img_default_movie'] = $settings['img_default_movie'] ?? ''; // الأصلي: فارغ
$cfg['img_default_series'] = $settings['img_default_series'] ?? ''; // الأصلي: فارغ
$cfg['img_quality'] = $settings['img_quality'] ?? '85'; // الأصلي: 85
$cfg['img_compression'] = ($settings['img_compression'] ?? '1') === '1'; // الأصلي: 1

// ── إعدادات المستخدم (7 إعداد) ──
$cfg['usr_save_last_watch'] = ($settings['usr_save_last_watch'] ?? '1') === '1'; // الأصلي: 1
$cfg['usr_autoplay'] = ($settings['usr_autoplay'] ?? '1') === '1'; // الأصلي: 1
$cfg['usr_dark_mode'] = ($settings['usr_dark_mode'] ?? '1') === '1'; // الأصلي: 1
$cfg['usr_language'] = $settings['usr_language'] ?? 'ar'; // الأصلي: ar
$cfg['usr_notifications'] = ($settings['usr_notifications'] ?? '1') === '1'; // الأصلي: 1
$cfg['usr_favorites'] = ($settings['usr_favorites'] ?? '1') === '1'; // الأصلي: 1
$cfg['usr_watch_history'] = ($settings['usr_watch_history'] ?? '1') === '1'; // الأصلي: 1

// ── الأداء (Performance) (8 إعداد) ──
$cfg['perf_cache_duration'] = $settings['perf_cache_duration'] ?? '3600'; // الأصلي: 3600
$cfg['perf_image_cache'] = ($settings['perf_image_cache'] ?? '1') === '1'; // الأصلي: 1
$cfg['perf_api_cache'] = ($settings['perf_api_cache'] ?? '1') === '1'; // الأصلي: 1
$cfg['perf_gzip_brotli'] = ($settings['perf_gzip_brotli'] ?? '1') === '1'; // الأصلي: 1
$cfg['perf_lazy_loading'] = ($settings['perf_lazy_loading'] ?? '1') === '1'; // الأصلي: 1
$cfg['perf_http_version'] = $settings['perf_http_version'] ?? '2'; // الأصلي: 2
$cfg['perf_prefetch'] = ($settings['perf_prefetch'] ?? '1') === '1'; // الأصلي: 1
$cfg['perf_preconnect'] = ($settings['perf_preconnect'] ?? '1') === '1'; // الأصلي: 1

// ── إعدادات الترجمة (6 إعداد) ──
$cfg['sub_default_language'] = $settings['sub_default_language'] ?? 'ar'; // الأصلي: ar
$cfg['sub_font_size'] = $settings['sub_font_size'] ?? '18'; // الأصلي: 18
$cfg['sub_font_color'] = $settings['sub_font_color'] ?? '#ffffff'; // الأصلي: #ffffff
$cfg['sub_bg_color'] = $settings['sub_bg_color'] ?? '#000000'; // الأصلي: #000000
$cfg['sub_position'] = $settings['sub_position'] ?? 'bottom'; // الأصلي: bottom
$cfg['sub_bg_opacity'] = $settings['sub_bg_opacity'] ?? '60'; // الأصلي: 60

// ── إعدادات المسلسلات (5 إعداد) ──
$cfg['sr_resume_last_ep'] = ($settings['sr_resume_last_ep'] ?? '1') === '1'; // الأصلي: 1
$cfg['sr_auto_next_ep'] = ($settings['sr_auto_next_ep'] ?? '1') === '1'; // الأصلي: 1
$cfg['sr_skip_intro'] = ($settings['sr_skip_intro'] ?? '0') === '1'; // الأصلي: 0
$cfg['sr_skip_outro'] = ($settings['sr_skip_outro'] ?? '0') === '1'; // الأصلي: 0
$cfg['sr_season_order'] = $settings['sr_season_order'] ?? 'asc'; // الأصلي: asc

// ── إعدادات الأفلام (7 إعداد) ──
$cfg['mv_per_page'] = $settings['mv_per_page'] ?? '24'; // الأصلي: 24
$cfg['mv_default_quality'] = $settings['mv_default_quality'] ?? 'auto'; // الأصلي: auto
$cfg['mv_auto_subtitle'] = ($settings['mv_auto_subtitle'] ?? '0') === '1'; // الأصلي: 0
$cfg['mv_subtitle_language'] = $settings['mv_subtitle_language'] ?? 'ar'; // الأصلي: ar
$cfg['mv_play_trailer'] = ($settings['mv_play_trailer'] ?? '1') === '1'; // الأصلي: 1
$cfg['mv_show_similar'] = ($settings['mv_show_similar'] ?? '1') === '1'; // الأصلي: 1
$cfg['mv_resume_watch'] = ($settings['mv_resume_watch'] ?? '1') === '1'; // الأصلي: 1

// ── إعدادات القنوات (7 إعداد) ──
$cfg['ch_per_page'] = $settings['ch_per_page'] ?? '40'; // الأصلي: 40
$cfg['ch_order'] = $settings['ch_order'] ?? 'display_order'; // الأصلي: display_order
$cfg['ch_group_order'] = $settings['ch_group_order'] ?? 'display_order'; // الأصلي: display_order
$cfg['ch_hide_offline'] = ($settings['ch_hide_offline'] ?? '0') === '1'; // الأصلي: 0
$cfg['ch_auto_status'] = ($settings['ch_auto_status'] ?? '0') === '1'; // الأصلي: 0
$cfg['ch_check_interval'] = $settings['ch_check_interval'] ?? '60'; // الأصلي: 60
$cfg['ch_resume_last'] = ($settings['ch_resume_last'] ?? '1') === '1'; // الأصلي: 1

// ── إعدادات مشغّل الفيديو (14 إعداد) ──
$cfg['pl_autoplay'] = ($settings['pl_autoplay'] ?? '1') === '1'; // الأصلي: 1
$cfg['pl_mute_on_start'] = ($settings['pl_mute_on_start'] ?? '0') === '1'; // الأصلي: 0
$cfg['pl_auto_fullscreen'] = ($settings['pl_auto_fullscreen'] ?? '0') === '1'; // الأصلي: 0
$cfg['pl_pip'] = ($settings['pl_pip'] ?? '1') === '1'; // الأصلي: 1
$cfg['pl_webcast'] = ($settings['pl_webcast'] ?? '1') === '1'; // الأصلي: 1
$cfg['pl_seek_buttons'] = ($settings['pl_seek_buttons'] ?? '1') === '1'; // الأصلي: 1
$cfg['pl_playback_speed'] = $settings['pl_playback_speed'] ?? '1'; // الأصلي: 1
$cfg['pl_thumbnails'] = ($settings['pl_thumbnails'] ?? '1') === '1'; // الأصلي: 1
$cfg['pl_show_channel_logo'] = ($settings['pl_show_channel_logo'] ?? '1') === '1'; // الأصلي: 1
$cfg['pl_show_channel_name'] = ($settings['pl_show_channel_name'] ?? '1') === '1'; // الأصلي: 1
$cfg['pl_show_clock'] = ($settings['pl_show_clock'] ?? '0') === '1'; // الأصلي: 0
$cfg['pl_show_viewers'] = ($settings['pl_show_viewers'] ?? '0') === '1'; // الأصلي: 0
$cfg['pl_show_share'] = ($settings['pl_show_share'] ?? '1') === '1'; // الأصلي: 1
$cfg['pl_show_report'] = ($settings['pl_show_report'] ?? '1') === '1'; // الأصلي: 1

// ── إعدادات البث (Streaming) (17 إعداد) ──
$cfg['st_low_latency'] = ($settings['st_low_latency'] ?? '0') === '1'; // الأصلي: 0
$cfg['st_buffer_size'] = $settings['st_buffer_size'] ?? '30'; // الأصلي: 30
$cfg['st_startup_buffer'] = $settings['st_startup_buffer'] ?? '2'; // الأصلي: 2
$cfg['st_max_buffer'] = $settings['st_max_buffer'] ?? '60'; // الأصلي: 60
$cfg['st_back_buffer'] = $settings['st_back_buffer'] ?? '90'; // الأصلي: 90
$cfg['st_live_sync'] = $settings['st_live_sync'] ?? '3'; // الأصلي: 3
$cfg['st_auto_quality'] = ($settings['st_auto_quality'] ?? '1') === '1'; // الأصلي: 1
$cfg['st_default_quality'] = $settings['st_default_quality'] ?? 'auto'; // الأصلي: auto
$cfg['st_allow_quality_change'] = ($settings['st_allow_quality_change'] ?? '1') === '1'; // الأصلي: 1
$cfg['st_auto_reconnect'] = ($settings['st_auto_reconnect'] ?? '1') === '1'; // الأصلي: 1
$cfg['st_reconnect_attempts'] = $settings['st_reconnect_attempts'] ?? '5'; // الأصلي: 5
$cfg['st_reconnect_timeout'] = $settings['st_reconnect_timeout'] ?? '3'; // الأصلي: 3
$cfg['st_failover'] = ($settings['st_failover'] ?? '1') === '1'; // الأصلي: 1
$cfg['st_protocol'] = $settings['st_protocol'] ?? 'hls'; // الأصلي: hls
$cfg['st_llhls_support'] = ($settings['st_llhls_support'] ?? '0') === '1'; // الأصلي: 0
$cfg['st_playlist_refresh'] = $settings['st_playlist_refresh'] ?? '6'; // الأصلي: 6
$cfg['st_stream_cache'] = ($settings['st_stream_cache'] ?? '1') === '1'; // الأصلي: 1


// هل الزائر الحالي مدير مسجّل الدخول؟ (لتجاوز الصيانة والقفل)
/* [إصلاح] كانت هذه السطر تقرأ $_SESSION قبل بدء الجلسة، فتكون فارغة دائماً
   ولا يُتعرَّف على المدير إطلاقاً. نبدأ الجلسة أولاً. */
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$__is_admin_visitor = !empty($_SESSION['admin_logged_in']) || !empty($_SESSION['admin_username']) || !empty($_SESSION['is_admin']);

/* ══ [أمان] وكيل TMDB من جهة الخادم ══
   كان مفتاح TMDB يُطبع داخل شيفرة الصفحة، فيقرأه أي زائر من مصدر الصفحة
   ويستعمله باسم المالك. الآن يبقى المفتاح على الخادم، والمتصفح يسأل هذه
   النقطة فقط. تُنفَّذ قبل أي إخراج HTML. */
if (isset($_GET['tmdb_proxy'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $__tmdb_key = trim($settings['tmdb_api_key'] ?? '');
    if ($__tmdb_key === '') { echo json_encode(['error' => 'disabled']); exit; }

    // مسارات مسموحة فقط — لا نسمح للمتصفح بتمرير مسار حر
    $__mode = $_GET['tmdb_proxy'];
    $__lang = (($_GET['lang'] ?? 'ar') === 'en') ? 'en-US' : 'ar';

    if ($__mode === 'search') {
        $__q = trim((string)($_GET['q'] ?? ''));
        if ($__q === '' || mb_strlen($__q) > 200) { echo json_encode(['results' => []]); exit; }
        $__url = 'https://api.themoviedb.org/3/search/multi?api_key=' . urlencode($__tmdb_key)
               . '&query=' . urlencode($__q) . '&language=' . urlencode($__lang);
    } elseif ($__mode === 'detail') {
        $__type = ($_GET['type'] ?? '') === 'movie' ? 'movie' : 'tv';
        $__id   = (int)($_GET['id'] ?? 0);
        if ($__id <= 0) { echo json_encode(['error' => 'bad id']); exit; }
        $__url = 'https://api.themoviedb.org/3/' . $__type . '/' . $__id
               . '?api_key=' . urlencode($__tmdb_key) . '&language=' . urlencode($__lang);
    } else {
        echo json_encode(['error' => 'bad mode']); exit;
    }

    $__ch = curl_init($__url);
    curl_setopt_array($__ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'ShashetyIPTV',
    ]);
    $__res  = curl_exec($__ch);
    $__code = (int)curl_getinfo($__ch, CURLINFO_HTTP_CODE);
    curl_close($__ch);

    if ($__res === false) { echo json_encode(['error' => 'network']); exit; }
    // لا نمرّر أخطاء TMDB الخام (قد تحوي المفتاح في رسالة الخطأ)
    if ($__code === 401) { echo json_encode(['error' => 'bad key']); exit; }
    if ($__code !== 200) { echo json_encode(['error' => 'upstream']); exit; }
    echo $__res;
    exit;
}

/* ── إجبار HTTPS: إعادة توجيه أي http إلى https ── */
if ($gs_force_https && empty($_SERVER['HTTPS']) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https' && PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        $__redir = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . $__redir, true, 301);
        exit;
    }
}

/* ── بوابة قفل الموقع بكلمة مرور (تُطبّق قبل أي إخراج، تتجاوزها إدارة الموقع) ── */
if ($gs_gate_enabled && $gs_gate_password !== '' && !$__is_admin_visitor) {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    // معالجة إرسال كلمة السر
    if (isset($_POST['__gate_pw'])) {
        if (hash_equals($gs_gate_password, (string)$_POST['__gate_pw'])) {
            $_SESSION['__gate_ok'] = true;
        } else {
            $__gate_error = 'كلمة المرور غير صحيحة';
        }
    }
    if (empty($_SESSION['__gate_ok'])) {
        // عرض صفحة البوابة والتوقف
        $__gn = htmlspecialchars($settings['site_name'] ?? 'Shashety');
        $__gerr = isset($__gate_error) ? '<p style="color:#ff6b6b;margin:0 0 12px">'.htmlspecialchars($__gate_error).'</p>' : '';
        http_response_code(401);
        echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$__gn.' — دخول محمي</title></head>'
           . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0f0f0f;font-family:Tahoma,Arial,sans-serif;color:#fff">'
           . '<form method="POST" style="background:#181818;padding:40px 32px;border-radius:16px;max-width:360px;width:90%;text-align:center;border:1px solid #2a2a2a">'
           . '<div style="font-size:2.4rem;margin-bottom:14px">🔒</div>'
           . '<h2 style="margin:0 0 6px;font-size:1.3rem">'.$__gn.'</h2>'
           . '<p style="color:#999;font-size:.9rem;margin:0 0 20px">هذا الموقع محمي. أدخل كلمة المرور للمتابعة.</p>'
           . $__gerr
           . '<input type="password" name="__gate_pw" placeholder="كلمة المرور" autofocus style="width:100%;padding:12px;border-radius:10px;border:1px solid #333;background:#0f0f0f;color:#fff;box-sizing:border-box;margin-bottom:14px;text-align:center">'
           . '<button type="submit" style="width:100%;padding:12px;border:none;border-radius:10px;background:#e50914;color:#fff;font-weight:700;font-size:1rem;cursor:pointer">دخول</button>'
           . '</form></body></html>';
        exit;
    }
}

/* ── وضع الصيانة: يُغلق الموقع أمام الزوار (المدير يتجاوزه) ── */
if ($gs_maintenance_mode && !$__is_admin_visitor) {
    $__mn  = htmlspecialchars($settings['site_name'] ?? 'Shashety');
    $__msg = htmlspecialchars($gs_maintenance_message);
    http_response_code(503);
    header('Retry-After: 3600');
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$__mn.' — صيانة</title></head>'
       . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0f0f0f,#1a0508);font-family:Tahoma,Arial,sans-serif;color:#fff;text-align:center">'
       . '<div style="max-width:480px;padding:40px 24px">'
       . '<div style="font-size:4rem;margin-bottom:16px">🛠️</div>'
       . '<h1 style="margin:0 0 12px;font-size:1.8rem">الموقع تحت الصيانة</h1>'
       . '<p style="color:#bbb;font-size:1.05rem;line-height:1.8;margin:0">'.$__msg.'</p>'
       . '<div style="margin-top:28px;color:#666;font-size:.85rem">'.$__mn.'</div>'
       . '</div></body></html>';
    exit;
}

// ══ الأقسام المعطّلة (مخفية) من الواجهة الأمامية — تُتحكم من admin.php > الأقسام ══
$disabled_category_ids = [];
try {
    $dc = $pdo->query("SELECT id FROM categories WHERE COALESCE(is_active,1) = 0");
    $disabled_category_ids = $dc ? array_map('intval', $dc->fetchAll(PDO::FETCH_COLUMN)) : [];
} catch (PDOException $e) {}

// ══ القنوات المعطّلة (غير نشطة) من الواجهة الأمامية — تُتحكم من admin.php > القنوات ══
$disabled_channel_ids = [];
try {
    $dch = $pdo->query("SELECT id FROM channels WHERE COALESCE(is_active,1) = 0");
    $disabled_channel_ids = $dch ? array_map('intval', $dch->fetchAll(PDO::FETCH_COLUMN)) : [];
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<link rel="dns-prefetch" href="https://fonts.googleapis.com">
<link rel="dns-prefetch" href="https://fonts.gstatic.com" crossorigin>
<link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
<link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://fonts.googleapis.com">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#141414">
<meta name="title" content="<?php echo htmlspecialchars($site_name); ?> - <?php echo htmlspecialchars($site_description); ?>">
<meta name="description" content="<?php echo htmlspecialchars($site_description); ?>">
<meta name="robots" content="index, follow">
<meta property="og:type" content="website">
<meta property="og:title" content="<?php echo htmlspecialchars($site_name); ?>">
<meta property="og:description" content="<?php echo htmlspecialchars($site_description); ?>">
<meta property="og:locale" content="ar_AR">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📺</text></svg>">
<title><?php echo htmlspecialchars($site_name); ?> — <?php echo htmlspecialchars($welcome_title); ?></title>
<link rel="preload" href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;900&display=swap"></noscript>
<style>
/* ════ ROOT VARIABLES ════ */
:root {
  --red:#e50914; --bg:#0f0f0f; --bg2:#181818; --bg3:#202020;
  --surface:rgba(28,28,28,.97); --border:rgba(255,255,255,.1);
  --text:#f0f0f0; --text-dim:#b8b8b8; --text-muted:#707070;
  --accent:<?php echo htmlspecialchars($theme_color); ?>;
  --radius:10px; --radius-lg:16px; --radius-xl:24px;
  --shadow:0 10px 50px rgba(0,0,0,.8);
  --transition:all .35s cubic-bezier(0.25, 1, 0.4, 1);
  --ease-spring:cubic-bezier(0.175, 0.885, 0.32, 1.275);
  --ease-out:cubic-bezier(0.19, 1, 0.22, 1);
}

@media (prefers-reduced-motion: reduce) {
  *,*::before,*::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
  }
}

/* ════ RESET ════ */
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{scroll-behavior:smooth;overflow-x:hidden;width:100%}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden;width:100%;max-width:100vw;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
a{text-decoration:none;color:inherit}
button{font-family:inherit;cursor:pointer;border:none;background:none}
img{display:block;max-width:100%;height:auto}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:99px}
::-webkit-scrollbar-thumb:hover{background:var(--red)}
.hidden{display:none!important}

/* ════ KEYFRAMES ════ */
@keyframes shimmer{0%{background-position:-900px 0}100%{background-position:900px 0}}
@keyframes fadeIn{0%{opacity:0;transform:translateY(15px)}100%{opacity:1;transform:translateY(0)}}
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
@keyframes cardIn{0%{opacity:0;transform:translateY(40px) scale(0.9)}100%{opacity:1;transform:translateY(0) scale(1)}}
@keyframes glowPulse{0%,100%{box-shadow:0 0 8px rgba(229,9,20,.25)}50%{box-shadow:0 0 18px rgba(229,9,20,.55)}}
@keyframes iconBounce{0%{transform:scale(1)}30%{transform:scale(1.22) rotate(-7deg)}55%{transform:scale(1.14) rotate(4deg)}100%{transform:scale(1.18) rotate(-6deg)}}
@keyframes spin2{to{transform:rotate(360deg)}}
@keyframes playerSlideIn{from{opacity:0}to{opacity:1}}
@keyframes lockFloat{0%,100%{transform:translateY(0) scale(1)}50%{transform:translateY(-8px) scale(1.04)}}
@keyframes shakeIcon{0%,100%{transform:rotate(0)}8%{transform:rotate(-10deg)}18%{transform:rotate(10deg)}28%{transform:rotate(-7deg)}38%{transform:rotate(7deg)}}
@keyframes ripple{to{transform:scale(5);opacity:0}}
@keyframes toast-in{from{opacity:0;transform:translateX(60px) scale(.85)}to{opacity:1;transform:translateX(0) scale(1)}}
@keyframes toast-out{to{opacity:0;transform:translateX(60px) scale(.85)}}
@keyframes nxKenBurns{0%{transform:scale(1)}100%{transform:scale(1.12)}}
@keyframes nxFloat{0%{transform:rotateY(-5deg) translateY(0)}100%{transform:rotateY(-3deg) translateY(-12px)}}
@keyframes nxBounce{0%,100%{transform:translateX(0)}50%{transform:translateX(8px)}}
@keyframes musicBarAnim{0%{transform:scaleY(0.3)}100%{transform:scaleY(1)}}

/* ════ SKELETON ════ */
.skeleton{background:linear-gradient(110deg,#181818 20%,#2c2c2c 40%,#3d3d3d 50%,#2c2c2c 60%,#181818 80%);background-size:1200px 100%;animation:shimmer 1.5s ease-in infinite;border-radius:var(--radius)}

/* ════ DEVTOOLS OVERLAY ════ */
.devtools-overlay{display:none;position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.97);backdrop-filter:blur(20px);align-items:center;justify-content:center;flex-direction:column;animation:fadeIn .3s ease}
.devtools-overlay.show{display:flex}
.devtools-box{background:linear-gradient(160deg,#1a0a0a,#140000);border:1px solid rgba(229,9,20,.35);border-radius:var(--radius-xl);padding:52px 56px;text-align:center;max-width:440px;width:90%;box-shadow:0 0 80px rgba(229,9,20,.25),0 30px 80px rgba(0,0,0,.9)}
.devtools-lock-icon{font-size:4.5rem;margin-bottom:24px;display:inline-block;animation:lockFloat 3.5s ease-in-out infinite}
.devtools-lock-icon.shake{animation:shakeIcon .7s ease,lockFloat 3.5s ease-in-out .7s infinite}
.devtools-title{font-size:1.7rem;font-weight:900;color:#fff;margin-bottom:10px}
.devtools-sub{font-size:1rem;color:#707070;line-height:1.6;margin-bottom:28px}
.devtools-badge{display:inline-flex;align-items:center;gap:8px;background:rgba(229,9,20,.12);border:1px solid rgba(229,9,20,.3);padding:8px 20px;border-radius:99px;font-size:.85rem;font-weight:700;color:#ff6060}

/* ════ LICENSE BANNER ════ */
.license-banner{background:linear-gradient(135deg,#9a0000,#c00,#b71c1c);padding:14px 20px;display:flex;align-items:center;justify-content:center;gap:16px;font-weight:700;font-size:.9rem;box-shadow:0 4px 25px rgba(183,28,28,.6)}
.lic-renew{background:rgba(255,255,255,.2);color:#fff;padding:7px 18px;border-radius:99px;font-weight:800;transition:var(--transition);border:1px solid rgba(255,255,255,.3)}

/* ════ NAVBAR ════ */
.navbar{
  position:fixed;top:0;left:0;right:0;z-index:900;
  padding: max(10px, env(safe-area-inset-top)) max(15px, env(safe-area-inset-right)) 10px max(15px, env(safe-area-inset-left));
  display:flex;align-items:center;gap:12px;
  background:rgba(12,12,12,.7);backdrop-filter:blur(24px) saturate(180%);
  -webkit-backdrop-filter:blur(24px) saturate(180%);
  border-bottom:1px solid rgba(255,255,255,.05);transition:.4s var(--ease-out);
}
.navbar.scrolled{box-shadow:0 4px 20px rgba(0,0,0,.5)}
.nav-actions{display:flex;align-items:center;gap:7px;flex-shrink:0;order:1}
.nav-center{flex:1;order:2}
.nav-brand{flex-shrink:0;order:3}
.nav-logo-img{width:32px;height:32px;border-radius:5px;object-fit:cover}
.nav-logo-text{font-size:1.3rem;font-weight:900;letter-spacing:-1px;color:var(--red)}
.search-wrap{position:relative}
.search-wrap input{
  width:100%;padding:9px 38px 9px 14px;
  background:rgba(255,255,255,.06);
  border:1.5px solid rgba(255,255,255,.15);
  border-radius:99px;color:var(--text);
  font-family:inherit;font-size:.9rem;direction:rtl;
  transition:border-color .2s,background .2s;
}
.search-wrap input:focus{outline:none;background:rgba(255,255,255,.1);border-color:var(--red)}
.search-wrap .si{position:absolute;right:13px;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;font-size:.85rem}
.nav-btn{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.08);border:1.5px solid rgba(255,255,255,.1);color:#ccc;display:flex;align-items:center;justify-content:center;font-size:.9rem;transition:var(--transition);position:relative;cursor:pointer}
.nav-btn:hover{background:var(--red);border-color:var(--red);color:#fff;transform:scale(1.08)}
#notifBadge{position:absolute;top:3px;right:3px;width:8px;height:8px;border-radius:50%;background:red;box-shadow:0 0 8px #ff3040}
/* nav responsive في كتلة الـ responsive الموحّدة أدناه */

/* ════ CATEGORY QUICK NAV (شريط اختصارات الأقسام) ════ */
.cat-navbar{
  position:fixed;left:0;right:0;z-index:880;
  top:var(--navbar-h, 68px);
  display:flex;align-items:center;gap:8px;
  padding:10px max(15px, env(safe-area-inset-right)) 10px max(15px, env(safe-area-inset-left));
  background:rgba(12,12,12,.55);backdrop-filter:blur(18px) saturate(160%);
  -webkit-backdrop-filter:blur(18px) saturate(160%);
  border-bottom:1px solid rgba(255,255,255,.05);
  overflow-x:auto;overflow-y:hidden;scrollbar-width:none;-ms-overflow-style:none;
  white-space:nowrap;
}
.cat-navbar::-webkit-scrollbar{display:none}
.cat-nav-btn{
  flex-shrink:0;display:inline-flex;align-items:center;gap:6px;
  padding:7px 16px;border-radius:99px;cursor:pointer;
  background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);
  color:#ccc;font-size:.82rem;font-weight:700;
  transition:var(--transition);font-family:inherit;
}
.cat-nav-btn:hover{background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.22);color:#fff}
.cat-nav-btn.active{background:var(--red);border-color:var(--red);color:#fff;box-shadow:0 4px 14px rgba(229,9,20,.4)}
.cat-nav-btn i,.cat-nav-btn .lcn{font-size:.8rem}
/* [SHS-ICON-FIX] ضمان وراثة اللون والحجم لأيقونات SVG داخل شريط الأقسام */
.cat-nav-btn .lcn{display:inline-flex;align-items:center;justify-content:center;line-height:0;flex-shrink:0}
.cat-nav-btn svg,.cat-nav-btn .lcn svg{
  width:1em;height:1em;flex-shrink:0;
  color:inherit;stroke:currentColor;fill:none;
  vertical-align:middle;pointer-events:none;
}
.cat-nav-btn.active svg,.cat-nav-btn.active .lcn svg{color:inherit;stroke:currentColor}

/* ════ HERO WELCOME ════ */
.hero-welcome{padding:0 20px;margin-bottom:28px}
.hero-welcome h1{font-size:clamp(1.5rem,2.5vw,2.4rem);font-weight:900;margin-bottom:8px;animation:fadeUp .6s ease both}
.hero-welcome p{color:#aaa;font-size:.95rem;animation:fadeUp .6s .1s ease both}

/* ════ SECTION ROW ════ */
.netflix-slider-row{position:relative;margin-bottom:32px}
/* تسريع الرسم: تخطّي رسم الصفوف خارج الشاشة حتى الاقتراب منها (إضافة) */
.netflix-slider-row{content-visibility:auto;contain-intrinsic-size:auto 260px}
.slider-header{display:flex;align-items:center;justify-content:space-between;padding:0 12px;margin-bottom:10px;border-right:3px solid var(--red)}
.slider-title{display:flex;align-items:center;gap:8px;font-size:1rem;font-weight:800;color:#fff;padding:0;margin:0;flex:1;min-width:0}
.slider-title-icon{width:26px;height:26px;border-radius:5px;background:rgba(229,9,20,.15);border:1px solid rgba(229,9,20,.3);display:flex;align-items:center;justify-content:center;color:#ff4d57;font-size:.75rem;flex-shrink:0}
.slider-badge{font-size:.68rem;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);padding:2px 7px;border-radius:99px;color:var(--text-muted);white-space:nowrap}
.slider-nav-btns{display:none}
.snav-btn{display:none}
.slider-scroll-mask{position:relative}
.slider-footer{display:none}

/* [SHS-CATMENU-STYLE-START] قائمة الأقسام العمودية المنسدلة (إضافة فقط) */
.shs-catmenu-btn{
  width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.08);
  border:1.5px solid rgba(255,255,255,.1);color:#ccc;display:flex;align-items:center;
  justify-content:center;font-size:.9rem;transition:var(--transition);cursor:pointer;position:relative;
}
.shs-catmenu-btn:hover{background:var(--red);border-color:var(--red);color:#fff;transform:scale(1.08)}
.shs-catmenu-overlay{
  position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1400;opacity:0;visibility:hidden;
  transition:opacity .25s ease,visibility .25s ease;
}
.shs-catmenu-overlay.open{opacity:1;visibility:visible}
.shs-catmenu-panel{
  position:fixed;top:0;right:0;height:100%;width:min(300px,82vw);
  background:#141414;border-left:1px solid rgba(255,255,255,.08);
  box-shadow:-8px 0 40px rgba(0,0,0,.6);z-index:1401;
  transform:translateX(100%);transition:transform .3s cubic-bezier(.4,0,.2,1);
  display:flex;flex-direction:column;overflow-y:auto;
}
.shs-catmenu-panel.open{transform:translateX(0)}
.shs-catmenu-panel::-webkit-scrollbar{width:6px}
.shs-catmenu-panel::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:3px}
.shs-catmenu-head{
  display:flex;align-items:center;justify-content:space-between;gap:16px;
  padding:18px 20px;border-bottom:1px solid rgba(255,255,255,.08);
  position:sticky;top:0;background:#141414;z-index:2;
}
.shs-catmenu-head a,.shs-catmenu-head .shs-catmenu-home{
  background:none;border:none;color:#eaeaea;font-size:.95rem;font-weight:700;
  cursor:pointer;font-family:inherit;padding:4px 2px;transition:color .2s;
}
.shs-catmenu-head a:hover,.shs-catmenu-head .shs-catmenu-home:hover{color:var(--red)}
.shs-catmenu-close{
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);
  color:#ccc;width:30px;height:30px;border-radius:50%;display:flex;
  align-items:center;justify-content:center;cursor:pointer;transition:.2s;flex-shrink:0;
}
.shs-catmenu-close:hover{background:var(--red);border-color:var(--red);color:#fff}
.shs-catmenu-list{display:flex;flex-direction:column;padding:6px 0}
.shs-catmenu-item{
  background:none;border:none;color:#dcdcdc;font-family:inherit;
  text-align:right;font-size:.95rem;padding:16px 22px;cursor:pointer;
  border-bottom:1px solid rgba(255,255,255,.06);transition:background .2s,color .2s;
  display:flex;align-items:center;justify-content:flex-end;gap:10px;
}
.shs-catmenu-item:hover{background:rgba(255,255,255,.05);color:#fff}
.shs-catmenu-item.active{color:var(--red);font-weight:700}
.shs-catmenu-item .shs-catmenu-arrow{color:var(--text-muted);font-size:.8rem;order:-1}
.shs-catmenu-empty{padding:24px 22px;color:var(--text-muted);font-size:.85rem;text-align:center}
@media(max-width:600px){
  .shs-catmenu-item{padding:15px 18px;font-size:.9rem}
  .shs-catmenu-head{padding:16px 18px}
}
/* [SHS-CATMENU-STYLE-END] */

/* [SHS-CATMENU-PRO-START] إخفاء الشريط الأفقي + تصميم احترافي للقائمة العمودية (إضافة فقط) */
/* إخفاء شريط الأقسام الأفقي (بدون حذف الكود) */
#catNavbar{display:none !important}

/* لوحة أعرض بخلفية متدرجة وحواف ناعمة */
.shs-catmenu-panel{
  width:min(330px,86vw);
  background:linear-gradient(180deg,#181818 0%,#101010 100%);
  border-left:1px solid rgba(255,255,255,.06);
  box-shadow:-14px 0 50px rgba(0,0,0,.7);
}
.shs-catmenu-overlay{background:rgba(0,0,0,.62);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px)}

/* رأس احترافي: عنوان بارز + خط سفلي بلون الهوية */
.shs-catmenu-head{
  flex-direction:row-reverse;justify-content:space-between;align-items:center;
  padding:20px 22px 16px;border-bottom:1px solid rgba(255,255,255,.06);
  background:transparent;
}
.shs-catmenu-head .shs-catmenu-home{display:none}
.shs-catmenu-headwrap{display:flex;flex-direction:column;gap:2px;align-items:flex-start}
.shs-catmenu-title{
  font-size:1.15rem;font-weight:900;color:#fff;letter-spacing:-.3px;
  display:flex;align-items:center;gap:9px;
}
.shs-catmenu-title::before{
  content:"";width:4px;height:20px;border-radius:99px;
  background:linear-gradient(180deg,var(--red),#ff5b64);display:inline-block;
}
.shs-catmenu-sub{font-size:.72rem;color:var(--text-muted);padding-right:13px}

/* زر الرئيسية كصف مميّز أعلى القائمة */
.shs-catmenu-homerow{
  display:flex;align-items:center;justify-content:flex-end;gap:10px;
  margin:12px 16px 6px;padding:13px 16px;cursor:pointer;
  background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);
  border-radius:12px;color:#eaeaea;font-family:inherit;font-size:.92rem;font-weight:700;
  transition:background .2s,border-color .2s,transform .15s;
}
.shs-catmenu-homerow:hover{background:rgba(229,9,20,.14);border-color:rgba(229,9,20,.4);color:#fff;transform:translateX(-3px)}
.shs-catmenu-homerow .lcn{color:var(--red);font-size:1rem}

/* عناصر الأقسام: بطاقات ناعمة بدل خطوط فاصلة صريحة */
.shs-catmenu-list{padding:6px 12px 20px;gap:2px}
.shs-catmenu-item{
  border-bottom:none;border-radius:11px;padding:14px 16px;margin:1px 0;
  position:relative;overflow:hidden;
}
.shs-catmenu-item::after{content:none}
.shs-catmenu-item:hover{background:rgba(255,255,255,.06);transform:translateX(-3px)}
.shs-catmenu-item.active{
  background:linear-gradient(90deg,rgba(229,9,20,.18),rgba(229,9,20,.04));
  color:#fff;
}
.shs-catmenu-item.active::before{
  content:"";position:absolute;right:0;top:18%;height:64%;width:3px;
  border-radius:99px;background:var(--red);
}
.shs-catmenu-item .shs-catmenu-idx{
  font-size:.7rem;color:var(--text-muted);background:rgba(255,255,255,.05);
  border:1px solid rgba(255,255,255,.08);border-radius:7px;min-width:24px;height:24px;
  display:flex;align-items:center;justify-content:center;order:-2;flex-shrink:0;transition:.2s;
}
.shs-catmenu-item:hover .shs-catmenu-idx,.shs-catmenu-item.active .shs-catmenu-idx{
  background:rgba(229,9,20,.18);border-color:rgba(229,9,20,.35);color:#ff8a90;
}
.shs-catmenu-item .shs-catmenu-name{flex:1;text-align:right}
.shs-catmenu-item .shs-catmenu-arrow{opacity:0;transform:translateX(4px);transition:.2s}
.shs-catmenu-item:hover .shs-catmenu-arrow{opacity:1;transform:translateX(0)}

/* شارة عدد الأقسام في الأسفل */
.shs-catmenu-count{
  margin-top:auto;padding:14px 22px;border-top:1px solid rgba(255,255,255,.06);
  font-size:.72rem;color:var(--text-muted);text-align:center;
}
/* [SHS-CATMENU-PRO-END] */

/* [SHS-SEARCHBAR-START] ترتيب وتصميم شريط البحث ليطابق الصورة (إضافة فقط) */
/* الترتيب: الشعار أقصى اليمين — البحث يتمدّد — الأزرار أقصى اليسار */
.navbar .nav-brand{order:3 !important}
.navbar .nav-center{order:2 !important;flex:1 !important;min-width:0}
.navbar .nav-actions{order:1 !important}

/* كبسولة البحث: خلفية داكنة زرقاء + العدسة يساراً + نص يبدأ من اليسار */
.search-wrap{position:relative;max-width:640px;margin:0 auto}
.search-wrap input{
  width:100% !important;
  padding:11px 16px 11px 44px !important;   /* مساحة للعدسة على اليسار */
  background:linear-gradient(180deg,#1e2a4a 0%,#182238 100%) !important;
  border:1.5px solid rgba(120,150,220,.22) !important;
  border-radius:999px !important;
  color:#e8ecf6 !important;
  font-size:.92rem !important;
  direction:ltr !important;                 /* النص والعدسة على اليسار كما بالصورة */
  text-align:left !important;
  box-shadow:0 2px 10px rgba(0,0,0,.28) inset,0 1px 0 rgba(255,255,255,.03);
}
.search-wrap input::placeholder{color:#9fb0d4 !important;opacity:.9}
.search-wrap input:focus{
  background:linear-gradient(180deg,#22315a 0%,#1b2743 100%) !important;
  border-color:rgba(120,150,220,.5) !important;
  box-shadow:0 0 0 3px rgba(90,120,200,.18),0 2px 10px rgba(0,0,0,.3) inset !important;
}
/* أيقونة العدسة على اليسار */
.search-wrap .si{
  right:auto !important;left:15px !important;
  color:#9fb0d4 !important;font-size:.9rem !important;
}
/* زر البحث الصوتي ينتقل إلى اليمين لتفادي تداخله مع العدسة */
#voiceSearchBtn{left:auto !important;right:14px !important;color:#9fb0d4 !important}

/* الشعار على أقصى اليمين بحجم أوضح */
.navbar .nav-logo-img{width:34px;height:34px;border-radius:8px}
/* [SHS-SEARCHBAR-END] */

/* [SHS-CATVIEW-STYLE-START] عرض احترافي داخل الأقسام + هياكل تحميل (إضافة فقط) */
/* بانر عنوان القسم */
.shs-catview-banner{
  display:flex;align-items:center;gap:14px;margin:6px 0 20px;padding:16px 18px;
  border-radius:16px;position:relative;overflow:hidden;
  background:linear-gradient(120deg,rgba(229,9,20,.14),rgba(30,42,74,.35) 60%,rgba(255,255,255,.02));
  border:1px solid rgba(255,255,255,.07);
}
.shs-catview-banner::before{
  content:"";position:absolute;inset:0;z-index:0;
  background:radial-gradient(120% 140% at 100% 0,rgba(229,9,20,.22),transparent 55%);
  pointer-events:none;
}
.shs-catview-banner>*{position:relative;z-index:1}
.shs-catview-ico{
  width:46px;height:46px;flex-shrink:0;border-radius:13px;display:flex;align-items:center;justify-content:center;
  background:linear-gradient(180deg,rgba(229,9,20,.28),rgba(229,9,20,.12));
  border:1px solid rgba(229,9,20,.35);color:#ff6169;font-size:1.15rem;
  box-shadow:0 4px 14px rgba(229,9,20,.25);
}
.shs-catview-meta{display:flex;flex-direction:column;gap:4px;min-width:0;flex:1}
.shs-catview-name{font-size:1.25rem;font-weight:900;color:#fff;letter-spacing:-.4px;line-height:1.1}
.shs-catview-chips{display:flex;flex-wrap:wrap;gap:6px}
.shs-catview-chip{
  font-size:.68rem;font-weight:700;padding:3px 10px;border-radius:99px;
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:var(--text-dim);
  display:inline-flex;align-items:center;gap:5px;
}
.shs-catview-chip.total{background:rgba(229,9,20,.16);border-color:rgba(229,9,20,.32);color:#ff8a90}
.shs-catview-chip .dot{width:6px;height:6px;border-radius:50%;background:currentColor;opacity:.8}

/* فاصل بين القنوات والمسلسلات */
.shs-catview-sep{
  grid-column:1/-1;display:flex;align-items:center;gap:12px;margin:14px 0 4px;
  color:var(--text-muted);font-size:.78rem;font-weight:700;
}
.shs-catview-sep::before,.shs-catview-sep::after{content:"";height:1px;flex:1;background:rgba(255,255,255,.08)}

/* هياكل تحميل (Skeleton) بدل السبنر */
.shs-skel-grid{display:none}
.shs-skel-card{
  border-radius:12px;overflow:hidden;background:rgba(255,255,255,.04);
  border:1px solid rgba(255,255,255,.05);aspect-ratio:2/3;position:relative;
}
.shs-skel-card::after{
  content:"";position:absolute;inset:0;
  background:linear-gradient(100deg,transparent 20%,rgba(255,255,255,.07) 45%,transparent 70%);
  transform:translateX(-100%);animation:shsShimmer 1.25s infinite;
}
@keyframes shsShimmer{100%{transform:translateX(100%)}}

/* ظهور ناعم لشبكة العناصر */
.shs-fadein{animation:shsFadeIn .35s ease both}
@keyframes shsFadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

/* إخفاء صف العنوان القديم داخل قسم العرض (البانر يحلّ محله) — بلا حذف من الـ HTML */
#categoryViewSection > div:has(> #categoryViewTitle){display:none !important}
/* [SHS-CATVIEW-STYLE-END] */

/* [SHS-POLISH-START] لمسات احترافية: شريط أفقي + عرض القسم + القائمة الجانبية (إضافة فقط) */

/* ═══ (1) شريط الأقسام الأفقي — Pills زجاجية + حركات دقيقة + تدرّج للنشط ═══ */
.cat-navbar{
  background:linear-gradient(180deg,rgba(18,18,22,.72),rgba(12,12,14,.55)) !important;
  backdrop-filter:blur(22px) saturate(180%) !important;
  -webkit-backdrop-filter:blur(22px) saturate(180%) !important;
  gap:9px !important;
}
.cat-nav-btn{
  padding:8px 18px !important;
  background:rgba(255,255,255,.05) !important;
  border:1px solid rgba(255,255,255,.09) !important;
  -webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px);
  color:#d4d4d4 !important;font-weight:700 !important;
  transition:transform .18s cubic-bezier(.34,1.56,.64,1),background .2s,border-color .2s,box-shadow .2s,color .2s !important;
  will-change:transform;
}
.cat-nav-btn:hover{
  background:rgba(255,255,255,.1) !important;border-color:rgba(255,255,255,.2) !important;
  color:#fff !important;transform:translateY(-2px) scale(1.03);
  box-shadow:0 6px 18px rgba(0,0,0,.35);
}
.cat-nav-btn:active{transform:translateY(0) scale(.97)}
.cat-nav-btn.active{
  background:linear-gradient(135deg,#ff2b36,#e50914 55%,#b00610) !important;
  border-color:rgba(255,90,98,.6) !important;color:#fff !important;
  box-shadow:0 6px 20px rgba(229,9,20,.45),0 0 0 1px rgba(255,255,255,.06) inset !important;
  transform:translateY(-1px);
}

/* ═══ (2) ترويسة القسم — أفخم وأوضح ═══ */
.shs-catview-banner{
  gap:16px;padding:20px 22px;border-radius:20px;margin:8px 0 22px;
  animation:shsBannerIn .5s cubic-bezier(.22,1,.36,1) both;
}
@keyframes shsBannerIn{from{opacity:0;transform:translateY(-8px) scale(.98)}to{opacity:1;transform:none}}
.shs-catview-ico{width:54px;height:54px;border-radius:16px;font-size:1.35rem}
.shs-catview-name{font-size:1.5rem;letter-spacing:-.6px}
.shs-catview-chip{font-size:.72rem;padding:4px 12px}

/* زر العودة أنعم داخل عرض القسم */
#categoryViewSection .back-btn{
  transition:transform .18s,background .2s,color .2s;border-radius:99px;
}
#categoryViewSection .back-btn:hover{transform:translateX(3px)}

/* دخول ناعم Slide-up لكامل قسم العرض */
#categoryViewSection:not(.hidden){animation:shsSectionIn .45s cubic-bezier(.22,1,.36,1) both}
@keyframes shsSectionIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}

/* ظهور تدريجي متدرّج لبطاقات الشبكة */
#categoryViewGrid.shs-stagger > .ch-card,
#categoryViewGrid.shs-stagger > .sr-card{animation:shsCardIn .4s ease both}
@keyframes shsCardIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}

/* ═══ (2ب) حالة التحميل — سبنر مزدوج أنيق ═══ */
.shs-spinner{
  width:54px;height:54px;margin:0 auto;position:relative;
}
.shs-spinner::before,.shs-spinner::after{
  content:"";position:absolute;inset:0;border-radius:50%;
  border:3px solid transparent;
}
.shs-spinner::before{border-top-color:#e50914;border-right-color:#e50914;animation:spin2 .8s linear infinite}
.shs-spinner::after{inset:8px;border-bottom-color:rgba(255,90,98,.6);border-left-color:rgba(255,90,98,.6);animation:spin2 1.1s linear infinite reverse}
.shs-loading-txt{margin-top:16px;color:var(--text-muted);font-size:.82rem;font-weight:600}

/* ═══ (2ج) حالة الفراغ — أيقونية جميلة ═══ */
.shs-empty-wrap{display:flex;flex-direction:column;align-items:center;gap:14px;padding:56px 20px}
.shs-empty-ico{
  width:84px;height:84px;border-radius:24px;display:flex;align-items:center;justify-content:center;
  background:radial-gradient(120% 120% at 30% 20%,rgba(229,9,20,.16),rgba(255,255,255,.03));
  border:1px solid rgba(255,255,255,.08);color:#ff6169;font-size:2rem;
  box-shadow:0 10px 30px rgba(0,0,0,.35);animation:shsFloat 3s ease-in-out infinite;
}
@keyframes shsFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-7px)}}
.shs-empty-title{font-size:1.05rem;font-weight:800;color:#e8e8e8}
.shs-empty-sub{font-size:.82rem;color:var(--text-muted);max-width:280px;text-align:center;line-height:1.6}

/* ═══ (3) القائمة الجانبية — زجاج داكن + مسافات + انزلاق + سكرول أنيق ═══ */
.shs-catmenu-panel{
  background:linear-gradient(180deg,rgba(20,22,30,.92),rgba(10,11,16,.94)) !important;
  -webkit-backdrop-filter:blur(26px) saturate(160%);backdrop-filter:blur(26px) saturate(160%);
  border-left:1px solid rgba(255,255,255,.08) !important;
}
.shs-catmenu-list{padding:10px 12px 20px;gap:5px}
.shs-catmenu-item{
  padding:15px 16px;margin:2px 0;
  transition:background .22s,color .22s,transform .2s cubic-bezier(.34,1.56,.64,1) !important;
}
.shs-catmenu-item:hover{transform:translateX(-6px)}
.shs-catmenu-homerow{transition:background .2s,border-color .2s,transform .2s cubic-bezier(.34,1.56,.64,1)}
.shs-catmenu-homerow:hover{transform:translateX(-6px)}

/* شريط تمرير أنيق يظهر عند الحاجة فقط */
.shs-catmenu-panel{scrollbar-width:thin;scrollbar-color:transparent transparent}
.shs-catmenu-panel:hover{scrollbar-color:rgba(255,255,255,.2) transparent}
.shs-catmenu-panel::-webkit-scrollbar{width:8px}
.shs-catmenu-panel::-webkit-scrollbar-track{background:transparent}
.shs-catmenu-panel::-webkit-scrollbar-thumb{
  background:transparent;border-radius:99px;border:2px solid transparent;background-clip:padding-box;
  transition:background .3s;
}
.shs-catmenu-panel:hover::-webkit-scrollbar-thumb{background:rgba(255,255,255,.18);background-clip:padding-box}
.shs-catmenu-panel::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.32);background-clip:padding-box}

/* شارة أيقونة القسم في القائمة الجانبية (بديل شارة الرقم) */
.shs-catmenu-ico{
  order:-2;flex-shrink:0;width:38px;height:38px;border-radius:11px;
  display:flex;align-items:center;justify-content:center;
  background:linear-gradient(180deg,rgba(255,255,255,.07),rgba(255,255,255,.02));
  border:1px solid rgba(255,255,255,.08);color:#cfd4de;font-size:1.05rem;
  transition:background .22s,border-color .22s,color .22s,transform .22s;
}
.shs-catmenu-ico svg{width:20px;height:20px}
.shs-catmenu-item:hover .shs-catmenu-ico{
  background:linear-gradient(180deg,rgba(229,9,20,.22),rgba(229,9,20,.08));
  border-color:rgba(229,9,20,.4);color:#ff8a90;transform:scale(1.06);
}
.shs-catmenu-item.active .shs-catmenu-ico{
  background:linear-gradient(135deg,#ff2b36,#e50914);border-color:rgba(255,90,98,.55);
  color:#fff;box-shadow:0 4px 12px rgba(229,9,20,.35);
}
/* أيقونة بانر القسم أوضح */
.shs-catview-ico svg{width:26px;height:26px}
/* [SHS-POLISH-END] */

/* ════ GRID CONTAINER ════ */
.slider-cards-wrapper {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(clamp(100px, 14vw, 175px), 1fr));
  gap: clamp(8px, 1.5vw, 15px);
  padding: 0 12px;
  overflow: visible;
  /* FIX: direction:ltr للـ grid حتى تمتلئ الكروت من اليسار لليمين */
  direction: ltr;
}
.slider-cards-wrapper .skeleton{height:0;padding-bottom:150%;border-radius:8px}
@media(max-width:1200px){.slider-cards-wrapper{grid-template-columns:repeat(5,1fr)}}
@media(max-width:900px){.slider-cards-wrapper{grid-template-columns:repeat(4,1fr)}}
@media(max-width:768px){.slider-cards-wrapper,.channels-row{grid-template-columns:repeat(3,1fr)!important}}

/* ════ CARDS ════ */
.ch-card,.sr-card{
  position:relative;overflow:hidden;
  background:linear-gradient(145deg,rgba(30,30,30,1) 0%,rgba(18,18,18,1) 100%);
  border:1px solid rgba(255,255,255,.05);
  border-radius:clamp(8px,1.2vw,14px);
  cursor:pointer;width:100%;
  box-shadow:0 4px 15px rgba(0,0,0,.4);
  transition:transform .35s var(--ease-spring),border-color .3s,box-shadow .3s;
  /* FIX: لا will-change دائم — يُفعّل فقط عند hover عبر JS */
}
.ch-card:hover,.sr-card:hover{transform:translateY(-8px) scale(1.05);border-color:var(--red);box-shadow:0 15px 40px rgba(0,0,0,.85),0 0 25px rgba(229,9,20,.4);z-index:10}
.ch-thumb{position:relative;width:100%;aspect-ratio:16/9;background:#111;overflow:hidden;display:flex;align-items:center;justify-content:center}
.sr-poster{position:relative;width:100%;aspect-ratio:2/3;background:#111;overflow:hidden;display:flex;align-items:center;justify-content:center}
.ch-thumb img{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;padding:10%;transition:transform .25s var(--ease-spring)}
.sr-poster img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;padding:0;transition:transform .25s var(--ease-spring)}
.ch-card:hover .ch-thumb img,.sr-card:hover .sr-poster img{transform:scale(1.06)}
.ch-thumb::after,.sr-poster::after{content:'';position:absolute;inset:0;background:transparent;transition:background .2s;z-index:2;pointer-events:none}
.ch-card:hover .ch-thumb::after,.sr-card:hover .sr-poster::after{background:rgba(0,0,0,.3)}
.ch-thumb .ch-icon,.sr-poster .sr-icon{font-size:1.8rem;color:#2e2e2e;position:relative;z-index:1;transition:color .2s,transform .22s}
.ch-card:hover .ch-thumb .ch-icon,.sr-card:hover .sr-poster .sr-icon{color:#ff4d57;transform:scale(1.12)}
.ch-play-btn{position:absolute;z-index:4;top:50%;left:50%;transform:translate(-50%,-50%);width:36px;height:36px;border-radius:50%;background:rgba(229,9,20,.9);color:#fff;font-size:.85rem;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s;pointer-events:none}
.ch-card:hover .ch-play-btn{opacity:1}
.ch-live-badge{position:absolute;top:5px;right:5px;z-index:5;background:#e50914;color:#fff;font-size:.55rem;font-weight:800;padding:2px 6px;border-radius:3px;animation:glowPulse 3s ease-in-out infinite}
.ch-fmt-badge{position:absolute;top:5px;left:5px;z-index:5;background:rgba(0,0,0,.7);color:#999;font-size:.52rem;font-weight:800;padding:1px 4px;border-radius:3px;text-transform:uppercase;border:1px solid rgba(255,255,255,.1)}
.ch-quality-badge{position:absolute;bottom:5px;right:5px;z-index:5;background:rgba(0,0,0,.72);color:#fff;font-size:.52rem;font-weight:800;padding:1px 5px;border-radius:3px;border:1px solid rgba(255,255,255,.15)}
.ch-info,.sr-info{padding:6px 7px 8px;background:#161616;direction:rtl}
.ch-name,.sr-name{font-size:clamp(0.68rem,2.2vw,0.85rem);font-weight:700;color:#eeeeee;line-height:1.4;height:2.8em;margin-bottom:6px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;text-overflow:ellipsis;white-space:normal;transition:color .2s var(--ease-spring)}
.ch-card:hover .ch-name,.sr-card:hover .sr-name{color:#fff}
.ch-meta,.sr-meta{font-size:.62rem;color:var(--text-muted);display:flex;align-items:center;gap:3px}
@media(max-width:600px){.ch-info,.sr-info{padding:5px 6px 6px}}

/* ════ INFO ACTION BUTTONS ════ */
.info-action-btn{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);color:#bbb;width:25px;height:25px;border-radius:5px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .18s;flex-shrink:0;font-size:.68rem}
.info-action-btn:hover{background:var(--red);color:#fff;border-color:var(--red);transform:scale(1.08)}
.info-action-btn.active-fav{color:var(--red)}

/* ════ EPISODES GRID ════ */
.channels-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(clamp(100px,14vw,175px),1fr));gap:clamp(8px,1.5vw,14px);direction:ltr}
.episodes-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(clamp(145px,20vw,240px),1fr));gap:clamp(10px,2vw,18px);direction:ltr}/* episodes responsive في كتلة الـ responsive الموحّدة */
.ep-card{display:flex;flex-direction:column;background:#181818;border:1px solid rgba(255,255,255,.08);border-radius:var(--radius);overflow:hidden;cursor:pointer;transition:var(--transition)}
.ep-card:hover{transform:translateY(-8px) scale(1.04);border-color:var(--red);box-shadow:0 15px 35px rgba(0,0,0,.7),0 0 20px rgba(229,9,20,.35);z-index:5}
.ep-thumb-area{position:relative;width:100%;aspect-ratio:16/9;background:linear-gradient(135deg,#252525,#1a1a1a);display:flex;align-items:center;justify-content:center;overflow:hidden}
.ep-thumb-video{width:100%;height:100%;object-fit:cover;position:absolute;inset:0;z-index:0;pointer-events:none}
/* [SHS-EPPOSTER] عند استخدام بوستر المسلسل كخلفية: تعتيم خفيف ليبرز زر التشغيل */
.ep-thumb-fallback{filter:brightness(.62) saturate(1.05)}
.ep-thumb-area:has(.ep-thumb-fallback)::before{content:"";position:absolute;inset:0;z-index:1;background:linear-gradient(180deg,rgba(0,0,0,.15),rgba(0,0,0,.55));pointer-events:none}
.ep-card:hover .ep-thumb-fallback{filter:brightness(.78) saturate(1.1)}
.ep-thumb-icon{font-size:2.4rem;color:rgba(255,255,255,.6);transition:.3s;z-index:2;position:relative}
.ep-card:hover .ep-thumb-icon{color:var(--red);transform:scale(1.15)}
.ep-num-badge{position:absolute;top:8px;right:8px;background:var(--red);color:#fff;padding:2px 9px;border-radius:4px;font-size:.72rem;font-weight:bold;z-index:3}
.ep-info-box{padding:10px;text-align:center;border-top:1px solid rgba(255,255,255,.05);background:#151515}
.ep-date-text{font-size:.82rem;color:var(--text-muted);display:flex;align-items:center;justify-content:center;gap:6px;font-weight:600}

/* ════ SEARCH GRID ════ */
.channels-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(clamp(100px,14vw,175px),1fr));gap:clamp(8px,1.5vw,14px)}
@media(max-width:1024px){.channels-row{grid-template-columns:repeat(5,1fr)}}

/* ════ BACK BUTTON ════ */
.back-btn{display:inline-flex;align-items:center;gap:10px;padding:9px 20px;background:rgba(255,255,255,.07);border:1.5px solid rgba(255,255,255,.14);border-radius:99px;color:var(--text);margin-bottom:24px;cursor:pointer;font-weight:700;font-size:.9rem;transition:var(--transition)}
.back-btn:hover{background:rgba(229,9,20,.12);border-color:rgba(229,9,20,.5);color:#ff4d57}

/* ════ PANELS ════ */
.fp-panel,.np-panel,.m3u-panel,.ep-panel{position:fixed;top:0;height:100%;z-index:9996;width:min(100vw,420px);background:var(--surface);backdrop-filter:blur(30px) saturate(1.5);display:flex;flex-direction:column;transition:all .45s var(--ease-spring);box-shadow:0 0 50px rgba(0,0,0,.85)}
.fp-panel{right:-420px;border-left:1px solid rgba(255,255,255,.1)}.fp-panel.open{right:0}
.np-panel{right:-420px;border-left:1px solid rgba(255,255,255,.1)}.np-panel.open{right:0}
.m3u-panel{right:-420px;border-left:1px solid rgba(255,255,255,.1)}.m3u-panel.open{right:0}
.ep-panel{left:-420px;border-right:1px solid rgba(255,255,255,.1)}.ep-panel.open{left:0}
.panel-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9995;display:none;backdrop-filter:blur(2px)}.panel-overlay.show{display:block}
/* panels responsive في كتلة الـ responsive الموحّدة */
.ep-panel-head{padding:22px 20px;background:linear-gradient(180deg,rgba(229,9,20,.08),transparent);border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.ep-panel-close{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.07);color:#fff;border:1px solid rgba(255,255,255,.1);cursor:pointer;transition:background .2s;display:flex;align-items:center;justify-content:center}
.ep-panel-close:hover{background:rgba(229,9,20,.3)}
.m3u-item{display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:10px;cursor:pointer;margin-bottom:5px;border:1px solid rgba(255,255,255,.05);background:rgba(255,255,255,.03);transition:all .2s}
.m3u-item:hover{background:rgba(229,9,20,.1);border-color:rgba(229,9,20,.35)}
.m3u-item.playing{background:rgba(229,9,20,.15);border-color:rgba(229,9,20,.5)}
.m3u-item-logo{width:36px;height:36px;border-radius:8px;object-fit:contain;background:rgba(255,255,255,.07);flex-shrink:0}
.m3u-item-name{font-size:.86rem;font-weight:700;color:#f0f0f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.m3u-item-group{font-size:.7rem;color:#666}
.ep-item{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:10px;cursor:pointer;margin-bottom:6px;border:1.5px solid rgba(255,255,255,.05);background:rgba(255,255,255,.03);transition:all .22s}
.ep-item:hover{background:rgba(229,9,20,.1);border-color:rgba(229,9,20,.35)}
.ep-item.playing{background:rgba(229,9,20,.15);border-color:rgba(229,9,20,.5)}
.ep-item-num{width:36px;height:36px;border-radius:50%;background:rgba(229,9,20,.12);border:1.5px solid rgba(229,9,20,.3);display:flex;align-items:center;justify-content:center;font-size:.76rem;font-weight:900;color:#ff4d57;flex-shrink:0}
.ep-item.playing .ep-item-num{background:var(--red);color:#fff}
.ep-item-info{flex:1;min-width:0}
.ep-item-title{font-size:.87rem;font-weight:700;color:#f0f0f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px}
.ep-item-play{width:28px;height:28px;border-radius:50%;background:rgba(229,9,20,.15);border:1px solid rgba(229,9,20,.3);color:#ff4d57;display:flex;align-items:center;justify-content:center;font-size:.7rem;flex-shrink:0;opacity:0;transition:.2s}
.ep-item:hover .ep-item-play,.ep-item.playing .ep-item-play{opacity:1}

/* ════ TOAST ════ */
.toasts{position:fixed;bottom:24px;left:24px;z-index:99999;display:flex;flex-direction:column;gap:10px;direction:rtl}
.toast{background:rgba(24,24,24,.97);color:var(--text);border:1px solid rgba(255,255,255,.1);border-right:3px solid var(--red);padding:12px 18px;border-radius:var(--radius);font-size:.86rem;font-weight:600;box-shadow:var(--shadow);display:flex;align-items:center;gap:10px;animation:toast-in .35s var(--ease-spring)}
.toast.out{animation:toast-out .28s forwards}

/* ════ TMDB MODAL ════ */
.tmdb-modal-overlay{position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.85);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;padding:20px}
.tmdb-modal-overlay.open{display:flex}
.tmdb-modal-box{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-lg);width:100%;max-width:600px;max-height:90vh;display:flex;flex-direction:column;box-shadow:var(--shadow);animation:cardIn .3s var(--ease-out)}
.tmdb-modal-head{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.tmdb-modal-close{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:#ccc;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:.2s}
.tmdb-modal-close:hover{background:var(--red);color:#fff;border-color:var(--red)}
.tmdb-modal-body{padding:22px;overflow-y:auto}
.tmdb-info-wrap{display:flex;gap:18px;flex-wrap:wrap;direction:rtl;text-align:right}
.tmdb-info-poster{width:140px;border-radius:var(--radius);flex-shrink:0;border:1px solid var(--border);object-fit:cover;background:var(--bg3)}
.tmdb-info-details{flex:1;min-width:200px}
.tmdb-info-title{font-size:1.3rem;font-weight:800;color:#fff;margin-bottom:8px;line-height:1.2}
.tmdb-info-meta{font-size:.85rem;color:var(--text-muted);margin-bottom:14px;display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.tmdb-genre-badge{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);padding:3px 10px;border-radius:99px;font-size:.75rem;font-weight:600;color:#ccc}
.tmdb-info-overview{font-size:.9rem;color:#ddd;line-height:1.7;background:rgba(0,0,0,.3);padding:14px;border-radius:var(--radius);border:1px solid rgba(255,255,255,.05)}

/* ══════════════════════════════════════════
   FIX CRITICAL: عزل شامل للمشغل
   منع أي filter أو transform أو opacity
   من الصفحة الخارجية أن تؤثر على الفيديو
══════════════════════════════════════════ */
#playerOverlay{
  /* عزل المشغل تماماً عن أي stacking context خارجي */
  isolation:isolate;
  /* لا transform على الـ overlay نفسه */
  transform:none !important;
}
/* FIX: الأزرار الشفافة لا تخلق compositing layer */
#playerOverlay .p-back:hover{background:rgba(229,9,20,.85);transform:scale(1.06)}
#playerOverlay .p-btn:hover{background:rgba(229,9,20,.85);transform:scale(1.06)}
#playerOverlay .p-seek-btn:hover{background:rgba(229,9,20,.8);transform:scale(1.08)}
#playerOverlay .p-play-btn:hover{background:rgba(229,9,20,1);transform:scale(1.1)}
#playerOverlay .p-icon-btn:hover{background:rgba(229,9,20,.85);transform:scale(1.06)}
/* FIX: p-vol-track مُعرَّف مرة واحدة فقط */


.cat-card:focus,.ch-card:focus,.sr-card:focus,.ep-card:focus{outline:none!important;transform:translateY(-8px) scale(1.05)!important;border-color:rgba(229,9,20,.8)!important;box-shadow:0 22px 55px rgba(229,9,20,.5),0 0 0 4px #fff!important;z-index:10}

/* ════ PLAYER ════ */
.player-overlay{
  position:fixed;inset:0;z-index:9990;background:#000;
  display:none;flex-direction:column;
  width:100vw;height:100vh;height:100dvh;overflow:hidden;
  /* FIX: لا contain:strict — يمنع requestFullscreen() في Chrome/Firefox */
}
#playerOverlay:focus,#pvWrap:focus,video#html5Player:focus{outline:none}
.player-overlay.p-native-fs{position:fixed!important;inset:0!important;width:100%!important;height:100%!important;z-index:2147483647!important;margin:0!important;padding:0!important;display:flex!important}
#playerOverlay:fullscreen,#playerOverlay:-webkit-full-screen,#playerOverlay:-moz-full-screen{position:fixed!important;inset:0!important;width:100vw!important;height:100vh!important;z-index:2147483647!important;display:flex!important;flex-direction:column!important;background:#000!important}
#playerOverlay:fullscreen .pv-wrap,#playerOverlay:-webkit-full-screen .pv-wrap{position:absolute!important;inset:0!important;width:100%!important;height:100%!important}
#playerOverlay:fullscreen video,#playerOverlay:-webkit-full-screen video{width:100%!important;height:100%!important;object-fit:contain!important}
/* FIX: لا animation بـ scale على المشغل — يُفسد الجودة */
.player-overlay.active{display:flex;animation:playerSlideIn .25s ease}
.player-overlay.idle *{cursor:none!important}

/* ══ VIDEO LAYER — نقي بدون أي تأثير ══ */
.pv-wrap{
  position:absolute;inset:0;
  display:flex;align-items:center;justify-content:center;
  background:#000;
  /* FIX: isolation يمنع أي stacking context خارجي من التأثير على الفيديو */
  isolation:isolate;
}
video#html5Player{
  width:100%;
  height:100%;
  /* الجودة الأصلية الكاملة — contain يحافظ على النسبة */
  object-fit:contain;
  /* لا أي تأثير CSS يلمس الفيديو */
  transform:none !important;
  filter:none;
  opacity:1 !important;
  will-change:auto;
  /* أعلى جودة rendering ممكنة */
  image-rendering:auto; /* تم تعديله لـ auto لتجنب تهنيج شاشات TCL */
}
/* تحسينات الجودة فقط عند الطلب الصريح */
video#html5Player.enh-deblock{filter:url(#enh-deblock) !important}
video#html5Player.enh-hdr{filter:url(#enh-hdr) !important}
video#html5Player.enh-frame{filter:url(#enh-frame) !important}
video#html5Player.enh-full{filter:url(#enh-full) !important}

.p-buffer{position:absolute;inset:0;display:none;align-items:center;justify-content:center;pointer-events:none;z-index:15}
.p-buffer.show{display:flex}
.p-buffer-ring{width:56px;height:56px;border:4px solid rgba(255,255,255,.12);border-top-color:#e50914;border-radius:50%;animation:spin2 .8s linear infinite}
.p-flash{position:absolute;inset:0;pointer-events:none;display:flex;align-items:center;justify-content:center;z-index:20}
.p-flash-icon{width:74px;height:74px;border-radius:50%;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;font-size:1.9rem;color:#fff;opacity:0;transition:opacity .28s;pointer-events:none}
.p-flash-icon.show{opacity:1}
/* FIX: vignette خفيف جداً — يظهر فقط عند ظهور الكنترولز */
.p-vignette-top{position:absolute;top:0;left:0;right:0;height:30%;background:linear-gradient(180deg,rgba(0,0,0,.55) 0%,rgba(0,0,0,.2) 60%,transparent 100%);pointer-events:none;z-index:10;opacity:0;transition:opacity .3s}
.p-vignette-bot{position:absolute;bottom:0;left:0;right:0;height:35%;background:linear-gradient(0deg,rgba(0,0,0,.65) 0%,rgba(0,0,0,.25) 60%,transparent 100%);pointer-events:none;z-index:10;opacity:0;transition:opacity .3s}
/* يظهر الـ vignette فقط عند ظهور الكنترولز */
.player-overlay:not(.idle) .p-vignette-top,
.player-overlay:not(.idle) .p-vignette-bot{opacity:1}

/* ── TOP ── */
.p-top{position:absolute;top:0;left:0;right:0;z-index:30;padding:max(24px,env(safe-area-inset-top)) 24px 0;display:flex;align-items:center;gap:18px;transition:opacity .3s cubic-bezier(0.25,1,0.4,1);direction:rtl}
.p-top.hide{opacity:0;pointer-events:none}
/* FIX: حذف backdrop-filter من أزرار المشغل — كانت تخلق stacking context يُدمر جودة الفيديو */
.p-back{width:48px;height:48px;border-radius:50%;background:rgba(0,0,0,.5);border:1px solid rgba(255,255,255,.2);color:#fff;font-size:1.15rem;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .2s,transform .2s;box-shadow:0 4px 15px rgba(0,0,0,.5)}
.p-back:hover{background:rgba(229,9,20,.8);border-color:var(--red);transform:scale(1.08)}
.p-title-block{flex:1;min-width:0;position:relative}
.p-channel-name{font-size:1.2rem;font-weight:900;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-shadow:0 2px 10px rgba(0,0,0,1)}
.p-title-sub{display:flex;align-items:center;gap:8px;margin-top:5px;flex-wrap:wrap}
.p-live-badge{background:var(--red);color:#fff;padding:3px 10px;border-radius:4px;font-size:.65rem;font-weight:900;letter-spacing:1px;box-shadow:0 0 10px rgba(229,9,20,.6);animation:glowPulse 2s infinite alternate;white-space:nowrap}
.p-fmt-tag{font-size:.65rem;font-weight:800;color:#eee;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);padding:2px 8px;border-radius:4px;text-transform:uppercase;white-space:nowrap}
.p-top-right{display:flex;align-items:center;gap:10px;flex-shrink:0}
.p-ep-nav{display:flex;align-items:center;gap:12px}
.p-ep-label{font-size:.9rem;font-weight:800;color:#fff;white-space:nowrap;text-shadow:0 2px 8px rgba(0,0,0,.8);max-width:180px;overflow:hidden;text-overflow:ellipsis}
.p-icon-btn{width:44px;height:44px;border-radius:50%;background:rgba(0,0,0,.5);border:1px solid rgba(255,255,255,.2);color:#fff;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .2s,transform .2s}
.p-icon-btn:hover{background:rgba(229,9,20,.8);transform:scale(1.08)}
.p-icon-btn:disabled{opacity:0.4;cursor:not-allowed;transform:none;background:rgba(255,255,255,.05)}

/* ── CENTER ── */
.p-center{position:absolute;inset:0;z-index:25;display:flex;align-items:center;justify-content:center;gap:45px;pointer-events:none;transition:opacity .3s cubic-bezier(0.25,1,0.4,1)}
.p-center.hide{opacity:0;pointer-events:none}
.p-seek-btn{width:76px;height:76px;max-width:80px;max-height:80px;border-radius:50%;background:rgba(0,0,0,.55);border:2px solid rgba(255,255,255,.15);color:#fff;font-size:1.8rem;cursor:pointer;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;pointer-events:auto;transition:background .2s,transform .2s;box-shadow:0 10px 30px rgba(0,0,0,.5);box-sizing:border-box;flex:0 0 auto}
.p-seek-btn:hover,.p-seek-btn:active{background:rgba(229,9,20,.8);border-color:var(--red);transform:scale(1.12)}
.p-seek-n{font-size:.65rem;font-weight:900;color:rgba(255,255,255,.9);line-height:1}
.p-play-btn{width:96px;height:96px;max-width:104px;max-height:104px;border-radius:50%;background:rgba(229,9,20,.85);border:3px solid rgba(255,255,255,.2);color:#fff;font-size:2.8rem;cursor:pointer;display:flex;align-items:center;justify-content:center;pointer-events:auto;transition:background .25s,transform .25s;box-shadow:0 15px 40px rgba(229,9,20,.4);box-sizing:border-box;flex:0 0 auto}
.p-play-btn:hover,.p-play-btn:active{background:rgba(229,9,20,1);border-color:#fff;transform:scale(1.15) translateY(-5px);box-shadow:0 20px 50px rgba(229,9,20,.6)}

/* ── BOTTOM ── */
.p-bottom{position:absolute;bottom:0;left:0;right:0;z-index:30;padding:40px 24px max(24px,env(safe-area-inset-bottom));transition:opacity .3s cubic-bezier(0.25,1,0.4,1)}
.p-bottom.hide{opacity:0;pointer-events:none}
.p-prog-row{display:flex;align-items:center;gap:16px;margin-bottom:18px;direction:ltr}
.p-tc{font-size:.85rem;font-weight:800;color:rgba(255,255,255,.9);font-family:monospace;white-space:nowrap;min-width:44px;text-align:center;text-shadow:0 1px 5px rgba(0,0,0,.8)}
.p-prog-wrap{flex:1;padding:12px 0;cursor:pointer}
.p-prog-track{position:relative;height:6px;background:rgba(255,255,255,.25);border-radius:10px;transition:height .2s}
.p-prog-wrap:hover .p-prog-track{height:10px}
.p-prog-fill{position:absolute;left:0;top:0;height:100%;background:var(--red);border-radius:10px;width:0;transition:width .2s linear}
.p-prog-dot{position:absolute;right:-9px;top:50%;transform:translateY(-50%);width:18px;height:18px;background:#fff;border-radius:50%;box-shadow:0 0 8px rgba(0,0,0,.6);opacity:0;transition:opacity .2s}
.p-prog-wrap:hover .p-prog-dot{opacity:1}
/* شريط الأدوات — RTL للعربية، اليمين = ترجمة+تحسين، اليسار = صوت+fullscreen */
.p-tools{display:flex;align-items:center;justify-content:space-between;gap:12px;direction:rtl}
.p-tools-l,.p-tools-r{display:flex;align-items:center;gap:10px}
.p-btn{width:48px;height:48px;border-radius:12px;background:rgba(0,0,0,.5);border:1px solid rgba(255,255,255,.15);color:#fff;font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .2s,transform .2s;flex-shrink:0}
.p-btn:hover{background:rgba(229,9,20,.8);border-color:var(--red);transform:scale(1.1)}
.p-btn.active-magic{color:#ff4d57;background:rgba(229,9,20,.2);border-color:var(--red);box-shadow:0 0 15px rgba(229,9,20,.5)}
.p-enh{flex-direction:column;gap:3px;font-size:1.05rem;width:58px;height:48px}
.p-enh-lbl{font-size:.5rem;font-weight:900;color:rgba(255,255,255,.8);line-height:1}
.p-vol-wrap{display:flex;align-items:center;gap:0;position:relative;direction:ltr}
.p-vol-wrap:hover .p-vol-slider-wrap{width:100px;opacity:1;margin-right:8px}
.p-vol-icon{background:none!important;border:none!important;box-shadow:none!important}
.p-vol-slider-wrap{width:0;opacity:0;overflow:hidden;transition:all .3s cubic-bezier(0.25,1,0.4,1);display:flex;align-items:center}
.p-vol-track{position:relative;width:100%;height:6px;background:rgba(255,255,255,.2);border-radius:10px;cursor:pointer;transition:height .2s}
.p-vol-track:hover{height:8px}
.p-vol-fill{height:100%;background:#fff;border-radius:10px;width:100%;pointer-events:none}
.p-vol-thumb{position:absolute;right:0;top:50%;transform:translate(50%,-50%);width:16px;height:16px;background:#fff;border-radius:50%;box-shadow:0 2px 5px rgba(0,0,0,.6);pointer-events:none;opacity:0;transition:opacity .2s}
.p-vol-track:hover .p-vol-thumb{opacity:1}
.p-time-txt{font-size:.9rem;font-weight:800;color:rgba(255,255,255,.9);font-family:monospace;white-space:nowrap;padding:0 8px}
@media(max-width:1024px){.p-vol-slider-wrap{width:80px!important;opacity:1!important;margin-right:6px!important}.p-back{width:44px;height:44px}.p-icon-btn{width:40px;height:40px}.p-btn{width:44px;height:44px}}
/* player responsive في كتلة الـ responsive الموحّدة */

/* ════ SCREENSAVER ════ */
#nxScreensaver{position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:99998;background:#000;opacity:0;pointer-events:none;visibility:hidden;transition:opacity 1s,visibility 1s;overflow:hidden;font-family:'Cairo',sans-serif}
#nxScreensaver.nx-active{opacity:1;pointer-events:auto;visibility:visible}
.nx-bg{position:absolute;inset:-15%;background-size:cover;background-position:center 20%;filter:blur(55px) saturate(1.5) brightness(.25);z-index:1;animation:nxKenBurns 20s infinite alternate linear}
.nx-vignette{position:absolute;inset:0;z-index:2;background:radial-gradient(circle at center,transparent 30%,rgba(0,0,0,.85) 100%),linear-gradient(0deg,#000 0%,rgba(0,0,0,0) 40%)}
.nx-container{position:absolute;inset:0;z-index:3;display:flex;align-items:center;justify-content:flex-start;padding:0 8vw;gap:5vw;direction:rtl;opacity:1;transition:opacity .8s}
.nx-container.nx-faded{opacity:0}
.nx-poster{width:clamp(240px,22vw,360px);aspect-ratio:2/3;border-radius:12px;object-fit:cover;box-shadow:0 30px 80px rgba(0,0,0,.9),0 0 0 1px rgba(255,255,255,.08);animation:nxFloat 6s ease-in-out infinite alternate}
.nx-info-box{display:flex;flex-direction:column;max-width:700px;align-items:flex-start;text-align:right}
.nx-top-badge{display:inline-flex;align-items:center;gap:8px;margin-bottom:18px;background:#E50914;color:#fff;font-size:.88rem;font-weight:800;padding:4px 14px;border-radius:4px;letter-spacing:.5px}
.nx-title{font-size:clamp(2.5rem,5vw,4.5rem);font-weight:900;color:#fff;line-height:1.1;margin-bottom:14px;text-shadow:2px 4px 15px rgba(0,0,0,.8);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.nx-meta-row{display:flex;gap:12px;align-items:center;color:#a3a3a3;font-size:1rem;font-weight:700;margin-bottom:22px;flex-wrap:wrap}
.nx-match{color:#46d369;font-weight:900}
.nx-tag{border:1px solid rgba(255,255,255,.3);padding:1px 8px;border-radius:4px;font-size:.88rem;font-family:monospace;color:#ddd}
.nx-footer{position:absolute;bottom:50px;left:0;right:0;text-align:center;z-index:3;font-size:.9rem;color:rgba(255,255,255,.4);display:flex;justify-content:center;align-items:center;gap:10px}
.nx-bounce-arrow{animation:nxBounce 2s infinite ease-in-out;display:inline-block}
/* screensaver responsive في كتلة الـ responsive الموحّدة */

/* ════ MUSIC PLAYER ════ */
.music-mini-btn{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.08);border:1.5px solid rgba(255,255,255,.1);color:#ccc;display:flex;align-items:center;justify-content:center;font-size:.9rem;transition:var(--transition);position:relative;cursor:pointer}
.music-mini-btn:hover{background:rgba(156,39,176,.2);border-color:rgba(156,39,176,.5);color:#e040fb;transform:scale(1.08)}
.music-mini-btn.playing{background:rgba(156,39,176,.2);border-color:rgba(156,39,176,.5);color:#e040fb}
.music-mini-btn .music-eq{display:flex;align-items:flex-end;gap:2px;height:14px}
.music-mini-btn .music-eq-bar{width:3px;background:currentColor;border-radius:2px;animation:musicBarAnim 0.5s ease infinite alternate}
.music-mini-btn .music-eq-bar:nth-child(1){height:40%;animation-delay:0s}
.music-mini-btn .music-eq-bar:nth-child(2){height:70%;animation-delay:0.15s}
.music-mini-btn .music-eq-bar:nth-child(3){height:50%;animation-delay:0.3s}
.music-mini-btn .music-eq.paused .music-eq-bar{animation:none;height:30%!important}
/* music responsive في كتلة الـ responsive الموحّدة */

/* ════ INIT LOADER ════ */
#nxInitLoader{position:fixed;inset:0;z-index:9999999;background:#000;display:flex;align-items:center;justify-content:center;transition:opacity 0.4s var(--ease-out),visibility 0.4s}
#nxInitLoader.loaded{opacity:0;visibility:hidden;pointer-events:none}
.nx-loader-circle{width:70px;height:70px;border:4px solid rgba(229,9,20,.12);border-top-color:var(--red);border-radius:50%;animation:spin2 0.8s linear infinite}

/* ════ DEVICE CAPABILITY BADGES ════ */
#deviceBadgesWrap{
  position:absolute;top:90px;right:20px;z-index:40;
  display:flex;flex-direction:column;gap:7px;
  pointer-events:none;direction:rtl;
}
.dev-badge{
  display:inline-flex;align-items:center;gap:7px;
  background:rgba(0,0,0,.78);
  border:1px solid rgba(255,255,255,.15);
  border-radius:6px;
  padding:5px 12px;
  font-size:.72rem;font-weight:800;
  color:#fff;
  opacity:0;
  transform:translateX(20px);
  transition:opacity .4s ease, transform .4s ease;
  white-space:nowrap;
}
.dev-badge.visible{opacity:1;transform:translateX(0)}
.dev-badge .db-icon{font-size:.85rem;flex-shrink:0}
/* ألوان حسب النوع */
.dev-badge.audio-dolby{border-color:rgba(0,163,255,.5);color:#7dd4fc}
.dev-badge.audio-dts{border-color:rgba(255,136,0,.5);color:#fdba74}
.dev-badge.audio-std{border-color:rgba(255,255,255,.2);color:#d1d5db}
.dev-badge.video-hdr{border-color:rgba(255,204,0,.5);color:#fde047}
.dev-badge.video-4k{border-color:rgba(168,85,247,.5);color:#d8b4fe}
.dev-badge.video-std{border-color:rgba(255,255,255,.2);color:#d1d5db}
.dev-badge.display-hz{border-color:rgba(52,211,153,.5);color:#6ee7b7}

video#html5Player::cue{
  background:rgba(0,0,0,.78);
  color:#fff;
  font-family:'Cairo',sans-serif;
  font-size:1.05em;
  font-weight:600;
  line-height:1.5;
  text-shadow:0 1px 3px rgba(0,0,0,.9);
  border-radius:4px;
  padding:2px 6px;
}
video#html5Player::cue(b){font-weight:900}
video#html5Player::cue(i){font-style:italic;color:#ffe066}
#subBtn.sub-active{color:#ff4d57;background:rgba(229,9,20,.2);border-color:var(--red)}

/* ════ RESPONSIVE — كتلة موحّدة ════ */
@media(max-width:768px){
  /* grid */
  .slider-cards-wrapper,.channels-row{grid-template-columns:repeat(3,1fr)!important}
  .channels-row{gap:8px}
  .episodes-grid{grid-template-columns:repeat(2,1fr);gap:10px}
  /* panels */
  .fp-panel,.np-panel,.m3u-panel{width:100%;right:-100%}
  .ep-panel{width:100%;left:-100%}
  /* player */
  .p-seek-btn{width:64px;height:64px;font-size:1.5rem}
  .p-play-btn{width:82px;height:82px;font-size:2.4rem}
  .p-center{gap:30px}
  .p-btn{width:44px;height:44px;font-size:1.1rem}
  .p-top{padding:max(16px,env(safe-area-inset-top)) 16px 0;gap:12px;flex-wrap:wrap}
  .p-back{order:1}.p-top-right{order:2;margin-right:auto}
  .p-title-block{order:3;width:100%;flex:none;margin-top:2px;padding-right:6px}
  .p-channel-name{font-size:1.1rem;white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
  .p-title-sub{position:relative;top:auto;right:auto;margin-top:6px;width:100%}
  .p-bottom{padding:24px 16px max(16px,env(safe-area-inset-bottom))}
  /* screensaver */
  .nx-container{flex-direction:column-reverse;justify-content:center;padding:0 5vw;gap:18px}
  .nx-info-box{align-items:center!important;text-align:center!important}
  .nx-poster{width:clamp(130px,40vw,220px)!important}
  .nx-title{font-size:clamp(1.7rem,7vw,2.3rem)!important}
  .nx-footer{bottom:max(28px,env(safe-area-inset-bottom))!important}
}
@media(max-width:480px){
  /* nav */
  .nav-logo-text{display:none}
  .nav-btn{width:36px;height:36px;font-size:.82rem}
  .music-mini-btn{width:36px;height:36px;font-size:.82rem}
  /* category quick nav */
  .cat-navbar{padding:8px 12px;gap:6px}
  .cat-nav-btn{padding:6px 13px;font-size:.76rem}
  /* player */
  .p-seek-btn{width:56px;height:56px;font-size:1.3rem}
  .p-play-btn{width:72px;height:72px;font-size:2rem}
  .p-center{gap:20px}
  .p-btn{width:40px;height:40px;font-size:1rem;border-radius:10px}
  .p-enh{width:48px;height:40px}
  .p-time-txt{display:none}
  .p-icon-btn,.p-back{width:36px;height:36px;font-size:.9rem}
  .p-top-right{gap:6px}
  .p-ep-nav{gap:6px}
}

/* ════ TV FIX — إصلاح تضخم أزرار المشغل على التلفاز فقط ════
   متصفحات التلفاز (شاشة عريضة جداً + كثافة بكسل منخفضة) تُظهر أزرار
   التحكم عملاقة. نثبّت أحجاماً معقولة لها دون المساس بالموبايل/الأندرويد. */
@media screen and (min-width:1280px){
  /* أزرار التقديم/التأخير والإيقاف في وسط الفيديو */
  #playerOverlay .p-center{gap:60px}
  #playerOverlay .p-seek-btn{width:72px!important;height:72px!important;font-size:1.7rem!important}
  #playerOverlay .p-play-btn{width:92px!important;height:92px!important;font-size:2.6rem!important}
  #playerOverlay .p-seek-n{font-size:.62rem!important}
  /* منع أي تكبير عند hover/active يجعلها تقفز على التلفاز */
  #playerOverlay .p-seek-btn:hover,#playerOverlay .p-seek-btn:active{transform:none!important}
  #playerOverlay .p-play-btn:hover,#playerOverlay .p-play-btn:active{transform:none!important}
}
/* أجهزة التلفاز عبر pointer الخشن + شاشة كبيرة (تأكيد إضافي) */
@media screen and (min-width:1280px) and (pointer:coarse){
  #playerOverlay .p-seek-btn{width:68px!important;height:68px!important}
  #playerOverlay .p-play-btn{width:88px!important;height:88px!important}
}
/* شاشات 4K/التلفاز فائق العرض — نحافظ على نفس الحجم النسبي المعقول */
@media screen and (min-width:1920px){
  #playerOverlay .p-seek-btn{width:80px!important;height:80px!important;font-size:1.9rem!important}
  #playerOverlay .p-play-btn{width:104px!important;height:104px!important;font-size:3rem!important}
  #playerOverlay .p-center{gap:72px}
}

/* ════ LUCIDE COMPAT ════ */
.lcn{display:inline-flex;align-items:center;justify-content:center;vertical-align:middle;line-height:1}
.lcn svg{width:1em;height:1em;stroke:currentColor;fill:none}

/* ════ PERF BOOST — تحسينات أداء إضافية ════ */
/* تمرير أنعم على كامل الصفحة */
html{scroll-behavior:smooth}
@media (prefers-reduced-motion: reduce){html{scroll-behavior:auto}}
/* عزل رسم كل صف لتقليل إعادة التخطيط عند التمرير */
.netflix-slider-row{contain:layout style}
/* الصور: انتقال ظهور لطيف + تسريع فك الترميز */
img{content-visibility:auto}
img.perf-img{opacity:0;transition:opacity .35s ease}
img.perf-img.perf-loaded{opacity:1}
/* تسريع اللمس وإزالة تأخير 300ms على الجوال */
a,button,.netflix-card,.nx-card,[onclick]{touch-action:manipulation}
/* تلميح للمتصفح بأن العناصر القابلة للتمرير ستتحرك */
.netflix-slider,.nx-row,.ep-list{will-change:scroll-position}

/* ══════════════════════════════════════════════════════════════════
   ✨ GLASS THEME — تحسين تأثير الزجاج (Blur أعمق + إضاءة داخلية)
   إضافة فقط — لا يحذف أي شيء. يعزّز وضوح ونعومة الأسطح الزجاجية.
   ══════════════════════════════════════════════════════════════════ */
@supports ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){

  /* شريط التنقّل العلوي — زجاج أعمق وأنقى */
  .navbar{
    background:linear-gradient(180deg,rgba(14,14,16,.62),rgba(10,10,12,.5)) !important;
    -webkit-backdrop-filter:blur(30px) saturate(200%) contrast(105%) !important;
    backdrop-filter:blur(30px) saturate(200%) contrast(105%) !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.06) !important;
  }
  .navbar.scrolled{
    background:linear-gradient(180deg,rgba(12,12,14,.78),rgba(9,9,11,.66)) !important;
    box-shadow:0 8px 30px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.07) !important;
  }

  /* شريط الأقسام الأفقي — زجاج غنيّ */
  .cat-navbar{
    -webkit-backdrop-filter:blur(28px) saturate(190%) contrast(104%) !important;
    backdrop-filter:blur(28px) saturate(190%) contrast(104%) !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.05) !important;
  }
  .cat-nav-btn{
    -webkit-backdrop-filter:blur(14px) saturate(140%) !important;
    backdrop-filter:blur(14px) saturate(140%) !important;
  }

  /* اللوحات الجانبية (فلاتر/إشعارات/M3U/حلقات) — زجاج فاخر */
  .fp-panel,.np-panel,.m3u-panel,.ep-panel{
    background:linear-gradient(180deg,rgba(26,26,30,.82),rgba(16,16,20,.86)) !important;
    -webkit-backdrop-filter:blur(38px) saturate(180%) contrast(103%) !important;
    backdrop-filter:blur(38px) saturate(180%) contrast(103%) !important;
    border-left:1px solid rgba(255,255,255,.09);
    box-shadow:0 0 60px rgba(0,0,0,.85),inset 1px 0 0 rgba(255,255,255,.05) !important;
  }
  .panel-overlay{
    -webkit-backdrop-filter:blur(6px) saturate(120%);
    backdrop-filter:blur(6px) saturate(120%);
  }

  /* القائمة الجانبية للأقسام — زجاج معتّم أنيق */
  .shs-catmenu-panel{
    -webkit-backdrop-filter:blur(34px) saturate(175%) contrast(103%) !important;
    backdrop-filter:blur(34px) saturate(175%) contrast(103%) !important;
    box-shadow:inset 1px 0 0 rgba(255,255,255,.05),0 0 50px rgba(0,0,0,.6) !important;
  }
  .shs-catmenu-overlay{
    -webkit-backdrop-filter:blur(6px) saturate(120%) !important;
    backdrop-filter:blur(6px) saturate(120%) !important;
  }

  /* نافذة TMDB المنبثقة — زجاج ناعم */
  .tmdb-modal-overlay{
    -webkit-backdrop-filter:blur(14px) saturate(150%);
    backdrop-filter:blur(14px) saturate(150%);
  }
}

/* على الأجهزة الضعيفة: نُخفّف حِدّة الـblur للحفاظ على الأداء */
@media (max-width:640px){
  @supports ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){
    .navbar,.navbar.scrolled{
      -webkit-backdrop-filter:blur(22px) saturate(180%) !important;
      backdrop-filter:blur(22px) saturate(180%) !important;
    }
    .cat-navbar{
      -webkit-backdrop-filter:blur(20px) saturate(170%) !important;
      backdrop-filter:blur(20px) saturate(170%) !important;
    }
    .fp-panel,.np-panel,.m3u-panel,.ep-panel,.shs-catmenu-panel{
      -webkit-backdrop-filter:blur(26px) saturate(165%) !important;
      backdrop-filter:blur(26px) saturate(165%) !important;
    }
  }
}
</style>
<?php if (!empty($custom_css_db)): ?>
<style id="siteCustomTheme"><?php echo strip_tags($custom_css_db); ?></style>
<?php endif; ?>
<?php /* كود مخصص يُحقن داخل head (تحليلات/بكسل/سكربت) — من الإعدادات العامة */ ?>
<?php if (!empty($gs_custom_head_code)): ?>
<?php echo $gs_custom_head_code; ?>
<?php endif; ?>
</head>
<body>
<!-- INIT LOADER -->
<div id="nxInitLoader"><div class="nx-loader-circle"></div></div>

<?php /* الشريط الإعلاني العلوي — يُتحكم به من الإعدادات العامة */ ?>
<?php if ($gs_announce_enabled && trim($gs_announce_text) !== ''): ?>
<div id="gsAnnounceBar" style="position:relative;z-index:9998;background:linear-gradient(90deg,var(--accent,#e50914),#9a050d);color:#fff;text-align:center;padding:9px 40px;font-size:.9rem;font-weight:600;box-shadow:0 2px 10px rgba(0,0,0,.4)">
  <?php if (trim($gs_announce_link) !== ''): ?>
    <?php
      /* [أمان] نسمح فقط بـ http/https أو رابط نسبي — نمنع javascript: و data: */
      $__al = trim($gs_announce_link);
      if (!preg_match('#^(https?://|/)#i', $__al)) { $__al = 'https://' . ltrim($__al, '/'); }
    ?>
    <a href="<?php echo htmlspecialchars($__al, ENT_QUOTES); ?>" target="_blank" rel="noopener noreferrer" style="color:#fff;text-decoration:none"><?php echo htmlspecialchars($gs_announce_text); ?></a>
  <?php else: ?>
    <?php echo htmlspecialchars($gs_announce_text); ?>
  <?php endif; ?>
  <button onclick="this.parentElement.style.display='none'" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);background:transparent;border:none;color:#fff;font-size:1.2rem;cursor:pointer;line-height:1" aria-label="إغلاق">&times;</button>
</div>
<?php endif; ?>

<!-- SVG FILTERS -->
<svg style="display:none" xmlns="http://www.w3.org/2000/svg">
  <filter id="enh-deblock" x="0" y="0" width="100%" height="100%" color-interpolation-filters="sRGB">
    <feGaussianBlur stdDeviation="0.45" result="blurred"/>
    <feComposite in="SourceGraphic" in2="blurred" operator="arithmetic" k1="0" k2="1.6" k3="-0.6" k4="0" result="unsharp"/>
    <feBlend in="unsharp" in2="blurred" mode="normal"/>
  </filter>
  <filter id="enh-hdr" x="0" y="0" width="100%" height="100%" color-interpolation-filters="sRGB">
    <feColorMatrix type="saturate" values="1.1"/>
    <feComponentTransfer>
      <feFuncR type="table" tableValues="0.00 0.05 0.18 0.38 0.60 0.80 0.93 1.00"/>
      <feFuncG type="table" tableValues="0.00 0.05 0.18 0.38 0.60 0.80 0.93 1.00"/>
      <feFuncB type="table" tableValues="0.00 0.04 0.15 0.34 0.57 0.78 0.92 1.00"/>
    </feComponentTransfer>
  </filter>
  <filter id="enh-frame" x="0" y="0" width="100%" height="100%" color-interpolation-filters="sRGB">
    <feConvolveMatrix order="3" preserveAlpha="true" kernelMatrix="-0.1 -0.15 -0.1 -0.15 2.1 -0.15 -0.1 -0.15 -0.1"/>
  </filter>
  <filter id="enh-full" x="0" y="0" width="100%" height="100%" color-interpolation-filters="sRGB">
    <feGaussianBlur stdDeviation="0.35" result="soft"/>
    <feComposite in="SourceGraphic" in2="soft" operator="arithmetic" k1="0" k2="1.5" k3="-0.5" k4="0" result="deblocked"/>
    <feColorMatrix in="deblocked" type="saturate" values="1.08" result="sat"/>
    <feComponentTransfer in="sat" result="hdr">
      <feFuncR type="table" tableValues="0.00 0.05 0.18 0.38 0.60 0.80 0.93 1.00"/>
      <feFuncG type="table" tableValues="0.00 0.05 0.18 0.38 0.60 0.80 0.93 1.00"/>
      <feFuncB type="table" tableValues="0.00 0.04 0.15 0.34 0.57 0.78 0.92 1.00"/>
    </feComponentTransfer>
    <feConvolveMatrix in="hdr" order="3" preserveAlpha="true" kernelMatrix="-0.08 -0.12 -0.08 -0.12 1.8 -0.12 -0.08 -0.12 -0.08"/>
  </filter>
</svg>

<!-- DEVTOOLS OVERLAY -->
<?php if ($gs_block_devtools): ?>
<div class="devtools-overlay" id="devtoolsOverlay">
  <div class="devtools-box">
    <div class="devtools-lock-icon" id="lockIcon"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="#ff4d57" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg></span></div>
    <div class="devtools-title">السيرفر محمي</div>
    <div style="width:60px;height:2px;background:linear-gradient(90deg,transparent,var(--red),transparent);margin:12px auto 18px"></div>
    <div class="devtools-badge"><span class="lcn" style="font-size:.75rem"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span> حماية متقدمة مفعّلة</div>
    <div class="devtools-sub">هذا النظام محمي بالكامل.<br>لا يُسمح بالوصول إلى أدوات المطور.</div>
  </div>
</div>
<?php endif; ?>

<?php if($license_expired): ?>
<div class="license-banner">
  <span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m14.5 9.5-5 5"/><path d="m9.5 9.5 5 5"/></svg></span>
  <span>الرخصة منتهية — يرجى التجديد للوصول للمحتوى</span>
  <a href="activate.php" class="lic-renew"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg></span> تجديد الآن</a>
</div>
<div style="height:50px"></div>
<?php endif; ?>

<div class="panel-overlay" id="panelOverlay" onclick="closeAllPanels()"></div>

<!-- NAVBAR -->
<nav class="navbar" id="navbar">
  <div class="nav-brand">
    <?php if($site_logo): ?>
    <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="Logo" class="nav-logo-img">
    <?php endif; ?>
    <span class="nav-logo-text"><?php echo htmlspecialchars($site_name); ?></span>
  </div>
  <div class="nav-center">
    <?php if(!$hide_search): ?>
    <div class="search-wrap">
      <input type="text" id="searchInput" placeholder="بحث / Search" oninput="handleSearch()">
      <span class="lcn si"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></span>
      <span class="lcn" id="voiceSearchBtn" style="position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--text-muted);cursor:pointer;font-size:1.1rem;display:none;z-index:10;transition:0.2s" title="بحث صوتي"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/></svg></span>
    </div>
    <?php endif; ?>
  </div>
  <div class="nav-actions">
    <!-- [SHS-CATMENU-BTN-START] زر قائمة الأقسام (إضافة فقط) -->
    <button type="button" class="shs-catmenu-btn" id="shsCatMenuBtn" title="الأقسام" onclick="shsOpenCatMenu()">
      <span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" x2="21" y1="6" y2="6"/><line x1="3" x2="21" y1="12" y2="12"/><line x1="3" x2="21" y1="18" y2="18"/></svg></span>
    </button>
    <!-- [SHS-CATMENU-BTN-END] -->
    <?php if(!$hide_music): ?>
    <button class="nav-btn music-mini-btn" id="musicMiniBtn" title="مشغل الموسيقى" onclick="toggleSiteMusic()">
      <div class="music-eq paused" id="musicEq">
        <div class="music-eq-bar"></div>
        <div class="music-eq-bar"></div>
        <div class="music-eq-bar"></div>
      </div>
    </button>
    <?php endif; ?>
    <?php if(!$hide_admin_btn): ?>
    <a href="admin.php" class="nav-btn" style="background:var(--red);color:#fff;border-color:var(--red)" title="لوحة التحكم"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg></span></a>
    <?php endif; ?>
    <?php if(!$hide_notifications): ?>
    <button class="nav-btn" title="الإشعارات" onclick="toggleNotifPanel()">
      <span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg></span>
      <span id="notifBadge" style="display:none"></span>
    </button>
    <?php endif; ?>
    <?php if(!$hide_favorites): ?>
    <button class="nav-btn" title="المفضلة" onclick="toggleFavPanel()"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg></span></button>
    <?php endif; ?>
  </div>
</nav>

<!-- CATEGORY QUICK NAV -->
<nav class="cat-navbar" id="catNavbar" style="display:none"></nav>

<!-- [SHS-CATMENU-HTML-START] قائمة الأقسام العمودية المنسدلة (إضافة فقط) -->
<div class="shs-catmenu-overlay" id="shsCatMenuOverlay" onclick="shsCloseCatMenu()"></div>
<aside class="shs-catmenu-panel" id="shsCatMenuPanel" aria-hidden="true">
  <div class="shs-catmenu-head">
    <button type="button" class="shs-catmenu-home" onclick="shsCatMenuGoHome()">الرئيسية</button>
    <div class="shs-catmenu-headwrap">
      <span class="shs-catmenu-title">الأقسام</span>
      <span class="shs-catmenu-sub">تصفّح كل الأقسام</span>
    </div>
    <button type="button" class="shs-catmenu-close" onclick="shsCloseCatMenu()" aria-label="إغلاق">
      <span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></span>
    </button>
  </div>
  <button type="button" class="shs-catmenu-homerow" onclick="shsCatMenuGoHome()">
    <span>الرئيسية</span>
    <span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span>
  </button>
  <div class="shs-catmenu-list" id="shsCatMenuList">
    <div class="shs-catmenu-empty">جارٍ التحميل…</div>
  </div>
  <div class="shs-catmenu-count" id="shsCatMenuCount"></div>
</aside>
<!-- [SHS-CATMENU-HTML-END] -->

<!-- MAIN -->
<main style="padding-top:88px;padding-bottom:60px" id="mainContent">
  <div class="hero-welcome" id="heroWelcome">
    <h1><?php echo htmlspecialchars($welcome_title); ?></h1>
    <p><?php echo htmlspecialchars($welcome_subtitle); ?></p>
  </div>

  <div id="netflixStyleSliders">
    <div style="margin-bottom:40px">
      <div style="padding:0 20px;margin-bottom:10px">
        <div class="skeleton" style="height:22px;width:160px;border-radius:6px;display:inline-block"></div>
      </div>
      <div style="display:flex;gap:10px;padding:0 20px;overflow:hidden">
        <?php for($i=0;$i<8;$i++): ?>
        <div class="skeleton" style="flex:0 0 calc((100vw - 40px - 70px)/8);height:195px;border-radius:10px;flex-shrink:0"></div>
        <?php endfor; ?>
      </div>
    </div>
  </div>

  <div id="categoryViewSection" class="hidden" style="padding:0 20px">
    <button class="back-btn" onclick="closeCategoryView()"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></span> الرئيسية</button>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;font-size:1.1rem;font-weight:800;color:#fff">
      <div class="slider-title-icon"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg></span></div>
      <span id="categoryViewTitle">القسم</span>
      <span class="slider-badge" id="categoryViewCountBadge">0 عنصر</span>
    </div>
    <div class="channels-row" id="categoryViewGrid"></div>
    <div id="categoryViewLoading" class="hidden" style="text-align:center;padding:56px 0">
      <div class="shs-spinner"></div>
      <div class="shs-loading-txt">جارٍ تحميل المحتوى…</div>
    </div>
    <div id="categoryViewEmpty" class="hidden" style="padding:0;color:var(--text-muted)">
      <div class="shs-empty-wrap">
        <div class="shs-empty-ico"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 8V6a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v2"/><rect width="20" height="14" x="2" y="8" rx="2"/><path d="m9 13 2 2 4-4"/></svg></span></div>
        <div class="shs-empty-title">لا يوجد محتوى بعد</div>
        <p class="shs-empty-sub">هذا القسم فارغ حالياً. جرّب قسماً آخر أو عد لاحقاً بعد إضافة محتوى جديد.</p>
      </div>
    </div>
  </div>

  <div id="searchViewSection" class="hidden" style="padding:0 20px">
    <button class="back-btn" onclick="clearSearchAndGoHome()"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></span> الرئيسية</button>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;font-size:1.1rem;font-weight:800;color:#fff">
      <div class="slider-title-icon"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></span></div>
      نتائج البحث
      <span class="slider-badge" id="searchCountBadge">0 نتيجة</span>
    </div>
    <div class="channels-row" id="searchGrid"></div>
    <div id="searchEmpty" class="hidden" style="text-align:center;padding:60px 0;color:var(--text-muted)">
      <span class="lcn" style="font-size:3rem;margin-bottom:16px;display:block;opacity:.3"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></span>
      <p>لا توجد نتائج مطابقة</p>
    </div>
  </div>

  <div class="hidden" id="epSection" style="padding:0 20px">
    <button class="back-btn" id="epBackBtn" onclick="backFromEpisodesToHome()"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></span> <span id="epBackLabel">رجوع</span></button>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;font-size:1.1rem;font-weight:800;color:#fff">
      <div class="slider-title-icon"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="20" x="2" y="2" rx="2.18" ry="2.18"/><line x1="7" x2="7" y1="2" y2="22"/><line x1="17" x2="17" y1="2" y2="22"/><line x1="2" x2="22" y1="7" y2="7"/><line x1="2" x2="22" y1="12" y2="12"/><line x1="2" x2="22" y1="17" y2="17"/></svg></span></div>
      <span id="epSectionTitle">الحلقات</span>
    </div>
    <div class="episodes-grid" id="epGrid"></div>
    <div id="epLoading" class="hidden" style="text-align:center;padding:40px 0"><div class="p-buffer-ring" style="margin:0 auto"></div></div>
    <div id="epEmpty" class="hidden" style="text-align:center;padding:60px 0;color:var(--text-muted)"><p>لا تتوفر حلقات</p></div>
  </div>
</main>

<footer style="background:#0d0d0d;border-top:1px solid rgba(255,255,255,.07);direction:rtl">

  <div style="max-width:1000px;margin:0 auto;padding:48px 32px 32px">

    <!-- الأعلى: الشعار والوصف — نفس تصميم القديم -->
    <div style="text-align:center;margin-bottom:40px">
      <div style="font-size:1.6rem;font-weight:900;color:var(--red);letter-spacing:-1px;margin-bottom:8px"><?php echo htmlspecialchars($site_name); ?></div>
      <p style="color:#444;font-size:.82rem;line-height:1.7;margin:0"><?php echo htmlspecialchars($footer_text); ?></p>
    </div>

    <!-- عنوان التواصل -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;justify-content:center">
      <div style="flex:1;max-width:120px;height:1px;background:linear-gradient(to right,transparent,rgba(229,9,20,.3))"></div>
      <span style="font-size:.68rem;font-weight:800;color:#444;text-transform:uppercase;letter-spacing:3px">تواصل معنا</span>
      <div style="flex:1;max-width:120px;height:1px;background:linear-gradient(to left,transparent,rgba(229,9,20,.3))"></div>
    </div>

    <!-- أزرار التواصل أفقية -->
    <?php if(!$hide_social): ?>
    <div style="display:flex;flex-wrap:wrap;gap:12px;justify-content:center;margin-bottom:40px">

      <!-- واتساب -->
  <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $settings['contact_whatsapp'] ?? '9647512328848'); ?>" target="_blank" rel="noopener noreferrer"
         style="display:flex;align-items:center;gap:10px;padding:12px 22px;border-radius:50px;background:rgba(37,211,102,.08);border:1px solid rgba(37,211,102,.2);text-decoration:none;color:#e2e8f0;font-size:.85rem;font-weight:700;transition:all .22s;white-space:nowrap"
         onmouseover="this.style.background='rgba(37,211,102,.2)';this.style.borderColor='rgba(37,211,102,.5)';this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 20px rgba(37,211,102,.2)'"
         onmouseout="this.style.background='rgba(37,211,102,.08)';this.style.borderColor='rgba(37,211,102,.2)';this.style.transform='translateY(0)';this.style.boxShadow='none'">
        <div style="width:30px;height:30px;border-radius:50%;background:#25D366;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="white"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
        </div>
        واتساب
      </a>

      <!-- فيسبوك -->
      <a href="https://<?php echo htmlspecialchars(ltrim($settings['contact_facebook'] ?? 'facebook.com/xxkpq', 'https://')); ?>" target="_blank" rel="noopener"
         style="display:flex;align-items:center;gap:10px;padding:12px 22px;border-radius:50px;background:rgba(24,119,242,.08);border:1px solid rgba(24,119,242,.2);text-decoration:none;color:#e2e8f0;font-size:.85rem;font-weight:700;transition:all .22s;white-space:nowrap"
         onmouseover="this.style.background='rgba(24,119,242,.2)';this.style.borderColor='rgba(24,119,242,.5)';this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 20px rgba(24,119,242,.2)'"
         onmouseout="this.style.background='rgba(24,119,242,.08)';this.style.borderColor='rgba(24,119,242,.2)';this.style.transform='translateY(0)';this.style.boxShadow='none'">
        <div style="width:30px;height:30px;border-radius:50%;background:#1877F2;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="white"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
        </div>
        فيسبوك
      </a>

      <!-- البريد -->
      <a href="mailto:<?php echo htmlspecialchars($settings['contact_email'] ?? 'info@shashety-pro.com'); ?>"
         style="display:flex;align-items:center;gap:10px;padding:12px 22px;border-radius:50px;background:rgba(229,9,20,.08);border:1px solid rgba(229,9,20,.2);text-decoration:none;color:#e2e8f0;font-size:.85rem;font-weight:700;transition:all .22s;white-space:nowrap"
         onmouseover="this.style.background='rgba(229,9,20,.2)';this.style.borderColor='rgba(229,9,20,.5)';this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 20px rgba(229,9,20,.2)'"
         onmouseout="this.style.background='rgba(229,9,20,.08)';this.style.borderColor='rgba(229,9,20,.2)';this.style.transform='translateY(0)';this.style.boxShadow='none'">
        <div style="width:30px;height:30px;border-radius:50%;background:#e50914;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
        </div>
        البريد الإلكتروني
      </a>

    </div>
    <?php endif; ?>

    <!-- فاصل + حقوق -->
    <div style="height:1px;background:rgba(255,255,255,.05);margin-bottom:20px"></div>
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px">
      <span style="color:#2e2e2e;font-size:.74rem"><?php echo htmlspecialchars($footer_text); ?></span>
    </div>

  </div>
</footer>


<div class="toasts" id="toastContainer"></div>

<!-- TMDB Modal -->
<div class="tmdb-modal-overlay" id="tmdbInfoM">
  <div class="tmdb-modal-box">
    <div class="tmdb-modal-head">
      <div style="font-size:1.05rem;font-weight:800;display:flex;align-items:center;gap:10px">
        <span class="lcn" style="color:var(--red)"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg></span> تفاصيل العمل
      </div>
      <button class="tmdb-modal-close" onclick="closeTmdbModal()"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></span></button>
    </div>
    <div class="tmdb-modal-body" id="tmdbInfoBody"></div>
  </div>
</div>

<!-- Panels -->
<div class="ep-panel" id="epPanel">
  <div class="ep-panel-head">
    <div style="font-size:1rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px"><span class="lcn" style="color:#B36BFF"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="20" x="2" y="2" rx="2.18" ry="2.18"/><line x1="7" x2="7" y1="2" y2="22"/><line x1="17" x2="17" y1="2" y2="22"/><line x1="2" x2="22" y1="7" y2="7"/><line x1="2" x2="22" y1="12" y2="12"/><line x1="2" x2="22" y1="17" y2="17"/></svg></span><span id="epPanelTitle">الحلقات</span></div>
    <button class="ep-panel-close" onclick="toggleEpPanel()"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></span></button>
  </div>
  <div style="flex:1;overflow-y:auto;padding:14px" id="epPanelBody"></div>
</div>

<div class="fp-panel" id="favPanel">
  <div class="ep-panel-head">
    <div style="font-size:1rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px"><span class="lcn" style="color:#ff4d57"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg></span>مفضلتي</div>
    <button class="ep-panel-close" onclick="toggleFavPanel()"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></span></button>
  </div>
  <div style="flex:1;overflow-y:auto;padding:14px" id="favPanelBody"></div>
</div>

<div class="m3u-panel" id="m3uPanel">
  <div class="ep-panel-head">
    <div style="font-size:1rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px"><span class="lcn" style="color:#ff4d57"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="10" x2="21" y1="6" y2="6"/><line x1="10" x2="21" y1="12" y2="12"/><line x1="10" x2="21" y1="18" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg></span><span id="m3uPanelHead">قائمة التشغيل</span></div>
    <button class="ep-panel-close" onclick="toggleM3UPanel()"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></span></button>
  </div>
  <div style="flex:1;overflow-y:auto;padding:14px" id="m3uPanelBody"></div>
</div>

<div class="np-panel" id="notifPanel">
  <div class="ep-panel-head">
    <div style="font-size:1rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px"><span class="lcn" style="color:#ffb020"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg></span>المحتوى المُضاف حديثاً</div>
    <button class="ep-panel-close" onclick="toggleNotifPanel()"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></span></button>
  </div>
  <div style="flex:1;overflow-y:auto;padding:14px" id="notifPanelBody"></div>
</div>

<!-- PLAYER -->
<div class="player-overlay" id="playerOverlay" tabindex="-1">
  <div class="pv-wrap" id="pvWrap" tabindex="-1">
    <video id="html5Player" playsinline preload="auto"></video>
    <div class="p-buffer" id="pBuffer"><div class="p-buffer-ring"></div></div>
    <div class="p-flash"><div class="p-flash-icon" id="pFlash"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg></span></div></div>
  </div>
  <div class="p-vignette-top"></div>
  <div class="p-vignette-bot"></div>
  <!-- شعارات دعم الجهاز: صوت + صورة + هرتزية -->
  <div id="deviceBadgesWrap"></div>
  <div class="p-top" id="pTop">
    <button type="button" class="p-back" onclick="closePlayer()"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></span></button>
    <div class="p-title-block">
      <div class="p-channel-name" id="pChannelName">—</div>
      <div class="p-title-sub">
        <span class="p-live-badge" id="pBadgeLabel">LIVE</span>
        <span class="p-fmt-tag" id="pFmtTag">HLS</span>
      </div>
    </div>
    <div class="p-top-right">
      <div class="p-ep-nav" id="pEpNav" style="display:none">
        <button type="button" class="p-icon-btn" onclick="navEpisode(-1)" id="pPrevEp"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="19 20 9 12 19 4 19 20"/><line x1="5" x2="5" y1="19" y2="5"/></svg></span></button>
        <span id="pEpLabel" class="p-ep-label">الحلقة 1</span>
        <button type="button" class="p-icon-btn" onclick="navEpisode(1)" id="pNextEp"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 4 15 12 5 20 5 4"/><line x1="19" x2="19" y1="5" y2="19"/></svg></span></button>
      </div>
      <?php if(!$hide_cast): ?>
      <button type="button" class="p-icon-btn" onclick="castToSmartWvc()"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 8V6a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-6"/><path d="M2 12a9 9 0 0 1 8 8"/><path d="M2 16a5 5 0 0 1 4 4"/><line x1="2" x2="2.01" y1="20" y2="20"/></svg></span></button>
      <?php endif; ?>
      <?php if(!$hide_download): ?>
      <button type="button" class="p-icon-btn" id="tdmDownloadBtn" onclick="downloadWithTdm()" style="display:none" title="تحميل الفيديو"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg></span></button>
      <?php endif; ?>
    </div>
  </div>
  <div class="p-center" id="pCenter">
    <button type="button" class="p-seek-btn" onclick="skip(-10)">
      <span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg></span><span class="p-seek-n">10</span>
    </button>
    <button type="button" class="p-play-btn" id="playBtn" onclick="togglePlay()">
      <span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="4" height="16" x="6" y="4"/><rect width="4" height="16" x="14" y="4"/></svg></span>
    </button>
    <button type="button" class="p-seek-btn" onclick="skip(10)">
      <span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg></span><span class="p-seek-n">10</span>
    </button>
  </div>
  <div class="p-bottom" id="pBottom">
    <div class="p-prog-row">
      <span class="p-tc" id="pTimeCur">00:00</span>
      <div class="p-prog-wrap" id="pProgress" onclick="seekTo(event)">
        <div class="p-prog-track"><div class="p-prog-fill" id="pFill"><div class="p-prog-dot"></div></div></div>
      </div>
      <span class="p-tc" id="pTimeTotal">00:00</span>
    </div>
    <div class="p-tools">
      <!-- اليمين: ترجمة + تحسين + قوائم (p-tools-l أول في DOM = يمين في RTL) -->
      <div class="p-tools-l">
        <button type="button" class="p-btn" onclick="toggleSubtitle()" id="subBtn"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="14" x="3" y="5" rx="2" ry="2"/><path d="M7 15h4m4 0h2M7 11h2m4 0h4"/></svg></span></button>
        <button type="button" class="p-btn p-enh" onclick="toggleEnhancements()" id="enhanceBtn">
          <span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.64 3.64-1.28-1.28a1.21 1.21 0 0 0-1.72 0L2.36 18.64a1.21 1.21 0 0 0 0 1.72l1.28 1.28a1.2 1.2 0 0 0 1.72 0L21.64 5.36a1.2 1.2 0 0 0 0-1.72Z"/><path d="m14 7 3 3"/><path d="M5 6v4"/><path d="M19 14v4"/><path d="M10 2v2"/><path d="M7 8H3"/><path d="M21 16h-4"/><path d="M11 3H9"/></svg></span><span id="enhLabel" class="p-enh-lbl">HD</span>
        </button>
        <button type="button" class="p-btn" onclick="toggleEpPanel()" id="epPanelBtn" style="display:none"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" x2="21" y1="6" y2="6"/><line x1="8" x2="21" y1="12" y2="12"/><line x1="8" x2="21" y1="18" y2="18"/><line x1="3" x2="3.01" y1="6" y2="6"/><line x1="3" x2="3.01" y1="12" y2="12"/><line x1="3" x2="3.01" y1="18" y2="18"/></svg></span></button>
        <button type="button" class="p-btn" onclick="toggleM3UPanel()" id="m3uPanelBtn" style="display:none"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="10" x2="21" y1="6" y2="6"/><line x1="10" x2="21" y1="12" y2="12"/><line x1="10" x2="21" y1="18" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg></span></button>
      </div>
      <!-- اليسار: صوت + وقت + fullscreen (p-tools-r ثاني في DOM = يسار في RTL) -->
      <div class="p-tools-r">
        <button type="button" class="p-btn p-fs-btn" onclick="toggleFullscreen()">
          <span class="lcn" id="fsIcon"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21 21-6-6m6 6v-4.8m0 4.8h-4.8"/><path d="M3 16.2V21m0 0h4.8M3 21l6-6"/><path d="M21 7.8V3m0 0h-4.8M21 3l-6 6"/><path d="M3 7.8V3m0 0h4.8M3 3l6 6"/></svg></span>
        </button>
        <span class="p-time-txt" id="pTime">00:00 / 00:00</span>
        <div class="p-vol-wrap" id="volWrap">
          <button type="button" class="p-btn p-vol-icon" id="muteIcon" onclick="toggleMute()">
            <span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg></span>
          </button>
          <div class="p-vol-slider-wrap">
            <div class="p-vol-track" onclick="setVolume(event)">
              <div class="p-vol-fill" id="volFill"></div>
              <div class="p-vol-thumb" id="volThumb"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- SCREENSAVER -->
<div id="nxScreensaver">
  <div class="nx-bg" id="nxBg"></div>
  <div class="nx-vignette"></div>
  <div class="nx-container" id="nxWrap">
    <div class="nx-info-box">
      <div class="nx-top-badge"><span class="lcn" style="font-size:.7rem"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg></span> متاح للمشاهدة</div>
      <h1 class="nx-title" id="nxTitle">—</h1>
      <div class="nx-meta-row">
        <span class="nx-match" id="nxMatchBadge">المطابقة 98%</span>
        <span id="nxYear">2024</span>
        <span class="nx-tag">HD</span>
      </div>
    </div>
    <div><img class="nx-poster" id="nxImg" src="" alt=""></div>
  </div>
  <div class="nx-footer"><span class="nx-bounce-arrow"><span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg></span></span> المس للعودة</div>
</div>

<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
<!-- إصدار مثبّت بدل @latest: تخزين مؤقت أقوى وبلا جولة تحديد إصدار.
     preload يبدأ التنزيل فوراً، وasync ينفّذه دون حجب رسم الصفحة. -->
<link rel="preload" as="script" href="https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js">
<script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js" async></script>
<script src="https://cdn.dashjs.org/latest/dash.all.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/flv.js@latest/dist/flv.min.js" defer></script>

<script>
'use strict';

/* ════ SMART CACHE ════ */
(function(){
  const orig=window.fetch;
  window.fetch=async function(){
    const url=arguments[0];
    if(typeof url==='string'&&url.includes('api.php')&&url.includes('action=all_content')){
      const k='sc_'+url;
      const cached=sessionStorage.getItem(k);
      if(cached)return new Response(cached,{status:200,headers:new Headers({'Content-Type':'application/json'})});
      try{
        const r=await orig.apply(this,arguments);
        r.clone().text().then(t=>{try{sessionStorage.setItem(k,t);}catch(e){}});
        return r;
      }catch(e){return orig.apply(this,arguments);}
    }
    return orig.apply(this,arguments);
  };
})();

/* ════ SMART CACHE+ — تسريع شامل لطلبات api.php (إضافة) ════
   - كاش لكل الطلبات القرائية (channels/series/episodes/all_content)
   - منع تكرار الطلبات المتزامنة المتطابقة (deduplication)
   - تجاهل طلبات increment_view (كتابة) من الكاش
   ──────────────────────────────────────────────────────────── */
(function(){
  const orig=window.fetch;
  const mem=new Map();          // كاش في الذاكرة (أسرع من sessionStorage)
  const inflight=new Map();     // طلبات جارية الآن
  const TTL=10*60*1000;         // صلاحية الكاش: ١٠ دقائق (كانت دقيقة واحدة → إعادة جلب مستمرة)
  const SS_PREFIX='shs_c_';     // كاش يبقى بين تنقّلات الصفحة وإعادة التحميل

  // استرجاع ما خُزّن في الجلسة السابقة إلى الذاكرة
  try{
    const now0=Date.now();
    Object.keys(sessionStorage).forEach(k=>{
      if(k.indexOf(SS_PREFIX)!==0) return;
      try{
        const o=JSON.parse(sessionStorage.getItem(k));
        if(o && (now0-o.t)<TTL) mem.set(k.slice(SS_PREFIX.length),{body:o.body,t:o.t});
        else sessionStorage.removeItem(k);
      }catch(e){ try{sessionStorage.removeItem(k);}catch(_){} }
    });
  }catch(e){}
  // الإجراءات القرائية القابلة للتخزين
  const READ=/action=(channels|series|episodes|all_content)\b/;
  // إجراءات كتابة لا تُخزَّن إطلاقاً
  const SKIP=/action=(increment_view)\b/;

  window.fetch=function(){
    const url=arguments[0];
    // نتعامل فقط مع طلبات api.php القرائية البسيطة (GET بدون body)
    const simple = (typeof url==='string') && url.includes('api.php')
                 && READ.test(url) && !SKIP.test(url)
                 && (arguments.length<2 || !arguments[1] || (arguments[1].method||'GET').toUpperCase()==='GET');
    if(!simple) return orig.apply(this,arguments);

    const k=url;
    const now=Date.now();
    // 1) كاش في الذاكرة
    const c=mem.get(k);
    if(c && (now-c.t)<TTL){
      return Promise.resolve(new Response(c.body,{status:200,headers:new Headers({'Content-Type':'application/json'})}));
    }
    // 2) طلب جارٍ بنفس الرابط → شاركه
    if(inflight.has(k)) return inflight.get(k).then(b=>new Response(b,{status:200,headers:new Headers({'Content-Type':'application/json'})}));

    const p=orig.apply(this,arguments).then(r=>{
      const clone=r.clone();
      return clone.text().then(body=>{
        const t=Date.now();
        try{ mem.set(k,{body,t}); }catch(e){}
        // نحفظ الاستجابات الصغيرة فقط في sessionStorage (حدّها ~5MB)
        try{
          if(body.length < 600000) sessionStorage.setItem(SS_PREFIX+k, JSON.stringify({body,t}));
        }catch(e){ /* الحصة ممتلئة — الذاكرة تكفي */ }
        inflight.delete(k);
        return body;
      }).then(()=>r).catch(()=>{inflight.delete(k);return r;});
    }).catch(e=>{ inflight.delete(k); throw e; });

    // نخزّن وعد النص للمشاركة بين الطلبات المتزامنة
    inflight.set(k, p.then(r=>r.clone().text()).catch(()=>''));
    return p;
  };

  // أداة إبطال الكاش يدوياً عند الحاجة
  window.scInvalidate=function(){
    mem.clear();
    try{ Object.keys(sessionStorage).forEach(k=>{ if(k.indexOf(SS_PREFIX)===0) sessionStorage.removeItem(k); }); }catch(e){}
  };
})();

/* ══════════════════════════════════════════════════════════════════════
   ║  التحديث اللحظي للمحتوى (Live Updates)                              ║
   ║  ───────────────────────────────────────────────────────────────    ║
   ║  الفكرة: بدل جلب كل المحتوى كل فترة (ثقيل جداً)، نسأل الخادم سؤالاً  ║
   ║  واحداً صغيراً: "ما هي بصمة المحتوى الحالية؟" الرد ~40 بايت.        ║
   ║  إن تغيّرت البصمة = هناك إضافة جديدة → نُبطل الكاش ونحدّث الواجهة.  ║
   ║                                                                      ║
   ║  لماذا ليس WebSocket؟ لأنه يحتاج عملية دائمة ومنفذاً خاصاً لا        ║
   ║  توفّرهما الاستضافات المشتركة، ولأن المحتوى هنا يتغيّر نادراً        ║
   ║  (إضافة إدارية)، فاتصال دائم لكل زائر تكلفة بلا فائدة.              ║
   ══════════════════════════════════════════════════════════════════════ */
(function(){
  var POLL_ACTIVE   = 30000;   // كل ٣٠ ثانية والتبويب مفتوح أمام المستخدم
  var POLL_HIDDEN   = 0;       // ٠ = نوقف تماماً عندما يكون التبويب في الخلفية
  var _lastVersion  = null;
  var _timer        = null;
  var _busy         = false;
  var _failCount    = 0;

  // لا نزعج المستخدم أثناء المشاهدة: التحديث يُؤجَّل حتى يغلق المشغّل
  function playerOpen(){
    var o = document.getElementById('playerOverlay');
    return !!(o && o.classList.contains('active'));
  }

  function currentScreen(){
    function vis(id){ var e=document.getElementById(id); return e && !e.classList.contains('hidden'); }
    if(vis('epSection'))            return 'episodes';
    if(vis('searchViewSection'))    return 'search';
    if(vis('categoryViewSection'))  return 'category';
    return 'home';
  }

  /* إعادة بناء الشاشة الحالية بالبيانات الجديدة — بلا إعادة تحميل الصفحة */
  async function refreshCurrentScreen(){
    try{
      if(typeof window.scInvalidate==='function') window.scInvalidate();
      var scr = currentScreen();

      if(scr==='category' && window.App && App.currentCategoryView){
        // إعادة فتح نفس القسم يعيد جلبه ورسمه بالمحتوى الجديد
        if(typeof openCategoryView==='function')
          await openCategoryView(App.currentCategoryView.id, App.currentCategoryView.name||'');
        return;
      }
      if(scr==='episodes' && window.App && App.currentSeriesId){
        if(typeof openSeriesEpisodes==='function')
          await openSeriesEpisodes(App.currentSeriesId, App.currentSeriesName||'', App.currentSeriesPoster||'');
        return;
      }
      if(scr==='search'){
        if(typeof handleSearch==='function') handleSearch();
        return;
      }
      // الرئيسية: نعيد بناء الصفوف من الصفر (الأقسام قد تكون تغيّرت أيضاً)
      if(typeof loadAndBuildNetflixHome==='function'){
        // نُفرغ فهرس البحث حتى لا تتراكم نسخ قديمة
        try{ if(window._shsKeys) _shsKeys.clear(); if(window.App) App.allContent=[]; }catch(e){}
        await loadAndBuildNetflixHome();
      }
    }catch(e){ /* فشل التحديث لا يجب أن يكسر الصفحة */ }
  }

  function showUpdateToast(){
    // إشعار خفيف بدل مفاجأة المستخدم بتغيّر الشاشة
    try{ if(typeof toast==='function') toast('تم تحديث المحتوى'); }catch(e){}
  }

  async function checkOnce(){
    if(_busy) return;
    _busy = true;
    try{
      // cache:'no-store' ضروري: لا نريد أي طبقة كاش بيننا وبين البصمة
      var r = await window.fetch('api.php?action=content_version&_t='+Date.now(), {cache:'no-store'});
      if(!r.ok) throw new Error('HTTP '+r.status);
      var d = await r.json();
      var v = d && (d.version!==undefined ? String(d.version) : null);
      if(v===null) throw new Error('no version field');

      _failCount = 0;
      if(_lastVersion===null){ _lastVersion = v; return; }   // أول قراءة = خط الأساس
      if(v === _lastVersion) return;                          // لا جديد

      _lastVersion = v;
      if(playerOpen()){
        // المستخدم يشاهد الآن — نحدّث بعد إغلاق المشغّل مباشرة
        window.__shsPendingRefresh = true;
        return;
      }
      await refreshCurrentScreen();
      showUpdateToast();
    }catch(e){
      // تراجع تدريجي عند الفشل حتى لا نُغرق خادماً معطّلاً
      _failCount++;
    }finally{
      _busy = false;
      schedule();
    }
  }

  function nextDelay(){
    if(document.hidden) return POLL_HIDDEN;           // ٠ = لا جدولة إطلاقاً
    var base = POLL_ACTIVE;
    if(_failCount>0) base = Math.min(base * Math.pow(2,_failCount), 10*60*1000);
    return base;
  }

  function schedule(){
    if(_timer){ clearTimeout(_timer); _timer=null; }
    var d = nextDelay();
    if(d<=0) return;                                  // التبويب مخفي → توقف تام
    _timer = setTimeout(checkOnce, d);
  }

  // عند عودة المستخدم للتبويب: افحص فوراً (هذا يغطي الغياب الطويل بلا استهلاك)
  document.addEventListener('visibilitychange', function(){
    if(document.hidden){ if(_timer){clearTimeout(_timer);_timer=null;} }
    else { checkOnce(); }
  });

  // تحديث مؤجَّل بعد إغلاق المشغّل
  function hookClose(){
    if(typeof window.closePlayer!=='function'){ setTimeout(hookClose,300); return; }
    if(window.closePlayer.__shsLiveHooked) return;
    var _o = window.closePlayer;
    window.closePlayer = function(){
      var r = _o.apply(this, arguments);
      if(window.__shsPendingRefresh){
        window.__shsPendingRefresh = false;
        setTimeout(function(){ refreshCurrentScreen().then(showUpdateToast); }, 300);
      }
      return r;
    };
    window.closePlayer.__shsLiveHooked = true;
  }

  // أدوات يدوية للتحكم من الكونسول أو من أزرار مستقبلية
  window.shsLiveUpdates = {
    checkNow: function(){ return checkOnce(); },
    stop:  function(){ if(_timer){clearTimeout(_timer);_timer=null;} POLL_ACTIVE=0; },
    start: function(ms){ POLL_ACTIVE = ms||30000; schedule(); }
  };

  document.addEventListener('DOMContentLoaded', function(){
    hookClose();
    // فحص أول بعد ٥ ثوانٍ حتى لا نزاحم تحميل الصفحة الأولى
    setTimeout(checkOnce, 5000);
  });
})();

/* ════ APP STATE ════ */
const App={
  allContent:[],cats:[],
  currentType:'',currentSeriesId:0,currentSeriesName:'',allEpisodes:[],
  currentEpisodeIdx:-1,currentCategoryView:null,
  _catCache:{}, /* [SHS-CATVIEW] كاش محتوى الأقسام للاستجابة الفورية */
  currentSeriesPoster:'', /* [SHS-EPPOSTER] بوستر المسلسل الحالي كخلفية احتياطية للحلقات */
  license:<?php echo $license_expired?'true':'false'; ?>
};

/* ════ DEVICE DETECTION — مرة واحدة في أول الكود ════ */
const _UA=(function(){
  const ua=navigator.userAgent||'';
  const isIOS=/iPad|iPhone|iPod/.test(ua)&&!window.MSStream;
  const isAndroidTV=/Android/i.test(ua)&&(/TV|STB|BOX|bravia|shield|mibox/i.test(ua)||!/Mobile/i.test(ua));
  const isAndroidMobile=/Android/i.test(ua)&&/Mobile/i.test(ua)&&!isAndroidTV;
  // TV الحقيقي فقط — لا نصنف الكمبيوتر كـ TV مطلقاً
  const isSmartTV=/SmartTV|SMART-TV|Tizen|WebOS|HbbTV|VIDAA|NetCast|Hisense|Philips|TCL|BRAVIA/i.test(ua);
  return{
    ua,
    isIOS,
    isAndroid:/Android/i.test(ua),
    isAndroidMobile,
    isAndroidTV,
    isSmartTV,
    isTV:isAndroidTV||isSmartTV,   // الكمبيوتر ليس TV — fullscreen API يعمل عليه
    isWindows:/Windows NT/i.test(ua),
    isMobile:/iPhone|iPad|iPod|Android/i.test(ua)
  };
})();
var _isTV=_UA.isTV, _isIOS=_UA.isIOS, _isAndroid=_UA.isAndroid, _isWindows=_UA.isWindows;

/* ════ DEVTOOLS PROTECTION ════ */
<?php if ($gs_block_devtools): ?>
(function(){
  const overlay=document.getElementById('devtoolsOverlay'),lockIcon=document.getElementById('lockIcon');
  if(!overlay) return;
  function show(){overlay.classList.add('show');lockIcon.classList.remove('shake');void lockIcon.offsetWidth;lockIcon.classList.add('shake')}
  document.addEventListener('keydown',function(e){
    if(e.keyCode===123||e.ctrlKey&&e.shiftKey&&(e.keyCode===73||e.keyCode===74||e.keyCode===67)||e.ctrlKey&&e.keyCode===85){e.preventDefault();e.stopPropagation();show();return false}
  },true);
  let open=false;
  setInterval(function(){
    const w=!_UA.isMobile&&((window.outerWidth-window.innerWidth>160)||(window.outerHeight-window.innerHeight>160));
    if(w&&!open){open=true;show();}else if(!w&&open){open=false;overlay.classList.remove('show');}
  },800);
  document.addEventListener('contextmenu',function(e){e.preventDefault();return false});
  ['log','debug','warn','info','dir','table','trace','error'].forEach(function(m){try{console[m]=function(){}}catch(e){}});
})();
<?php endif; ?>

/* ════ FAVORITES ════ */
let MyFavs={channels:[],series:[]};
try{const s=localStorage.getItem('shashety_favs_v2');if(s){const p=JSON.parse(s);if(p&&Array.isArray(p.channels)&&Array.isArray(p.series))MyFavs=p;}}catch(e){}
function saveFavs(){try{localStorage.setItem('shashety_favs_v2',JSON.stringify(MyFavs));}catch(e){toast('تعذر حفظ المفضلة');}
  // مزامنة فهرس المفضلة السريع بعد أي تعديل
  if(typeof rebuildFavSets==='function') rebuildFavSets();
}
function toggleMyFav(id,name,type,icon_url,streamUrl='',subUrl=''){
  if(!MyFavs[type])return;
  const list=MyFavs[type];
  const idx=list.findIndex(x=>String(x.id)===String(id));
  if(idx>=0){list.splice(idx,1);toast('أزيل من المفضلة');}
  else{list.push({id,name,icon_url,stream_url:streamUrl,subtitle_url:subUrl,t_stamp:Date.now()});toast('أضيف للمفضلة');}
  saveFavs();buildFavPanel();
}
function buildFavPanel(){
  const b=document.getElementById('favPanelBody');
  const merged=[...MyFavs.channels.map(c=>({...c,ftype:'channels'})),...MyFavs.series.map(s=>({...s,ftype:'series'}))];
  merged.sort((a,b_)=>(b_.t_stamp||0)-(a.t_stamp||0));
  if(!merged.length){b.innerHTML='<div style="text-align:center;padding:40px;color:var(--text-muted)">قائمة المفضلة فارغة</div>';return;}
  b.innerHTML='';
  merged.forEach(item=>{
    const d=document.createElement('div');d.className='m3u-item';
    const ic=item.icon_url?`<img class="m3u-item-logo" src="${esc(item.icon_url)}" loading="lazy">`:`<div class="m3u-item-logo" style="display:flex;align-items:center;justify-content:center;color:#666;font-size:1.2rem">${item.ftype==='series'?'🎬':'📺'}</div>`;
    const del=`<button onclick="event.stopPropagation();toggleMyFav('${item.id}','','${item.ftype}')" style="background:rgba(229,9,20,.15);border-radius:6px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;color:#ff4d57;cursor:pointer;border:none">🗑</button>`;
    d.innerHTML=`${ic}<div style="flex:1;min-width:0"><div class="m3u-item-name">${esc(item.name)}</div><div class="m3u-item-group">${item.ftype==='channels'?'بث مباشر':'مسلسلات وأفلام'}</div></div>${del}`;
    d.onclick=()=>{if(item.ftype==='channels')openPlayerChannel({id:item.id,name:item.name,stream_url:item.stream_url,subtitle_url:item.subtitle_url});else openSeriesEpisodes(item.id,item.name,item.poster_url||item.img||'');};
    b.appendChild(d);
  });
}

/* ════ NOTIFICATIONS ════ */
const PendingNotifsKey='shashety_notifs_v4';
let MyNotifsQueue=[];
try{MyNotifsQueue=JSON.parse(localStorage.getItem(PendingNotifsKey)||'[]');}catch(e){}
function updateNotifBadge(){const b=document.getElementById('notifBadge');if(b)b.style.display=MyNotifsQueue.length>0?'block':'none';}
async function syncNotifications(cats){
  const SK='shashety_sync_v4';
  const isFirst=!localStorage.getItem(SK);
  let state=JSON.parse(localStorage.getItem(SK)||'{}');
  let discovered=[];
  for(const cat of cats){
    const cid=cat.id;
    if(!state[cid])state[cid]={srSeen:[],chSeen:[],srCount:0,chCount:0};
    const st=state[cid];
    const curSr=parseInt(cat.series_count||0),curCh=parseInt(cat.channel_count||0);
    if(curSr>st.srCount||isFirst){
      try{const r=await fetch('api.php?action=series&category_id='+cid);const d=await r.json();
        (d.series||[]).forEach(s=>{if(!st.srSeen.includes(s.id)){if(!isFirst)discovered.push({id:s.id,type:'series',name:s.name,img:s.poster_url||'',catName:cat.name});st.srSeen.push(s.id);}});
        st.srCount=curSr;}catch(e){}
    }
    if(curCh>st.chCount||isFirst){
      try{const r=await fetch('api.php?action=channels&category_id='+cid);const d=await r.json();
        (d.channels||[]).forEach(c=>{if(!st.chSeen.includes(c.id)){if(!isFirst)discovered.push({id:c.id,type:'channel',name:c.name,img:c.logo_url||'',catName:cat.name,streamUrl:c.stream_url,subUrl:c.subtitle_url});st.chSeen.push(c.id);}});
        st.chCount=curCh;}catch(e){}
    }
  }
  localStorage.setItem(SK,JSON.stringify(state));
  if(discovered.length){discovered.forEach(nd=>{if(!MyNotifsQueue.some(x=>String(x.id)===String(nd.id)))MyNotifsQueue.unshift(nd);});localStorage.setItem(PendingNotifsKey,JSON.stringify(MyNotifsQueue));}
  updateNotifBadge();
}
function buildNotifPanel(){
  const b=document.getElementById('notifPanelBody');b.innerHTML='';
  if(!MyNotifsQueue.length){b.innerHTML='<div style="text-align:center;padding:40px;color:var(--text-muted)">لا توجد إشعارات</div>';return;}
  MyNotifsQueue.forEach(item=>{
    const d=document.createElement('div');d.className='m3u-item';
    d.style.cssText='background:#1a1a1a;padding:12px;border:1px solid rgba(229,9,20,.15);border-radius:10px;margin-bottom:8px;position:relative;align-items:flex-start';
    const ph=item.img?`<img src="${esc(item.img)}" style="width:48px;height:68px;object-fit:cover;border-radius:6px;flex-shrink:0;background:#222">`:`<div style="width:48px;height:68px;display:flex;align-items:center;justify-content:center;background:#222;border-radius:6px;flex-shrink:0;color:#666;font-size:1.4rem">${item.type==='channel'?'📺':'🎬'}</div>`;
    const ap=`openFromNotif('${item.id}','${item.type}','${escA(item.name)}','${escA(item.streamUrl||'')}','${escA(item.subUrl||'')}')`;
    d.innerHTML=`${ph}<div style="flex:1;min-width:0"><div style="font-weight:bold;font-size:.88rem;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:4px">${esc(item.name)}</div><div style="font-size:.7rem;color:var(--text-dim);margin-bottom:8px">في <span style="color:#B36BFF">${esc(item.catName||'')}</span></div><button onclick="event.stopPropagation();${ap}" style="background:var(--red);color:#fff;border:none;padding:3px 10px;border-radius:6px;font-size:.74rem;font-weight:700;cursor:pointer">▶ تشغيل</button></div><button onclick="event.stopPropagation();removeNotif('${item.id}')" style="position:absolute;top:8px;left:8px;background:rgba(255,255,255,.07);color:#ccc;border:none;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.65rem">✕</button>`;
    b.appendChild(d);
  });
}
function removeNotif(id){MyNotifsQueue=MyNotifsQueue.filter(n=>String(n.id)!==String(id));localStorage.setItem(PendingNotifsKey,JSON.stringify(MyNotifsQueue));buildNotifPanel();updateNotifBadge();}
function openFromNotif(id,type,name,sUrl='',subUrl=''){
  if(type==='channel')openPlayerChannel({id:id,name:name,stream_url:sUrl,subtitle_url:subUrl});
  else openSeriesEpisodes(id,name);
}

/* ════ PANELS ════ */
function openPanelOverlay(){document.getElementById('panelOverlay').classList.add('show');document.body.style.overflow='hidden';history.pushState({depth:'panel'},'');}
function closePanelOverlay(){document.getElementById('panelOverlay').classList.remove('show');document.body.style.overflow='';}
function closeAllPanels(){['favPanel','m3uPanel','notifPanel','epPanel'].forEach(id=>document.getElementById(id)?.classList.remove('open'));closePanelOverlay();}
function toggleFavPanel(){const p=document.getElementById('favPanel');const o=p.classList.toggle('open');if(o){openPanelOverlay();buildFavPanel();}else closePanelOverlay();}
function toggleNotifPanel(){const p=document.getElementById('notifPanel');const o=p.classList.toggle('open');if(o){openPanelOverlay();buildNotifPanel();}else closePanelOverlay();}
function toggleM3UPanel(){PL.m3uPanelOpen=!PL.m3uPanelOpen;document.getElementById('m3uPanel').classList.toggle('open',PL.m3uPanelOpen);if(PL.m3uPanelOpen)history.pushState({depth:'panel'},'');}
function toggleEpPanel(){PL.epPanelOpen=!PL.epPanelOpen;document.getElementById('epPanel').classList.toggle('open',PL.epPanelOpen);if(PL.epPanelOpen)history.pushState({depth:'panel'},'');}
window.addEventListener('scroll',()=>document.getElementById('navbar').classList.toggle('scrolled',window.scrollY>10),{passive:true});

/* ════ FORMAT ════ */
function detectFmt(url){
  const c=(url||'').split('?')[0].toLowerCase().trim();
  if(c.endsWith('.m3u8')||c.endsWith('.m3u'))return 'hls';
  if(c.endsWith('.mpd'))return 'dash';
  if(c.endsWith('.flv'))return 'flv';
  if(c.endsWith('.mp4')||c.endsWith('.m4v'))return 'mp4';
  if(c.endsWith('.mkv'))return 'mkv';
  if(c.endsWith('.webm'))return 'webm';
  if(c.endsWith('.ts')||c.endsWith('.mts'))return 'ts';
  return 'hls';
}
function fmtLabel(url){return{hls:'HLS',dash:'DASH',flv:'FLV',mp4:'MP4',mkv:'MKV',webm:'WEBM',ts:'TS'}[detectFmt(url)]||'HLS';}
function isLiveFormat(url){return['hls','dash','flv','ts'].includes(detectFmt(url));}

/* ════ HELPERS ════ */
function esc(s){const d=document.createElement('div');d.textContent=String(s||'');return d.innerHTML;}
function escA(s){return String(s||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"').replace(/\n/g,'\\n');}
function toast(msg){
  const c=document.getElementById('toastContainer'),t=document.createElement('div');
  t.className='toast';t.textContent=msg;
  c.appendChild(t);
  setTimeout(()=>{t.classList.add('out');t.addEventListener('animationend',()=>t.remove());},3200);
}

/* ════ TMDB ════ */
/* [أمان] المفتاح لم يعد يصل للمتصفح إطلاقاً — كل الطلبات عبر وكيل الخادم. */
async function showTmdbInfoClient(query,defaultType){
  const modal=document.getElementById('tmdbInfoM');const body=document.getElementById('tmdbInfoBody');
  modal.classList.add('open');document.body.style.overflow='hidden';
  body.innerHTML='<div style="text-align:center;padding:40px;color:var(--text-muted)">جاري الجلب...</div>';
  try{
    const cq=query.replace(/(1080p|720p|4k|fhd|hd|ar|en)/gi,'').trim();
    const sd=await(await fetch(`index.php?tmdb_proxy=search&q=${encodeURIComponent(cq)}`)).json();
    if(sd.error==='disabled'){body.innerHTML='<div style="text-align:center;padding:40px;color:var(--text-muted)">ميزة التفاصيل غير مفعلة</div>';return;}
    if(sd.error==='bad key'){body.innerHTML='<div style="text-align:center;padding:40px;color:#ff4d57">مفتاح API غير صحيح</div>';return;}
    if(!sd.results||!sd.results.length){body.innerHTML='<div style="text-align:center;padding:40px;color:var(--text-muted)">لم يتم العثور على معلومات</div>';return;}
    const item=sd.results.find(i=>i.media_type==='movie'||i.media_type==='tv')||sd.results[0];
    const type=(item.media_type||defaultType)==='movie'?'movie':'tv';
    let d=await(await fetch(`index.php?tmdb_proxy=detail&type=${type}&id=${encodeURIComponent(item.id)}`)).json();
    if(!d.overview){
      const en=await(await fetch(`index.php?tmdb_proxy=detail&type=${type}&id=${encodeURIComponent(item.id)}&lang=en`)).json();
      d.overview=en.overview;
    }
    const title=d.title||d.name||cq;
    /* [أمان] بيانات TMDB خارجية — تُهرَّب قبل الإدراج لمنع XSS.
       poster_path يُبنى من رقم/مسار TMDB فقط، ونتحقق من شكله. */
    const posterOk=typeof d.poster_path==='string'&&/^\/[A-Za-z0-9._-]+$/.test(d.poster_path);
    const poster=posterOk?`https://image.tmdb.org/t/p/w300${d.poster_path}`:'';
    const year=String(d.release_date||d.first_air_date||'').substring(0,4);
    const rating=d.vote_average?Number(d.vote_average).toFixed(1):'—';
    const genres=(d.genres||[]).map(g=>`<span class="tmdb-genre-badge">${esc(g.name)}</span>`).join(' ');
    body.innerHTML=`<div class="tmdb-info-wrap">${poster?`<img src="${esc(poster)}" class="tmdb-info-poster">`:''}<div class="tmdb-info-details"><div class="tmdb-info-title">${esc(title)} ${year?'('+esc(year)+')':''}</div><div class="tmdb-info-meta"><span style="color:#f5c518;font-weight:bold">★ ${esc(rating)}</span></div><div style="margin-bottom:12px">${genres}</div><div class="tmdb-info-overview">${esc(d.overview||'لا توجد قصة متوفرة')}</div></div></div>`;
  }catch(e){body.innerHTML='<div style="text-align:center;padding:40px;color:#ff4d57">خطأ في الاتصال</div>';}
}
function closeTmdbModal(){document.getElementById('tmdbInfoM').classList.remove('open');document.body.style.overflow='';}
document.getElementById('tmdbInfoM').addEventListener('click',function(e){if(e.target===this)closeTmdbModal();});

/* ════ LOAD HOME ════ */
const DISABLED_CATEGORY_IDS = <?php echo json_encode($disabled_category_ids); ?>;
const DISABLED_CHANNEL_IDS = <?php echo json_encode($disabled_channel_ids); ?>;
const HIDE_MOST_WATCHED = <?php echo $hide_most_watched ? 'true' : 'false'; ?>;
const HIDE_SUGGESTIONS  = <?php echo $hide_suggestions ? 'true' : 'false'; ?>;
async function loadAndBuildNetflixHome(){
  if(App.license){
    document.getElementById('netflixStyleSliders').innerHTML='<div style="text-align:center;padding:60px 20px;color:var(--text-muted)"><p>الرخصة منتهية</p><a href="activate.php" style="display:inline-block;margin-top:16px;padding:10px 24px;background:var(--red);color:#fff;border-radius:99px;font-weight:800">تجديد الرخصة</a></div>';
    return;
  }
  try{
    const catRes=await fetch('api.php?action=all_content');
    const catData=await catRes.json();
    App.cats=(catData.categories||[]).filter(c=>!DISABLED_CATEGORY_IDS.includes(parseInt(c.id)));
    renderCategoryNavBar();
    const wrap=document.getElementById('netflixStyleSliders');
    wrap.innerHTML='';
    if(!App.cats.length){wrap.innerHTML='<div style="padding:40px;text-align:center;color:var(--text-muted)">لا يوجد محتوى متاح</div>';return;}
    App.cats.forEach(c=>{
      const seriesCnt=parseInt(c.series_count||0);
      const channelCnt=parseInt(c.channel_count||0);
      if(channelCnt>0&&seriesCnt===0){buildSliderRow(wrap,c,'channels',channelCnt);}
      else if(seriesCnt>0&&channelCnt===0){buildSliderRow(wrap,c,'series',seriesCnt);}
      else if(channelCnt>0&&seriesCnt>0){buildSliderRow(wrap,c,'channels',channelCnt);buildSliderRow(wrap,c,'series',seriesCnt,true);}
      else{buildSliderRow(wrap,c,'channels',6);}
    });
    /* استعادة ما كان المستخدم يشاهده قبل التحديث (مسلسل/قسم/بحث/فيديو).
       نبدأها قبل انتظار صفوف الرئيسية، فلا يرى المستخدم الرئيسية ثم قفزة. */
    const _hadState = !!(window.location.hash || '').replace(/^#/,'');
    const _restorePromise = (async ()=>{
      try{ return await shsRestoreFromHash(); }
      catch(e){ console.error('restore:', e); return false; }
    })();

    if(_hadState){
      // نُخفي الرئيسية فوراً حتى لا تومض قبل الاستعادة
      const _hw = document.getElementById('heroWelcome');
      const _ns = document.getElementById('netflixStyleSliders');
      if(_hw) _hw.classList.add('hidden');
      if(_ns) _ns.classList.add('hidden');
      const _ok = await _restorePromise;
      if(!_ok){
        // فشلت الاستعادة (عنصر محذوف مثلاً) — نُعيد الرئيسية بدل ترك شاشة فارغة
        try{ shsSetHash(null); }catch(e){}
        if(_hw) _hw.classList.remove('hidden');
        if(_ns) _ns.classList.remove('hidden');
      }
      fetchAllRows();          // نبني الرئيسية في الخلفية للرجوع إليها لاحقاً
    } else {
      await fetchAllRows();
      await _restorePromise;
    }

    const syncDelay=window.requestIdleCallback||(fn=>setTimeout(fn,4000));
    syncDelay(()=>syncNotifications(App.cats));
    // الصفوف المميزة ثانوية — نؤجّلها حتى يهدأ المتصفح فلا تزاحم الرسم الأول
    if(!HIDE_MOST_WATCHED||!HIDE_SUGGESTIONS){
      const idle=window.requestIdleCallback||(fn=>setTimeout(fn,1200));
      idle(()=>loadFeaturedRows(wrap));
    }
  }catch(e){
    document.getElementById('netflixStyleSliders').innerHTML=`<div style="padding:40px;text-align:center;color:var(--text-muted)"><p>خطأ في الاتصال</p><button onclick="loadAndBuildNetflixHome()" style="margin-top:16px;padding:10px 24px;background:var(--red);color:#fff;border:none;border-radius:99px;cursor:pointer;font-family:inherit">إعادة المحاولة</button></div>`;
  }
}

function buildSliderRow(wrap,c,type,count,isSubRow){
  const rowId=c.id+'_'+type;
  const isVOD=(type==='series');
  const rowLabel=isSubRow?(isVOD?c.name+' — أفلام':c.name+' — قنوات'):c.name;
  const skelN=Math.min(8,Math.max(4,count));
  const row=document.createElement('div');
  row.className='netflix-slider-row';
  row.dataset.rowId=rowId;row.dataset.catId=c.id;row.dataset.type=type;row.dataset.loaded='0';
  row.innerHTML=`
    <div class="slider-header">
      <div class="slider-title">
        <div class="slider-title-icon">${isVOD?'🎬':'📡'}</div>
        ${esc(rowLabel)}
        <span class="slider-badge" id="badge-${rowId}">${count>0?count+(isVOD?' عمل':' قناة'):'...'}</span>
      </div>
    </div>
    <div class="slider-scroll-mask" onmouseenter="if(window.shsUpdateRowArrows) window.shsUpdateRowArrows(this.querySelector('.slider-cards-wrapper'))">
      <button class="shs-row-arrow shs-left shs-show" aria-label="السابق" onclick="window.shsScrollRow(this, -1)"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>
      <div class="slider-cards-wrapper" id="slider-lane-${rowId}" onscroll="if(window.shsUpdateRowArrows) window.shsUpdateRowArrows(this)">
        ${Array(skelN).fill('<div class="skeleton" style="height:200px;border-radius:10px"></div>').join('')}
      </div>
      <button class="shs-row-arrow shs-right shs-show" aria-label="التالي" onclick="window.shsScrollRow(this, 1)"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>
    </div>`;
  wrap.appendChild(row);
}

async function fetchAllRows(){
  const allRows=Array.from(document.querySelectorAll('.netflix-slider-row[data-loaded="0"]'));
  if(!allRows.length)return;
  const INITIAL=3;
  const firstBatch=allRows.slice(0,INITIAL);
  const restRows=allRows.slice(INITIAL);
  await Promise.all(firstBatch.map(row=>fetchSingleRow(row)));
  if(restRows.length){
    // تتبع الـ observer لإمكانية قطع الاتصال
    const obs=new IntersectionObserver((entries,ob)=>{
      entries.forEach(entry=>{
        if(!entry.isIntersecting)return;
        if(entry.target.dataset.loaded!=='0')return;
        ob.unobserve(entry.target);
        fetchSingleRow(entry.target).then(()=>{
          // قطع الاتصال إذا تحمّلت كل الصفوف
          const remaining=document.querySelectorAll('.netflix-slider-row[data-loaded="0"]');
          if(!remaining.length)ob.disconnect();
        });
      });
    },{rootMargin:'400px 0px'});
    restRows.forEach(row=>obs.observe(row));
  }
}

async function fetchSingleRow(row){
  const rowId=row.dataset.rowId;
  const catId=row.dataset.catId;
  const type=row.dataset.type;
  const isVOD=(type==='series');
  const laneEl=document.getElementById('slider-lane-'+rowId);
  if(!laneEl)return;
  row.dataset.loaded='1';
  try{
    const action=isVOD?'series':'channels';
    const r=await fetch(`api.php?action=${action}&category_id=${encodeURIComponent(catId)}`);
    if(!r.ok)throw new Error('HTTP '+r.status);
    const payload=await r.json();
    const items=isVOD?(payload.series||[]):(payload.channels||[]);
    if(!items.length){row.remove();return;}
    items.forEach(k=>{ _shsAddContent(k, isVOD?'series':'channel'); });
    const badge=document.getElementById('badge-'+rowId);
    if(badge)badge.textContent=items.length+(isVOD?' عمل':' قناة');
    renderItemsIntoSliderDOM(laneEl,items,type);
  }catch(err){
    if(laneEl)laneEl.innerHTML='<div style="color:var(--text-muted);padding:16px;font-size:.85rem;direction:rtl">تعذر التحميل</div>';
    row.dataset.loaded='0';
  }
}

/* ════ FEATURED ROWS — الأكثر مشاهدة + مقترحات قد تعجبك ════ */
function buildFeaturedRow(wrap,rowId,label,icon){
  const row=document.createElement('div');
  row.className='netflix-slider-row';
  row.dataset.rowId=rowId;
  row.innerHTML=`
    <div class="slider-header">
      <div class="slider-title">
        <div class="slider-title-icon">${icon}</div>
        ${esc(label)}
        <span class="slider-badge" id="badge-${rowId}">...</span>
      </div>
    </div>
    <div class="slider-scroll-mask" onmouseenter="if(window.shsUpdateRowArrows) window.shsUpdateRowArrows(this.querySelector('.slider-cards-wrapper'))">
      <button class="shs-row-arrow shs-left shs-show" aria-label="السابق" onclick="window.shsScrollRow(this, -1)"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>
      <div class="slider-cards-wrapper" id="slider-lane-${rowId}" onscroll="if(window.shsUpdateRowArrows) window.shsUpdateRowArrows(this)">
        ${Array(6).fill('<div class="skeleton" style="height:200px;border-radius:10px"></div>').join('')}
      </div>
      <button class="shs-row-arrow shs-right shs-show" aria-label="التالي" onclick="window.shsScrollRow(this, 1)"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>
    </div>`;
  wrap.prepend(row);
  return row;
}
function fillFeaturedRow(rowId,items,cardType){
  const row=document.querySelector(`.netflix-slider-row[data-row-id="${rowId}"]`);
  const laneEl=document.getElementById('slider-lane-'+rowId);
  if(!row||!laneEl)return;
  if(!items.length){row.remove();return;}
  const badge=document.getElementById('badge-'+rowId);
  if(badge)badge.textContent=items.length+(cardType==='series'?' عمل':' عنصر');
  renderItemsIntoSliderDOM(laneEl,items,cardType==='series'?'series':'channels');
}
function shuffleArr(arr){
  const a=arr.slice();
  for(let i=a.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[a[i],a[j]]=[a[j],a[i]];}
  return a;
}
async function loadFeaturedRows(wrap){
  if(!App.cats||!App.cats.length)return;
  try{
    /* ── تحسين أداء: لا نجلب كل المكتبة ──
       الصفوف المميزة تحتاج ٢٠ عنصراً مخلوطاً + ١٥ الأكثر مشاهدة فقط.
       كنا نجلب كل عنصر في كل قسم (عشرات الآلاف بعد استيراد Xtream) ثم نرمي ٩٩٪ منه.
       الآن: نتخطى الأقسام الفارغة، ونحدّ عدد الأقسام، وننفّذ على دفعات صغيرة. */
    const MAX_CATS = 8;      // أقصى عدد أقسام نقرأ منها للصفوف المميزة
    const BATCH    = 3;      // عدد الطلبات المتوازية في الدفعة الواحدة

    const picks = [];
    App.cats.forEach(c=>{
      const nCh = parseInt(c.channel_count||0);
      const nSr = parseInt(c.series_count ||0);
      if(nCh > 0) picks.push({cat:c, type:'channel', weight:nCh});
      if(nSr > 0) picks.push({cat:c, type:'series',  weight:nSr});
    });
    // نفضّل الأقسام الأغنى محتوى، ثم نقصّ القائمة
    picks.sort((a,b)=>b.weight-a.weight);
    const chosen = shuffleArr(picks.slice(0, MAX_CATS * 2)).slice(0, MAX_CATS);

    const results = [];
    for(let i=0; i<chosen.length; i+=BATCH){
      const slice = chosen.slice(i, i+BATCH);
      const batch = await Promise.all(slice.map(p=>{
        const act = p.type==='channel' ? 'channels' : 'series';
        return fetch(`api.php?action=${act}&category_id=${encodeURIComponent(p.cat.id)}`)
          .then(r=>r.json())
          .then(d=>({type:p.type, items:(p.type==='channel' ? (d.channels||[]) : (d.series||[]))}))
          .catch(()=>({type:p.type, items:[]}));
      }));
      results.push(...batch);
    }
    const allChannels=[],allSeries=[];
    const seenCh=new Set(),seenSr=new Set();
    results.forEach(res=>{
      res.items.forEach(item=>{
        if(res.type==='channel'){ if(!seenCh.has(item.id)){seenCh.add(item.id);allChannels.push(item);} }
        else{ if(!seenSr.has(item.id)){seenSr.add(item.id);allSeries.push(item);} }
      });
    });

    // ── مقترحات قد تعجبك: خليط عشوائي يتغيّر كل زيارة (يُبنى أولاً ليظهر أسفل الأكثر مشاهدة) ──
    if(!HIDE_SUGGESTIONS && (allChannels.length+allSeries.length)>0){
      const mixed=shuffleArr([
        ...allChannels.map(x=>({...x,_ftype:'channels'})),
        ...allSeries.map(x=>({...x,_ftype:'series'}))
      ]).slice(0,20);
      if(mixed.length){
        buildFeaturedRow(wrap,'suggestions','مقترحات قد تعجبك','✨');
        const laneEl=document.getElementById('slider-lane-suggestions');
        const badge=document.getElementById('badge-suggestions');
        if(badge)badge.textContent=mixed.length+' عنصر';
        if(laneEl){
          // كل عنصر بنوعه الصحيح (channels/series) حتى تُبنى البطاقة المناسبة له بترتيب الخلط نفسه
          const frag=document.createDocumentFragment();
          mixed.forEach((item,idx)=>{
            const tmp=document.createElement('div');
            renderItemsIntoSliderDOM(tmp,[item],item._ftype);
            const card=tmp.firstElementChild;
            if(card){card.style.animationDelay=(idx*.03)+'s';frag.appendChild(card);}
          });
          laneEl.innerHTML='';
          laneEl.appendChild(frag);
        }
      }
    }

    // ── الأكثر مشاهدة: صف قنوات + صف مسلسلات منفصلين، الأعلى views_count ──
    if(!HIDE_MOST_WATCHED){
      const topChannels=allChannels.slice().sort((a,b)=>parseInt(b.views_count||0)-parseInt(a.views_count||0)).slice(0,15);
      const topSeries=allSeries.slice().sort((a,b)=>parseInt(b.views_count||0)-parseInt(a.views_count||0)).slice(0,15);
      if(topSeries.length){
        buildFeaturedRow(wrap,'most_watched_series','الأكثر مشاهدة — مسلسلات وأفلام','🔥');
        fillFeaturedRow('most_watched_series',topSeries,'series');
      }
      if(topChannels.length){
        buildFeaturedRow(wrap,'most_watched_channels','الأكثر مشاهدة — قنوات','🔥');
        fillFeaturedRow('most_watched_channels',topChannels,'channels');
      }
    }
  }catch(e){}
}

/* ════ RENDER CARDS — DocumentFragment لأداء أفضل ════ */
/* أقصى عدد بطاقات تُبنى في الشريط الأفقي الواحد.
   بناء ٨٠٠٠ بطاقة لقسم أفلام كامل كان يخنق الصفحة، بينما لا يرى المستخدم سوى ١٠ منها.
   الباقي يُفتح عبر «عرض الكل» في صفحة القسم. */
const MAX_CARDS_PER_ROW = 40;

/* ════ عرض تدريجي (Progressive Rendering) ════
   المشكلة: صفحة القسم والبحث تمرران noCap=true، فتُبنى كل العناصر دفعة واحدة.
   قسم فيه ٥٠٠٠ عنصر = ٥٠٠٠ بطاقة × ~١٢ عنصر DOM = تجميد للتبويب عدة ثوانٍ.
   الحل: نبني أول دفعة فوراً، ثم نكمل الباقي عند التمرير (بلا حذف أي عنصر). */
const FIRST_PAINT_CARDS = 60;   // عدد البطاقات المبنية فوراً
const CHUNK_CARDS       = 60;   // حجم الدفعة التالية عند الوصول للنهاية

/* فهرس المفضلة كـ Set: البحث O(1) بدل .some() لكل بطاقة (O(n×m)).
   يُعاد بناؤه عند أي تعديل على المفضلة. */
let _favChSet = new Set(), _favSrSet = new Set();
function rebuildFavSets(){
  try{
    _favChSet = new Set((MyFavs.channels||[]).map(f=>String(f.id)));
    _favSrSet = new Set((MyFavs.series  ||[]).map(f=>String(f.id)));
  }catch(e){ _favChSet=new Set(); _favSrSet=new Set(); }
}
rebuildFavSets();

function renderItemsIntoSliderDOM(sliderDom,items,cardType,highlightStr='',noCap){
  if(cardType==='channels'&&items&&items.length){
    // إخفاء القنوات غير النشطة (إن أرسل الـ API حقل is_active)
    items=items.filter(it=>it.is_active===undefined||it.is_active===null||parseInt(it.is_active)!==0);
  }
  if(!items||!items.length){
    sliderDom.innerHTML='<div style="color:var(--text-muted);padding:16px;font-size:.82rem;grid-column:1/-1;text-align:center">لا يوجد محتوى</div>';
    return;
  }
  // قصّ العدد المبني في الأشرطة الأفقية فقط (صفحة القسم والبحث تمرران noCap)
  if(!noCap && items.length > MAX_CARDS_PER_ROW) items = items.slice(0, MAX_CARDS_PER_ROW);

  // فهرس المفضلة يُحدّث مرة واحدة لكل عملية رسم بدل بحث خطي لكل بطاقة
  rebuildFavSets();

  // بناء بطاقة واحدة (نفس منطق البناء السابق تماماً، بلا أي حذف)
  function _buildCard(item, idx){
    const div=document.createElement('div');
    div.style.animationDelay=(Math.min(idx,20)*.03)+'s';
    if(cardType==='series'){
      const isFav=_favSrSet.has(String(item.id));
      div.className='sr-card';
      // صورة البوستر
      const poster=document.createElement('div');
      poster.className='sr-poster';
      if(item.poster_url){
        const img=document.createElement('img');
        img.src=esc(item.poster_url);
        img.loading='lazy';
        img.decoding='async';
        img.alt=esc(item.name);
        img.onerror=function(){this.style.display='none';};
        poster.appendChild(img);
      }else{
        poster.innerHTML='<span style="font-size:1.8rem;color:#2e2e2e">🎬</span>';
      }
      poster.innerHTML+='<div class="ch-play-btn">▶</div>';
      // info
      const info=document.createElement('div');
      info.className='sr-info';
      const nameEl=document.createElement('div');
      nameEl.className='sr-name';
      nameEl.title=item.name;
      if(highlightStr){
        const terms = highlightStr.split(' ').filter(Boolean).map(w => w.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\\\$&')).join('|');
        if(terms){
          nameEl.innerHTML=item.name.replace(new RegExp(`(${terms})`, 'gi'), '<mark style="background:var(--red);color:#fff;border-radius:2px;padding:0 2px">$1</mark>');
        }else{
          nameEl.textContent=item.name;
        }
      } else {
        nameEl.textContent=item.name;
      }
      const actions=document.createElement('div');
      actions.style.cssText='display:flex;align-items:center;gap:4px;flex-wrap:wrap';
      const btnInfo=document.createElement('button');
      btnInfo.className='info-action-btn';
      btnInfo.title='معلومات';
      btnInfo.textContent='ℹ';
      btnInfo.onclick=e=>{e.stopPropagation();showTmdbInfoClient(item.name,'tv');};
      const btnFav=document.createElement('button');
      btnFav.className='info-action-btn'+(isFav?' active-fav':'');
      btnFav.textContent='♥';
      btnFav.onclick=e=>{e.stopPropagation();toggleMyFav(item.id,item.name,'series',item.poster_url||'');};
      const badge=document.createElement('span');
      badge.style.cssText='font-size:.6rem;color:var(--text-muted);background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);padding:1px 5px;border-radius:3px';
      badge.textContent='VOD';
      actions.append(btnInfo,btnFav,badge);
      info.append(nameEl,actions);
      div.append(poster,info);
      div.dataset.prefetchSeries=item.id;
      div.addEventListener('click',()=>openSeriesEpisodes(item.id,item.name,item.poster_url||''));
    }else{
      const isFav=_favChSet.has(String(item.id));
      const isLive=isLiveFormat(item.stream_url||'');
      const fmt=fmtLabel(item.stream_url||'');
      div.className='ch-card';
      // thumb
      const thumb=document.createElement('div');
      thumb.className='ch-thumb';
      if(item.logo_url){
        const img=document.createElement('img');
        img.src=esc(item.logo_url);
        img.loading='lazy';
        img.decoding='async';
        img.alt=esc(item.name);
        img.onerror=function(){this.style.display='none';};
        thumb.appendChild(img);
      }else{
        thumb.innerHTML='<span style="font-size:1.8rem;color:#2e2e2e">📺</span>';
      }
      const liveBadge=document.createElement('span');
      liveBadge.className='ch-live-badge';
      liveBadge.textContent=isLive?'LIVE':fmt;
      const fmtBadge=document.createElement('span');
      fmtBadge.className='ch-fmt-badge';
      fmtBadge.textContent=fmt;
      thumb.innerHTML+='<div class="ch-play-btn">▶</div>';
      thumb.prepend(liveBadge);
      thumb.appendChild(fmtBadge);
      if(item.quality){
        const qualityBadge=document.createElement('span');
        qualityBadge.className='ch-quality-badge';
        qualityBadge.textContent=item.quality;
        thumb.appendChild(qualityBadge);
      }
      // info
      const info=document.createElement('div');
      info.className='ch-info';
      const nameEl=document.createElement('div');
      nameEl.className='ch-name';
      nameEl.title=item.name;
      if(highlightStr){
        const terms = highlightStr.split(' ').filter(Boolean).map(w => w.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\\\$&')).join('|');
        if(terms){
          nameEl.innerHTML=item.name.replace(new RegExp(`(${terms})`, 'gi'), '<mark style="background:var(--red);color:#fff;border-radius:2px;padding:0 2px">$1</mark>');
        }else{
          nameEl.textContent=item.name;
        }
      } else {
        nameEl.textContent=item.name;
      }
      const actions=document.createElement('div');
      actions.style.cssText='display:flex;align-items:center;gap:4px;flex-wrap:wrap';
      const btnInfo=document.createElement('button');
      btnInfo.className='info-action-btn';
      btnInfo.title='معلومات';
      btnInfo.textContent='ℹ';
      btnInfo.onclick=e=>{e.stopPropagation();showTmdbInfoClient(item.name,'movie');};
      const btnFav=document.createElement('button');
      btnFav.className='info-action-btn'+(isFav?' active-fav':'');
      btnFav.textContent='♥';
      btnFav.onclick=e=>{e.stopPropagation();toggleMyFav(item.id,item.name,'channels',item.logo_url||'',item.stream_url||'',item.subtitle_url||'');};
      const badge=document.createElement('span');
      badge.style.cssText='font-size:.6rem;color:var(--text-muted);background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);padding:1px 5px;border-radius:3px';
      badge.textContent=isLive?'LIVE':fmt;
      actions.append(btnInfo,btnFav,badge);
      info.append(nameEl,actions);
      div.append(thumb,info);
      div.addEventListener('click',()=>openPlayerChannel(item));
    }
    return div;
  }

  sliderDom.innerHTML='';

  // ── الرسم على دفعات ──
  // الدفعة الأولى فوراً (المستخدم يرى محتوى خلال أجزاء من الثانية)،
  // والباقي يُضاف عند اقتراب التمرير من النهاية — بلا فقدان أي عنصر.
  let _rendered = 0;
  const _total = items.length;

  function _appendChunk(n){
    if(_rendered >= _total) return;
    const end = Math.min(_rendered + n, _total);
    const frag = document.createDocumentFragment();
    for(let i=_rendered; i<end; i++) frag.appendChild(_buildCard(items[i], i));
    sliderDom.appendChild(frag);
    _rendered = end;
    if(_rendered >= _total && sliderDom.__shsChunkCleanup) sliderDom.__shsChunkCleanup();
  }

  // تنظيف أي مراقب سابق على نفس الحاوية (عند إعادة الرسم)
  if(sliderDom.__shsChunkCleanup){ try{ sliderDom.__shsChunkCleanup(); }catch(e){} }

  _appendChunk(noCap ? FIRST_PAINT_CARDS : _total);

  if(_rendered < _total){
    // حارس في نهاية القائمة: عند ظهوره نبني الدفعة التالية
    const sentinel=document.createElement('div');
    sentinel.style.cssText='grid-column:1/-1;height:1px';
    sliderDom.appendChild(sentinel);
    const io=new IntersectionObserver(entries=>{
      entries.forEach(en=>{
        if(!en.isIntersecting) return;
        _appendChunk(CHUNK_CARDS);
        // نُبقي الحارس آخر عنصر دائماً
        if(_rendered < _total) sliderDom.appendChild(sentinel);
        else sentinel.remove();
      });
    },{rootMargin:'600px'});
    io.observe(sentinel);
    sliderDom.__shsChunkCleanup=function(){
      try{ io.disconnect(); }catch(e){}
      try{ sentinel.remove(); }catch(e){}
      sliderDom.__shsChunkCleanup=null;
    };
  }
}

/* ════ EPISODES ════ */
async function openSeriesEpisodes(seriesId,seriesName,seriesPoster){
  App.currentSeriesId=seriesId;App.currentSeriesName=seriesName;
  shsSetHash({s:seriesId});                       // يبقى بعد التحديث
  /* [SHS-EPPOSTER] تحديد بوستر المسلسل: من الوسيط، وإلا نبحث عنه في المحتوى المخزّن */
  App.currentSeriesPoster=seriesPoster||'';
  if(!App.currentSeriesPoster){
    try{
      var _f=(App.allContent||[]).find(function(x){return String(x.id)===String(seriesId)&&(x.poster_url||x._ftype==='series'||x.ftype==='series');});
      if(_f&&_f.poster_url)App.currentSeriesPoster=_f.poster_url;
    }catch(e){}
  }
  document.getElementById('netflixStyleSliders').classList.add('hidden');
  document.getElementById('heroWelcome').classList.add('hidden');
  document.getElementById('searchViewSection').classList.add('hidden');
  document.getElementById('categoryViewSection').classList.add('hidden');
  document.getElementById('epSection').classList.remove('hidden');
  document.getElementById('epSectionTitle').textContent=seriesName;
  const grid=document.getElementById('epGrid');
  const loading=document.getElementById('epLoading');
  const empty=document.getElementById('epEmpty');
  grid.innerHTML='';loading.classList.remove('hidden');empty.classList.add('hidden');
  window.scrollTo({top:0,behavior:'smooth'});
  try{
    const r=await fetch(`api.php?action=episodes&series_id=${encodeURIComponent(seriesId)}`);
    const d=await r.json();App.allEpisodes=d.episodes||[];
    /* [SHS-EPPOSTER] لو أرجع الـ API بوستر المسلسل، نستخدمه احتياطياً */
    if(!App.currentSeriesPoster){
      var sp=d.series_poster||d.poster_url||(d.series&&d.series.poster_url)||'';
      if(sp)App.currentSeriesPoster=sp;
    }
    loading.classList.add('hidden');
    if(!App.allEpisodes.length){empty.classList.remove('hidden');}else renderEpisodes(App.allEpisodes);
    fetch('api.php?action=increment_view&id='+seriesId+'&type=series').catch(()=>{});
  }catch(e){loading.classList.add('hidden');grid.innerHTML='<div style="color:var(--red);padding:20px">تعذر تحميل الحلقات</div>';}
}
function renderEpisodes(eps){
  const g=document.getElementById('epGrid');g.innerHTML='';
  eps.forEach((ep,i)=>{
    const dv=document.createElement('div');dv.className='ep-card';dv.style.animationDelay=(i*.05)+'s';
    /* [SHS-EPPOSTER] الأولوية لصورة الحلقة، وإلا بوستر المسلسل الأصلي بدل الخلفية الفارغة */
    const epImg=ep.image_url||ep.thumbnail_url||ep.cover_url||ep.poster_url||'';
    const fallback=App.currentSeriesPoster||'';
    const finalImg=epImg||fallback;
    const usingFallback=(!epImg&&!!fallback);
    const imgH=finalImg?`<img class="ep-thumb-video${usingFallback?' ep-thumb-fallback':''}" src="${esc(finalImg)}" loading="lazy" onerror="this.style.display='none'">`:'';
    const title=ep.title||('حلقة '+ep.episode_number);
    dv.innerHTML=`<div class="ep-thumb-area">${imgH}<span class="ep-thumb-icon">▶</span><div class="ep-num-badge">حلقة ${esc(ep.episode_number)}</div></div>
      <div class="ep-info-box">
        <div style="color:#f0f0f0;font-weight:700;font-size:clamp(0.7rem,2.2vw,0.88rem);line-height:1.4;height:2.8em;margin-bottom:5px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;text-overflow:ellipsis" title="${esc(title)}">${esc(title)}</div>
        <div class="ep-date-text">📅 ${ep.added||ep.date||'مشاهدة'}</div>
      </div>`;
    dv.addEventListener('click',()=>openPlayerEpisode(i));
    g.appendChild(dv);
  });
}
function backFromEpisodesToHome(){
  document.getElementById('epSection').classList.add('hidden');
  /* غادرنا صفحة الحلقات — نُنظّف معرّف العمل حتى لا يعيد التحديث فتحه */
  App.currentSeriesId=null; App.currentSeriesName=''; App.currentSeriesPoster='';
  const searchQ = document.getElementById('searchInput') ? document.getElementById('searchInput').value.trim() : '';
  if(searchQ.length > 0){
    document.getElementById('searchViewSection').classList.remove('hidden');
    try{ shsSetHash({q:searchQ.toLowerCase()}); }catch(e){}
  }else if(App.currentCategoryView){
    document.getElementById('categoryViewSection').classList.remove('hidden');
    try{ shsSetHash({c:App.currentCategoryView.id}); }catch(e){}
  }else{
    document.getElementById('netflixStyleSliders').classList.remove('hidden');
    document.getElementById('heroWelcome').classList.remove('hidden');
    try{ shsSetHash(null); }catch(e){}   // رجوع للرئيسية → عنوان نظيف
  }
  window.scrollTo({top:0,behavior:'smooth'});
}

/* ════ SEARCH ════ */
let searchTimer;
function handleSearch(){
  clearTimeout(searchTimer);
  searchTimer=setTimeout(async ()=>{
    const q=document.getElementById('searchInput').value.trim().toLowerCase();
    if(q.length<1){clearSearchAndGoHome();return;}
    document.getElementById('netflixStyleSliders').classList.add('hidden');
    document.getElementById('epSection').classList.add('hidden');
    document.getElementById('heroWelcome').classList.add('hidden');
    document.getElementById('categoryViewSection').classList.add('hidden');
    document.getElementById('searchViewSection').classList.remove('hidden');
    const grid=document.getElementById('searchGrid');
    const empty=document.getElementById('searchEmpty');
    const badge=document.getElementById('searchCountBadge');
    /* ── لا نحجب البحث بانتظار المكتبة كاملة ──
       سابقاً: await _shsEnsureAllContent() كان يجلب عشرات آلاف الصفوف قبل إظهار أي نتيجة.
       الآن: نبحث فوراً فيما هو محمّل، ونُكمل التحميل في الخلفية ثم نعيد البحث تلقائياً. */
    badge.textContent='جاري البحث...';
    if(!_shsAllContentLoaded){
      const qAtStart = document.getElementById('searchInput').value.trim().toLowerCase();
      _shsEnsureAllContent().then(()=>{
        // أعِد العرض فقط إن كان المستخدم ما زال يبحث بنفس الكلمة
        const still = document.getElementById('searchInput').value.trim().toLowerCase();
        if(still && still === qAtStart) _shsRenderSearch(still);
      });
    }
    const qNow=document.getElementById('searchInput').value.trim().toLowerCase();
    if(qNow.length<1){clearSearchAndGoHome();return;}
    shsSetHash({q:qNow});                          // البحث يبقى بعد التحديث
    _shsRenderSearch(qNow);
  },220);
}

/* ── تنفيذ البحث والعرض (مفصول ليُعاد استدعاؤه بعد اكتمال التحميل الخلفي) ── */
const MAX_SEARCH_RESULTS = 120;   // نبني ١٢٠ بطاقة كحد أقصى بدل آلاف

function _shsRenderSearch(qNow){
  const grid  = document.getElementById('searchGrid');
  const empty = document.getElementById('searchEmpty');
  const badge = document.getElementById('searchCountBadge');
  if(!grid || !empty || !badge) return;

  const nq    = _shsNormalizeSearch(qNow);
  const words = nq.split(' ').filter(Boolean);
  const scored= [];

  for(let i=0;i<App.allContent.length;i++){
    const v=App.allContent[i];
    /* الاسم المطبّع يُحسب مرة واحدة ويُخزّن على العنصر.
       سابقاً كان _shsNormalizeSearch يعمل على كل عنصر في كل ضغطة مفتاح
       (٣٠ ألف عنصر × ٦ تعبيرات نمطية × حقلين ≈ ٣٦٠ ألف عملية لكل حرف). */
    if(v._nn===undefined) v._nn=_shsNormalizeSearch(v.name||'');
    if(v._nq===undefined) v._nq=_shsNormalizeSearch(v.quality||'');
    const nameN=v._nn, qualN=v._nq;

    let allFound=true;
    for(let w=0;w<words.length;w++){
      if(nameN.indexOf(words[w])===-1 && qualN.indexOf(words[w])===-1){allFound=false;break;}
    }
    if(!allFound)continue;

    let score=1;
    if(nameN===nq)score=4;
    else if(nameN.indexOf(nq)===0)score=3;
    else if(nameN.indexOf(nq)>-1)score=2;
    scored.push({v:v,score:score});
  }

  scored.sort((a,b)=>b.score-a.score || (a.v.name||'').localeCompare(b.v.name||'','ar'));

  const total   = scored.length;
  const shown   = scored.slice(0, MAX_SEARCH_RESULTS).map(x=>x.v);
  const loading = !_shsAllContentLoaded;

  badge.textContent = total
    ? (total > MAX_SEARCH_RESULTS
        ? ('أفضل '+MAX_SEARCH_RESULTS+' من '+total+' نتيجة' + (loading?' — جارٍ البحث في الباقي...':''))
        : (total+' نتيجة' + (loading?' — جارٍ البحث في الباقي...':'')))
    : (loading ? 'جارٍ البحث...' : '0 نتيجة');

  if(shown.length){
    empty.classList.add('hidden'); grid.classList.remove('hidden');
    const channels=shown.filter(x=>x.globalType==='channel');
    const series  =shown.filter(x=>x.globalType==='series');
    grid.innerHTML='';
    if(channels.length){
      const chHeader = document.createElement('h3');
      chHeader.style.cssText = 'grid-column:1/-1;margin:15px 0 10px 0;color:var(--text-muted);font-size:1.15rem;display:flex;align-items:center;gap:8px;';
      chHeader.innerHTML = '<span class="lcn" style="color:var(--red)">▶</span> القنوات والأفلام <span style="font-size:0.8rem;background:rgba(255,255,255,0.1);padding:2px 8px;border-radius:12px;color:#fff;">'+channels.length+'</span>';
      grid.appendChild(chHeader);
      renderItemsIntoSliderDOM(grid,channels,'channels', qNow, true);
    }
    if(series.length){
      const srHeader = document.createElement('h3');
      srHeader.style.cssText = 'grid-column:1/-1;margin:15px 0 10px 0;color:var(--text-muted);font-size:1.15rem;display:flex;align-items:center;gap:8px;';
      srHeader.innerHTML = '<span class="lcn" style="color:var(--red)">🎬</span> المسلسلات <span style="font-size:0.8rem;background:rgba(255,255,255,0.1);padding:2px 8px;border-radius:12px;color:#fff;">'+series.length+'</span>';
      grid.appendChild(srHeader);
      renderItemsIntoSliderDOM(grid,series,'series', qNow, true);
    }
  } else if(!loading){
    grid.classList.add('hidden');
    empty.classList.remove('hidden');
    empty.innerHTML = `<span class="lcn" style="font-size:3rem;margin-bottom:16px;display:block;opacity:.3"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></span><p>لم نجد أي نتائج لـ "<b style="color:var(--text)">${qNow}</b>"</p><p style="font-size:0.85rem;margin-top:8px;opacity:0.7">جرب كلمات أخرى أو تأكد من الإملاء</p>`;
  }
}
/* فهرس مفاتيح مشترك لمنع التكرار في O(1) بدل O(n) لكل عنصر */
const _shsKeys = new Set();
function _shsAddContent(item, type){
  const key = type + '_' + item.id;
  if(_shsKeys.has(key)) return false;
  _shsKeys.add(key);
  App.allContent.push({...item, globalType:type, _key:key});
  return true;
}

/* جلب كل المحتوى من كل الأقسام مرة واحدة (للبحث الشامل) — يُخزّن بعد أول جلب */
let _shsAllContentLoaded=false;
async function _shsEnsureAllContent(){
  if(_shsAllContentLoaded)return;
  if(!App.cats||!App.cats.length)return; // لا أقسام بعد
  try{
    /* دفعات محدودة بدل إطلاق مئات الطلبات دفعة واحدة (كان يخنق المتصفح بعد استيراد Xtream).
       يعمل الآن في الخلفية بلا حجب البحث، فنرفع التوازي قليلاً. */
    const BATCH = 6;
    const jobs = [];
    App.cats.forEach(c=>{
      if(parseInt(c.channel_count||0) > 0) jobs.push({id:c.id, type:'channel', act:'channels'});
      if(parseInt(c.series_count ||0) > 0) jobs.push({id:c.id, type:'series',  act:'series'});
    });

    /* الفهرس المشترك _shsKeys يمنع التكرار في O(1).
       سابقاً كان App.allContent.find() داخل الحلقة → O(n²) يجمّد التبويب مع عشرات الآلاف. */
    for(let i=0; i<jobs.length; i+=BATCH){
      const slice = jobs.slice(i, i+BATCH);
      const res = await Promise.all(slice.map(j=>
        fetch(`api.php?action=${j.act}&category_id=${encodeURIComponent(j.id)}`)
          .then(r=>r.json())
          .then(d=>({type:j.type, items:(j.type==='series' ? (d.series||[]) : (d.channels||[]))}))
          .catch(()=>({type:j.type, items:[]}))
      ));
      res.forEach(r=>{ r.items.forEach(k=>_shsAddContent(k, r.type)); });
      // نترك المتصفح يتنفّس بين الدفعات فلا تتجمّد الواجهة
      await new Promise(r=>setTimeout(r,0));
    }
    _shsAllContentLoaded=true;
  }catch(e){ /* عند الفشل نكمل بالمحتوى المتاح */ }
}
/* تطبيع نص البحث العربي: توحيد الهمزات/الألف/التاء المربوطة + إزالة التشكيل */
function _shsNormalizeSearch(s){
  return (s||'').toString().toLowerCase()
    .replace(/[\u0623\u0625\u0622\u0627]/g,'ا')
    .replace(/\u0629/g,'ه')
    .replace(/\u0649/g,'ي')
    .replace(/[\u064B-\u065F\u0670]/g,'')
    .replace(/\u0640/g,'')
    .replace(/\s+/g,' ').trim();
}

/* ══════════════════════════════════════════════════════════════
   موجّه العناوين (Router) — يحفظ مكانك في رابط الصفحة
   المشكلة: التطبيق كان صفحة واحدة بلا عنوان لكل حالة، فأي تحديث (Refresh)
   يعيدك للرئيسية: تخرج من المسلسل، ومن البحث، ومن الفيديو الشغّال.
   الحل: نكتب الحالة في hash العنوان، ونستعيدها عند التحميل.
   أمثلة:  #s=12         (مسلسل/فيلم رقم ١٢)
           #s=12&e=3     (نفس العمل + الحلقة الثالثة تعمل)
           #c=5          (قسم رقم ٥)
           #q=باتمان     (نتيجة بحث)
           #ch=88        (قناة تعمل)
   ══════════════════════════════════════════════════════════════ */
let _shsRouting = false;          // نمنع الحلقة: تغييرنا للـhash يجب ألا يُطلق الاستعادة
let _shsRestoring = false;        // نمنع الكتابة أثناء الاستعادة
/* عنوان مؤجّل: يُكتب بعد pushState الخاص بالمشغّل حتى يقع على إدخالة المشغّل
   لا على إدخالة الشاشة التي جئنا منها. */
let _pendingHash = null;

function shsSetHash(obj){
  if(_shsRestoring) return;       // لا نكتب ونحن نستعيد
  const parts = [];
  if(obj){
    if(obj.q)  parts.push('q='  + encodeURIComponent(obj.q));
    if(obj.c)  parts.push('c='  + encodeURIComponent(obj.c));
    if(obj.s)  parts.push('s='  + encodeURIComponent(obj.s));
    if(obj.e !== undefined && obj.e !== null && obj.e !== '') parts.push('e=' + encodeURIComponent(obj.e));
    if(obj.ch) parts.push('ch=' + encodeURIComponent(obj.ch));
  }
  const h = parts.length ? ('#' + parts.join('&')) : '';
  const cur = window.location.hash || '';
  if(cur === h) return;
  _shsRouting = true;
  try{
    // replaceState لا يضيف إدخالة جديدة — نترك إدارة الرجوع للمنطق الأصلي
    history.replaceState(history.state, '', window.location.pathname + window.location.search + h);
  }catch(e){ try{ window.location.hash = h; }catch(_){} }
  setTimeout(()=>{ _shsRouting = false; }, 0);
}

function shsGetHash(){
  const h = (window.location.hash || '').replace(/^#/, '');
  if(!h) return {};
  const o = {};
  h.split('&').forEach(kv=>{
    const i = kv.indexOf('=');
    if(i < 0) return;
    const k = kv.slice(0, i), v = decodeURIComponent(kv.slice(i + 1));
    if(v) o[k] = v;
  });
  return o;
}

/* استعادة الحالة بعد تحميل الصفحة */
async function shsRestoreFromHash(){
  const st = shsGetHash();
  if(!st.q && !st.c && !st.s && !st.ch) return false;
  _shsRestoring = true;
  try{
    /* ١) بحث */
    if(st.q){
      const inp = document.getElementById('searchInput');
      if(inp){
        inp.value = st.q;
        _shsRestoring = false;      // نسمح للبحث بكتابة حالته
        handleSearch();
        return true;
      }
    }

    /* ٢) قناة تعمل */
    if(st.ch){
      const chId = String(st.ch);
      let ch = (App.allContent || []).find(x => x.globalType === 'channel' && String(x.id) === chId);

      /* لا نحمّل المكتبة كاملة من أجل قناة واحدة (كان يستغرق ثوانٍ بعد استيراد Xtream).
         أسرع طريق: استرجاع ما حُفظ في الجلسة عند فتح المشغّل. */
      if(!ch){
        try{
          const saved = JSON.parse(sessionStorage.getItem('shs_restore') || 'null');
          if(saved && saved.type === 'channel' && saved.ch && String(saved.ch.id) === chId) ch = saved.ch;
        }catch(e){}
      }
      /* وإن لم يوجد، نسأل الـAPI عن هذه القناة تحديداً */
      if(!ch){
        try{
          const r = await fetch(`api.php?action=channels&id=${encodeURIComponent(chId)}`);
          const d = await r.json();
          const one = (d.channels && (Array.isArray(d.channels) ? d.channels[0] : d.channels)) || d.data || null;
          if(one && String(one.id) === chId) ch = one;
        }catch(e){}
      }
      /* الملاذ الأخير فقط: تحميل المكتبة */
      if(!ch){
        await _shsEnsureAllContent();
        ch = (App.allContent || []).find(x => x.globalType === 'channel' && String(x.id) === chId);
      }

      _shsRestoring = false;
      if(ch){ openPlayerChannel(ch); return true; }
      return false;
    }

    /* ٣) مسلسل/فيلم — مع حلقة اختيارية */
    if(st.s){
      const sid = st.s;
      let name = '', poster = '';
      let hit = (App.allContent || []).find(x => x.globalType === 'series' && String(x.id) === String(sid));

      /* عند التحديث تكون App.allContent شبه فارغة، فلا نجد الاسم ويظهر العنوان فارغاً.
         نجلب بيانات العمل مباشرة من الـAPI بدل تحميل المكتبة كاملة. */
      if(!hit){
        try{
          const r = await fetch(`api.php?action=series&id=${encodeURIComponent(sid)}`);
          const d = await r.json();
          const one = (d.series && (Array.isArray(d.series) ? d.series[0] : d.series)) || d.data || null;
          if(one){ name = one.name || ''; poster = one.poster_url || ''; }
        }catch(e){}
      } else {
        name = hit.name || ''; poster = hit.poster_url || '';
      }

      _shsRestoring = false;
      await openSeriesEpisodes(sid, name, poster);   // ينتظر جلب الحلقات فعلياً

      /* تشغيل الحلقة المطلوبة — بلا setTimeout عشوائي.
         openSeriesEpisodes انتهى بالفعل، فـ App.allEpisodes جاهزة الآن. */
      if(st.e !== undefined && st.e !== ''){
        const idx = parseInt(st.e);
        if(!isNaN(idx) && App.allEpisodes && App.allEpisodes[idx]){
          openPlayerEpisode(idx);
        }
      }
      return true;
    }

    /* ٤) قسم */
    if(st.c){
      const cid = st.c;
      const cat = (App.cats || []).find(x => String(x.id) === String(cid));
      _shsRestoring = false;
      await openCategoryView(cid, cat ? cat.name : '');
      return true;
    }
  }catch(e){
    console.error('restore error:', e);
  }finally{
    _shsRestoring = false;
  }
  return false;
}

function clearSearchAndGoHome(){
  shsSetHash(null);                               // تنظيف العنوان عند العودة للرئيسية
  document.getElementById('searchInput').value='';
  document.getElementById('searchViewSection').classList.add('hidden');
  document.getElementById('searchEmpty').classList.add('hidden');
  document.getElementById('netflixStyleSliders').classList.remove('hidden');
  document.getElementById('heroWelcome').classList.remove('hidden');
  setActiveCatNavBtn(null);
}

/* ════ CATEGORY QUICK NAV — شريط اختصارات الأقسام ════ */
function renderCategoryNavBar(){
  const bar=document.getElementById('catNavbar');
  if(!bar)return;
  bar.innerHTML='';
  if(!App.cats||!App.cats.length){bar.style.display='none';return;}
  bar.style.display='flex';
  const homeBtn=document.createElement('button');
  homeBtn.type='button';
  homeBtn.className='cat-nav-btn active';
  homeBtn.dataset.catId='';
  homeBtn.innerHTML='<span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span> الرئيسية';
  homeBtn.addEventListener('click',()=>{
    closeCategoryView();
    if(!document.getElementById('searchViewSection').classList.contains('hidden')) clearSearchAndGoHome();
  });
  bar.appendChild(homeBtn);
  App.cats.forEach(c=>{
    const btn=document.createElement('button');
    btn.type='button';
    btn.className='cat-nav-btn';
    btn.dataset.catId=c.id;
    btn.textContent=c.name;
    btn.addEventListener('click',()=>openCategoryView(c.id,c.name));
    bar.appendChild(btn);
  });
  syncCatNavbarOffset();
  try{ shsRenderCatMenu(); }catch(e){}
}
/* [SHS-CATMENU-JS-START] قائمة الأقسام العمودية المنسدلة (إضافة فقط) */

/* [SHS-CATICON] نظام أيقونات احترافي يختار الأيقونة حسب اسم القسم */
var SHS_ICONS={
  tv:'<path d="M7 21h10"/><rect width="20" height="14" x="2" y="3" rx="2"/><path d="m17 7-5 4-5-4"/>',
  live:'<path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5"/><path d="M19.1 4.9C23 8.8 23 15.1 19.1 19"/>',
  movie:'<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 3v18M17 3v18M3 7.5h4M17 7.5h4M3 12h18M3 16.5h4M17 16.5h4"/>',
  series:'<rect width="20" height="15" x="2" y="7" rx="2"/><path d="m17 2-5 5-5-5"/>',
  sports:'<circle cx="12" cy="12" r="10"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/><circle cx="12" cy="12" r="3"/>',
  kids:'<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" x2="9.01" y1="9" y2="9"/><line x1="15" x2="15.01" y1="9" y2="9"/>',
  news:'<path d="M4 22h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9a1 1 0 0 1 1-1h1"/><path d="M16 6h-6v4h6zM10 14h6M10 18h6"/>',
  doc:'<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
  music:'<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
  radio:'<path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5"/><path d="M19.1 4.9C23 8.8 23 15.1 19.1 19"/>',
  quiz:'<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" x2="12.01" y1="17" y2="17"/>',
  talk:'<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/><path d="M8 12h.01M12 12h.01M16 12h.01"/>',
  theater:'<path d="M2 10s3-3 3-8M22 10s-3-3-3-8M10 2c0 4.4-3.6 8-8 8M14 2c0 4.4 3.6 8 8 8"/><path d="M2 10a10 10 0 0 0 20 0M12 14v.01"/><path d="M8 17a4 4 0 0 0 8 0"/>',
  person:'<circle cx="12" cy="8" r="4"/><path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>',
  fight:'<path d="M6.5 6.5 17.5 17.5M21 21l-3-3M3 3l3 3M18 6l3-3M6 18l-3 3"/><rect width="4" height="4" x="4" y="4" rx="1"/><rect width="4" height="4" x="16" y="16" rx="1"/>',
  grid:'<rect width="7" height="7" x="3" y="3" rx="1.5"/><rect width="7" height="7" x="14" y="3" rx="1.5"/><rect width="7" height="7" x="14" y="14" rx="1.5"/><rect width="7" height="7" x="3" y="14" rx="1.5"/>'
};
function shsPickIconKey(name){
  var n=(name||'').toString();
  var has=function(){for(var i=0;i<arguments.length;i++){if(n.indexOf(arguments[i])!==-1)return true;}return false;};
  if(has('تلفزيون','تلفاز','قنوات','فضائي','بث المباشر','مباشر'))return 'tv';
  if(has('بث'))return 'live';
  if(has('افلام','أفلام','فلم','سينما','movie'))return 'movie';
  if(has('مسلسل','مسلسلات','دراما','series'))return 'series';
  if(has('رياض','كرة','مباريات','دوري','sport'))return 'sports';
  if(has('اطفال','أطفال','كرتون','انمي','أنمي','kids'))return 'kids';
  if(has('اخبار','أخبار','news'))return 'news';
  if(has('وثائق','وثائقي','doc'))return 'doc';
  if(has('موسيق','اغاني','أغاني','طرب','music'))return 'music';
  if(has('راديو','اذاعة','إذاعة','radio'))return 'radio';
  if(has('مسابق','مسابقات','تحدي'))return 'quiz';
  if(has('حوار','برامج حوارية','توك','بودكاست','podcast'))return 'talk';
  if(has('مسرح','مسرحيات','مسرحية'))return 'theater';
  if(has('عروض','مصارع','مصارعة'))return 'fight';
  if(has('سيرة','شخصية','بايوغراف'))return 'person';
  if(has('ترفيه','منوع','منوعات'))return 'grid';
  return 'grid';
}
function shsCatIconSVG(name,size){
  var body=SHS_ICONS[shsPickIconKey(name)]||SHS_ICONS.grid;
  var s=size||'1em';
  return '<svg xmlns="http://www.w3.org/2000/svg" width="'+s+'" height="'+s+'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'+body+'</svg>';
}
function shsRenderCatMenu(){
  var list=document.getElementById('shsCatMenuList');
  if(!list)return;
  list.innerHTML='';
  if(!App.cats||!App.cats.length){
    list.innerHTML='<div class="shs-catmenu-empty">لا توجد أقسام متاحة</div>';
    return;
  }
  App.cats.forEach(function(c,i){
    var b=document.createElement('button');
    b.type='button';
    b.className='shs-catmenu-item';
    b.dataset.catId=c.id;
    var idx=String(i+1).padStart(2,'0');
    var nm=(c.name||'').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    b.innerHTML=
      '<span class="shs-catmenu-ico">'+shsCatIconSVG(c.name)+'</span>'+
      '<span class="shs-catmenu-name">'+nm+'</span>'+
      '<span class="shs-catmenu-arrow"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></span>';
    b.addEventListener('click',function(){
      shsCloseCatMenu();
      try{ openCategoryView(c.id,c.name); }catch(e){}
    });
    list.appendChild(b);
  });
  var cnt=document.getElementById('shsCatMenuCount');
  if(cnt)cnt.textContent=App.cats.length+' قسم متاح';
}
function shsOpenCatMenu(){
  try{ shsRenderCatMenu(); }catch(e){}
  var ov=document.getElementById('shsCatMenuOverlay');
  var pn=document.getElementById('shsCatMenuPanel');
  if(ov)ov.classList.add('open');
  if(pn){pn.classList.add('open');pn.setAttribute('aria-hidden','false');}
  document.body.style.overflow='hidden';
}
function shsCloseCatMenu(){
  var ov=document.getElementById('shsCatMenuOverlay');
  var pn=document.getElementById('shsCatMenuPanel');
  if(ov)ov.classList.remove('open');
  if(pn){pn.classList.remove('open');pn.setAttribute('aria-hidden','true');}
  document.body.style.overflow='';
}
function shsCatMenuGoHome(){
  shsCloseCatMenu();
  try{ closeCategoryView(); }catch(e){}
  try{
    if(!document.getElementById('searchViewSection').classList.contains('hidden')) clearSearchAndGoHome();
  }catch(e){}
}
document.addEventListener('keydown',function(e){ if(e.key==='Escape') shsCloseCatMenu(); });
/* [SHS-CATMENU-JS-END] */
function setActiveCatNavBtn(catId){
  document.querySelectorAll('.cat-nav-btn').forEach(b=>{
    b.classList.toggle('active', String(b.dataset.catId)===String(catId??''));
  });
  /* [SHS-CATMENU-ACTIVE] مزامنة التمييز مع القائمة العمودية (إضافة فقط) */
  try{
    document.querySelectorAll('#shsCatMenuList .shs-catmenu-item').forEach(function(b){
      b.classList.toggle('active', String(b.dataset.catId)===String(catId??''));
    });
  }catch(e){}
}
async function openCategoryView(catId,catName){
  App.currentCategoryView={id:catId,name:catName};
  shsSetHash({c:catId});                          // يبقى بعد التحديث
  setActiveCatNavBtn(catId);
  document.getElementById('netflixStyleSliders').classList.add('hidden');
  document.getElementById('heroWelcome').classList.add('hidden');
  document.getElementById('searchViewSection').classList.add('hidden');
  document.getElementById('epSection').classList.add('hidden');
  document.getElementById('categoryViewSection').classList.remove('hidden');
  document.getElementById('categoryViewTitle').textContent=catName||'القسم';
  const grid=document.getElementById('categoryViewGrid');
  const loading=document.getElementById('categoryViewLoading');
  const empty=document.getElementById('categoryViewEmpty');
  const badge=document.getElementById('categoryViewCountBadge');
  grid.innerHTML='';grid.classList.remove('hidden');
  empty.classList.add('hidden');loading.classList.remove('hidden');
  window.scrollTo({top:0,behavior:'smooth'});
  try{
    // إلغاء طلب القسم السابق إن كان المستخدم ينتقل بسرعة بين الأقسام
    if(window.__shsCatAbort){ try{ window.__shsCatAbort.abort(); }catch(e){} }
    const _ac = ('AbortController' in window) ? new AbortController() : null;
    window.__shsCatAbort = _ac;
    const _sig = _ac ? {signal:_ac.signal} : {};
    const [chRes,srRes]=await Promise.all([
      fetch(`api.php?action=channels&category_id=${encodeURIComponent(catId)}`,_sig),
      fetch(`api.php?action=series&category_id=${encodeURIComponent(catId)}`,_sig)
    ]);
    const chData=await chRes.json();
    const srData=await srRes.json();
    const channels=chData.channels||[];
    const series=srData.series||[];
    loading.classList.add('hidden');
    const total=channels.length+series.length;
    badge.textContent=total+' عنصر';
    if(!total){
      grid.classList.add('hidden');empty.classList.remove('hidden');
    }else{
      grid.innerHTML='';
      if(channels.length)renderItemsIntoSliderDOM(grid,channels,'channels','',true);
      if(series.length){
        if(channels.length){
          const sep=document.createElement('div');
          sep.style='grid-column:1/-1;padding-top:8px';
          grid.appendChild(sep);
        }
        renderItemsIntoSliderDOM(grid,series,'series','',true);
      }
    }
  }catch(e){
    // الإلغاء المتعمّد عند تبديل الأقسام ليس خطأ — نتجاهله
    if(e && e.name==='AbortError') return;
    loading.classList.add('hidden');
    grid.classList.add('hidden');
    empty.classList.remove('hidden');
    empty.querySelector('p').textContent='تعذر تحميل محتوى القسم';
  }
}
function closeCategoryView(){
  App.currentCategoryView=null;
  document.getElementById('categoryViewSection').classList.add('hidden');
  document.getElementById('netflixStyleSliders').classList.remove('hidden');
  document.getElementById('heroWelcome').classList.remove('hidden');
  setActiveCatNavBtn(null);
  try{ shsSetHash(null); }catch(e){}   // رجوع للرئيسية → عنوان نظيف
  window.scrollTo({top:0,behavior:'smooth'});
}

/* [SHS-CATVIEW-JS-START] كاش + عرض احترافي فوري داخل الأقسام (إضافة فقط) */
/* بانر عنوان القسم مع شرائح الإحصائيات */
function shsRenderCatBanner(catName,chCount,srCount){
  var host=document.getElementById('categoryViewSection');
  if(!host)return;
  var b=document.getElementById('shsCatViewBanner');
  if(!b){
    b=document.createElement('div');
    b.id='shsCatViewBanner';
    b.className='shs-catview-banner';
    /* نضعه بعد زر الرجوع مباشرة، وقبل عنوان القسم القديم (الذي يبقى مخفياً منطقياً بلا حذف) */
    var backBtn=host.querySelector('.back-btn');
    if(backBtn&&backBtn.nextSibling){host.insertBefore(b,backBtn.nextSibling);}else{host.insertBefore(b,host.firstChild);}
  }
  var total=(chCount|0)+(srCount|0);
  var chips='<span class="shs-catview-chip total"><span class="dot"></span>'+total+' عنصر</span>';
  if(chCount>0)chips+='<span class="shs-catview-chip">قنوات: '+chCount+'</span>';
  if(srCount>0)chips+='<span class="shs-catview-chip">أفلام/مسلسلات: '+srCount+'</span>';
  var nm=(catName||'القسم').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  b.innerHTML=
    '<div class="shs-catview-ico">'+shsCatIconSVG(catName)+'</div>'+
    '<div class="shs-catview-meta"><span class="shs-catview-name">'+nm+'</span><div class="shs-catview-chips">'+chips+'</div></div>';
  b.style.display='flex';
}
function shsBannerLoading(catName){
  shsRenderCatBanner(catName,0,0);
  var b=document.getElementById('shsCatViewBanner');
  if(b){var chips=b.querySelector('.shs-catview-chips'); if(chips)chips.innerHTML='<span class="shs-catview-chip">جارٍ التحميل…</span>';}
}
/* هياكل تحميل بدل السبنر */
function shsShowSkeleton(grid,n){
  n=n||12;
  var html='';
  for(var i=0;i<n;i++)html+='<div class="shs-skel-card"></div>';
  grid.innerHTML=html;
  grid.classList.remove('hidden');
}
/* رسم المحتوى داخل الشبكة بأسلوب موحّد */
function shsPaintCategory(grid,channels,series){
  grid.innerHTML='';
  grid.classList.add('shs-fadein','shs-stagger');
  if(channels&&channels.length)renderItemsIntoSliderDOM(grid,channels,'channels','',true);
  if(series&&series.length){
    if(channels&&channels.length){
      var sep=document.createElement('div');
      sep.className='shs-catview-sep';
      sep.textContent='أفلام ومسلسلات';
      grid.appendChild(sep);
    }
    renderItemsIntoSliderDOM(grid,series,'series','',true);
  }
  /* توزيع تأخير الظهور على البطاقات لإحساس متدرّج */
  try{
    var cards=grid.querySelectorAll('.ch-card,.sr-card');
    for(var i=0;i<cards.length;i++){ cards[i].style.animationDelay=Math.min(i*0.035,0.5)+'s'; }
  }catch(e){}
  /* إعادة تشغيل أنيميشن الظهور */
  void grid.offsetWidth;
}

/* التفاف حول openCategoryView: عرض فوري من الكاش + تحديث بالخلفية */
if(typeof openCategoryView==='function' && !openCategoryView.__shsFast){
  var _shsOrigOpenCategoryView=openCategoryView;
  openCategoryView=async function(catId,catName){
    App.currentCategoryView={id:catId,name:catName};
    try{ setActiveCatNavBtn(catId); }catch(e){}
    /* إظهار قسم العرض وإخفاء البقية فوراً */
    try{
      document.getElementById('netflixStyleSliders').classList.add('hidden');
      document.getElementById('heroWelcome').classList.add('hidden');
      document.getElementById('searchViewSection').classList.add('hidden');
      document.getElementById('epSection').classList.add('hidden');
      document.getElementById('categoryViewSection').classList.remove('hidden');
    }catch(e){}
    var titleEl=document.getElementById('categoryViewTitle');
    if(titleEl)titleEl.textContent=catName||'القسم';
    var grid=document.getElementById('categoryViewGrid');
    var loading=document.getElementById('categoryViewLoading');
    var empty=document.getElementById('categoryViewEmpty');
    var badge=document.getElementById('categoryViewCountBadge');
    if(loading)loading.classList.add('hidden'); /* نستخدم هياكل التحميل بدل السبنر */
    if(empty)empty.classList.add('hidden');
    window.scrollTo({top:0,behavior:'smooth'});

    var cached=App._catCache[catId];
    if(cached){
      /* ⚡ استجابة فورية من الكاش — بلا انتظار الشبكة */
      shsRenderCatBanner(catName,cached.channels.length,cached.series.length);
      var tot=cached.channels.length+cached.series.length;
      if(badge)badge.textContent=tot+' عنصر';
      if(!tot){ grid.classList.add('hidden'); if(empty)empty.classList.remove('hidden'); }
      else{ grid.classList.remove('hidden'); shsPaintCategory(grid,cached.channels,cached.series); }
    }else{
      /* أول مرة: هياكل تحميل ثم جلب */
      shsBannerLoading(catName);
      if(grid){grid.classList.remove('hidden');shsShowSkeleton(grid,12);}
    }
    /* تحديث/جلب من الشبكة (يتم دائماً لتحديث الكاش بالخلفية) */
    try{
      var res=await Promise.all([
        fetch('api.php?action=channels&category_id='+encodeURIComponent(catId)).then(function(r){return r.json();}),
        fetch('api.php?action=series&category_id='+encodeURIComponent(catId)).then(function(r){return r.json();})
      ]);
      var channels=(res[0]&&res[0].channels)||[];
      var series=(res[1]&&res[1].series)||[];
      App._catCache[catId]={channels:channels,series:series,ts:Date.now()};
      /* لا نعيد الرسم إن كان المستخدم غادر القسم */
      if(!App.currentCategoryView||String(App.currentCategoryView.id)!==String(catId))return;
      shsRenderCatBanner(catName,channels.length,series.length);
      var total=channels.length+series.length;
      if(badge)badge.textContent=total+' عنصر';
      if(!total){
        grid.classList.add('hidden'); if(empty)empty.classList.remove('hidden');
      }else{
        grid.classList.remove('hidden'); if(empty)empty.classList.add('hidden');
        shsPaintCategory(grid,channels,series);
      }
    }catch(e){
      if(!cached){ /* أظهر خطأ فقط إن لم يكن هناك كاش يُعرض */
        if(grid)grid.classList.add('hidden');
        if(empty){empty.classList.remove('hidden');var p=empty.querySelector('p');if(p)p.textContent='تعذر تحميل محتوى القسم';}
      }
    }
  };
  openCategoryView.__shsFast=true;
}

/* [SHS-VIEWRESTORE] حفظ/استعادة موضع التصفّح عند تحديث الصفحة (إضافة فقط) */
function shsSaveView(obj){try{sessionStorage.setItem('shs_view',JSON.stringify(obj));}catch(e){}}
function shsClearView(){try{sessionStorage.removeItem('shs_view');}catch(e){}}

/* التفاف حول عرض القسم لحفظ حالته */
if(typeof openCategoryView==='function' && !openCategoryView.__shsViewHook){
  var _shsOCV=openCategoryView;
  openCategoryView=function(catId,catName){
    shsSaveView({type:'category',id:catId,name:catName});
    return _shsOCV.apply(this,arguments);
  };
  openCategoryView.__shsFast=true;
  openCategoryView.__shsViewHook=true;
}
/* التفاف حول قائمة حلقات المسلسل لحفظ حالتها */
if(typeof openSeriesEpisodes==='function' && !openSeriesEpisodes.__shsViewHook){
  var _shsOSE=openSeriesEpisodes;
  openSeriesEpisodes=function(seriesId,seriesName,seriesPoster){
    /* نحفظ أيضاً سياق القسم إن كنا داخل قسم، للرجوع الصحيح */
    var cv=App.currentCategoryView?{id:App.currentCategoryView.id,name:App.currentCategoryView.name}:null;
    shsSaveView({type:'series',id:seriesId,name:seriesName,poster:seriesPoster||'',cat:cv});
    return _shsOSE.apply(this,arguments);
  };
  openSeriesEpisodes.__shsViewHook=true;
}
/* مسح الحالة عند العودة للرئيسية */
['closeCategoryView','clearSearchAndGoHome'].forEach(function(fn){
  if(typeof window[fn]==='function' && !window[fn].__shsClearHook){
    var _o=window[fn];
    window[fn]=function(){shsClearView();return _o.apply(this,arguments);};
    window[fn].__shsClearHook=true;
  }
});
/* الرجوع من الحلقات: إن كنا داخل قسم نحفظ القسم، وإلا نمسح */
if(typeof backFromEpisodesToHome==='function' && !backFromEpisodesToHome.__shsClearHook){
  var _bfe=backFromEpisodesToHome;
  backFromEpisodesToHome=function(){
    try{
      var sq=(document.getElementById('searchInput')||{}).value||'';
      if(sq.trim().length>0){shsClearView();}
      else if(App.currentCategoryView){shsSaveView({type:'category',id:App.currentCategoryView.id,name:App.currentCategoryView.name});}
      else{shsClearView();}
    }catch(e){shsClearView();}
    return _bfe.apply(this,arguments);
  };
  backFromEpisodesToHome.__shsClearHook=true;
}

/* الاستعادة عند تحميل الصفحة */
function shsRestoreView(){
  var raw;try{raw=sessionStorage.getItem('shs_view');}catch(e){return;}
  if(!raw)return;
  var d;try{d=JSON.parse(raw);}catch(e){return;}
  if(!d||!d.type)return;
  /* لا نستعيد إن كان هناك مشغّل قيد الاستعادة (shs_restore له الأولوية) */
  try{if(sessionStorage.getItem('shs_restore'))return;}catch(e){}
  try{
    if(d.type==='category'){
      if(typeof openCategoryView==='function')openCategoryView(d.id,d.name);
    }else if(d.type==='series'){
      /* لو كان داخل قسم، نفتح القسم أولاً (بصمت) ثم قائمة الحلقات */
      if(d.cat&&typeof openCategoryView==='function'){App.currentCategoryView={id:d.cat.id,name:d.cat.name};}
      if(typeof openSeriesEpisodes==='function')openSeriesEpisodes(d.id,d.name,d.poster||'');
    }
  }catch(e){}
}
/* نشغّلها بعد تحميل الأقسام والدوال */
if(document.readyState==='loading'){
  document.addEventListener('DOMContentLoaded',function(){setTimeout(shsRestoreView,350);});
}else{
  setTimeout(shsRestoreView,350);
}
/* [SHS-VIEWRESTORE-END] */
/* [SHS-CATVIEW-JS-END] */
function syncCatNavbarOffset(){
  const navbar=document.getElementById('navbar');
  const catBar=document.getElementById('catNavbar');
  const main=document.getElementById('mainContent');
  if(!navbar||!catBar)return;
  const navH=navbar.offsetHeight||68;
  document.documentElement.style.setProperty('--navbar-h',navH+'px');
  if(main){
    const catH=(App.cats&&App.cats.length)?(catBar.offsetHeight||48):0;
    main.style.paddingTop=(navH+catH+16)+'px';
  }
}
window.addEventListener('resize',()=>{ if(document.getElementById('catNavbar').style.display!=='none') syncCatNavbarOffset(); });

// Voice Search
document.addEventListener('DOMContentLoaded',function(){
  const SR=window.SpeechRecognition||window.webkitSpeechRecognition;
  const btn=document.getElementById('voiceSearchBtn');
  if(SR&&btn){
    btn.style.display='block';
    const rec=new SR();rec.lang='ar-SA';rec.interimResults=false;
    rec.onresult=e=>{document.getElementById('searchInput').value=e.results[0][0].transcript;btn.style.color='var(--text-muted)';handleSearch();};
    rec.onerror=()=>btn.style.color='var(--text-muted)';
    rec.onend=()=>btn.style.color='var(--text-muted)';
    btn.addEventListener('click',()=>{try{rec.start();btn.style.color='var(--red)';}catch(e){}});
  }
});

/* ════ PLAYER STATE ════ */
const PL={hls:null,dash:null,flv:null,vol:1,muted:false,idle:null,subtitleOn:false,epPanelOpen:false,m3uPanelOpen:false,m3uEntries:[],m3uIdx:-1,userPaused:false,backupUrl:'',usedBackup:false};
try{window.PL=PL;}catch(e){} /* جسر: كشف PL على window لإصلاحات المشغّل — لا يغيّر أي منطق */
const _saved={active:false,url:'',subUrl:'',type:'',epIdx:-1,seriesId:0};

/* ════ CAST ════ */
function castToSmartWvc(){
  if(!_saved.url){toast('لا يوجد بث للإرسال');return;}
  const a=document.createElement('a');a.href=_saved.url;const absUrl=a.href;
  toast('جارِ تجهيز الإرسال...');
  setTimeout(()=>{
    if(_UA.isIOS){
      const t=Date.now();
      window.location.href='wvc-x-callback://open?url='+encodeURIComponent(absUrl);
      setTimeout(()=>{if(Date.now()-t<2000)window.location.href='https://apps.apple.com/app/web-video-cast-browser-to-tv/id1400866497';},1500);
    }else{
      const sch=absUrl.startsWith('https')?'https':'http';
      const tc=absUrl.split('://')[1]||absUrl;
      const ws=encodeURIComponent('https://play.google.com/store/apps/details?id=com.instantbits.cast.webvideo');
      window.location.href=`intent://${tc}#Intent;package=com.instantbits.cast.webvideo;action=android.intent.action.VIEW;scheme=${sch};type=video/*;S.browser_fallback_url=${ws};end;`;
    }
  },300);
}

function downloadWithTdm(){
  if(!_saved.url){toast('لا يوجد بث جاهز للتحميل');return;}
  const a=document.createElement('a');a.href=_saved.url;const absUrl=a.href;
  toast('جارٍ تحويلك لتطبيق TDM...');
  setTimeout(()=>{
    const sch=absUrl.startsWith('https')?'https':'http';
    const tc=absUrl.split('://')[1]||absUrl;
    const storeFallback=encodeURIComponent('https://play.google.com/store/apps/details?id=com.tdm.manager&hl=en_GB');
    window.location.href=`intent://${tc}#Intent;package=com.tdm.manager;action=android.intent.action.VIEW;scheme=${sch};type=video/*;S.browser_fallback_url=${storeFallback};end;`;
  },300);
}

document.addEventListener('DOMContentLoaded',function(){
  // استخدام _UA المحسوب مسبقاً — لا قراءة userAgent جديدة
  const dlBtn=document.getElementById('tdmDownloadBtn');
  if(dlBtn&&_UA.isAndroid&&!_UA.isWindows)dlBtn.style.display='flex';
});

/* ════ OPEN PLAYER ════ */
function openPlayerChannel(ch){
  try{sessionStorage.setItem('shs_restore',JSON.stringify({type:'channel',ch:ch}));}catch(e){}
  /* لا نكتب الـhash هنا: pushState لم يُنفَّذ بعد (السطر ~3449)، فالكتابة الآن
     تلوّث إدخالة الشاشة السابقة (الرئيسية) بـ #ch=..، فيعود التحديث للمشغّل
     بعد الخروج منه. نؤجّلها لتقع على إدخالة المشغّل نفسها. */
  _pendingHash = (ch && ch.id) ? {ch:ch.id} : null;
  App.currentType='channel';App.currentEpisodeIdx=-1;
  document.getElementById('pEpNav').style.display='none';
  document.getElementById('epPanelBtn').style.display='none';
  document.getElementById('m3uPanelBtn').style.display='none';
  PL.backupUrl=ch.backup_url||'';PL.usedBackup=false;
  const fmt=fmtLabel(ch.stream_url||'');const isLive=isLiveFormat(ch.stream_url||'');
  document.getElementById('pBadgeLabel').textContent=isLive?'LIVE':'VOD';
  document.getElementById('pChannelName').textContent=ch.name;
  document.getElementById('pFmtTag').textContent=ch.quality||fmt;
  document.getElementById('pTime').textContent=isLive?'بث مباشر':'00:00 / 00:00';
  const f=detectFmt(ch.stream_url||'');
  if(f==='hls'&&(ch.stream_url||'').toLowerCase().endsWith('.m3u')){
    _openOverlay('',ch.subtitle_url||'');
    toast('جارٍ تحميل قائمة M3U...');
    parseM3U(ch.stream_url).then(entries=>{if(!entries.length){toast('القائمة فارغة');return;}PL.m3uEntries=entries;PL.m3uIdx=0;buildM3UPanel();document.getElementById('m3uPanelBtn').style.display='flex';toggleM3UPanel();playM3UEntry(0);});
    return;
  }
  _openOverlay(ch.stream_url,ch.subtitle_url||'');
  if(ch.id)fetch('api.php?action=increment_view&id='+ch.id+'&type=channel').catch(()=>{});
}

function openPlayerEpisode(idx){
  try{sessionStorage.setItem('shs_restore',JSON.stringify({type:'episode',idx:idx,ep:App.allEpisodes[idx],seriesId:App.currentSeriesId,seriesName:App.currentSeriesName}));}catch(e){}
  /* نفس سبب التأجيل في openPlayerChannel: الكتابة قبل pushState تلوّث
     إدخالة صفحة الحلقات، فيعيدك التحديث للفيديو بعد الخروج منه. */
  _pendingHash = (App.currentSeriesId!==undefined && App.currentSeriesId!==null)
    ? {s:App.currentSeriesId, e:idx} : null;
  App.currentType='episode';App.currentEpisodeIdx=idx;
  const ep=App.allEpisodes[idx];if(!ep)return;
  PL.backupUrl='';PL.usedBackup=false;
  const fmt=fmtLabel(ep.stream_url||'');const isLive=isLiveFormat(ep.stream_url||'');
  document.getElementById('pBadgeLabel').textContent=isLive?'LIVE':'EP';
  document.getElementById('pChannelName').textContent=App.currentSeriesName;
  document.getElementById('pFmtTag').textContent=fmt;
  document.getElementById('pEpLabel').textContent=ep.title;
  document.getElementById('pEpNav').style.display='flex';
  document.getElementById('pPrevEp').disabled=(idx===0);
  document.getElementById('pNextEp').disabled=(idx===App.allEpisodes.length-1);
  document.getElementById('epPanelBtn').style.display='flex';
  _openOverlay(ep.stream_url,ep.subtitle_url||'');
  buildEpPanel();
  fetch('api.php?action=increment_view&id='+ep.id+'&type=episode').catch(()=>{});
}
function navEpisode(dir){const ni=App.currentEpisodeIdx+dir;if(ni>=0&&ni<App.allEpisodes.length)openPlayerEpisode(ni);}

var _prevScreen={ep:false,home:false,search:false,category:false};

function _openOverlay(url,subUrl){
  const overlay=document.getElementById('playerOverlay');

  // هل نفس المحتوى ولم يُدمَّر؟
  const same=!_saved.destroyed &&
    _saved.active &&
    _saved.type===App.currentType&&
    (App.currentType==='channel'
      ? _saved.url===url
      : _saved.epIdx===App.currentEpisodeIdx && _saved.seriesId===App.currentSeriesId);

  _prevScreen.ep=!document.getElementById('epSection').classList.contains('hidden');
  _prevScreen.home=!document.getElementById('netflixStyleSliders').classList.contains('hidden');
  _prevScreen.search=!document.getElementById('searchViewSection').classList.contains('hidden');
  _prevScreen.category=!document.getElementById('categoryViewSection').classList.contains('hidden');
  overlay.classList.add('active');
  document.body.style.overflow='hidden';
  /* ندفع إدخالة للمشغّل مرة واحدة فقط. إن كان المشغّل مفتوحاً أصلاً (تبديل حلقة)
     فالإدخالة موجودة، وتكرارها يجعل الرجوع يحتاج ضغطات متعددة. */
  if(!(window.history.state && window.history.state.player==='active')){
    window.history.pushState({player:'active'},'');
  }
  /* الآن فقط نكتب عنوان المشغّل — على إدخالته الخاصة.
     بهذا يبقى التحديث داخل الفيديو يعمل، وعند الخروج تعود الشاشة السابقة نظيفة. */
  if(_pendingHash){ const _ph=_pendingHash; _pendingHash=null; try{ shsSetHash(_ph); }catch(e){} }
  fixPlayerHeight();
  setTimeout(function(){try{overlay.focus();}catch(e){}},100);

  if(same){
    // نفس المحتوى ولم يُغلَق — استئناف فقط
    const v=document.getElementById('html5Player');
    if(v&&v.paused)v.play().catch(()=>{});
  }else{
    // محتوى جديد أو بعد إغلاق — تشغيل من البداية
    if(url)initStream(url,subUrl);
    _saved.active=true;
    _saved.destroyed=false;
    _saved.url=url;
    _saved.subUrl=subUrl;
    _saved.type=App.currentType;
    _saved.epIdx=App.currentEpisodeIdx;
    _saved.seriesId=App.currentSeriesId;
  }
  // عرض شعارات قدرات الجهاز عند كل فتح للمشغل
  _showDeviceBadges();
  showControls();
}

function closePlayer(){
  try{sessionStorage.removeItem('shs_restore');}catch(e){}
  _pendingHash = null;   // إلغاء أي عنوان مؤجّل لم يُكتب بعد
  /* ── العنوان يتبع الشاشة التي نعود إليها فعلاً ──
     الخطأ سابقاً: كنا نكتب {s: App.currentSeriesId} دائماً، حتى لو كان الرجوع للرئيسية.
     فيبقى #s=.. في العنوان، وأي تحديث يعيد فتح العمل/الفيديو من جديد.
     _prevScreen يحفظ الشاشة التي كنا فيها قبل فتح المشغّل — نعتمد عليها. */
  try{
    const q = (document.getElementById('searchInput')||{}).value || '';
    if(_prevScreen.ep && App.currentSeriesId){
      shsSetHash({s:App.currentSeriesId});          // نرجع لصفحة الحلقات (بلا e — الفيديو أُغلق)
    } else if(_prevScreen.search && q.trim()){
      shsSetHash({q:q.trim()});                     // نرجع لنتائج البحث
    } else if(_prevScreen.category && App.currentCategoryView){
      shsSetHash({c:App.currentCategoryView.id});   // نرجع للقسم
    } else {
      shsSetHash(null);                             // نرجع للرئيسية → عنوان نظيف
    }
  }catch(e){}
  // إلغاء أي إعادة تشغيل تلقائية معلّقة (حتى لا تُعاد فتح قناة بعد الإغلاق)
  if(_hardReloadTimer){clearTimeout(_hardReloadTimer);_hardReloadTimer=null;}
  _hardReloadUrl='';
  // خروج من fullscreen أولاً
  try{
    if(document.fullscreenElement||document.webkitFullscreenElement)
      (document.exitFullscreen||document.webkitExitFullscreen).call(document);
  }catch(e){}
  // حفظ موضع التشغيل + تعليم أن المشغل دُمِّر
  const v=document.getElementById('html5Player');
  if(v&&!isNaN(v.currentTime))_saved.time=v.currentTime;
  _saved.destroyed=true; // ← الإصلاح: يمنع same=true من تخطي initStream
  // تنظيف المشغل بالكامل
  destroyPlayer();
  // إخفاء overlay والـ panels
  document.getElementById('playerOverlay').classList.remove('active');
  document.getElementById('epPanel').classList.remove('open');
  document.getElementById('m3uPanel').classList.remove('open');
  PL.epPanelOpen=false; PL.m3uPanelOpen=false;
  document.body.style.overflow='';
  // استعادة الشاشة السابقة
  document.getElementById('epSection').classList.toggle('hidden',!_prevScreen.ep);
  document.getElementById('netflixStyleSliders').classList.toggle('hidden',!_prevScreen.home);
  document.getElementById('heroWelcome').classList.toggle('hidden',!_prevScreen.home);
  document.getElementById('searchViewSection').classList.toggle('hidden',!_prevScreen.search);
  document.getElementById('categoryViewSection').classList.toggle('hidden',!_prevScreen.category);
}

/* ══════════════════════════════════════════════════════════
   DEVICE CAPABILITY DETECTION
   يكشف دعم: الصوت (Dolby/DTS/AAC) + الصورة (HDR/4K/8K) + الهرتزية
   ويعرضها كشعارات عند بدء تشغيل كل فيديو
══════════════════════════════════════════════════════════ */

/* كشف قدرات الجهاز مرة واحدة عند التحميل */
const _DevCaps=(function(){
  const ua=_UA.ua;
  const v=document.createElement('video');

  /* ══ الصوت ══ */
  const audio={
    dolbyAtmos: !!(v.canPlayType('audio/mp4; codecs="ec-3"')||v.canPlayType('video/mp4; codecs="ec-3"')),
    dolbyAudio: !!v.canPlayType('audio/mp4; codecs="ac-3"'),
    dtsX:       !!(v.canPlayType('audio/mp4; codecs="dtsc"')||v.canPlayType('audio/mp4; codecs="dtse"')),
    aac:        !!v.canPlayType('audio/mp4; codecs="mp4a.40.2"'),
    opus:       !!v.canPlayType('audio/webm; codecs="opus"'),
  };

  /* ══ الفيديو / HDR ══ */
  const hdrP3   = window.matchMedia('(color-gamut: p3)').matches;
  const hdrRec2020 = window.matchMedia('(color-gamut: rec2020)').matches;
  const hdrDynamic = window.matchMedia('(dynamic-range: high)').matches;
  const hdr10plus  = hdrRec2020&&hdrDynamic;
  const colorDepth = screen.colorDepth||0;

  const video={
    hdr10plus,
    hdr10: hdrDynamic && hdrP3,
    hlg:   hdrDynamic,
    hdrAny: hdrDynamic||hdrP3,
    h265:  !!(v.canPlayType('video/mp4; codecs="hvc1.1.6.L93.B0"')||v.canPlayType('video/mp4; codecs="hev1.1.6.L93.B0"')),
    av1:   !!v.canPlayType('video/mp4; codecs="av01.0.05M.08"'),
    h264:  !!v.canPlayType('video/mp4; codecs="avc1.42E01E"'),
    res4k: screen.width>=3840||screen.height>=2160,
    res8k: screen.width>=7680||screen.height>=4320,
    colorDepth,
  };

  /* ══ الشاشة / هرتزية ══ */
  // MediaCapabilities API — الأدق
  let hzEst=60;
  if(typeof screen.refreshRate==='number')        hzEst=Math.round(screen.refreshRate);
  else if(window.matchMedia('(min-resolution: 2dppx)').matches && _UA.isTV) hzEst=120;

  // تقدير من UA للتلفازات المعروفة
  if(/TCL|Hisense|Sony|Samsung|LG|BRAVIA/i.test(ua)){
    if(/8K|2160p|75inch|85inch|98inch/i.test(ua)) hzEst=Math.max(hzEst,120);
    else hzEst=Math.max(hzEst,60);
  }
  if(_UA.isAndroidTV&&!_UA.isMobile) hzEst=Math.max(hzEst,60);

  const vrr = window.matchMedia('(update: fast)').matches;
  const display={hz:hzEst, vrr};

  /* ══ نوع الجهاز ══ */
  const deviceType = _UA.isTV        ? 'TV'
                   : _UA.isIOS       ? 'iOS'
                   : _UA.isAndroidMobile ? 'Android'
                   : 'Desktop';

  return{audio,video,display,deviceType};
})();

/* بناء الشعارات وعرضها */
function _showDeviceBadges(){
  const wrap=document.getElementById('deviceBadgesWrap');
  if(!wrap)return;
  wrap.innerHTML='';

  const badges=[];
  const C=_DevCaps;

  /* ── الصوت ── */
  if(C.audio.dolbyAtmos){
    badges.push({cls:'audio-dolby',icon:'🔊',label:'Dolby Atmos'});
  }else if(C.audio.dolbyAudio){
    badges.push({cls:'audio-dolby',icon:'🔊',label:'Dolby Audio'});
  }else if(C.audio.dtsX){
    badges.push({cls:'audio-dts',icon:'🔊',label:'DTS:X'});
  }else if(C.audio.aac){
    badges.push({cls:'audio-std',icon:'🔊',label:'AAC Stereo'});
  }

  /* ── الصورة ── */
  if(C.video.res8k){
    badges.push({cls:'video-4k',icon:'🖥',label:'8K Ultra HD'});
  }else if(C.video.res4k){
    badges.push({cls:'video-4k',icon:'🖥',label:'4K Ultra HD'});
  }

  if(C.video.hdr10plus){
    badges.push({cls:'video-hdr',icon:'☀️',label:'HDR10+'});
  }else if(C.video.hdr10){
    badges.push({cls:'video-hdr',icon:'☀️',label:'HDR10'});
  }else if(C.video.hlg){
    badges.push({cls:'video-hdr',icon:'☀️',label:'HLG'});
  }

  if(C.video.av1){
    badges.push({cls:'video-std',icon:'🎬',label:'AV1'});
  }else if(C.video.h265){
    badges.push({cls:'video-std',icon:'🎬',label:'HEVC / H.265'});
  }else if(C.video.h264){
    badges.push({cls:'video-std',icon:'🎬',label:'H.264'});
  }

  /* ── الهرتزية ── */
  const hz=C.display.hz;
  const vrrTxt=C.display.vrr?' VRR':'';
  if(hz>=240){
    badges.push({cls:'display-hz',icon:'⚡',label:`${hz}Hz${vrrTxt}`});
  }else if(hz>=144){
    badges.push({cls:'display-hz',icon:'⚡',label:`${hz}Hz${vrrTxt}`});
  }else if(hz>=120){
    badges.push({cls:'display-hz',icon:'⚡',label:`${hz}Hz${vrrTxt}`});
  }else{
    badges.push({cls:'display-hz',icon:'⚡',label:`${hz}Hz`});
  }

  /* ── نوع الجهاز ── */
  const typeMap={TV:'📺 تلفاز',iOS:'📱 iOS',Android:'📱 أندرويد',Desktop:'💻 متصفح'};
  badges.push({cls:'video-std',icon:'',label:typeMap[C.deviceType]||C.deviceType});

  /* إنشاء العناصر مع تأخير تتالي */
  badges.forEach((b,i)=>{
    const el=document.createElement('div');
    el.className=`dev-badge ${b.cls}`;
    el.innerHTML=`<span class="db-icon">${b.icon}</span>${b.label}`;
    wrap.appendChild(el);
    // ظهور تتالي
    setTimeout(()=>el.classList.add('visible'), i*80+100);
  });

  /* اختفاء تلقائي بعد 4 ثوانٍ */
  setTimeout(()=>{
    wrap.querySelectorAll('.dev-badge').forEach(el=>{
      el.style.transition='opacity .6s ease, transform .6s ease';
      el.classList.remove('visible');
    });
    setTimeout(()=>{wrap.innerHTML='';},700);
  },4000);
}


/* ════════════════════════════════════════════
   SUBTITLE SYSTEM — يدعم VTT و SRT تلقائياً
   SRT  → يُحوَّل إلى VTT في المتصفح (Blob URL)
   VTT  → يُمرَّر مباشرة
   يكشف النوع من الامتداد أو محتوى الملف
════════════════════════════════════════════ */

/* تحويل SRT نص إلى VTT نص */
function _srtToVtt(srt){
  // أضف رأس VTT
  let vtt = 'WEBVTT\n\n';
  // استبدل فواصل السطر المختلفة بـ \n
  const text = srt.replace(/\r\n/g,'\n').replace(/\r/g,'\n').trim();
  // استبدل الطوابع الزمنية: 00:00:00,000 → 00:00:00.000
  vtt += text.replace(/(\d{2}:\d{2}:\d{2}),(\d{3})/g,'$1.$2');
  return vtt;
}

/* إنشاء Blob URL من نص */
function _makeBlobUrl(text, mime){
  try{
    const blob = new Blob([text], {type: mime});
    return URL.createObjectURL(blob);
  }catch(e){ return null; }
}

/* إضافة track للفيديو */
function _attachTrack(videoEl, srcUrl, isBlob){
  // احذف أي tracks قديمة
  while(videoEl.firstChild && videoEl.firstChild.tagName === 'TRACK'){
    videoEl.removeChild(videoEl.firstChild);
  }
  const t = document.createElement('track');
  t.kind    = 'subtitles';
  t.label   = 'العربية';
  t.srclang = 'ar';
  t.src     = srcUrl;
  t.default = true;
  videoEl.appendChild(t);
  // تفعيل فوري
  if(videoEl.textTracks && videoEl.textTracks[0]){
    videoEl.textTracks[0].mode = 'showing';
  }
  // حذف Blob URL بعد التحميل لتحرير الذاكرة
  if(isBlob){
    t.addEventListener('load', ()=>{ try{ URL.revokeObjectURL(srcUrl); }catch(e){} }, {once:true});
  }
}

/* الدالة الرئيسية — تكشف النوع وتُحمّل */
async function _loadSubtitle(videoEl, subUrl){
  if(!subUrl || !subUrl.trim()) return;

  const ext = subUrl.split('?')[0].split('.').pop().toLowerCase();

  try{
    if(ext === 'vtt'){
      // VTT — مباشر بدون تحويل
      _attachTrack(videoEl, subUrl, false);
      return;
    }

    if(ext === 'srt'){
      // SRT — جلب ثم تحويل إلى VTT
      const resp = await fetch(subUrl);
      if(!resp.ok) throw new Error('fetch failed');
      const raw  = await resp.text();
      const vtt  = _srtToVtt(raw);
      const bUrl = _makeBlobUrl(vtt, 'text/vtt');
      if(bUrl){ _attachTrack(videoEl, bUrl, true); }
      else     { _attachTrack(videoEl, subUrl, false); } // fallback
      return;
    }

    // امتداد غير معروف — جلب وفحص المحتوى
    const resp = await fetch(subUrl);
    if(!resp.ok) throw new Error('fetch failed');
    const raw  = await resp.text();
    const trimmed = raw.trimStart();

    if(trimmed.startsWith('WEBVTT')){
      // المحتوى VTT
      const bUrl = _makeBlobUrl(raw, 'text/vtt');
      if(bUrl){ _attachTrack(videoEl, bUrl, true); }
      else     { _attachTrack(videoEl, subUrl, false); }
    } else {
      // افتراض SRT
      const vtt  = _srtToVtt(raw);
      const bUrl = _makeBlobUrl(vtt, 'text/vtt');
      if(bUrl){ _attachTrack(videoEl, bUrl, true); }
      else     { _attachTrack(videoEl, subUrl, false); }
    }

  }catch(err){
    // فشل الجلب (CORS) — جرب مباشرة كـ fallback
    _attachTrack(videoEl, subUrl, false);
  }
}


/* ══ إعادة تشغيل القناة بالكامل تلقائياً عند توقفها (بديل الخروج والدخول اليدوي) ══
   تعيد بناء البث لنفس الرابط مع تباعد زمني متزايد، ولا تستسلم.
   تُستخدم عندما تفشل محاولات الاسترداد الخفيفة. */
var _hardReloadTimer=null, _hardReloadUrl='', _hardReloadSub='';
function _hardReloadStream(url){
  // إن استُنفدت محاولات الرابط الأساسي ويوجد رابط احتياطي لم يُستخدم بعد — بدّل إليه فوراً
  if(PL.backupUrl && !PL.usedBackup && url!==PL.backupUrl && (PL._hlsHardRetry||0)>=3){
    PL.usedBackup=true;
    PL._hlsHardRetry=0;PL._hlsNetRetry=0;PL._hlsMediaRetry=0;
    if(_hardReloadTimer){clearTimeout(_hardReloadTimer);_hardReloadTimer=null;}
    toast('تعذّر الرابط الأساسي — جارٍ التبديل للرابط الاحتياطي...');
    try{ initStream(PL.backupUrl, _hardReloadSub||''); }catch(_){}
    return;
  }
  // حدّ أقصى للمحاولات السريعة المتتالية قبل المباعدة الأطول
  PL._hlsHardRetry=(PL._hlsHardRetry||0)+1;
  if(_hardReloadTimer)clearTimeout(_hardReloadTimer);
  // تباعد متزايد: 1ث، 2ث، 3ث ... بحد أقصى 8ث (يحاول للأبد بهدوء)
  const wait=Math.min(PL._hlsHardRetry,8)*1000;
  showBuf(true);
  if(PL._hlsHardRetry<=2) toast('إعادة تشغيل القناة...');
  _hardReloadTimer=setTimeout(function(){
    const overlay=document.getElementById('playerOverlay');
    // لا نعيد إن أُغلق المشغل أو غيّر المستخدم القناة
    if(!overlay||!overlay.classList.contains('active'))return;
    if(!_hardReloadUrl || _hardReloadUrl!==url)return;
    try{ initStream(url, _hardReloadSub||''); }catch(_){}
  }, wait);
}

function initStream(url,subUrl){
  // نتذكّر الرابط الحالي حتى يعرف الاسترداد التلقائي ما يعيد تشغيله
  _hardReloadUrl=url; _hardReloadSub=subUrl||'';
  const v=document.getElementById('html5Player');
  destroyPlayer();

  // إعادة إنشاء عنصر الفيديو بالكامل — يضمن حذف الـ tracks القديمة ونظافة كاملة
  const newV=document.createElement('video');
  newV.id='html5Player';
  newV.setAttribute('playsinline','');
  newV.setAttribute('preload','auto');

  // ══ ضمان الجودة الأصلية — CSS inline لا يُلغيه أي قاعدة خارجية ══
  newV.style.cssText=[
    'width:100%',
    'height:100%',
    'object-fit:contain',
    'transform:none',
    'filter:none',
    'opacity:1',
    'image-rendering:high-quality',
    'will-change:auto',
    'display:block'
  ].join(';');

  const pvWrap=document.getElementById('pvWrap');
  const oldV=pvWrap.querySelector('video#html5Player');
  if(oldV)pvWrap.replaceChild(newV,oldV);
  else pvWrap.insertBefore(newV,pvWrap.firstChild);

  // ══ نظام الترجمة — يدعم VTT و SRT تلقائياً ══
  if(subUrl&&subUrl.trim()){
    document.getElementById('subBtn').style.opacity='1';
    PL.subtitleOn=true;
    _loadSubtitle(newV, subUrl);
  }else{
    document.getElementById('subBtn').style.opacity='0.4';
    PL.subtitleOn=false;
  }

  const fmt=detectFmt(url);showBuf(true);

  // FIX: HLS مع إعدادات جودة محسّنة
  if(fmt==='hls'){
    /* hls.js يُحمّل async — لو ضغط المستخدم بسرعة قد لا يكون جاهزاً بعد.
       ننتظره لحظات بدل السقوط مباشرة إلى src (الذي يفشل في أغلب المتصفحات). */
    if(typeof Hls==='undefined' && !newV.canPlayType('application/vnd.apple.mpegurl')){
      let _tries=0;
      const _waitHls=setInterval(()=>{
        if(typeof Hls!=='undefined'){ clearInterval(_waitHls); initStream(url,subUrl); }
        else if(++_tries>40){ clearInterval(_waitHls); showBuf(false); toast('تعذّر تحميل مشغّل البث'); }
      },50);
      return;
    }
    if(typeof Hls!=='undefined'&&Hls.isSupported()){
      PL.hls=new Hls({
        enableWorker:true,
        /* lowLatencyMode كان true — وهو يقلّص المخزن عمداً لتقليل التأخير،
           فيسبّب تقطيعاً وهبوطاً في الجودة. أُطفئ لصالح أعلى جودة واستقرار. */
        lowLatencyMode:false,

        /* ══ بلا أي تحديد للسرعة أو الجودة ══
           كل القيود أُزيلت: لا سقف للجودة، لا سقف للسرعة، لا سقف للمخزن.
           المشغّل يأخذ أعلى جودة متاحة ويستهلك كامل سرعة الاتصال. */

        // ── المخزن: قيم متوازنة للبث المباشر (تمنع تراكم التأخير) ──
        maxBufferLength:30,
        maxMaxBufferLength:60,
        maxBufferSize:60*1000*1000,
        backBufferLength:30,
        maxBufferHole:0.5,

        // ══ مزامنة البث الحيّ — الإصلاح الأساسي لتشوّه الصوت ══
        liveSyncDurationCount:3,
        liveMaxLatencyDurationCount:10,
        liveDurationInfinity:true,
        maxLiveSyncPlaybackRate:1.0,      // ← يمنع تسريع التشغيل الذي يشوّه الصوت

        // ── الجودة: أعلى مستوى دائماً ──
        capLevelToPlayerSize:false,       // ← الأهم: كان يقيّد الجودة بحجم المشغّل
        capLevelOnFPSDrop:false,          // لا يخفض الجودة عند تذبذب الإطارات
        startLevel:-1,                    // يبدأ بأفضل ما تسمح به السرعة المقدّرة
        autoStartLoad:true,

        // ── ABR: يستغل كامل عرض النطاق ──
        abrEwmaDefaultEstimate:50000000,  // 50Mbps تقدير مبدئي عالٍ → يبدأ بأعلى جودة
        abrEwmaFastLive:2.0,
        abrEwmaSlowLive:5.0,
        abrBandWidthFactor:1.0,           // ← يستخدم 100% من السرعة (كان 0.9)
        abrBandWidthUpFactor:1.0,         // ← يرفع الجودة بلا تحفّظ (كان 0.8)
        abrMaxWithRealBitrate:false,
        testBandwidth:false,              // لا جولة قياس تبطئ البدء

        // ── التحميل: متوازٍ وسريع ──
        startFragPrefetch:true,
        progressive:false,
        fragLoadingMaxRetry:8,
        fragLoadingRetryDelay:300,
        fragLoadingMaxRetryTimeout:64000,
        manifestLoadingMaxRetry:6,
        manifestLoadingRetryDelay:300,
        levelLoadingMaxRetry:6,
        levelLoadingRetryDelay:300,
        maxStarvationDelay:4,
        maxLoadingDelay:4,
        highBufferWatchdogPeriod:1,
        nudgeMaxRetry:6,
      });
      PL.hls.attachMedia(newV);
      PL.hls.loadSource(url);
      PL.hls.on(Hls.Events.MANIFEST_PARSED,(e,data)=>{
        /* ══ فرض أعلى جودة متاحة ══
           نختار أعلى مستوى في القائمة صراحةً، ثم نُعيد التبديل التلقائي
           كي يبقى على الأعلى ما دامت السرعة تسمح — بلا أي سقف. */
        try{
          const levels = (data && data.levels) || PL.hls.levels || [];
          if(levels.length > 1){
            let best = 0, bw = -1;
            levels.forEach((lv,i)=>{ const b = lv.bitrate||0; if(b > bw){ bw = b; best = i; } });
            PL.hls.nextLevel = best;      // ابدأ فوراً بأعلى جودة
            PL.hls.autoLevelCapping = -1; // -1 = بلا سقف إطلاقاً
          }
        }catch(_){}
        newV.play().catch(()=>{});
      });
      // نبدأ التشغيل أيضاً عند أول جزء جاهز (أيّهما أسبق)
      PL.hls.on(Hls.Events.FRAG_LOADED,()=>{ if(newV.paused && !PL.userPaused) newV.play().catch(()=>{}); });
      // ══ استرداد تلقائي قوي — يعيد تشغيل القناة بالكامل عند توقفها، بلا خروج يدوي ══
      PL._hlsNetRetry=0;   // عداد أخطاء الشبكة
      PL._hlsMediaRetry=0; // عداد أخطاء الميديا
      PL._hlsHardRetry=0;  // عداد إعادة البناء الكاملة
      PL.hls.on(Hls.Events.ERROR,(e,d)=>{
        if(!d.fatal){return;} // غير القاتلة يعالجها hls.js وحده
        // ── تبديل ذكي سريع: إن فشل تحميل القائمة/الـ manifest الأساسي كلياً ووُجد رابط احتياطي، انتقل فوراً ──
        if(PL.backupUrl && !PL.usedBackup && url!==PL.backupUrl &&
           (d.details===Hls.ErrorDetails.MANIFEST_LOAD_ERROR ||
            d.details===Hls.ErrorDetails.MANIFEST_LOAD_TIMEOUT ||
            d.details===Hls.ErrorDetails.MANIFEST_PARSING_ERROR)){
          PL.usedBackup=true;
          PL._hlsHardRetry=0;PL._hlsNetRetry=0;PL._hlsMediaRetry=0;
          toast('تعذّر الرابط الأساسي — جارٍ التبديل للرابط الاحتياطي...');
          try{ initStream(PL.backupUrl, _hardReloadSub||''); }catch(_){}
          return;
        }
        if(d.type===Hls.ErrorTypes.NETWORK_ERROR){
          showBuf(true);
          PL._hlsNetRetry++;
          if(PL._hlsNetRetry<=3){
            // محاولة خفيفة: إعادة تشغيل التحميل
            try{ PL.hls.startLoad(); }catch(_){}
          } else {
            // الشبكة قُطعت فعلاً: إعادة بناء كاملة للقناة (مثل الخروج والدخول يدوياً)
            _hardReloadStream(url);
          }
        } else if(d.type===Hls.ErrorTypes.MEDIA_ERROR){
          showBuf(true);
          PL._hlsMediaRetry++;
          try{
            if(PL._hlsMediaRetry<=2){ PL.hls.recoverMediaError(); }
            else { _hardReloadStream(url); }
          }catch(_){ _hardReloadStream(url); }
        } else {
          // أي خطأ قاتل آخر: إعادة بناء كاملة
          _hardReloadStream(url);
        }
      });
      // عند نجاح تحميل أي مقطع، نصفّر كل العدّادات (البث تعافى)
      PL.hls.on(Hls.Events.FRAG_BUFFERED,()=>{ PL._hlsNetRetry=0; PL._hlsMediaRetry=0; PL._hlsHardRetry=0; });
      // ══ حارس البث الحيّ: يعيد الصوت لطبيعته دون تحديث الصفحة ══
      if(PL._liveGuard){ clearInterval(PL._liveGuard); PL._liveGuard=null; }
      PL._liveGuard=setInterval(function(){
        try{
          if(!PL.hls||newV.paused) return;
          if(newV.playbackRate!==1) newV.playbackRate=1;   // تصفير أي تسريع علق عليه المشغّل
          var pos=PL.hls.liveSyncPosition;
          if(pos!=null && (pos-newV.currentTime)>12){
            newV.currentTime=pos;   // قفزة للحافة الحيّة بدل اللحاق بتسريع الصوت
          }
        }catch(_){}
      },5000);
    }else if(newV.canPlayType('application/vnd.apple.mpegurl')){
      newV.src=url;newV.play().catch(()=>{});
    }else{newV.src=url;newV.play().catch(()=>{});}
  }else if(fmt==='dash'){
    if(typeof dashjs!=='undefined'){
      PL.dash=dashjs.MediaPlayer().create();
      PL.dash.initialize(newV,url,true);
      /* ══ DASH بلا تحديد سرعة أو جودة ══ */
      PL.dash.updateSettings({
        streaming:{
          buffer:{
            bufferTimeAtTopQuality:600,
            bufferTimeAtTopQualityLongForm:900,
            bufferToKeep:120,
          },
          abr:{
            autoSwitchBitrate:{video:true, audio:true},
            limitBitrateByPortal:false,   // لا يقيّد الجودة بحجم المشغّل
            usePixelRatioInLimitBitrateByPortal:false,
            maxBitrate:{video:-1, audio:-1},   // -1 = بلا سقف
            minBitrate:{video:-1, audio:-1},
            initialBitrate:{video:-1},         // يبدأ بأعلى ما تسمح به السرعة
          },
        },
      });
    }else{newV.src=url;newV.play().catch(()=>{});}
  }else if(fmt==='flv'){
    if(typeof flvjs!=='undefined'&&flvjs.isSupported()){
      PL.flv=flvjs.createPlayer(
        {type:'flv',url},
        {
          enableWorker:true,
          enableStashBuffer:true,     // مخزن مفعّل بدل معطّل
          stashInitialSize:1024,      // مخزن مبدئي أكبر
          autoCleanupSourceBuffer:true,
          lazyLoad:false,             // لا تحميل كسول — حمّل بأقصى سرعة
          lazyLoadMaxDuration:600,
        }
      );
      PL.flv.attachMediaElement(newV);PL.flv.load();PL.flv.play();
      PL.flv.on(flvjs.Events.ERROR,()=>{toast('خطأ في FLV');showBuf(false);});
    }else{toast('المتصفح لا يدعم FLV');showBuf(false);}
  }else{
    // MP4, MKV, WEBM — مشغل مباشر
    /* نبدأ التشغيل فور توفّر أول جزء قابل للعرض بدل انتظار تحميل بيانات أكثر.
       loadedmetadata يصل أبكر بكثير من loadeddata. */
    newV.addEventListener('loadedmetadata', ()=>{ newV.play().catch(()=>{}); }, {once:true});
    newV.addEventListener('canplay',        ()=>{ newV.play().catch(()=>{}); }, {once:true});
    newV.src=url;
    newV.load();
    newV.play().catch(()=>{});
  }

  newV.volume=PL.vol;newV.muted=PL.muted;
  newV.ontimeupdate=updateProgress;
  newV.onwaiting=()=>showBuf(true);
  newV.onplaying=()=>{showBuf(false);setPlayIcon(false);PL.userPaused=false;};
  newV.onpause=()=>setPlayIcon(true);
  newV.onloadeddata=()=>showBuf(false);
  /* نُخفي مؤشر التحميل بمجرد أن تصبح أول صورة جاهزة — أبكر من loadeddata */
  newV.onloadedmetadata=()=>showBuf(false);
  newV.oncanplay=()=>showBuf(false);
  newV.onerror=()=>{
    showBuf(false);
    if(PL.backupUrl && !PL.usedBackup && url!==PL.backupUrl){
      PL.usedBackup=true;
      toast('تعذّر الرابط الأساسي — جارٍ التبديل للرابط الاحتياطي...');
      try{ initStream(PL.backupUrl, subUrl||''); }catch(_){}
    }else{
      toast('تعذر تحميل الفيديو');
    }
  };
  newV.onended=()=>{
    if(App.currentType==='episode'&&App.currentEpisodeIdx<App.allEpisodes.length-1){
      toast('انتقال للحلقة التالية...');
      setTimeout(()=>navEpisode(1),2000);
    }
    if(PL.m3uEntries.length&&PL.m3uIdx<PL.m3uEntries.length-1)playM3UEntry(PL.m3uIdx+1);
  };

  // FIX: تحديث _lastUrl للـ watchdog
  _lastUrl=url;
}

/* destroyPlayer — تنظيف كامل مع تحرير Blob URLs */
function destroyPlayer(){
  if(PL._liveGuard){ clearInterval(PL._liveGuard); PL._liveGuard=null; }
  if(PL.hls){try{PL.hls.destroy();}catch(e){}PL.hls=null;}
  if(PL.dash){try{PL.dash.reset();}catch(e){}PL.dash=null;}
  if(PL.flv){try{PL.flv.destroy();}catch(e){}PL.flv=null;}
  const v=document.getElementById('html5Player');
  if(v){
    v.ontimeupdate=null;v.onwaiting=null;v.onplaying=null;v.onpause=null;
    v.onloadeddata=null;v.onerror=null;v.onended=null;
    try{v.pause();}catch(e){}
    // إزالة tracks وتحرير أي Blob URLs
    const tracks=Array.from(v.querySelectorAll('track'));
    tracks.forEach(t=>{
      try{
        if(t.src && t.src.startsWith('blob:')) URL.revokeObjectURL(t.src);
        v.removeChild(t);
      }catch(e){}
    });
    try{v.removeAttribute('src');v.load();}catch(e){}
  }
  // إعادة ضبط زر الترجمة
  const subBtn=document.getElementById('subBtn');
  if(subBtn){subBtn.style.opacity='0.4';subBtn.style.color='';subBtn.classList.remove('sub-active');}
  PL.subtitleOn=false;
  showBuf(false);
}

/* ════ M3U ════ */
async function parseM3U(urlOrText){
  let text=urlOrText;
  if(urlOrText.startsWith('http')||urlOrText.startsWith('//')){try{const r=await fetch(urlOrText);text=await r.text();}catch(e){toast('تعذر تحميل M3U');return[];}}
  const entries=[];let cur={};
  for(const line of text.split('\n').map(l=>l.trim()).filter(Boolean)){
    if(line.startsWith('#EXTM3U'))continue;
    if(line.startsWith('#EXTINF')){cur={};const ci=line.lastIndexOf(',');cur.name=ci>=0?line.slice(ci+1).trim():'بدون اسم';const lm=line.match(/tvg-logo="([^"]+)"/i);cur.logo=lm?lm[1]:'';const gm=line.match(/group-title="([^"]+)"/i);cur.group=gm?gm[1]:'';}
    else if(!line.startsWith('#')&&(line.startsWith('http')||line.startsWith('/'))){cur.url=line;entries.push({...cur});cur={};}
  }
  return entries;
}
function playM3UEntry(idx){
  if(idx<0||idx>=PL.m3uEntries.length)return;
  try{sessionStorage.setItem('shs_restore',JSON.stringify({type:'m3u',idx:idx,entries:PL.m3uEntries,name:PL.m3uName}));}catch(e){}
  PL.m3uIdx=idx;const e=PL.m3uEntries[idx];
  PL.backupUrl='';PL.usedBackup=false;
  document.getElementById('pChannelName').textContent=e.name;
  document.getElementById('pFmtTag').textContent=fmtLabel(e.url);
  document.getElementById('pBadgeLabel').textContent=isLiveFormat(e.url)?'LIVE':fmtLabel(e.url);
  initStream(e.url,'');
  document.querySelectorAll('.m3u-item').forEach((el,i)=>el.classList.toggle('playing',i===idx));
  toast('▶ '+e.name);
}
function buildM3UPanel(){
  document.getElementById('m3uPanelHead').textContent='قائمة التشغيل ('+PL.m3uEntries.length+')';
  const b=document.getElementById('m3uPanelBody');b.innerHTML='';
  PL.m3uEntries.forEach((e,idx)=>{
    const d=document.createElement('div');d.className='m3u-item'+(idx===PL.m3uIdx?' playing':'');
    const lh=e.logo?`<img class="m3u-item-logo" src="${esc(e.logo)}" loading="lazy">`:`<div class="m3u-item-logo" style="display:flex;align-items:center;justify-content:center">📺</div>`;
    d.innerHTML=`${lh}<div><div class="m3u-item-name">${esc(e.name)}</div><div class="m3u-item-group">${esc(e.group||fmtLabel(e.url))}</div></div>`;
    d.onclick=()=>playM3UEntry(idx);b.appendChild(d);
  });
}

/* ════ EP PANEL ════ */
function buildEpPanel(){
  document.getElementById('epPanelTitle').textContent=App.currentSeriesName;
  const b=document.getElementById('epPanelBody');b.innerHTML='';
  App.allEpisodes.forEach((ep,idx)=>{
    const d=document.createElement('div');d.className='ep-item'+(idx===App.currentEpisodeIdx?' playing':'');
    d.innerHTML=`<div class="ep-item-num">${ep.episode_number}</div><div class="ep-item-info"><div class="ep-item-title">${esc(ep.title)}</div><div style="font-size:.7rem;color:#666">${fmtLabel(ep.stream_url||'')}</div></div><div class="ep-item-play">▶</div>`;
    d.onclick=()=>{openPlayerEpisode(idx);if(window.innerWidth<=768)toggleEpPanel();};
    b.appendChild(d);
  });
}

/* ════ CONTROLS ════ */
function updateProgress(){
  const v=document.getElementById('html5Player');
  if(!v||!v.duration||isNaN(v.duration))return;
  const p=(v.currentTime/v.duration)*100;
  document.getElementById('pFill').style.width=p+'%';
  const cur=ft(v.currentTime),tot=ft(v.duration);
  document.getElementById('pTime').textContent=cur+' / '+tot;
  const ec=document.getElementById('pTimeCur'),et=document.getElementById('pTimeTotal');
  if(ec)ec.textContent=cur;if(et)et.textContent=tot;
}
function seekTo(e){
  const v=document.getElementById('html5Player');
  if(!v||!v.duration||isNaN(v.duration))return;
  const r=document.getElementById('pProgress').getBoundingClientRect();
  v.currentTime=((e.clientX-r.left)/r.width)*v.duration;
  updateProgress();
}
function _syncVolUI(){
  const fill=document.getElementById('volFill');
  const thumb=document.getElementById('volThumb');
  const pct=(PL.muted?0:PL.vol)*100;
  if(fill)fill.style.width=pct+'%';
  if(thumb)thumb.style.right=(100-pct)+'%';
  const ic=document.getElementById('muteIcon');
  if(ic){
    const icon=PL.muted||PL.vol===0?'🔇':PL.vol<0.5?'🔉':'🔊';
    ic.innerHTML=`<span style="font-size:1.2rem">${icon}</span>`;
  }
}
function setVolume(e){
  const r=e.currentTarget.getBoundingClientRect();
  const p=Math.max(0,Math.min(1,(e.clientX-r.left)/r.width));
  const v=document.getElementById('html5Player');
  if(v){v.volume=p;v.muted=(p===0);}
  PL.vol=p;PL.muted=(p===0);_syncVolUI();
}
function changeVol(d){
  const nv=Math.max(0,Math.min(1,PL.vol+d));
  const v=document.getElementById('html5Player');
  if(v){v.volume=nv;v.muted=(nv===0);}
  PL.vol=nv;if(nv>0)PL.muted=false;_syncVolUI();
  toast('الصوت: '+Math.round(nv*100)+'%');
}
function toggleMute(){
  const v=document.getElementById('html5Player');
  PL.muted=!PL.muted;if(v)v.muted=PL.muted;_syncVolUI();
  toast(PL.muted?'كتم الصوت':'تفعيل الصوت');
}
function togglePlay(){
  const v=document.getElementById('html5Player');
  if(!v)return;
  // ── إصلاح التلفاز: نعتمد على نية المستخدم الصريحة (PL.userPaused) بدل v.paused ──
  // لأن التلفاز يجعل v.paused=true مؤقتاً أثناء التقديم/البَفرة، فيختلّ المنطق
  // ويبقى userPaused عالقاً → القائمة لا تختفي. هنا نعكس النية المنطقية لا حالة العنصر.
  if(PL.userPaused){
    // النية: تشغيل
    PL.userPaused=false;
    v.play().catch(()=>{});
  } else {
    // النية: إيقاف
    PL.userPaused=true;
    try{ v.pause(); }catch(e){}
  }
  setPlayIcon(PL.userPaused);
  // إعادة ضبط مؤقّت الإخفاء دائماً بعد التبديل — يضمن اختفاء القائمة تلقائياً
  // حتى لو جاء التبديل بعد تقديم/تأخير على التلفاز.
  showControls();
}
function setPlayIcon(p){
  document.getElementById('playBtn').innerHTML=p?
    '<span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg></span>':
    '<span class="lcn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="4" height="16" x="6" y="4"/><rect width="4" height="16" x="14" y="4"/></svg></span>';
}
function flash(t){
  const el=document.getElementById('pFlash');
  el.innerHTML=`<span style="font-size:2rem">${t==='play'?'▶':'⏸'}</span>`;
  el.classList.add('show');setTimeout(()=>el.classList.remove('show'),400);
}
function skip(s){
  const v=document.getElementById('html5Player');
  if(!v)return;
  v.currentTime=Math.max(0,Math.min(v.currentTime+s,v.duration||0));
  updateProgress();
  showControls(); // إعادة ضبط مؤقّت الإخفاء بعد التقديم/التأخير (مهم للتلفاز)
}
function ft(s){const m=Math.floor(s/60),ss=Math.floor(s%60);return String(m).padStart(2,'0')+':'+String(ss).padStart(2,'0');}

/* ════ SUBTITLE TOGGLE ════ */
function toggleSubtitle(){
  const v=document.getElementById('html5Player');
  if(!v) return;
  const tracks=v.textTracks;
  if(!tracks||!tracks.length){
    toast('لا تتوفر ترجمة');
    return;
  }
  PL.subtitleOn=!PL.subtitleOn;
  for(let i=0;i<tracks.length;i++){
    tracks[i].mode=PL.subtitleOn?'showing':'hidden';
  }
  const btn=document.getElementById('subBtn');
  if(btn){
    btn.style.opacity=PL.subtitleOn?'1':'0.6';
    btn.style.color=PL.subtitleOn?'#ff4d57':'';
  }
  toast(PL.subtitleOn?'✓ الترجمة مفعّلة':'✕ الترجمة مُوقفة');
}

const ENH_MODES=[
  {cls:'',label:'قياسي',msg:'وضع قياسي'},
  {cls:'enh-deblock',label:'DeBlock',msg:'De-Block — إزالة تشوهات البكسل'},
  {cls:'enh-hdr',label:'HDR',msg:'HDR — تحسين الألوان'},
  {cls:'enh-frame',label:'Frame+',msg:'Frame+ — تحسين الوضوح'},
  {cls:'enh-full',label:'Ultra',msg:'Ultra — تحسين شامل'}
];
let _enhIdx=0;
function toggleEnhancements(){
  const v=document.getElementById('html5Player');
  const b=document.getElementById('enhanceBtn');
  const lbl=document.getElementById('enhLabel');
  ENH_MODES.forEach(m=>{if(m.cls&&v)v.classList.remove(m.cls);});
  _enhIdx=(_enhIdx+1)%ENH_MODES.length;
  const mode=ENH_MODES[_enhIdx];
  if(mode.cls&&v)v.classList.add(mode.cls);
  if(lbl)lbl.textContent=mode.label;
  b.classList.toggle('active-magic',_enhIdx>0);
  b.style.opacity=_enhIdx===0?'0.6':'1';
  toast(mode.msg);
}

function showBuf(s){document.getElementById('pBuffer').classList.toggle('show',s);}

function showControls(){
  const r=document.getElementById('playerOverlay');
  const top=document.getElementById('pTop');
  const bot=document.getElementById('pBottom');
  const cen=document.getElementById('pCenter');
  r.classList.remove('idle');
  if(top)top.classList.remove('hide');
  if(bot)bot.classList.remove('hide');
  if(cen)cen.classList.remove('hide');
  clearTimeout(PL.idle);
  const delay=_isTV?6000:4000;
  PL.idle=setTimeout(function(){
    const v=document.getElementById('html5Player');
    if(!v)return;
    // نخفي القوائم طالما المستخدم لم يوقف الفيديو يدوياً.
    // نعتمد على PL.userPaused بدل v.paused لأن التلفاز يجعل v.paused=true
    // مؤقتاً أثناء التقديم/البَفرة فتبقى القوائم ظاهرة بلا داعٍ.
    if(!PL.userPaused&&!PL.epPanelOpen&&!PL.m3uPanelOpen){
      if(top)top.classList.add('hide');
      if(bot)bot.classList.add('hide');
      if(cen)cen.classList.add('hide');
      r.classList.add('idle');
      // مسح تركيز الريموت البصري على التلفاز عند إخفاء القوائم
      if(_isTV&&window._clearPlayerFocus){try{window._clearPlayerFocus();}catch(e){}}
    }
  },delay);
}

function fixPlayerHeight(){const el=document.getElementById('playerOverlay');if(!el)return;el.style.height=window.innerHeight+'px';}

/* ════ PLAYER EVENTS ════ */
document.addEventListener('DOMContentLoaded',function(){
  const wrap=document.getElementById('pvWrap');
  const overlay=document.getElementById('playerOverlay');
  let _lastTap=0;
  wrap.addEventListener('touchstart',function(e){
    const now=Date.now();const diff=now-_lastTap;_lastTap=now;
    if(diff<280&&diff>0){
      e.preventDefault();
      const t=e.changedTouches[0];
      const rect=wrap.getBoundingClientRect();
      const x=t.clientX-rect.left;
      if(x<rect.width/3)skip(-10);else if(x>(rect.width/3)*2)skip(10);else togglePlay();
    }else{showControls();}
  },{passive:false});
  wrap.addEventListener('click',showControls);
  wrap.addEventListener('dblclick',function(e){
    const rect=wrap.getBoundingClientRect();const x=e.clientX-rect.left;
    if(x<rect.width/3)skip(-10);else if(x>(rect.width/3)*2)skip(10);else togglePlay();
  });
  overlay.addEventListener('mousemove',showControls,{passive:true});
  window.addEventListener('resize',fixPlayerHeight,{passive:true});
  window.addEventListener('orientationchange',()=>setTimeout(fixPlayerHeight,300),{passive:true});
  fixPlayerHeight();
});

window.addEventListener('popstate',function(){window._goBack();});

var _fsActive=false, _fsMethod='none';

function _setFsIcon(on){
  const fi=document.getElementById('fsIcon');
  if(!fi)return;
  fi.outerHTML=on?
    '<span class="lcn" id="fsIcon"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 15 6 6m-6-6v4.8m0-4.8h4.8"/><path d="M9 19.8V15m0 0H4.2M9 15l-6 6"/><path d="M15 4.2V9m0 0h4.8M15 9l6-6"/><path d="M9 4.2V9m0 0H4.2M9 9 3 3"/></svg></span>':
    '<span class="lcn" id="fsIcon"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21 21-6-6m6 6v-4.8m0 4.8h-4.8"/><path d="M3 16.2V21m0 0h4.8M3 21l6-6"/><path d="M21 7.8V3m0 0h-4.8M21 3l-6 6"/><path d="M3 7.8V3m0 0h4.8M3 3l6 6"/></svg></span>';
}
function _cssFS(on){
  const ov=document.getElementById('playerOverlay');
  if(on){ov.classList.add('p-native-fs');document.body.style.overflow='hidden';_fsActive=true;_fsMethod='css';}
  else{ov.classList.remove('p-native-fs');document.body.style.overflow='';_fsActive=false;_fsMethod='none';}
}
function _lockL(){try{if(screen.orientation&&typeof screen.orientation.lock==='function')screen.orientation.lock('landscape').catch(()=>{});}catch(e){}}
function _unlockL(){try{if(screen.orientation&&typeof screen.orientation.unlock==='function')screen.orientation.unlock();}catch(e){}}

async function toggleFullscreen(){
  const ov  = document.getElementById('playerOverlay');
  const vid = document.getElementById('html5Player');

  // هل نحن الآن في fullscreen؟
  const inFS = !!(
    document.fullscreenElement       ||
    document.webkitFullscreenElement  ||
    document.mozFullScreenElement     ||
    (_fsActive && _fsMethod === 'css')
  );

  /* ══ دخول fullscreen ══ */
  if(!inFS){

    /* 1. TV حقيقي (Android TV, Smart TV) → CSS fullscreen */
    if(_isTV){
      _cssFS(true);
      _setFsIcon(true);
      return;
    }

    /* 2. iOS Safari → webkitEnterFullscreen على الـ video */
    if(_isIOS){
      try{
        if(vid && vid.webkitEnterFullscreen){
          vid.webkitEnterFullscreen();
          _fsActive = true;
          _fsMethod = 'ios';
          _setFsIcon(true);
        }
      }catch(e){}
      return;
    }

    /* 3. كمبيوتر / Android Mobile → Fullscreen API على الـ overlay */
    const req = ov.requestFullscreen
             || ov.webkitRequestFullscreen
             || ov.mozRequestFullScreen
             || ov.msRequestFullscreen;

    if(req){
      try{
        await req.call(ov);
        _fsActive = true;
        _fsMethod = 'api';
        _setFsIcon(true);
        // قفل landscape على الموبايل فقط
        if(_UA.isAndroidMobile) _lockL();
      }catch(err){
        // Fullscreen API رفض (مثل iframe sandbox) → CSS fallback
        _cssFS(true);
        _setFsIcon(true);
      }
    }else{
      // المتصفح لا يدعم API أصلاً → CSS
      _cssFS(true);
      _setFsIcon(true);
    }

  /* ══ خروج من fullscreen ══ */
  }else{

    _setFsIcon(false);

    /* TV أو CSS mode */
    if(_fsMethod === 'css' || _isTV){
      _cssFS(false);
      return;
    }

    /* iOS */
    if(_fsMethod === 'ios'){
      // iOS يخرج تلقائياً عند الضغط على زر الـ video
      _fsActive = false;
      _fsMethod = 'none';
      return;
    }

    /* API (كمبيوتر / موبايل) */
    _cssFS(false); // أزل CSS class احتياطاً
    _unlockL();
    try{
      const exit = document.exitFullscreen
                || document.webkitExitFullscreen
                || document.mozCancelFullScreen
                || document.msExitFullscreen;
      if(exit && (document.fullscreenElement || document.webkitFullscreenElement)){
        await exit.call(document);
      }
      _fsActive = false;
      _fsMethod = 'none';
    }catch(e){
      _fsActive = false;
      _fsMethod = 'none';
    }
  }
}

(function(){
  function onFSChange(){
    const isFS=!!(
      document.fullscreenElement      ||
      document.webkitFullscreenElement ||
      document.mozFullScreenElement
    );
    if(isFS){
      _fsActive=true;
      _setFsIcon(true);
      // قفل landscape على الموبايل فقط لا الكمبيوتر
      if(_UA.isAndroidMobile) _lockL();
    }else{
      // خرج من fullscreen (زر Esc أو زر المتصفح)
      if(_fsMethod!=='css'){
        _fsActive=false;
        _fsMethod='none';
        _cssFS(false);
        _unlockL();
      }
      _setFsIcon(false);
    }
  }
  document.addEventListener('fullscreenchange',      onFSChange);
  document.addEventListener('webkitfullscreenchange',onFSChange);
  document.addEventListener('mozfullscreenchange',   onFSChange);
})();

/* ════ TV CONTROL ════ */
(function(){
  var _idx=-1,_btns=[];
  window._playerTvFocusActive=false;
  function getBtns(){
    return Array.from(document.querySelectorAll('#playerOverlay .p-btn,#playerOverlay .p-play-btn,#playerOverlay .p-seek-btn'))
      .filter(b=>b.offsetParent!==null&&b.style.display!=='none');
  }
  function applyFocus(idx){
    _btns=getBtns();
    _btns.forEach(b=>{b.style.outline='';b.style.background='';b.style.transform='';b.style.boxShadow='';});
    _idx=(idx>=0&&idx<_btns.length)?idx:-1;
    if(_idx<0){window._playerTvFocusActive=false;return;}
    const b=_btns[_idx];
    b.style.outline='3px solid #fff';b.style.background='rgba(229,9,20,.65)';
    b.style.transform='scale(1.25)';b.style.boxShadow='0 0 0 5px rgba(229,9,20,.35)';
    window._playerTvFocusActive=true;
  }
  function clearAll(){getBtns().forEach(b=>{b.style.outline='';b.style.background='';b.style.transform='';b.style.boxShadow='';});_idx=-1;_btns=[];window._playerTvFocusActive=false;}
  window._clearPlayerFocus=clearAll;
  function activate(){showControls();const all=getBtns();const pi=all.findIndex(b=>b.id==='playBtn');applyFocus(pi>=0?pi:Math.floor(all.length/2));}
  document.addEventListener('keydown',function(e){
    if(!document.getElementById('playerOverlay').classList.contains('active'))return;
    var kc=e.keyCode||e.which||0,ks=e.key||'';
    if(kc===116 || ks==='F5' || (e.ctrlKey && (kc===82 || ks==='r' || ks==='R'))){
      e.preventDefault();
      e.stopPropagation();
      var u = (typeof _lastUrl!=='undefined' && _lastUrl) || (typeof _hardReloadUrl!=='undefined' && _hardReloadUrl);
      if(u && typeof _hardReloadStream === 'function') _hardReloadStream(u);
      return;
    }
    if(kc===27||kc===8||kc===4||kc===10009||ks==='Escape'||ks==='BrowserBack'){
      e.preventDefault();e.stopPropagation();
      var isFS=!!(document.fullscreenElement||document.webkitFullscreenElement||_fsActive);
      if(isFS){toggleFullscreen();}else{clearAll();closePlayer();}
      return;
    }
    if(ks==='MediaPlayPause'||kc===179||kc===415){e.preventDefault();togglePlay();showControls();return;}
    if(ks==='FastFwd'||kc===417){e.preventDefault();skip(30);return;}
    if(ks==='Rewind'||kc===412){e.preventDefault();skip(-30);return;}
    if(kc===175||kc===447){e.preventDefault();changeVol(.1);return;}
    if(kc===174||kc===448){e.preventDefault();changeVol(-.1);return;}
    if(kc===173||kc===449){e.preventDefault();toggleMute();return;}
    if(ks==='ChannelUp'||kc===427){e.preventDefault();if(App.currentType==='episode')navEpisode(1);return;}
    if(ks==='ChannelDown'||kc===428){e.preventDefault();if(App.currentType==='episode')navEpisode(-1);return;}
    var L=(ks==='ArrowLeft'||kc===37||kc===21);
    var R=(ks==='ArrowRight'||kc===39||kc===22);
    var U=(ks==='ArrowUp'||kc===38||kc===19);
    var D=(ks==='ArrowDown'||kc===40||kc===20);
    var OK=(ks==='Enter'||ks==='Select'||kc===13||kc===23);
    if(!L&&!R&&!U&&!D&&!OK)return;
    e.preventDefault();
    var hidden=document.getElementById('pBottom').classList.contains('hide');
    if(hidden||_idx<0){activate();return;}
    if(OK){var c=getBtns()[_idx];if(c)c.click();return;}
    var fresh=getBtns(),len=fresh.length;
    if(R&&_idx<len-1){_btns=fresh;applyFocus(_idx+1);}
    if(L&&_idx>0){_btns=fresh;applyFocus(_idx-1);}
    if(U)changeVol(.1);if(D)changeVol(-.1);
  },true);
  var _oc=window.closePlayer;
  window.closePlayer=function(){clearAll();if(_oc)_oc.apply(this,arguments);};
})();

/* ════ TV NAVIGATION (خارج المشغل) ════ */
var _tvFocus=null;
function _tvSetFocus(el){
  if(_tvFocus){_tvFocus.classList.remove('tv-focus');_tvFocus.style.outline='';}
  _tvFocus=el;if(!el)return;
  el.classList.add('tv-focus');
  el.scrollIntoView({behavior:'smooth',block:'center'});
  if(el.tagName!=='INPUT')try{el.focus({preventScroll:true});}catch(e){}
}
document.addEventListener('keydown',function(e){
  if(document.getElementById('playerOverlay').classList.contains('active'))return;
  if(document.getElementById('tmdbInfoM').classList.contains('open'))return;
  var ks=e.key||'',kc=e.keyCode||e.which||0;
  var K={UP:ks==='ArrowUp'||kc===38||kc===19,DOWN:ks==='ArrowDown'||kc===40||kc===20,LEFT:ks==='ArrowLeft'||kc===37||kc===21,RIGHT:ks==='ArrowRight'||kc===39||kc===22,OK:ks==='Enter'||ks==='Select'||ks===' '||kc===13||kc===23,BACK:ks==='Escape'||ks==='BrowserBack'||kc===27||kc===4||kc===10009||kc===8};
  if(!K.UP&&!K.DOWN&&!K.LEFT&&!K.RIGHT&&!K.OK&&!K.BACK)return;
  if(K.BACK){e.preventDefault();window._goBack();return;}
  var sel='.ch-card,.sr-card,.ep-card,.back-btn,.nav-btn,.info-action-btn,#searchInput,.ep-item,.m3u-item';
  var focusables=Array.from(document.querySelectorAll(sel)).filter(function(el){
    var r=el.getBoundingClientRect();
    return r.width>0&&r.height>0&&!el.closest('.hidden');
  });
  if(!focusables.length)return;
  if(K.OK){if(_tvFocus&&focusables.includes(_tvFocus)){if(_tvFocus.tagName==='INPUT'){try{_tvFocus.focus();}catch(e){}}else _tvFocus.click();e.preventDefault();}return;}
  e.preventDefault();
  if(!_tvFocus||!focusables.includes(_tvFocus)){_tvSetFocus(focusables[0]);return;}
  var cur=_tvFocus.getBoundingClientRect();var best=null,bestScore=Infinity;
  focusables.forEach(function(el){
    if(el===_tvFocus)return;
    var r=el.getBoundingClientRect();var cx=r.left+r.width/2,cy=r.top+r.height/2,ox=cur.left+cur.width/2,oy=cur.top+cur.height/2;
    var dx=cx-ox,dy=cy-oy,ok=false;
    if(K.RIGHT&&dx>20)ok=true;if(K.LEFT&&dx<-20)ok=true;if(K.DOWN&&dy>20)ok=true;if(K.UP&&dy<-20)ok=true;
    if(!ok)return;
    var primary=(K.UP||K.DOWN)?Math.abs(dy):Math.abs(dx),secondary=(K.UP||K.DOWN)?Math.abs(dx):Math.abs(dy);
    var score=primary+secondary*2;if(score<bestScore){bestScore=score;best=el;}
  });
  if(best)_tvSetFocus(best);
});

(function(){
  var s=document.createElement('style');
  s.textContent='.tv-focus{outline:3px solid #fff!important;outline-offset:4px!important;transform:scale(1.08) translateY(-4px)!important;z-index:999!important;border-color:var(--red)!important;box-shadow:0 15px 40px rgba(0,0,0,.9),0 0 35px rgba(229,9,20,.95)!important}.back-btn.tv-focus{outline:3px solid var(--red)!important;background:rgba(229,9,20,.25)!important;border-color:var(--red)!important;color:#fff!important}.nav-btn.tv-focus{outline:3px solid #fff!important;background:var(--red)!important;color:#fff!important}';
  document.head.appendChild(s);
})();

(function(){
  function applyTabindex(){
    document.querySelectorAll('.ch-card,.sr-card,.ep-card,.back-btn,.nav-btn,.ep-item,.m3u-item,.info-action-btn,#searchInput').forEach(function(el){if(!el.getAttribute('tabindex'))el.setAttribute('tabindex','0');});
  }
  if(window.MutationObserver){var obs=new MutationObserver(function(ms){var changed=false;ms.forEach(function(m){if(m.addedNodes.length)changed=true;});if(changed){clearTimeout(obs._t);obs._t=setTimeout(applyTabindex,150);}});obs.observe(document.body,{childList:true,subtree:true});}
  setTimeout(applyTabindex,600);setTimeout(applyTabindex,2000);
})();

/* ════ GESTURES + BACK NAVIGATION ════ */
(function(){
  window._goBack=function(){
    if(document.getElementById('playerOverlay').classList.contains('active')){
      var isFS=!!(document.fullscreenElement||document.webkitFullscreenElement||_fsActive);
      if(isFS){toggleFullscreen();return;}
      closePlayer();return;
    }
    var tmdb=document.getElementById('tmdbInfoM');if(tmdb&&tmdb.classList.contains('open')){closeTmdbModal();return;}
    var panels=['epPanel','m3uPanel','favPanel','notifPanel'];
    for(var i=0;i<panels.length;i++){
      if(document.getElementById(panels[i]).classList.contains('open')){
        document.getElementById(panels[i]).classList.remove('open');
        document.getElementById('panelOverlay').classList.remove('show');
        document.body.style.overflow='';return;
      }
    }
    if(!document.getElementById('epSection').classList.contains('hidden')){backFromEpisodesToHome();return;}
    if(!document.getElementById('searchViewSection').classList.contains('hidden')){clearSearchAndGoHome();return;}
    if(!document.getElementById('categoryViewSection').classList.contains('hidden')){closeCategoryView();return;}
  };
  var gsx=0,gsy=0,gActive=false;
  var EDGE=0.18,MIN_X=65,MAX_Y=65;
  document.addEventListener('touchstart',function(e){var t=e.changedTouches[0];gsx=t.screenX;gsy=t.screenY;var w=window.innerWidth;gActive=(gsx<w*EDGE)||(gsx>w*(1-EDGE));},{passive:true});
  document.addEventListener('touchend',function(e){if(!gActive)return;var t=e.changedTouches[0];var dx=t.screenX-gsx,dy=Math.abs(t.screenY-gsy);gActive=false;if(Math.abs(dx)<MIN_X||dy>MAX_Y)return;window._goBack();},{passive:true});

  document.addEventListener('DOMContentLoaded',function(){
    var wrap=document.getElementById('pvWrap');if(!wrap)return;
    var sx=0,sy=0,st=0;
    wrap.addEventListener('touchstart',function(e){var t=e.changedTouches[0];sx=t.clientX;sy=t.clientY;st=Date.now();},{passive:true});
    wrap.addEventListener('touchend',function(e){
      if(!document.getElementById('playerOverlay').classList.contains('active'))return;
      var t=e.changedTouches[0],dx=t.clientX-sx,dy=Math.abs(t.clientY-sy),dt=Date.now()-st;
      if(Math.abs(dx)>60&&dy<50&&dt<400){if(dx>0)skip(-15);else skip(15);}
    },{passive:true});
  });

  var _origOpenSeries=window.openSeriesEpisodes;
  window.openSeriesEpisodes=function(){history.pushState({depth:'episodes'},'');return _origOpenSeries.apply(this,arguments);};
})();

/* ════ WATCHDOG ════ */
let _lastUrl='',_stallTicks=0,_watchdogInt=null,_bgPauseTimer=null,_hiddenAt=0;
function _watchdogStart(){
  if(_watchdogInt)clearInterval(_watchdogInt);
  _stallTicks=0;let _prev=-1;
  _watchdogInt=setInterval(()=>{
    const v=document.getElementById('html5Player');
    const overlay=document.getElementById('playerOverlay');
    if(!v||!overlay||!overlay.classList.contains('active')){clearInterval(_watchdogInt);_watchdogInt=null;return;}
    if(v.paused||v.ended){_stallTicks=0;return;}
    // القناة "ماتت" تماماً (readyState=0 ومستمرة) — نعيد تشغيلها تلقائياً
    if(v.readyState===0){
      _stallTicks++;
      if(_stallTicks>=4){_stallTicks=0;const u=_lastUrl||_hardReloadUrl;if(u)_hardReloadStream(u);}
      _prev=v.currentTime;return;
    }
    if(v.currentTime===_prev&&v.readyState<3){
      _stallTicks++;
      // المرحلة 1 (تجمّد قصير): استرداد خفيف عبر hls.js دون إعادة بناء — بلا قفزة مرئية
      if(_stallTicks===3 && PL.hls){
        try{ PL.hls.startLoad(); }catch(_){}
      }
      // المرحلة 2 (تجمّد مستمر): إعادة تشغيل كاملة تلقائية لا تستسلم
      if(_stallTicks>=6){_stallTicks=0;const u=_lastUrl||_hardReloadUrl;if(u)_hardReloadStream(u);}
    }else _stallTicks=0;
    _prev=v.currentTime;
  },2000);
}
function _watchdogStop(){if(_watchdogInt){clearInterval(_watchdogInt);_watchdogInt=null;}}
document.addEventListener('play',e=>{if(e.target&&e.target.id==='html5Player'){if(e.target.src&&e.target.src!==window.location.href)_lastUrl=e.target.src;_watchdogStart();}},true);
document.addEventListener('pause',e=>{if(e.target&&e.target.id==='html5Player')_watchdogStop();},true);
document.addEventListener('ended',e=>{if(e.target&&e.target.id==='html5Player')_watchdogStop();},true);

/* ════ RESUME POSITION ════ */
function _resumeKey(){if(App.currentType==='episode')return'resume_ep_'+App.currentSeriesId+'_'+App.currentEpisodeIdx;return null;}
function _resumeSave(){
  const k=_resumeKey();if(!k)return;
  const v=document.getElementById('html5Player');
  if(!v||!v.duration||isNaN(v.duration)||v.currentTime<5)return;
  if(v.duration-v.currentTime<10){_resumeDelete();return;}
  try{localStorage.setItem(k,JSON.stringify({t:Math.floor(v.currentTime),d:Math.floor(v.duration),ts:Date.now()}));}catch(e){}
}
function _resumeGet(){const k=_resumeKey();if(!k)return null;try{const raw=localStorage.getItem(k);if(!raw)return null;const obj=JSON.parse(raw);if(Date.now()-obj.ts>30*24*3600*1000){localStorage.removeItem(k);return null;}return obj;}catch(e){return null;}}
function _resumeDelete(){const k=_resumeKey();if(k)try{localStorage.removeItem(k);}catch(e){}}
function _resumeOffer(pos,dur){
  const old=document.getElementById('resumeBar');if(old)old.remove();
  const bar=document.createElement('div');bar.id='resumeBar';
  const pct=Math.round((pos/dur)*100);
  bar.innerHTML=`<div style="flex:1;min-width:0"><span style="font-weight:800;color:#fff">استئناف من ${ft(pos)}</span><div style="height:3px;background:rgba(255,255,255,.15);border-radius:99px;margin-top:6px"><div style="width:${pct}%;height:100%;background:var(--red);border-radius:99px"></div></div></div><button id="resumeYes" style="background:var(--red);color:#fff;border:none;padding:8px 18px;border-radius:99px;font-weight:800;font-size:.85rem;cursor:pointer;font-family:inherit;flex-shrink:0">استئناف</button><button id="resumeNo" style="background:rgba(255,255,255,.1);color:#ccc;border:none;padding:8px 14px;border-radius:99px;font-weight:700;font-size:.85rem;cursor:pointer;font-family:inherit;flex-shrink:0">من البداية</button>`;
  bar.style.cssText='position:absolute;bottom:110px;left:4%;right:4%;z-index:9999;background:rgba(10,10,10,.97);border:1px solid rgba(255,255,255,.1);border-right:3px solid var(--red);border-radius:12px;padding:14px 18px;display:flex;align-items:center;gap:12px;box-shadow:0 8px 30px rgba(0,0,0,.8);direction:rtl';
  document.getElementById('playerOverlay').appendChild(bar);
  document.getElementById('resumeYes').onclick=function(){
    const v=document.getElementById('html5Player');
    if(v){if(v.readyState>=2)v.currentTime=pos;else v.addEventListener('canplay',function s(){v.removeEventListener('canplay',s);v.currentTime=pos;});}
    bar.remove();
  };
  document.getElementById('resumeNo').onclick=function(){_resumeDelete();bar.remove();};
  setTimeout(()=>{if(bar.parentNode)bar.remove();},12000);
}
let _resumeInterval=null;
function _resumeStartSaving(){if(_resumeInterval)clearInterval(_resumeInterval);_resumeInterval=setInterval(_resumeSave,5000);}
function _resumeStopSaving(){if(_resumeInterval){clearInterval(_resumeInterval);_resumeInterval=null;}_resumeSave();}
document.addEventListener('play',e=>{if(e.target&&e.target.id==='html5Player')_resumeStartSaving();},true);
document.addEventListener('pause',e=>{if(e.target&&e.target.id==='html5Player')_resumeSave();},true);
document.addEventListener('ended',e=>{if(e.target&&e.target.id==='html5Player'){_resumeStopSaving();_resumeDelete();}},true);

document.getElementById('playerOverlay').addEventListener('animationend',function(e){
  if(e.animationName!=='playerSlideIn'||App.currentType!=='episode')return;
  setTimeout(()=>{
    const v=document.getElementById('html5Player');
    const s=_resumeGet();if(!s||s.t<5)return;
    if(v.duration&&!isNaN(v.duration))_resumeOffer(s.t,v.duration);
    else v.addEventListener('loadedmetadata',function m(){v.removeEventListener('loadedmetadata',m);const s2=_resumeGet();if(s2&&s2.t>=5)_resumeOffer(s2.t,v.duration||s2.d);});
  },600);
});

/* ════ VISIBILITY CHANGE ════ */
document.addEventListener('visibilitychange',function(){
  const overlay=document.getElementById('playerOverlay');const v=document.getElementById('html5Player');
  if(!overlay||!overlay.classList.contains('active')||!v)return;
  if(document.hidden){
    _hiddenAt=Date.now();
    _bgPauseTimer=setTimeout(()=>{if(document.hidden&&!v.paused){try{v.pause();}catch(e){}toast('البث متوقف — التبويب مخفي');}},30000);
  }else{
    if(_bgPauseTimer){clearTimeout(_bgPauseTimer);_bgPauseTimer=null;}
    const ms=Date.now()-_hiddenAt;
    if(v.paused&&ms>800){
      if(ms>120000&&_lastUrl){toast('استئناف البث...');initStream(_lastUrl,'');}
      else{v.play().catch(()=>{});}
    }
  }
});

window.addEventListener('beforeunload',(e)=>{
  var ov = document.getElementById('playerOverlay');
  if(ov && ov.classList.contains('active')){
    e.preventDefault();
    e.returnValue = '';
    return;
  }
  try{
    if(typeof _watchdogStop==='function')_watchdogStop();
    const v=document.getElementById('html5Player');
    if(v){try{v.pause();}catch(e){}}
    if(PL.hls){try{PL.hls.destroy();}catch(e){}}
    if(PL.dash){try{PL.dash.reset();}catch(e){}}
    if(PL.flv){try{PL.flv.destroy();}catch(e){}}
  }catch(e){}
});

/* ════ SCREENSAVER ════ */
(function(){
  let nxIdleTime=0,nxSlideLoop=null,nxIdx=0,nxList=[];
  const NX_IDLE=60,NX_SLIDE=10000;
  const scr=document.getElementById('nxScreensaver');
  const bg=document.getElementById('nxBg');
  const wrap=document.getElementById('nxWrap');
  const pImg=document.getElementById('nxImg');
  const pTitle=document.getElementById('nxTitle');
  const pMatch=document.getElementById('nxMatchBadge');
  const pYear=document.getElementById('nxYear');
  function collect(){
    let pool=[];
    if(typeof MyNotifsQueue!=='undefined')MyNotifsQueue.forEach(o=>{if(o.img&&!o.img.includes('undefined'))pool.push({src:o.img,name:o.name});});
    if(pool.length<5)document.querySelectorAll('.sr-card img,.ch-card img').forEach(img=>{if(img.src&&img.style.display!=='none'){const n=img.closest('.sr-card,.ch-card')?.querySelector('.sr-name,.ch-name');pool.push({src:img.src,name:n?n.textContent:''});}});
    return pool.filter((o,i,a)=>a.findIndex(x=>x.src===o.src)===i);
  }
  function slide(){
    if(!nxList.length)return;if(nxIdx>=nxList.length)nxIdx=0;
    wrap.classList.add('nx-faded');
    setTimeout(()=>{
      const c=nxList[nxIdx];bg.style.backgroundImage=`url("${c.src}")`;pImg.src=c.src;pTitle.textContent=c.name||'';
      pMatch.textContent='المطابقة '+(Math.floor(Math.random()*12)+88)+'%';pYear.textContent=(Math.floor(Math.random()*4)+2021);
      wrap.classList.remove('nx-faded');
    },800);nxIdx++;
  }
  function launch(){
    const overlay=document.getElementById('playerOverlay');
    if(overlay?.classList.contains('active'))return;
    nxList=collect();if(!nxList.length)return;
    nxIdx=Math.floor(Math.random()*nxList.length);scr.classList.add('nx-active');slide();
    if(nxSlideLoop)clearInterval(nxSlideLoop);nxSlideLoop=setInterval(slide,NX_SLIDE);
  }
  function kill(){scr.classList.remove('nx-active');if(nxSlideLoop)clearInterval(nxSlideLoop);setTimeout(()=>{pImg.src='';bg.style.backgroundImage='';},1000);nxIdleTime=0;}
  setInterval(()=>{if(!document.getElementById('playerOverlay')?.classList.contains('active')){nxIdleTime++;if(nxIdleTime>=NX_IDLE&&!scr.classList.contains('nx-active'))launch();}else nxIdleTime=0;},1000);
  ['mousemove','mousedown','keydown','touchstart','scroll','click'].forEach(sig=>document.addEventListener(sig,kill,{passive:true}));
})();

/* ════ SITE MUSIC PLAYER ════ */
(function(){
  const INTERO_URL='/iptv/intero.mp3';
  let siteMusic=new Audio(INTERO_URL);
  siteMusic.loop=true;
  let isMusicPlaying=false;
  function initSiteMusic(){
    let saved=localStorage.getItem('shashety_music_play');
    if(saved==='1'){let pp=siteMusic.play();if(pp!==undefined)pp.then(()=>{isMusicPlaying=true;updateSiteMusicUI(true);}).catch(()=>{isMusicPlaying=false;updateSiteMusicUI(false);});}
    else updateSiteMusicUI(false);
  }
  function playSiteMusic(){siteMusic.play().then(()=>{isMusicPlaying=true;localStorage.setItem('shashety_music_play','1');updateSiteMusicUI(true);}).catch(()=>{isMusicPlaying=false;localStorage.setItem('shashety_music_play','0');updateSiteMusicUI(false);});}
  function pauseSiteMusic(){siteMusic.pause();isMusicPlaying=false;localStorage.setItem('shashety_music_play','0');updateSiteMusicUI(false);}
  function toggleSiteMusic(){if(isMusicPlaying)pauseSiteMusic();else playSiteMusic();}
  function updateSiteMusicUI(playing){
    const btn=document.getElementById('musicMiniBtn');const eq=document.getElementById('musicEq');
    if(!btn||!eq)return;
    if(playing){btn.classList.add('playing');eq.classList.remove('paused');}
    else{btn.classList.remove('playing');eq.classList.add('paused');}
  }
  document.addEventListener('play',function(e){if(e.target&&e.target.id==='html5Player'){if(isMusicPlaying){siteMusic.dataset.wasPlaying='1';siteMusic.pause();}}},true);
  document.addEventListener('ended',function(e){if(e.target&&e.target.id==='html5Player'){if(siteMusic.dataset.wasPlaying==='1'){delete siteMusic.dataset.wasPlaying;siteMusic.play().catch(()=>{});}}},true);
  document.addEventListener('pause',function(e){if(e.target&&e.target.id==='html5Player'){if(siteMusic.dataset.wasPlaying==='1'){delete siteMusic.dataset.wasPlaying;siteMusic.play().catch(()=>{});}}},true);
  window.toggleSiteMusic=toggleSiteMusic;
  document.addEventListener('DOMContentLoaded',()=>setTimeout(initSiteMusic,1000));
  if(document.readyState==='complete'||document.readyState==='interactive')setTimeout(initSiteMusic,1000);
})();

updateNotifBadge();
document.addEventListener('DOMContentLoaded',loadAndBuildNetflixHome);
</script>

<script>
window.addEventListener('load',function(){
  var loader=document.getElementById('nxInitLoader');
  if(loader){setTimeout(function(){loader.classList.add('loaded');setTimeout(function(){loader.remove();},500);},250);}
});
</script>
<!-- ════ PERF BOOST — تسريع التنقل والصور (إضافة) ════ -->
<script>
(function(){
  'use strict';

  /* 1) Lazy-loading تلقائي لكل الصور + ظهور تدريجي ناعم
        يطبّق loading=lazy و decoding=async على أي صورة تفوتها، ويضيف
        تأثير ظهور لطيف. يعمل على الصور الحالية والمضافة لاحقاً (MutationObserver). */
  function enhanceImg(img){
    if(img.dataset.perfDone) return;
    img.dataset.perfDone = '1';
    if(!img.hasAttribute('loading')) img.loading = 'lazy';
    if(!img.hasAttribute('decoding')) img.decoding = 'async';
    // ظهور ناعم (نتجنّب الشعار في الناف لئلا يومض)
    if(!img.classList.contains('nav-logo-img')){
      img.classList.add('perf-img');
      if(img.complete && img.naturalWidth>0){ img.classList.add('perf-loaded'); }
      else{
        img.addEventListener('load', ()=>img.classList.add('perf-loaded'), {once:true});
        img.addEventListener('error',()=>img.classList.add('perf-loaded'), {once:true});
      }
    }
  }
  function scanImgs(root){ (root||document).querySelectorAll('img').forEach(enhanceImg); }
  scanImgs(document);
  // راقب الصور المُضافة ديناميكياً (شبكة نتفليكس، الحلقات، نتائج البحث…)
  try{
    new MutationObserver(muts=>{
      for(const m of muts){
        m.addedNodes && m.addedNodes.forEach(n=>{
          if(n.nodeType!==1) return;
          if(n.tagName==='IMG') enhanceImg(n);
          else if(n.querySelectorAll) n.querySelectorAll('img').forEach(enhanceImg);
        });
      }
    }).observe(document.body, {childList:true, subtree:true});
  }catch(e){}

  /* 2) Prefetch ذكي عند المرور/اللمس على بطاقة مسلسل
        يجلب حلقات المسلسل مسبقاً (في الكاش الموجود) فيصبح الفتح فورياً.
        يعتمد على كاش api.php المضاف سابقاً، فلا طلب مكرر فعلي عند النقر. */
  const prefetched = new Set();
  function prefetchSeries(id){
    if(id==null || prefetched.has(id)) return;
    prefetched.add(id);
    // طلب صامت — النتيجة تُخزَّن في الكاش الذكي
    try{ fetch('api.php?action=episodes&series_id='+encodeURIComponent(id)).catch(()=>{}); }catch(e){}
  }
  // نلتقط أي عنصر يفتح مسلسلاً عبر onclick يحوي openSeriesEpisodes(ID
  function seriesIdFromEl(el){
    if(!el || !el.closest) return null;
    // 1) عبر وسم البيانات على البطاقة (الطريقة المعتمدة للشبكة الرئيسية)
    const card = el.closest('[data-prefetch-series]');
    if(card){ const v=card.getAttribute('data-prefetch-series'); if(v) return v; }
    // 2) عبر onclick نصي يحوي openSeriesEpisodes(ID
    const host = el.closest('[onclick]');
    if(host){
      const oc = host.getAttribute('onclick')||'';
      const mm = oc.match(/openSeriesEpisodes\(\s*['"]?(\d+)/);
      if(mm) return mm[1];
    }
    return null;
  }
  let hoverTimer=null;
  document.addEventListener('mouseover', e=>{
    const t = e.target;
    if(!(t instanceof Element)) return;
    const id = seriesIdFromEl(t);
    if(id){ clearTimeout(hoverTimer); hoverTimer=setTimeout(()=>prefetchSeries(id), 120); }
  }, {passive:true});
  // على الجوال: عند بدء اللمس
  document.addEventListener('touchstart', e=>{
    const t = e.target;
    if(t instanceof Element){ const id=seriesIdFromEl(t); if(id) prefetchSeries(id); }
  }, {passive:true});

  /* 3) ربط الـ prefetch ببطاقات تُنشأ عبر addEventListener (لا onclick نصي)
        نلتقط أقرب عنصر يحمل بيانات مسلسل عبر التفويض — احتياطي إضافي. */
  // (مغطّى أعلاه عبر seriesIdFromEl للعناصر ذات onclick)

  /* 4) تأجيل المهام غير الحرجة حتى يهدأ المتصفح */
  const idle = window.requestIdleCallback || function(fn){ return setTimeout(fn, 200); };
  idle(function(){
    // تلميح للمتصفح بأن api.php على نفس الأصل (يسرّع أول طلب)
    try{
      const l=document.createElement('link');
      l.rel='preconnect'; l.href=location.origin;
      document.head.appendChild(l);
    }catch(e){}
  });

  /* 5) تأجيل المهام غير الحرجة حتى يهدأ المتصفح (إضافي) */
  // (مغطّى في النقطة 4)

})();
</script>
<!-- ════════════ تحسينات واجهة شاشتي المدمجة — إضافة آمنة ════════════ -->
<style id="shashety-improve-css">
  #shsToTop{position:fixed;left:20px;bottom:20px;z-index:9999;width:46px;height:46px;border:none;border-radius:50%;background:rgba(229,9,20,.92);color:#fff;cursor:pointer;font-size:20px;line-height:46px;text-align:center;box-shadow:0 6px 20px rgba(0,0,0,.35);opacity:0;transform:translateY(16px) scale(.9);transition:opacity .25s ease,transform .25s ease;pointer-events:none;}
  #shsToTop.show{opacity:1;transform:translateY(0) scale(1);pointer-events:auto;}
  #shsToTop:hover{background:#ff2b35;}
  #shsProgress{position:fixed;top:0;left:0;height:3px;width:0;background:linear-gradient(90deg,#e50914,#ff6b6b);z-index:10000;transition:width .3s ease,opacity .4s ease;box-shadow:0 0 10px rgba(229,9,20,.6);opacity:0;}
  img.shs-lazy{opacity:0;transition:opacity .4s ease;}
  img.shs-lazy.shs-loaded{opacity:1;}
  @media (prefers-reduced-motion: reduce){#shsToTop,#shsProgress,img.shs-lazy{transition:none !important;}}
  /* إخفاء زر العودة للأعلى وشريط التقدّم عند تشغيل المشغّل (منع التداخل) */
  .player-overlay.active ~ #shsToTop,
  body:has(.player-overlay.active) #shsToTop{opacity:0 !important;pointer-events:none !important;visibility:hidden !important;}
  body:has(.player-overlay.active) #shsProgress{display:none !important;}
</style>
<button id="shsToTop" aria-label="العودة للأعلى" title="العودة للأعلى">↑</button>
<div id="shsProgress"></div>
<script id="shashety-improve-js">
(function(){'use strict';
  function enableLazyImages(){try{document.querySelectorAll('img:not([loading])').forEach(function(img){if(img.src&&img.src.indexOf('data:')===0)return;img.setAttribute('loading','lazy');img.setAttribute('decoding','async');if(!img.complete){img.classList.add('shs-lazy');img.addEventListener('load',function(){img.classList.add('shs-loaded');},{once:true});img.addEventListener('error',function(){img.classList.add('shs-loaded');},{once:true});}});}catch(e){}}
  function initToTop(){var btn=document.getElementById('shsToTop');if(!btn)return;function playerOn(){var ov=document.getElementById('playerOverlay');return ov&&ov.classList.contains('active');}var ticking=false;function onScroll(){if(ticking)return;ticking=true;requestAnimationFrame(function(){if(window.scrollY>400&&!playerOn())btn.classList.add('show');else btn.classList.remove('show');ticking=false;});}window.addEventListener('scroll',onScroll,{passive:true});btn.addEventListener('click',function(){window.scrollTo({top:0,behavior:'smooth'});});
    // مراقبة المشغّل: إخفاء الزر فوراً عند الفتح (يعمل في كل المتصفحات حتى بلا :has)
    var ov=document.getElementById('playerOverlay');
    if(ov){try{var mo=new MutationObserver(function(){if(ov.classList.contains('active'))btn.classList.remove('show');});mo.observe(ov,{attributes:true,attributeFilter:['class']});}catch(e){}}
  }
  function initProgress(){var bar=document.getElementById('shsProgress');if(!bar)return;document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('a');if(!a||!a.href)return;if(a.target==='_blank'||a.href.indexOf('#')!==-1)return;if(a.href.indexOf(window.location.origin)!==0)return;bar.style.opacity='1';bar.style.width='70%';});window.addEventListener('beforeunload',function(){bar.style.width='100%';});}
  function guardCdnScripts(){window.addEventListener('error',function(ev){var t=ev.target;if(t&&t.tagName==='SCRIPT'&&t.src&&!t.dataset.shsRetried){t.dataset.shsRetried='1';var s=document.createElement('script');s.src=t.src;s.async=true;s.defer=true;s.dataset.shsRetried='1';document.head.appendChild(s);}},true);}
  function init(){
    try{
      const rs=sessionStorage.getItem('shs_restore');
      /* إن كان العنوان يحمل حالة (#s= / #c= / #q= / #ch=) فالموجّه سيتكفّل بالاستعادة،
         فلا نفتح المشغّل هنا أيضاً حتى لا يُفتح مرتين. نبقي مسار m3u فقط لأنه ليس في العنوان. */
      const hasHash = !!(window.location.hash || '').replace(/^#/,'');
      if(rs){
        const d=JSON.parse(rs);
        if(d.type==='channel'){
          if(!hasHash) setTimeout(()=>openPlayerChannel(d.ch),500);
        }else if(d.type==='episode'){
          App.currentSeriesId=d.seriesId;
          App.currentSeriesName=d.seriesName;
          if(d.seriesId && !hasHash){
            fetch('api.php?action=episodes&series_id='+encodeURIComponent(d.seriesId))
              .then(r=>r.json()).then(res=>{
                App.allEpisodes=res.episodes||[];
                if(App.allEpisodes.length)openPlayerEpisode(d.idx);
              }).catch(()=>{});
          }
        }else if(d.type==='m3u'){
          PL.m3uEntries=d.entries||[];
          if(typeof PL.m3uName === 'undefined') PL.m3uName = d.name||'';
          if(PL.m3uEntries.length)setTimeout(()=>playM3UEntry(d.idx),500);
        }
      }
    }catch(e){}
    enableLazyImages();initToTop();initProgress();guardCdnScripts();
  }
  if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',init);}else{init();}
})();
</script>
<!-- ════════════════════ نهاية تحسينات واجهة شاشتي ════════════════════ -->
<!-- ════════════ تحسينات مشغّل شاشتي — إضافة آمنة (لا تحذف شيئاً) ════════════ -->
<style id="shashety-player-enhance-css">
  /* زر صورة-داخل-صورة (PiP) */
  .shs-pip-btn{display:inline-flex;align-items:center;justify-content:center;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);color:#fff;width:38px;height:38px;border-radius:10px;cursor:pointer;transition:.2s;font-size:17px;}
  .shs-pip-btn:hover{background:rgba(229,9,20,.85);border-color:rgba(229,9,20,.9);transform:translateY(-1px);}
  /* تلميح سرعة التشغيل */
  .shs-rate-tag{position:absolute;top:14px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.78);color:#fff;padding:6px 16px;border-radius:99px;font-weight:800;font-size:.9rem;z-index:60;opacity:0;pointer-events:none;transition:opacity .25s ease;font-family:inherit;}
  .shs-rate-tag.show{opacity:1;}
  /* تلميح اختصارات لوحة المفاتيح */
  .shs-keys-hint{position:fixed;inset:0;background:rgba(0,0,0,.82);z-index:200;display:none;align-items:center;justify-content:center;padding:20px;}
  .shs-keys-hint.open{display:flex;}
  .shs-keys-card{background:#1a1a1a;border:1px solid rgba(255,255,255,.12);border-radius:16px;max-width:440px;width:100%;padding:24px 26px;color:#eee;font-family:inherit;direction:rtl;}
  .shs-keys-card h3{margin:0 0 16px;color:#fff;font-size:1.15rem;display:flex;align-items:center;gap:8px;}
  .shs-keys-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.92rem;}
  .shs-keys-row:last-child{border-bottom:none;}
  .shs-keys-row kbd{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);border-radius:6px;padding:2px 9px;font-family:monospace;font-size:.85rem;color:#fff;min-width:22px;text-align:center;display:inline-block;}
  .shs-keys-close{margin-top:18px;width:100%;background:var(--red,#e50914);color:#fff;border:none;padding:11px;border-radius:10px;font-weight:800;cursor:pointer;font-family:inherit;font-size:.95rem;}
</style>

<div class="shs-rate-tag" id="shsRateTag"></div>
<div class="shs-keys-hint" id="shsKeysHint">
  <div class="shs-keys-card">
    <h3>⌨️ اختصارات المشغّل</h3>
    <div class="shs-keys-row"><span>تشغيل / إيقاف</span><kbd>مسافة</kbd></div>
    <div class="shs-keys-row"><span>تقديم ١٠ ثوانٍ</span><kbd>→</kbd></div>
    <div class="shs-keys-row"><span>تأخير ١٠ ثوانٍ</span><kbd>←</kbd></div>
    <div class="shs-keys-row"><span>رفع الصوت</span><kbd>↑</kbd></div>
    <div class="shs-keys-row"><span>خفض الصوت</span><kbd>↓</kbd></div>
    <div class="shs-keys-row"><span>كتم الصوت</span><kbd>M</kbd></div>
    <div class="shs-keys-row"><span>ملء الشاشة</span><kbd>F</kbd></div>
    <div class="shs-keys-row"><span>صورة داخل صورة</span><kbd>P</kbd></div>
    <div class="shs-keys-row"><span>تسريع التشغيل</span><kbd>&gt;</kbd></div>
    <div class="shs-keys-row"><span>إبطاء التشغيل</span><kbd>&lt;</kbd></div>
    <div class="shs-keys-row"><span>قفز لنسبة من الفيديو</span><kbd>0</kbd>…<kbd>9</kbd></div>
    <button class="shs-keys-close" onclick="document.getElementById('shsKeysHint').classList.remove('open')">إغلاق</button>
  </div>
</div>

<script id="shashety-player-enhance-js">
(function(){
  'use strict';
  function vid(){ return document.getElementById('html5Player'); }
  function playerOpen(){
    var ov = document.getElementById('playerOverlay');
    return ov && ov.classList.contains('active');
  }
  function typingInField(){
    var a = document.activeElement;
    return a && (a.tagName==='INPUT' || a.tagName==='TEXTAREA' || a.isContentEditable);
  }

  /* ── سرعة التشغيل ── */
  var RATES = [0.5, 0.75, 1, 1.25, 1.5, 2];
  function showRate(){
    var v = vid(); if(!v) return;
    var tag = document.getElementById('shsRateTag');
    if(!tag) return;
    tag.textContent = '⏩ السرعة: ' + v.playbackRate + '×';
    tag.classList.add('show');
    clearTimeout(showRate._t);
    showRate._t = setTimeout(function(){ tag.classList.remove('show'); }, 1200);
  }
  function bumpRate(dir){
    var v = vid(); if(!v) return;
    var i = RATES.indexOf(v.playbackRate);
    if(i === -1) i = 2; // 1x افتراضي
    i = Math.max(0, Math.min(RATES.length-1, i + dir));
    v.playbackRate = RATES[i];
    showRate();
  }

  /* ── صورة داخل صورة (PiP) ── */
  function togglePiP(){
    var v = vid(); if(!v) return;
    try{
      if(document.pictureInPictureElement){
        document.exitPictureInPicture();
      }else if(document.pictureInPictureEnabled && !v.disablePictureInPicture){
        v.requestPictureInPicture();
      }
    }catch(e){ /* بصمت */ }
  }

  /* ── حقن زر PiP بجوار زر ملء الشاشة (إن وُجد) ── */
  function injectPipButton(){
    if(document.getElementById('shsPipBtn')) return;
    var fsBtn = document.querySelector('.p-fs-btn');
    if(!fsBtn || !fsBtn.parentNode) return;
    if(!('pictureInPictureEnabled' in document) || !document.pictureInPictureEnabled) return;
    var b = document.createElement('button');
    b.type = 'button';
    b.id = 'shsPipBtn';
    b.className = 'shs-pip-btn';
    b.title = 'صورة داخل صورة (P)';
    b.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 10h6V4"/><path d="m2 4 6 6"/><path d="M21 10V7a2 2 0 0 0-2-2h-7"/><path d="M3 14v2a2 2 0 0 0 2 2h3"/><rect width="10" height="7" x="12" y="13.5" rx="2"/></svg>';
    b.addEventListener('click', togglePiP);
    fsBtn.parentNode.insertBefore(b, fsBtn);
  }

  /* ── قفز لنسبة (0-9) ── */
  function seekPercent(p){
    var v = vid(); if(!v || !v.duration || isNaN(v.duration)) return;
    v.currentTime = v.duration * (p/10);
    if(typeof showControls === 'function') showControls();
  }

  /* ── اختصارات لوحة المفاتيح (كمبيوتر فقط، لا تمسّ منطق الريموت) ── */
  document.addEventListener('keydown', function(e){
    if(!playerOpen() || typingInField()) return;
    if(e.ctrlKey || e.altKey || e.metaKey) return;
    var k = e.key;

    switch(k){
      case ' ': case 'k': case 'K':
        if(typeof togglePlay==='function'){ e.preventDefault(); togglePlay(); }
        return;
      case 'ArrowRight':
        if(typeof skip==='function'){ e.preventDefault(); skip(10); }
        return;
      case 'ArrowLeft':
        if(typeof skip==='function'){ e.preventDefault(); skip(-10); }
        return;
      case 'ArrowUp':
        if(typeof changeVol==='function'){ e.preventDefault(); changeVol(0.1); }
        return;
      case 'ArrowDown':
        if(typeof changeVol==='function'){ e.preventDefault(); changeVol(-0.1); }
        return;
      case 'm': case 'M':
        if(typeof toggleMute==='function'){ e.preventDefault(); toggleMute(); }
        return;
      case 'f': case 'F':
        if(typeof toggleFullscreen==='function'){ e.preventDefault(); toggleFullscreen(); }
        return;
      case 'p': case 'P':
        e.preventDefault(); togglePiP();
        return;
      case '>': case '.':
        e.preventDefault(); bumpRate(1);
        return;
      case '<': case ',':
        e.preventDefault(); bumpRate(-1);
        return;
      case '?':
        e.preventDefault();
        document.getElementById('shsKeysHint').classList.toggle('open');
        return;
    }
    // أرقام 0..9 للقفز لنسبة
    if(k >= '0' && k <= '9'){
      e.preventDefault();
      seekPercent(parseInt(k,10));
    }
  }, false);

  /* محاولة حقن زر PiP عند فتح المشغّل */
  var _origOpen = window.openPlayerChannel;
  if(typeof _origOpen === 'function'){
    window.openPlayerChannel = function(){
      var r = _origOpen.apply(this, arguments);
      setTimeout(injectPipButton, 300);
      return r;
    };
  }
  var _origOpenEp = window.openPlayerEpisode;
  if(typeof _origOpenEp === 'function'){
    window.openPlayerEpisode = function(){
      var r = _origOpenEp.apply(this, arguments);
      setTimeout(injectPipButton, 300);
      return r;
    };
  }
  // محاولة إضافية بعد التحميل (احتياط)
  document.addEventListener('DOMContentLoaded', function(){
    setTimeout(injectPipButton, 1000);
  });
})();
</script>
<!-- ════════════════════ نهاية تحسينات مشغّل شاشتي ════════════════════ -->
<!-- ════════════ شاشة توقف احترافية — تحسين بصري آمن (لا يحذف المنطق الأصلي) ════════════ -->
<style id="shashety-screensaver-pro-css">
  /* ── خلفية أعمق وتدرّج سينمائي أنعم (يتجاوز القديم بنفس المحدّدات) ── */
  #nxScreensaver{background:#050505 !important;}
  #nxScreensaver .nx-bg{
    inset:-12% !important;
    filter:blur(60px) saturate(1.6) brightness(.28) !important;
    animation:nxKenBurnsPro 28s infinite alternate ease-in-out !important;
    transition:background-image 1.2s ease !important;
  }
  @keyframes nxKenBurnsPro{
    0%{transform:scale(1.05) translate(0,0)}
    100%{transform:scale(1.18) translate(-2%,-2%)}
  }
  /* ── طبقة حُبيبات/توهّج خفيف فوق الخلفية (احترافية) ── */
  #nxScreensaver .nx-vignette{
    background:
      radial-gradient(ellipse at 30% 40%, rgba(229,9,20,.10) 0%, transparent 45%),
      radial-gradient(circle at center, transparent 25%, rgba(0,0,0,.88) 100%),
      linear-gradient(0deg,#000 0%, rgba(0,0,0,0) 42%) !important;
  }

  /* ── البوستر: ظل أعمق + إطار زجاجي + انعكاس ضوئي ── */
  #nxScreensaver .nx-poster{
    border-radius:16px !important;
    box-shadow:
      0 40px 100px rgba(0,0,0,.95),
      0 0 0 1px rgba(255,255,255,.10),
      0 0 60px rgba(229,9,20,.12) !important;
    animation:nxFloatPro 7s ease-in-out infinite alternate !important;
  }
  @keyframes nxFloatPro{
    0%{transform:perspective(1200px) rotateY(-6deg) translateY(0) scale(1)}
    100%{transform:perspective(1200px) rotateY(-3deg) translateY(-16px) scale(1.015)}
  }

  /* ── العنوان: ظهور تدريجي أنيق عند كل شريحة ── */
  #nxScreensaver .nx-title{
    text-shadow:0 6px 30px rgba(0,0,0,.9) !important;
    letter-spacing:-.5px;
  }
  #nxScreensaver:not(.nx-faded) .nx-info-box > *{animation:nxRise .9s cubic-bezier(.2,.7,.2,1) both;}
  #nxScreensaver .nx-info-box > *:nth-child(1){animation-delay:.05s}
  #nxScreensaver .nx-info-box > *:nth-child(2){animation-delay:.14s}
  #nxScreensaver .nx-info-box > *:nth-child(3){animation-delay:.22s}
  @keyframes nxRise{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}

  /* ── انتقال الحاوية أنعم (slide + fade بدل fade فقط) ── */
  #nxScreensaver .nx-container{transition:opacity .9s ease, transform .9s cubic-bezier(.2,.7,.2,1) !important;}
  #nxScreensaver .nx-container.nx-faded{opacity:0 !important; transform:translateX(40px) scale(.985);}

  /* ── شارة المطابقة: توهّج أخضر ناعم ── */
  #nxScreensaver .nx-match{text-shadow:0 0 16px rgba(70,211,105,.5);}

  /* ════ الساعة والتاريخ (جديد) ════ */
  .nx-clock{
    position:absolute; top:6vh; left:7vw; z-index:4; direction:ltr;
    text-align:left; color:#fff; pointer-events:none;
    opacity:0; transition:opacity 1.2s ease .4s;
    text-shadow:0 4px 24px rgba(0,0,0,.7);
  }
  #nxScreensaver.nx-active .nx-clock{opacity:1;}
  .nx-clock-time{
    font-size:clamp(3rem,7vw,6rem); font-weight:200; line-height:1;
    font-family:'Cairo','SF Pro Display',sans-serif; letter-spacing:2px;
    font-variant-numeric:tabular-nums;
  }
  .nx-clock-time .nx-ampm{font-size:.32em;font-weight:600;opacity:.7;margin-inline-start:.3em;}
  .nx-clock-date{
    font-size:clamp(.9rem,1.6vw,1.3rem); font-weight:600; color:rgba(255,255,255,.62);
    margin-top:10px; letter-spacing:.5px; font-family:'Cairo',sans-serif; direction:rtl; text-align:left;
  }
  .nx-brand{
    position:absolute; bottom:48px; right:7vw; z-index:4;
    display:flex; align-items:center; gap:10px; direction:rtl;
    opacity:0; transition:opacity 1.2s ease .6s;
    color:rgba(255,255,255,.5); font-weight:800; font-size:1rem; letter-spacing:.5px;
  }
  #nxScreensaver.nx-active .nx-brand{opacity:1;}
  .nx-brand-dot{width:8px;height:8px;border-radius:50%;background:#E50914;box-shadow:0 0 12px rgba(229,9,20,.8);animation:nxPulse 2s infinite ease-in-out;}
  @keyframes nxPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.8)}}

  @media (max-width:768px){
    .nx-clock{top:4vh;left:6vw;}
    .nx-clock-time{font-size:clamp(2.2rem,9vw,3.5rem);}
    .nx-brand{bottom:90px;}
  }
  @media (prefers-reduced-motion: reduce){
    #nxScreensaver .nx-bg,#nxScreensaver .nx-poster{animation:none !important;}
    #nxScreensaver .nx-container{transition:opacity .5s ease !important;}
  }
</style>

<script id="shashety-screensaver-pro-js">
(function(){
  'use strict';
  var scr = document.getElementById('nxScreensaver');
  if(!scr) return;

  /* ── حقن عناصر الساعة + العلامة (مرة واحدة) ── */
  function injectExtras(){
    if(!document.getElementById('nxClock')){
      var clock = document.createElement('div');
      clock.className = 'nx-clock';
      clock.id = 'nxClock';
      clock.innerHTML =
        '<div class="nx-clock-time" id="nxClockTime">--:--</div>' +
        '<div class="nx-clock-date" id="nxClockDate"></div>';
      scr.appendChild(clock);
    }
    if(!document.getElementById('nxBrand')){
      // اسم الموقع إن توفّر في الصفحة، وإلا فارغ بدون كسر
      var siteName = (document.querySelector('meta[property="og:title"]') || {}).content || document.title || '';
      siteName = String(siteName).split('—')[0].split('-')[0].trim();
      var brand = document.createElement('div');
      brand.className = 'nx-brand';
      brand.id = 'nxBrand';
      brand.innerHTML = '<span>' + (siteName || 'مباشر') + '</span><span class="nx-brand-dot"></span>';
      scr.appendChild(brand);
    }
  }
  injectExtras();

  /* ── تحديث الساعة (يعمل فقط عندما تكون الشاشة نشطة) ── */
  var AR_DAYS = ['الأحد','الإثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
  var AR_MONTHS = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
  function tick(){
    if(!scr.classList.contains('nx-active')) return;
    var t = document.getElementById('nxClockTime');
    var d = document.getElementById('nxClockDate');
    if(!t || !d) return;
    var now = new Date();
    var h = now.getHours();
    var m = now.getMinutes();
    var ampm = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12; if(h12 === 0) h12 = 12;
    var mm = (m < 10 ? '0' : '') + m;
    t.innerHTML = h12 + ':' + mm + '<span class="nx-ampm">' + ampm + '</span>';
    d.textContent = AR_DAYS[now.getDay()] + '، ' + now.getDate() + ' ' + AR_MONTHS[now.getMonth()] + ' ' + now.getFullYear();
  }
  setInterval(tick, 1000);
  tick();

  /* ── عند تفعيل الشاشة، حدّث الساعة فوراً (مراقبة class) ── */
  try{
    var mo = new MutationObserver(function(){
      if(scr.classList.contains('nx-active')) tick();
    });
    mo.observe(scr, {attributes:true, attributeFilter:['class']});
  }catch(e){ /* المتصفحات القديمة: المؤقّت كافٍ */ }
})();
</script>
<!-- ════════════════════ نهاية شاشة التوقف الاحترافية ════════════════════ -->
<!-- ════════════ تحكم تشغيل/إطفاء شاشة التوقف — إضافة آمنة ════════════ -->
<style id="shashety-saver-toggle-css">
  .saver-toggle-btn{transition:var(--transition);position:relative;}
  .saver-toggle-btn:hover{background:rgba(229,9,20,.18);border-color:rgba(229,9,20,.5);color:#ff6b6b;transform:scale(1.08);}
  /* الحالة المُطفأة: لون باهت + شرطة */
  .saver-toggle-btn.saver-off{opacity:.65;color:#888;}
  .saver-toggle-btn.saver-off:hover{opacity:1;color:#46d369;border-color:rgba(70,211,105,.5);background:rgba(70,211,105,.12);}
  /* تلميح حالة صغير يظهر عند الضغط */
  .saver-toast{position:fixed;bottom:28px;left:50%;transform:translateX(-50%) translateY(20px);background:rgba(20,20,20,.96);color:#fff;border:1px solid rgba(255,255,255,.12);padding:11px 22px;border-radius:99px;font-family:'Cairo',sans-serif;font-weight:700;font-size:.9rem;z-index:100000;opacity:0;pointer-events:none;transition:opacity .3s ease,transform .3s ease;display:flex;align-items:center;gap:8px;direction:rtl;box-shadow:0 10px 40px rgba(0,0,0,.5);}
  .saver-toast.show{opacity:1;transform:translateX(-50%) translateY(0);}
  .saver-toast .st-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0;}
  .saver-toast.on  .st-dot{background:#46d369;box-shadow:0 0 10px rgba(70,211,105,.8);}
  .saver-toast.off .st-dot{background:#888;}
</style>

<div class="saver-toast" id="saverToast"><span class="st-dot"></span><span id="saverToastTxt"></span></div>

<script id="shashety-saver-toggle-js">
(function(){
  'use strict';
  var KEY = 'shashety_saver_enabled';
  var scr = document.getElementById('nxScreensaver');

  // قرار الإدارة من لوحة التحكم (إن أطفأها المدير = إطفاء إجباري لكل الزوار)
  var ADMIN_DISABLED = <?php echo $hide_screensaver ? 'true' : 'false'; ?>;

  // الحالة المحفوظة (افتراضياً: مُفعّلة). قرار الإدارة يتجاوز تفضيل الزائر.
  function isEnabled(){
    if(ADMIN_DISABLED) return false;
    try{ return localStorage.getItem(KEY) !== '0'; }catch(e){ return true; }
  }
  function setEnabled(v){
    try{ localStorage.setItem(KEY, v ? '1' : '0'); }catch(e){}
  }

  // عند الإطفاء: نراقب الشاشة ونمنعها فوراً من الظهور
  var guard = null;
  function startGuard(){
    if(guard || !scr) return;
    // إخفاء فوري إن كانت ظاهرة
    if(scr.classList.contains('nx-active')) scr.classList.remove('nx-active');
    try{
      guard = new MutationObserver(function(){
        if(!isEnabled() && scr.classList.contains('nx-active')){
          scr.classList.remove('nx-active');
        }
      });
      guard.observe(scr, {attributes:true, attributeFilter:['class']});
    }catch(e){
      // متصفحات قديمة: فحص دوري بديل
      guard = setInterval(function(){
        if(!isEnabled() && scr && scr.classList.contains('nx-active')) scr.classList.remove('nx-active');
      }, 500);
    }
  }
  function stopGuard(){
    if(!guard) return;
    if(typeof guard.disconnect === 'function') guard.disconnect();
    else clearInterval(guard);
    guard = null;
  }

  // تحديث مظهر الزر
  function syncButton(){
    var btn = document.getElementById('saverToggleBtn');
    var on  = document.getElementById('saverIconOn');
    var off = document.getElementById('saverIconOff');
    if(!btn) return;
    var en = isEnabled();
    btn.classList.toggle('saver-off', !en);
    btn.title = en ? 'شاشة التوقف: مُفعّلة' : 'شاشة التوقف: مُطفأة';
    if(on)  on.style.display  = en ? '' : 'none';
    if(off) off.style.display = en ? 'none' : '';
  }

  // تلميح الحالة
  function toast(en){
    var t = document.getElementById('saverToast');
    var x = document.getElementById('saverToastTxt');
    if(!t || !x) return;
    t.classList.toggle('on', en);
    t.classList.toggle('off', !en);
    x.textContent = en ? 'شاشة التوقف مُفعّلة' : 'شاشة التوقف مُطفأة';
    t.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(function(){ t.classList.remove('show'); }, 1800);
  }

  // الدالة العامة التي يستدعيها الزر
  window.toggleScreensaverPref = function(){
    var en = !isEnabled();
    setEnabled(en);
    if(en){ stopGuard(); }
    else  { startGuard(); }
    syncButton();
    toast(en);
  };

  // تطبيق الحالة عند تحميل الصفحة
  function init(){
    // إذا أطفأ المدير الشاشة من اللوحة، نخفي الزر الفردي (القرار للإدارة)
    if(ADMIN_DISABLED){
      var b = document.getElementById('saverToggleBtn');
      if(b) b.style.display = 'none';
      startGuard();
      return;
    }
    syncButton();
    if(!isEnabled()) startGuard();
  }
  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', init);
  } else { init(); }
})();
</script>
<!-- ════════════════════ نهاية تحكم شاشة التوقف ════════════════════ -->
<!-- ════════════ إصلاحات المشغّل (دون تغيير المشغّل) — إضافة آمنة ════════════ -->
<script id="shashety-player-fixes-js">
(function(){
  'use strict';

  /* ══════════════════════════════════════════════════════════════════
     العيب 1: زر الرجوع في أندرويد يعمل "رجوع" لكن لا يخرج من المشغّل
     ─────────────────────────────────────────────────────────────────
     يوجد مساران لإغلاق المشغّل، ولكلٍّ حالة history مختلفة:

       (أ) زر الإغلاق المرئي (X): يستدعي closePlayer مباشرة — الـ history
           لم يُلمس، فتبقى إدخالة {player:'active'} عالقة. الضغطة التالية
           على رجوع أندرويد تستهلك تلك الإدخالة العالقة بدل أن تخرج =
           هذا هو سبب "يعمل رجوع ولكن لا يخرج".

       (ب) زر رجوع أندرويد: المتصفح يطلق popstate (ويزيل إدخالة تلقائياً)
           ثم _goBack ينادي closePlayer. هنا الـ history صحيح أصلاً.

     الحل: نميّز المصدر. في المسار (أ) نزيل الإدخالة العالقة بـ history.back()
     مرة واحدة لمزامنة الـ stack. في المسار (ب) لا نلمس الـ history إطلاقاً.
     لا نعدّل منطق المشغّل؛ فقط نغلّف closePlayer ونعلّم مصدر النداء.
  ══════════════════════════════════════════════════════════════════ */

  var _fromPopstate = false;  // علم: هل النداء الحالي قادم من popstate؟

  // نلتقط popstate قبل المنطق الأصلي (capture) لنعلّم المصدر
  window.addEventListener('popstate', function(){
    _fromPopstate = true;
    // نطلق العلم بعد انتهاء دورة الحدث (بعد أن ينفّذ المنطق الأصلي closePlayer)
    setTimeout(function(){ _fromPopstate = false; }, 0);
  }, true);

  function wrapClose(){
    if(typeof window.closePlayer !== 'function'){ setTimeout(wrapClose, 200); return; }
    if(window.closePlayer.__shsWrapped) return;

    var _orig = window.closePlayer;
    window.closePlayer = function(){
      var wasActive = !!(document.getElementById('playerOverlay') &&
                         document.getElementById('playerOverlay').classList.contains('active'));
      var fromPop = _fromPopstate;

      var r = _orig.apply(this, arguments);

      // مزامنة الـ history فقط في المسار (أ): إغلاق يدوي بزر X والمشغّل كان نشطاً
      // وليس قادماً من popstate (حتى لا نزيل إدخالة مرتين فنخرج من الموقع).
      if(wasActive && !fromPop){
        try{
          // إزالة إدخالة {player:'active'} العالقة لمزامنة المكدّس
          if(window.history.state && window.history.state.player === 'active'){
            _suppressNextGoBack = true;
            history.back();
          }
        }catch(e){}
      }
      return r;
    };
    window.closePlayer.__shsWrapped = true;
  }
  wrapClose();

  // عند تنفيذ history.back() أعلاه سيُطلق popstate → _goBack. لكن المشغّل
  // أصبح مغلقاً، فـ _goBack سيتعامل مع شاشة خلفية. نمنع تأثيراً جانبياً
  // واحداً فقط بعد إغلاقنا اليدوي.
  var _suppressNextGoBack = false;
  var _origGoBackGetter;
  function guardGoBack(){
    if(typeof window._goBack !== 'function'){ setTimeout(guardGoBack, 200); return; }
    if(window._goBack.__shsGuarded) return;
    var _orig = window._goBack;
    window._goBack = function(){
      if(_suppressNextGoBack){
        _suppressNextGoBack = false;
        return; // نتجاهل هذه الـ popstate الناتجة عن مزامنتنا فقط
      }
      return _orig.apply(this, arguments);
    };
    window._goBack.__shsGuarded = true;
  }
  guardGoBack();


  /* ══════════════════════════════════════════════════════════════════
     العيب 2: في التلفاز، بعد تقديم ثم إيقاف/تشغيل، شريط التحكم لا يختفي
     ─────────────────────────────────────────────────────────────────
     السبب: على التلفاز يدخل الفيديو buffering بعد التقديم، فحدث onplaying
     يتأخّر، فيبقى PL.userPaused في حالة انتقالية عند انتهاء مؤقّت الإخفاء
     فلا تُخفى القائمة إلا بالخروج وإعادة الدخول.

     الحل: حارس خفيف يصحّح PL.userPaused إن كان الفيديو يعمل فعلاً، ويعيد
     ضبط مؤقّت الإخفاء عبر showControls الأصلية. لا نغيّر منطق المشغّل.
  ══════════════════════════════════════════════════════════════════ */

  var _lastT = -1;
  function reconcile(){
    var ov = document.getElementById('playerOverlay');
    if(!ov || !ov.classList.contains('active')) return;
    var v = document.getElementById('html5Player');
    if(!v || !window.PL) return;

    var advancing = (!v.paused && !v.ended && v.currentTime !== _lastT);
    _lastT = v.currentTime;

    // الفيديو يعمل فعلاً لكن النظام يظنه متوقفاً → صحّح وأخفِ القائمة بعد المهلة
    if(advancing && window.PL.userPaused === true){
      window.PL.userPaused = false;
      if(typeof window.setPlayIcon === 'function'){ try{ window.setPlayIcon(false); }catch(e){} }
      if(typeof window.showControls === 'function'){ try{ window.showControls(); }catch(e){} }
    }
  }
  setInterval(reconcile, 700);

  // ضمان إعادة ضبط مؤقّت الإخفاء عند بدء التشغيل فعلياً (ولو تأخّر بعد بَفرة)
  function hookPlaying(){
    var v = document.getElementById('html5Player');
    if(!v){ setTimeout(hookPlaying, 300); return; }
    if(v.__shsPlayingHook) return;
    v.addEventListener('playing', function(){
      if(window.PL) window.PL.userPaused = false;
      if(typeof window.showControls === 'function'){ try{ window.showControls(); }catch(e){} }
    });
    v.__shsPlayingHook = true;
  }
  hookPlaying();
  setInterval(hookPlaying, 2000); // عنصر الفيديو قد يُعاد إنشاؤه عند تغيير المصدر

})();
</script>
<!-- ════════════════════ نهاية إصلاحات المشغّل ════════════════════ -->
<!-- ════════════ إصلاح تمرير شريط الأقسام على الكمبيوتر — إضافة آمنة ════════════ -->
<style id="shashety-catnav-fix-css">
  /* تسريع مذهل لصفحة الواجهة (تأجيل رسم الأقسام غير المرئية) */
  .netflix-slider-row {
    content-visibility: auto;
    contain-intrinsic-size: auto 300px;
  }
  
  /* أزرار تمرير لصفوف القنوات والأفلام (Netflix style) */
  .slider-scroll-mask { position: relative; }
  .shs-row-arrow {
    position: absolute; top: 0; bottom: 0; width: 45px; z-index: 10;
    background: rgba(0,0,0,0.6); color: white; border: none; font-size: 24px;
    cursor: pointer; opacity: 0; transition: opacity 0.2s, background 0.2s;
    display: flex; align-items: center; justify-content: center;
  }
  .shs-row-arrow:hover { background: rgba(0,0,0,0.85); color: #fff; }
  .slider-scroll-mask:hover .shs-row-arrow.shs-show { opacity: 1; }
  .shs-row-arrow.shs-show.shs-left { left: 0; background: linear-gradient(90deg, rgba(0,0,0,.8) 0%, rgba(0,0,0,0) 100%); }
  .shs-row-arrow.shs-show.shs-right { right: 0; background: linear-gradient(270deg, rgba(0,0,0,.8) 0%, rgba(0,0,0,0) 100%); }
  @media (hover: none), (max-width: 768px) { .shs-row-arrow { display: none !important; } }
  
  /* أزرار تمرير عائمة (لا تلمس بنية الشريط) */
  .shs-catnav-arrow{
    position:fixed;z-index:885;width:44px;
    display:flex;align-items:center;justify-content:center;
    border:none;cursor:pointer;color:#fff;
    opacity:0;pointer-events:none;transition:opacity .2s ease;
  }
  .shs-catnav-arrow.shs-show{opacity:1;pointer-events:auto;}
  .shs-catnav-arrow.shs-left{
    background:linear-gradient(90deg, rgba(10,10,10,.96) 35%, rgba(10,10,10,0));
    justify-content:flex-start;padding-left:8px;
  }
  .shs-catnav-arrow.shs-right{
    background:linear-gradient(270deg, rgba(10,10,10,.96) 35%, rgba(10,10,10,0));
    justify-content:flex-end;padding-right:8px;
  }
  .shs-catnav-arrow .shs-arrow-circle{
    width:32px;height:32px;border-radius:50%;
    background:rgba(229,9,20,.92);display:flex;align-items:center;justify-content:center;
    box-shadow:0 3px 12px rgba(0,0,0,.45);transition:transform .15s ease, background .2s ease;
  }
  .shs-catnav-arrow:hover .shs-arrow-circle{transform:scale(1.12);background:#ff2b35;}
</style>

<script id="shashety-catnav-fix-js">
(function(){
  'use strict';

  // ── وظائف تمرير صفوف القنوات/الأفلام ──
  window.shsScrollRow = function(btn, dir) {
    var lane = btn.parentElement.querySelector('.slider-cards-wrapper');
    if(!lane) return;
    var amount = Math.max(lane.clientWidth * 0.7, 200);
    lane.scrollBy({left: dir * amount, behavior: 'smooth'});
  };

  window.shsUpdateRowArrows = function(lane) {
    var mask = lane.parentElement;
    if(!mask) return;
    var leftBtn = mask.querySelector('.shs-row-arrow.shs-left');
    var rightBtn = mask.querySelector('.shs-row-arrow.shs-right');
    if(!leftBtn || !rightBtn) return;
    
    var overflow = lane.scrollWidth - lane.clientWidth;
    if(overflow <= 4) {
        leftBtn.classList.remove('shs-show');
        rightBtn.classList.remove('shs-show');
        return;
    }
    
    var sl = lane.scrollLeft;
    var dir = window.getComputedStyle(lane).direction;
    var atStart, atEnd;
    
    if(dir === 'rtl') {
        if(sl <= 0) {
            atStart = Math.abs(sl) <= 4;
            atEnd = Math.abs(sl) >= overflow - 4;
        } else {
            atStart = sl >= overflow - 4;
            atEnd = sl <= 4;
        }
    } else {
        atStart = sl <= 4;
        atEnd = sl >= overflow - 4;
    }
    
    if (dir === 'rtl') {
        rightBtn.classList.toggle('shs-show', !atStart);
        leftBtn.classList.toggle('shs-show', !atEnd);
    } else {
        leftBtn.classList.toggle('shs-show', !atStart);
        rightBtn.classList.toggle('shs-show', !atEnd);
    }
  };

  function setup(){
    var bar = document.getElementById('catNavbar');
    if(!bar) return false;
    if(bar.__shsCatnavFixed) return true;

    // ── أزرار عائمة مستقلة (لا نلمس الشريط ولا ننقله) ──
    var leftBtn = document.createElement('button');
    leftBtn.type='button';
    leftBtn.className='shs-catnav-arrow shs-left';
    leftBtn.setAttribute('aria-label','تمرير');
    leftBtn.innerHTML='<span class="shs-arrow-circle"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></span>';

    var rightBtn = document.createElement('button');
    rightBtn.type='button';
    rightBtn.className='shs-catnav-arrow shs-right';
    rightBtn.setAttribute('aria-label','تمرير');
    rightBtn.innerHTML='<span class="shs-arrow-circle"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></span>';

    document.body.appendChild(leftBtn);
    document.body.appendChild(rightBtn);

    // مزامنة موضع الأزرار مع موضع الشريط (أعلى/ارتفاع)
    function positionArrows(){
      var hidden = (bar.style.display==='none' || bar.offsetParent===null);
      if(hidden){
        // نخفي عبر class فقط (لا نلمس style.display حتى لا نتجاوز قاعدة @media)
        leftBtn.classList.remove('shs-show');
        rightBtn.classList.remove('shs-show');
        return;
      }
      var r = bar.getBoundingClientRect();
      [leftBtn,rightBtn].forEach(function(b){
        b.style.top = r.top+'px';
        b.style.height = r.height+'px';
      });
      leftBtn.style.left = '0px';
      rightBtn.style.right = '0px';
    }

    function scrollByAmount(dir){
      var amount = Math.max(220, bar.clientWidth*0.7);
      bar.scrollBy({ left: dir*amount, behavior:'smooth' });
    }
    rightBtn.addEventListener('click', function(){ scrollByAmount(1); });
    leftBtn.addEventListener('click',  function(){ scrollByAmount(-1); });

    // إظهار/إخفاء حسب وجود محتوى مخفي وموضع التمرير (يدعم جميع المتصفحات LTR و RTL)
    function updateArrows(){
      positionArrows();
      var hidden = (bar.style.display==='none' || bar.offsetParent===null);
      if(hidden) return;
      var overflow = bar.scrollWidth - bar.clientWidth;
      if(overflow <= 4){
        // لا يوجد محتوى زائد → لا حاجة للأزرار
        leftBtn.classList.remove('shs-show');
        rightBtn.classList.remove('shs-show');
        return;
      }
      
      var sl = bar.scrollLeft;
      var dir = window.getComputedStyle(bar).direction;
      var atStart, atEnd;
      
      if(dir === 'rtl') {
          if (sl <= 0) {
              // Chrome/Firefox: 0 إلى -overflow
              atStart = Math.abs(sl) <= 4;
              atEnd = Math.abs(sl) >= overflow - 4;
          } else {
              // Safari: overflow إلى 0
              atStart = sl >= overflow - 4;
              atEnd = sl <= 4;
          }
      } else {
          // LTR: 0 إلى overflow
          atStart = sl <= 4;
          atEnd = sl >= overflow - 4;
      }

      // في RTL البداية على اليمين والنهاية على اليسار
      if (dir === 'rtl') {
          rightBtn.classList.toggle('shs-show', !atStart);
          leftBtn.classList.toggle('shs-show', !atEnd);
      } else {
          leftBtn.classList.toggle('shs-show', !atStart);
          rightBtn.classList.toggle('shs-show', !atEnd);
      }
    }

    // عجلة الماوس العمودية → تمرير أفقي
    bar.addEventListener('wheel', function(e){
      if(bar.scrollWidth <= bar.clientWidth) return;
      if(Math.abs(e.deltaY) > Math.abs(e.deltaX)){
        e.preventDefault();
        bar.scrollLeft += e.deltaY;
      }
    }, {passive:false});

    bar.addEventListener('scroll', updateArrows, {passive:true});
    window.addEventListener('resize', updateArrows, {passive:true});
    window.addEventListener('scroll', positionArrows, {passive:true});

    // مراقبة إعادة بناء الأقسام
    try{
      var mo = new MutationObserver(function(){ setTimeout(updateArrows,50); });
      mo.observe(bar, {childList:true, attributes:true, attributeFilter:['style']});
    }catch(e){}

    updateArrows();
    setTimeout(updateArrows,300);
    setTimeout(updateArrows,1000);

    // ضمان قوي: نغلّف renderCategoryNavBar لتحديث الأزرار بعد كل إعادة بناء للأقسام
    if(typeof window.renderCategoryNavBar === 'function' && !window.renderCategoryNavBar.__shsHooked){
      var _origRender = window.renderCategoryNavBar;
      window.renderCategoryNavBar = function(){
        var r = _origRender.apply(this, arguments);
        setTimeout(updateArrows, 60);
        setTimeout(updateArrows, 400);
        return r;
      };
      window.renderCategoryNavBar.__shsHooked = true;
    }

    bar.__shsCatnavFixed = true;
    return true;
  }

  if(!setup()){
    var tries=0;
    var iv=setInterval(function(){ tries++; if(setup()||tries>40) clearInterval(iv); },250);
  }
})();
</script>
<!-- ════════════════════ نهاية إصلاح شريط الأقسام ════════════════════ -->

<?php /* ═══ تصدير إعدادات المجموعات إلى JS + تطبيق فعلي على الواجهة والمشغّل ═══ */ ?>
<script>
/* [أمان] لا نُصدّر كل $cfg إلى المتصفح — كان يكشف إعدادات الخادم لأي زائر.
   نُصدّر فقط المفاتيح التي تحتاجها الواجهة فعلاً (قائمة بيضاء). */
window.SITE_CFG = <?php
$__cfg_public_keys = [
    // الواجهة
    'theme_color','ui_font','ui_font_size','ui_transitions','usr_dark_mode',
    // المشغّل (سلوك مرئي فقط)
    'pl_autoplay','pl_mute_on_start','pl_pip','pl_playback_speed','pl_auto_fullscreen',
    'pl_show_channel_name','pl_show_channel_logo','pl_webcast','pl_show_clock',
    'pl_seek_buttons','pl_show_viewers','pl_show_share','pl_show_report',
    // الترجمة
    'sub_font_size','sub_font_color','sub_bg_color','sub_bg_opacity',
    // ميزات المستخدم
    'usr_notifications','usr_favorites','usr_watch_history',
    // الأفلام
    'mv_play_trailer','mv_show_similar',
    // إعدادات hls.js من جهة العميل فقط
    'st_default_quality','st_low_latency','st_max_buffer','st_back_buffer',
    'st_buffer_size','st_live_sync','st_auto_quality',
    'st_reconnect_attempts','st_reconnect_timeout',
];
$__cfg_public = [];
foreach ($__cfg_public_keys as $__k) {
    if (array_key_exists($__k, $cfg)) $__cfg_public[$__k] = $cfg[$__k];
}
echo json_encode($__cfg_public, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;
(function(){
  var C = window.SITE_CFG || {};
  // ── تطبيق إعدادات الواجهة (لون/خط/حجم الخط/الانتقالات/الخ) ──
  try {
    if (C.theme_color) document.documentElement.style.setProperty('--accent', C.theme_color);
    if (C.ui_font_size) document.documentElement.style.setProperty('--base-font-size', C.ui_font_size + 'px');
    if (C.ui_font) document.body.style.fontFamily = "'" + C.ui_font + "', Tajawal, sans-serif";
    if (C.ui_transitions === false) { var st=document.createElement('style'); st.textContent='*{transition:none !important;animation:none !important}'; document.head.appendChild(st); }
    
    // تطبيق إعدادات الإظهار والإخفاء المرئية
    var extraCss = '';
    if (C.pl_show_channel_name === false) extraCss += '#pChannelName{display:none !important;} ';
    if (C.pl_show_channel_logo === false) extraCss += '.p-channel-logo{display:none !important;} ';
    if (C.pl_webcast === false) extraCss += '[onclick="castToSmartWvc()"]{display:none !important;} ';
    if (C.pl_show_clock === false) extraCss += '#nxClock{display:none !important;} ';
    if (C.usr_notifications === false) extraCss += '.p-notifications,[onclick*="syncNotifications"]{display:none !important;} ';
    if (C.usr_favorites === false) extraCss += '.p-favorites,[onclick*="MyFavs"]{display:none !important;} ';
    if (C.usr_watch_history === false) extraCss += '.p-history,[onclick*="resumeWatch"]{display:none !important;} ';
    if (C.pl_seek_buttons === false) extraCss += '.p-seek-btn{display:none !important;} ';
    if (C.pl_show_viewers === false) extraCss += '#pViewers,.viewers-count{display:none !important;} ';
    if (C.pl_show_share === false) extraCss += '.p-share,[onclick*="share"]{display:none !important;} ';
    if (C.pl_show_report === false) extraCss += '.p-report,[onclick*="report"]{display:none !important;} ';
    if (C.mv_play_trailer === false) extraCss += '#trailerBtn,[onclick*="trailer"]{display:none !important;} ';
    if (C.mv_show_similar === false) extraCss += '#similarBox,.similar-section{display:none !important;} ';
    if (C.usr_dark_mode === false) extraCss += 'body{background:#f0f0f0 !important; color:#000 !important;} '; // تطبيق بسيط للوضع الفاتح
    
    // إعدادات الترجمة
    if (C.sub_font_size) extraCss += 'video#html5Player::cue{font-size:'+C.sub_font_size+'px !important;} ';
    if (C.sub_font_color) extraCss += 'video#html5Player::cue{color:'+C.sub_font_color+' !important;} ';
    if (C.sub_bg_color) {
      let opacity = C.sub_bg_opacity ? parseInt(C.sub_bg_opacity)/100 : 0.78;
      let alpha = Math.round(opacity * 255).toString(16).padStart(2,'0');
      let hex = String(C.sub_bg_color).substring(0,7);
      extraCss += 'video#html5Player::cue{background:'+hex+alpha+' !important;} ';
    }
    
    if (extraCss) { var style = document.createElement('style'); style.textContent = extraCss; document.head.appendChild(style); }
  } catch(e){}

  // ── تطبيق إعدادات مشغّل الفيديو فعلياً على أي <video> في الصفحة ──
  function applyPlayer(v){
    try{
      if (C.pl_autoplay) { v.autoplay = true; }
      if (C.pl_mute_on_start) { v.muted = true; }
      if (C.pl_pip === false) { v.setAttribute('disablePictureInPicture',''); }
      if (C.pl_playback_speed) { var s=parseFloat(C.pl_playback_speed); if(!isNaN(s)) v.playbackRate = s; }
      // منع التحميل حسب إعداد الأداء/الأفلام
      var cl = 'nodownload';
      if (C.st_default_quality) v.setAttribute('data-default-quality', C.st_default_quality);
      v.setAttribute('controlsList', cl);
      if (C.pl_auto_fullscreen) {
        v.addEventListener('play', function once(){ if(v.requestFullscreen) v.requestFullscreen().catch(function(){}); v.removeEventListener('play', once); });
      }
    }catch(e){}
  }
  function scanVideos(){ document.querySelectorAll('video').forEach(applyPlayer); }
  document.addEventListener('DOMContentLoaded', scanVideos);
  // مراقبة الفيديوهات المُضافة ديناميكياً
  try{
    var mo = new MutationObserver(function(muts){ muts.forEach(function(m){ m.addedNodes && m.addedNodes.forEach(function(n){ if(n.tagName==='VIDEO') applyPlayer(n); else if(n.querySelectorAll) n.querySelectorAll('video').forEach(applyPlayer); }); }); });
    mo.observe(document.documentElement,{childList:true,subtree:true});
  }catch(e){}

  // ── تطبيق إعدادات HLS.js إن كان مستخدماً (Buffer / ABR / Low Latency / إعادة الاتصال) ──
  // نضع القيم في window.SITE_HLS_CONFIG ليقرأها كود التشغيل عند إنشاء new Hls()
  window.SITE_HLS_CONFIG = {
    lowLatencyMode: !!C.st_low_latency,                                  // Low Latency Mode
    maxBufferLength: parseInt(C.st_max_buffer) || 60,                    // Max Buffer Length
    backBufferLength: parseInt(C.st_back_buffer) || 90,                  // Back Buffer Length
    maxMaxBufferLength: parseInt(C.st_buffer_size) || 30,                // Buffer Size
    liveSyncDuration: parseInt(C.st_live_sync) || 3,                     // Live Sync Duration
    startLevel: (C.st_auto_quality === false ? 0 : -1),                 // Auto Quality (ABR): -1 = تلقائي
    manifestLoadingMaxRetry: parseInt(C.st_reconnect_attempts) || 5,     // عدد محاولات إعادة الاتصال
    levelLoadingMaxRetry: parseInt(C.st_reconnect_attempts) || 5,
    fragLoadingMaxRetry: parseInt(C.st_reconnect_attempts) || 5,
    manifestLoadingRetryDelay: (parseInt(C.st_reconnect_timeout) || 3) * 1000, // المهلة قبل إعادة الاتصال
  };
})();
</script>

<?php /* ═══ إعدادات الأمان الحساسة من الإعدادات العامة ═══ */ ?>
<?php /* الكود المكرر لحماية devtools تم نقله بالكامل للأعلى */ ?>

<?php if ($gs_disable_download): ?>
<style>
/* منع تحميل الفيديوهات: إخفاء أي زر تنزيل + منع قائمة الفيديو */
.download-btn,[data-action="download"],.video-download,.dl-btn,#tdmDownloadBtn{display:none !important;}
video{pointer-events:auto;}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('video').forEach(function(v){
    v.setAttribute('controlsList','nodownload noremoteplayback');
    v.setAttribute('disablePictureInPicture','');
    v.addEventListener('contextmenu',function(e){e.preventDefault();});
  });
});
</script>
<?php endif; ?>

<?php /* كود مخصص يُحقن قبل نهاية body (شات/ودجت/سكربت) — من الإعدادات العامة */ ?>
<?php if (!empty($gs_custom_body_code)): ?>
<?php echo $gs_custom_body_code; ?>
<?php endif; ?>

</body>
</html>

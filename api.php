<?php
/**
 * API للتطبيق - Shashety IPTV
 * نقطة النهاية الرئيسية للبيانات
 */

// رؤوس CORS محسّنة
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// التعامل مع طلبات OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';

// ══════════════════════════════════════════════════════════════
// إنشاء جداول المسلسلات والحلقات تلقائياً إذا لم تكن موجودة
// ══════════════════════════════════════════════════════════════
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
        is_active TINYINT(1) DEFAULT 1,
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
} catch (PDOException $e) {
    // تجاهل إذا فشل الإنشاء
}

// الحصول على الإجراء المطلوب
$action = sanitizeInput($_GET['action'] ?? '');

// تحديد الإجراءات المسموح بها
// ── أُضيفت: all_content, series, episodes ──
$allowedActions = [
    'categories',
    'channels',
    'channel',
    'search',
    'featured',
    'stats',
    'increment_view',
    'all_content',
    'series',
    'episodes',
    'content_version',
];

if (!in_array($action, $allowedActions)) {
    jsonResponse([
        'success' => false,
        'error' => 'إجراء غير صالح'
    ], 400);
}

// توجيه الطلبات حسب الإجراء
switch($action) {
    case 'categories':
        getCategories();
        break;
        
    case 'channels':
        getChannels();
        break;
        
    case 'channel':
        getChannel();
        break;
        
    case 'search':
        searchChannels();
        break;
        
    case 'featured':
        getFeaturedChannels();
        break;
        
    case 'stats':
        getStatistics();
        break;
        
    case 'increment_view':
        incrementViewCount();
        break;

    // ── جديد ──
    case 'all_content':
        getAllContent();
        break;

    case 'series':
        getSeries();
        break;

    case 'episodes':
        getEpisodes();
        break;

    // ── بصمة المحتوى: للتحديث اللحظي بلا إعادة تحميل ──
    case 'content_version':
        getContentVersion();
        break;
        
    default:
        jsonResponse([
            'success' => false,
            'error' => 'إجراء غير معروف'
        ], 400);
}

/**
 * الحصول على جميع الأقسام مع عدد القنوات
 */
function getCategories() {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                c.id,
                c.name,
                c.slug,
                c.icon,
                c.description,
                COUNT(ch.id) as channel_count
            FROM categories c
            LEFT JOIN channels ch ON c.id = ch.category_id AND ch.is_active = 1
            WHERE c.is_active = 1
            GROUP BY c.id
            ORDER BY c.display_order ASC, c.name ASC
        ");
        
        $stmt->execute();
        $categories = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'count' => count($categories),
            'categories' => $categories
        ]);
        
    } catch(PDOException $e) {
        error_log("API Error - getCategories: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error' => 'حدث خطأ في جلب الأقسام'
        ], 500);
    }
}

/**
 * الحصول على القنوات حسب القسم
 */
function getChannels() {
    global $pdo;
    
    try {
        $category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        
        if ($category_id <= 0) {
            jsonResponse([
                'success' => false,
                'error' => 'معرف القسم غير صالح'
            ], 400);
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                ch.*,
                c.name as category_name,
                c.icon as category_icon
            FROM channels ch
            JOIN categories c ON ch.category_id = c.id
            WHERE ch.category_id = ? AND ch.is_active = 1
            ORDER BY ch.display_order ASC, ch.name ASC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$category_id, $limit, $offset]);
        $channels = $stmt->fetchAll();
        
        // الحصول على العدد الكلي
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM channels 
            WHERE category_id = ? AND is_active = 1
        ");
        $countStmt->execute([$category_id]);
        $total = $countStmt->fetch()['total'];
        
        jsonResponse([
            'success' => true,
            'count' => count($channels),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'channels' => $channels
        ]);
        
    } catch(PDOException $e) {
        error_log("API Error - getChannels: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error' => 'حدث خطأ في جلب القنوات'
        ], 500);
    }
}

/**
 * الحصول على قناة واحدة
 */
function getChannel() {
    global $pdo;
    
    try {
        $channel_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        
        if ($channel_id <= 0) {
            jsonResponse([
                'success' => false,
                'error' => 'معرف القناة غير صالح'
            ], 400);
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                ch.*,
                c.name as category_name,
                c.icon as category_icon
            FROM channels ch
            JOIN categories c ON ch.category_id = c.id
            WHERE ch.id = ? AND ch.is_active = 1
            LIMIT 1
        ");
        
        $stmt->execute([$channel_id]);
        $channel = $stmt->fetch();
        
        if (!$channel) {
            jsonResponse([
                'success' => false,
                'error' => 'القناة غير موجودة'
            ], 404);
        }
        
        jsonResponse([
            'success' => true,
            'channel' => $channel
        ]);
        
    } catch(PDOException $e) {
        error_log("API Error - getChannel: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error' => 'حدث خطأ في جلب القناة'
        ], 500);
    }
}

/**
 * البحث في القنوات
 * ── مُحسَّن: يبحث أيضاً في المسلسلات ──
 */
function searchChannels() {
    global $pdo;
    
    try {
        $query = sanitizeInput($_GET['q'] ?? '');
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        
        if (empty($query) || mb_strlen($query) < 2) {
            jsonResponse([
                'success' => false,
                'error' => 'يجب إدخال حرفين على الأقل للبحث'
            ], 400);
        }
        
        $searchTerm = '%' . $query . '%';
        
        // البحث في القنوات (الكود الأصلي)
        $stmt = $pdo->prepare("
            SELECT 
                ch.*,
                c.name as category_name,
                c.icon as category_icon
            FROM channels ch
            JOIN categories c ON ch.category_id = c.id
            WHERE ch.is_active = 1 
            AND (ch.name LIKE ? OR ch.description LIKE ?)
            ORDER BY ch.views_count DESC, ch.name ASC
            LIMIT ?
        ");
        
        $stmt->execute([$searchTerm, $searchTerm, $limit]);
        $channels = $stmt->fetchAll();

        // البحث في المسلسلات (جديد)
        $series = [];
        try {
            $srStmt = $pdo->prepare("
                SELECT s.*, c.name as cat_name, COUNT(e.id) as ep_count
                FROM series s
                LEFT JOIN categories c ON s.category_id = c.id
                LEFT JOIN episodes e ON e.series_id = s.id
                WHERE s.is_active = 1 AND s.name LIKE ?
                GROUP BY s.id
                ORDER BY s.name ASC
                LIMIT ?
            ");
            $srStmt->execute([$searchTerm, (int)ceil($limit / 2)]);
            $series = $srStmt->fetchAll();
        } catch (PDOException $e) {
            // الجدول غير موجود — تجاهل
        }
        
        jsonResponse([
            'success' => true,
            'query' => $query,
            'count' => count($channels),
            'channels' => $channels,
            'series' => $series,
        ]);
        
    } catch(PDOException $e) {
        error_log("API Error - searchChannels: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error' => 'حدث خطأ في البحث'
        ], 500);
    }
}

/**
 * الحصول على القنوات المميزة
 */
function getFeaturedChannels() {
    global $pdo;
    
    try {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        
        $stmt = $pdo->prepare("
            SELECT 
                ch.*,
                c.name as category_name,
                c.icon as category_icon
            FROM channels ch
            JOIN categories c ON ch.category_id = c.id
            WHERE ch.is_active = 1 AND ch.is_featured = 1
            ORDER BY ch.display_order ASC, ch.views_count DESC
            LIMIT ?
        ");
        
        $stmt->execute([$limit]);
        $channels = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'count' => count($channels),
            'channels' => $channels
        ]);
        
    } catch(PDOException $e) {
        error_log("API Error - getFeaturedChannels: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error' => 'حدث خطأ في جلب القنوات المميزة'
        ], 500);
    }
}

/**
 * الحصول على الإحصائيات العامة
 * ── مُحسَّن: يشمل إحصائيات المسلسلات والحلقات ──
 */
function getStatistics() {
    global $pdo;
    
    try {
        // عدد القنوات النشطة
        $channelsStmt = $pdo->query("SELECT COUNT(*) as total FROM channels WHERE is_active = 1");
        $totalChannels = $channelsStmt->fetch()['total'];
        
        // عدد الأقسام النشطة
        $categoriesStmt = $pdo->query("SELECT COUNT(*) as total FROM categories WHERE is_active = 1");
        $totalCategories = $categoriesStmt->fetch()['total'];
        
        // إجمالي المشاهدات
        $viewsStmt = $pdo->query("SELECT SUM(views_count) as total FROM channels");
        $totalViews = $viewsStmt->fetch()['total'] ?? 0;

        // إحصائيات المسلسلات والحلقات (جديد)
        $totalSeries   = 0;
        $totalEpisodes = 0;
        try {
            $totalSeries   = (int)$pdo->query("SELECT COUNT(*) FROM series WHERE is_active = 1")->fetchColumn();
            $totalEpisodes = (int)$pdo->query("SELECT COUNT(*) FROM episodes")->fetchColumn();
        } catch (PDOException $e) {
            // الجدول غير موجود — تجاهل
        }
        
        jsonResponse([
            'success' => true,
            'stats' => [
                // الحقول الأصلية — محفوظة
                'total_channels'   => $totalChannels,
                'total_categories' => $totalCategories,
                'total_views'      => $totalViews,
                'online'           => true,
                // الحقول الجديدة
                'total_series'     => $totalSeries,
                'total_episodes'   => $totalEpisodes,
                // أسماء مختصرة يستخدمها index.php
                'channels'         => $totalChannels,
                'categories'       => $totalCategories,
                'series'           => $totalSeries,
                'episodes'         => $totalEpisodes,
            ]
        ]);
        
    } catch(PDOException $e) {
        error_log("API Error - getStatistics: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error' => 'حدث خطأ في جلب الإحصائيات'
        ], 500);
    }
}

/**
 * زيادة عداد المشاهدات
 * ── مُحسَّن: يدعم ?type=channel|series|episode ──
 */
function incrementViewCount() {
    global $pdo;
    
    try {
        // قبول GET أو POST
        $channel_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['channel_id']) ? (int)$_POST['channel_id'] : 0);
        $type = sanitizeInput($_GET['type'] ?? 'channel');
        
        if ($channel_id <= 0) {
            jsonResponse([
                'success' => false,
                'error' => 'معرف القناة غير صالح'
            ], 400);
            return;
        }

        // مسلسل (جديد)
        if ($type === 'series') {
            try {
                $pdo->prepare("UPDATE series SET views_count = views_count + 1 WHERE id = ?")
                    ->execute([$channel_id]);
            } catch (PDOException $e) {}
            jsonResponse(['success' => true, 'message' => 'تم تسجيل المشاهدة', 'series_id' => $channel_id]);
            return;
        }

        // حلقة (جديد)
        if ($type === 'episode') {
            try {
                $pdo->prepare("UPDATE episodes SET views_count = views_count + 1 WHERE id = ?")
                    ->execute([$channel_id]);
            } catch (PDOException $e) {}
            jsonResponse(['success' => true, 'message' => 'تم تسجيل المشاهدة', 'episode_id' => $channel_id]);
            return;
        }

        // قناة — الكود الأصلي
        // التحقق من وجود القناة
        $checkStmt = $pdo->prepare("SELECT id FROM channels WHERE id = ? AND is_active = 1");
        $checkStmt->execute([$channel_id]);
        
        if (!$checkStmt->fetch()) {
            jsonResponse([
                'success' => false,
                'error' => 'القناة غير موجودة'
            ], 404);
            return;
        }
        
        // زيادة عداد المشاهدات - هذا الأهم!
        $stmt = $pdo->prepare("UPDATE channels SET views_count = views_count + 1 WHERE id = ?");
        $success = $stmt->execute([$channel_id]);
        
        if (!$success) {
            jsonResponse([
                'success' => false,
                'error' => 'فشل تحديث العداد'
            ], 500);
            return;
        }
        
        // تسجيل المشاهدة (اختياري - فقط إذا كان الجدول موجود)
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            
            $viewStmt = $pdo->prepare("
                INSERT INTO view_stats (channel_id, ip_address, user_agent, viewed_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $viewStmt->execute([$channel_id, $ip, $user_agent]);
        } catch(PDOException $e) {
            // الجدول غير موجود - تجاهل الخطأ
        }
        
        jsonResponse([
            'success' => true,
            'message' => 'تم تسجيل المشاهدة بنجاح',
            'channel_id' => $channel_id
        ]);
        
    } catch(PDOException $e) {
        error_log("API Error - incrementViewCount: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error' => 'حدث خطأ في تسجيل المشاهدة'
        ], 500);
    }
}

/* ══════════════════════════════════════════════════════════════
   الدوال الجديدة — المسلسلات والحلقات
══════════════════════════════════════════════════════════════ */

/**
 * all_content
 * يعيد الأقسام مع عدد القنوات والمسلسلات معاً
 * يستخدمه index.php لعرض شارة "X مسلسل" على بطاقة القسم
 */
function getAllContent() {
    global $pdo;

    try {
        $stmt = $pdo->query("
            SELECT
                c.id,
                c.name,
                c.slug,
                c.icon,
                c.description,
                c.display_order,
                COUNT(DISTINCT ch.id) as channel_count,
                COUNT(DISTINCT s.id)  as series_count
            FROM categories c
            LEFT JOIN channels ch ON ch.category_id = c.id AND ch.is_active = 1
            LEFT JOIN series   s  ON s.category_id  = c.id AND s.is_active  = 1
            WHERE c.is_active = 1
            GROUP BY c.id
            ORDER BY c.display_order ASC, c.name ASC
        ");

        $categories = $stmt->fetchAll();

        jsonResponse([
            'success'    => true,
            'count'      => count($categories),
            'categories' => $categories,
        ]);

    } catch (PDOException $e) {
        // احتياط: إذا فشل JOIN مع series نرجع للأقسام بدون series_count
        try {
            $stmt = $pdo->query("
                SELECT c.id, c.name, c.slug, c.icon, c.description, c.display_order,
                       COUNT(ch.id) as channel_count, 0 as series_count
                FROM categories c
                LEFT JOIN channels ch ON ch.category_id = c.id AND ch.is_active = 1
                WHERE c.is_active = 1
                GROUP BY c.id
                ORDER BY c.display_order ASC, c.name ASC
            ");
            $categories = $stmt->fetchAll();
            jsonResponse(['success' => true, 'count' => count($categories), 'categories' => $categories]);
        } catch (PDOException $e2) {
            error_log("API Error - getAllContent: " . $e2->getMessage());
            jsonResponse(['success' => false, 'error' => 'حدث خطأ في جلب المحتوى'], 500);
        }
    }
}

/**
 * series
 * جلب المسلسلات — كل المسلسلات أو حسب قسم محدد عبر ?category_id=X
 */
function getSeries() {
    global $pdo;

    try {
        $category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
        $limit       = isset($_GET['limit'])       ? (int)$_GET['limit']       : 200;
        $offset      = isset($_GET['offset'])      ? (int)$_GET['offset']      : 0;

        if ($category_id > 0) {
            $stmt = $pdo->prepare("
                SELECT s.*, c.name as cat_name, c.icon as cat_icon, COUNT(e.id) as ep_count
                FROM series s
                LEFT JOIN categories c ON s.category_id = c.id
                LEFT JOIN episodes   e ON e.series_id   = s.id
                WHERE s.category_id = ? AND s.is_active = 1
                GROUP BY s.id
                ORDER BY s.display_order ASC, s.id DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$category_id, $limit, $offset]);
        } else {
            $stmt = $pdo->prepare("
                SELECT s.*, c.name as cat_name, c.icon as cat_icon, COUNT(e.id) as ep_count
                FROM series s
                LEFT JOIN categories c ON s.category_id = c.id
                LEFT JOIN episodes   e ON e.series_id   = s.id
                WHERE s.is_active = 1
                GROUP BY s.id
                ORDER BY s.display_order ASC, s.id DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$limit, $offset]);
        }

        $series = $stmt->fetchAll();

        jsonResponse([
            'success' => true,
            'count'   => count($series),
            'series'  => $series,
        ]);

    } catch (PDOException $e) {
        error_log("API Error - getSeries: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'حدث خطأ في جلب المسلسلات'], 500);
    }
}

/**
 * episodes
 * جلب حلقات مسلسل محدد عبر ?series_id=X
 */
function getEpisodes() {
    global $pdo;

    try {
        $series_id = isset($_GET['series_id']) ? (int)$_GET['series_id'] : 0;

        if ($series_id <= 0) {
            jsonResponse(['success' => false, 'error' => 'معرّف المسلسل غير صالح'], 400);
            return;
        }

        // التحقق من وجود المسلسل
        $check = $pdo->prepare("SELECT id, name FROM series WHERE id = ? AND is_active = 1");
        $check->execute([$series_id]);
        $sr = $check->fetch();

        if (!$sr) {
            jsonResponse(['success' => false, 'error' => 'المسلسل غير موجود'], 404);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM episodes
            WHERE series_id = ?
            ORDER BY episode_number ASC, display_order ASC, id ASC
        ");
        $stmt->execute([$series_id]);
        $episodes = $stmt->fetchAll();

        jsonResponse([
            'success'     => true,
            'series_id'   => $series_id,
            'series_name' => $sr['name'],
            'count'       => count($episodes),
            'episodes'    => $episodes,
        ]);

    } catch (PDOException $e) {
        error_log("API Error - getEpisodes: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'حدث خطأ في جلب الحلقات'], 500);
    }
}

/**
 * content_version
 * بصمة صغيرة تتغيّر عند أي إضافة/حذف في المحتوى.
 * تستعملها index.php للتحديث اللحظي بلا إعادة تحميل الصفحة.
 *
 * COUNT(*) + MAX(id) معاً يكشفان:
 *   • الإضافة → يزيدان
 *   • الحذف   → COUNT ينقص
 *   • إضافة+حذف بنفس العدد → MAX(id) يكشفها
 *
 * مهم: نحسب القنوات/المسلسلات النشطة فقط (is_active = 1) ليتطابق
 * ما نراقبه مع ما تعرضه بقية نقاط الـAPI فعلاً؛ فتفعيل قناة أو
 * تعطيلها من لوحة التحكم يُعتبر تغييراً أيضاً.
 */
function getContentVersion() {
    global $pdo;

    // لا كاش إطلاقاً على هذه النقطة، وإلا لن يصل التغيير للمتصفح
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $parts = [];

    // القنوات والمسلسلات: النشطة فقط + أعلى معرّف
    foreach (['channels' => 'is_active = 1', 'series' => 'is_active = 1'] as $tbl => $cond) {
        try {
            $row = $pdo->query("SELECT COUNT(*) AS c, COALESCE(MAX(id),0) AS m FROM `$tbl` WHERE $cond")
                       ->fetch(PDO::FETCH_ASSOC);
            $parts[] = $tbl . ':' . (int)$row['c'] . ':' . (int)$row['m'];
        } catch (PDOException $e) {
            // إن لم يوجد عمود is_active نعيد المحاولة بلا شرط
            try {
                $row = $pdo->query("SELECT COUNT(*) AS c, COALESCE(MAX(id),0) AS m FROM `$tbl`")
                           ->fetch(PDO::FETCH_ASSOC);
                $parts[] = $tbl . ':' . (int)$row['c'] . ':' . (int)$row['m'];
            } catch (PDOException $e2) {
                $parts[] = $tbl . ':0:0';
            }
        }
    }

    // الحلقات والأقسام: بلا شرط (الحلقات ليس فيها is_active)
    foreach (['episodes', 'categories'] as $tbl) {
        try {
            $row = $pdo->query("SELECT COUNT(*) AS c, COALESCE(MAX(id),0) AS m FROM `$tbl`")
                       ->fetch(PDO::FETCH_ASSOC);
            $parts[] = $tbl . ':' . (int)$row['c'] . ':' . (int)$row['m'];
        } catch (PDOException $e) {
            $parts[] = $tbl . ':0:0';
        }
    }

    // بصمة قصيرة للمقارنة فقط (ليست لغرض أمني)
    $version = substr(md5(implode('|', $parts)), 0, 16);

    jsonResponse([
        'success' => true,
        'version' => $version,
        'ts'      => time(),
    ]);
}

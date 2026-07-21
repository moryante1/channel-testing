<?php
// orig 2768-2898

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



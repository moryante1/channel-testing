<?php
require_once 'config.php';

$username = 'admin';
$password = 'Ali1992320';
$results  = [];
$hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// ── 1. تحديث جدول users ──
try {
    $s = $pdo->prepare("UPDATE users SET password=? WHERE username=?");
    $s->execute([$hash, $username]);
    $results[] = ['ok', 'جدول users: تم تحديث كلمة المرور (' . $s->rowCount() . ' صف)'];
} catch (PDOException $e) {
    $results[] = ['err', 'جدول users: ' . $e->getMessage()];
}

// ── 2. إنشاء جدول admin_users بدون DEFAULT على TEXT ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `admin_users` (
        `id`               INT AUTO_INCREMENT PRIMARY KEY,
        `username`         VARCHAR(100) NOT NULL UNIQUE,
        `password_hash`    VARCHAR(255) NOT NULL,
        `display_name`     VARCHAR(100),
        `role`             VARCHAR(20) DEFAULT 'normal',
        `allowed_sections` TEXT,
        `is_active`        TINYINT(1) DEFAULT 1,
        `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `last_login`       TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = ['ok', 'جدول admin_users: تم الإنشاء بنجاح'];
} catch (PDOException $e) {
    $results[] = ['err', 'إنشاء admin_users: ' . $e->getMessage()];
}

// ── 3. تحديث أو إنشاء المستخدم في admin_users ──
try {
    $check = $pdo->prepare("SELECT id, role FROM admin_users WHERE username=?");
    $check->execute([$username]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $pdo->prepare("UPDATE admin_users SET password_hash=?, role='administrator', is_active=1, display_name='Admin', allowed_sections='[]' WHERE username=?")
            ->execute([$hash, $username]);
        $results[] = ['ok', 'admin_users: تم التحديث — role=administrator (كان: ' . $existing['role'] . ')'];
    } else {
        $pdo->prepare("INSERT INTO admin_users (username, password_hash, display_name, role, allowed_sections, is_active) VALUES (?, ?, 'Admin', 'administrator', '[]', 1)")
            ->execute([$username, $hash]);
        $results[] = ['ok', 'admin_users: تم الإنشاء — role=administrator — ID=' . $pdo->lastInsertId()];
    }
} catch (PDOException $e) {
    $results[] = ['err', 'admin_users خطأ: ' . $e->getMessage()];
}

// ── 4. التحقق النهائي ──
$final = null;
try {
    $st = $pdo->prepare("SELECT id, username, role, is_active FROM admin_users WHERE username=?");
    $st->execute([$username]);
    $final = $st->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $results[] = ['err', 'خطأ في التحقق: ' . $e->getMessage()];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>إصلاح صلاحيات المدير</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Tahoma,sans-serif;background:#0f0f0f;color:#eee;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.wrap{width:100%;max-width:520px}
.card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.6)}
.card-head{background:#E50914;padding:24px 28px;text-align:center}
.card-head h1{font-size:1.3rem;font-weight:700;color:#fff;margin-bottom:4px}
.card-head p{font-size:.8rem;color:rgba(255,255,255,.75)}
.card-body{padding:28px}
.row{display:flex;align-items:flex-start;gap:10px;padding:11px 14px;border-radius:8px;margin-bottom:8px;font-size:.85rem;line-height:1.5}
.row.ok{background:rgba(0,208,132,.1);border:1px solid rgba(0,208,132,.25);color:#00D084}
.row.err{background:rgba(229,9,20,.1);border:1px solid rgba(229,9,20,.25);color:#ff6b6b}
.row.info{background:rgba(76,201,240,.1);border:1px solid rgba(76,201,240,.2);color:#4CC9F0}
.final{background:#111;border:1px solid #2a2a2a;border-radius:8px;padding:16px;margin:20px 0}
.final h3{font-size:.75rem;color:#555;text-transform:uppercase;letter-spacing:.1em;margin-bottom:12px}
.frow{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #1e1e1e;font-size:.875rem}
.frow:last-child{border-bottom:none}
.lbl{color:#666}
.val{font-weight:700;color:#eee}
.val.red{color:#E50914}
.val.green{color:#00D084}
.warn{background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);border-radius:8px;padding:12px 16px;font-size:.8rem;color:#f5a623;margin-bottom:20px}
.btn{display:block;width:100%;padding:14px;background:#E50914;color:#fff;font-size:1rem;font-weight:700;text-align:center;border-radius:8px;text-decoration:none}
.btn:hover{background:#f01020}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="card-head">
      <h1>&#x1F527; إصلاح صلاحيات المدير</h1>
      <p>المستخدم: <?= htmlspecialchars($username) ?></p>
    </div>
    <div class="card-body">

      <?php foreach ($results as $r): ?>
      <div class="row <?= $r[0] ?>">
        <?php if($r[0]==='ok'):?>&#x2705; <?php elseif($r[0]==='err'):?>&#x274C; <?php else:?>&#x2139;&#xFE0F; <?php endif;?>
        <span><?= htmlspecialchars($r[1]) ?></span>
      </div>
      <?php endforeach; ?>

      <?php if ($final): ?>
      <div class="final">
        <h3>النتيجة النهائية</h3>
        <div class="frow"><span class="lbl">ID</span><span class="val"><?= $final['id'] ?></span></div>
        <div class="frow"><span class="lbl">اسم المستخدم</span><span class="val"><?= htmlspecialchars($final['username']) ?></span></div>
        <div class="frow"><span class="lbl">كلمة المرور</span><span class="val"><?= htmlspecialchars($password) ?></span></div>
        <div class="frow"><span class="lbl">الصلاحية</span><span class="val red"><?= $final['role'] ?> <?= $final['role']==='administrator'?'&#x1F451;':'' ?></span></div>
        <div class="frow"><span class="lbl">الحالة</span><span class="val green"><?= $final['is_active']?'&#x2705; نشط':'&#x274C; معطل' ?></span></div>
      </div>
      <?php else: ?>
      <div class="row err">&#x274C; <span>لم يتم إنشاء المستخدم — راجع الأخطاء أعلاه</span></div>
      <?php endif; ?>

      <div class="warn">&#x26A0;&#xFE0F; احذف ملف <strong>fix_admin_role.php</strong> فوراً بعد الاستخدام!</div>
      <a href="login.php" class="btn">&#x1F510; تسجيل الدخول الآن</a>

    </div>
  </div>
</div>
</body>
</html>

<?php
require_once 'config.php';

// ============================================================
//  غيّر كلمة المرور هنا فقط ⬇️
$new_password = 'Ali1992320';
$username     = 'admin';
// ============================================================

$result_type  = '';
$result_msg   = '';
$hash_display = '';

try {
    $check = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($check->rowCount() == 0) {
        $result_type = 'error';
        $result_msg  = 'جدول users غير موجود! استورد database.sql أولاً.';
    } else {
        $hashed = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt   = $pdo->prepare("UPDATE users SET password = ? WHERE username = ?");
        $ok     = $stmt->execute([$hashed, $username]);

        if ($ok && $stmt->rowCount() > 0) {
            $result_type  = 'success';
            $hash_display = $hashed;
            error_log("[SECURITY] Password reset for '{$username}' from IP: " . ($_SERVER['REMOTE_ADDR'] ?? '') . " at " . date('Y-m-d H:i:s'));
        } elseif ($stmt->rowCount() === 0) {
            $result_type = 'error';
            $result_msg  = "اسم المستخدم '$username' غير موجود في قاعدة البيانات.";
        } else {
            $result_type = 'error';
            $result_msg  = 'فشل تحديث كلمة المرور!';
        }
    }
} catch (PDOException $e) {
    $result_type = 'error';
    $result_msg  = 'خطأ في قاعدة البيانات: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>إعادة تعيين كلمة المرور</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root{
  --red:#E50914;
  --red-glow:rgba(229,9,20,0.3);
  --bg:#141414;
  --surface:rgba(0,0,0,0.85);
  --text:#fff;
  --muted:#a3a3a3;
  --success:#46d369;
  --warn:#f5a623;
}

body{
  font-family:'Cairo',sans-serif;
  background:var(--bg);
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  overflow:hidden;
}

.bg{position:fixed;inset:0;z-index:0;
  background:
    radial-gradient(ellipse 80% 50% at 50% -5%,rgba(229,9,20,.2) 0%,transparent 70%),
    radial-gradient(ellipse 50% 40% at 90% 100%,rgba(229,9,20,.08) 0%,transparent 60%),
    #141414;
}
.bg-grid{position:fixed;inset:0;z-index:0;
  background-image:
    linear-gradient(rgba(255,255,255,.018) 1px,transparent 1px),
    linear-gradient(90deg,rgba(255,255,255,.018) 1px,transparent 1px);
  background-size:55px 55px;
  mask-image:radial-gradient(ellipse 70% 70% at 50% 50%,black 30%,transparent 100%);
}

.particles{position:fixed;inset:0;z-index:0;pointer-events:none}
.p{position:absolute;border-radius:50%;opacity:0;animation:up linear infinite}
.p:nth-child(1){left:8%;width:3px;height:3px;background:var(--red);animation-duration:9s;animation-delay:0s}
.p:nth-child(2){left:22%;width:2px;height:2px;background:var(--red);animation-duration:13s;animation-delay:2s}
.p:nth-child(3){left:45%;width:3px;height:3px;background:var(--red);animation-duration:8s;animation-delay:4s}
.p:nth-child(4){left:65%;width:2px;height:2px;background:var(--red);animation-duration:11s;animation-delay:1s}
.p:nth-child(5){left:82%;width:3px;height:3px;background:var(--red);animation-duration:7s;animation-delay:3s}
.p:nth-child(6){left:55%;width:2px;height:2px;background:var(--red);animation-duration:14s;animation-delay:5s}
@keyframes up{
  0%{transform:translateY(100vh) scale(0);opacity:0}
  10%{opacity:.7}
  90%{opacity:.2}
  100%{transform:translateY(-10vh) scale(1.5);opacity:0}
}

.wrap{position:relative;z-index:10;width:100%;max-width:460px;padding:20px;
  animation:fadein .7s cubic-bezier(.16,1,.3,1) both}
@keyframes fadein{from{opacity:0;transform:translateY(28px)}to{opacity:1;transform:translateY(0)}}

.logo{text-align:center;margin-bottom:32px}
.logo-mark{font-size:2.6rem;font-weight:900;color:var(--red);letter-spacing:-1px;
  text-shadow:0 0 35px var(--red-glow),0 2px 6px rgba(0,0,0,.8)}
.logo-tag{display:block;font-size:.65rem;letter-spacing:4px;color:var(--muted);
  text-transform:uppercase;margin-top:3px}

.card{
  background:var(--surface);
  border:1px solid rgba(255,255,255,.07);
  border-radius:6px;
  padding:44px 40px 36px;
  backdrop-filter:blur(18px);
  box-shadow:0 25px 60px rgba(0,0,0,.7),0 0 0 1px rgba(255,255,255,.03);
}
.card-title{font-size:1.7rem;font-weight:700;color:var(--text);margin-bottom:6px}
.card-sub{font-size:.85rem;color:var(--muted);margin-bottom:28px;line-height:1.6}

.result{border-radius:5px;padding:20px;
  animation:slidein .4s cubic-bezier(.16,1,.3,1) both}
@keyframes slidein{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
.result.success{background:rgba(70,211,105,.08);border:1px solid rgba(70,211,105,.25)}
.result.error{background:rgba(229,9,20,.1);border:1px solid rgba(229,9,20,.3)}

.result-icon{font-size:2rem;margin-bottom:10px;display:block}
.result-title{font-size:1.05rem;font-weight:700;margin-bottom:14px}
.result.success .result-title{color:var(--success)}
.result.error   .result-title{color:#ff6b6b}

.info-row{
  display:flex;align-items:center;justify-content:space-between;
  padding:9px 12px;border-radius:4px;margin-bottom:6px;
  background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);
  font-size:.85rem;
}
.info-label{color:var(--muted)}
.info-val{color:var(--text);font-weight:600}

.hash-box{
  margin-top:12px;padding:10px 12px;
  background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.08);
  border-radius:4px;font-size:.66rem;color:#555;
  word-break:break-all;line-height:1.6;direction:ltr;text-align:left;
}
.hash-label{font-size:.72rem;color:var(--muted);margin-bottom:5px;display:block}

.btn{
  display:inline-flex;align-items:center;gap:8px;
  margin-top:20px;padding:13px 28px;
  background:var(--red);color:#fff;
  font-family:'Cairo',sans-serif;font-size:.95rem;font-weight:700;
  border:none;border-radius:4px;cursor:pointer;text-decoration:none;
  transition:all .2s;
}
.btn:hover{background:#f40612;transform:translateY(-1px);
  box-shadow:0 8px 25px rgba(229,9,20,.4)}

.warn-box{
  margin-top:18px;padding:11px 14px;
  background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.22);
  border-radius:4px;font-size:.78rem;color:var(--warn);
  display:flex;gap:8px;align-items:flex-start;line-height:1.5;
}
.sec-note{
  display:flex;align-items:center;justify-content:center;gap:5px;
  margin-top:16px;font-size:.72rem;color:#444;
}
</style>
</head>
<body>

<div class="bg"></div>
<div class="bg-grid"></div>
<div class="particles">
  <div class="p"></div><div class="p"></div><div class="p"></div>
  <div class="p"></div><div class="p"></div><div class="p"></div>
</div>

<div class="wrap">

  <div class="logo">
    <span class="logo-mark">STREAM</span>
    <span class="logo-tag">Admin Portal</span>
  </div>

  <div class="card">
    <h1 class="card-title">إعادة تعيين كلمة المرور</h1>
    <p class="card-sub">نتيجة العملية على قاعدة البيانات</p>

    <div class="result <?= $result_type ?>">

      <?php if ($result_type === 'success'): ?>

        <span class="result-icon">✅</span>
        <div class="result-title">تم التحديث بنجاح!</div>

        <div class="info-row">
          <span class="info-label">اسم المستخدم</span>
          <span class="info-val"><?= htmlspecialchars($username) ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">كلمة المرور</span>
          <span class="info-val"><?= htmlspecialchars($new_password) ?></span>
        </div>

        <span class="hash-label">Hash المُولَّد:</span>
        <div class="hash-box"><?= htmlspecialchars($hash_display) ?></div>

        <a href="admin.php" class="btn">🔐 تسجيل الدخول</a>

      <?php else: ?>

        <span class="result-icon">❌</span>
        <div class="result-title">فشلت العملية</div>
        <p style="color:#ff6b6b;font-size:.88rem;line-height:1.6">
          <?= htmlspecialchars($result_msg) ?>
        </p>

      <?php endif; ?>

    </div>

    <div class="warn-box">
      ⚠️ <span>احذف ملف <strong>reset_password.php</strong> من السيرفر فوراً بعد الاستخدام!</span>
    </div>

    <div class="sec-note">🔒 Bcrypt · cost=12</div>
  </div>

</div>

</body>
</html>

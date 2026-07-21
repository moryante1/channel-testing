<?php
/**
 * صفحة تسجيل الدخول - Shashety IPTV
 * محسّنة للأمان والأداء
 */

require_once 'config.php';

if (isAdminLoggedIn()) {
    redirect('admin.php');
}

/* ── CSRF token ── */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$error = '';

$clientIp     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$lockoutTime  = 300;
$maxAttempts  = 5;

$loginAttempts = $_SESSION['login_attempts'] ?? 0;
$lastAttempt   = $_SESSION['last_attempt'] ?? 0;

if ($loginAttempts >= $maxAttempts) {
    $timePassed = time() - $lastAttempt;
    if ($timePassed < $lockoutTime) {
        $remainingTime = ceil(($lockoutTime - $timePassed) / 60);
        $error = "تم تجاوز عدد المحاولات. يرجى الانتظار {$remainingTime} دقيقة.";
    } else {
        $_SESSION['login_attempts'] = 0;
        $loginAttempts = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $loginAttempts < $maxAttempts) {

    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($_SESSION['csrf_token'], $postedToken)) {
        $error = 'انتهت صلاحية الجلسة. يرجى إعادة المحاولة.';
        logActivity('فشل التحقق من CSRF', "IP: {$clientIp}");
    } else {
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'يرجى إدخال اسم المستخدم وكلمة المرور';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
                $stmt->execute([$username]);
                $admin = $stmt->fetch();

                $hash = $admin['password'] ?? '$2y$12$usesomesillystringforsalt0000000000000000000000000000';
                $passwordOk = password_verify($password, $hash);

                if ($admin && $passwordOk) {
                    session_regenerate_id(true);

                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_id']        = $admin['id'];
                    $_SESSION['admin_username']  = $admin['username'];
                    $_SESSION['login_attempts']  = 0;
                    $_SESSION['csrf_token']       = bin2hex(random_bytes(32));

                    $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $updateStmt->execute([$admin['id']]);

                    logActivity('تسجيل دخول ناجح', "المستخدم: {$username}");
                    redirect('admin.php');
                } else {
                    $_SESSION['login_attempts'] = $loginAttempts + 1;
                    $_SESSION['last_attempt']   = time();
                    $error = 'اسم المستخدم أو كلمة المرور غير صحيحة';
                    logActivity('محاولة تسجيل دخول فاشلة', "المستخدم: {$username} | IP: {$clientIp}");
                }
            } catch (PDOException $e) {
                error_log("Login Error: " . $e->getMessage());
                $error = 'حدث خطأ في عملية تسجيل الدخول';
            }
        }
    }
    $loginAttempts = $_SESSION['login_attempts'] ?? $loginAttempts;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>تسجيل الدخول — Shashety IPTV</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* ══════════════════════════════════════
           THEME TOKENS
           ══════════════════════════════════════ */
        :root {
            --red: #e50914;
            --red-soft: #ff3b44;
            --radius: 14px;
            --radius-sm: 10px;
            --ease: cubic-bezier(0.4, 0, 0.2, 1);
            --font: 'Cairo', sans-serif;
        }

        /* ── DARK (cinematic) ── */
        [data-theme="dark"] {
            --bg: #0a0a0c;
            --bg-glow-1: rgba(229,9,20,0.10);
            --bg-glow-2: rgba(120,8,12,0.10);
            --card-bg: rgba(20,20,24,0.72);
            --card-border: rgba(255,255,255,0.10);
            --text: #f5f5f7;
            --text-soft: #9a9aa2;
            --text-faint: #5a5a62;
            --input-bg: rgba(255,255,255,0.04);
            --input-border: rgba(255,255,255,0.12);
            --input-text: #ffffff;
            --divider: rgba(255,255,255,0.08);
            --shadow: 0 30px 70px rgba(0,0,0,0.6);
            --emblem-shadow: 0 10px 40px rgba(229,9,20,0.35);
        }

        /* ── LIGHT (clean / daytime) ── */
        [data-theme="light"] {
            --bg: #f0f1f4;
            --bg-glow-1: rgba(229,9,20,0.06);
            --bg-glow-2: rgba(180,0,0,0.04);
            --card-bg: rgba(255,255,255,0.85);
            --card-border: rgba(0,0,0,0.08);
            --text: #16161a;
            --text-soft: #5c5c66;
            --text-faint: #9a9aa4;
            --input-bg: rgba(0,0,0,0.025);
            --input-border: rgba(0,0,0,0.12);
            --input-text: #16161a;
            --divider: rgba(0,0,0,0.08);
            --shadow: 0 24px 60px rgba(0,0,0,0.12);
            --emblem-shadow: 0 10px 34px rgba(229,9,20,0.28);
        }

        *, *::before, *::after {
            margin: 0; padding: 0; box-sizing: border-box;
        }

        html { font-size: 16px; }

        body {
            font-family: var(--font);
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            color: var(--text);
            transition: background 0.5s var(--ease), color 0.5s var(--ease);
        }

        /* ── Quiet ambient glow background ── */
        .bg-glow {
            position: fixed;
            inset: 0;
            z-index: 0;
            background:
                radial-gradient(ellipse 60% 50% at 18% 22%, var(--bg-glow-1) 0%, transparent 60%),
                radial-gradient(ellipse 55% 50% at 82% 80%, var(--bg-glow-2) 0%, transparent 62%);
            transition: background 0.5s var(--ease);
        }

        .bg-vignette {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            background: radial-gradient(ellipse 90% 90% at 50% 50%, transparent 55%, rgba(0,0,0,0.25) 100%);
            opacity: 0;
            transition: opacity 0.5s var(--ease);
        }
        [data-theme="dark"] .bg-vignette { opacity: 1; }

        /* ── Theme toggle (top) ── */
        .theme-toggle {
            position: fixed;
            top: 24px;
            left: 24px;
            z-index: 20;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 100px;
            padding: 8px 14px;
            cursor: pointer;
            color: var(--text-soft);
            font-family: var(--font);
            font-size: 0.82rem;
            font-weight: 600;
            transition: color 0.25s, border-color 0.25s, background 0.25s;
        }
        .theme-toggle:hover {
            color: var(--text);
            border-color: var(--red);
        }
        .theme-toggle i { font-size: 0.95rem; color: var(--red); width: 16px; text-align: center; }

        /* ── Brand wordmark (top right) ── */
        .brand {
            position: fixed;
            top: 24px;
            right: 28px;
            z-index: 20;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .brand-logo {
            width: 44px; height: 44px;
            display: flex; align-items: center; justify-content: center;
            color: var(--red); font-size: 1.1rem;
            filter: drop-shadow(0 3px 8px rgba(229,9,20,0.35));
        }
        .brand-logo img {
            width: 100%; height: 100%;
            object-fit: contain;
        }
        .brand-name {
            font-size: 1.05rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            color: var(--text);
        }

        /* ══════════════════════════════════════
           CARD
           ══════════════════════════════════════ */
        .login-card {
            position: relative;
            z-index: 5;
            width: 100%;
            max-width: 420px;
            margin: 0 20px;
            background: var(--card-bg);
            backdrop-filter: blur(30px) saturate(1.2);
            -webkit-backdrop-filter: blur(30px) saturate(1.2);
            border-radius: var(--radius);
            border: 1px solid var(--card-border);
            padding: 44px 40px 36px;
            box-shadow: var(--shadow);
            animation: cardIn 0.7s var(--ease) both;
            transition: background 0.5s var(--ease), border-color 0.5s var(--ease), box-shadow 0.5s var(--ease);
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Emblem: TV / broadcast screen ── */
        .emblem {
            display: flex;
            justify-content: center;
            margin-bottom: 22px;
        }
        .emblem-box {
            width: 120px; height: 120px;
            display: flex; align-items: center; justify-content: center;
            color: var(--red);
            font-size: 3rem;
            filter: drop-shadow(0 8px 24px rgba(229,9,20,0.35));
        }
        .emblem-box img {
            width: 100%; height: 100%;
            object-fit: contain;
        }

        /* ── Header ── */
        .card-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .card-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 6px;
            letter-spacing: -0.01em;
        }
        .card-subtitle {
            font-size: 0.88rem;
            color: var(--text-soft);
            font-weight: 500;
        }

        /* ── Error alert ── */
        .alert-error {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(229, 9, 20, 0.10);
            border: 1px solid rgba(229, 9, 20, 0.30);
            border-radius: var(--radius-sm);
            padding: 12px 14px;
            margin-bottom: 22px;
            animation: fadeIn 0.3s var(--ease);
        }
        .alert-error i { color: var(--red); font-size: 0.95rem; flex-shrink: 0; }
        .alert-error span { font-size: 0.86rem; color: var(--red-soft); font-weight: 600; line-height: 1.4; }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        /* ── Attempts ── */
        .attempts {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 20px;
            font-size: 0.78rem;
            color: var(--text-soft);
        }
        .attempts-dots { display: flex; gap: 5px; }
        .dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: var(--text-faint);
            transition: background 0.3s;
        }
        .dot.used { background: var(--red); }

        /* ── Fields ── */
        .field { margin-bottom: 16px; }
        .field-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-soft);
            margin-bottom: 7px;
        }
        .input-wrap { position: relative; }
        .input-icon {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-faint);
            font-size: 0.9rem;
            pointer-events: none;
            transition: color 0.2s;
        }
        .field-input {
            width: 100%;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: var(--radius-sm);
            color: var(--input-text);
            font-family: var(--font);
            font-size: 0.98rem;
            font-weight: 500;
            padding: 13px 42px 13px 14px;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
            outline: none;
        }
        #password { padding-left: 42px; }
        .field-input::placeholder { color: var(--text-faint); font-weight: 400; }
        .field-input:focus {
            border-color: var(--red);
            box-shadow: 0 0 0 3px rgba(229,9,20,0.12);
        }
        .field-input:focus ~ .input-icon { color: var(--red); }
        .field-input:disabled { opacity: 0.45; cursor: not-allowed; }

        .toggle-pw {
            position: absolute;
            left: 12px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: var(--text-faint);
            cursor: pointer;
            font-size: 0.9rem;
            padding: 4px;
            z-index: 2;
            transition: color 0.2s;
        }
        .toggle-pw:hover { color: var(--text); }

        /* ── Submit ── */
        .btn-submit {
            width: 100%;
            margin-top: 8px;
            padding: 14px;
            background: var(--red);
            border: none;
            border-radius: var(--radius-sm);
            color: #fff;
            font-family: var(--font);
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            transition: background 0.2s, transform 0.12s, box-shadow 0.2s;
            box-shadow: 0 6px 18px rgba(229,9,20,0.28);
        }
        .btn-submit:hover:not(:disabled) {
            background: #f40612;
            transform: translateY(-1px);
            box-shadow: 0 9px 26px rgba(229,9,20,0.4);
        }
        .btn-submit:active:not(:disabled) { transform: translateY(0); }
        .btn-submit:disabled { background: #6b2226; opacity: 0.55; cursor: not-allowed; box-shadow: none; }

        .spinner {
            display: none;
            width: 17px; height: 17px;
            border: 2px solid rgba(255,255,255,0.35);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Footer ── */
        .card-footer {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 18px;
            margin-top: 26px;
            padding-top: 20px;
            border-top: 1px solid var(--divider);
        }
        .footer-item {
            display: flex; align-items: center; gap: 6px;
            font-size: 0.76rem;
            color: var(--text-faint);
        }
        .footer-item i { font-size: 0.72rem; color: var(--red); }

        :focus-visible { outline: 2px solid var(--red); outline-offset: 3px; }

        @media (max-width: 460px) {
            .login-card { padding: 36px 24px 28px; }
            .brand-name { display: none; }
            .card-footer { gap: 12px; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>

    <div class="bg-glow"></div>
    <div class="bg-vignette"></div>

    <!-- Theme toggle -->
    <button class="theme-toggle" id="themeToggle" type="button" aria-label="تبديل الوضع الليلي/النهاري">
        <i class="fas fa-moon" id="themeIcon"></i>
        <span id="themeLabel">ليلي</span>
    </button>

    <!-- Brand -->
    <div class="brand">
        <span class="brand-name">SHASHETY</span>
        <span class="brand-logo">
            <img src="assets/22.png" alt="Shashety"
                 onerror="this.style.display='none';this.insertAdjacentHTML('afterend','<i class=\'fas fa-tv\'></i>');">
        </span>
    </div>

    <!-- Card -->
    <main class="login-card" role="main">
        <div class="emblem" aria-hidden="true">
            <div class="emblem-box">
                <img src="assets/22.png" alt=""
                     onerror="this.style.display='none';this.insertAdjacentHTML('afterend','<i class=\'fas fa-tv\'></i>');">
            </div>
        </div>

        <div class="card-header">
            <h1 class="card-title">لوحة التحكم</h1>
            <p class="card-subtitle">نظام إدارة وبث القنوات — IPTV</p>
        </div>

        <?php if ($error): ?>
        <div class="alert-error" role="alert" aria-live="assertive">
            <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>

        <?php if ($loginAttempts > 0 && $loginAttempts < $maxAttempts): ?>
        <div class="attempts" role="status" aria-live="polite">
            <div class="attempts-dots" aria-hidden="true">
                <?php for ($i = 0; $i < $maxAttempts; $i++): ?>
                    <div class="dot <?php echo $i < $loginAttempts ? 'used' : ''; ?>"></div>
                <?php endfor; ?>
            </div>
            <span><?php echo $maxAttempts - $loginAttempts; ?> محاولات متبقية</span>
        </div>
        <?php endif; ?>

        <form method="POST" id="loginForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

            <div class="field">
                <label class="field-label" for="username">اسم المستخدم</label>
                <div class="input-wrap">
                    <input
                        type="text" id="username" name="username" class="field-input"
                        placeholder="أدخل اسم المستخدم"
                        autocomplete="username" spellcheck="false" required autofocus
                        <?php echo ($loginAttempts >= $maxAttempts) ? 'disabled' : ''; ?>>
                    <i class="fas fa-user input-icon" aria-hidden="true"></i>
                </div>
            </div>

            <div class="field">
                <label class="field-label" for="password">كلمة المرور</label>
                <div class="input-wrap">
                    <input
                        type="password" id="password" name="password" class="field-input"
                        placeholder="أدخل كلمة المرور"
                        autocomplete="current-password" required
                        <?php echo ($loginAttempts >= $maxAttempts) ? 'disabled' : ''; ?>>
                    <i class="fas fa-lock input-icon" aria-hidden="true"></i>
                    <button type="button" class="toggle-pw" id="togglePw" aria-label="إظهار/إخفاء كلمة المرور" tabindex="-1">
                        <i class="fas fa-eye" id="eyeIcon" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn"
                <?php echo ($loginAttempts >= $maxAttempts) ? 'disabled' : ''; ?>>
                <span class="spinner" id="spinner" aria-hidden="true"></span>
                <i class="fas fa-right-to-bracket" id="btnIcon" aria-hidden="true"></i>
                <span id="btnLabel">دخول</span>
            </button>
        </form>

        <footer class="card-footer">
            <div class="footer-item"><i class="fas fa-shield-halved" aria-hidden="true"></i><span>اتصال آمن</span></div>
            <div class="footer-item"><i class="fas fa-lock" aria-hidden="true"></i><span>جلسة مشفّرة</span></div>
        </footer>
    </main>

    <script>
        /* ── Theme toggle (ليلي / نهاري) ── */
        const root        = document.documentElement;
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon   = document.getElementById('themeIcon');
        const themeLabel  = document.getElementById('themeLabel');

        function applyTheme(theme) {
            root.setAttribute('data-theme', theme);
            const dark = theme === 'dark';
            themeIcon.className  = dark ? 'fas fa-moon' : 'fas fa-sun';
            themeLabel.textContent = dark ? 'ليلي' : 'نهاري';
        }

        themeToggle.addEventListener('click', () => {
            const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            applyTheme(next);
        });

        /* ── Password toggle ── */
        const togglePw = document.getElementById('togglePw');
        const pwInput  = document.getElementById('password');
        const eyeIcon  = document.getElementById('eyeIcon');
        togglePw?.addEventListener('click', () => {
            const show = pwInput.type === 'password';
            pwInput.type = show ? 'text' : 'password';
            eyeIcon.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
        });

        /* ── Submit loading state ── */
        const form      = document.getElementById('loginForm');
        const submitBtn = document.getElementById('submitBtn');
        const spinner   = document.getElementById('spinner');
        const btnIcon   = document.getElementById('btnIcon');
        const btnLabel  = document.getElementById('btnLabel');

        form?.addEventListener('submit', (e) => {
            if (submitBtn.disabled) { e.preventDefault(); return; }
            const user = document.getElementById('username').value.trim();
            const pass = pwInput.value;
            if (!user || !pass) { e.preventDefault(); return; }
            submitBtn.disabled = true;
            spinner.style.display = 'block';
            btnIcon.style.display = 'none';
            btnLabel.textContent  = 'جارٍ التحقق...';
        });

        /* ── حماية F12 / أدوات المطور (ردع للمستخدم العادي فقط) ── */
        (function() {
            document.addEventListener('keydown', (e) => {
                if (e.key === 'F12' ||
                    (e.ctrlKey && e.shiftKey && ['I','J','C'].includes(e.key)) ||
                    (e.ctrlKey && e.key === 'U')) {
                    e.preventDefault();
                }
            });
            document.addEventListener('contextmenu', (e) => e.preventDefault());
        })();
    </script>
</body>
</html>

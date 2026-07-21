<?php
/**
 * ملف الإعداد الأولي - Shashety IPTV
 * استخدم هذا الملف لإنشاء حساب المدير أو إعادة تعيين كلمة المرور
 */

require_once 'config.php';

// معلومات المدير
$admin_username = 'admin';
$admin_password = 'Ali1992320'; // غيّر هذه الكلمة حسب رغبتك
$admin_email = 'admin@shashety.tv';

// تشفير كلمة المرور
$hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);

try {
    // التحقق من وجود المستخدم
    $check = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
    $check->execute([$admin_username]);
    
    if ($check->rowCount() > 0) {
        // تحديث كلمة المرور
        $stmt = $pdo->prepare("UPDATE admins SET password = ?, email = ? WHERE username = ?");
        $stmt->execute([$hashed_password, $admin_email, $admin_username]);
        $message = "✅ تم تحديث كلمة المرور بنجاح!";
    } else {
        // إنشاء مستخدم جديد
        $stmt = $pdo->prepare("INSERT INTO admins (username, password, email) VALUES (?, ?, ?)");
        $stmt->execute([$admin_username, $hashed_password, $admin_email]);
        $message = "✅ تم إنشاء حساب المدير بنجاح!";
    }
    
    $success = true;
    
} catch(PDOException $e) {
    $success = false;
    $message = "❌ خطأ: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعداد النظام - Shashety IPTV</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Cairo', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .setup-container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 600px;
            width: 100%;
        }
        
        h1 {
            color: #667eea;
            margin-bottom: 30px;
            text-align: center;
            font-size: 2rem;
        }
        
        .message {
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 1.1rem;
            text-align: center;
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            border: 2px solid #c3e6cb;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 2px solid #f5c6cb;
        }
        
        .credentials {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            margin: 20px 0;
        }
        
        .credentials h3 {
            color: #495057;
            margin-bottom: 15px;
        }
        
        .cred-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #dee2e6;
        }
        
        .cred-item:last-child {
            border-bottom: none;
        }
        
        .cred-label {
            font-weight: 600;
            color: #6c757d;
        }
        
        .cred-value {
            font-weight: 700;
            color: #212529;
            background: #fff;
            padding: 5px 15px;
            border-radius: 5px;
            font-family: monospace;
        }
        
        .warning {
            background: #fff3cd;
            border: 2px solid #ffc107;
            color: #856404;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }
        
        .warning strong {
            display: block;
            margin-bottom: 10px;
            font-size: 1.1rem;
        }
        
        .actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            flex: 1;
            padding: 15px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .hash-info {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            font-size: 0.9rem;
            color: #004085;
        }
        
        code {
            background: #f8f9fa;
            padding: 2px 8px;
            border-radius: 4px;
            font-family: monospace;
            color: #e83e8c;
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <h1>🎯 إعداد نظام Shashety IPTV</h1>
        
        <div class="message <?php echo $success ? 'success' : 'error'; ?>">
            <?php echo $message; ?>
        </div>
        
        <?php if ($success): ?>
            <div class="credentials">
                <h3>📋 بيانات تسجيل الدخول</h3>
                <div class="cred-item">
                    <span class="cred-label">اسم المستخدم:</span>
                    <span class="cred-value"><?php echo $admin_username; ?></span>
                </div>
                <div class="cred-item">
                    <span class="cred-label">كلمة المرور:</span>
                    <span class="cred-value"><?php echo $admin_password; ?></span>
                </div>
                <div class="cred-item">
                    <span class="cred-label">البريد الإلكتروني:</span>
                    <span class="cred-value"><?php echo $admin_email; ?></span>
                </div>
            </div>
            
            <div class="hash-info">
                <strong>🔐 معلومات التشفير:</strong>
                <p>تم تشفير كلمة المرور باستخدام <code>PASSWORD_DEFAULT</code> (bcrypt)</p>
                <p style="margin-top: 5px;">Hash: <code style="font-size: 0.75rem; word-break: break-all;"><?php echo $hashed_password; ?></code></p>
            </div>
            
            <div class="warning">
                <strong>⚠️ تحذير أمني مهم!</strong>
                <ul style="margin: 10px 0 0 20px; line-height: 1.8;">
                    <li>احذف هذا الملف <code>setup.php</code> فوراً بعد الاستخدام</li>
                    <li>غيّر كلمة المرور من لوحة التحكم</li>
                    <li>لا تشارك بيانات الدخول مع أحد</li>
                </ul>
            </div>
            
            <div class="actions">
                <a href="login.php" class="btn btn-primary">
                    🔐 الذهاب لتسجيل الدخول
                </a>
                <a href="admin.php" class="btn btn-primary">
                    🎛️ لوحة التحكم
                </a>
            </div>
            
            <div style="margin-top: 30px; text-align: center; color: #6c757d;">
                <p><strong>ملاحظة:</strong> يمكنك تعديل بيانات المدير من ملف <code>setup.php</code> ثم تشغيله مرة أخرى</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

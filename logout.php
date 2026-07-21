<?php
/**
 * صفحة تسجيل الخروج - Shashety IPTV
 */

require_once 'config.php';

// تسجيل النشاط
if (isset($_SESSION['admin_username'])) {
    logActivity('تسجيل خروج', "المستخدم: {$_SESSION['admin_username']}");
}

// تدمير الجلسة
session_unset();
session_destroy();

// إعادة التوجيه إلى صفحة تسجيل الدخول
redirect('login.php');

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

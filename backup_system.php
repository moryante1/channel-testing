<?php
/**
 * نظام النسخ الاحتياطي الشامل
 * Complete Backup System
 */

session_start();
require_once 'config.php';

if(!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$action = $_GET['action'] ?? '';

try {
    if($action === 'export_full') {
        // تصدير قاعدة البيانات كاملة
        $filename = 'iptv_full_backup_' . date('Y-m-d_H-i-s') . '.sql';
        
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        
        $output = "-- ════════════════════════════════════════════════════════════════\n";
        $output .= "-- IPTV System Full Backup\n";
        $output .= "-- Date: " . date('Y-m-d H:i:s') . "\n";
        $output .= "-- Database: " . DB_NAME . "\n";
        $output .= "-- ════════════════════════════════════════════════════════════════\n\n";
        
        $output .= "SET NAMES utf8mb4;\n";
        $output .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        
        // جلب جميع الجداول الموجودة فعلياً في القاعدة تفادياً لنقص جداول (مهم جداً للنسخة الحديثة)
        $tables = [];
        $queryTables = $pdo->query("SHOW TABLES");
        while($row = $queryTables->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        foreach($tables as $table) {
            $output .= "-- ────────────────────────────────────────────────────────────────\n";
            $output .= "-- Table: $table\n";
            $output .= "-- ────────────────────────────────────────────────────────────────\n\n";
            
            $output .= "DROP TABLE IF EXISTS `$table`;\n\n";
            
            // هيكل الجدول
            $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
            $row = $stmt->fetch();
            $output .= $row['Create Table'] . ";\n\n";
            
            // البيانات
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if(!empty($rows)) {
                // الحصول على أسماء الأعمدة
                $columns = array_keys($rows[0]);
                $columnsList = '`' . implode('`, `', $columns) . '`';
                
                $output .= "INSERT INTO `$table` ($columnsList) VALUES\n";
                
                $valuesList = [];
                foreach($rows as $row) {
                    $values = [];
                    foreach($row as $value) {
                        if($value === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = $pdo->quote($value);
                        }
                    }
                    $valuesList[] = '(' . implode(', ', $values) . ')';
                }
                
                $output .= implode(",\n", $valuesList) . ";\n\n";
            }
        }
        
        $output .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        $output .= "\n-- ════════════════════════════════════════════════════════════════\n";
        $output .= "-- Backup Completed Successfully\n";
        $output .= "-- ════════════════════════════════════════════════════════════════\n";
        
        echo $output;
        exit;
        
    } elseif($action === 'export_channels') {
        // تصدير القنوات والأقسام فقط
        $filename = 'iptv_channels_backup_' . date('Y-m-d_H-i-s') . '.sql';
        
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        
        $output = "-- ════════════════════════════════════════════════════════════════\n";
        $output .= "-- IPTV Channels Backup Only\n";
        $output .= "-- Date: " . date('Y-m-d H:i:s') . "\n";
        $output .= "-- ════════════════════════════════════════════════════════════════\n\n";
        
        $output .= "SET NAMES utf8mb4;\n";
        $output .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        
        // Categories
        $output .= "-- Categories\n";
        $output .= "TRUNCATE TABLE categories;\n\n";
        $stmt = $pdo->query("SELECT * FROM categories ORDER BY id");
        
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $output .= "INSERT INTO categories (id, name, slug, icon, description, is_active, display_order, created_at) VALUES (";
            $output .= $row['id'] . ", ";
            $output .= $pdo->quote($row['name']) . ", ";
            $output .= $pdo->quote($row['slug']) . ", ";
            $output .= $pdo->quote($row['icon']) . ", ";
            $output .= $pdo->quote($row['description']) . ", ";
            $output .= $row['is_active'] . ", ";
            $output .= $row['display_order'] . ", ";
            $output .= $pdo->quote($row['created_at']);
            $output .= ");\n";
        }
        
        $output .= "\n-- Channels\n";
        $output .= "TRUNCATE TABLE channels;\n\n";
        $stmt = $pdo->query("SELECT * FROM channels ORDER BY id");
        
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $output .= "INSERT INTO channels (id, category_id, name, slug, stream_url, logo_url, logo_icon, description, is_active, display_order, views_count, created_at) VALUES (";
            $output .= $row['id'] . ", ";
            $output .= $row['category_id'] . ", ";
            $output .= $pdo->quote($row['name']) . ", ";
            $output .= $pdo->quote($row['slug']) . ", ";
            $output .= $pdo->quote($row['stream_url']) . ", ";
            $output .= $pdo->quote($row['logo_url']) . ", ";
            $output .= $pdo->quote($row['logo_icon']) . ", ";
            $output .= $pdo->quote($row['description']) . ", ";
            $output .= $row['is_active'] . ", ";
            $output .= $row['display_order'] . ", ";
            $output .= $row['views_count'] . ", ";
            $output .= $pdo->quote($row['created_at']);
            $output .= ");\n";
        }
        
        $output .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";
        
        echo $output;
        exit;
        
    } elseif($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // استيراد من ملف SQL
        if(!isset($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('لم يتم رفع الملف بشكل صحيح');
        }
        
        $file = $_FILES['sql_file'];
        
        // التحقق من نوع الملف
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if($fileExt !== 'sql') {
            throw new Exception('الملف يجب أن يكون بصيغة SQL');
        }
        
        $sql = file_get_contents($file['tmp_name']);
        
        if(empty($sql)) {
            throw new Exception('الملف فارغ');
        }
        
        // تعطيل قيود المفاتيح الأجنبية أثناء الرفع حتى لا يحدث فشل بسبب الترتيب
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        
        // فصل الأوامر بطريقة أدق
        $delimiter = ';';
        $statements = [];
        $buffer = '';
        $lines = explode("\n", $sql);
        
        foreach($lines as $line) {
            $line = trim($line);
            if(empty($line) || substr($line, 0, 2) === '--' || substr($line, 0, 2) === '/*') {
                continue;
            }
            $buffer .= $line . "\n";
            // إذا كان السطر ينتهي بالفاصل (غالبا ; ) نقوم بحفظ الأمر
            if(preg_match("/$delimiter\s*$/", $line)) {
                $statements[] = trim($buffer);
                $buffer = '';
            }
        }
        if(!empty(trim($buffer))) {
            $statements[] = trim($buffer);
        }
        
        try {
            $executed = 0;
            // التنفيذ المباشر للأوامر بدون الـ Transaction لحل مشكلة الإيقاف التلقائي
            foreach($statements as $statement) {
                if(!empty($statement)) {
                    $pdo->exec($statement);
                    $executed++;
                }
            }
            
            // إعادة تفعيل المفاتيح الأجنبية
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
            
            $_SESSION['success'] = "✅ تم استعادة النظام وتصدير الملفات بنجاح! ($executed أمر تم تنفيذه)";
            
        } catch(PDOException $e) {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;"); // نضمن إعادة التفعيل حتى لو حدث خطأ
            throw new Exception('فشل أثناء تنفيذ قاعدة البيانات: ' . $e->getMessage());
        }
        
        header('Location: admin.php#backup');
        exit;
        
    } else {
        throw new Exception('إجراء غير متاح أو لم تقم برفع ملف بشكل صحيح!');
    }
    
} catch(Exception $e) {
    $_SESSION['error'] = '❌ ' . $e->getMessage();
    header('Location: admin.php#backup');
    exit;
}
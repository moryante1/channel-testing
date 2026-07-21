<?php
// === 1. معالجة طلبات الأجاكس في الخلفية (نستثنيها من الـ Referer حتى لا يتم حظرها وتعليق الصفحة) ===
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    // التأكد من أن دالة تنفيذ الأوامر مسموحة
    if (!function_exists('shell_exec')) {
        echo json_encode(['success' => false, 'message' => 'دالة shell_exec معطلة في السيرفر.']);
        exit;
    }

    $upload_dir = defined('VID_UPLOAD_DIR') ? VID_UPLOAD_DIR : '/var/www/html/uploads';

    // === جلب الأرقام والإحصائيات للوحة التحكم ===
    if ($_POST['ajax_action'] === 'get_storage') {
        @shell_exec("mkdir -p " . escapeshellarg($upload_dir));

        $stats_cmd = "df -h " . escapeshellarg($upload_dir) . " | awk 'NR==2 {print $2 \",\" $3 \",\" $4 \",\" $5}'";
        $stats_raw = @shell_exec($stats_cmd);
        $stats_parts = explode(',', trim($stats_raw));

        echo json_encode([
            'success' => true, 
            'df' => @shell_exec("df -h | grep -v 'loop\|tmpfs\|udev' 2>/dev/null") ?: 'لا توجد بيانات', 
            'lsblk' => @shell_exec("lsblk -o NAME,SIZE,FSTYPE,MOUNTPOINT | grep -v 'loop' 2>/dev/null") ?: 'لا توجد بيانات',
            'stats' => [
                'total' => $stats_parts[0] ?? '0G',
                'used'  => $stats_parts[1] ?? '0G',
                'free'  => $stats_parts[2] ?? '0G',
                'percent' => $stats_parts[3] ?? '0%'
            ]
        ]);
        exit;
    }

    // === جلب جميع الهاردات المتصلة بالسيرفر للوحة ===
    if ($_POST['ajax_action'] === 'get_drives') {
        $cmd = "df -T | awk '$2 ~ /^(ext[234]|xfs|btrfs)$/ {print $7}' | grep -vE '^/$|^/boot'";
        $mount_points = @shell_exec($cmd);
        $drives = array_filter(explode("\n", trim($mount_points)));
        echo json_encode(['success' => true, 'drives' => array_values($drives)]);
        exit;
    }

    // === التنفيذ المباشر للدمج (يقبل هاردات متعددة مقسومة بـ : ) ===
    if ($_POST['ajax_action'] === 'execute_merge') {
        $sources = $_POST['sources'] ?? ''; 
        $target = $_POST['target'] ?? '';   

        if (!preg_match('/^[a-zA-Z0-9_\-\/\.:]+$/', $sources) || 
            !preg_match('/^[a-zA-Z0-9_\-\/\.]+$/', $target)) {
            echo json_encode(['success' => false, 'message' => 'خطأ: مسارات غير صالحة.']);
            exit;
        }

        $mount_opts = "allow_other,use_ino,dropcacheonclose=true,category.create=epmfs,fsname=mergerfs";
        $fstab_line = "$sources $target fuse.mergerfs $mount_opts,nofail 0 0";

        $s_sources = escapeshellarg($sources);
        $s_target = escapeshellarg($target);
        $s_fstab_line = escapeshellarg($fstab_line);

        $sed_target = str_replace('/', '\/', $target);

        // أوامر لتفعيل allow_other والدمج ووضع السعة في FSTAB لتعمل حتى لو أعدت تشغيل السيرفر
        $cmds = [
            "sudo DEBIAN_FRONTEND=noninteractive apt-get install mergerfs -y",
            "sudo sh -c 'grep -q \"^user_allow_other\" /etc/fuse.conf || echo \"user_allow_other\" >> /etc/fuse.conf'",
            "sudo mkdir -p $s_target",
            "sudo umount -f $s_target 2>/dev/null || true", 
            "sudo mergerfs -o $mount_opts $s_sources $s_target", 
            "sudo sed -i '/" . $sed_target . " fuse\.mergerfs/d' /etc/fstab",
            "echo $s_fstab_line | sudo tee -a /etc/fstab > /dev/null"
        ];

        $output_log = "";
        foreach ($cmds as $cmd) {
            $output_log .= "> " . $cmd . "\n";
            $output_log .= shell_exec($cmd . " 2>&1") . "\n";
        }

        echo json_encode(['success' => true, 'log' => $output_log, 'message' => 'تم دمج وتوسعة مساحة الموقع بنجاح!']);
        exit;
    }

    // === فك الارتباط ===
    if ($_POST['ajax_action'] === 'execute_unmerge') {
        $target = $_POST['target'] ?? '';
        
        if (!preg_match('/^[a-zA-Z0-9_\-\/\.]+$/', $target)) {
            echo json_encode(['success' => false, 'message' => 'خطأ: مسار غير صالح.']); 
            exit; 
        }

        $s_target = escapeshellarg($target);
        $sed_target = str_replace('/', '\/', $target);

        $cmds = [
            "sudo umount -f $s_target 2>/dev/null || true", 
            "sudo sed -i '/" . $sed_target . " fuse\.mergerfs/d' /etc/fstab" 
        ];

        $output_log = "";
        foreach ($cmds as $cmd) {
            $output_log .= "> " . $cmd . "\n";
            $output_log .= shell_exec($cmd . " 2>&1") . "\n";
        }

        echo json_encode(['success' => true, 'log' => $output_log, 'message' => 'تم فصل الأقراص المدمجة بنجاح!']);
        exit;
    }
}

// === 2. نظام حماية فتح الصفحة المباشر (لتحويل من يحاول فتحها بدون admin.php) ===
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
$referer_path = parse_url($referer, PHP_URL_PATH);
if (!$referer_path || basename($referer_path) !== 'admin.php') {
    // تذكر، إذا أردت فتح الصفحة كمسؤول دون مشاكل أثناء برمجتك ضع تعليق // على الكودين السفليين.
    header('Location: admin.php');
    exit;
}

$upload_dir = defined('VID_UPLOAD_DIR') ? VID_UPLOAD_DIR : '/var/www/html/uploads';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>إدارة التخزين الذكية - اتحاد الهاردات (Auto-Pilot)</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root { --nf-bg: #141414; --nf-card: #181818; --nf-red: #E50914; --nf-red-hover: #b8070f; --nf-text: #ffffff; --nf-text-muted: #b3b3b3; --nf-input: #333333; --nf-border: rgba(255, 255, 255, 0.1); --radius-sm: 4px; --radius-md: 8px; --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1); }
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family: 'Tajawal', sans-serif; background-color: var(--nf-bg); color: var(--nf-text); padding: 40px 20px; min-height: 100vh; background-image: linear-gradient(to bottom, rgba(0,0,0,0.8) 0, rgba(20,20,20,1) 300px); }
    .container { max-width: 1200px; margin: 0 auto; }
    .header-title { font-size: 2.2rem; font-weight: 800; margin-bottom: 25px; display: flex; align-items: center; gap: 12px; text-shadow: 2px 2px 4px rgba(0,0,0,0.5); }
    .header-title i { color: var(--nf-red); }
    .capacity-dashboard { background: rgba(30,30,30,0.8); border: 1px solid var(--nf-border); border-radius: var(--radius-md); padding: 25px; margin-bottom: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); backdrop-filter: blur(10px); }
    .cap-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; font-weight: bold; font-size: 1.2rem; }
    .cap-boxes { display: flex; gap: 15px; margin-bottom: 20px; }
    .cap-box { flex: 1; background: rgba(0,0,0,0.4); border: 1px solid var(--nf-border); padding: 15px; border-radius: var(--radius-sm); text-align: center; transition: var(--transition); }
    .cap-box:hover { background: rgba(0,0,0,0.6); transform: translateY(-2px); border-color: var(--nf-text-muted); }
    .cap-box-val { font-size: 2rem; font-weight: 800; color: #fff; margin-bottom: 5px; direction: ltr; }
    .cap-box-lbl { font-size: 0.85rem; color: var(--nf-text-muted); text-transform: uppercase; letter-spacing: 1px; }
    .progress-wrap { width: 100%; background: #222; height: 12px; border-radius: 6px; overflow: hidden; position: relative; box-shadow: inset 0 1px 3px rgba(0,0,0,0.9); }
    .progress-bar { height: 100%; background: var(--nf-red); width: 0%; transition: width 1s cubic-bezier(0.25, 0.8, 0.25, 1); position: relative; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
    @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } .cap-boxes { flex-direction: column; } }
    .card { background: var(--nf-card); border-radius: var(--radius-md); box-shadow: 0 4px 15px rgba(0,0,0,0.4); display: flex; flex-direction: column; }
    .card-hd { padding: 20px; border-bottom: 1px solid var(--nf-border); font-weight: 700; font-size: 1.1rem; display: flex; justify-content: space-between; align-items: center; }
    .card-body { padding: 25px 20px; flex-grow: 1; }
    .term-box { background: #000; border: 1px solid #222; border-radius: var(--radius-sm); padding: 15px; font-family: monospace; font-size: 0.85rem; color: #00ff88; overflow-x: auto; white-space: pre-wrap; direction: ltr; text-align: left; margin-bottom: 20px; min-height: 100px; box-shadow: inset 0 0 10px rgba(0,0,0,0.8); }
    .term-title { font-size: 0.85rem; color: var(--nf-text-muted); font-weight: bold; margin-bottom: 8px; direction: ltr; text-align: left; }
    .fg { margin-bottom: 20px; }
    .fl { display: block; font-size: 0.9rem; color: var(--nf-text-muted); margin-bottom: 10px; font-weight: 500; }
    .fi { width: 100%; padding: 15px; background: var(--nf-input); border: 1px solid transparent; border-radius: var(--radius-sm); color: var(--nf-text); font-family: monospace; outline: none; direction: ltr; text-align: left; font-size: 1rem; }
    .fi:focus { background: #404040; border-color: #00D084; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; padding: 12px 24px; border-radius: var(--radius-sm); font-family: inherit; font-weight: 700; font-size: 1rem; border: none; cursor: pointer; transition: var(--transition); color: white; }
    .btn-primary { background: #00D084; color:#000; }
    .btn-primary:hover { background: #00b06b; }
    .btn-secondary { background: rgba(109, 109, 110, 0.7); color:#fff; }
    .btn-outline { background: transparent; border: 1px solid var(--nf-text-muted); color: var(--nf-text-muted); }
    .drives-container { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; background: #111; padding: 15px; border-radius: var(--radius-sm); border: 1px solid #333; max-height: 250px; overflow-y: auto; }
    .drive-item { display: flex; align-items: center; gap: 12px; cursor: pointer; padding: 12px 15px; border-radius: var(--radius-sm); background: #222; border: 1px solid #222; }
    .drive-item:has(input:checked) { border-color: #00D084; background: rgba(0, 208, 132, 0.1); }
    .drive-item input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; accent-color: #00D084; }
    .drive-item span { font-family: monospace; font-size: 0.95rem; color: var(--nf-text); flex-grow: 1; direction: ltr; text-align: left; }
    .modal { position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(5px); display: none; align-items: center; justify-content: center; z-index: 100; padding: 20px; opacity: 0; transition: opacity 0.3s ease; }
    .modal.show { display: flex; opacity: 1; }
    .modal-content { background: var(--nf-card); width: 100%; max-width: 650px; border-radius: var(--radius-md); box-shadow: 0 20px 50px rgba(0,0,0,0.8); }
    .modal-hd { padding: 20px; border-bottom: 1px solid var(--nf-border); display: flex; justify-content: space-between; align-items: center; font-weight: bold; font-size: 1.2rem; background: rgba(24, 24, 24, 0.95); }
    .modal-body { padding: 25px; max-height: 80vh; overflow-y: auto;}
    .close-btn { background: none; border: none; color: var(--nf-text-muted); cursor: pointer; font-size: 1.5rem; }
    button:disabled { opacity: 0.6; cursor: not-allowed !important; }
</style>
</head>
<body>

<div class="container">
    <a href="admin.php" class="btn btn-outline" style="margin-bottom: 20px;"><i class="fas fa-arrow-right"></i> العودة للوحة</a>

    <div class="header-title">
        <i class="fas fa-cubes"></i> التوحيد العضوي لهاردات التخزين (اتحاد شامل)
    </div>

    <!-- لوحة السعة المدمجة -->
    <div class="capacity-dashboard">
        <div class="cap-header">
            <span><i class="fas fa-chart-line" style="color:#00D084; margin-left:8px;"></i> مساحة الموقع الإجمالية (بعد الدمج)</span>
            <span id="uiPercent" style="color:var(--nf-text-muted); font-size:0.9rem;">يتم الفحص...</span>
        </div>
        <div class="cap-boxes">
            <div class="cap-box"><div class="cap-box-lbl">إجمالي المساحة (Total)</div><div class="cap-box-val" id="uiTotal" style="color:#00D084;">-</div></div>
            <div class="cap-box"><div class="cap-box-lbl">المتوفر للرفع (Free)</div><div class="cap-box-val" id="uiFree">-</div></div>
            <div class="cap-box"><div class="cap-box-lbl">المساحة المستهلكة (Used)</div><div class="cap-box-val" id="uiUsed" style="color:var(--nf-red);">-</div></div>
        </div>
        <div class="progress-wrap"><div class="progress-bar" id="uiProgressBar"></div></div>
    </div>

    <div class="grid">
        <!-- أداة الاتحاد -->
        <div class="card" style="border: 1px solid rgba(0,208,132,0.3);">
            <div class="card-hd"><span><i class="fas fa-hdd" style="color:#00D084; margin-left:8px;"></i> توحيد ودمج مساحة الهاردات (1 هارد إلى 16 وأكثر)</span></div>
            <div class="card-body">
                
                <div class="fg" style="background: rgba(255, 255, 255, 0.03); padding: 15px; border-radius: var(--radius-sm); border-right: 4px solid #4CC9F0;">
                    <label class="fl">المجلد الأساسي الذي به البيانات (قبل الدمج):</label>
                    <input type="text" id="mainSourceDir" class="fi" placeholder="/var/www/site_data" value="/var/www">
                    <p style="font-size:0.8rem; color:var(--nf-text-muted); margin-top:5px;">سيوحد هذا المجلد كقطعة أولى مع الأقراص الجديدة لتتضاعف السعة.</p>
                </div>

                <div class="fg">
                    <label class="fl">أضف الهاردات للسعة الإجمالية (أشر عليها ليتم اتحادها):</label>
                    <div class="drives-container" id="detectedDrives">
                        <span style="grid-column: 1 / -1; color:#bbb;"><i class="fas fa-spinner fa-spin"></i> جاري فحص جميع الهاردات بالسيرفر...</span>
                    </div>
                </div>

                <div class="fg">
                    <label class="fl">المسار الذي سيظهر في موقعك كأن المساحات قطعة واحدة:</label>
                    <input type="text" id="mergeTarget" class="fi" value="<?php echo htmlspecialchars($upload_dir); ?>">
                </div>

                <button class="btn btn-primary" id="btnMerge" style="width:100%;" onclick="executeAction('merge', this)">
                    <i class="fas fa-magic"></i> دمج ومضاعفة المساحة
                </button>
                <div style="display:flex; justify-content:center; align-items:center; gap:5px; margin-top:10px;">
                    <button class="btn btn-outline" style="flex:1; background:rgba(229, 9, 20, 0.2); border-color:transparent; color:#fff;" onclick="executeAction('unmerge', this)">
                        <i class="fas fa-unlink"></i> إيقاف الدمج (فصل)
                    </button>
                </div>

            </div>
        </div>

        <!-- المراقب التقني -->
        <div class="card">
            <div class="card-hd"><span><i class="fas fa-terminal" style="color:var(--nf-text-muted); margin-left:8px;"></i> Live Monitor (Root)</span></div>
            <div class="card-body">
                <div class="term-title">> lsblk (البيانات الخام للأقراص)</div>
                <div class="term-box" id="term-lsblk">CONNECTING...</div>
                <div class="term-title" style="margin-top:15px;">> df -h (تفصيل الاستهلاك الحالي)</div>
                <div class="term-box" id="term-df" style="color: #4CC9F0;">ANALYZING...</div>
            </div>
        </div>
    </div>
</div>

<!-- Modal التقرير -->
<div class="modal" id="cmdModal">
    <div class="modal-content">
        <div class="modal-hd">
            <span>تقرير العمليات الخفية</span>
            <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div id="statusMessage" style="background: rgba(0, 208, 132, 0.1); border-right: 3px solid #00D084; padding: 15px; border-radius: var(--radius-sm); margin-bottom: 20px; display:none;">
                <h4 style="color:#00D084; font-size:1.1rem; margin-bottom:5px;" id="statusMsgText">نجاح!</h4>
            </div>
            <textarea id="liveTerminal" class="fi" style="background:#000; color:#00D084; resize:none; height:180px; font-size:0.85rem;" readonly>بدء الإرسال...</textarea>
            <button class="btn btn-secondary" style="width:100%; margin-top:15px;" onclick="closeModal();">إغلاق التقرير (تحديث المساحة)</button>
        </div>
    </div>
</div>

<script>
    function loadServerData() {
        const termDf = document.getElementById('term-df');
        const termLsblk = document.getElementById('term-lsblk');
        
        let fd = new FormData(); fd.append('ajax_action', 'get_storage');
        fetch(location.href, { method: 'POST', body: fd })
        .then(res => res.text()) // Use text() to prevent JSON crash if errors output
        .then(txt => {
            try {
                let data = JSON.parse(txt);
                if(data.success) {
                    termLsblk.textContent = data.lsblk; 
                    termDf.textContent = data.df; 
                    if(data.stats) {
                        document.getElementById('uiTotal').textContent = data.stats.total;
                        document.getElementById('uiFree').textContent = data.stats.free;
                        document.getElementById('uiUsed').textContent = data.stats.used;
                        document.getElementById('uiPercent').textContent = 'الاستهلاك: ' + data.stats.percent;
                        const pNum = data.stats.percent.replace('%', '');
                        document.getElementById('uiProgressBar').style.width = pNum + '%';
                        document.getElementById('uiProgressBar').style.backgroundColor = pNum > 90 ? 'var(--nf-red)' : '#00D084';
                    }
                }
            } catch(e) {
                termDf.textContent = "Data loading interrupted by an unexpected PHP response.\nResponse:\n" + txt;
            }
        });

        let fdDrv = new FormData(); fdDrv.append('ajax_action', 'get_drives');
        fetch(location.href, { method: 'POST', body: fdDrv }).then(r=>r.json()).then(data => {
            let container = document.getElementById('detectedDrives');
            if(data.drives && data.drives.length > 0) {
                let htmlStr = '';
                data.drives.forEach((drv, i) => {
                    htmlStr += `<label class="drive-item" for="drive_${i}">
                        <input type="checkbox" id="drive_${i}" value="${drv.trim()}" class="merger-disk-chk">
                        <span>${drv.trim()}</span>
                    </label>`;
                });
                container.innerHTML = htmlStr;
            } else {
                container.innerHTML = `<span style="grid-column: 1 / -1; text-align:center; padding:10px; color:#bbb;">لم يتم العثور على أقراص ثانوية إضافية.</span>`;
            }
        });
    }

    function executeAction(type, btnElement) {
        let fd = new FormData();
        const termBox = document.getElementById('liveTerminal');
        const statusBox = document.getElementById('statusMessage');
        const modal = document.getElementById('cmdModal');
        
        statusBox.style.display = 'none';
        termBox.value = 'الرجاء الانتظار...\nتنفذ الأوامر الآن בצلاحيات Root 🔒';
        btnElement.disabled = true;

        modal.style.display = 'flex';
        setTimeout(() => modal.classList.add('show'), 10);

        if (type === 'merge') {
            // سحر دمج الكل
            const mainDir = document.getElementById('mainSourceDir').value.trim();
            const chkBoxes = document.querySelectorAll('.merger-disk-chk:checked');
            let driveList = [mainDir]; 
            
            chkBoxes.forEach(el => {
                if(el.value.trim() !== "") driveList.push(el.value);
            });
            
            let sources = driveList.filter(Boolean).join(':'); // يقوم بلصق الجميع كمسار متحد مفصول بنقطتين
            let target = document.getElementById('mergeTarget').value.trim();
            
            if (!sources || !target) {
                termBox.value = '❌ المسار المستهدف أو المجلدات الأساسية غير صالحة.';
                btnElement.disabled = false;
                return;
            }

            termBox.value += "\nنظام الاتحاد سيجمع السعات التالية:\n" + sources.split(':').join("\n+ ") + "\n\nو يوجهها جميعاً إلى:\n=> " + target;
            
            fd.append('ajax_action', 'execute_merge');
            fd.append('sources', sources);
            fd.append('target', target);
        } else if(type === 'unmerge') {
            let target = document.getElementById('mergeTarget').value.trim();
            if(!target){ btnElement.disabled = false; modal.style.display = 'none'; alert("اكتب مسار الموقع للإلغاء"); return;}
            fd.append('ajax_action', 'execute_unmerge');
            fd.append('target', target);
        }

        fetch(location.href, { method: 'POST', body: fd })
        .then(res => res.text()) // Using text then parsing to catch errors
        .then(txt => {
            btnElement.disabled = false;
            try {
                let data = JSON.parse(txt);
                statusBox.style.display = 'block';
                if(data.success){
                    document.getElementById('statusMsgText').innerText = 'تمت العملية بامتياز ✔️';
                    statusBox.style.background = 'rgba(0, 208, 132, 0.1)';
                    statusBox.style.borderRightColor = '#00D084';
                    termBox.value = data.log || "انتهى بسلام.";
                } else {
                    document.getElementById('statusMsgText').innerText = 'خطأ أثناء المعالجة ⚠️';
                    statusBox.style.background = 'rgba(229, 9, 20, 0.2)';
                    statusBox.style.borderRightColor = 'var(--nf-red)';
                    termBox.value = data.message;
                }
            }catch(e) {
                termBox.value = "البيانات القادمة من السيرفر ليست سليمة (خطأ PHP مخفي):\n" + txt;
            }
        });
    }

    function closeModal() {
        const m = document.getElementById('cmdModal'); m.classList.remove('show');
        setTimeout(() => { m.style.display = 'none'; loadServerData(); }, 250);
    }
    
    window.onload = loadServerData;
</script>
</body>
</html>
<section id="vmanage" class="sec">
  <div class="shdr"><h1 class="stitle">إدارة <span>الفيديوهات</span></h1><button class="btn btn-p" onclick="S('vupload')"><i class="fas fa-plus"></i>رفع جديد</button></div>
  <div style="display:flex;gap:9px;align-items:center;margin-bottom:18px;flex-wrap:wrap">
    <div class="tsrch" style="max-width:250px;flex:1"><i class="fas fa-search"></i><input type="text" id="vmSearch" placeholder="بحث…" oninput="vmFilter()"></div>
    <select class="fs" id="vmType" style="width:150px" onchange="vmFilter()">
        <option value="all">كل المجلدات الفعالة</option>
        <option value="uploaded">الرفع العام (المُعلقة)</option>
        <option value="merged">المدمجة والمعدلة</option>
        <option value="series">شاشتي (المسلسلات)</option>
    </select>
    <button class="btn btn-g" onclick="vmLoad()"><i class="fas fa-sync-alt"></i>تحديث</button>
    <span id="vmCnt" style="font-size:.78rem;color:var(--t3);margin-right:auto"></span>
  </div>
  <div id="vmLoad" style="text-align:center;padding:50px;color:var(--t3)"><div class="pspin" style="margin:0 auto 12px"></div><p>جارٍ التحميل…</p></div>
  <div id="vmEmpty" style="display:none" class="empty"><i class="fas fa-film"></i><p>لا توجد فيديوهات</p><button class="btn btn-p" style="margin-top:14px" onclick="S('vupload')"><i class="fas fa-plus"></i>ارفع الآن</button></div>
  <div id="vmGrid" class="vmgrid" style="display:none"></div>
  <input type="file" id="vmSubUp" accept=".srt,.ass,.ssa,.vtt" style="display:none" onchange="vmHandleSubUp(this)">
</section>

<!-- SETTINGS -->

<section id="series" class="sec">
  <div class="shdr"><h1 class="stitle">إدارة <span>شاشتي</span></h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-g" id="srBackBtn" style="display:none" onclick="srBack()"><i class="fas fa-arrow-right"></i>رجوع</button>
      <button class="btn btn-v" id="srBulkBtn" style="display:none" onclick="OM('bulkM')"><i class="fas fa-folder-open"></i>رفع مجلد كامل</button>
      <button class="btn btn-p" id="srAddBtn" onclick="OM('addSeriesM')"><i class="fas fa-plus"></i>مسلسل / فيلم جديد</button>
    </div>
  </div>
  <div id="srBreadcrumb" style="display:none;align-items:center;gap:8px;margin-bottom:18px;font-size:.855rem;color:var(--t3)"><span style="cursor:pointer;color:#4CC9F0" onclick="srBack()">شاشتي</span><i class="fas fa-chevron-left" style="font-size:.62rem"></i><strong id="srBCName" style="color:var(--t1)"></strong><span class="bdg bp" id="srBCCount" style="margin-right:6px"></span></div>
  <div id="srFilterBar" style="display:flex;gap:8px;align-items:center;margin-bottom:18px;flex-wrap:wrap">
    <select class="fs" id="srCatFilter" style="max-width:200px" onchange="loadSeries()"><option value="">كل الأقسام</option><?php foreach($categories as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option><?php endforeach; ?></select>
    <div class="tsrch" style="max-width:230px;flex:1"><i class="fas fa-search"></i><input type="text" id="srSearch" placeholder="بحث عن فيلم/مسلسل..." oninput="srFilter()"></div>
    <span id="srCount" style="font-size:.78rem;color:var(--t3);margin-right:auto"></span>
  </div>
  <div id="srLoading" style="display:none;text-align:center;padding:50px;color:var(--t3)"><div class="pspin" style="margin:0 auto 12px"></div><p>جارٍ التحميل…</p></div>
  <div id="srGrid" class="srgrid"></div>
  <div id="srEmpty" style="display:none" class="empty"><i class="fas fa-film"></i><p>لا توجد بيانات بعد</p></div>
  <div id="epsPanel" style="display:none">
    <div class="tw">
      <div class="tt" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; background:var(--s1)">
          <span style="font-weight:900;font-size:1.05rem;"><i class="fas fa-list-ul" style="color:var(--red); margin-left:8px"></i> إدارة عناصر ومقاطع العمل</span>
          
          <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
              <span style="font-size:0.72rem; color:var(--gold); border:1px solid rgba(245, 166, 35, 0.2); background:rgba(245, 166, 35, 0.1); padding:4px 8px; border-radius:4px;"><i class="fas fa-mouse-pointer"></i> ادعمنا بـ السحب والافلات للترتيب (يُحفظ تلقائياً)</span>
              
              <select class="fs" id="epSortAZ" onchange="_sortAndSaveEps()" style="width:auto; padding:6px; font-size:0.75rem; font-weight:bold; cursor:pointer">
                  <option value="def">📅 ترتيب: حسب الإضافة للخادم</option>
                  <option value="az">✨ الفرز: تصاعدي الشامل (A-Z)</option>
                  <option value="za">✨ الفرز: تنازلي الشامل (Z-A)</option>
                  <option value="manual" disabled style="background:#000;color:var(--t3)">🤚 مُهندس يدويا (إفلات وماوس)</option>
              </select>
              
              <button class="btn btn-p bsm" id="delBulkBtn" style="display:none; background:#ff4d57; color:#fff" onclick="deleteCheckedEps()">
                <i class="fas fa-trash-alt"></i> نسف التحديد
              </button>
                       <button class="btn btn-p bsm" id="convertMp4Btn" style="display:none; background:rgba(179,107,255,1); color:#fff; margin-right:8px; box-shadow:0 4px 14px rgba(179,107,255,.3);" onclick="convertCheckedEpsToMp4()">
   <i class="fas fa-magic"></i> التحويل السريع لـ MP4
</button>
              
          </div>
      </div>
      <table><thead><tr><th style="width:30px;"><input type="checkbox" id="chkEpsMaster" onclick="toggleChkEps(this)" style="cursor:pointer; width:16px;height:16px; accent-color:var(--red);"></th><th>العرض</th><th>اسم العمل</th><th>امتداد الاستضافة</th><th>تشفير لغة</th><th>المدة</th><th>مـزايـــا</th></tr></thead><tbody id="epsTbody"></tbody></table>
      <div id="epsEmpty" style="display:none" class="empty"><i class="fas fa-film"></i><p>لا توجد حلقات/فيديوهات</p></div>
    </div>
  </div>
</section>

<!-- VIDEO UPLOAD -->

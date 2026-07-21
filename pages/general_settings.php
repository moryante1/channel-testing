<section id="general-settings" class="sec">
  <div class="shdr">
    <h1 class="stitle"><i class="fas fa-sliders-h" style="color:#F5A623"></i> الإعدادات <span>العامة</span></h1>
    <button class="btn btn-g bsm" onclick="loadGeneralSettings()"><i class="fas fa-sync-alt"></i> استرجاع المحفوظ</button>
  </div>
  <p style="color:var(--t3);font-size:.86rem;margin-bottom:22px;line-height:1.7">
    تحكّم كامل في كل الإعدادات الحساسة والمهمة للموقع (index.php) من مكان واحد.
    التغييرات تُحفظ في قاعدة البيانات وتُطبَّق فوراً — <b>لا حاجة لتعديل أي ملف يدوياً</b>.
  </p>
  <div id="gsAlert"></div>

  <!-- ══════════ مجموعة 1: التحكم الحرج (وضع الصيانة + قفل الموقع) ══════════ -->
  <div class="card" style="margin-bottom:22px;border:1px solid rgba(255,77,87,.35);box-shadow:0 0 18px rgba(255,77,87,.08)">
    <div class="chdr" style="background:rgba(255,77,87,.05);border-bottom:1px solid rgba(255,77,87,.2)">
      <span class="ctitle"><i class="fas fa-triangle-exclamation" style="color:#ff4d57;margin-left:8px"></i>تحكم حرج — استخدم بحذر</span>
    </div>
    <div class="cbody" style="display:flex;flex-direction:column;gap:16px">

      <!-- وضع الصيانة: يُغلق الموقع أمام الزوار ويعرض رسالة (المدير يبقى قادراً على الدخول) -->
      <label class="fc-card" for="gs_maintenance_mode" style="cursor:pointer">
        <div class="fc-ic" style="background:rgba(255,77,87,.12);color:#ff4d57"><i class="fas fa-hard-hat"></i></div>
        <div class="fc-info"><b>وضع الصيانة</b><small>إغلاق الموقع أمام الزوار وعرض صفحة صيانة. المدير يبقى قادراً على التصفح.</small></div>
        <span class="fc-switch"><input type="checkbox" id="gs_maintenance_mode" data-key="maintenance_mode" data-type="bool"><span class="fc-slider"></span></span>
      </label>
      <!-- نص رسالة الصيانة الظاهرة للزوار أثناء تفعيل وضع الصيانة -->
      <div class="fg" style="margin:0">
        <label class="fl">رسالة صفحة الصيانة</label>
        <input type="text" class="fi" id="gs_maintenance_message" data-key="maintenance_message" data-type="text" placeholder="الموقع تحت الصيانة حالياً، نعود قريباً بإذن الله">
      </div>

      <hr style="border:none;border-top:1px solid var(--br);margin:4px 0">

      <!-- قفل الموقع بكلمة مرور: يطلب كلمة سر قبل الدخول للموقع كاملاً (بوابة حماية) -->
      <label class="fc-card" for="gs_gate_enabled" style="cursor:pointer">
        <div class="fc-ic" style="background:rgba(179,107,255,.12);color:#B36BFF"><i class="fas fa-lock"></i></div>
        <div class="fc-info"><b>قفل الموقع بكلمة مرور</b><small>حماية الموقع بالكامل ببوابة كلمة سر قبل الدخول (للمواقع الخاصة).</small></div>
        <span class="fc-switch"><input type="checkbox" id="gs_gate_enabled" data-key="gate_enabled" data-type="bool"><span class="fc-slider"></span></span>
      </label>
      <!-- كلمة مرور بوابة الموقع (تُطلب من الزائر عند تفعيل القفل) -->
      <div class="fg" style="margin:0">
        <label class="fl">كلمة مرور الدخول للموقع</label>
        <input type="text" class="fi" id="gs_gate_password" data-key="gate_password" data-type="text" placeholder="اكتب كلمة سر الدخول للموقع" autocomplete="off">
      </div>
    </div>
  </div>

  <!-- ══════════ مجموعة 2: هوية الموقع (الاسم / الوصف / الشعار / اللون) ══════════ -->
  <div class="card" style="margin-bottom:22px">
    <div class="chdr"><span class="ctitle"><i class="fas fa-id-card" style="color:#58a6ff;margin-left:8px"></i>هوية الموقع</span></div>
    <div class="cbody" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px">
      <!-- اسم الموقع: يظهر في عنوان التبويب و og:title ومشاركات السوشيال -->
      <div class="fg" style="margin:0"><label class="fl">اسم الموقع</label>
        <input type="text" class="fi" id="gs_site_name" data-key="site_name" data-type="text" placeholder="Shashety"></div>
      <!-- وصف الموقع: meta description لمحركات البحث (SEO) -->
      <div class="fg" style="margin:0"><label class="fl">وصف الموقع (SEO)</label>
        <input type="text" class="fi" id="gs_site_description" data-key="site_description" data-type="text" placeholder="نظام IPTV احترافي"></div>
      <!-- رابط شعار الموقع -->
      <div class="fg" style="margin:0"><label class="fl">رابط الشعار (Logo URL)</label>
        <input type="text" class="fi" id="gs_site_logo" data-key="site_logo" data-type="text" placeholder="https://example.com/logo.png"></div>
      <!-- اللون الأساسي للثيم (accent) -->
      <div class="fg" style="margin:0"><label class="fl">اللون الأساسي للثيم</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="color" id="gs_theme_color_pick" style="width:48px;height:42px;border:1px solid var(--br);border-radius:8px;background:transparent;cursor:pointer" oninput="document.getElementById('gs_theme_color').value=this.value" value="#e50914">
          <input type="text" class="fi" id="gs_theme_color" data-key="theme_color" data-type="text" placeholder="#e50914" style="flex:1" oninput="try{document.getElementById('gs_theme_color_pick').value=this.value}catch(e){}">
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════ مجموعة 3: نصوص الواجهة (الترحيب / الفوتر) ══════════ -->
  <div class="card" style="margin-bottom:22px">
    <div class="chdr"><span class="ctitle"><i class="fas fa-heading" style="color:#00D084;margin-left:8px"></i>نصوص الواجهة</span></div>
    <div class="cbody" style="display:flex;flex-direction:column;gap:16px">
      <!-- عنوان الترحيب الرئيسي في الصفحة الأولى -->
      <div class="fg" style="margin:0"><label class="fl">عنوان الترحيب</label>
        <input type="text" class="fi" id="gs_welcome_title" data-key="welcome_title" data-type="text" placeholder="مرحباً بك في عالم البث المباشر"></div>
      <!-- العنوان الفرعي للترحيب -->
      <div class="fg" style="margin:0"><label class="fl">العنوان الفرعي للترحيب</label>
        <input type="text" class="fi" id="gs_welcome_subtitle" data-key="welcome_subtitle" data-type="text" placeholder="شاهد آلاف القنوات من جميع أنحاء العالم"></div>
      <!-- نص حقوق الفوتر أسفل الموقع -->
      <div class="fg" style="margin:0"><label class="fl">نص الفوتر (الحقوق)</label>
        <input type="text" class="fi" id="gs_footer_text" data-key="footer_text" data-type="text" placeholder="جميع الحقوق محفوظة © 2026 Shashety"></div>
    </div>
  </div>

  <!-- ══════════ مجموعة 4: روابط التواصل (واتساب / فيسبوك / بريد) ══════════ -->
  <div class="card" style="margin-bottom:22px">
    <div class="chdr"><span class="ctitle"><i class="fas fa-share-nodes" style="color:#25D366;margin-left:8px"></i>روابط التواصل</span></div>
    <div class="cbody" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px">
      <!-- رقم واتساب التواصل الظاهر في الموقع -->
      <div class="fg" style="margin:0"><label class="fl"><i class="fab fa-whatsapp" style="color:#25D366"></i> رقم واتساب</label>
        <input type="text" class="fi" id="gs_contact_whatsapp" data-key="contact_whatsapp" data-type="text" placeholder="9647512328848" dir="ltr"></div>
      <!-- رابط صفحة الفيسبوك -->
      <div class="fg" style="margin:0"><label class="fl"><i class="fab fa-facebook" style="color:#1877F2"></i> رابط فيسبوك</label>
        <input type="text" class="fi" id="gs_contact_facebook" data-key="contact_facebook" data-type="text" placeholder="facebook.com/yourpage" dir="ltr"></div>
      <!-- بريد التواصل الإلكتروني -->
      <div class="fg" style="margin:0"><label class="fl"><i class="fas fa-envelope" style="color:#EA4335"></i> بريد التواصل</label>
        <input type="text" class="fi" id="gs_contact_email" data-key="contact_email" data-type="text" placeholder="info@example.com" dir="ltr"></div>
    </div>
  </div>

  <!-- ══════════ مجموعة 5: الشريط الإعلاني العلوي ══════════ -->
  <div class="card" style="margin-bottom:22px">
    <div class="chdr"><span class="ctitle"><i class="fas fa-bullhorn" style="color:#F5A623;margin-left:8px"></i>الشريط الإعلاني</span></div>
    <div class="cbody" style="display:flex;flex-direction:column;gap:16px">
      <!-- تفعيل ظهور الشريط الإعلاني أعلى الموقع -->
      <label class="fc-card" for="gs_announcement_enabled" style="cursor:pointer">
        <div class="fc-ic" style="background:rgba(245,166,35,.12);color:#F5A623"><i class="fas fa-bullhorn"></i></div>
        <div class="fc-info"><b>إظهار الشريط الإعلاني</b><small>شريط نصي يظهر أعلى الصفحة الرئيسية لكل الزوار.</small></div>
        <span class="fc-switch"><input type="checkbox" id="gs_announcement_enabled" data-key="announcement_enabled" data-type="bool"><span class="fc-slider"></span></span>
      </label>
      <!-- نص الإعلان الظاهر في الشريط -->
      <div class="fg" style="margin:0"><label class="fl">نص الإعلان</label>
        <input type="text" class="fi" id="gs_announcement_text" data-key="announcement_text" data-type="text" placeholder="مثال: تم إضافة قنوات جديدة! تصفّح الآن"></div>
      <!-- رابط اختياري عند النقر على الشريط -->
      <div class="fg" style="margin:0"><label class="fl">رابط الإعلان (اختياري)</label>
        <input type="text" class="fi" id="gs_announcement_link" data-key="announcement_link" data-type="text" placeholder="https://... اتركه فارغاً لبدون رابط" dir="ltr"></div>
    </div>
  </div>

  <!-- ══════════ مجموعة 6: الأمان والحماية ══════════ -->
  <div class="card" style="margin-bottom:22px">
    <div class="chdr"><span class="ctitle"><i class="fas fa-shield-halved" style="color:#4CC9F0;margin-left:8px"></i>الأمان والحماية</span></div>
    <div class="cbody" style="display:flex;flex-direction:column;gap:14px">
      <!-- إجبار HTTPS: يعيد توجيه أي زيارة http إلى https تلقائياً -->
      <label class="fc-card" for="gs_force_https" style="cursor:pointer">
        <div class="fc-ic" style="background:rgba(0,208,132,.12);color:#00D084"><i class="fas fa-lock"></i></div>
        <div class="fc-info"><b>إجبار HTTPS</b><small>إعادة توجيه كل الزيارات إلى النسخة الآمنة (https) تلقائياً.</small></div>
        <span class="fc-switch"><input type="checkbox" id="gs_force_https" data-key="force_https" data-type="bool"><span class="fc-slider"></span></span>
      </label>
      <!-- منع أدوات المطور: تعطيل النقر الأيمن و F12 على الواجهة -->
      <label class="fc-card" for="gs_block_devtools" style="cursor:pointer">
        <div class="fc-ic" style="background:rgba(255,159,28,.12);color:#ff9f1c"><i class="fas fa-user-secret"></i></div>
        <div class="fc-info"><b>منع أدوات المطور</b><small>تعطيل النقر الأيمن و F12 لتصعيب فحص الروابط (حماية سطحية).</small></div>
        <span class="fc-switch"><input type="checkbox" id="gs_block_devtools" data-key="block_devtools" data-type="bool"><span class="fc-slider"></span></span>
      </label>
      <!-- منع التحميل: إخفاء زر تنزيل الفيديو عالمياً -->
      <label class="fc-card" for="gs_disable_download" style="cursor:pointer">
        <div class="fc-ic" style="background:rgba(229,9,20,.12);color:var(--red)"><i class="fas fa-download"></i></div>
        <div class="fc-info"><b>منع تحميل الفيديوهات</b><small>إخفاء زر التنزيل من المشغّل لكل الزوار.</small></div>
        <span class="fc-switch"><input type="checkbox" id="gs_disable_download" data-key="disable_download" data-type="bool"><span class="fc-slider"></span></span>
      </label>
    </div>
  </div>

  <!-- ══════════ مجموعة 7: أكواد مخصصة (تحليلات / بكسل / سكربتات) — حساس جداً ══════════ -->
  <div class="card" style="margin-bottom:22px;border:1px solid rgba(245,166,35,.3)">
    <div class="chdr" style="background:rgba(245,166,35,.05);border-bottom:1px solid rgba(245,166,35,.2)">
      <span class="ctitle"><i class="fas fa-code" style="color:#F5A623;margin-left:8px"></i>أكواد مخصصة (متقدم — حساس)</span>
    </div>
    <div class="cbody" style="display:flex;flex-direction:column;gap:16px">
      <p style="color:var(--t3);font-size:.8rem;line-height:1.6;margin:0">
        <i class="fas fa-circle-info" style="color:#F5A623"></i>
        يُحقن هذا الكود مباشرة في صفحة الموقع. استخدمه لأكواد Google Analytics أو Facebook Pixel أو أي سكربت. <b>لا تلصق كوداً من مصدر غير موثوق.</b>
      </p>
      <!-- كود يُحقن داخل <head>: مثالي لأكواد التتبع/التحليلات/الميتا -->
      <div class="fg" style="margin:0"><label class="fl">كود داخل &lt;head&gt; (تحليلات / بكسل / meta)</label>
        <textarea class="fi" id="gs_custom_head_code" data-key="custom_head_code" data-type="text" rows="4" style="font-family:'Courier New',monospace;direction:ltr;text-align:left" placeholder="&lt;!-- Google Analytics / Facebook Pixel --&gt;"></textarea></div>
      <!-- كود يُحقن قبل </body>: مثالي لسكربتات الشات/الودجت -->
      <div class="fg" style="margin:0"><label class="fl">كود قبل &lt;/body&gt; (شات / ودجت / سكربت)</label>
        <textarea class="fi" id="gs_custom_body_code" data-key="custom_body_code" data-type="text" rows="4" style="font-family:'Courier New',monospace;direction:ltr;text-align:left" placeholder="&lt;!-- Live Chat / Custom Script --&gt;"></textarea></div>
    </div>
  </div>


  <!-- ═══════════════════════════════════════════════════════════════════
       المجموعات المتقدمة — مدموجة داخل الإعدادات العامة (بطاقات قابلة للطي)
       كل بطاقة لها زر حفظ خاص بها (نفس آلية الحفظ لكل مجموعة على حدة)
       ═══════════════════════════════════════════════════════════════════ -->
  <div style="margin-top:30px;margin-bottom:14px;display:flex;align-items:center;gap:10px">
    <div style="flex:1;height:1px;background:var(--br)"></div>
    <span style="color:var(--t3);font-size:.8rem;font-weight:700;letter-spacing:1px"><i class="fas fa-layer-group"></i> الإعدادات المتقدمة</span>
    <div style="flex:1;height:1px;background:var(--br)"></div>
  </div>
  <p style="color:var(--t3);font-size:.82rem;margin-bottom:18px;line-height:1.7">
    كل مجموعة أدناه قابلة للطي، ولها <b>زر حفظ خاص</b> — عدّل ما تريد في أي مجموعة ثم احفظها وحدها.
  </p>


  <!-- مجموعة: إعدادات البث الخادمية -->
  <div class="gs-acc" data-group="streaming_server" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#F5A62322;color:#F5A623;flex-shrink:0"><i class="fas fa-server"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات البث الخادمية</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      <p style="color:var(--t3);font-size:.82rem;margin:0 0 14px;line-height:1.7">قيم تُخزَّن في قاعدة البيانات جاهزة لربطها بخادم البث (FFmpeg/Transcoder). لا تؤثر ما لم يقرأها خادم البث لديك.</p>
      <div id="ga_streaming_server"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- srv_hls_segment_duration — الأصلي: 6 -->
    <div class="fg" style="margin:0"><label class="fl">مدة جزء HLS (ثانية) — HLS Segment Duration <span style="color:var(--t3);font-weight:400">(الأصلي: 6)</span></label>
      <input type="number" class="fi" id="f_srv_hls_segment_duration" data-key="srv_hls_segment_duration" data-type="text" placeholder="6"></div>
    <!-- srv_playlist_length — الأصلي: 5 -->
    <div class="fg" style="margin:0"><label class="fl">طول قائمة التشغيل — Playlist Length <span style="color:var(--t3);font-weight:400">(الأصلي: 5)</span></label>
      <input type="number" class="fi" id="f_srv_playlist_length" data-key="srv_playlist_length" data-type="text" placeholder="5"></div>
    <!-- srv_llhls_enable — الأصلي: 0 -->
    <label class="fc-card" for="f_srv_llhls_enable" style="cursor:pointer">
      <div class="fc-info"><b>تفعيل LL-HLS</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_srv_llhls_enable" data-key="srv_llhls_enable" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- srv_ffmpeg_params — الأصلي: فارغ -->
    <div class="fg" style="margin:0"><label class="fl">باراميترات FFmpeg إضافية</label>
      <textarea class="fi" id="f_srv_ffmpeg_params" data-key="srv_ffmpeg_params" data-type="text" rows="3" style="font-family:'Courier New',monospace;direction:ltr;text-align:left" placeholder=""></textarea></div>
    <!-- srv_hwaccel — الأصلي: none -->
    <div class="fg" style="margin:0"><label class="fl">تسريع عتادي — Hardware Acceleration <span style="color:var(--t3);font-weight:400">(الأصلي: none)</span></label>
      <select class="fs" id="f_srv_hwaccel" data-key="srv_hwaccel" data-type="text"><option value="none">none</option><option value="nvenc">nvenc</option><option value="vaapi">vaapi</option><option value="qsv">qsv</option></select></div>
    <!-- srv_thread_count — الأصلي: 0 -->
    <div class="fg" style="margin:0"><label class="fl">عدد المسارات — Thread Count (0=تلقائي) <span style="color:var(--t3);font-weight:400">(الأصلي: 0)</span></label>
      <input type="number" class="fi" id="f_srv_thread_count" data-key="srv_thread_count" data-type="text" placeholder="0"></div>
    <!-- srv_tcp_udp_buffer — الأصلي: 8192 -->
    <div class="fg" style="margin:0"><label class="fl">حجم TCP/UDP Buffer (KB) <span style="color:var(--t3);font-weight:400">(الأصلي: 8192)</span></label>
      <input type="number" class="fi" id="f_srv_tcp_udp_buffer" data-key="srv_tcp_udp_buffer" data-type="text" placeholder="8192"></div>
    <!-- srv_socket_buffer — الأصلي: 65536 -->
    <div class="fg" style="margin:0"><label class="fl">Socket Buffer (بايت) <span style="color:var(--t3);font-weight:400">(الأصلي: 65536)</span></label>
      <input type="number" class="fi" id="f_srv_socket_buffer" data-key="srv_socket_buffer" data-type="text" placeholder="65536"></div>
    <!-- srv_cdn_failover — الأصلي: 0 -->
    <label class="fc-card" for="f_srv_cdn_failover" style="cursor:pointer">
      <div class="fc-info"><b>CDN Failover</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_srv_cdn_failover" data-key="srv_cdn_failover" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- srv_stream_priority — الأصلي: normal -->
    <div class="fg" style="margin:0"><label class="fl">أولوية البث — Stream Priority <span style="color:var(--t3);font-weight:400">(الأصلي: normal)</span></label>
      <select class="fs" id="f_srv_stream_priority" data-key="srv_stream_priority" data-type="text"><option value="low">low</option><option value="normal">normal</option><option value="high">high</option></select></div>
    <!-- srv_health_check_interval — الأصلي: 30 -->
    <div class="fg" style="margin:0"><label class="fl">فترة فحص الصحة — Health Check (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 30)</span></label>
      <input type="number" class="fi" id="f_srv_health_check_interval" data-key="srv_health_check_interval" data-type="text" placeholder="30"></div>
    <!-- srv_auto_restart_stream — الأصلي: 1 -->
    <label class="fc-card" for="f_srv_auto_restart_stream" style="cursor:pointer">
      <div class="fc-info"><b>إعادة تشغيل البث تلقائياً — Auto Restart</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_srv_auto_restart_stream" data-key="srv_auto_restart_stream" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- srv_stream_timeout — الأصلي: 20 -->
    <div class="fg" style="margin:0"><label class="fl">مهلة البث — Stream Timeout (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 20)</span></label>
      <input type="number" class="fi" id="f_srv_stream_timeout" data-key="srv_stream_timeout" data-type="text" placeholder="20"></div>
    <!-- srv_packet_loss_recovery — الأصلي: 1 -->
    <label class="fc-card" for="f_srv_packet_loss_recovery" style="cursor:pointer">
      <div class="fc-info"><b>استرجاع فقد الحزم — Packet Loss Recovery</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_srv_packet_loss_recovery" data-key="srv_packet_loss_recovery" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- srv_jitter_buffer — الأصلي: 500 -->
    <div class="fg" style="margin:0"><label class="fl">Jitter Buffer (مللي ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 500)</span></label>
      <input type="number" class="fi" id="f_srv_jitter_buffer" data-key="srv_jitter_buffer" data-type="text" placeholder="500"></div>
    <!-- srv_abr_enable — الأصلي: 1 -->
    <label class="fc-card" for="f_srv_abr_enable" style="cursor:pointer">
      <div class="fc-info"><b>معدل بت متكيّف — Adaptive Bitrate (ABR)</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_srv_abr_enable" data-key="srv_abr_enable" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- srv_max_bitrate — الأصلي: 8000 -->
    <div class="fg" style="margin:0"><label class="fl">أقصى معدل بت — Maximum Bitrate (kbps) <span style="color:var(--t3);font-weight:400">(الأصلي: 8000)</span></label>
      <input type="number" class="fi" id="f_srv_max_bitrate" data-key="srv_max_bitrate" data-type="text" placeholder="8000"></div>
    <!-- srv_min_bitrate — الأصلي: 800 -->
    <div class="fg" style="margin:0"><label class="fl">أدنى معدل بت — Minimum Bitrate (kbps) <span style="color:var(--t3);font-weight:400">(الأصلي: 800)</span></label>
      <input type="number" class="fi" id="f_srv_min_bitrate" data-key="srv_min_bitrate" data-type="text" placeholder="800"></div>
    <!-- srv_gop_size — الأصلي: 48 -->
    <div class="fg" style="margin:0"><label class="fl">حجم GOP — GOP Size (إطار) <span style="color:var(--t3);font-weight:400">(الأصلي: 48)</span></label>
      <input type="number" class="fi" id="f_srv_gop_size" data-key="srv_gop_size" data-type="text" placeholder="48"></div>
    <!-- srv_keyframe_interval — الأصلي: 2 -->
    <div class="fg" style="margin:0"><label class="fl">فاصل الإطار المفتاحي — Keyframe Interval (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 2)</span></label>
      <input type="number" class="fi" id="f_srv_keyframe_interval" data-key="srv_keyframe_interval" data-type="text" placeholder="2"></div>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('streaming_server')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('streaming_server')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات الواجهة -->
  <div class="gs-acc" data-group="ui" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#B36BFF22;color:#B36BFF;flex-shrink:0"><i class="fas fa-palette"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات الواجهة</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_ui"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- ui_theme — الأصلي: dark -->
    <div class="fg" style="margin:0"><label class="fl">الثيم <span style="color:var(--t3);font-weight:400">(الأصلي: dark)</span></label>
      <select class="fs" id="f_ui_theme" data-key="ui_theme" data-type="text"><option value="dark">dark</option><option value="light">light</option><option value="netflix">netflix</option><option value="purple">purple</option><option value="github">github</option><option value="emerald">emerald</option><option value="royal">royal</option></select></div>
    <!-- theme_color — الأصلي: #e50914 -->
    <div class="fg" style="margin:0"><label class="fl">اللون الأساسي <span style="color:var(--t3);font-weight:400">(الأصلي: #e50914)</span></label>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="color" id="f_theme_color_pick" value="#e50914" style="width:48px;height:42px;border:1px solid var(--br);border-radius:8px;background:transparent;cursor:pointer" oninput="document.getElementById('f_theme_color').value=this.value">
        <input type="text" class="fi" id="f_theme_color" data-key="theme_color" data-type="text" placeholder="#e50914" style="flex:1" oninput="try{document.getElementById('f_theme_color_pick').value=this.value}catch(e){}">
      </div></div>
    <!-- ui_font — الأصلي: Tajawal -->
    <div class="fg" style="margin:0"><label class="fl">الخط <span style="color:var(--t3);font-weight:400">(الأصلي: Tajawal)</span></label>
      <select class="fs" id="f_ui_font" data-key="ui_font" data-type="text"><option value="Tajawal">Tajawal</option><option value="Cairo">Cairo</option><option value="Almarai">Almarai</option><option value="Inter">Inter</option><option value="Roboto">Roboto</option></select></div>
    <!-- ui_font_size — الأصلي: 16 -->
    <div class="fg" style="margin:0"><label class="fl">حجم الخط (px) <span style="color:var(--t3);font-weight:400">(الأصلي: 16)</span></label>
      <input type="number" class="fi" id="f_ui_font_size" data-key="ui_font_size" data-type="text" placeholder="16"></div>
    <!-- ui_transitions — الأصلي: 1 -->
    <label class="fc-card" for="f_ui_transitions" style="cursor:pointer">
      <div class="fc-info"><b>تأثيرات الانتقال</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_ui_transitions" data-key="ui_transitions" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- ui_banner — الأصلي: فارغ -->
    <div class="fg" style="margin:0"><label class="fl">بانر أعلى الصفحة (رابط صورة)</label>
      <input type="text" class="fi" id="f_ui_banner" data-key="ui_banner" data-type="text" placeholder=""></div>
    <!-- ui_icon_style — الأصلي: solid -->
    <div class="fg" style="margin:0"><label class="fl">نمط الأيقونات <span style="color:var(--t3);font-weight:400">(الأصلي: solid)</span></label>
      <select class="fs" id="f_ui_icon_style" data-key="ui_icon_style" data-type="text"><option value="solid">solid</option><option value="regular">regular</option><option value="duotone">duotone</option></select></div>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('ui')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('ui')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات الصور -->
  <div class="gs-acc" data-group="images" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#4CC9F022;color:#4CC9F0;flex-shrink:0"><i class="fas fa-image"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات الصور</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_images"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- img_default_channel — الأصلي: فارغ -->
    <div class="fg" style="margin:0"><label class="fl">صورة افتراضية للقنوات (رابط)</label>
      <input type="text" class="fi" id="f_img_default_channel" data-key="img_default_channel" data-type="text" placeholder=""></div>
    <!-- img_default_movie — الأصلي: فارغ -->
    <div class="fg" style="margin:0"><label class="fl">صورة افتراضية للأفلام (رابط)</label>
      <input type="text" class="fi" id="f_img_default_movie" data-key="img_default_movie" data-type="text" placeholder=""></div>
    <!-- img_default_series — الأصلي: فارغ -->
    <div class="fg" style="margin:0"><label class="fl">صورة افتراضية للمسلسلات (رابط)</label>
      <input type="text" class="fi" id="f_img_default_series" data-key="img_default_series" data-type="text" placeholder=""></div>
    <!-- img_quality — الأصلي: 85 -->
    <div class="fg" style="margin:0"><label class="fl">جودة الصور (1-100) <span style="color:var(--t3);font-weight:400">(الأصلي: 85)</span></label>
      <input type="number" class="fi" id="f_img_quality" data-key="img_quality" data-type="text" placeholder="85"></div>
    <!-- img_compression — الأصلي: 1 -->
    <label class="fc-card" for="f_img_compression" style="cursor:pointer">
      <div class="fc-info"><b>ضغط الصور</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_img_compression" data-key="img_compression" data-type="bool"><span class="fc-slider"></span></span>
    </label>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('images')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('images')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات المستخدم -->
  <div class="gs-acc" data-group="user" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#00D08422;color:#00D084;flex-shrink:0"><i class="fas fa-user-gear"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات المستخدم</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_user"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- usr_save_last_watch — الأصلي: 1 -->
    <label class="fc-card" for="f_usr_save_last_watch" style="cursor:pointer">
      <div class="fc-info"><b>حفظ آخر مشاهدة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_usr_save_last_watch" data-key="usr_save_last_watch" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- usr_autoplay — الأصلي: 1 -->
    <label class="fc-card" for="f_usr_autoplay" style="cursor:pointer">
      <div class="fc-info"><b>التشغيل التلقائي</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_usr_autoplay" data-key="usr_autoplay" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- usr_dark_mode — الأصلي: 1 -->
    <label class="fc-card" for="f_usr_dark_mode" style="cursor:pointer">
      <div class="fc-info"><b>الوضع الليلي</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_usr_dark_mode" data-key="usr_dark_mode" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- usr_language — الأصلي: ar -->
    <div class="fg" style="margin:0"><label class="fl">اللغة <span style="color:var(--t3);font-weight:400">(الأصلي: ar)</span></label>
      <select class="fs" id="f_usr_language" data-key="usr_language" data-type="text"><option value="ar">ar</option><option value="en">en</option><option value="tr">tr</option></select></div>
    <!-- usr_notifications — الأصلي: 1 -->
    <label class="fc-card" for="f_usr_notifications" style="cursor:pointer">
      <div class="fc-info"><b>الإشعارات</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_usr_notifications" data-key="usr_notifications" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- usr_favorites — الأصلي: 1 -->
    <label class="fc-card" for="f_usr_favorites" style="cursor:pointer">
      <div class="fc-info"><b>المفضلة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_usr_favorites" data-key="usr_favorites" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- usr_watch_history — الأصلي: 1 -->
    <label class="fc-card" for="f_usr_watch_history" style="cursor:pointer">
      <div class="fc-info"><b>سجل المشاهدة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_usr_watch_history" data-key="usr_watch_history" data-type="bool"><span class="fc-slider"></span></span>
    </label>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('user')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('user')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: الأداء (Performance) -->
  <div class="gs-acc" data-group="performance" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#ff9f1c22;color:#ff9f1c;flex-shrink:0"><i class="fas fa-gauge-high"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">الأداء (Performance)</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_performance"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- perf_cache_duration — الأصلي: 3600 -->
    <div class="fg" style="margin:0"><label class="fl">مدة الكاش — Cache (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 3600)</span></label>
      <input type="number" class="fi" id="f_perf_cache_duration" data-key="perf_cache_duration" data-type="text" placeholder="3600"></div>
    <!-- perf_image_cache — الأصلي: 1 -->
    <label class="fc-card" for="f_perf_image_cache" style="cursor:pointer">
      <div class="fc-info"><b>كاش الصور — Image Cache</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_perf_image_cache" data-key="perf_image_cache" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- perf_api_cache — الأصلي: 1 -->
    <label class="fc-card" for="f_perf_api_cache" style="cursor:pointer">
      <div class="fc-info"><b>كاش API — API Cache</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_perf_api_cache" data-key="perf_api_cache" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- perf_gzip_brotli — الأصلي: 1 -->
    <label class="fc-card" for="f_perf_gzip_brotli" style="cursor:pointer">
      <div class="fc-info"><b>ضغط Gzip/Brotli</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_perf_gzip_brotli" data-key="perf_gzip_brotli" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- perf_lazy_loading — الأصلي: 1 -->
    <label class="fc-card" for="f_perf_lazy_loading" style="cursor:pointer">
      <div class="fc-info"><b>Lazy Loading</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_perf_lazy_loading" data-key="perf_lazy_loading" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- perf_http_version — الأصلي: 2 -->
    <div class="fg" style="margin:0"><label class="fl">إصدار HTTP <span style="color:var(--t3);font-weight:400">(الأصلي: 2)</span></label>
      <select class="fs" id="f_perf_http_version" data-key="perf_http_version" data-type="text"><option value="1.1">1.1</option><option value="2">2</option><option value="3">3</option></select></div>
    <!-- perf_prefetch — الأصلي: 1 -->
    <label class="fc-card" for="f_perf_prefetch" style="cursor:pointer">
      <div class="fc-info"><b>Prefetch</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_perf_prefetch" data-key="perf_prefetch" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- perf_preconnect — الأصلي: 1 -->
    <label class="fc-card" for="f_perf_preconnect" style="cursor:pointer">
      <div class="fc-info"><b>Preconnect</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_perf_preconnect" data-key="perf_preconnect" data-type="bool"><span class="fc-slider"></span></span>
    </label>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('performance')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('performance')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات الترجمة -->
  <div class="gs-acc" data-group="subtitles" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#58a6ff22;color:#58a6ff;flex-shrink:0"><i class="fas fa-closed-captioning"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات الترجمة</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_subtitles"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- sub_default_language — الأصلي: ar -->
    <div class="fg" style="margin:0"><label class="fl">اللغة الافتراضية <span style="color:var(--t3);font-weight:400">(الأصلي: ar)</span></label>
      <select class="fs" id="f_sub_default_language" data-key="sub_default_language" data-type="text"><option value="ar">ar</option><option value="en">en</option><option value="tr">tr</option><option value="fr">fr</option></select></div>
    <!-- sub_font_size — الأصلي: 18 -->
    <div class="fg" style="margin:0"><label class="fl">حجم الخط (px) <span style="color:var(--t3);font-weight:400">(الأصلي: 18)</span></label>
      <input type="number" class="fi" id="f_sub_font_size" data-key="sub_font_size" data-type="text" placeholder="18"></div>
    <!-- sub_font_color — الأصلي: #ffffff -->
    <div class="fg" style="margin:0"><label class="fl">لون الخط <span style="color:var(--t3);font-weight:400">(الأصلي: #ffffff)</span></label>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="color" id="f_sub_font_color_pick" value="#ffffff" style="width:48px;height:42px;border:1px solid var(--br);border-radius:8px;background:transparent;cursor:pointer" oninput="document.getElementById('f_sub_font_color').value=this.value">
        <input type="text" class="fi" id="f_sub_font_color" data-key="sub_font_color" data-type="text" placeholder="#ffffff" style="flex:1" oninput="try{document.getElementById('f_sub_font_color_pick').value=this.value}catch(e){}">
      </div></div>
    <!-- sub_bg_color — الأصلي: #000000 -->
    <div class="fg" style="margin:0"><label class="fl">لون الخلفية <span style="color:var(--t3);font-weight:400">(الأصلي: #000000)</span></label>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="color" id="f_sub_bg_color_pick" value="#000000" style="width:48px;height:42px;border:1px solid var(--br);border-radius:8px;background:transparent;cursor:pointer" oninput="document.getElementById('f_sub_bg_color').value=this.value">
        <input type="text" class="fi" id="f_sub_bg_color" data-key="sub_bg_color" data-type="text" placeholder="#000000" style="flex:1" oninput="try{document.getElementById('f_sub_bg_color_pick').value=this.value}catch(e){}">
      </div></div>
    <!-- sub_position — الأصلي: bottom -->
    <div class="fg" style="margin:0"><label class="fl">موضع الترجمة <span style="color:var(--t3);font-weight:400">(الأصلي: bottom)</span></label>
      <select class="fs" id="f_sub_position" data-key="sub_position" data-type="text"><option value="top">top</option><option value="center">center</option><option value="bottom">bottom</option></select></div>
    <!-- sub_bg_opacity — الأصلي: 60 -->
    <div class="fg" style="margin:0"><label class="fl">شفافية الخلفية (0-100) <span style="color:var(--t3);font-weight:400">(الأصلي: 60)</span></label>
      <input type="number" class="fi" id="f_sub_bg_opacity" data-key="sub_bg_opacity" data-type="text" placeholder="60"></div>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('subtitles')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('subtitles')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات المسلسلات -->
  <div class="gs-acc" data-group="series" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#e040fb22;color:#e040fb;flex-shrink:0"><i class="fas fa-film"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات المسلسلات</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_series"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- sr_resume_last_ep — الأصلي: 1 -->
    <label class="fc-card" for="f_sr_resume_last_ep" style="cursor:pointer">
      <div class="fc-info"><b>التشغيل من آخر حلقة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_sr_resume_last_ep" data-key="sr_resume_last_ep" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- sr_auto_next_ep — الأصلي: 1 -->
    <label class="fc-card" for="f_sr_auto_next_ep" style="cursor:pointer">
      <div class="fc-info"><b>الانتقال للحلقة التالية تلقائياً</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_sr_auto_next_ep" data-key="sr_auto_next_ep" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- sr_skip_intro — الأصلي: 0 -->
    <label class="fc-card" for="f_sr_skip_intro" style="cursor:pointer">
      <div class="fc-info"><b>تخطي المقدمة (Skip Intro)</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_sr_skip_intro" data-key="sr_skip_intro" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- sr_skip_outro — الأصلي: 0 -->
    <label class="fc-card" for="f_sr_skip_outro" style="cursor:pointer">
      <div class="fc-info"><b>تخطي الشارة الختامية</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_sr_skip_outro" data-key="sr_skip_outro" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- sr_season_order — الأصلي: asc -->
    <div class="fg" style="margin:0"><label class="fl">ترتيب المواسم <span style="color:var(--t3);font-weight:400">(الأصلي: asc)</span></label>
      <select class="fs" id="f_sr_season_order" data-key="sr_season_order" data-type="text"><option value="asc">asc</option><option value="desc">desc</option></select></div>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('series')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('series')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات الأفلام -->
  <div class="gs-acc" data-group="movies" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#ff4d5722;color:#ff4d57;flex-shrink:0"><i class="fas fa-clapperboard"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات الأفلام</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_movies"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- mv_per_page — الأصلي: 24 -->
    <div class="fg" style="margin:0"><label class="fl">عدد الأفلام في الصفحة <span style="color:var(--t3);font-weight:400">(الأصلي: 24)</span></label>
      <input type="number" class="fi" id="f_mv_per_page" data-key="mv_per_page" data-type="text" placeholder="24"></div>
    <!-- mv_default_quality — الأصلي: auto -->
    <div class="fg" style="margin:0"><label class="fl">جودة العرض الافتراضية <span style="color:var(--t3);font-weight:400">(الأصلي: auto)</span></label>
      <select class="fs" id="f_mv_default_quality" data-key="mv_default_quality" data-type="text"><option value="auto">auto</option><option value="480">480</option><option value="720">720</option><option value="1080">1080</option></select></div>
    <!-- mv_auto_subtitle — الأصلي: 0 -->
    <label class="fc-card" for="f_mv_auto_subtitle" style="cursor:pointer">
      <div class="fc-info"><b>تشغيل الترجمة تلقائياً</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_mv_auto_subtitle" data-key="mv_auto_subtitle" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- mv_subtitle_language — الأصلي: ar -->
    <div class="fg" style="margin:0"><label class="fl">اللغة الافتراضية للترجمة <span style="color:var(--t3);font-weight:400">(الأصلي: ar)</span></label>
      <select class="fs" id="f_mv_subtitle_language" data-key="mv_subtitle_language" data-type="text"><option value="ar">ar</option><option value="en">en</option><option value="tr">tr</option><option value="fr">fr</option></select></div>
    <!-- mv_play_trailer — الأصلي: 1 -->
    <label class="fc-card" for="f_mv_play_trailer" style="cursor:pointer">
      <div class="fc-info"><b>تشغيل التريلر</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_mv_play_trailer" data-key="mv_play_trailer" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- mv_show_similar — الأصلي: 1 -->
    <label class="fc-card" for="f_mv_show_similar" style="cursor:pointer">
      <div class="fc-info"><b>عرض الأفلام المشابهة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_mv_show_similar" data-key="mv_show_similar" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- mv_resume_watch — الأصلي: 1 -->
    <label class="fc-card" for="f_mv_resume_watch" style="cursor:pointer">
      <div class="fc-info"><b>استكمال المشاهدة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_mv_resume_watch" data-key="mv_resume_watch" data-type="bool"><span class="fc-slider"></span></span>
    </label>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('movies')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('movies')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات القنوات -->
  <div class="gs-acc" data-group="channels" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#F5A62322;color:#F5A623;flex-shrink:0"><i class="fas fa-tv"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات القنوات</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_channels"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- ch_per_page — الأصلي: 40 -->
    <div class="fg" style="margin:0"><label class="fl">عدد القنوات في الصفحة <span style="color:var(--t3);font-weight:400">(الأصلي: 40)</span></label>
      <input type="number" class="fi" id="f_ch_per_page" data-key="ch_per_page" data-type="text" placeholder="40"></div>
    <!-- ch_order — الأصلي: display_order -->
    <div class="fg" style="margin:0"><label class="fl">ترتيب القنوات <span style="color:var(--t3);font-weight:400">(الأصلي: display_order)</span></label>
      <select class="fs" id="f_ch_order" data-key="ch_order" data-type="text"><option value="display_order">display_order</option><option value="name">name</option><option value="newest">newest</option></select></div>
    <!-- ch_group_order — الأصلي: display_order -->
    <div class="fg" style="margin:0"><label class="fl">ترتيب المجموعات <span style="color:var(--t3);font-weight:400">(الأصلي: display_order)</span></label>
      <select class="fs" id="f_ch_group_order" data-key="ch_group_order" data-type="text"><option value="display_order">display_order</option><option value="name">name</option></select></div>
    <!-- ch_hide_offline — الأصلي: 0 -->
    <label class="fc-card" for="f_ch_hide_offline" style="cursor:pointer">
      <div class="fc-info"><b>إخفاء القنوات غير المتصلة</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_ch_hide_offline" data-key="ch_hide_offline" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- ch_auto_status — الأصلي: 0 -->
    <label class="fc-card" for="f_ch_auto_status" style="cursor:pointer">
      <div class="fc-info"><b>تحديث حالة القنوات تلقائياً</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_ch_auto_status" data-key="ch_auto_status" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- ch_check_interval — الأصلي: 60 -->
    <div class="fg" style="margin:0"><label class="fl">فترة فحص القنوات (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 60)</span></label>
      <input type="number" class="fi" id="f_ch_check_interval" data-key="ch_check_interval" data-type="text" placeholder="60"></div>
    <!-- ch_resume_last — الأصلي: 1 -->
    <label class="fc-card" for="f_ch_resume_last" style="cursor:pointer">
      <div class="fc-info"><b>تشغيل آخر قناة تمت مشاهدتها</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_ch_resume_last" data-key="ch_resume_last" data-type="bool"><span class="fc-slider"></span></span>
    </label>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('channels')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('channels')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات مشغّل الفيديو -->
  <div class="gs-acc" data-group="player" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#00D08422;color:#00D084;flex-shrink:0"><i class="fas fa-play-circle"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات مشغّل الفيديو</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_player"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- pl_autoplay — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_autoplay" style="cursor:pointer">
      <div class="fc-info"><b>التشغيل التلقائي</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_autoplay" data-key="pl_autoplay" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_mute_on_start — الأصلي: 0 -->
    <label class="fc-card" for="f_pl_mute_on_start" style="cursor:pointer">
      <div class="fc-info"><b>كتم الصوت عند التشغيل</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_mute_on_start" data-key="pl_mute_on_start" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_auto_fullscreen — الأصلي: 0 -->
    <label class="fc-card" for="f_pl_auto_fullscreen" style="cursor:pointer">
      <div class="fc-info"><b>ملء الشاشة تلقائياً</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_auto_fullscreen" data-key="pl_auto_fullscreen" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_pip — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_pip" style="cursor:pointer">
      <div class="fc-info"><b>Picture in Picture</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_pip" data-key="pl_pip" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_webcast — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_webcast" style="cursor:pointer">
      <div class="fc-info"><b>webcast</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_webcast" data-key="pl_webcast" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_seek_buttons — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_seek_buttons" style="cursor:pointer">
      <div class="fc-info"><b>أزرار التقديم والترجيع</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_seek_buttons" data-key="pl_seek_buttons" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_playback_speed — الأصلي: 1 -->
    <div class="fg" style="margin:0"><label class="fl">سرعة التشغيل الافتراضية <span style="color:var(--t3);font-weight:400">(الأصلي: 1)</span></label>
      <select class="fs" id="f_pl_playback_speed" data-key="pl_playback_speed" data-type="text"><option value="0.5">0.5</option><option value="0.75">0.75</option><option value="1">1</option><option value="1.25">1.25</option><option value="1.5">1.5</option><option value="2">2</option></select></div>
    <!-- pl_thumbnails — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_thumbnails" style="cursor:pointer">
      <div class="fc-info"><b>معاينة الصور (Thumbnails)</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_thumbnails" data-key="pl_thumbnails" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_show_channel_logo — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_show_channel_logo" style="cursor:pointer">
      <div class="fc-info"><b>إظهار شعار القناة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_show_channel_logo" data-key="pl_show_channel_logo" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_show_channel_name — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_show_channel_name" style="cursor:pointer">
      <div class="fc-info"><b>إظهار اسم القناة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_show_channel_name" data-key="pl_show_channel_name" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_show_clock — الأصلي: 0 -->
    <label class="fc-card" for="f_pl_show_clock" style="cursor:pointer">
      <div class="fc-info"><b>إظهار الساعة</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_show_clock" data-key="pl_show_clock" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_show_viewers — الأصلي: 0 -->
    <label class="fc-card" for="f_pl_show_viewers" style="cursor:pointer">
      <div class="fc-info"><b>إظهار عداد المشاهدين</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_show_viewers" data-key="pl_show_viewers" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_show_share — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_show_share" style="cursor:pointer">
      <div class="fc-info"><b>إظهار زر المشاركة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_show_share" data-key="pl_show_share" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- pl_show_report — الأصلي: 1 -->
    <label class="fc-card" for="f_pl_show_report" style="cursor:pointer">
      <div class="fc-info"><b>إظهار زر الإبلاغ</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_pl_show_report" data-key="pl_show_report" data-type="bool"><span class="fc-slider"></span></span>
    </label>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('player')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('player')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- مجموعة: إعدادات البث (Streaming) -->
  <div class="gs-acc" data-group="streaming_client" style="border:1px solid var(--br);border-radius:var(--r2,12px);margin-bottom:14px;overflow:hidden;background:var(--s1,transparent)">
    <button type="button" class="gs-acc-head" onclick="gsToggleAcc(this)" style="width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:transparent;border:none;cursor:pointer;color:var(--t1);text-align:right">
      <span style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#4CC9F022;color:#4CC9F0;flex-shrink:0"><i class="fas fa-signal"></i></span>
      <span style="flex:1;font-weight:700;font-size:1rem">إعدادات البث (Streaming)</span>
      <i class="fas fa-chevron-down gs-acc-arrow" style="transition:transform .25s;color:var(--t3)"></i>
    </button>
    <div class="gs-acc-body" style="display:none;padding:0 18px 18px">
      
      <div id="ga_streaming_client"></div>
      <div class="cbody" style="display:flex;flex-direction:column;gap:14px">

    <!-- st_low_latency — الأصلي: 0 -->
    <label class="fc-card" for="f_st_low_latency" style="cursor:pointer">
      <div class="fc-info"><b>Low Latency Mode</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_st_low_latency" data-key="st_low_latency" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- st_buffer_size — الأصلي: 30 -->
    <div class="fg" style="margin:0"><label class="fl">Buffer Size (ثانية 1-30) <span style="color:var(--t3);font-weight:400">(الأصلي: 30)</span></label>
      <input type="number" class="fi" id="f_st_buffer_size" data-key="st_buffer_size" data-type="text" placeholder="30"></div>
    <!-- st_startup_buffer — الأصلي: 2 -->
    <div class="fg" style="margin:0"><label class="fl">Startup Buffer (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 2)</span></label>
      <input type="number" class="fi" id="f_st_startup_buffer" data-key="st_startup_buffer" data-type="text" placeholder="2"></div>
    <!-- st_max_buffer — الأصلي: 60 -->
    <div class="fg" style="margin:0"><label class="fl">Max Buffer Length (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 60)</span></label>
      <input type="number" class="fi" id="f_st_max_buffer" data-key="st_max_buffer" data-type="text" placeholder="60"></div>
    <!-- st_back_buffer — الأصلي: 90 -->
    <div class="fg" style="margin:0"><label class="fl">Back Buffer Length (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 90)</span></label>
      <input type="number" class="fi" id="f_st_back_buffer" data-key="st_back_buffer" data-type="text" placeholder="90"></div>
    <!-- st_live_sync — الأصلي: 3 -->
    <div class="fg" style="margin:0"><label class="fl">Live Sync Duration (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 3)</span></label>
      <input type="number" class="fi" id="f_st_live_sync" data-key="st_live_sync" data-type="text" placeholder="3"></div>
    <!-- st_auto_quality — الأصلي: 1 -->
    <label class="fc-card" for="f_st_auto_quality" style="cursor:pointer">
      <div class="fc-info"><b>Auto Quality (ABR)</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_st_auto_quality" data-key="st_auto_quality" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- st_default_quality — الأصلي: auto -->
    <div class="fg" style="margin:0"><label class="fl">الجودة الافتراضية <span style="color:var(--t3);font-weight:400">(الأصلي: auto)</span></label>
      <select class="fs" id="f_st_default_quality" data-key="st_default_quality" data-type="text"><option value="auto">auto</option><option value="480">480</option><option value="720">720</option><option value="1080">1080</option></select></div>
    <!-- st_allow_quality_change — الأصلي: 1 -->
    <label class="fc-card" for="f_st_allow_quality_change" style="cursor:pointer">
      <div class="fc-info"><b>السماح للمستخدم بتغيير الجودة</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_st_allow_quality_change" data-key="st_allow_quality_change" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- st_auto_reconnect — الأصلي: 1 -->
    <label class="fc-card" for="f_st_auto_reconnect" style="cursor:pointer">
      <div class="fc-info"><b>إعادة الاتصال التلقائي عند الانقطاع</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_st_auto_reconnect" data-key="st_auto_reconnect" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- st_reconnect_attempts — الأصلي: 5 -->
    <div class="fg" style="margin:0"><label class="fl">عدد محاولات إعادة الاتصال <span style="color:var(--t3);font-weight:400">(الأصلي: 5)</span></label>
      <input type="number" class="fi" id="f_st_reconnect_attempts" data-key="st_reconnect_attempts" data-type="text" placeholder="5"></div>
    <!-- st_reconnect_timeout — الأصلي: 3 -->
    <div class="fg" style="margin:0"><label class="fl">المهلة قبل إعادة الاتصال (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 3)</span></label>
      <input type="number" class="fi" id="f_st_reconnect_timeout" data-key="st_reconnect_timeout" data-type="text" placeholder="3"></div>
    <!-- st_failover — الأصلي: 1 -->
    <label class="fc-card" for="f_st_failover" style="cursor:pointer">
      <div class="fc-info"><b>الانتقال لرابط احتياطي (Failover)</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_st_failover" data-key="st_failover" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- st_protocol — الأصلي: hls -->
    <div class="fg" style="margin:0"><label class="fl">استخدام HLS أو DASH <span style="color:var(--t3);font-weight:400">(الأصلي: hls)</span></label>
      <select class="fs" id="f_st_protocol" data-key="st_protocol" data-type="text"><option value="hls">hls</option><option value="dash">dash</option></select></div>
    <!-- st_llhls_support — الأصلي: 0 -->
    <label class="fc-card" for="f_st_llhls_support" style="cursor:pointer">
      <div class="fc-info"><b>دعم LL-HLS</b><small>القيمة الأصلية: 0</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_st_llhls_support" data-key="st_llhls_support" data-type="bool"><span class="fc-slider"></span></span>
    </label>
    <!-- st_playlist_refresh — الأصلي: 6 -->
    <div class="fg" style="margin:0"><label class="fl">مدة تحديث Playlist (ثانية) <span style="color:var(--t3);font-weight:400">(الأصلي: 6)</span></label>
      <input type="number" class="fi" id="f_st_playlist_refresh" data-key="st_playlist_refresh" data-type="text" placeholder="6"></div>
    <!-- st_stream_cache — الأصلي: 1 -->
    <label class="fc-card" for="f_st_stream_cache" style="cursor:pointer">
      <div class="fc-info"><b>Cache للبث</b><small>القيمة الأصلية: 1</small></div>
      <span class="fc-switch"><input type="checkbox" id="f_st_stream_cache" data-key="st_stream_cache" data-type="bool"><span class="fc-slider"></span></span>
    </label>
          </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-p" onclick="saveGroupSettings('streaming_client')" style="padding:10px 24px"><i class="fas fa-save"></i> حفظ هذه المجموعة</button>
        <button class="btn btn-g" onclick="loadGroupSettings('streaming_client')"><i class="fas fa-rotate-left"></i> استرجاع</button>
      </div>
    </div>
  </div>

  <!-- زر الحفظ الرئيسي -->
  <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;position:sticky;bottom:0;background:var(--s0,transparent);padding:6px 0">
    <button class="btn btn-p" onclick="saveGeneralSettings()" style="padding:12px 30px"><i class="fas fa-save"></i> حفظ كل الإعدادات العامة</button>
    <button class="btn btn-g" onclick="loadGeneralSettings()"><i class="fas fa-rotate-left"></i> استرجاع المحفوظ</button>
    <button class="btn btn-d" onclick="restoreDefaultSettings()" style="padding:12px 30px; background-color: #d32f2f; color: white; border-color: transparent;"><i class="fas fa-undo"></i> استرجاع كل القيم الأصلية</button>
  </div>
</section>


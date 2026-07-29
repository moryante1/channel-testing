<script id="shashety-player-fixes-js">
(function(){
  'use strict';

  /* ══════════════════════════════════════════════════════════════════
     العيب 1: زر الرجوع في أندرويد يعمل "رجوع" لكن لا يخرج من المشغّل
     ─────────────────────────────────────────────────────────────────
     يوجد مساران لإغلاق المشغّل، ولكلٍّ حالة history مختلفة:

       (أ) زر الإغلاق المرئي (X): يستدعي closePlayer مباشرة — الـ history
           لم يُلمس، فتبقى إدخالة {player:'active'} عالقة. الضغطة التالية
           على رجوع أندرويد تستهلك تلك الإدخالة العالقة بدل أن تخرج =
           هذا هو سبب "يعمل رجوع ولكن لا يخرج".

       (ب) زر رجوع أندرويد: المتصفح يطلق popstate (ويزيل إدخالة تلقائياً)
           ثم _goBack ينادي closePlayer. هنا الـ history صحيح أصلاً.

     الحل: نميّز المصدر. في المسار (أ) نزيل الإدخالة العالقة بـ history.back()
     مرة واحدة لمزامنة الـ stack. في المسار (ب) لا نلمس الـ history إطلاقاً.
     لا نعدّل منطق المشغّل؛ فقط نغلّف closePlayer ونعلّم مصدر النداء.
  ══════════════════════════════════════════════════════════════════ */

  var _fromPopstate = false;  // علم: هل النداء الحالي قادم من popstate؟

  // نلتقط popstate قبل المنطق الأصلي (capture) لنعلّم المصدر
  window.addEventListener('popstate', function(){
    _fromPopstate = true;
    // نطلق العلم بعد انتهاء دورة الحدث (بعد أن ينفّذ المنطق الأصلي closePlayer)
    setTimeout(function(){ _fromPopstate = false; }, 0);
  }, true);

  function wrapClose(){
    if(typeof window.closePlayer !== 'function'){ setTimeout(wrapClose, 200); return; }
    if(window.closePlayer.__shsWrapped) return;

    var _orig = window.closePlayer;
    window.closePlayer = function(){
      var wasActive = !!(document.getElementById('playerOverlay') &&
                         document.getElementById('playerOverlay').classList.contains('active'));
      var fromPop = _fromPopstate;

      var r = _orig.apply(this, arguments);

      // مزامنة الـ history فقط في المسار (أ): إغلاق يدوي بزر X والمشغّل كان نشطاً
      // وليس قادماً من popstate (حتى لا نزيل إدخالة مرتين فنخرج من الموقع).
      if(wasActive && !fromPop){
        try{
          // إزالة إدخالة {player:'active'} العالقة لمزامنة المكدّس
          if(window.history.state && window.history.state.player === 'active'){
            _suppressNextGoBack = true;
            history.back();
          }
        }catch(e){}
      }
      return r;
    };
    window.closePlayer.__shsWrapped = true;
  }
  wrapClose();

  // عند تنفيذ history.back() أعلاه سيُطلق popstate → _goBack. لكن المشغّل
  // أصبح مغلقاً، فـ _goBack سيتعامل مع شاشة خلفية. نمنع تأثيراً جانبياً
  // واحداً فقط بعد إغلاقنا اليدوي.
  var _suppressNextGoBack = false;
  var _origGoBackGetter;
  function guardGoBack(){
    if(typeof window._goBack !== 'function'){ setTimeout(guardGoBack, 200); return; }
    if(window._goBack.__shsGuarded) return;
    var _orig = window._goBack;
    window._goBack = function(){
      if(_suppressNextGoBack){
        _suppressNextGoBack = false;
        return; // نتجاهل هذه الـ popstate الناتجة عن مزامنتنا فقط
      }
      return _orig.apply(this, arguments);
    };
    window._goBack.__shsGuarded = true;
  }
  guardGoBack();


  /* ══════════════════════════════════════════════════════════════════
     العيب 2: في التلفاز، بعد تقديم ثم إيقاف/تشغيل، شريط التحكم لا يختفي
     ─────────────────────────────────────────────────────────────────
     السبب: على التلفاز يدخل الفيديو buffering بعد التقديم، فحدث onplaying
     يتأخّر، فيبقى PL.userPaused في حالة انتقالية عند انتهاء مؤقّت الإخفاء
     فلا تُخفى القائمة إلا بالخروج وإعادة الدخول.

     الحل: حارس خفيف يصحّح PL.userPaused إن كان الفيديو يعمل فعلاً، ويعيد
     ضبط مؤقّت الإخفاء عبر showControls الأصلية. لا نغيّر منطق المشغّل.
  ══════════════════════════════════════════════════════════════════ */

  var _lastT = -1;
  function reconcile(){
    var ov = document.getElementById('playerOverlay');
    if(!ov || !ov.classList.contains('active')) return;
    var v = document.getElementById('html5Player');
    if(!v || !window.PL) return;

    var advancing = (!v.paused && !v.ended && v.currentTime !== _lastT);
    _lastT = v.currentTime;

    // الفيديو يعمل فعلاً لكن النظام يظنه متوقفاً → صحّح وأخفِ القائمة بعد المهلة
    if(advancing && window.PL.userPaused === true){
      window.PL.userPaused = false;
      if(typeof window.setPlayIcon === 'function'){ try{ window.setPlayIcon(false); }catch(e){} }
      if(typeof window.showControls === 'function'){ try{ window.showControls(); }catch(e){} }
    }
  }
  setInterval(reconcile, 700);

  // ضمان إعادة ضبط مؤقّت الإخفاء عند بدء التشغيل فعلياً (ولو تأخّر بعد بَفرة)
  function hookPlaying(){
    var v = document.getElementById('html5Player');
    if(!v){ setTimeout(hookPlaying, 300); return; }
    if(v.__shsPlayingHook) return;
    v.addEventListener('playing', function(){
      if(window.PL) window.PL.userPaused = false;
      if(typeof window.showControls === 'function'){ try{ window.showControls(); }catch(e){} }
    });
    v.__shsPlayingHook = true;
  }
  hookPlaying();
  setInterval(hookPlaying, 2000); // عنصر الفيديو قد يُعاد إنشاؤه عند تغيير المصدر

})();
</script>
<!-- ════════════════════ نهاية إصلاحات المشغّل ════════════════════ -->
<!-- ════════════ إصلاح تمرير شريط الأقسام على الكمبيوتر — إضافة آمنة ════════════ -->

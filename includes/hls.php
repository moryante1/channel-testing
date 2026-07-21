<script src="https://cdn.jsdelivr.net/npm/hls.js@latest">
// إغلاق قائمة اللغات عند النقر بالخارج
document.addEventListener('click', function() {
    var drop = document.getElementById('langDrop');
    if(drop && drop.classList.contains('op')) { drop.classList.remove('op'); }
    var psw = document.getElementById('profSw');
    if(psw && psw.classList.contains('op')) { psw.classList.remove('op'); }
});
</script>

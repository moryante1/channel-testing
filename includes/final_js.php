<script>
// API Settings Dynamic Logic
function addApiCard() {
    const sel = document.getElementById('apiTypeSelect');
    const type = sel.value;
    if(!type) return;
    const card = document.getElementById('card_' + type);
    if(card) {
        card.style.display = 'block';
    }
    sel.value = '';
}

function removeApiCard(type) {
    const card = document.getElementById('card_' + type);
    if(card) {
        card.style.display = 'none';
        if(type === 'tmdb') document.getElementById('api_tmdb_key').value = '';
        if(type === 'omdb') document.getElementById('api_omdb_key').value = '';
        if(type === 'os') {
            document.getElementById('api_os_user').value = '';
            document.getElementById('api_os_pass').value = '';
            document.getElementById('api_os_key').value = '';
        }
        document.getElementById('status_' + type).innerHTML = '';
    }
}

async function testApiTmdb() {
    const key = document.getElementById('api_tmdb_key').value.trim();
    const st = document.getElementById('status_tmdb');
    if(!key) {
        st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> يرجى إدخال المفتاح أولاً</span>';
        return;
    }
    st.innerHTML = '<span style="color:var(--t3)"><i class="fas fa-spinner fa-spin"></i> جاري الفحص...</span>';
    try {
        const r = await fetch(`https://api.themoviedb.org/3/configuration?api_key=${encodeURIComponent(key)}`);
        if(r.ok) {
            st.innerHTML = '<span style="color:var(--green)"><i class="fas fa-check-circle"></i> متصل ويعمل بنجاح</span>';
        } else {
            st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> مفتاح غير صالح أو الحساب لا يعمل</span>';
        }
    } catch(e) {
        st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> خطأ في الاتصال</span>';
    }
}

async function testApiOmdb() {
    const key = document.getElementById('api_omdb_key').value.trim();
    const st = document.getElementById('status_omdb');
    if(!key) {
        st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> يرجى إدخال المفتاح أولاً</span>';
        return;
    }
    st.innerHTML = '<span style="color:var(--t3)"><i class="fas fa-spinner fa-spin"></i> جاري الفحص...</span>';
    try {
        const r = await fetch(`https://www.omdbapi.com/?apikey=${encodeURIComponent(key)}&s=Batman&page=1`);
        const d = await r.json();
        if(d.Response === 'True') {
            st.innerHTML = '<span style="color:var(--green)"><i class="fas fa-check-circle"></i> متصل ويعمل بنجاح</span>';
        } else {
            st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> مفتاح غير صالح أو الحساب لا يعمل</span>';
        }
    } catch(e) {
        st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> خطأ في الاتصال</span>';
    }
}

function testApiOs() {
    const user = document.getElementById('api_os_user').value.trim();
    const pass = document.getElementById('api_os_pass').value.trim();
    const key  = document.getElementById('api_os_key').value.trim();
    const st   = document.getElementById('status_os');

    if(!key || !user || !pass) {
        st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> \u064a\u0631\u062c\u0649 \u0645\u0644\u0621 \u0627\u0644\u0645\u0641\u062a\u0627\u062d \u0648\u0627\u0633\u0645 \u0627\u0644\u0645\u0633\u062a\u062e\u062f\u0645 \u0648\u0643\u0644\u0645\u0629 \u0627\u0644\u0645\u0631\u0648\u0631</span>';
        return;
    }
    st.innerHTML = '<span style="color:var(--t3)"><i class="fas fa-spinner fa-spin"></i> \u062c\u0627\u0631\u064a \u0627\u0644\u0641\u062d\u0635...</span>';

    api({ajax_action:'os_login', username:user, password:pass, api_key:key}).then(d => {
        if(d.success){
            st.innerHTML = '<span style="color:var(--green)"><i class="fas fa-check-circle"></i> \u0645\u062a\u0635\u0644 \u0628\u0646\u062c\u0627\u062d \u2014 \u0631\u0635\u064a\u062f \u0627\u0644\u062a\u0646\u0632\u064a\u0644: '+(d.allowed||'?')+'</span>';
        } else {
            st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> '+(d.error||'\u0641\u0634\u0644 \u0627\u0644\u0627\u062a\u0635\u0627\u0644')+'</span>';
        }
    }).catch(() => {
        st.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> \u062e\u0637\u0623 \u0641\u064a \u0627\u0644\u0627\u062a\u0635\u0627\u0644</span>';
    });
}

function fetchServerStats() {
    const fd = new FormData();
    fd.append('ajax_action', 'get_server_stats');
    
    // استخدام fetch مباشرة بدلاً من api() لمنع ظهور شريط التحميل العلوي
    fetch(location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            // CPU
            let cpuP = d.cpu.percent;
            document.getElementById('cpu_percent_text').textContent = cpuP + '%';
            let cpuColor = cpuP > 85 ? 'linear-gradient(90deg, #ff416c, #ff4b2b)' : (cpuP > 60 ? 'linear-gradient(90deg, #fceabb, #f8b500)' : 'linear-gradient(90deg, #B36BFF, #7B2CBF)');
            document.getElementById('cpu_bar').style.width = cpuP + '%';
            document.getElementById('cpu_bar').style.background = cpuColor;
            document.getElementById('cpu_desc').textContent = 'استهلاك المعالج الفعلي';

            // RAM
            let ramP = d.ram.percent;
            document.getElementById('ram_percent_text').textContent = ramP + '%';
            let ramColor = ramP > 85 ? 'linear-gradient(90deg, #ff416c, #ff4b2b)' : (ramP > 60 ? 'linear-gradient(90deg, #fceabb, #f8b500)' : 'linear-gradient(90deg, #00D084, #009e60)');
            document.getElementById('ram_bar').style.width = ramP + '%';
            document.getElementById('ram_bar').style.background = ramColor;
            document.getElementById('ram_used_text').textContent = 'مستخدم: ' + d.ram.used;
            document.getElementById('ram_total_text').textContent = 'الكلي: ' + d.ram.total;

            // Disk
            let diskP = d.disk.percent;
            document.getElementById('disk_percent_text').textContent = diskP + '%';
            let diskColor = diskP > 85 ? 'linear-gradient(90deg, #ff416c, #ff4b2b)' : (diskP > 60 ? 'linear-gradient(90deg, #fceabb, #f8b500)' : 'linear-gradient(90deg, #4CC9F0, #0096C7)');
            document.getElementById('disk_bar').style.width = diskP + '%';
            document.getElementById('disk_bar').style.background = diskColor;
            document.getElementById('disk_used_text').textContent = 'مستخدم: ' + d.disk.used;
            document.getElementById('disk_total_text').textContent = 'الكلي: ' + d.disk.total;
        }
    }).catch(e => {}); // الصمت عند الخطأ لمنع إزعاج المستخدم
}

document.addEventListener('DOMContentLoaded', () => {
    fetchServerStats();
    // تقليل المدة إلى 3 ثواني ليكون التحسس مستمر ولحظي
    setInterval(fetchServerStats, 3000);
});
</script>

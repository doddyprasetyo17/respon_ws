/*
 * Respon WS -- pengambilan data dan penggambaran.
 *
 * Halaman tidak pernah reload sendiri. Kartu sudah dirender server dari config;
 * skrip ini hanya memperbarui isinya dari api.php.
 *
 * Riwayat datang dari server, bukan dari penyimpanan browser. Penyimpanan lokal
 * hanya dipakai untuk satu hal: preferensi alarm, yang memang milik tiap layar.
 */

(function () {
    'use strict';

    var board = document.getElementById('board');
    if (!board) {
        return;
    }

    var REFRESH = Math.max(5, parseInt(board.dataset.refresh, 10) || 30);
    var THRESHOLD_GOOD = parseInt(board.dataset.good, 10) || 500;
    var ALARM_KEY = 'responws.alarm.v1';

    var els = {
        banner: document.getElementById('banner'),
        cycleNote: document.getElementById('cycleNote'),
        cycleDuration: document.getElementById('cycleDuration'),
        windowNote: document.getElementById('windowNote'),
        countdown: document.getElementById('countdown'),
        refreshBtn: document.getElementById('refreshBtn'),
        alarmBtn: document.getElementById('alarmBtn'),
        alarmLabel: document.getElementById('alarmLabel'),
        sweep: document.getElementById('sweep'),
        sweepFill: document.getElementById('sweepFill'),
        ticker: document.getElementById('ticker'),
        tickerRun: document.getElementById('tickerRun'),
        tickerSeg: document.getElementById('tickerSeg'),
        tickerSegClone: document.getElementById('tickerSegClone')
    };

    var remaining = REFRESH;
    var inFlight = false;
    var anyDown = false;

    /* ---------- Format ---------- */

    var LABEL_STATUS = {
        good: 'Jaringan bagus',
        slow: 'Lambat',
        error: 'Error server',
        down: 'Terputus',
        unset: 'Belum diset'
    };

    function jam(epochDetik) {
        if (!epochDetik) {
            return '';
        }
        var d = new Date(epochDetik * 1000);
        return ('0' + d.getHours()).slice(-2) + ':' +
            ('0' + d.getMinutes()).slice(-2) + ':' +
            ('0' + d.getSeconds()).slice(-2);
    }

    function durasi(detik) {
        if (detik < 60) {
            return Math.max(0, Math.round(detik)) + ' detik';
        }
        var menit = Math.round(detik / 60);
        if (menit < 60) {
            return menit + ' menit';
        }
        var jamPenuh = Math.floor(menit / 60);
        var sisa = menit % 60;
        return sisa > 0 ? jamPenuh + ' jam ' + sisa + ' menit' : jamPenuh + ' jam';
    }

    function persen(nilai) {
        if (nilai === null || typeof nilai !== 'number') {
            return '';
        }
        return (nilai >= 100 ? '100' : nilai.toFixed(1)) + '%';
    }

    /* ---------- Penyimpanan preferensi alarm ---------- */

    function loadAlarmPref() {
        try {
            return window.localStorage.getItem(ALARM_KEY) === 'on';
        } catch (err) {
            return false;
        }
    }

    function saveAlarmPref(on) {
        try {
            window.localStorage.setItem(ALARM_KEY, on ? 'on' : 'off');
        } catch (err) {
            /* preferensi saja; abaikan kalau penyimpanan diblokir */
        }
    }

    /* ---------- Grafik latency ---------- */

    /**
     * Hitung titik polyline pada viewBox 200x40, plus posisi garis ambang.
     *
     * Skala mengikuti nilai terbesar yang benar-benar terjadi, bukan ambang,
     * supaya variasi kecil tetap terlihat. Konsekuensinya garis ambang hanya
     * masuk gambar kalau memang pernah ada yang mendekatinya -- dan justru itu
     * satu-satunya saat garis tersebut berguna.
     */
    function geometriTrace(samples) {
        var hasil = { points: '0,20 200,20', thresholdY: null };

        if (!samples || samples.length === 0) {
            return hasil;
        }

        var max = 100;
        var i;
        for (i = 0; i < samples.length; i++) {
            if (typeof samples[i] === 'number' && samples[i] > max) {
                max = samples[i];
            }
        }

        function y(nilai) {
            if (typeof nilai !== 'number') {
                return 20; // tidak menjawab -> garis datar di tengah
            }
            return 37 - Math.min(1, nilai / max) * 33;
        }

        if (samples.length === 1) {
            var satu = y(samples[0]).toFixed(1);
            hasil.points = '0,' + satu + ' 200,' + satu;
        } else {
            var langkah = 200 / (samples.length - 1);
            var titik = [];
            for (i = 0; i < samples.length; i++) {
                titik.push((i * langkah).toFixed(1) + ',' + y(samples[i]).toFixed(1));
            }
            hasil.points = titik.join(' ');
        }

        if (THRESHOLD_GOOD > 0 && THRESHOLD_GOOD <= max) {
            hasil.thresholdY = y(THRESHOLD_GOOD);
        }

        return hasil;
    }

    /* ---------- Strip riwayat ---------- */

    function renderStrip(container, strip, svc) {
        if (!container) {
            return;
        }

        container.textContent = '';

        if (!strip || strip.length === 0) {
            container.classList.add('strip--empty');
            container.setAttribute('aria-label', 'Riwayat belum terkumpul');
            return;
        }

        container.classList.remove('strip--empty');

        var frag = document.createDocumentFragment();
        for (var i = 0; i < strip.length; i++) {
            var sel = document.createElement('span');
            sel.className = 'strip__cell';
            sel.setAttribute('data-s', strip[i].s);
            sel.title = jam(strip[i].t) + ' — ' + (LABEL_STATUS[strip[i].s] || strip[i].s);
            frag.appendChild(sel);
        }
        container.appendChild(frag);

        container.setAttribute(
            'aria-label',
            strip.length + ' pengecekan terakhir, ketersediaan ' +
            (persen(svc.availability) || 'belum terhitung')
        );
    }

    /* ---------- Render kartu ---------- */

    function render(data) {
        var services = data.services || [];
        anyDown = false;

        for (var i = 0; i < services.length; i++) {
            var svc = services[i];
            var card = board.querySelector('[data-svc="' + cssEscape(svc.id) + '"]');
            if (!card) {
                // Layanan baru ditambahkan ke config setelah halaman dimuat.
                continue;
            }

            if (svc.status === 'down') {
                anyDown = true;
            }

            card.dataset.status = svc.status;

            setField(card, 'latency',
                typeof svc.latency_ms === 'number' ? Math.round(svc.latency_ms) : '–––');
            setField(card, 'label', svc.label);
            setField(card, 'code', svc.http_code ? String(svc.http_code) : '');

            renderAvail(card.querySelector('[data-field="avail"]'), svc);

            var geom = geometriTrace(svc.latencies || []);
            var garis = card.querySelector('[data-field="trace"]');
            if (garis) {
                garis.setAttribute('points', geom.points);
            }
            var area = card.querySelector('[data-field="area"]');
            if (area) {
                // Garis yang sama, ditutup ke dasar viewBox.
                area.setAttribute('points', geom.points + ' 200,40 0,40');
            }
            var ambang = card.querySelector('[data-field="threshold"]');
            if (ambang) {
                if (geom.thresholdY === null) {
                    ambang.setAttribute('opacity', '0');
                } else {
                    ambang.setAttribute('y1', geom.thresholdY.toFixed(1));
                    ambang.setAttribute('y2', geom.thresholdY.toFixed(1));
                    ambang.setAttribute('opacity', '1');
                }
            }

            renderStrip(card.querySelector('[data-field="strip"]'), svc.strip, svc);

            card.title = judulKartu(svc, data);
        }

        renderSummary(data.summary || {});
        renderTicker(data.incidents || [], data.summary || {}, data);

        if (els.cycleNote) {
            els.cycleNote.textContent = data.checked_at_human || '--:--:--';
        }
        if (els.cycleDuration) {
            els.cycleDuration.textContent = 'Siklus ' + (data.duration_ms || 0) + ' ms untuk ' +
                services.length + ' layanan';
        }
        if (els.windowNote) {
            els.windowNote.textContent = data.window_minutes
                ? 'Riwayat ' + durasi(data.window_minutes * 60) + ' terakhir'
                : 'Riwayat belum tersimpan';
        }

        if (data.history_note) {
            showBanner(data.history_note);
        } else {
            hideBanner();
        }

        updateAlarm();
    }

    function judulKartu(svc, data) {
        var baris = [svc.name + ' — ' + svc.label];

        if (svc.detail) {
            baris.push(svc.detail);
        }
        if (typeof svc.connect_ms === 'number') {
            baris.push('Waktu connect: ' + Math.round(svc.connect_ms) + ' ms');
        }
        // Nilai lazim layanan ini, untuk menilai apakah angka sekarang wajar.
        // Tidak dipajang di kartu: latency ke luar rumah sakit berayun cukup
        // lebar sehingga penanda naik-turun akan menyala hampir sepanjang waktu
        // dan berhenti berarti apa-apa. Trace di bawah angka sudah menunjukkan
        // arah pergerakannya dengan lebih jujur.
        if (typeof svc.baseline_ms === 'number') {
            baris.push('Biasanya sekitar ' + svc.baseline_ms + ' ms');
        }
        if (svc.availability !== null && svc.history_samples > 0) {
            baris.push('Ketersediaan ' + persen(svc.availability) +
                ' dari ' + svc.history_samples + ' pengecekan');
        }
        if (svc.outages > 0) {
            baris.push(svc.outages + ' gangguan dalam ' + durasi((data.window_minutes || 0) * 60) + ' terakhir');
        }

        return baris.join('\n');
    }

    function renderAvail(node, svc) {
        if (!node) {
            return;
        }

        // Di bawah beberapa sampel, persentase belum berarti apa-apa. Angka 100%
        // juga disembunyikan: strip yang seluruhnya hijau sudah mengatakannya,
        // dan menampilkannya di setiap kartu justru membuat angka yang TIDAK
        // seratus persen tenggelam di antara belasan angka yang sama.
        if (svc.availability === null || (svc.history_samples || 0) < 3 || svc.availability >= 100) {
            node.textContent = '';
            node.removeAttribute('data-tone');
            return;
        }

        node.textContent = persen(svc.availability);

        if (svc.availability < 95) {
            node.setAttribute('data-tone', 'bad');
        } else if (svc.availability < 99.5) {
            node.setAttribute('data-tone', 'warn');
        } else {
            node.removeAttribute('data-tone');
        }
    }

    function renderSummary(summary) {
        var keys = ['good', 'slow', 'error', 'down'];
        for (var i = 0; i < keys.length; i++) {
            var node = document.querySelector('[data-summary="' + keys[i] + '"]');
            if (!node) {
                continue;
            }
            var count = summary[keys[i]] || 0;
            node.textContent = count;
            node.dataset.zero = count === 0 ? 'true' : 'false';
        }
    }

    /* ---------- Baris keadaan: ticker berjalan ---------- */

    /*
     * Ticker menampung berapa pun jumlah gangguan tanpa menambah tinggi
     * halaman: isinya berjalan di dalam satu baris. Versi sebelumnya adalah
     * daftar yang memanjang, dan panjangnya itu mendorong kartu keluar layar
     * tepat pada saat kartu paling perlu dilihat.
     *
     * Kecepatan tetap dalam piksel per detik, bukan durasi tetap. Dengan durasi
     * tetap, satu gangguan akan melesat terlalu cepat untuk dibaca dan delapan
     * gangguan akan merayap.
     */
    var TICKER_PX_PER_S = 90;

    /*
     * Tanda tangan isi ticker: layanan mana dengan status apa. Sengaja tidak
     * memuat angka durasi. Membangun ulang isi berarti memulai animasi dari
     * awal, dan kalau tanda tangannya ikut berubah tiap siklus, teks akan
     * melompat kembali ke awal setiap 30 detik dan gangguan di ujung daftar
     * tidak akan pernah terbaca. Selama daftarnya sama, yang diperbarui hanya
     * angka durasinya, di tempat.
     */
    var tickerSig = null;

    function renderTicker(incidents, summary, data) {
        if (!els.ticker || !els.tickerSeg || !els.tickerSegClone || !els.tickerRun) {
            return;
        }

        var sekarang = data.server_time || Math.floor(Date.now() / 1000);
        var i;

        if (incidents.length === 0) {
            tickerSig = 'ok';
            els.ticker.dataset.state = 'ok';
            tulisTicker(fragTenang(summary), false);
            return;
        }

        var cap = [];
        for (i = 0; i < incidents.length; i++) {
            cap.push(incidents[i].id + ':' + incidents[i].status + ':' + incidents[i].label);
        }
        var sig = cap.join('|');

        els.ticker.dataset.state = 'problem';

        if (sig === tickerSig) {
            var metas = els.ticker.querySelectorAll('.ticker__meta');
            for (i = 0; i < metas.length; i++) {
                metas[i].textContent = metaInsiden(
                    incidents[i % incidents.length], sekarang, data);
            }
            return;
        }

        tickerSig = sig;
        tulisTicker(fragGangguan(incidents, sekarang, data), true);
    }

    function fragTenang(summary) {
        var frag = document.createDocumentFragment();
        frag.appendChild(span('ticker__head', 'Semua layanan normal · ' +
            (summary.good || 0) + ' dari ' + (summary.total || 0) + ' menjawab cepat'));
        return frag;
    }

    function fragGangguan(incidents, sekarang, data) {
        var frag = document.createDocumentFragment();
        frag.appendChild(span('ticker__head', incidents.length + ' layanan perlu diperiksa'));

        for (var i = 0; i < incidents.length; i++) {
            var ins = incidents[i];
            var item = document.createElement('span');
            item.className = 'ticker__item';
            item.setAttribute('data-status', ins.status);

            item.appendChild(span('ticker__name', ins.name));
            item.appendChild(span('ticker__what',
                ins.status === 'slow' && typeof ins.latency_ms === 'number'
                    ? ins.label + ' ' + Math.round(ins.latency_ms) + ' ms'
                    : ins.label));
            // Selalu dipasang, walau kosong: jumlah simpul meta harus tetap
            // sama dengan jumlah gangguan supaya pembaruan di tempat di atas
            // tidak salah alamat.
            item.appendChild(span('ticker__meta', metaInsiden(ins, sekarang, data)));

            frag.appendChild(item);
        }

        return frag;
    }

    /**
     * Isi ticker, lalu -- kalau ia harus berjalan -- salin isinya ke segmen
     * kedua dan hitung durasi satu putaran.
     *
     * Segmen kedua yang identik itulah yang membuat putaran tidak berjeda:
     * animasi menggeser separuh lebar, jadi segmen kedua tiba persis di tempat
     * yang pertama. Saat tidak berjalan, segmen kedua dikosongkan -- kalau
     * tidak, teksnya terlihat tercetak dua kali bersebelahan.
     */
    function tulisTicker(frag, bolehJalan) {
        els.tickerSeg.textContent = '';
        els.tickerSegClone.textContent = '';
        els.tickerSeg.appendChild(frag);

        // Isi yang sudah muat seluruhnya tidak perlu digeser. Satu gangguan
        // biasanya muat, dan menggerakkannya hanya membuat teks yang sebetulnya
        // terbaca jadi mengejar-ngejar mata.
        var jarak = parseFloat(
            window.getComputedStyle(els.tickerSeg).paddingRight) || 0;
        var isi = els.tickerSeg.scrollWidth - jarak;
        var ruang = els.tickerSeg.parentNode.parentNode.clientWidth;
        var jalan = bolehJalan && isi > ruang;

        els.ticker.dataset.run = jalan ? 'true' : 'false';

        if (!jalan) {
            els.tickerRun.style.removeProperty('--ticker-speed');
            return;
        }

        var anak = els.tickerSeg.childNodes;
        for (var k = 0; k < anak.length; k++) {
            els.tickerSegClone.appendChild(anak[k].cloneNode(true));
        }

        els.tickerRun.style.setProperty('--ticker-speed',
            Math.max(12, Math.round((isi + jarak) / TICKER_PX_PER_S)) + 's');

        // Mengganti isi tidak memulai ulang animasi dengan sendirinya. Tanpa
        // paksaan ini, daftar gangguan yang baru masuk di tengah putaran lama
        // dan separuh isinya terlewat satu putaran penuh.
        els.tickerRun.style.animation = 'none';
        void els.tickerRun.offsetWidth;
        els.tickerRun.style.animation = '';
    }

    function metaInsiden(ins, sekarang, data) {
        var bagian = [];

        if (ins.since) {
            bagian.push('sejak ' + jam(ins.since) + ' (' + durasi(sekarang - ins.since) + ')');
        }

        // Layanan yang berulang kali putus-nyambung butuh perlakuan berbeda dari
        // yang mati sekali dan tetap mati, jadi hitungannya ditampilkan.
        if (ins.outages > 1) {
            bagian.push(ins.outages + '\u00d7 terganggu dalam ' +
                durasi((data.window_minutes || 0) * 60) + ' terakhir');
        }

        if (ins.detail) {
            bagian.push(ins.detail);
        }

        return bagian.join(' · ');
    }

    /* ---------- Utilitas DOM ---------- */

    function span(cls, text) {
        var node = document.createElement('span');
        node.className = cls;
        node.textContent = text;
        return node;
    }

    function setField(card, field, value) {
        var node = card.querySelector('[data-field="' + field + '"]');
        if (node) {
            node.textContent = value;
        }
    }

    function cssEscape(value) {
        return String(value).replace(/["\\]/g, '\\$&');
    }

    function showBanner(message) {
        if (!els.banner) {
            return;
        }
        els.banner.textContent = message;
        els.banner.hidden = false;
    }

    function hideBanner() {
        if (els.banner) {
            els.banner.hidden = true;
        }
    }

    /* ---------- Alarm ---------- */

    var audioCtx = null;
    var beepTimer = null;
    var alarmOn = loadAlarmPref();

    function ensureAudio() {
        try {
            if (!audioCtx) {
                var Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) {
                    return false;
                }
                audioCtx = new Ctx();
            }
            if (audioCtx.state === 'suspended') {
                audioCtx.resume();
            }
            return audioCtx.state === 'running';
        } catch (err) {
            return false;
        }
    }

    /**
     * Nada dibangkitkan di browser, bukan diunduh. File audio dari internet
     * justru gagal dimuat tepat ketika jaringan putus -- saat alarm paling perlu.
     */
    function beep() {
        if (!audioCtx || audioCtx.state !== 'running') {
            return;
        }
        var now = audioCtx.currentTime;
        var osc = audioCtx.createOscillator();
        var gain = audioCtx.createGain();

        osc.type = 'square';
        osc.frequency.setValueAtTime(880, now);
        osc.frequency.setValueAtTime(660, now + 0.16);

        gain.gain.setValueAtTime(0.0001, now);
        gain.gain.exponentialRampToValueAtTime(0.18, now + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.32);

        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.start(now);
        osc.stop(now + 0.34);
    }

    function updateAlarm() {
        var berbunyi = alarmOn && anyDown;

        if (berbunyi && !beepTimer) {
            if (ensureAudio()) {
                beep();
                beepTimer = window.setInterval(beep, 1400);
            }
        } else if (!berbunyi && beepTimer) {
            window.clearInterval(beepTimer);
            beepTimer = null;
        }

        updateAlarmLabel();
    }

    function updateAlarmLabel() {
        if (!els.alarmBtn || !els.alarmLabel) {
            return;
        }
        els.alarmBtn.setAttribute('aria-pressed', alarmOn ? 'true' : 'false');

        var teks;
        if (!alarmOn) {
            teks = 'Alarm mati';
        } else if (audioCtx && audioCtx.state === 'running') {
            teks = anyDown ? 'Alarm berbunyi' : 'Alarm aktif';
        } else {
            teks = 'Izinkan suara';
        }

        els.alarmLabel.textContent = teks;
        // Di mode TV labelnya disembunyikan dan hanya ikonnya yang tampak,
        // jadi keterangannya dipindahkan ke tooltip.
        els.alarmBtn.title = teks + ' — tekan A untuk mengubah';
    }

    /**
     * Nyalakan / matikan alarm.
     *
     * Browser hanya mengizinkan audio setelah ada interaksi pengguna, jadi
     * konteks audio dibuka di dalam sini -- bukan saat halaman dimuat.
     */
    function toggleAlarm() {
        if (!alarmOn) {
            alarmOn = true;
            saveAlarmPref(true);
            if (ensureAudio()) {
                beep();
            }
        } else if (!audioCtx || audioCtx.state !== 'running') {
            // Preferensi sudah menyala dari kunjungan sebelumnya, tapi suara
            // belum diizinkan browser. Interaksi inilah yang membukanya.
            if (ensureAudio()) {
                beep();
            }
        } else {
            alarmOn = false;
            saveAlarmPref(false);
        }
        updateAlarm();
    }

    if (els.alarmBtn) {
        els.alarmBtn.addEventListener('click', toggleAlarm);
    }

    /*
     * Tombol "A". Mode TV menyembunyikan kontrol karena layar itu tidak punya
     * mouse -- tapi kebijakan autoplay browser tetap menolak membunyikan apa
     * pun sebelum ada gestur pengguna. Penekanan tombol adalah gestur yang sah,
     * jadi ini satu-satunya cara alarm bisa diaktifkan di TV.
     */
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'a' && ev.key !== 'A') {
            return;
        }
        if (ev.ctrlKey || ev.altKey || ev.metaKey) {
            return;
        }
        var fokus = ev.target;
        if (fokus && (fokus.tagName === 'INPUT' || fokus.tagName === 'TEXTAREA' ||
                fokus.isContentEditable)) {
            return;
        }
        ev.preventDefault();
        toggleAlarm();
    });

    /* ---------- Siklus pengecekan ---------- */

    function setBusy(busy) {
        if (els.refreshBtn) {
            els.refreshBtn.disabled = busy;
        }
        if (els.sweep) {
            els.sweep.classList.toggle('sweep--busy', busy);
        }
    }

    function paintCountdown() {
        if (els.countdown) {
            els.countdown.textContent = remaining + 's';
        }
        if (els.sweepFill) {
            els.sweepFill.style.transform = 'scaleX(' + (remaining / REFRESH).toFixed(3) + ')';
        }
    }

    function check() {
        if (inFlight) {
            return;
        }
        inFlight = true;
        setBusy(true);

        var controller = null;
        var timer = null;
        if (window.AbortController) {
            controller = new AbortController();
            timer = window.setTimeout(function () {
                controller.abort();
            }, 20000);
        }

        var options = { cache: 'no-store' };
        if (controller) {
            options.signal = controller.signal;
        }

        window.fetch('api.php', options)
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || data.error) {
                        throw new Error(data.error || ('Server membalas HTTP ' + response.status));
                    }
                    return data;
                });
            })
            .then(function (data) {
                render(data);
            })
            .catch(function (err) {
                // Kegagalan di sini berarti dashboard-nya sendiri tidak terjangkau
                // (Apache mati, PHP error, komputer ini offline) -- bukan layanan
                // yang dipantau. Bedakan pesannya supaya tidak menyesatkan.
                showBanner('Gagal menghubungi server pemantau: ' + err.message +
                    '. Angka di bawah adalah hasil pengecekan terakhir yang berhasil.');
            })
            .then(function () {
                if (timer) {
                    window.clearTimeout(timer);
                }
                inFlight = false;
                setBusy(false);
                remaining = REFRESH;
                paintCountdown();
            });
    }

    function tick() {
        // Tab tersembunyi: hentikan hitung mundur, jangan bebani jaringan RS
        // untuk layar yang tidak dilihat siapa pun.
        if (document.hidden || inFlight) {
            return;
        }

        remaining -= 1;
        if (remaining <= 0) {
            check();
            return;
        }
        paintCountdown();
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            // Data yang tampil sudah basi selama tab tidak aktif.
            check();
        }
    });

    if (els.refreshBtn) {
        els.refreshBtn.addEventListener('click', check);
    }

    updateAlarmLabel();
    paintCountdown();
    window.setInterval(tick, 1000);
    check();
}());

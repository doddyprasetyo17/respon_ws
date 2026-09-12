/*
 * Respon WS -- pengambilan data dan penggambaran trace.
 *
 * Halaman tidak pernah reload sendiri. Kartu sudah dirender server dari
 * config; skrip ini hanya memperbarui isinya dari api.php.
 */

(function () {
    'use strict';

    var board = document.getElementById('board');
    if (!board) {
        return;
    }

    var REFRESH = Math.max(5, parseInt(board.dataset.refresh, 10) || 30);
    var HISTORY_LIMIT = 24;
    var HISTORY_KEY = 'responws.history.v1';
    var ALARM_KEY = 'responws.alarm.v1';

    var els = {
        banner: document.getElementById('banner'),
        cycleNote: document.getElementById('cycleNote'),
        cycleDuration: document.getElementById('cycleDuration'),
        countdown: document.getElementById('countdown'),
        refreshBtn: document.getElementById('refreshBtn'),
        alarmBtn: document.getElementById('alarmBtn'),
        alarmLabel: document.getElementById('alarmLabel'),
        sweep: document.getElementById('sweep'),
        sweepFill: document.getElementById('sweepFill')
    };

    var history = loadHistory();
    var remaining = REFRESH;
    var inFlight = false;
    var anyDown = false;

    /* ---------- Penyimpanan lokal (selalu opsional) ---------- */

    function loadHistory() {
        try {
            var raw = window.localStorage.getItem(HISTORY_KEY);
            var parsed = raw ? JSON.parse(raw) : null;
            return (parsed && typeof parsed === 'object') ? parsed : {};
        } catch (err) {
            // Mode penyamaran, penyimpanan diblokir, atau data rusak.
            return {};
        }
    }

    function saveHistory() {
        try {
            window.localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
        } catch (err) {
            /* riwayat hanya pemanis; abaikan kalau tidak bisa disimpan */
        }
    }

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
            /* abaikan */
        }
    }

    /* ---------- Trace ---------- */

    /**
     * Ubah riwayat latency jadi titik-titik polyline pada viewBox 200x40.
     * Nilai null (layanan tidak menjawab) digambar rata di tengah, sehingga
     * layanan mati terbaca sebagai garis datar.
     */
    function buildTrace(samples) {
        var flat = '0,20 200,20';
        if (!samples || samples.length === 0) {
            return flat;
        }

        var max = 100;
        for (var i = 0; i < samples.length; i++) {
            if (typeof samples[i] === 'number' && samples[i] > max) {
                max = samples[i];
            }
        }

        function y(value) {
            if (typeof value !== 'number') {
                return 20;
            }
            return 37 - Math.min(1, value / max) * 33;
        }

        if (samples.length === 1) {
            var only = y(samples[0]).toFixed(1);
            return '0,' + only + ' 200,' + only;
        }

        var step = 200 / (samples.length - 1);
        var points = [];
        for (var j = 0; j < samples.length; j++) {
            points.push((j * step).toFixed(1) + ',' + y(samples[j]).toFixed(1));
        }
        return points.join(' ');
    }

    /* ---------- Render ---------- */

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

            var samples = history[svc.id] || [];
            samples.push(typeof svc.latency_ms === 'number' ? svc.latency_ms : null);
            if (samples.length > HISTORY_LIMIT) {
                samples = samples.slice(-HISTORY_LIMIT);
            }
            history[svc.id] = samples;

            setField(card, 'latency', typeof svc.latency_ms === 'number' ? Math.round(svc.latency_ms) : '–––');
            setField(card, 'label', svc.label);
            setField(card, 'code', svc.http_code ? String(svc.http_code) : '');

            var trace = card.querySelector('[data-field="trace"]');
            if (trace) {
                trace.setAttribute('points', buildTrace(samples));
            }

            card.title = svc.name + ' — ' + svc.label +
                (svc.detail ? ' (' + svc.detail + ')' : '') +
                (typeof svc.connect_ms === 'number' ? '\nWaktu connect: ' + Math.round(svc.connect_ms) + ' ms' : '');
        }

        saveHistory();
        renderSummary(data.summary || {});

        if (els.cycleNote) {
            els.cycleNote.textContent = data.checked_at_human || '--:--:--';
        }
        if (els.cycleDuration) {
            els.cycleDuration.textContent = 'Siklus ' + (data.duration_ms || 0) + ' ms untuk ' +
                services.length + ' layanan';
        }

        updateAlarm();
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

    function setField(card, field, value) {
        var node = card.querySelector('[data-field="' + field + '"]');
        if (node) {
            node.textContent = value;
        }
    }

    function cssEscape(value) {
        return String(value).replace(/["\\]/g, '\\$&');
    }

    /* ---------- Banner ---------- */

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
        var shouldSound = alarmOn && anyDown;

        if (shouldSound && !beepTimer) {
            if (ensureAudio()) {
                beep();
                beepTimer = window.setInterval(beep, 1400);
            }
        } else if (!shouldSound && beepTimer) {
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

        if (!alarmOn) {
            els.alarmLabel.textContent = 'Alarm mati';
        } else if (audioCtx && audioCtx.state === 'running') {
            els.alarmLabel.textContent = anyDown ? 'Alarm berbunyi' : 'Alarm aktif';
        } else {
            els.alarmLabel.textContent = 'Izinkan suara';
        }
    }

    if (els.alarmBtn) {
        els.alarmBtn.addEventListener('click', function () {
            // Browser hanya mengizinkan audio setelah interaksi; klik ini adalah
            // interaksi tersebut, jadi konteks audio dibuka di sini.
            if (!alarmOn) {
                alarmOn = true;
                saveAlarmPref(true);
                if (ensureAudio()) {
                    beep();
                }
            } else if (!audioCtx || audioCtx.state !== 'running') {
                // Preferensi sudah menyala dari kunjungan sebelumnya, tapi suara
                // belum diizinkan browser. Klik ini yang membukanya.
                if (ensureAudio()) {
                    beep();
                }
            } else {
                alarmOn = false;
                saveAlarmPref(false);
            }
            updateAlarm();
        });
    }

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
                hideBanner();
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

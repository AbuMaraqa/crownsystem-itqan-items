/**
 * الإشعارات الحية: الاشتراك في قناة المستخدم الخاصة عبر Reverb، وعرض تنبيه منبثق مع صوت،
 * وتحديث جرس الإشعارات وعنوان الصفحة، وإشعار سطح المكتب عندما تكون الصفحة في الخلفية.
 *
 * الإعدادات تأتي من window.CrownNotifications التي يطبعها القالب الرئيسي.
 */

const config = window.CrownNotifications || null;
const STORAGE_SOUND = 'crown.notifications.sound';
const STORAGE_CLAIM = 'crown.notifications.claim.';
const TOAST_LIFETIME = 7000;
const MAX_TOASTS = 4;

const safeStorage = {
    get(key) {
        try { return window.localStorage.getItem(key); } catch { return null; }
    },
    set(key, value) {
        try { window.localStorage.setItem(key, value); } catch { /* التخزين غير متاح */ }
    },
    remove(key) {
        try { window.localStorage.removeItem(key); } catch { /* التخزين غير متاح */ }
    },
};

/* ------------------------------------------------------------------ */
/* الصوت: نغمة مُركّبة عبر Web Audio فلا حاجة لملف صوتي                */
/* ------------------------------------------------------------------ */

const sound = {
    ctx: null,

    context() {
        if (!this.ctx) {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return null;
            this.ctx = new Ctx();
        }
        return this.ctx;
    },

    // المتصفحات تمنع تشغيل الصوت قبل أول تفاعل من المستخدم، لذا نفتح السياق عند أول نقرة.
    unlock() {
        const ctx = this.context();
        if (ctx && ctx.state === 'suspended') ctx.resume().catch(() => {});
    },

    tone(ctx, frequency, start, duration, peak) {
        const osc = ctx.createOscillator();
        const overtone = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = 'sine';
        overtone.type = 'triangle';
        osc.frequency.setValueAtTime(frequency, start);
        overtone.frequency.setValueAtTime(frequency * 2, start);

        const overtoneGain = ctx.createGain();
        overtoneGain.gain.value = 0.12;

        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(peak, start + 0.015);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);

        osc.connect(gain);
        overtone.connect(overtoneGain).connect(gain);
        gain.connect(ctx.destination);

        osc.start(start);
        overtone.start(start);
        osc.stop(start + duration + 0.05);
        overtone.stop(start + duration + 0.05);
    },

    play(level = 'info') {
        if (!store().soundEnabled) return;
        const ctx = this.context();
        if (!ctx) return;
        if (ctx.state === 'suspended') ctx.resume().catch(() => {});

        const t = ctx.currentTime + 0.02;
        const melodies = {
            info: [[880, 0, 0.35], [1318.5, 0.11, 0.5]],
            success: [[783.99, 0, 0.3], [1046.5, 0.1, 0.3], [1567.98, 0.2, 0.55]],
            warning: [[987.77, 0, 0.28], [987.77, 0.18, 0.4]],
            danger: [[1174.66, 0, 0.22], [880, 0.14, 0.22], [1174.66, 0.28, 0.45]],
        };

        (melodies[level] || melodies.info).forEach(([freq, offset, duration]) => {
            this.tone(ctx, freq, t + offset, duration, 0.18);
        });
    },
};

/* ------------------------------------------------------------------ */
/* حالة مشتركة عبر Alpine store                                        */
/* ------------------------------------------------------------------ */

function store() {
    // livewire:init قد يسبق تسجيل المخزن في alpine:init، فيعود Alpine.store بـ undefined.
    return (window.Alpine && window.Alpine.store('notifications')) || fallbackStore;
}

const fallbackStore = { soundEnabled: true, connected: false, unread: 0, toasts: [] };

function registerStore() {
    window.Alpine.store('notifications', {
        enabled: !!config,
        connected: false,
        unread: 0,
        soundEnabled: safeStorage.get(STORAGE_SOUND) !== 'off',
        desktopPermission: 'Notification' in window ? Notification.permission : 'unsupported',
        desktopSupported: 'Notification' in window && window.isSecureContext,
        toasts: [],

        toggleSound() {
            this.soundEnabled = !this.soundEnabled;
            safeStorage.set(STORAGE_SOUND, this.soundEnabled ? 'on' : 'off');
            if (this.soundEnabled) {
                sound.unlock();
                sound.play('info');
            }
        },

        async requestDesktop() {
            if (!this.desktopSupported) return;
            this.desktopPermission = await Notification.requestPermission();
        },

        setUnread(count) {
            this.unread = Number(count) || 0;
        },

        dismiss(id) {
            const toast = this.toasts.find((item) => item.id === id);
            if (!toast || toast.leaving) return;
            toast.leaving = true;
            setTimeout(() => {
                this.toasts = this.toasts.filter((item) => item.id !== id);
            }, 220);
        },

        // يعلَّم الإشعار مقروءاً على الخادم ثم يُفتح رابطه.
        open(toast) {
            this.dismiss(toast.id);
            window.Livewire?.dispatch('notification-open', { id: toast.notificationId });
        },

        pause(toast, paused) {
            const current = this.toasts.find((item) => item.id === toast.id);
            if (current) current.paused = paused;
        },
    });

    // عنوان الصفحة يعكس عدد الإشعارات غير المقروءة.
    const baseTitle = document.title.replace(/^\(\d+\+?\)\s*/, '');
    window.Alpine.effect(() => {
        const unread = window.Alpine.store('notifications').unread;
        document.title = unread > 0 ? `(${unread > 99 ? '99+' : unread}) ${baseTitle}` : baseTitle;
    });
}

/* ------------------------------------------------------------------ */
/* استقبال الإشعار                                                     */
/* ------------------------------------------------------------------ */

const seen = new Set();

// عند فتح أكثر من تبويب يُشغَّل الصوت وإشعار سطح المكتب مرة واحدة فقط.
function claim(id) {
    const key = STORAGE_CLAIM + id;
    if (safeStorage.get(key)) return false;
    safeStorage.set(key, String(Date.now()));
    setTimeout(() => safeStorage.remove(key), 15000);
    return true;
}

function safeUrl(url) {
    if (!url || url === '#') return null;
    try {
        const parsed = new URL(url, window.location.origin);
        return ['http:', 'https:'].includes(parsed.protocol) ? parsed.href : null;
    } catch {
        return null;
    }
}

// NotificationService يرسل اللون (primary/success/warning/danger)، ومنه نحدد نغمة الصوت وشكل التنبيه.
function levelOf(payload) {
    const value = String(payload.level || payload.color || '').replace(/^(text|bg)-/, '');
    if (value === 'error') return 'danger';
    return ['success', 'warning', 'danger'].includes(value) ? value : 'info';
}

function normalize(payload) {
    return {
        id: `${payload.id}-${Date.now()}`,
        notificationId: payload.id,
        title: payload.title || '',
        body: payload.message || payload.body || '',
        level: levelOf(payload),
        url: safeUrl(payload.url),
        sender: payload.sender_name || null,
        progress: 100,
        paused: false,
        leaving: false,
    };
}

function showToast(toast) {
    const s = store();
    s.toasts = [toast, ...s.toasts].slice(0, MAX_TOASTS);

    let remaining = TOAST_LIFETIME;
    let last = performance.now();

    const tick = (now) => {
        const current = s.toasts.find((item) => item.id === toast.id);
        if (!current || current.leaving) return;

        if (!current.paused) remaining -= now - last;
        last = now;
        current.progress = Math.max(0, (remaining / TOAST_LIFETIME) * 100);

        if (remaining <= 0) {
            s.dismiss(toast.id);
            return;
        }
        requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
}

function showDesktop(toast) {
    const s = store();
    if (!s.desktopSupported || Notification.permission !== 'granted') return;

    const desktop = new Notification(toast.title, {
        body: toast.body,
        icon: config.icon || undefined,
        tag: toast.notificationId,
        silent: true,
    });

    desktop.onclick = () => {
        window.focus();
        s.open(toast);
        desktop.close();
    };
}

function handle(payload) {
    if (!payload?.id || seen.has(payload.id)) return;
    seen.add(payload.id);

    const toast = normalize(payload);
    showToast(toast);

    window.Livewire?.dispatch('notification-received', { id: payload.id });
    window.dispatchEvent(new CustomEvent('crown-notification', { detail: toast }));

    const foreground = document.visibilityState === 'visible' && document.hasFocus();

    // التبويب الظاهر يأخذ الأولوية في تشغيل الصوت، والتبويبات الخلفية تنتظر قليلاً.
    setTimeout(() => {
        if (!claim(payload.id)) return;
        sound.play(toast.level);
        if (!foreground) showDesktop(toast);
    }, foreground ? 0 : 300);
}

/* ------------------------------------------------------------------ */
/* الاتصال بـ Reverb                                                   */
/* ------------------------------------------------------------------ */

function connect() {
    if (!config?.channel || !window.Echo) return;

    window.Echo.private(config.channel).notification(handle);

    const connection = window.Echo.connector?.pusher?.connection;
    if (connection) {
        const update = () => { store().connected = connection.state === 'connected'; };
        connection.bind('state_change', update);
        update();
    }
}

if (config) {
    document.addEventListener('alpine:init', registerStore);
    document.addEventListener('livewire:init', connect);

    ['pointerdown', 'keydown'].forEach((type) => {
        window.addEventListener(type, () => sound.unlock(), { once: true, capture: true });
    });
}

window.CrownSound = sound;

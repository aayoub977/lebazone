<template>
    <!-- Lebazone: bottom-right chat popups + a mini chat window you can reply from, on every page. -->
    <div class="lbz-chat-toasts" :class="{ 'win-open': !!win }" aria-live="polite">
        <transition-group name="lbz-ct">
            <div v-for="t in toasts" :key="t.key" class="lbz-ct" role="button" tabindex="0"
                @click="open(t)" @keydown.enter="open(t)" @mouseenter="hold(t)" @mouseleave="release(t)">
                <div class="lbz-ct-avatar">
                    <img v-if="t.avatar" :src="t.avatar" alt="" />
                    <template v-else>{{ initials(t.sender) }}</template>
                </div>
                <div class="lbz-ct-body">
                    <div class="lbz-ct-top">
                        <strong class="lbz-ct-name">{{ t.sender }}</strong>
                        <span v-if="t.more > 1" class="lbz-ct-more">{{ t.more }}</span>
                        <small class="lbz-ct-time">{{ t.time }}</small>
                    </div>
                    <div v-if="t.sub" class="lbz-ct-sub">{{ t.sub }}</div>
                    <div class="lbz-ct-text">{{ t.text }}</div>
                    <div class="lbz-ct-hint">Tap to reply</div>
                </div>
                <button type="button" class="lbz-ct-x" aria-label="Close" @click.stop="dismiss(t)">×</button>
            </div>
        </transition-group>
    </div>

    <transition name="lbz-cw">
        <div v-if="win" class="lbz-cw" :style="winStyle" role="dialog">
            <div class="lbz-cw-head">
                <div class="lbz-ct-avatar sm">
                    <img v-if="win.avatar" :src="win.avatar" alt="" />
                    <template v-else>{{ initials(win.title) }}</template>
                </div>
                <div class="lbz-cw-title">
                    <strong>{{ win.title }}</strong>
                    <small v-if="win.sub">{{ win.sub }}</small>
                </div>
                <button type="button" class="lbz-cw-btn" title="Open full chat" @click="openFull">↗</button>
                <button type="button" class="lbz-cw-btn" title="Close" @click="closeWin">×</button>
            </div>
            <div ref="body" class="lbz-cw-body">
                <div v-if="win.loading" class="lbz-cw-empty">Loading…</div>
                <div v-else-if="!win.messages.length" class="lbz-cw-empty">No messages yet</div>
                <div v-for="m in win.messages" :key="m.id" class="lbz-cw-row" :class="{ mine: m.sender_type === myType }">
                    <div class="lbz-cw-bubble">
                        <a v-if="m.attachment_type === 'image'" :href="m.attachment_url" target="_blank">
                            <img :src="m.attachment_url" class="lbz-cw-img" alt="" @load="scrollBottom" />
                        </a>
                        <audio v-else-if="m.attachment_type === 'audio'" controls :src="m.attachment_url" class="lbz-cw-audio"></audio>
                        <video v-else-if="m.attachment_type === 'video'" controls :src="m.attachment_url" class="lbz-cw-img"></video>
                        <a v-else-if="m.attachment_url" :href="m.attachment_url" target="_blank">📎 File</a>
                        <div v-if="m.message" class="lbz-cw-text">{{ m.message }}</div>
                        <small class="lbz-cw-time">{{ clock(m.created_at) }}</small>
                    </div>
                </div>
            </div>
            <form v-if="!win.readOnly" class="lbz-cw-foot" @submit.prevent="send">
                <label class="lbz-cw-btn attach" title="Photo">
                    📷<input type="file" accept="image/*" multiple hidden @change="onPick" />
                    <span v-if="files.length" class="lbz-ct-more">{{ files.length }}</span>
                </label>
                <textarea ref="draft" v-model="draft" rows="1" placeholder="Type a reply…"
                    @keydown.enter.exact.prevent="send"></textarea>
                <button type="submit" class="lbz-cw-send" :disabled="sending || (!draft.trim() && !files.length)">➤</button>
            </form>
            <div v-else class="lbz-cw-foot ro">You can only watch this chat</div>
        </div>
    </transition>
</template>

<script>
import axios from 'axios';

const FAST = 3000;     // tab visible: check every 3 s
const SLOW = 15000;    // tab in background
const SHOW_MS = 8000;  // how long a popup stays

export default {
    name: 'ChatToaster',
    props: {
        api: { type: String, required: true },        // .../chat  (poll, messages, send, mark_read)
        chatPath: { type: String, required: true },   // full chat page: /chat or /delivery_boy/chat
        myType: { type: String, default: 'admin' },   // sender_type of this panel's user
        enabled: { type: Boolean, default: true },
    },
    emits: ['count'],
    data() {
        return { toasts: [], lastId: 0, unseen: 0, win: null, draft: '', files: [], sending: false, winStyle: null };
    },
    mounted() {
        this._title = document.title;
        this._onVis = () => {
            if (!document.hidden) { this.unseen = 0; this.setTitle(); }
            this.pollNow();
        };
        document.addEventListener('visibilitychange', this._onVis);
        this._onPollNow = () => this.pollNow();
        window.addEventListener('chat:poll-now', this._onPollNow);
        // Anything in the panel can open a chat in the mini window: dispatch chat:open-mini {conversation_id}.
        this._onOpenMini = (e) => e && e.detail && this.openWin({ conversationId: e.detail.conversation_id, title: e.detail.title || '' });
        window.addEventListener('chat:open-mini', this._onOpenMini);
        if (this.enabled) this.pollNow(true);
    },
    beforeUnmount() {
        this.stopped = true;
        clearTimeout(this._timer);
        document.removeEventListener('visibilitychange', this._onVis);
        window.removeEventListener('chat:poll-now', this._onPollNow);
        window.removeEventListener('chat:open-mini', this._onOpenMini);
        this.toasts.forEach(t => clearTimeout(t.timer));
        this.fitStop();
        this.unseen = 0; this.setTitle();
    },
    watch: {
        enabled(v) { if (v) this.pollNow(true); },
        // The full chat page took over — close the mini window for the same thread.
        '$route.path'(p) { if (this.win && p.startsWith(this.chatPath)) this.win = null; },
        // Phones: the window fills exactly the visible screen (above the browser bar and keyboard).
        win(v, old) {
            if (!!v === !!old) return;
            if (v) this.fitStart(); else this.fitStop();
        },
    },
    methods: {
        schedule() {
            clearTimeout(this._timer);
            if (this.stopped || !this.enabled) return;
            this._timer = setTimeout(() => this.pollNow(), document.hidden ? SLOW : FAST);
        },
        pollNow(withCount = false) {
            if (this.stopped || !this.enabled || !localStorage.getItem('token')) return;
            if (this._busy) { this._again = true; return; }
            this._busy = true;
            axios.get(this.api + '/poll', { params: { after_id: this.lastId, with_count: withCount ? 1 : 0 } })
                .then(res => {
                    const d = (res.data && res.data.data) || {};
                    const first = !this.lastId;
                    if (d.last_id) this.lastId = Math.max(this.lastId, Number(d.last_id));
                    if (d.count !== null && d.count !== undefined) this.$emit('count', Number(d.count) || 0);
                    const items = Array.isArray(d.items) ? d.items : [];
                    if (!first && items.length) this.handle(items);
                })
                .catch(() => {})
                .finally(() => {
                    this._busy = false;
                    if (this._again) { this._again = false; this.pollNow(); } else this.schedule();
                });
        },
        handle(items) {
            const ids = [...new Set(items.map(i => i.conversation_id))];
            window.dispatchEvent(new CustomEvent('chat:incoming', { detail: { conversation_ids: ids, items } }));

            const watching = String(window.activeChatConversationId || '');
            const onChat = this.$route && this.$route.path.startsWith(this.chatPath);
            let popped = 0, intoWin = false;
            items.forEach(i => {
                // Open in the mini window → just add it there.
                if (this.win && this.win.conversationId === i.conversation_id) {
                    if (i.message && !this.win.messages.find(m => m.id === i.message.id)) {
                        this.win.messages.push(i.message);
                        intoWin = intoWin || !i.from_staff;
                    }
                    return;
                }
                if (i.observer || i.from_staff) return;
                if (!document.hidden && onChat && String(i.conversation_id) === watching) return;
                this.push(i);
                popped++;
            });
            if (intoWin) { this.$nextTick(this.scrollBottom); this.markRead(); }
            if (popped || (intoWin && document.hidden)) {
                this.beep();
                if (document.hidden) { this.unseen += popped || 1; this.setTitle(); }
            }
        },
        push(i) {
            const m = i.message || {};
            const text = m.message || this.attachmentLabel(m.attachment_type);
            const sub = [i.title !== i.sender_name ? i.title : '', i.order_number || ''].filter(Boolean).join(' · ');
            const old = this.toasts.find(t => t.conversationId === i.conversation_id);
            if (old) {
                old.text = text; old.time = this.clock(m.created_at); old.more++; old.sender = i.sender_name;
                this.restart(old);
                return;
            }
            const t = {
                key: 'c' + i.conversation_id + '-' + m.id,
                conversationId: i.conversation_id,
                sender: i.sender_name || i.title || '',
                title: i.title, avatar: i.avatar, sub,
                readOnly: !!i.observer,
                text, time: this.clock(m.created_at), more: 1, timer: null,
            };
            this.toasts.push(t);
            while (this.toasts.length > 4) { const x = this.toasts.shift(); clearTimeout(x.timer); }
            this.restart(t);
        },
        restart(t) { clearTimeout(t.timer); t.timer = setTimeout(() => this.dismiss(t), SHOW_MS); },
        hold(t) { clearTimeout(t.timer); },
        release(t) { this.restart(t); },
        dismiss(t) { clearTimeout(t.timer); this.toasts = this.toasts.filter(x => x !== t); },

        // ---- mini chat window ----
        open(t) {
            this.dismiss(t);
            this.openWin({ conversationId: t.conversationId, title: t.title || t.sender, sub: t.sub, avatar: t.avatar, readOnly: t.readOnly });
        },
        openWin({ conversationId, title, sub = '', avatar = null, readOnly = false }) {
            if (!conversationId) return;
            this.toasts.filter(x => x.conversationId === conversationId).forEach(x => this.dismiss(x));
            this.win = { conversationId, title, sub, avatar, readOnly, messages: [], loading: true };
            this.draft = ''; this.files = [];
            axios.get(this.api + '/messages', { params: { conversation_id: conversationId, limit: 30, offset: 0 } })
                .then(res => {
                    if (!this.win || this.win.conversationId !== conversationId) return;
                    const d = (res.data && res.data.data) || {};
                    this.win.messages = d.messages || [];
                    const c = d.conversation || {};
                    if (c.title) this.win.title = c.title;
                    if (c.avatar) this.win.avatar = c.avatar;
                    if (c.type === 'delivery_boy_customer' && this.myType === 'admin') this.win.readOnly = true;
                    if (!this.win.sub && c.order_number) this.win.sub = c.order_number;
                    window.dispatchEvent(new Event('chat:refresh-unread'));
                    this.pollNow(true);
                })
                .catch(() => {})
                .finally(() => {
                    if (this.win) this.win.loading = false;
                    this.$nextTick(() => { this.scrollBottom(); this.$refs.draft && this.$refs.draft.focus(); });
                });
        },
        closeWin() { this.win = null; },
        isPhone() { return window.innerWidth <= 576; },
        fit() {
            if (!this.win || !this.isPhone()) { this.winStyle = null; return; }
            const vv = window.visualViewport;
            const h = vv ? vv.height : window.innerHeight;
            const top = vv ? vv.offsetTop : 0;
            this.winStyle = { top: top + 'px', height: h + 'px' };
            this.$nextTick(this.scrollBottom);
        },
        fitStart() {
            this._fit = () => this.fit();
            const vv = window.visualViewport;
            if (vv) { vv.addEventListener('resize', this._fit); vv.addEventListener('scroll', this._fit); }
            window.addEventListener('resize', this._fit);
            if (this.isPhone()) document.body.classList.add('lbz-cw-lock');
            this.fit();
        },
        fitStop() {
            const vv = window.visualViewport;
            if (this._fit) {
                if (vv) { vv.removeEventListener('resize', this._fit); vv.removeEventListener('scroll', this._fit); }
                window.removeEventListener('resize', this._fit);
            }
            document.body.classList.remove('lbz-cw-lock');
            this.winStyle = null;
        },
        openFull() {
            const id = this.win && this.win.conversationId;
            this.win = null;
            this.$router.push({ path: this.chatPath, query: { open: id, t: Date.now() } });
        },
        markRead() {
            if (!this.win || this.win.readOnly || document.hidden) return;
            axios.post(this.api + '/mark_read', { conversation_id: this.win.conversationId })
                .then(() => window.dispatchEvent(new Event('chat:refresh-unread'))).catch(() => {});
        },
        onPick(e) {
            this.files.push(...Array.from(e.target.files || []));
            e.target.value = '';
        },
        send() {
            if (!this.win || this.sending) return;
            const text = this.draft.trim();
            if (!text && !this.files.length) return;
            const id = this.win.conversationId;
            const fd = new FormData();
            fd.append('conversation_id', id);
            fd.append('message', text);
            this.files.forEach(f => fd.append('images[]', f));
            this.sending = true;
            const keep = { text: this.draft, files: this.files };
            this.draft = ''; this.files = [];
            axios.post(this.api + '/send', fd)
                .then(res => {
                    if (!res.data || res.data.status === 0) throw new Error((res.data && res.data.message) || 'Could not send');
                    const arr = Array.isArray(res.data.data) ? res.data.data : (res.data.data ? [res.data.data] : []);
                    if (this.win && this.win.conversationId === id) {
                        arr.forEach(m => { if (!this.win.messages.find(x => x.id === m.id)) this.win.messages.push(m); });
                        this.$nextTick(this.scrollBottom);
                    }
                    window.dispatchEvent(new CustomEvent('chat:incoming', { detail: { conversation_ids: [id], items: [] } }));
                })
                .catch(err => {
                    this.draft = keep.text; this.files = keep.files;
                    const msg = (err && err.response && err.response.data && err.response.data.message) || err.message;
                    if (this.showError) this.showError(msg); else alert(msg);
                })
                .finally(() => { this.sending = false; });
        },
        scrollBottom() { const el = this.$refs.body; if (el) el.scrollTop = el.scrollHeight; },

        attachmentLabel(type) {
            return ({ image: '📷 Photo', audio: '🎤 Voice message', video: '🎥 Video' })[type] || '📎 File';
        },
        clock(s) {
            const d = new Date(String(s || '').replace(' ', 'T'));
            return isNaN(d) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        },
        initials(n) {
            return String(n || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase();
        },
        setTitle() {
            const base = (this._title || document.title).replace(/^\(\d+\)\s*/, '');
            this._title = base;
            document.title = this.unseen ? '(' + this.unseen + ') ' + base : base;
        },
        beep() {
            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                this._ctx = this._ctx || new Ctx();
                const ctx = this._ctx;
                if (ctx.state === 'suspended') ctx.resume();
                [[880, 0], [1320, 0.12]].forEach(([f, at]) => {
                    const o = ctx.createOscillator(), g = ctx.createGain();
                    o.type = 'sine'; o.frequency.value = f;
                    g.gain.setValueAtTime(0.0001, ctx.currentTime + at);
                    g.gain.exponentialRampToValueAtTime(0.18, ctx.currentTime + at + 0.02);
                    g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + at + 0.22);
                    o.connect(g); g.connect(ctx.destination);
                    o.start(ctx.currentTime + at); o.stop(ctx.currentTime + at + 0.25);
                });
            } catch (e) { /* sound is optional */ }
        },
    },
};
</script>

<style>
.lbz-chat-toasts { position: fixed; right: 20px; bottom: 20px; z-index: 2100; display: flex; flex-direction: column; gap: 10px; width: 340px; max-width: calc(100vw - 32px); pointer-events: none; }
.lbz-chat-toasts.win-open { bottom: 540px; }
.lbz-ct { pointer-events: auto; display: flex; gap: 10px; align-items: flex-start; padding: 12px 34px 12px 12px; position: relative; cursor: pointer;
    background: var(--bs-body-bg, #fff); color: var(--bs-body-color, #212529); border: 1px solid rgba(127, 127, 127, .22);
    border-left: 4px solid var(--bs-primary, #435ebe); border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, .18); }
body.theme-dark .lbz-ct, body.theme-dark .lbz-cw { background: #1e1e2d; color: #e6e6ef; border-color: #34344a; }
body.theme-dark .lbz-ct { border-left-color: var(--bs-primary, #435ebe); }
.lbz-ct:hover { transform: translateY(-1px); box-shadow: 0 14px 34px rgba(0, 0, 0, .24); }
.lbz-ct-avatar { flex: 0 0 40px; width: 40px; height: 40px; border-radius: 50%; overflow: hidden; display: flex; align-items: center; justify-content: center;
    background: var(--bs-primary, #435ebe); color: #fff; font-weight: 700; font-size: 14px; }
.lbz-ct-avatar.sm { flex-basis: 34px; width: 34px; height: 34px; font-size: 12px; background: rgba(255, 255, 255, .25); }
.lbz-ct-avatar img { width: 100%; height: 100%; object-fit: cover; }
.lbz-ct-body { min-width: 0; flex: 1; }
.lbz-ct-top { display: flex; align-items: center; gap: 6px; }
.lbz-ct-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 14px; }
.lbz-ct-more { background: #dc3545; color: #fff; border-radius: 10px; font-size: 11px; padding: 0 6px; line-height: 17px; }
.lbz-ct-time { margin-left: auto; opacity: .6; font-size: 11px; white-space: nowrap; }
.lbz-ct-sub { font-size: 11.5px; opacity: .65; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lbz-ct-text { font-size: 13px; margin-top: 2px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; word-break: break-word; }
.lbz-ct-hint { font-size: 11px; margin-top: 4px; color: var(--bs-primary, #435ebe); font-weight: 600; }
.lbz-ct-x { position: absolute; top: 6px; right: 8px; border: 0; background: none; color: inherit; opacity: .5; font-size: 20px; line-height: 1; padding: 2px 4px; }
.lbz-ct-x:hover { opacity: 1; }
.lbz-ct-enter-active, .lbz-ct-leave-active { transition: all .25s ease; }
.lbz-ct-enter-from, .lbz-ct-leave-to { opacity: 0; transform: translateX(40px); }

/* mini chat window */
.lbz-cw { position: fixed; right: 20px; bottom: 20px; z-index: 2101; width: 360px; height: 500px; max-height: calc(100vh - 40px); max-width: calc(100vw - 32px);
    display: flex; flex-direction: column; overflow: hidden; border-radius: 14px; border: 1px solid rgba(127, 127, 127, .22);
    background: var(--bs-body-bg, #fff); color: var(--bs-body-color, #212529); box-shadow: 0 18px 50px rgba(0, 0, 0, .28); }
.lbz-cw-head { display: flex; align-items: center; gap: 8px; padding: 10px 10px 10px 12px; background: var(--bs-primary, #435ebe); color: #fff; }
.lbz-cw-title { flex: 1; min-width: 0; display: flex; flex-direction: column; line-height: 1.2; }
.lbz-cw-title strong, .lbz-cw-title small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lbz-cw-title small { opacity: .8; font-size: 11.5px; }
.lbz-cw-btn { border: 0; background: rgba(255, 255, 255, .18); color: inherit; width: 30px; height: 30px; border-radius: 8px; font-size: 17px; line-height: 1;
    display: inline-flex; align-items: center; justify-content: center; cursor: pointer; position: relative; flex: 0 0 auto; }
.lbz-cw-btn:hover { background: rgba(255, 255, 255, .3); }
.lbz-cw-btn.attach { background: transparent; font-size: 18px; margin: 0; }
.lbz-cw-btn.attach .lbz-ct-more { position: absolute; top: -4px; right: -4px; }
.lbz-cw-body { flex: 1; overflow-y: auto; padding: 12px; display: flex; flex-direction: column; gap: 6px; background: rgba(127, 127, 127, .06); }
.lbz-cw-empty { margin: auto; opacity: .6; font-size: 13px; }
.lbz-cw-row { display: flex; }
.lbz-cw-row.mine { justify-content: flex-end; }
.lbz-cw-bubble { max-width: 78%; padding: 7px 10px; border-radius: 14px 14px 14px 4px; font-size: 13.5px; background: var(--bs-body-bg, #fff);
    border: 1px solid rgba(127, 127, 127, .2); word-break: break-word; }
body.theme-dark .lbz-cw-bubble { background: #2a2a3d; border-color: #3a3a52; }
.lbz-cw-row.mine .lbz-cw-bubble { background: var(--bs-primary, #435ebe); color: #fff; border-color: transparent; border-radius: 14px 14px 4px 14px; }
.lbz-cw-text { white-space: pre-wrap; }
.lbz-cw-img { display: block; max-width: 100%; max-height: 200px; border-radius: 8px; }
.lbz-cw-audio { width: 220px; max-width: 100%; }
.lbz-cw-time { display: block; text-align: right; font-size: 10.5px; opacity: .65; margin-top: 2px; }
.lbz-cw-foot { display: flex; align-items: flex-end; gap: 6px; padding: 8px; border-top: 1px solid rgba(127, 127, 127, .2); }
.lbz-cw-foot.ro { justify-content: center; font-size: 12.5px; opacity: .7; padding: 12px; }
.lbz-cw-foot textarea { flex: 1; resize: none; max-height: 100px; border: 1px solid rgba(127, 127, 127, .3); border-radius: 18px; padding: 7px 12px;
    font-size: 14px; background: transparent; color: inherit; outline: none; }
.lbz-cw-foot textarea:focus { border-color: var(--bs-primary, #435ebe); }
.lbz-cw-send { border: 0; width: 38px; height: 38px; border-radius: 50%; background: var(--bs-primary, #435ebe); color: #fff; font-size: 16px; flex: 0 0 auto; }
.lbz-cw-send:disabled { opacity: .45; }
.lbz-cw-enter-active, .lbz-cw-leave-active { transition: all .22s ease; }
.lbz-cw-enter-from, .lbz-cw-leave-to { opacity: 0; transform: translateY(30px) scale(.97); }
@media (max-width: 576px) {
    .lbz-chat-toasts { right: 16px; bottom: 16px; }
    .lbz-chat-toasts.win-open { display: none; }
    /* full screen on phones; JS sets top/height from the visible area (Safari bars + keyboard) */
    .lbz-cw { top: 0; left: 0; right: 0; bottom: auto; width: 100%; max-width: 100%; height: 100vh; height: 100dvh; max-height: none; border-radius: 0; border: 0; }
    .lbz-cw-head { padding-top: calc(10px + env(safe-area-inset-top)); }
    .lbz-cw-foot { padding-bottom: calc(8px + env(safe-area-inset-bottom)); }
    /* 16px stops iPhone from zooming in when you tap the box */
    .lbz-cw-foot textarea { font-size: 16px; }
    .lbz-cw-btn { width: 36px; height: 36px; }
    body.lbz-cw-lock { overflow: hidden; }
}
</style>

<template>
    <!-- Custom (Lebazone): Order Station - touch screen PC next to the printers.
         Tap an order -> its sheet + the guessed number of box labels print. It also prints
         everything packers send from their phones (reprints, changed box counts). -->
    <div class="os" :class="{ mini: mode === 'labels' }">
        <header class="os-top">
            <div class="os-brand">
                <span class="os-logo">{{ appName }}</span>
                <span class="os-title">Order Station · محطة الطلبات</span>
            </div>
            <div class="os-counts">
                <button v-for="b in BUCKETS" :key="b.key" type="button" class="os-c" :class="{ on: filter.includes(b.key) }" :style="{ '--c': b.color }"
                    @click="pick(b.key)"><b>{{ counts[b.key] || 0 }}</b>{{ b.label }}</button>
            </div>
            <div class="os-right">
                <span class="os-printer" :class="{ busy: printing || pendingPrints }" :title="'Prints: ' + modeLabel">
                    🖨 {{ printing ? 'Printing…' : pendingPrints ? pendingPrints + ' in queue' : 'Ready' }}</span>
                <span class="os-clock">{{ clock }}</span>
                <button class="os-icon" title="Settings" @click="showSettings = !showSettings">⚙</button>
            </div>
        </header>

        <div v-if="showSettings" class="os-settings">
            <div class="fw-bold mb-2">This window prints:</div>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <button v-for="m in modes" :key="m.key" class="btn" :class="mode === m.key ? 'btn-danger' : 'btn-outline-dark'" @click="setMode(m.key)">{{ m.label }}</button>
            </div>
            <div class="small text-muted mb-3">Two printers? Open a second window (start-order-station.bat does this) set to "Box labels only" with the label printer,
                and set this one to "Sheets only". Box label size:
                <select v-model="boxSizeVal" class="form-select form-select-sm d-inline-block w-auto ms-1">
                    <option value="58x40">58 × 40 mm</option><option value="100x70">100 × 70 mm</option>
                    <option value="100x150">100 × 150 mm</option><option value="a4">A4 sheet</option>
                </select></div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-outline-dark" @click="fullscreen">⛶ Full screen</button>
                <button class="btn btn-outline-danger" @click="logout">Log out</button>
            </div>
        </div>

        <!-- Labels-only window: just a status page -->
        <div v-if="mode === 'labels'" class="os-mini">
            <div class="display-6">🏷</div>
            <h3>Box label printer</h3>
            <p class="text-muted">This window prints box labels sent from the Order Station and the packers' phones. Keep it open.</p>
            <div class="small">Last printed: {{ lastPrinted || '—' }}</div>
        </div>

        <main v-else class="os-main">
            <div class="os-showing">
                <b>{{ showingLabel }}</b> · {{ shown.length }} order{{ shown.length === 1 ? '' : 's' }}
                <button v-if="!isDefault" class="btn btn-sm btn-outline-dark ms-2" @click="filter = ['waiting', 'printed']">Back to "To print"</button>
            </div>
            <div v-if="!shown.length" class="os-empty">
                <div style="font-size:4rem">✅</div>
                <h2>{{ isDefault ? 'No orders waiting' : 'Nothing here' }}</h2>
                <div v-if="isDefault" class="ar">ما في طلبات بانتظار الطباعة</div>
            </div>
            <div class="os-grid">
                <button v-for="o in shown" :key="o.id" class="os-card" :class="{ printed: o.printed_at, late: o.overdue }"
                    :style="{ '--rc': o.region_color }" @click="open(o)">
                    <div class="os-card-region">{{ o.region_label }} · {{ o.region_ar }}</div>
                    <div class="os-card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div class="os-card-num">{{ o.number }}</div>
                            <div class="os-card-age" :class="{ late: o.overdue }">{{ ago(o.stage_minutes) }}</div>
                        </div>
                        <div class="os-card-cust">{{ o.customer }}</div>
                        <ul class="os-card-lines">
                            <li v-for="(l, i) in o.lines.slice(0, 3)" :key="i">{{ l }}</li>
                            <li v-if="o.lines.length > 3" class="text-muted">+ {{ o.lines.length - 3 }} more</li>
                        </ul>
                        <div class="os-card-foot">
                            <span class="os-box">📦 {{ o.boxes_guess }} box{{ o.boxes_guess > 1 ? 'es' : '' }}</span>
                            <span v-if="o.missing_size" class="os-warn" title="Some products have no size - the box count may be off">⚠ size?</span>
                            <span v-if="!['waiting', 'printed'].includes(o.bucket)" class="os-stage" :style="{ '--c': bucketColor(o.bucket) }">
                                {{ o.stage_label }}<template v-if="o.staff_name"> · {{ o.staff_name }}</template></span>
                            <span v-else-if="o.printed_at" class="os-done">✓ Printed</span>
                            <span v-else class="os-tap">Tap to print</span>
                        </div>
                    </div>
                </button>
            </div>
        </main>

        <!-- Confirm print -->
        <div v-if="sel" class="os-modal" @click.self="sel = null">
            <div class="os-dialog">
                <div class="os-dialog-region" :style="{ background: sel.region_color }">{{ sel.region_label }} · {{ sel.region_ar }}</div>
                <h2 class="mb-1">{{ sel.number }}</h2>
                <div class="text-muted mb-3">{{ sel.customer }} · {{ sel.items }} item(s)</div>
                <ul class="os-dialog-lines"><li v-for="(l, i) in sel.lines" :key="i">{{ l }}</li></ul>
                <div class="fw-bold mt-3 mb-2">Box labels · كراتين</div>
                <div class="d-flex align-items-center justify-content-center gap-3 mb-1">
                    <button class="os-step" @click="boxes = Math.max(1, boxes - 1)">−</button>
                    <span class="os-boxes">{{ boxes }}</span>
                    <button class="os-step" @click="boxes = Math.min(30, boxes + 1)">+</button>
                </div>
                <div class="small text-muted text-center mb-3">Guess from product sizes: {{ sel.boxes_guess }}<span v-if="sel.missing_size"> · {{ sel.missing_size }} product(s) without a size</span></div>
                <button class="os-go" :disabled="busy" @click="print">🖨 {{ sel.printed_at ? 'Print again' : 'Print sheet + ' + boxes + ' label' + (boxes > 1 ? 's' : '') }}</button>
                <div v-if="sel.printed_at" class="d-flex gap-2 mt-2">
                    <button class="btn btn-outline-dark flex-fill py-3" :disabled="busy" @click="reprint('sheet')">📄 Sheet only</button>
                    <button class="btn btn-outline-dark flex-fill py-3" :disabled="busy" @click="reprint('boxes')">🏷 {{ boxes }} box label{{ boxes > 1 ? 's' : '' }} only</button>
                </div>
                <button class="btn btn-link w-100 mt-2" @click="sel = null">Cancel</button>
                <button class="btn btn-sm w-100 mt-1" :class="removeArmed ? 'btn-danger' : 'btn-outline-danger'" :disabled="busy" @click="removeFromStation">
                    {{ removeArmed ? 'Tap again to remove ' + sel.number + ' from the station' : 'Stuck order? Remove from station' }}
                </button>
            </div>
        </div>

        <div v-if="toast" class="os-toast" :class="toast.ok ? 'ok' : 'bad'">{{ toast.text }}</div>
    </div>
</template>

<script>
import axios from 'axios';
import Auth from '../../Auth.js';
import { printSheets, printBoxes, boxSize } from '../../utils/warehousePrint.js';
import { listenForUnlock, soundInfo, soundOk, soundError } from '../../utils/sounds.js';

const MODE_KEY = 'lbzStationPrints';
const BUCKETS = [
    { key: 'waiting', label: 'To print', color: '#DC2626' },
    { key: 'printed', label: 'Printed', color: '#6B7280' },
    { key: 'packing', label: 'Packing', color: '#D97706' },
    { key: 'stamp', label: 'To stamp', color: '#2563EB' },
    { key: 'shelf', label: 'To shelf', color: '#7C3AED' },
    { key: 'ready', label: 'Ready', color: '#16A34A' },
];
const wait = ms => new Promise(r => setTimeout(r, ms));

export default {
    data() {
        let mode = 'all';
        try { mode = localStorage.getItem(MODE_KEY) || new URLSearchParams(location.search).get('prints') || 'all'; } catch (e) {}
        const q = new URLSearchParams(location.search).get('prints');
        if (q) mode = q;
        return {
            mode, orders: [], BUCKETS, filter: ['waiting', 'printed'], counts: { waiting: 0, printed: 0, packing: 0, stamp: 0, shelf: 0, ready: 0 }, pendingPrints: 0,
            sel: null, boxes: 1, busy: false, removeArmed: false, printing: false, checking: false, toast: null, clock: '', lastPrinted: '', showSettings: false,
            appName: 'Lebazone', boxSizeVal: boxSize.get(), knownIds: null,
            modes: [{ key: 'all', label: 'Sheets + box labels' }, { key: 'sheets', label: 'Sheets only' }, { key: 'labels', label: 'Box labels only' }],
        };
    },
    computed: {
        isDefault() { return this.filter.length === 2 && this.filter.includes('waiting') && this.filter.includes('printed'); },
        shown() { return this.orders.filter(o => this.filter.includes(o.bucket)); },
        showingLabel() { return this.isDefault ? 'To print & printed' : this.BUCKETS.filter(b => this.filter.includes(b.key)).map(b => b.label).join(' + '); },
        modeLabel() { return (this.modes.find(m => m.key === this.mode) || {}).label; } },
    watch: { boxSizeVal(v) { boxSize.set(v); } },
    mounted() {
        listenForUnlock();
        document.title = 'Order Station';
        this.tick(); this._clock = setInterval(this.tick, 15000);
        this.load(); this._load = setInterval(this.load, 8000);
        this._jobs = setInterval(this.runJobs, 3000);
        this.keepAwake();
        axios.get(this.$apiUrl + '/store_settings').then(r => { const n = r.data?.data?.app_name; if (n) this.appName = n; }).catch(() => {});
    },
    beforeUnmount() { clearInterval(this._clock); clearInterval(this._load); clearInterval(this._jobs); },
    methods: {
        tick() { this.clock = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); },
        ago(m) { m = Number(m || 0); return m < 60 ? m + ' min' : Math.floor(m / 60) + ' h ' + (m % 60) + ' m'; },
        say(ok, text) { this.toast = { ok, text }; clearTimeout(this._t); this._t = setTimeout(() => { this.toast = null; }, 3500); },
        setMode(m) { this.mode = m; try { localStorage.setItem(MODE_KEY, m); } catch (e) {} },
        async keepAwake() {
            try { this._wake = await navigator.wakeLock.request('screen'); } catch (e) {}
            document.addEventListener('visibilitychange', async () => {
                if (document.visibilityState === 'visible') { try { this._wake = await navigator.wakeLock.request('screen'); } catch (e) {} }
            });
        },
        fullscreen() { try { document.documentElement.requestFullscreen(); } catch (e) {} this.showSettings = false; },
        logout() { if (window.confirm('Log out of the Order Station?')) { Auth.logout(); this.$router.push('/login'); } },
        load() {
            if (this.mode === 'labels') return;
            axios.get(this.$apiUrl + '/station/orders').then(r => {
                if (r.data.status !== 1) return;
                const d = r.data.data;
                const ids = new Set(d.orders.map(o => o.id));
                if (this.knownIds && d.orders.some(o => !this.knownIds.has(o.id))) soundInfo(); // new order arrived
                this.knownIds = ids;
                this.orders = d.orders; this.counts = d.counts; this.pendingPrints = d.pending_prints;
            }).catch(() => {});
        },
        pick(key) { this.filter = this.isDefault || !this.filter.includes(key) ? [key] : ['waiting', 'printed']; window.scrollTo(0, 0); },
        bucketColor(k) { return (BUCKETS.find(b => b.key === k) || {}).color || '#6B7280'; },
        open(o) { this.sel = o; this.boxes = o.boxes || o.boxes_guess || 1; },
        reprint(what) {
            const o = this.sel;
            this.busy = true;
            axios.post(this.$apiUrl + '/warehouse/print', { order_id: o.id, what, boxes: this.boxes }).then(r => {
                if (r.data.status !== 1) { soundError(); return this.say(false, r.data.message); }
                soundOk(); this.say(true, 'Printing ' + o.number + (what === 'sheet' ? ' sheet' : ' · ' + this.boxes + ' label(s)'));
                this.sel = null; this.runJobs();
            }).catch(e => { soundError(); this.say(false, e?.response?.data?.message || e.message); })
              .finally(() => { this.busy = false; });
        },
        // Custom (Lebazone): hides a stuck order from the station; the order itself is untouched.
        removeFromStation() {
            if (!this.removeArmed) { this.removeArmed = true; setTimeout(() => { this.removeArmed = false; }, 4000); return; }
            const o = this.sel;
            this.busy = true;
            axios.post(this.$apiUrl + '/station/remove', { order_id: o.id }).then(r => {
                if (r.data.status !== 1) { soundError(); return this.say(false, r.data.message); }
                this.say(true, o.number + ' removed from the station');
                this.sel = null; this.load();
            }).catch(e => { soundError(); this.say(false, e?.response?.data?.message || e.message); })
              .finally(() => { this.busy = false; this.removeArmed = false; });
        },
        print() {
            const o = this.sel;
            this.busy = true;
            axios.post(this.$apiUrl + '/station/print', { order_id: o.id, boxes: this.boxes }).then(r => {
                if (r.data.status !== 1) { soundError(); return this.say(false, r.data.message); }
                soundOk(); this.say(true, r.data.message);
                this.sel = null; this.load(); this.runJobs();
            }).catch(e => { soundError(); this.say(false, e?.response?.data?.message || e.message); })
              .finally(() => { this.busy = false; });
        },
        // Print whatever is waiting in the queue (taps here + reprints from phones), one by one.
        async runJobs() {
            // Checking the queue every 3s is silent; the badge says "Printing…" only while something prints.
            if (this.checking || this.printing) return;
            this.checking = true;
            try {
                const r = await axios.get(this.$apiUrl + '/station/jobs', { params: { prints: this.mode } });
                const jobs = r.data.status === 1 ? (r.data.data || []) : [];
                if (jobs.length) this.printing = true;
                const done = [];
                for (const j of jobs) {
                    if (j.type === 'sheet') printSheets([j.order], this.appName);
                    else printBoxes(j.order, boxSize.get());
                    done.push(j.id);
                    this.lastPrinted = j.order.number + ' · ' + (j.type === 'sheet' ? 'sheet' : j.order.boxes + ' label(s)') + ' · ' + new Date().toLocaleTimeString();
                    await wait(2500); // give the printer dialog / silent print time before the next one
                }
                if (done.length) {
                    await axios.post(this.$apiUrl + '/station/jobs/done', { ids: done });
                    this.pendingPrints = Math.max(0, this.pendingPrints - done.length);
                }
            } catch (e) { /* offline for a moment - try again in 3s */ }
            this.printing = false;
            this.checking = false;
        },
    },
};
</script>

<style scoped>
.os { min-height: 100vh; background: #F3F4F6; font-family: inherit; user-select: none; -webkit-user-select: none; }
.os .ar { font-family: Tahoma, Arial, sans-serif; direction: rtl; }
.os-top { position: sticky; top: 0; z-index: 5; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; padding: 12px 20px;
    background: #111827; color: #fff; }
.os-brand { display: flex; flex-direction: column; line-height: 1.1; }
.os-logo { color: #F87171; font-weight: 900; font-size: 1.3rem; }
.os-title { font-size: .85rem; opacity: .85; }
.os-counts { display: flex; gap: 8px; flex-wrap: wrap; flex: 1; justify-content: center; }
.os-c { cursor: pointer; color: #fff; border: 2px solid transparent; transition: background .1s; }
.os-c.on { background: rgba(255, 255, 255, .22); border-color: var(--c); }
.os-showing { margin-bottom: 12px; color: #374151; display: flex; align-items: center; flex-wrap: wrap; gap: 4px; }
.os-stage { margin-left: auto; color: #fff; background: var(--c); font-weight: 700; font-size: .8rem; padding: 3px 10px; border-radius: 10px; }
.os-c { display: flex; flex-direction: column; align-items: center; min-width: 84px; padding: 6px 10px; border-radius: 12px;
    background: rgba(255, 255, 255, .08); border-bottom: 3px solid var(--c); font-size: .75rem; }
.os-c b { font-size: 1.5rem; line-height: 1.1; }
.os-right { display: flex; align-items: center; gap: 12px; }
.os-printer { padding: 6px 12px; border-radius: 20px; background: #065F46; font-weight: 700; font-size: .85rem; }
.os-printer.busy { background: #B45309; }
.os-clock { font-size: 1.4rem; font-weight: 800; }
.os-icon { border: 0; background: rgba(255, 255, 255, .12); color: #fff; width: 44px; height: 44px; border-radius: 12px; font-size: 1.3rem; }
.os-settings { background: #fff; padding: 16px 20px; border-bottom: 1px solid #E5E7EB; }
.os-main { padding: 20px; }
.os-empty { text-align: center; padding: 80px 20px; color: #374151; }
.os-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
.os-card { text-align: left; border: 0; padding: 0; border-radius: 18px; background: #fff; overflow: hidden;
    box-shadow: 0 6px 18px rgba(17, 24, 39, .08); transition: transform .1s; }
.os-card:active { transform: scale(.98); }
.os-card.printed { opacity: .6; }
.os-card.late { box-shadow: 0 0 0 3px #DC2626, 0 6px 18px rgba(17, 24, 39, .08); }
.os-card-region { background: var(--rc); color: #fff; font-weight: 800; padding: 8px 14px; font-size: 1rem; }
.os-card-body { padding: 14px; }
.os-card-num { font-size: 1.5rem; font-weight: 900; }
.os-card-age { font-size: .85rem; color: #6B7280; font-weight: 700; white-space: nowrap; }
.os-card-age.late { color: #DC2626; }
.os-card-cust { color: #374151; font-weight: 600; margin-bottom: 6px; }
.os-card-lines { list-style: none; padding: 0; margin: 0 0 10px; font-size: .9rem; color: #4B5563; }
.os-card-lines li { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.os-card-foot { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.os-box { background: #F3F4F6; padding: 4px 10px; border-radius: 10px; font-weight: 800; }
.os-warn { background: #FEF3C7; color: #92400E; padding: 4px 8px; border-radius: 10px; font-weight: 700; font-size: .8rem; }
.os-done { margin-left: auto; color: #16A34A; font-weight: 800; }
.os-tap { margin-left: auto; color: #DC2626; font-weight: 800; }
.os-modal { position: fixed; inset: 0; background: rgba(17, 24, 39, .55); display: flex; align-items: center; justify-content: center; z-index: 20; padding: 16px; }
.os-dialog { background: #fff; border-radius: 22px; padding: 24px; width: min(520px, 100%); max-height: 92vh; overflow: auto; }
.os-dialog-region { color: #fff; font-weight: 800; padding: 8px 14px; border-radius: 12px; display: inline-block; margin-bottom: 10px; }
.os-dialog-lines { padding-left: 18px; color: #374151; }
.os-step { width: 72px; height: 72px; border-radius: 18px; border: 2px solid #E5E7EB; background: #fff; font-size: 2.2rem; font-weight: 800; }
.os-boxes { font-size: 3.2rem; font-weight: 900; min-width: 80px; text-align: center; }
.os-go { width: 100%; border: 0; border-radius: 18px; padding: 20px; background: #DC2626; color: #fff; font-size: 1.4rem; font-weight: 800; }
.os-go:disabled { opacity: .6; }
.os-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); padding: 14px 24px; border-radius: 14px; font-weight: 800;
    font-size: 1.1rem; z-index: 30; box-shadow: 0 10px 30px rgba(0, 0, 0, .2); }
.os-toast.ok { background: #16A34A; color: #fff; } .os-toast.bad { background: #DC2626; color: #fff; }
.os-mini { text-align: center; padding: 60px 20px; }
</style>

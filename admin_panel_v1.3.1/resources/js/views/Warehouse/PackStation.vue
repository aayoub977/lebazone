<template>
    <!-- Custom (Lebazone): guided pack station. One big instruction at a time, in English and Arabic. -->
    <div class="ps">
        <!-- Scanner bar (always there: badge, order sheet, product or box) -->
        <div class="card mb-3"><div class="card-body">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <div v-if="actor" class="ps-who">
                    <span class="ps-avatar" :class="actor.type">{{ (actor.name || '?').charAt(0).toUpperCase() }}</span>
                    <div class="lh-sm">
                        <div class="fw-bold">{{ actor.name }}</div>
                        <div class="small text-muted">{{ actor.type === 'driver' ? 'Driver · سائق' : 'Packing · تجهيز' }}</div>
                    </div>
                    <button v-if="!locked" class="btn btn-sm btn-link" @click="switchUser">Switch · تغيير</button>
                </div>
                <input ref="scan" v-model="code" class="form-control form-control-lg ps-input" autocomplete="off"
                    :placeholder="placeholder" @keydown="onKey" @keydown.enter.prevent="enterScan" @paste="pasted = true">
                <CameraScanner big @scan="c => doScan(c)" />
            </div>
            <div v-if="msg" class="ps-msg mt-3" :class="'ps-' + msg.level">{{ msg.text }}</div>
        </div></div>

        <!-- ============ 0 · WHO IS WORKING ============ -->
        <div v-if="!actor && (checking || isEmployee)" class="card"><div class="card-body text-center py-5 text-muted">
            <template v-if="checking">Loading… · لحظة</template>
            <template v-else>Your account is not set up as an employee yet — ask the manager. · حسابك مش مربوط بموظف</template>
        </div></div>
        <div v-else-if="!actor" class="card"><div class="card-body">
            <h2 class="ps-h">Who is working? <span class="ar">مين عم يشتغل؟</span></h2>
            <p class="text-muted mb-3">Tap your name. This device remembers you until you tap Switch.</p>
            <div class="ps-grid">
                <button v-for="s in activeStaff" :key="s.id" class="ps-name" @click="setActor({ type: 'staff', id: s.id, name: s.name })">
                    <span class="ps-avatar staff">{{ s.name.charAt(0).toUpperCase() }}</span>{{ s.name }}</button>
            </div>
            <div v-if="!activeStaff.length" class="alert alert-warning mt-2 mb-0">
                No employees yet. <a href="#" @click.prevent="$router.push('/system_users')">Add employees in System users →</a></div>
            <template v-if="drivers.length">
                <h6 class="mt-4 text-muted">Driver collecting boxes? · سائق؟</h6>
                <div class="d-flex flex-wrap gap-2">
                    <button v-for="d in drivers" :key="'d' + d.id" class="btn btn-outline-dark" @click="setActor({ type: 'driver', id: d.id, name: d.name })">🚚 {{ d.name }}</button>
                </div>
            </template>
        </div></div>

        <!-- ============ DRIVER ============ -->
        <div v-else-if="actor.type === 'driver'" class="card"><div class="card-body">
            <h2 v-if="!handover" class="ps-h">Scan any box you are taking <span class="ar">امسح أي كرتونة</span></h2>
            <template v-else>
                <div class="ps-region" :style="{ background: handover.region_color }">
                    <div class="ps-region-name">{{ handover.region_label }}</div>
                    <div class="ps-region-sub">{{ handover.remaining_boxes ? 'Take ' + handover.remaining_boxes + ' more box(es) · خود ' + handover.remaining_boxes + ' كراتين كمان' : 'All taken ✓ · أخدت الكل' }}</div>
                </div>
                <div v-for="o in handover.orders" :key="o.id" class="ps-row" :class="{ done: o.done }">
                    <div><b>{{ o.number }}</b> · {{ o.customer }} <span class="text-muted">· {{ o.area }}</span></div>
                    <div class="ms-auto text-end">
                        <span v-for="n in o.boxes" :key="n" class="badge me-1" :class="o.handed.includes(n) ? 'bg-success' : 'bg-light text-dark border'">{{ n }}/{{ o.boxes }}</span>
                        <div class="small fw-bold">{{ o.cod ? 'Collect $' + money(o.cod) : 'Paid' }}</div>
                    </div>
                </div>
            </template>
        </div></div>

        <!-- ============ EMPLOYEE · NO ORDER OPEN ============ -->
        <div v-else-if="!order">
            <button v-if="nextOrder" class="ps-go mb-3" @click="start(nextOrder)">
                <span class="ps-go-t">▶ {{ ['new', 'assigned'].includes(nextOrder.stage) ? 'Start next order · ابدأ الطلب التالي' : 'Continue · كمّل' }}</span>
                <span class="ps-go-s">{{ nextOrder.number }} · {{ nextOrder.customer }} · {{ nextOrder.items }} item(s) · {{ nextOrder.region_label }}</span>
            </button>
            <div v-else class="card mb-3"><div class="card-body text-center py-5">
                <div class="display-6">🎉</div>
                <h3 class="mb-1">No orders waiting</h3><div class="ar fs-5">ما في طلبات هلّق</div>
                <button class="btn btn-outline-secondary mt-3" @click="loadQueue">Check again</button>
            </div></div>

            <div v-if="queue.mine.length" class="card mb-3"><div class="card-body">
                <h6>Your unfinished orders · طلباتك</h6>
                <div v-for="o in queue.mine" :key="o.id" class="ps-row">
                    <span class="ps-dot" :style="{ background: o.region_color }"></span>
                    <div><b>{{ o.number }}</b> · {{ o.customer }}<div class="small text-muted">{{ o.items }} item(s) · {{ stepName(o.stage) }}</div></div>
                    <button class="btn btn-warning ms-auto" @click="start(o)">Continue</button>
                </div>
            </div></div>
            <div v-if="(queue.others || []).length" class="card mb-3"><div class="card-body">
                <h6>Started by a colleague — anyone can finish · بلّش فيهن زميل، فيك تكمّل</h6>
                <div v-for="o in queue.others" :key="'o' + o.id" class="ps-row" :class="{ late: o.overdue }">
                    <span class="ps-dot" :style="{ background: o.region_color }"></span>
                    <div><b>{{ o.number }}</b> · {{ o.customer }}<div class="small text-muted">{{ o.items }} item(s) · {{ stepName(o.stage) }}<span v-if="o.staff_name"> · {{ o.staff_name }}</span></div></div>
                    <button class="btn btn-outline-warning ms-auto" @click="start(o)">Continue</button>
                </div>
            </div></div>
            <div v-if="queue.waiting.length" class="card"><div class="card-body">
                <h6>Waiting · بانتظار ({{ queue.waiting.length }})</h6>
                <div v-for="o in queue.waiting" :key="o.id" class="ps-row" :class="{ late: o.overdue }">
                    <span class="ps-dot" :style="{ background: o.region_color }"></span>
                    <div><b>{{ o.number }}</b> · {{ o.customer }}<div class="small text-muted">{{ o.items }} item(s) · {{ o.region_label }} · {{ ago(o.stage_minutes) }}</div></div>
                    <button class="btn btn-outline-primary ms-auto" @click="start(o)">Start</button>
                </div>
            </div></div>
        </div>

        <!-- ============ EMPLOYEE · ORDER OPEN ============ -->
        <div v-else class="card"><div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <span class="ps-chip" :style="{ background: order.region_color }">{{ order.region_label }} · {{ order.region_ar }}</span>
                <div class="fw-bold fs-5">{{ order.number }} <span class="fw-normal text-muted">· {{ order.customer }}</span></div>
                <button class="btn btn-sm btn-outline-secondary ms-auto" @click="closeOrder">← Back · رجوع</button>
            </div>
            <div v-if="order.note" class="alert alert-warning py-2"><b>Customer note:</b> {{ order.note }}</div>

            <!-- steps -->
            <div class="ps-steps mb-4">
                <div v-for="(s, i) in STEPS" :key="s.key" class="ps-step" :class="{ done: stepIndex > i, now: stepIndex === i }">
                    <span class="ps-step-n">{{ stepIndex > i ? '✓' : i + 1 }}</span>
                    <span>{{ s.en }}<br><small class="ar">{{ s.ar }}</small></span>
                </div>
            </div>

            <!-- not started -->
            <template v-if="['new', 'assigned'].includes(order.stage)">
                <button class="ps-go" @click="start(order)"><span class="ps-go-t">▶ Start this order · ابدأ</span>
                    <span class="ps-go-s">The receipt and delivery note print now</span></button>
            </template>

            <!-- 1 · scan products -->
            <template v-else-if="order.stage === 'picking'">
                <h2 class="ps-h">Scan each product <span class="ar">امسح كل منتج</span></h2>
                <div class="progress ps-progress mb-3"><div class="progress-bar bg-success" :style="{ width: pct + '%' }">{{ pickedQty }} / {{ totalQty }}</div></div>
                <div v-for="i in sortedItems" :key="i.id" class="ps-item" :class="{ done: i.picked >= i.qty }">
                    <img v-if="i.image" :src="i.image" alt=""><div v-else class="ps-noimg">📦</div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-bold text-truncate">{{ i.name }}</div>
                        <span v-if="i.color" class="ps-color">{{ i.color }}</span>
                        <code class="small ms-1">{{ i.code }}</code>
                    </div>
                    <div class="ps-qty">{{ i.picked }}<small>/{{ i.qty }}</small></div>
                    <span v-if="i.picked >= i.qty" class="ps-tick">✓</span>
                </div>
            </template>

            <!-- 2 · boxes -->
            <template v-else-if="order.stage === 'picked'">
                <h2 class="ps-h">All products found ✓ — how many boxes? <span class="ar">كم كرتونة؟</span></h2>
                <p class="text-muted">
                    <template v-if="order.printed_boxes">{{ order.printed_boxes }} label(s) were printed with the sheet. Same number? Tap it. Different? Tap the right number and new labels print at the Order Station.</template>
                    <template v-else>Tap the number. The box labels print at the Order Station.</template>
                </p>
                <div class="ps-boxes">
                    <button v-for="n in 5" :key="n" class="ps-boxbtn" :class="{ guess: n === (order.printed_boxes || order.boxes_guess) }" :disabled="busy" @click="pack(n)">
                        {{ n }}<small>{{ n === (order.printed_boxes || order.boxes_guess) ? 'suggested' : (n === 1 ? 'box' : 'boxes') }}</small></button>
                    <div class="ps-boxbtn more">
                        <input v-model.number="moreBoxes" type="number" min="6" max="50" class="form-control text-center" placeholder="6+">
                        <button class="btn btn-sm btn-primary mt-1" :disabled="busy || !moreBoxes" @click="pack(moreBoxes)">OK</button>
                    </div>
                </div>
            </template>

            <!-- 3 · stamp -->
            <template v-else-if="order.stage === 'packed'">
                <h2 class="ps-h">Stamp the receipt &amp; delivery note, then scan the sheet barcode
                    <span class="ar">اختم الفاتورة وورقة التسليم، وبعدين امسح باركود الورقة</span></h2>
                <div class="ps-hint">🏷 Stick the {{ order.boxes }} box label(s) on the boxes · ✅ Stamp both papers · 📄 Scan the QR code next to the stamp</div>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button class="btn btn-outline-primary" @click="reprintBoxes">Reprint box labels</button>
                    <button class="btn btn-outline-primary" @click="printSheet(order)">Reprint sheet</button>
                    <button class="btn btn-outline-secondary" @click="changeBoxes">Change number of boxes</button>
                </div>
            </template>

            <!-- 4 · boxes onto the region shelf, each scanned -->
            <template v-else-if="order.stage === 'stamped'">
                <div class="ps-region" :style="{ background: order.region_color }">
                    <div class="ps-region-sub">Put the boxes on the shelf, then scan each box label · حط الكراتين على الرف وامسح كل كرتونة</div>
                    <div class="ps-region-name">{{ order.region_label }} · {{ order.region_ar }}</div>
                </div>
                <div class="d-flex flex-wrap gap-2 justify-content-center mb-3">
                    <span v-for="n in order.boxes" :key="n" class="ps-boxchip" :class="{ done: (order.shelved || []).includes(n) }">
                        {{ (order.shelved || []).includes(n) ? '✓' : '📦' }} Box {{ n }}/{{ order.boxes }}</span>
                </div>
                <div class="text-center fw-bold mb-3">{{ (order.shelved || []).length }} / {{ order.boxes }} scanned · You can start the next order once all boxes are scanned</div>
                <div class="d-flex flex-wrap gap-2 justify-content-center">
                    <button class="btn btn-outline-primary" @click="reprintBoxes">Reprint box labels</button>
                </div>
            </template>

            <!-- done -->
            <template v-else-if="order.stage === 'ready'">
                <div class="ps-region" :style="{ background: order.region_color }">
                    <div class="ps-region-sub">✓ {{ order.boxes }} box(es) on the shelf · الكراتين على الرف</div>
                    <div class="ps-region-name">{{ order.region_label }} · {{ order.region_ar }}</div>
                </div>
                <div class="text-center text-success fw-bold mb-3">The customer was told the order is packed and ready for delivery.</div>
                <button class="ps-go" @click="next"><span class="ps-go-t">▶ Next order · الطلب التالي</span></button>
            </template>

            <template v-else-if="order.stage === 'out'">
                <h2 class="ps-h">With the driver ✓ <span class="ar">مع السائق</span></h2>
                <p>{{ order.driver ? order.driver.name : '' }}</p>
                <button class="ps-go" @click="next"><span class="ps-go-t">▶ Next order · الطلب التالي</span></button>
            </template>

            <div v-if="!['new', 'out'].includes(order.stage)" class="text-end mt-4">
                <button class="btn btn-sm btn-link text-danger" @click="reopen">Something wrong? Start this order again</button>
            </div>
        </div></div>
    </div>
</template>

<script>
import axios from 'axios';
import CameraScanner from '../../components/CameraScanner.vue';
import { listenForUnlock, soundOk, soundDone, soundError, soundInfo } from '../../utils/sounds.js';

const STEPS = [
    { key: 'pick', en: 'Scan products', ar: 'امسح المنتجات' },
    { key: 'pack', en: 'Boxes & labels', ar: 'الكراتين' },
    { key: 'stamp', en: 'Stamp & scan sheet', ar: 'اختم وامسح' },
    { key: 'shelf', en: 'Scan boxes on shelf', ar: 'امسح على الرف' },
];
const KEY = 'lbzStationActor';

export default {
    components: { CameraScanner },
    props: { staff: { type: Array, default: () => [] }, drivers: { type: Array, default: () => [] }, appName: { type: String, default: 'Lebazone' } },
    emits: ['changed', 'go'],
    data() {
        let actor = null;
        try { actor = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) {}
        const isEmployee = ['Packer', 'Product entry', 'Packer + products'].includes(window.Role);
        if (isEmployee) actor = null; // never reuse a name remembered on this device
        return { STEPS, actor, locked: isEmployee, isEmployee, checking: true, order: null, handover: null, queue: { mine: [], others: [], waiting: [] }, code: '', msg: null,
            busy: false, moreBoxes: null, keyTimes: [], pasted: false, pendingId: null, audio: null, timer: null };
    },
    computed: {
        activeStaff() { return this.staff.filter(s => Number(s.active)); },
        nextOrder() { return this.queue.mine[0] || this.queue.waiting[0] || (this.queue.others || [])[0] || null; },
        placeholder() {
            if (!this.actor) return 'Tap your name above · اضغط على اسمك';
            if (this.actor.type === 'driver') return 'Scan a box label (BX…) · امسح الكرتونة';
            if (!this.order) return 'Scan an order sheet (ORD…) · امسح ورقة الطلب';
            if (this.order.stage === 'picking') return 'Scan a product… · امسح المنتج';
            if (this.order.stage === 'packed') return 'Scan the stamped sheet (ORD…) · امسح الورقة';
            if (this.order.stage === 'stamped') return 'Scan each box label (BX…) · امسح الكراتين';
            return 'Scan…';
        },
        stepIndex() {
            const s = this.order ? this.order.stage : '';
            return { picking: 0, picked: 1, packed: 2, stamped: 3, ready: 4, out: 4 }[s] ?? -1;
        },
        totalQty() { return this.order ? this.order.items.reduce((a, i) => a + i.qty, 0) : 0; },
        pickedQty() { return this.order ? this.order.items.reduce((a, i) => a + Math.min(i.picked, i.qty), 0) : 0; },
        pct() { return this.totalQty ? Math.round(this.pickedQty / this.totalQty * 100) : 0; },
        sortedItems() { return this.order ? [...this.order.items].sort((a, b) => (a.picked >= a.qty) - (b.picked >= b.qty)) : []; },
    },
    watch: {
        staff(list) {
            // A remembered employee that was disabled or deleted: ask again.
            if (!this.locked && this.actor && this.actor.type === 'staff' && list.length && !list.some(s => s.id === this.actor.id && Number(s.active))) this.switchUser();
        },
    },
    mounted() {
        listenForUnlock();
        // Employee login: the account IS the employee - no name to tap, no switching.
        axios.get(this.$apiUrl + '/warehouse/me').then(r => {
            const e = r.data && r.data.status === 1 ? r.data.data.employee : null;
            this.checking = false;
            if (e) { this.locked = true; this.isEmployee = true; this.setActor({ type: 'staff', id: e.id, name: e.name }, false); }
            else if (this.actor && this.actor.type === 'staff') this.loadQueue();
        }).catch(() => { this.checking = false; if (!this.isEmployee && this.actor && this.actor.type === 'staff') this.loadQueue(); });
        this.timer = setInterval(() => { if (this.actor && this.actor.type === 'staff' && !this.order) this.loadQueue(); }, 15000);
        this.focus();
    },
    beforeUnmount() { clearInterval(this.timer); },
    methods: {
        money(v) { return Number(v || 0).toFixed(2); },
        ago(m) { m = Number(m || 0); return m < 60 ? m + ' min ago' : Math.floor(m / 60) + ' h ago'; },
        stepName(stage) { return { assigned: 'Not started', picking: 'Scanning products', picked: 'Choose boxes', packed: 'Stamp & scan sheet', stamped: 'Scan boxes onto shelf' }[stage] || stage; },
        focus() {
            // Touch screens: no auto-focus (it opened the keyboard over the screen); use the camera button.
            if (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) return;
            this.$nextTick(() => this.$refs.scan && this.$refs.scan.focus());
        },
        beep(ok) { ok ? soundOk() : soundError(); },
        say(level, text, sound = null) {
            this.msg = { level, text };
            (sound || (level === 'error' ? soundError : level === 'info' ? soundInfo : soundOk))();
        },
        fail(e) { this.say('error', e?.response?.data?.message || e?.message || String(e)); },
        sheetCode(id) { return 'ORD' + String(id).padStart(6, '0'); },

        setActor(a, remember = true) {
            if (this.locked && remember) return; // employees cannot change who they are
            this.actor = a; this.order = null; this.handover = null; this.msg = null;
            if (remember) { try { localStorage.setItem(KEY, JSON.stringify(a)); } catch (e) {} }
            if (a.type === 'staff') {
                this.loadQueue();
                if (this.pendingId) { this.load(this.pendingId); this.pendingId = null; }
            }
            this.focus();
        },
        switchUser() {
            if (this.locked) return;
            this.actor = null; this.order = null; this.handover = null; this.msg = null;
            try { localStorage.removeItem(KEY); } catch (e) {}
            this.focus();
        },
        /** Called by the Orders board ("Pack it"). */
        open(id) {
            if (this.actor && this.actor.type === 'staff') this.load(id);
            else { this.pendingId = id; this.say('info', 'Tap your name first · اضغط على اسمك'); }
        },
        loadQueue() {
            if (!this.actor || this.actor.type !== 'staff') return Promise.resolve();
            return axios.get(this.$apiUrl + '/warehouse/queue', { params: { staff_id: this.actor.id } }).then(r => {
                if (r.data.status === 1) this.queue = r.data.data;
                else if (/choose who/i.test(r.data.message || '')) this.switchUser();
            }).catch(() => {});
        },
        load(id) {
            return axios.get(this.$apiUrl + '/warehouse/order', { params: { id } }).then(r => {
                if (r.data.status !== 1) return this.say('error', r.data.message);
                this.order = r.data.data; this.msg = null; this.focus();
            }).catch(this.fail);
        },
        closeOrder() { this.order = null; this.msg = null; this.loadQueue(); this.focus(); },
        async next() { this.order = null; this.msg = null; await this.loadQueue(); if (this.nextOrder) this.start(this.nextOrder); this.focus(); },

        start(o) {
            // Not started yet: starting it = scanning its sheet. Then print the sheet to stamp later.
            if (['new', 'assigned'].includes(o.stage)) {
                // Not printed at the Order Station yet -> send the sheet + guessed box labels there.
                return this.doScan(this.sheetCode(o.id)).then(ok => { if (ok && this.order && !this.order.printed_at) this.sendPrint('all'); });
            }
            return this.load(o.id);
        },

        // Custom (Lebazone): products and the stamped sheet must be SCANNED (scanner or camera),
        // never typed. A barcode scanner types the whole code in a few ms; a person can't.
        onKey(e) {
            if (e.key && e.key.length === 1) {
                if (!this.code) { this.keyTimes = []; this.pasted = false; }
                (this.keyTimes = this.keyTimes || []).push(performance.now());
            }
        },
        enterScan() {
            const t = this.keyTimes || [];
            const avg = t.length > 1 ? (t[t.length - 1] - t[0]) / (t.length - 1) : 999;
            const typed = this.pasted || t.length < 4 || avg > 80; // scanners: <30ms per character; people: 150ms+
            this.keyTimes = []; this.pasted = false;
            const mustScan = this.order && ['picking', 'packed', 'stamped'].includes(this.order.stage);
            if (mustScan && typed) {
                this.code = '';
                this.say('error', 'Scan it, don\'t type it · امسح الباركود، ممنوع الكتابة');
                return;
            }
            this.doScan();
        },
        doScan(c) {
            const code = String(c ?? this.code).trim();
            this.code = '';
            if (!code) return Promise.resolve(false);
            const body = { code, order_id: this.order ? this.order.id : 0,
                staff_id: this.actor && this.actor.type === 'staff' ? this.actor.id : 0,
                driver_id: this.actor && this.actor.type === 'driver' ? this.actor.id : 0 };
            return axios.post(this.$apiUrl + '/warehouse/scan', body).then(r => {
                if (r.data.status !== 1) { this.say('error', r.data.message); return false; }
                const d = r.data.data;
                if (d.actor) this.setActor(d.actor);
                const before = this.order ? this.order.stage : null;
                if (d.order) this.order = d.order;
                if (d.handover) this.handover = d.handover;
                const after = this.order ? this.order.stage : null;
                // All products found, or the order went onto the shelf: the "done" chime.
                const finished = d.level !== 'error' && ((before === 'picking' && after === 'picked') || (after === 'ready' && before !== 'ready'));
                this.say(d.level, d.message, finished ? soundDone : null);
                if (d.done) this.$emit('changed');
                return d.level !== 'error';
            }).catch(e => { this.fail(e); return false; }).finally(this.focus);
        },
        pack(n) {
            if (!this.order || !n) return;
            this.busy = true;
            axios.post(this.$apiUrl + '/warehouse/pack', { order_id: this.order.id, boxes: n, staff_id: this.actor.id }).then(r => {
                if (r.data.status !== 1) return this.say('error', r.data.message);
                this.order = r.data.data; this.moreBoxes = null;
                this.say('success', r.data.message);
            }).catch(this.fail).finally(() => { this.busy = false; this.focus(); });
        },
        changeBoxes() {
            const n = parseInt(window.prompt('How many boxes? · كم كرتونة؟', String(this.order.boxes || 1)), 10);
            if (n > 0) this.pack(n);
        },
        // Phones can't print: everything goes to the Order Station printer.
        sendPrint(what) {
            if (!this.order) return;
            axios.post(this.$apiUrl + '/warehouse/print', { order_id: this.order.id, what, boxes: this.order.boxes || undefined }).then(r => {
                this.say(r.data.status === 1 ? 'info' : 'error', r.data.status === 1 ? '🖨 Printing at the Order Station · عم تنطبع' : r.data.message);
            }).catch(this.fail).finally(this.focus);
        },
        reprintBoxes() { this.sendPrint('boxes'); },
        printSheet() { this.sendPrint('sheet'); },
        reopen() {
            const reason = window.prompt('Start ' + this.order.number + ' again from the beginning? Why?');
            if (reason === null) return;
            axios.post(this.$apiUrl + '/warehouse/reopen', { order_id: this.order.id, reason }).then(r => {
                if (r.data.status !== 1) return this.say('error', r.data.message);
                this.order = r.data.data;
                this.say('info', 'Order reset - tap Start · رجع من الأول');
                this.$emit('changed');
            }).catch(this.fail);
        },
    },
};
</script>

<style scoped>
.ps .ar { font-family: Tahoma, Arial, sans-serif; direction: rtl; unicode-bidi: isolate; }
.ps-input { flex: 1; min-width: 220px; }
.ps-who { display: flex; align-items: center; gap: 8px; padding-right: 8px; }
.ps-avatar { width: 40px; height: 40px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
    font-weight: 800; color: #fff; background: #DC2626; flex-shrink: 0; }
.ps-avatar.driver { background: #111827; }
.ps-msg { font-size: 1.3rem; font-weight: 700; padding: 12px 16px; border-radius: 10px; }
.ps-success { background: #DCFCE7; color: #166534; } .ps-info { background: #DBEAFE; color: #1E3A8A; } .ps-error { background: #FEE2E2; color: #991B1B; }
.ps-h { font-size: 1.6rem; font-weight: 800; margin-bottom: 12px; }
.ps-h .ar { display: block; font-size: 1.25rem; font-weight: 700; color: #6B7280; margin-top: 2px; }
.ps-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
.ps-name { display: flex; align-items: center; gap: 10px; padding: 16px; font-size: 1.15rem; font-weight: 700; border: 2px solid #E5E7EB;
    border-radius: 14px; background: #fff; text-align: left; }
.ps-name:hover { border-color: #DC2626; background: #FEF2F2; }
.ps-go { display: flex; flex-direction: column; align-items: center; width: 100%; padding: 22px; border: 0; border-radius: 16px;
    background: #16A34A; color: #fff; box-shadow: 0 6px 18px rgba(22, 163, 74, .3); }
.ps-go:hover { background: #15803D; }
.ps-go-t { font-size: 1.6rem; font-weight: 800; } .ps-go-s { opacity: .9; margin-top: 4px; }
.ps-row { display: flex; align-items: center; gap: 12px; padding: 10px 4px; border-bottom: 1px solid #F3F4F6; min-width: 0; }
.ps-row > div { min-width: 0; overflow-wrap: anywhere; }
.ps { max-width: 100%; overflow-x: hidden; }
.ps-who { min-width: 0; } .ps-input { min-width: 0 !important; flex-basis: 160px; }
.ps-row.done { background: #F0FDF4; } .ps-row.late { background: #FEF2F2; }
.ps-dot { width: 14px; height: 14px; border-radius: 50%; flex-shrink: 0; }
.ps-chip { color: #fff; font-weight: 800; padding: 6px 14px; border-radius: 10px; }
.ps-steps { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.ps-step { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 12px; background: #F3F4F6; color: #6B7280; font-size: .9rem; line-height: 1.15; }
.ps-step-n { width: 28px; height: 28px; border-radius: 50%; background: #D1D5DB; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; flex-shrink: 0; }
.ps-step.done { background: #DCFCE7; color: #166534; } .ps-step.done .ps-step-n { background: #16A34A; }
.ps-step.now { background: #FEE2E2; color: #991B1B; font-weight: 700; } .ps-step.now .ps-step-n { background: #DC2626; }
.ps-progress { height: 28px; font-size: 1rem; font-weight: 700; border-radius: 14px; }
.ps-item { display: flex; align-items: center; gap: 12px; padding: 10px; border: 2px solid #E5E7EB; border-radius: 12px; margin-bottom: 8px; }
.ps-item.done { border-color: #86EFAC; background: #F0FDF4; opacity: .8; }
.ps-item img, .ps-noimg { width: 56px; height: 56px; object-fit: contain; border-radius: 8px; background: #fff; flex-shrink: 0; }
.ps-noimg { display: flex; align-items: center; justify-content: center; font-size: 1.6rem; background: #F3F4F6; }
.ps-color { display: inline-block; padding: 1px 10px; border-radius: 10px; background: #111827; color: #fff; font-weight: 700; font-size: .85rem; }
.ps-qty { font-size: 1.8rem; font-weight: 800; min-width: 70px; text-align: center; } .ps-qty small { font-size: 1rem; color: #6B7280; }
.ps-tick { font-size: 1.6rem; color: #16A34A; font-weight: 800; width: 38px; text-align: center; }
.ps-boxes { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 12px; }
.ps-boxbtn { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 110px; border: 2px solid #E5E7EB;
    border-radius: 16px; background: #fff; font-size: 2.4rem; font-weight: 800; }
.ps-boxbtn small { font-size: .9rem; font-weight: 600; color: #6B7280; }
.ps-boxbtn:not(.more):hover { border-color: #DC2626; background: #FEF2F2; }
.ps-boxbtn.guess { border-color: #16A34A; background: #F0FDF4; }
.ps-boxbtn.more { font-size: 1rem; padding: 10px; }
.ps-boxchip { padding: 8px 14px; border-radius: 12px; border: 2px dashed #D1D5DB; font-weight: 700; background: #fff; }
.ps-boxchip.done { border: 2px solid #16A34A; background: #DCFCE7; color: #166534; }
.ps-hint { font-size: 1.1rem; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 12px; padding: 12px 16px; }
.ps-region { color: #fff; text-align: center; border-radius: 18px; padding: 26px 16px; margin-bottom: 16px; }
.ps-region-name { font-size: 2.6rem; font-weight: 900; line-height: 1.1; }
.ps-region-sub { font-size: 1.2rem; font-weight: 600; opacity: .95; }
.min-w-0 { min-width: 0; }
@media (max-width: 576px) { .ps-steps { grid-template-columns: repeat(2, 1fr); } .ps-h { font-size: 1.3rem; } .ps-region-name { font-size: 2rem; } }
</style>

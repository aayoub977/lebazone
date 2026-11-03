<template>
    <!-- Custom (Lebazone): driver screen. Two lists that never disappear:
         "Take from the shelf" (his zone) and "In my car" (photo of boxes -> deliver with photo). -->
    <div class="cb">
        <!-- Photo needed: blocks the next scan until done -->
        <div v-if="photoOrder" class="cb-photo-alert mb-3">
            <div class="fw-bold fs-5">📸 Take a photo of the boxes · صوّر الكراتين</div>
            <div class="mb-2">{{ photoOrder.number }} · {{ photoOrder.customer }} · {{ photoOrder.boxes }} box(es)</div>
            <button class="btn btn-light btn-lg w-100 fw-bold" :disabled="!!uploading" @click="takePhoto(photoOrder, 'collected')">
                <b-spinner v-if="uploading" small /> <template v-else>📷 Open camera · افتح الكاميرا</template>
            </button>
        </div>

        <!-- Scan -->
        <div class="card mb-3 cb-scan"><div class="card-body">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <CameraScanner big label="📷 Scan box · امسح" @scan="c => doScan(c)" />
                <input ref="scan" v-model="code" class="form-control form-control-lg" style="flex:1;min-width:150px" autocomplete="off"
                    placeholder="or type BX…" @keydown.enter.prevent="doScan()">
            </div>
            <div v-if="msg" class="cb-msg mt-3" :class="'cb-' + msg.level">{{ msg.text }}</div>
        </div></div>

        <!-- Tabs -->
        <div class="cb-tabs mb-3">
            <button :class="{ on: tab === 'shelf' }" @click="tab = 'shelf'">🏬 Shelf · الرف <span class="cb-n">{{ ready.length }}</span></button>
            <button :class="{ on: tab === 'car' }" @click="tab = 'car'">🚚 My car · سيارتي <span class="cb-n">{{ toDeliver }}</span></button>
        </div>

        <!-- Shelf -->
        <template v-if="tab === 'shelf'">
            <div v-if="zones.length" class="mb-2 small">Your zone · منطقتك:
                <span v-for="z in zones" :key="z.region" class="badge me-1" :style="{ background: z.color }">{{ z.label }} · {{ z.ar }}</span></div>
            <div v-if="!ready.length" class="card"><div class="card-body text-center text-muted py-4">
                Nothing ready for your zone · ما في شي جاهز لمنطقتك
                <div><button class="btn btn-sm btn-outline-secondary mt-2" @click="load">Refresh</button></div>
            </div></div>
            <div v-for="o in ready" :key="o.id" :id="'cbo' + o.id" class="cb-card" :class="{ hit: o.id === lastId }" :style="{ borderLeftColor: o.region_color }">
                <div class="d-flex justify-content-between gap-2">
                    <div><b>{{ o.number }}</b> · {{ o.customer }}<div class="small text-muted">{{ o.region_label }} · {{ o.area }}</div></div>
                    <div class="fw-bold text-nowrap">{{ o.cod ? '$' + money(o.cod) : 'Paid' }}</div>
                </div>
                <div class="mt-2">
                    <span v-for="n in o.boxes" :key="n" class="cb-box" :class="{ ok: o.handed.includes(n) }">{{ o.handed.includes(n) ? '✓ ' : '' }}Box {{ n }}/{{ o.boxes }}</span>
                </div>
                <div class="small text-muted mt-1">{{ o.handed.length ? 'Scan the other box(es) · امسح الباقي' : 'Scan every box to take it · امسح كل كرتونة' }}</div>
            </div>
            <div v-if="others.length" class="small text-muted mt-2">Not your zone — leave on the shelf · مش منطقتك:
                <span v-for="r in others" :key="r.label" class="me-2">{{ r.label }} ({{ r.boxes }})</span></div>
        </template>

        <!-- My car -->
        <template v-else>
            <div v-if="!car.length" class="card"><div class="card-body text-center text-muted py-4">Your car is empty · سيارتك فاضية</div></div>
            <div v-for="o in car" :key="o.id" :id="'cbo' + o.id" class="cb-card" :class="{ hit: o.id === lastId, done: o.delivered }" :style="{ borderLeftColor: o.region_color }">
                <div class="d-flex justify-content-between gap-2">
                    <div><b>{{ o.number }}</b> · {{ o.customer }}<div class="small text-muted">{{ o.address || o.area }}</div></div>
                    <div class="fw-bold text-nowrap text-end">{{ o.cod ? 'Collect $' + money(o.cod) : 'Paid' }}<div class="small text-muted">📦 {{ o.boxes }}</div></div>
                </div>
                <div class="d-flex gap-2 mt-2 flex-wrap">
                    <a v-if="o.phone" :href="'tel:' + o.phone.replace(/\s+/g, '')" class="btn btn-outline-secondary btn-sm">📞 Call</a>
                    <a :href="mapUrl(o)" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">🗺️ Map</a>
                    <a v-for="p in o.photos" :key="p.url" :href="p.url" target="_blank" class="cb-thumb" :title="p.type"><img :src="p.url" alt=""></a>
                </div>
                <button v-if="!o.collected_photo" class="btn btn-danger btn-lg w-100 mt-2 fw-bold" :disabled="!!uploading" @click="takePhoto(o, 'collected')">
                    <b-spinner v-if="uploading === o.id" small /> <template v-else>📸 Photo of boxes · صوّر الكراتين</template></button>
                <button v-else-if="!o.delivered" class="btn btn-success btn-lg w-100 mt-2 fw-bold" :disabled="!!uploading" @click="takePhoto(o, 'delivered')">
                    <b-spinner v-if="uploading === o.id" small /> <template v-else>✅ Delivered — take photo · تم التسليم</template></button>
                <div v-else class="text-success fw-bold mt-2">✓ Delivered · تم التسليم</div>
            </div>
        </template>

        <input ref="cam" type="file" accept="image/*" capture="environment" class="d-none" @change="upload">
    </div>
</template>

<script>
import axios from 'axios';
import CameraScanner from '../../components/CameraScanner.vue';
import { listenForUnlock, soundOk, soundError, soundDone } from '../../utils/sounds.js';

export default {
    components: { CameraScanner },
    data() {
        return { code: '', msg: null, tab: 'shelf', ready: [], car: [], others: [], zones: [], photoNeeded: null,
            lastId: null, uploading: null, pending: null };
    },
    computed: {
        toDeliver() { return this.car.filter(o => !o.delivered).length; },
        photoOrder() { return this.photoNeeded ? this.car.find(o => o.id === this.photoNeeded) || null : null; },
    },
    mounted() {
        listenForUnlock();
        this.load();
        this._t = setInterval(() => { if (document.visibilityState === 'visible' && !this.uploading) this.load(); }, 15000);
    },
    beforeUnmount() { clearInterval(this._t); },
    methods: {
        money(v) { return Number(v || 0).toFixed(2); },
        mapUrl(o) {
            if (o.lat && o.lng) return 'https://www.google.com/maps/search/?api=1&query=' + o.lat + ',' + o.lng;
            return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent((o.address || o.area || '') + ' Lebanon');
        },
        load() {
            return axios.get(this.$deliveryBoyApiUrl + '/warehouse/board').then(r => {
                if (r.data.status !== 1) return;
                const d = r.data.data || {};
                this.ready = d.ready || []; this.car = d.car || []; this.others = d.others || []; this.zones = d.zones || [];
                this.photoNeeded = d.photo_needed || null;
            }).catch(() => {});
        },
        focus(id) {
            this.lastId = id;
            this.$nextTick(() => { const el = document.getElementById('cbo' + id); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' }); });
        },
        doScan(c) {
            const code = String(c ?? this.code).trim();
            this.code = '';
            if (!code) return;
            axios.post(this.$deliveryBoyApiUrl + '/warehouse/scan', { code }).then(async r => {
                if (r.data.status !== 1) { this.msg = { level: 'error', text: r.data.message }; soundError(); return; }
                const d = r.data.data || {};
                this.msg = { level: d.level, text: d.message };
                await this.load();
                if (d.level === 'error') { soundError(); if (d.photo_needed) this.tab = 'car'; return; }
                if (d.completed) { soundDone(); this.tab = 'car'; this.focus(d.completed); }
                else { soundOk(); this.tab = 'shelf'; if (d.order_id) this.focus(d.order_id); }
            }).catch(e => { this.msg = { level: 'error', text: e?.response?.data?.message || e.message }; soundError(); });
        },
        takePhoto(o, type) {
            this.pending = { id: o.id, type };
            this.$refs.cam.value = '';
            this.$refs.cam.click();
        },
        upload(ev) {
            const file = ev.target.files && ev.target.files[0];
            if (!file || !this.pending) return;
            const { id, type } = this.pending;
            const fd = new FormData();
            fd.append('order_id', id); fd.append('type', type); fd.append('photo', file);
            this.uploading = id;
            axios.post(this.$deliveryBoyApiUrl + '/warehouse/photo', fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then(async r => {
                this.uploading = null; this.pending = null;
                if (r.data.status !== 1) { this.msg = { level: 'error', text: r.data.message }; soundError(); return; }
                this.msg = { level: 'success', text: r.data.message };
                type === 'delivered' ? soundDone() : soundOk();
                await this.load();
                this.focus(id);
            }).catch(e => { this.uploading = null; this.msg = { level: 'error', text: e?.response?.data?.message || e.message }; soundError(); });
        },
    },
};
</script>

<style scoped>
.cb { max-width: 720px; margin: 0 auto; }
.cb-scan { position: sticky; top: 0; z-index: 5; }
.cb-msg { font-size: 1.1rem; font-weight: 700; padding: 10px 14px; border-radius: 10px; }
.cb-success { background: #DCFCE7; color: #166534; } .cb-info { background: #DBEAFE; color: #1E3A8A; } .cb-error { background: #FEE2E2; color: #991B1B; }
.cb-tabs { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.cb-tabs button { border: 2px solid #E5E7EB; background: #fff; border-radius: 14px; padding: 12px 8px; font-weight: 800; font-size: 1.05rem; }
.cb-tabs button.on { border-color: #DC2626; background: #FEF2F2; color: #991B1B; }
.cb-n { display: inline-block; min-width: 26px; padding: 0 6px; border-radius: 13px; background: #111827; color: #fff; font-size: .85rem; }
.cb-card { background: #fff; border: 1px solid #E5E7EB; border-left: 6px solid; border-radius: 14px; padding: 12px 14px; margin-bottom: 10px; transition: box-shadow .2s; }
.cb-card.hit { box-shadow: 0 0 0 3px #FCA5A5; }
.cb-card.done { background: #F0FDF4; }
.cb-box { display: inline-block; padding: 6px 10px; margin: 0 6px 6px 0; border-radius: 10px; border: 1px dashed #9CA3AF; font-weight: 700; }
.cb-box.ok { background: #16A34A; border: 1px solid #16A34A; color: #fff; }
.cb-thumb img { width: 38px; height: 38px; object-fit: cover; border-radius: 8px; border: 1px solid #E5E7EB; }
.cb-photo-alert { background: #DC2626; color: #fff; border-radius: 16px; padding: 16px; animation: cbp 1.2s ease-in-out infinite alternate; }
@keyframes cbp { from { box-shadow: 0 0 0 0 rgba(220, 38, 38, .5); } to { box-shadow: 0 0 0 10px rgba(220, 38, 38, 0); } }
</style>

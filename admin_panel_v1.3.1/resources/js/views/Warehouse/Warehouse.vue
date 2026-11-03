<template>
    <!-- Custom (Lebazone): orders + warehouse in one place. -->
    <div class="wh">
        <div class="page-head d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h3 class="page-head-title mb-0">{{ tab === 'drivers' ? 'Drivers & zones' : 'Order Station' }}</h3>
            <div v-if="manager && tab !== 'drivers'" class="btn-group">
                <button class="btn" :class="tab === 'board' ? 'btn-primary' : 'btn-outline-primary'" @click="go('board')">Live board</button>
                <button class="btn" :class="tab === 'station' ? 'btn-primary' : 'btn-outline-primary'" @click="go('station')">📦 Pack</button>
                <button class="btn" :class="tab === 'print' ? 'btn-primary' : 'btn-outline-primary'" @click="go('print')">🖨 Print station</button>
                <button class="btn" :class="tab === 'staff' ? 'btn-primary' : 'btn-outline-primary'" @click="go('staff')">Staff</button>
            </div>
            <a v-else-if="manager" href="#" class="small" @click.prevent="$router.push('/delivery_boys')">Documents, bank details &amp; settlements → Delivery boys</a>
        </div>

        <!-- ============ PACK STATION ============ -->
        <PackStation v-show="tab === 'station'" ref="station" :staff="staff" :drivers="drivers" :app-name="appName"
            @changed="loadBoard" @go="go" />

        <!-- ============ BOARD ============ -->
        <div v-if="manager" v-show="tab === 'board'">
            <div class="row g-2 mb-3">
                <div v-for="s in steps" :key="'c' + s.key" class="col-6 col-md">
                    <button class="card w-100 text-center wh-count" :class="{ active: stageFilter === s.key }" @click="stageFilter = stageFilter === s.key ? '' : s.key">
                        <div class="card-body py-2">
                            <div class="small text-muted">{{ s.label }}</div>
                            <div class="fs-3 fw-bold">{{ base.filter(o => s.match.includes(o.stage)).length }}</div>
                        </div>
                    </button>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mb-2 small">
                <span class="badge bg-warning-subtle text-warning-emphasis border">🖨 Waiting to print: {{ printCounts.waiting }}</span>
                <span class="badge bg-success-subtle text-success-emphasis border">Printed, not started: {{ printCounts.printed }}</span>
            </div>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <button class="btn btn-sm" :class="!regionFilter ? 'btn-dark' : 'btn-outline-dark'" @click="regionFilter = ''">All regions ({{ orders.length }})</button>
                <button v-for="r in regions" :key="r.key" class="btn btn-sm text-white" :style="{ background: r.color, opacity: regionFilter && regionFilter !== r.key ? .45 : 1 }"
                    @click="regionFilter = r.key">{{ r.label }} ({{ orders.filter(o => o.region === r.key).length }})</button>
                <button class="btn btn-sm" :class="lateOnly ? 'btn-danger' : 'btn-outline-danger'" @click="lateOnly = !lateOnly">
                    ⚠ Late ({{ orders.filter(o => o.overdue).length }})</button>
                <button class="btn btn-sm btn-outline-secondary ms-auto" @click="loadBoard">Refresh</button>
            </div>
            <div class="card"><div class="card-body table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Order</th><th>Customer</th><th>Region</th><th>Step</th><th>Waiting</th><th>Employee</th><th>Total</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="o in shown" :key="o.id" :class="{ 'table-danger': o.overdue }">
                            <td class="fw-bold">{{ o.number }}<div class="small text-muted">{{ o.created_at.slice(0, 16) }}</div>
                                <div v-if="['new', 'assigned'].includes(o.stage)" class="small fw-normal" :class="o.printed_at ? 'text-success' : 'text-warning-emphasis'">{{ o.printed_at ? '🖨 printed' : '🖨 not printed yet' }}</div></td>
                            <td>{{ o.customer }}<div class="small text-muted">{{ o.phone }}</div></td>
                            <td>
                                <select class="form-select form-select-sm" :value="o.region" @change="setRegion(o, $event.target.value)" :disabled="o.stage === 'out'">
                                    <option v-for="r in regions" :key="r.key" :value="r.key">{{ r.label }}</option>
                                </select>
                            </td>
                            <td><span class="badge" :class="stageBadge(o.stage)">{{ o.stage_label }}</span>
                                <div v-if="o.boxes" class="small text-muted">{{ o.boxes }} box(es)</div></td>
                            <td :class="o.overdue ? 'text-danger fw-bold' : 'text-muted'">{{ duration(o.stage_minutes) }}</td>
                            <td>
                                <select class="form-select form-select-sm" :value="o.staff ? o.staff.id : ''" @change="assign(o, $event.target.value)"
                                    :disabled="['ready', 'out'].includes(o.stage)" title="Hand the order to another employee - progress is kept">
                                    <option value="">— anyone —</option>
                                    <option v-for="s in staff.filter(x => x.active)" :key="s.id" :value="s.id">{{ s.name }}</option>
                                </select>
                            </td>
                            <td>${{ money(o.total) }}<div class="small text-muted">{{ o.payment_method }}</div></td>
                            <td class="text-nowrap text-end">
                                <button v-if="!['ready', 'out'].includes(o.stage)" class="btn btn-sm btn-success me-1" @click="packIt(o)">Pack it</button>
                                <button class="btn btn-sm btn-outline-dark" @click="detailId = o.id; detailShow = true">Details</button>
                            </td>
                        </tr>
                        <tr v-if="!shown.length"><td colspan="8" class="text-center text-muted py-4">No orders here.</td></tr>
                    </tbody>
                </table>
            </div></div>
            <div class="mt-2">
                <button class="btn btn-outline-primary btn-sm" :disabled="!shown.filter(o => o.stage === 'new').length"
                    @click="printNewSheets">Print sheets for all new orders</button>
            </div>
        </div>

 <OrderDetailSlider v-if="manager" v-model="detailShow" :order-id="detailId" @updated="loadBoard" />

        <!-- ============ STAFF ============ -->
        <!-- ============ PRINT STATION ============ -->
        <div v-if="manager" v-show="tab === 'print'" class="row g-3">
            <div class="col-lg-7"><div class="card h-100"><div class="card-body">
                <h5>Print station</h5>
                <p class="text-muted">The touch screen next to the printers: tap an order and its sheet and box labels print. It also prints everything packers send from their phones.
                    It runs full screen, on its own login on the station PC.</p>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" @click="$router.push('/station')">Open the print station here</button>
                    <button class="btn btn-outline-primary" @click="openStationWindow">Open in a new window</button>
                </div>
            </div></div></div>
            <div class="col-lg-5"><div class="card h-100"><div class="card-body">
                <h6>Right now</h6>
                <div class="d-flex gap-3">
                    <div><div class="small text-muted">Waiting to print</div><div class="fs-3 fw-bold">{{ printCounts.waiting }}</div></div>
                    <div><div class="small text-muted">Printed, not started</div><div class="fs-3 fw-bold">{{ printCounts.printed }}</div></div>
                </div>
                <button class="btn btn-outline-secondary btn-sm mt-2" :disabled="!printCounts.waiting" @click="printWaitingSheets">Print sheets for orders not printed yet</button>
            </div></div></div>
        </div>

        <div v-if="manager" v-show="tab === 'staff' || tab === 'drivers'" class="row g-3">
            <!-- Custom (Lebazone): employees and drivers are added and edited in System users only -->
            <div class="col-12"><div class="card"><div class="card-body d-flex flex-wrap gap-3 align-items-center">
                <div class="me-auto">
                    <h6 class="mb-1">{{ tab === 'drivers' ? 'Drivers & their zones' : 'Employees' }}</h6>
                    <div class="small text-muted">Packers, product adders, drivers (with their zones), admins and the Order Station login are all added in <b>System users</b>.
                        You have {{ staff.length }} warehouse employee(s) and {{ drivers.length }} driver(s).</div>
                </div>
                <button class="btn btn-primary" @click="$router.push('/system_users')">Open System users</button>
            </div></div></div>
            <div class="col-12" v-show="tab === 'staff'"><div class="card"><div class="card-body">
                <h6>Time limits per step <small class="text-muted fw-normal">- after this many minutes the order turns red and admins get a bell alert (0 = no limit)</small></h6>
                <div class="d-flex flex-wrap gap-3 align-items-end">
                    <div v-for="(v, k) in sla" :key="k">
                        <label class="form-label small mb-1">{{ slaLabels[k] || k }}</label>
                        <input v-model.number="sla[k]" type="number" min="0" class="form-control form-control-sm" style="width:110px">
                    </div>
                    <button class="btn btn-primary btn-sm" @click="saveSla">Save limits</button>
                </div>
            </div></div></div>
            <div class="col-12" v-show="tab === 'staff'"><div class="card"><div class="card-body d-flex flex-wrap gap-3 align-items-center">
                <span class="small">Box label size</span>
                <select v-model="boxSizeVal" class="form-select form-select-sm" style="width:auto">
                    <option value="58x40">58 × 40 mm</option>
                    <option value="100x70">100 × 70 mm</option>
                    <option value="100x150">100 × 150 mm (4 × 6 in)</option>
                    <option value="a4">A4 sheet (8 per page)</option>
                </select>
            </div></div></div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import PackStation from './PackStation.vue';
import OrderDetailSlider from '../Orders/OrderDetailSlider.vue';
import { printSheets, printBadges, boxSize } from '../../utils/warehousePrint.js';

const blankDriver = () => ({ id: 0, name: '', mobile: '', login: '', password: '', regions: [] });
const blankStaff = () => ({ id: 0, name: '', phone: '', can_pack: 1, can_products: 0, login: '', password: '', active: 1, hasLogin: false });

const STEPS = [
    { key: 'new', label: 'New', match: ['new', 'assigned'] },
    { key: 'picking', label: 'Scanning', match: ['picking', 'picked'] },
    { key: 'packed', label: 'To stamp', match: ['packed'] },
    { key: 'stamped', label: 'To shelf', match: ['stamped'] },
    { key: 'ready', label: 'On shelf', match: ['ready'] },
    { key: 'out', label: 'With driver', match: ['out'] },
];

export default {
    components: { PackStation, OrderDetailSlider },
    data() {
        return {
            tab: 'station', orders: [], staff: [], drivers: [], regions: [], regionFilter: '', stageFilter: '', lateOnly: false,
            detailShow: false, detailId: null, sla: {}, slaLabels: {}, form: blankStaff(), dForm: blankDriver(), steps: STEPS,
            boxSizeVal: boxSize.get(), appName: 'Lebazone',
        };
    },
    computed: {
        manager() { return this.$can('order_list'); },
        loginUrl() { return window.location.origin + '/login'; },
        driverUrl() { return window.location.origin + '/login'; },
        suggestLogin() {
            const n = (this.form.name || '').trim().split(/\s+/)[0].toLowerCase().replace(/[^a-z0-9]/g, '');
            return (n || 'name') + '@' + window.location.hostname.replace(/^admin\./, '');
        },
        base() {
            let list = this.regionFilter ? this.orders.filter(o => o.region === this.regionFilter) : this.orders;
            if (this.lateOnly) list = list.filter(o => o.overdue);
            return list;
        },
        printCounts() {
            const fresh = this.orders.filter(o => ['new', 'assigned'].includes(o.stage));
            return { waiting: fresh.filter(o => !o.printed_at).length, printed: fresh.filter(o => o.printed_at).length };
        },
        shown() {
            const s = STEPS.find(x => x.key === this.stageFilter);
            return s ? this.base.filter(o => s.match.includes(o.stage)) : this.base;
        },
    },
    watch: {
        boxSizeVal(v) { boxSize.set(v); },
        '$route.query.tab': { immediate: true, handler(t) {
            if (!this.$can('order_list')) { this.tab = 'station'; return; }
            if (['station', 'board', 'print', 'staff', 'drivers'].includes(t)) { this.tab = t; if (t === 'board' || t === 'print') this.loadBoard(); }
            else this.tab = 'board'; // managers land on the Live board
        } },
    },
    mounted() {
        this.loadBoard();
        if (this.manager) axios.get(this.$apiUrl + '/warehouse/sla').then(r => { if (r.data.status === 1) { this.sla = r.data.data.limits; this.slaLabels = r.data.data.labels; } }).catch(() => {});
        if (this.manager) axios.get(this.$apiUrl + '/scan/options').then(r => { if (r.data.status === 1) this.appName = r.data.data.app_name || 'Lebazone'; }).catch(() => {});
        this.boardTimer = setInterval(() => { if (this.tab === 'board' && document.visibilityState === 'visible') this.loadBoard(); }, 20000);
    },
    beforeUnmount() { clearInterval(this.boardTimer); },
    methods: {
        go(t) { this.$router.replace({ query: { tab: t } }); },
        duration(m) { m = Number(m || 0); return m < 60 ? m + ' min' : Math.floor(m / 60) + ' h ' + (m % 60) + ' min'; },
        money(v) { return Number(v || 0).toFixed(2); },
        driverCode(d) { return 'DRV' + String(d.id).padStart(4, '0'); },
        stageBadge(s) { return { new: 'bg-secondary', assigned: 'bg-secondary', picking: 'bg-warning text-dark', picked: 'bg-warning text-dark',
            packed: 'bg-info text-dark', stamped: 'bg-info text-dark', ready: 'bg-success', out: 'bg-dark' }[s] || 'bg-primary'; },
        loadBoard() {
            axios.get(this.$apiUrl + '/warehouse/board').then(r => {
                if (r.data.status !== 1) return;
                const d = r.data.data;
                this.orders = d.orders; this.staff = d.staff; this.drivers = d.drivers; this.regions = d.regions;
            }).catch(() => {});
        },
        packIt(o) { this.go('station'); this.$nextTick(() => this.$refs.station.open(o.id)); },
        assign(o, staffId) {
            if (!staffId) return;
            axios.post(this.$apiUrl + '/warehouse/assign', { order_id: o.id, staff_id: staffId }).then(r => {
                if (r.data.status !== 1) return this.showError(r.data.message);
                this.showMessage('success', r.data.message); this.loadBoard();
            }).catch(e => this.showError(e.message));
        },
        setRegion(o, region) {
            axios.post(this.$apiUrl + '/warehouse/region', { order_id: o.id, region }).then(r => {
                if (r.data.status !== 1) return this.showError(r.data.message);
                this.loadBoard();
            });
        },
        blankDriver,
        editDriver(d) { this.dForm = { id: d.id, name: d.name, mobile: d.mobile, login: d.login || '', password: '', regions: [...(d.zones || [])] }; },
        saveDriver() {
            axios.post(this.$apiUrl + '/warehouse/driver_save', this.dForm).then(r => {
                if (r.data.status !== 1) return this.showError(r.data.message);
                this.showMessage('success', r.data.message);
                this.dForm = blankDriver();
                this.loadBoard();
            }).catch(e => this.showError(e?.response?.data?.message || e.message));
        },
        toggleZone(d, key) {
            const zones = (d.zones || []).includes(key) ? d.zones.filter(z => z !== key) : [...(d.zones || []), key];
            axios.post(this.$apiUrl + '/warehouse/driver_regions', { driver_id: d.id, regions: zones }).then(r => {
                if (r.data.status !== 1) return this.showError(r.data.message);
                d.zones = zones;
            }).catch(e => this.showError(e.message));
        },
        staffPayload(s) {
            return { id: s.id, name: s.name, phone: s.phone, active: s.active, can_pack: s.can_pack ?? 1, can_products: s.can_products ?? 0, login: s.login || '' };
        },
        editStaff(s) {
            this.form = { ...blankStaff(), ...this.staffPayload(s), password: '', hasLogin: !!s.login };
            window.scrollTo(0, 0);
        },
        resetForm() { this.form = blankStaff(); },
        saveStaff(s) {
            if (!String(s.name || '').trim()) return this.showError('Enter the employee name');
            const body = { ...s };
            if (s === this.form && !body.login && body.password) body.login = this.suggestLogin;
            axios.post(this.$apiUrl + '/warehouse/staff', body).then(r => {
                if (r.data.status !== 1) return this.showError(r.data.message);
                this.staff = r.data.data;
                if (s === this.form) { this.showMessage('success', s.id ? 'Employee saved' : 'Employee added'); this.resetForm(); }
            }).catch(e => this.showError(e?.response?.data?.message || e.message));
        },
        saveSla() {
            axios.post(this.$apiUrl + '/warehouse/sla', this.sla).then(r => {
                if (r.data.status !== 1) return this.showError(r.data.message);
                this.sla = r.data.data.limits; this.showMessage('success', 'Time limits saved'); this.loadBoard();
            });
        },
        badges(list) { printBadges(list, this.appName); },
        openStationWindow() { window.open(this.$router.resolve('/station').href, 'lebazone-station'); },
        async printWaitingSheets() {
            const full = [];
            for (const o of this.orders.filter(x => ['new', 'assigned'].includes(x.stage) && !x.printed_at)) {
                const r = await axios.get(this.$apiUrl + '/warehouse/order', { params: { id: o.id } });
                if (r.data.status === 1) full.push(r.data.data);
            }
            printSheets(full, this.appName);
        },
        async printNewSheets() {
            const full = [];
            for (const o of this.shown.filter(x => x.stage === 'new')) {
                const r = await axios.get(this.$apiUrl + '/warehouse/order', { params: { id: o.id } });
                if (r.data.status === 1) full.push(r.data.data);
            }
            printSheets(full, this.appName);
        },
    },
};
</script>

<style scoped>
.wh-count { border: 2px solid transparent; background: #fff; }
.wh-count.active { border-color: #DC2626; }
.wh-zone { border: 1px solid #D1D5DB; background: #fff; padding: 1px 8px; font-size: .8rem; }
</style>

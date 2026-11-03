<template>
    <!-- Custom (Lebazone): System users = the one place to add every employee —
         admins, packers, product adders, drivers and the Order Station login. Each job is saved
         through its own existing endpoint, so warehouse access and driver zones keep working. -->
    <div class="list-page su">
        <div class="page-head flex-wrap gap-2">
            <div>
                <h3 class="page-head-title mb-0">{{ __('system_users') }}</h3>
                <div class="small text-muted">Everyone who logs in: admins, packers, product adders, drivers and the Order Station screen.</div>
            </div>
            <button class="btn btn-primary list-add-btn ms-auto d-inline-flex align-items-center gap-2 text-nowrap" @click="openAdd()" v-if="isSuper">
                <Plus :size="16" /><span>Add employee</span>
            </button>
        </div>

        <!-- Custom (Lebazone): drivers who signed up in the driver app wait here for approval -->
        <div v-if="pendingDrivers > 0" class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
            <span><b>{{ pendingDrivers }}</b> driver sign-up request{{ pendingDrivers > 1 ? 's are' : ' is' }} waiting for approval.</span>
            <button class="btn btn-sm btn-warning ms-auto" @click="$router.push('/registered_delivery_boys')">Review requests</button>
        </div>

        <div class="list-surface">
            <div class="list-toolbar flex-wrap gap-2">
                <div class="list-search">
                    <Search class="list-search-icon" />
                    <input v-model="q" type="search" class="form-control" :placeholder="__('search')">
                </div>
                <div class="btn-group btn-group-sm flex-wrap">
                    <button v-for="j in jobFilters" :key="j.key" class="btn" :class="fJob === j.key ? 'btn-primary' : 'btn-outline-primary'" @click="fJob = j.key">
                        {{ j.label }} <span class="ms-1 opacity-75">{{ countJob(j.key) }}</span></button>
                </div>
                <a v-if="fJob === 'driver'" href="#" class="small ms-auto" @click.prevent="$router.push('/delivery_boys')">Disabled drivers &amp; all driver details →</a>
                <button class="list-icon-btn" :class="{ 'ms-auto': fJob !== 'driver' }" :title="__('refresh')" @click="load()"><RefreshCw :class="{ 'is-spinning': loading }" /></button>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Name</th><th>Job</th><th>Login</th><th>Zones</th><th class="text-end"></th></tr></thead>
                    <tbody>
                        <tr v-for="p in shown" :key="p.key" :class="{ 'text-muted': p.active === false }">
                            <td class="fw-semibold">{{ p.name }}<div class="small text-muted">{{ p.phone }}</div>
                                <span v-if="p.active === false" class="badge bg-secondary">disabled</span></td>
                            <td><span class="badge" :class="JOBS[p.job].badge">{{ JOBS[p.job].label }}</span>
                                <div v-if="p.job === 'admin'" class="small text-muted">{{ p.roleName }}</div></td>
                            <td class="small">{{ p.login ? $filters.emailMask(p.login) : '—' }}</td>
                            <td>
                                <template v-if="p.job === 'driver'">
                                    <button v-for="r in regions" :key="r.key" type="button" class="btn btn-sm me-1 mb-1 su-zone" :disabled="!isSuper"
                                        :style="(p.zones || []).includes(r.key) ? { background: r.color, borderColor: r.color, color: '#fff' } : {}"
                                        @click="toggleZone(p, r.key)">{{ r.label }}</button>
                                    <span v-if="!(p.zones || []).length" class="small text-warning">all regions</span>
                                </template>
                            </td>
                            <td class="text-end text-nowrap">
                                <template v-if="p.protected"><span class="small text-muted d-inline-flex align-items-center gap-1"><ShieldCheck :size="15" /> {{ __('protected') }}</span></template>
                                <template v-else-if="isSuper">
                                    <button class="btn btn-sm btn-outline-dark me-1" @click="openEdit(p)">Edit</button>
                                    <button v-if="p.job === 'driver'" class="btn btn-sm btn-outline-secondary me-1" @click="$router.push('/delivery_boys/view/' + p.src.id)" title="Documents, bank details, wallet and settlements">Full record</button>
                                    <button v-if="['packer', 'products', 'both'].includes(p.job)" class="btn btn-sm btn-outline-secondary" @click="toggleStaff(p)">{{ p.active ? 'Disable' : 'Enable' }}</button>
                                    <button v-if="['admin', 'station'].includes(p.job)" class="btn btn-sm" :class="armed === p.key ? 'btn-danger' : 'btn-outline-danger'" @click="remove(p)">{{ armed === p.key ? 'Click again' : __('delete') }}</button>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="!shown.length"><td colspan="5" class="text-center text-muted py-4">{{ loading ? 'Loading…' : 'No one here yet.' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- add / edit -->
        <div v-if="form" class="su-scrim" @click.self="form = null">
            <div class="su-drawer">
                <div class="su-drawer-h"><h5 class="mb-0">{{ form.edit ? 'Edit ' + JOBS[form.job].label.toLowerCase() : 'Add employee' }}</h5><button class="btn-close ms-auto" @click="form = null"></button></div>
                <div class="su-drawer-b">
                    <template v-if="!form.edit">
                        <label class="form-label small fw-semibold">Job</label>
                        <div class="su-jobs mb-3">
                            <button v-for="(j, k) in JOBS" :key="k" type="button" class="su-job" :class="{ on: form.job === k }" @click="form.job = k">
                                <b>{{ j.label }}</b><span>{{ j.hint }}</span></button>
                        </div>
                    </template>
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label small mb-1">Name *</label><input class="form-control" v-model="form.name" @input="autoLogin"></div>
                        <div class="col-md-6" v-if="['packer', 'products', 'both', 'driver'].includes(form.job)"><label class="form-label small mb-1">Phone{{ form.job === 'driver' ? ' *' : '' }}</label><input class="form-control" v-model="form.phone" inputmode="tel"></div>
                        <div class="col-md-6" v-if="form.job === 'admin'"><label class="form-label small mb-1">Role *</label>
                            <select class="form-select" v-model="form.role_id"><option value="">Choose…</option><option v-for="r in adminRoles" :key="r.id" :value="r.id">{{ r.name }}</option></select></div>
                        <div class="col-md-6"><label class="form-label small mb-1">Login email {{ needsLogin ? '*' : '(optional)' }}</label><input class="form-control" v-model="form.login" autocomplete="off" :placeholder="suggestLogin"></div>
                        <div class="col-md-6"><label class="form-label small mb-1">{{ form.edit ? 'New password (leave empty to keep)' : 'Password' + (needsLogin ? ' *' : '') }}</label>
                            <div class="input-group"><input :type="showPw ? 'text' : 'password'" class="form-control" v-model="form.password" autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary" @click="showPw = !showPw">{{ showPw ? 'Hide' : 'Show' }}</button></div></div>
                        <div class="col-12" v-if="form.job === 'driver'"><label class="form-label small mb-1">Delivery zones</label><div>
                            <button v-for="r in regions" :key="r.key" type="button" class="btn btn-sm me-1 mb-1 su-zone"
                                :style="form.regions.includes(r.key) ? { background: r.color, borderColor: r.color, color: '#fff' } : {}"
                                @click="form.regions = form.regions.includes(r.key) ? form.regions.filter(z => z !== r.key) : [...form.regions, r.key]">{{ r.label }}</button></div>
                            <div class="form-text">The driver only sees orders for these zones on the shelf. None ticked = all zones.</div></div>
                        <div class="col-12" v-if="form.job === 'driver' && form.edit">
                            <a href="#" class="small" @click.prevent="form = null; $router.push('/delivery_boys/edit/' + editId)">Documents, vehicle and bank details →</a></div>
                    </div>
                    <div class="alert alert-light border small mt-3 mb-0">{{ JOBS[form.job].what }}</div>
                </div>
                <div class="su-drawer-f">
                    <button class="btn btn-outline-secondary ms-auto" @click="form = null">{{ __('cancel') }}</button>
                    <button class="btn btn-primary" :disabled="busy" @click="save">{{ form.edit ? 'Save changes' : 'Add ' + JOBS[form.job].label.toLowerCase() }}</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import { Search, RefreshCw, Plus, ShieldCheck } from 'lucide-vue-next';

const WAREHOUSE_ROLES = ['Packer', 'Product entry', 'Packer + products'];
const JOBS = {
    admin: { label: 'Admin', hint: 'Full or partial panel access by role', badge: 'bg-dark', what: 'Signs in to the admin panel and sees what their role allows (Roles page).' },
    packer: { label: 'Packer', hint: 'Packs orders on phone or scanner', badge: 'bg-warning-subtle text-warning-emphasis', what: 'Sees only the Pack screen. A login is optional — without one they tap their name on the shared Pack screen.' },
    products: { label: 'Product adder', hint: 'Adds products by scanning', badge: 'bg-info-subtle text-info-emphasis', what: 'Sees only the Scan products screen to add products.' },
    both: { label: 'Packer + products', hint: 'Both warehouse jobs', badge: 'bg-primary-subtle text-primary-emphasis', what: 'Sees the Pack screen and the Scan products screen.' },
    driver: { label: 'Driver', hint: 'Delivers orders in chosen zones', badge: 'bg-success-subtle text-success-emphasis', what: 'Signs in on the same login page and lands on the driver screen. Documents, bank details and settlements are under Delivery boys → Full record.' },
    station: { label: 'Order Station', hint: 'Login for the printer touch screen', badge: 'bg-secondary-subtle text-secondary-emphasis', what: 'A shared login for the touch-screen PC next to the printers. It opens straight into the full-screen Print station.' },
};
const blank = (job = 'packer') => ({ edit: false, job, id: 0, name: '', phone: '', login: '', password: '', role_id: '', regions: [], active: 1, loginTouched: false });

export default {
    components: { Search, RefreshCw, Plus, ShieldCheck },
    data() {
        return { JOBS, loading: false, busy: false, admins: [], roles: [], staff: [], drivers: [], regions: [], appName: 'Lebazone',
            q: '', fJob: '', form: null, showPw: false, armed: null };
    },
    computed: {
        isSuper() { return this.$role('Super Admin'); },
        stationRole() { return this.roles.find(r => r.name === 'Order Station'); },
        adminRoles() { return this.roles.filter(r => ![...WAREHOUSE_ROLES, 'Order Station'].includes(r.name)); },
        jobFilters() { return [{ key: '', label: 'Everyone' }, { key: 'admin', label: 'Admins' }, { key: 'warehouse', label: 'Warehouse' }, { key: 'driver', label: 'Drivers' }, { key: 'station', label: 'Order Station' }]; },
        people() {
            const staffLogins = new Set(this.staff.map(s => (s.login || '').toLowerCase()).filter(Boolean));
            const list = [];
            this.admins.forEach(a => {
                const role = a.role ? a.role.name : '';
                if (WAREHOUSE_ROLES.includes(role) && staffLogins.has((a.email || '').toLowerCase())) return; // shown as warehouse staff
                list.push({ key: 'a' + a.id, src: a, job: role === 'Order Station' ? 'station' : 'admin', name: a.username, login: a.email, roleName: role, protected: role === 'Super Admin' });
            });
            this.staff.forEach(s => {
                const pack = +s.can_pack !== 0, prod = +s.can_products === 1;
                list.push({ key: 's' + s.id, src: s, job: pack && prod ? 'both' : prod ? 'products' : 'packer', name: s.name, phone: s.phone, login: s.login, code: s.code, active: !!s.active });
            });
            this.drivers.forEach(d => list.push({ key: 'd' + d.id, src: d, job: 'driver', name: d.name, phone: d.mobile, login: d.login, zones: d.zones || [], code: 'DRV' + String(d.id).padStart(4, '0') }));
            return list;
        },
        shown() {
            const q = this.q.trim().toLowerCase();
            return this.people.filter(p => {
                if (this.fJob === 'warehouse' && !['packer', 'products', 'both'].includes(p.job)) return false;
                if (this.fJob && this.fJob !== 'warehouse' && p.job !== this.fJob) return false;
                return !q || [p.name, p.login, p.phone, JOBS[p.job].label].join(' ').toLowerCase().includes(q);
            });
        },
        needsLogin() { return this.form && !['packer'].includes(this.form.job) && !this.form.edit; },
        suggestLogin() {
            const n = ((this.form && this.form.name) || '').trim().split(/\s+/)[0].toLowerCase().replace(/[^a-z0-9]/g, '');
            return (n || 'name') + '@' + window.location.hostname.replace(/^admin\./, '');
        },
    },
    created() { this.load(); },
    methods: {
        countJob(k) { return k === '' ? this.people.length : k === 'warehouse' ? this.people.filter(p => ['packer', 'products', 'both'].includes(p.job)).length : this.people.filter(p => p.job === k).length; },
        load() {
            this.loading = true;
            Promise.all([
                axios.get(this.$apiUrl + '/system_users').then(r => { this.admins = r.data.data.records || []; this.roles = r.data.data.roles || []; }),
                axios.get(this.$apiUrl + '/warehouse/board').then(r => { if (r.data.status === 1) { const d = r.data.data; this.staff = d.staff || []; this.drivers = d.drivers || []; this.regions = d.regions || []; } }).catch(() => {}),
            ]).finally(() => { this.loading = false; });
        },
        autoLogin() { /* the placeholder shows the suggestion; nothing is filled in silently */ },
        openAdd() { this.form = blank(); this.showPw = false; },
        openEdit(p) {
            const s = p.src;
            const f = { ...blank(p.job), edit: true, id: s.id, name: p.name, phone: p.phone || '', login: p.login || '' };
            if (p.job === 'admin' || p.job === 'station') f.role_id = s.role_id;
            if (p.job === 'driver') f.regions = [...(p.zones || [])];
            if (['packer', 'products', 'both'].includes(p.job)) f.active = s.active ? 1 : 0;
            this.form = f; this.showPw = false;
        },
        post(url, data) {
            return axios.post(this.$apiUrl + url, data).then(r => {
                if (r.data.status === 1) { this.showMessage('success', r.data.message || 'Saved'); return true; }
                this.showError(r.data.message); return false;
            }).catch(e => { this.showError(e.response?.data?.message || e.message); return false; });
        },
        async save() {
            const f = this.form;
            if (!f.name.trim()) return this.showError('Enter the name');
            const login = (f.login || '').trim() || (f.password ? this.suggestLogin : '');
            let ok = false;
            this.busy = true;
            if (f.job === 'admin' || f.job === 'station') {
                const role_id = f.job === 'station' ? (this.stationRole && this.stationRole.id) : f.role_id;
                if (!role_id) { this.busy = false; return this.showError(f.job === 'station' ? 'The Order Station role is missing — run apply-updates.bat' : 'Choose a role'); }
                if (!login) { this.busy = false; return this.showError('Enter a login email'); }
                const body = { username: f.name.trim(), email: login, role_id, password: f.password, confirm_password: f.password };
                if (f.edit) ok = await this.post('/system_users/update', { ...body, id: f.id });
                else { if (!f.password) { this.busy = false; return this.showError('Choose a password'); } ok = await this.post('/system_users/save', body); }
            } else if (f.job === 'driver') {
                ok = await this.post('/warehouse/driver_save', { id: f.edit ? f.id : 0, name: f.name.trim(), mobile: f.phone, login, password: f.password, regions: f.regions });
            } else {
                ok = await this.post('/warehouse/staff', { id: f.edit ? f.id : 0, name: f.name.trim(), phone: f.phone, can_pack: f.job === 'products' ? 0 : 1,
                    can_products: f.job === 'packer' ? 0 : 1, active: f.active, login: (f.login || '').trim() || (f.password ? this.suggestLogin : ''), password: f.password });
            }
            this.busy = false;
            if (ok) { this.form = null; this.load(); }
        },
        toggleStaff(p) {
            const s = p.src;
            this.post('/warehouse/staff', { id: s.id, name: s.name, phone: s.phone, active: s.active ? 0 : 1, can_pack: s.can_pack ?? 1, can_products: s.can_products ?? 0, login: s.login || '' }).then(ok => ok && this.load());
        },
        toggleZone(p, key) {
            const zones = p.zones.includes(key) ? p.zones.filter(z => z !== key) : [...p.zones, key];
            axios.post(this.$apiUrl + '/warehouse/driver_regions', { driver_id: p.src.id, regions: zones }).then(r => {
                if (r.data.status !== 1) return this.showError(r.data.message);
                p.src.zones = zones; this.drivers = [...this.drivers];
            }).catch(e => this.showError(e.message));
        },
        remove(p) {
            if (this.armed !== p.key) { this.armed = p.key; setTimeout(() => { if (this.armed === p.key) this.armed = null; }, 3500); return; }
            this.armed = null;
            this.post('/system_users/delete', { id: p.src.id }).then(ok => ok && this.load());
        },
    },
};
</script>

<style scoped>
.su-zone { border: 1px solid var(--bs-border-color); background: var(--bs-body-bg); padding: 1px 8px; font-size: .8rem; }
.su-scrim { position: fixed; inset: 0; background: rgba(0, 0, 0, .4); z-index: 1050; }
.su-drawer { position: absolute; top: 0; right: 0; bottom: 0; width: min(620px, 100%); background: var(--bs-body-bg); display: flex; flex-direction: column; box-shadow: 0 10px 30px rgba(0, 0, 0, .2); }
.su-drawer-h, .su-drawer-f { display: flex; gap: 8px; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--bs-border-color); }
.su-drawer-f { border-bottom: 0; border-top: 1px solid var(--bs-border-color); }
.su-drawer-b { flex: 1; overflow-y: auto; padding: 16px; }
.su-jobs { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px; }
.su-job { text-align: left; border: 1px solid var(--bs-border-color); border-radius: 10px; padding: 10px 12px; background: var(--bs-body-bg); display: flex; flex-direction: column; gap: 2px; }
.su-job span { font-size: 12px; color: var(--bs-secondary-color); }
.su-job.on { border-color: var(--bs-primary); box-shadow: 0 0 0 2px var(--bs-primary-bg-subtle); }
</style>

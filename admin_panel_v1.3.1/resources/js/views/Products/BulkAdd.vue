<template>
    <!-- Custom (Lebazone): bulk upload from an Excel sheet + the photos it names. -->
    <div class="ba">
        <div class="page-head flex-wrap gap-2">
            <div>
                <h3 class="page-head-title mb-0">Bulk upload products · رفع منتجات من ملف Excel</h3>
                <div class="small text-muted">Fill the sample sheet, then upload it with the photos it names.</div>
            </div>
            <router-link to="/scan" class="btn btn-outline-secondary ms-auto">← Scan products</router-link>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-lg-4">
                <div class="card h-100"><div class="card-body">
                    <div class="ba-step">1</div>
                    <h5>Download the sample</h5>
                    <p class="small text-muted">One row per product. The <b>How to fill</b> tab explains every column; Category and Box size have dropdowns.</p>
                    <button type="button" class="btn btn-outline-primary" :disabled="busy" @click="downloadSample">⬇ Download sample (.xlsx)</button>
                </div></div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100"><div class="card-body">
                    <div class="ba-step">2</div>
                    <h5>Upload your sheet</h5>
                    <p class="small text-muted">The filled .xlsx file. You see every product before anything is saved.</p>
                    <label class="btn btn-primary mb-0" :class="{ disabled: busy }">
                        📄 Choose Excel file
                        <input type="file" accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden @change="uploadSheet">
                    </label>
                    <div v-if="sheetName" class="small mt-2">✓ {{ sheetName }} · {{ rows.length }} row(s)</div>
                </div></div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100"><div class="card-body">
                    <div class="ba-step">3</div>
                    <h5>Add the photos</h5>
                    <p class="small text-muted">Select all the photos named in the <b>Photos</b> column (you can select many at once).</p>
                    <label class="btn btn-primary mb-0" :class="{ disabled: !rows.length || busy }">
                        🖼 Choose photos
                        <input type="file" accept="image/*" multiple hidden :disabled="!rows.length" @change="addPhotos">
                    </label>
                    <div v-if="preparing" class="small text-muted mt-2">Preparing photos…</div>
                    <div v-else-if="photoCount" class="small mt-2">✓ {{ photoCount }} photo(s) · <span :class="missing.length ? 'text-danger' : 'text-success'">{{ missing.length ? missing.length + ' named photo(s) not found' : 'all named photos found' }}</span></div>
                </div></div>
            </div>
        </div>

        <div v-if="groups.length" class="card"><div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <h5 class="mb-0">Check before saving</h5>
                <span class="small text-muted">{{ groups.length }} product(s) from {{ rows.length }} row(s) · rows with the same Name become one product with several colours</span>
            </div>
            <div v-if="missing.length" class="alert alert-warning py-2 small">
                Photos named in the sheet but not chosen yet: <b>{{ missing.slice(0, 12).join(', ') }}</b><span v-if="missing.length > 12"> and {{ missing.length - 12 }} more</span>.
                Choose them in step 3, or the products are saved without them.
            </div>
            <div class="table-responsive">
                <table class="table align-middle ba-table">
                    <thead><tr><th>Photos</th><th>Product</th><th>Colours · qty</th><th class="text-end">Price</th><th>Category · size</th><th>Status</th></tr></thead>
                    <tbody>
                        <tr v-for="g in groups" :key="g.key" :class="'is-' + g.state">
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    <template v-for="r in g.rows" :key="'p' + r.row">
                                        <template v-for="(n, j) in r.photos" :key="r.row + n">
                                            <img v-if="photoUrl(n)" :src="photoUrl(n)" :alt="n" class="ba-th" :class="{ main: r === g.rows[0] && j === 0 }" :title="n">
                                            <span v-else class="ba-th ba-miss" :title="n + ' - not chosen'">?</span>
                                        </template>
                                    </template>
                                    <span v-if="!g.rows.some(r => r.photos.length)" class="small text-muted">no photo</span>
                                </div>
                            </td>
                            <td><div class="fw-semibold">{{ g.rows[0].name || '(no name)' }}</div><div class="small text-muted">row {{ g.rows.map(r => r.row).join(', ') }}</div></td>
                            <td class="small">
                                <div v-for="r in g.rows" :key="'c' + r.row">{{ r.color || 'One colour' }} · {{ r.stock }} pcs<span v-if="r.barcode" class="text-muted"> · {{ r.barcode }}</span></div>
                            </td>
                            <td class="text-end text-nowrap">
                                <template v-if="g.rows[0].price != null">${{ money(g.rows[0].sale_price != null ? g.rows[0].sale_price : g.rows[0].price) }}
                                    <div v-if="g.rows[0].sale_price != null" class="small text-muted text-decoration-line-through">${{ money(g.rows[0].price) }}</div></template>
                            </td>
                            <td class="small">{{ g.rows[0].category }} · {{ g.rows[0].size }}</td>
                            <td class="small" style="min-width: 160px">
                                <span v-if="g.state === 'saved'" class="text-success fw-semibold">Saved ✓ {{ g.code }}</span>
                                <span v-else-if="g.state === 'saving'" class="text-info">Saving…</span>
                                <span v-else-if="g.errors.length" class="text-danger">{{ g.errors.join(' · ') }}</span>
                                <span v-else class="text-muted">Ready</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div></div>

        <div v-if="groups.length" class="ba-bar">
            <span class="small">{{ ready.length }} ready · {{ blocked }} need a fix in the sheet · {{ savedCount }} saved</span>
            <button type="button" class="btn btn-primary btn-lg" :disabled="saving || preparing > 0 || !ready.length" @click="saveAll">
                {{ saving ? 'Saving ' + progress + '…' : 'Save ' + ready.length + ' product(s)' }}</button>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import { shrinkImage } from '../../utils/shrinkImage.js';

const key = n => String(n || '').trim().toLowerCase();
const stem = n => key(n).replace(/\.[a-z0-9]+$/, '');

export default {
    data() {
        return { rows: [], groups: [], sheetName: '', files: {}, preparing: 0, busy: false, saving: false, progress: '' };
    },
    computed: {
        photoCount() { return Object.keys(this.files).length; },
        missing() {
            const out = [];
            for (const r of this.rows) for (const n of r.photos) if (!this.fileFor(n) && !out.includes(n)) out.push(n);
            return out;
        },
        ready() { return this.groups.filter(g => g.state !== 'saved' && !g.errors.length); },
        blocked() { return this.groups.filter(g => g.state !== 'saved' && g.errors.length).length; },
        savedCount() { return this.groups.filter(g => g.state === 'saved').length; },
    },
    beforeUnmount() { Object.values(this.files).forEach(f => URL.revokeObjectURL(f.url)); },
    methods: {
        money(v) { return Number(v || 0).toFixed(2); },
        downloadSample() {
            this.busy = true;
            axios.get(this.$apiUrl + '/scan/bulk_sample', { responseType: 'blob' }).then(r => {
                const url = URL.createObjectURL(r.data);
                const a = document.createElement('a');
                a.href = url; a.download = 'lebazone-products-sample.xlsx';
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(() => URL.revokeObjectURL(url), 2000);
            }).catch(e => this.showError(e?.response?.data?.message || e.message)).finally(() => { this.busy = false; });
        },
        uploadSheet(e) {
            const f = e.target.files && e.target.files[0];
            e.target.value = '';
            if (!f) return;
            const fd = new FormData();
            fd.append('file', f);
            this.busy = true;
            axios.post(this.$apiUrl + '/scan/bulk_parse', fd).then(r => {
                if (r.data.status !== 1) return this.showError(r.data.message);
                this.rows = r.data.data.rows;
                this.sheetName = f.name;
                this.buildGroups();
            }).catch(e2 => this.showError(e2?.response?.data?.message || e2.message)).finally(() => { this.busy = false; });
        },
        // rows with the same name = one product, each row one colour
        buildGroups() {
            const map = new Map();
            for (const r of this.rows) {
                const k = key(r.name) || ('row' + r.row);
                if (!map.has(k)) map.set(k, { key: k, rows: [], state: 'new', code: '', errors: [] });
                map.get(k).rows.push(r);
            }
            this.groups = [...map.values()].map(g => {
                const errs = [];
                g.rows.forEach(r => r.errors.forEach(x => errs.push((g.rows.length > 1 ? 'row ' + r.row + ': ' : '') + x)));
                if (g.rows.length > 1) {
                    const cols = g.rows.map(r => key(r.color));
                    if (cols.some(c => !c)) errs.push('several rows with this name - give each a Colour');
                    else if (new Set(cols).size !== cols.length) errs.push('the same colour appears twice');
                }
                return { ...g, errors: errs };
            });
        },
        fileFor(name) { return this.files[key(name)] || this.files['~' + stem(name)] || null; },
        photoUrl(name) { const f = this.fileFor(name); return f ? f.url : ''; },
        async addPhotos(e) {
            const list = Array.from(e.target.files || []).filter(f => (f.type || '').startsWith('image/'));
            e.target.value = '';
            this.preparing++;
            try {
                for (const f of list) {
                    const small = await shrinkImage(f);
                    const entry = { file: small, url: URL.createObjectURL(small) };
                    const old = this.files[key(f.name)];
                    if (old) URL.revokeObjectURL(old.url);
                    // match by the exact file name, or by the name without its extension
                    this.files = { ...this.files, [key(f.name)]: entry, ['~' + stem(f.name)]: entry };
                }
            } finally { this.preparing--; }
        },
        async saveAll() {
            const todo = this.ready;
            this.saving = true;
            let ok = 0, n = 0;
            for (const g of todo) {
                this.progress = (++n) + '/' + todo.length;
                const first = g.rows[0];
                const fd = new FormData();
                fd.append('name', first.name);
                fd.append('name_ar', first.name_ar || '');
                fd.append('description', first.description || '');
                fd.append('category_id', first.category_id);
                fd.append('price', first.price);
                fd.append('sale_price', first.sale_price != null ? first.sale_price : '');
                fd.append('size', first.size);
                fd.append('warranty', '1 year');
                fd.append('colors', JSON.stringify(g.rows.map(r => ({ color: r.color || '', barcode: r.barcode || '', stock: r.stock || 0 }))));
                // first row's first photo = the product's main photo, its other photos the gallery;
                // each further colour gets its own first photo
                g.rows.forEach((r, i) => {
                    const found = r.photos.map(p => this.fileFor(p)).filter(Boolean);
                    if (!found.length) return;
                    if (i === 0) { fd.append('photo', found[0].file); found.slice(1).forEach(x => fd.append('photos[]', x.file)); }
                    else { fd.append('photo_' + i, found[0].file); found.slice(1).forEach(x => fd.append('photos[]', x.file)); }
                });
                g.state = 'saving';
                try {
                    const res = await axios.post(this.$apiUrl + '/scan/create', fd);
                    if (res.data.status === 1) {
                        g.state = 'saved'; ok++;
                        g.code = ((res.data.data || {}).variants || [])[0]?.code || '';
                    } else { g.state = 'new'; g.errors = [res.data.message || 'Could not save']; }
                } catch (err) {
                    g.state = 'new'; g.errors = [err?.response?.data?.message || err.message];
                }
            }
            this.saving = false; this.progress = '';
            const failed = todo.length - ok;
            if (failed) this.showError(ok + ' saved, ' + failed + ' could not be saved (see Status)');
            else this.showMessage('success', ok + ' product(s) saved');
        },
    },
};
</script>

<style scoped>
.ba { padding-bottom: 90px; }
.ba-step { width: 30px; height: 30px; border-radius: 50%; background: #DC2626; color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center; margin-bottom: 8px; }
.ba-th { width: 44px; height: 44px; border-radius: 6px; object-fit: cover; border: 1px solid #D1D5DB; }
.ba-th.main { border: 2px solid #DC2626; }
.ba-miss { display: inline-flex; align-items: center; justify-content: center; background: #FEF2F2; color: #B91C1C; font-weight: 700; }
.ba-table tr.is-saved { background: #F0FDF4; }
.ba-bar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 1030; display: flex; align-items: center; justify-content: flex-end; gap: 10px;
    padding: 10px 16px; background: var(--bs-body-bg, #fff); border-top: 1px solid #E5E7EB; box-shadow: 0 -4px 14px rgba(0, 0, 0, .06); }
</style>

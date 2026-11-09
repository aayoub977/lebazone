<template>
    <!-- Custom (Lebazone): add many products at once - pick photos, fill a row per product, save all. -->
    <div class="ba">
        <div class="page-head flex-wrap gap-2">
            <div>
                <h3 class="page-head-title mb-0">Bulk add products · إضافة منتجات دفعة واحدة</h3>
                <div class="small text-muted">Pick photos (one product per photo), fill in each row, then save them all.</div>
            </div>
            <router-link to="/scan" class="btn btn-outline-secondary ms-auto">← Scan products</router-link>
        </div>

        <!-- defaults -->
        <div class="card mb-3"><div class="card-body">
            <div class="fw-bold mb-2">For new rows · الإعدادات الافتراضية</div>
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small mb-1" for="ba-cat">Category</label>
                    <select id="ba-cat" v-model="def.category_id" class="form-select">
                        <option value="">Choose…</option>
                        <option v-for="c in opts.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <span class="form-label small mb-1 d-block">Box size</span>
                    <div class="btn-group w-100" role="group" aria-label="Box size">
                        <button v-for="z in SIZES" :key="'d' + z" type="button" class="btn" :class="def.size === z ? 'btn-dark' : 'btn-outline-secondary'" @click="def.size = z">{{ z }}</button>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="ba-qty">Quantity</label>
                    <input id="ba-qty" v-model.number="def.stock" type="number" min="0" inputmode="numeric" class="form-control">
                </div>
            </div>
            <button v-if="rows.length" type="button" class="btn btn-link btn-sm px-0 mt-1" @click="applyDefaults">Apply category, size and quantity to all unsaved rows</button>
        </div></div>

        <!-- add -->
        <div class="d-flex flex-wrap gap-2 mb-3">
            <label class="btn btn-primary btn-lg mb-0">
                🖼 Pick photos - one product per photo
                <input type="file" accept="image/*" multiple hidden @change="addFromPhotos">
            </label>
            <label class="btn btn-outline-primary btn-lg mb-0">
                📷 Take a photo
                <input type="file" accept="image/*" capture="environment" hidden @change="addFromPhotos">
            </label>
            <button type="button" class="btn btn-outline-secondary btn-lg" @click="addRow()">+ Empty row</button>
        </div>
        <div v-if="preparing" class="small text-muted mb-2">Preparing photos…</div>
        <div v-if="!rows.length" class="card"><div class="card-body text-center text-muted py-5">
            No products yet. Pick photos above - every photo becomes a product row.<br>
            Several photos of the same product? Pick them all, then tap <b>"Same product as above"</b> on the extra rows.
        </div></div>

        <!-- rows -->
        <div v-for="(r, i) in rows" :key="r.key" class="card mb-2 ba-row" :class="'is-' + r.state">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <b>#{{ i + 1 }}</b>
                    <span v-if="r.state === 'saved'" class="badge bg-success">Saved ✓ {{ r.code }}</span>
                    <span v-else-if="r.state === 'saving'" class="badge bg-info text-dark">Saving…</span>
                    <span v-else-if="r.state === 'error'" class="badge bg-danger">Not saved</span>
                    <span v-if="r.state === 'error'" class="small text-danger">{{ r.error }}</span>
                    <span class="ms-auto"></span>
                    <button v-if="i > 0 && r.state !== 'saved'" type="button" class="btn btn-sm btn-outline-secondary" @click="mergeUp(i)" title="Move this row's photos into the product above">Same product as above ↑</button>
                    <button v-if="r.state !== 'saved' && r.state !== 'saving'" type="button" class="btn btn-sm btn-outline-danger" :aria-label="'Remove row ' + (i + 1)" @click="removeRow(i)">×</button>
                </div>

                <fieldset :disabled="r.state === 'saved' || r.state === 'saving'">
                    <div class="ba-photos mb-2">
                        <div v-for="(m, j) in r.photos" :key="m.url" class="ba-thumb" :class="{ main: j === r.main }">
                            <img :src="m.url" alt="">
                            <button type="button" class="ba-x" :aria-label="'Remove photo ' + (j + 1)" @click="removePhoto(r, j)">×</button>
                            <span v-if="j === r.main" class="ba-main">★ Main</span>
                            <button v-else type="button" class="ba-star" @click="r.main = j">☆ Main</button>
                        </div>
                        <label v-if="r.photos.length < 10" class="ba-thumb ba-add">
                            <span>+ Photo</span>
                            <input type="file" accept="image/*" multiple hidden @change="e => addPhotos(r, e)">
                        </label>
                    </div>
                    <div class="row g-2">
                        <div class="col-lg-4 col-12">
                            <label class="form-label small mb-1" :for="'n' + r.key">Name *</label>
                            <input :id="'n' + r.key" v-model="r.name" class="form-control" placeholder="Product name">
                        </div>
                        <div class="col-lg-2 col-4">
                            <label class="form-label small mb-1" :for="'p' + r.key">Price $ *</label>
                            <input :id="'p' + r.key" v-model="r.price" type="number" min="0" step="0.01" inputmode="decimal" class="form-control">
                        </div>
                        <div class="col-lg-2 col-4">
                            <label class="form-label small mb-1" :for="'s' + r.key">Sale $</label>
                            <input :id="'s' + r.key" v-model="r.sale_price" type="number" min="0" step="0.01" inputmode="decimal" class="form-control" placeholder="none">
                        </div>
                        <div class="col-lg-2 col-4">
                            <label class="form-label small mb-1" :for="'q' + r.key">Quantity</label>
                            <input :id="'q' + r.key" v-model.number="r.stock" type="number" min="0" inputmode="numeric" class="form-control">
                        </div>
                        <div class="col-lg-2 col-6">
                            <label class="form-label small mb-1" :for="'c' + r.key">Colour</label>
                            <input :id="'c' + r.key" v-model="r.color" list="ba-colors" class="form-control" placeholder="optional">
                        </div>
                        <div class="col-lg-4 col-6">
                            <label class="form-label small mb-1" :for="'k' + r.key">Category *</label>
                            <select :id="'k' + r.key" v-model="r.category_id" class="form-select">
                                <option value="">Choose…</option>
                                <option v-for="c in opts.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                        <div class="col-lg-4 col-6">
                            <span class="form-label small mb-1 d-block">Box size *</span>
                            <div class="btn-group w-100" role="group" aria-label="Box size">
                                <button v-for="z in SIZES" :key="r.key + z" type="button" class="btn btn-sm" :class="r.size === z ? 'btn-dark' : 'btn-outline-secondary'" @click="r.size = z">{{ z }}</button>
                            </div>
                        </div>
                        <div class="col-lg-4 col-12">
                            <label class="form-label small mb-1" :for="'b' + r.key">Box barcode</label>
                            <input :id="'b' + r.key" v-model="r.barcode" class="form-control" placeholder="optional - scan or type">
                        </div>
                    </div>
                </fieldset>
            </div>
        </div>
        <datalist id="ba-colors"><option v-for="c in opts.colors" :key="c" :value="c" /></datalist>

        <div v-if="rows.length" class="ba-bar">
            <span class="small">{{ unsaved.length }} to save · {{ savedCount }} saved</span>
            <button v-if="savedCount" type="button" class="btn btn-outline-secondary" :disabled="saving" @click="clearSaved">Clear saved</button>
            <button type="button" class="btn btn-primary btn-lg" :disabled="saving || preparing > 0 || !unsaved.length" @click="saveAll">
                {{ saving ? 'Saving ' + progress + '…' : 'Save all (' + unsaved.length + ')' }}</button>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import { shrinkImage } from '../../utils/shrinkImage.js';

const SIZES = ['S', 'M', 'L', 'XL'];
let seq = 0;

export default {
    data() {
        let last = '';
        try { last = localStorage.getItem('lbzLastCategory') || ''; } catch (e) {}
        return {
            SIZES, opts: { categories: [], colors: [] }, rows: [], preparing: 0, saving: false, progress: '',
            def: { category_id: last ? Number(last) || '' : '', size: 'S', stock: 1 },
        };
    },
    computed: {
        unsaved() { return this.rows.filter(r => r.state !== 'saved'); },
        savedCount() { return this.rows.length - this.unsaved.length; },
    },
    created() {
        axios.get(this.$apiUrl + '/scan/options').then(r => { if (r.data.status === 1) this.opts = r.data.data; });
    },
    beforeUnmount() { this.rows.forEach(r => r.photos.forEach(m => URL.revokeObjectURL(m.url))); },
    methods: {
        newRow(photos = []) {
            return { key: ++seq, photos, main: 0, name: '', price: '', sale_price: '', stock: this.def.stock, color: '', barcode: '',
                category_id: this.def.category_id, size: this.def.size, state: 'new', error: '', code: '' };
        },
        addRow(photos = []) { this.rows.push(this.newRow(photos)); },
        async addFromPhotos(e) {
            const files = Array.from(e.target.files || []).filter(f => (f.type || '').startsWith('image/')).slice(0, 60);
            e.target.value = '';
            this.preparing++;
            try {
                for (const f of files) { // one at a time keeps memory low on phones
                    const s = await shrinkImage(f);
                    this.addRow([{ file: s, url: URL.createObjectURL(s) }]);
                }
            } finally { this.preparing--; }
        },
        async addPhotos(r, e) {
            const files = Array.from(e.target.files || []).filter(f => (f.type || '').startsWith('image/')).slice(0, 10 - r.photos.length);
            e.target.value = '';
            this.preparing++;
            try {
                for (const f of files) { const s = await shrinkImage(f); r.photos.push({ file: s, url: URL.createObjectURL(s) }); }
            } finally { this.preparing--; }
        },
        removePhoto(r, j) {
            URL.revokeObjectURL(r.photos[j].url);
            r.photos.splice(j, 1);
            if (j < r.main) r.main--; else if (j === r.main) r.main = 0;
            if (r.main >= r.photos.length) r.main = 0;
        },
        removeRow(i) {
            this.rows[i].photos.forEach(m => URL.revokeObjectURL(m.url));
            this.rows.splice(i, 1);
        },
        // several photos of one product: fold this row into the one above
        mergeUp(i) {
            const up = this.rows[i - 1], r = this.rows[i];
            if (up.state === 'saved') return this.showError('The row above is already saved - add the photos there with "+ Photo" before saving');
            up.photos.push(...r.photos.slice(0, Math.max(0, 10 - up.photos.length)));
            for (const k of ['name', 'price', 'sale_price', 'color', 'barcode']) if (!up[k] && r[k]) up[k] = r[k];
            this.rows.splice(i, 1);
        },
        applyDefaults() {
            for (const r of this.unsaved) { r.category_id = this.def.category_id; r.size = this.def.size; r.stock = this.def.stock; }
        },
        clearSaved() {
            this.rows.filter(r => r.state === 'saved').forEach(r => r.photos.forEach(m => URL.revokeObjectURL(m.url)));
            this.rows = this.unsaved;
        },
        problem(r) {
            if (!r.name.trim()) return 'Enter the name';
            if (!(+r.price > 0)) return 'Enter the price';
            if (r.sale_price !== '' && +r.sale_price > +r.price) return 'Sale price is higher than the price';
            if (!r.category_id) return 'Choose a category';
            if (!SIZES.includes(r.size)) return 'Choose the box size';
            return '';
        },
        async saveAll() {
            const todo = this.unsaved;
            this.saving = true;
            let ok = 0, n = 0;
            for (const r of todo) {
                this.progress = (++n) + '/' + todo.length;
                const bad = this.problem(r);
                if (bad) { r.state = 'error'; r.error = bad; continue; }
                r.state = 'saving'; r.error = '';
                const fd = new FormData();
                fd.append('name', r.name.trim());
                fd.append('category_id', r.category_id);
                fd.append('price', r.price);
                fd.append('sale_price', r.sale_price === '' ? '' : r.sale_price);
                fd.append('size', r.size);
                fd.append('warranty', '1 year');
                fd.append('colors', JSON.stringify([{ color: (r.color || '').trim(), barcode: (r.barcode || '').trim(), stock: +r.stock || 0 }]));
                r.photos.forEach((m, j) => fd.append(j === r.main ? 'photo' : 'photos[]', m.file));
                try {
                    const res = await axios.post(this.$apiUrl + '/scan/create', fd);
                    if (res.data.status === 1) {
                        r.state = 'saved'; ok++;
                        r.code = (res.data.data && res.data.data.variants && res.data.data.variants[0] && res.data.data.variants[0].code) || '';
                        try { localStorage.setItem('lbzLastCategory', String(r.category_id)); } catch (e) {}
                    } else { r.state = 'error'; r.error = res.data.message || 'Could not save'; }
                } catch (e) {
                    r.state = 'error'; r.error = e?.response?.data?.message || e.message;
                }
            }
            this.saving = false; this.progress = '';
            const failed = todo.length - ok;
            if (failed) this.showError(ok + ' saved, ' + failed + ' need a fix (marked in red)');
            else this.showMessage('success', ok + ' product(s) saved');
        },
    },
};
</script>

<style scoped>
.ba { padding-bottom: 90px; }
.ba-row.is-saved { opacity: .7; border-color: #16A34A; }
.ba-row.is-error { border-color: #DC2626; }
.ba-photos { display: flex; flex-wrap: wrap; gap: 8px; }
.ba-thumb { position: relative; width: 84px; height: 84px; border: 1px solid #D1D5DB; border-radius: 10px; overflow: hidden; background: #fff; margin: 0; }
.ba-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ba-thumb.main { border: 3px solid #DC2626; }
.ba-x { position: absolute; top: 2px; right: 2px; width: 26px; height: 26px; border: 0; border-radius: 50%; background: rgba(17, 24, 39, .75); color: #fff; font-size: 16px; line-height: 1; }
.ba-main, .ba-star { position: absolute; left: 0; right: 0; bottom: 0; border: 0; padding: 2px 0; font-size: 11px; font-weight: 700; text-align: center; }
.ba-main { background: #DC2626; color: #fff; }
.ba-star { background: rgba(255, 255, 255, .9); color: #111827; }
.ba-add { border-style: dashed; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #374151; cursor: pointer; }
.ba-bar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 1030; display: flex; align-items: center; justify-content: flex-end; gap: 10px;
    padding: 10px 16px; background: var(--bs-body-bg, #fff); border-top: 1px solid #E5E7EB; box-shadow: 0 -4px 14px rgba(0, 0, 0, .06); }
</style>

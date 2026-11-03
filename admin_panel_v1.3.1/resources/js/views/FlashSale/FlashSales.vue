<template>
    <!-- Custom (Hader): flash deals — time-boxed deal prices with a countdown on the website/app. -->
    <div>
        <div class="list-page" v-if="!editing">
            <div class="page-head">
                <h3 class="page-head-title">Flash deals</h3>
                <button class="btn btn-primary list-add-btn d-inline-flex align-items-center gap-2 text-nowrap"
                    @click="openEditor()" v-if="$can('promo_code_create')">
                    <Plus :size="16" /><span>Add flash deal</span>
                </button>
            </div>
            <div class="list-surface p-3">
                <p class="text-muted small mb-3">
                    While a deal is live, its deal prices replace the products' sale prices in every store, and a
                    countdown section appears at the top of the website home page. Prices go back automatically when it ends.
                </p>
                <div v-if="loading" class="text-center py-4"><b-spinner small /></div>
                <div v-else-if="!sales.length" class="text-center text-muted py-4">No flash deals yet.</div>
                <div class="table-responsive" v-else>
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Title</th><th>Starts</th><th>Ends</th><th>Products</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                            <tr v-for="s in sales" :key="s.id">
                                <td class="fw-semibold">{{ s.title }}</td>
                                <td>{{ fmt(s.starts_at) }}</td>
                                <td>{{ fmt(s.ends_at) }}</td>
                                <td>{{ s.items_count }}</td>
                                <td><span class="badge" :class="badge(s)">{{ label(s) }}</span></td>
                                <td class="text-end text-nowrap">
                                    <button class="btn btn-sm btn-outline-primary me-1" @click="openEditor(s.id)" v-if="$can('promo_code_update')">Edit</button>
                                    <button class="btn btn-sm btn-outline-danger" @click="remove(s)" v-if="$can('promo_code_delete')">Delete</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-else>
            <div class="page-head">
                <h3 class="page-head-title">{{ form.id ? 'Edit flash deal' : 'New flash deal' }}</h3>
                <button class="btn btn-outline-secondary ms-auto" @click="editing = false">Back</button>
            </div>
            <div class="card"><div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Title (English) <span class="text-danger">*</span></label>
                        <input class="form-control" v-model="form.title" placeholder="e.g. Weekend Mega Deals">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Title (Arabic)</label>
                        <input class="form-control" dir="rtl" v-model="form.title_ar" placeholder="مثال: عروض نهاية الأسبوع">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Starts <span class="text-danger">*</span></label>
                        <input type="datetime-local" class="form-control" v-model="form.starts_local">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Ends <span class="text-danger">*</span></label>
                        <input type="datetime-local" class="form-control" v-model="form.ends_local">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Enabled</label>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" :checked="form.status == 1" @change="form.status = $event.target.checked ? 1 : 0">
                        </div>
                    </div>
                </div>

                <h5 class="mt-2">Products in this deal</h5>
                <div class="input-group mb-2" style="max-width: 520px">
                    <input class="form-control" v-model="search" placeholder="Search products by name or SKU" @keyup.enter="findVariants">
                    <button class="btn btn-outline-primary" type="button" @click="findVariants">Search</button>
                </div>
                <div v-if="results.length" class="border rounded mb-3" style="max-height: 240px; overflow:auto">
                    <div v-for="r in results" :key="r.product_variant_id" class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
                        <div>
                            <div class="fw-semibold">{{ r.product_name }} <span class="text-muted" v-if="r.variant_name">— {{ r.variant_name }}</span></div>
                            <small class="text-muted">SKU {{ r.sku || '-' }} · regular price {{ r.price ?? '-' }}</small>
                        </div>
                        <button class="btn btn-sm btn-primary" type="button" @click="addItem(r)" :disabled="hasItem(r)">{{ hasItem(r) ? 'Added' : 'Add' }}</button>
                    </div>
                </div>

                <div class="table-responsive" v-if="form.items.length">
                    <table class="table align-middle">
                        <thead><tr><th>Product</th><th>Regular price</th><th style="width:180px">Deal price</th><th>Saving</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="(it, i) in form.items" :key="it.product_variant_id">
                                <td>{{ it.product_name }} <small class="text-muted" v-if="it.variant_name">— {{ it.variant_name }}</small></td>
                                <td>{{ it.price ?? '-' }}</td>
                                <td><input type="number" min="0" step="0.01" class="form-control" v-model.number="it.deal_price"></td>
                                <td>
                                    <span v-if="it.price && it.deal_price && it.deal_price < it.price" class="text-success fw-semibold">-{{ Math.round((1 - it.deal_price / it.price) * 100) }}%</span>
                                    <span v-else-if="it.deal_price" class="text-danger small">Must be below the regular price</span>
                                </td>
                                <td class="text-end"><button class="btn btn-sm btn-link text-danger" type="button" @click="form.items.splice(i, 1)">Remove</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-muted">No products added yet — search above.</p>

                <div class="d-flex justify-content-end">
                    <b-button variant="primary" :disabled="saving" @click="save">Save flash deal <b-spinner v-if="saving" small /></b-button>
                </div>
            </div></div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import { Plus } from 'lucide-vue-next';

const toLocalInput = (iso) => {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

export default {
    components: { Plus },
    data() {
        return { loading: false, saving: false, sales: [], editing: false, search: '', results: [], form: this.blank() };
    },
    created() { this.load(); },
    methods: {
        blank() {
            const start = new Date(); start.setMinutes(0, 0, 0); start.setHours(start.getHours() + 1);
            const end = new Date(start.getTime() + 24 * 3600 * 1000);
            return { id: null, title: '', title_ar: '', status: 1, starts_local: toLocalInput(start), ends_local: toLocalInput(end), items: [] };
        },
        fmt(iso) { return iso ? new Date(iso).toLocaleString() : '-'; },
        label(s) { return s.status != 1 ? 'Disabled' : ({ live: 'Live now', scheduled: 'Scheduled', ended: 'Ended' }[s.state] || s.state); },
        badge(s) { return s.status != 1 ? 'bg-secondary' : ({ live: 'bg-success', scheduled: 'bg-info', ended: 'bg-light text-dark' }[s.state] || 'bg-light'); },
        load() {
            this.loading = true;
            axios.get(this.$apiUrl + '/flash_sales').then(r => { this.sales = r.data.data || []; }).finally(() => { this.loading = false; });
        },
        openEditor(id = null) {
            this.results = []; this.search = '';
            if (!id) { this.form = this.blank(); this.editing = true; return; }
            axios.get(this.$apiUrl + '/flash_sales/edit/' + id).then(r => {
                const d = r.data.data;
                if (!d) return this.showError(r.data.message);
                this.form = { id: d.id, title: d.title, title_ar: d.title_ar || '', status: d.status,
                    starts_local: toLocalInput(d.starts_at), ends_local: toLocalInput(d.ends_at), items: d.items || [] };
                this.editing = true;
            });
        },
        findVariants() {
            axios.get(this.$apiUrl + '/flash_sales/variants', { params: { search: this.search } })
                .then(r => { this.results = r.data.data || []; });
        },
        hasItem(r) { return this.form.items.some(i => i.product_variant_id === r.product_variant_id); },
        addItem(r) {
            if (this.hasItem(r)) return;
            const suggested = r.price ? Math.round(r.price * 0.8 * 100) / 100 : null;
            this.form.items.push({ ...r, deal_price: suggested });
        },
        save() {
            const f = this.form;
            if (!f.title || !f.starts_local || !f.ends_local) return this.showError('Title, start and end are required.');
            const fd = new FormData();
            if (f.id) fd.append('id', f.id);
            fd.append('title', f.title);
            fd.append('title_ar', f.title_ar || '');
            fd.append('status', f.status);
            fd.append('starts_at', new Date(f.starts_local).toISOString());
            fd.append('ends_at', new Date(f.ends_local).toISOString());
            fd.append('items', JSON.stringify(f.items.map(i => ({ product_variant_id: i.product_variant_id, deal_price: i.deal_price }))));
            this.saving = true;
            axios.post(this.$apiUrl + '/flash_sales/save', fd).then(r => {
                if (r.data.status === 1) { this.showMessage('success', r.data.message); this.editing = false; this.load(); }
                else this.showError(r.data.message);
            }).catch(e => this.showError(e.response?.data?.message || e.message)).finally(() => { this.saving = false; });
        },
        remove(s) {
            if (!window.confirm(`Delete "${s.title}"? Live prices will be restored.`)) return;
            const fd = new FormData(); fd.append('id', s.id);
            axios.post(this.$apiUrl + '/flash_sales/delete', fd).then(r => {
                if (r.data.status === 1) { this.showMessage('success', r.data.message); this.load(); } else this.showError(r.data.message);
            });
        },
    },
};
</script>

<template>
    <!-- Custom (Lebazone): a consignment seller's own page - balance, products and their state, sales and payouts. -->
    <div class="sp">
        <div class="page-head flex-wrap gap-2">
            <div>
                <h3 class="page-head-title mb-0">{{ d.business_name || 'My shop' }}</h3>
                <div class="small text-muted" v-if="loaded">Lebazone commission: {{ d.commission_percent }}% · you get the rest of each sale</div>
            </div>
            <router-link to="/scan/bulk" class="btn btn-outline-primary ms-auto">📄 Add many (Excel)</router-link>
            <router-link to="/scan" class="btn btn-outline-primary">📷 Quick add (scan)</router-link>
            <!-- Custom (Lebazone): the same full product form Lebazone uses -->
            <router-link to="/products/create" class="btn btn-primary">+ Add a product</router-link>
        </div>

        <div v-if="error" class="alert alert-warning">{{ error }}</div>
        <div v-else-if="!loaded" class="text-muted py-5 text-center">Loading…</div>
        <template v-else>
            <div class="sp-stats mb-3">
                <div class="sp-stat hl"><div class="k">Lebazone owes you</div><div class="v">{{ money(d.balance) }}</div></div>
                <div class="sp-stat"><div class="k">On the shop</div><div class="v">{{ count('live') }}</div></div>
                <div class="sp-stat"><div class="k">Waiting for approval</div><div class="v">{{ count('waiting') }}</div></div>
                <div class="sp-stat"><div class="k">Not approved</div><div class="v">{{ count('rejected') }}</div></div>
            </div>

            <div class="card mb-3"><div class="card-body">
                <h5 class="mb-1">How it works</h5>
                <ol class="small text-muted mb-0 ps-3">
                    <li>Add each product with <b>+ Add a product</b> - the full form: name, description, photos, category, brand,
                        colours / sizes, price and how many pieces. On the phone, <b>📷 Quick add</b> is faster.</li>
                    <li>Lebazone checks it - it appears on the shop once approved.</li>
                    <li>Bring the pieces to Lebazone; we count them in.</li>
                    <li>When an item is delivered and its return period is over, its price minus the commission is added to what Lebazone owes you.</li>
                </ol>
            </div></div>

            <SellerStoreEditor title="My store page" :about="d.store_about || ''" :banner="d.store_banner || ''" :url="d.store_url || ''" @saved="load" />

            <!-- Custom (Lebazone): the seller's own categories (their store page) -->
            <div class="card mb-3"><div class="card-body">
                <h5 class="mb-1">My categories</h5>
                <p class="small text-muted mb-2">Your own categories - customers see them on your store page. Pick one for each product in the product form.
                    (Your own colours / sizes / materials: in the product form, <b>+ Add attribute</b>.)</p>
                <div v-for="c in cats" :key="'c' + c.id" class="d-flex align-items-center gap-2 py-1 border-top">
                    <span class="flex-grow-1">{{ c.name }} <small class="text-muted">· {{ c.products }} product{{ c.products === 1 ? '' : 's' }}</small></span>
                    <button class="btn btn-sm btn-outline-secondary" @click="renameCat(c)">Rename</button>
                    <button class="btn btn-sm btn-outline-danger" @click="deleteCat(c)">Delete</button>
                </div>
                <form class="d-flex gap-2 mt-2" @submit.prevent="addCat">
                    <input v-model="newCat" class="form-control form-control-sm" placeholder="New category, e.g. Phone cases" maxlength="100">
                    <button class="btn btn-sm btn-primary" :disabled="!newCat.trim()">Add</button>
                </form>
            </div></div>

            <div class="card mb-3"><div class="card-body">
                <h5>My products</h5>
                <div v-if="!d.products.length" class="text-muted small py-2">No products yet - tap <b>+ Add a product</b>.</div>
                <div v-for="p in d.products" :key="p.id" class="sp-prod">
                    <img v-if="p.image" :src="p.image" alt=""><div v-else class="sp-noimg" aria-hidden="true">📦</div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate">{{ p.name }}</div>
                        <div class="small text-muted">{{ p.price != null ? money(p.price) : '' }} · {{ p.stock }} in stock</div>
                        <div v-if="p.state === 'rejected' && p.note" class="small text-danger">Reason: {{ p.note }}</div>
                    </div>
                    <span class="badge" :class="badge(p.state)">{{ label(p.state) }}</span>
                    <router-link :to="'/products/edit/' + p.id" class="btn btn-sm btn-outline-secondary ms-2"
                        title="Changes go back to Lebazone for approval">✏️ Edit</router-link>
                </div>
            </div></div>

            <div class="card"><div class="card-body">
                <h5>Sales and payouts</h5>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Date</th><th>What</th><th class="text-end">Sale</th><th class="text-end">Commission</th><th class="text-end">For you</th></tr></thead>
                    <tbody>
                        <tr v-for="r in d.ledger" :key="r.id">
                            <td class="text-nowrap">{{ (r.created_at || '').slice(0, 10) }}</td>
                            <td>{{ r.type === 'payout' ? 'Paid to you' + (r.note && r.note !== 'Payout' ? ' · ' + r.note : '') : r.note }}</td>
                            <td class="text-end">{{ r.type === 'sale' ? money(r.gross) : '' }}</td>
                            <td class="text-end">{{ r.type === 'sale' ? '-' + money(r.commission) : '' }}</td>
                            <td class="text-end fw-semibold" :class="{ 'text-danger': r.net < 0 }">{{ money(r.net) }}</td>
                        </tr>
                        <tr v-if="!d.ledger.length"><td colspan="5" class="text-center text-muted py-3">Nothing yet - sales show here after delivery and the return period.</td></tr>
                    </tbody>
                </table></div>
            </div></div>
        </template>
    </div>
</template>

<script>
import axios from 'axios';
import SellerStoreEditor from '../../components/SellerStoreEditor.vue';

export default {
    components: { SellerStoreEditor },
    data() {
        return { d: { products: [], ledger: [], balance: 0 }, loaded: false, error: '', cats: [], newCat: '' };
    },
    created() { this.load(); this.loadCats(); },
    methods: {
        loadCats() {
            axios.get(this.$apiUrl + '/seller_portal/categories').then(r => { this.cats = (r.data && r.data.data) || []; }).catch(() => {});
        },
        saveCat(body) {
            return axios.post(this.$apiUrl + '/seller_portal/categories/save', body).then(r => {
                if (r.data.status !== 1) { this.$toast ? this.$toast.error(r.data.message) : window.alert(r.data.message); return; }
                this.loadCats();
            });
        },
        addCat() { const name = this.newCat.trim(); if (!name) return; this.saveCat({ name }).then(() => { this.newCat = ''; }); },
        renameCat(c) {
            const name = (window.prompt('New name', c.name) || '').trim();
            if (name && name !== c.name) this.saveCat({ id: c.id, name });
        },
        deleteCat(c) {
            if (!window.confirm('Delete "' + c.name + '"? Its products stay - they just lose this category.')) return;
            axios.post(this.$apiUrl + '/seller_portal/categories/delete', { id: c.id }).then(() => this.loadCats());
        },
        load() {
            axios.get(this.$apiUrl + '/seller_portal/summary').then(r => {
                if (r.data.status !== 1) { this.error = r.data.message; return; }
                this.d = r.data.data; this.loaded = true;
            }).catch(e => { this.error = e?.response?.data?.message || e.message; });
        },
        money(v) { return '$' + Number(v || 0).toFixed(2); },
        count(s) { return this.d.products.filter(p => p.state === s).length; },
        label(s) { return { live: 'On the shop', waiting: 'Waiting for approval', rejected: 'Not approved', hidden: 'Hidden', paused: 'Paused - account not active' }[s] || s; },
        badge(s) { return { live: 'bg-success', waiting: 'bg-warning text-dark', rejected: 'bg-danger', hidden: 'bg-secondary', paused: 'bg-secondary' }[s] || 'bg-light text-dark'; },
    },
};
</script>

<style scoped>
.sp-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.sp-stat { background: var(--bs-body-bg, #fff); border: 1px solid #E5E7EB; border-radius: 12px; padding: 12px 14px; }
.sp-stat .k { font-size: 12px; color: #4B5563; }
.sp-stat .v { font-size: 22px; font-weight: 700; }
.sp-stat.hl { border-color: #DC2626; }
.sp-prod { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #F3F4F6; }
.sp-prod img, .sp-noimg { width: 52px; height: 52px; border-radius: 8px; object-fit: cover; flex-shrink: 0; background: #F3F4F6; display: flex; align-items: center; justify-content: center; }
.min-w-0 { min-width: 0; }
@media (max-width: 768px) { .sp-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>

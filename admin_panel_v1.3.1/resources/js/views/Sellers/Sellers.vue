<template>
    <!-- Custom (Lebazone): consignment sellers — applications, commission, products, ledger, payouts. -->
    <div>
        <div class="list-page" v-if="!current">
            <div class="page-head">
                <h3 class="page-head-title">Sellers</h3>
                <button class="btn btn-primary list-add-btn ms-auto" @click="openSeller(null)">Add seller</button>
            </div>
            <!-- Custom (Lebazone): products sellers added themselves wait here -->
            <div v-if="pending.length" class="card mb-3 border-warning"><div class="card-body">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                    <h5 class="mb-0">Waiting for your approval ({{ pending.length }})</h5>
                    <button class="btn btn-sm ms-auto" :class="delAllArmed ? 'btn-danger' : 'btn-outline-danger'" :disabled="busy" @click="deleteAllPending">
                        {{ delAllArmed ? 'Click again to delete all ' + pending.length : 'Delete all' }}</button>
                </div>
                <p class="small text-muted">Added by sellers from their own login. Approve to put it on the shop, or reject with a reason they will see.</p>
                <div v-for="p in pending" :key="'pp' + p.id" class="d-flex align-items-center gap-3 border-bottom py-2 flex-wrap">
                    <img v-if="p.image" :src="p.image" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:8px">
                    <div class="flex-grow-1" style="min-width:180px">
                        <div class="fw-semibold">{{ p.name }}</div>
                        <div class="small text-muted">{{ p.seller }} · {{ p.colors }} colour(s) · {{ money(p.sale_price != null ? p.sale_price : p.price) }} · {{ p.stock }} pcs</div>
                    </div>
                    <a :href="'/products/edit/' + p.id" class="btn btn-sm btn-outline-secondary">Check / edit</a>
                    <button class="btn btn-sm btn-success" :disabled="busy" @click="review(p, 'approve')">Approve</button>
                    <button class="btn btn-sm btn-outline-danger" :disabled="busy" @click="review(p, 'reject')">Reject</button>
                    <button class="btn btn-sm btn-link text-danger" :disabled="busy" @click="deletePending(p)" :aria-label="'Delete ' + p.name">Delete</button>
                </div>
            </div></div>
            <div class="list-surface p-3">
                <p class="text-muted small">
                    Consignment sellers supply products that Lebazone stocks, sells and delivers. Applications from the website's
                    "Sell on Lebazone" page appear here as <b>Pending</b>. Sellers are credited (sale − commission) once an item is
                    delivered and its return window closes.
                </p>
                <div class="btn-group btn-group-sm mb-3">
                    <button v-for="s in ['', 'pending', 'active', 'suspended', 'rejected']" :key="s" class="btn"
                        :class="filter === s ? 'btn-primary' : 'btn-outline-primary'" @click="filter = s">{{ s ? s : 'all' }}</button>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Business</th><th>Contact</th><th>Commission</th><th>Products</th><th>Balance owed</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="s in filtered" :key="s.id">
                                <td class="fw-semibold">{{ s.business_name }}<div class="small text-muted">{{ s.category }} {{ s.city ? '· ' + s.city : '' }}</div></td>
                                <td>{{ s.contact_name }}<div class="small text-muted">{{ s.phone }} {{ s.email }}</div></td>
                                <td>{{ s.commission_percent }}%</td>
                                <td>{{ s.products_count }}</td>
                                <td>{{ money(s.balance) }}</td>
                                <td><span class="badge" :class="badge(s.status)">{{ s.status }}</span></td>
                                <td class="text-end"><button class="btn btn-sm btn-outline-primary" @click="openSeller(s)">Open</button></td>
                            </tr>
                            <tr v-if="!filtered.length"><td colspan="7" class="text-center text-muted py-4">No sellers.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-else>
            <div class="page-head">
                <h3 class="page-head-title">{{ form.id ? form.business_name : 'New seller' }}</h3>
                <button class="btn btn-outline-secondary ms-auto" @click="current = null; load()">Back</button>
            </div>
            <div class="row">
                <div class="col-lg-6 mb-3">
                    <div class="card h-100"><div class="card-body">
                        <h5>Details</h5>
                        <div class="row">
                            <div class="col-md-6 mb-2"><label class="form-label">Business name</label><input class="form-control" v-model="form.business_name"></div>
                            <div class="col-md-6 mb-2"><label class="form-label">Contact name</label><input class="form-control" v-model="form.contact_name"></div>
                            <div class="col-md-6 mb-2"><label class="form-label">Phone</label><input class="form-control" v-model="form.phone"></div>
                            <div class="col-md-6 mb-2"><label class="form-label">Email</label><input class="form-control" v-model="form.email"></div>
                            <div class="col-md-6 mb-2"><label class="form-label">City</label><input class="form-control" v-model="form.city"></div>
                            <div class="col-md-6 mb-2"><label class="form-label">Category</label><input class="form-control" v-model="form.category"></div>
                            <div class="col-md-6 mb-2"><label class="form-label">Commission %</label><input type="number" min="0" max="100" step="0.5" class="form-control" v-model="form.commission_percent"></div>
                            <div class="col-md-6 mb-2"><label class="form-label">Status</label>
                                <select class="form-select" v-model="form.status"><option>pending</option><option>active</option><option>suspended</option><option>rejected</option></select></div>
                            <div class="col-12 mb-2"><label class="form-label">Payout details (bank / Whish / OMT)</label><textarea class="form-control" rows="2" v-model="form.payout_details"></textarea></div>
                            <div class="col-12 mb-2"><label class="form-label">Internal notes</label><textarea class="form-control" rows="2" v-model="form.admin_notes"></textarea></div>
                            <div class="col-12 mb-2" v-if="form.message"><label class="form-label">Their application message</label><div class="border rounded p-2 small bg-light">{{ form.message }}</div></div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <a v-if="form.statement_url && form.status === 'active'" :href="form.statement_url" target="_blank" class="small">Seller's private statement link ↗</a>
                            <b-button variant="primary" class="ms-auto" :disabled="busy" @click="save">Save</b-button>
                        </div>
                    </div></div>
                </div>
                <div class="col-lg-6 mb-3" v-if="form.id">
                    <div class="card mb-3"><div class="card-body">
                        <div class="d-flex justify-content-between"><h5>Balance owed</h5><span class="fs-4 fw-bold">{{ money(detail.balance) }}</span></div>
                        <div class="input-group mt-2">
                            <input type="number" min="0" step="0.01" class="form-control" v-model="payout.amount" placeholder="Amount paid">
                            <input class="form-control" v-model="payout.note" placeholder="Reference (e.g. Whish transfer #)">
                            <button class="btn btn-outline-primary" @click="recordPayout" :disabled="busy">Record payout</button>
                        </div>
                    </div></div>
                    <SellerStoreEditor v-if="detail.store" :seller-id="form.id" title="Their store page" :about="detail.store.about"
                        :banner="detail.store.banner" :url="detail.store.url" @saved="loadDetail" />
                    <div class="card mb-3"><div class="card-body">
                        <h5 class="mb-1">Seller login</h5>
                        <p class="small text-muted mb-2">
                            <template v-if="detail.login">Logs in at the admin panel with <b>{{ detail.login.email }}</b>
                                <span v-if="!detail.login.active" class="text-danger">(off - the seller is not active)</span>.
                                They add products from their phone and see only their own products, sales and balance.</template>
                            <template v-else>Give the seller a login so they can add their products themselves. Their products go on the shop only after you approve them.</template>
                        </p>
                        <div class="row g-2">
                            <div class="col-md-6"><input class="form-control" type="email" v-model="login.email" placeholder="Login email" autocomplete="off"></div>
                            <div class="col-md-6"><input class="form-control" type="text" v-model="login.password" :placeholder="detail.login ? 'New password (leave empty to keep)' : 'Password (min 8)'" autocomplete="new-password"></div>
                        </div>
                        <button class="btn btn-outline-primary mt-2" :disabled="busy" @click="saveLogin">{{ detail.login ? 'Update login' : 'Create login' }}</button>
                    </div></div>
                    <div class="card"><div class="card-body">
                        <h5>Products ({{ detail.products.length }})</h5>
                        <div class="input-group mb-2">
                            <input class="form-control" v-model="search" placeholder="Search products to link" @keyup.enter="findProducts">
                            <button class="btn btn-outline-primary" @click="findProducts">Search</button>
                        </div>
                        <div v-for="p in results" :key="'r' + p.id" class="d-flex justify-content-between border-bottom py-1 small">
                            <span>{{ p.name }} <span v-if="p.seller_id && p.seller_id !== form.id" class="text-warning">(linked to another seller)</span></span>
                            <button class="btn btn-sm btn-link" @click="link([p.id], form.id)" :disabled="p.seller_id === form.id">{{ p.seller_id === form.id ? 'Linked' : 'Link' }}</button>
                        </div>
                        <div v-for="p in detail.products" :key="'p' + p.id" class="d-flex justify-content-between border-bottom py-1 small">
                            <span>{{ p.name }}
                                <span v-if="p.review === 'pending'" class="badge bg-warning text-dark ms-1">waiting for approval</span>
                                <span v-else-if="p.review === 'rejected'" class="badge bg-danger ms-1">rejected</span></span>
                            <button class="btn btn-sm btn-link text-danger" @click="link([p.id], 0)">Unlink</button>
                        </div>
                    </div></div>
                </div>
                <div class="col-12" v-if="form.id">
                    <div class="card"><div class="card-body">
                        <h5>Ledger</h5>
                        <div class="table-responsive"><table class="table table-sm">
                            <thead><tr><th>Date</th><th>Type</th><th>Details</th><th class="text-end">Sale</th><th class="text-end">Commission</th><th class="text-end">Net</th></tr></thead>
                            <tbody>
                                <tr v-for="r in detail.ledger" :key="r.id">
                                    <td>{{ (r.created_at || '').slice(0, 10) }}</td><td>{{ r.type }}</td><td>{{ r.note }}</td>
                                    <td class="text-end">{{ r.type === 'sale' ? money(r.gross) : '' }}</td>
                                    <td class="text-end">{{ r.type === 'sale' ? '-' + money(r.commission) : '' }}</td>
                                    <td class="text-end" :class="{ 'text-danger': r.net < 0 }">{{ money(r.net) }}</td>
                                </tr>
                                <tr v-if="!detail.ledger.length"><td colspan="6" class="text-muted text-center">No activity yet.</td></tr>
                            </tbody>
                        </table></div>
                    </div></div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import SellerStoreEditor from '../../components/SellerStoreEditor.vue';

export default {
    components: { SellerStoreEditor },
    data() {
        return { sellers: [], filter: '', current: null, form: {}, detail: { balance: 0, ledger: [], products: [] },
            payout: { amount: '', note: '' }, search: '', results: [], busy: false,
            login: { email: '', password: '' }, pending: [], delAllArmed: false };
    },
    computed: {
        filtered() { return this.filter ? this.sellers.filter(s => s.status === this.filter) : this.sellers; },
    },
    created() { this.load(); this.loadPending(); },
    methods: {
        money(v) { return '$' + Number(v || 0).toFixed(2); },
        badge(s) { return { pending: 'bg-warning text-dark', active: 'bg-success', suspended: 'bg-secondary', rejected: 'bg-light text-dark' }[s] || 'bg-light'; },
        load() { axios.get(this.$apiUrl + '/sellers').then(r => { this.sellers = r.data.data || []; }); },
        openSeller(s) {
            this.form = s ? { ...s } : { business_name: '', contact_name: '', phone: '', email: '', city: '', category: '', commission_percent: 15, status: 'active' };
            this.current = s || {}; this.results = []; this.search = '';
            this.detail = { balance: 0, ledger: [], products: [] };
            if (s) this.loadDetail();
        },
        loadDetail() {
            axios.get(this.$apiUrl + '/sellers/ledger/' + this.form.id).then(r => {
                this.detail = r.data.data || this.detail;
                this.login = { email: (this.detail.login && this.detail.login.email) || this.form.email || '', password: '' };
            });
        },
        // Custom (Lebazone): seller logins + approvals
        loadPending() { axios.get(this.$apiUrl + '/sellers/pending_products').then(r => { this.pending = r.data.data || []; }).catch(() => {}); },
        saveLogin() {
            this.post('/sellers/save_login', { seller_id: this.form.id, email: this.login.email, password: this.login.password }, () => { this.loadDetail(); });
        },
        deleteAllPending() {
            if (!this.delAllArmed) { this.delAllArmed = true; setTimeout(() => { this.delAllArmed = false; }, 4000); return; }
            this.delAllArmed = false;
            this.post('/sellers/delete_pending', { product_ids: this.pending.map(p => p.id).join(',') }, () => { this.loadPending(); this.load(); });
        },
        deletePending(p) {
            if (!window.confirm('Delete "' + p.name + '"? This cannot be undone.')) return;
            this.post('/sellers/delete_pending', { product_ids: String(p.id) }, () => { this.loadPending(); this.load(); });
        },
        review(p, decision) {
            let note = '';
            if (decision === 'reject') {
                note = window.prompt('Why is "' + p.name + '" not approved? The seller will see this.', '');
                if (note === null) return;
            }
            this.post('/sellers/review_product', { product_id: p.id, decision, note }, () => { this.loadPending(); this.load(); });
        },
        post(url, data, after) {
            this.busy = true;
            const fd = new FormData(); for (const k in data) fd.append(k, data[k] ?? '');
            axios.post(this.$apiUrl + url, fd).then(r => {
                if (r.data.status === 1) { this.showMessage('success', r.data.message); after && after(); } else this.showError(r.data.message);
            }).catch(e => this.showError(e.response?.data?.message || e.message)).finally(() => { this.busy = false; });
        },
        save() {
            const isNew = !this.form.id;
            this.post('/sellers/save', this.form, () => { if (isNew) { this.current = null; } this.load(); if (!isNew) this.loadDetail(); });
        },
        recordPayout() { this.post('/sellers/payout', { seller_id: this.form.id, ...this.payout }, () => { this.payout = { amount: '', note: '' }; this.loadDetail(); }); },
        findProducts() { axios.get(this.$apiUrl + '/sellers/products', { params: { search: this.search } }).then(r => { this.results = r.data.data || []; }); },
        link(ids, sellerId) { this.post('/sellers/assign_products', { product_ids: ids.join(','), seller_id: sellerId }, () => { this.loadDetail(); if (this.search) this.findProducts(); }); },
    },
};
</script>

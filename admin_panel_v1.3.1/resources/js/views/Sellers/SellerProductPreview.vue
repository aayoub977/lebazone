<template>
    <!-- Custom (Lebazone): a seller's pending product, laid out the way the shop's product page shows it. -->
    <div class="pv">
        <div class="pv-bar">
            <button type="button" class="btn btn-outline-secondary" @click="back">← Back to approvals</button>
            <span v-if="d" class="pv-state" :class="'s-' + (d.review || (d.status ? 'live' : 'hidden'))">{{ stateLabel }}</span>
            <span class="ms-auto"></span>
            <template v-if="d">
                <router-link :to="'/products/edit/' + d.id" class="btn btn-outline-secondary">✎ Edit</router-link>
                <button v-if="d.review === 'pending'" type="button" class="btn btn-outline-danger" :disabled="busy" @click="decide('reject')">Reject</button>
                <button v-if="d.review === 'pending'" type="button" class="btn btn-success" :disabled="busy" @click="decide('approve')">✓ Approve - put it on the shop</button>
            </template>
        </div>

        <div v-if="error" class="alert alert-warning">{{ error }}</div>
        <div v-else-if="!d" class="text-muted text-center py-5">Loading…</div>
        <template v-else>
            <div class="small text-muted mb-2">Preview - this is how customers will see it. <span v-if="d.seller">Added by <b>{{ d.seller.name }}</b> on {{ (d.created_at || '').slice(0, 10) }} · commission {{ d.seller.commission }}%</span></div>
            <div v-if="d.review === 'rejected' && d.review_note" class="alert alert-danger py-2 small">Rejected: {{ d.review_note }}</div>

            <div class="pv-page">
                <div class="row g-4">
                    <!-- gallery -->
                    <div class="col-lg-6">
                        <div class="pv-main">
                            <img v-if="shown" :src="shown" :alt="d.name">
                            <div v-else class="pv-noimg">No photo</div>
                        </div>
                        <div class="pv-thumbs" v-if="photos.length > 1">
                            <button v-for="(u, i) in photos" :key="u + i" type="button" class="pv-thumb" :class="{ on: shown === u }" @click="pic = u" :aria-label="'Photo ' + (i + 1)">
                                <img :src="u" alt=""></button>
                        </div>
                    </div>
                    <!-- details -->
                    <div class="col-lg-6">
                        <div class="small text-uppercase text-muted fw-semibold mb-1">{{ d.category }}<span v-if="d.brand"> · {{ d.brand }}</span></div>
                        <h2 class="pv-name">{{ d.name }}</h2>
                        <div v-if="d.name_ar" class="text-muted mb-2" dir="rtl">{{ d.name_ar }}</div>
                        <div class="pv-price" v-if="v">
                            <span class="now">${{ money(v.sale_price != null && v.sale_price > 0 ? v.sale_price : v.price) }}</span>
                            <span v-if="v.sale_price && v.price && v.sale_price < v.price" class="was">${{ money(v.price) }}</span>
                            <span v-if="v.sale_price && v.price && v.sale_price < v.price" class="off">-{{ Math.round((1 - v.sale_price / v.price) * 100) }}%</span>
                        </div>
                        <div v-if="v" class="mb-3" :class="v.stock > 0 ? 'text-success' : 'text-danger'">{{ v.stock > 0 ? 'In stock (' + v.stock + ')' : 'Out of stock' }}</div>

                        <div v-if="d.variants.length > 1" class="mb-3">
                            <div class="fw-semibold mb-1">Colour: {{ v ? (v.color || v.options) : '' }}</div>
                            <div class="d-flex flex-wrap gap-2">
                                <button v-for="x in d.variants" :key="x.id" type="button" class="pv-opt" :class="{ on: v && x.id === v.id }" @click="pickVariant(x)">
                                    <img v-if="x.image" :src="x.image" alt="">{{ x.color || x.options || x.name }}</button>
                            </div>
                        </div>

                        <div class="pv-fake mb-3" aria-hidden="true"><span>−</span><b>1</b><span>+</span></div>
                        <button type="button" class="btn btn-danger btn-lg w-100 mb-3" disabled>Add to cart</button>

                        <div class="pv-meta">
                            <div><span>Sold by:</span> <b class="text-danger">{{ d.seller ? d.seller.name : 'Lebazone' }}</b></div>
                            <div v-if="v && v.sku"><span>Code:</span> {{ v.sku }}<span v-if="v.barcode"> · barcode {{ v.barcode }}</span></div>
                            <div v-if="d.size"><span>Box size:</span> {{ d.size }}</div>
                        </div>
                    </div>
                </div>

                <div class="pv-desc mt-4">
                    <h5>Description</h5>
                    <div v-if="d.description" class="pv-html" v-html="safeDescription"></div>
                    <div v-else class="text-danger small">No description.</div>
                </div>
            </div>

            <!-- what the admin should check -->
            <div class="card mt-3"><div class="card-body small">
                <b>Before approving, check:</b>
                <ul class="mb-0 mt-1">
                    <li :class="photos.length ? 'text-success' : 'text-danger'">{{ photos.length }} photo(s)</li>
                    <li :class="(d.description || '').replace(/<[^>]+>/g, '').trim().length >= 20 ? 'text-success' : 'text-danger'">Description</li>
                    <li :class="d.variants.every(x => x.stock > 0) ? 'text-success' : 'text-warning'">Quantities: {{ d.variants.map(x => (x.color || 'one colour') + ' ' + x.stock).join(', ') }}</li>
                    <li v-if="v && v.cost != null">Lebazone pays the seller ${{ money(v.cost) }} per piece (price minus commission)</li>
                </ul>
            </div></div>
        </template>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    data() { return { d: null, v: null, pic: '', busy: false, error: '' }; },
    computed: {
        photos() {
            if (!this.v) return [];
            return [this.v.image, ...(this.v.gallery || [])].filter(Boolean);
        },
        shown() { return this.pic || this.photos[0] || ''; },
        stateLabel() {
            const r = this.d && (this.d.review || (this.d.status ? 'live' : 'hidden'));
            return { pending: 'Waiting for approval', rejected: 'Rejected', seller_off: 'Paused (seller not active)', live: 'On the shop', hidden: 'Hidden' }[r] || r;
        },
        // the description is the seller's own text: show it as text with line breaks, never as raw HTML
        safeDescription() {
            const div = document.createElement('div');
            div.innerHTML = this.d.description || '';
            const text = (div.textContent || '').trim();
            return text.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])).replace(/\n/g, '<br>');
        },
    },
    created() { this.load(); },
    watch: { '$route.params.id'() { this.load(); } },
    methods: {
        money(x) { return Number(x || 0).toFixed(2); },
        load() {
            this.d = null; this.error = '';
            axios.get(this.$apiUrl + '/sellers/preview_product', { params: { id: this.$route.params.id } }).then(r => {
                if (r.data.status !== 1) { this.error = r.data.message; return; }
                this.d = r.data.data;
                this.v = this.d.variants[0] || null;
                this.pic = '';
            }).catch(e => { this.error = e?.response?.data?.message || e.message; });
        },
        pickVariant(x) { this.v = x; this.pic = ''; },
        back() { this.$router.push('/sellers?tab=pending'); },
        decide(decision) {
            let note = '';
            if (decision === 'reject') {
                note = window.prompt('Why is "' + this.d.name + '" not approved? The seller will see this.', '');
                if (note === null) return;
            }
            const fd = new FormData();
            fd.append('product_id', this.d.id); fd.append('decision', decision); fd.append('note', note);
            this.busy = true;
            axios.post(this.$apiUrl + '/sellers/review_product', fd).then(r => {
                if (r.data.status !== 1) return this.showError(r.data.message);
                this.showMessage('success', r.data.message);
                this.back();
            }).catch(e => this.showError(e?.response?.data?.message || e.message)).finally(() => { this.busy = false; });
        },
    },
};
</script>

<style scoped>
.pv-bar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 10px 0; background: var(--bs-body-bg, #fff); }
.pv-state { font-size: 13px; font-weight: 600; padding: 4px 10px; border-radius: 999px; background: #F3F4F6; }
.pv-state.s-pending { background: #FEF3C7; color: #92400E; }
.pv-state.s-rejected { background: #FEE2E2; color: #991B1B; }
.pv-state.s-live { background: #DCFCE7; color: #166534; }
.pv-page { background: #fff; color: #111827; border: 1px solid #E5E7EB; border-radius: 14px; padding: 24px; }
.pv-main { aspect-ratio: 1 / 1; border: 1px solid #E5E7EB; border-radius: 12px; overflow: hidden; background: #fff; display: flex; align-items: center; justify-content: center; }
.pv-main img { width: 100%; height: 100%; object-fit: contain; }
.pv-noimg { color: #B91C1C; font-weight: 600; }
.pv-thumbs { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.pv-thumb { width: 64px; height: 64px; padding: 0; border: 1px solid #D1D5DB; border-radius: 8px; overflow: hidden; background: #fff; }
.pv-thumb.on { border: 2px solid #DC2626; }
.pv-thumb img { width: 100%; height: 100%; object-fit: cover; }
.pv-name { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.pv-price { display: flex; align-items: baseline; gap: 10px; margin-bottom: 6px; }
.pv-price .now { font-size: 28px; font-weight: 800; color: #DC2626; }
.pv-price .was { color: #6B7280; text-decoration: line-through; }
.pv-price .off { background: #DC2626; color: #fff; font-size: 12px; font-weight: 700; padding: 2px 8px; border-radius: 999px; }
.pv-opt { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #D1D5DB; background: #fff; border-radius: 10px; padding: 6px 12px; font-size: 14px; min-height: 44px; }
.pv-opt img { width: 28px; height: 28px; object-fit: cover; border-radius: 6px; }
.pv-opt.on { border: 2px solid #DC2626; }
.pv-fake { display: inline-flex; align-items: center; gap: 18px; border: 1px solid #D1D5DB; border-radius: 10px; padding: 8px 16px; }
.pv-meta > div { margin-bottom: 4px; }
.pv-meta span { color: #4B5563; }
.pv-html { line-height: 1.6; }
</style>

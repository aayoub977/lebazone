<template>
    <!-- Custom (Hader): loyalty points programme settings. -->
    <div>
        <div class="page-head">
            <h3 class="page-head-title">Loyalty points</h3>
        </div>
        <div class="row">
            <div class="col-md-4 mb-3" v-for="s in statCards" :key="s.label">
                <div class="card h-100"><div class="card-body">
                    <div class="text-muted small">{{ s.label }}</div>
                    <div class="fs-4 fw-bold">{{ s.value }}</div>
                </div></div>
            </div>
        </div>
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="mb-1">Programme</h5>
                    <p class="text-muted small mb-0">
                        Customers earn points on delivered orders once the return window has passed (nothing returned),
                        and convert them into wallet balance they can spend at checkout.
                    </p>
                </div>
                <div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" :checked="form.loyalty_enabled === '1'"
                        @change="form.loyalty_enabled = $event.target.checked ? '1' : '0'">
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Points earned per 1 {{ currency }} spent</label>
                    <input type="number" min="0" step="any" class="form-control" v-model="form.loyalty_points_per_unit">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Wallet value of 1 point ({{ currency }})</label>
                    <input type="number" min="0" step="any" class="form-control" v-model="form.loyalty_point_value">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Minimum points to redeem</label>
                    <input type="number" min="1" step="1" class="form-control" v-model="form.loyalty_min_redeem_points">
                </div>
            </div>
            <div class="alert alert-info py-2 small">
                Example: a {{ currency }}100 order earns <b>{{ exampleEarn }}</b> points,
                worth <b>{{ currency }}{{ exampleValue }}</b> in wallet balance —
                a <b>{{ rewardRate }}%</b> reward.
            </div>
            <div class="d-flex justify-content-end">
                <b-button variant="primary" :disabled="saving" @click="save">Save <b-spinner v-if="saving" small /></b-button>
            </div>
        </div></div>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    data() {
        return {
            saving: false, currency: '$', stats: {},
            form: { loyalty_enabled: '1', loyalty_points_per_unit: '10', loyalty_point_value: '0.01', loyalty_min_redeem_points: '500' },
        };
    },
    computed: {
        exampleEarn() { return Math.floor(100 * Number(this.form.loyalty_points_per_unit || 0)); },
        exampleValue() { return (this.exampleEarn * Number(this.form.loyalty_point_value || 0)).toFixed(2); },
        rewardRate() { return (Number(this.form.loyalty_points_per_unit || 0) * Number(this.form.loyalty_point_value || 0) * 100).toFixed(1); },
        statCards() {
            return [
                { label: 'Points held by customers', value: (this.stats.outstanding_points || 0).toLocaleString() },
                { label: 'Customers with points', value: (this.stats.members || 0).toLocaleString() },
                { label: 'Redeemed into wallets', value: this.currency + Number(this.stats.redeemed_value || 0).toFixed(2) },
            ];
        },
    },
    created() {
        axios.get(this.$apiUrl + '/loyalty_settings').then(r => {
            const d = r.data.data || {};
            for (const k in this.form) if (d[k] !== null && d[k] !== undefined) this.form[k] = String(d[k]);
            this.stats = d.stats || {};
        });
    },
    methods: {
        save() {
            const fd = new FormData();
            for (const k in this.form) fd.append(k, this.form[k]);
            this.saving = true;
            axios.post(this.$apiUrl + '/loyalty_settings/save', fd).then(r => {
                if (r.data.status === 1) this.showMessage('success', r.data.message); else this.showError(r.data.message);
            }).catch(e => this.showError(e.response?.data?.message || e.message)).finally(() => { this.saving = false; });
        },
    },
};
</script>

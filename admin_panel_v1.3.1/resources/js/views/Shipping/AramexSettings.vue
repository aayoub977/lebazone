<template>
    <!-- Custom (Hader): Aramex courier settings. -->
    <div>
        <div class="page-head"><h3 class="page-head-title">Aramex shipping</h3></div>
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <p class="text-muted small mb-0" style="max-width: 720px">
                    Book Aramex shipments straight from an order (Orders → select items → <b>Book Aramex shipment</b>).
                    The tracking number, tracking link and printable label are filled in automatically, and cash-on-delivery
                    orders tell Aramex how much to collect.
                </p>
                <div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" :checked="f.aramex_enabled === '1'" @change="f.aramex_enabled = $event.target.checked ? '1' : '0'">
                </div>
            </div>
            <h6 class="mt-2">Aramex account</h6>
            <div class="row">
                <div class="col-md-3 mb-3"><label class="form-label">Mode</label>
                    <select class="form-select" v-model="f.aramex_mode"><option value="test">Test</option><option value="live">Live</option></select></div>
                <div class="col-md-3 mb-3"><label class="form-label">Username (email)</label><input class="form-control" v-model="f.aramex_username"></div>
                <div class="col-md-3 mb-3"><label class="form-label">Password</label><input class="form-control" type="password" v-model="f.aramex_password"></div>
                <div class="col-md-3 mb-3"><label class="form-label">Account number</label><input class="form-control" v-model="f.aramex_account_number"></div>
                <div class="col-md-3 mb-3"><label class="form-label">Account PIN</label><input class="form-control" type="password" v-model="f.aramex_account_pin"></div>
                <div class="col-md-3 mb-3"><label class="form-label">Account entity</label><input class="form-control" v-model="f.aramex_account_entity" placeholder="BEY"></div>
                <div class="col-md-3 mb-3"><label class="form-label">Account country</label><input class="form-control" v-model="f.aramex_account_country" placeholder="LB"></div>
                <div class="col-md-3 mb-3"><label class="form-label">Default weight per item (kg)</label><input class="form-control" type="number" step="0.1" v-model="f.aramex_default_weight"></div>
            </div>
            <h6 class="mt-2">Pick-up / sender</h6>
            <div class="row">
                <div class="col-md-4 mb-3"><label class="form-label">Name</label><input class="form-control" v-model="f.aramex_shipper_name"></div>
                <div class="col-md-4 mb-3"><label class="form-label">Phone</label><input class="form-control" v-model="f.aramex_shipper_phone" placeholder="+961…"></div>
                <div class="col-md-4 mb-3"><label class="form-label">Email</label><input class="form-control" v-model="f.aramex_shipper_email"></div>
                <div class="col-md-6 mb-3"><label class="form-label">Address</label><input class="form-control" v-model="f.aramex_shipper_address"></div>
                <div class="col-md-3 mb-3"><label class="form-label">City</label><input class="form-control" v-model="f.aramex_shipper_city"></div>
                <div class="col-md-3 mb-3"><label class="form-label">Country code</label><input class="form-control" v-model="f.aramex_shipper_country" placeholder="LB"></div>
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
    data() { return { saving: false, f: {} }; },
    created() {
        axios.get(this.$apiUrl + '/aramex_settings').then(r => { this.f = r.data.data || {}; });
    },
    methods: {
        save() {
            const fd = new FormData();
            for (const k in this.f) fd.append(k, this.f[k] ?? '');
            this.saving = true;
            axios.post(this.$apiUrl + '/aramex_settings/save', fd).then(r => {
                if (r.data.status === 1) this.showMessage('success', r.data.message); else this.showError(r.data.message);
            }).catch(e => this.showError(e.response?.data?.message || e.message)).finally(() => { this.saving = false; });
        },
    },
};
</script>

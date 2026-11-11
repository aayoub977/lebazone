<template>
    <!-- Custom (Lebazone): item cost from its weight (cost per kg), plus a suggested selling price (margin %). -->
    <div class="wc">
        <div class="d-flex flex-wrap align-items-end gap-2">
            <div>
                <label class="form-label small fw-bold mb-1" :for="'wc-' + uid">Weight · الوزن</label>
                <div class="input-group" :class="small ? 'input-group-sm' : ''" style="max-width: 190px">
                    <input :id="'wc-' + uid" type="number" min="0" step="1" inputmode="numeric" class="form-control"
                        :value="modelValue || ''" @input="onWeight" placeholder="e.g. 250">
                    <span class="input-group-text">g</span>
                </div>
            </div>
            <div class="small wc-calc" v-if="rate > 0">
                <template v-if="modelValue > 0">
                    {{ kg }} kg × ${{ fmt(rate) }}/kg = <b>cost ${{ fmt(cost) }}</b>
                    <span v-if="margin > 0"> · +{{ fmt(margin, 0) }}% = <b>price ${{ fmt(price) }}</b></span>
                    <span class="text-success"> ✓ filled in</span>
                </template>
                <span v-else class="text-muted">Type the weight to work out the cost</span>
            </div>
            <div class="small text-warning" v-else>Set the cost per kg first →</div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-2">
            <button v-if="canEdit" type="button" class="btn btn-sm btn-link px-1" @click="editSettings">
                {{ rate > 0 ? '$' + fmt(rate) + '/kg · ' + fmt(margin, 0) + '% margin - change' : 'Set cost per kg & margin' }}</button>
        </div>
    </div>
</template>

<script>
import axios from 'axios';

let n = 0;
let cache = null; // one load per page

export default {
    props: {
        modelValue: { type: Number, default: 0 }, // grams
        small: { type: Boolean, default: false },
        canEdit: { type: Boolean, default: true },
    },
    emits: ['update:modelValue', 'apply'],
    data() { return { uid: ++n, rate: 0, margin: 0 }; },
    computed: {
        kg() { return Math.round((this.modelValue || 0)) / 1000; },
        cost() { return Math.round(this.kg * this.rate * 100) / 100; },
        price() { return Math.round(this.cost * (1 + this.margin / 100) * 100) / 100; },
    },
    created() { this.load(); },
    beforeUnmount() { clearTimeout(this._t); },
    methods: {
        // Custom (Lebazone): typing the weight fills the purchase cost and the selling price straight away
        onWeight(e) {
            const g = +e.target.value || 0;
            this.$emit('update:modelValue', g);
            clearTimeout(this._t);
            this._t = setTimeout(() => {
                if (g > 0 && this.rate > 0) this.$emit('apply', { cost: this.cost, price: this.margin > 0 ? this.price : null, weight: g, auto: true });
            }, 500);
        },
        fmt(v, d = 2) { return Number(v || 0).toFixed(d); },
        load(force = false) {
            if (!cache || force) cache = axios.get(this.$apiUrl + '/scan/cost_settings').then(r => (r.data.status === 1 ? r.data.data : { cost_per_kg: 0, margin_percent: 0 }));
            cache.then(d => { this.rate = +d.cost_per_kg || 0; this.margin = +d.margin_percent || 0; }).catch(() => {});
        },
        editSettings() {
            const kg = window.prompt('Cost per kg ($) - the price you pay for 1 kg', this.rate ? String(this.rate) : '');
            if (kg === null) return;
            const m = window.prompt('Profit margin % for the suggested selling price (0 = no suggestion)', String(this.margin || 0));
            if (m === null) return;
            const fd = new FormData();
            fd.append('cost_per_kg', parseFloat(kg) || 0);
            fd.append('margin_percent', parseFloat(m) || 0);
            axios.post(this.$apiUrl + '/scan/cost_settings', fd).then(r => {
                if (r.data.status !== 1) return this.showError(r.data.message);
                this.load(true);
                this.showMessage('success', 'Cost per kg saved');
            }).catch(e => this.showError(e?.response?.data?.message || e.message));
        },
    },
};
</script>

<style scoped>
.wc { border: 1px dashed #D1D5DB; border-radius: 10px; padding: 10px 12px; background: #F9FAFB; }
.wc-calc { padding-bottom: 6px; }
</style>

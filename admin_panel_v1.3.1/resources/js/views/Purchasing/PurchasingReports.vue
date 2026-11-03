<template>
    <!-- Custom (Lebazone): Purchasing reports — preview, then export to Excel / PDF / CSV. -->
    <div>
        <div class="card mb-3"><div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-3"><label class="form-label small mb-1">Report</label>
                    <select class="form-select" v-model="r.type"><option v-for="(t, k) in REPORTS" :key="k" :value="k">{{ t }}</option></select></div>
                <div class="col-md-2 col-6"><label class="form-label small mb-1">Items added from</label><input type="date" class="form-control" v-model="r.from"></div>
                <div class="col-md-2 col-6"><label class="form-label small mb-1">to</label><input type="date" class="form-control" v-model="r.to"></div>
                <div class="col-md-2 col-6"><label class="form-label small mb-1">Supplier</label>
                    <select class="form-select" v-model="r.supplier_id"><option value="">All</option><option value="-">No supplier</option><option v-for="s in suppliers" :key="s.id" :value="String(s.id)">{{ s.name }}</option></select></div>
                <div class="col-md-3 col-6"><label class="form-label small mb-1">Origin country</label>
                    <select class="form-select" v-model="r.origin"><option value="">All</option><option value="-">Not set</option><option v-for="o in origins" :key="o">{{ o }}</option></select></div>
                <div class="col-md-3 col-6"><label class="form-label small mb-1">Shipment</label>
                    <select class="form-select" v-model="r.shipment_id"><option value="">All</option><option value="-">Not in a shipment</option><option v-for="s in shipments" :key="s.id" :value="String(s.id)">{{ s.name }}</option></select></div>
                <div class="col-md-2 col-6"><label class="form-label small mb-1">Status</label>
                    <select class="form-select" v-model="r.status"><option value="">Any</option><option v-for="s in ITEM_STATUS" :key="s">{{ s }}</option></select></div>
                <div class="col-md-7 d-flex flex-wrap gap-2 align-items-center">
                    <div class="form-check me-2" v-if="r.type === 'items'"><input class="form-check-input" type="checkbox" id="rep-pics" v-model="pics"><label class="form-check-label small" for="rep-pics">Pictures in PDF and Excel</label></div>
                    <button class="btn btn-link btn-sm" @click="clear">Clear filters</button>
                    <div class="ms-auto d-flex gap-2">
                        <button class="btn btn-primary btn-sm" @click="exp('xlsx')" :disabled="!!busy">{{ busy === 'xlsx' ? 'Preparing…' : 'Export Excel' }}</button>
                        <button class="btn btn-outline-primary btn-sm" @click="exp('pdf')" :disabled="!!busy">{{ busy === 'pdf' ? 'Preparing…' : 'Export PDF' }}</button>
                        <button class="btn btn-outline-primary btn-sm" @click="exp('csv')" :disabled="!!busy">{{ busy === 'csv' ? 'Preparing…' : 'Export CSV' }}</button>
                    </div>
                </div>
            </div>
        </div></div>
        <div v-if="rep">
            <h5 class="mb-0">{{ rep.title }}</h5><div class="small text-muted mb-2">{{ rep.subtitle }}</div>
            <div v-if="!rep.rows.length" class="list-surface p-5 text-center text-muted">No items match these filters.</div>
            <div v-else class="list-surface table-responsive">
                <table class="table table-sm align-middle mb-0 rep-table">
                    <thead><tr><th v-for="c in rep.columns" :key="c.k" :class="{ 'text-end': c.t }">{{ c.h }}</th></tr></thead>
                    <tbody><tr v-for="(row, i) in rep.rows" :key="i">
                        <td v-for="(c, j) in rep.columns" :key="c.k" :class="{ 'text-end mono': c.t, 'fw-semibold': j === 0 }">{{ cell(row[c.k], c.t === 'row' ? row._t : c.t) }}</td>
                    </tr></tbody>
                    <tfoot v-if="rep.totals"><tr class="fw-bold"><td v-for="c in rep.columns" :key="c.k" :class="{ 'text-end mono': c.t }">{{ cell(rep.totals[c.k], c.t) }}</td></tr></tfoot>
                </table>
            </div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import { ITEM_STATUS, fmt, download } from './purchasing.js';

const REPORTS = { items: 'Landed cost by item', supplier: 'Spend by supplier', origin: 'Spend by origin country', shipment: 'Shipments report', summary: 'Summary' };

export default {
    props: { suppliers: Array, shipments: Array, origins: Array, currency: String },
    data() { return { REPORTS, ITEM_STATUS, r: { type: 'items', from: '', to: '', supplier_id: '', shipment_id: '', status: '', origin: '' }, pics: true, rep: null, busy: '' }; },
    watch: { r: { deep: true, handler() { this.load(); } } },
    created() { this.load(); },
    methods: {
        cell(v, t) {
            if (v == null || v === '') return t ? '—' : '';
            if (!t || typeof v !== 'number') return v;
            if (t === 'pct') return (v * 100).toFixed(1) + '%';
            return fmt(v, t === 'int' ? 0 : (t === 'n3' || t === 'm3') ? 3 : 2);
        },
        load() { axios.get(this.$apiUrl + '/purchasing/report', { params: { ...this.r, format: 'json' } }).then(res => { if (res.data.status === 1) this.rep = res.data.data; else this.showError(res.data.message); }); },
        clear() { this.r = { ...this.r, from: '', to: '', supplier_id: '', shipment_id: '', status: '', origin: '' }; },
        exp(format) {
            this.busy = format;
            download(this, '/purchasing/report', { ...this.r, format, pictures: this.pics ? 1 : 0 }, `Purchasing_report.${format}`).finally(() => { this.busy = ''; });
        },
    },
};
</script>

<style scoped>
.mono { font-variant-numeric: tabular-nums; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.rep-table th { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--bs-secondary-color); }
.rep-table td { font-size: 13px; }
</style>

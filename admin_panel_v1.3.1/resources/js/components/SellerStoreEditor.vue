<template>
    <!-- Custom (Lebazone): a seller's store page - banner + about text; customers reach it from "Sold by". -->
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
            <h5 class="mb-0">{{ title }}</h5>
            <a v-if="url" :href="url" target="_blank" rel="noopener" class="small ms-auto">Open the store page ↗</a>
        </div>
        <p class="small text-muted mb-2">Customers see this page when they tap the seller's name under <b>Sold by</b> on a product. Best banner size: 1600 × 400 (wide).</p>
        <div class="sse-banner mb-2">
            <img v-if="preview" :src="preview" alt="Store banner">
            <div v-else class="sse-empty">No banner yet - a plain red strip shows instead</div>
        </div>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <label class="btn btn-outline-primary btn-sm mb-0">
                🖼 {{ preview ? 'Change banner' : 'Upload banner' }}
                <input type="file" accept="image/*" hidden @change="pick">
            </label>
            <button v-if="preview" type="button" class="btn btn-outline-danger btn-sm" @click="removeBanner">Remove banner</button>
        </div>
        <label class="form-label small mb-1" :for="'sse-about-' + uid">About the store <span class="text-muted">(optional, up to 600 characters)</span></label>
        <textarea :id="'sse-about-' + uid" v-model="text" class="form-control" rows="3" maxlength="600"
            placeholder="e.g. Original phone accessories and spare parts since 2015."></textarea>
        <button type="button" class="btn btn-primary mt-2" :disabled="busy || preparing" @click="save">{{ busy ? 'Saving…' : 'Save store page' }}</button>
    </div></div>
</template>

<script>
import axios from 'axios';
import { shrinkImage } from '../utils/shrinkImage.js';

let n = 0;
export default {
    props: {
        sellerId: { type: Number, default: null }, // set when an admin edits a seller
        about: { type: String, default: '' },
        banner: { type: String, default: '' },
        url: { type: String, default: '' },
        title: { type: String, default: 'Store page' },
    },
    emits: ['saved'],
    data() {
        return { uid: ++n, text: this.about, preview: this.banner, file: null, remove: false, busy: false, preparing: false };
    },
    watch: {
        about(v) { this.text = v; },
        banner(v) { this.preview = v; this.file = null; this.remove = false; },
    },
    methods: {
        async pick(e) {
            const f = e.target.files && e.target.files[0];
            e.target.value = '';
            if (!f) return;
            this.preparing = true;
            try {
                this.file = await shrinkImage(f, 2000, 0.85);
                this.preview = URL.createObjectURL(this.file);
                this.remove = false;
            } finally { this.preparing = false; }
        },
        removeBanner() { this.file = null; this.preview = ''; this.remove = true; },
        save() {
            const fd = new FormData();
            fd.append('about', this.text || '');
            if (this.sellerId) fd.append('seller_id', this.sellerId);
            if (this.file) fd.append('banner', this.file);
            else if (this.remove) fd.append('remove_banner', '1');
            this.busy = true;
            axios.post(this.$apiUrl + '/seller_portal/store', fd).then(r => {
                if (r.data.status !== 1) return this.showError(r.data.message);
                this.showMessage('success', r.data.message);
                this.$emit('saved');
            }).catch(e => this.showError(e?.response?.data?.message || e.message)).finally(() => { this.busy = false; });
        },
    },
};
</script>

<style scoped>
.sse-banner { border: 1px solid #E5E7EB; border-radius: 10px; overflow: hidden; aspect-ratio: 4 / 1; background: #F9FAFB; }
.sse-banner img { width: 100%; height: 100%; object-fit: cover; display: block; }
.sse-empty { height: 100%; display: flex; align-items: center; justify-content: center; font-size: 13px; color: #4B5563; text-align: center; padding: 8px; }
</style>

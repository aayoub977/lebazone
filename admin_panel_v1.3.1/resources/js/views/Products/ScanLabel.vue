<template>
    <!-- Custom (Lebazone): scan a barcode -> quick add (photo, name, price, colour, quantity) -> labels print. -->
    <div class="sl">
        <div class="page-head"><h3 class="page-head-title mb-0">Scan products</h3></div>

        <!-- Mode -->
        <div class="sl-modes mb-3">
            <button class="sl-mode" :class="{ on: !receiveMode }" @click="receiveMode = false; focusScan()">
                <span class="sl-mode-i">➕</span><span><b>Add / find a product</b><br><small>أضف أو ابحث عن منتج</small></span></button>
            <button class="sl-mode" :class="{ on: receiveMode }" @click="receiveMode = true; focusScan()">
                <span class="sl-mode-i">📦</span><span><b>Receive stock</b> · each scan = +1<br><small>استلام بضاعة · كل مسحة = +1</small></span></button>
        </div>

        <!-- Scan -->
        <div class="card mb-3"><div class="card-body">
            <div class="d-flex flex-wrap gap-2">
                <input ref="scan" v-model="code" class="form-control form-control-lg" style="flex:1;min-width:220px" autocomplete="off"
                    :placeholder="receiveMode ? 'Scan the product you received… · امسح المنتج' : 'Scan a barcode or type it + Enter · امسح الباركود'"
                    @keydown.enter.prevent="lookup">
                <CameraScanner big @scan="c => { code = c; lookup(); }" />
            </div>
            <div class="text-muted small mt-2">
                <template v-if="!receiveMode">New barcode → a short form opens, labels print when you save. Known barcode → the product opens below.</template>
                <template v-else>Each scan adds 1 to that colour's stock. Unknown barcodes are not added in this mode.</template>
            </div>
            <div v-if="flash" class="sl-flash mt-2" :class="flash.ok ? 'ok' : 'bad'">{{ flash.text }}</div>
        </div></div>

        <!-- Result -->
        <div v-for="p in products" :key="p.id" class="card mb-3"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                <div>
                    <h5 class="mb-0">{{ p.name }}</h5>
                    <div class="text-muted small">{{ p.category }}<span v-if="p.brand"> · {{ p.brand }}</span>
                        <span v-if="!p.status" class="badge bg-secondary ms-1">hidden</span></div>
                </div>
                <button class="btn btn-outline-secondary btn-sm" @click="openAddColor(p)">+ Add colour</button>
            </div>
            <div class="d-flex align-items-center gap-1 flex-wrap mb-2">
                <span class="small fw-bold me-1" :class="{ 'text-danger': !p.size }">Size{{ p.size ? '' : ' (missing!)' }}:</span>
                <button v-for="z in SIZES" :key="'p' + z.key" class="btn btn-sm" :class="p.size === z.key ? 'btn-dark' : 'btn-outline-secondary'"
                    :title="z.hint" @click="setSize(p, z.key)">{{ z.key }}</button>
            </div>
            <div v-for="v in p.variants" :key="v.id" class="sl-var" :class="{ hit: v.matched }">
                <img v-if="v.image" :src="v.image" alt=""><div v-else class="sl-noimg">📦</div>
                <div class="flex-grow-1">
                    <div><span class="sl-color">{{ v.color }}</span> <b class="ms-1">${{ money(v.sale_price) }}</b>
                        <small v-if="v.price && v.sale_price < v.price" class="text-muted text-decoration-line-through ms-1">${{ money(v.price) }}</small></div>
                    <div class="small text-muted"><code>{{ v.code }}</code>
                        <span v-if="v.barcode"> · {{ v.barcode }}</span>
                        <a v-else href="#" class="ms-1" @click.prevent="setBarcode(v)">add box barcode</a></div>
                </div>
                <div class="sl-stock" :class="v.stock > 0 ? 'text-success' : 'text-danger'">{{ v.stock }}<small>in stock</small></div>
                <div class="d-flex gap-1 flex-wrap justify-content-end">
                    <button class="btn btn-success" @click="receive(v, 1)">+1</button>
                    <button class="btn btn-outline-success" @click="receivePrompt(v)">+ …</button>
                    <button class="btn btn-outline-primary" @click="openPrint(p, v)">🏷 Labels</button>
                </div>
            </div>
        </div></div>

        <!-- Products without a size yet -->
        <div v-if="missing.count" class="card mb-3 border-warning"><div class="card-body">
            <h6 class="mb-1">⚠ {{ missing.count }} product(s) have no size yet</h6>
            <p class="small text-muted mb-2">Tap S / M / L / XL for each so the Order Station can guess the boxes.</p>
            <div v-for="p in missing.products" :key="'m' + p.id" class="d-flex align-items-center gap-2 py-1 border-top">
                <img v-if="p.image" :src="p.image" style="width:36px;height:36px;object-fit:contain;border-radius:6px">
                <span class="small fw-bold flex-grow-1 text-truncate">{{ p.name }}</span>
                <button v-for="z in SIZES" :key="'mz' + z.key" class="btn btn-sm btn-outline-secondary" :title="z.hint" @click="setSize(p, z.key, true)">{{ z.key }}</button>
            </div>
        </div></div>

        <!-- Recently added -->
        <div class="card mb-3"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0">Recently added here</h6>
                <a href="/products" class="small" @click.prevent="$router.push('/products')">All products →</a>
            </div>
            <div v-if="!recent.length" class="text-muted small">Nothing yet — scan a new barcode to add a product.</div>
            <div class="list-group list-group-flush">
                <button v-for="p in recent" :key="'r' + p.id" class="list-group-item list-group-item-action d-flex align-items-center gap-3"
                    @click="products = [p]; scrollTop()">
                    <img v-if="p.variants[0] && p.variants[0].image" :src="p.variants[0].image" style="width:36px;height:36px;object-fit:contain;border-radius:6px">
                    <span class="fw-bold">{{ p.name }}</span>
                    <span class="small text-muted">{{ p.variants.map(v => v.color + ' (' + v.stock + ')').join(', ') }}</span>
                </button>
            </div>
        </div></div>

        <!-- ============ QUICK ADD ============ -->
        <b-modal v-model="showNew" title="New product · منتج جديد" size="lg" centered @hidden="focusScan" @shown="focusName">
            <div class="small text-muted mb-3">Barcode <code>{{ form.barcode }}</code> ·
                <a href="#" @click.prevent="switchToExisting">it's a new colour of a product I already have →</a></div>

            <div class="d-flex gap-3 flex-wrap flex-md-nowrap">
                <label class="sl-photo" :class="{ has: photoUrl }">
                    <img v-if="photoUrl" :src="photoUrl" alt="">
                    <span v-else>📷<br>Take photo<br><small>صورة</small></span>
                    <input type="file" accept="image/*" capture="environment" hidden @change="onPhoto">
                </label>
                <div class="flex-grow-1">
                    <label class="form-label fw-bold mb-1">Name · الاسم *</label>
                    <input ref="qName" v-model="form.name" class="form-control form-control-lg" placeholder="e.g. Anker PowerCore 20000mAh" @input="guessCategory">
                    <div class="row g-2 mt-1">
                        <div class="col-6">
                            <label class="form-label fw-bold mb-1">Price $ · السعر *</label>
                            <input v-model="form.price" type="number" inputmode="decimal" min="0" step="0.01" class="form-control form-control-lg">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold mb-1">Quantity · الكمية</label>
                            <div class="input-group input-group-lg">
                                <button class="btn btn-outline-secondary" type="button" @click="form.colors[0].stock = Math.max(0, (+form.colors[0].stock || 0) - 1)">−</button>
                                <input v-model.number="form.colors[0].stock" type="number" inputmode="numeric" min="0" class="form-control text-center">
                                <button class="btn btn-outline-secondary" type="button" @click="form.colors[0].stock = (+form.colors[0].stock || 0) + 1">+</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <label class="form-label fw-bold mt-3 mb-1">Colour · اللون</label>
            <datalist id="lbz-colors"><option v-for="c in opts.colors" :key="c" :value="c" /></datalist>
            <div class="sl-chips">
                <button type="button" class="sl-chip" :class="{ on: !form.colors[0].color }" @click="form.colors[0].color = ''">One colour only</button>
                <button v-for="c in topColors" :key="c" type="button" class="sl-chip" :class="{ on: same(form.colors[0].color, c) }" @click="form.colors[0].color = c">
                    <span class="sl-dot" :style="{ background: swatch(c) }"></span>{{ c }}</button>
                <input v-model="form.colors[0].color" list="lbz-colors" class="form-control form-control-sm sl-other" placeholder="Other colour…">
            </div>

            <label class="form-label fw-bold mt-3 mb-1">Size · الحجم * <span class="small text-muted fw-normal">— used to guess how many boxes an order needs</span></label>
            <div class="sl-sizes">
                <button v-for="z in SIZES" :key="z.key" type="button" class="sl-size" :class="{ on: form.size === z.key }" @click="form.size = z.key">
                    <b>{{ z.key }}</b><small>{{ z.hint }}</small></button>
            </div>

            <label class="form-label fw-bold mt-3 mb-1">Category · الفئة *
                <span v-if="catGuessed" class="small text-success fw-normal ms-1">✓ picked from the name — change it if wrong</span></label>
            <select v-model="form.category_id" class="form-select" @change="catGuessed = false; catTouched = true">
                <option value="">Choose…</option>
                <option v-for="c in opts.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>

            <details class="sl-more mt-3" :open="moreOpen" @toggle="moreOpen = $event.target.open">
                <summary>More details (optional) · تفاصيل إضافية</summary>
                <div class="row mt-2">
                    <div class="col-md-6 mb-2"><label class="form-label small">Name in Arabic</label><input v-model="form.name_ar" class="form-control" dir="rtl"></div>
                    <div class="col-md-6 mb-2"><label class="form-label small">Brand</label>
                        <select v-model="form.brand_id" class="form-select"><option value="">—</option><option v-for="b in opts.brands" :key="b.id" :value="b.id">{{ b.name }}</option></select></div>
                    <div class="col-4 mb-2"><label class="form-label small">Sale price $</label><input v-model="form.sale_price" type="number" min="0" step="0.01" class="form-control" placeholder="none"></div>
                    <div class="col-4 mb-2"><label class="form-label small">Cost $ (private)</label><input v-model="form.cost" type="number" min="0" step="0.01" class="form-control"></div>
                    <div class="col-4 mb-2"><label class="form-label small">Warranty</label>
                        <select v-model="form.warranty" class="form-select"><option v-for="w in warranties" :key="w" :value="w">{{ w }}</option></select></div>
                    <div class="col-12 mb-2"><label class="form-label small">Description</label><textarea v-model="form.description" rows="2" class="form-control" placeholder="Key specs, what's in the box"></textarea></div>
                </div>
                <div class="fw-bold small mt-1">More colours of this product</div>
                <div v-for="(c, i) in form.colors.slice(1)" :key="i" class="row g-2 align-items-end mb-2">
                    <div class="col-5"><input v-model="c.color" list="lbz-colors" class="form-control" placeholder="Colour, e.g. White"></div>
                    <div class="col-4"><input v-model="c.barcode" class="form-control" placeholder="Box barcode (optional)" @keydown.enter.prevent></div>
                    <div class="col-2"><input v-model.number="c.stock" type="number" min="0" class="form-control" placeholder="Qty"></div>
                    <div class="col-1"><button class="btn btn-outline-danger w-100" @click="form.colors.splice(i + 1, 1)">×</button></div>
                </div>
                <button class="btn btn-link btn-sm px-0" @click="form.colors.push({ color: '', barcode: '', stock: 1 })">+ Another colour</button>
            </details>

            <template #footer>
                <div class="me-auto small text-muted">Label size:
                    <select v-model="labelSize" class="form-select form-select-sm d-inline-block w-auto">
                        <option value="50x30">50 × 30</option><option value="40x25">40 × 25</option><option value="58x40">58 × 40</option><option value="a4">A4 sheet</option>
                    </select></div>
                <button class="btn btn-secondary" @click="showNew = false">Cancel</button>
                <button class="btn btn-primary btn-lg" :disabled="busy" @click="saveNew">
                    Save &amp; print {{ form.colors.length === 1 ? Math.max(1, +form.colors[0].stock || 0) + ' label(s)' : 'labels' }} <b-spinner v-if="busy" small /></button>
            </template>
        </b-modal>

        <!-- Add colour to an existing product -->
        <b-modal v-model="showAddColor" title="Add a colour · لون جديد" centered @hidden="focusScan">
            <div v-if="!addColor.product">
                <label class="form-label">Find the product</label>
                <input v-model="searchTerm" class="form-control mb-2" placeholder="Type part of the name" @input="searchProducts">
                <div class="list-group">
                    <button v-for="p in searchResults" :key="p.id" class="list-group-item list-group-item-action"
                        @click="addColor.product = p">{{ p.name }} <small class="text-muted">({{ p.variants.map(v => v.color).join(', ') }})</small></button>
                </div>
            </div>
            <div v-else>
                <p class="mb-2"><b>{{ addColor.product.name }}</b>
                    <small class="text-muted">— has {{ addColor.product.variants.map(v => v.color).join(', ') }}</small></p>
                <div class="sl-chips mb-2">
                    <button v-for="c in topColors" :key="'a' + c" type="button" class="sl-chip" :class="{ on: same(addColor.color, c) }" @click="addColor.color = c">
                        <span class="sl-dot" :style="{ background: swatch(c) }"></span>{{ c }}</button>
                    <input v-model="addColor.color" list="lbz-colors" class="form-control form-control-sm sl-other" placeholder="Other…">
                </div>
                <div class="row g-2">
                    <div class="col-4"><label class="form-label small">Quantity</label><input v-model="addColor.stock" type="number" min="0" class="form-control"></div>
                    <div class="col-4"><label class="form-label small">Price $</label><input v-model="addColor.price" type="number" step="0.01" class="form-control" placeholder="same"></div>
                    <div class="col-4"><label class="form-label small">Sale $</label><input v-model="addColor.sale_price" type="number" step="0.01" class="form-control" placeholder="same"></div>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-6"><label class="form-label small">Box barcode</label><input v-model="addColor.barcode" class="form-control" @keydown.enter.prevent></div>
                    <div class="col-6"><label class="form-label small">Photo</label>
                        <input type="file" accept="image/*" capture="environment" class="form-control" @change="e => addColor.photo = e.target.files[0]"></div>
                </div>
            </div>
            <template #footer>
                <button class="btn btn-secondary" @click="showAddColor = false">Cancel</button>
                <button class="btn btn-primary" :disabled="busy || !addColor.product" @click="saveColor">Save &amp; print labels</button>
            </template>
        </b-modal>

        <!-- Print labels: which colour + how many -->
        <b-modal v-model="showPick" :title="pick.title" centered @hidden="focusScan">
            <p class="mb-2">{{ pick.question }}</p>
            <div class="d-grid gap-2">
                <button v-for="v in pick.variants" :key="v.id" class="btn text-start d-flex align-items-center gap-3"
                    :class="pick.selected && pick.selected.id === v.id ? 'btn-primary' : 'btn-outline-secondary'" @click="selectPick(v)">
                    <img v-if="v.image" :src="v.image" style="width:40px;height:40px;object-fit:contain;border-radius:6px;background:#fff">
                    <span class="fw-bold">{{ v.color }}</span>
                    <span class="ms-auto small">{{ v.code }} · stock {{ v.stock }}</span>
                </button>
            </div>
            <div v-if="pick.mode === 'label'" class="row g-2 mt-3">
                <div class="col-6"><label class="form-label">How many labels?</label>
                    <input v-model.number="pick.copies" type="number" min="1" max="500" class="form-control"></div>
                <div class="col-6"><label class="form-label">Label size</label>
                    <select v-model="labelSize" class="form-select">
                        <option value="50x30">50 × 30 mm</option><option value="40x25">40 × 25 mm</option>
                        <option value="58x40">58 × 40 mm</option><option value="a4">A4 sheet (24 per page)</option>
                    </select></div>
            </div>
            <template #footer>
                <button class="btn btn-secondary" @click="showPick = false">Cancel</button>
                <button class="btn btn-primary" :disabled="!pick.selected" @click="confirmPick">{{ pick.mode === 'label' ? 'Print' : 'OK' }}</button>
            </template>
        </b-modal>
    </div>
</template>

<script>
import axios from 'axios';
import CameraScanner from '../../components/CameraScanner.vue';
import { printProductLabels } from '../../utils/warehousePrint.js';

const blankForm = () => ({ barcode: '', name: '', name_ar: '', category_id: '', brand_id: '', price: '', sale_price: '', cost: '',
    warranty: '1 year', description: '', photo: null, size: '', colors: [{ color: '', barcode: '', stock: 1 }] });

const SIZES = [
    { key: 'S', hint: 'cable, case, charger' },
    { key: 'M', hint: 'power bank, earbuds, mouse' },
    { key: 'L', hint: 'speaker, keyboard, router' },
    { key: 'XL', hint: 'own box: monitor, big speaker' },
];

// Words in a product name -> words to look for in category names.
const HINTS = [
    [['power bank', 'powerbank', 'powercore', 'mah'], ['power bank', 'powerbank', 'power']],
    [['charger', 'adapter', 'gan ', 'wall plug', 'car charger', 'wireless charg', 'magsafe'], ['charger', 'charging', 'power']],
    [['cable', 'usb-c to', 'lightning', 'type-c to'], ['cable']],
    [['earbuds', 'airpods', 'buds', 'headphone', 'headset', 'earphone', 'tws'], ['earbud', 'headphone', 'earphone', 'audio']],
    [['speaker', 'soundbar', 'sound bar'], ['speaker', 'audio']],
    [['keyboard', 'mouse', 'webcam', 'usb hub', 'ssd', 'flash drive', 'laptop stand', 'mousepad'], ['computer', 'keyboard', 'mouse', 'laptop', 'pc', 'storage', 'accessor']],
    [['smartwatch', 'smart watch', 'watch', 'band', 'fitness tracker'], ['wearable', 'watch']],
    [['case', 'cover', 'screen protector', 'tempered glass'], ['case', 'protect', 'phone accessor']],
    [['camera', 'tripod', 'gimbal', 'ring light', 'selfie'], ['camera', 'photo']],
    [['router', 'wifi', 'wi-fi', 'extender', 'mesh'], ['network', 'router', 'wifi']],
    [['controller', 'gamepad', 'playstation', 'xbox', 'nintendo', 'gaming'], ['gaming', 'game']],
    [['smart plug', 'smart bulb', 'smart home', 'camera wifi'], ['smart home', 'smart']],
    [['iphone', 'galaxy', 'redmi', 'smartphone'], ['phone', 'mobile']],
    [['tablet', 'ipad'], ['tablet']],
];
const LAST_CAT = 'lbzLastCategory';
const store = { get: (k, d) => { try { return localStorage.getItem(k) || d; } catch (e) { return d; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} } };

export default {
    components: { CameraScanner },
    data() {
        return {
            code: '', busy: false, receiveMode: false, products: [], recent: [], flash: null, SIZES, missing: { count: 0, products: [] },
            opts: { categories: [], brands: [], colors: [], app_name: 'Lebazone' },
            warranties: ['No warranty', '1 month', '3 months', '6 months', '1 year', '2 years'],
            showNew: false, form: blankForm(), photoUrl: '', catGuessed: false, catTouched: false, moreOpen: false,
            showAddColor: false, addColor: { product: null, color: '', barcode: '', stock: 1, price: '', sale_price: '', photo: null },
            searchTerm: '', searchResults: [],
            showPick: false, pick: { mode: 'label', title: '', question: '', variants: [], selected: null, product: null, copies: 1 },
            labelSize: store.get('lbzLabelSize', '50x30'),
        };
    },
    computed: {
        topColors() { return (this.opts.colors || []).slice(0, 10); },
    },
    watch: { labelSize(v) { store.set('lbzLabelSize', v); } },
    mounted() {
        axios.get(this.$apiUrl + '/scan/options').then(r => { if (r.data.status === 1) this.opts = r.data.data; });
        this.loadRecent();
        this.loadMissing();
        this.focusScan();
    },
    methods: {
        loadMissing() { axios.get(this.$apiUrl + '/scan/missing_size').then(r => { if (r.data.status === 1) this.missing = r.data.data; }).catch(() => {}); },
        setSize(p, size, fromMissing = false) {
            axios.post(this.$apiUrl + '/scan/set_size', { product_id: p.id, size }).then(r => {
                if (r.data.status !== 1) return this.showError(r.data.message);
                if (fromMissing) {
                    this.missing.products = this.missing.products.filter(x => x.id !== p.id);
                    this.missing.count = Math.max(0, this.missing.count - 1);
                    if (!this.missing.products.length) this.loadMissing();
                } else {
                    this.products = this.products.map(x => x.id === p.id ? r.data.data : x);
                }
                this.say(true, 'Size ' + size + ' saved ✓');
            }).catch(this.fail);
        },
        loadRecent() { axios.get(this.$apiUrl + '/scan/recent').then(r => { if (r.data.status === 1) this.recent = r.data.data || []; }); },
        money(v) { return Number(v || 0).toFixed(2); },
        scrollTop() { window.scrollTo(0, 0); },
        focusScan() {
            if (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) return; // phones: no keyboard pop-up
            this.$nextTick(() => this.$refs.scan && this.$refs.scan.focus());
        },
        focusName() { this.$nextTick(() => this.$refs.qName && this.$refs.qName.focus()); },
        fail(e) { this.showError(e?.response?.data?.message || e?.message || e); },
        say(ok, text) { this.flash = { ok, text }; clearTimeout(this.flashT); this.flashT = setTimeout(() => { this.flash = null; }, 4000); },
        same(a, b) { return String(a || '').trim().toLowerCase() === String(b || '').trim().toLowerCase(); },
        swatch(c) {
            const k = String(c || '').toLowerCase().replace(/\s+/g, '');
            const map = { spacegrey: '#4B5563', spacegray: '#4B5563', rosegold: '#E8B4A6', midnight: '#1F2937', starlight: '#F5F0E6', graphite: '#374151' };
            if (map[k]) return map[k];
            return (window.CSS && CSS.supports && CSS.supports('color', k)) ? k : '#D1D5DB';
        },
        onPhoto(e) {
            const f = e.target.files && e.target.files[0];
            this.form.photo = f || null;
            if (this.photoUrl) URL.revokeObjectURL(this.photoUrl);
            this.photoUrl = f ? URL.createObjectURL(f) : '';
        },
        guessCategory() {
            if (this.catTouched) return;
            const name = ' ' + this.form.name.toLowerCase() + ' ';
            const cats = this.opts.categories || [];
            const norm = s => s.toLowerCase().replace(/[^a-z0-9 ]/g, ' ');
            let hit = null;
            // 1) a category name (or its singular) appears in the product name
            for (const c of cats) {
                const words = norm(c.name).split(' ').filter(w => w.length > 3 && !['and', 'with', 'accessories'].includes(w));
                if (words.some(w => name.includes(w) || name.includes(w.replace(/s$/, '')))) { hit = c; break; }
            }
            // 2) known gadget words
            if (!hit) {
                for (const [keys, targets] of HINTS) {
                    if (!keys.some(k => name.includes(k))) continue;
                    hit = cats.find(c => targets.some(t => norm(c.name).includes(t)));
                    if (hit) break;
                }
            }
            if (hit) { this.form.category_id = hit.id; this.catGuessed = true; }
            else if (!this.form.category_id) {
                const last = Number(store.get(LAST_CAT, 0));
                if (cats.some(c => c.id === last)) { this.form.category_id = last; this.catGuessed = false; }
            }
        },

        lookup() {
            const code = this.code.trim();
            if (!code || this.busy) return;
            this.busy = true;
            axios.get(this.$apiUrl + '/scan/lookup', { params: { code } }).then(r => {
                if (r.data.status !== 1) return this.showError(r.data.message);
                const d = r.data.data;
                this.code = '';
                if (!d.found) {
                    if (this.receiveMode) { this.say(false, 'Barcode ' + code + ' is not in the shop yet — switch to "Add / find" to add it.'); return; }
                    this.form = blankForm();
                    this.form.barcode = code;
                    this.form.colors[0].barcode = code;
                    this.form.category_id = '';
                    this.catGuessed = false; this.catTouched = false; this.moreOpen = false;
                    if (this.photoUrl) URL.revokeObjectURL(this.photoUrl);
                    this.photoUrl = '';
                    this.guessCategory();
                    this.showNew = true;
                    return;
                }
                this.products = d.products;
                if (this.receiveMode) {
                    const matches = d.products.flatMap(p => p.variants.filter(v => v.matched));
                    if (matches.length === 1) this.receive(matches[0], 1);
                    else if (matches.length > 1) this.openPick('receive', d.products[0], matches, 'Which colour?', 'This barcode is on several colours — which one did you receive?');
                }
            }).catch(this.fail).finally(() => { this.busy = false; this.focusScan(); });
        },

        saveNew() {
            const f = this.form;
            if (!f.name.trim()) return this.showError('Enter the product name');
            if (!(+f.price > 0)) return this.showError('Enter the price');
            if (!f.category_id) return this.showError('Choose a category');
            if (!f.size) return this.showError('Choose the size: S, M, L or XL');
            const extra = f.colors.slice(1).filter(c => (c.color || '').trim());
            if (extra.length && !(f.colors[0].color || '').trim()) return this.showError('You added more colours — choose the colour of the first one too');
            const colors = [f.colors[0], ...extra];
            const fd = new FormData();
            ['name', 'name_ar', 'category_id', 'brand_id', 'price', 'sale_price', 'cost', 'warranty', 'description', 'size'].forEach(k => fd.append(k, f[k] ?? ''));
            fd.append('colors', JSON.stringify(colors));
            if (f.photo) fd.append('photo', f.photo);
            this.busy = true;
            axios.post(this.$apiUrl + '/scan/create', fd).then(r => {
                if (r.data.status !== 1) return this.showError(r.data.message);
                store.set(LAST_CAT, String(f.category_id));
                const p = r.data.data;
                this.showNew = false;
                this.products = [p];
                this.loadRecent();
                if (p.variants.length === 1) {
                    const v = p.variants[0];
                    const n = Math.max(1, Math.min(500, +v.stock || 1));
                    printProductLabels(p, v, n, this.labelSize, this.opts.app_name);
                    this.say(true, 'Saved ✓ — printing ' + n + ' label(s) for ' + v.color);
                } else {
                    this.say(true, 'Saved ✓ — choose the colour to print');
                    this.openPrint(p);
                }
            }).catch(this.fail).finally(() => { this.busy = false; });
        },

        switchToExisting() {
            const bc = this.form.barcode;
            this.showNew = false;
            this.addColor = { product: null, color: '', barcode: bc, stock: 1, price: '', sale_price: '', photo: null };
            this.searchTerm = ''; this.searchResults = [];
            this.showAddColor = true;
        },
        openAddColor(p) {
            this.addColor = { product: p, color: '', barcode: '', stock: 1, price: '', sale_price: '', photo: null };
            this.showAddColor = true;
        },
        searchProducts() {
            const q = this.searchTerm.trim();
            if (q.length < 2) { this.searchResults = []; return; }
            axios.get(this.$apiUrl + '/scan/search', { params: { q } }).then(r => { this.searchResults = r.data.data || []; });
        },
        saveColor() {
            const a = this.addColor;
            if (!(a.color || '').trim()) return this.showError('Choose the colour');
            const fd = new FormData();
            fd.append('product_id', a.product.id);
            ['color', 'barcode', 'stock', 'price', 'sale_price'].forEach(k => fd.append(k, a[k] ?? ''));
            if (a.photo) fd.append('photo', a.photo);
            this.busy = true;
            axios.post(this.$apiUrl + '/scan/add_color', fd).then(r => {
                if (r.data.status !== 1) return this.showError(r.data.message);
                this.showAddColor = false;
                const p = r.data.data;
                this.products = [p];
                this.loadRecent();
                const v = p.variants.find(x => this.same(x.color, a.color));
                if (v) {
                    const n = Math.max(1, Math.min(500, +a.stock || 1));
                    printProductLabels(p, v, n, this.labelSize, this.opts.app_name);
                    this.say(true, 'Colour added ✓ — printing ' + n + ' label(s) for ' + v.color);
                } else this.openPrint(p);
            }).catch(this.fail).finally(() => { this.busy = false; });
        },

        setBarcode(v) {
            const bc = window.prompt('Scan or type the box barcode for ' + v.color);
            if (!bc) return;
            axios.post(this.$apiUrl + '/scan/set_barcode', { variant_id: v.id, barcode: bc.trim() }).then(r => {
                if (r.data.status !== 1) return this.showError(r.data.message);
                this.products = [r.data.data];
            }).catch(this.fail);
        },
        receivePrompt(v) {
            const n = parseInt(window.prompt('How many ' + v.color + ' did you receive? · كم وصل؟', '1'), 10);
            if (n > 0) this.receive(v, n);
        },
        receive(v, qty) {
            axios.post(this.$apiUrl + '/scan/receive', { variant_id: v.id, qty }).then(r => {
                if (r.data.status !== 1) return this.say(false, r.data.message);
                this.products = [r.data.data];
                const nv = r.data.data.variants.find(x => x.id === v.id);
                this.say(true, '+' + qty + ' ' + v.color + ' ✓ — now ' + (nv ? nv.stock : '') + ' in stock');
            }).catch(this.fail).finally(this.focusScan);
        },

        // Labels: always confirm the colour (pre-selected when there is only one or the scanned one).
        openPrint(p, preselect = null) {
            const selected = preselect || (p.variants.length === 1 ? p.variants[0] : (p.variants.find(v => v.matched) || null));
            this.openPick('label', p, p.variants, 'Print labels', 'Which colour are the labels for?', selected);
        },
        openPick(mode, product, variants, title, question, selected = null) {
            this.pick = { mode, title, question, variants, product, selected, copies: selected && selected.stock > 0 ? Math.min(selected.stock, 50) : 1 };
            this.showPick = true;
        },
        selectPick(v) { this.pick.selected = v; if (this.pick.mode === 'label') this.pick.copies = v.stock > 0 ? Math.min(v.stock, 50) : 1; },
        confirmPick() {
            const v = this.pick.selected;
            this.showPick = false;
            if (this.pick.mode === 'receive') return this.receive(v, 1);
            printProductLabels(this.pick.product, v, Math.max(1, this.pick.copies || 1), this.labelSize, this.opts.app_name);
            this.focusScan();
        },
    },
};
</script>

<style scoped>
.sl-modes { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.sl-mode { display: flex; align-items: center; gap: 12px; text-align: left; padding: 14px 16px; border: 2px solid #E5E7EB; border-radius: 14px; background: #fff; }
.sl-mode small { color: #6B7280; }
.sl-mode.on { border-color: #DC2626; background: #FEF2F2; }
.sl-mode-i { font-size: 1.8rem; }
.sl-flash { font-weight: 700; font-size: 1.1rem; padding: 10px 14px; border-radius: 10px; }
.sl-flash.ok { background: #DCFCE7; color: #166534; } .sl-flash.bad { background: #FEE2E2; color: #991B1B; }
.sl-var { display: flex; align-items: center; gap: 12px; padding: 10px; border: 1px solid #E5E7EB; border-radius: 12px; margin-bottom: 8px; flex-wrap: wrap; }
.sl-var.hit { border-color: #F59E0B; background: #FFFBEB; }
.sl-var img, .sl-noimg { width: 56px; height: 56px; object-fit: contain; border-radius: 8px; background: #fff; }
.sl-noimg { display: flex; align-items: center; justify-content: center; background: #F3F4F6; font-size: 1.5rem; }
.sl-color { display: inline-block; padding: 1px 10px; border-radius: 10px; background: #111827; color: #fff; font-weight: 700; font-size: .85rem; }
.sl-stock { font-size: 1.6rem; font-weight: 800; text-align: center; min-width: 70px; line-height: 1; }
.sl-stock small { display: block; font-size: .7rem; font-weight: 600; color: #6B7280; }
.sl-photo { width: 150px; height: 150px; flex-shrink: 0; border: 2px dashed #D1D5DB; border-radius: 14px; display: flex; align-items: center;
    justify-content: center; text-align: center; cursor: pointer; background: #F9FAFB; font-weight: 700; color: #6B7280; overflow: hidden; }
.sl-photo.has { border-style: solid; background: #fff; }
.sl-photo img { width: 100%; height: 100%; object-fit: contain; }
.sl-sizes { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.sl-size { display: flex; flex-direction: column; align-items: center; padding: 10px 6px; border: 2px solid #E5E7EB; border-radius: 12px; background: #fff; }
.sl-size b { font-size: 1.3rem; } .sl-size small { font-size: .68rem; color: #6B7280; text-align: center; line-height: 1.15; }
.sl-size.on { border-color: #DC2626; background: #FEF2F2; }
@media (max-width: 576px) { .sl-sizes { grid-template-columns: repeat(2, 1fr); } }
.sl-chips { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.sl-chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border: 2px solid #E5E7EB; border-radius: 20px; background: #fff; font-weight: 600; }
.sl-chip.on { border-color: #DC2626; background: #FEF2F2; color: #991B1B; }
.sl-dot { width: 14px; height: 14px; border-radius: 50%; border: 1px solid rgba(0, 0, 0, .2); }
.sl-other { width: 150px; }
.sl-more summary { cursor: pointer; font-weight: 700; color: #374151; }
@media (max-width: 576px) { .sl-modes { grid-template-columns: 1fr; } .sl-photo { width: 100%; height: 180px; } }
</style>

<template>
    <!-- Custom (Lebazone): app home. Tiles come from the same menu + permissions as the sidebar,
         so each role sees only its own features and new pages/roles appear automatically. -->
    <div class="ah">
        <div class="ah-hero mb-3">
            <div>
                <div class="ah-hello">{{ greeting }}, {{ firstName }} 👋</div>
                <div class="ah-sub">{{ roleLabel }} · {{ today }}</div>
            </div>
            <button v-if="installable" class="btn btn-light fw-bold" @click="install"><Download :size="16" class="me-1" />Install app</button>
        </div>

        <PasskeyCard />

        <div v-if="showIosHint" class="alert alert-light border small mb-3">
            <Smartphone :size="16" class="me-1" /> To install on iPhone: tap <b>Share</b> → <b>Add to Home Screen</b>.
            <button class="btn-close float-end" @click="dismissIos"></button>
        </div>

        <!-- Main jobs -->
        <div v-if="main.length" class="ah-main mb-4">
            <router-link v-for="m in main" :key="m.url" :to="m.url" class="ah-big" :style="{ '--c': m.color }">
                <span class="ah-big-ic"><component :is="m.icon" :size="28" /></span>
                <span class="ah-big-t">{{ m.label }}<small class="ar">{{ m.ar }}</small></span>
                <span v-if="m.count != null" class="ah-big-n">{{ m.count }}<small>{{ m.countLabel }}</small></span>
            </router-link>
        </div>

        <!-- Everything this account can open -->
        <div v-if="groups.length > 1 || total > 6" class="mb-3 position-relative">
            <Search :size="16" class="ah-search-ic" />
            <input v-model="q" type="search" class="form-control form-control-lg ah-search" placeholder="Search features…">
        </div>
        <template v-for="g in shownGroups" :key="g.title">
            <div class="ah-group">{{ g.title }}</div>
            <div class="ah-grid mb-3">
                <router-link v-for="i in g.items" :key="i.url" :to="i.url" class="ah-tile">
                    <span class="ah-tile-ic"><component :is="i.icon" :size="20" /></span>
                    <span class="ah-tile-t">{{ i.name }}<small v-if="i.parent">{{ i.parent }}</small></span>
                </router-link>
            </div>
        </template>
        <div v-if="!total" class="text-center text-muted py-5">Your account has no screens yet — ask the manager to give your role permissions.</div>
    </div>
</template>

<script>
import axios from 'axios';
import { markRaw } from 'vue';
import { PackageCheck, ScanBarcode, ClipboardList, ShoppingCart, Package, LayoutDashboard, Download, Smartphone, Search } from 'lucide-vue-next';
import { canInstall, promptInstall } from '../../pwa.js';
import PasskeyCard from '../../components/PasskeyCard.vue';

const MAIN = [
    { perm: 'warehouse_pack', url: '/warehouse?tab=station', label: 'Pack orders', ar: 'تجهيز الطلبات', icon: PackageCheck, color: '#DC2626', key: 'pack' },
    { perm: 'product_scan', url: '/scan', label: 'Scan products', ar: 'مسح وإضافة المنتجات', icon: ScanBarcode, color: '#2563EB' },
    { perm: 'order_list', url: '/warehouse?tab=board', label: 'Orders board', ar: 'لوحة الطلبات', icon: ClipboardList, color: '#059669', key: 'board' },
    { perm: 'order_list', url: '/orders', label: 'All orders', ar: 'كل الطلبات', icon: ShoppingCart, color: '#D97706' },
    { perm: 'product_list', url: '/products', label: 'Products', ar: 'المنتجات', icon: Package, color: '#7C3AED' },
    { perm: 'manage_dashboard', url: '/dashboard', label: 'Dashboard', ar: 'الإحصائيات', icon: LayoutDashboard, color: '#111827' },
];

export default {
    components: { Download, Smartphone, Search, PasskeyCard },
    inject: { lbzAppMenu: { default: () => () => [] } },
    data() {
        let user = {};
        try { user = JSON.parse(localStorage.getItem('user') || '{}') || {}; } catch (e) {}
        let iosDismissed = false;
        try { iosDismissed = localStorage.getItem('lbzIosHint') === '1'; } catch (e) {}
        return { user, q: '', installable: canInstall(), iosDismissed, counts: {} };
    },
    computed: {
        firstName() { return String(this.user.username || 'there').split(' ')[0]; },
        roleLabel() { return window.Role || (this.user.role && this.user.role.name) || ''; },
        today() { return new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' }); },
        greeting() { const h = new Date().getHours(); return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening'; },
        standalone() { return window.matchMedia && window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true; },
        showIosHint() { return !this.iosDismissed && !this.standalone && /iphone|ipad|ipod/i.test(navigator.userAgent); },
        main() {
            return MAIN.filter(m => this.$can(m.perm)).slice(0, 4).map(m => ({
                ...m, icon: markRaw(m.icon),
                count: m.key && this.counts[m.key] != null ? this.counts[m.key] : null,
                countLabel: m.key === 'pack' ? 'to pack' : m.key === 'board' ? 'open' : '',
            }));
        },
        groups() {
            const mainUrls = new Set(this.main.map(m => m.url));
            return (this.lbzAppMenu() || []).map(g => ({ ...g, items: g.items.filter(i => !mainUrls.has(i.url)) })).filter(g => g.items.length);
        },
        total() { return this.groups.reduce((a, g) => a + g.items.length, 0) + this.main.length; },
        shownGroups() {
            const q = this.q.trim().toLowerCase();
            if (!q) return this.groups;
            return this.groups.map(g => ({ ...g, items: g.items.filter(i => (i.name + ' ' + (i.parent || '') + ' ' + g.title).toLowerCase().includes(q)) }))
                .filter(g => g.items.length);
        },
    },
    mounted() {
        this._onInstallable = () => { this.installable = true; };
        this._onInstalled = () => { this.installable = false; };
        window.addEventListener('pwa:installable', this._onInstallable);
        window.addEventListener('pwa:installed', this._onInstalled);
        this.loadCounts();
        this._t = setInterval(() => { if (document.visibilityState === 'visible') this.loadCounts(); }, 30000);
    },
    beforeUnmount() {
        window.removeEventListener('pwa:installable', this._onInstallable);
        window.removeEventListener('pwa:installed', this._onInstalled);
        clearInterval(this._t);
    },
    methods: {
        async install() { await promptInstall(); this.installable = canInstall(); },
        dismissIos() { this.iosDismissed = true; try { localStorage.setItem('lbzIosHint', '1'); } catch (e) {} },
        loadCounts() {
            if (!this.$can('warehouse_pack') && !this.$can('order_list')) return;
            axios.get(this.$apiUrl + '/warehouse/board').then(r => {
                if (r.data.status !== 1) return;
                const list = r.data.data.orders || [];
                this.counts = {
                    pack: list.filter(o => ['new', 'assigned', 'picking', 'picked', 'packed', 'stamped'].includes(o.stage)).length,
                    board: list.filter(o => o.stage !== 'out').length,
                };
            }).catch(() => {});
        },
    },
};
</script>

<style scoped>
.ah { max-width: 1100px; margin: 0 auto; }
.ah .ar { font-family: Tahoma, Arial, sans-serif; direction: rtl; unicode-bidi: isolate; }
.ah-hero { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; color: #fff; border-radius: 20px;
    padding: 22px 20px; background: linear-gradient(135deg, #DC2626, #991B1B 60%, #111827); box-shadow: 0 10px 30px rgba(153, 27, 27, .25); }
.ah-hello { font-size: 1.5rem; font-weight: 800; }
.ah-sub { opacity: .85; }
.ah-main { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 12px; }
.ah-big { display: flex; align-items: center; gap: 14px; padding: 18px; border-radius: 18px; background: #fff; text-decoration: none; color: #111827;
    border: 1px solid #F3F4F6; box-shadow: 0 4px 14px rgba(17, 24, 39, .06); transition: transform .12s, box-shadow .12s; }
.ah-big:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(17, 24, 39, .1); color: #111827; }
.ah-big-ic { width: 54px; height: 54px; border-radius: 16px; display: flex; align-items: center; justify-content: center; color: #fff; background: var(--c); flex-shrink: 0; }
.ah-big-t { font-weight: 800; font-size: 1.1rem; line-height: 1.2; flex: 1; }
.ah-big-t small { display: block; font-weight: 600; color: #6B7280; font-size: .85rem; }
.ah-big-n { text-align: center; font-size: 1.6rem; font-weight: 900; color: var(--c); line-height: 1; }
.ah-big-n small { display: block; font-size: .65rem; font-weight: 700; color: #6B7280; }
.ah-search { padding-left: 40px; border-radius: 14px; }
.ah-search-ic { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #9CA3AF; }
.ah-group { font-size: .75rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: #6B7280; margin: 6px 4px 8px; }
.ah-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
.ah-tile { display: flex; align-items: center; gap: 10px; padding: 12px; border-radius: 14px; background: #fff; border: 1px solid #F3F4F6;
    text-decoration: none; color: #111827; font-weight: 600; font-size: .9rem; line-height: 1.2; }
.ah-tile:hover { border-color: #FCA5A5; background: #FEF2F2; color: #111827; }
.ah-tile-ic { width: 36px; height: 36px; border-radius: 10px; background: #F3F4F6; display: flex; align-items: center; justify-content: center; color: #DC2626; flex-shrink: 0; }
.ah-tile-t small { display: block; color: #9CA3AF; font-weight: 500; font-size: .72rem; }
@media (max-width: 576px) {
    .ah-hello { font-size: 1.25rem; }
    .ah-main { grid-template-columns: 1fr 1fr; }
    .ah-big { flex-direction: column; align-items: flex-start; padding: 14px; }
    .ah-big-n { text-align: left; }
    .ah-grid { grid-template-columns: 1fr 1fr; }
}
</style>

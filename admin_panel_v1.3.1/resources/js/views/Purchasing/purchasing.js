// Custom (Lebazone): shared helpers for the Purchasing screens.
import axios from 'axios';

export const ITEM_STATUS = ['Quote', 'Ordered', 'Paid', 'Shipped', 'Received'];
export const SHIP_STATUS = ['Planning', 'Booked', 'In transit', 'Arrived', 'Delivered'];
export const MODES = ['Sea (LCL)', 'Sea (FCL)', 'Air', 'Land', 'Express'];
export const PLATFORMS = ['1688', 'Alibaba', 'Made-in-China', 'Taobao', 'Local market', 'Factory direct', 'Other'];
export const COUNTRIES = ['China', 'Turkey', 'India', 'Vietnam', 'Indonesia', 'Thailand', 'Malaysia', 'Pakistan', 'Bangladesh', 'Egypt',
    'Saudi Arabia', 'United Arab Emirates', 'Oman', 'Jordan', 'South Korea', 'Japan', 'Taiwan', 'Hong Kong', 'Italy', 'Germany', 'Spain',
    'United Kingdom', 'United States', 'Lebanon'];

export function num(v) {
    if (v === '' || v == null) return null;
    const x = typeof v === 'number' ? v : parseFloat(String(v).replace(/,/g, ''));
    return Number.isFinite(x) ? x : null;
}
export function fmt(v, d = 2) {
    if (v == null || !Number.isFinite(Number(v))) return '—';
    return Number(v).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
}
export function statusClass(s) {
    return { Quote: 'bg-secondary-subtle text-secondary-emphasis', Ordered: 'bg-warning-subtle text-warning-emphasis', Paid: 'bg-info-subtle text-info-emphasis',
        Shipped: 'bg-dark text-white', Received: 'bg-success-subtle text-success-emphasis', Planning: 'bg-secondary-subtle text-secondary-emphasis',
        Booked: 'bg-warning-subtle text-warning-emphasis', 'In transit': 'bg-dark text-white', Arrived: 'bg-info-subtle text-info-emphasis',
        Delivered: 'bg-success-subtle text-success-emphasis' }[s] || 'bg-light text-dark';
}

/** POST JSON or FormData; resolves the response body when status === 1, shows the message either way. */
export function post(vm, url, data, { silent = false } = {}) {
    return axios.post(vm.$apiUrl + url, data).then(r => {
        if (r.data.status === 1) { if (!silent) vm.showMessage('success', r.data.message); return r.data; }
        vm.showError(r.data.message); return null;
    }).catch(e => { vm.showError(e.response?.data?.message || e.message); return null; });
}

/** GET a file (xlsx / csv / pdf) and save it. */
export function download(vm, url, params, fallbackName) {
    return axios.get(vm.$apiUrl + url, { params, responseType: 'blob' }).then(async res => {
        if (res.data.type === 'application/json') {
            const body = JSON.parse(await res.data.text());
            vm.showError(body.message || 'Export failed');
            return;
        }
        const m = /filename="?([^";]+)"?/i.exec(res.headers['content-disposition'] || '');
        const href = URL.createObjectURL(res.data);
        const a = document.createElement('a');
        a.href = href; a.download = m ? m[1] : fallbackName;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(href), 1000);
    }).catch(e => vm.showError(e.response?.statusText || e.message));
}

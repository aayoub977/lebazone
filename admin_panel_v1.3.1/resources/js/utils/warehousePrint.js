// Custom (Lebazone): printing for the warehouse (order sheet, box labels, badges, product labels).
// Prints through a hidden frame, so pop-up blockers never stop a label from printing.
import { code128Svg } from './code128.js';
import { BrowserQRCodeSvgWriter } from '@zxing/browser';

/** QR code as an SVG string (phones read these far more easily than long barcodes). */
export function qrSvg(text, px = 200) {
    try { return new BrowserQRCodeSvgWriter().write(String(text), px, px).outerHTML; } catch (e) { return ''; }
}

export const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const money = v => Number(v || 0).toFixed(2);

const get = (k, d) => { try { return localStorage.getItem(k) || d; } catch (e) { return d; } };
const set = (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} };
export const boxSize = { get: () => get('lbzBoxSize', '58x40'), set: v => set('lbzBoxSize', v) };

export function printHtml(title, css, body) {
    const html = `<!doctype html><html><head><meta charset="utf-8"><title>${esc(title)}</title><style>
        * { box-sizing: border-box; } body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; }
        ${css}</style></head><body>${body}</body></html>`;
    const f = document.createElement('iframe');
    f.setAttribute('aria-hidden', 'true');
    f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
    document.body.appendChild(f);
    const d = f.contentWindow.document;
    d.open(); d.write(html); d.close();
    setTimeout(() => {
        try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) {}
        setTimeout(() => f.remove(), 60000);
    }, 300);
    return true;
}

function sheetHtml(o, appName) {
    const ll = o.ll_rate > 0 ? ` <span class="ll">(${Math.round(o.total * o.ll_rate).toLocaleString('en-US')} L.L.)</span>` : '';
    const rows = o.items.map(i => `<tr><td>${esc(i.name)}${i.color ? ' — ' + esc(i.color) : ''}<div class="c">${esc(i.code)}</div></td>
        <td class="r">${i.qty}</td><td class="r">$${money(i.price)}</td><td class="r">$${money(i.price * i.qty)}</td></tr>`).join('');
    const bc = code128Svg(o.sheet_code, { height: 40 });
    const qr = qrSvg(o.sheet_code);
    const half = (title, inner) => `<div class="half"><div class="hd"><div><div class="brand">${esc(appName)}</div><div class="t">${title}</div></div>
        <div class="bcw"><div class="qr">${qr}</div><div><div class="bc">${bc}</div><div class="code">SCAN · ${esc(o.sheet_code)}</div></div></div></div>${inner}</div>`;
    const receipt = half('RECEIPT · فاتورة', `
        <div class="meta"><b>Order ${esc(o.number)}</b> · ${esc(String(o.created_at).slice(0, 16))} · Payment: ${esc(o.payment_method)}</div>
        <div class="meta">Customer: <b>${esc(o.customer)}</b> · ${esc(o.phone)}</div>
        <table class="it"><thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Total</th></tr></thead><tbody>${rows}</tbody></table>
        <div class="tot">Delivery: $${money(o.delivery_charge)}${o.discount ? ' · Discount: -$' + money(o.discount) : ''} · <b>Total: $${money(o.total)}</b>${ll}</div>
        <div class="foot"><div class="stamp">STAMP</div><div class="small">Thank you for shopping with ${esc(appName)}.<br>Returns within 10 days, unused and in original packaging. Keep this receipt for warranty.</div></div>`);
    const note = half('DELIVERY NOTE · ورقة تسليم', `
        <div class="region" style="background:${o.region_color}">${esc(o.region_label)} · ${esc(o.region_ar)}</div>
        <div class="meta">Order <b>${esc(o.number)}</b> · Boxes: <b>${o.boxes || '___'}</b></div>
        <div class="meta">Deliver to: <b>${esc(o.customer)}</b> · <b>${esc(o.phone)}</b></div>
        <div class="meta">${esc(o.address)}${o.landmark ? ' · Landmark: ' + esc(o.landmark) : ''}</div>
        ${o.note ? `<div class="meta">Note: ${esc(o.note)}</div>` : ''}
        <div class="cod">${o.cod > 0 ? 'COLLECT CASH: $' + money(o.cod) + (o.ll_rate > 0 ? ' (' + Math.round(o.cod * o.ll_rate).toLocaleString('en-US') + ' L.L.)' : '') : 'PAID - collect nothing'}</div>
        <div class="foot"><div class="stamp">STAMP</div>
            <div class="scanbox"><div class="qrbig">${qrSvg(o.sheet_code, 300)}</div><div class="scanlbl">SCAN AFTER<br>STAMPING<br><span dir="rtl">امسح بعد الختم</span></div></div>
            <div class="sig">Packed by: ______________<br><br>Driver: ______________<br><br>Customer signature: ______________</div></div>`);
    return `<div class="page">${receipt}<div class="cut">✂ - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -</div>${note}</div>`;
}

const SHEET_CSS = `@page { size: A4; margin: 8mm; } .page { height: 280mm; display: flex; flex-direction: column; page-break-after: always; }
    .half { flex: 1; padding: 3mm 2mm; overflow: hidden; } .cut { text-align: center; color: #999; font-size: 8pt; }
    .hd { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #000; padding-bottom: 2mm; margin-bottom: 2mm; }
    .brand { font-size: 18pt; font-weight: bold; color: #DC2626; } .t { font-weight: bold; letter-spacing: 1px; }
    .bcw { display: flex; gap: 3mm; align-items: center; } .qr { width: 24mm; height: 24mm; } .qr svg { width: 100%; height: 100%; display: block; }
    .bcw > div:last-child { width: 70mm; } .bc { height: 17mm; } .bc svg { width: 100%; height: 100%; } .code { text-align: center; font-size: 9pt; letter-spacing: 1px; font-weight: bold; }
    .meta { font-size: 10pt; margin: 1mm 0; } .it { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-top: 2mm; }
    .it th, .it td { border-bottom: 1px solid #ddd; padding: 1mm; text-align: left; } .r { text-align: right !important; } .c { font-size: 8pt; color: #555; }
    .tot { text-align: right; font-size: 11pt; margin-top: 2mm; } .ll { font-size: 9pt; color: #444; }
    .region { color: #fff; font-size: 22pt; font-weight: bold; padding: 2mm 4mm; border-radius: 2mm; display: inline-block; margin-bottom: 2mm; }
    .cod { font-size: 16pt; font-weight: bold; border: 2px solid #000; padding: 2mm; margin: 3mm 0; text-align: center; }
    .foot { display: flex; gap: 6mm; align-items: flex-end; margin-top: 3mm; }
    .stamp { width: 38mm; height: 26mm; border: 2px dashed #888; color: #aaa; display: flex; align-items: center; justify-content: center; font-weight: bold; }
    .small { font-size: 8.5pt; color: #333; } .sig { font-size: 10pt; }
    .scanbox { display: flex; align-items: center; gap: 2mm; padding: 2mm; border: 2px solid #000; border-radius: 2mm; background: #fff; }
    .qrbig { width: 34mm; height: 34mm; padding: 1.5mm; background: #fff; } .qrbig svg { width: 100%; height: 100%; display: block; }
    .scanlbl { font-size: 9pt; font-weight: bold; line-height: 1.25; }`;

/** Receipt + delivery note (A4) for one or more full orders (from /warehouse/order). */
export function printSheets(orders, appName = 'Lebazone') {
    if (!orders.length) return false;
    return printHtml(orders.length === 1 ? 'Order ' + orders[0].number : 'Order sheets', SHEET_CSS, orders.map(o => sheetHtml(o, appName)).join(''));
}

export function printBoxes(o, size = boxSize.get()) {
    if (!o.boxes) return false;
    const sizes = { '58x40': [58, 40], '100x70': [100, 70], '100x150': [100, 150] };
    const sheet = size === 'a4';
    const [w, h] = sheet ? [99, 67] : (sizes[size] || sizes['58x40']);
    const k = Math.min(w / 58, h / 40);
    let body = '';
    for (let n = 1; n <= o.boxes; n++) {
        const code = 'BX' + String(o.id).padStart(6, '0') + '-' + n;
        body += `<div class="l"><div class="rg" style="background:${o.region_color}">${esc(o.region_label)} · ${esc(o.region_ar || '')}</div>
            <div class="row"><b>${esc(o.number)}</b><b>BOX ${n}/${o.boxes}</b></div>
            <div class="cu">${esc(o.customer)} · ${esc((o.address || '').slice(0, 60))}</div>
            <div class="b">${code128Svg(code, { height: 40 })}</div><div class="code">${code}</div></div>`;
    }
    const css = `@page { size: ${sheet ? 'A4' : w + 'mm ' + h + 'mm'}; margin: ${sheet ? '10mm 5mm' : '0'}; }
        ${sheet ? '.wrap{display:grid;grid-template-columns:repeat(2,' + w + 'mm);gap:2mm}' : ''}
        .l { width: ${w}mm; height: ${h}mm; padding: ${1.5 * k}mm; display: flex; flex-direction: column; overflow: hidden; ${sheet ? 'border:1px dashed #ccc;' : 'page-break-after: always;'} }
        .rg { color: #fff; font-weight: bold; text-align: center; font-size: ${10 * k}pt; border-radius: 1mm; }
        .row { display: flex; justify-content: space-between; font-size: ${7.5 * k}pt; margin-top: .6mm; }
        .cu { font-size: ${6 * k}pt; line-height: 1.15; max-height: 2.4em; overflow: hidden; }
        .b { flex: 1; min-height: 0; margin-top: .5mm; } .b svg { width: 100%; height: 100%; display: block; }
        .code { text-align: center; font-size: ${6.5 * k}pt; }`;
    return printHtml('Box labels ' + o.number, css, `<div class="wrap">${body}</div>`);
}

export function printBadges(list, appName = 'Lebazone') {
    const body = list.map(b => `<div class="bd"><div class="br">${esc(appName)}</div><div class="nm">${esc(b.name)}</div>
        <div class="rl">${esc(b.role)}</div><div class="b">${code128Svg(b.code, { height: 40 })}</div><div class="code">${esc(b.code)}</div></div>`).join('');
    const css = `@page { size: A4; margin: 12mm; } .wrap { display: grid; grid-template-columns: repeat(2, 85.6mm); gap: 6mm; }
        .bd { width: 85.6mm; height: 54mm; border: 1px solid #999; border-radius: 3mm; padding: 3mm 4mm; display: flex; flex-direction: column; }
        .br { color: #DC2626; font-weight: bold; font-size: 12pt; } .nm { font-size: 15pt; font-weight: bold; margin-top: 1mm; }
        .rl { font-size: 9pt; color: #555; } .b { flex: 1; min-height: 0; margin-top: 2mm; } .b svg { width: 100%; height: 100%; }
        .code { text-align: center; font-size: 9pt; letter-spacing: 1px; }`;
    return printHtml('Badge', css, `<div class="wrap">${body}</div>`);
}

/** Product price labels (one colour, any number of copies). */
export function printProductLabels(p, v, copies, size = '50x30', appName = 'Lebazone') {
    const sizes = { '50x30': [50, 30], '40x25': [40, 25], '58x40': [58, 40] };
    const sheet = size === 'a4';
    const [w, h] = sheet ? [63.5, 33.9] : (sizes[size] || sizes['50x30']);
    const svg = code128Svg(v.code, { height: 40 });
    const price = Number(v.sale_price || v.price || 0).toFixed(2);
    const small = h < 28;
    const one = `<div class="l"><div class="top"><b>${esc(appName)}</b><span>$${price}</span></div>
        <div class="n">${esc(p.name)}</div><div class="c">${esc(v.color)}</div>
        <div class="b">${svg}</div><div class="code">${esc(v.code)}</div></div>`;
    const css = `@page { size: ${sheet ? 'A4' : w + 'mm ' + h + 'mm'}; margin: ${sheet ? '13mm 7mm' : '0'}; }
        ${sheet ? '.wrap{display:grid;grid-template-columns:repeat(3,' + w + 'mm);column-gap:2.5mm}' : ''}
        .l { width: ${w}mm; height: ${h}mm; padding: 1.5mm 2mm; overflow: hidden; display: flex; flex-direction: column;
             ${sheet ? '' : 'page-break-after: always; break-after: page;'} }
        .top { display: flex; justify-content: space-between; font-size: ${small ? 6.5 : 8}pt; line-height: 1.1; }
        .top span { font-weight: bold; font-size: ${small ? 8 : 10}pt; }
        .n { font-size: ${small ? 6 : 7}pt; line-height: 1.15; max-height: 2.3em; overflow: hidden; margin-top: .3mm; }
        .c { font-size: ${small ? 6.5 : 8}pt; font-weight: bold; text-transform: uppercase; }
        .b { flex: 1; min-height: 0; margin-top: .5mm; } .b svg { width: 100%; height: 100%; display: block; }
        .code { text-align: center; font-size: ${small ? 6 : 7}pt; letter-spacing: .5px; }`;
    return printHtml('Label ' + v.code, css, `<div class="wrap">${one.repeat(Math.max(1, copies))}</div>`);
}

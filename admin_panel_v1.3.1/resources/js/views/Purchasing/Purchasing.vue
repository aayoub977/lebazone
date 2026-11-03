<template>
    <!-- Custom (Lebazone): Purchasing — import costing (formerly the "Orders Online" app). -->
    <div class="pur">
        <div class="page-head flex-wrap gap-2">
            <div>
                <h3 class="page-head-title mb-0">Purchasing</h3>
                <div class="small text-muted">Landed cost per piece: goods + freight by CBM + delivery. Linked items update Lebazone's cost price and stock.</div>
            </div>
            <div class="ms-auto d-flex flex-wrap gap-2 align-items-center">
                <div class="input-group input-group-sm pur-rate" title="Default price for 1 CBM, used when no saved freight rate applies">
                    <span class="input-group-text">Default CBM</span>
                    <input type="number" step="any" min="0" class="form-control text-end" v-model="rateInput" @change="saveDefaultRate">
                </div>
                <button class="btn btn-sm btn-outline-secondary" @click="panel = 'reports'">Reports</button>
                <button class="btn btn-sm btn-outline-secondary" @click="exportAll" :disabled="busy.export">{{ busy.export ? 'Preparing…' : 'Export Excel' }}</button>
                <button class="btn btn-sm btn-outline-secondary" @click="panel = 'settings'" title="Settings">⚙ Settings</button>
                <button class="btn btn-sm btn-outline-primary" @click="openForm('shipment', null)">+ Shipment</button>
                <button class="btn btn-sm btn-primary" @click="openItem(null)">+ Add item</button>
            </div>
        </div>

        <div v-if="!loaded" class="text-center text-muted py-5">Loading…</div>

        <!-- ================= ONE SCREEN: items by shipment + suppliers + freight rates ================= -->
        <div v-else class="row g-3">
            <div class="col-xxl-9 col-xl-8">
                <div class="pur-stats mb-3">
                    <div class="pur-stat"><div class="k">Item lines</div><div class="v">{{ list.length }}<small v-if="list.length !== items.length"> of {{ items.length }}</small></div></div>
                    <div class="pur-stat"><div class="k">Total pieces</div><div class="v">{{ fmt(sum('total_items'), 0) }}</div></div>
                    <div class="pur-stat"><div class="k">Total CBM</div><div class="v">{{ fmt(sum('cbm'), 3) }}</div></div>
                    <div class="pur-stat"><div class="k">Goods</div><div class="v">{{ money(sum('item_cost')) }}</div></div>
                    <div class="pur-stat"><div class="k">Freight + delivery</div><div class="v">{{ money(sum('shipping')) }}</div></div>
                    <div class="pur-stat hl"><div class="k">Landed total</div><div class="v">{{ money(sum('total')) }}</div></div>
                    <div class="pur-stat hl"><div class="k">Avg cost / piece</div><div class="v">{{ money(avgPiece, 3) }}</div></div>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-2 align-items-center">
                    <input type="search" class="form-control form-control-sm flex-grow-1" style="min-width:200px;max-width:360px" v-model="f.q" placeholder="Search name, colour, SKU, supplier…">
                    <select class="form-select form-select-sm w-auto" v-model="f.sup"><option value="">All suppliers</option><option value="-">No supplier</option><option v-for="s in suppliersSorted" :key="s.id" :value="String(s.id)">{{ s.name }}</option></select>
                    <select class="form-select form-select-sm w-auto" v-model="f.ship"><option value="">All shipments</option><option value="-">Not in a shipment</option><option v-for="s in shipments" :key="s.id" :value="String(s.id)">{{ s.name }}</option></select>
                    <select class="form-select form-select-sm w-auto" v-model="f.status"><option value="">Any status</option><option v-for="s in ITEM_STATUS" :key="s">{{ s }}</option></select>
                    <select class="form-select form-select-sm w-auto" v-model="f.origin"><option value="">All origins</option><option value="-">Origin not set</option><option v-for="o in origins" :key="o">{{ o }}</option></select>
                    <select class="form-select form-select-sm w-auto" v-model="f.link"><option value="">Linked or not</option><option value="yes">Linked to a product</option><option value="no">Not linked</option></select>
                    <select class="form-select form-select-sm w-auto" v-model="f.sort"><option value="new">Newest first</option><option value="name">Name A–Z</option><option value="total">Highest total</option><option value="per">Highest cost / piece</option></select>
                    <div class="form-check form-switch mb-0 ms-1"><input class="form-check-input" type="checkbox" id="pur-group" v-model="grouped" @change="saveGrouped"><label class="form-check-label small" for="pur-group">Group by shipment</label></div>
                    <button v-if="filtered" class="btn btn-link btn-sm p-0" @click="clearFilters">Clear filters</button>
                </div>

                <div v-if="!items.length" class="list-surface p-5 text-center">
                    <h5>No items yet</h5>
                    <p class="text-muted">Add an item with its box or item size, quantity and price per piece. Freight, delivery and the cost of each piece are worked out for you.</p>
                    <button class="btn btn-primary" @click="openItem(null)">+ Add first item</button>
                </div>
                <div v-else class="list-surface">
                    <table class="table align-middle mb-0 pur-table">
                        <thead><tr>
                            <th>Item</th><th>Packing</th><th class="text-end">Goods</th><th class="text-end">Freight</th><th class="text-end">Delivery</th>
                            <th class="text-end">Total</th><th class="text-end">Cost / piece</th><th>Lebazone</th>
                        </tr></thead>
                        <tbody v-for="g in groups" :key="g.key">
                            <!-- shipment header -->
                            <tr v-if="grouped" class="pur-group">
                                <td colspan="8">
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <button class="btn btn-sm btn-link p-0 text-decoration-none fw-bold text-body" @click="toggle(g.key)">{{ closed[g.key] ? '▸' : '▾' }} {{ g.sh ? g.sh.name : 'Not in a shipment' }}</button>
                                        <span v-if="g.sh" class="badge" :class="statusClass(g.sh.status)">{{ g.sh.status }}</span>
                                        <span class="small text-muted">{{ g.sh ? shipLine(g.sh) : 'Items with no shipment keep their own delivery cost' }}</span>
                                        <span class="small ms-auto mono">{{ g.rows.length }} line{{ g.rows.length === 1 ? '' : 's' }} · {{ fmt(sum('total_items', g.rows), 0) }} pcs · {{ fmt(sum('cbm', g.rows), 3) }} CBM<template v-if="g.sh"> · delivery {{ money(g.sh.delivery_cost) }}</template> · <b>{{ money(sum('total', g.rows)) }}</b></span>
                                        <template v-if="g.sh">
                                            <button class="btn btn-sm btn-outline-success" @click="receiveShipment(g.sh)" :disabled="!shipItems(g.sh).length">{{ g.sh.received_at ? 'Received ✓ (again)' : 'Receive into stock' }}</button>
                                            <button class="btn btn-sm btn-outline-secondary" @click="openForm('shipment', g.sh)">Edit</button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                            <template v-if="!closed[g.key]">
                                <tr v-for="it in g.rows" :key="it.id" @click="openItem(it)" class="pur-row">
                                    <td class="c-item" data-label="Item">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="pur-pic" :class="{ has: it.picture_url, over: dropId === it.id }" @click.stop="it.picture_url ? zoom(it.picture_url) : openItem(it)"
                                                @dragover.prevent="dropId = it.id" @dragleave="dropId = null" @drop.prevent="dropPicture(it, $event)" title="Drop a photo here">
                                                <img v-if="it.picture_url" :src="it.picture_url" alt="" loading="lazy"><span v-else>Drop<br>photo</span>
                                                <div v-if="uploading === it.id" class="busy">…</div>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="fw-semibold">{{ it.name }}</div>
                                                <div class="d-flex flex-wrap gap-1 my-1" v-if="it.variants"><span class="badge rounded-pill text-bg-light border" v-for="v in splitV(it.variants)" :key="v">{{ v }}</span></div>
                                                <div class="small text-muted">{{ [supName(it), it.calc.origin, it.sku ? 'SKU ' + it.sku : ''].filter(Boolean).join(' · ') || 'No supplier' }}</div>
                                                <span class="badge" :class="statusClass(it.status)">{{ it.status }}</span>
                                                <span v-if="it.shipment_id && !grouped" class="small text-muted ms-1">{{ shipName(it.shipment_id) }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Packing">
                                        <div class="mono"><b>{{ fmt(it.calc.total_items, 0) }}</b> pcs · {{ it.calc.cbm_estimated ? '≈ ' : '' }}{{ fmt(it.calc.cbm, 3) }} CBM
                                            <span v-if="it.calc.cbm_estimated" class="badge border text-muted fw-normal">estimated</span></div>
                                        <div class="small text-muted">{{ packText(it) }}</div>
                                        <div class="small text-muted" v-if="it.calc.cbm_estimated && it.calc.est">est. carton {{ dims(it.calc.est.L, it.calc.est.W, it.calc.est.H) }}</div>
                                        <div class="small text-muted" v-else-if="it.calc.has_box">box {{ dims(it.box_l, it.box_w, it.box_h) }}</div>
                                    </td>
                                    <td class="text-end" data-label="Goods"><div class="mono">{{ money(it.calc.item_cost) }}</div><div class="small text-muted" v-if="it.calc.unit_cost != null">{{ money(it.calc.unit_cost, 3) }} / pc</div></td>
                                    <td class="text-end" data-label="Freight"><div class="mono">{{ money(it.calc.freight) }}</div><div class="small text-muted" v-if="it.calc.cbm != null" :title="it.calc.rate_src">@ {{ fmt(it.calc.rate, 0) }}/CBM · {{ short(it.calc.rate_src) }}</div></td>
                                    <td class="text-end" data-label="Delivery"><div class="mono">{{ money(it.calc.delivery) }}</div><div class="small text-muted" v-if="it.shipment_id">{{ it.calc.share != null ? fmt(it.calc.share * 100, 1) + '% of shipment' : 'share of shipment' }}</div></td>
                                    <td class="text-end fw-semibold" data-label="Total"><div class="mono">{{ money(it.calc.total) }}</div></td>
                                    <td class="text-end" data-label="Cost / piece"><div class="mono fw-bold">{{ money(it.calc.per_item, 3) }}</div>
                                        <div class="small" v-if="it.calc.sell != null" :class="it.calc.margin < 0 ? 'text-danger' : 'text-success'">sell {{ money(it.calc.sell) }} · {{ fmt(it.calc.margin * 100, 1) }}%</div>
                                        <div class="small text-muted" v-else-if="it.calc.suggested_price">suggest {{ money(it.calc.suggested_price) }}</div></td>
                                    <td data-label="Lebazone">
                                        <template v-if="it.link">
                                            <div class="small fw-semibold text-truncate" style="max-width:170px" :title="it.link.name">{{ it.link.name }}</div>
                                            <div class="small text-muted">{{ it.link.sku }} · stock {{ it.link.available ?? '—' }}</div>
                                            <div class="small" :class="costSynced(it) ? 'text-success' : 'text-warning-emphasis'">cost {{ money(it.link.purchase_price) }}{{ costSynced(it) ? ' ✓' : ' — update' }}</div>
                                            <div class="small text-muted" v-if="it.received_at">received {{ fmt(it.received_qty, 0) }}</div>
                                        </template>
                                        <span v-else class="small text-muted">Not linked</span>
                                    </td>
                                </tr>
                                <tr v-if="!g.rows.length"><td colspan="8" class="small text-muted ps-4">No items yet — open an item and choose this shipment.</td></tr>
                            </template>
                        </tbody>
                        <tbody v-if="!list.length"><tr><td colspan="8" class="text-center text-muted py-4">No items match these filters.</td></tr></tbody>
                        <tfoot v-if="list.length"><tr class="fw-semibold">
                            <td class="c-item">Totals · {{ list.length }} lines</td>
                            <td data-label="Packing" class="mono">{{ fmt(sum('total_items'), 0) }} pcs · {{ fmt(sum('cbm'), 3) }} CBM</td>
                            <td class="text-end mono" data-label="Goods">{{ money(sum('item_cost')) }}</td>
                            <td class="text-end mono" data-label="Freight">{{ money(sum('freight')) }}</td>
                            <td class="text-end mono" data-label="Delivery">{{ money(sum('delivery')) }}</td>
                            <td class="text-end mono" data-label="Total">{{ money(sum('total')) }}</td>
                            <td class="text-end mono" data-label="Avg / piece">{{ money(avgPiece, 3) }}</td><td></td>
                        </tr></tfoot>
                    </table>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-3" v-if="unsynced.length">
                    <button class="btn btn-sm btn-outline-primary" @click="applyAll">Update Lebazone cost price on {{ unsynced.length }} linked item(s)</button>
                </div>
            </div>

            <!-- side column: suppliers + freight rates -->
            <div class="col-xxl-3 col-xl-4">
                <div class="card mb-3"><div class="card-body p-3">
                    <div class="d-flex align-items-center mb-2"><h6 class="mb-0 fw-bold">Suppliers <span class="text-muted fw-normal">{{ suppliers.length }}</span></h6>
                        <button class="btn btn-sm btn-outline-primary ms-auto" @click="openForm('supplier', null)">+ Add</button></div>
                    <div v-if="!suppliers.length" class="small text-muted">No suppliers yet. The origin country picks the freight rate automatically.</div>
                    <div v-for="s in suppliersSorted" :key="s.id" class="pur-side-row" :class="{ on: f.sup === String(s.id) }">
                        <div class="d-flex align-items-start gap-2">
                            <button class="btn btn-link p-0 text-start text-decoration-none text-body min-w-0 flex-grow-1" @click="f.sup = f.sup === String(s.id) ? '' : String(s.id)" title="Show only this supplier's items">
                                <div class="fw-semibold text-truncate">{{ s.name }}</div>
                                <div class="small text-muted">{{ [s.country || 'origin not set', s.platform].filter(Boolean).join(' · ') }}<span v-if="s.rating" class="text-warning"> {{ '★'.repeat(s.rating) }}</span></div>
                                <div class="small mono">{{ supItems(s).length }} items · {{ money(sum('item_cost', supItems(s))) }}</div>
                            </button>
                            <button class="btn btn-sm btn-light" @click="openSup = openSup === s.id ? null : s.id" title="Contact details">{{ openSup === s.id ? '▴' : '▾' }}</button>
                            <button class="btn btn-sm btn-light" @click="openForm('supplier', s)">Edit</button>
                        </div>
                        <dl v-if="openSup === s.id" class="row small mb-0 mt-2">
                            <template v-for="row in supRows(s)" :key="row[0]"><dt class="col-4 text-muted fw-normal">{{ row[0] }}</dt><dd class="col-8 mb-1 text-break">
                                <a v-if="row[2]" :href="row[2]" target="_blank" rel="noopener">{{ row[1] }}</a><span v-else>{{ row[1] }}</span></dd></template>
                            <dd v-if="s.notes" class="col-12 text-muted mb-0" style="white-space:pre-wrap">{{ s.notes }}</dd>
                        </dl>
                    </div>
                </div></div>

                <div class="card"><div class="card-body p-3">
                    <div class="d-flex align-items-center mb-2"><h6 class="mb-0 fw-bold">Freight rates <span class="text-muted fw-normal">per CBM</span></h6>
                        <button class="btn btn-sm btn-outline-primary ms-auto" @click="openForm('rate', null)">+ Add</button></div>
                    <div class="pur-side-row d-flex align-items-center"><div class="me-auto"><div class="fw-semibold">Default</div><div class="small text-muted">when nothing else applies</div></div>
                        <div class="mono fw-bold">{{ fmt(settings.cbm_price, 2) }}</div></div>
                    <div v-for="r in rates" :key="r.id" class="pur-side-row">
                        <div class="d-flex align-items-center gap-2">
                            <div class="me-auto min-w-0"><div class="fw-semibold text-truncate">{{ r.label }}</div>
                                <div class="small text-muted">{{ [r.country, r.mode].filter(Boolean).join(' · ') || 'No origin set' }}<b v-if="r.auto && r.country" class="text-success"> · auto</b>
                                    · {{ items.filter(i => i.calc.rate_src.startsWith(r.label)).length }} items</div>
                                <div v-for="h in (r.history || []).slice(-2).reverse()" :key="h.until + h.price" class="small text-muted">was {{ fmt(h.price, 2) }} until {{ h.until }}</div></div>
                            <div class="mono fw-bold">{{ fmt(r.price, 2) }}</div>
                            <button class="btn btn-sm btn-light" @click="openForm('rate', r)">Edit</button>
                        </div>
                    </div>
                    <div class="small text-muted mt-2">Order used: item's own rate → shipment's rate → automatic rate for the supplier's country → default.</div>
                </div></div>
            </div>
        </div>

        <!-- Reports / Settings open over the page -->
        <div v-if="panel" class="pur-scrim" @click.self="panel = null">
            <div class="pur-drawer" :class="{ wide: panel === 'reports' }">
                <div class="pur-drawer-h"><h5 class="mb-0">{{ panel === 'reports' ? 'Reports' : 'Settings' }}</h5><button class="btn-close ms-auto" @click="panel = null"></button></div>
                <div class="pur-drawer-b">
                    <PurchasingReports v-if="panel === 'reports'" :suppliers="suppliersSorted" :shipments="shipments" :origins="origins" :currency="settings.currency" />
                    <div v-else class="row g-3">
            <div class="col-12"><div class="card"><div class="card-body">
                <h5>Defaults</h5>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label small">Default CBM price</label><input type="number" step="any" min="0" class="form-control" v-model="sForm.cbm_price"></div>
                    <div class="col-6"><label class="form-label small">Currency label</label><input class="form-control" maxlength="8" v-model="sForm.currency"></div>
                    <div class="col-6"><label class="form-label small">Carton padding (cm added to each side)</label><input type="number" step="any" min="0" class="form-control" v-model="sForm.carton_pad">
                        <div class="form-text">Used when estimating a carton from the item size.</div></div>
                    <div class="col-6"><label class="form-label small">Target margin for suggested selling price (%)</label><input type="number" step="any" min="0" max="95" class="form-control" v-model="sForm.default_margin">
                        <div class="form-text">Suggested price = cost per piece ÷ (1 − margin).</div></div>
                    <div class="col-12"><label class="form-label small">Lebazone store that receives the stock</label>
                        <select class="form-select" v-model="sForm.default_store_id"><option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option></select></div>
                </div>
                <div class="mt-3 small"><b>Your real boxes vs items inside:</b>
                    <span v-if="learned"> on average +{{ Math.round(learned.avg * 100) }}% bigger (from {{ learned.n }} item{{ learned.n > 1 ? 's' : '' }})</span>
                    <span v-else class="text-muted"> shows once items have both an item size and a real box size.</span></div>
                <button class="btn btn-primary mt-3" @click="saveSettings">Save settings</button>
            </div></div></div>
            <div class="col-12"><div class="card"><div class="card-body">
                <h5>AI — product photos &amp; descriptions</h5>
                <p class="small text-muted mb-2">Used in each item's <b>Store listing</b>. The AI starts from the item's real picture. You pay the AI company per use (a few cents per photo); nothing runs unless you press the button.</p>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label small">Photos made by</label>
                        <select class="form-select" v-model="sForm.ai_provider"><option value="">Off</option><option value="gemini">Google Gemini</option><option value="openai">OpenAI</option></select></div>
                    <div class="col-6"><label class="form-label small">Descriptions written by</label>
                        <select class="form-select" v-model="sForm.ai_text_provider"><option value="">Same as photos</option><option value="gemini">Google Gemini</option><option value="openai">OpenAI</option><option value="claude">Claude (Anthropic API)</option></select></div>
                    <div class="col-12"><label class="form-label small">{{ sForm.ai_provider === 'openai' ? 'OpenAI' : 'Gemini' }} API key
                        <span v-if="settings.ai_has_key" class="text-success">· saved ✓</span></label>
                        <div class="input-group"><input type="password" class="form-control" v-model="sForm.ai_key" autocomplete="new-password" :placeholder="settings.ai_has_key ? 'Leave empty to keep the saved key' : 'Paste the key'">
                            <button v-if="settings.ai_has_key" class="btn btn-outline-danger" type="button" @click="clearKey('clear_ai_key')">Remove</button></div>
                        <div class="form-text">Gemini: aistudio.google.com → Get API key. OpenAI: platform.openai.com → API keys.</div></div>
                    <div class="col-12" v-if="sForm.ai_text_provider === 'claude'"><label class="form-label small">Claude API key <span v-if="settings.claude_has_key" class="text-success">· saved ✓</span></label>
                        <div class="input-group"><input type="password" class="form-control" v-model="sForm.claude_key" autocomplete="new-password" :placeholder="settings.claude_has_key ? 'Leave empty to keep the saved key' : 'Paste the key'">
                            <button v-if="settings.claude_has_key" class="btn btn-outline-danger" type="button" @click="clearKey('clear_claude_key')">Remove</button></div>
                        <div class="form-text">From console.anthropic.com — the API is billed separately from a Claude subscription. Claude writes descriptions only (it can't make photos).</div></div>
                </div>
                <div class="small mt-2">Status: photos <b :class="settings.ai_photos ? 'text-success' : 'text-muted'">{{ settings.ai_photos ? 'ready' : 'off' }}</b> · descriptions <b :class="settings.ai_text ? 'text-success' : 'text-muted'">{{ settings.ai_text ? 'ready' : 'off' }}</b></div>
                <button class="btn btn-primary mt-3" @click="saveAi">Save AI settings</button>
            </div></div></div>
            <div class="col-12"><div class="card"><div class="card-body small">
                <h5>How the numbers are worked out</h5>
                <table class="table table-sm mb-0"><tbody>
                    <tr><td class="text-muted">Total pieces</td><td>order quantity, or pieces per box × boxes</td></tr>
                    <tr><td class="text-muted">Total CBM</td><td>box L × W × H (cm) ÷ 1,000,000 × boxes — or the estimated carton from the item size</td></tr>
                    <tr><td class="text-muted">Goods</td><td>cost per piece × total pieces</td></tr>
                    <tr><td class="text-muted">Freight</td><td>total CBM × CBM price</td></tr>
                    <tr><td class="text-muted">Delivery</td><td>one amount per shipment, split across its items</td></tr>
                    <tr class="fw-semibold"><td>Total cost</td><td>goods + freight + delivery</td></tr>
                    <tr class="fw-bold"><td>Cost per piece</td><td>total cost ÷ total pieces → Lebazone cost price</td></tr>
                </tbody></table>
            </div></div></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- item form -->
        <PurchasingItemForm v-if="itemOpen" :item="itemOpen" :data="dataForForm" @close="itemOpen = null" @saved="afterItemSave" />

        <!-- supplier / shipment / rate form -->
        <div v-if="form" class="pur-scrim" @click.self="form = null">
            <div class="pur-drawer">
                <div class="pur-drawer-h"><h5 class="mb-0">{{ formTitle }}</h5><button class="btn-close ms-auto" @click="form = null"></button></div>
                <div class="pur-drawer-b">
                    <div class="row g-2">
                        <template v-for="fd in formFields" :key="fd.k">
                            <div v-if="!fd.show || fd.show(form.data)" :class="fd.col || 'col-12'">
                                <label class="form-label small mb-1">{{ fd.label }}</label>
                                <select v-if="fd.options" class="form-select" v-model="form.data[fd.k]"><option v-for="o in fd.options" :key="o[0]" :value="o[0]">{{ o[1] }}</option></select>
                                <textarea v-else-if="fd.type === 'textarea'" class="form-control" rows="3" v-model="form.data[fd.k]"></textarea>
                                <div v-else-if="fd.type === 'check'" class="form-check"><input class="form-check-input" type="checkbox" :id="'f' + fd.k" v-model="form.data[fd.k]"><label class="form-check-label" :for="'f' + fd.k">{{ fd.text }}</label></div>
                                <input v-else class="form-control" :type="fd.type || 'text'" :list="fd.list" :step="fd.type === 'number' ? 'any' : null" v-model="form.data[fd.k]" :placeholder="fd.ph || ''">
                                <div v-if="fd.hint" class="form-text">{{ fd.hint }}</div>
                            </div>
                        </template>
                    </div>
                </div>
                <div class="pur-drawer-f">
                    <button v-if="form.data.id" class="btn" :class="armed ? 'btn-danger' : 'btn-outline-danger'" @click="removeForm">{{ armed ? 'Click again to delete' : 'Delete' }}</button>
                    <button class="btn btn-outline-secondary ms-auto" @click="form = null">Cancel</button>
                    <button class="btn btn-primary" @click="saveForm" :disabled="busy.form">Save</button>
                </div>
            </div>
        </div>
        <datalist id="pur-countries"><option v-for="c in COUNTRIES" :key="c" :value="c"></option></datalist>

        <div v-if="zoomSrc" class="pur-scrim d-grid" style="place-items:center" @click="zoomSrc = null"><img :src="zoomSrc" class="pur-zoom" alt=""></div>
    </div>
</template>

<script>
import axios from 'axios';
import PurchasingItemForm from './PurchasingItemForm.vue';
import PurchasingReports from './PurchasingReports.vue';
import { ITEM_STATUS, SHIP_STATUS, MODES, PLATFORMS, COUNTRIES, num, fmt, statusClass, post, download } from './purchasing.js';

export default {
    components: { PurchasingItemForm, PurchasingReports },
    data() {
        let grouped = true;
        try { grouped = localStorage.getItem('pur-grouped') !== '0'; } catch (e) {}
        return { ITEM_STATUS, COUNTRIES, grouped, closed: {}, openSup: null, panel: null, loaded: false, settings: {}, items: [], suppliers: [], shipments: [], rates: [], stores: [], learned: null, allocation: {},
            f: { q: '', sup: '', ship: '', status: '', origin: '', link: '', sort: 'new' }, rateInput: '', sForm: {},
            itemOpen: null, form: null, armed: false, dropId: null, uploading: null, zoomSrc: null, busy: { export: false, form: false } };
    },
    computed: {
        filtered() { const f = this.f; return !!(f.q.trim() || f.sup || f.ship || f.status || f.origin || f.link); },
        /** Items grouped under their shipment (empty shipments shown when no filter is on), then the ones with no shipment. */
        groups() {
            if (!this.grouped) return [{ key: 'all', sh: null, rows: this.list }];
            const out = [];
            for (const sh of this.shipmentsSorted) {
                const rows = this.list.filter(i => i.shipment_id === sh.id);
                if (rows.length || !this.filtered) out.push({ key: 's' + sh.id, sh, rows });
            }
            const ids = new Set(this.shipments.map(s => s.id));
            const none = this.list.filter(i => !i.shipment_id || !ids.has(i.shipment_id));
            if (none.length) out.push({ key: 'none', sh: null, rows: none });
            return out;
        },
        suppliersSorted() { return [...this.suppliers].sort((a, b) => (a.name || '').localeCompare(b.name || '')); },
        shipmentsSorted() { return [...this.shipments].sort((a, b) => (b.ship_date || '').localeCompare(a.ship_date || '') || b.id - a.id); },
        origins() { return [...new Set(this.suppliers.map(s => (s.country || '').trim()).filter(Boolean))].sort(); },
        list() {
            const f = this.f, q = f.q.trim().toLowerCase();
            const rows = this.items.filter(it => {
                if (f.sup && String(it.supplier_id || '') !== (f.sup === '-' ? '' : f.sup)) return false;
                if (f.ship && String(it.shipment_id || '') !== (f.ship === '-' ? '' : f.ship)) return false;
                if (f.status && it.status !== f.status) return false;
                if (f.origin && it.calc.origin !== (f.origin === '-' ? '' : f.origin)) return false;
                if (f.link === 'yes' && !it.link) return false;
                if (f.link === 'no' && it.link) return false;
                if (q && ![it.name, it.variants, it.sku, this.supName(it), it.calc.origin, it.notes, it.link && it.link.sku].join(' ').toLowerCase().includes(q)) return false;
                return true;
            });
            const by = { new: (a, b) => (b.created_at || '').localeCompare(a.created_at || ''), name: (a, b) => a.name.localeCompare(b.name),
                total: (a, b) => (b.calc.total || 0) - (a.calc.total || 0), per: (a, b) => (b.calc.per_item || 0) - (a.calc.per_item || 0) };
            return rows.sort(by[f.sort] || by.new);
        },
        avgPiece() { const t = this.sum('total'); const q = this.list.reduce((a, i) => a + (i.calc.total != null ? i.calc.qty : 0), 0); return t != null && q ? t / q : null; },
        unsynced() { return this.items.filter(i => i.link && i.calc.per_item != null && !this.costSynced(i)); },
        dataForForm() { return { settings: this.settings, suppliers: this.suppliersSorted, shipments: this.shipments, rates: this.rates, stores: this.stores, learned: this.learned, allocation: this.allocation }; },
        formTitle() { const t = { supplier: 'supplier', shipment: 'shipment', rate: 'freight rate' }[this.form.kind]; return (this.form.data.id ? 'Edit ' : 'New ') + t; },
        formFields() {
            const k = this.form.kind;
            if (k === 'supplier') return [
                { k: 'name', label: 'Supplier name', col: 'col-8' }, { k: 'rating', label: 'Rating', col: 'col-4', options: [['', 'Not rated'], ...[5, 4, 3, 2, 1].map(n => [n, '★'.repeat(n)])] },
                { k: 'company', label: 'Company / legal name', col: 'col-6' }, { k: 'platform', label: 'Where you buy', col: 'col-6', options: [['', 'Choose…'], ...PLATFORMS.map(p => [p, p])] },
                { k: 'country', label: 'Origin country', col: 'col-6', list: 'pur-countries', hint: 'Where the goods ship from — picks the freight rate.' }, { k: 'city', label: 'City', col: 'col-6' },
                { k: 'contact', label: 'Contact person', col: 'col-6' }, { k: 'phone', label: 'Phone', col: 'col-6', type: 'tel' },
                { k: 'whatsapp', label: 'WhatsApp', col: 'col-4', type: 'tel' }, { k: 'wechat', label: 'WeChat ID', col: 'col-4' }, { k: 'email', label: 'Email', col: 'col-4', type: 'email' },
                { k: 'website', label: 'Store or website link', ph: 'https://…' }, { k: 'address', label: 'Address' },
                { k: 'payment_terms', label: 'Payment terms', col: 'col-6', ph: 'e.g. 30% deposit, 70% before shipping' }, { k: 'lead_time', label: 'Lead time (days)', col: 'col-3', type: 'number' },
                { k: 'moq', label: 'MOQ', col: 'col-3' }, { k: 'bank', label: 'Bank / payment details' }, { k: 'notes', label: 'Notes', type: 'textarea' }];
            if (k === 'shipment') return [
                { k: 'name', label: 'Shipment name', col: 'col-8', ph: 'e.g. Container 1' }, { k: 'status', label: 'Status', col: 'col-4', options: SHIP_STATUS.map(s => [s, s]) },
                { k: 'ship_date', label: 'Ship date', col: 'col-6', type: 'date' }, { k: 'eta', label: 'Expected arrival', col: 'col-6', type: 'date' },
                { k: 'forwarder', label: 'Forwarder / carrier', col: 'col-6' }, { k: 'tracking', label: 'Tracking / container no.', col: 'col-6' },
                { k: 'delivery_cost', label: 'Delivery cost (whole shipment)', col: 'col-6', type: 'number', hint: 'Entered once, split across the items.' },
                { k: 'allocation', label: 'Split the delivery cost', col: 'col-6', options: Object.entries(this.allocation) },
                { k: 'rate_sel', label: 'Freight rate for this shipment', col: 'col-6', options: [['', 'Per item (automatic)'], ...this.rates.map(r => ['r' + r.id, r.label + ' — ' + fmt(r.price) + '/CBM']), ['custom', 'Custom price…']] },
                { k: 'cbm_price', label: 'Custom CBM price', col: 'col-6', type: 'number', show: d => d.rate_sel === 'custom' },
                { k: 'notes', label: 'Notes', type: 'textarea' }];
            return [
                { k: 'country', label: 'Origin country', col: 'col-6', list: 'pur-countries', ph: 'e.g. China' }, { k: 'mode', label: 'Shipping method', col: 'col-6', options: MODES.map(m => [m, m]) },
                { k: 'name', label: 'Name (optional)', ph: 'e.g. China · Sea (LCL) · forwarder X', hint: 'Left blank, it is named from the country and method.' },
                { k: 'price', label: 'Price per CBM', col: 'col-6', type: 'number' },
                { k: 'auto', label: 'Use automatically', col: 'col-6', type: 'check', text: 'For suppliers from this country' },
                { k: 'notes', label: 'Notes', type: 'textarea', ph: 'Forwarder, valid until, minimum charge…' }];
        },
    },
    created() { this.load(); },
    methods: {
        fmt, statusClass,
        money(v, d = 2) { return v == null ? '—' : (this.settings.currency ? this.settings.currency + ' ' : '') + fmt(v, d); },
        dims(a, b, c) { return [a, b, c].every(x => num(x) != null) ? `${fmt(num(a), 0)} × ${fmt(num(b), 0)} × ${fmt(num(c), 0)} cm` : ''; },
        short(s) { return s && s.length > 24 ? s.slice(0, 23) + '…' : s; },
        splitV(v) { return String(v || '').split(',').map(s => s.trim()).filter(Boolean); },
        saveGrouped() { try { localStorage.setItem('pur-grouped', this.grouped ? '1' : '0'); } catch (e) {} },
        toggle(k) { this.closed = { ...this.closed, [k]: !this.closed[k] }; },
        clearFilters() { this.f = { ...this.f, q: '', sup: '', ship: '', status: '', origin: '', link: '' }; },
        shipLine(sh) { return [sh.ship_date && 'Ships ' + sh.ship_date, sh.eta && 'ETA ' + sh.eta, sh.forwarder, sh.tracking && 'Tracking ' + sh.tracking].filter(Boolean).join(' · ') || 'No dates yet'; },
        supName(it) { const s = this.suppliers.find(x => x.id === it.supplier_id); return s ? s.name : ''; },
        shipName(id) { const s = this.shipments.find(x => x.id === id); return s ? s.name : ''; },
        sum(k, rows) { let s = 0, any = false; (rows || this.list).forEach(i => { const v = i.calc[k]; if (v != null) { s += Number(v); any = true; } }); return any ? s : null; },
        shipItems(sh) { return this.items.filter(i => i.shipment_id === sh.id); },
        supItems(s) { return this.items.filter(i => i.supplier_id === s.id); },
        packText(it) {
            const c = it.calc; const bx = c.per_used != null || c.cbm != null ? c.boxes_used : null;
            return [bx != null ? (c.boxes_derived ? '≈ ' : '') + fmt(bx, bx % 1 ? 2 : 0) + ' box' + (bx === 1 ? '' : 'es') : '', c.per_used != null ? fmt(c.per_used, 0) + '/box' + (c.auto_per ? ' (est.)' : '') : ''].filter(Boolean).join(' × ');
        },
        costSynced(it) { return it.link && it.calc.per_item != null && Math.abs((it.link.purchase_price || 0) - Math.round(it.calc.per_item * 100) / 100) < 0.005; },
        supRows(s) {
            const wa = s.whatsapp ? String(s.whatsapp).replace(/[^\d]/g, '') : '';
            const web = s.website ? (/^https?:\/\//i.test(s.website) ? s.website : null) : null;
            return [['Contact', s.contact], ['Phone', s.phone], ['WhatsApp', s.whatsapp, wa ? 'https://wa.me/' + wa : null], ['WeChat', s.wechat], ['Email', s.email],
                ['Store', s.website, web], ['Address', s.address], ['Payment', s.payment_terms], ['Lead time', s.lead_time ? fmt(s.lead_time, 0) + ' days' : ''], ['MOQ', s.moq]].filter(r => r[1]);
        },
        load() {
            return axios.get(this.$apiUrl + '/purchasing/data').then(r => {
                if (r.data.status !== 1) { this.showError(r.data.message); return; }
                const d = r.data.data;
                Object.assign(this, { settings: d.settings, items: d.items, suppliers: d.suppliers, shipments: d.shipments, rates: d.rates, stores: d.stores, learned: d.learned, allocation: d.allocation });
                this.rateInput = d.settings.cbm_price;
                this.sForm = { ...d.settings };
                this.loaded = true;
                if (this.itemOpen && this.itemOpen.id) { const fresh = this.items.find(i => i.id === this.itemOpen.id); if (fresh) this.itemOpen = fresh; }
            }).catch(e => this.showError(e.response?.data?.message || e.message));
        },
        afterItemSave(keepOpenId) {
            this.load().then(() => { if (keepOpenId) { const it = this.items.find(i => i.id === keepOpenId); if (it) this.itemOpen = it; } else this.itemOpen = null; });
        },
        openItem(it) { this.itemOpen = it || { id: null, status: 'Quote' }; },
        zoom(src) { this.zoomSrc = src; },
        dropPicture(it, e) {
            this.dropId = null;
            const file = e.dataTransfer.files[0];
            if (!file || !/^image\//.test(file.type)) { this.showError('That file is not a picture'); return; }
            const fd = new FormData(); fd.append('id', it.id); fd.append('picture', file);
            this.uploading = it.id;
            post(this, '/purchasing/items/picture', fd).then(ok => { this.uploading = null; if (ok) this.load(); });
        },
        saveDefaultRate() { post(this, '/purchasing/settings/save', { cbm_price: this.rateInput }).then(ok => ok && this.load()); },
        saveSettings() { const { ai_provider, ai_text_provider, ai_key, claude_key, ...rest } = this.sForm; post(this, '/purchasing/settings/save', rest).then(ok => ok && this.load()); },
        saveAi() {
            const f = this.sForm, body = { ai_provider: f.ai_provider || '', ai_text_provider: f.ai_text_provider || '' };
            if (f.ai_key) body.ai_key = f.ai_key;
            if (f.claude_key) body.claude_key = f.claude_key;
            post(this, '/purchasing/settings/save', body).then(ok => ok && this.load());
        },
        clearKey(which) { post(this, '/purchasing/settings/save', { [which]: 1 }).then(ok => ok && this.load()); },
        exportAll() { this.busy.export = true; download(this, '/purchasing/export', {}, 'Lebazone_Purchasing.xlsx').finally(() => { this.busy.export = false; }); },
        applyAll() { post(this, '/purchasing/apply_cost', { item_ids: this.unsynced.map(i => i.id) }).then(ok => ok && this.load()); },
        receiveShipment(sh) {
            const linked = this.shipItems(sh).filter(i => i.link).length, all = this.shipItems(sh).length;
            if (!linked) { this.showError('None of the items in this shipment is linked to a Lebazone product yet. Open each item and link or create its product first.'); return; }
            post(this, '/purchasing/receive', { shipment_id: sh.id }).then(ok => { if (ok) this.load(); if (ok && linked < all) this.showError(`${all - linked} item(s) were skipped because they are not linked to a product.`); });
        },
        openForm(kind, row) {
            this.armed = false;
            let data = row ? { ...row } : {};
            if (kind === 'shipment') {
                data = row ? { ...row, rate_sel: row.rate_mode === 'rate' ? 'r' + row.rate_id : row.rate_mode === 'custom' ? 'custom' : '' } : { status: 'Planning', allocation: 'cbm', ship_date: new Date().toISOString().slice(0, 10), rate_sel: '', delivery_cost: '' };
            }
            if (kind === 'rate') data = row ? { ...row, auto: !!Number(row.auto) } : { mode: 'Sea (LCL)', auto: true };
            this.form = { kind, data };
        },
        saveForm() {
            const k = this.form.kind; const d = { ...this.form.data };
            if (k === 'shipment') { d.rate_mode = d.rate_sel === 'custom' ? 'custom' : d.rate_sel ? 'rate' : ''; d.rate_id = d.rate_sel && d.rate_sel !== 'custom' ? d.rate_sel.slice(1) : ''; }
            if (k === 'rate') d.auto = d.auto ? 1 : 0;
            delete d.history; delete d.label;
            this.busy.form = true;
            post(this, `/purchasing/${k === 'rate' ? 'rates' : k + 's'}/save`, d).then(ok => { this.busy.form = false; if (ok) { this.form = null; this.load(); } });
        },
        removeForm() {
            if (!this.armed) { this.armed = true; setTimeout(() => { this.armed = false; }, 3500); return; }
            const k = this.form.kind;
            post(this, `/purchasing/${k === 'rate' ? 'rates' : k + 's'}/delete`, { id: this.form.data.id }).then(ok => { if (ok) { this.form = null; this.load(); } });
        },
    },
};
</script>

<style scoped>
.pur .mono { font-variant-numeric: tabular-nums; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.pur .min-w-0 { min-width: 0; }
.pur-rate { width: 210px; }
.pur-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1px; background: var(--bs-border-color); border: 1px solid var(--bs-border-color); border-radius: 10px; overflow: hidden; }
.pur-stat { background: var(--bs-body-bg); padding: 10px 14px; min-width: 0; }
.pur-stat .k { font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--bs-secondary-color); }
.pur-stat .v { font-size: 19px; font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pur-stat .v small { font-size: 12px; color: var(--bs-secondary-color); font-weight: 400; }
.pur-stat.hl .v { color: var(--bs-emphasis-color); }
.pur-table th { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--bs-secondary-color); white-space: nowrap; }
.pur-row { cursor: pointer; }
.pur-table td.c-item { width: 34%; }
.pur-pic { flex: none; width: 96px; height: 96px; border-radius: 10px; border: 1px dashed var(--bs-border-color); background: var(--bs-tertiary-bg); display: grid; place-items: center; overflow: hidden; position: relative;
    color: var(--bs-secondary-color); font-size: 11px; text-align: center; line-height: 1.2; }
.pur-pic.has { border: 0; background: none; cursor: zoom-in; }
.pur-pic.over { border-color: var(--bs-primary); background: var(--bs-primary-bg-subtle); }
.pur-pic img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pur-pic .busy { position: absolute; inset: 0; display: grid; place-items: center; background: rgba(0, 0, 0, .45); color: #fff; font-weight: 700; }
.pur-scrim { position: fixed; inset: 0; background: rgba(0, 0, 0, .4); z-index: 1050; }
.pur-drawer { position: absolute; top: 0; right: 0; bottom: 0; width: min(620px, 100%); background: var(--bs-body-bg); display: flex; flex-direction: column; box-shadow: 0 10px 30px rgba(0, 0, 0, .2); }
.pur-drawer-h, .pur-drawer-f { display: flex; gap: 8px; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--bs-border-color); }
.pur-drawer-f { border-bottom: 0; border-top: 1px solid var(--bs-border-color); }
.pur-drawer-b { flex: 1; overflow-y: auto; padding: 16px; }
.pur-drawer.wide { width: min(1100px, 100%); }
.pur-group td { background: var(--bs-tertiary-bg) !important; border-top: 2px solid var(--bs-border-color); }
.pur-side-row { padding: 8px 0; border-top: 1px solid var(--bs-border-color); }
.pur-side-row.on { background: var(--bs-primary-bg-subtle); border-radius: 8px; padding-left: 6px; padding-right: 6px; }
.pur-zoom { max-width: min(900px, 92vw); max-height: 86vh; border-radius: 10px; background: #fff; }
@media (max-width: 991px) {
    .pur-table thead { display: none; }
    .pur-table, .pur-table tbody, .pur-table tfoot { display: block; width: 100%; }
    .pur-table tr { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 8px 14px; padding: 12px; border-bottom: 1px solid var(--bs-border-color); }
    .pur-table td { display: block; border: 0; padding: 0; text-align: left !important; width: auto !important; }
    .pur-table td.c-item, .pur-table tr.pur-group td { grid-column: 1 / -1; }
    .pur-table td[data-label]:not(.c-item)::before { content: attr(data-label); display: block; font-size: 10.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--bs-secondary-color); }
    .pur-table td:empty { display: none; }
}
</style>

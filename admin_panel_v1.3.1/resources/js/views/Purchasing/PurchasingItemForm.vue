<template>
    <!-- Custom (Lebazone): Purchasing item form with live landed-cost breakdown and the links into the catalogue. -->
    <div class="pur-scrim" @click.self="$emit('close')">
        <div class="pur-drawer" @paste="onPaste">
            <div class="pur-drawer-h">
                <h5 class="mb-0">{{ f.id ? 'Edit item' : 'Add item' }}</h5>
                <button v-if="f.id" class="btn btn-sm btn-outline-secondary ms-auto" @click="duplicate" title="Same item, another colour or size">Duplicate</button>
                <button class="btn-close" :class="{ 'ms-auto': !f.id }" @click="$emit('close')"></button>
            </div>
            <div class="pur-drawer-b">
                <!-- picture -->
                <div class="pur-dz" :class="{ over: dzOver }" @click="$refs.file.click()" @dragover.prevent="dzOver = true" @dragleave="dzOver = false" @drop.prevent="onDrop">
                    <div class="pur-pic big" :class="{ has: picSrc }"><img v-if="picSrc" :src="picSrc" alt=""><span v-else>No photo</span></div>
                    <div class="small text-muted"><b class="d-block text-body">Picture</b>Drag a photo here, paste it, or click to choose. It fills the frame automatically.
                        <div v-if="picSrc"><button type="button" class="btn btn-link btn-sm text-danger px-0" @click.stop="removePicture">Remove picture</button></div></div>
                    <input ref="file" type="file" accept="image/*" class="d-none" @change="e => setFile(e.target.files[0])">
                </div>

                <h6 class="pur-legend">Item</h6>
                <div class="row g-2">
                    <div class="col-8"><label class="form-label small mb-1">Item name</label><input class="form-control" v-model="f.name" placeholder="e.g. Ramadan Lantern"></div>
                    <div class="col-4"><label class="form-label small mb-1">SKU / code</label><input class="form-control" v-model="f.sku"></div>
                    <div class="col-12"><label class="form-label small mb-1">Variants (colour, size…)</label><input class="form-control" v-model="f.variants" placeholder="Black, Gold, Red"><div class="form-text">Separate with commas.</div></div>
                    <div class="col-6"><label class="form-label small mb-1">Supplier</label>
                        <select class="form-select" v-model="f.supplier_id"><option value="">No supplier</option><option v-for="s in data.suppliers" :key="s.id" :value="String(s.id)">{{ s.name }}{{ s.country ? ' · ' + s.country : '' }}</option><option value="__new">+ New supplier…</option></select></div>
                    <div class="col-6"><label class="form-label small mb-1">Status</label><select class="form-select" v-model="f.status"><option v-for="s in ITEM_STATUS" :key="s">{{ s }}</option></select></div>
                    <div class="col-12" v-if="f.supplier_id === '__new'"><label class="form-label small mb-1">New supplier name</label><input class="form-control" v-model="f.new_supplier"><div class="form-text">Add contact details later in Suppliers.</div></div>
                </div>

                <h6 class="pur-legend">Item size <span class="fw-normal text-muted">(one piece in its packaging — used to estimate the carton)</span></h6>
                <div class="row g-2">
                    <div class="col-4"><label class="form-label small mb-1">Length (cm)</label><input type="number" step="any" min="0" class="form-control" v-model="f.item_l"></div>
                    <div class="col-4"><label class="form-label small mb-1">Width (cm)</label><input type="number" step="any" min="0" class="form-control" v-model="f.item_w"></div>
                    <div class="col-4"><label class="form-label small mb-1">Height (cm)</label><input type="number" step="any" min="0" class="form-control" v-model="f.item_h"></div>
                </div>

                <h6 class="pur-legend">Quantity, boxes &amp; packing</h6>
                <div class="row g-2">
                    <div class="col-12"><label class="form-label small mb-1">Total pieces (order quantity)</label><input type="number" step="any" min="0" class="form-control" v-model="f.qty" placeholder="e.g. 500">
                        <div class="form-text">Fill this, or boxes × pieces per box. With quantity + item size the number of cartons is worked out.</div></div>
                    <div class="col-6"><label class="form-label small mb-1">Number of boxes</label><input type="number" step="any" min="0" class="form-control" v-model="f.boxes" :disabled="c && c.boxes_derived" :placeholder="c && c.boxes_derived ? '≈ ' + fmt(c.boxes_used, 2) + ' (from quantity)' : '1'"></div>
                    <div class="col-6"><label class="form-label small mb-1">Pieces per box</label><input type="number" step="any" min="0" class="form-control" v-model="f.per_box" :placeholder="c && c.auto_per ? '≈ ' + fmt(c.per_used, 0) + ' (estimated)' : ''"></div>
                    <div class="col-4"><label class="form-label small mb-1">Box length (cm)</label><input type="number" step="any" min="0" class="form-control" v-model="f.box_l"></div>
                    <div class="col-4"><label class="form-label small mb-1">Box width (cm)</label><input type="number" step="any" min="0" class="form-control" v-model="f.box_w"></div>
                    <div class="col-4"><label class="form-label small mb-1">Box height (cm)</label><input type="number" step="any" min="0" class="form-control" v-model="f.box_h"></div>
                    <div class="col-12"><label class="form-label small mb-1">Freight CBM from</label>
                        <select class="form-select" v-model="f.cbm_basis"><option value="">Automatic — real box if entered, otherwise item-size estimate</option><option value="box">Real box size</option><option value="item">Item size + packing estimate</option></select></div>
                </div>
                <div class="pur-calc mt-2" v-if="c">
                    <template v-if="c.piece_vol != null"><div class="ln"><span>One item</span><span class="mono">{{ cm(f.item_l, f.item_w, f.item_h) }} · {{ fmt(c.piece_vol, 5) }} CBM</span></div></template>
                    <div class="ln" v-if="c.auto_per"><span>Pieces per carton (estimated, max 80 cm a side)</span><span class="mono">{{ fmt(c.per_used, 0) }}</span></div>
                    <div class="ln" v-if="c.boxes_derived"><span>Cartons for this quantity</span><span class="mono">{{ fmt(c.qty_entered, 0) }} ÷ {{ fmt(c.per_used, 0) }} = {{ fmt(c.boxes_used, 2) }}</span></div>
                    <div class="ln" v-if="c.items_vol != null"><span>{{ fmt(c.per_used, 0) }} items, no packing</span><span class="mono">{{ fmt(c.items_vol, 4) }} CBM</span></div>
                    <template v-if="c.est">
                        <div class="ln" :class="{ tot: !c.has_box }"><span>Estimated carton (layout {{ c.est.layout }}, +{{ fmt(pad, 1) }} cm per side)</span><span class="mono">{{ cm(c.est.L, c.est.W, c.est.H) }} · {{ fmt(c.est.cbm, 4) }} CBM</span></div>
                        <div class="ln" v-if="c.items_vol"><span>Size increase from carton &amp; padding</span><span class="mono">+{{ Math.round((c.est.cbm / c.items_vol - 1) * 100) }}%</span></div>
                        <div class="ln text-warning-emphasis" v-if="c.est.too_big"><span>Note</span><span>a carton side is over 80 cm — maybe split into smaller boxes</span></div>
                        <div class="ln"><span class="text-muted">{{ c.cbm_estimated ? (c.has_box ? 'Freight uses this estimate (you chose item size).' : 'Freight uses this estimate until you enter the real box size.') : 'Freight uses your real box size.' }}</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="copyEst">Copy to box size</button></div>
                    </template>
                    <div class="ln" v-else-if="c.piece_vol != null && c.per_used == null"><span>Enter the <b>total pieces</b> (or pieces per box) to estimate the cartons and their freight.</span></div>
                    <template v-if="c.cbm_real != null && c.cbm_est != null">
                        <div class="ln" :class="{ tot: !c.cbm_estimated }"><span>Freight with real box</span><span class="mono">{{ fmt(c.cbm_real, 4) }} CBM → {{ money(c.cbm_real * c.rate) }}</span></div>
                        <div class="ln" :class="{ tot: c.cbm_estimated }"><span>Freight with item-size estimate</span><span class="mono">{{ fmt(c.cbm_est, 4) }} CBM → {{ money(c.cbm_est * c.rate) }}</span></div>
                    </template>
                    <template v-if="c.has_box">
                        <div class="ln"><span>Your box</span><span class="mono">{{ cm(f.box_l, f.box_w, f.box_h) }} · {{ fmt(c.real_vol, 4) }} CBM</span></div>
                        <div class="ln" v-if="c.pack_increase != null" :class="{ 'text-danger': c.pack_increase < 0 }"><span>Box vs items inside</span><span>{{ c.pack_increase < 0 ? 'box is smaller than the items — check the sizes' : '+' + Math.round(c.pack_increase * 100) + '% bigger' }}</span></div>
                        <div class="ln" v-if="c.fit != null"><span>Pieces that fit (approx.)</span><span class="mono">{{ fmt(c.fit, 0) }}{{ c.per_used && c.fit < c.per_used ? ' — fewer than pieces per box' : '' }}</span></div>
                    </template>
                    <div class="ln" v-if="learned && !c.has_box && c.items_vol"><span>From your {{ learned.n }} item(s) with real boxes</span><span class="mono">avg +{{ Math.round(learned.avg * 100) }}% → ≈ {{ fmt(c.items_vol * (1 + learned.avg), 4) }} CBM</span></div>
                </div>

                <h6 class="pur-legend">Costs</h6>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label small mb-1">Cost per piece</label><input type="number" step="any" min="0" class="form-control" v-model="f.unit_cost"></div>
                    <div class="col-6"><label class="form-label small mb-1">Selling price per piece</label><input type="number" step="any" min="0" class="form-control" v-model="f.sell_price" :placeholder="c && c.suggested_price ? 'suggested ' + fmt(c.suggested_price) : ''">
                        <div class="form-text">Optional. Shows your margin.</div></div>
                    <div class="col-6"><label class="form-label small mb-1">Shipment</label>
                        <select class="form-select" v-model="f.shipment_id"><option value="">Not in a shipment</option><option v-for="s in data.shipments" :key="s.id" :value="String(s.id)">{{ s.name }}</option><option value="__new">+ New shipment…</option></select></div>
                    <div class="col-6"><label class="form-label small mb-1">Delivery cost (this order)</label><input type="number" step="any" min="0" class="form-control" v-model="f.delivery" :disabled="!!f.shipment_id">
                        <div class="form-text">{{ f.shipment_id ? 'Comes from the shipment, split across its items.' : 'One amount for the whole order, not per piece.' }}</div></div>
                    <template v-if="f.shipment_id === '__new'">
                        <div class="col-6"><label class="form-label small mb-1">New shipment name</label><input class="form-control" v-model="f.new_shipment"></div>
                        <div class="col-6"><label class="form-label small mb-1">Shipment delivery cost</label><input type="number" step="any" min="0" class="form-control" v-model="f.new_shipment_delivery"></div>
                    </template>
                    <div class="col-6"><label class="form-label small mb-1">Freight rate (price per CBM)</label>
                        <select class="form-select" v-model="f.rate_sel"><option value="">Automatic</option><option v-for="r in data.rates" :key="r.id" :value="'r' + r.id">{{ r.label }} — {{ fmt(r.price) }}/CBM</option><option value="custom">Custom price for this item…</option></select>
                        <div class="form-text" v-if="c">{{ f.rate_sel ? (f.rate_sel === 'custom' ? 'Type the price.' : 'Saved rate — change it under Freight rates.') : 'Uses: ' + c.rate_src + ' — ' + fmt(c.rate) + '/CBM.' }}</div></div>
                    <div class="col-6" v-if="f.rate_sel === 'custom'"><label class="form-label small mb-1">Custom CBM price</label><input type="number" step="any" min="0" class="form-control" v-model="f.cbm_custom"></div>
                    <div class="col-6"><label class="form-label small mb-1">Target margin % (optional)</label><input type="number" step="any" min="0" max="95" class="form-control" v-model="f.target_margin" :placeholder="'default ' + fmt(data.settings.default_margin, 0) + '%'"></div>
                </div>

                <h6 class="pur-legend">Cost breakdown</h6>
                <div class="pur-calc" v-if="c">
                    <div class="ln"><span>Total pieces</span><span class="mono">{{ c.total_items != null ? fmt(c.total_items, 0) + (c.qty_entered ? ' (order quantity)' : '') : '—' }}</span></div>
                    <div class="ln"><span>{{ c.cbm_estimated ? 'Total CBM (estimated carton)' : 'Total CBM' }}</span><span class="mono">{{ c.cbm != null ? (c.cbm_estimated ? '≈ ' : '') + fmt(c.cbm, 4) : '—' }}</span></div>
                    <div class="ln"><span>Goods cost</span><span class="mono">{{ c.item_cost != null ? money(c.unit_cost, 3) + ' × ' + fmt(c.qty, 0) + ' = ' + money(c.item_cost) : '—' }}</span></div>
                    <div class="ln"><span>Freight (@ {{ fmt(c.rate) }} per CBM · {{ c.rate_src }})</span><span class="mono">{{ money(c.freight) }}</span></div>
                    <div class="ln"><span>{{ c.share != null ? 'Delivery share (' + fmt(c.share * 100, 1) + '% of shipment)' : 'Delivery' }}</span><span class="mono">{{ money(c.delivery) }}</span></div>
                    <div class="ln tot"><span>Total cost</span><span class="mono">{{ money(c.total) }}</span></div>
                    <div class="ln big"><span>Cost per piece</span><span class="mono">{{ money(c.per_item, 3) }}</span></div>
                    <div class="ln" v-if="c.sell != null"><span>Profit per piece</span><span class="mono" :class="c.profit < 0 ? 'text-danger' : 'text-success'">{{ money(c.profit, 3) }} ({{ fmt(c.margin * 100, 1) }}%)</span></div>
                    <div class="ln" v-if="c.suggested_price"><span>Suggested selling price ({{ fmt(c.margin_target, 0) }}% margin)</span><span class="mono">{{ money(c.suggested_price) }}</span></div>
                </div>

                <!-- ===== Store listing: description, photos, barcode ===== -->
                <h6 class="pur-legend">Store listing <span class="fw-normal text-muted">· brand {{ data.settings.brand_name || 'Lebazone' }}</span></h6>
                <div class="d-flex align-items-center mb-1">
                    <label class="form-label small mb-0 me-auto">Description (shown on the website)</label>
                    <button v-if="f.id" type="button" class="btn btn-sm btn-outline-primary" :disabled="!!ai.desc" @click="aiDescription"
                        :title="data.settings.ai_text ? '' : 'Set up AI in Purchasing → Settings'">
                        <span v-if="ai.desc" class="spinner-border spinner-border-sm me-1"></span>✨ {{ ai.desc ? 'Writing…' : 'Write with AI' }}</button>
                </div>
                <textarea class="form-control" rows="5" v-model="f.description" placeholder="What it is, what it's for, colours, size, what's in the box…"></textarea>
                <div class="form-text">Plain text is fine — blank lines become paragraphs. Empty = the name and options are used.</div>

                <div class="d-flex align-items-center mt-3 mb-1">
                    <label class="form-label small mb-0 me-auto">Store photos <span class="text-muted">({{ photos.length }}) · first one is the main photo</span></label>
                </div>
                <div v-if="!f.id" class="small text-muted">Save the item first to add more photos.</div>
                <template v-else>
                    <div class="pur-ph-grid">
                        <div v-for="(ph, i) in photos" :key="ph.path" class="pur-ph" :class="{ main: i === 0 }">
                            <img :src="ph.url" alt="" @click="zoom = ph.url">
                            <span v-if="i === 0" class="pur-ph-tag">Main</span>
                            <div class="pur-ph-bar">
                                <button type="button" title="Move left" :disabled="i === 0" @click="movePhoto(i, -1)">◀</button>
                                <button type="button" title="Make main" :disabled="i === 0" @click="movePhoto(i, -i)">★</button>
                                <button type="button" title="Move right" :disabled="i === photos.length - 1" @click="movePhoto(i, 1)">▶</button>
                                <button type="button" title="Remove" class="del" @click="removePhoto(i)">✕</button>
                            </div>
                        </div>
                        <label class="pur-ph add" title="Add photos">＋<span>Add photos</span>
                            <input type="file" accept="image/*" multiple class="d-none" @change="uploadPhotos"></label>
                    </div>

                    <!-- AI photos -->
                    <div class="pur-ai mt-2">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <b class="small">✨ AI photos from the item picture</b>
                            <label v-for="st in aiStyles" :key="st" class="form-check form-check-inline small mb-0">
                                <input class="form-check-input" type="checkbox" :value="st" v-model="ai.styles"> {{ STYLE_LABEL[st] || st }}</label>
                            <button type="button" class="btn btn-sm btn-primary ms-auto" :disabled="ai.photoJobs.length > 0 || !ai.styles.length" @click="aiPhotos">
                                <span v-if="ai.photoJobs.length" class="spinner-border spinner-border-sm me-1"></span>{{ ai.photoJobs.length ? 'Making ' + ai.photoJobs.length + '…' : 'Make ' + ai.styles.length + ' photos' }}</button>
                        </div>
                        <div v-if="!data.settings.ai_photos" class="small text-muted mt-1">Turn this on in Purchasing → <b>Settings → AI</b> (Gemini or OpenAI key).</div>
                        <div v-if="ai.slow" class="small text-warning mt-1">Still waiting… make sure the “Background jobs” window is open on the PC.</div>
                        <div v-if="ai.candidates.length" class="mt-2">
                            <div class="small text-muted mb-1">Check each photo carefully — keep only the ones that show the product exactly as it is.</div>
                            <div class="pur-ph-grid">
                                <div v-for="(cd, i) in ai.candidates" :key="cd.path" class="pur-ph cand">
                                    <img :src="cd.url" alt="" @click="zoom = cd.url">
                                    <span class="pur-ph-tag ai">{{ STYLE_LABEL[cd.style] || 'AI' }}</span>
                                    <div class="pur-ph-bar">
                                        <button type="button" class="ok" @click="keepCandidate(i)">✓ Keep</button>
                                        <button type="button" class="del" @click="ai.candidates.splice(i, 1)">✕</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-for="er in ai.errors" :key="er" class="small text-danger mt-1">{{ er }}</div>
                    </div>
                </template>

                <div class="row g-2 mt-2 align-items-end">
                    <div class="col"><label class="form-label small mb-1">Barcode on the item <span class="text-muted">(scan it)</span></label>
                        <div class="d-flex gap-2"><input class="form-control" v-model="f.barcode" placeholder="Scan or type the barcode printed on the item" autocomplete="off" @keydown.enter.prevent>
                            <CameraScanner @scan="c => { f.barcode = c; scanBeep(); }" /></div>
                        <div class="form-text">No barcode on the item? Leave it empty — the product gets a Lebazone code (LBZ…) and you print stickers when the goods arrive.</div></div>
                </div>
                <div v-if="zoom" class="pur-zoom" @click="zoom = null"><img :src="zoom" alt=""></div>

                <!-- Lebazone catalogue -->
                <h6 class="pur-legend">Lebazone product</h6>
                <div v-if="!f.id" class="small text-muted">Save the item first, then link it to a product or create one.</div>
                <div v-else-if="item.link" class="card"><div class="card-body small">
                    <div class="d-flex gap-2"><div class="me-auto"><div class="fw-semibold">{{ item.link.name }}</div>
                        <div class="text-muted">SKU {{ item.link.sku }} · {{ item.link.status ? 'shown on store' : 'hidden (draft)' }} · store stock {{ item.link.available ?? '—' }}</div></div>
                        <a :href="$router.resolve('/products/edit/' + item.link.product_id).href" target="_blank" class="text-nowrap">Open product ↗</a></div>
                    <div class="d-flex flex-wrap gap-3 my-2">
                        <div><div class="text-muted">Lebazone cost price</div><b class="mono">{{ money(item.link.purchase_price) }}</b></div>
                        <div><div class="text-muted">Landed cost now</div><b class="mono">{{ money(c && c.per_item, 2) }}</b></div>
                        <div><div class="text-muted">Selling price</div><b class="mono">{{ money(item.link.discounted_price || item.link.price) }}</b></div>
                        <div v-if="Number(item.received_qty)"><div class="text-muted">Received</div><b class="mono">{{ fmt(item.received_qty, 0) }} pcs</b></div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-sm btn-primary" @click="act('/purchasing/apply_cost', { item_id: f.id })" :disabled="dirty || busy">Update cost price</button>
                        <button class="btn btn-sm btn-outline-primary" @click="act('/purchasing/apply_cost', { item_id: f.id, with_price: 1, price: f.sell_price || (c && c.suggested_price) })" :disabled="dirty || busy">
                            Update cost + selling price ({{ money(f.sell_price || (c && c.suggested_price)) }})</button>
                    </div>
                    <div class="input-group input-group-sm mt-2" style="max-width:380px">
                        <span class="input-group-text">Receive</span>
                        <input type="number" min="1" class="form-control" v-model="receiveQty">
                        <span class="input-group-text">pieces</span>
                        <button class="btn btn-success" @click="receive" :disabled="dirty || busy || !(receiveQty > 0)">Add to stock</button>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                        <div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="pur-lbl" v-model="labelsOnReceive">
                            <label class="form-check-label" for="pur-lbl">Print a sticker for each piece when adding to stock</label></div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="printLabels(receiveQty || 1)">🏷 Print stickers now</button>
                        <select v-model="labelSize" class="form-select form-select-sm w-auto"><option value="50x30">50×30 mm</option><option value="40x25">40×25 mm</option><option value="58x40">58×40 mm</option><option value="a4">A4 sheet</option></select>
                    </div>
                    <div class="text-muted mt-1">Sticker code: <b class="mono">{{ item.link.sku }}</b>{{ f.barcode ? ' · the item also has its own barcode ' + f.barcode + ' (the packer can scan either)' : '' }}</div>
                    <div class="d-flex justify-content-between mt-2"><span class="text-muted" v-if="dirty">Save your changes first.</span>
                        <button class="btn btn-sm btn-link text-danger ms-auto px-0" @click="act('/purchasing/link', { item_id: f.id, variant_id: 0 })">Unlink</button></div>
                </div></div>
                <div v-else class="card"><div class="card-body small">
                    <div class="btn-group btn-group-sm mb-2"><button class="btn" :class="mode === 'link' ? 'btn-primary' : 'btn-outline-primary'" @click="mode = 'link'">Link existing product</button>
                        <button class="btn" :class="mode === 'create' ? 'btn-primary' : 'btn-outline-primary'" @click="mode = 'create'; loadCategories()">Create new product</button></div>
                    <template v-if="mode === 'link'">
                        <div class="input-group input-group-sm"><input class="form-control" v-model="search" placeholder="Search Lebazone products by name or SKU" @keyup.enter="findProducts"><button class="btn btn-outline-primary" @click="findProducts">Search</button></div>
                        <div v-for="p in results" :key="p.variant_id" class="d-flex align-items-center gap-2 border-bottom py-1">
                            <img v-if="p.image" :src="p.image" alt="" style="width:34px;height:34px;object-fit:cover;border-radius:6px">
                            <span class="me-auto">{{ p.name }} <span class="text-muted">{{ p.sku }}</span></span>
                            <button class="btn btn-sm btn-link" @click="act('/purchasing/link', { item_id: f.id, variant_id: p.variant_id, store_id: storeId })">Link</button>
                        </div>
                        <div v-if="searched && !results.length" class="text-muted py-1">No products found.</div>
                    </template>
                    <template v-else>
                        <div class="row g-2">
                            <div class="col-6"><label class="form-label small mb-1">Category</label><select class="form-select form-select-sm" v-model="np.category_id"><option value="">Choose…</option><option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option></select></div>
                            <div class="col-6"><label class="form-label small mb-1">Selling price</label><input type="number" step="any" min="0" class="form-control form-control-sm" v-model="np.price" :placeholder="c && c.suggested_price ? String(c.suggested_price) : ''"></div>
                            <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="np-pub" v-model="np.publish"><label class="form-check-label" for="np-pub">Show on the store now (otherwise it is created hidden, as a draft)</label></div></div>
                        </div>
                        <div class="text-muted my-1">Uses the item's name, description, store photos (first = main), barcode and the <b>{{ data.settings.brand_name || 'Lebazone' }}</b> brand; cost price = landed cost per piece. Code: the SKU above, or a new LBZ code. Stock starts at 0 — receive the goods to add it.</div>
                        <button class="btn btn-sm btn-primary" @click="createProduct" :disabled="dirty || busy">Create product</button>
                        <span class="text-muted ms-2" v-if="dirty">Save your changes first.</span>
                    </template>
                    <div class="mt-2" v-if="data.stores.length > 1"><label class="form-label small mb-1">Store</label>
                        <select class="form-select form-select-sm" v-model="storeId"><option v-for="s in data.stores" :key="s.id" :value="s.id">{{ s.name }}</option></select></div>
                </div></div>

                <h6 class="pur-legend">Notes</h6>
                <textarea class="form-control" rows="2" v-model="f.notes" placeholder="Quality, packing, order number…"></textarea>
            </div>
            <div class="pur-drawer-f">
                <button v-if="f.id" class="btn" :class="armed ? 'btn-danger' : 'btn-outline-danger'" @click="remove">{{ armed ? 'Click again to delete' : 'Delete' }}</button>
                <button class="btn btn-outline-secondary ms-auto" @click="$emit('close')">Cancel</button>
                <button class="btn btn-primary" @click="save" :disabled="busy">{{ f.id ? 'Save changes' : 'Add item' }}</button>
            </div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import { ITEM_STATUS, num, fmt, post } from './purchasing.js';
import CameraScanner from '../../components/CameraScanner.vue';
import { soundOk } from '../../utils/sounds.js'; // Custom (Lebazone): scan sound
import { printProductLabels } from '../../utils/warehousePrint.js';

const STYLE_LABEL = { studio: 'White background', angle: 'Other angle', lifestyle: 'In use', detail: 'Close-up', packaging: 'With box' };
const lsGet = (k, d) => { try { return localStorage.getItem(k) || d; } catch (e) { return d; } };

const FIELDS = ['name', 'sku', 'variants', 'status', 'qty', 'boxes', 'per_box', 'box_l', 'box_w', 'box_h', 'item_l', 'item_w', 'item_h', 'cbm_basis',
    'unit_cost', 'sell_price', 'target_margin', 'delivery', 'cbm_custom', 'notes', 'description', 'barcode'];

export default {
    components: { CameraScanner },
    props: { item: { type: Object, required: true }, data: { type: Object, required: true } },
    emits: ['close', 'saved'],
    data() {
        return { ITEM_STATUS, STYLE_LABEL, photos: (this.item.photos || []).slice(), zoom: null,
            ai: { styles: ['studio', 'angle', 'lifestyle', 'detail'], photoJobs: [], candidates: [], errors: [], desc: null, slow: false },
            labelSize: lsGet('lbzLabelSize', '50x30'), labelsOnReceive: !this.item.barcode,
            f: this.toForm(this.item), c: this.item.calc || null, learned: this.data.learned, pad: this.data.settings.carton_pad,
            file: null, preview: null, removePic: false, dzOver: false, armed: false, busy: false, dirty: false, timer: null,
            mode: 'link', search: '', results: [], searched: false, categories: [], np: { category_id: '', price: '', publish: false },
            storeId: this.item.store_id || this.data.settings.default_store_id,
            receiveQty: Math.max(0, Math.round(((this.item.calc && this.item.calc.total_items) || 0) - Number(this.item.received_qty || 0))) || '' };
    },
    computed: {
        picSrc() { return this.preview || (this.removePic ? null : this.item.picture_url); },
        aiStyles() { return this.data.settings.ai_styles || ['studio', 'angle', 'lifestyle', 'detail', 'packaging']; },
    },
    watch: {
        labelSize(v) { try { localStorage.setItem('lbzLabelSize', v); } catch (e) {} },
        f: { deep: true, handler() { this.dirty = true; clearTimeout(this.timer); this.timer = setTimeout(this.runPreview, 250); } },
    },
    mounted() { this.$nextTick(() => { this.dirty = false; }); this.runPreview(); document.addEventListener('keydown', this.onKey); },
    beforeUnmount() { this.stopAi = true; clearTimeout(this.aiTimer); document.removeEventListener('keydown', this.onKey); clearTimeout(this.timer); if (this.preview) URL.revokeObjectURL(this.preview); },
    methods: {
        scanBeep() { soundOk(); }, // Custom (Lebazone): sound + buzz when the camera reads the barcode
        fmt,
        onKey(e) { if (e.key === 'Escape') this.$emit('close'); },
        money(v, d = 2) { return v == null || v === '' ? '—' : (this.data.settings.currency ? this.data.settings.currency + ' ' : '') + fmt(Number(v), d); },
        cm(a, b, c) { return [a, b, c].every(x => num(x) != null) ? `${fmt(num(a), 0)} × ${fmt(num(b), 0)} × ${fmt(num(c), 0)} cm` : ''; },
        toForm(it) {
            const f = { id: it.id || null };
            FIELDS.forEach(k => { const v = it[k]; f[k] = v == null ? '' : (typeof v === 'string' && /^-?\d+\.\d+$/.test(v) ? String(Number(v)) : v); });
            f.description = it.description ? this.htmlToText(it.description) : ''; // stored as simple HTML, edited as text
            f.status = it.status || 'Quote';
            f.cbm_basis = it.cbm_basis || '';
            f.supplier_id = it.supplier_id ? String(it.supplier_id) : '';
            f.shipment_id = it.shipment_id ? String(it.shipment_id) : '';
            f.rate_sel = it.rate_mode === 'rate' && it.rate_id ? 'r' + it.rate_id : it.rate_mode === 'custom' ? 'custom' : '';
            f.new_supplier = ''; f.new_shipment = ''; f.new_shipment_delivery = '';
            f.copy_from = it.copy_from || '';
            return f;
        },
        payload() {
            const f = this.f, p = {};
            FIELDS.forEach(k => { p[k] = f[k] ?? ''; });
            if (f.id) p.id = f.id;
            p.supplier_id = f.supplier_id === '__new' ? '' : f.supplier_id;
            p.shipment_id = f.shipment_id === '__new' ? '' : f.shipment_id;
            p.rate_mode = f.rate_sel === 'custom' ? 'custom' : f.rate_sel ? 'rate' : '';
            p.rate_id = f.rate_sel && f.rate_sel !== 'custom' ? f.rate_sel.slice(1) : '';
            if (p.shipment_id) p.delivery = '';
            return p;
        },
        runPreview() {
            axios.post(this.$apiUrl + '/purchasing/preview', this.payload()).then(r => { if (r.data.status === 1) { this.c = r.data.data.calc; this.learned = r.data.data.learned; this.pad = r.data.data.carton_pad; } }).catch(() => {});
        },
        setFile(file) {
            if (!file || !/^image\//.test(file.type)) { this.showError('That file is not a picture'); return; }
            if (this.preview) URL.revokeObjectURL(this.preview);
            this.file = file; this.preview = URL.createObjectURL(file); this.removePic = false; this.dirty = true;
        },
        onDrop(e) { this.dzOver = false; this.setFile(e.dataTransfer.files[0]); },
        onPaste(e) { const file = [...(e.clipboardData?.files || [])].find(x => /^image\//.test(x.type)); if (file) { e.preventDefault(); this.setFile(file); } },
        removePicture() { this.file = null; if (this.preview) URL.revokeObjectURL(this.preview); this.preview = null; this.removePic = true; this.dirty = true; },
        copyEst() { if (!this.c || !this.c.est) return; this.f.box_l = this.c.est.L; this.f.box_w = this.c.est.W; this.f.box_h = this.c.est.H; },
        save() {
            if (!String(this.f.name).trim()) { this.showError('Give the item a name'); return; }
            const fd = new FormData(), p = this.payload();
            Object.entries(p).forEach(([k, v]) => fd.append(k, v ?? ''));
            if (this.f.supplier_id === '__new') fd.append('new_supplier', this.f.new_supplier || '');
            if (this.f.shipment_id === '__new') { fd.append('new_shipment', this.f.new_shipment || ''); fd.append('new_shipment_delivery', this.f.new_shipment_delivery || ''); }
            if (this.file) fd.append('picture', this.file);
            else if (this.removePic) fd.append('remove_picture', 1);
            else if (this.f.copy_from) fd.append('copy_picture_from', this.f.copy_from);
            this.busy = true;
            post(this, '/purchasing/items/save', fd).then(ok => { this.busy = false; if (ok) { this.dirty = false; this.$emit('saved', null); } });
        },
        remove() {
            if (!this.armed) { this.armed = true; setTimeout(() => { this.armed = false; }, 3500); return; }
            post(this, '/purchasing/items/delete', { id: this.f.id }).then(ok => ok && this.$emit('saved', null));
        },
        duplicate() {
            const copy = { ...this.item, id: null, variants: '', product_id: null, product_variant_id: null, link: null, received_qty: 0, received_at: null, copy_from: this.item.id };
            this.f = this.toForm(copy); this.dirty = true; this.armed = false;
            this.showMessage('success', 'Copy ready — change the variant and save');
        },
        act(url, data) {
            this.busy = true;
            post(this, url, data).then(ok => { this.busy = false; if (ok) this.$emit('saved', this.f.id); });
        },
        findProducts() {
            axios.get(this.$apiUrl + '/purchasing/products', { params: { search: this.search } }).then(r => { this.results = r.data.data || []; this.searched = true; });
        },
        loadCategories() {
            if (this.categories.length) return;
            axios.get(this.$apiUrl + '/categories/active').then(r => {
                this.categories = (r.data.data || []).map(cat => ({ id: cat.id, name: cat.name || (cat.translations && cat.translations[0] && cat.translations[0].name) || ('#' + cat.id) }));
            });
            if (!this.np.price && this.c && this.c.suggested_price) this.np.price = this.f.sell_price || this.c.suggested_price;
        },
        // ---- store photos ----
        savePhotos() {
            return axios.post(this.$apiUrl + '/purchasing/items/photos_save', { id: this.f.id, photos: this.photos.map(p => p.path) })
                .then(r => { if (r.data.status !== 1) this.showError(r.data.message); }).catch(() => this.showError('Could not save the photos'));
        },
        movePhoto(i, d) { const j = i + d; if (j < 0 || j >= this.photos.length) return; const [p] = this.photos.splice(i, 1); this.photos.splice(j, 0, p); this.savePhotos(); },
        removePhoto(i) { this.photos.splice(i, 1); this.savePhotos(); },
        uploadPhotos(e) {
            const files = [...(e.target.files || [])]; e.target.value = '';
            if (!files.length) return;
            const fd = new FormData(); fd.append('id', this.f.id); files.forEach(x => fd.append('photos[]', x));
            axios.post(this.$apiUrl + '/purchasing/items/photos', fd).then(r => {
                if (r.data.status === 1) this.photos = r.data.data.photos; else this.showError(r.data.message);
            }).catch(() => this.showError('Upload failed'));
        },
        keepCandidate(i) { const [c] = this.ai.candidates.splice(i, 1); this.photos.push({ path: c.path, url: c.url }); this.savePhotos(); },
        // ---- AI ----
        aiStart(body) {
            return axios.post(this.$apiUrl + '/purchasing/ai/start', { id: this.f.id, ...body }).then(r => {
                if (r.data.status !== 1) { this.showError(r.data.message); return []; }
                return r.data.data.jobs || [];
            }).catch(() => { this.showError('Could not start the AI'); return []; });
        },
        aiPhotos() {
            if (!this.data.settings.ai_photos) { this.showError('Set up AI photos first: Purchasing → Settings → AI'); return; }
            this.ai.errors = [];
            this.aiStart({ kind: 'photos', styles: this.ai.styles }).then(ids => { this.ai.photoJobs = ids; this.aiPoll(); });
        },
        aiDescription() {
            if (!this.data.settings.ai_text) { this.showError('Set up AI descriptions first: Purchasing → Settings → AI'); return; }
            this.ai.errors = [];
            this.aiStart({ kind: 'description' }).then(ids => { this.ai.desc = ids[0] || null; this.aiPoll(); });
        },
        aiPoll() {
            clearTimeout(this.aiTimer);
            const ids = [...this.ai.photoJobs, ...(this.ai.desc ? [this.ai.desc] : [])];
            if (!ids.length || this.stopAi) { this.ai.slow = false; return; }
            axios.get(this.$apiUrl + '/purchasing/ai/status', { params: { jobs: ids } }).then(r => {
                (r.data.data || []).forEach(j => {
                    if (j.status !== 'done' && j.status !== 'failed') { if (j.status === 'queued' && j.waiting_s > 40) this.ai.slow = true; return; }
                    if (j.kind === 'photo') {
                        this.ai.photoJobs = this.ai.photoJobs.filter(x => x !== j.id);
                        if (j.status === 'done' && j.result) this.ai.candidates.push({ ...j.result, style: j.style });
                    } else {
                        this.ai.desc = null;
                        if (j.status === 'done' && j.result) {
                            if (String(this.f.description || '').trim() && !confirm('Replace the current description with the AI one?')) return;
                            this.f.description = this.htmlToText(j.result);
                        }
                    }
                    if (j.status === 'failed') this.ai.errors.push((STYLE_LABEL[j.style] || 'AI') + ': ' + (j.error || 'failed'));
                    this.ai.slow = false;
                });
            }).catch(() => {}).finally(() => { this.aiTimer = setTimeout(this.aiPoll, 3000); });
        },
        // AI returns simple HTML — show it as readable text in the box (saved back as paragraphs / bullets).
        htmlToText(h) {
            return String(h)
                .replace(/<li[^>]*>/gi, '• ').replace(/<\/li>/gi, '\n').replace(/<\/p>/gi, '\n\n').replace(/<br\s*\/?>/gi, '\n')
                .replace(/<[^>]+>/g, '').replace(/&amp;/g, '&').replace(/&nbsp;/g, ' ').replace(/&#39;/g, "'").replace(/&quot;/g, '"')
                .replace(/\n{3,}/g, '\n\n').trim();
        },
        // ---- stickers ----
        printLabels(n) {
            const l = this.item.link; if (!l) return;
            printProductLabels({ name: l.name || this.f.name }, { code: l.sku, color: this.f.variants || '', sale_price: l.discounted_price || l.price }, Math.max(1, Math.round(Number(n) || 1)), this.labelSize, 'Lebazone');
        },
        receive() {
            const n = this.receiveQty;
            if (this.labelsOnReceive) this.printLabels(n);
            this.act('/purchasing/receive', { item_id: this.f.id, qty: n });
        },
        createProduct() {
            if (!this.np.category_id) { this.showError('Choose a category'); return; }
            this.act('/purchasing/create_product', { item_id: this.f.id, category_id: this.np.category_id, price: this.np.price, publish: this.np.publish ? 1 : 0, store_id: this.storeId });
        },
    },
};
</script>

<style scoped>
.mono { font-variant-numeric: tabular-nums; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.pur-scrim { position: fixed; inset: 0; background: rgba(0, 0, 0, .4); z-index: 1050; }
.pur-drawer { position: absolute; top: 0; right: 0; bottom: 0; width: min(720px, 100%); background: var(--bs-body-bg); display: flex; flex-direction: column; box-shadow: 0 10px 30px rgba(0, 0, 0, .2); }
.pur-drawer-h, .pur-drawer-f { display: flex; gap: 8px; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--bs-border-color); }
.pur-drawer-f { border-bottom: 0; border-top: 1px solid var(--bs-border-color); }
.pur-drawer-b { flex: 1; overflow-y: auto; padding: 16px; }
.pur-legend { font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--bs-secondary-color); margin: 18px 0 8px; }
.pur-dz { display: flex; gap: 14px; align-items: center; border: 1.5px dashed var(--bs-border-color); border-radius: 10px; padding: 12px; cursor: pointer; }
.pur-dz.over { border-color: var(--bs-primary); background: var(--bs-primary-bg-subtle); }
.pur-pic { flex: none; width: 96px; height: 96px; border-radius: 10px; border: 1px dashed var(--bs-border-color); background: var(--bs-tertiary-bg); display: grid; place-items: center; overflow: hidden; color: var(--bs-secondary-color); font-size: 11px; }
.pur-pic.big { width: 140px; height: 140px; }
.pur-pic.has { border: 0; background: none; }
.pur-pic img { width: 100%; height: 100%; object-fit: cover; }
.pur-calc { border: 1px solid var(--bs-border-color); border-radius: 10px; overflow: hidden; font-size: 13px; }
.pur-calc .ln { display: flex; justify-content: space-between; gap: 10px; align-items: center; padding: 7px 12px; border-bottom: 1px solid var(--bs-border-color); }
.pur-calc .ln:last-child { border-bottom: 0; }
.pur-calc .ln > span:first-child { color: var(--bs-secondary-color); }
.pur-calc .ln > span:last-child { text-align: right; font-weight: 600; }
.pur-calc .ln.tot { background: var(--bs-tertiary-bg); font-weight: 700; }
.pur-calc .ln.tot > span:first-child { color: var(--bs-body-color); }
.pur-calc .ln.big { background: var(--bs-emphasis-color); color: var(--bs-body-bg); }
.pur-calc .ln.big > span:first-child { color: var(--bs-body-bg); }
.pur-calc .ln.big .mono { font-size: 17px; }
.pur-ph-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)); gap: 8px; }
.pur-ph { position: relative; aspect-ratio: 1; border-radius: 10px; overflow: hidden; border: 1px solid var(--bs-border-color); background: var(--bs-tertiary-bg); }
.pur-ph.main { outline: 2px solid var(--bs-primary); outline-offset: 1px; }
.pur-ph img { width: 100%; height: 100%; object-fit: cover; cursor: zoom-in; display: block; }
.pur-ph-tag { position: absolute; top: 4px; left: 4px; background: var(--bs-primary); color: #fff; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 6px; }
.pur-ph-tag.ai { background: #7c3aed; }
.pur-ph-bar { position: absolute; left: 0; right: 0; bottom: 0; display: flex; background: rgba(0, 0, 0, .55); }
.pur-ph-bar button { flex: 1; border: 0; background: none; color: #fff; font-size: 12px; padding: 4px 0; }
.pur-ph-bar button:disabled { opacity: .35; }
.pur-ph-bar button.del:hover { background: #dc2626; }
.pur-ph-bar button.ok { background: #16a34a; font-weight: 700; }
.pur-ph.add { display: flex; flex-direction: column; align-items: center; justify-content: center; cursor: pointer; border-style: dashed; font-size: 26px; color: var(--bs-secondary-color); }
.pur-ph.add span { font-size: 11px; }
.pur-ph.cand { border-color: #7c3aed; }
.pur-ai { border: 1px dashed #a78bfa; border-radius: 10px; padding: 10px; background: rgba(124, 58, 237, .04); }
.pur-zoom { position: fixed; inset: 0; z-index: 1100; background: rgba(0, 0, 0, .8); display: grid; place-items: center; cursor: zoom-out; }
.pur-zoom img { max-width: 92vw; max-height: 92vh; border-radius: 10px; }
</style>

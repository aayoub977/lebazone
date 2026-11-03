<?php
// Lebazone: store descriptions for the items imported from "Orders Online" (written from each item's
// name and photo). Fills only items whose description is still empty — your own edits are never replaced.
// Also puts products already made from these items under the Lebazone brand with the same description.
$dev = __DIR__;
$base = $dev . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (!Schema::hasTable('pur_items') || !Schema::hasColumn('pur_items', 'description')) { echo "Descriptions: run the migrations first.\n"; return; }

$h = function (string $intro, array $bullets) {
    return '<p>' . e($intro) . '</p><ul>' . implode('', array_map(fn ($b) => '<li>' . e($b) . '</li>', $bullets)) . '</ul>';
};
$safety = 'Safety: for ages 14+ with adult supervision. Always wear the safety goggles, use only 7–8 mm water-gel balls, and never aim at the face, people without eye protection or animals.';
$gelBall = 'Shoots soft water-gel balls (7–8 mm) — soak the balls in water for a few hours before use.';

$D = [
    'it-ramadan-3m-20lights' => $h('Battery-powered Ramadan string lights with 20 crescent-moon and star shaped lights on a 3 m wire — an easy way to light up a window, shelf or doorway for Ramadan and Eid.',
        ['20 crescent and star lights on a 3 m string', 'Runs on a small battery box — no socket needed', 'Warm, soft glow for evenings and Iftar', 'Light and easy to hang anywhere']),
    'it-ramadan-lantern' => $h('A classic Ramadan lantern (fanous) with decorative panels showing crescents and mosque domes. When lit it gives a warm, festive glow — perfect on the Iftar table, a shelf or by the entrance.',
        ['Traditional lantern shape with a top ring for carrying or hanging', 'Decorative Ramadan cut-out panels', 'Available in Black, Gold, Red and Bronze', 'A lovely gift for Ramadan and Eid']),
    'itmv0rksn1kmjb1' => $h('LED curtain fairy lights, 4 m wide, with hanging strands of different lengths (about 40, 50 and 60 cm) for a falling-lights effect. Comes with a remote control to switch modes from across the room.',
        ['4 m long with hanging light strands', 'Remote control included', 'Plug-in power box', 'Great for windows, walls, bedrooms, Ramadan and party decoration']),
    'itmv0rnrfexvqg6' => $h('Gold Ramadan serving trays in festive shapes — a crescent moon and a star — with a decorative cut-out rim. Ideal for dates, sweets and nuts on the Iftar table or as decoration.',
        ['Crescent tray about 27.2 × 9.5 × 5.2 cm', 'Star tray about 23.8 cm wide, 5 cm high', 'Shiny gold finish with Ramadan pattern', 'Different models available']),
    'itmv0s1484z0ni9' => $h('Compact 39 cm AK47-style gel ball blaster with colourful graffiti designs. Switch between manual and automatic firing. ' . $gelBall,
        ['Manual and automatic dual modes', 'Rechargeable battery with charging cable', 'Comes with gel ball container (hopper) and adapter', 'Silencer or flame-tube attachment (one of the two)', 'Colour box packaging (about 41 × 17 × 7.5 cm) — designs vary', $safety]),
    'itmv0s35rxgc2zb' => $h('55 cm MP9-style gel ball blaster with bright graffiti designs, manual and automatic modes. ' . $gelBall,
        ['Manual and automatic dual modes', 'Rechargeable battery with charging cable', 'Gel ball container (hopper) and adapter included', 'Silencer or flame-tube attachment (one of the two)', 'Colour box packaging — designs vary', $safety]),
    'itmv0s42rsrk15d' => $h('38 cm M416-style gel ball blaster in colourful graffiti designs with manual and automatic modes. ' . $gelBall,
        ['Manual and automatic dual modes', 'Rechargeable battery with charging cable', 'Gel ball container (hopper) and adapter included', 'Silencer or flame-tube attachment (one of the two)', 'Colour box packaging (about 36.5 × 17 × 7.5 cm) — designs vary', $safety]),
    'itmv0s6aziuh7ej' => $h('39 cm M249-style gel ball blaster with a stand (bipod) and graffiti designs, manual and automatic modes. ' . $gelBall,
        ['Manual and automatic dual modes', 'Rechargeable battery with charging cable', 'Gel ball container (hopper) and adapter included', 'Silencer or flame-tube attachment (one of the two)', 'Colour box packaging (about 36.5 × 17 × 7.5 cm) — designs vary', $safety]),
    'itmv0s82ixiil5y' => $h('39 cm AUG-style gel ball blaster, available in four mixed graffiti designs, with manual and automatic modes. ' . $gelBall,
        ['Manual and automatic dual modes', 'Rechargeable battery with USB charging', 'Gel balls and container included', 'Colour box packaging (about 37.5 × 18 × 7 cm)', 'Designs vary — sent at random', $safety]),
    'itmv0s9ubp2h5ma' => $h('Big 69 cm AK47-style gel ball blaster in mixed colourful designs, with manual and automatic modes. ' . $gelBall,
        ['Manual and automatic dual modes', '7.4 V rechargeable battery with USB charging cable', 'Carry strap and a pack of gel balls included', 'Silencer or flame-tube attachment (one of the two)', 'Colour box packaging (about 38.5 × 21 × 7 cm) — designs vary', $safety]),
    'itmv0sccgrk34qd' => $h('68 cm M416-style gel ball blaster with a 7.4 V battery and manual and automatic modes, in colourful graffiti designs. ' . $gelBall,
        ['Manual and automatic dual modes', '7.4 V rechargeable battery', 'Gel balls and container included', 'Colour box packaging — designs vary', $safety]),
    'itmv0se7dhn2s97' => $h('69 cm UMP45-style gel ball blaster in mixed designs, with manual and automatic modes. ' . $gelBall,
        ['Manual and automatic dual modes', '7.4 V rechargeable battery with USB charging cable', 'Gel ball pack, container (hopper) and adapter included', 'Silencer or flame-tube attachment (one of the two)', 'Colour box packaging (about 46.5 × 22 × 9.5 cm) — designs vary', $safety]),
    'itmv0sfoupvioma' => $h('Extra-long 103 cm AR15-style gel ball blaster with manual and automatic modes — a big one for outdoor team games. ' . $gelBall,
        ['Manual and automatic dual modes', 'Rechargeable battery with charging cable', 'Dry gel balls, gel ball container and adapter included', 'Silencer attachment included', 'Colour box packaging (about 40.5 × 19 × 8.3 cm) — designs vary', $safety]),
    'itmv0sjgxk1zaku' => $h('75 cm M4A1-style electric gel ball blaster in black, full automatic, with flashing lights and a mist spray effect for extra fun in night games. ' . $gelBall,
        ['Electric full-automatic firing', 'Flashing light and mist spray effect', 'Top sight and accessory rail', 'Rechargeable battery', $safety]),
    'itmv0spkzdnyga0' => $h('65 cm AK47-style gel blaster, "Running Lantern + Spitfire" edition, with a light-up silencer and colourful graffiti body. ' . $gelBall,
        ['Light-up silencer effect', 'Rechargeable battery and charging cable', 'Gel balls and accessories included', 'Colour box packaging (about 45 × 21 × 6 cm)', $safety]),
    'itmv0ss1z0br5dg' => $h('85 cm SCAR-style electric gel ball blaster in green camouflage, with automatic and manual modes and a full set of accessories. ' . $gelBall,
        ['Automatic and manual modes', 'Comes with safety goggles, carry strap, scope, foregrip and bipod', 'Two magazines plus a large drum magazine', 'Rechargeable battery', 'Colour box packaging', $safety]),
    'itmv0stb4eg1wdn' => $h('80 cm AUG-style electric gel ball blaster with automatic and manual modes — made for outdoor activities and team shooting games. ' . $gelBall,
        ['Automatic and manual modes', 'Comes with safety goggles, scope and carry strap', 'Magazine plus a large drum magazine', 'Rechargeable battery', 'Colour box — Red or Blue', $safety]),
    'itmv0swtkdbro9j' => $h('58 cm electric gel ball blaster with a folding stock, red-dot style sight and drum magazine, with automatic and manual modes. ' . $gelBall,
        ['Automatic and manual modes', 'Drum magazine plus extra magazines', 'Red-dot style sight', 'Rechargeable battery with USB charger', 'Colour box — Blue or Red', $safety]),
    'itmv0sz4qkffwup' => $h('Electric gel ball pistol (Glock style) with continuous manual and automatic firing, graffiti design and lots of extras in the box. ' . $gelBall,
        ['Manual and automatic continuous firing', 'Drum magazine plus two magazines', 'Sight, silencer and accessories included', 'Rechargeable battery with charger', 'Colour box — Red, Blue or Grey', $safety]),
    'itmv0t1tym0otp8' => $h('87 cm M416-style gel ball toy blaster with integrated manual and automatic modes, drum magazine and scope. ' . $gelBall,
        ['Manual and automatic modes', 'Comes with safety goggles, carry strap, magazine and drum magazine', 'Gel balls and refill bottle included', 'Rechargeable battery with USB cable', 'Colour box packaging', $safety]),
    'itmv0t2omi41ryq' => $h('67 cm M249-style gel ball toy blaster in green camouflage with a bipod, scope and manual and automatic modes. ' . $gelBall,
        ['Manual and automatic modes', 'Bipod stand and top scope', 'Comes with safety goggles, carry strap, gel balls and refill bottle', 'Rechargeable battery with USB cable', 'Colour box packaging', $safety]),
    'itmv0t4c0p54f5e' => $h('P90 Mini Edition gel ball blaster with a graffiti design and a light-up "flame" silencer for a cool effect. About 49.5 × 24.5 × 5.5 cm. ' . $gelBall,
        ['Light-up flame-effect silencer', 'Top-loading gel ball container', 'Compact size, easy for younger players to hold', 'Colour box packaging', $safety]),
    'itmv0t7zf42f4bl' => $h('Electric dual-tube M416-style gel ball blaster with colourful night lights and a drum magazine — great for evening games. ' . $gelBall,
        ['Electric firing with two barrels', 'Colourful light effect for night play', 'Drum magazine', 'Colour box packaging', $safety]),
    'itmv0v49grbz580' => $h('Wooden Eid Mubarak serving trays with mosque, crescent and lantern cut-out decorations — beautiful for serving sweets, dates, coffee and tea to guests during Ramadan and Eid.',
        ['Natural wood with festive Eid Mubarak decoration', 'Several models: trays with compartments, crescent-and-star tray and more', 'Great for desserts, cookies, dates and cups', 'Packaging about 30 × 20 × 10 cm', 'Model shown may vary']),
    'itmv0veebyew13h' => $h('Ramadan Kareem / Eid Mubarak table runner, 33 × 180 cm, with crescent-moon and Arabic calligraphy designs — instantly gives your dining table a festive look.',
        ['Size 33 × 180 cm — fits most dining tables', 'Crescent and calligraphy Ramadan design', 'Available in multiple colours', 'Lovely for Iftar gatherings and Eid']),
];

$brandId = (int) (DB::table('pur_settings')->where('key', 'brand_id')->value('value') ?? 0);
$langId = (int) (DB::table('languages')->where('is_default', 1)->value('id') ?? DB::table('languages')->orderBy('id')->value('id'));
$items = 0; $products = 0;
foreach ($D as $sid => $html) {
    $it = DB::table('pur_items')->where('source_id', $sid)->first();
    if (!$it) continue;
    if (!trim((string) $it->description)) {
        DB::table('pur_items')->where('id', $it->id)->update(['description' => $html, 'updated_at' => now()]);
        $items++;
    }
    if ($it->product_id) {
        if ($brandId) DB::table('products')->where('id', $it->product_id)->update(['brand_id' => $brandId]);
        // Fill the product's description only if it is still the automatic one (just the name / options).
        if (Schema::hasTable('product_translations') && $langId) {
            $tr = DB::table('product_translations')->where('product_id', $it->product_id)->where('language_id', $langId)->first();
            $auto = !$tr || !trim(strip_tags((string) $tr->description)) || str_starts_with(trim((string) $tr->description), '<p>' . e($it->name) . '</p>');
            if ($tr && $auto) {
                DB::table('product_translations')->where('id', $tr->id)->update(['description' => $html, 'updated_at' => now()]);
                if (Schema::hasColumn('products', 'description')) DB::table('products')->where('id', $it->product_id)->update(['description' => $html]);
                $products++;
            }
        }
    }
}
echo "Descriptions: $items item(s) filled, $products existing product(s) updated.\n";

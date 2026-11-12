<?php
// Lebazone: once - writes the 5 customer policies for Lebanon (privacy, returns, shipping,
// cancellation, terms). Only fields that are still EMPTY or hold the placeholder text are
// filled - anything you already wrote is kept. Edit them any time in
// admin -> Countries -> Lebanon -> policies. Flag lebazone_policies_v1.
// (Drafts written 2026-11-12 - have them checked by a lawyer in Lebanon when you can.)
$base = __DIR__ . '/../admin_panel_v1.3.1';
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\LanguageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (DB::table('settings')->where('variable', 'lebazone_policies_v1')->value('value') === '1') return;

$contact = <<<'H'
<h3>Contact us</h3>
<p>Email: <a href="mailto:lb.lebazone@gmail.com">lb.lebazone@gmail.com</a><br>WhatsApp: <a href="https://wa.me/96181976696">+961 81 976 696</a><br>Website: lebazone.store</p>
H;
$updated = '<p><i>Last updated: 12 November 2026</i></p>';

$p = [];

$p['privacy_policy'] = $updated . <<<'H'
<p>Lebazone ("we", "us") runs the online shop at lebazone.store and the Lebazone app in Lebanon. This policy explains what information we collect, why, and how we protect it.</p>
<h3>1. Information we collect</h3>
<ul>
<li><b>Account details:</b> your name, email address, phone number (if you add one) and password. If you sign in with Google, we receive your name and email from Google.</li>
<li><b>Delivery details:</b> the addresses you save and their location on the map, so our riders can find you.</li>
<li><b>Order details:</b> what you buy, your order history, cancellations, returns and wallet balance.</li>
<li><b>Payment details:</b> online payments (such as Whish) are handled by the payment provider. We never see or store your card or account password.</li>
<li><b>Device and usage information:</b> device type, app version, notification token, the products you view and cookies on our website.</li>
</ul>
<h3>2. How we use it</h3>
<ul>
<li>To process, deliver and support your orders, returns and refunds.</li>
<li>To send order updates and invoices by email, app notification, SMS or WhatsApp.</li>
<li>To show you products you may like and remember your cart and recently viewed items.</li>
<li>To send offers only if you agree; you can stop them at any time.</li>
<li>To prevent fraud, keep the shop secure and meet legal and accounting obligations.</li>
</ul>
<h3>3. Who we share it with</h3>
<p>Only what is needed: our delivery riders (your name, phone and address), the seller of a product you buy from a partner seller on Lebazone (to prepare your order), payment providers, and the services that run our shop (hosting, email, notifications and maps). We do <b>not</b> sell your personal information.</p>
<h3>4. How long we keep it</h3>
<p>While your account is active, and afterwards only as long as needed for orders, returns, accounting and the law. Then we delete it or make it anonymous.</p>
<h3>5. Your choices</h3>
<p>You can view and update your details in your account, delete saved addresses, and delete your account at any time from the app or website, or by contacting us. Some invoice records may be kept where the law requires.</p>
<h3>6. Security</h3>
<p>Your password is stored encrypted and all connections to our shop are encrypted (HTTPS). No system is 100% secure, so please use a strong password and keep it private.</p>
<h3>7. Cookies</h3>
<p>Our website uses cookies to keep you signed in, remember your cart and understand how the site is used. You can control cookies in your browser.</p>
<h3>8. Children</h3>
<p>Lebazone is meant for adults (18+). Accounts must be opened by an adult.</p>
<h3>9. Changes</h3>
<p>We may update this policy. The latest version is always on our website and app.</p>
H . $contact;

$p['shipping_policy'] = $updated . <<<'H'
<h3>Where we deliver</h3>
<p>We deliver across <b>Lebanon</b>. When you add an address, the map shows whether we deliver there. We do not ship outside Lebanon at the moment.</p>
<h3>Delivery time</h3>
<p>Most orders arrive within <b>1 to 3 working days</b>. Remote areas, public holidays, bad weather or road closures can add time. The estimated delivery date is shown at checkout and in your order.</p>
<h3>Delivery fee</h3>
<p>The delivery fee is shown at checkout before you pay. Free delivery applies when your order reaches the free-delivery amount shown in the cart.</p>
<h3>Receiving your order</h3>
<ul>
<li>Our rider will call or WhatsApp you before arriving. Please keep your phone on and make sure someone is at the address.</li>
<li>For cash on delivery, please have the exact amount ready (US dollars; the Lebanese pound equivalent is shown for reference).</li>
<li>Please check the package in front of the rider. If it is damaged or something is missing, tell the rider and contact us the same day.</li>
<li>If we cannot reach you after two delivery attempts, the order may be cancelled.</li>
</ul>
<h3>Partner sellers</h3>
<p>Some products are sold by partner sellers on Lebazone ("Sold by" on the product page). Lebazone still handles the order, delivery and customer service for these products.</p>
H . $contact;

$p['return_policy'] = $updated . <<<'H'
<h3>Change of mind - 7 days</h3>
<p>You can return most products within <b>7 days</b> of delivery if they are <b>unused, in the original packaging, with all accessories, manuals and gifts</b>. Some products cannot be returned; this is shown on the product page.</p>
<h3>Faulty, damaged or wrong item</h3>
<p>If your item arrives damaged, faulty or is not what you ordered, contact us <b>within 3 days</b> of delivery with photos or a short video. We will replace it or refund you, and we cover the delivery cost.</p>
<h3>Items we cannot accept back</h3>
<ul>
<li>Items that have been used, installed, damaged by misuse, or are missing parts or packaging.</li>
<li>Hygiene and personal-care items once opened (for example trimmers and shavers).</li>
<li>Items marked "non-returnable" on the product page, and clearance or final-sale items.</li>
</ul>
<h3>How to return</h3>
<ol>
<li>Open <b>My Orders</b>, choose the item and tap <b>Return</b> - or contact us on WhatsApp.</li>
<li>We confirm the return and arrange a pickup, or you can drop it off.</li>
<li>We inspect the item when it reaches us (usually within 2 working days).</li>
</ol>
<h3>Refunds</h3>
<ul>
<li>Approved refunds go to your <b>Lebazone wallet</b> right away, to use on your next order.</li>
<li>If you prefer, we can refund cash on delivery orders in cash or by Whish transfer, and online payments back to the original method, within 7 working days.</li>
<li>For change-of-mind returns, the original delivery fee and the return pickup fee are not refunded.</li>
</ul>
<h3>Warranty</h3>
<p>Products with a warranty show it on the product page. Warranty covers manufacturing faults, not misuse, water or drop damage. Contact us with your order number to make a claim.</p>
H . $contact;

$p['cancellation_policy'] = $updated . <<<'H'
<h3>Cancelling your order</h3>
<p>You can cancel an order from <b>My Orders</b> for free <b>until it is packed and handed to delivery</b>. After that the cancel button disappears - please contact us instead, and if the order is already on its way you can refuse it or return it under our Return Policy.</p>
<h3>Refunds for cancelled orders</h3>
<ul>
<li><b>Cash on delivery:</b> nothing to refund, as you have not paid.</li>
<li><b>Paid online or with wallet balance:</b> the full amount goes back to your Lebazone wallet right away, or to your original payment method within 7 working days if you ask us.</li>
</ul>
<h3>When Lebazone may cancel</h3>
<p>We may cancel an order (fully or partly) if an item is out of stock, the price or details shown were clearly wrong, the address is outside our delivery area, we cannot reach you after two delivery attempts, or we suspect fraud. We will tell you and refund anything you paid.</p>
<h3>Repeated refused deliveries</h3>
<p>If cash on delivery orders are refused several times, we may ask for online payment on your future orders.</p>
H . $contact;

$p['terms_conditions'] = $updated . <<<'H'
<p>Welcome to Lebazone. By using lebazone.store or the Lebazone app and placing orders, you agree to these terms.</p>
<h3>1. Your account</h3>
<p>You must be 18 or older and give true details. Keep your password private; you are responsible for orders placed from your account. We may suspend accounts that misuse the shop or break these terms.</p>
<h3>2. Products and prices</h3>
<ul>
<li>Prices are in US dollars (USD). The Lebanese pound amount is shown for reference only and may change with the exchange rate.</li>
<li>We try to show products, photos and descriptions accurately; small differences in colour or packaging can happen.</li>
<li>Prices and offers can change at any time, but the price you see at checkout is the price you pay for that order.</li>
<li>If a price or product detail is clearly wrong, we may cancel the order and refund you.</li>
</ul>
<h3>3. Orders</h3>
<p>Your order is confirmed when you receive the order confirmation. Stock is reserved for you once the order is placed. We may limit quantities per customer.</p>
<h3>4. Payment</h3>
<p>We accept cash on delivery and the online payment methods shown at checkout. Wallet balance and promo codes can be used where shown; promo codes have their own conditions and cannot be exchanged for cash.</p>
<h3>5. Delivery, returns and cancellations</h3>
<p>See our Shipping Policy, Return Policy and Cancellation Policy, which are part of these terms.</p>
<h3>6. Partner sellers</h3>
<p>Some products are supplied by partner sellers and marked "Sold by". Lebazone handles the sale, delivery, returns and customer service for these orders under the same policies.</p>
<h3>7. Reviews and content</h3>
<p>Reviews and photos you post must be honest and respectful. We may remove content that is false, offensive or unrelated.</p>
<h3>8. Use of the shop</h3>
<p>Do not misuse the website or app, try to access other accounts, copy our content or use automated tools to collect data.</p>
<h3>9. Liability</h3>
<p>Lebazone is not responsible for indirect losses or for delays caused by events outside our control (such as power or internet outages, strikes, weather, or security conditions). Our responsibility for any order is limited to the amount you paid for it.</p>
<h3>10. Law</h3>
<p>These terms are governed by the laws of Lebanon. If a problem cannot be solved with our customer service, the courts of Beirut have jurisdiction.</p>
<h3>11. Changes</h3>
<p>We may update these terms. The version on our website at the time of your order applies to that order.</p>
H . $contact;

$c = DB::table('countries')->whereRaw('UPPER(code) = ?', ['LB'])->first();
if (!$c) { echo "Policies: Lebanon missing - skipped.\n"; return; }

$isPlaceholder = function ($v, $field) {
    $t = trim(strip_tags((string) $v));
    return $t === '' || strcasecmp($t, ucwords(str_replace('_', ' ', $field))) === 0
        || strcasecmp(str_replace(['&', ' and '], ['', ''], $t), str_replace(['&', ' and '], ['', ''], ucwords(str_replace('_', ' ', $field)))) === 0;
};

$filled = [];
$upd = [];
foreach ($p as $field => $html) {
    if (property_exists($c, $field) && $isPlaceholder($c->$field, $field)) { $upd[$field] = $html; $filled[$field] = 1; }
}
if ($upd) DB::table('countries')->where('id', $c->id)->update($upd);

// the policy pages read the language copy first - fill the default language too
if (Schema::hasTable('country_translations')) {
    $lang = app(LanguageService::class)->getDefaultLanguage();
    $lid = $lang ? (int) $lang->id : 0;
    if ($lid) {
        $row = DB::table('country_translations')->where('country_id', $c->id)->where('language_id', $lid)->first();
        $tu = [];
        foreach ($p as $field => $html) {
            if (!Schema::hasColumn('country_translations', $field)) continue;
            if (!$row || $isPlaceholder($row->$field ?? '', $field)) { $tu[$field] = $html; $filled[$field] = 1; }
        }
        if ($tu) {
            if ($row) DB::table('country_translations')->where('id', $row->id)->update($tu + ['updated_at' => now()]);
            else DB::table('country_translations')->insert($tu + ['country_id' => $c->id, 'language_id' => $lid, 'name' => $c->name ?? 'Lebanon', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}

DB::table('settings')->updateOrInsert(['variable' => 'lebazone_policies_v1'], ['value' => '1']);
\Illuminate\Support\Facades\Artisan::call('optimize:clear');
try { \Illuminate\Support\Facades\Artisan::call('config:cache'); } catch (\Throwable $e) {}
echo 'Policies: filled ' . count($filled) . ' (' . implode(', ', array_keys($filled)) . ') - fields you had already written were kept.' . "\n";

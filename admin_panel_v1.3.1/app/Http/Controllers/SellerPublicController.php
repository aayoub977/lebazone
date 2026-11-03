<?php

namespace App\Http\Controllers;

use App\Helpers\CommonHelper;
use App\Models\Product;
use App\Models\Seller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Custom (Hader): "Sell on Hader" applications + the seller's private statement page. */
class SellerPublicController extends Controller
{
    public function apply(Request $request)
    {
        $key = 'seller-apply:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return CommonHelper::responseError('Too many attempts. Try again later.');
        }
        RateLimiter::hit($key, 3600);

        $v = Validator::make($request->all(), [
            'business_name' => 'required|string|max:191',
            'contact_name'  => 'required|string|max:191',
            'phone'         => 'required|string|max:40',
            'email'         => 'nullable|email|max:191',
            'city'          => 'nullable|string|max:100',
            'category'      => 'nullable|string|max:100',
            'message'       => 'nullable|string|max:2000',
        ]);
        if ($v->fails()) {
            return CommonHelper::responseError($v->errors()->first());
        }
        Seller::create($request->only(['business_name', 'contact_name', 'phone', 'email', 'city', 'category', 'message'])
            + ['status' => 'pending', 'statement_token' => (string) Str::uuid(),
               'commission_percent' => (float) (Setting::get_value('seller_default_commission') ?: 15)]);
        return CommonHelper::responseSuccess('Application received');
    }

    public function statement(string $token)
    {
        $s = Seller::where('statement_token', $token)->firstOrFail();
        abort_if(in_array($s->status, ['pending', 'rejected'], true), 404);
        $rows = DB::table('seller_ledger')->where('seller_id', $s->id)->orderByDesc('id')->limit(200)->get();
        $products = Product::where('seller_id', $s->id)->with('translations')->get(['id', 'name']);
        $stock = DB::table('product_variants as pv')
            ->join('product_variant_store_stocks as st', 'st.product_variant_id', '=', 'pv.id')
            ->whereIn('pv.product_id', $products->pluck('id'))
            ->groupBy('pv.product_id')
            ->selectRaw('pv.product_id, SUM(st.available) as qty, MAX(st.is_unlimited_stock) as unlimited')
            ->get()->keyBy('product_id');
        $app = e((string) (Setting::get_value('app_name') ?: 'Hader'));
        $name = e($s->business_name);
        $bal = number_format($s->balance(), 2);
        $sold = number_format((float) $rows->where('type', 'sale')->sum('gross'), 2);

        $ledgerHtml = $rows->map(fn ($r) => '<tr><td>' . e(substr((string) $r->created_at, 0, 10)) . '</td><td>' . e(ucfirst($r->type))
            . '</td><td>' . e((string) $r->note) . '</td><td class="n">' . ($r->type === 'sale' ? number_format($r->gross, 2) : '')
            . '</td><td class="n">' . ($r->type === 'sale' ? '−' . number_format($r->commission, 2) : '')
            . '</td><td class="n ' . ($r->net < 0 ? 'neg' : '') . '">' . number_format($r->net, 2) . '</td></tr>')->implode('');
        $stockHtml = $products->map(function ($p) use ($stock) {
            $st = $stock[$p->id] ?? null;
            $q = $st ? ((int) $st->unlimited ? '∞' : (int) $st->qty) : 0;
            return '<tr><td>' . e((string) $p->name) . '</td><td class="n">' . $q . '</td></tr>';
        })->implode('');

        return response(<<<HTML
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>{$name} – {$app} seller statement</title>
<style>body{font-family:system-ui,sans-serif;margin:0;background:#f1f5f9;color:#0f172a}.w{max-width:960px;margin:0 auto;padding:20px}
.h{background:#0369A1;color:#fff;padding:22px 20px}.h h1{margin:0;font-size:22px}.cards{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}
.c{background:#fff;border-radius:12px;padding:14px 18px;flex:1;min-width:180px}.c b{display:block;font-size:22px;margin-top:4px}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;margin-bottom:18px}th,td{padding:9px 12px;border-bottom:1px solid #e2e8f0;text-align:left;font-size:14px}
th{background:#f8fafc}.n{text-align:right}.neg{color:#b91c1c}h2{font-size:16px;margin:18px 0 8px}</style></head>
<body><div class="h"><div class="w" style="padding:0"><h1>{$name}</h1><div>{$app} seller statement · keep this link private</div></div></div>
<div class="w"><div class="cards"><div class="c">Balance owed to you<b>{$bal}</b></div><div class="c">Total sales credited<b>{$sold}</b></div></div>
<h2>Activity</h2><table><tr><th>Date</th><th>Type</th><th>Details</th><th class="n">Sale</th><th class="n">Commission</th><th class="n">Net</th></tr>{$ledgerHtml}</table>
<h2>Your products in stock</h2><table><tr><th>Product</th><th class="n">Units in stock</th></tr>{$stockHtml}</table>
<p style="color:#64748b;font-size:13px">Sales are credited once an item is delivered and its return window has closed.</p></div></body></html>
HTML)->header('Content-Type', 'text/html');
    }
}

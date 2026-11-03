<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Custom (Hader): admin management of consignment sellers. */
class SellersApiController extends Controller
{
    public function index(Request $request)
    {
        $sellers = Seller::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByRaw("FIELD(status,'pending','active','suspended','rejected')")->orderByDesc('id')->get();
        $balances = DB::table('seller_ledger')->groupBy('seller_id')->selectRaw('seller_id, SUM(net) as bal')->pluck('bal', 'seller_id');
        $counts = Product::whereNotNull('seller_id')->groupBy('seller_id')->selectRaw('seller_id, COUNT(*) as c')->pluck('c', 'seller_id');
        $sellers->each(function ($s) use ($balances, $counts) {
            $s->balance = round((float) ($balances[$s->id] ?? 0), 2);
            $s->products_count = (int) ($counts[$s->id] ?? 0);
            $s->statement_url = url('seller-statement/' . $s->statement_token);
        });
        return CommonHelper::responseWithData($sellers);
    }

    public function save(Request $request)
    {
        $v = Validator::make($request->all(), [
            'business_name'      => 'required|string|max:191',
            'contact_name'       => 'required|string|max:191',
            'phone'              => 'required|string|max:40',
            'email'              => 'nullable|email',
            'commission_percent' => 'required|numeric|min:0|max:100',
            'status'             => 'required|in:pending,active,rejected,suspended',
        ]);
        if ($v->fails()) {
            return CommonHelper::responseError($v->errors()->first());
        }
        $seller = $request->filled('id') ? Seller::find($request->id) : new Seller(['statement_token' => (string) Str::uuid()]);
        if (!$seller) {
            return CommonHelper::responseError('Seller not found');
        }
        $seller->fill($request->only(['business_name', 'contact_name', 'phone', 'email', 'city', 'category',
            'commission_percent', 'status', 'payout_details', 'admin_notes']));
        $seller->save();
        return CommonHelper::responseSuccess('Seller saved');
    }

    public function ledger($id)
    {
        $seller = Seller::find($id);
        if (!$seller) {
            return CommonHelper::responseError('Seller not found');
        }
        $rows = DB::table('seller_ledger')->where('seller_id', $id)->orderByDesc('id')->limit(300)->get();
        $products = Product::where('seller_id', $id)->with('translations')->get(['id', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => (string) $p->name]);
        return CommonHelper::responseWithData([
            'balance'  => round($seller->balance(), 2),
            'ledger'   => $rows,
            'products' => $products,
        ]);
    }

    public function payout(Request $request)
    {
        $v = Validator::make($request->all(), [
            'seller_id' => 'required|exists:sellers,id',
            'amount'    => 'required|numeric|min:0.01',
            'note'      => 'nullable|string|max:180',
        ]);
        if ($v->fails()) {
            return CommonHelper::responseError($v->errors()->first());
        }
        DB::table('seller_ledger')->insert([
            'seller_id' => $request->seller_id, 'type' => 'payout', 'net' => -round((float) $request->amount, 2),
            'note' => $request->input('note') ?: 'Payout', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return CommonHelper::responseSuccess('Payout recorded');
    }

    /** Link (or unlink with seller_id = 0) products to a seller. */
    public function assignProducts(Request $request)
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->input('product_ids')))));
        if (!$ids) {
            return CommonHelper::responseError('Select at least one product');
        }
        $sellerId = (int) $request->input('seller_id');
        if ($sellerId && !Seller::whereKey($sellerId)->exists()) {
            return CommonHelper::responseError('Seller not found');
        }
        Product::whereIn('id', $ids)->update(['seller_id' => $sellerId ?: null]);
        return CommonHelper::responseSuccess($sellerId ? 'Products linked to seller' : 'Products unlinked');
    }

    /** Product picker search. */
    public function searchProducts(Request $request)
    {
        $q = trim((string) $request->input('search', ''));
        $rows = Product::query()->with('translations')
            ->when($q !== '', fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('name', 'like', "%{$q}%")
                  ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$q}%"));
            }))
            ->orderByDesc('id')->limit(30)->get(['id', 'name', 'seller_id'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => (string) $p->name, 'seller_id' => $p->seller_id]);
        return CommonHelper::responseWithData($rows);
    }
}

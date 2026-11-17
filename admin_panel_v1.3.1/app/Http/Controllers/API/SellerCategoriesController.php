<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Helpers\SellerScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Custom (Lebazone): a seller's OWN categories, shown on their store page.
 * A seller manages theirs from "My shop"; Lebazone staff pass seller_id.
 */
class SellerCategoriesController extends Controller
{
    private function sellerId(Request $request): ?int
    {
        $sid = SellerScope::id();
        if ($sid !== null) return $sid > 0 ? $sid : null;
        return $request->filled('seller_id') ? (int) $request->input('seller_id') : null;
    }

    public function list(Request $request)
    {
        $sid = $this->sellerId($request);
        if (!$sid) return CommonHelper::responseWithData([]);
        $rows = DB::table('seller_categories')->where('seller_id', $sid)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'sort_order']);
        $counts = DB::table('products')->whereNull('deleted_at')->where('seller_id', $sid)->whereNotNull('seller_category_id')
            ->groupBy('seller_category_id')->selectRaw('seller_category_id, COUNT(*) n')->pluck('n', 'seller_category_id');
        return CommonHelper::responseWithData($rows->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name,
            'sort_order' => (int) $r->sort_order, 'products' => (int) ($counts[$r->id] ?? 0)])->values());
    }

    public function save(Request $request)
    {
        $sid = $this->sellerId($request);
        if (!$sid) return CommonHelper::responseError('Seller not found');
        $name = trim((string) $request->input('name', ''));
        if ($name === '' || mb_strlen($name) > 100) return CommonHelper::responseError('Type a category name (up to 100 characters)');
        $id = (int) $request->input('id', 0);
        $dup = DB::table('seller_categories')->where('seller_id', $sid)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
        if ($dup) return CommonHelper::responseError('You already have a category with this name');
        if ($id) {
            $n = DB::table('seller_categories')->where('id', $id)->where('seller_id', $sid)->update(['name' => $name, 'updated_at' => now()]);
            if (!$n && !DB::table('seller_categories')->where('id', $id)->where('seller_id', $sid)->exists()) return CommonHelper::responseError('Category not found');
        } else {
            $id = DB::table('seller_categories')->insertGetId(['seller_id' => $sid, 'name' => $name,
                'sort_order' => (int) DB::table('seller_categories')->where('seller_id', $sid)->max('sort_order') + 1,
                'created_at' => now(), 'updated_at' => now()]);
        }
        return CommonHelper::responseSuccessWithData('Saved', ['id' => (int) $id, 'name' => $name]);
    }

    public function delete(Request $request)
    {
        $sid = $this->sellerId($request);
        $id = (int) $request->input('id');
        if (!$sid || !DB::table('seller_categories')->where('id', $id)->where('seller_id', $sid)->exists()) {
            return CommonHelper::responseError('Category not found');
        }
        DB::table('products')->where('seller_id', $sid)->where('seller_category_id', $id)->update(['seller_category_id' => null]);
        DB::table('seller_categories')->where('id', $id)->delete();
        return CommonHelper::responseSuccess('Deleted');
    }
}

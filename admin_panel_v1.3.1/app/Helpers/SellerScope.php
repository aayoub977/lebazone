<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * Custom (Lebazone): who is asking - a seller login or Lebazone staff.
 *   id()  null = Lebazone staff, otherwise the seller's id (-1 = Seller login not linked to a seller)
 */
class SellerScope
{
    private static $memo = false;

    public static function id(): ?int
    {
        if (self::$memo !== false) return self::$memo;
        $u = null;
        try { $u = auth('api')->user(); } catch (\Throwable $e) {}
        if (!$u || !$u->role || $u->role->name !== 'Seller') return self::$memo = null;
        return self::$memo = ((int) DB::table('sellers')->where('admin_id', $u->id)->value('id') ?: -1);
    }

    /**
     * A hidden holding category for seller products that don't have a Lebazone category yet.
     * Lebazone picks the real category when approving. Never shown on the shop (status 0).
     */
    public static function unsortedCategoryId(): int
    {
        $id = (int) DB::table('categories')->where('slug', 'lbz-seller-unsorted')->value('id');
        if ($id) return $id;
        return (int) DB::table('categories')->insertGetId([
            'name' => 'Seller products - to sort (hidden)', 'slug' => 'lbz-seller-unsorted', 'image' => '',
            'status' => 0, 'parent_id' => 0, 'row_order' => 9999, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** True when the product still needs a real Lebazone category (none, the holding one, or a hidden one). */
    public static function needsCategory(?int $categoryId): bool
    {
        if (!$categoryId) return true;
        $c = DB::table('categories')->where('id', $categoryId)->first(['slug', 'status']);
        return !$c || $c->slug === 'lbz-seller-unsorted' || (int) $c->status !== 1;
    }

    public static function isSeller(): bool
    {
        return self::id() !== null;
    }
}

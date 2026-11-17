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

    public static function isSeller(): bool
    {
        return self::id() !== null;
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;

/**
 * Custom: stores that switch quick-commerce off (setting quick_commerce_enabled = 0)
 * serve everything on the ecommerce channel. Apps/website that still send
 * `channel: quick` (their built-in default) are quietly treated as ecommerce, so
 * zone lookups, carts, prices and orders all resolve the same way.
 */
class ForceEcommerceChannel
{
    private static ?bool $quickOff = null;

    public function handle(Request $request, Closure $next)
    {
        if (self::$quickOff === null) {
            try {
                self::$quickOff = (string) Setting::get_value('quick_commerce_enabled') === '0';
            } catch (\Throwable $e) {
                self::$quickOff = false; // no DB yet (installer) — leave requests alone
            }
        }
        if (self::$quickOff && $request->headers->get('channel') !== 'ecommerce') {
            $request->headers->set('channel', 'ecommerce');
        }
        return $next($request);
    }
}

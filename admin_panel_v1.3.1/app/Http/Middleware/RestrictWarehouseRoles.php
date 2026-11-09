<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Custom (Lebazone): employee accounts (Packer / Product entry) may only use their own screens.
 * The panel's API has no per-route permission checks, so this keeps them out of everything else
 * (settings, customers, money, users...) and stops a disabled employee immediately.
 */
class RestrictWarehouseRoles
{
    public const ROLES = ['Packer', 'Product entry', 'Packer + products', 'Order Station', 'Seller'];

    /** Reads the panel shell needs for anyone who is logged in. */
    private const SHELL_GET = ['api/change_language', 'api/chat/unread_count', 'api/chat/poll', 'api/get_top_notifications', 'api/notification_read',
        'api/system_languages', 'api/store_settings', 'api/setup_guide', 'api/role', 'api/media', 'api/passkey/*'];
    private const SHELL_POST = ['api/update_fcm_token', 'api/logout', 'api/broadcasting/auth', 'api/system_users/change_password', 'api/change_language', 'api/passkey/*'];

    public function handle(Request $request, Closure $next)
    {
        if (!$request->is('api/*') || !$request->bearerToken()) return $next($request);
        try { $user = auth('api')->user(); } catch (\Throwable $e) { return $next($request); }
        $role = $user && $user->role ? $user->role->name : null;
        if (!$role || !in_array($role, self::ROLES, true)) return $next($request);

        if ((int) ($user->status ?? 1) !== 1) {
            return response()->json(['status' => 0, 'message' => 'Your account is disabled - ask the manager'], 401);
        }
        $perms = $user->allPermissions;
        $pack = in_array('warehouse_pack', $perms, true);
        $station = in_array('warehouse_station', $perms, true);
        $products = in_array('product_scan', $perms, true);
        $seller = in_array('seller_portal', $perms, true); // Custom (Lebazone): sellers - ScanApiController limits them to their own products
        $read = $request->isMethod('GET') || $request->isMethod('HEAD');

        $ok = false;
        if ($read) {
            $ok = $request->is(...self::SHELL_GET)
                || ($pack && $request->is('api/warehouse/board', 'api/warehouse/order', 'api/warehouse/queue', 'api/warehouse/me'))
                || ($products && $request->is('api/scan/*'))
                || ($station && $request->is('api/station/*'))
                || ($seller && $request->is('api/seller_portal/*'))
                || $request->is('api/warehouse/me');
        } else {
            $ok = $request->is(...self::SHELL_POST)
                || ($pack && $request->is('api/warehouse/scan', 'api/warehouse/pack', 'api/warehouse/reopen', 'api/warehouse/print'))
                || ($station && $request->is('api/station/*', 'api/warehouse/print'))
                || ($products && $request->is('api/scan/*'));
        }
        if (!$ok) return response()->json(['status' => 0, 'message' => 'Not allowed for your account'], 403);
        return $next($request);
    }
}

<?php

namespace App\Http\Controllers\API\Customer;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Custom (Hader): customer loyalty points balance, history and redemption. */
class LoyaltyApiController extends Controller
{
    public function index(Request $request, LoyaltyService $service)
    {
        $user = $request->user('api-customers');
        $cfg = $service->config();
        $points = (int) ($user->loyalty_points ?? 0);
        $history = DB::table('loyalty_point_transactions')->where('user_id', $user->id)
            ->orderByDesc('id')->limit(50)
            ->get(['id', 'order_id', 'points', 'type', 'wallet_amount', 'note', 'created_at']);

        return CommonHelper::responseWithData([
            'enabled'         => $cfg['enabled'],
            'points'          => $points,
            'point_value'     => $cfg['point_value'],
            'points_per_unit' => $cfg['points_per_unit'],
            'min_redeem'      => $cfg['min_redeem'],
            'wallet_value'    => round($points * $cfg['point_value'], 2),
            'history'         => $history,
        ]);
    }

    public function redeem(Request $request, LoyaltyService $service)
    {
        $user = $request->user('api-customers');
        $points = (int) $request->input('points', $user->loyalty_points ?? 0);
        [$ok, $message, $amount] = $service->redeem($user, $points);
        if (!$ok) {
            return CommonHelper::responseError($message);
        }
        $user->refresh();
        return CommonHelper::responseSuccessWithData($message, [
            'wallet_amount' => $amount,
            'points'        => (int) $user->loyalty_points,
            'balance'       => (float) $user->balance,
        ]);
    }
}

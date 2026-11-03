<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Custom (Hader): admin loyalty programme settings + stats. */
class LoyaltySettingsApiController extends Controller
{
    private const KEYS = ['loyalty_enabled', 'loyalty_points_per_unit', 'loyalty_point_value', 'loyalty_min_redeem_points'];

    public function index()
    {
        $data = [];
        foreach (self::KEYS as $k) {
            $data[$k] = Setting::get_value($k);
        }
        $data['stats'] = [
            'outstanding_points' => (int) DB::table('users')->sum('loyalty_points'),
            'members'            => (int) DB::table('users')->where('loyalty_points', '>', 0)->count(),
            'redeemed_value'     => (float) DB::table('loyalty_point_transactions')->where('type', 'redeem')->sum('wallet_amount'),
        ];
        return CommonHelper::responseWithData($data);
    }

    public function save(Request $request)
    {
        $v = Validator::make($request->all(), [
            'loyalty_enabled'           => 'required|in:0,1',
            'loyalty_points_per_unit'   => 'required|numeric|min:0',
            'loyalty_point_value'       => 'required|numeric|min:0',
            'loyalty_min_redeem_points' => 'required|integer|min:1',
        ]);
        if ($v->fails()) {
            return CommonHelper::responseError($v->errors()->first());
        }
        foreach (self::KEYS as $k) {
            Setting::updateOrCreate(['variable' => $k], ['value' => (string) $request->input($k)]);
        }
        return CommonHelper::responseSuccess('Loyalty settings saved');
    }
}

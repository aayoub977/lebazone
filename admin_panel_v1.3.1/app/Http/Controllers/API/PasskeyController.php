<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Custom (Lebazone): sign in with Face ID / fingerprint / Windows Hello (WebAuthn passkeys).
 * Works for every panel account (super admin, admins, packers, product entry, drivers).
 * The phone keeps the private key behind its biometrics; we only store the public key.
 * Verification uses lbuchs/WebAuthn (MIT), vendored in app/Libraries/WebAuthn.
 */
class PasskeyController extends Controller
{
    private static bool $loaded = false;

    private static function boot(): void
    {
        if (self::$loaded) return;
        spl_autoload_register(function ($class) {
            $prefix = 'lbuchs\\WebAuthn\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
            $file = app_path('Libraries/WebAuthn/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php');
            if (is_file($file)) require_once $file;
        });
        self::$loaded = true;
    }

    private function webauthn(Request $request): \lbuchs\WebAuthn\WebAuthn
    {
        self::boot();
        $name = \App\Models\Setting::get_value('app_name') ?: 'Lebazone';
        // Only "none" attestation: no device make/model prompt, nothing to verify against vendor roots.
        return new \lbuchs\WebAuthn\WebAuthn($name, $request->getHost(), ['none'], true);
    }

    private static function b64uDecode(?string $s): string
    {
        $s = strtr((string) $s, '-_', '+/');
        return (string) base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    private static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function available(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('admin_passkeys');
    }

    // ------------------------------------------------------------ turn on (logged in)

    public function registerOptions(Request $request)
    {
        if (!self::available()) return CommonHelper::responseError('Run apply-updates.bat first');
        $u = auth()->user();
        $wa = $this->webauthn($request);
        $exclude = DB::table('admin_passkeys')->where('admin_id', $u->id)->pluck('credential_id')
            ->map(fn ($c) => self::b64uDecode($c))->all();
        // Resident key + user verification + this device's own authenticator (Face ID, fingerprint, Windows Hello).
        $args = $wa->getCreateArgs('lbz-' . $u->id, (string) $u->email, (string) ($u->username ?: $u->email), 90, true, true, false, $exclude);
        $key = Str::random(40);
        Cache::put('passkey:reg:' . $key, ['c' => self::b64uEncode($wa->getChallenge()->getBinaryString()), 'u' => $u->id], 300);
        return CommonHelper::responseWithData(['key' => $key, 'options' => $args]);
    }

    public function register(Request $request)
    {
        $u = auth()->user();
        $state = Cache::pull('passkey:reg:' . $request->input('key'));
        if (!$state || (int) $state['u'] !== (int) $u->id) return CommonHelper::responseError('Took too long - please try again');
        try {
            $data = $this->webauthn($request)->processCreate(
                self::b64uDecode($request->input('clientDataJSON')),
                self::b64uDecode($request->input('attestationObject')),
                self::b64uDecode($state['c']),
                true, true, false
            );
        } catch (\Throwable $e) {
            return CommonHelper::responseError('Could not turn on Face ID: ' . $e->getMessage());
        }
        $credId = self::b64uEncode($data->credentialId);
        DB::table('admin_passkeys')->updateOrInsert(['credential_id' => $credId], [
            'admin_id' => $u->id, 'public_key' => $data->credentialPublicKey, 'sign_count' => (int) $data->signatureCounter,
            'device_name' => mb_substr(trim((string) $request->input('device_name', 'This device')), 0, 100),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return CommonHelper::responseSuccess('Face ID / fingerprint sign-in is on for this device');
    }

    public function list()
    {
        if (!self::available()) return CommonHelper::responseWithData([]);
        return CommonHelper::responseWithData(DB::table('admin_passkeys')->where('admin_id', auth()->id())
            ->orderByDesc('id')->get(['id', 'device_name', 'created_at', 'last_used_at']));
    }

    public function delete(Request $request)
    {
        DB::table('admin_passkeys')->where('admin_id', auth()->id())->where('id', (int) $request->input('id'))->delete();
        return CommonHelper::responseSuccess('Removed');
    }

    // ------------------------------------------------------------ sign in (public)

    public function loginOptions(Request $request)
    {
        if (!self::available()) return CommonHelper::responseError('Face ID sign-in is not set up yet');
        $wa = $this->webauthn($request);
        // No account list: the phone offers the passkeys it holds for this site (usernameless sign-in).
        $args = $wa->getGetArgs([], 90, false, false, false, false, true, true);
        $key = Str::random(40);
        Cache::put('passkey:login:' . $key, self::b64uEncode($wa->getChallenge()->getBinaryString()), 300);
        return CommonHelper::responseWithData(['key' => $key, 'options' => $args]);
    }

    public function login(Request $request)
    {
        if (!self::available()) return CommonHelper::responseError('Face ID sign-in is not set up yet');
        $challenge = Cache::pull('passkey:login:' . $request->input('key'));
        if (!$challenge) return CommonHelper::responseError('Took too long - please try again');
        $row = DB::table('admin_passkeys')->where('credential_id', (string) $request->input('id'))->first();
        if (!$row) {
            return CommonHelper::responseError('Face ID is not linked to an account here. Sign in with your password, then turn Face ID on again.');
        }
        $wa = $this->webauthn($request);
        try {
            $wa->processGet(
                self::b64uDecode($request->input('clientDataJSON')),
                self::b64uDecode($request->input('authenticatorData')),
                self::b64uDecode($request->input('signature')),
                $row->public_key,
                self::b64uDecode($challenge),
                (int) $row->sign_count,
                true, true
            );
        } catch (\Throwable $e) {
            return CommonHelper::responseError('Face ID sign-in failed - please use your password');
        }
        $user = Admin::with('deliveryBoy')->find($row->admin_id);
        if (!$user) return CommonHelper::responseError('This account no longer exists');

        // Same panel rules as the password login.
        if ((int) $request->input('type') === 3 && !$user->deliveryBoy) {
            return CommonHelper::responseError('This is not a driver account - use the main login page');
        }

        $counter = 0;
        try { $counter = (int) $wa->getSignatureCounter(); } catch (\Throwable $e) {}
        DB::table('admin_passkeys')->where('id', $row->id)->update(['last_used_at' => now(), 'updated_at' => now()]
            + ($counter > 0 ? ['sign_count' => $counter] : []));

        return app(AdminAuthController::class)->completeLogin($user, $request);
    }
}

<?php

namespace App\Http\Controllers;

use App\Helpers\CommonHelper;
use App\Models\Country;
use App\Models\LocalPaymentSession;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\Payments\AreebaGateway;
use App\Services\Payments\BnplOrderData;
use App\Services\Payments\TabbyGateway;
use App\Services\Payments\TamaraGateway;
use App\Services\Payments\LocalPaymentSettlement;
use App\Services\Payments\WhishGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Custom (Hader): Lebanese payment methods — Whish Money and Areeba (cards).
 * Started from OrderApiController::initiateTransaction (payment_method "Whish" /
 * "Areeba"); customers come back through the routes below.
 */
class LocalPaymentController extends Controller
{
    private const GATEWAYS = [
        'whish'  => ['type' => 'Whish',  'txn' => 'txn_type_whish'],
        'areeba' => ['type' => 'Areeba', 'txn' => 'txn_type_areeba'],
        'tabby'  => ['type' => 'Tabby',  'txn' => 'txn_type_tabby'],
        'tamara' => ['type' => 'Tamara', 'txn' => 'txn_type_tamara'],
    ];

    /**
     * Called by initiateTransaction. Returns ['redirectUrl' => …] or ['error' => message].
     */
    public static function start(string $gateway, Request $request, array $gateways, string $currencyCode, $countryId): array
    {
        $user = auth()->user();
        if (in_array($gateway, ['tabby', 'tamara'], true) && $request->type !== 'order') {
            return ['error' => 'Pay-later is only available for orders.'];
        }
        if ($request->type === 'order') {
            $order = Order::where('id', $request->order_id)->where('user_id', $user->id)->first();
            if (!$order) {
                return ['error' => 'Order not found!'];
            }
            $amount = (float) $order->final_total;
            $customId = (string) $order->id;
            $description = 'Order #' . ($order->order_number ?? $order->id);
        } else {
            $amount = round((float) $request->wallet_amount, 2);
            $customId = 'wallet_recharge-' . $user->id . CommonHelper::rechargeCountrySuffix($countryId);
            $description = 'Wallet top-up';
        }
        if ($amount <= 0) {
            return ['error' => 'Invalid amount'];
        }

        $session = LocalPaymentSession::create([
            'token' => (string) Str::uuid(), 'gateway' => $gateway, 'type' => $request->type,
            'custom_id' => $customId, 'user_id' => $user->id, 'amount' => $amount,
            'currency' => strtoupper($currencyCode ?: 'USD'),
            'request_from' => $request->request_from === 'website' ? 'website' : 'app',
        ]);
        $appName = (string) (Setting::get_value('app_name') ?: 'Hader');

        if ($gateway === 'whish' && ($gateways['whish_mode'] ?? '') === 'demo') {
            // Demo mode: a local stand-in for Whish's page, for testing without keys.
            return ['redirectUrl' => url("local-pay/whish/{$session->token}/demo")];
        }
        if ($gateway === 'whish') {
            $wg = new WhishGateway($gateways);
            if (!$wg->isConfigured()) {
                return ['error' => __('payment_method_not_configured')];
            }
            $url = $wg->create($session, "{$appName} - {$description}");
            return $url ? ['redirectUrl' => $url] : ['error' => 'Error while communicating with Whish'];
        }

        if (in_array($gateway, ['tabby', 'tamara'], true)) {
            $data = new BnplOrderData(Order::find((int) $customId));
            $lang = strtolower((string) $request->header('lang', 'en'));
            if ($gateway === 'tabby') {
                $tg = new TabbyGateway($gateways);
                $r = $tg->isConfigured() ? $tg->create($session, $data, $lang) : ['error' => __('payment_method_not_configured')];
            } else {
                $tm = new TamaraGateway($gateways);
                $cc = (string) (Country::whereKey($countryId)->value('code') ?: 'AE');
                $r = $tm->isConfigured() ? $tm->create($session, $data, $lang, strtoupper($cc)) : ['error' => __('payment_method_not_configured')];
            }
            return isset($r['url']) ? ['redirectUrl' => $r['url']] : ['error' => $r['error']];
        }

        $ag = new AreebaGateway($gateways);
        if (!$ag->isConfigured()) {
            return ['error' => __('payment_method_not_configured')];
        }
        return $ag->create($session, "{$appName} - {$description}", $appName)
            ? ['redirectUrl' => url("local-pay/areeba/{$session->token}/checkout")]
            : ['error' => 'Error while communicating with Areeba'];
    }

    /** Hosted Checkout page for Areeba (loads the gateway's checkout.js). */
    public function areebaCheckout(string $token)
    {
        $s = LocalPaymentSession::where('token', $token)->where('gateway', 'areeba')->firstOrFail();
        $ag = new AreebaGateway($this->gatewaysFor($s));
        $script = e($ag->base() . '/static/checkout/checkout.min.js');
        $sessionId = e((string) $s->gateway_ref);
        $cancel = e(url("local-pay/areeba/{$token}/return?cancelled=1"));
        $title = e((string) (Setting::get_value('app_name') ?: 'Hader'));

        return response(<<<HTML
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} – Secure payment</title>
<script src="{$script}" data-error="hdrError" data-cancel="{$cancel}"></script>
<script>
function hdrError(e){ console.log(e); window.location.href = "{$cancel}"; }
window.addEventListener('load', function () {
  Checkout.configure({ session: { id: "{$sessionId}" } });
  Checkout.showPaymentPage();
});
</script></head>
<body style="font-family:system-ui,sans-serif;text-align:center;padding:48px 16px;color:#334155">
<p>Redirecting to secure card payment…</p></body></html>
HTML)->header('Content-Type', 'text/html');
    }

    public function areebaReturn(Request $request, string $token)
    {
        $s = LocalPaymentSession::where('token', $token)->where('gateway', 'areeba')->firstOrFail();
        if ($s->status === 'pending') {
            [$status, $txnId] = (new AreebaGateway($this->gatewaysFor($s)))
                ->verify($s, $request->filled('cancelled') ? '' : $request->input('resultIndicator'));
            $this->settle($s, $status, $txnId);
        }
        return $this->finish($s->fresh());
    }

    /** Demo mode only: pretend Whish page with Pay / Cancel buttons. */
    public function whishDemo(Request $request, string $token)
    {
        $s = LocalPaymentSession::where('token', $token)->where('gateway', 'whish')->firstOrFail();
        if (($this->gatewaysFor($s)['whish_mode'] ?? '') !== 'demo') {
            abort(404);
        }
        if ($request->filled('result') && $s->status === 'pending') {
            $ok = $request->input('result') === 'pay';
            $this->settle($s, $ok ? 'success' : 'failed', 'WHISH-DEMO-' . $s->id);
            return $this->finish($s->fresh());
        }
        $amount = e(number_format((float) $s->amount, 2) . ' ' . $s->currency);
        $pay = e(url("local-pay/whish/{$token}/demo?result=pay"));
        $cancel = e(url("local-pay/whish/{$token}/demo?result=cancel"));
        return response(<<<HTML
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Whish (demo)</title></head>
<body style="font-family:system-ui,sans-serif;background:#f6f7f9;margin:0;padding:32px 16px;color:#0f172a">
<div style="max-width:380px;margin:auto;background:#fff;border-radius:16px;padding:24px;box-shadow:0 4px 20px #0001;text-align:center">
<div style="display:inline-block;background:#E4002B;color:#fff;font-weight:800;border-radius:10px;padding:6px 14px">whish</div>
<p style="color:#b45309;background:#fff7ed;border-radius:8px;padding:6px;font-size:13px">DEMO MODE - no real money moves</p>
<p style="margin:18px 0 4px;color:#64748b">Amount to pay</p>
<p style="font-size:28px;font-weight:800;margin:0 0 24px">{$amount}</p>
<a href="{$pay}" style="display:block;background:#E4002B;color:#fff;text-decoration:none;font-weight:700;padding:14px;border-radius:12px">Pay with Whish</a>
<a href="{$cancel}" style="display:block;margin-top:10px;color:#64748b;text-decoration:none;padding:10px">Cancel</a>
</div></body></html>
HTML)->header('Content-Type', 'text/html');
    }

    public function whishReturn(string $token)
    {
        $s = LocalPaymentSession::where('token', $token)->where('gateway', 'whish')->firstOrFail();
        $this->checkWhish($s);
        return $this->finish($s->fresh());
    }

    /** Server-to-server notification from Whish (success or failure). */
    public function whishCallback(string $token)
    {
        $s = LocalPaymentSession::where('token', $token)->where('gateway', 'whish')->first();
        if ($s) {
            $this->checkWhish($s);
        }
        return response()->json(['status' => true]);
    }

    /** Tabby / Tamara: customer returns (and Tamara's notification) — always re-verify with the provider. */
    public function bnplReturn(string $gateway, string $token)
    {
        $s = LocalPaymentSession::where('token', $token)->where('gateway', $gateway)->firstOrFail();
        $this->checkBnpl($s);
        return $this->finish($s->fresh());
    }

    public function bnplCallback(string $gateway, string $token)
    {
        $s = LocalPaymentSession::where('token', $token)->where('gateway', $gateway)->first();
        if ($s) {
            $this->checkBnpl($s);
        }
        return response()->json(['status' => true]);
    }

    /** Landing page for the mobile apps (a webview can watch for local-pay/status). */
    public function status(Request $request)
    {
        $ok = $request->input('status') === 'success';
        $title = $ok ? __('payment_successful') : __('payment_failed');
        return response('<html><head><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            . e($title) . '</title></head><body style="font-family:sans-serif;text-align:center;padding:48px 16px"><h3>'
            . e($title) . '</h3></body></html>')->header('Content-Type', 'text/html');
    }

    /** Scheduled: re-check attempts the customer never came back from. */
    public static function reconcilePending(): int
    {
        $n = 0;
        $self = new self();
        LocalPaymentSession::where('status', 'pending')
            ->where('created_at', '<=', now()->subMinutes(3))
            ->where('created_at', '>=', now()->subDays(2))
            ->orderBy('id')->limit(200)->get()
            ->each(function (LocalPaymentSession $s) use ($self, &$n) {
                if ($s->gateway === 'whish') {
                    $self->checkWhish($s);
                } elseif (in_array($s->gateway, ['tabby', 'tamara'], true)) {
                    $self->checkBnpl($s);
                } elseif ($s->gateway_ref) {
                    [$status, $txnId] = (new AreebaGateway($self->gatewaysFor($s)))->verify($s, null);
                    $self->settle($s, $status, $txnId);
                }
                $n += $s->fresh()->status !== 'pending' ? 1 : 0;
            });
        return $n;
    }

    // ------------------------------------------------------------------

    private function checkWhish(LocalPaymentSession $s): void
    {
        if ($s->status !== 'pending') {
            return;
        }
        $status = (new WhishGateway($this->gatewaysFor($s)))->status($s);
        $this->settle($s, $status, 'WHISH-' . $s->id);
    }

    private function checkBnpl(LocalPaymentSession $s): void
    {
        if ($s->status !== 'pending') {
            return;
        }
        $gw = $this->gatewaysFor($s);
        [$status, $txnId] = $s->gateway === 'tabby' ? (new TabbyGateway($gw))->verify($s) : (new TamaraGateway($gw))->verify($s);
        $this->settle($s, $status, $txnId);
    }

    private function settle(LocalPaymentSession $s, string $status, string $txnId): void
    {
        $g = self::GATEWAYS[$s->gateway];
        if ($status === 'success') {
            LocalPaymentSettlement::success($s->custom_id, $txnId ?: strtoupper($s->gateway) . '-' . $s->id, $s->amount, $g['type'], $g['txn']);
            $s->update(['status' => 'success']);
        } elseif ($status === 'failed') {
            LocalPaymentSettlement::failure($s->custom_id, $txnId ?: strtoupper($s->gateway) . '-F-' . $s->id, $s->amount, $g['type'], $g['txn']);
            $s->update(['status' => 'failed']);
        }
        // pending: leave it — Whish's callback or the next return visit settles it.
    }

    private function finish(LocalPaymentSession $s)
    {
        $status = $s->status === 'success' ? 'success' : ($s->status === 'failed' ? 'failed' : 'pending');
        $query = 'status=' . $status . '&type=' . $s->type . '&payment_method=' . self::GATEWAYS[$s->gateway]['type'];
        if ($s->type === 'order' && is_numeric($s->custom_id)) {
            $query .= '&order_id=' . (int) $s->custom_id;
        }
        $website = rtrim((string) Setting::get_value('website_url'), '/');
        if ($s->request_from === 'website' && $website !== '') {
            return redirect()->away($website . '/web-payment-status?' . $query);
        }
        return redirect()->to(url('local-pay/status?' . $query));
    }

    private function gatewaysFor(LocalPaymentSession $s): array
    {
        if (is_numeric($s->custom_id)) {
            $order = Order::find((int) $s->custom_id);
            return $order ? CommonHelper::countryPaymentGateways(CommonHelper::resolveOrderZone($order)?->country) : [];
        }
        $countryId = CommonHelper::parseRechargeCountryId($s->custom_id);
        return $countryId ? CommonHelper::countryPaymentGateways(Country::find($countryId)) : [];
    }
}

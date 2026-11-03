<?php

namespace App\Services\Payments;

use App\Models\LocalPaymentSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Custom (Hader): Areeba card payments (Mastercard Payment Gateway Services,
 * Hosted Checkout). Visa / Mastercard issued in Lebanon and abroad.
 *
 * Flow: INITIATE_CHECKOUT session → our page loads Areeba's checkout.js and shows
 * the hosted payment page → Areeba returns with resultIndicator → we check it
 * matches successIndicator AND read the order back from the API before settling.
 *
 * Gateway keys: areeba_payment_method, areeba_merchant_id, areeba_api_password,
 * areeba_base_url (default https://epayment.areeba.com), areeba_api_version.
 */
class AreebaGateway
{
    public const DEFAULT_BASE = 'https://epayment.areeba.com';
    public const DEFAULT_VERSION = '100';

    public function __construct(private array $gw) {}

    public function isConfigured(): bool
    {
        return ($this->gw['areeba_merchant_id'] ?? '') !== '' && ($this->gw['areeba_api_password'] ?? '') !== '';
    }

    public function base(): string
    {
        return rtrim(trim((string) ($this->gw['areeba_base_url'] ?? '')) ?: self::DEFAULT_BASE, '/');
    }

    private function api(string $path): string
    {
        $v = trim((string) ($this->gw['areeba_api_version'] ?? '')) ?: self::DEFAULT_VERSION;
        $mid = rawurlencode((string) $this->gw['areeba_merchant_id']);
        return $this->base() . "/api/rest/version/{$v}/merchant/{$mid}/" . ltrim($path, '/');
    }

    private function client()
    {
        return Http::timeout(25)->acceptJson()
            ->withBasicAuth('merchant.' . $this->gw['areeba_merchant_id'], (string) $this->gw['areeba_api_password']);
    }

    public static function gatewayOrderId(LocalPaymentSession $s): string
    {
        return 'HDR' . str_pad((string) $s->id, 8, '0', STR_PAD_LEFT);
    }

    /** Creates the checkout session; stores session id + successIndicator. */
    public function create(LocalPaymentSession $s, string $description, string $merchantName): bool
    {
        try {
            $res = $this->client()->post($this->api('session'), [
                'apiOperation' => 'INITIATE_CHECKOUT',
                'interaction'  => [
                    'operation'      => 'PURCHASE',
                    'returnUrl'      => url("local-pay/areeba/{$s->token}/return"),
                    'cancelUrl'      => url("local-pay/areeba/{$s->token}/return?cancelled=1"),
                    'merchant'       => ['name' => $merchantName ?: 'Hader'],
                    'displayControl' => ['billingAddress' => 'HIDE', 'shipping' => 'HIDE'],
                ],
                'order' => [
                    'id'          => self::gatewayOrderId($s),
                    'amount'      => number_format($s->amount, 2, '.', ''),
                    'currency'    => $s->currency,
                    'description' => mb_substr($description, 0, 127),
                ],
            ]);
            $sessionId = $res->json('session.id');
            if ($res->successful() && $sessionId) {
                $s->update(['gateway_ref' => $sessionId, 'success_indicator' => $res->json('successIndicator')]);
                return true;
            }
            Log::warning('Areeba session failed', ['status' => $res->status(), 'body' => $res->json()]);
        } catch (\Throwable $e) {
            Log::error('Areeba session exception: ' . $e->getMessage());
        }
        return false;
    }

    /** 'success' | 'failed' | 'pending', verified against the gateway. Also returns the txn id. */
    public function verify(LocalPaymentSession $s, ?string $resultIndicator): array
    {
        try {
            $res = $this->client()->get($this->api('order/' . self::gatewayOrderId($s)));
            if (!$res->successful()) {
                return ['pending', ''];
            }
            $captured = (float) $res->json('totalCapturedAmount', 0);
            $result = strtoupper((string) $res->json('result'));
            $status = strtoupper((string) $res->json('status'));
            $txnId = (string) ($res->json('transaction.0.transaction.id') ?? $res->json('id') ?? '');
            $indicatorOk = $s->success_indicator && $resultIndicator && hash_equals($s->success_indicator, $resultIndicator);

            if ($result === 'SUCCESS' && in_array($status, ['CAPTURED', 'PURCHASED'], true)
                && $captured + 0.01 >= $s->amount && ($indicatorOk || $resultIndicator === null)) {
                return ['success', $txnId ?: self::gatewayOrderId($s)];
            }
            if (in_array($status, ['FAILED', 'CANCELLED', 'DECLINED', 'EXPIRED'], true) || $result === 'FAILURE') {
                return ['failed', $txnId];
            }
        } catch (\Throwable $e) {
            Log::error('Areeba verify exception: ' . $e->getMessage());
        }
        return ['pending', ''];
    }
}

<?php

namespace App\Services\Payments;

use App\Models\LocalPaymentSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Custom (Hader): Tamara — split in instalments (UAE). Orders only.
 * Checkout → customer approves → we authorise the order with Tamara and capture it,
 * so the order is only marked paid once Tamara has confirmed it.
 * Keys: tamara_payment_method, tamara_api_token, tamara_mode (sandbox|production), tamara_instalments.
 */
class TamaraGateway
{
    public function __construct(private array $gw) {}

    public function isConfigured(): bool
    {
        return ($this->gw['tamara_api_token'] ?? '') !== '';
    }

    private function base(): string
    {
        return ($this->gw['tamara_mode'] ?? '') === 'production' ? 'https://api.tamara.co' : 'https://api-sandbox.tamara.co';
    }

    private function client()
    {
        return Http::timeout(25)->acceptJson()->withToken((string) $this->gw['tamara_api_token']);
    }

    private function money(float $v, string $cur): array
    {
        return ['amount' => round($v, 2), 'currency' => $cur];
    }

    public function create(LocalPaymentSession $s, BnplOrderData $d, string $lang, string $countryCode): array
    {
        $o = $d->order;
        $u = $d->user();
        $a = $d->address();
        [$first, $last] = $d->nameParts();
        $ret = url("local-pay/tamara/{$s->token}/return");
        $cur = $s->currency;
        $items = $d->items()->map(fn ($i) => [
            'reference_id' => (string) $i->id, 'type' => 'Physical', 'name' => mb_substr((string) $i->product_name, 0, 100),
            'sku' => (string) $i->product_variant_id, 'quantity' => (int) $i->quantity,
            'total_amount' => $this->money((float) $i->sub_total, $cur),
        ])->values()->all();

        try {
            $res = $this->client()->post($this->base() . '/checkout', [
                'order_reference_id' => 'HDR-' . $s->id . '-' . $o->id,
                'order_number' => (string) ($o->order_number ?? $o->id),
                'total_amount' => $this->money($s->amount, $cur),
                'shipping_amount' => $this->money((float) $o->delivery_charge, $cur),
                'tax_amount' => $this->money((float) $d->items()->sum(fn ($i) => (float) ($i->tax_total ?? ((float) $i->tax_amount * (int) $i->quantity))), $cur),
                'description' => 'Order #' . ($o->order_number ?? $o->id),
                'country_code' => $countryCode,
                'payment_type' => 'PAY_BY_INSTALMENTS',
                'instalments' => (int) ($this->gw['tamara_instalments'] ?? 4) ?: 4,
                'locale' => str_starts_with($lang, 'ar') ? 'ar_SA' : 'en_US',
                'items' => $items,
                'consumer' => ['first_name' => $first, 'last_name' => $last, 'phone_number' => $d->phone(), 'email' => (string) ($u->email ?? '')],
                'shipping_address' => ['first_name' => $first, 'last_name' => $last, 'line1' => mb_substr((string) ($a->address ?? $o->address), 0, 200),
                    'city' => (string) ($a->city ?? ''), 'country_code' => $countryCode, 'phone_number' => $d->phone()],
                'merchant_url' => ['success' => $ret . '?r=success', 'failure' => $ret . '?r=failure', 'cancel' => $ret . '?r=cancel',
                    'notification' => url("local-pay/tamara/{$s->token}/callback")],
            ]);
            if ($res->successful() && $res->json('checkout_url') && $res->json('order_id')) {
                $s->update(['gateway_ref' => $res->json('order_id')]);
                return ['url' => $res->json('checkout_url')];
            }
            Log::warning('Tamara checkout failed', ['status' => $res->status(), 'body' => $res->json()]);
        } catch (\Throwable $e) {
            Log::error('Tamara exception: ' . $e->getMessage());
        }
        return ['error' => 'Tamara isn\'t available for this order. Please choose another payment method.'];
    }

    public function verify(LocalPaymentSession $s): array
    {
        if (!$s->gateway_ref) {
            return ['failed', ''];
        }
        $id = $s->gateway_ref;
        try {
            $st = strtolower((string) $this->client()->get($this->base() . "/orders/{$id}")->json('status'));
            if ($st === 'approved') {
                $auth = $this->client()->post($this->base() . "/orders/{$id}/authorise");
                $st = strtolower((string) ($auth->json('status') ?: $st));
            }
            if ($st === 'authorised') {
                $this->client()->post($this->base() . '/payments/capture', [
                    'order_id' => $id, 'total_amount' => $this->money($s->amount, $s->currency),
                    'shipping_info' => ['shipped_at' => now()->toIso8601String(), 'shipping_company' => 'Hader'],
                ]);
            }
            if (in_array($st, ['authorised', 'fully_captured', 'partially_captured'], true)) {
                return ['success', 'TAMARA-' . $id];
            }
            if (in_array($st, ['declined', 'expired', 'canceled', 'cancelled'], true)) {
                return ['failed', 'TAMARA-' . $id];
            }
        } catch (\Throwable $e) {
            Log::error('Tamara verify exception: ' . $e->getMessage());
        }
        return ['pending', ''];
    }
}

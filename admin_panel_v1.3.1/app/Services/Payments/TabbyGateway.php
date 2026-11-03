<?php

namespace App\Services\Payments;

use App\Models\LocalPaymentSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Custom (Hader): Tabby — pay in 4, interest-free (UAE). Orders only.
 * Session → customer approves on Tabby → we read the payment back
 * (AUTHORIZED → capture in full → CLOSED) before marking the order paid.
 * Keys: tabby_payment_method, tabby_secret_key, tabby_merchant_code, tabby_base_url.
 */
class TabbyGateway
{
    public function __construct(private array $gw) {}

    public function isConfigured(): bool
    {
        return ($this->gw['tabby_secret_key'] ?? '') !== '' && ($this->gw['tabby_merchant_code'] ?? '') !== '';
    }

    private function base(): string
    {
        return rtrim(trim((string) ($this->gw['tabby_base_url'] ?? '')) ?: 'https://api.tabby.ai', '/');
    }

    private function client()
    {
        return Http::timeout(25)->acceptJson()->withToken((string) $this->gw['tabby_secret_key']);
    }

    /** Returns the Tabby checkout URL, or ['error' => …]. */
    public function create(LocalPaymentSession $s, BnplOrderData $d, string $lang): array
    {
        $o = $d->order;
        $u = $d->user();
        $a = $d->address();
        $ret = url("local-pay/tabby/{$s->token}/return");
        $items = $d->items()->map(fn ($i) => [
            'title' => mb_substr((string) $i->product_name, 0, 100), 'quantity' => (int) $i->quantity,
            'unit_price' => number_format((float) $i->discounted_price ?: (float) $i->price, 2, '.', ''),
            'reference_id' => (string) $i->product_variant_id, 'category' => 'General',
        ])->values()->all();

        try {
            $res = $this->client()->post($this->base() . '/api/v2/checkout', [
                'payment' => [
                    'amount' => number_format($s->amount, 2, '.', ''), 'currency' => $s->currency,
                    'description' => 'Order #' . ($o->order_number ?? $o->id),
                    'buyer' => ['phone' => $d->phone(), 'email' => (string) ($u->email ?? ''), 'name' => implode(' ', $d->nameParts())],
                    'shipping_address' => ['city' => (string) ($a->city ?? ''), 'address' => mb_substr((string) ($a->address ?? $o->address), 0, 200),
                        'zip' => (string) ($a->pincode ?? '00000')],
                    'order' => ['reference_id' => (string) ($o->order_number ?? $o->id), 'items' => $items],
                    'buyer_history' => ['registered_since' => optional($u?->created_at)->toIso8601String() ?? now()->toIso8601String(), 'loyalty_level' => 0],
                    'order_history' => [],
                ],
                'lang' => str_starts_with($lang, 'ar') ? 'ar' : 'en',
                'merchant_code' => (string) $this->gw['tabby_merchant_code'],
                'merchant_urls' => ['success' => $ret . '?r=success', 'cancel' => $ret . '?r=cancel', 'failure' => $ret . '?r=failure'],
            ]);
            $url = $res->json('configuration.available_products.installments.0.web_url');
            if ($res->successful() && $url && $res->json('payment.id')) {
                $s->update(['gateway_ref' => $res->json('payment.id')]);
                return ['url' => $url];
            }
            Log::warning('Tabby session failed', ['status' => $res->status(), 'body' => $res->json()]);
            if ($res->json('status') === 'rejected') {
                return ['error' => 'Tabby isn\'t available for this order. Please choose another payment method.'];
            }
        } catch (\Throwable $e) {
            Log::error('Tabby exception: ' . $e->getMessage());
        }
        return ['error' => 'Error while communicating with Tabby'];
    }

    /** ['success'|'failed'|'pending', txnId] — captures an AUTHORIZED payment. */
    public function verify(LocalPaymentSession $s): array
    {
        if (!$s->gateway_ref) {
            return ['failed', ''];
        }
        try {
            $p = $this->client()->get($this->base() . '/api/v2/payments/' . $s->gateway_ref);
            $status = strtoupper((string) $p->json('status'));
            if ($status === 'AUTHORIZED') {
                $c = $this->client()->post($this->base() . '/api/v2/payments/' . $s->gateway_ref . '/captures', [
                    'amount' => number_format($s->amount, 2, '.', ''), 'reference_id' => 'cap-' . $s->id,
                ]);
                $status = strtoupper((string) ($c->json('status') ?: $status));
            }
            if (in_array($status, ['CLOSED', 'AUTHORIZED'], true)) {
                return ['success', 'TABBY-' . $s->gateway_ref];
            }
            if (in_array($status, ['REJECTED', 'EXPIRED'], true)) {
                return ['failed', 'TABBY-' . $s->gateway_ref];
            }
        } catch (\Throwable $e) {
            Log::error('Tabby verify exception: ' . $e->getMessage());
        }
        return ['pending', ''];
    }
}

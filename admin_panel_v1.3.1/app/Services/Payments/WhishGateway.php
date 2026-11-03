<?php

namespace App\Services\Payments;

use App\Models\LocalPaymentSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Custom (Hader): Whish Money (Whish Pay) hosted payment.
 *
 * Flow: create → customer pays on Whish's collect page → Whish redirects back and
 * calls our callback → we ALWAYS confirm with the status endpoint before settling.
 *
 * Endpoint paths follow Whish's Collect web-service spec (payment/whish and
 * payment/collect/status). The base URL is configurable per country because
 * Whish issues it with the merchant credentials — confirm it with Whish before
 * going live.
 *
 * Gateway keys (country → payment gateways): whish_payment_method, whish_mode
 * (sandbox|production), whish_channel, whish_secret, whish_website_url,
 * whish_base_url (optional override).
 */
class WhishGateway
{
    public const SANDBOX_URL    = 'https://lb.sandbox.whish.money/itel-service/api/';
    public const PRODUCTION_URL = 'https://whish.money/itel-service/api/';

    public function __construct(private array $gw) {}

    public function isConfigured(): bool
    {
        return ($this->gw['whish_channel'] ?? '') !== '' && ($this->gw['whish_secret'] ?? '') !== '';
    }

    private function base(): string
    {
        $override = trim((string) ($this->gw['whish_base_url'] ?? ''));
        if ($override !== '') {
            return rtrim($override, '/') . '/';
        }
        return ($this->gw['whish_mode'] ?? '') === 'production' ? self::PRODUCTION_URL : self::SANDBOX_URL;
    }

    private function client()
    {
        return Http::timeout(20)->acceptJson()->withHeaders([
            'channel'    => (string) ($this->gw['whish_channel'] ?? ''),
            'secret'     => (string) ($this->gw['whish_secret'] ?? ''),
            'websiteurl' => (string) ($this->gw['whish_website_url'] ?? config('app.url')),
        ]);
    }

    /** Returns the Whish collect URL to send the customer to, or null. */
    public function create(LocalPaymentSession $s, string $description): ?string
    {
        $return   = url("local-pay/whish/{$s->token}/return");
        $callback = url("local-pay/whish/{$s->token}/callback");
        try {
            $res = $this->client()->post($this->base() . 'payment/whish', [
                'amount'             => round($s->amount, 2),
                'currency'           => $s->currency,
                'invoice'            => $description,
                'externalId'         => (int) $s->id,
                'successCallbackUrl' => $callback,
                'failureCallbackUrl' => $callback,
                'successRedirectUrl' => $return,
                'failureRedirectUrl' => $return,
            ]);
            $url = $res->json('data.collectUrl');
            if ($res->successful() && $res->json('status') && $url) {
                return $url;
            }
            Log::warning('Whish create failed', ['status' => $res->status(), 'body' => $res->json()]);
        } catch (\Throwable $e) {
            Log::error('Whish create exception: ' . $e->getMessage());
        }
        return null;
    }

    /** 'success' | 'failed' | 'pending' (unknown/error counts as pending). */
    public function status(LocalPaymentSession $s): string
    {
        try {
            $res = $this->client()->post($this->base() . 'payment/collect/status', [
                'currency'   => $s->currency,
                'externalId' => (int) $s->id,
            ]);
            $st = strtolower((string) $res->json('data.collectStatus'));
            if ($res->successful() && $res->json('status')) {
                return in_array($st, ['success', 'failed'], true) ? $st : 'pending';
            }
            Log::warning('Whish status failed', ['body' => $res->json()]);
        } catch (\Throwable $e) {
            Log::error('Whish status exception: ' . $e->getMessage());
        }
        return 'pending';
    }
}

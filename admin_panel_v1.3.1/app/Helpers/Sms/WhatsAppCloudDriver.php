<?php

namespace App\Helpers\Sms;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Custom (Hader): WhatsApp Business Platform (Meta Cloud API) driver.
 *
 * Business-initiated WhatsApp messages must use pre-approved templates, so every
 * SMS template type maps to a WhatsApp template of the SAME NAME (e.g.
 * "customer_order_received", "otp_customer"). The template body variables
 * {{1}}, {{2}}… are filled with the SMS template's {{placeholders}} in the order
 * they first appear in the SMS text — see local-dev/WHATSAPP_TEMPLATES.md.
 *
 * OTP templates ("otp*") are sent as Authentication templates with the code in
 * the copy-code button as well, which WhatsApp requires.
 *
 * Settings: whatsapp_access_token, whatsapp_phone_number_id,
 *           whatsapp_languages (e.g. "en,ar" — first is the fallback).
 * Never throws; returns false so the caller can fall back to SMS.
 */
class WhatsAppCloudDriver implements SmsDriverInterface
{
    private const API_VERSION = 'v21.0';

    public function send(string $to, string $message, array $context = []): bool
    {
        $token   = trim((string) Setting::get_value('whatsapp_access_token'));
        $phoneId = trim((string) Setting::get_value('whatsapp_phone_number_id'));
        $type    = (string) ($context['type'] ?? '');

        if ($token === '' || $phoneId === '' || $type === '' || $to === '') {
            return false;
        }

        $to = preg_replace('/\D+/', '', $to); // E.164 digits only, no "+"
        $vars = (array) ($context['vars'] ?? []);
        $raw  = (string) ($context['raw_message'] ?? '');

        // Body parameters in the order the placeholders first appear in the SMS text.
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $raw, $m);
        $params = [];
        foreach (array_unique($m[1] ?? []) as $key) {
            $value = (string) ($vars[$key] ?? '');
            $params[] = ['type' => 'text', 'text' => $value !== '' ? $value : '-'];
        }

        $components = [];
        if ($params) {
            $components[] = ['type' => 'body', 'parameters' => $params];
        }
        if (str_starts_with($type, 'otp') && !empty($vars['otp'])) {
            // Authentication templates: the code also goes in the copy-code button.
            $components = [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => (string) $vars['otp']]]],
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0',
                 'parameters' => [['type' => 'text', 'text' => (string) $vars['otp']]]],
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'       => $type,
                'language'   => ['code' => $this->languageCode($context['language_code'] ?? null)],
                'components' => $components,
            ],
        ];

        try {
            $res = Http::withToken($token)->timeout(15)
                ->post('https://graph.facebook.com/' . self::API_VERSION . "/{$phoneId}/messages", $payload);
            if ($res->successful() && !empty($res->json('messages.0.id'))) {
                return true;
            }
            Log::warning('WhatsApp send failed', ['type' => $type, 'status' => $res->status(), 'body' => $res->json()]);
        } catch (\Throwable $e) {
            Log::error('WhatsApp send exception: ' . $e->getMessage());
        }
        return false;
    }

    /** Recipient language if a template language exists for it, else the first configured one. */
    private function languageCode(?string $wanted): string
    {
        $langs = array_values(array_filter(array_map('trim',
            explode(',', (string) (Setting::get_value('whatsapp_languages') ?: 'en')))));
        $wanted = $wanted ? strtolower($wanted) : null;
        return ($wanted && in_array($wanted, $langs, true)) ? $wanted : ($langs[0] ?? 'en');
    }
}

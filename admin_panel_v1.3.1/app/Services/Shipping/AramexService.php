<?php

namespace App\Services\Shipping;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Custom (Hader): Aramex Shipping Services API (JSON) — books a shipment for one
 * or more items of an order and returns the AWB number + printable label URL.
 * Domestic (same country) → ProductGroup DOM / ONP; otherwise EXP / PPX.
 * Cash-on-delivery orders get the CODS service with the items' amount to collect.
 */
class AramexService
{
    private const LIVE = 'https://ws.aramex.net/ShippingAPI.V2/Shipping/Service_1_0.svc/json/CreateShipments';
    private const TEST = 'https://ws.dev.aramex.net/ShippingAPI.V2/Shipping/Service_1_0.svc/json/CreateShipments';
    public const TRACK_URL = 'https://www.aramex.com/track/results?ShipmentNumber=';

    public static function enabled(): bool
    {
        return Setting::get_value('aramex_enabled') === '1'
            && trim((string) Setting::get_value('aramex_username')) !== ''
            && trim((string) Setting::get_value('aramex_account_number')) !== '';
    }

    /** @return array{ok:bool, awb?:string, label_url?:string, tracking_url?:string, message?:string} */
    public function createShipment(Order $order, $items): array
    {
        $s = fn (string $k) => trim((string) Setting::get_value($k));
        $addr = $order->address_id ? UserAddress::find($order->address_id) : null;
        $user = User::find($order->user_id);

        $shipperCountry = strtoupper($s('aramex_shipper_country') ?: 'LB');
        $countryCode = strtoupper((string) (\App\Models\Country::whereKey($order->country_id)->value('code') ?: $shipperCountry));
        $domestic = $countryCode === $shipperCountry;

        $weight = 0.0;
        $pieces = 0;
        $codAmount = 0.0;
        $descr = [];
        foreach ($items as $it) {
            $pieces += (int) $it->quantity;
            $weight += (float) ($s('aramex_default_weight') ?: 0.5) * (int) $it->quantity;
            $codAmount += (float) $it->sub_total /* already incl. tax */ + (float) $it->delivery_charge
                - (float) $it->promo_discount - (float) $it->wallet_balance;
            $descr[] = mb_substr((string) $it->product_name, 0, 40);
        }
        $isCod = strtoupper((string) $order->payment_method) === 'COD';
        $currency = $order->currency_code ?: 'USD';

        $phone = trim(($addr->country_code ?? '') . ($addr->mobile ?? $order->mobile));
        $consigneeName = $addr->name ?? ($user->name ?? 'Customer');
        $line1 = $addr ? trim($addr->address . ', ' . $addr->area) : (string) $order->address;

        $payload = [
            'ClientInfo' => [
                'UserName' => $s('aramex_username'), 'Password' => (string) Setting::get_value('aramex_password'),
                'Version' => 'v1.0', 'AccountNumber' => $s('aramex_account_number'),
                'AccountPin' => $s('aramex_account_pin'), 'AccountEntity' => $s('aramex_account_entity'),
                'AccountCountryCode' => $s('aramex_account_country'), 'Source' => 24,
            ],
            'LabelInfo' => ['ReportID' => 9201, 'ReportType' => 'URL'],
            'Shipments' => [[
                'Reference1' => (string) ($order->order_number ?? $order->id),
                'Shipper' => [
                    'AccountNumber' => $s('aramex_account_number'),
                    'PartyAddress' => ['Line1' => $s('aramex_shipper_address') ?: $s('aramex_shipper_city'),
                        'City' => $s('aramex_shipper_city'), 'CountryCode' => $shipperCountry],
                    'Contact' => ['PersonName' => $s('aramex_shipper_name'), 'CompanyName' => $s('aramex_shipper_name'),
                        'PhoneNumber1' => $s('aramex_shipper_phone'), 'CellPhone' => $s('aramex_shipper_phone'),
                        'EmailAddress' => $s('aramex_shipper_email')],
                ],
                'Consignee' => [
                    'PartyAddress' => ['Line1' => mb_substr($line1, 0, 100), 'Line2' => mb_substr((string) ($addr->landmark ?? ''), 0, 100),
                        'City' => (string) ($addr->city ?? $s('aramex_shipper_city')), 'StateOrProvinceCode' => (string) ($addr->state ?? ''),
                        'PostCode' => (string) ($addr->pincode ?? ''), 'CountryCode' => $countryCode],
                    'Contact' => ['PersonName' => $consigneeName, 'CompanyName' => $consigneeName,
                        'PhoneNumber1' => $phone, 'CellPhone' => $phone, 'EmailAddress' => (string) ($user->email ?? '')],
                ],
                'ShippingDateTime' => '/Date(' . (now()->getTimestamp() * 1000) . ')/',
                'DueDate' => '/Date(' . (now()->addDays(3)->getTimestamp() * 1000) . ')/',
                'Details' => [
                    'ActualWeight' => ['Unit' => 'KG', 'Value' => max(0.1, round($weight, 2))],
                    'ProductGroup' => $domestic ? 'DOM' : 'EXP',
                    'ProductType' => $domestic ? 'ONP' : 'PPX',
                    'PaymentType' => 'P',
                    'NumberOfPieces' => max(1, $pieces),
                    'DescriptionOfGoods' => mb_substr(implode(', ', $descr), 0, 100) ?: 'Goods',
                    'GoodsOriginCountry' => $shipperCountry,
                    'Services' => $isCod ? 'CODS' : '',
                    'CashOnDeliveryAmount' => $isCod ? ['CurrencyCode' => $currency, 'Value' => round(max(0, $codAmount), 2)] : null,
                ],
            ]],
        ];

        try {
            $url = Setting::get_value('aramex_mode') === 'live' ? self::LIVE : self::TEST;
            $res = Http::timeout(40)->acceptJson()->post($url, $payload);
            $j = $res->json() ?? [];
            $ship = $j['Shipments'][0] ?? null;
            if ($res->successful() && empty($j['HasErrors']) && !empty($ship['ID'])) {
                return ['ok' => true, 'awb' => (string) $ship['ID'],
                    'label_url' => (string) ($ship['ShipmentLabel']['LabelURL'] ?? ''),
                    'tracking_url' => self::TRACK_URL . $ship['ID']];
            }
            $msgs = collect(array_merge($j['Notifications'] ?? [], $ship['Notifications'] ?? []))
                ->pluck('Message')->filter()->implode(' ');
            Log::warning('Aramex create failed', ['status' => $res->status(), 'body' => $j]);
            return ['ok' => false, 'message' => $msgs ?: 'Aramex rejected the shipment (HTTP ' . $res->status() . ')'];
        } catch (\Throwable $e) {
            Log::error('Aramex exception: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not reach Aramex: ' . $e->getMessage()];
        }
    }
}

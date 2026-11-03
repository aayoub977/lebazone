<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\UserAddress;

/** Custom (Hader): buyer / address / items for pay-later (Tabby, Tamara) checkouts. */
class BnplOrderData
{
    public function __construct(public Order $order)
    {
    }

    public function user(): ?User
    {
        return User::find($this->order->user_id);
    }

    public function address(): ?UserAddress
    {
        return $this->order->address_id ? UserAddress::find($this->order->address_id) : null;
    }

    public function items()
    {
        return OrderItem::where('order_id', $this->order->id)->get();
    }

    /** "John Smith" → ["John", "Smith"]. */
    public function nameParts(): array
    {
        $full = trim((string) ($this->address()->name ?? $this->user()->name ?? 'Customer'));
        $parts = preg_split('/\s+/', $full, 2);
        return [$parts[0] ?: 'Customer', $parts[1] ?? $parts[0]];
    }

    public function phone(): string
    {
        $a = $this->address();
        return preg_replace('/[^\d+]/', '', ($a->country_code ?? '') . ($a->mobile ?? $this->order->mobile));
    }
}

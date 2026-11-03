<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Custom (Lebazone): bell alert in the admin panel for an order stuck in a warehouse stage. */
class WarehouseAlert extends Notification
{
    public function __construct(public int $orderId, public string $text) {}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        return ['type' => 'warehouse', 'order_id' => $this->orderId, 'text' => $this->text];
    }
}

<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Custom (Lebazone): bell alert - a seller added a product that waits for approval. */
class SellerProductAlert extends Notification
{
    public function __construct(public int $productId, public string $text) {}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        return ['type' => 'seller_product', 'product_id' => $this->productId, 'text' => $this->text];
    }
}

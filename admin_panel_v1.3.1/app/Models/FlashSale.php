<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Custom (Hader): a time-boxed flash deal. */
class FlashSale extends Model
{
    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
        'status'    => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(FlashSaleItem::class)->orderBy('sort_order')->orderBy('id');
    }
}

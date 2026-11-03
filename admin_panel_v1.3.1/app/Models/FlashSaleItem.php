<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Custom (Hader): one product variant + its deal price inside a flash sale. */
class FlashSaleItem extends Model
{
    protected $guarded = [];

    protected $casts = ['deal_price' => 'float'];

    public function flashSale()
    {
        return $this->belongsTo(FlashSale::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}

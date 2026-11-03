<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Custom (Hader): a Whish / Areeba payment attempt. */
class LocalPaymentSession extends Model
{
    protected $guarded = [];
    protected $casts = ['meta' => 'array', 'amount' => 'float'];
}

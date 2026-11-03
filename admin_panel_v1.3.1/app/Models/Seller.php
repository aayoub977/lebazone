<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Custom (Hader): a consignment seller (supplier). */
class Seller extends Model
{
    protected $guarded = [];
    protected $casts = ['commission_percent' => 'float'];

    public function balance(): float
    {
        return (float) DB::table('seller_ledger')->where('seller_id', $this->id)->sum('net');
    }
}

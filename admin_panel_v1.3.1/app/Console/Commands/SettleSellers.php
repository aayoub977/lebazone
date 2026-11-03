<?php

namespace App\Console\Commands;

use App\Services\SellerSettlementService;
use Illuminate\Console\Command;

/** Custom (Hader): credit consignment sellers for items past their return window. */
class SettleSellers extends Command
{
    protected $signature = 'sellers:settle';
    protected $description = 'Credit consignment sellers for delivered items past their return window';

    public function handle(SellerSettlementService $s): int
    {
        $n = $s->settleDue();
        if ($n) {
            $this->info("Seller sales credited: {$n}");
        }
        return self::SUCCESS;
    }
}

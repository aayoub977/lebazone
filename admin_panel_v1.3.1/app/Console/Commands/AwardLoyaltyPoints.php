<?php

namespace App\Console\Commands;

use App\Services\LoyaltyService;
use Illuminate\Console\Command;

/** Custom (Hader): award loyalty points for orders past their return window. */
class AwardLoyaltyPoints extends Command
{
    protected $signature = 'loyalty:award';
    protected $description = 'Award loyalty points for delivered orders whose return window has closed';

    public function handle(LoyaltyService $service): int
    {
        $n = $service->awardDue();
        if ($n) {
            $this->info("Loyalty points awarded for {$n} order(s)");
        }
        return self::SUCCESS;
    }
}

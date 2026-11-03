<?php

namespace App\Console\Commands;

use App\Services\FlashSaleService;
use Illuminate\Console\Command;

/** Custom (Hader): starts/ends flash deals on schedule. */
class ApplyFlashSales extends Command
{
    protected $signature = 'flash-sales:apply';
    protected $description = 'Start due flash deals and end finished ones';

    public function handle(FlashSaleService $service): int
    {
        $r = $service->sync();
        if ($r['started'] || $r['ended']) {
            $this->info("Flash sales started: {$r['started']}, ended: {$r['ended']}");
        }
        return self::SUCCESS;
    }
}

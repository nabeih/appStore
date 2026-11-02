<?php

namespace App\Console\Commands\Store;

use App\Actions\Notifications\DispatchAppAlerts;
use Illuminate\Console\Command;

class DispatchAlertsCommand extends Command
{
    protected $signature = 'app:alerts-dispatch';

    protected $description = 'Deliver committed in-app alert events idempotently without external push services';

    public function handle(DispatchAppAlerts $alerts): int
    {
        $this->info('Dispatched committed events: '.$alerts->pending());

        return self::SUCCESS;
    }
}

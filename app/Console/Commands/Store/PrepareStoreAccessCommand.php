<?php

namespace App\Console\Commands\Store;

use App\Actions\Access\InitializeAccessAction;
use App\Actions\Store\StoreContext;
use App\Actions\Store\StoreOrderFinance;
use App\Models\User;
use App\Support\Access\AccessPermissions;
use App\Support\Sales\OperationContext;
use Illuminate\Console\Command;

class PrepareStoreAccessCommand extends Command
{
    protected $signature = 'app:store-access {--owner= : Existing active owner username} {--confirm : Explicitly approve updating owner permissions and store system accounts}';

    protected $description = 'Prepare existing owner permissions and delivery accounts after an approved deployment';

    public function handle(StoreContext $store): int
    {
        $owner = User::where('username', $this->option('owner'))->first();
        if (! $this->option('confirm') || ! $owner || ! AccessPermissions::isOwner($owner) || ! $store->installed()) {
            $this->error('Existing active owner, installed schema and explicit --confirm are required.');

            return self::FAILURE;
        }
        OperationContext::run(function () use ($owner): void {
            app(InitializeAccessAction::class)->execute();
            app(StoreOrderFinance::class)->provision();
            OperationContext::audit($owner, $owner, 'store.access_prepared', null, ['new_store_permissions' => true]);
        });
        $this->info('Owner permissions and store delivery accounts prepared; no opening balances or stock posted.');

        return self::SUCCESS;
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Actions\Access\FirstRun\PrepareSetupTokenAction;
use App\Models\ApplicationInstallation;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\Sales\SalesAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FirstRunSetupConcurrencyTest extends TestCase
{
    public function test_two_processes_with_the_same_token_can_create_only_one_owner_without_migrations_or_deletion(): void
    {
        $source = DB::connection();
        if (! app()->environment('testing') || $source->getDriverName() !== 'mysql'
            || $source->getDatabaseName() !== 'appstore_golive_20261031_tests'
            || ! in_array($source->getConfig('host'), ['127.0.0.1', 'localhost'], true)
            || $source->getConfig('url')) {
            throw new \LogicException('Only the explicitly isolated local test database may supply fixture schema.');
        }
        $database = 'appstore_first_run_race_'.bin2hex(random_bytes(8));
        $this->assertSame(0, (int) $source->table('information_schema.SCHEMATA')->where('SCHEMA_NAME', $database)->count());
        $source->statement('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.first_run_race' => array_replace($source->getConfig(), ['database' => $database, 'url' => null, 'name' => 'first_run_race'])]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('first_run_race');
        $workers = [];
        try {
            $target = DB::connection();
            $target->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($source->getSchemaBuilder()->getTableListing($source->getDatabaseName(), false) as $table) {
                if (! preg_match('/\A[a-z0-9_]+\z/', $table)) {
                    throw new \LogicException('Unexpected fixture table name.');
                }
                $ddl = (array) $source->selectOne('SHOW CREATE TABLE '.$source->getQueryGrammar()->wrapTable($table));
                $target->statement($ddl['Create Table']);
            }
            $target->statement('SET FOREIGN_KEY_CHECKS=1');
            $this->prepareFirstRunSchema();
            DB::table('product_code_counters')->insert(['id' => 1, 'last_number' => 0, 'lock_version' => 0]);
            DB::table('sales_controls')->insert(['id' => 1]);
            ApplicationInstallation::findOrFail(1)->update(['status' => 'unprepared']);
            Storage::fake('installation_tokens');
            app(PrepareSetupTokenAction::class)->execute();
            $state = ApplicationInstallation::findOrFail(1);
            $token = Storage::disk('installation_tokens')->get($state->delivery_id.'.token');
            $code = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection=json_decode(getenv('FIRST_RUN_CONNECTION'),true,512,JSON_THROW_ON_ERROR);
if(!preg_match('/\Aappstore_first_run_race_[a-f0-9]{16}\z/',$connection['database'])||!in_array($connection['host'],['127.0.0.1','localhost'],true)){exit(2);}
config(['database.default'=>'first_run_race','database.connections.first_run_race'=>$connection,'filesystems.disks.installation_tokens.root'=>getenv('FIRST_RUN_DELIVERY_ROOT')]);
Illuminate\Support\Facades\DB::setDefaultConnection('first_run_race');
$data=json_decode(getenv('FIRST_RUN_DATA'),true,512,JSON_THROW_ON_ERROR);
while(microtime(true)<(float)getenv('FIRST_RUN_START')){usleep(1000);}
try{$owner=app(App\Actions\Access\FirstRun\BootstrapOwnerAction::class)->execute($data);echo json_encode(['status'=>201,'owner_id'=>$owner->id]);}
catch(Throwable $error){echo json_encode(['status'=>method_exists($error,'getStatusCode')?$error->getStatusCode():500,'type'=>$error::class]);}
PHP;
            $start = microtime(true) + 1;
            for ($index = 0; $index < 2; $index++) {
                $worker = new Process([PHP_BINARY, '-r', $code], base_path(), [
                    'APP_ENV' => 'testing', 'BCRYPT_ROUNDS' => '4', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
                    'FIRST_RUN_CONNECTION' => json_encode($target->getConfig(), JSON_THROW_ON_ERROR),
                    'FIRST_RUN_DATA' => json_encode(['name' => 'Isolated owner', 'username' => 'race.owner.'.$index, 'email' => 'race'.$index.'@example.test',
                        'password' => 'Isolated-Password!4', 'setup_token' => $token], JSON_THROW_ON_ERROR),
                    'FIRST_RUN_DELIVERY_ROOT' => Storage::disk('installation_tokens')->path(''),
                    'FIRST_RUN_START' => (string) $start,
                ]);
                $worker->setTimeout(40);
                $worker->start();
                $workers[] = $worker;
            }
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), 'Isolated worker must exit cleanly.');
                $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame([201, 404], $statuses);
            $this->assertSame(1, User::count());
            $this->assertSame(4, Role::count());
            $owner = User::firstOrFail();
            $this->assertSame('sales_owner', $owner->role->name);
            $this->assertSame(count(SalesAccess::PERMISSIONS), $owner->role->permissions()->count());
            $this->assertNull(ApplicationInstallation::findOrFail(1)->token_hash);
            $this->assertSame('completed', ApplicationInstallation::findOrFail(1)->status);
            $this->assertSame(1, DB::table('audit_events')->where('action', 'installation.completed')->count());
            $this->assertSame(0, DB::table('branches')->count());
            $this->assertSame(0, DB::table('journal_entries')->count());
            $branchCode = str_replace(
                '$owner=app(App\\Actions\\Access\\FirstRun\\BootstrapOwnerAction::class)->execute($data);echo json_encode([\'status\'=>201,\'owner_id\'=>$owner->id]);',
                '$branch=app(App\\Actions\\Access\\FirstRun\\CompleteFirstBranchAction::class)->execute(App\\Models\\User::firstOrFail(), $data);echo json_encode([\'status\'=>201,\'branch_id\'=>$branch->id]);',
                $code
            );
            $this->assertNotSame($code, $branchCode);
            $branchWorkers = [];
            $start = microtime(true) + 1;
            for ($index = 0; $index < 2; $index++) {
                $worker = new Process([PHP_BINARY, '-r', $branchCode], base_path(), [
                    'APP_ENV' => 'testing', 'BCRYPT_ROUNDS' => '4', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
                    'FIRST_RUN_CONNECTION' => json_encode($target->getConfig(), JSON_THROW_ON_ERROR),
                    'FIRST_RUN_DATA' => json_encode(['name' => 'First branch '.$index], JSON_THROW_ON_ERROR),
                    'FIRST_RUN_DELIVERY_ROOT' => Storage::disk('installation_tokens')->path(''),
                    'FIRST_RUN_START' => (string) $start,
                ]);
                $worker->setTimeout(40);
                $worker->start();
                $workers[] = $worker;
                $branchWorkers[] = $worker;
            }
            $branchStatuses = [];
            foreach ($branchWorkers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), 'Branch worker must exit cleanly.');
                $branchStatuses[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status'];
            }
            sort($branchStatuses);
            $this->assertSame([201, 409], $branchStatuses);
            $this->assertSame(1, DB::table('branches')->count());
            $this->assertSame(1, DB::table('audit_events')->where('action', 'installation.first_branch_completed')->count());
            $this->assertNotNull(ApplicationInstallation::findOrFail(1)->first_branch_completed_at);
            $this->assertFalse(ApplicationInstallation::findOrFail(1)->first_branch_required);
            $this->assertSame(0, DB::table('financial_accounts')->count());
            $this->assertSame(0, DB::table('inventories')->count());
            $phase = function (string $operationCode, array $data) use ($target, &$workers): array {
                $phaseWorkers = [];
                $start = microtime(true) + 1;
                for ($i = 0; $i < 2; $i++) {
                    $worker = new Process([PHP_BINARY, '-r', $operationCode], base_path(), [
                        'APP_ENV' => 'testing', 'BCRYPT_ROUNDS' => '4', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
                        'FIRST_RUN_CONNECTION' => json_encode($target->getConfig(), JSON_THROW_ON_ERROR),
                        'FIRST_RUN_DATA' => json_encode($data, JSON_THROW_ON_ERROR),
                        'FIRST_RUN_DELIVERY_ROOT' => Storage::disk('installation_tokens')->path(''),
                        'FIRST_RUN_START' => (string) $start,
                    ]);
                    $worker->setTimeout(40);
                    $worker->start();
                    $workers[] = $worker;
                    $phaseWorkers[] = $worker;
                }
                $statuses = [];
                foreach ($phaseWorkers as $worker) {
                    $worker->wait();
                    $this->assertTrue($worker->isSuccessful(), 'Management race worker must exit cleanly.');
                    $statuses[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status'];
                }
                sort($statuses);

                return $statuses;
            };
            $managementCode = str_replace(
                '$owner=app(App\\Actions\\Access\\FirstRun\\BootstrapOwnerAction::class)->execute($data);',
                '$owner=app(App\\Actions\\Branches\\ManageBranchAction::class)->save(App\\Models\\User::firstOrFail(),$data);',
                $code
            );
            $managementCode = str_replace('getStatusCode():500', 'getStatusCode():($error instanceof Illuminate\\Validation\\ValidationException?422:500)', $managementCode);
            $this->assertNotSame($code, $managementCode);
            $initialBranch = User::firstOrFail()->branch_id;
            $createStatuses = $phase($managementCode, ['name' => 'Concurrent additional branch']);
            $this->assertSame([201, 422], $createStatuses);
            $extra = Branch::where('name', 'Concurrent additional branch')->firstOrFail();
            $this->assertSame(2, DB::table('branches')->count());
            $this->assertSame(1, DB::table('audit_events')->where('action', 'branch.created')->count());
            $this->assertSame($initialBranch, User::firstOrFail()->branch_id);
            $statusCode = str_replace(
                '->save(App\\Models\\User::firstOrFail(),$data)',
                '->status(App\\Models\\User::firstOrFail(),App\\Models\\Branch::findOrFail($data["id"]),false,"Concurrent disabling")',
                $managementCode
            );
            $statusStatuses = $phase($statusCode, ['id' => $extra->id]);
            $this->assertSame([201, 201], $statusStatuses);
            $this->assertFalse((bool) $extra->fresh()->is_active);
            $this->assertSame(1, DB::table('audit_events')->where('action', 'branch.status_changed')->count());
            $this->assertSame($initialBranch, ApplicationInstallation::findOrFail(1)->initial_branch_id);
            if (getenv('FIRST_RUN_CONCURRENCY_REPORT') === '1') {
                file_put_contents(storage_path('app/reports/first-run-concurrency.json'), json_encode([
                    'database' => $database, 'statuses' => $statuses, 'first_branch_statuses' => $branchStatuses, 'additional_branch_statuses' => $createStatuses, 'branch_status_requests' => $statusStatuses, 'owner_count' => 1, 'retained_for_review' => true,
                    'migrations_executed' => false, 'tables_or_data_deleted' => false,
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            DB::purge('first_run_race');
            DB::setDefaultConnection($original);
        }
    }
}

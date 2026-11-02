<?php

/**
 * Local release rehearsal. Source reads are enforced read-only.
 * All DDL, migrations and access initialization target a newly created clone.
 * Never imports or mutates app_store. Retains backup and clone for review.
 */
require dirname(__DIR__).'/vendor/autoload.php';

use App\Models\User;
use App\Support\Access\AccessPermissions;
use App\Support\Mobile\MobileChangeTracking;
use App\Support\Sales\SalesAccess;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

chdir(dirname(__DIR__));
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$source = DB::connection();
$config = $source->getConfig();
if (! $app->environment('local') || $app->configurationIsCached() || $source->getDriverName() !== 'mysql'
    || $source->getDatabaseName() !== 'app_store' || ($config['host'] ?? '') !== '127.0.0.1'
    || (string) ($config['port'] ?? '') !== '3306' || ! empty($config['url']) || isset($config['read']) || isset($config['write'])) {
    throw new LogicException('Only the uncached local app_store loopback connection may be read.');
}
$source->statement('SET SESSION TRANSACTION READ ONLY');

/** @return array<string, array{count:int,sha256:string}> */
function releaseFingerprints(Connection $db, array $tables, bool $upgraded = false): array
{
    $result = [];
    foreach ($tables as $table) {
        $hashes = [];
        foreach ($db->table($table)->cursor() as $record) {
            $values = (array) $record;
            if ($upgraded && $table === 'inventories') {
                unset($values['reserved_quantity']);
            }
            ksort($values);
            $hashes[] = hash('sha256', json_encode($values, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        }
        sort($hashes, SORT_STRING);
        $result[$table] = ['count' => count($hashes), 'sha256' => hash('sha256', implode("\n", $hashes))];
    }

    return $result;
}

/** @return array<string, mixed> */
function releaseSchema(Connection $db): array
{
    $tables = $db->getSchemaBuilder()->getTableListing($db->getDatabaseName(), false);
    sort($tables);
    $schema = [];
    foreach ($tables as $table) {
        $builder = $db->getSchemaBuilder();
        $foreignKeys = $builder->getForeignKeys($table);
        foreach ($foreignKeys as &$key) {
            if ($key['foreign_schema'] !== $db->getDatabaseName()) {
                throw new RuntimeException('Cross-database foreign key requires manual review.');
            }
            $key['foreign_schema'] = '__current__';
        }
        unset($key);
        $schema[$table] = ['columns' => $builder->getColumns($table), 'indexes' => $builder->getIndexes($table), 'foreign_keys' => $foreignKeys];
    }

    return $schema;
}

$source->beginTransaction();
try {
    $tables = $source->getSchemaBuilder()->getTableListing('app_store', false);
    sort($tables);
    $engines = $source->select('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
    foreach ($engines as $engine) {
        if ($engine->ENGINE !== 'InnoDB') {
            throw new RuntimeException('A nontransactional table prevents consistent snapshot backup.');
        }
    }
    $schema = releaseSchema($source);
    $baseline = releaseFingerprints($source, $tables);
    $applied = $source->table('migrations')->pluck('migration')->all();
    $migrations = [];
    foreach (glob(base_path('database/migrations/*.php')) as $file) {
        $name = basename($file, '.php');
        $migrations[$name] = ['applied' => in_array($name, $applied, true), 'source_sha256' => hash_file('sha256', $file)];
    }
    $missing = array_diff($applied, array_keys($migrations));
    if ($missing !== []) {
        throw new RuntimeException('Applied migration has no source file. Stop for schema review.');
    }
    $owners = User::with('role.permissions')->whereHas('role', fn ($query) => $query->where('name', 'sales_owner'))->get();
    $access = $owners->map(fn (User $owner): array => [
        'id' => $owner->id, 'active_owner' => AccessPermissions::isOwner($owner),
        'missing_permissions' => array_values(array_diff(array_keys(SalesAccess::PERMISSIONS), $owner->role->permissions->pluck('name')->all())),
    ])->all();
    Artisan::call('migrate:status', ['--no-interaction' => true]);
    file_put_contents(storage_path('app/reports/final-release-migration-status.txt'), Artisan::output());
    $environment = ['environment' => app()->environment(), 'database' => 'app_store', 'host' => '127.0.0.1',
        'php' => PHP_VERSION, 'laravel' => app()->version(), 'mysql' => $source->selectOne('SELECT VERSION() AS version')->version,
        'debug' => config('app.debug'), 'session_driver' => config('session.driver'), 'secure_cookie' => config('session.secure'),
        'http_only' => config('session.http_only'), 'same_site' => config('session.same_site'), 'queue' => config('queue.default'),
        'cache' => config('cache.default'), 'mail_transport' => config('mail.default'),
        'visual_search' => config('product_search.visual_enabled'), 'visual_indexing' => config('product_search.indexing_enabled')];
    $report = ['environment' => $environment, 'migrations' => $migrations, 'source_schema' => $schema,
        'source_fingerprints' => $baseline, 'owners' => $access, 'source_triggers' => $source->table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', 'app_store')->get(['TRIGGER_NAME', 'EVENT_OBJECT_TABLE', 'ACTION_TIMING', 'EVENT_MANIPULATION'])->all()];
} finally {
    $source->rollBack();
}
$reportPath = storage_path('app/reports/final-release-rehearsal.json');
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
if ($argv !== [__FILE__, '--backup-restore-upgrade'] && ($argv[1] ?? '') !== '--backup-restore-upgrade') {
    echo json_encode(['read_only' => true, 'tables' => count($tables), 'pending' => array_keys(array_filter($migrations, fn (array $row): bool => ! $row['applied'])), 'report' => $reportPath], JSON_THROW_ON_ERROR);
    exit(0);
}

config(['database.connections.release_reference' => array_replace($config, ['database' => 'appstore_store_install_20261101_tests'])]);
$reference = releaseSchema(DB::connection('release_reference'));
$schemaDifferences = [];
foreach ($schema as $table => $definition) {
    $expectedDefinition = $reference[$table] ?? null;
    if ($table === 'inventories' && $expectedDefinition) {
        $expectedDefinition['columns'] = array_values(array_filter($expectedDefinition['columns'], fn (array $column): bool => $column['name'] !== 'reserved_quantity'));
    }
    if ($expectedDefinition !== $definition) {
        $schemaDifferences[] = $table;
    }
}
$report['existing_schema_against_clean_migrations'] = ['different_tables' => $schemaDifferences];
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
if ($schemaDifferences !== []) {
    throw new RuntimeException('Schema differs from clean migrations: '.implode(', ', $schemaDifferences).'. Stop before upgrade.');
}
$stamp = '20261101_'.bin2hex(random_bytes(4));
$target = 'appstore_release_rehearsal_'.$stamp;
$private = storage_path('app/private/release-rehearsal/'.$stamp);
if (! mkdir($private, 0700, true)) {
    throw new RuntimeException('Cannot create private backup directory.');
}
$dump = $private.'/app_store.sql';
$optionsFile = $private.'/mysql-client.cnf';
$quote = fn (string $value): string => '"'.strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r']).'"';
file_put_contents($optionsFile, "[client]\nhost=127.0.0.1\nport=3306\nuser=".$quote((string) $config['username'])."\npassword=".$quote((string) $config['password'])."\ndefault-character-set=utf8mb4\n");
chmod($optionsFile, 0600);
$bin = 'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/';
try {
    $backup = new Process([$bin.'mysqldump.exe', '--defaults-extra-file='.$optionsFile, '--single-transaction', '--skip-lock-tables',
        '--routines', '--events', '--triggers', '--hex-blob', '--set-gtid-purged=OFF', '--no-tablespaces', '--result-file='.$dump, 'app_store']);
    $backup->setTimeout(300);
    $backup->run();
    if (! $backup->isSuccessful() || ! is_file($dump) || filesize($dump) === 0) {
        throw new RuntimeException('Backup failed; no source database changes were attempted.');
    }
    chmod($dump, 0600);
    $sql = file_get_contents($dump);
    if (preg_match('/^\\s*(?:CREATE DATABASE|USE\\s+)/mi', $sql)) {
        throw new RuntimeException('Dump changes database context; refusing restore.');
    }
    unset($sql);
    config(['database.connections.release_admin' => $config, 'database.connections.release_clone' => array_replace($config, ['database' => $target])]);
    $admin = DB::connection('release_admin');
    if ($admin->table('information_schema.SCHEMATA')->where('SCHEMA_NAME', $target)->exists()) {
        throw new RuntimeException('Clone database already exists; refusing reuse.');
    }
    $admin->statement('CREATE DATABASE '.$target.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $restore = new Process([$bin.'mysql.exe', '--defaults-extra-file='.$optionsFile, '--database='.$target, '--binary-mode']);
    $input = fopen($dump, 'rb');
    $restore->setInput($input);
    $restore->setTimeout(300);
    $restore->run();
    fclose($input);
    if (! $restore->isSuccessful()) {
        throw new RuntimeException('Restore failed; retained backup and isolated clone for inspection.');
    }
} finally {
    if (is_file($optionsFile)) {
        unlink($optionsFile);
    }
}
$clone = DB::connection('release_clone');
$restored = releaseFingerprints($clone, $tables);
if ($restored !== $baseline || releaseSchema($clone) !== $schema) {
    throw new RuntimeException('Restored clone differs from inspected source; stop. Backup/clone retained, no source write.');
}
$publicRoot = storage_path('app/public');
$fileManifest = [];
if (is_dir($publicRoot)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($publicRoot, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile() || $file->isLink()) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($publicRoot) + 1);
        $destination = $private.'/public-files/'.$relative;
        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0700, true);
        }
        $before = hash_file('sha256', $file->getPathname());
        if (! copy($file->getPathname(), $destination) || hash_file('sha256', $destination) !== $before || hash_file('sha256', $file->getPathname()) !== $before) {
            throw new RuntimeException('Media changed during backup; stop before upgrade.');
        }
        $fileManifest[$relative] = $before;
    }
}
file_put_contents($private.'/public-files-manifest.json', json_encode($fileManifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$report['backup'] = ['directory' => $private, 'dump' => $dump, 'bytes' => filesize($dump), 'sha256' => hash_file('sha256', $dump),
    'restored_database' => $target, 'restored_schema_equal' => true, 'restored_all_rows_equal' => true, 'media_files' => count($fileManifest)];
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$processEnvironment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $target, 'DB_HOST' => '127.0.0.1', 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'null', 'VISUAL_SEARCH_ENABLED' => 'false', 'VISUAL_SEARCH_INDEXING' => 'false'];
$migrate = new Process([PHP_BINARY, 'artisan', 'migrate', '--no-interaction', '--force'], base_path(), $processEnvironment);
$migrate->setTimeout(300);
$migrate->run();
file_put_contents(storage_path('app/reports/final-release-clone-migrations.txt'), $migrate->getOutput().$migrate->getErrorOutput());
if (! $migrate->isSuccessful()) {
    throw new RuntimeException('Clone migration failed. No source changes; review retained DDL before retry.');
}
$after = releaseFingerprints($clone, array_values(array_diff($tables, ['migrations'])), true);
$expected = array_diff_key($baseline, ['migrations' => true]);
if ($after !== $expected) {
    throw new RuntimeException('Preexisting business data changed during clone upgrade. Stop and investigate.');
}
$triggers = $clone->table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', $target)->get();
if ($triggers->whereIn('TRIGGER_NAME', MobileChangeTracking::triggerNames())->count() !== 60
    || $triggers->whereIn('TRIGGER_NAME', ['store_reservation_insert', 'store_reservation_update', 'store_reservation_delete'])->count() !== 3) {
    throw new RuntimeException('Clone trigger inventory is incomplete.');
}
$report['upgrade'] = ['database' => $target, 'pending_after' => count($migrations) - $clone->table('migrations')->count(),
    'preexisting_table_fingerprints_equal' => true, 'new_reserved_total' => (int) $clone->table('inventories')->sum('reserved_quantity'),
    'trigger_count' => $triggers->count(), 'long_index_names' => $clone->select('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND CHAR_LENGTH(INDEX_NAME) > 64', [$target]),
    'after_schema' => releaseSchema($clone), 'after_fingerprints' => $after,
    'mobile_changes' => $clone->table('mobile_sync_changes')->count()];
$activeOwners = $owners->filter(fn (User $owner): bool => AccessPermissions::isOwner($owner));
if ($activeOwners->count() === 1) {
    $prepare = new Process([PHP_BINARY, 'artisan', 'app:store-access', '--owner='.$activeOwners->first()->username, '--confirm', '--no-interaction'], base_path(), $processEnvironment);
    $prepare->setTimeout(120);
    $prepare->mustRun();
    $permissionFirst = releaseFingerprints($clone, ['permissions', 'roles', 'permission_role', 'financial_accounts']);
    $prepare->mustRun();
    $permissionSecond = releaseFingerprints($clone, ['permissions', 'roles', 'permission_role', 'financial_accounts']);
    $report['access_rehearsal'] = ['success' => true, 'two_runs_roles_permissions_accounts_equal' => $permissionFirst === $permissionSecond,
        'delivery_accounts' => $clone->table('financial_accounts')->whereIn('code', ['delivery_revenue', 'courier_receivables'])->count()];
}
$source->beginTransaction();
try {
    $final = releaseFingerprints($source, $tables);
} finally {
    $source->rollBack();
}
$report['source_after'] = ['all_table_fingerprints_equal' => $final === $baseline,
    'changed_tables' => array_keys(array_filter($final, fn (array $row, string $key): bool => $row !== $baseline[$key], ARRAY_FILTER_USE_BOTH))];
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo json_encode(['clone' => $target, 'backup' => $private, 'preexisting_rows_preserved' => true, 'triggers' => $triggers->count(), 'source_unchanged' => $final === $baseline, 'report' => $reportPath], JSON_THROW_ON_ERROR);

<?php

/**
 * Run only against the explicitly approved, already migrated MySQL sandboxes.
 * Schema creation and migration are deliberately separate from this runner.
 */
require dirname(__DIR__).'/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\TextUI\Application as PHPUnitApplication;

$root = dirname(__DIR__);
chdir($root);
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_URL')) {
    throw new LogicException('Set an explicit testing MySQL environment without DB_URL.');
}
$app = new Application($root);
(new LoadEnvironmentVariables)->bootstrap($app);
(new LoadConfiguration)->bootstrap($app);
$connection = $app['config']->get('database.connections.mysql');
$database = $connection['database'];
if ($app->configurationIsCached() || $app['config']->get('database.default') !== 'mysql'
    || ! in_array($database, ['appstore_golive_20261031_tests', 'appstore_golive_20261031_snapshot'], true)
    || ! in_array($connection['host'], ['127.0.0.1', 'localhost'], true) || (string) $connection['port'] !== '3306'
    || ! empty($connection['url']) || isset($connection['read']) || isset($connection['write'])) {
    throw new LogicException('Refusing any database except the approved loopback go-live sandboxes.');
}
$lock = fopen(sys_get_temp_dir().'/'.$database.'-phpunit.lock', 'c');
if (! flock($lock, LOCK_EX | LOCK_NB)) {
    throw new LogicException('Another test run already owns this sandbox.');
}
$arguments = ['phpunit', '--no-progress'];
foreach (array_slice($argv, 1) as $argument) {
    if (! str_starts_with($argument, '--filter=')) {
        throw new LogicException('Only --filter=... is accepted by this safety runner.');
    }
    $arguments[] = $argument;
}
if ($database === 'appstore_golive_20261031_snapshot'
    && ($arguments !== ['phpunit', '--no-progress', '--filter=GoLiveSchemaTest'] || getenv('GO_LIVE_SNAPSHOT') !== '1')) {
    throw new LogicException('The populated snapshot accepts only the read-only schema smoke test.');
}
RefreshDatabaseState::$migrated = true;
exit((new PHPUnitApplication)->run($arguments));

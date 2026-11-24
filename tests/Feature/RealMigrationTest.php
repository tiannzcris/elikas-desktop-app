<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Migrations as the packaged app runs them (2.1.1): `php artisan migrate
 * --force` in its own process, with the environment NativePHP gives it
 * (NATIVEPHP_RUNNING, a database FILE on the "nativephp" connection, its own
 * storage), while Electron's API cannot answer -- at startup Electron's main
 * process is blocked waiting for this very migrate. The "API" here accepts
 * connections and never replies, so any call to Electron from a migration
 * hangs and the test fails on its timeout (2.1.0's bug), and is counted.
 *
 * The real database samples are read from C:\secrets\elikas\desktop-db-samples
 * through a temporary copy; those tests are skipped where the folder is missing.
 */
#[Group('real-migrations')]
class RealMigrationTest extends TestCase
{
    private const SAMPLES = 'C:/secrets/elikas/desktop-db-samples';

    private string $dir;

    private Process $electron;

    private string $connections;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'elikas-migrate-'.uniqid();
        foreach (['storage/app', 'storage/logs', 'storage/framework/cache', 'storage/framework/views', 'storage/framework/sessions', 'database'] as $sub) {
            mkdir($this->dir.'/'.$sub, 0777, true);
        }

        // Electron's API while its main process waits for migrate: accepts, never answers.
        $this->connections = $this->dir.'/electron-connections.txt';
        file_put_contents($this->connections, '0');
        $script = $this->dir.'/electron.php';
        file_put_contents($script, '<?php $s = stream_socket_server("tcp://127.0.0.1:0", $e, $m); $n = stream_socket_get_name($s, false);'
            .' file_put_contents($argv[1].".port", substr($n, strrpos($n, ":") + 1)); $held = [];'
            .' while ($c = @stream_socket_accept($s, -1)) { $held[] = $c; file_put_contents($argv[1], (string) count($held)); }');
        $this->electron = new Process([PHP_BINARY, $script, $this->connections]);
        $this->electron->start();
        for ($i = 0; $i < 100 && ! is_file($this->connections.'.port'); $i++) {
            usleep(50_000);
        }
    }

    protected function tearDown(): void
    {
        $this->electron->stop(0);
        $this->deleteDir($this->dir);
        parent::tearDown();
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    private function database(): string
    {
        return $this->dir.'/database/database.sqlite';
    }

    /** One `artisan migrate --force`, as NativePHP starts it. */
    private function migrateProcess(): Process
    {
        return $this->artisan_(['migrate', '--force']);
    }

    /** An artisan command in the packaged app's environment. */
    private function artisan_(array $arguments): Process
    {
        $process = new Process(array_merge([PHP_BINARY, 'artisan'], $arguments, ['--no-ansi']), base_path(), [
            'NATIVEPHP_RUNNING' => 'true',
            'NATIVEPHP_DATABASE_PATH' => $this->database(),
            'NATIVEPHP_STORAGE_PATH' => $this->dir.'/storage',
            'NATIVEPHP_API_URL' => 'http://127.0.0.1:'.trim((string) @file_get_contents($this->connections.'.port')).'/api/',
            'NATIVEPHP_SECRET' => 'test',
            // The test runner's own settings must not leak into the app's process.
            'APP_ENV' => 'production', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->database(),
            'CACHE_STORE' => 'database', 'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'database',
            'ELIKAS_LOCAL_DATA_KEY_PATH' => '', 'ELIKAS_LOCAL_BACKUPS_PATH' => '',
        ]);
        $process->setTimeout(90);

        return $process;
    }

    private function migrate(): Process
    {
        $process = $this->migrateProcess();
        $process->run();
        $this->assertSame(0, $process->getExitCode(), "migrate failed:\n".$process->getOutput().$process->getErrorOutput());

        return $process;
    }

    private function pdo(): \PDO
    {
        return new \PDO('sqlite:'.$this->database());
    }

    private function columns(string $table): array
    {
        return array_column($this->pdo()->query("pragma table_info({$table})")->fetchAll(\PDO::FETCH_ASSOC), 'name');
    }

    /** @return array<string, int> every table's row count */
    private function rowCounts(): array
    {
        $pdo = $this->pdo();
        $counts = [];
        foreach ($pdo->query("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name")->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $counts[$table] = (int) $pdo->query("select count(*) from \"{$table}\"")->fetchColumn();
        }

        return $counts;
    }

    private function schema(): array
    {
        return $this->pdo()->query("select type, name, sql from sqlite_master where name not like 'sqlite_%' order by type, name")->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function migrationFiles(): int
    {
        return count(glob(database_path('migrations/*.php')));
    }

    private function useSample(string $name): void
    {
        $from = self::SAMPLES.'/'.$name;
        if (! is_dir($from)) {
            $this->markTestSkipped("Sample {$from} is not on this computer.");
        }
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($from.'/database.sqlite'.$suffix)) {
                copy($from.'/database.sqlite'.$suffix, $this->database().$suffix);
            }
        }
    }

    private function assertNoCallToElectron(): void
    {
        $this->assertSame('0', trim((string) file_get_contents($this->connections)), 'A migration called Electron\'s API while Electron waits for it.');
    }

    private function assertFullyMigrated(): void
    {
        $pdo = $this->pdo();
        $this->assertSame($this->migrationFiles(), (int) $pdo->query('select count(*) from migrations')->fetchColumn());
        $this->assertSame(1, array_count_values($this->columns('local_auth'))['password_hash'] ?? 0);
        foreach (['pending_handouts', 'pending_list_additions', 'cache', 'jobs'] as $table) {
            $this->assertContains($table, array_keys($this->rowCounts()));
        }
    }

    public function test_a_fresh_database_migrates_without_calling_electron(): void
    {
        touch($this->database());

        $this->migrate();

        $this->assertFullyMigrated();
        $this->assertNoCallToElectron();
        // The key is made by artisan unprotected; the app protects it at its first page.
        $keyFile = $this->dir.'/storage/app/local-data.key';
        $key = is_file($keyFile) ? json_decode((string) file_get_contents($keyFile), true) : ['protected' => 'not made'];
        $this->assertContains($key['protected'], ['none', 'not made']);
    }

    public function test_a_real_2_0_2_database_migrates_and_keeps_every_row(): void
    {
        $this->useSample('db-2.0.2');
        $before = $this->rowCounts();

        $this->migrate();

        $this->assertFullyMigrated();
        $this->assertNoCallToElectron();
        $after = $this->rowCounts();
        foreach ($before as $table => $rows) {
            if ($table === 'migrations') {
                continue;
            }
            $this->assertSame($rows, $after[$table] ?? null, "Rows of {$table} changed.");
        }
        // Personal data is encrypted now, with a backup made first when there was any.
        $status = $this->artisan_(['elikas:local-data', 'status']);
        $status->run();
        $this->assertStringContainsString('Personal values still in plain text: 0', $status->getOutput(), $status->getErrorOutput());
    }

    public function test_a_2_1_0_database_left_half_migrated_by_the_failure_migrates(): void
    {
        $this->useSample('db-2.1.0-fresh-failed');
        $this->assertContains('password_hash', $this->columns('local_auth'), 'The sample has the columns 2.1.0 left behind.');

        $this->migrate();

        $this->assertFullyMigrated();
        $this->assertNoCallToElectron();
    }

    public function test_the_columns_2_1_0_left_behind_are_not_added_again(): void
    {
        touch($this->database());
        $this->migrate();
        // As 2.1.0 left it: the columns there, the migrations after 037 not recorded.
        $this->pdo()->exec("delete from migrations where migration > '2026_11_23_000037'");

        $this->migrate();

        $this->assertFullyMigrated();
    }

    public function test_running_migrate_twice_changes_nothing(): void
    {
        touch($this->database());
        $this->migrate();
        $schema = $this->schema();
        $rows = $this->rowCounts();

        $second = $this->migrate();

        $this->assertStringContainsString('Nothing to migrate', $second->getOutput());
        $this->assertSame($schema, $this->schema());
        $this->assertSame($rows, $this->rowCounts());
    }

    public function test_two_migrates_started_together_both_succeed(): void
    {
        touch($this->database());
        $first = $this->migrateProcess();
        $second = $this->migrateProcess();

        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        $this->assertSame(0, $first->getExitCode(), $first->getOutput().$first->getErrorOutput());
        $this->assertSame(0, $second->getExitCode(), $second->getOutput().$second->getErrorOutput());
        $this->assertFullyMigrated();
    }

    public function test_with_a_key_protected_by_windows_the_encryption_waits_for_the_app(): void
    {
        $this->useSample('db-2.1.0-fresh-failed');
        // A key only the running app can open (as after the app's first page).
        file_put_contents($this->dir.'/storage/app/local-data.key', json_encode(['v' => 1, 'cipher' => 'aes-256-gcm', 'protected' => 'windows-account', 'key' => 'opaque']));

        $this->migrate();

        $this->assertFullyMigrated();
        $this->assertNoCallToElectron();
        // Deferred to the running app, which opens the key and finishes it.
        $flag = $this->pdo()->query("select value from app_state where key = 'local_data_encryption_pending'")->fetchColumn();
        $this->assertSame('true', $flag);
    }
}

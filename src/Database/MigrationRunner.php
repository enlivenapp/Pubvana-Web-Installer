<?php

namespace CI4Installer\Database;

use CI4Installer\Result;
use Throwable;

/**
 * Bootstraps a CI4 application programmatically and runs its migrations/seeders.
 *
 * Two execution strategies are provided for each operation:
 *  - Direct bootstrap (runMigrations / runSeeder): loads CI4 in-process.
 *  - Exec fallback (runMigrationsViaExec / runSeederViaExec): shells out to spark.
 */
class MigrationRunner
{
    /** Absolute path to the root of the installed CI4 application. */
    private string $appRoot;

    /** Tracks whether the CI4 environment has already been bootstrapped. */
    private bool $bootstrapped = false;

    /**
     * @param string $appRoot Absolute path to the installed CI4 application root
     *                        (the directory that contains spark, app/, public/, etc.)
     */
    public function __construct(string $appRoot)
    {
        $this->appRoot = rtrim($appRoot, '/\\');
    }

    // ---------------------------------------------------------------------------
    // Public API — direct bootstrap
    // ---------------------------------------------------------------------------

    /**
     * Bootstraps CI4 in-process and runs all pending migrations.
     *
     * Uses the CI4 Migrations service (`service('migrations')`) and calls
     * `latest()` to bring the schema up to date.
     */
    public function runMigrations(): Result
    {
        try {
            $this->bootstrap();

            /** @var \CodeIgniter\Database\MigrationRunner $runner */
            $runner = service('migrations');
            $runner->latest();

            return Result::ok('All migrations applied successfully.');
        } catch (Throwable $e) {
            return Result::fail('Migration failed: ' . $e->getMessage());
        }
    }

    /**
     * Bootstraps CI4 in-process and runs the named database seeder.
     *
     * @param string $className Fully-qualified seeder class name (e.g. 'DatabaseSeeder').
     */
    public function runSeeder(string $className): Result
    {
        try {
            $this->bootstrap();

            $seeder = \Config\Database::seeder();
            $seeder->call($className);

            return Result::ok("Seeder '{$className}' completed successfully.");
        } catch (Throwable $e) {
            return Result::fail("Seeder '{$className}' failed: " . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------------------
    // Public API — exec fallback
    // ---------------------------------------------------------------------------

    /**
     * Runs migrations by shelling out to spark.
     *
     * Prefer this when bootstrapping in-process is not possible (e.g. the
     * installer runs inside a different PHP version / environment than the app).
     */
    public function runMigrationsViaExec(): Result
    {
        $spark  = escapeshellarg($this->appRoot . '/spark');
        $command = 'php ' . $spark . ' migrate --all 2>&1';

        exec($command, $output, $code);

        $outputStr = implode("\n", $output);

        if ($code !== 0) {
            return Result::fail(
                "spark migrate failed (exit {$code}):\n{$outputStr}"
            );
        }

        return Result::ok($outputStr ?: 'All migrations applied successfully.');
    }

    /**
     * Runs a seeder by shelling out to spark.
     *
     * @param string $className Seeder class name passed to `spark db:seed`.
     */
    public function runSeederViaExec(string $className): Result
    {
        $spark    = escapeshellarg($this->appRoot . '/spark');
        $seedArg  = escapeshellarg($className);
        $command  = 'php ' . $spark . ' db:seed ' . $seedArg . ' 2>&1';

        exec($command, $output, $code);

        $outputStr = implode("\n", $output);

        if ($code !== 0) {
            return Result::fail(
                "spark db:seed failed (exit {$code}):\n{$outputStr}"
            );
        }

        return Result::ok($outputStr ?: "Seeder '{$className}' completed successfully.");
    }

    // ---------------------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------------------

    /**
     * Bootstraps the CI4 application environment exactly once.
     *
     * Defines the path constants that CI4 requires and then loads the framework
     * bootstrap file. Subsequent calls are no-ops.
     *
     * @throws \RuntimeException if required files are not found.
     */
    private function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        require_once $this->appRoot . '/vendor/autoload.php';
        require_once $this->appRoot . '/app/Config/Paths.php';
        $paths = new \Config\Paths();
        if (! defined('ENVIRONMENT')) {
            define('ENVIRONMENT', $_ENV['CI_ENVIRONMENT'] ?? $_SERVER['CI_ENVIRONMENT'] ?? 'production');
        }
        require_once $paths->systemDirectory . '/Boot.php';
        \CodeIgniter\Boot::bootConsole($paths);

        $this->bootstrapped = true;
    }
}

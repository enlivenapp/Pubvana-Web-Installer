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

        $appRoot = $this->appRoot;

        // Define CI4's required path constants (only if not already defined).
        if (! defined('ROOTPATH')) {
            define('ROOTPATH', $appRoot . DIRECTORY_SEPARATOR);
        }

        if (! defined('APPPATH')) {
            define('APPPATH', $appRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR);
        }

        if (! defined('WRITABLEPATH')) {
            define('WRITABLEPATH', $appRoot . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR);
        }

        if (! defined('SYSTEMPATH')) {
            $systemPath = $appRoot . '/vendor/codeigniter4/framework/system/';

            if (! is_dir($systemPath)) {
                // Some installations use the split package name.
                $systemPath = $appRoot . '/vendor/codeigniter4/codeigniter4/system/';
            }

            if (! is_dir($systemPath)) {
                throw new \RuntimeException(
                    "CI4 system path not found. Checked:\n"
                    . "  {$appRoot}/vendor/codeigniter4/framework/system/\n"
                    . "  {$appRoot}/vendor/codeigniter4/codeigniter4/system/\n"
                    . 'Ensure Composer dependencies are installed.'
                );
            }

            define('SYSTEMPATH', $systemPath);
        }

        // Load CI4's bootstrap utility (registers autoloaders, helpers, etc.).
        $bootstrapFile = SYSTEMPATH . 'bootstrap.php';

        if (! file_exists($bootstrapFile)) {
            // Older CI4 versions used a different file name.
            $bootstrapFile = SYSTEMPATH . 'util_bootstrap.php';
        }

        if (! file_exists($bootstrapFile)) {
            throw new \RuntimeException(
                "CI4 bootstrap file not found at '{$bootstrapFile}'."
            );
        }

        require_once $bootstrapFile;

        $this->bootstrapped = true;
    }
}

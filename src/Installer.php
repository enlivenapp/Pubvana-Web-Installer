<?php

namespace CI4Installer;

use CI4Installer\Auth\AuthAdapterFactory;
use CI4Installer\Config\ConfigValidator;
use CI4Installer\Config\EnvWriter;
use CI4Installer\Database\ConnectionTester;
use CI4Installer\Database\MigrationRunner;
use CI4Installer\Environment\Detector;
use CI4Installer\Environment\Requirements;
use CI4Installer\Environment\ServerEnvironment;
use CI4Installer\Filesystem\FilesystemFactory;
use CI4Installer\Filesystem\FilesystemInterface;
use CI4Installer\Source\SourceFactory;
use CI4Installer\UI\WizardRenderer;
use Throwable;

/**
 * Main orchestrator for the CI4 Installer.
 *
 * Instantiated and executed by install.php:
 *   (new Installer(__DIR__))->run();
 *
 * Responsibilities:
 *   - Session management (start, read, write, clear)
 *   - CSRF protection on every POST
 *   - Step routing (GET/POST → handler method)
 *   - Orchestration of all subsystems
 *   - AJAX substep dispatch and JSON responses
 *   - Cleanup on completion
 */
class Installer
{
    /** Where install.php lives and where the app will be installed. */
    private string $targetDir;

    /**
     * Where this Installer.php file was extracted to (the temp dir).
     * Since this file is at {extractedDir}/Installer.php, we derive it from __DIR__.
     */
    private string $extractedDir;

    /** Loaded from installer-config.php and populated with defaults. */
    private array $config;

    /** Template engine for rendering wizard steps. */
    private WizardRenderer $renderer;

    /** Populated after environment detection (cached in session). */
    private ?ServerEnvironment $env = null;

    /** Filesystem driver (cached after first use). */
    private ?FilesystemInterface $fs = null;

    // -------------------------------------------------------------------------
    // Session keys
    // -------------------------------------------------------------------------

    private const SESS_ENV           = 'ci4_installer_env';
    private const SESS_FS_METHOD     = 'ci4_installer_fs_method';
    private const SESS_FS_CREDS      = 'ci4_installer_fs_creds';
    private const SESS_DB_DRIVER     = 'ci4_installer_db_driver';
    private const SESS_DB_CREDS      = 'ci4_installer_db_creds';
    private const SESS_CONFIGURATION = 'ci4_installer_configuration';
    private const SESS_APP_SETTINGS  = 'ci4_installer_app_settings';
    private const SESS_ADMIN         = 'ci4_installer_admin';
    private const SESS_INSTALL_DIR   = 'ci4_installer_install_dir';
    private const SESS_SUBSTEPS      = 'ci4_installer_substeps';

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param string $targetDir Absolute path to the directory containing install.php.
     */
    public function __construct(string $targetDir)
    {
        $this->targetDir    = rtrim($targetDir, '/\\');
        $this->extractedDir = rtrim(__DIR__, '/\\');

        // Load developer configuration.
        $configFile = $this->targetDir . '/installer-config.php';

        if (! file_exists($configFile)) {
            $this->die(
                'installer-config.php not found',
                "Expected at: <code>{$configFile}</code><br>"
                . "Create installer-config.php in the same directory as install.php."
            );
        }

        $config = require $configFile;

        if (! is_array($config)) {
            $this->die(
                'installer-config.php must return an array',
                "The file was loaded but did not return a PHP array. "
                . "Ensure it ends with <code>return [...];</code>"
            );
        }

        // Validate before applying defaults (so we catch developer mistakes).
        $errors = ConfigValidator::validate($config);

        if ($errors !== []) {
            $list = implode('</li><li>', array_map('htmlspecialchars', $errors));
            $this->die(
                'installer-config.php has configuration errors',
                "<ul><li>{$list}</li></ul>"
            );
        }

        $this->config = ConfigValidator::withDefaults($config);

        // Determine template directory (shipped with installer source).
        $templateDir = $this->extractedDir . '/UI/templates';

        $this->renderer = new WizardRenderer($templateDir, $this->config);
    }

    // -------------------------------------------------------------------------
    // Main entry point
    // -------------------------------------------------------------------------

    /**
     * Handle the current HTTP request and output the response.
     */
    public function run(): void
    {
        $this->startSession();

        $step = $this->resolveStep();

        // CSRF check on every POST.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['csrf_token'] ?? '';
            if (! $this->renderer->validateCsrf($token)) {
                // For AJAX substep requests return JSON; otherwise show error page.
                if ($this->isAjaxSubstepRequest()) {
                    $this->jsonResponse(['success' => false, 'message' => 'CSRF token invalid. Please refresh and try again.']);
                    return;
                }
                $this->die('Security Error', 'CSRF token invalid or missing. Please go back and try again.');
            }
        }

        // Route to the appropriate handler.
        $html = match ($step) {
            'welcome'       => $this->handleWelcome(),
            'system-check'  => $this->handleSystemCheck(),
            'filesystem'    => $this->handleFilesystem(),
            'database'      => $this->handleDatabase(),
            'configuration' => $this->handleConfiguration(),
            'app-settings'  => $this->handleAppSettings(),
            'admin'         => $this->handleAdmin(),
            'install'       => $this->handleInstall(),
            'complete'      => $this->handleComplete(),
            default         => $this->handleWelcome(),
        };

        // handleInstall() may have already output JSON and returned null.
        if ($html !== null) {
            echo $html;
        }
    }

    // -------------------------------------------------------------------------
    // Session bootstrap
    // -------------------------------------------------------------------------

    /**
     * Start the PHP session with secure settings.
     * Respects HTTPS environment for the Secure cookie flag.
     */
    private function startSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            // Session already active (e.g. WizardRenderer started it).
            return;
        }

        $isHttps = $this->detectHttps();

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        @session_start();
    }

    // -------------------------------------------------------------------------
    // Step handlers
    // -------------------------------------------------------------------------

    /**
     * Welcome step — branding info, intro copy, nothing collected yet.
     */
    private function handleWelcome(): string
    {
        return $this->renderer->render('welcome', [
            'branding' => $this->config['branding'],
        ]);
    }

    /**
     * System check step — run environment detection and requirements check.
     */
    private function handleSystemCheck(): string
    {
        // Use cached environment from session if available.
        $env = $this->loadEnvFromSession();

        if ($env === null) {
            $detector = new Detector($this->targetDir);
            $env      = $detector->detect();
            $this->saveEnvToSession($env);
        }

        $this->env = $env;

        $requirements = new Requirements($env, $this->config);
        $checks       = $requirements->check();
        $hasBlockers  = $requirements->hasBlockers();

        return $this->renderer->render('system-check', [
            'env'         => $env,
            'checks'      => $checks,
            'hasBlockers' => $hasBlockers,
        ]);
    }

    /**
     * Filesystem step — detect the available write method.
     *
     * If DirectDriver is detected, auto-advance by storing the method in session.
     * If credentials are POSTed for FTP/SSH, create and test the driver.
     */
    private function handleFilesystem(): string
    {
        $directDriver = FilesystemFactory::detect($this->targetDir);

        if ($directDriver !== null) {
            // Direct access available — store and skip credential collection.
            $this->saveToSession(self::SESS_FS_METHOD, 'direct');
            $this->saveToSession(self::SESS_FS_CREDS, []);
        }

        $method = $this->getFromSession(self::SESS_FS_METHOD);
        $error  = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $directDriver === null) {
            $fsMethod = $_POST['fs_method'] ?? '';
            $host     = trim($_POST['fs_host']     ?? '');
            $user     = trim($_POST['fs_user']     ?? '');
            $pass     = $_POST['fs_pass']           ?? '';
            $port     = (int) ($_POST['fs_port']   ?? 0);

            try {
                $driver = match ($fsMethod) {
                    'ftps' => FilesystemFactory::createFtps($host, $user, $pass, $port > 0 ? $port : 21),
                    'ftp'  => FilesystemFactory::createFtp($host, $user, $pass, $port > 0 ? $port : 21),
                    'ssh2' => FilesystemFactory::createSsh2($host, $user, $pass, $port > 0 ? $port : 22),
                    default => null,
                };

                if ($driver === null) {
                    $error = 'Unknown filesystem method selected.';
                } else {
                    // Test the driver by checking if the target dir exists.
                    if ($driver->exists($this->targetDir)) {
                        $this->saveToSession(self::SESS_FS_METHOD, $fsMethod);
                        $this->saveToSession(self::SESS_FS_CREDS, [
                            'host' => $host,
                            'user' => $user,
                            'pass' => $pass,
                            'port' => $port,
                        ]);
                        $this->fs = $driver;
                        $method   = $fsMethod;
                    } else {
                        $error = "Connection succeeded but target directory is not accessible: {$this->targetDir}";
                    }
                }
            } catch (Throwable $e) {
                $error = 'Connection failed: ' . $e->getMessage();
            }
        }

        return $this->renderer->render('filesystem', [
            'detectedMethod' => $directDriver !== null ? 'direct' : null,
            'method'         => $method,
            'error'          => $error,
        ]);
    }

    /**
     * Database step — collect and test database credentials.
     */
    private function handleDatabase(): string
    {
        $env     = $this->getServerEnvironment();
        $error   = null;
        $success = null;
        $driver  = $this->getFromSession(self::SESS_DB_DRIVER, '');
        $creds   = $this->getFromSession(self::SESS_DB_CREDS, []);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action   = $_POST['action'] ?? 'test';
            $driver   = trim($_POST['db_driver']   ?? '');
            $hostname = trim($_POST['db_hostname']  ?? '127.0.0.1');
            $port     = trim($_POST['db_port']      ?? '');
            $database = trim($_POST['db_database']  ?? '');
            $username = trim($_POST['db_username']  ?? '');
            $password = $_POST['db_password']        ?? '';
            $path     = trim($_POST['db_path']      ?? '');

            $creds = [
                'hostname' => $hostname,
                'port'     => $port !== '' ? (int) $port : null,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'path'     => $path,
            ];

            // Remove null port so the tester uses its own default.
            if ($creds['port'] === null) {
                unset($creds['port']);
            }

            $tester = new ConnectionTester();

            if ($action === 'create_db') {
                $result = $tester->createDatabase($driver, $creds);
                if ($result->success) {
                    $success = $result->content ?: "Database created successfully.";
                    // Now test the connection for real.
                    $testResult = $tester->test($driver, $creds);
                    if ($testResult->success) {
                        $this->saveToSession(self::SESS_DB_DRIVER, $driver);
                        $this->saveToSession(self::SESS_DB_CREDS, $creds);
                    } else {
                        $error = $testResult->errorMessage;
                    }
                } else {
                    $error = $result->errorMessage;
                }
            } else {
                // Standard connection test.
                $result = $tester->test($driver, $creds);
                if ($result->success) {
                    $this->saveToSession(self::SESS_DB_DRIVER, $driver);
                    $this->saveToSession(self::SESS_DB_CREDS, $creds);
                    $success = 'Connection successful.';
                } else {
                    $error = $result->errorMessage;
                }
            }
        }

        // Build available drivers list (only those present on the server).
        $availableDrivers = [];
        foreach ($env->dbDrivers as $driverName => $status) {
            if ($status === 'passed') {
                // Map PHP extension name to CI4 driver name.
                $ci4Driver = $this->mapDbExtToDriver($driverName);
                if ($ci4Driver !== null) {
                    $availableDrivers[$ci4Driver] = $driverName;
                }
            }
        }

        return $this->renderer->render('database', [
            'availableDrivers' => $availableDrivers,
            'driver'           => $driver,
            'creds'            => $creds,
            'error'            => $error,
            'success'          => $success,
            'savedDriver'      => $this->getFromSession(self::SESS_DB_DRIVER, ''),
        ]);
    }

    /**
     * Configuration step — base URL, CI environment, encryption key.
     */
    private function handleConfiguration(): string
    {
        $error   = null;
        $saved   = $this->getFromSession(self::SESS_CONFIGURATION, []);

        // Defaults / auto-detected values.
        $defaults = [
            'base_url'       => $this->detectBaseUrl(),
            'environment'    => 'production',
            'encryption_key' => $this->generateEncryptionKey(),
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $baseUrl       = trim($_POST['base_url']        ?? '');
            $environment   = trim($_POST['environment']     ?? 'production');
            $encryptionKey = trim($_POST['encryption_key']  ?? '');

            // Basic validation.
            if ($baseUrl === '') {
                $error = 'Base URL is required.';
            } elseif (! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
                $error = 'Base URL does not appear to be a valid URL.';
            } elseif (! in_array($environment, ['production', 'development', 'testing'], true)) {
                $error = 'Environment must be one of: production, development, testing.';
            } elseif (strlen($encryptionKey) < 32) {
                $error = 'Encryption key must be at least 32 characters.';
            }

            if ($error === null) {
                $saved = [
                    'base_url'       => rtrim($baseUrl, '/') . '/',
                    'environment'    => $environment,
                    'encryption_key' => $encryptionKey,
                ];
                $this->saveToSession(self::SESS_CONFIGURATION, $saved);
            }
        }

        $values = $saved + $defaults;

        return $this->renderer->render('configuration', [
            'values'   => $values,
            'defaults' => $defaults,
            'error'    => $error,
        ]);
    }

    /**
     * App settings step — render developer-defined env_vars from config.
     * If no env_vars in config, skip to the next step.
     */
    private function handleAppSettings(): string
    {
        $envVars = $this->config['env_vars'] ?? [];

        if (empty($envVars)) {
            // Nothing to collect — skip.
            $this->redirect('admin');
            return '';
        }

        $error  = null;
        $saved  = $this->getFromSession(self::SESS_APP_SETTINGS, []);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $values = [];
            $missingRequired = [];

            foreach ($envVars as $varDef) {
                $key      = $varDef['key']      ?? '';
                $required = $varDef['required']  ?? false;
                $type     = $varDef['type']      ?? 'text';

                if ($key === '') {
                    continue;
                }

                if ($type === 'boolean') {
                    $values[$key] = isset($_POST[$key]) ? 'true' : 'false';
                } else {
                    $value = trim($_POST[$key] ?? '');

                    if ($required && $value === '') {
                        $missingRequired[] = $varDef['label'] ?? $key;
                    }

                    // Pattern validation.
                    if ($value !== '' && isset($varDef['pattern'])) {
                        $pattern = $varDef['pattern'];
                        if (@preg_match($pattern, $value) === 0) {
                            $label = $varDef['label'] ?? $key;
                            $error = "Value for '{$label}' does not match the required format.";
                            break;
                        }
                    }

                    $values[$key] = $value !== '' ? $value : ($varDef['default'] ?? '');
                }
            }

            if ($error === null && ! empty($missingRequired)) {
                $error = 'The following fields are required: ' . implode(', ', $missingRequired);
            }

            if ($error === null) {
                $this->saveToSession(self::SESS_APP_SETTINGS, $values);
                $saved = $values;
            }
        }

        // Group env_vars for rendering.
        $grouped = $this->groupEnvVars($envVars);

        return $this->renderer->render('app-settings', [
            'envVars' => $envVars,
            'grouped' => $grouped,
            'saved'   => $saved,
            'error'   => $error,
        ]);
    }

    /**
     * Admin account step — collect admin credentials via the auth adapter.
     * If auth.system is 'none', skip.
     */
    private function handleAdmin(): string
    {
        $authSystem = $this->config['auth']['system'] ?? 'none';

        if ($authSystem === 'none') {
            $this->redirect('install');
            return '';
        }

        $dbCreds = $this->getFromSession(self::SESS_DB_CREDS, []);
        $adapter = AuthAdapterFactory::create($this->config, $this->targetDir, $dbCreds);
        $fields  = $adapter->getFields();
        $error   = null;
        $saved   = $this->getFromSession(self::SESS_ADMIN, []);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $values  = [];
            $missing = [];

            foreach ($fields as $field) {
                $key      = $field['name']     ?? '';
                $required = $field['required']  ?? true;
                $value    = trim($_POST[$key]   ?? '');

                if ($key === '') {
                    continue;
                }

                if ($required && $value === '') {
                    $missing[] = $field['label'] ?? $key;
                    continue;
                }

                $values[$key] = $value;
            }

            // Password confirmation check.
            $password        = $values['password']         ?? '';
            $passwordConfirm = trim($_POST['password_confirm'] ?? '');

            if ($password !== '' && $passwordConfirm !== '' && $password !== $passwordConfirm) {
                $error = 'Passwords do not match.';
            }

            if ($error === null && ! empty($missing)) {
                $error = 'The following fields are required: ' . implode(', ', $missing);
            }

            if ($error === null) {
                $this->saveToSession(self::SESS_ADMIN, $values);
                $saved = $values;
            }
        }

        return $this->renderer->render('admin', [
            'authSystem' => $authSystem,
            'fields'     => $fields,
            'saved'      => $saved,
            'error'      => $error,
        ]);
    }

    /**
     * Install step — progress UI or AJAX substep dispatch.
     *
     * GET (no substep): render the installation progress page.
     * POST/GET with substep: execute the substep and return JSON.
     */
    private function handleInstall(): ?string
    {
        $substep = $_POST['substep'] ?? $_GET['substep'] ?? null;

        if ($substep !== null) {
            // AJAX substep execution.
            @set_time_limit(300);

            try {
                $result = match ((string) $substep) {
                    'download'    => $this->substepDownload(),
                    'extract'     => $this->substepExtract(),
                    'validate'    => $this->substepValidate(),
                    'public_dir'  => $this->substepPublicDir(),
                    'write_env'   => $this->substepWriteEnv(),
                    'permissions' => $this->substepPermissions(),
                    'migrations'  => $this->substepMigrations(),
                    'seeders'     => $this->substepSeeders(),
                    'admin'       => $this->substepAdmin(),
                    default       => ['success' => false, 'message' => "Unknown substep: {$substep}"],
                };
            } catch (Throwable $e) {
                $result = [
                    'success' => false,
                    'message' => 'Unexpected error: ' . $e->getMessage(),
                ];
            }

            // Mark completion in session so the UI can track status.
            if (! empty($result['success'])) {
                $completed = $this->getFromSession(self::SESS_SUBSTEPS, []);
                $completed[$substep] = true;
                $this->saveToSession(self::SESS_SUBSTEPS, $completed);
            }

            $this->jsonResponse($result);
            return null;
        }

        // Render the install progress page.
        return $this->renderer->render('install', [
            'substeps'   => $this->buildSubstepList(),
            'csrfToken'  => $this->renderer->getCsrfToken(),
        ]);
    }

    /**
     * Complete step — self-delete, clean up, clear session.
     */
    private function handleComplete(): string
    {
        $fs = $this->getFilesystem();

        // Attempt to delete install.php via filesystem abstraction.
        $installPhp = $this->targetDir . '/install.php';
        $deleted    = false;

        if (file_exists($installPhp)) {
            $deleteResult = $fs->delete($installPhp);
            $deleted      = $deleteResult->success;
        }

        // Fallback: create install.lock if delete failed.
        if (! $deleted) {
            $lockFile = $this->targetDir . '/install.lock';
            @file_put_contents($lockFile, date('Y-m-d H:i:s') . ' — Installation complete. Delete this file to re-run the installer.');
        }

        // Remove the temp extraction directory.
        $this->cleanupExtractedDir();

        // Clear session data.
        $this->clearInstallSession();

        $postInstallUrl = $this->config['post_install_url'] ?? '/';

        return $this->renderer->render('complete', [
            'selfDeleted'    => $deleted,
            'postInstallUrl' => $postInstallUrl,
            'branding'       => $this->config['branding'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Substep handlers
    // -------------------------------------------------------------------------

    /**
     * 4a. Download the application source.
     */
    private function substepDownload(): array
    {
        $installDir = $this->getFromSession(self::SESS_INSTALL_DIR);

        // State recovery: if download already completed, skip.
        if ($installDir !== null && is_dir($installDir) && $this->dirHasFiles($installDir)) {
            return ['success' => true, 'message' => 'Skipped — already complete.'];
        }

        $env    = $this->getServerEnvironment();
        $source = SourceFactory::getBestSource($this->config['source'] ?? [], $env);

        // Create a unique target directory for the download.
        $targetBase = sys_get_temp_dir() . '/ci4_install_' . md5($this->targetDir . getmypid());

        if (! is_dir($targetBase)) {
            @mkdir($targetBase, 0755, true);
        }

        $result = $source->download($targetBase);

        if (! $result->success) {
            return ['success' => false, 'message' => $result->errorMessage];
        }

        $downloadedDir = $result->content ?? $targetBase;

        $this->saveToSession(self::SESS_INSTALL_DIR, $downloadedDir);

        return ['success' => true, 'message' => 'Download complete.'];
    }

    /**
     * 4b. Extract and normalize the directory structure.
     */
    private function substepExtract(): array
    {
        $installDir = $this->getFromSession(self::SESS_INSTALL_DIR);

        if ($installDir === null || ! is_dir($installDir)) {
            return ['success' => false, 'message' => 'No downloaded source found. Please re-run the download step.'];
        }

        // State recovery: if key app files already exist, extraction is done.
        if (file_exists($installDir . '/spark') && is_dir($installDir . '/app')) {
            // Normalize nested directory if needed.
            $this->normalizeDirectory($installDir);
            return ['success' => true, 'message' => 'Skipped — already complete.'];
        }

        // The source's download() may return a zip path; in that case we need to extract.
        // Check if the directory contains a zip file that needs extracting.
        $zipFile = $this->findZipFile($installDir);

        if ($zipFile !== null) {
            $extractResult = $this->extractZip($zipFile, $installDir);

            if (! $extractResult['success']) {
                return $extractResult;
            }

            @unlink($zipFile);
        }

        // Normalize nested directory structure (GitHub-style archives create a subdirectory).
        $normalized = $this->normalizeDirectory($installDir);

        return ['success' => true, 'message' => $normalized ? 'Extracted and normalized.' : 'Extracted successfully.'];
    }

    /**
     * 4c. Validate the downloaded application and run composer install if needed.
     */
    private function substepValidate(): array
    {
        $installDir = $this->getFromSession(self::SESS_INSTALL_DIR);

        if ($installDir === null || ! is_dir($installDir)) {
            return ['success' => false, 'message' => 'Source directory not found. Please re-run the download step.'];
        }

        // State recovery.
        if (
            file_exists($installDir . '/vendor/autoload.php')
            && file_exists($installDir . '/spark')
            && file_exists($installDir . '/app/Config/App.php')
        ) {
            return ['success' => true, 'message' => 'Skipped — already complete.'];
        }

        // Check for required files.
        $missing = [];

        if (! file_exists($installDir . '/spark')) {
            $missing[] = 'spark';
        }

        if (! file_exists($installDir . '/app/Config/App.php')) {
            $missing[] = 'app/Config/App.php';
        }

        if (! empty($missing)) {
            return [
                'success' => false,
                'message' => 'Downloaded source is incomplete. Missing: ' . implode(', ', $missing)
                    . '. Ensure your source package is a valid CodeIgniter 4 application.',
            ];
        }

        // Check for vendor directory.
        if (! file_exists($installDir . '/vendor/autoload.php')) {
            $env = $this->getServerEnvironment();

            if ($env->composerAvailable === 'passed' && $env->execAvailable === 'passed') {
                // Run composer install.
                $composerCmd = $env->composerPath ?: 'composer';
                $targetArg   = escapeshellarg($installDir);
                $cmd         = "cd {$targetArg} && {$composerCmd} install --no-dev --no-interaction 2>&1";

                $output = [];
                $code   = 0;
                @exec($cmd, $output, $code);

                if ($code !== 0) {
                    return [
                        'success' => false,
                        'message' => 'composer install failed: ' . implode("\n", $output),
                    ];
                }

                if (! file_exists($installDir . '/vendor/autoload.php')) {
                    return [
                        'success' => false,
                        'message' => 'composer install completed but vendor/autoload.php was not created.',
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'message' => 'vendor/autoload.php is missing and Composer is not available. '
                        . 'Please use a release package that includes the vendor directory.',
                ];
            }
        }

        return ['success' => true, 'message' => 'Validation complete — all required files present.'];
    }

    /**
     * 4d. Handle the public/ directory convention.
     *
     * Implements the full strategy cascade: isolate → htaccess → flatten → none.
     */
    private function substepPublicDir(): array
    {
        $installDir = $this->getFromSession(self::SESS_INSTALL_DIR);

        if ($installDir === null || ! is_dir($installDir)) {
            return ['success' => false, 'message' => 'Source directory not found.'];
        }

        $handling = $this->config['public_dir_handling'] ?? 'auto';

        // Pre-check: if index.php already exists at root of installDir, the developer
        // already restructured the zip — skip this entire step.
        if (file_exists($installDir . '/index.php') && ! is_dir($installDir . '/public')) {
            return ['success' => true, 'message' => 'Skipped — developer zip already restructured (index.php at root).'];
        }

        // If there is no public/ directory, there is nothing to handle.
        if (! is_dir($installDir . '/public')) {
            return ['success' => true, 'message' => 'No public/ directory found — nothing to restructure.'];
        }

        $env = $this->getServerEnvironment();

        switch ($handling) {
            case 'none':
                return ['success' => true, 'message' => 'Skipped — public_dir_handling is none.'];

            case 'isolate':
                $result = $this->strategyIsolate($installDir, $env);
                if ($result['success']) {
                    return $result;
                }
                return ['success' => false, 'message' => $result['message']];

            case 'htaccess':
                $result = $this->strategyHtaccess($installDir, $env);
                if ($result['success']) {
                    return $result;
                }
                return ['success' => false, 'message' => $result['message']];

            case 'flatten':
                return $this->strategyFlatten($installDir);

            case 'auto':
            default:
                // Try isolate → htaccess → flatten.
                $warnings = [];

                $result = $this->strategyIsolate($installDir, $env);
                if ($result['success']) {
                    return $result;
                }
                $warnings[] = 'Isolate failed: ' . $result['message'];

                $result = $this->strategyHtaccess($installDir, $env);
                if ($result['success']) {
                    $result['message'] = implode('; ', $warnings) . ' — ' . $result['message'];
                    return $result;
                }
                $warnings[] = 'Htaccess failed: ' . $result['message'];

                $result = $this->strategyFlatten($installDir);
                $result['warning'] = implode('; ', $warnings)
                    . ' — Fell back to flatten strategy. '
                    . 'Application source files are inside the web root, protected only by access rules.';
                return $result;
        }
    }

    /**
     * Strategy 1: Move app files outside the document root (most secure).
     */
    private function strategyIsolate(string $installDir, ServerEnvironment $env): array
    {
        $docRoot = $env->documentRoot;

        if ($docRoot === '' || ! is_dir($docRoot)) {
            return ['success' => false, 'message' => 'Document root not detectable.'];
        }

        // The parent of the document root.
        $parentDir = dirname(rtrim($docRoot, '/\\'));

        if ($parentDir === $docRoot || $parentDir === '' || $parentDir === '.') {
            return ['success' => false, 'message' => 'Cannot determine parent directory above document root.'];
        }

        // Test writability of the parent directory.
        $testFile = $parentDir . '/.ci4_write_test_' . uniqid('', true);
        if (@file_put_contents($testFile, 'test') === false) {
            return ['success' => false, 'message' => 'Parent directory above document root is not writable.'];
        }
        @unlink($testFile);

        // Create a dedicated subdirectory (named by app).
        $appName    = preg_replace('/[^a-z0-9_-]/i', '_', $this->config['branding']['name'] ?? 'ci4app');
        $appName    = strtolower($appName);
        $outOfRoot  = $parentDir . '/' . $appName . '_ci4';

        if (! is_dir($outOfRoot) && ! @mkdir($outOfRoot, 0755, true)) {
            return ['success' => false, 'message' => "Cannot create out-of-root directory: {$outOfRoot}"];
        }

        // Files/directories to move outside the web root.
        $toMove = ['app', 'writable', 'vendor', 'system', 'spark', 'composer.json', 'composer.lock', 'env', 'tests'];
        $moved  = [];

        foreach ($toMove as $item) {
            $src = $installDir . '/' . $item;
            $dst = $outOfRoot . '/' . $item;

            if (! file_exists($src)) {
                continue;
            }

            if (! @rename($src, $dst)) {
                // Rollback any already-moved items.
                foreach ($moved as $movedDst => $movedSrc) {
                    @rename($movedDst, $movedSrc);
                }
                // Clean up the out-of-root directory.
                $this->removeDir($outOfRoot);
                return [
                    'success' => false,
                    'message' => "Failed to move '{$item}' to '{$outOfRoot}'. Rolled back.",
                ];
            }

            $moved[$dst] = $src;
        }

        // Move public/ contents into the target (document root) directory.
        $publicDir = $installDir . '/public';
        if (is_dir($publicDir)) {
            $this->mergeDir($publicDir, $this->targetDir);
            $this->removeDir($publicDir);
        }

        // Update app/Config/Paths.php with the new out-of-root paths.
        $pathsFile = $outOfRoot . '/app/Config/Paths.php';
        if (file_exists($pathsFile)) {
            $this->updatePathsConfig($pathsFile, $outOfRoot);
        }

        // Update public/index.php (now at targetDir/index.php) to point to Paths.php.
        $indexFile = $this->targetDir . '/index.php';
        if (file_exists($indexFile)) {
            $this->updateIndexPhp($indexFile, $outOfRoot);
        }

        // Update the install dir in session to reflect the new app root.
        $this->saveToSession(self::SESS_INSTALL_DIR, $outOfRoot);

        return ['success' => true, 'message' => "App files isolated to {$outOfRoot}. Only public/ contents remain in document root."];
    }

    /**
     * Strategy 2: Write .htaccess/server config to route requests to public/.
     */
    private function strategyHtaccess(string $installDir, ServerEnvironment $env): array
    {
        $server = $env->serverSoftware;

        if ($server === 'Nginx') {
            // Cannot auto-configure Nginx — provide instructions.
            $nginxConf = $this->buildNginxConfig($installDir);
            $this->saveToSession('nginx_conf_instructions', $nginxConf);
            return [
                'success' => true,
                'message' => 'Nginx detected. Manual server configuration is required. Instructions have been saved.',
                'warning' => 'Nginx cannot be configured automatically. Please apply the Nginx config shown in the UI.',
                'requires_manual' => true,
                'manual_type'     => 'nginx',
                'manual_config'   => $nginxConf,
            ];
        }

        if ($server === 'IIS') {
            $webConfigPath = $installDir . '/web.config';
            $webConfig     = $this->buildWebConfig();
            if (@file_put_contents($webConfigPath, $webConfig) === false) {
                return ['success' => false, 'message' => 'Failed to write web.config for IIS.'];
            }
            return ['success' => true, 'message' => 'web.config written for IIS URL Rewrite.'];
        }

        // Apache / LiteSpeed.
        if ($env->modRewrite === 'failed') {
            return ['success' => false, 'message' => 'Apache mod_rewrite is disabled.'];
        }

        // Write root-level .htaccess.
        $rootHtaccess = "Options -Indexes\n"
            . "RewriteEngine On\n"
            . "RewriteCond %{REQUEST_FILENAME} !-f\n"
            . "RewriteCond %{REQUEST_FILENAME} !-d\n"
            . "RewriteRule ^(.*)$ public/index.php/$1 [QSA,L]\n";

        $htaccessPath = $installDir . '/.htaccess';

        if (@file_put_contents($htaccessPath, $rootHtaccess) === false) {
            return ['success' => false, 'message' => 'Failed to write root .htaccess file.'];
        }

        // Write deny .htaccess in sensitive directories.
        $denyHtaccess = "<IfModule authz_core_module>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !authz_core_module>\n"
            . "    Deny from all\n"
            . "</IfModule>\n";

        foreach (['app', 'vendor', 'writable', 'system'] as $dir) {
            $path = $installDir . '/' . $dir;
            if (is_dir($path)) {
                @file_put_contents($path . '/.htaccess', $denyHtaccess);
            }
        }

        return ['success' => true, 'message' => 'Root .htaccess written to route all requests to public/index.php.'];
    }

    /**
     * Strategy 3: Flatten — copy index.php and .htaccess from public/ to root.
     */
    private function strategyFlatten(string $installDir): array
    {
        $publicIndex    = $installDir . '/public/index.php';
        $publicHtaccess = $installDir . '/public/.htaccess';
        $rootIndex      = $installDir . '/index.php';
        $rootHtaccess   = $installDir . '/.htaccess';

        if (! file_exists($publicIndex)) {
            return ['success' => false, 'message' => 'public/index.php not found — cannot flatten.'];
        }

        // Copy index.php to root and update the $pathsConfig path reference.
        $indexContent = @file_get_contents($publicIndex);
        if ($indexContent === false) {
            return ['success' => false, 'message' => 'Failed to read public/index.php.'];
        }

        // Update the path to Paths.php inside index.php.
        $indexContent = preg_replace(
            '#\$pathsConfig\s*=\s*FCPATH\s*\.\s*\'[^\']+\'#',
            "\$pathsConfig = FCPATH . '../app/Config/Paths.php'",
            $indexContent
        );

        // Redefine FCPATH if needed.
        $indexContent = preg_replace(
            "#define\s*\(\s*'FCPATH'\s*,\s*[^)]+\)#",
            "define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR)",
            $indexContent
        );

        if (@file_put_contents($rootIndex, $indexContent) === false) {
            return ['success' => false, 'message' => 'Failed to write index.php to document root.'];
        }

        // Copy .htaccess from public/ to root.
        if (file_exists($publicHtaccess)) {
            @copy($publicHtaccess, $rootHtaccess);
        }

        // Write deny rules in sensitive directories.
        $denyHtaccess = "<IfModule authz_core_module>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !authz_core_module>\n"
            . "    Deny from all\n"
            . "</IfModule>\n";

        foreach (['app', 'vendor', 'writable', 'system'] as $dir) {
            $path = $installDir . '/' . $dir;
            if (is_dir($path)) {
                @file_put_contents($path . '/.htaccess', $denyHtaccess);
            }
        }

        return [
            'success' => true,
            'message' => 'index.php copied to document root (flatten strategy). '
                . 'NOTE: Application source files are inside the web root, protected only by access rules.',
        ];
    }

    /**
     * 4e. Write the .env file.
     */
    private function substepWriteEnv(): array
    {
        $installDir = $this->getFromSession(self::SESS_INSTALL_DIR);

        if ($installDir === null || ! is_dir($installDir)) {
            return ['success' => false, 'message' => 'Application directory not found.'];
        }

        // State recovery: if .env already exists and contains our marker, skip.
        $envPath = $installDir . '/.env';
        if (file_exists($envPath)) {
            $existing = @file_get_contents($envPath);
            if ($existing !== false && str_contains($existing, 'CI4 Installer')) {
                return ['success' => true, 'message' => 'Skipped — .env already exists.'];
            }
        }

        // Collect values from session.
        $configuration  = $this->getFromSession(self::SESS_CONFIGURATION, []);
        $appSettings    = $this->getFromSession(self::SESS_APP_SETTINGS, []);
        $dbDriver       = $this->getFromSession(self::SESS_DB_DRIVER, '');
        $dbCreds        = $this->getFromSession(self::SESS_DB_CREDS, []);

        $values = [];

        // Core CI4 env vars.
        if (isset($configuration['environment'])) {
            $values['CI_ENVIRONMENT'] = $configuration['environment'];
        }

        if (isset($configuration['base_url'])) {
            $values['app.baseURL'] = $configuration['base_url'];
        }

        if (isset($configuration['encryption_key'])) {
            $values['encryption.key'] = 'hex2bin:' . $configuration['encryption_key'];
        }

        // Database credentials.
        if ($dbDriver !== '') {
            $values['database.default.DBDriver'] = $dbDriver;

            if ($dbDriver === 'SQLite3') {
                if (! empty($dbCreds['path'])) {
                    $values['database.default.database'] = $dbCreds['path'];
                }
            } else {
                foreach (['hostname', 'port', 'database', 'username', 'password'] as $key) {
                    if (! empty($dbCreds[$key])) {
                        $envKey = match ($key) {
                            'hostname' => 'database.default.hostname',
                            'port'     => 'database.default.DBPort',
                            'database' => 'database.default.database',
                            'username' => 'database.default.username',
                            'password' => 'database.default.password',
                        };
                        $values[$envKey] = $dbCreds[$key];
                    }
                }
            }
        }

        // Developer-defined env vars.
        foreach ($appSettings as $key => $value) {
            $values[$key] = $value;
        }

        $fs     = $this->getFilesystem();
        $writer = new EnvWriter($fs);
        $result = $writer->write($installDir, $values);

        if (! $result->success) {
            // Fallback: generate display content for manual copy.
            $displayContent = $writer->generateDisplay($installDir, $values);
            return [
                'success'         => false,
                'message'         => $result->errorMessage,
                'manual_fallback' => $displayContent,
            ];
        }

        return ['success' => true, 'message' => '.env file written successfully.'];
    }

    /**
     * 4f. Create writable directories and set permissions.
     */
    private function substepPermissions(): array
    {
        $installDir = $this->getFromSession(self::SESS_INSTALL_DIR);

        if ($installDir === null || ! is_dir($installDir)) {
            return ['success' => false, 'message' => 'Application directory not found.'];
        }

        $writableDirs  = $this->config['writable_dirs']              ?? [];
        $permissions   = $this->config['writable_dir_permissions']   ?? 0755;
        $fs            = $this->getFilesystem();
        $errors        = [];
        $created       = [];

        foreach ($writableDirs as $relPath) {
            $absPath = $installDir . '/' . ltrim($relPath, '/\\');

            // Create directory if it doesn't exist.
            if (! $fs->exists($absPath)) {
                $mkResult = $fs->mkdir($absPath, true);
                if (! $mkResult->success) {
                    $errors[] = "Failed to create {$relPath}: " . $mkResult->errorMessage;
                    continue;
                }
                $created[] = $relPath;
            }

            // Set permissions.
            $chmodResult = $fs->chmod($absPath, $permissions);
            if (! $chmodResult->success) {
                // Non-fatal on Windows or when using FTP drivers that don't support chmod.
                $errors[] = "Warning: could not chmod {$relPath}: " . $chmodResult->errorMessage;
            }
        }

        if (! empty($errors) && count($errors) === count($writableDirs)) {
            return [
                'success' => false,
                'message' => 'All permission operations failed: ' . implode('; ', $errors),
            ];
        }

        $message = empty($created)
            ? 'Writable directories verified.'
            : 'Created: ' . implode(', ', $created) . '.';

        if (! empty($errors)) {
            $message .= ' Warnings: ' . implode('; ', $errors);
        }

        return ['success' => true, 'message' => $message];
    }

    /**
     * 4g. Run database migrations.
     */
    private function substepMigrations(): array
    {
        if (! ($this->config['post_install']['migrate'] ?? true)) {
            return ['success' => true, 'message' => 'Skipped — migrations disabled in config.'];
        }

        $installDir = $this->getFromSession(self::SESS_INSTALL_DIR);

        if ($installDir === null || ! is_dir($installDir)) {
            return ['success' => false, 'message' => 'Application directory not found.'];
        }

        $runner = new MigrationRunner($installDir);

        // Try direct bootstrap first.
        $result = $runner->runMigrations();

        if ($result->success) {
            return ['success' => true, 'message' => $result->content ?? 'Migrations applied successfully.'];
        }

        // Fallback: exec spark migrate.
        $env = $this->getServerEnvironment();

        if ($env->execAvailable === 'passed') {
            $execResult = $runner->runMigrationsViaExec();

            if ($execResult->success) {
                return ['success' => true, 'message' => $execResult->content ?? 'Migrations applied via spark.'];
            }

            return [
                'success' => false,
                'message' => 'Migrations failed via both direct bootstrap and spark exec. '
                    . 'Please run `php spark migrate` manually via SSH. Error: '
                    . $execResult->errorMessage,
            ];
        }

        return [
            'success' => false,
            'message' => 'Migrations failed: ' . $result->errorMessage
                . '. Please run `php spark migrate` manually via SSH or your control panel.',
        ];
    }

    /**
     * 4h. Run database seeders.
     */
    private function substepSeeders(): array
    {
        $shouldSeed   = $this->config['post_install']['seed']         ?? false;
        $seederClass  = $this->config['post_install']['seeder_class'] ?? '';

        if (! $shouldSeed || $seederClass === '') {
            return ['success' => true, 'message' => 'Skipped — no seeder configured.'];
        }

        $installDir = $this->getFromSession(self::SESS_INSTALL_DIR);

        if ($installDir === null || ! is_dir($installDir)) {
            return ['success' => false, 'message' => 'Application directory not found.'];
        }

        $runner = new MigrationRunner($installDir);

        // Try direct bootstrap first.
        $result = $runner->runSeeder($seederClass);

        if ($result->success) {
            return ['success' => true, 'message' => $result->content ?? "Seeder '{$seederClass}' completed."];
        }

        // Fallback: exec spark db:seed.
        $env = $this->getServerEnvironment();

        if ($env->execAvailable === 'passed') {
            $execResult = $runner->runSeederViaExec($seederClass);

            if ($execResult->success) {
                return ['success' => true, 'message' => $execResult->content ?? "Seeder '{$seederClass}' completed via spark."];
            }

            return [
                'success' => false,
                'message' => "Seeder failed via both direct bootstrap and spark exec. "
                    . "Please run `php spark db:seed {$seederClass}` manually. Error: "
                    . $execResult->errorMessage,
            ];
        }

        return [
            'success' => false,
            'message' => "Seeder '{$seederClass}' failed: " . $result->errorMessage
                . ". Please run `php spark db:seed {$seederClass}` manually.",
        ];
    }

    /**
     * 4i. Create the admin user via the configured auth adapter.
     */
    private function substepAdmin(): array
    {
        $authSystem = $this->config['auth']['system'] ?? 'none';

        if ($authSystem === 'none') {
            return ['success' => true, 'message' => 'Skipped — no auth system configured.'];
        }

        $installDir  = $this->getFromSession(self::SESS_INSTALL_DIR);
        $adminData   = $this->getFromSession(self::SESS_ADMIN, []);
        $dbCreds     = $this->getFromSession(self::SESS_DB_CREDS, []);

        if (empty($adminData)) {
            return ['success' => false, 'message' => 'Admin credentials not found in session. Please complete the admin step.'];
        }

        if ($installDir === null || ! is_dir($installDir)) {
            return ['success' => false, 'message' => 'Application directory not found.'];
        }

        try {
            $adapter = AuthAdapterFactory::create($this->config, $installDir, $dbCreds);
            $result  = $adapter->createAdmin($adminData);

            if ($result->success) {
                return ['success' => true, 'message' => $result->content ?? 'Admin user created successfully.'];
            }

            return ['success' => false, 'message' => $result->errorMessage];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Admin creation failed: ' . $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    /**
     * Get a value from the installer session namespace.
     */
    private function getFromSession(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Save a value to the installer session namespace.
     */
    private function saveToSession(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Clear all installer-specific session data.
     */
    private function clearInstallSession(): void
    {
        $keys = [
            self::SESS_ENV,
            self::SESS_FS_METHOD,
            self::SESS_FS_CREDS,
            self::SESS_DB_DRIVER,
            self::SESS_DB_CREDS,
            self::SESS_CONFIGURATION,
            self::SESS_APP_SETTINGS,
            self::SESS_ADMIN,
            self::SESS_INSTALL_DIR,
            self::SESS_SUBSTEPS,
            'csrf_token',
            'ci4_installer_db_attempts',
            'nginx_conf_instructions',
        ];

        foreach ($keys as $key) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Redirect to a specific step by outputting a Location header.
     */
    private function redirect(string $step): void
    {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/install.php';
        $url        = $scriptName . '?step=' . urlencode($step);
        header('Location: ' . $url, true, 302);
        exit;
    }

    /**
     * Output a JSON response and terminate.
     */
    private function jsonResponse(array $data): void
    {
        if (! headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Detect the base URL from $_SERVER variables.
     */
    private function detectBaseUrl(): string
    {
        $https  = $this->detectHttps();
        $scheme = $https ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST']   ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $script = $_SERVER['SCRIPT_NAME'] ?? '/install.php';

        // Strip the filename to get the directory.
        $dir = rtrim(dirname($script), '/\\');
        $dir = $dir === '.' ? '' : $dir;

        return $scheme . '://' . $host . $dir . '/';
    }

    /**
     * Generate a cryptographically secure encryption key.
     */
    private function generateEncryptionKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Determine whether the current request is served over HTTPS.
     */
    private function detectHttps(): bool
    {
        $https = $_SERVER['HTTPS']                  ?? '';
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        $port  = (int) ($_SERVER['SERVER_PORT']     ?? 0);

        return (
            ($https !== '' && strtolower($https) !== 'off')
            || strtolower($proto) === 'https'
            || $port === 443
        );
    }

    /**
     * Get (or lazy-detect) the filesystem driver, backed by session cache.
     */
    private function getFilesystem(): FilesystemInterface
    {
        if ($this->fs !== null) {
            return $this->fs;
        }

        $method = $this->getFromSession(self::SESS_FS_METHOD);
        $creds  = $this->getFromSession(self::SESS_FS_CREDS, []);

        if ($method === null || $method === 'direct') {
            // Attempt auto-detect; fall back to DirectDriver.
            $driver = FilesystemFactory::detect($this->targetDir);

            if ($driver === null) {
                // Use direct driver anyway — best-effort.
                $driver = new \CI4Installer\Filesystem\DirectDriver();
            }

            $this->fs = $driver;
            return $this->fs;
        }

        try {
            $host = $creds['host'] ?? '';
            $user = $creds['user'] ?? '';
            $pass = $creds['pass'] ?? '';
            $port = (int) ($creds['port'] ?? 0);

            $this->fs = match ($method) {
                'ftps' => FilesystemFactory::createFtps($host, $user, $pass, $port > 0 ? $port : 21),
                'ftp'  => FilesystemFactory::createFtp($host, $user, $pass, $port > 0 ? $port : 21),
                'ssh2' => FilesystemFactory::createSsh2($host, $user, $pass, $port > 0 ? $port : 22),
                default => FilesystemFactory::detect($this->targetDir) ?? new \CI4Installer\Filesystem\DirectDriver(),
            };
        } catch (Throwable) {
            $this->fs = new \CI4Installer\Filesystem\DirectDriver();
        }

        return $this->fs;
    }

    /**
     * Return the canonical substep list with labels and status.
     *
     * @return array<int, array{id: string, label: string, completed: bool}>
     */
    private function buildSubstepList(): array
    {
        $completed = $this->getFromSession(self::SESS_SUBSTEPS, []);

        $substeps = [
            ['id' => 'download',    'label' => 'Download Application'],
            ['id' => 'extract',     'label' => 'Extract Files'],
            ['id' => 'validate',    'label' => 'Validate Source'],
            ['id' => 'public_dir',  'label' => 'Configure Public Directory'],
            ['id' => 'write_env',   'label' => 'Write .env Configuration'],
            ['id' => 'permissions', 'label' => 'Set File Permissions'],
            ['id' => 'migrations',  'label' => 'Run Database Migrations'],
            ['id' => 'seeders',     'label' => 'Run Database Seeders'],
            ['id' => 'admin',       'label' => 'Create Admin Account'],
        ];

        foreach ($substeps as &$substep) {
            $substep['completed'] = isset($completed[$substep['id']]);
        }

        return $substeps;
    }

    // -------------------------------------------------------------------------
    // Private — routing helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve which step to render, defaulting to 'welcome'.
     */
    private function resolveStep(): string
    {
        $step = $_POST['step'] ?? $_GET['step'] ?? 'welcome';

        // Whitelist of valid step slugs.
        $valid = [
            'welcome', 'system-check', 'filesystem', 'database',
            'configuration', 'app-settings', 'admin', 'install', 'complete',
        ];

        return in_array($step, $valid, true) ? $step : 'welcome';
    }

    /**
     * Determine whether the current request is an AJAX substep call.
     */
    private function isAjaxSubstepRequest(): bool
    {
        return isset($_POST['substep']) || isset($_GET['substep'])
            || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    // -------------------------------------------------------------------------
    // Private — environment helpers
    // -------------------------------------------------------------------------

    /**
     * Get the ServerEnvironment from session cache or detect if needed.
     */
    private function getServerEnvironment(): ServerEnvironment
    {
        if ($this->env !== null) {
            return $this->env;
        }

        $env = $this->loadEnvFromSession();

        if ($env === null) {
            $detector = new Detector($this->targetDir);
            $env      = $detector->detect();
            $this->saveEnvToSession($env);
        }

        $this->env = $env;
        return $this->env;
    }

    /**
     * Attempt to deserialize a ServerEnvironment from the session.
     * Returns null if not present or corrupted.
     */
    private function loadEnvFromSession(): ?ServerEnvironment
    {
        $data = $this->getFromSession(self::SESS_ENV);

        if (! is_array($data)) {
            return null;
        }

        try {
            $env = new ServerEnvironment();

            foreach ($data as $key => $value) {
                if (property_exists($env, $key)) {
                    $env->{$key} = $value;
                }
            }

            return $env;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Serialize a ServerEnvironment to the session.
     */
    private function saveEnvToSession(ServerEnvironment $env): void
    {
        // Convert object to array for session serialization.
        $data = [];
        foreach (get_object_vars($env) as $key => $value) {
            $data[$key] = $value;
        }
        $this->saveToSession(self::SESS_ENV, $data);
    }

    // -------------------------------------------------------------------------
    // Private — database helpers
    // -------------------------------------------------------------------------

    /**
     * Map a PHP extension name to the CI4 DB driver name.
     */
    private function mapDbExtToDriver(string $ext): ?string
    {
        return match ($ext) {
            'mysqli'  => 'MySQLi',
            'pgsql'   => 'Postgre',
            'sqlite3' => 'SQLite3',
            'sqlsrv'  => 'SQLSRV',
            default   => null,
        };
    }

    // -------------------------------------------------------------------------
    // Private — filesystem helpers
    // -------------------------------------------------------------------------

    /**
     * Find a .zip file in a directory. Returns the path or null.
     */
    private function findZipFile(string $dir): ?string
    {
        if (! is_dir($dir)) {
            return null;
        }

        $files = @scandir($dir);

        if ($files === false) {
            return null;
        }

        foreach ($files as $file) {
            if (str_ends_with(strtolower($file), '.zip')) {
                return $dir . '/' . $file;
            }
        }

        return null;
    }

    /**
     * Extract a zip archive using the best available method.
     */
    private function extractZip(string $zipPath, string $destDir): array
    {
        // Method 1: ZipArchive.
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            $res = $zip->open($zipPath);

            if ($res === true) {
                $zip->extractTo($destDir);
                $zip->close();
                return ['success' => true];
            }
        }

        // Method 2: exec unzip.
        $env = $this->getServerEnvironment();

        if ($env->execAvailable === 'passed') {
            $zipArg  = escapeshellarg($zipPath);
            $destArg = escapeshellarg($destDir);
            $output  = [];
            $code    = 0;
            @exec("unzip -o {$zipArg} -d {$destArg} 2>&1", $output, $code);

            if ($code === 0) {
                return ['success' => true];
            }
        }

        // Method 3: PharData.
        if (extension_loaded('phar')) {
            try {
                $phar = new \PharData($zipPath);
                $phar->extractTo($destDir, null, true);
                return ['success' => true];
            } catch (Throwable $e) {
                return ['success' => false, 'message' => 'PharData extraction failed: ' . $e->getMessage()];
            }
        }

        return [
            'success' => false,
            'message' => 'Cannot extract zip: no extraction method available (ZipArchive, unzip, or PharData required).',
        ];
    }

    /**
     * Normalize a directory containing a single nested subdirectory.
     *
     * GitHub-style archives create: target/reponame-v1.0.0/ (one level of nesting).
     * This method flattens that if exactly one subdirectory and no files exist at the top level.
     *
     * @return bool True if normalization was performed.
     */
    private function normalizeDirectory(string $dir): bool
    {
        $entries = @scandir($dir);

        if ($entries === false) {
            return false;
        }

        $entries = array_values(array_filter($entries, static fn($e) => $e !== '.' && $e !== '..'));

        // Check: exactly one entry and it is a directory.
        if (count($entries) !== 1) {
            return false;
        }

        $subPath = $dir . '/' . $entries[0];

        if (! is_dir($subPath)) {
            return false;
        }

        // Move all contents of the subdirectory up one level.
        $subEntries = @scandir($subPath);

        if ($subEntries === false) {
            return false;
        }

        foreach ($subEntries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $src = $subPath . '/' . $entry;
            $dst = $dir . '/' . $entry;

            if (! @rename($src, $dst)) {
                return false;
            }
        }

        @rmdir($subPath);

        return true;
    }

    /**
     * Recursively copy all contents of $src into $dst.
     */
    private function mergeDir(string $src, string $dst): void
    {
        if (! is_dir($dst)) {
            @mkdir($dst, 0755, true);
        }

        $entries = @scandir($src);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $srcPath = $src . '/' . $entry;
            $dstPath = $dst . '/' . $entry;

            if (is_dir($srcPath)) {
                $this->mergeDir($srcPath, $dstPath);
            } else {
                @copy($srcPath, $dstPath);
            }
        }
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $entries = @scandir($dir);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * Check if a directory contains at least one non-dot file or subdirectory.
     */
    private function dirHasFiles(string $dir): bool
    {
        $entries = @scandir($dir);

        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * Clean up the extracted temp directory.
     */
    private function cleanupExtractedDir(): void
    {
        // Only clean up if the extracted dir is inside sys_get_temp_dir().
        $tmpDir = sys_get_temp_dir();

        if (str_starts_with($this->extractedDir, $tmpDir)) {
            $this->removeDir($this->extractedDir);
        }
    }

    // -------------------------------------------------------------------------
    // Private — Paths.php and index.php update helpers
    // -------------------------------------------------------------------------

    /**
     * Update app/Config/Paths.php to point to the out-of-root directory.
     */
    private function updatePathsConfig(string $pathsFile, string $appRoot): void
    {
        $content = @file_get_contents($pathsFile);

        if ($content === false) {
            return;
        }

        // Update $systemDirectory path.
        $content = preg_replace(
            "#public\s+\\\$systemDirectory\s*=\s*'[^']*'#",
            "public \$systemDirectory = '{$appRoot}/vendor/codeigniter4/framework/system'",
            $content
        );

        // Update $appDirectory path.
        $content = preg_replace(
            "#public\s+\\\$appDirectory\s*=\s*'[^']*'#",
            "public \$appDirectory = '{$appRoot}/app'",
            $content
        );

        // Update $writableDirectory path.
        $content = preg_replace(
            "#public\s+\\\$writableDirectory\s*=\s*'[^']*'#",
            "public \$writableDirectory = '{$appRoot}/writable'",
            $content
        );

        // Update $testsDirectory path.
        $content = preg_replace(
            "#public\s+\\\$testsDirectory\s*=\s*'[^']*'#",
            "public \$testsDirectory = '{$appRoot}/tests'",
            $content
        );

        @file_put_contents($pathsFile, $content);
    }

    /**
     * Update public/index.php (now at targetDir/index.php) to reference the out-of-root Paths.php.
     */
    private function updateIndexPhp(string $indexFile, string $appRoot): void
    {
        $content = @file_get_contents($indexFile);

        if ($content === false) {
            return;
        }

        $pathsPhp = $appRoot . '/app/Config/Paths.php';

        // Replace the $pathsConfig assignment.
        $content = preg_replace(
            '#\$pathsConfig\s*=\s*[\'"]?[^\'";\s]+[\'"]?\s*;#',
            "\$pathsConfig = '{$pathsPhp}';",
            $content
        );

        @file_put_contents($indexFile, $content);
    }

    // -------------------------------------------------------------------------
    // Private — server config generation helpers
    // -------------------------------------------------------------------------

    /**
     * Generate an Nginx config block for routing requests to public/index.php.
     */
    private function buildNginxConfig(string $appDir): string
    {
        $appDirEscaped = addslashes($appDir);

        return <<<NGINX
# Add this to your Nginx server block configuration:
server {
    listen 80;
    server_name yourdomain.com;
    root {$appDirEscaped}/public;
    index index.php index.html;

    location / {
        try_files \$uri \$uri/ /index.php\$is_args\$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to sensitive directories
    location ~ ^/(app|vendor|writable|system)/ {
        deny all;
    }
}
NGINX;
    }

    /**
     * Generate a web.config for IIS URL Rewrite.
     */
    private function buildWebConfig(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <rewrite>
            <rules>
                <rule name="CI4 Public Dir" stopProcessing="true">
                    <match url="^(.*)$" />
                    <conditions>
                        <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
                        <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
                    </conditions>
                    <action type="Rewrite" url="public/index.php/{R:1}" />
                </rule>
            </rules>
        </rewrite>
        <security>
            <requestFiltering>
                <hiddenSegments>
                    <add segment="app" />
                    <add segment="vendor" />
                    <add segment="writable" />
                    <add segment="system" />
                </hiddenSegments>
            </requestFiltering>
        </security>
    </system.webServer>
</configuration>
XML;
    }

    // -------------------------------------------------------------------------
    // Private — env_vars grouping helper
    // -------------------------------------------------------------------------

    /**
     * Group env_vars definitions by their 'group' field for template rendering.
     *
     * @return array<string, array> Associative array of group => [varDefs]
     */
    private function groupEnvVars(array $envVars): array
    {
        $groups = [];

        foreach ($envVars as $varDef) {
            $group = $varDef['group'] ?? 'General';
            $groups[$group][] = $varDef;
        }

        return $groups;
    }

    // -------------------------------------------------------------------------
    // Private — fatal error display
    // -------------------------------------------------------------------------

    /**
     * Output a developer-facing fatal error page and exit.
     *
     * Used only for conditions that indicate a misconfigured installer
     * (not for end-user errors).
     */
    private function die(string $title, string $details): never
    {
        $safeTitle   = htmlspecialchars($title);
        $appName     = htmlspecialchars($this->config['branding']['name'] ?? 'CI4 Installer');

        echo "<!DOCTYPE html><html lang='en'><head>"
            . "<meta charset='UTF-8'>"
            . "<title>Installer Error — {$safeTitle}</title>"
            . "<style>body{font-family:system-ui,sans-serif;max-width:700px;margin:4rem auto;padding:0 1.5rem}"
            . "h1{color:#c0392b}code{background:#f8f8f8;padding:.1em .3em;border-radius:3px}"
            . "pre{background:#f8f8f8;padding:1em;border-radius:5px;overflow:auto}"
            . ".badge{background:#e74c3c;color:#fff;padding:.2em .7em;border-radius:1em;font-size:.8em}"
            . "</style></head><body>"
            . "<h1><span class='badge'>Error</span> {$safeTitle}</h1>"
            . "<p><strong>{$appName} Installer</strong> could not start due to a configuration problem.</p>"
            . "<div>{$details}</div>"
            . "</body></html>";

        exit(1);
    }
}

<?php
/**
 * Step: Database Setup
 *
 * Variables available:
 *  - $data['availableDrivers'] array   e.g. ['MySQLi', 'SQLite3']
 *  - $data['error']            string  Error from previous attempt (optional)
 *  - $data['rateLimited']      bool    Rate limit exceeded (optional)
 *  - $config                   array   Full installer config
 *  - $csrfToken                string  CSRF token
 */
$availableDrivers = $data['availableDrivers'] ?? ['MySQLi'];
$error            = $data['error']            ?? '';
$rateLimited      = $data['rateLimited']      ?? false;

// Default values carried across page reloads
$prevDriver   = $_POST['db_driver']   ?? ($availableDrivers[0] ?? 'MySQLi');
$prevHost     = $_POST['db_hostname'] ?? '127.0.0.1';
$prevPort     = $_POST['db_port']     ?? '';
$prevDatabase = $_POST['db_database'] ?? '';
$prevUsername = $_POST['db_username'] ?? '';
$prevPath     = $_POST['db_path']     ?? '';

// Default ports per driver
$defaultPorts = [
    'MySQLi'  => '3306',
    'Postgre' => '5432',
    'SQLSRV'  => '1433',
    'SQLite3' => '',
];

// Server-based drivers (need host/port/db/user/pass)
$serverDrivers = ['MySQLi', 'Postgre', 'SQLSRV'];
?>

<div
    x-data="{
        driver: '<?= htmlspecialchars($prevDriver, ENT_QUOTES, 'UTF-8') ?>',
        defaultPorts: { MySQLi: '3306', Postgre: '5432', SQLSRV: '1433', SQLite3: '' },
        get isServerDriver() { return ['MySQLi', 'Postgre', 'SQLSRV'].includes(this.driver); },
        get defaultPort()    { return this.defaultPorts[this.driver] ?? ''; },

        testing: false,
        testResult: null,
        testMessage: '',
        creating: false,
        createResult: null,
        createMessage: '',

        async testConnection() {
            this.testing = true;
            this.testResult  = null;
            this.testMessage = '';
            try {
                const form = document.getElementById('db-form');
                const data = new FormData(form);
                data.set('action', 'test_database');
                const resp = await fetch('install.php', { method: 'POST', body: data });
                const json = await resp.json();
                this.testResult  = json.success ? 'success' : 'error';
                this.testMessage = json.message  ?? (json.success ? 'Connection successful!' : 'Connection failed.');
            } catch (e) {
                this.testResult  = 'error';
                this.testMessage = 'Request failed. Please try the form submission instead.';
            } finally {
                this.testing = false;
            }
        },

        async createDatabase() {
            this.creating = true;
            this.createResult  = null;
            this.createMessage = '';
            try {
                const form = document.getElementById('db-form');
                const data = new FormData(form);
                data.set('action', 'create_database');
                const resp = await fetch('install.php', { method: 'POST', body: data });
                const json = await resp.json();
                this.createResult  = json.success ? 'success' : 'error';
                this.createMessage = json.message  ?? (json.success ? 'Database created!' : 'Could not create database.');
            } catch (e) {
                this.createResult  = 'error';
                this.createMessage = 'Request failed.';
            } finally {
                this.creating = false;
            }
        }
    }"
>
    <form method="POST" action="install.php" id="db-form">
        <input type="hidden" name="step"       value="configuration">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <?php if ($rateLimited): ?>
            <div class="alert alert-error mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
                <span>Too many connection attempts. Please wait a few minutes before trying again.</span>
            </div>
        <?php endif; ?>

        <?php if ($error && ! $rateLimited): ?>
            <div class="alert alert-error mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <!-- Driver selector -->
        <div class="form-control mb-5">
            <label class="label" for="db_driver">
                <span class="label-text font-medium">Database Driver</span>
            </label>
            <select
                name="db_driver"
                id="db_driver"
                class="select select-bordered w-full"
                x-model="driver"
            >
                <?php foreach ($availableDrivers as $drv): ?>
                    <option
                        value="<?= htmlspecialchars($drv, ENT_QUOTES, 'UTF-8') ?>"
                        <?= $drv === $prevDriver ? 'selected' : '' ?>
                    ><?= htmlspecialchars($drv, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Server-based fields -->
        <div x-show="isServerDriver" x-cloak>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">

                <!-- Hostname -->
                <div class="form-control sm:col-span-2">
                    <label class="label" for="db_hostname">
                        <span class="label-text font-medium">Hostname</span>
                    </label>
                    <input
                        type="text"
                        id="db_hostname"
                        name="db_hostname"
                        class="input input-bordered w-full"
                        placeholder="127.0.0.1"
                        value="<?= htmlspecialchars($prevHost, ENT_QUOTES, 'UTF-8') ?>"
                        autocomplete="off"
                    >
                </div>

                <!-- Port -->
                <div class="form-control">
                    <label class="label" for="db_port">
                        <span class="label-text font-medium">Port</span>
                    </label>
                    <input
                        type="number"
                        id="db_port"
                        name="db_port"
                        class="input input-bordered w-full"
                        :placeholder="defaultPort"
                        value="<?= htmlspecialchars($prevPort, ENT_QUOTES, 'UTF-8') ?>"
                        min="1"
                        max="65535"
                    >
                </div>
            </div>

            <!-- Database name -->
            <div class="form-control mb-4">
                <label class="label" for="db_database">
                    <span class="label-text font-medium">Database Name <span class="text-error">*</span></span>
                </label>
                <input
                    type="text"
                    id="db_database"
                    name="db_database"
                    class="input input-bordered w-full"
                    placeholder="my_app_db"
                    value="<?= htmlspecialchars($prevDatabase, ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="off"
                >
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <!-- Username -->
                <div class="form-control">
                    <label class="label" for="db_username">
                        <span class="label-text font-medium">Username <span class="text-error">*</span></span>
                    </label>
                    <input
                        type="text"
                        id="db_username"
                        name="db_username"
                        class="input input-bordered w-full"
                        placeholder="dbuser"
                        value="<?= htmlspecialchars($prevUsername, ENT_QUOTES, 'UTF-8') ?>"
                        autocomplete="username"
                    >
                </div>

                <!-- Password -->
                <div class="form-control">
                    <label class="label" for="db_password">
                        <span class="label-text font-medium">Password</span>
                    </label>
                    <input
                        type="password"
                        id="db_password"
                        name="db_password"
                        class="input input-bordered w-full"
                        placeholder="&bullet;&bullet;&bullet;&bullet;&bullet;&bullet;&bullet;&bullet;"
                        autocomplete="current-password"
                    >
                </div>
            </div>
        </div>

        <!-- SQLite3 path -->
        <div x-show="!isServerDriver" x-cloak>
            <div class="form-control mb-4">
                <label class="label" for="db_path">
                    <span class="label-text font-medium">SQLite3 Database File Path <span class="text-error">*</span></span>
                    <span class="label-text-alt text-base-content/50">Absolute server path</span>
                </label>
                <input
                    type="text"
                    id="db_path"
                    name="db_path"
                    class="input input-bordered w-full font-mono text-sm"
                    placeholder="/var/www/myapp/writable/database.sqlite"
                    value="<?= htmlspecialchars($prevPath, ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="off"
                >
                <label class="label">
                    <span class="label-text-alt text-warning">
                        Recommendation: place the file inside your app&apos;s <code>writable/</code> directory, not the web root.
                    </span>
                </label>
            </div>
        </div>

        <!-- Test result banners -->
        <template x-if="testResult === 'success'">
            <div class="alert alert-success mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
                <span x-text="testMessage"></span>
            </div>
        </template>
        <template x-if="testResult === 'error'">
            <div class="alert alert-error mb-4">
                <div class="flex-1">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                    <span x-text="testMessage"></span>
                </div>
                <!-- Offer Create DB option if error hints db doesn't exist -->
                <button
                    type="button"
                    class="btn btn-sm btn-outline btn-warning ml-auto gap-1"
                    @click="createDatabase()"
                    :disabled="creating"
                    x-show="testMessage.toLowerCase().includes('does not exist') || testMessage.toLowerCase().includes('unknown database')"
                >
                    <span x-show="creating" class="loading loading-spinner loading-xs"></span>
                    Create Database
                </button>
            </div>
        </template>

        <!-- Create DB result -->
        <template x-if="createResult === 'success'">
            <div class="alert alert-success mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
                <span x-text="createMessage"></span>
            </div>
        </template>
        <template x-if="createResult === 'error'">
            <div class="alert alert-error mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
                <span x-text="createMessage"></span>
            </div>
        </template>

        <!-- Action buttons -->
        <div class="flex flex-wrap justify-between gap-3 pt-2 border-t border-base-200 mt-2">
            <div class="flex gap-2">
                <!-- Back -->
                <a href="install.php?step=filesystem" class="btn btn-ghost gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    Back
                </a>

                <!-- Test Connection -->
                <button
                    type="button"
                    class="btn btn-outline btn-info gap-2"
                    @click="testConnection()"
                    :disabled="testing || <?= $rateLimited ? 'true' : 'false' ?>"
                >
                    <span x-show="testing" class="loading loading-spinner loading-xs"></span>
                    <svg x-show="!testing" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 14.25h13.5m-13.5 0a3 3 0 0 1-3-3m3 3a3 3 0 1 0 6 0m-6 0H3.375A1.875 1.875 0 0 1 1.5 12.375V7.5A1.875 1.875 0 0 1 3.375 5.625h17.25A1.875 1.875 0 0 1 22.5 7.5v4.875A1.875 1.875 0 0 1 20.625 14.25H18m-13.5 0v4.125c0 .621.504 1.125 1.125 1.125H18m0-5.25v4.125c0 .621-.504 1.125-1.125 1.125H5.625" />
                    </svg>
                    <span x-text="testing ? 'Testing\u2026' : 'Test Connection'"></span>
                </button>
            </div>

            <!-- Continue -->
            <button type="submit" class="btn btn-primary gap-2">
                Continue
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                </svg>
            </button>
        </div>

    </form>
</div>

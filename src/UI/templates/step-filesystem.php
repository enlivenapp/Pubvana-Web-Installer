<?php
/**
 * Step: Filesystem Access
 *
 * Variables available:
 *  - $data['method']  string  Detected method: 'direct'|'ftp'|'ftps'|'ssh2'|'unknown'
 *  - $data['error']   string  Optional error message from a previous connection attempt
 *  - $config          array   Full installer config
 *  - $csrfToken       string  CSRF token
 */
$method = $data['method'] ?? 'unknown';
$error  = $data['error']  ?? '';

// Which FTP/SSH extensions are available?
$hasFtp  = function_exists('ftp_connect');
$hasFtps = function_exists('ftp_ssl_connect');
$hasSsh2 = extension_loaded('ssh2');
?>

<?php if ($method === 'direct'): ?>

    <!-- Direct access available — green confirmation -->
    <div class="flex flex-col items-center gap-4 py-4 text-center">
        <div class="w-16 h-16 rounded-full bg-success/10 flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
            </svg>
        </div>
        <div>
            <h3 class="text-lg font-semibold text-base-content">Direct File Access Available</h3>
            <p class="text-base-content/60 text-sm mt-1">
                The web server runs as the file owner &mdash; no FTP or SSH credentials needed.
            </p>
        </div>

        <div class="alert alert-success w-full max-w-sm">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
            </svg>
            <span class="text-sm">Files can be written directly. Continuing&hellip;</span>
        </div>
    </div>

    <div class="flex justify-between pt-4">
        <form method="POST" action="<?= htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="step"       value="system-check">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-ghost gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
                Back
            </button>
        </form>
        <form method="POST" action="<?= htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="step"            value="database">
            <input type="hidden" name="csrf_method"     value="direct">
            <input type="hidden" name="csrf_token"      value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-primary gap-2">
                Continue
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                </svg>
            </button>
        </form>
    </div>

<?php else: ?>

    <!-- Credentials needed -->
    <div class="mb-5">
        <?php if ($method === 'unknown'): ?>
            <div class="alert alert-warning mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
                <span>The web server does not appear to own the installation directory. Please provide FTP or SSH credentials to write files.</span>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                </svg>
                <span>
                    Detected filesystem method: <strong><?= htmlspecialchars(strtoupper($method), ENT_QUOTES, 'UTF-8') ?></strong>.
                    Please confirm your credentials below.
                </span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Credentials form with Alpine.js enhancement -->
    <div
        x-data="{
            connType: '<?= htmlspecialchars(in_array($method, ['ftp','ftps','ssh2'], true) ? $method : 'ftp', ENT_QUOTES, 'UTF-8') ?>',
            testing: false,
            testResult: null,
            testMessage: '',
            async testConnection() {
                this.testing = true;
                this.testResult = null;
                this.testMessage = '';
                try {
                    const form = this.$el.querySelector('form');
                    const data = new FormData(form);
                    data.set('action', 'test_filesystem');
                    const resp = await fetch('<?= htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8') ?>', { method: 'POST', body: data });
                    const json = await resp.json();
                    this.testResult  = json.success ? 'success' : 'error';
                    this.testMessage = json.message  ?? (json.success ? 'Connection successful!' : 'Connection failed.');
                } catch (e) {
                    this.testResult  = 'error';
                    this.testMessage = 'Request failed. Please try the form submission instead.';
                } finally {
                    this.testing = false;
                }
            }
        }"
    >
        <form method="POST" action="<?= htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8') ?>" id="fs-form">
            <input type="hidden" name="step"       value="filesystem">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <!-- Connection type -->
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text font-medium">Connection Type</span>
                </label>
                <select
                    name="fs_type"
                    class="select select-bordered w-full"
                    x-model="connType"
                >
                    <?php if ($hasFtp): ?>
                        <option value="ftp">FTP</option>
                    <?php endif; ?>
                    <?php if ($hasFtps): ?>
                        <option value="ftps">FTPS (FTP over SSL)</option>
                    <?php endif; ?>
                    <?php if ($hasSsh2): ?>
                        <option value="ssh2">SSH2 / SFTP</option>
                    <?php endif; ?>
                    <?php if (! $hasFtp && ! $hasFtps && ! $hasSsh2): ?>
                        <option value="ftp">FTP (extension may not be available)</option>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Hostname -->
            <div class="form-control mb-4">
                <label class="label" for="fs_hostname">
                    <span class="label-text font-medium">Hostname</span>
                </label>
                <input
                    type="text"
                    id="fs_hostname"
                    name="fs_hostname"
                    class="input input-bordered w-full"
                    placeholder="ftp.example.com"
                    value="<?= htmlspecialchars($_POST['fs_hostname'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    required
                    autocomplete="off"
                >
            </div>

            <!-- Username -->
            <div class="form-control mb-4">
                <label class="label" for="fs_username">
                    <span class="label-text font-medium">Username</span>
                </label>
                <input
                    type="text"
                    id="fs_username"
                    name="fs_username"
                    class="input input-bordered w-full"
                    placeholder="ftpuser"
                    value="<?= htmlspecialchars($_POST['fs_username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    required
                    autocomplete="username"
                >
            </div>

            <!-- Password -->
            <div class="form-control mb-4">
                <label class="label" for="fs_password">
                    <span class="label-text font-medium">Password</span>
                </label>
                <input
                    type="password"
                    id="fs_password"
                    name="fs_password"
                    class="input input-bordered w-full"
                    placeholder="&bullet;&bullet;&bullet;&bullet;&bullet;&bullet;&bullet;&bullet;"
                    required
                    autocomplete="current-password"
                >
            </div>

            <!-- Port (conditional default) -->
            <div class="form-control mb-5">
                <label class="label" for="fs_port">
                    <span class="label-text font-medium">Port</span>
                </label>
                <input
                    type="number"
                    id="fs_port"
                    name="fs_port"
                    class="input input-bordered w-full"
                    :placeholder="connType === 'ssh2' ? '22' : '21'"
                    :value="connType === 'ssh2' ? '22' : '21'"
                    min="1"
                    max="65535"
                >
            </div>

            <!-- AJAX test result -->
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                    <span x-text="testMessage"></span>
                </div>
            </template>

            <!-- Actions -->
            <div class="flex flex-wrap justify-between gap-3 pt-2">
                <div class="flex gap-2">
                    <form method="POST" action="<?= htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8') ?>" style="display:inline">
                        <input type="hidden" name="step"       value="system-check">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-ghost gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                            </svg>
                            Back
                        </button>
                    </form>

                    <!-- Test Connection (Alpine.js) -->
                    <button
                        type="button"
                        class="btn btn-outline btn-info gap-2"
                        @click="testConnection()"
                        :disabled="testing"
                    >
                        <span x-show="testing" class="loading loading-spinner loading-xs"></span>
                        <svg x-show="!testing" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                        </svg>
                        <span x-text="testing ? 'Testing\u2026' : 'Test Connection'"></span>
                    </button>
                </div>

                <!-- Continue (form POST) -->
                <button type="submit" form="fs-form" class="btn btn-primary gap-2">
                    Continue
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </button>
            </div>
        </form>
    </div>

<?php endif; ?>

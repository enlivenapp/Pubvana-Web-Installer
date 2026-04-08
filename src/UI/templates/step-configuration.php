<?php
/**
 * Step: App Configuration
 *
 * Variables available:
 *  - $data['detectedBaseUrl'] string  Auto-detected base URL
 *  - $data['encryptionKey']   string  Auto-generated encryption key (or session-persisted)
 *  - $data['error']           string  Optional error from previous attempt
 *  - $config                  array   Full installer config
 *  - $csrfToken               string  CSRF token
 */
$detectedBaseUrl = $data['detectedBaseUrl'] ?? '';
$encryptionKey   = $data['encryptionKey']   ?? bin2hex(random_bytes(32));
$error           = $data['error']           ?? '';

$prevBaseUrl     = $_POST['base_url']     ?? $detectedBaseUrl;
$prevEnvironment = $_POST['environment']  ?? 'production';
$prevKey         = $_POST['encrypt_key']  ?? $encryptionKey;
?>

<div
    x-data="{
        encKey: '<?= htmlspecialchars($prevKey, ENT_QUOTES, 'UTF-8') ?>',
        async regenerateKey() {
            try {
                const resp = await fetch('<?= htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8') ?>?action=generate_key', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'csrf_token=<?= urlencode($csrfToken) ?>'
                });
                const json = await resp.json();
                if (json.key) { this.encKey = json.key; }
            } catch(e) {
                // Fallback: generate a pseudo-random key client-side for display purposes
                // (server will verify and regenerate anyway)
                const arr = new Uint8Array(32);
                crypto.getRandomValues(arr);
                this.encKey = Array.from(arr).map(b => b.toString(16).padStart(2,'0')).join('');
            }
        }
    }"
>
    <form method="POST" action="<?= htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8') ?>" id="config-form">
        <input type="hidden" name="step"       value="configuration">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <?php if ($error): ?>
            <div class="alert alert-error mb-5">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <!-- Base URL -->
        <div class="form-control mb-5">
            <label class="label" for="base_url">
                <span class="label-text font-medium">Base URL <span class="text-error">*</span></span>
                <span class="label-text-alt text-base-content/50">Include trailing slash</span>
            </label>
            <input
                type="url"
                id="base_url"
                name="base_url"
                class="input input-bordered w-full font-mono text-sm"
                placeholder="https://example.com/"
                value="<?= htmlspecialchars($prevBaseUrl, ENT_QUOTES, 'UTF-8') ?>"
                required
                autocomplete="url"
            >
            <?php if ($detectedBaseUrl): ?>
                <label class="label">
                    <span class="label-text-alt text-base-content/50">
                        Auto-detected: <code class="text-xs"><?= htmlspecialchars($detectedBaseUrl, ENT_QUOTES, 'UTF-8') ?></code>
                    </span>
                </label>
            <?php endif; ?>
        </div>

        <!-- Environment -->
        <div class="form-control mb-5">
            <label class="label" for="environment">
                <span class="label-text font-medium">Environment</span>
            </label>
            <select
                id="environment"
                name="environment"
                class="select select-bordered w-full"
            >
                <option value="production"  <?= $prevEnvironment === 'production'  ? 'selected' : '' ?>>Production</option>
                <option value="development" <?= $prevEnvironment === 'development' ? 'selected' : '' ?>>Development</option>
                <option value="testing"     <?= $prevEnvironment === 'testing'     ? 'selected' : '' ?>>Testing</option>
            </select>
            <label class="label">
                <span class="label-text-alt text-warning">
                    Use <strong>production</strong> for live sites &mdash; development mode exposes detailed error output.
                </span>
            </label>
        </div>

        <!-- Encryption Key -->
        <div class="form-control mb-6">
            <label class="label" for="encrypt_key">
                <span class="label-text font-medium">Encryption Key</span>
                <span class="label-text-alt text-base-content/50">Auto-generated, 64 hex chars</span>
            </label>
            <div class="join w-full">
                <input
                    type="text"
                    id="encrypt_key"
                    name="encrypt_key"
                    class="input input-bordered join-item flex-1 font-mono text-xs"
                    :value="encKey"
                    readonly
                    aria-label="Encryption key"
                >
                <!-- Hidden field for form submission -->
                <input type="hidden" name="encrypt_key_value" :value="encKey">
                <button
                    type="button"
                    class="btn btn-outline join-item gap-1"
                    @click="regenerateKey()"
                    title="Regenerate key"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    Regenerate
                </button>
            </div>
            <label class="label">
                <span class="label-text-alt text-info">
                    This key is used to encrypt session cookies and other sensitive data. Store it securely &mdash; changing it later will invalidate all existing sessions.
                </span>
            </label>
        </div>

        <!-- Navigation -->
        <div class="flex justify-between pt-2 border-t border-base-200">
            <a href="<?= htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8') ?>?step=database" class="btn btn-ghost gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
                Back
            </a>
            <button type="submit" class="btn btn-primary gap-2">
                Continue
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                </svg>
            </button>
        </div>

    </form>
</div>

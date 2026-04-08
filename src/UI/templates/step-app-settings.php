<?php
/**
 * Step: Application Settings (Developer-defined env vars)
 *
 * Variables available:
 *  - $data['envVars'] array  Array of env var group definitions from config
 *  - $data['error']   string Optional validation error
 *  - $config          array  Full installer config
 *  - $csrfToken       string CSRF token
 *
 * Each env var definition:
 *  [
 *    'key'      => 'APP_NAME',
 *    'label'    => 'Application Name',
 *    'type'     => 'text',       // text|password|email|url|select|boolean
 *    'group'    => 'General',
 *    'required' => true,
 *    'default'  => '',
 *    'help'     => 'Help text shown below the field',
 *    'options'  => [],           // for type=select: ['value' => 'Label', ...]
 *    'pattern'  => '',           // optional HTML input pattern
 *  ]
 */
$envVars = $data['envVars'] ?? [];
$error   = $data['error']   ?? '';

// Group variables by their 'group' key
$groups = [];
foreach ($envVars as $varDef) {
    $group = $varDef['group'] ?? 'General';
    $groups[$group][] = $varDef;
}

/**
 * Get the submitted or default value for a variable.
 */
$getValue = function (array $def): string {
    $key     = $def['key'] ?? '';
    $default = (string) ($def['default'] ?? '');
    $post    = $_POST['env'][$key] ?? null;
    return $post !== null ? (string) $post : $default;
};
?>

<?php if (empty($envVars)): ?>
    <!-- No env vars configured — auto-continue -->
    <div class="flex flex-col items-center gap-4 py-4 text-center">
        <div class="alert alert-info w-full max-w-sm">
            <span>No additional application settings to configure.</span>
        </div>
    </div>
    <div class="flex justify-between pt-4">
        <a href="<?= htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8') ?>?step=configuration" class="btn btn-ghost gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            Back
        </a>
        <form method="POST" action="<?= htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="step"       value="admin">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-primary gap-2">
                Continue
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                </svg>
            </button>
        </form>
    </div>
<?php else: ?>

    <?php if ($error): ?>
        <div class="alert alert-error mb-5">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
            <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="step"       value="app-settings">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <?php foreach ($groups as $groupName => $vars): ?>
            <div class="mb-6">
                <!-- Group heading -->
                <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/50 mb-3 pb-1 border-b border-base-200">
                    <?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?>
                </h3>

                <div class="space-y-4">
                    <?php foreach ($vars as $def):
                        $key      = $def['key']      ?? '';
                        $label    = $def['label']     ?? $key;
                        $type     = $def['type']      ?? 'text';
                        $required = ! empty($def['required']);
                        $help     = $def['help']      ?? '';
                        $pattern  = $def['pattern']   ?? '';
                        $options  = $def['options']   ?? [];
                        $value    = $getValue($def);
                        $inputId  = 'env_' . preg_replace('/[^a-z0-9_]/i', '_', $key);
                    ?>

                    <div class="form-control" x-data="{}">
                        <label class="label" for="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>">
                            <span class="label-text font-medium">
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                <?php if ($required): ?>
                                    <span class="text-error ml-0.5">*</span>
                                <?php endif; ?>
                            </span>
                            <?php if ($type === 'boolean'): ?>
                                <span class="label-text-alt text-base-content/40">Toggle</span>
                            <?php endif; ?>
                        </label>

                        <?php if ($type === 'boolean'): ?>
                            <!-- Boolean toggle -->
                            <div class="flex items-center gap-3">
                                <input
                                    type="hidden"
                                    name="env[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]"
                                    value="false"
                                >
                                <input
                                    type="checkbox"
                                    id="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>"
                                    name="env[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]"
                                    class="toggle toggle-primary"
                                    value="true"
                                    <?= in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true) ? 'checked' : '' ?>
                                >
                                <span class="text-sm text-base-content/60">
                                    Enabled
                                </span>
                            </div>

                        <?php elseif ($type === 'select'): ?>
                            <!-- Select dropdown -->
                            <select
                                id="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>"
                                name="env[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]"
                                class="select select-bordered w-full"
                                <?= $required ? 'required' : '' ?>
                            >
                                <?php foreach ($options as $optVal => $optLabel): ?>
                                    <option
                                        value="<?= htmlspecialchars((string) $optVal, ENT_QUOTES, 'UTF-8') ?>"
                                        <?= (string) $optVal === $value ? 'selected' : '' ?>
                                    ><?= htmlspecialchars($optLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>

                        <?php elseif ($type === 'password'): ?>
                            <!-- Password with show/hide -->
                            <div x-data="{ show: false }" class="join w-full">
                                <input
                                    :type="show ? 'text' : 'password'"
                                    id="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>"
                                    name="env[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]"
                                    class="input input-bordered join-item flex-1"
                                    value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $required ? 'required' : '' ?>
                                    <?= $pattern  ? 'pattern="' . htmlspecialchars($pattern, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                                    autocomplete="new-password"
                                >
                                <button
                                    type="button"
                                    class="btn btn-outline join-item"
                                    @click="show = !show"
                                    :aria-label="show ? 'Hide' : 'Show'"
                                >
                                    <svg x-show="!show" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                    <svg x-show="show" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                                    </svg>
                                </button>
                            </div>

                        <?php else: ?>
                            <!-- text / email / url -->
                            <input
                                type="<?= in_array($type, ['text','email','url','number'], true) ? $type : 'text' ?>"
                                id="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>"
                                name="env[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]"
                                class="input input-bordered w-full"
                                value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $required ? 'required' : '' ?>
                                <?= $pattern  ? 'pattern="' . htmlspecialchars($pattern, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                                autocomplete="off"
                            >
                        <?php endif; ?>

                        <?php if ($help): ?>
                            <label class="label">
                                <span class="label-text-alt text-base-content/50">
                                    <?= htmlspecialchars($help, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </label>
                        <?php endif; ?>
                    </div>

                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Navigation -->
        <div class="flex justify-between pt-4 border-t border-base-200">
            <a href="<?= htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8') ?>?step=configuration" class="btn btn-ghost gap-2">
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

<?php endif; ?>

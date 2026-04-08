<?php
/**
 * Step: Admin Account
 *
 * Variables available:
 *  - $data['fields']  array   Field definitions from the auth adapter's getFields()
 *  - $data['error']   string  Optional error from previous attempt
 *  - $config          array   Full installer config
 *  - $csrfToken       string  CSRF token
 *
 * Each field definition (from AuthAdapterInterface::getFields()):
 *  [
 *    'name'     => 'email',
 *    'type'     => 'email',   // text|email|password
 *    'label'    => 'Email',
 *    'required' => true,
 *  ]
 */
$fields = $data['fields'] ?? [];
$error  = $data['error']  ?? '';

// Detect if there is a password field so we can add the confirm + strength indicator
$hasPasswordField = ! empty(array_filter($fields, fn($f) => ($f['type'] ?? '') === 'password'));
?>

<?php if (empty($fields)): ?>
    <!-- No auth configured — skip -->
    <div class="flex flex-col items-center gap-4 py-4 text-center">
        <div class="alert alert-info w-full max-w-sm">
            <span>No admin account setup required for this installation.</span>
        </div>
    </div>
    <div class="flex justify-between pt-4">
        <a href="<?= htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8') ?>?step=app-settings" class="btn btn-ghost gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            Back
        </a>
        <form method="POST" action="<?= htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="step"       value="install">
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

    <div
        x-data="{
            password: '',
            passwordConfirm: '',
            showPassword: false,
            showConfirm: false,
            get strength() {
                const p = this.password;
                if (p.length === 0) return 0;
                let score = 0;
                if (p.length >= 8)  score++;
                if (p.length >= 12) score++;
                if (/[A-Z]/.test(p)) score++;
                if (/[a-z]/.test(p)) score++;
                if (/[0-9]/.test(p)) score++;
                if (/[^A-Za-z0-9]/.test(p)) score++;
                return Math.min(score, 4);
            },
            get strengthLabel() {
                return ['', 'Weak', 'Fair', 'Good', 'Strong'][this.strength] ?? '';
            },
            get strengthClass() {
                return ['', 'progress-error', 'progress-warning', 'progress-info', 'progress-success'][this.strength] ?? '';
            },
            get passwordsMatch() {
                return this.passwordConfirm === '' || this.password === this.passwordConfirm;
            }
        }"
    >
        <form method="POST" action="<?= htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="step"       value="admin">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <?php if ($error): ?>
                <div class="alert alert-error mb-5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                    <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <p class="text-base-content/70 text-sm mb-5">
                Create the administrator account you will use to log in after installation.
            </p>

            <?php foreach ($fields as $field):
                $name     = $field['name']     ?? '';
                $type     = $field['type']      ?? 'text';
                $label    = $field['label']     ?? $name;
                $required = ! empty($field['required']);
                $inputId  = 'admin_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
                $isPass   = $type === 'password';
            ?>

            <div class="form-control mb-4" <?= $isPass ? 'x-data' : '' ?>>
                <label class="label" for="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="label-text font-medium">
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($required): ?><span class="text-error ml-0.5">*</span><?php endif; ?>
                    </span>
                </label>

                <?php if ($isPass): ?>
                    <!-- Password field with show/hide and Alpine strength binding -->
                    <div class="join w-full">
                        <input
                            :type="showPassword ? 'text' : 'password'"
                            id="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>"
                            name="admin[<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>]"
                            class="input input-bordered join-item flex-1"
                            x-model="password"
                            <?= $required ? 'required' : '' ?>
                            autocomplete="new-password"
                        >
                        <button
                            type="button"
                            class="btn btn-outline join-item"
                            @click="showPassword = !showPassword"
                        >
                            <svg x-show="!showPassword" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                            <svg x-show="showPassword" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                            </svg>
                        </button>
                    </div>

                    <!-- Strength indicator -->
                    <div x-show="password.length > 0" class="mt-2 space-y-1">
                        <progress
                            class="progress w-full h-1.5"
                            :class="strengthClass"
                            :value="strength"
                            max="4"
                        ></progress>
                        <p class="text-xs" :class="{
                            'text-error':   strength === 1,
                            'text-warning': strength === 2,
                            'text-info':    strength === 3,
                            'text-success': strength === 4
                        }">
                            Password strength: <span x-text="strengthLabel"></span>
                        </p>
                    </div>

                    <!-- Password confirm (added after the password field) -->
                    <div class="form-control mt-4">
                        <label class="label" for="admin_password_confirm">
                            <span class="label-text font-medium">Confirm Password <span class="text-error">*</span></span>
                        </label>
                        <div class="join w-full">
                            <input
                                :type="showConfirm ? 'text' : 'password'"
                                id="admin_password_confirm"
                                name="admin[password_confirm]"
                                class="input input-bordered join-item flex-1"
                                :class="{ 'input-error': !passwordsMatch && passwordConfirm.length > 0 }"
                                x-model="passwordConfirm"
                                required
                                autocomplete="new-password"
                            >
                            <button
                                type="button"
                                class="btn btn-outline join-item"
                                @click="showConfirm = !showConfirm"
                            >
                                <svg x-show="!showConfirm" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                                <svg x-show="showConfirm" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                                </svg>
                            </button>
                        </div>
                        <div x-show="!passwordsMatch && passwordConfirm.length > 0" class="label">
                            <span class="label-text-alt text-error">Passwords do not match.</span>
                        </div>
                    </div>

                <?php else: ?>
                    <input
                        type="<?= in_array($type, ['text','email','url'], true) ? $type : 'text' ?>"
                        id="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>"
                        name="admin[<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>]"
                        class="input input-bordered w-full"
                        value="<?= htmlspecialchars($_POST['admin'][$name] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        <?= $required ? 'required' : '' ?>
                        autocomplete="<?= $type === 'email' ? 'email' : 'off' ?>"
                    >
                <?php endif; ?>
            </div>

            <?php endforeach; ?>

            <!-- Navigation -->
            <div class="flex justify-between pt-4 border-t border-base-200 mt-2">
                <a href="<?= htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8') ?>?step=app-settings" class="btn btn-ghost gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    Back
                </a>
                <button
                    type="submit"
                    class="btn btn-primary gap-2"
                    :disabled="<?= $hasPasswordField ? '!passwordsMatch' : 'false' ?>"
                >
                    Continue
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </button>
            </div>
        </form>
    </div>

<?php endif; ?>

<?php
/**
 * Step: Installing
 *
 * Variables available:
 *  - $data['substeps']  array   Substep definitions
 *  - $data['completed'] array   Map of substep key => bool (already done — for no-JS page reload flow)
 *  - $data['errors']    array   Map of substep key => error string
 *  - $config            array   Full installer config
 *  - $csrfToken         string  CSRF token
 *
 * Each substep definition:
 *  [
 *    'key'   => '4a',
 *    'label' => 'Download application files',
 *    'action' => 'substep_4a',   // POST action value
 *  ]
 */
$substeps  = $data['substeps']  ?? [
    ['key' => '4a', 'label' => 'Download application files',          'action' => 'substep_4a'],
    ['key' => '4b', 'label' => 'Extract & normalise directory structure', 'action' => 'substep_4b'],
    ['key' => '4c', 'label' => 'Validate download & install dependencies', 'action' => 'substep_4c'],
    ['key' => '4d', 'label' => 'Handle public/ directory structure',  'action' => 'substep_4d'],
    ['key' => '4e', 'label' => 'Write .env configuration',            'action' => 'substep_4e'],
    ['key' => '4f', 'label' => 'Set file permissions',                'action' => 'substep_4f'],
    ['key' => '4g', 'label' => 'Run database migrations',             'action' => 'substep_4g'],
    ['key' => '4h', 'label' => 'Run database seeders',                'action' => 'substep_4h'],
    ['key' => '4i', 'label' => 'Create admin user',                   'action' => 'substep_4i'],
];
$completed = $data['completed'] ?? [];
$errors    = $data['errors']    ?? [];

// For no-JS flow: find the first incomplete, non-errored step to run next
$nextNoJsStep = null;
foreach ($substeps as $sub) {
    if (! ($completed[$sub['key']] ?? false) && ! isset($errors[$sub['key']])) {
        $nextNoJsStep = $sub;
        break;
    }
}

// Build JSON for Alpine
$substepsJson = json_encode($substeps);
$completedJson = json_encode($completed);
$errorsJson    = json_encode($errors);
?>

<div
    x-data="installWizard()"
    x-init="init()"
>
    <!-- Substep progress list -->
    <div class="space-y-2 mb-6">
        <template x-for="(sub, idx) in substeps" :key="sub.key">
            <div
                class="flex items-center gap-3 p-3 rounded-lg border transition-colors"
                :class="{
                    'border-success/20 bg-success/5':  stepStatus(sub.key) === 'done',
                    'border-primary/20 bg-primary/5':  stepStatus(sub.key) === 'running',
                    'border-error/20 bg-error/5':      stepStatus(sub.key) === 'failed',
                    'border-base-200 bg-base-50':      stepStatus(sub.key) === 'pending'
                }"
            >
                <!-- Status icon -->
                <div class="flex-shrink-0 w-6 h-6 flex items-center justify-center">
                    <!-- Pending -->
                    <span
                        x-show="stepStatus(sub.key) === 'pending'"
                        class="w-5 h-5 rounded-full border-2 border-base-300 inline-block"
                    ></span>
                    <!-- Running -->
                    <span
                        x-show="stepStatus(sub.key) === 'running'"
                        class="loading loading-spinner loading-sm text-primary"
                    ></span>
                    <!-- Done -->
                    <svg
                        x-show="stepStatus(sub.key) === 'done'"
                        xmlns="http://www.w3.org/2000/svg"
                        class="w-5 h-5 text-success"
                        fill="none" viewBox="0 0 24 24"
                        stroke="currentColor" stroke-width="2.5"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                    <!-- Failed -->
                    <svg
                        x-show="stepStatus(sub.key) === 'failed'"
                        xmlns="http://www.w3.org/2000/svg"
                        class="w-5 h-5 text-error"
                        fill="none" viewBox="0 0 24 24"
                        stroke="currentColor" stroke-width="2.5"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </div>

                <!-- Label + detail -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span
                            class="text-sm font-medium"
                            :class="{
                                'text-success':      stepStatus(sub.key) === 'done',
                                'text-primary':      stepStatus(sub.key) === 'running',
                                'text-error':        stepStatus(sub.key) === 'failed',
                                'text-base-content/60': stepStatus(sub.key) === 'pending'
                            }"
                            x-text="sub.label"
                        ></span>
                        <span
                            x-show="stepStatus(sub.key) === 'running'"
                            class="badge badge-sm badge-primary"
                        >Running&hellip;</span>
                        <span
                            x-show="stepStatus(sub.key) === 'done'"
                            class="badge badge-sm badge-success"
                        >Done</span>
                    </div>
                    <!-- Error detail -->
                    <p
                        x-show="stepStatus(sub.key) === 'failed'"
                        class="text-xs text-error mt-0.5"
                        x-text="errorFor(sub.key)"
                    ></p>
                </div>
            </div>
        </template>
    </div>

    <!-- Overall status messages -->
    <div x-show="phase === 'complete'" x-cloak>
        <div class="alert alert-success mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
            </svg>
            <span>All installation steps completed successfully!</span>
        </div>
        <div class="flex justify-end">
            <a href="install.php?step=complete" class="btn btn-primary gap-2">
                Finish
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                </svg>
            </a>
        </div>
    </div>

    <div x-show="phase === 'error'" x-cloak>
        <div class="alert alert-error mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
            </svg>
            <div>
                <p class="font-semibold">Installation step failed.</p>
                <p class="text-sm" x-text="currentError"></p>
                <p class="text-sm mt-1">
                    You may retry the failed step, or
                    <a href="install.php?step=admin" class="link">go back</a>
                    and check your settings.
                </p>
            </div>
        </div>
        <button
            type="button"
            class="btn btn-warning gap-2"
            @click="retry()"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
            </svg>
            Retry
        </button>
    </div>

    <div x-show="phase === 'idle'" x-cloak>
        <div class="flex justify-center">
            <button
                type="button"
                class="btn btn-primary btn-lg gap-2"
                @click="startInstall()"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                </svg>
                Begin Installation
            </button>
        </div>
    </div>

</div>

<!-- No-JS fallback form (hidden from JS users) -->
<noscript>
    <?php if ($nextNoJsStep): ?>
        <div class="mt-4 p-4 bg-warning/10 border border-warning/30 rounded-lg">
            <p class="text-sm text-base-content/80 mb-3">
                JavaScript is not available. Click the button below to run each installation step one at a time.
            </p>
            <p class="font-medium text-sm mb-3">
                Next: <?= htmlspecialchars($nextNoJsStep['label'], ENT_QUOTES, 'UTF-8') ?>
            </p>
            <form method="POST" action="install.php">
                <input type="hidden" name="step"       value="install">
                <input type="hidden" name="action"     value="<?= htmlspecialchars($nextNoJsStep['action'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-primary">
                    Run Step &rarr;
                </button>
            </form>
        </div>
    <?php else: ?>
        <div class="mt-4">
            <div class="alert alert-success">All steps complete.</div>
            <a href="install.php?step=complete" class="btn btn-primary mt-3">Finish</a>
        </div>
    <?php endif; ?>
</noscript>

<script>
function installWizard() {
    return {
        substeps:    <?= $substepsJson ?>,
        completed:   <?= $completedJson ?>,
        errors:      <?= $errorsJson ?>,
        phase:       'idle',      // idle | running | complete | error
        currentKey:  null,
        currentError: '',
        csrfToken:   <?= json_encode($csrfToken) ?>,

        init() {
            // If some steps are already done (page reload after partial failure), resume
            const allDone = this.substeps.every(s => this.completed[s.key]);
            if (allDone) {
                this.phase = 'complete';
                return;
            }
            const hasErrors = Object.keys(this.errors).length > 0;
            if (hasErrors) {
                const failedKey = Object.keys(this.errors)[0];
                this.currentKey   = failedKey;
                this.currentError = this.errors[failedKey];
                this.phase = 'error';
                return;
            }
            // Auto-start if there are already some completed steps (resuming)
            const anyDone = this.substeps.some(s => this.completed[s.key]);
            if (anyDone) {
                this.startInstall();
            }
        },

        stepStatus(key) {
            if (this.errors[key])      return 'failed';
            if (this.completed[key])   return 'done';
            if (this.currentKey === key && this.phase === 'running') return 'running';
            return 'pending';
        },

        errorFor(key) {
            return this.errors[key] ?? '';
        },

        async startInstall() {
            this.phase = 'running';
            for (const sub of this.substeps) {
                if (this.completed[sub.key]) continue;
                if (this.errors[sub.key])    break;

                this.currentKey = sub.key;
                const ok = await this.runStep(sub);
                if (! ok) {
                    this.phase = 'error';
                    return;
                }
                this.completed[sub.key] = true;
            }
            this.phase       = 'complete';
            this.currentKey  = null;
        },

        async runStep(sub) {
            try {
                const body = new URLSearchParams({
                    action:     sub.action,
                    step:       'install',
                    csrf_token: this.csrfToken,
                });
                const resp = await fetch('install.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:   body.toString(),
                });
                const json = await resp.json();
                if (! json.success) {
                    this.errors[sub.key]  = json.message ?? 'Unknown error.';
                    this.currentError     = this.errors[sub.key];
                    return false;
                }
                return true;
            } catch (e) {
                this.errors[sub.key]  = 'Network error: ' + e.message;
                this.currentError     = this.errors[sub.key];
                return false;
            }
        },

        retry() {
            if (this.currentKey) {
                delete this.errors[this.currentKey];
            }
            this.currentError = '';
            this.startInstall();
        },
    };
}
</script>

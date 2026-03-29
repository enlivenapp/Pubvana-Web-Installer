<?php
/**
 * Step: System Requirements Check
 *
 * Variables available:
 *  - $data['requirements'] array  Check results: [{label, status, detail}, ...]
 *  - $data['hasBlockers']  bool   True if any check has status 'failed'
 *  - $config               array  Full installer config
 *  - $csrfToken            string CSRF token
 */
$requirements = $data['requirements'] ?? [];
$hasBlockers  = $data['hasBlockers']  ?? false;

$passed   = array_filter($requirements, fn($r) => $r['status'] === 'passed');
$warnings = array_filter($requirements, fn($r) => $r['status'] === 'warning');
$failed   = array_filter($requirements, fn($r) => $r['status'] === 'failed');
?>

<!-- Summary badges -->
<div class="flex flex-wrap gap-2 mb-5">
    <?php if (count($passed)): ?>
        <div class="badge badge-success gap-1">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
            </svg>
            <?= count($passed) ?> passed
        </div>
    <?php endif; ?>
    <?php if (count($warnings)): ?>
        <div class="badge badge-warning gap-1">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
            <?= count($warnings) ?> warning<?= count($warnings) !== 1 ? 's' : '' ?>
        </div>
    <?php endif; ?>
    <?php if (count($failed)): ?>
        <div class="badge badge-error gap-1">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
            <?= count($failed) ?> failed
        </div>
    <?php endif; ?>
</div>

<!-- Requirements list -->
<div class="space-y-2 mb-6">
    <?php foreach ($requirements as $req): ?>
        <?php
        $status = $req['status'];
        $label  = htmlspecialchars($req['label']  ?? '', ENT_QUOTES, 'UTF-8');
        $detail = htmlspecialchars($req['detail'] ?? '', ENT_QUOTES, 'UTF-8');

        if ($status === 'passed') {
            $rowClass    = 'border-success/20 bg-success/5';
            $iconClass   = 'text-success';
            $badgeClass  = 'badge-success';
            $badgeLabel  = 'Passed';
            $iconPath    = 'm4.5 12.75 6 6 9-13.5';
        } elseif ($status === 'warning') {
            $rowClass    = 'border-warning/20 bg-warning/5';
            $iconClass   = 'text-warning';
            $badgeClass  = 'badge-warning';
            $badgeLabel  = 'Warning';
            $iconPath    = 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z';
        } else {
            $rowClass    = 'border-error/20 bg-error/5';
            $iconClass   = 'text-error';
            $badgeClass  = 'badge-error';
            $badgeLabel  = 'Failed';
            $iconPath    = 'M6 18 18 6M6 6l12 12';
        }
        ?>
        <div class="flex items-start gap-3 border rounded-lg p-3 <?= $rowClass ?>">
            <div class="mt-0.5 flex-shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 <?= $iconClass ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="<?= $iconPath ?>" />
                </svg>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-sm text-base-content"><?= $label ?></span>
                    <span class="badge badge-sm <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                </div>
                <?php if ($detail): ?>
                    <p class="text-xs text-base-content/60 mt-0.5"><?= $detail ?></p>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (empty($requirements)): ?>
        <div class="alert alert-info">
            <span>No requirement checks were run.</span>
        </div>
    <?php endif; ?>
</div>

<!-- Blocker alert -->
<?php if ($hasBlockers): ?>
    <div class="alert alert-error mb-5">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
        </svg>
        <span>Please resolve the issues above before continuing.</span>
    </div>
<?php elseif (count($warnings)): ?>
    <div class="alert alert-warning mb-5">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
        </svg>
        <span>Some warnings were detected. You can still continue, but review the items above.</span>
    </div>
<?php endif; ?>

<!-- Navigation -->
<div class="flex justify-between pt-2">
    <form method="POST" action="install.php">
        <input type="hidden" name="step"       value="welcome">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn btn-ghost gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            Back
        </button>
    </form>

    <form method="POST" action="install.php">
        <input type="hidden" name="step"       value="filesystem">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <button
            type="submit"
            class="btn btn-primary gap-2"
            <?= $hasBlockers ? 'disabled' : '' ?>
        >
            Continue
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
            </svg>
        </button>
    </form>
</div>

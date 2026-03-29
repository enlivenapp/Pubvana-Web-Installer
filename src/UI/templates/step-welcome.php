<?php
/**
 * Step: Welcome
 *
 * Variables available:
 *  - $config    array  Full installer config
 *  - $csrfToken string CSRF token
 */
$appName    = htmlspecialchars($config['branding']['name']    ?? 'Application', ENT_QUOTES, 'UTF-8');
$appVersion = htmlspecialchars($config['branding']['version'] ?? '1.0.0',       ENT_QUOTES, 'UTF-8');
$welcomeText = !empty($config['branding']['welcome_text'])
    ? $config['branding']['welcome_text']
    : "This wizard will guide you through the installation of " . ($config['branding']['name'] ?? 'this application') . ". The process takes just a few minutes.";
?>

<div class="flex flex-col items-center text-center gap-6 py-4">

    <!-- Logo (large, welcome view) -->
    <?php if (! empty($config['branding']['logo'])): ?>
        <img
            src="<?= htmlspecialchars($config['branding']['logo'], ENT_QUOTES, 'UTF-8') ?>"
            alt="<?= $appName ?> logo"
            class="max-h-24 max-w-xs object-contain"
        >
    <?php else: ?>
        <!-- Placeholder icon when no logo configured -->
        <div class="w-20 h-20 rounded-full bg-primary/10 flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
            </svg>
        </div>
    <?php endif; ?>

    <!-- App name + version -->
    <div>
        <h2 class="text-3xl font-extrabold text-base-content"><?= $appName ?></h2>
        <p class="text-base-content/60 text-sm mt-1">Version <?= $appVersion ?></p>
    </div>

    <!-- Welcome text -->
    <p class="text-base-content/80 text-base leading-relaxed max-w-lg">
        <?= htmlspecialchars($welcomeText, ENT_QUOTES, 'UTF-8') ?>
    </p>

    <!-- Info: what the wizard will do -->
    <div class="bg-info/10 border border-info/30 rounded-xl p-4 text-left w-full max-w-md">
        <p class="font-semibold text-info-content mb-2 text-sm">This wizard will:</p>
        <ul class="space-y-1 text-sm text-base-content/80">
            <li class="flex items-start gap-2">
                <span class="text-success mt-0.5">&#10003;</span>
                Check your server meets the requirements
            </li>
            <li class="flex items-start gap-2">
                <span class="text-success mt-0.5">&#10003;</span>
                Configure database and application settings
            </li>
            <li class="flex items-start gap-2">
                <span class="text-success mt-0.5">&#10003;</span>
                Download and install the application files
            </li>
            <li class="flex items-start gap-2">
                <span class="text-success mt-0.5">&#10003;</span>
                Run database migrations and create your admin account
            </li>
        </ul>
    </div>

    <!-- CTA -->
    <form method="POST" action="install.php" class="mt-2">
        <input type="hidden" name="step"       value="system-check">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn btn-primary btn-lg gap-2">
            Let&rsquo;s get started
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
            </svg>
        </button>
    </form>

</div>

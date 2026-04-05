<?php
/**
 * Step: Installation Complete
 *
 * Variables available:
 *  - $data['postInstallUrl']    string  URL to redirect the user to after install
 *  - $data['installerRemoved']  bool    Whether install.php was automatically deleted
 *  - $config                    array   Full installer config
 *  - $csrfToken                 string  CSRF token (not used here, but available)
 */
$postInstallUrl   = $data['postInstallUrl']   ?? '/';
$installerRemoved = $data['installerRemoved'] ?? false;
$appName          = htmlspecialchars($config['branding']['name'] ?? 'your application', ENT_QUOTES, 'UTF-8');
?>

<div class="flex flex-col items-center text-center gap-6 py-4">

    <!-- Success icon -->
    <div class="w-20 h-20 rounded-full bg-success/15 flex items-center justify-center">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
        </svg>
    </div>

    <!-- Heading -->
    <div>
        <h2 class="text-2xl font-extrabold text-base-content">Installation Complete!</h2>
        <p class="text-base-content/60 text-sm mt-1">
            <?= $appName ?> has been successfully installed.
        </p>
    </div>

    <!-- Security notice -->
    <div class="alert <?= $installerRemoved ? 'alert-success' : 'alert-warning' ?> w-full max-w-md text-left">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <?php if ($installerRemoved): ?>
                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
            <?php else: ?>
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            <?php endif; ?>
        </svg>
        <div>
            <?php if ($installerRemoved): ?>
                <p class="font-semibold">Security: Installer removed</p>
                <p class="text-sm mt-0.5">The installer files have been automatically deleted from your server.</p>
            <?php else: ?>
                <p class="font-semibold">Security Notice</p>
                <p class="text-sm mt-0.5">
                    Please <strong>delete <code><?= htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8') ?></code>, <code><?= htmlspecialchars(pathinfo($scriptName, PATHINFO_FILENAME) . '.zip', ENT_QUOTES, 'UTF-8') ?></code>, and <code>installer-config.php</code></strong>
                    from your server immediately. Leaving them in place is a security risk.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- What was done summary -->
    <div class="bg-base-200 rounded-xl p-4 text-left w-full max-w-md">
        <p class="font-semibold text-sm text-base-content mb-2">Installation summary:</p>
        <ul class="space-y-1 text-sm text-base-content/70">
            <li class="flex items-center gap-2">
                <span class="text-success">&#10003;</span>
                Application files downloaded &amp; extracted
            </li>
            <li class="flex items-center gap-2">
                <span class="text-success">&#10003;</span>
                Environment configuration written to <code>.env</code>
            </li>
            <li class="flex items-center gap-2">
                <span class="text-success">&#10003;</span>
                Database migrations completed
            </li>
            <?php if (! empty($config['auth']['system']) && $config['auth']['system'] !== 'none'): ?>
                <li class="flex items-center gap-2">
                    <span class="text-success">&#10003;</span>
                    Admin account created
                </li>
            <?php endif; ?>
            <?php if (! empty($config['post_install']['seed'])): ?>
                <li class="flex items-center gap-2">
                    <span class="text-success">&#10003;</span>
                    Database seeded
                </li>
            <?php endif; ?>
        </ul>
    </div>

    <!-- CTA -->
    <div class="flex flex-col sm:flex-row gap-3 mt-2">
        <a
            href="<?= htmlspecialchars($postInstallUrl, ENT_QUOTES, 'UTF-8') ?>"
            class="btn btn-primary btn-lg gap-2"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
            </svg>
            Visit Your Site
        </a>
    </div>

    <!-- Attribution -->
    <p class="text-xs text-base-content/40 mt-2">
        Installed with the CI4 Installer by EnlivenApp
    </p>

</div>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($stepTitle, ENT_QUOTES, 'UTF-8') ?> &mdash; <?= htmlspecialchars($config['branding']['name'] ?? 'Installer', ENT_QUOTES, 'UTF-8') ?></title>
    <!-- Tailwind CSS + DaisyUI: CDN first, inline fallback from build -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daisyui@4.12.14/dist/full.min.css" onerror="this.remove()">
    <script src="https://cdn.tailwindcss.com" onerror="this.remove()"></script>
    <style>
        /* DAISYUI_CSS */
    </style>
    <style>
        <?= $brandingCss ?>

        /* Installer-specific overrides */
        .installer-step-active .step-circle {
            background-color: var(--brand-primary, oklch(var(--p)));
        }
        .step-content-enter {
            animation: fadeSlideIn 0.25s ease-out;
        }
        @keyframes fadeSlideIn {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .installer-logo {
            max-height: 64px;
            max-width: 200px;
            object-fit: contain;
        }
        .progress-step-label {
            font-size: 0.65rem;
            line-height: 1.1;
        }
    </style>
</head>
<body class="min-h-screen bg-base-200 flex flex-col">

    <!-- Header -->
    <header class="navbar bg-base-100 shadow-sm border-b border-base-300 px-4">
        <div class="flex-1 flex items-center gap-3">
            <?php if (! empty($config['branding']['logo'])): ?>
                <img
                    src="<?= htmlspecialchars($config['branding']['logo'], ENT_QUOTES, 'UTF-8') ?>"
                    alt="<?= htmlspecialchars($config['branding']['name'] ?? '', ENT_QUOTES, 'UTF-8') ?> logo"
                    class="installer-logo"
                >
            <?php endif; ?>
            <span class="text-xl font-bold text-base-content">
                <?= htmlspecialchars($config['branding']['name'] ?? 'Installer', ENT_QUOTES, 'UTF-8') ?>
                <?php if (! empty($config['branding']['version'])): ?>
                    <span class="text-sm font-normal text-base-content/60 ml-1">
                        v<?= htmlspecialchars($config['branding']['version'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
            </span>
        </div>
        <div class="flex-none">
            <span class="badge badge-ghost badge-sm">Installation Wizard</span>
        </div>
    </header>

    <!-- Main -->
    <main class="flex-1 flex flex-col items-center justify-start py-8 px-4">

        <!-- Progress Steps -->
        <div class="w-full max-w-3xl mb-6 overflow-x-auto">
            <ul class="steps steps-horizontal w-full text-xs">
                <?php
                $stepLabels = [
                    1 => 'Welcome',
                    2 => 'System',
                    3 => 'Files',
                    4 => 'Database',
                    5 => 'Config',
                    6 => 'Settings',
                    7 => 'Admin',
                    8 => 'Install',
                    9 => 'Done',
                ];
                for ($i = 1; $i <= $totalSteps; $i++):
                    $isDone    = $i < $currentStep;
                    $isCurrent = $i === $currentStep;
                    $classes   = 'step progress-step-label';
                    if ($isDone || $isCurrent) {
                        $classes .= ' step-primary';
                    }
                ?>
                    <li class="<?= $classes ?>"><?= htmlspecialchars($stepLabels[$i] ?? (string) $i, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endfor; ?>
            </ul>
        </div>

        <!-- Card -->
        <div class="card bg-base-100 shadow-xl w-full max-w-3xl step-content-enter">
            <div class="card-body p-6 md:p-8">

                <!-- Step title -->
                <h1 class="card-title text-2xl font-bold mb-6 pb-4 border-b border-base-200">
                    <?= htmlspecialchars($stepTitle, ENT_QUOTES, 'UTF-8') ?>
                    <span class="ml-auto text-sm font-normal text-base-content/50 badge badge-ghost">
                        Step <?= $currentStep ?> of <?= $totalSteps ?>
                    </span>
                </h1>

                <!-- Step content injected here -->
                <?= $content ?>

            </div>
        </div>

    </main>

    <!-- Footer -->
    <footer class="footer footer-center p-4 bg-base-100 border-t border-base-300 text-base-content/60 text-xs mt-auto">
        <div class="flex flex-col sm:flex-row items-center gap-2">
            <span>Original script by <strong>EnlivenApp</strong></span>
            <?php if (! empty($config['branding']['support_url']) || ! empty($config['branding']['support_email'])): ?>
                <span class="hidden sm:inline">&middot;</span>
                <?php if (! empty($config['branding']['support_url'])): ?>
                    <a
                        href="<?= htmlspecialchars($config['branding']['support_url'], ENT_QUOTES, 'UTF-8') ?>"
                        class="link link-hover"
                        target="_blank"
                        rel="noopener noreferrer"
                    >Support</a>
                <?php elseif (! empty($config['branding']['support_email'])): ?>
                    <a
                        href="mailto:<?= htmlspecialchars($config['branding']['support_email'], ENT_QUOTES, 'UTF-8') ?>"
                        class="link link-hover"
                    ><?= htmlspecialchars($config['branding']['support_email'], ENT_QUOTES, 'UTF-8') ?></a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </footer>

    <!-- Alpine.js: CDN first, inline fallback from build -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js" onerror="this.remove()"></script>
    <script>
        /* ALPINE_JS */
    </script>

</body>
</html>

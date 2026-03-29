<?php

namespace CI4Installer\UI;

/**
 * Template engine and step router for the installer wizard.
 *
 * Handles PHP template rendering, layout wrapping, CSRF token management,
 * and branding CSS property generation.
 */
class WizardRenderer
{
    private string $templateDir;
    private array $config;

    public function __construct(string $templateDir, array $config)
    {
        $this->templateDir = rtrim($templateDir, DIRECTORY_SEPARATOR);
        $this->config      = $config;

        // Start the session if not already active (needed for CSRF).
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Render a named step template inside the full page layout.
     *
     * @param string $step  Template slug, e.g. 'welcome', 'database'
     * @param array  $data  Variables to expose inside the template
     */
    public function render(string $step, array $data = []): string
    {
        $stepMeta = $this->getStepMeta($step);

        // Render the inner step template first.
        $templateFile = $this->templateDir . DIRECTORY_SEPARATOR . 'step-' . $step . '.php';
        $innerContent = $this->renderTemplate($templateFile, array_merge($data, [
            'config'    => $this->config,
            'csrfToken' => $this->getCsrfToken(),
            'step'      => $step,
        ]));

        // Wrap in the full layout.
        return $this->renderLayout(
            $innerContent,
            $stepMeta['title'],
            $stepMeta['current'],
            $stepMeta['total'],
        );
    }

    /**
     * Build CSS custom properties from branding config.
     */
    public function getBrandingCss(): string
    {
        $colors = $this->config['branding']['colors'] ?? [];

        $primary    = $this->sanitizeColor($colors['primary']    ?? '#570df8');
        $secondary  = $this->sanitizeColor($colors['secondary']  ?? '#f000b8');
        $accent     = $this->sanitizeColor($colors['accent']     ?? '#1dcdbc');
        $text       = $this->sanitizeColor($colors['text']       ?? '#1a1a1a');
        $background = $this->sanitizeColor($colors['background'] ?? '#ffffff');

        return ":root {\n"
            . "  --brand-primary: {$primary};\n"
            . "  --brand-secondary: {$secondary};\n"
            . "  --brand-accent: {$accent};\n"
            . "  --brand-text: {$text};\n"
            . "  --brand-background: {$background};\n"
            . "}\n";
    }

    /**
     * Get (or generate) a CSRF token stored in the session.
     */
    public function getCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Validate a submitted CSRF token against the session value.
     */
    public function validateCsrf(string $token): bool
    {
        $stored = $_SESSION['csrf_token'] ?? '';

        if ($stored === '' || $token === '') {
            return false;
        }

        return hash_equals($stored, $token);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Render the full HTML layout, injecting the step content.
     */
    private function renderLayout(
        string $content,
        string $stepTitle,
        int    $currentStep,
        int    $totalSteps,
    ): string {
        $layoutFile = $this->templateDir . DIRECTORY_SEPARATOR . 'layout.php';

        return $this->renderTemplate($layoutFile, [
            'content'     => $content,
            'stepTitle'   => $stepTitle,
            'currentStep' => $currentStep,
            'totalSteps'  => $totalSteps,
            'brandingCss' => $this->getBrandingCss(),
            'config'      => $this->config,
        ]);
    }

    /**
     * Render a PHP template file by extracting $data into local scope.
     *
     * @throws \RuntimeException if the template file is not found.
     */
    private function renderTemplate(string $template, array $data): string
    {
        if (! is_file($template)) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        // Bring all data keys into local scope for the template.
        extract($data, EXTR_SKIP);

        ob_start();

        try {
            include $template;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Map a step slug to its display metadata.
     *
     * Returns ['title' => string, 'current' => int, 'total' => int].
     */
    private function getStepMeta(string $step): array
    {
        $steps = [
            'welcome'       => ['title' => 'Welcome',              'current' => 1],
            'system-check'  => ['title' => 'System Requirements',  'current' => 2],
            'filesystem'    => ['title' => 'Filesystem Access',    'current' => 3],
            'database'      => ['title' => 'Database Setup',       'current' => 4],
            'configuration' => ['title' => 'App Configuration',    'current' => 5],
            'app-settings'  => ['title' => 'Application Settings', 'current' => 6],
            'admin'         => ['title' => 'Admin Account',        'current' => 7],
            'install'       => ['title' => 'Installing',           'current' => 8],
            'complete'      => ['title' => 'Installation Complete', 'current' => 9],
        ];

        $meta = $steps[$step] ?? ['title' => ucfirst($step), 'current' => 1];

        return [
            'title'   => $meta['title'],
            'current' => $meta['current'],
            'total'   => count($steps),
        ];
    }

    /**
     * Sanitize a color value to prevent CSS injection.
     * Allows hex colors, rgb(), hsl(), and named colors.
     */
    private function sanitizeColor(string $color): string
    {
        $color = trim($color);

        // Whitelist: hex (#abc, #aabbcc, #aabbccdd), rgb/rgba/hsl/hsla, plain word chars
        if (preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\)|hsla?\([^)]+\)|[a-zA-Z]+)$/', $color)) {
            return $color;
        }

        return '#570df8'; // fallback to DaisyUI default primary
    }
}

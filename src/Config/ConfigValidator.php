<?php

namespace CI4Installer\Config;

/**
 * Validates and normalises the installer-config.php configuration array.
 */
class ConfigValidator
{
    // ---------------------------------------------------------------------------
    // Allowed value sets
    // ---------------------------------------------------------------------------

    private const ALLOWED_AUTH_SYSTEMS = [
        'shield',
        'ion_auth',
        'myth_auth',
        'custom',
        'none',
    ];

    private const ALLOWED_PUBLIC_DIR_HANDLING = [
        'auto',
        'isolate',
        'htaccess',
        'flatten',
        'none',
    ];

    // ---------------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------------

    /**
     * Validates the installer config array.
     *
     * @param  array $config Raw config as loaded from installer-config.php.
     * @return string[]      List of human-readable error strings. Empty = valid.
     */
    public static function validate(array $config): array
    {
        $errors = [];

        // -----------------------------------------------------------------------
        // Required keys
        // -----------------------------------------------------------------------

        // branding.name
        $brandingName = $config['branding']['name'] ?? null;

        if (! is_string($brandingName) || trim($brandingName) === '') {
            $errors[] = "'branding.name' is required and must be a non-empty string.";
        }

        // source (must be an array)
        if (! isset($config['source']) || ! is_array($config['source'])) {
            $errors[] = "'source' is required and must be an array.";
        } else {
            // source.zip
            $sourceZip = $config['source']['zip'] ?? null;

            if (! is_string($sourceZip) || trim($sourceZip) === '') {
                $errors[] = "'source.zip' is required and must be a non-empty string.";
            }
        }

        // requirements.php
        $phpRequirement = $config['requirements']['php'] ?? null;

        if ($phpRequirement !== null) {
            if (! is_string($phpRequirement) || ! self::isValidVersionString((string) $phpRequirement)) {
                $errors[] = "'requirements.php' must be a valid version string (e.g. '8.1', '8.1.0', '>=8.1').";
            }
        }

        // -----------------------------------------------------------------------
        // Conditional requirements
        // -----------------------------------------------------------------------

        // post_install.seed = true  →  post_install.seeder_class required
        $postInstallSeed = $config['post_install']['seed'] ?? false;

        if ($postInstallSeed === true) {
            $seederClass = $config['post_install']['seeder_class'] ?? null;

            if (! is_string($seederClass) || trim($seederClass) === '') {
                $errors[] = "'post_install.seeder_class' is required when 'post_install.seed' is true.";
            }
        }

        // auth.system validation
        $authSystem = $config['auth']['system'] ?? 'none';

        if (! in_array($authSystem, self::ALLOWED_AUTH_SYSTEMS, true)) {
            $errors[] = sprintf(
                "'auth.system' must be one of: %s. Got: '%s'.",
                implode(', ', self::ALLOWED_AUTH_SYSTEMS),
                $authSystem,
            );
        } else {
            // auth.system !== 'none'  →  auth.collect required
            if ($authSystem !== 'none') {
                if (! isset($config['auth']['collect'])) {
                    $errors[] = "'auth.collect' is required when 'auth.system' is not 'none'.";
                }
            }

            // auth.system in (shield, ion_auth, myth_auth)  →  auth.group required
            if (in_array($authSystem, ['shield', 'ion_auth', 'myth_auth'], true)) {
                $authGroup = $config['auth']['group'] ?? null;

                if (! is_string($authGroup) || trim($authGroup) === '') {
                    $errors[] = "'auth.group' is required when 'auth.system' is '{$authSystem}'.";
                }
            }

            // auth.system = 'custom'  →  auth.table, auth.fields, auth.hash_method required
            if ($authSystem === 'custom') {
                $authTable = $config['auth']['table'] ?? null;

                if (! is_string($authTable) || trim($authTable) === '') {
                    $errors[] = "'auth.table' is required when 'auth.system' is 'custom'.";
                }

                $authFields = $config['auth']['fields'] ?? null;

                if (! is_array($authFields) || count($authFields) === 0) {
                    $errors[] = "'auth.fields' is required and must be a non-empty array when 'auth.system' is 'custom'.";
                }

                $hashMethod = $config['auth']['hash_method'] ?? null;

                if (! is_string($hashMethod) || trim($hashMethod) === '') {
                    $errors[] = "'auth.hash_method' is required when 'auth.system' is 'custom'.";
                }
            }
        }

        // public_dir_handling (optional, but if present must be a known value)
        if (isset($config['public_dir_handling'])) {
            if (! in_array($config['public_dir_handling'], self::ALLOWED_PUBLIC_DIR_HANDLING, true)) {
                $errors[] = sprintf(
                    "'public_dir_handling' must be one of: %s. Got: '%s'.",
                    implode(', ', self::ALLOWED_PUBLIC_DIR_HANDLING),
                    $config['public_dir_handling'],
                );
            }
        }

        return $errors;
    }

    /**
     * Returns the config array with all optional keys filled to their defaults.
     *
     * Does not overwrite keys that are already set.
     *
     * @param  array $config Raw (or partially-populated) config array.
     * @return array         Config with defaults applied.
     */
    public static function withDefaults(array $config): array
    {
        // branding.version
        if (! isset($config['branding']['version'])) {
            $config['branding']['version'] = '1.0.0';
        }

        // branding.colors
        if (! isset($config['branding']['colors'])) {
            $config['branding']['colors'] = [
                'primary'    => '#e44d26',
                'secondary'  => '#f7f7f7',
                'accent'     => '#264de4',
                'text'       => '#1a1a1a',
                'background' => '#ffffff',
            ];
        }

        // writable_dirs
        if (! isset($config['writable_dirs'])) {
            $config['writable_dirs'] = [
                'writable/cache',
                'writable/logs',
                'writable/session',
                'writable/uploads',
            ];
        }

        // writable_dir_permissions
        if (! isset($config['writable_dir_permissions'])) {
            $config['writable_dir_permissions'] = 0755;
        }

        // env_vars
        if (! isset($config['env_vars'])) {
            $config['env_vars'] = [];
        }

        // post_install.migrate
        if (! isset($config['post_install']['migrate'])) {
            $config['post_install']['migrate'] = true;
        }

        // post_install.seed
        if (! isset($config['post_install']['seed'])) {
            $config['post_install']['seed'] = false;
        }

        // auth.system
        if (! isset($config['auth']['system'])) {
            $config['auth']['system'] = 'none';
        }

        // public_dir_handling
        if (! isset($config['public_dir_handling'])) {
            $config['public_dir_handling'] = 'auto';
        }

        // post_install_url
        if (! isset($config['post_install_url'])) {
            $config['post_install_url'] = '/';
        }

        return $config;
    }

    // ---------------------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------------------

    /**
     * Returns true if $version looks like a valid (optionally-prefixed) version
     * string, e.g. '8.1', '8.1.0', '>=8.1', '^8.1', '~8.1.0'.
     */
    private static function isValidVersionString(string $version): bool
    {
        // Strip recognised constraint prefixes.
        $stripped = ltrim($version, '^~>=<! ');

        // Must consist of digit groups separated by dots (1–4 segments).
        return (bool) preg_match('/^\d+(\.\d+){0,3}$/', $stripped);
    }
}

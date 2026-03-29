<?php

namespace CI4Installer\Auth;

/**
 * Creates the correct AuthAdapterInterface implementation based on the
 * 'auth.system' value in the installer configuration array.
 *
 * Supported system values:
 *   'shield'    → ShieldAdapter
 *   'ion_auth'  → IonAuthAdapter
 *   'myth_auth' → MythAuthAdapter
 *   'custom'    → GenericAdapter  (requires $config['auth'] and $dbCredentials)
 *   'none'      → NoneAdapter
 */
class AuthAdapterFactory
{
    /**
     * Build and return the appropriate adapter.
     *
     * @param array  $config        Full installer config array.  Must contain
     *                              $config['auth']['system'] at minimum.
     * @param string $appRoot       Absolute path to the target CI4 application
     *                              root (no trailing slash).
     * @param array  $dbCredentials Database credentials forwarded to
     *                              GenericAdapter when system === 'custom'.
     *
     * @throws \InvalidArgumentException When the system value is unrecognised.
     */
    public static function create(
        array  $config,
        string $appRoot,
        array  $dbCredentials = [],
    ): AuthAdapterInterface {
        $system = $config['auth']['system'] ?? 'none';

        return match ($system) {
            'shield'    => new ShieldAdapter(
                $appRoot,
                $config['auth']['group'] ?? 'superadmin',
            ),
            'ion_auth'  => new IonAuthAdapter(
                $appRoot,
                $config['auth']['group'] ?? 'admin',
            ),
            'myth_auth' => new MythAuthAdapter(
                $appRoot,
                $config['auth']['group'] ?? 'admin',
            ),
            'custom'    => new GenericAdapter(
                $config['auth'],
                $dbCredentials,
            ),
            'none'      => new NoneAdapter(),
            default     => throw new \InvalidArgumentException(
                sprintf(
                    'AuthAdapterFactory: unknown auth system "%s". '
                    . 'Expected one of: shield, ion_auth, myth_auth, custom, none.',
                    $system,
                )
            ),
        };
    }
}

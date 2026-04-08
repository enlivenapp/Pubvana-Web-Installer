<?php
/**
 * CI4 Installer Configuration
 *
 * Customize this file for your CodeIgniter 4 application.
 * See the README for full documentation of all options.
 */
$version = '2.3.1';

return [
    // --- Branding ---
    'branding' => [
        'name'          => 'Pubvana CMS',
        'version'       => $version,
        'logo'          => 'https://cdn.pubvana.net/pubvana_logo.png',
        'support_url'   => 'https://pubvana.net/contact',
        'support_email' => 'cs@pubvana.net',
        'welcome_text'  => 'Welcome to the Pubvana Installer',  // empty = auto-generated from app name
        'colors'        => [
            'primary'   => '#0f172a',
            'secondary' => '#1e293b',
            'accent'    => '#f59e0b',
            'neutral'   => '#1e293b',
            'base-100'  => '#FFFFFF',
            'base-200'  => '#fafaf9',
            'base-300'  => '#e2e8f0',
        ],
    ],

    // --- Download Source ---
    // source.zip is REQUIRED. composer and git are optional enhancements.
    'source' => [
        'zip' => "https://github.com/enlivenapp/pubvana/releases/download/v{$version}/release.zip",
    ],

    // --- Server Requirements ---
    'requirements' => [
        'php'        => '8.2',
        'extensions' => ['curl', 'mbstring', 'intl', 'json', 'fileinfo', 'openssl'],
        'databases'  => ['MySQLi'],  // Options: MySQLi, Postgre, SQLite3, SQLSRV
    ],

    // --- Writable Directories ---
    'writable_dirs'            => [
        'writable/cache',
        'writable/logs',
        'writable/session',
        'writable/uploads',
        'writable/tmp',
        'writable/backups',
        'writable/updates',
        'public/assets',
        'public/images',
        'public/plugins',
        'public/themes',
    ],
    'writable_dir_permissions' => 0755,

    // --- Custom ENV Variables ---
    // Each entry creates a form field in the wizard.
    // Types: text, password, email, url, select, boolean
    'env_vars' => [
        // Example:
        // [
        //     'key'      => 'app.myKey',
        //     'label'    => 'My Setting',
        //     'type'     => 'text',
        //     'required' => false,
        //     'group'    => 'General',
        //     'help'     => 'Description of this setting.',
        //     'default'  => '',
        //     'validate' => '',  // regex pattern, e.g. 'regex:/^sk_/'
        // ],
    ],

    // --- Post-Install Actions ---
    'post_install' => [
        'migrate'       => true,
        'seed'          => true,
        'seeder_class'  => 'App\\Database\\Seeds\\DatabaseSeeder',
    ],

    // --- Authentication ---
    // system: shield, custom, none
    'auth' => [
        'system'  => 'shield',
        'collect' => ['username', 'email', 'password'],
        'group'   => 'superadmin',
        // For 'custom' system only:
        // 'table'         => 'users',
        // 'fields'        => ['email' => 'email', 'password' => 'password_hash'],
        // 'hash_method'   => 'PASSWORD_DEFAULT',
        // 'extra_inserts' => ['role' => 'admin', 'active' => 1],
    ],

    // --- CI4 Public Directory Handling ---
    // auto: try isolate → htaccess → flatten
    // isolate: move app files outside document root (most secure)
    // htaccess: rewrite to public/ via .htaccess/web.config
    // flatten: move index.php to root (last resort)
    // none: developer's zip already handles it
    'public_dir_handling' => 'none',

    // --- Post-Install Redirect ---
    'post_install_url' => '/login',
];

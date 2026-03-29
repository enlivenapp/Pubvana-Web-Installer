# CodeIgniter 4 Flexible Web App Installer

**By EnlivenApp**

A generic, reusable web-based installer for CodeIgniter 4 applications. Drop it into your project, configure what your app needs, and give your users a guided setup wizard -- no CLI required on their end, probably.

[![License: CC BY 4.0](https://img.shields.io/badge/License-CC%20BY%204.0-lightgrey.svg)](https://creativecommons.org/licenses/by/4.0/)

---

## What Is This?

This project produces a single self-extracting `install.php` file that walks your users through installing your CI4 application via a step-by-step web wizard. You define what your app needs in a config file; the installer handles environment checks, downloading, configuration, migrations, and admin setup.

**Key features:**

- Works on shared hosting with no CLI, SSH, or composer access
- Supports Apache, Nginx, LiteSpeed, and IIS
- Supports all four CI4 database drivers: MySQLi, PostgreSQL, SQLite3, SQL Server
- Filesystem abstraction: Direct PHP, FTP, FTPS, SSH2
- Graduated fallback chains for every operation -- if the preferred method fails, there is always a next option or manual instructions
- Polished UI with DaisyUI and Alpine.js
- Detects server capabilities automatically and adapts
- Attempts to self-delete after installation

**As an app developer**, you configure what your app needs in `installer-config.php`. The installer handles the rest: environment checks, downloading your app, writing `.env`, running migrations, creating the admin user.

Your end user downloads a single zip, extracts it on their server, and clicks through a wizard. That is it.

---

## Quick Start

### For App Developers

1. **Clone the repo**
   ```bash
   git clone git@github.com:enlivenapp/CodeIgniter4-Flexible-Web-App-Installer.git
   cd CodeIgniter4-Flexible-Web-App-Installer
   ```

2. **Edit `installer-config.php`** for your application (see Configuration Reference below)

3. **Build the installer**
   ```bash
   php build/pack.php
   ```
   This produces `dist/installer.zip` containing both `install.php` and your `installer-config.php`.

4. **Distribute `dist/installer.zip` to your users with the filename of your choosing.** They extract it on their server and navigate to `install.php` in a browser.

---

## Configuration Reference

The `installer-config.php` file controls everything the installer does. Every key is documented below.

### branding

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `name` | string | Yes | -- | Your application name. Displayed throughout the wizard. |
| `version` | string | No | `'1.0.0'` | Version string shown on the welcome screen. |
| `logo` | string | No | `''` | Path to a logo image (relative or URL). Empty for no logo. |
| `support_url` | string | No | `''` | URL shown in error messages ("Visit X for help"). |
| `support_email` | string | No | `''` | Email shown in error messages if no support_url. |
| `welcome_text` | string | No | Auto-generated | Custom welcome message on the first screen. |
| `colors` | array | No | Built-in scheme | DaisyUI color overrides (see below). |

**Color keys:** `primary`, `secondary`, `accent`, `neutral`, `base-100`, `base-200`, `base-300`. Values are CSS color strings (hex, rgb, hsl).

### source

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `zip` | string | **Yes** | Direct download URL to a zip file containing your app. This is the universal fallback -- it must always be provided. |
| `composer` | string | No | Packagist package name (e.g., `'vendor/package'`). Used when composer is available on the server. |
| `git` | string | No | Git repository URL. Used when git is available on the server. |

The installer picks the best download method based on what the server supports: composer (fastest) > git > cURL download > PHP streams > manual upload instructions.

**Important:** The zip file must include the `vendor/` directory if you want it to work on servers without composer. See "Preparing Your Release ZIP" below.

### requirements

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `php` | string | `'8.2'` | Minimum PHP version required. |
| `extensions` | array | `[]` | PHP extensions your app needs (e.g., `['curl', 'mbstring', 'intl']`). |
| `databases` | array | All four | Restrict which database drivers are offered. Options: `MySQLi`, `Postgre`, `SQLite3`, `SQLSRV`. |

### writable_dirs

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `writable_dirs` | array | `['writable/cache', 'writable/logs', 'writable/session', 'writable/uploads']` | Directories the installer creates and sets permissions on. |
| `writable_dir_permissions` | int | `0755` | Permission mode for writable directories (ignored on Windows). |

### env_vars

An array of custom environment variables your app needs. Each entry creates a form field in the wizard.

```php
'env_vars' => [
    [
        'key'      => 'stripe.secretKey',     // The .env key to set
        'label'    => 'Stripe Secret Key',    // Form field label
        'type'     => 'password',             // Input type (see below)
        'required' => true,                   // Block installation if empty?
        'group'    => 'Stripe',               // Group heading in the wizard
        'help'     => 'Find this in your Stripe dashboard.',  // Help text
        'default'  => '',                     // Default value
        'validate' => 'regex:/^sk_/',         // Validation pattern (optional)
    ],
],
```

**Available types:** `text`, `password`, `email`, `url`, `select`, `boolean`

For `select` type, add an `options` key with an array of choices.

### post_install

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `migrate` | bool | `true` | Run database migrations after install. |
| `seed` | bool | `false` | Run a database seeder after migrations. |
| `seeder_class` | string | `''` | Fully qualified seeder class name. Required if `seed` is `true`. Example: `'App\\Database\\Seeds\\DefaultSeeder'` |

### auth

Controls admin user creation during installation.

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `system` | string | No (default: `'none'`) | Auth library. Options: `shield`, `ion_auth`, `myth_auth`, `custom`, `none`. |
| `collect` | array | If system is not `none` | Fields to show in the wizard. Example: `['username', 'email', 'password']` |
| `group` | string | For shield/ion_auth/myth_auth | Group or role to assign the admin. Example: `'superadmin'` |

**For `custom` auth systems**, additional keys are required:

| Key | Type | Description |
|-----|------|-------------|
| `table` | string | Database table name for users. |
| `fields` | array | Maps wizard fields to database columns: `['email' => 'email_column', 'password' => 'pass_hash_column']` |
| `hash_method` | string | PHP `password_hash()` algorithm constant. Example: `'PASSWORD_DEFAULT'` |
| `extra_inserts` | array | Additional columns to set: `['role' => 'admin', 'active' => 1]` |

### public_dir_handling

Controls how the installer handles CI4's `public/` directory convention.

| Value | Description |
|-------|-------------|
| `auto` | **(Default)** Try each strategy in order: isolate, htaccess, flatten. |
| `isolate` | Move app files (`app/`, `vendor/`, `writable/`) outside the document root. Most secure. |
| `htaccess` | Write a root `.htaccess` (Apache/LiteSpeed) or `web.config` (IIS) to route requests to `public/`. Nginx gets manual instructions. |
| `flatten` | Copy `index.php` to the root directory. Last resort -- works but less secure. |
| `none` | Skip this step. Use when your release zip already handles the directory structure. |

### post_install_url

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `post_install_url` | string | `'/'` | URL to redirect to after installation completes. |

---

## How It Works

The installer runs through five phases:

### Phase 1: Environment Detection

Probes the server by actually attempting operations (not just checking flags):
- PHP version and extensions
- Server software (Apache, Nginx, LiteSpeed, IIS)
- Available database drivers
- exec() and shell_exec() availability
- Composer and Git presence
- Filesystem ownership (determines Direct vs FTP/SSH access)
- HTTPS status
- Outbound HTTP connectivity

### Phase 2: Requirements Check

Compares detected capabilities against your `requirements` config. Shows a green/red/yellow checklist. Hard failures block installation; warnings allow proceeding.

### Phase 3: User Configuration

Collects values through the wizard:
- Database credentials (with live connection testing)
- Base URL (auto-detected, user confirms)
- Encryption key (auto-generated)
- Custom env vars from your config
- Admin user credentials (if auth is configured)
- FTP/SSH credentials (only if direct filesystem access is unavailable)

### Phase 4: Installation

Each substep runs as a separate AJAX request to manage execution time on shared hosting:

| Substep | Description |
|---------|-------------|
| 4a | Download the application (composer/git/zip/manual) |
| 4b | Extract and normalize directory structure |
| 4c | Validate (check for vendor/, spark, app/) |
| 4d | Handle public/ directory convention |
| 4e | Write .env file |
| 4f | Set directory permissions |
| 4g | Run migrations |
| 4h | Run seeders (if configured) |
| 4i | Create admin user (if configured) |

### Phase 5: Cleanup

- Deletes `install.php` (creates `install.lock` as fallback if delete fails)
- Removes temporary extraction directory
- Redirects to your application

---

## Server Compatibility

### Minimum Requirements

- PHP 8.2 or higher
- At least one supported database driver

### Supported Web Servers

| Server | How Handled |
|--------|-------------|
| Apache | Full support. Writes `.htaccess` for rewrites and directory protection. |
| LiteSpeed | Full support. Uses `.htaccess` (LiteSpeed is Apache-compatible). |
| IIS | Writes `web.config` with URL Rewrite rules and request filtering. |
| Nginx | Shows manual configuration instructions (Nginx ignores `.htaccess`). |

### Supported Databases

| Driver | Extension | Notes |
|--------|-----------|-------|
| MySQLi | ext-mysqli | Most common on shared hosting. |
| PostgreSQL | ext-pgsql | Common on VPS and managed DB services. |
| SQLite3 | ext-sqlite3 | No server needed. Installer validates path and permissions. |
| SQL Server | ext-sqlsrv | Windows/enterprise environments. |

### Filesystem Access

| Method | When Used |
|--------|-----------|
| Direct PHP | PHP runs as file owner (suPHP, PHP-FPM per-user). Detected automatically. |
| FTP | Direct access fails, `ext-ftp` available. Credentials collected in wizard. |
| FTPS | Direct access fails, `ftp_ssl_connect()` available. |
| SSH2/SFTP | Direct access fails, `ext-ssh2` available. |
| Manual | All methods fail. User shown exact instructions. |

### Optional Capabilities

These are used when available but never required:

| Capability | Benefit |
|------------|---------|
| exec() | Enables composer, git, and spark commands |
| Composer | Fastest download method (composer create-project) |
| Git | Second-fastest download method (git clone) |
| cURL | Preferred HTTP download method |
| ZipArchive | Preferred extraction method |

---

## Building from Source

### Prerequisites

- PHP 8.2+ (for running the build script)
- Optional: Node.js + npm (for building purged DaisyUI CSS)

### Project Structure

```
ci4-installer/
├── src/                    Source code (modular)
│   ├── Installer.php       Main orchestrator
│   ├── Autoloader.php      PSR-4 autoloader
│   ├── Result.php          Universal result object
│   ├── Auth/               Auth adapter system
│   ├── Config/             Config validator + .env writer
│   ├── Database/           Connection tester + migration runner
│   ├── Environment/        Server detection + requirements check
│   ├── Filesystem/         4-driver filesystem abstraction
│   ├── Source/             Download strategy chain
│   └── UI/                 Wizard renderer + templates + assets
├── build/
│   └── pack.php            Build script
├── dist/
│   └── install.php         Generated installer (after build)
├── installer-config.php    Example configuration
├── LICENSE                 CC BY 4.0
└── README.md
```

### Build Process

```bash
php build/pack.php
```

This:
1. Inlines CSS and JS assets into the layout template
2. Collects all files under `src/` into a tar.gz archive
3. Base64-encodes the archive
4. Generates `dist/install.php` with a self-extraction stub

The self-extraction stub includes three fallback methods:
1. PharData (available in most PHP builds)
2. exec('tar') (if exec is available)
3. Pure PHP tar parser (always works if ext-zlib is present)

### Custom Build Output

```bash
php build/pack.php --output=/path/to/install.php
```

### CSS/JS Assets

The layout template loads DaisyUI and Alpine.js from CDN by default. The inline `/* DAISYUI_CSS */` and `/* ALPINE_JS */` placeholders serve as offline fallbacks.

For a production build with purged CSS (smaller file):

```bash
# Install Tailwind + DaisyUI
npm install -D tailwindcss daisyui

# Create tailwind.config.js pointing to src/UI/templates/
# Run: npx tailwindcss -o src/UI/assets/daisyui.min.css --minify

# Then rebuild
php build/pack.php
```

---

## Auth Adapters

The installer supports five auth systems:

### Shield (CodeIgniter Shield)

```php
'auth' => [
    'system'  => 'shield',
    'collect' => ['username', 'email', 'password'],
    'group'   => 'superadmin',
],
```

Creates the user via Shield's UserModel and assigns the specified group.

### IonAuth

```php
'auth' => [
    'system'  => 'ion_auth',
    'collect' => ['username', 'email', 'password'],
    'group'   => 'admin',
],
```

Uses IonAuth's register() method and group assignment.

### Myth:Auth

```php
'auth' => [
    'system'  => 'myth_auth',
    'collect' => ['email', 'password'],
    'group'   => 'admin',
],
```

Creates a user entity via Myth:Auth's UserModel.

### Generic (Custom Auth)

For any auth system not listed above:

```php
'auth' => [
    'system'         => 'custom',
    'collect'        => ['email', 'password'],
    'table'          => 'users',
    'fields'         => [
        'email'      => 'email',
        'password'   => 'password_hash',
    ],
    'hash_method'    => 'PASSWORD_DEFAULT',
    'extra_inserts'  => [
        'role'   => 'admin',
        'active' => 1,
    ],
],
```

Performs a direct database INSERT with password hashing. Works with any table structure.

### None

```php
'auth' => [
    'system' => 'none',
],
```

Skips admin creation entirely. Use this when your app handles first-run registration.

---

## Creating Your App's Download ZIP

The `source.zip` value in your config is a URL where the installer can download your application. When a user runs the installer, it fetches your app from this URL. For servers without composer, the zip **must include the `vendor/` directory** — otherwise there's no way to get the dependencies.

### Step 1: Build the zip

From your CI4 app's root directory:

```bash
composer install --no-dev --optimize-autoloader
zip -r my-app-v1.0.0.zip . -x '.git/*' -x 'tests/*' -x '.env'
```

This creates a zip with everything your app needs to run, minus dev dependencies and sensitive files.

### Step 2: Upload it somewhere

The zip needs to be hosted at a publicly accessible URL. Some options:

- **Your own website** — upload to your server (e.g., `https://yoursite.com/downloads/my-app-v1.0.0.zip`)
- **GitHub Releases** — if your project is on GitHub, create a Release and attach the zip file as an asset
- **Any file host** — anywhere that gives you a direct download link

The key requirement: the URL must be a **direct download link** to the zip file, not a page with a download button.

### Step 3: Set the URL in your config

```php
'source' => [
    'zip' => 'https://yoursite.com/downloads/my-app-v1.0.0.zip',
],
```

That's it. The installer downloads from this URL when your user runs it.

### Automating builds with GitHub Actions (optional, advanced)

If your project is on GitHub, you can automate the zip-building process so you never have to do it manually. GitHub Actions is a free automation service built into GitHub — when something happens in your repo (like tagging a new version), it runs commands for you on GitHub's servers.

The example below does this: when you push a version tag (like `v1.0.0`), GitHub automatically builds the zip and attaches it to a Release page.

Create a file at `.github/workflows/build-release.yml` in your app's repo:

```yaml
name: Build Release

# This runs whenever you push a tag starting with "v" (e.g., v1.0.0)
on:
  push:
    tags: ['v*']

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      # Check out your code
      - uses: actions/checkout@v4

      # Set up PHP on the build server
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'

      # Install your app's composer dependencies (production only)
      - name: Install dependencies
        run: composer install --no-dev --optimize-autoloader

      # Create the zip, excluding files your users don't need
      - name: Create release zip
        run: |
          zip -r release.zip . \
            -x '.git/*' -x 'tests/*' -x '.env' \
            -x '.github/*' -x 'phpunit.xml.dist'

      # Attach the zip to a GitHub Release page.
      # softprops/action-gh-release is a widely-used community action
      # that handles creating the Release and uploading files.
      # You don't need to install anything — GitHub downloads it
      # automatically when the workflow runs.
      - name: Upload release
        uses: softprops/action-gh-release@v1
        with:
          files: release.zip
```

Once this is set up, tag a release and push it:

```bash
git tag v1.0.0
git push origin v1.0.0
```

GitHub builds the zip automatically. Your download URL will be:

```
https://github.com/your-org/your-app/releases/latest/download/release.zip
```

If you don't use GitHub or don't want automation, just build the zip manually and upload it. The installer doesn't care how the zip got there — it just needs the URL.

---

## License

This project is licensed under the [Creative Commons Attribution 4.0 International License](https://creativecommons.org/licenses/by/4.0/).

**Required attribution:** "Original script by EnlivenApp"

You are free to use, modify, and distribute this software for any purpose, including commercial use, as long as you include the attribution.

---

## Contributing

Contributions are welcome. Please open an issue to discuss proposed changes before submitting a pull request.

[Report an issue](https://github.com/enlivenapp/CodeIgniter4-Flexible-Web-App-Installer/issues)

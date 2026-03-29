# Pubvana Installer

Install Pubvana on any PHP hosting environment through a guided web wizard. No command line required.

## What You Get

Pubvana is a CMS and blogging platform built on CodeIgniter 4. This installer handles the full setup: server checks, database configuration, file permissions, migrations, seeding, and admin account creation.

## Requirements

- PHP 8.2 or higher
- MySQL database
- Required PHP extensions: curl, mbstring, intl, json, fileinfo, openssl
- A web server (Apache, LiteSpeed, Nginx, or IIS)

## Installation

### 1. Upload

Extract the installer zip on your server. You should have two files in your web root:

- `install.php`
- `installer-config.php`

### 2. Run the Wizard

Navigate to `install.php` in your browser (e.g., `https://yourdomain.com/install.php`). The wizard walks you through each step:

**System Check** -- verifies your server meets the requirements. Green means good, red means something needs fixing before you can proceed.

**Filesystem** -- the installer figures out how to write files. On most shared hosting this happens automatically. If your PHP process doesn't own the target directory, you'll be asked for FTP or SSH credentials.

**Database** -- enter your MySQL credentials. The installer tests the connection before moving on.

**Configuration** -- confirm your base URL and environment setting. An encryption key is generated automatically.

**Admin Account** -- create your first admin user. You'll set a username, email, and password. This account gets superadmin privileges.

**Install** -- the installer downloads Pubvana, extracts it, writes your `.env` file, runs database migrations, seeds initial data, and creates your admin account. Each step shows progress so you know what's happening.

**Done** -- the installer cleans up after itself and redirects you to the login page.

### 3. Log In

After installation completes, you're redirected to `/login`. Sign in with the admin credentials you just created.

## How Pubvana Gets Downloaded

The installer tries multiple methods in order, picking the best one your server supports:

1. **Composer** (`enlivenapp/pubvana`) -- if composer is available on the server
2. **Git** -- if git is available
3. **Direct download** -- falls back to downloading the zip from GitHub
4. **Manual upload** -- if nothing else works, you get instructions for uploading the files yourself

You don't need to choose. The installer detects what's available and picks the fastest option.

## Web Server Notes

Pubvana ships its own `.htaccess` files for Apache and LiteSpeed, so the installer leaves those alone. If you're running Nginx or IIS, you'll need to configure your server to route requests through the `public/` directory yourself.

## After Installation

The `install.php` file deletes itself when installation completes. If deletion fails (some hosting environments prevent it), an `install.lock` file is created instead, which prevents the installer from running again.

If you need to reinstall, delete both `install.lock` and re-upload `install.php`.

## Troubleshooting

**System check shows red items** -- your hosting environment is missing something Pubvana needs. The most common issue is a missing PHP extension. Contact your hosting provider to enable the required extensions listed above.

**Database connection fails** -- double-check your hostname, username, password, and database name. On shared hosting the database host is usually `localhost`. Some providers use a different hostname, which you can find in your hosting control panel.

**Permission errors during install** -- the installer needs to write files to your web directory. If direct PHP access doesn't work, the wizard will ask for FTP or SSH credentials as an alternative.

**Blank page or 500 error** -- check your PHP error log. The most common cause is an unsupported PHP version.

## Support

Visit [pubvana.net/contact](https://pubvana.net/contact) for help.

## License

Pubvana Installer is licensed under the [Creative Commons Attribution 4.0 International License](https://creativecommons.org/licenses/by/4.0/).

Original installer script by EnlivenApp.

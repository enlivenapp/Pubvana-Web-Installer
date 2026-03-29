<?php
/**
 * CI4 Installer Build Script
 *
 * Compiles the modular source into a single self-extracting install.php.
 *
 * Usage: php build/pack.php [--output=path]
 */

$projectRoot = dirname(__DIR__);
$srcDir = $projectRoot . '/src';
$outputPath = $projectRoot . '/dist/install.php';

// Parse CLI args
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $outputPath = substr($arg, 9);
    }
}

// Ensure output directory exists
$outputDir = dirname($outputPath);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

echo "CI4 Installer Build Script\n";
echo "==========================\n\n";

// --- Step 1: Inline assets into layout template ---
echo "Step 1: Inlining assets into templates...\n";

$cssPath = $srcDir . '/UI/assets/daisyui.min.css';
$jsPath = $srcDir . '/UI/assets/alpine.min.js';
$layoutPath = $srcDir . '/UI/templates/layout.php';

$cssContent = file_exists($cssPath) ? file_get_contents($cssPath) : '/* DaisyUI CSS not found — run Tailwind build first */';
$jsContent = file_exists($jsPath) ? file_get_contents($jsPath) : '/* Alpine.js not found — download alpine.min.js to src/UI/assets/ */';

// We'll inject these into the archive by modifying layout.php content in memory
$layoutContent = file_exists($layoutPath) ? file_get_contents($layoutPath) : '';
if ($layoutContent) {
    $layoutContent = str_replace('/* DAISYUI_CSS */', $cssContent, $layoutContent);
    $layoutContent = str_replace('/* ALPINE_JS */', $jsContent, $layoutContent);
}

// --- Step 2: Collect all source files ---
echo "Step 2: Collecting source files...\n";

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($iterator as $file) {
    if ($file->isFile()) {
        $relativePath = substr($file->getPathname(), strlen($srcDir) + 1);
        // Use forward slashes for tar compatibility
        $relativePath = str_replace('\\', '/', $relativePath);

        // Use modified layout content if this is the layout file
        if ($relativePath === 'UI/templates/layout.php' && $layoutContent) {
            $files[$relativePath] = $layoutContent;
        } else {
            $files[$relativePath] = file_get_contents($file->getPathname());
        }
    }
}

echo "   Collected " . count($files) . " files\n";

// --- Step 3: Create tar.gz archive ---
echo "Step 3: Creating tar.gz archive...\n";

$tarContent = buildTar($files);
$gzContent = gzencode($tarContent, 9);
$base64 = base64_encode($gzContent);

echo "   Archive size: " . formatBytes(strlen($gzContent)) . "\n";
echo "   Base64 size: " . formatBytes(strlen($base64)) . "\n";

// --- Step 4: Generate install.php ---
echo "Step 4: Generating install.php...\n";

$stub = generateStub($base64);
file_put_contents($outputPath, $stub);

$finalSize = filesize($outputPath);
echo "   install.php: " . formatBytes($finalSize) . "\n";

// --- Step 5: Create distribution zip ---
echo "Step 5: Creating distribution zip...\n";

$configSource = $projectRoot . '/installer-config.php';
$zipOutputPath = $outputDir . '/installer.zip';

if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    if ($zip->open($zipOutputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $zip->addFile($outputPath, 'install.php');
        if (file_exists($configSource)) {
            $zip->addFile($configSource, 'installer-config.php');
        }
        $zip->close();
        $zipSize = filesize($zipOutputPath);
        echo "   installer.zip: " . formatBytes($zipSize) . "\n";
    } else {
        echo "   Warning: Could not create zip. ZipArchive failed to open.\n";
        echo "   install.php is still available at: {$outputPath}\n";
    }
} else {
    echo "   Warning: ZipArchive not available. Skipping zip creation.\n";
    echo "   install.php is still available at: {$outputPath}\n";
}

echo "\nBuild complete!\n";
echo "Output directory: {$outputDir}/\n";
echo "   install.php         — the installer (for manual distribution)\n";
echo "   installer.zip       — ready to distribute (install.php + installer-config.php)\n";

// ============================================================
// Build functions
// ============================================================

/**
 * Build a POSIX tar archive from an array of files.
 */
function buildTar(array $files): string
{
    $tar = '';

    foreach ($files as $name => $content) {
        $tar .= buildTarHeader($name, $content);
        $tar .= $content;
        // Pad to 512-byte boundary
        $padding = 512 - (strlen($content) % 512);
        if ($padding < 512) {
            $tar .= str_repeat("\0", $padding);
        }
    }

    // End-of-archive marker: two 512-byte blocks of zeros
    $tar .= str_repeat("\0", 1024);

    return $tar;
}

/**
 * Build a POSIX tar header for a single file.
 */
function buildTarHeader(string $name, string $content): string
{
    $header = str_pad($name, 100, "\0");                    // name (0-99)
    $header .= str_pad('0000644', 8, "\0");                 // mode (100-107)
    $header .= str_pad(decoct(0), 8, "\0");                 // uid (108-115)
    $header .= str_pad(decoct(0), 8, "\0");                 // gid (116-123)
    $header .= str_pad(decoct(strlen($content)), 12, "\0"); // size (124-135)
    $header .= str_pad(decoct(time()), 12, "\0");           // mtime (136-147)
    $header .= '        ';                                   // checksum placeholder (148-155)
    $header .= '0';                                          // typeflag: regular file (156)
    $header .= str_repeat("\0", 100);                        // linkname (157-256)
    $header .= "ustar\0";                                   // magic (257-262)
    $header .= "00";                                         // version (263-264)
    $header .= str_pad('', 32, "\0");                        // uname (265-296)
    $header .= str_pad('', 32, "\0");                        // gname (297-328)
    $header .= str_repeat("\0", 8);                          // devmajor (329-336)
    $header .= str_repeat("\0", 8);                          // devminor (337-344)
    $header .= str_repeat("\0", 155);                        // prefix (345-499)
    $header .= str_repeat("\0", 12);                         // pad to 512 (500-511)

    // Calculate and insert checksum
    $checksum = 0;
    for ($i = 0; $i < 512; $i++) {
        $checksum += ord($header[$i]);
    }
    $checksumStr = str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT) . "\0 ";
    $header = substr_replace($header, $checksumStr, 148, 8);

    return $header;
}

/**
 * Generate the install.php stub with embedded archive.
 */
function generateStub(string $base64Archive): string
{
    return '<?php
// CI4 Installer by EnlivenApp
// Creative Commons License — Original script by EnlivenApp
// https://creativecommons.org/licenses/by/4.0/

if (file_exists(__DIR__ . \'/install.lock\')) {
    die(\'Installation already completed. Delete install.lock to re-run.\');
}

// Load config for support contact info
$supportMessage = \'\';
if (file_exists(__DIR__ . \'/installer-config.php\')) {
    $__cfg = require __DIR__ . \'/installer-config.php\';
    if (!empty($__cfg[\'branding\'][\'support_url\'])) {
        $supportMessage = \'Visit \' . htmlspecialchars($__cfg[\'branding\'][\'support_url\']) . \' for help.\';
    } elseif (!empty($__cfg[\'branding\'][\'support_email\'])) {
        $supportMessage = \'Contact \' . htmlspecialchars($__cfg[\'branding\'][\'support_email\']) . \' for help.\';
    }
    unset($__cfg);
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . \'ci4-installer-\' . md5(__DIR__);

if (!is_dir($tmp)) {
    $archive = base64_decode(INSTALLER_ARCHIVE);
    $archivePath = $tmp . \'.tar.gz\';

    if (!@mkdir($tmp, 0755, true)) {
        die(\'Could not create temporary directory. \' . $supportMessage);
    }

    if (file_put_contents($archivePath, $archive) === false) {
        @rmdir($tmp);
        die(\'Could not write temporary archive. \' . $supportMessage);
    }

    $extracted = false;

    // Method 1: PharData (available in most PHP builds)
    if (!$extracted && extension_loaded(\'phar\')) {
        try {
            $phar = new PharData($archivePath);
            $phar->extractTo($tmp, null, true);
            $extracted = true;
        } catch (Exception $e) {
            // Fall through to next method
        }
    }

    // Method 2: exec(\'tar\')
    if (!$extracted && function_exists(\'exec\')) {
        $disabled = array_map(\'trim\', explode(\',\', ini_get(\'disable_functions\')));
        if (!in_array(\'exec\', $disabled, true)) {
            @exec(
                \'tar -xzf \' . escapeshellarg($archivePath) . \' -C \' . escapeshellarg($tmp) . \' 2>&1\',
                $output,
                $returnCode
            );
            if ($returnCode === 0) {
                $extracted = true;
            }
        }
    }

    // Method 3: Pure PHP tar.gz parser (last resort)
    if (!$extracted) {
        $extracted = ci4InstallerPurePHPExtract($archivePath, $tmp);
    }

    @unlink($archivePath);

    if (!$extracted) {
        // Clean up failed extraction
        @rmdir($tmp);
        die(\'Could not extract installer archive. \' . $supportMessage);
    }
}

// Boot the installer
require $tmp . DIRECTORY_SEPARATOR . \'Autoloader.php\';
\\CI4Installer\\Autoloader::register($tmp);

$installer = new \\CI4Installer\\Installer(__DIR__);
$installer->run();

/**
 * Pure PHP tar.gz extractor — no extensions required beyond ext-zlib (bundled with PHP).
 */
function ci4InstallerPurePHPExtract(string $archivePath, string $destDir): bool
{
    $gz = @file_get_contents($archivePath);
    if ($gz === false) return false;

    // Decompress gzip
    if (function_exists(\'gzdecode\')) {
        $tar = @gzdecode($gz);
    } elseif (function_exists(\'gzinflate\')) {
        // Skip 10-byte gzip header
        $tar = @gzinflate(substr($gz, 10, -8));
    } else {
        return false;
    }

    if ($tar === false) return false;
    unset($gz);

    $pos = 0;
    $len = strlen($tar);

    while ($pos < $len) {
        // Read 512-byte header
        if ($pos + 512 > $len) break;
        $header = substr($tar, $pos, 512);
        $pos += 512;

        // Check for end-of-archive (all zeros)
        if (trim($header, "\\0") === \'\') break;

        // Parse header fields
        $name = rtrim(substr($header, 0, 100), "\\0");
        $size = octdec(rtrim(substr($header, 124, 12), "\\0 "));
        $typeFlag = $header[156];

        if ($name === \'\') break;

        // Handle prefix field for long names
        $prefix = rtrim(substr($header, 345, 155), "\\0");
        if ($prefix !== \'\') {
            $name = $prefix . \'/\' . $name;
        }

        $filePath = $destDir . DIRECTORY_SEPARATOR . str_replace(\'/\', DIRECTORY_SEPARATOR, $name);

        if ($typeFlag === \'5\' || substr($name, -1) === \'/\') {
            // Directory
            if (!is_dir($filePath)) {
                @mkdir($filePath, 0755, true);
            }
        } elseif ($typeFlag === \'0\' || $typeFlag === "\\0") {
            // Regular file
            $dirPath = dirname($filePath);
            if (!is_dir($dirPath)) {
                @mkdir($dirPath, 0755, true);
            }

            $content = $size > 0 ? substr($tar, $pos, $size) : \'\';
            @file_put_contents($filePath, $content);
        }

        // Advance past file content (padded to 512-byte boundary)
        if ($size > 0) {
            $pos += $size;
            $remainder = $size % 512;
            if ($remainder > 0) {
                $pos += (512 - $remainder);
            }
        }
    }

    return true;
}

const INSTALLER_ARCHIVE = \'' . $base64Archive . '\';
';
}

function formatBytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' bytes';
}

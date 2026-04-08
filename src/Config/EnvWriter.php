<?php

namespace CI4Installer\Config;

use CI4Installer\Filesystem\FilesystemInterface;
use CI4Installer\Result;

/**
 * Reads a CI4 `env` template file and writes a populated `.env` file.
 *
 * Strategy:
 *  - Lines that are already active (not commented out) are updated in place.
 *  - Lines that are commented out matching a supplied key are uncommented and
 *    updated in place.
 *  - Keys that have no corresponding line in the template are appended at the
 *    end of the file.
 *
 * Line endings are always "\n" (Unix-style).
 */
class EnvWriter
{
    private FilesystemInterface $fs;

    public function __construct(FilesystemInterface $fs)
    {
        $this->fs = $fs;
    }

    // ---------------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------------

    /**
     * Writes a .env file to $appRoot/.env populated with $values.
     *
     * @param string $appRoot Absolute path to the CI4 application root.
     * @param array  $values  Flat associative array of env key => value pairs.
     *                        Keys use dot notation or the exact env variable name
     *                        (e.g. 'database.default.hostname' or 'CI_ENVIRONMENT').
     */
    public function write(string $appRoot, array $values): Result
    {
        $content = $this->buildContent($appRoot, $values);

        if ($content instanceof Result) {
            return $content;
        }

        $envPath = rtrim($appRoot, '/\\') . '/.env';

        $result = $this->fs->write($envPath, $content);

        if (! $result->success) {
            return $result;
        }

        // Restrict permissions on Linux/macOS — .env files must not be world-readable.
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->fs->chmod($envPath, 0600);
        }

        return Result::ok($envPath);
    }

    /**
     * Returns the rendered .env content as a string without writing it.
     *
     * Useful for displaying the content in a textarea so the user can
     * manually paste it when the web server cannot write to the app directory.
     *
     * @param string $appRoot Absolute path to the CI4 application root.
     * @param array  $values  Flat associative array of env key => value pairs.
     */
    public function generateDisplay(string $appRoot, array $values): string
    {
        $content = $this->buildContent($appRoot, $values);

        if ($content instanceof Result) {
            // Return an empty string on failure; callers should use write() if
            // they need error handling.
            return '';
        }

        return $content;
    }

    // ---------------------------------------------------------------------------
    // Private — template processing
    // ---------------------------------------------------------------------------

    /**
     * Builds the complete .env content string or returns a Result::fail() on error.
     *
     * @return string|Result  String on success, Result::fail() on read error.
     */
    private function buildContent(string $appRoot, array $values): string|Result
    {
        $appRoot     = rtrim($appRoot, '/\\');
        $templatePath = $appRoot . '/env';

        // Read the env template.
        $readResult = $this->fs->read($templatePath);

        if (! $readResult->success) {
            return Result::fail(
                "Could not read env template at '{$templatePath}': " . $readResult->errorMessage
            );
        }

        /** @var string $raw */
        $raw = $readResult->content;

        // Normalise line endings to Unix-style.
        $raw = str_replace("\r\n", "\n", $raw);
        $raw = str_replace("\r", "\n", $raw);

        // Split into individual lines, preserving trailing newline behaviour.
        $lines = explode("\n", $raw);

        // Build the working line array keyed by index.
        $valuesPending = $values; // keys we still need to handle.

        foreach ($lines as $i => $line) {
            $parsed = $this->parseLine($line);

            if ($parsed === null) {
                // Blank line or a comment that doesn't look like a key=value pair.
                continue;
            }

            ['key' => $envKey, 'commented' => $commented] = $parsed;

            // Check whether the caller supplied a value for this key.
            if (array_key_exists($envKey, $valuesPending)) {
                $lines[$i] = $this->formatLine($envKey, $valuesPending[$envKey]);
                unset($valuesPending[$envKey]);
            } elseif ($commented) {
                // Leave commented-out lines that have no supplied value alone.
            }
            // Active lines with no supplied value are left untouched.
        }

        // Append any values that had no corresponding template line.
        if ($valuesPending !== []) {
            // Ensure the file ends with exactly one newline before appending.
            $trimmed = rtrim(implode("\n", $lines));
            $lines   = explode("\n", $trimmed);
            $lines[]  = '';
            $lines[]  = '# Added by CI4 Installer';

            foreach ($valuesPending as $key => $value) {
                $lines[] = $this->formatLine($key, $value);
            }
        }

        // Always end with a single trailing newline.
        $content = implode("\n", $lines);
        $content = rtrim($content) . "\n";

        return $content;
    }

    /**
     * Parses a single .env line and returns metadata, or null for non-key lines.
     *
     * Handles:
     *  - Active lines:    `KEY=value`
     *  - Commented lines: `# KEY=value`  or  `#KEY=value`
     *
     * @return array{key: string, commented: bool}|null
     */
    private function parseLine(string $line): ?array
    {
        $trimmed = ltrim($line);

        // Check for commented key=value: starts with '#' and contains '='
        if (str_starts_with($trimmed, '#')) {
            $afterHash = ltrim(substr($trimmed, 1));

            if (str_contains($afterHash, '=')) {
                $key = substr($afterHash, 0, strpos($afterHash, '='));
                $key = trim($key);

                if ($key !== '' && self::isValidEnvKey($key)) {
                    return ['key' => $key, 'commented' => true];
                }
            }

            return null;
        }

        // Active (uncommented) key=value line.
        if (str_contains($trimmed, '=')) {
            $key = substr($trimmed, 0, strpos($trimmed, '='));
            $key = trim($key);

            if ($key !== '' && self::isValidEnvKey($key)) {
                return ['key' => $key, 'commented' => false];
            }
        }

        return null;
    }

    /**
     * Formats a key=value pair for writing to the .env file.
     *
     * Values that contain spaces, special characters, or are empty strings are
     * double-quoted. Double quotes inside the value are escaped with a backslash.
     */
    private function formatLine(string $key, mixed $value): string
    {
        $str = (string) $value;

        // Quote the value if it contains whitespace, quotes, #, $, or is empty.
        if ($str === '' || preg_match('/[\s"\'#$\\\\]/', $str)) {
            $str = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $str) . '"';
        }

        return "{$key} = {$str}";
    }

    /**
     * Returns true if $key looks like a valid environment variable name
     * (letters, digits, underscores, and CI4-style dot-separated segments).
     */
    private static function isValidEnvKey(string $key): bool
    {
        // CI4 env files use both UPPER_CASE and dot.notation keys.
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key);
    }

    // ---------------------------------------------------------------------------
    // Private — parseTemplate (informational, used for inspection)
    // ---------------------------------------------------------------------------

    /**
     * Parses the env file content into an associative map of key => [value, commented].
     *
     * The returned array has the env variable name as the key and an array with:
     *  - 'value'    (string)  the raw value string (without surrounding quotes)
     *  - 'commented' (bool)   whether the line was commented out in the template
     *
     * @param  string $content Raw env file content.
     * @return array<string, array{value: string, commented: bool}>
     */
    private function parseTemplate(string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines   = explode("\n", $content);
        $parsed  = [];

        foreach ($lines as $line) {
            $meta = $this->parseLine($line);

            if ($meta === null) {
                continue;
            }

            $trimmed   = ltrim($line);
            $isComment = str_starts_with($trimmed, '#');

            // Extract the raw value from the line.
            $source    = $isComment ? ltrim(substr($trimmed, 1)) : $trimmed;
            $eqPos     = strpos($source, '=');
            $rawValue  = $eqPos !== false ? substr($source, $eqPos + 1) : '';

            // Strip surrounding quotes if present.
            $rawValue = $this->unquoteValue(trim($rawValue));

            $parsed[$meta['key']] = [
                'value'     => $rawValue,
                'commented' => $meta['commented'],
            ];
        }

        return $parsed;
    }

    /**
     * Strips surrounding double or single quotes from an env value.
     */
    private function unquoteValue(string $value): string
    {
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $inner = substr($value, 1, -1);

            // Unescape backslash sequences inside double-quoted values.
            if (str_starts_with($value, '"')) {
                $inner = str_replace(['\\"', '\\\\'], ['"', '\\'], $inner);
            }

            return $inner;
        }

        return $value;
    }
}

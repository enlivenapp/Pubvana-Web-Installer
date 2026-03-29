<?php

namespace CI4Installer\Source;

use CI4Installer\Environment\ServerEnvironment;

/**
 * Selects the best available download source based on environment capabilities
 * and the caller-supplied configuration.
 *
 * Priority order:
 *  1. ComposerSource  — if source.composer is set and composer is reachable
 *  2. GitSource       — if source.git is set and git is reachable
 *  3. CurlSource      — if source.zip is set and the curl extension is loaded
 *  4. StreamSource    — if source.zip is set and allow_url_fopen is on
 *  5. ManualSource    — always works (last resort)
 */
class SourceFactory
{
    /**
     * Return the highest-priority source that can execute given the current
     * environment.
     *
     * @param array                 $sourceConfig Keys: 'composer', 'git', 'zip'
     * @param ServerEnvironment|null $env         Optional pre-detected environment;
     *                                            when provided its flags are used
     *                                            instead of re-detecting.
     */
    public static function getBestSource(
        array $sourceConfig,
        ?ServerEnvironment $env = null,
    ): SourceInterface {
        // --- 1. Composer ---
        if (!empty($sourceConfig['composer'])) {
            $source = new ComposerSource($sourceConfig['composer']);

            $composerOk = $env !== null
                ? $env->composerAvailable === 'passed'
                : $source->canExecute();

            if ($composerOk) {
                return $source;
            }
        }

        // --- 2. Git ---
        if (!empty($sourceConfig['git'])) {
            $source = new GitSource($sourceConfig['git']);

            $gitOk = $env !== null
                ? $env->gitAvailable === 'passed'
                : $source->canExecute();

            if ($gitOk) {
                return $source;
            }
        }

        // --- 3. cURL ---
        if (!empty($sourceConfig['zip'])) {
            $source = new CurlSource($sourceConfig['zip']);

            $curlOk = $env !== null
                ? ($env->extensions['curl'] ?? '') === 'passed'
                : $source->canExecute();

            if ($curlOk) {
                return $source;
            }

            // --- 4. Stream (file_get_contents) ---
            $streamSource = new StreamSource($sourceConfig['zip']);

            $streamOk = $env !== null
                ? ($env->outboundHttp === 'passed' && (bool) ini_get('allow_url_fopen'))
                : $streamSource->canExecute();

            if ($streamOk) {
                return $streamSource;
            }
        }

        // --- 5. Manual ---
        $zipUrl = $sourceConfig['zip'] ?? '';
        return new ManualSource($zipUrl);
    }

    /**
     * Return all source instances for display purposes (e.g., capability matrix).
     *
     * Instances are only created for configuration keys that are set; ManualSource
     * is always included as the last entry.
     *
     * @param array $sourceConfig Keys: 'composer', 'git', 'zip'
     * @return SourceInterface[]
     */
    public static function getAllSources(array $sourceConfig): array
    {
        $sources = [];

        if (!empty($sourceConfig['composer'])) {
            $sources[] = new ComposerSource($sourceConfig['composer']);
        }

        if (!empty($sourceConfig['git'])) {
            $sources[] = new GitSource($sourceConfig['git']);
        }

        if (!empty($sourceConfig['zip'])) {
            $sources[] = new CurlSource($sourceConfig['zip']);
            $sources[] = new StreamSource($sourceConfig['zip']);
        }

        $sources[] = new ManualSource($sourceConfig['zip'] ?? '');

        return $sources;
    }
}

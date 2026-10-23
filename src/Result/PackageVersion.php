<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Result;

/**
 * Resolves the version string printed in every report header.
 *
 * Reports are stamped via `bin/wp-check`, which must resolve a real version:
 * without it every report says `v0.0.0` (CheckContext's default) and a reader
 * cannot tell releases apart. Resolution order below keeps that promise.
 *
 * Resolution order:
 *  1. composer.json `version` — set only when a maintainer pins it (Composer
 *     injects it for tagged installs; a hardcoded field would override the tag).
 *  2. Composer's runtime `InstalledVersions` — the authoritative answer for an
 *     installed package (`v0.7.0`), and `dev-main` for a git checkout.
 *  3. `FALLBACK` — only when the package runs without Composer metadata.
 */
final class PackageVersion
{
    public const FALLBACK = '0.0.0';

    /**
     * @param string|null $packageRoot directory holding composer.json; defaults
     *                                  to the installed package root
     */
    public static function detect(?string $packageRoot = null): string
    {
        return self::fromComposerJson($packageRoot)
            ?? self::fromVersionFile($packageRoot)
            ?? self::fromGitDescribe($packageRoot)
            ?? self::fromInstalledVersions()
            ?? self::FALLBACK;
    }

    /**
     * Display form: `v0.7.0` for a release, `dev-main` unchanged. Reporters used
     * to concatenate a literal `v`, which turned a branch checkout into
     * `vdev-main`.
     */
    public static function label(string $version): string
    {
        return preg_match('/^\d/', $version) === 1 ? 'v' . $version : $version;
    }

    /**
     * Version stamped by tools/build-phar.php (single-file builds have no
     * Composer runtime metadata to query).
     */
    private static function fromVersionFile(?string $packageRoot): ?string
    {
        $root = $packageRoot ?? dirname(__DIR__, 2);
        $file = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'VERSION';
        if (!is_file($file)) {
            return null;
        }
        $version = trim((string) file_get_contents($file));

        return $version === '' ? null : $version;
    }

    private static function fromComposerJson(?string $packageRoot): ?string
    {
        $root = $packageRoot ?? dirname(__DIR__, 2);
        $file = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !isset($data['version']) || !is_string($data['version'])) {
            return null;
        }

        $version = trim($data['version']);

        return $version === '' ? null : $version;
    }

    /**
     * `git describe` in a checkout: works even when vendor metadata is stale
     * (installed before the tag existed). Silent null without git or tags.
     */
    private static function fromGitDescribe(?string $packageRoot): ?string
    {
        $root = $packageRoot ?? dirname(__DIR__, 2);
        if (!is_dir(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . '.git')) {
            return null;
        }
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $proc = @proc_open('git describe --tags --always', $descriptors, $pipes, $root);
        if (!is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        $out = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return $out === '' ? null : ltrim($out, 'v');
    }

    private static function fromInstalledVersions(): ?string
    {
        if (!class_exists(\Composer\InstalledVersions::class)) {
            return null;
        }

        try {
            $version = \Composer\InstalledVersions::getPrettyVersion('rampart/wp-quality-checker');
        } catch (\OutOfBoundsException) {
            return null;
        }

        if (!is_string($version) || $version === '') {
            return null;
        }

        return ltrim($version, 'v');
    }
}

#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build the single-file wp-check.phar (no Composer needed to RUN it).
 *
 *   php -d phar.readonly=0 tools/build-phar.php [--output=build/wp-check.phar]
  *     [--version=v0.4.0] [--root=.]
 *
 * Steps: stage src+config+bin+composer.json → `composer install --no-dev`
 * (runtime deps only: php-parser, symfony) → stamp VERSION → pack with the
 * Phar API → optional gzip → chmod +x → .sha256 sidecar.
 *
 * Notes:
 * - Run with `-d phar.readonly=0`: creating archives is disabled by default.
 * - Dev tools (phpunit/phpcs/phpstan/wpcs) stay OUT: checkers invoke the
 *   TARGET project's own binaries, never bundled ones.
 * - VERSION feeds PackageVersion (phar builds carry no Composer metadata).
 */

/** @param list<string> $argv */
function arg(string $name, array $argv): ?string
{
    foreach ($argv as $i => $a) {
        if ($a === '--' . $name && isset($argv[$i + 1])) {
            return $argv[$i + 1];
        }
        if (str_starts_with($a, '--' . $name . '=')) {
            return substr($a, strlen($name) + 3);
        }
    }

    return null;
}

function fail(string $msg): never
{
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

if (!extension_loaded('phar')) {
    fail('ext-phar missing.');
}
if (ini_get('phar.readonly')) {
    fail('Re-run with: php -d phar.readonly=0 tools/build-phar.php ...');
}

$root = rtrim(arg('root', $argv) ?? (string) getcwd(), '/\\');
$output = arg('output', $argv) ?? ($root . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'wp-check.phar');
$version = arg('version', $argv) ?? ('dev-' . gmdate('Ymd'));

$stage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wp-phar-' . uniqid('', true);
foreach (['src', 'config', 'bin'] as $dir) {
    if (!is_dir($root . DIRECTORY_SEPARATOR . $dir)) {
        fail("Missing dir: $dir");
    }
}
@mkdir($stage, 0777, true);
foreach (['src', 'config', 'bin', 'composer.json', 'LICENSE'] as $item) {
    $from = $root . DIRECTORY_SEPARATOR . $item;
    $to = $stage . DIRECTORY_SEPARATOR . $item;
    if (is_dir($from)) {
        @mkdir($to, 0777, true);
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($from) + 1);
            $dest = $to . DIRECTORY_SEPARATOR . $rel;
            if ($f->isDir()) {
                @mkdir($dest, 0777, true);
            } else {
                copy($f->getPathname(), $dest);
            }
        }
    } elseif (is_file($from)) {
        copy($from, $to);
    }
}
file_put_contents($stage . DIRECTORY_SEPARATOR . 'VERSION', $version . "\n");

// Runtime deps only.
$cmd = 'composer install --no-dev --no-interaction --no-progress --no-scripts --working-dir=' . escapeshellarg($stage) . ' 2>&1';
$out = [];
$code = 0;
exec($cmd, $out, $code);
if ($code !== 0) {
    fail("composer install failed:\n" . implode("\n", array_slice($out, -5)));
}

$pharFile = $output;
if (!str_ends_with(strtolower($pharFile), '.phar')) {
    $pharFile .= '.phar';
}
@mkdir(dirname($pharFile), 0777, true);
if (is_file($pharFile)) {
    @unlink($pharFile);
}
$phar = new Phar($pharFile, 0, 'wp-check.phar');
$phar->buildFromDirectory($stage, '/\.(php|json|neon|dist)$/');
// bin/wp-check and VERSION have no extension, so the filter above skips
// them: add explicitly.
$phar->addFile($stage . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'wp-check', 'bin/wp-check');
$phar->addFile($stage . DIRECTORY_SEPARATOR . 'VERSION', 'VERSION');
$stub = "#!/usr/bin/env php\n<?php\nPhar::mapPhar('wp-check.phar');\nrequire 'phar://wp-check.phar/bin/wp-check';\n__HALT_COMPILER();\n";
$phar->setStub($stub);
if (extension_loaded('zlib')) {
    $phar->compressFiles(Phar::GZ);
}
@chmod($pharFile, 0755);
file_put_contents($pharFile . '.sha256', hash_file('sha256', $pharFile) . '  ' . basename($pharFile) . "\n");

// Cleanup staging.
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($stage);

printf("Built %s (%s, version %s)\n", $pharFile, number_format(filesize($pharFile)) . ' bytes', $version);

<?php
/**
 * Satellite bundle: reinstall Composer deps without ingest-only libs and without dev packages.
 *
 * Replaces archived vendor/ + composer.lock with composer install driven by a stripped
 * composer.json (PHP + Seismo autoload only). Requires the `composer` executable on PATH
 * or {@see getenv('COMPOSER_EXE')} to a Composer phar/binary.
 */

declare(strict_types=1);

require_once __DIR__ . '/FileTreeUtil.php';

final class SatelliteVendorBuilder
{
    private const FORBIDDEN_VENDOR_PACKAGES = ['simplepie', 'easyrdf', 'phpunit'];

    public static function rebuild(string $buildRoot): string
    {
        $root = rtrim($buildRoot, '/');
        $composerJson = $root . '/composer.json';
        if (!is_file($composerJson)) {
            throw new RuntimeException('Satellite vendor rebuild: composer.json missing in build tree.');
        }

        try {
            $decoded = json_decode((string)file_get_contents($composerJson), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Satellite vendor rebuild: composer.json is not valid JSON: ' . $e->getMessage());
        }

        unset(
            $decoded['require']['easyrdf/easyrdf'],
            $decoded['require']['simplepie/simplepie'],
            $decoded['require-dev']['phpunit/phpunit']
        );
        unset($decoded['require-dev']);
        unset($decoded['autoload-dev']);

        if (!isset($decoded['require']['php'])) {
            $decoded['require']['php'] = '>=8.1';
        }

        try {
            $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $e) {
            throw new RuntimeException('Satellite vendor rebuild: failed to encode composer.json: ' . $e->getMessage());
        }
        if (file_put_contents($composerJson, $encoded) === false) {
            throw new RuntimeException('Satellite vendor rebuild: cannot write stripped composer.json.');
        }

        $lock = $root . '/composer.lock';
        if (is_file($lock) && !unlink($lock)) {
            throw new RuntimeException('Satellite vendor rebuild: could not delete composer.lock.');
        }

        $vendor = $root . '/vendor';
        if (is_dir($vendor)) {
            FileTreeUtil::removeDir($vendor);
        }

        $binary = self::resolveComposerBinary();
        $cmd = sprintf(
            '%s install --working-dir=%s --no-dev --optimize-autoloader --no-interaction --no-ansi 2>&1',
            escapeshellarg($binary),
            escapeshellarg($root)
        );

        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $logBlock = implode("\n", $out);
        if ($code !== 0) {
            throw new RuntimeException(
                "composer install failed (exit {$code}). Is Composer installed? Output:\n" . $logBlock
            );
        }

        self::assertForbiddenPackagesAbsent($root);

        return $logBlock;
    }

    public static function resolveComposerBinary(): string
    {
        $fromEnv = getenv('COMPOSER_EXE');
        if ($fromEnv !== false && $fromEnv !== '' && self::looksExecutable((string)$fromEnv)) {
            return (string)$fromEnv;
        }

        $out = [];
        $code = 0;
        if (\PHP_OS_FAMILY === 'Windows') {
            exec('where composer 2>NUL', $out, $code);
        } else {
            exec('command -v composer 2>/dev/null', $out, $code);
        }
        if ($code === 0 && isset($out[0]) && trim((string)$out[0]) !== '') {
            $p = trim((string)$out[0]);
            if ($p !== '') {
                return $p;
            }
        }

        throw new RuntimeException(
            'composer binary not found. Install Composer globally or set COMPOSER_EXE to the composer phar/binary path.'
        );
    }

    private static function looksExecutable(string $path): bool
    {
        return is_file($path) && is_readable($path) && (@is_executable($path) || str_ends_with(strtolower($path), '.bat'));
    }

    private static function assertForbiddenPackagesAbsent(string $root): void
    {
        foreach (self::FORBIDDEN_VENDOR_PACKAGES as $dir) {
            $p = $root . '/vendor/' . $dir;
            if (is_dir($p)) {
                throw new RuntimeException(
                    "Satellite vendor sanity check failed: unexpected package directory still present: vendor/{$dir}/"
                );
            }
        }
    }
}

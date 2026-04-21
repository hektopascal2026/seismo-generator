<?php
/**
 * After pruning, assert the tree still has what satellites need and that
 * mothership-only entry points are gone. Uses `post_verify` from the manifest.
 */

declare(strict_types=1);

require_once __DIR__ . '/SatellitePruneManifest.php';

final class SatelliteBundleVerifier
{
    public static function verify(string $buildRoot, ?array $manifest = null): void
    {
        $root = rtrim($buildRoot, '/');
        if ($root === '' || !is_dir($root)) {
            throw new RuntimeException("Cannot verify: invalid build root: {$buildRoot}");
        }
        if ($manifest === null) {
            $manifest = SatellitePruneManifest::load($root);
        }
        $pv = $manifest['post_verify'] ?? null;
        if (!is_array($pv)) {
            throw new RuntimeException('post_verify is missing in manifest');
        }

        foreach ($pv['must_exist'] ?? [] as $rel) {
            $rel = (string)$rel;
            $path = $root . '/' . $rel;
            if (!is_file($path) && !is_dir($path)) {
                throw new RuntimeException("Satellite bundle verify failed: expected path missing: {$rel}");
            }
        }

        foreach ($pv['must_not_exist'] ?? [] as $rel) {
            $rel = (string)$rel;
            $path = $root . '/' . $rel;
            if (is_file($path) || is_dir($path)) {
                throw new RuntimeException("Satellite bundle verify failed: must be pruned but still present: {$rel}");
            }
        }

        foreach ($pv['php_lint'] ?? [] as $rel) {
            $rel  = (string)$rel;
            $path = $root . '/' . $rel;
            if (!is_file($path)) {
                throw new RuntimeException("Satellite bundle verify: php_lint target missing: {$rel}");
            }
            self::assertPhpSyntaxValid($path);
        }
    }

    private static function assertPhpSyntaxValid(string $file): void
    {
        $php = \PHP_BINARY !== '' && is_executable(\PHP_BINARY) ? \PHP_BINARY : 'php';
        $out = [];
        $code = 0;
        $cmd = escapeshellarg($php) . ' -l ' . escapeshellarg($file) . ' 2>&1';
        exec($cmd, $out, $code);
        if ($code !== 0) {
            $msg = implode("\n", $out);
            throw new RuntimeException("PHP syntax check failed for {$file}: {$msg}");
        }
    }
}

<?php
/**
 * After `git archive`, removes mothership-only UI and tooling from the build tree.
 * Satellite runtime uses `routes_satellite.inc.php` only; shared Services/Repositories
 * stay until Seismo splits them upstream.
 *
 * What to remove is defined in the Seismo repo: `build/satellite-prune.json` (not here).
 */

declare(strict_types=1);

require_once __DIR__ . '/FileTreeUtil.php';
require_once __DIR__ . '/SatellitePruneManifest.php';

final class SatelliteBundlePruner
{
    public static function prune(string $buildRoot): array
    {
        $root = rtrim($buildRoot, '/');
        if ($root === '' || !is_dir($root)) {
            throw new RuntimeException("Cannot prune: invalid build root: {$buildRoot}");
        }

        $manifest = SatellitePruneManifest::load($root);

        foreach ($manifest['remove_dirs'] as $rel) {
            $path = $root . '/' . $rel;
            if (is_dir($path)) {
                FileTreeUtil::removeDir($path);
            }
        }

        foreach ($manifest['remove_files'] as $rel) {
            $path = $root . '/' . $rel;
            if (is_file($path)) {
                if (!unlink($path)) {
                    throw new RuntimeException("Cannot delete file: {$path}");
                }
            }
        }

        return $manifest;
    }
}

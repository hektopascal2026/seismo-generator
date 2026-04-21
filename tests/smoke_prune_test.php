#!/usr/bin/env php
<?php
/**
 * Exercises the satellite prune + verify pipeline without `git archive`.
 * Run from the seismo-generator repo:
 *
 *   php tests/smoke_prune_test.php [path/to/seismo_0.5]
 *
 * CI / local: pass the Seismo checkout that contains `build/satellite-prune.json`.
 * Exit 0 on success, 1 on failure.
 */

declare(strict_types=1);

$seismo = $argv[1] ?? getenv('SEISMO_SOURCE') ?: '';
if ($seismo === '') {
    $seismo = dirname(__DIR__, 2) . '/seismo_0.5';
}
$seismo = realpath($seismo) ?: $seismo;
$manifestPath = $seismo . '/satellite-prune.json';
if (!is_file($manifestPath)) {
    fwrite(STDERR, "Seismo source missing manifest: {$manifestPath}\n");
    exit(1);
}

require_once dirname(__DIR__) . '/lib/FileTreeUtil.php';
require_once dirname(__DIR__) . '/lib/SatellitePruneManifest.php';
require_once dirname(__DIR__) . '/lib/SatelliteBundlePruner.php';
require_once dirname(__DIR__) . '/lib/SatelliteBundleVerifier.php';

$root = sys_get_temp_dir() . '/seismo-prune-smoke-' . bin2hex(random_bytes(4));
$fail = function (string $msg) use ($root): void {
    if (is_dir($root)) {
        FileTreeUtil::removeDir($root);
    }
    fwrite(STDERR, $msg . "\n");
    exit(1);
};

if (!@mkdir($root, 0700, true) && !is_dir($root)) {
    $fail("Cannot create temp dir: {$root}");
}

try {
    $raw = file_get_contents($manifestPath);
    if ($raw === false) {
        $fail('Cannot read manifest from Seismo');
    }
    $manifest = json_decode($raw, true);
    if (!is_array($manifest)) {
        $fail('Invalid manifest JSON');
    }
    $pv = $manifest['post_verify'] ?? [];
    if (!is_array($pv)) {
        $fail('post_verify must be an object');
    }

    $toCreate = array_unique(array_merge(
        $manifest['remove_files'] ?? [],
        $pv['must_exist'] ?? [],
        $pv['php_lint'] ?? []
    ));
    $toCreate = array_values(array_filter($toCreate, static fn (string $p): bool => $p !== 'satellite-prune.json'));
    sort($toCreate);

    foreach ($toCreate as $rel) {
        $p = $root . '/' . $rel;
        $d = dirname($p);
        if (!is_dir($d) && !@mkdir($d, 0700, true)) {
            $fail("mkdir failed: {$d}");
        }
        if ($rel === 'index.php' || $rel === 'bootstrap.php' || $rel === 'routes_satellite.inc.php') {
            $content = '<?php declare(strict_types=1);' . "\n";
        } elseif ($rel === 'config.local.php.example') {
            $content = "<?php declare(strict_types=1);\n// example\n";
        } else {
            $content = '';
        }
        if (file_put_contents($p, $content) === false) {
            $fail("write failed: {$p}");
        }
    }
    if (!@mkdir($root . '/tests', 0700)) {
        $fail('mkdir tests');
    }
    if (!@mkdir($root . '/docs', 0700)) {
        $fail('mkdir docs');
    }

    if (file_put_contents($root . '/satellite-prune.json', $raw) === false) {
        $fail('Cannot write temp manifest');
    }

    $m = SatelliteBundlePruner::prune($root);
    SatelliteBundleVerifier::verify($root, $m);
} catch (Throwable $e) {
    if (is_dir($root)) {
        FileTreeUtil::removeDir($root);
    }
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

FileTreeUtil::removeDir($root);
echo "OK: smoke_prune_test passed\n";
exit(0);

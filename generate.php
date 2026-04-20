#!/usr/bin/env php
<?php
/**
 * seismo-generator — build a deployable satellite Seismo folder from a
 * satellite.json exported by the mothership's Settings → Satellites tab.
 *
 * Usage:
 *   php generate.php <satellite.json>                     # wizard fills MySQL creds
 *   php generate.php <satellite.json> --no-wizard         # use defaults / fail if missing
 *   php generate.php <satellite.json> --seismo-source=../seismo_0.5
 *   php generate.php --help
 *
 * Local GUI: see README (php -S 127.0.0.1:8765 -t gui).
 *
 * Output lands in build/seismo-<slug>/ next to this script.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "seismo-generator is a CLI tool.\n");
    exit(1);
}

require_once __DIR__ . '/lib/GenerateService.php';
require_once __DIR__ . '/lib/Wizard.php';

// ── Argument parsing ──────────────────────────────────────────────
$argv = $_SERVER['argv'] ?? [];
array_shift($argv);

$jsonPath = null;
$seismoSource = GenerateService::defaultSeismoSource() ?? '';
$useWizard = true;
$forceClean = false;

foreach ($argv as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        printUsage();
        exit(0);
    }
    if ($arg === '--no-wizard') {
        $useWizard = false;
        continue;
    }
    if ($arg === '--force') {
        $forceClean = true;
        continue;
    }
    if (str_starts_with($arg, '--seismo-source=')) {
        $seismoSource = substr($arg, strlen('--seismo-source='));
        continue;
    }
    if ($arg[0] === '-') {
        fwrite(STDERR, "Unknown flag: {$arg}\n");
        exit(1);
    }
    if ($jsonPath === null) {
        $jsonPath = $arg;
        continue;
    }
    fwrite(STDERR, "Unexpected argument: {$arg}\n");
    exit(1);
}

if ($jsonPath === null) {
    fwrite(STDERR, "Missing satellite.json path.\n\n");
    printUsage();
    exit(1);
}

if (!is_file($jsonPath)) {
    fwrite(STDERR, "satellite.json not found: {$jsonPath}\n");
    exit(1);
}

if ($seismoSource === '' || !is_dir($seismoSource)) {
    fwrite(STDERR, "Could not locate Seismo source. Set SEISMO_SOURCE env var or pass --seismo-source=<path>.\n");
    exit(1);
}

$raw = file_get_contents($jsonPath);
if ($raw === false) {
    fwrite(STDERR, "Could not read satellite.json.\n");
    exit(1);
}

$service = new GenerateService(__DIR__);
$parsed = $service->parseAndValidateJson($raw);
if (isset($parsed['error'])) {
    fwrite(STDERR, $parsed['error'] . "\n");
    exit(1);
}

/** @var array<string, mixed> $satellite */
$satellite = $parsed['satellite'];
$slug = (string)$parsed['slug'];

$seismoSource = realpath($seismoSource) ?: $seismoSource;

echo "seismo-generator\n";
echo "  slug            : {$slug}\n";
echo "  display_name    : {$satellite['display_name']}\n";
echo "  mothership_url  : {$satellite['mothership_url']}\n";
echo "  mothership_db   : {$satellite['mothership_db']}\n";
echo "  magnitu profile : {$satellite['magnitu']['profile_slug']}\n";
echo "  seismo source   : {$seismoSource}\n\n";
echo "Two DBs: mothership = entry data (SELECT only). Local DB = scores + config — create GRANT for both.\n\n";

if ($useWizard) {
    $wizard = new Wizard();
    $inputs = $wizard->collectDeployInputs($satellite);
} else {
    $inputs = Wizard::defaultDeployInputs($satellite);
}

$result = $service->run(
    $satellite,
    $slug,
    $inputs,
    $seismoSource,
    $forceClean,
    $jsonPath,
    null
);

echo $result->log;

if (!$result->ok) {
    fwrite(STDERR, ($result->error ?? 'Build failed.') . "\n");
    exit(1);
}

$profile = $result->profile ?? '';
$apiKey = $result->apiKey ?? '';
$base = $inputs['mothership_url_base'] ?? '';
echo "\n  Magnitu settings (paste into the '{$profile}' profile):\n";
echo "  ─────────────────────────────────────────────────────\n";
echo '    Push target URL : ' . $base . '/seismo-' . $slug . '/' . "\n";
echo "    API key         : {$apiKey}\n";
echo "\n";

exit(0);

function printUsage(): void
{
    echo <<<TEXT
seismo-generator — build a deployable satellite Seismo folder.

Usage:
  php generate.php <satellite.json> [options]

Options:
  --seismo-source=<path>   Path to Seismo checkout (default: ../seismo_0.5 or \$SEISMO_SOURCE).
  --no-wizard              Skip MySQL prompts, use defaults (probably fine only for dry-runs).
  --force                  Wipe build/seismo-<slug>/ if it exists.
  -h, --help               Show this message.

TEXT;
}

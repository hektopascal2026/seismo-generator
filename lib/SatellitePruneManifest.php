<?php
/**
 * Loads `satellite-prune.json` from a Seismo tree root (shipped in git; must not
 * live under `build/` because `git archive` excludes that path — see `Archiver`).
 * The manifest is the source of truth for seismo-generator's satellite bundle
 * pruning; keep it in sync with routes_mothership vs routes_satellite surfaces.
 */

declare(strict_types=1);

final class SatellitePruneManifest
{
    public const EXPECTED_SCHEMA_VERSION = 1;

    public const RELATIVE_PATH = 'satellite-prune.json';

    /**
     * @return array{schema_version: int, remove_dirs: list<string>, remove_files: list<string>, post_verify?: array<string, mixed>}
     */
    public static function load(string $buildRoot): array
    {
        $path = rtrim($buildRoot, '/') . '/' . self::RELATIVE_PATH;
        if (!is_file($path)) {
            throw new RuntimeException(
                'Missing satellite prune manifest. Expected ' . self::RELATIVE_PATH
                . " in the Seismo root (add it to your checkout; upgrade to current seismo_0.5). Path checked: {$path}"
            );
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read manifest: {$path}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid JSON in manifest: {$path}");
        }
        $version = (int)($data['schema_version'] ?? 0);
        if ($version !== self::EXPECTED_SCHEMA_VERSION) {
            throw new RuntimeException(
                sprintf(
                    'Unsupported schema_version %d in %s (seismo-generator expects %d). Upgrade seismo-generator or the Seismo checkout.',
                    $version,
                    self::RELATIVE_PATH,
                    self::EXPECTED_SCHEMA_VERSION
                )
            );
        }
        if (!isset($data['remove_dirs'], $data['remove_files']) || !is_array($data['remove_dirs']) || !is_array($data['remove_files'])) {
            throw new RuntimeException("Manifest {$path} must define remove_dirs and remove_files arrays.");
        }
        $data['remove_dirs']  = array_values(array_map('strval', $data['remove_dirs']));
        $data['remove_files'] = array_values(array_map('strval', $data['remove_files']));

        if (!isset($data['post_verify']) || !is_array($data['post_verify'])) {
            throw new RuntimeException("Manifest {$path} must define post_verify object.");
        }

        return $data;
    }
}

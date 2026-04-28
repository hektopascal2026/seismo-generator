<?php
/**
 * Shared build logic for CLI and local web GUI.
 */

declare(strict_types=1);

require_once __DIR__ . '/FileTreeUtil.php';
require_once __DIR__ . '/TemplateRenderer.php';
require_once __DIR__ . '/Archiver.php';
require_once __DIR__ . '/SatelliteBundleVerifier.php';
require_once __DIR__ . '/SatelliteBundlePruner.php';

final class GenerateResult
{
    public function __construct(
        public bool $ok,
        public string $log,
        public ?string $error,
        public ?string $targetDir,
        public ?string $slug,
        public ?string $pushTargetUrl,
        public ?string $apiKey,
        public ?string $profile,
        public ?string $commit,
    ) {
    }
}

final class GenerateService
{
    public const SCHEMA_VERSION = 1;

    private string $generatorRoot;

    public function __construct(?string $generatorRoot = null)
    {
        $this->generatorRoot = $generatorRoot ?? dirname(__DIR__);
    }

    /**
     * @return array{error?: string, satellite?: array<string, mixed>, slug?: string}
     */
    public function parseAndValidateJson(string $raw): array
    {
        $raw = trim($raw);
        // Strip UTF-8 BOM — common when pasting from some editors / mothership export.
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        if ($raw === '') {
            return [
                'error' => 'Missing satellite.json — paste it or upload a file. '
                    . 'Replace existing build still needs the descriptor each time.',
            ];
        }

        $satellite = json_decode($raw, true);
        if (!is_array($satellite)) {
            $hint = '';
            if (json_last_error() !== JSON_ERROR_NONE) {
                $hint = ': ' . json_last_error_msg();
            }

            return ['error' => 'Invalid JSON' . $hint . '.'];
        }

        $schemaVersion = (int)($satellite['schema_version'] ?? 0);
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            return [
                'error' => sprintf(
                    'Unsupported schema_version %d (generator expects %d). Regenerate the JSON from the mothership.',
                    $schemaVersion,
                    self::SCHEMA_VERSION
                ),
            ];
        }

        foreach (['slug', 'display_name', 'mothership_url', 'mothership_db', 'magnitu'] as $required) {
            if (empty($satellite[$required])) {
                return ['error' => "satellite.json is missing required field: {$required}"];
            }
        }
        if (empty($satellite['magnitu']['api_key']) || empty($satellite['magnitu']['profile_slug'])) {
            return ['error' => 'satellite.json magnitu block must contain api_key + profile_slug.'];
        }

        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string)$satellite['slug']));
        if ($slug === '') {
            return ['error' => "satellite.json 'slug' is empty after normalisation."];
        }

        return ['satellite' => $satellite, 'slug' => $slug];
    }

    /**
     * @param array<string, string> $inputs db_host, db_name, db_user, db_pass, mothership_url_base
     * @param string|null $satelliteJsonSourcePath copy from this path (CLI)
     * @param string|null $satelliteJsonRaw write this content to build (GUI)
     */
    public function run(
        array $satellite,
        string $slug,
        array $inputs,
        string $seismoSource,
        bool $force,
        ?string $satelliteJsonSourcePath = null,
        ?string $satelliteJsonRaw = null,
    ): GenerateResult {
        $log = '';
        $append = static function (string $line) use (&$log): void {
            $log .= $line . "\n";
        };

        try {
            if ($satelliteJsonSourcePath === null && $satelliteJsonRaw === null) {
                throw new RuntimeException('Internal: need satellite JSON path or raw content.');
            }

            $seismoSource = realpath($seismoSource) ?: $seismoSource;
            if (!is_dir($seismoSource)) {
                throw new RuntimeException('Seismo source directory not found.');
            }

            $targetDir = $this->generatorRoot . '/build/seismo-' . $slug;
            if (is_dir($targetDir)) {
                if (!$force) {
                    throw new RuntimeException(
                        "build/seismo-{$slug}/ already exists. Enable “Replace existing build” or delete the folder."
                    );
                }
                $append("  removing existing {$targetDir}");
                FileTreeUtil::removeDir($targetDir);
            }

            $append('[1/4] git archive source → build/seismo-' . $slug . '/ ...');
            $archiver = new Archiver($seismoSource, $targetDir);
            $archiver->archive();
            $commit = $archiver->currentCommit();
            $append('  done (from commit ' . $commit . ')');

            $append('  pruning mothership-only paths (satellite bundle) ...');
            $pruneManifest = SatelliteBundlePruner::prune($targetDir);
            $append('  prune complete.');

            $append('  verifying satellite bundle ...');
            SatelliteBundleVerifier::verify($targetDir, $pruneManifest);
            $append('  verify OK.');

            $append('[2/4] rendering templates ...');
            $renderer = new TemplateRenderer();

            $brandTitle  = (string)($satellite['brand']['title'] ?? $satellite['display_name']);
            $brandAccent = trim((string)($satellite['brand']['accent'] ?? ''));
            if ($brandAccent === '') {
                // Keep in sync with seismo_0.5/bootstrap.php SEISMO_BRAND_ACCENT_DEFAULT
                $brandAccent = '#4a90e2';
            }
            $refreshKey  = (string)($satellite['mothership_remote_refresh_key'] ?? '');
            $apiKey      = (string)$satellite['magnitu']['api_key'];
            $profile     = (string)$satellite['magnitu']['profile_slug'];

            $configVars = [
                'SLUG'                   => $slug,
                'DB_HOST_PHP'            => TemplateRenderer::phpSingleQuoted($inputs['db_host']),
                'DB_NAME_PHP'            => TemplateRenderer::phpSingleQuoted($inputs['db_name']),
                'DB_USER_PHP'            => TemplateRenderer::phpSingleQuoted($inputs['db_user']),
                'DB_PASS_PHP'            => TemplateRenderer::phpSingleQuoted($inputs['db_pass']),
                'MOTHERSHIP_DB_PHP'      => TemplateRenderer::phpSingleQuoted((string)$satellite['mothership_db']),
                'MOTHERSHIP_URL_PHP'     => TemplateRenderer::phpSingleQuoted((string)$satellite['mothership_url']),
                'REMOTE_REFRESH_KEY_PHP' => TemplateRenderer::phpSingleQuoted($refreshKey),
                'BRAND_TITLE_PHP'        => TemplateRenderer::phpSingleQuoted($brandTitle),
                'BRAND_ACCENT_PHP'       => TemplateRenderer::phpSingleQuoted($brandAccent),
            ];
            $tmpl = $this->generatorRoot . '/template';
            $renderer->renderTo($tmpl . '/config.local.php.tmpl', $targetDir . '/config.local.php', $configVars);
            @chmod($targetDir . '/config.local.php', 0640);

            $sqlVars = [
                'SLUG'            => $slug,
                'DISPLAY_NAME'    => $satellite['display_name'],
                'MOTHERSHIP_DB'   => (string)$satellite['mothership_db'],
                'API_KEY'         => self::escapeSqlSingleQuoted($apiKey),
                'MAGNITU_PROFILE' => self::escapeSqlSingleQuoted($profile),
            ];
            $renderer->renderTo($tmpl . '/sql/install.sql.tmpl', $targetDir . '/sql/install.sql', $sqlVars);

            $htVars = ['SLUG' => $slug];
            $renderer->renderTo($tmpl . '/.htaccess.tmpl', $targetDir . '/.htaccess', $htVars);

            $deployVars = [
                'SLUG'                      => $slug,
                'DISPLAY_NAME'              => $satellite['display_name'],
                'GENERATED_AT'              => gmdate('Y-m-d H:i:s \U\T\C'),
                'MOTHERSHIP_URL'            => (string)$satellite['mothership_url'],
                'MOTHERSHIP_URL_BASE'       => $inputs['mothership_url_base'],
                'MOTHERSHIP_DB'             => (string)$satellite['mothership_db'],
                'DB_HOST'                   => $inputs['db_host'],
                'DB_NAME'                   => $inputs['db_name'],
                'DB_USER'                   => $inputs['db_user'],
                'MAGNITU_PROFILE'           => $profile,
                'API_KEY'                   => $apiKey,
                'REMOTE_REFRESH_KEY_MASKED' => self::mask($refreshKey),
            ];
            $renderer->renderTo($tmpl . '/DEPLOY.md.tmpl', $targetDir . '/DEPLOY.md', $deployVars);

            if ($satelliteJsonSourcePath !== null) {
                if (!is_file($satelliteJsonSourcePath)) {
                    throw new RuntimeException('satellite.json source file missing.');
                }
                if (!copy($satelliteJsonSourcePath, $targetDir . '/satellite.json')) {
                    throw new RuntimeException('Could not copy satellite.json into build.');
                }
            } else {
                if (file_put_contents($targetDir . '/satellite.json', $satelliteJsonRaw ?? '') === false) {
                    throw new RuntimeException('Could not write satellite.json into build.');
                }
            }
            @chmod($targetDir . '/satellite.json', 0640);

            $append('  rendered: config.local.php, sql/install.sql, .htaccess, DEPLOY.md');
            $append('[3/4] build complete');
            $append('[4/4] next steps');
            $append(str_repeat('─', 64));
            $append("  Output: {$targetDir}");
            $append("  Upload to: {$inputs['mothership_url_base']}/seismo-{$slug}/");
            $append(str_repeat('─', 64));

            $pushTarget = rtrim($inputs['mothership_url_base'], '/') . '/seismo-' . $slug . '/';

            return new GenerateResult(
                true,
                $log,
                null,
                $targetDir,
                $slug,
                $pushTarget,
                $apiKey,
                $profile,
                $commit
            );
        } catch (Throwable $e) {
            return new GenerateResult(
                false,
                $log,
                $e->getMessage(),
                isset($targetDir) ? $targetDir : null,
                $slug,
                null,
                null,
                null,
                null
            );
        }
    }

    public static function mask(string $secret): string
    {
        if ($secret === '') {
            return '(not set)';
        }
        if (strlen($secret) <= 8) {
            return str_repeat('*', strlen($secret));
        }
        return substr($secret, 0, 4) . str_repeat('*', max(4, strlen($secret) - 8)) . substr($secret, -4);
    }

    private static function escapeSqlSingleQuoted(string $raw): string
    {
        return str_replace("'", "''", $raw);
    }

    /**
     * Default Seismo source path (same search order as CLI).
     */
    public static function defaultSeismoSource(): ?string
    {
        $base = dirname(__DIR__);
        $candidates = [
            getenv('SEISMO_SOURCE') ?: false,
            $base . '/../seismo_0.5',
            $base . '/../seismo/seismo_0.5',
            $base . '/../seismo_0.4',
            $base . '/../seismo/seismo_0.4',
            $base . '/seismo_source',
        ];
        foreach ($candidates as $p) {
            if ($p === false || $p === '') {
                continue;
            }
            $rp = realpath($p);
            if ($rp && is_dir($rp)) {
                return $rp;
            }
        }
        return null;
    }
}

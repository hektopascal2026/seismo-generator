<?php
/**
 * After `git archive`, removes mothership-only UI and tooling from the build tree.
 * Satellite runtime uses `routes_satellite.inc.php` only; shared Services/Repositories
 * stay until Seismo splits them upstream.
 */

declare(strict_types=1);

final class SatelliteBundlePruner
{
    /** Directory names under the build root to remove entirely. */
    private const REMOVE_DIRS = [
        'tests',
        'docs',
    ];

    /** Files under the build root to remove. */
    private const REMOVE_FILES = [
        'routes_mothership.inc.php',
        'refresh_cron.php',
        'phpunit.xml.dist',
        'README-REORG.md',
        'views/about.php',
        'views/diagnostics.php',
        'views/feeds.php',
        'views/lex.php',
        'views/leg.php',
        'views/mail.php',
        'views/scraper.php',
        'views/setup.php',
        'views/styleguide.php',
        'views/partials/plugin_recent_runs.php',
        'views/partials/retention_panel.php',
        'views/partials/settings_tab_satellites.php',
        'src/Controller/AboutController.php',
        'src/Controller/DiagnosticsController.php',
        'src/Controller/ExportController.php',
        'src/Controller/FeedController.php',
        'src/Controller/LegController.php',
        'src/Controller/LexController.php',
        'src/Controller/MailController.php',
        'src/Controller/RetentionController.php',
        'src/Controller/SatelliteController.php',
        'src/Controller/ScraperController.php',
        'src/Controller/SetupController.php',
        'src/Controller/StyleguideController.php',
    ];

    public static function prune(string $buildRoot): void
    {
        $root = rtrim($buildRoot, '/');
        if ($root === '' || !is_dir($root)) {
            throw new RuntimeException("Cannot prune: invalid build root: {$buildRoot}");
        }

        foreach (self::REMOVE_DIRS as $rel) {
            $path = $root . '/' . $rel;
            if (is_dir($path)) {
                GenerateService::removeDir($path);
            }
        }

        foreach (self::REMOVE_FILES as $rel) {
            $path = $root . '/' . $rel;
            if (is_file($path)) {
                if (!unlink($path)) {
                    throw new RuntimeException("Cannot delete file: {$path}");
                }
            }
        }
    }
}

<?php
/**
 * Wizard — interactive readline prompts for the bits of satellite config that
 * the mothership's satellite.json doesn't know about (MySQL creds, local
 * path, overrides).
 *
 * Reads the satellite.json so the defaults it echoes back match what the
 * mothership exported; the user can override any field.
 */

final class Wizard
{
    public function __construct(private $stdin = STDIN, private $stdout = STDOUT) {}

    /**
     * MySQL database/user name suffix from a satellite slug: lowercase, hyphens
     * → underscores, no leading digit (shared hosts often choke on hyphens in names).
     */
    public static function mysqlSafeSuffixFromSlug(string $slug): string
    {
        $s = strtolower(preg_replace('/[^a-z0-9-]/', '', $slug));
        $s = str_replace('-', '_', $s);
        $s = preg_replace('/_+/', '_', $s);
        $s = trim($s, '_');
        if ($s === '') {
            return 'satellite';
        }
        if (ctype_digit($s[0])) {
            return 's_' . $s;
        }

        return $s;
    }

    /** Prompts with a default value; empty input returns default. */
    public function ask(string $label, string $default = '', bool $required = false): string
    {
        while (true) {
            $suffix = $default !== '' ? " [{$default}]" : '';
            fwrite($this->stdout, $label . $suffix . ': ');
            $line = fgets($this->stdin);
            if ($line === false) {
                if ($default !== '') return $default;
                throw new RuntimeException('Aborted.');
            }
            $line = trim($line);
            if ($line === '') $line = $default;
            if ($required && $line === '') {
                fwrite($this->stdout, "  (required)\n");
                continue;
            }
            return $line;
        }
    }

    /** Silent password prompt (POSIX `stty` — best-effort). */
    public function askPassword(string $label): string
    {
        fwrite($this->stdout, $label . ': ');
        if (function_exists('shell_exec') && stripos(PHP_OS, 'WIN') !== 0) {
            shell_exec('stty -echo');
            $line = fgets($this->stdin);
            shell_exec('stty echo');
            fwrite($this->stdout, "\n");
        } else {
            $line = fgets($this->stdin);
        }
        return trim((string)$line);
    }

    /** y/n with a default. */
    public function confirm(string $label, bool $default = false): bool
    {
        $suffix = $default ? ' [Y/n]' : ' [y/N]';
        fwrite($this->stdout, $label . $suffix . ': ');
        $line = trim((string)fgets($this->stdin));
        if ($line === '') return $default;
        return in_array(strtolower($line[0] ?? ''), ['y', 'j'], true);
    }

    /**
     * Collects MySQL / deployment fields from the user, using the satellite
     * JSON as context where helpful.
     *
     * @param array<string, mixed> $satelliteJson
     * @return array<string, string> keys: db_host, db_name, db_user, db_pass, mothership_url_base
     */
    public function collectDeployInputs(array $satelliteJson): array
    {
        $slug = (string)($satelliteJson['slug'] ?? 'satellite');
        $safe = self::mysqlSafeSuffixFromSlug($slug);
        $motherUrl = (string)($satelliteJson['mothership_url'] ?? '');

        fwrite($this->stdout, "\n── Two databases reminder ──\n");
        fwrite($this->stdout, "  MOTHERSHIP DB (from JSON): stores ENTRY rows — satellite only SELECTs.\n");
        fwrite($this->stdout, "  LOCAL DB (next prompts):    stores SCORES + config — you CREATE this DB.\n");
        fwrite($this->stdout, "\n── Satellite's own MySQL database (for scoring tables — NOT the mothership DB) ──\n");
        fwrite($this->stdout, "  (Suggested names use underscores — avoids MySQL/hyphen issues on some hosts.)\n");
        $dbHost = $this->ask('MySQL host', 'localhost', true);
        $dbName = $this->ask('MySQL DB name', 'seismo_' . $safe, true);
        $dbUser = $this->ask('MySQL user', 'seismo_' . $safe, true);
        $dbPass = $this->askPassword('MySQL password');

        fwrite($this->stdout, "\n── Deployment location ──\n");
        fwrite($this->stdout, "  Mothership URL (from JSON): {$motherUrl}\n");
        $urlBase = $this->ask(
            'Mothership URL base for satellite (usually same host, subdir seismo-' . $slug . ')',
            rtrim($motherUrl, '/') === '' ? '' : preg_replace('~/seismo[^/]*/?$~', '', rtrim($motherUrl, '/'))
        );
        if ($urlBase === '') {
            $urlBase = rtrim($motherUrl, '/');
        }
        $urlBase = rtrim($urlBase, '/');

        return [
            'db_host' => $dbHost,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'db_pass' => $dbPass,
            'mothership_url_base' => $urlBase,
        ];
    }

    /**
     * Same defaults as CLI `--no-wizard` (for GUI and automation).
     *
     * @param array<string, mixed> $satelliteJson
     * @return array<string, string>
     */
    public static function defaultDeployInputs(array $satelliteJson): array
    {
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($satelliteJson['slug'] ?? '')));
        $motherUrl = (string)($satelliteJson['mothership_url'] ?? '');
        $urlBase = rtrim(preg_replace('~/seismo[^/]*/?$~', '', rtrim($motherUrl, '/')), '/');
        if ($urlBase === '') {
            $urlBase = rtrim($motherUrl, '/');
        }

        $safe = self::mysqlSafeSuffixFromSlug($slug !== '' ? $slug : 'satellite');

        return [
            'db_host' => 'localhost',
            'db_name' => 'seismo_' . $safe,
            'db_user' => 'seismo_' . $safe,
            'db_pass' => '',
            'mothership_url_base' => $urlBase,
        ];
    }
}

<?php
/**
 * Archiver — runs `git archive` over the Seismo source tree and extracts the
 * result into the target build directory. We use `git archive` rather than a
 * plain rsync so .gitignored files (vendor/ cache, node_modules, build
 * artefacts, .cursor tooling, IDE droppings) are guaranteed to stay out of
 * the satellite upload.
 *
 * Exported tree excludes: .git/, .cursor/, build/, terminals/, mcps/. These
 * are also listed in an `.gitattributes export-ignore` file inside Seismo, but
 * we also enforce them via the ATTRIBUTES_FILE override for belt-and-braces.
 * Note: `satellite-prune.json` for seismo-generator lives at the repository
 * root (not under `build/`) so it is included in the archive.
 */

final class Archiver
{
    /** Default export-ignore patterns applied via a temporary .gitattributes. */
    private const DEFAULT_EXPORT_IGNORES = [
        '.cursor export-ignore',
        '.cursor/** export-ignore',
        '.github export-ignore',
        '.vscode export-ignore',
        'build export-ignore',
        'build/** export-ignore',
        'terminals export-ignore',
        'terminals/** export-ignore',
        'mcps export-ignore',
        'mcps/** export-ignore',
        'config.local.php export-ignore',
        'DEPLOY.md export-ignore',
    ];

    public function __construct(private string $sourceDir, private string $targetDir) {
        if (!is_dir($this->sourceDir) || !is_dir($this->sourceDir . '/.git')) {
            throw new RuntimeException("Source is not a git repository: {$this->sourceDir}");
        }
    }

    /**
     * Exports HEAD from the source repo into targetDir.
     *
     * @param string $ref Git ref to archive (default HEAD)
     */
    public function archive(string $ref = 'HEAD'): void
    {
        if (!is_dir($this->targetDir) && !mkdir($this->targetDir, 0755, true) && !is_dir($this->targetDir)) {
            throw new RuntimeException("Cannot create target dir: {$this->targetDir}");
        }

        // Warn if target dir isn't empty — protect against accidental overlays.
        $existing = array_diff(scandir($this->targetDir) ?: [], ['.', '..']);
        if (!empty($existing)) {
            throw new RuntimeException(
                "Target directory is not empty: {$this->targetDir}\n"
              . "Delete it first or pick a fresh build/<slug>/ path."
            );
        }

        // Write a transient .gitattributes with export-ignore rules so we don't
        // have to modify the source repo.
        $attrsFile = tempnam(sys_get_temp_dir(), 'seismogen_attrs_');
        if ($attrsFile === false) {
            throw new RuntimeException('Cannot create temp attributes file');
        }
        file_put_contents($attrsFile, implode("\n", self::DEFAULT_EXPORT_IGNORES) . "\n");

        $tarFile = tempnam(sys_get_temp_dir(), 'seismogen_tar_');
        if ($tarFile === false) {
            unlink($attrsFile);
            throw new RuntimeException('Cannot create temp tarball file');
        }

        try {
            $cmd = sprintf(
                'git -C %s -c core.attributesFile=%s archive --format=tar %s > %s',
                escapeshellarg($this->sourceDir),
                escapeshellarg($attrsFile),
                escapeshellarg($ref),
                escapeshellarg($tarFile)
            );
            $this->run($cmd, 'git archive');

            $extractCmd = sprintf('tar -xf %s -C %s', escapeshellarg($tarFile), escapeshellarg($this->targetDir));
            $this->run($extractCmd, 'tar extract');
        } finally {
            @unlink($attrsFile);
            @unlink($tarFile);
        }
    }

    /** Returns the currently-checked-out commit SHA of the source repo, short. */
    public function currentCommit(): string
    {
        $out = [];
        $rc = 0;
        exec('git -C ' . escapeshellarg($this->sourceDir) . ' rev-parse --short HEAD 2>/dev/null', $out, $rc);
        return $rc === 0 && !empty($out) ? trim($out[0]) : 'unknown';
    }

    /** Executes a shell command; raises on non-zero. */
    private function run(string $cmd, string $label): void
    {
        $rc = 0;
        $output = [];
        exec($cmd . ' 2>&1', $output, $rc);
        if ($rc !== 0) {
            throw new RuntimeException(
                "{$label} failed (exit {$rc}):\n  " . $cmd . "\n\n" . implode("\n", $output)
            );
        }
    }
}

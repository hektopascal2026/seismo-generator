<?php
/**
 * Local web UI for seismo-generator — bind to 127.0.0.1 only.
 *
 *   php -S 127.0.0.1:8765 -t /path/to/seismo-generator/gui
 *
 * Then open http://127.0.0.1:8765/
 */

declare(strict_types=1);

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '127.0.0.1' && $ip !== '::1') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden: this UI is for localhost only. Start with: php -S 127.0.0.1:8765 -t gui';
    exit;
}

require_once dirname(__DIR__) . '/lib/GenerateService.php';
require_once dirname(__DIR__) . '/lib/Wizard.php';

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$generatorRoot = dirname(__DIR__);
$service = new GenerateService($generatorRoot);
$defaultSource = GenerateService::defaultSeismoSource() ?? '';

$result = null;
$error = null;
$form = [
    'seismo_source' => $defaultSource,
    'db_host' => 'localhost',
    'db_name' => '',
    'db_user' => '',
    'db_pass' => '',
    'mothership_url_base' => '',
    'satellite_json' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['seismo_source'] = trim((string)($_POST['seismo_source'] ?? ''));
    $form['db_host'] = trim((string)($_POST['db_host'] ?? ''));
    $form['db_name'] = trim((string)($_POST['db_name'] ?? ''));
    $form['db_user'] = trim((string)($_POST['db_user'] ?? ''));
    $form['db_pass'] = (string)($_POST['db_pass'] ?? '');
    $form['mothership_url_base'] = trim((string)($_POST['mothership_url_base'] ?? ''));
    $form['satellite_json'] = (string)($_POST['satellite_json'] ?? '');

    $raw = $form['satellite_json'];
    if (isset($_FILES['satellite_file']) && is_array($_FILES['satellite_file'])
        && ($_FILES['satellite_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $tmp = (string)($_FILES['satellite_file']['tmp_name'] ?? '');
        if ($tmp !== '' && is_readable($tmp)) {
            $fileRaw = file_get_contents($tmp);
            if ($fileRaw !== false) {
                $raw = $fileRaw;
            }
        }
    }

    $parsed = $service->parseAndValidateJson($raw);
    if (isset($parsed['error'])) {
        $error = $parsed['error'];
    } else {
        /** @var array<string, mixed> $satellite */
        $satellite = $parsed['satellite'];
        $slug = (string)$parsed['slug'];
        $defaults = Wizard::defaultDeployInputs($satellite);
        if ($form['mothership_url_base'] === '') {
            $form['mothership_url_base'] = $defaults['mothership_url_base'];
        }

        $inputs = [
            'db_host' => $form['db_host'] !== '' ? $form['db_host'] : $defaults['db_host'],
            'db_name' => $form['db_name'] !== '' ? $form['db_name'] : $defaults['db_name'],
            'db_user' => $form['db_user'] !== '' ? $form['db_user'] : $defaults['db_user'],
            'db_pass' => $form['db_pass'],
            'mothership_url_base' => $form['mothership_url_base'] !== ''
                ? rtrim($form['mothership_url_base'], '/')
                : $defaults['mothership_url_base'],
        ];

        $src = $form['seismo_source'] !== '' ? $form['seismo_source'] : $defaultSource;
        if ($src === '' || !is_dir($src)) {
            $error = 'Seismo source path is missing or not a directory.';
        } else {
            $result = $service->run(
                $satellite,
                $slug,
                $inputs,
                $src,
                true,
                null,
                $raw
            );
            if (!$result->ok) {
                $error = $result->error ?? 'Build failed.';
            }
        }
    }
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>seismo-generator</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="wrap">
    <h1>seismo-generator</h1>
    <p class="sub">Build a satellite Seismo folder from <code>satellite.json</code> (local only).</p>

    <?php if ($error !== null): ?>
      <div class="alert err"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($result !== null && !$result->ok && $result->log !== ''): ?>
      <div class="panel panel--card">
        <h2>Log (partial)</h2>
        <pre class="log"><?= e($result->log) ?></pre>
      </div>
    <?php endif; ?>

    <?php if ($result !== null && $result->ok): ?>
      <div class="alert ok">Build succeeded.</div>
      <div class="panel panel--card">
        <h2>Log</h2>
        <pre class="log"><?= e($result->log) ?></pre>
        <?php if ($result->pushTargetUrl !== null && $result->apiKey !== null && $result->profile !== null): ?>
          <div class="magnitu-box">
            <h2 class="magnitu-title">Magnitu profile “<?= e($result->profile) ?>”</h2>
            <dl>
              <dt>Push target URL</dt>
              <dd><?= e($result->pushTargetUrl) ?></dd>
              <dt>API key</dt>
              <dd><?= e($result->apiKey) ?></dd>
              <?php if ($result->targetDir !== null): ?>
                <dt>Output folder</dt>
                <dd><?= e($result->targetDir) ?></dd>
              <?php endif; ?>
              <?php if ($result->commit !== null): ?>
                <dt>Seismo source commit</dt>
                <dd><?= e($result->commit) ?></dd>
              <?php endif; ?>
            </dl>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="panel">
      <h2>satellite.json</h2>
      <div class="field">
        <label for="satellite_file">Upload file</label>
        <input type="file" id="satellite_file" name="satellite_file" accept=".json,application/json">
      </div>
      <div class="field">
        <label for="satellite_json">Or paste JSON</label>
        <textarea id="satellite_json" name="satellite_json" placeholder='{ "schema_version": 1, "slug": "…" }'><?= e($form['satellite_json']) ?></textarea>
      </div>

      <h2>Paths</h2>
      <div class="field">
        <label for="seismo_source">Seismo source (git checkout, e.g. seismo_0.5)</label>
        <input type="text" id="seismo_source" name="seismo_source" value="<?= e($form['seismo_source']) ?>" autocomplete="off">
      </div>

      <div class="callout" role="note">
        <strong>Two MySQL databases</strong> — a satellite does <em>not</em> use only the main Seismo DB.
        <ul>
          <li><strong>Mothership DB</strong> (from JSON, <code>SEISMO_MOTHERSHIP_DB</code> in the build): <strong>entries</strong> — feeds, Lex, Leg, … — satellite <strong>reads</strong> only.</li>
          <li><strong>Local DB</strong> (fields below — <code>DB_NAME</code> in <code>config.local.php</code>): <strong>scores + keys</strong> — <code>entry_scores</code>, <code>system_config</code>, … — you <strong>create</strong> this database and import <code>sql/install.sql</code>.</li>
        </ul>
        Deploy: <code>GRANT SELECT</code> on mothership + <code>GRANT ALL</code> on local DB for the same MySQL user.
      </div>

      <h2>Satellite MySQL &amp; URL</h2>
      <p class="form-hint">These credentials are for the <strong>local</strong> database (scores), not the mothership. Leave blank to use defaults from the slug (same as CLI <code>--no-wizard</code>).</p>
      <div class="row two">
        <div class="field">
          <label for="db_host">MySQL host</label>
          <input type="text" id="db_host" name="db_host" value="<?= e($form['db_host']) ?>">
        </div>
        <div class="field">
          <label for="db_name">MySQL database</label>
          <input type="text" id="db_name" name="db_name" value="<?= e($form['db_name']) ?>" placeholder="seismo-&lt;slug&gt;">
        </div>
      </div>
      <div class="row two">
        <div class="field">
          <label for="db_user">MySQL user</label>
          <input type="text" id="db_user" name="db_user" value="<?= e($form['db_user']) ?>">
        </div>
        <div class="field">
          <label for="db_pass">MySQL password</label>
          <input type="password" id="db_pass" name="db_pass" value="<?= e($form['db_pass']) ?>" autocomplete="new-password">
        </div>
      </div>
      <div class="field">
        <label for="mothership_url_base">Mothership URL base (host for uploads, no trailing path)</label>
        <input type="text" id="mothership_url_base" name="mothership_url_base" value="<?= e($form['mothership_url_base']) ?>" placeholder="https://example.com" autocomplete="off">
      </div>

      <p style="margin-top:1.25rem">
        <button type="submit" class="primary">Generate</button>
      </p>
    </form>

    <footer class="note">
      CLI: <code>php <?= e($generatorRoot) ?>/generate.php &lt;satellite.json&gt;</code><br>
      Server must listen on <strong>127.0.0.1</strong> only — remote clients get 403.
    </footer>
  </div>
  <script>
  (function() {
    // Same mothership_base logic as Wizard::defaultDeployInputs() — fills from pasted satellite.json only (no baked-in URLs).
    function mothershipUrlBaseFromJson(obj) {
      var u = String(obj && obj.mothership_url != null ? obj.mothership_url : '').trim();
      if (!u) return '';
      var s = u.replace(/\/*$/, '');
      var stripped = s.replace(/\/seismo[^/]*\/?$/i, '').replace(/\/*$/, '');
      return stripped !== '' ? stripped : s;
    }

    function tryPrefill(ev) {
      var baseEl = document.getElementById('mothership_url_base');
      if (!baseEl || baseEl.value.trim() !== '') return;

      function applyRaw(raw) {
        raw = raw.replace(/^\uFEFF/, '').trim();
        if (!raw) return;
        try {
          var j = JSON.parse(raw);
          var b = mothershipUrlBaseFromJson(j);
          if (b) baseEl.value = b;
        } catch (e) { /* wait for valid paste */ }
      }

      var ta = document.getElementById('satellite_json');
      if ((ev && ev.target === ta) || (!ev && ta && ta.value.trim())) {
        applyRaw(ta.value);
      }
    }

    document.getElementById('satellite_json').addEventListener('input', tryPrefill);
    document.getElementById('satellite_file').addEventListener('change', function() {
      var baseEl = document.getElementById('mothership_url_base');
      if (!baseEl || baseEl.value.trim() !== '' || !this.files || !this.files[0]) return;
      var f = this.files[0];
      var r = new FileReader();
      r.onload = function() {
        try {
          var raw = String(r.result || '').replace(/^\uFEFF/, '').trim();
          var j = JSON.parse(raw);
          var b = mothershipUrlBaseFromJson(j);
          if (b) baseEl.value = b;
        } catch (e) {}
      };
      r.readAsText(f);
    });
    tryPrefill();
  })();
  </script>
</body>
</html>

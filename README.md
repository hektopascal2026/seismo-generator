# seismo-generator

A small PHP tool (CLI **or** a **local web UI**) that builds a deployable **satellite Seismo** folder from a `satellite.json` exported by the mothership Seismo's **Settings → Satellites** tab. The output starts from a **`git archive` of your Seismo checkout**, then **strips mothership-only UI and tooling** so the upload contains only what a satellite needs, with `SEISMO_SATELLITE_MODE=true` pre-wired to the right mothership DB and Magnitu profile.

## Two MySQL databases — not one

A satellite **never** runs with “only the main Seismo DB”. On the server you use **two** databases (usually on the **same** MySQL host):

| Database | Name comes from | What lives there |
|----------|-----------------|------------------|
| **Mothership** | `mothership_db` in `satellite.json` → `SEISMO_MOTHERSHIP_DB` | **Entries**: `feed_items`, `emails`, `lex_items`, `calendar_events`, `feeds`, … The satellite **only reads** these (`SELECT` via cross-database queries). |
| **Satellite (local)** | You create it; credentials go in `config.local.php` as `DB_NAME` / `DB_USER` / `DB_PASS` | **This instance only**: `entry_scores`, `system_config` (Magnitu API key, recipe prefs), `magnitu_labels`, `entry_favourites`, … Magnitu **writes scores here**; PHP’s default connection is this DB. |

So: **timeline rows** are read from the mothership DB; **scores and keys** live in the satellite’s **own** DB. That is why deploy instructions have you **`GRANT SELECT`** on the mothership and **`GRANT ALL`** (or equivalent) on the satellite DB — same MySQL user, two grants on two databases.

## Why this exists

Seismo is built around one central instance that scrapes and stores entries. A **satellite** is a lightweight Seismo installation that:

- reads entries via cross-DB `SELECT` from the mothership's MySQL database
- keeps its own scoring tables (`entry_scores`, `system_config`, `magnitu_labels`, `entry_favourites`) — config keys such as the Magnitu Bearer token live in **`system_config`** (0.5 renamed this from `magnitu_config`)
- has its own Magnitu API key so Magnitu's topic-specific profile (e.g. "digital", "kmu") pushes scores here
- shows only Magnitu-flagged entries (`investigation_lead` + `important`)
- has no scrapers, no admin UI, no cron

Satellites are generated from a JSON descriptor. Rebuild + redeploy instead of hand-editing, so the mothership always owns the registry.

The upload is a **slim Seismo 0.5 tree**: `git archive` first, then **generator pruning** removes mothership routes/controllers/views (feeds, Lex/Leg, diagnostics, setup, styleguide, …), plus `tests/`, `docs/`, `refresh_cron.php`, and related files. **Satellite mode** (`SEISMO_SATELLITE_MODE` in `config.local.php`) tells the app to expose only **Timeline**, **Filter**, **Highlights**, and **Settings** (General + Magnitu): no feed/Lex/Leg/diagnostics/source admin — entries are read from the mothership database; Magnitu pushes scores to the satellite’s local DB.

## Prerequisites

- PHP 8.0+ with the `ctype`, `pcre`, `json` extensions (standard on any PHP-capable host).
- `git`, `tar` in `$PATH` (used by `git archive` to export the Seismo source).
- A local checkout of Seismo (the mothership codebase). By default the generator expects it next to this repo — e.g. both live under `~/Documents/GitHub/seismo/`:

  ```
  ~/Documents/GitHub/seismo/seismo_0.5/          ← your Seismo 0.5 source (default)
  ~/Documents/GitHub/seismo/seismo-generator/  ← this repo
  ```

  Override with `--seismo-source=<path>` or the `SEISMO_SOURCE` env var. The CLI also tries `../seismo_0.4` as a last resort for legacy trees.

## Workflow

### 1. Register the satellite on the mothership

In the mothership Seismo, go to **Settings → Satellites**. Fill in slug (e.g. `digital`), display name, Magnitu profile slug, optional brand accent. Save.

Click **Download JSON** next to the new row. You get `satellite-digital.json`:

```json
{
  "schema_version": 1,
  "slug": "digital",
  "display_name": "Seismo Digital",
  "mothership_url": "https://example.com/seismo",
  "mothership_db": "seismo_main",
  "mothership_remote_refresh_key": "…",
  "magnitu": {
    "api_key": "…",
    "profile_slug": "digital"
  },
  "brand": { "accent": "#4a90e2", "title": "Seismo Digital" },
  "filters": { "labels": ["investigation_lead", "important"] },
  "exported_at": "2026-04-19T12:00:00Z"
}
```

### 2. Build the satellite

```bash
php generate.php ~/Downloads/satellite-digital.json
```

The wizard will prompt for the satellite's **own** MySQL creds (host, DB, user, password) and confirm the deployment URL base. Output lands in `build/seismo-digital/`.

Skip the wizard with `--no-wizard` if you already have the details and are using defaults (`seismo_<slug>` for DB name / user, empty password).

Rebuild over a previous attempt with `--force`.

### Local web UI (optional)

From this repo, bind **only to localhost** (the UI refuses other clients):

```bash
cd /path/to/seismo-generator
./start-gui.sh
```

Or manually:

```bash
php -S 127.0.0.1:8765 -t gui
```

**macOS:** double-click **`start-gui.command`** in Finder (opens Terminal, starts the server, opens the browser). **Linux / terminal:** `./start-gui.sh`.

Open [http://127.0.0.1:8765/](http://127.0.0.1:8765/), upload or paste `satellite.json`, set the Seismo source path and MySQL fields (or leave DB/URL fields empty to use the same defaults as `php generate.php … --no-wizard`), then **Generate**. Output is still under `build/seismo-<slug>/`.

### 3. Deploy

Follow `build/seismo-<slug>/DEPLOY.md`. The short version:

1. Upload the folder to your webspace (e.g. `example.com/seismo-digital/`).
2. Create the satellite's own MySQL DB.
3. Grant `SELECT` on the mothership DB to the satellite's DB user.
4. Import `sql/install.sql` into the satellite DB.
5. In Magnitu, point the `<profile>` profile's push target at the new URL with the API key from the JSON.

### 4. Refresh flow (what happens at runtime)

- Timeline entries: satellite queries mothership DB directly via `entryTable()` wrappers.
- Scores: Magnitu pushes to `/satellite/?action=magnitu_scores`, they land in the satellite's local `entry_scores`.
- Refresh button: calls `mothership_url/?action=refresh_all_remote&key=<refresh_key>` via `fetch()`, then reloads; the mothership runs the full refresh pipeline and the satellite picks up the new entries on reload.

## Layout

```
seismo-generator/
├── start-gui.sh              # Start local GUI (run in terminal: ./start-gui.sh)
├── start-gui.command         # macOS: double-click in Finder → Terminal + GUI
├── generate.php              # CLI entry
├── gui/
│   ├── index.php             # local web UI (127.0.0.1 only)
│   └── style.css
├── lib/
│   ├── GenerateService.php   # shared build logic (CLI + GUI)
│   ├── Archiver.php          # git archive of seismo_source into build/<slug>/
│   ├── SatelliteBundlePruner.php  # remove mothership-only files after archive
│   ├── TemplateRenderer.php  # {{PLACEHOLDER}} substitution
│   └── Wizard.php            # readline prompts for MySQL creds
├── template/
│   ├── config.local.php.tmpl
│   ├── sql/install.sql.tmpl
│   ├── .htaccess.tmpl
│   └── DEPLOY.md.tmpl
├── build/                    # gitignored output — one folder per satellite
└── README.md
```

## Flag reference

```
php generate.php <satellite.json> [options]

  --seismo-source=<path>   Path to Seismo checkout (default: ../seismo_0.5 or $SEISMO_SOURCE)
  --no-wizard              Skip MySQL prompts, use defaults
  --force                  Wipe build/seismo-<slug>/ if it exists
  -h, --help               Show help
```

## Contract with Seismo

The generator exports the Seismo source at `HEAD` with `git archive`, then runs **`SatelliteBundlePruner`** to delete mothership-only routes, controllers, views, `tests/`, `docs/`, `routes_mothership.inc.php`, `refresh_cron.php`, and similar (see `lib/SatelliteBundlePruner.php`). Shared `src/` services used by both modes stay in place. It **adds** these generated files:

| File | Purpose |
|------|---------|
| `config.local.php` | **`DB_*`** = satellite’s **local** DB (scores). **`SEISMO_MOTHERSHIP_DB`** = mothership **entries** DB (read-only). Satellite mode + branding — **never commit this** |
| `sql/install.sql`  | Scoring tables + seeded `system_config` (Magnitu API key, profile slug metadata) |
| `.htaccess`        | Auth header pass-through + deny rules for config/SQL artefacts |
| `DEPLOY.md`        | Step-by-step deploy checklist for this satellite |
| `satellite.json`   | Copy of the descriptor used for this build |

The archive uses `git archive` (not rsync), so anything that is `.gitignore`d stays out of the upload. Tooling folders (`.cursor/`, `.github/`, `build/`, terminal dumps, MCP descriptors) are pruned via an overlayed `core.attributesFile` without modifying the Seismo source.

## Caveats / known limitations

- **Local GUI** — `gui/index.php` returns **403** unless `REMOTE_ADDR` is `127.0.0.1` or `::1`. Do not expose it on `0.0.0.0` or a public host; it runs shell-level `git`/`tar` and writes into `build/`.
- **Schema version** (`satellite.json` `schema_version`) is locked at `1`. If you upgrade Seismo's satellite export format incompatibly, bump both the mothership export and `GenerateService::SCHEMA_VERSION` in `lib/GenerateService.php`.
- `mothership_remote_refresh_key` appears in plain text inside the generated `config.local.php`. It's also exposed to the satellite's public page so the Refresh button can call the mothership. That's intentional — satellites are public and the key acts as a cheap rate-limit rather than secrecy. If you need to restrict, put the satellite behind HTTP basic auth.
- **Footprint** — mothership-only PHP UI and dev artefacts are removed after archive; `vendor/` and shared `src/` stay so Composer autoload and satellite routes keep working without a second manifest inside Seismo. Edit `SatelliteBundlePruner::REMOVE_*` when upstream adds routes.

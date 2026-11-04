# Lando Studio — AI assistant guide

This is the single source of truth for coding agents working in **lando-studio** (Lando Studio). `.cursorrules` and `.github/copilot-instructions.md` are symlinks to this file. Edit only this file.

## What this repository is

A **desktop-first** developer tool: an **Electron** shell (via **NativePHP**) running a **Laravel** web app that controls **Lando** to run **WordPress** locally. Users create sites, clone from remotes, change PHP/DB/Redis versions (YAML regen + rebuild), start/stop/destroy apps, and watch streamed CLI output in the UI.

Host PHP (the NativePHP/Electron runtime) is separate from the PHP inside Lando containers. Container version lists live in `config/lando_dev/defaults.php` and the Settings page, not in host config.

## Stack (authoritative)

- **Backend**: PHP 8.2+ (`composer.json`), Laravel 12, PHPUnit 11, Pint
- **Frontend**: Livewire 3, Flux UI (`livewire/flux` ^2.13), Tailwind CSS v4, Vite 7, Blade
- **Desktop**: `nativephp/electron` ^1.3. Host PHP is resolved by `scripts/native-php.sh` (8.3 or 8.4 only)
- **External**: Lando CLI, Docker, WordPress inside Lando containers, SSH/`sshpass` + `mysqldump` for remote clones
- **Node**: see `.nvmrc` (22)

## Commands

| Goal | Command |
|------|---------|
| Install | `composer install && npm install` |
| First-time setup | `composer run setup` (copies `.env`, key, migrate, `npm install`, `npm run build`) |
| Desktop dev (macOS) | `composer run native:dev` (runs `scripts/native-dev.sh`) |
| Web dev | `composer run dev` (serve + queue + pail + Vite) |
| Frontend | `npm run dev` / `npm run build` |
| Tests | `composer run test` or `php artisan test` (`--testsuite=Unit` or `Feature` to narrow) |
| Format PHP | `./vendor/bin/pint` (check only: `./vendor/bin/pint --test`) |

**Use npm for all JavaScript in this project.** NativePHP requires npm, so do not use `bun` even though some local tool settings allow it.

## Architecture

| Path | Role |
|------|------|
| `app/Livewire/` | Full-page components. Each uses `#[Layout('components.layouts.app')]` and `#[Title(...)]` |
| `app/Livewire/Concerns/` | Traits: `WithCommandExecution` (multi-step Lando runs and polling), `WithNotifications` (`notifySuccess` / `notifyError` and similar) |
| `app/Services/` | Domain logic: Lando CLI (`LandoService`), YAML (`LandoYamlGenerator`, `LandoYamlParser`), site lifecycle (`SiteManager`), platform and shell (`PlatformDetector`), SSH (`SshService`), dependency checks, remote verification |
| `app/Support/` | Stateless helpers (`AnsiToHtml`, `CommandLogErrorDetector`, table-prefix detectors, UTF-8 log cleanup) |
| `app/Models/` | Eloquent: `Site`, `RemoteSite`, `CommandLog`, `UserPreference` |
| `app/Enums/` | Backed enums (`SiteStatus`) cast on models |
| `config/lando_dev/` | Version lists and defaults (`defaults.php`) and menu config |
| `resources/views/livewire/` | Page Blade views, one per component |
| `resources/views/flux/brand.blade.php` | Overridden Flux brand (sidebar logo) |
| `routes/web.php` | All web routes |
| `scripts/` | Native dev/Electron/PHP entrypoints |
| `tests/Unit`, `tests/Feature` | PHPUnit suites |
| `storage/logs/` | Per-action Lando logs (`lando_destroy_*`, `lando_action_*`, `install_*`, step logs) |

### Routes (see `routes/web.php`)

| Route | Component | Notes |
|-------|-----------|--------|
| `/setup` | `DependencyCheck` | First-run checks |
| `/` | closure | Redirects to `setup` if deps are missing, else to the latest site or `/create` |
| `/create` | `CreateSite` | Hub |
| `/create/new` | `NewSite` | New-site wizard |
| `/create/clone` | `CloneSite` | Clone from a remote |
| `/sites/{site}` | `SiteDashboard` | Per-site controls; `Site` route-model binding |
| `/settings` | `Settings` | Defaults saved to `storage/lando_studio_defaults.json` |
| `/settings/import` | `ImportSites` | Batch import |
| `/about` | `About` | Version info |

## Important behaviors

1. **Relative URLs in Electron.** The app often runs at `http://127.0.0.1:8100`, and `APP_URL` (`http://localhost` in `.env.example`) can point elsewhere. Build internal links and redirects with `route('name', [], false)`, as `SiteDashboard` does, so `wire:navigate` does not jump hosts.

2. **Site destroy.** `SiteDashboard` runs `lando destroy` and the path delete in the background, then polls the log. Only after that finishes does it remove the `Site` row. The redirect uses a full, non-SPA navigation so in-flight Livewire polls do not 404 on the deleted `{site}` route. Do not delete the `Site` earlier.

3. **Long-running commands.** Lando work runs through NativePHP `ChildProcess`, writes to a log file under `storage/logs/`, and the UI polls it (`wire:poll` / `pollActionStatus` / `WithCommandExecution::checkCommandStatus`). A step is treated as finished after roughly **30 s with no log writes**. Any step that writes its output somewhere other than the step log must emit heartbeat lines, or the UI will advance too early. See the dump heartbeat in `SshService`.

4. **ANSI in logs.** Blade renders terminal blocks through `App\Support\AnsiToHtml::lineToHtml()`, which escapes HTML. Do not echo raw log lines into Blade.

5. **Vite port 5175.** Electron's dev bundler uses 5173. Do not change `vite.config.js` to 5173.

6. **Secrets.** `RemoteSite::ssh_password` and `db_password` use the `encrypted` cast. Never write passwords into logs, step output, or the YAML. Pass them to `sshpass` inline (`SSHPASS='…' sshpass -e ssh …`). Do not rely on `export`, which the Electron child shell does not reliably pass through.

7. **Shell safety.** Build command strings with `escapeshellarg()` for every interpolated path or value. The codebase already does this in 40+ places.

8. **Lando DB import.** `lando db-import` must receive a plain `.sql` file. Decompress `.gz` to a temp `.sql` first, as `LandoService::dbImport` does, then remove the temp file.

9. **Database ports are unique across projects.** Every site's `services.database.portforward` must be a fixed port no other `.lando.yml` under the code path (or tracked site) publishes, and nothing may be listening on it. `DatabasePortAllocator` picks new ports (creating, cloning, re-allocating) and decides which of two clashing projects keeps a port (tracked sites win; otherwise the earlier path keeps it). Write the port with `LandoYamlPortEditor`, which edits the file in place. Any change to an existing project's port must be followed by `lando rebuild -y` in that project, or the containers keep the old port. Import does this automatically (`ImportSites::startRebuild`).

## Coding standards

### Baseline

- **PSR-4 autoloading.** `App\` maps to `app/`, `Tests\` to `tests/`. The namespace must match the directory. Do not add `require` or `include` calls.
- **PSR-12 formatting via Pint** (Laravel preset; there is no `pint.json`). Run `./vendor/bin/pint` on every PHP file you touch. Do not hand-format around it.
- **Indentation** follows `.editorconfig`: 4 spaces for PHP, JS, and Blade; 2 spaces for YAML; LF line endings; final newline; trim trailing whitespace (except Markdown).
- **Imports** are fully qualified `use` statements, alphabetized, with no unused imports. Do not use inline FQCNs in the body when a `use` will do.

### Types and strictness

- **`declare(strict_types=1);`** on every **new** PHP file, directly after `<?php`, followed by a blank line. The codebase is mixed: 24 of 82 PHP files in `app/`, `tests/`, `routes/`, `config/` and `database/` have it. Do not add it to an existing file as a drive-by change, since it changes coercion. If you are already rewriting most of a file, add it and run the tests.
- **Type every signature**: parameter types, return types (`: void`, `: ?string`, `: array`), and typed properties. Use constructor property promotion for injected dependencies, as `SiteManager` does.
- **Prefer `final`** for helpers and detectors that are not meant to be extended (`AnsiToHtml`, `WpConfigGenerator`, `CommandLogErrorDetector`). Most services are currently non-final; do not change that on an existing class without a reason.
- **Enums for fixed sets** (`SiteStatus`). Do not compare status against raw strings. Use casts on models.
- **Nullable means nullable.** Use `?T` only when `null` is a real state, not as a lazy default.

### PHPDoc

- Every **public method on a service or support class** gets a docblock. Add one for a non-obvious private method too, when it has side effects or a non-obvious contract.
- The docblock says **what and why**, not what the signature already says. Include side effects (filesystem, shell, DB, port reassignment), units, and failure behavior.
- Use **`@param` / `@return` only where the native type is not enough**: array shapes (`@return array{php_version: string, db_version: string}`), generics (`@param list<string>`), and `@var` on array properties (`@var array<string, mixed>`).
- Document thrown exceptions with `@throws` when callers are expected to catch them.
- Do not write `@param string $path The path` lines that repeat the signature.
- Keep one-line summaries in the imperative or descriptive mood, as in `Converts ANSI SGR escape sequences ... to safe HTML`.

### Naming

- Classes `PascalCase`. Methods and properties `camelCase`. Constants `UPPER_SNAKE`.
- Livewire actions are verbs that name the user intent: `saveDefaultPhpVersion`, `runLandoUpdate`, `pollLandoUpdate`. Polling methods start with `poll`.
- Config keys `snake_case` (`lando_dev.defaults.php_version`). Route names `dot.case` (`sites.show`, `create.clone`).
- Blade views and Livewire component directories follow Livewire's kebab-case mapping (`SiteDashboard` → `site-dashboard.blade.php`).

### Logging

- Use `Log::info` / `Log::error` with a `ClassName::method` prefix and a context array, as `CloneSite` does: `Log::error('CloneSite::runConnectionCheck remote site not found', ['id' => $id]);`.
- Never log passwords, tokens, or full SSH command lines that contain them.

### Livewire and UI

- Full-page components: `#[Layout('components.layouts.app')]` and `#[Title('…')]`. Use `WithNotifications` for toasts, not ad hoc `dispatch('notify')`.
- Use `wire:navigate` for in-app links, and relative `route(..., [], false)`.
- Terminal output scrolls through the `lando-studio-scroll-terminal` window event. Keep `wire:key` stable so terminals do not remount.
- **Flux first.** Reach for Flux components before writing custom markup. Styling is Tailwind v4 in `resources/css/app.css`.
- Keep Blade logic light. Compute data in the component or a service, not in the view.

### Models and database

- Eloquent models `$guarded = []` on `Site`. Add `$casts` for enums, integers, and encrypted fields. Keep relationship methods typed (`BelongsTo`, `HasMany`).
- Migrations are timestamped and named `create_*` or `add_*_to_*`. Add a migration for every schema change. Never edit an applied migration.

### Tests

- PHPUnit 11. Test classes end in `Test`, and test methods use `test_snake_case_describing_behavior()`.
- `Unit` tests for pure logic (parsers, detectors, `AnsiToHtml`). `Feature` tests when the database or container is involved.
- Use `RefreshDatabase` on any test that touches the DB. The test environment uses in-memory SQLite (`phpunit.xml`).
- Fake HTTP with `Http::fake()` (see `WpConfigGeneratorTest`). Never hit `api.wordpress.org` or a real remote in tests.
- Temp files: create a unique path, clean up in `finally` or `tearDown`.
- Run the **full suite** (`php artisan test`) after any non-trivial change.

## Guardrails

- **Focused diffs.** Change only what the task needs. No drive-by refactors, reformatting, or renames in unrelated files.
- **Do not rename** routes, the `Site` route-model binding, or the `lando-studio-*` window events without updating every Livewire and Blade caller.
- **Do not** change the Vite port, the Electron/NativePHP setup, or the host PHP selection in `scripts/native-php.sh` without checking the desktop dev flow.
- **Do not** commit `.env`, `vendor/`, `node_modules/`, `public/build/`, `public/hot`, `storage/lando_studio_defaults.json`, or `storage/logs/*.log`. All are gitignored; do not force-add them.
- **Do not** add dump, debug, or log statements to committed code (`dd`, `dump`, `var_dump`, `ray`). The codebase currently has none.
- **Do not** write dump artifacts on the remote server. Dumps are produced locally only. The remote-file policy is in `project-status.md`.
- **Working with specs and designs:** if a specification or design does not match the code or the other design, say so explicitly to the developer. Name both sides and the gap; do not silently pick one.

## Known issues (verify before relying on)

- `tests/Unit/MysqlDumpTablePrefixDetectorTest::test_detects_custom_prefix` fails: `MysqlDumpTablePrefixDetector::fromGzipFile` returns `wp_` for an `acme_options` table. Last checked 2026-11-03.
- `routes/web.php` fails `pint --test` (import order and operator spacing).
- Windows dump path (`SshService`) lacks the `pipefail` and heartbeat handling that macOS and Linux have (`project-status.md`).
- Some Settings logic is called from services (`SiteManager` calls `Settings::getDefault`). Do not add more; move it to a service if you touch it.

## Working agreements

- Read the code before changing it. Check the surrounding files for naming and idiom, and match them.
- Record handoff context in the repo (`project-status.md` already serves as the clone/dump handoff log), not only in chat.
- For a new artifact or feature, list the choices you made (what you chose, what you left out) in the final summary so the developer can redirect.
- Commit messages follow the existing history: Conventional Commits with a scope, e.g. `fix(dashboard): show local_domain in remote site selector`.

## Branches and releases

- `main` is the release branch. Only merged, QA-approved work lands there, and release tags are cut from it.
- `dev` is the integration and QA branch. Developers and users test here before anything reaches `main`.
- Every new feature gets a branch named `feature/<feature-name>`. Every bug fix gets `bugfix/<bug-name>`. Use short kebab-case names (e.g. `feature/windows-ci`, `bugfix/import-port-clash`).
- Process for each change:
  1. Create the `feature/*` or `bugfix/*` branch from `main`.
  2. Commit all the necessary changes to that branch.
  3. When the branch is stable, open a PR from it into `dev`.
  4. QA happens on `dev` with other developers and users.
  5. Once QA passes, open a PR from the same custom branch into `main`.
- Do not commit directly to `main` or `dev`. Do not open the `main` PR before the `dev` QA pass.

# main (project memory, goku-shared)

## Status (2026-10-14)
- Forked from goku-symfony at ea3f23c (`git clone`, history kept). `origin` = https://github.com/alexrayu/goku-shared.git (added 2026-10-14, nothing pushed by Claude). All port work is uncommitted in the working tree.
- Round 2 (2026-10-14): per-release cache dir, web updater for pending migrations, admin warning for a stalled queue, cleanup of abandoned chunk files. Verified: 79 tests, PHPStan clean, release B unzipped over an installed release A with a no-op migration -> visitors see "being updated", login -> Update -> admin.
- Port done in one autonomous session while the user was at work: MariaDB/MySQL baseline, Doctrine Messenger + cron worker, Imagick and GD processors with runtime selection, Apache `.htaccess` (public + root fallback), pre-database web installer, chunked archive upload, release zip. Verified: 76 tests green on MariaDB, PHPStan clean, release zip installed in prod mode with docroot = project root, special-character DB password, chunked 21 MB upload, cron-style worker, reader screenshot without seams (GD and Imagick).
- Session report: `~/Documents/tickets/goku-shared/port/report.md`. E2E harness (archive generator, Playwright upload + reader screenshot, GD/Imagick benchmark): `~/Documents/tickets/goku-shared/port/e2e/`.

- Round 3 (2026-10-14, after commit 1df26be): audit + QA. The updater now asks for `UPDATE_TOKEN` (installer writes it) instead of a login, because login and Twig globals load entities that break when a migration changes their tables; settings use defaults while migrations are pending. `migrate()` lifts `max_execution_time`; `.env.local` is written 0600. Fixed subfolder installs (redirect base path, `SCRIPT_NAME` behind the root `.htaccess`). QA passed: 80 tests on MariaDB and on MySQL 8.0.46; release in a subfolder with proc_open/exec disabled, open_basedir, 128M, 30 s, MySQL 8, special-character password: installer, chunked upload, cron worker (Imagick), reader, upgrade with a schema-changing migration and a live session. README screenshots in `screenshots/` (excluded from the release zip).
- Dev DB now holds goku-symfony's demo manga (5 works, 11 chapters, 64 pages, same ids; storage copied, modes normalised: goku-symfony's `derivatives` dir had a broken mode that root hid). Dev `.env.local` has `UPDATE_TOKEN=devtoken`.

## Next steps
1. User reviews the decisions taken without them (report.md, "Decisions to confirm") and commits.
2. Open items in `LATER.md` (old cache dir removal, PHP 8.2/8.3, S3, CI, license). GD strips in bands was dropped: GD cannot decode part of an image, and the full-source decode is the main cost.
3. Test on a real shared host (cPanel/Plesk): `.htaccess` behaviour, cron PHP binary path, `open_basedir`, disabled functions.

## Working mode
- Build mode (CLAUDE.md): no teaching stops; still ask on blockers and new dependencies. Work on `main` only; ONE commit message per task; the user commits and pushes. Update this file by hand at the end of each task.

## Environment and harness
- Dev: `dc(){ docker compose --env-file versions.env "$@"; }`; Apache on :8010. CLI as host user: `dc run --rm --user 1001:1001 -e COMPOSER_HOME=/tmp/c php ...` (Makefile does this).
- Dev DB is set up through the installer (`.env.local`, gitignored). Temp admin `demo-seed@goku.test` / `pw12345` exists in the dev DB from the e2e run; delete it when a real dev user exists.
- Browser testing: Playwright venv in `~/Documents/tickets/goku-symfony/phase-6/venv`, `executable_path` to `~/.cache/ms-playwright/chromium-1208/*/chrome`, `bypass_csp=True`, `--no-sandbox`.
- Release test: unzip `dist/*.zip`, `docker run -d --network goku-shared_default -p 8020:80 -e APACHE_DOCUMENT_ROOT=/app -v <dir>/goku:/app goku-shared-php`, DB user from the `database` container (root/root).

## Gotchas
- Composer's global GitHub token is stale: clean `COMPOSER_HOME`, quote constraints in zsh. Keep all `symfony/*` at 7.4, recheck the lock.
- `%env(resolve:DATABASE_URL)%` broke every page once the installer wrote a URL-encoded password (`%24` read as a parameter). Plain `%env()%` only.
- `APACHE_RUN_USER=#1001` left php:apache workers running as root (root-owned storage, worker could not read uploads). The image remaps `www-data` to `HOST_UID` instead.
- GD memory: 4-byte truecolor plus the PNG decoder's full-image buffer, ~7 bytes per source+canvas pixel (1600x20000 strip: 342 MB peak, refused at 256M). Imagick keeps pixels outside PHP (1 MB PHP peak).
- vips `thumbnail --size down --crop` on a source smaller than the box crops without upscaling (600x500 into 1200x630 stays 600x500); `SourceImage::cover()` copies that.
- Foundry warns "No persistence managers configured" when a filtered run starts with a unit test; the full suite does not.
- Upgrades: `Kernel::getCacheDir()` appends the id from `config/release.php` (written by `bin/release`, gitignored); without it an unzip over the old install keeps running the old compiled container. `framework.cache.prefix_seed` uses the cache dir, so cache.app (in var/share) gets a new namespace per release too. "No pending migrations" is cached in cache.system (per release).
- Foundry resets the test DB through the migrations (`reset.mode: migrate`): schema mode leaves `doctrine_migration_versions` empty and every page would redirect to the updater.
- Installer state: DB form open only while `DATABASE_URL` holds `!ChangeMe!`; a configured but unreachable DB is a 500, never the installer. Schema step only while `app_user` is missing.
- Carried over from goku-symfony: `csrf.yaml` only `submit` stateless; installer subscriber priority 64; tests need a user or redirect to `/install`; `getUser()` on the lazy firewall makes responses private (check `hasPreviousSession()`); `.css.twig` CSS-escapes the accent (print `|raw`); CSP hashing before the 304 (`SecurityHeaders` 16, `PublicPageCache` 0); EasyAdmin quirks (DECIMAL `setStoredAsString()`, `UniqueEntity` for DB keys, wide fields need `.table-responsive`); test gradients and colour when judging image quality.

## Decisions
- Fork decisions (2026-10-14, taken autonomously, to confirm): MySQL/MariaDB only; Doctrine transport + cron; vips -> Imagick -> GD auto-selection; Ansible and Redis removed; PHP stays 8.4.
- Images: GD and Imagick reproduce the vips pipeline: fit within 1200 wide and the WebP height cap, padded copy with edge pixels repeated (vips `embed --extend copy`), tiles copied into shuffled 144 px cells. Covers are centre crops (vips used `attention`). Input sniffed by `getimagesize` (JPEG, PNG, WebP, GIF only); Imagick reads with an explicit coder prefix so SVG/PDF/MSL coders are unreachable. EXIF orientation applied. `UnprocessableImage` fails the page without retries.
- Chunked upload: slice = min(upload limit, 16 MB) - 64 KB, sequential, staged in `STORAGE_PATH/staging/chapter-<id>.part`, offset must match the staged size, last slice calls `ArchiveIngestor::accept()`. The plain form post remains the no-JS fallback.
- Installer: one `/install` route walks requirements -> DB credentials (connects, writes `.env.local` with a fresh `APP_SECRET`, single-quoted URL; shows the content if not writable) -> migrations in-process -> first user. Open to the first visitor, like before.
- Release: `bin/release` copies the working tree minus dev files, `composer install --no-dev --classmap-authoritative`, `asset-map:compile`, `APP_ENV=prod` in `.env`, empty `var/`.
- Domain, reader, URLs, media cache headers, CSP, admin hub: unchanged from goku-symfony (see its memory). Media is now streamed by PHP; `MediaController` still honours X-Sendfile when a host trusts it.

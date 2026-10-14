# CLAUDE.md

Shared-hosting edition of goku-symfony (forked 2026-10-14 with its history): the same self-hosted manga/comics platform, built to run on ordinary shared hosting (Apache + `.htaccess`, PHP, MySQL/MariaDB, cron; no shell, Redis or libvips required). One install = one artist's site; invite-only posting, public reading. Original brief: `.claude/manga-platform-brief.md` (written for the droplet edition; where it conflicts with this file, this file wins). Install guide: `README.md`.

Branch memories live in `.claude/memories/<branch>.md` (committed, public repo), not in `~/.claude/tickets`. Work happens on `main` only (user rule: no feature branches), so `.claude/memories/main.md` is the one memory file.

## Stack (decided, do not relitigate; if one blocks you, stop and ask)

- Symfony 7.4 LTS, Doctrine ORM, MySQL/MariaDB only (PostgreSQL dropped in the fork; one migration baseline). Keep all `symfony/*` at 7.4 (recheck the lock after any `composer require`).
- Messenger with the Doctrine transport (`messenger_messages` table), consumed by a host cron job running `messenger:consume --time-limit=55`.
- Images behind `ImageProcessor`; `ImageProcessorFactory` picks libvips CLI (needs `proc_open`), then Imagick, then GD (`IMAGE_PROCESSOR` forces one). GD decodes into PHP memory: every GD job checks its estimated peak against `memory_limit` and refuses what would not fit.
- WebP derivatives only. No AVIF.
- Flysystem local adapter (`STORAGE_PATH`, default `var/storage`). Originals never web-reachable; derivatives through `MediaController` (PHP streams the file; X-Sendfile only if a host offers it). No S3 yet.
- Twig + Turbo + Stimulus via Symfony UX and AssetMapper. No Node build, no React.
- EasyAdmin for backoffice. PHPStan level 8 (+ doctrine), PHPUnit + Foundry.
- Dev: Docker Compose mimicking a shared host (php-apache, MariaDB, GD + Imagick, no Redis, no vips, 8M uploads, 256M memory). Prod: shared hosting from the release zip (`make release`), web installer, cron. No Ansible, no Docker in prod.
- Search later: MySQL FULLTEXT (decide when needed). No Meilisearch/Solr.
- Ask before adding any dependency not listed here.

## Working agreement: build mode now, teaching mode later

- Since 2026-10-06 (from phase 6): build without teaching stops. No query-count predictions, no "yours to write" pieces, no per-step proposals. Still ask on genuine blockers and dependency additions; still never commit. At the end of a task, list the changed files and propose one commit message for all of them: one terse sentence, no body, no Co-Authored-By (user rules 2026-10-08).
- When the user asks to be taught, apply the teaching rules below to the finished code.

### Teaching mode (paused)

- Propose, then build: a few lines on what and why before each step.
- Explain Doctrine decisions explicitly (owning vs. inverse side, cascade, orphan removal, fetch modes, flush timing), naming the UnitOfWork behaviour.
- Query-count discipline: for any page or repository method loading related data, ask the user to predict the query count before showing the profiler. Fix N+1s deliberately and explain.
- Note where Drupal would do it differently.
- Per phase, mark one or two small pieces as "yours to write" (repository method, Stimulus controller, test) and review them instead of writing them.
- The user commits, never Claude. At each logical boundary stop, list changed files, propose one message for the whole change.
- Stop at each phase checkpoint.

## Domain rules

- `Work.type`: `series | oneshot`. A oneshot is a Work with exactly one Chapter, rendered without a chapter list.
- `Chapter`: number, title, reading direction (`ltr | rtl | vertical`), published flag.
- `Page.position`: gapped integers (10, 20, 30...) so inserts never rewrite the chapter.
- Page width and height are stored at ingest; the reader reserves space from them.
- Archive page order is natural-sorted (`page_2` before `page_10`).
- Single-artist: no ownership on `Work`, no tenancy; every logged-in user manages every Work. No public registration; users are created by console command.
- Reading progress (later): thin table `(user_id, chapter_id, last_page, updated_at)`, upserted, not an entity.

## Portability rules

Seams only where a port is already known; no speculative abstraction. Swapping = new service + DI alias, not caller changes. Explain each seam when introduced, with the Drupal comparison.

- Image processing behind the `ImageProcessor` interface (vips, Imagick, GD); all three pass `tests/Image/ImageProcessorTestCase`.
- Portable Doctrine types only: no JSONB, array columns, custom types.
- All image URLs from one URL-generator service; never build storage URLs in Twig or controllers.
- Ingest starts from an archive already in storage, then dispatches a job.
- Database, Messenger transport and storage chosen by env DSNs, never hardcoded.
- Thin controllers and EasyAdmin CRUD; domain logic in services.
- App code must not assume a shell, `proc_open`, Redis, a long-running worker, or large request bodies (uploads are chunked).
- Secrets via env / Symfony secrets; nothing secret in the repo.

## Out of scope until the slice is live

Paged/RTL reader, reading progress, follows, search, comments, likes, ratings, collections, notifications, API Platform, public registration, AVIF, extra derivative sizes, multi-artist/tenancy. If something feels necessary, add it to `LATER.md`.

## Commands

`make up | down | install | stan | schema | test | worker | release` (run inside the php container as the host user; use `--no-deps` for one-off runs). Dev site on http://localhost:8010.

## Gotchas

- Composer's global GitHub token may be stale (Flex recipe 404s); use a clean `COMPOSER_HOME`.
- Do not set `config.platform.php` below the container's PHP patch version.
- `versions.env` is passed via `--env-file`; Compose's default `.env` is Symfony's.
- No `DATABASE_URL` in compose: dev runs the web installer like a host and gets `.env.local` (tests use `.env.test`). The installer's database step is open only while `DATABASE_URL` still contains the `!ChangeMe!` placeholder from `.env`; keep that marker.
- Never `%env(resolve:DATABASE_URL)%`: the installer URL-encodes passwords, and `resolve:` reads `%24...%` as a container parameter (500 on every page).
- Apache in the dev image runs as `www-data` remapped to uid 1001 (`HOST_UID` build arg); `APACHE_RUN_USER=#uid` left the workers running as root.
- Image-quality checks (scrambling, seams, compression): test gradients and colour in the real browser, not only text on white. Text-on-white hid tile seams that showed in the reader.
- Dev DB pages ingested before a derivative-format change (scrambling, thumbs) render broken. Re-ingest demo content before judging the UI; generator and uploader in `~/Documents/tickets/goku-symfony/ui-polish/`.
- Request subscribers that run before the `RouterListener` (priority 32) and generate URLs must call `getContext()->fromRequest()` first, or a site in a subfolder redirects to the domain root.
- A site in a subfolder behind the root `.htaccess` gets `SCRIPT_NAME=/<dir>/public/index.php` for `/<dir>/...` URLs; `public/index.php` rewrites `SCRIPT_NAME` so Symfony finds the base path. Domain-root installs never show this; test subfolders.
- Code that runs while migrations are pending (updater, login, Twig globals) must not load entities: their new mapping may not match the old schema. Hence the `UPDATE_TOKEN` updater and default site settings while pending.
- `cache.system` uses APCu when the extension is loaded, and a CLI pool clear never reaches the web server's APCu. To make the web side re-check pending migrations, run `cache:clear` (new container build id).

# unraid-secretsman — CLAUDE.md

## What this project is

Central secret storage for Unraid Docker templates. See `README.md` for what this is and why;
this file is the design record and phase roadmap for whoever (human or Claude) picks this up
next.

Stack: plain PHP (targets 8.1 and 8.4), no framework, no Composer, no runtime dependencies beyond what
Unraid already ships. The web UI in `ui/` (Preact, Vite, TypeScript) is built at package time; the shipped plugin
carries only its static output. `src/` is the resolver/patch/backup library; `plugin/` is the tree as
installed at `/usr/local/emhttp/plugins/unraid-secretsman/` (`plugin/src` and `plugin/reference`
are symlinks back to the top level — see "Repo layout note"); `tests/` is a hand-rolled assert
runner plus an on-host render harness. Ships as a self-hosted `.plg` + `.txz`.

**Host-level work that isn't about this plugin now lives in `kmbrimble/unraid-ops`** (private
repo) — most notably the dockerd/containerd container-loss investigation formerly tracked in
`docs/phase2-resume.md`, which was never actually caused by secretsman. See that repo's
`investigations/` directory, not this one, for anything host-level going forward.

**Projects live at `/projects/<name>`, never `~/projects`.** `/projects` is the bind-mount that survives container
rebuilds; `~/projects` is ephemeral. If a session ever lands in `~/projects` for real work, that is the mistake
recurring. (The stray `~/projects` skeleton this note used to describe is gone, checked 2026-11-04. The history is in
`docs/history.md`.)

**Generic Unraid rules live in user-level skills, not here.** This file keeps what is specific to secretsman:
- packaging, `.plg`, install/remove, boot ordering: `unraid-plugin-packaging`;
- `.page` files, endpoints, CSRF, POST transport, SAPI differences, stock CSS/widgets: `unraid-webgui-plugin-pages`;
- the patch layer method (hash gate, harness, recovery drill, upgrade re-verification): `unraid-stock-file-patching`,
  with this repo's own instance in `.claude/skills/unraid-upgrade-reverify/`;
- testing on the live host: `unraid-live-host-testing`;
- release mechanics: `forge-release`.

**History:** the phase write-ups, the disproven array-start hook design and the incident narratives are in
`docs/history.md`, verbatim. Read them for *why*; this file is the current design.

## Non-negotiable constraints

1. **FAIL CLOSED.** An unresolvable token aborts container creation with a visible error. A
   literal `!secret foo/bar` must NEVER be passed through as a variable value. This governs
   every ambiguous case in the token grammar: when in doubt, throw, don't guess.
2. **The store lives outside flash.** Default `/mnt/user/appdata/.secrets/store.json`,
   `root:root 0600`. Only the plugin's config — a pointer to the store path — goes on flash.
3. **Never log a resolved secret.** Not in debug output, not in error messages, not in tests.
   Every `SecretsmanError` message is written to name the *shape* of the problem (missing key,
   wrong field type, bad permissions) never the value.
4. **`/usr/local/emhttp` is restored from the OS image every boot**, so the `.plg` re-patches at
   boot. The patch must be a surgical, checksum-guarded text injection that REFUSES to apply
   (and raises an Unraid notification) when `Helpers.php` doesn't match a known-good hash in
   `reference/<version>/HASHES`. Never a whole-file replacement. **When this gate refuses to
   patch after an Unraid upgrade, use the `unraid-upgrade-reverify` skill
   (`.claude/skills/unraid-upgrade-reverify/SKILL.md`)** — the repeatable re-verification
   procedure this project developed, rather than re-deriving it from scratch each time.
5. **Prefer leaving the system untouched over a partial patch.**
6. **Never in the command string.** Callers of `xmlToCommand()` echo the returned `$cmd`,
   including to the GUI. A resolved secret must never appear in that string — see "Insertion
   point" below for how the resolver avoids this structurally, not by scrubbing after the fact.
7. **Any change to the patch layer (Phase 2+) requires the recovery drill in `RECOVERY.md` to
   be re-run and confirmed working before that change ships.**
   Enforced at release time since 1.0.1 (now `scripts/release-gates.sh`, run by Gitea CI on the version-bump PR and again by
   `.gitea/workflows/release.yml` at the tag): the release fails if
   the patch layer changed since the previous tag and the new version's CHANGES entry does not
   record the outcome on a line starting `RECOVERY drill:`.

8. **A function reachable from both a CLI script and a web-request script must not assume
   CLI-only globals or semantics.** That means: no direct `STDOUT`/`STDERR` writes (undefined
   under php-fpm — write a `[$ok, $message]`-shaped result instead, and let the CLI-only
   invocation guard at the bottom of the file, `if (realpath($_SERVER['SCRIPT_FILENAME']) ===
   __FILE__)`, do the printing and pick the exit code); no reliance on `$argv`; no assumption
   about `getcwd()` matching the script's own directory; no using an exit code as the only
   channel the caller learns the result through, since a web caller invoking the function
   in-process never sees one. **The test suite cannot catch a violation of this rule** — it
   runs exclusively under the CLI SAPI, where all of the above are defined by construction, so
   a function that's broken only under php-fpm still passes every test. Checking for this at
   the moment you give a CLI-only function a second, web-context caller is the only defense
   that actually works — see the dated STANDING NOTE below, and note this is the *third* time
   a caller in a different execution context (web vs. CLI, or a second enforcement layer with
   different timing) silently invalidated an assumption baked into working code: this one, the
   redundant CSRF check, and the `.plg` remove block's `-x` check on a script that's always
   invoked via `php <path>`, never executed directly.

9. **No md5 sidecar file, and no md5 re-check in the `.plg`'s install script.** The plugin
   manager already verifies the `.txz` against the `<MD5>` tag on its `FILE` block, in the
   same function, on the branch that re-fetches on a mismatch. A `<FILE Name="&plgPATH;/&name;.md5">`
   sidecar CANNOT be updated by a later release — the manager never overwrites an existing FILE
   that declares no `MD5`/`SHA256` of its own — so it stays pinned to the first version ever
   installed, and a script re-checking against it fails every subsequent upgrade with
   `package md5 mismatch, refusing to install`. This silently blocked 1.0.0 and 1.0.1 from ever
   reaching this host; the host sat on 2026.08.25 for two releases while `gh release list` said
   otherwise. Removed in 1.0.2, which also deletes the orphaned sidecar. `docker.netman` hit the
   identical bug and removed it in its 0.2.0 — see that repo's CLAUDE.md "Plugin manager quirk".
10. UI follows DESIGN.md. No colour, spacing, radius or shadow values outside the vendored
    `ui/src/styles/tokens.css`; changes go upstream to kmbrimble/design-system and are re-vendored.


## Patch target and insertion point (Phase 0 recon, 2026-08-24, read-only against the live host)

This is the recorded definition and call-site set that the `unraid-stock-file-patching` skill says to keep and to
re-check on every upgrade. The full original recon text is in `docs/history.md`.

- **There is exactly one producer of the docker create command: `xmlToCommand()`.** `@unraid/api` 4.34.0
  (Connect / unraid-api stack) has no container-create GraphQL mutation. `DockerMutations` is `start, stop, pause,
  unpause, removeContainer, updateAutostartConfiguration, updateContainer, updateContainers, updateAllContainers`,
  with no `createContainer`. The string `createContainer` appears nowhere in `/usr/local/unraid-api/dist`.
  `updateContainer` (the only re-create path) shells out to `scripts/update_container`, call site #5 below.
- **Single definition:** `/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php:389`,
  `function xmlToCommand($xml, $create_paths=false)`, returns `[$cmd, $Name, $Repository]`. The emhttp plugin
  directory is `dynamix.docker.manager`, NOT `dockerMan`. Only the flash template path
  (`/boot/config/plugins/dockerMan/templates-user/`) kept the old name.
- **Five call sites, all covered by patching the single definition:** `dynamix/include/UpdateTwo.php:148`,
  `dynamix.docker.manager/include/CreateDocker.php:134` (GUI apply), `CreateDocker.php:254`,
  `scripts/rebuild_container:29`, `scripts/update_container:163`.
- **Insertion point:** one line, immediately before the `foreach ($xml['Config'] as $key => $config)` loop (line 508
  in the 7.3.1 copy in `reference/7.3.x/Helpers.php`). At that point Config values are still unescaped, which is
  exactly what the resolver needs. Mutating `$xml['Config']` / `$xml['ExtraParams']` there lets the *existing* loop
  do all the emitting: deleting a resolved `!secret` entry means no `-e` is ever generated for it. This is why
  patching one line covers the security property for every caller without touching any of them.
- **Boot-time re-patch:** the `.plg` install block, run by `rc.local` for every flash `.plg` at boot. That is also
  why "delete the `.plg`, reboot" is a complete recovery path (see `unraid-plugin-packaging`, "Boot, array start and
  daemons").
- Unraid's PHP has **no `yaml` extension** and none is installable in place, which is why the store is JSON.
- `/run` is tmpfs, 128 MB, mounted `noexec`. `!secret` env-files go under `/run/secretsman`
  (`SECRETSMAN_RUNTIME_ROOT` overrides it in tests) and are passed as `--env-file`. That is fine for env-files, and
  it is small.

## `!secretfile` was removed; don't re-add it without a new threat model

`secretsman_parse_token()` recognises `!secretfile` only to abort with a message pointing at `!secret`. It was
removed (2026-08-25) after a real reboot showed its bind source could be missing when `rc.docker` autostarted the
container: Docker auto-vivified it as an empty directory, and `container_paths_exist()` checks only existence, not
type. The full CORRECTION and the superseded `disks_mounted` design are in `docs/history.md`. The decision, verbatim:

**Consequence, decided the same day: `!secretfile` was removed entirely, not redesigned.** A
redesign was drafted (fold repopulation into a synchronous, checksum-guarded `rc.docker` patch,
plus a type/mode check replacing the bare `container_paths_exist()` existence check) but never
built. On reflection, `!secretfile`'s only marginal benefit over `!secret` — hiding a value from
`docker inspect`/`/proc/<pid>/environ` — protects against an attacker who already has root or
docker-socket access to the host, at which point `store.json` is directly readable anyway. That
benefit wasn't worth a second patched stock file, a second checksum to carry through every
future Unraid release, and a boot-ordering dependency that had just been disproven once already.
`secretsman_parse_token()` now recognises `!secretfile` only to abort with a message pointing at
`!secret`. **If you're reading this section wondering whether to re-add file mode: re-read this
paragraph first, and the cost/benefit hasn't changed unless the threat model has.** `!secret`
(env-file, resolved at container-create time in the GUI, not at boot) is unaffected by any of
this.

## Adding a hash for a new Unraid version

`apply_patch.php` maps `/etc/unraid-version` to `reference/<major>.<minor>.x/` (a pre-release
suffix like `7.4.0-rc.2` maps to the same dir as `7.4.0`) and patches only if the live
`Helpers.php` md5 is listed in that dir's `HASHES`. No dir, or no match: notification, host left
unpatched. Upstream tags of `unraid/webgui` are a reliable hash source: verified 2026-10-30 that
the live 7.3.4 file with our block reverted equals the 7.3.4 and 7.3.1 tags (md5 `9a45421b…`).

**`reference/7.4.x/HASHES` holds the md5 of the RC tag 7.4.0-rc.2 only** (commit `26eb1b5c`,
identical in 7.4.0-rc.2.3; `dcece361…`), added before the final release existed. **The final
7.4.0 must be re-verified and its hash added** — the file can change between RC and final — and
nobody has yet seen it run on a real 7.4 host. Procedure:

1. `scripts/add-reference-hash.sh <tag> reference/<x>.<y>.x` — fetches the tag, fails unless the
   anchor line occurs exactly once, lists `xmlToCommand()` call sites (expect 1 definition, 5
   calls), prints the diff against the current reference and the HASHES line. Changes nothing.
2. Read the diff by the `unraid-upgrade-reverify` skill's step 4 standard (does anything touch
   the `Config` loop or the code before it?). Stop there if it does.
3. Add the printed line (with its tag/commit comment) to `HASHES`, keeping older lines for hosts
   still on them. Keep `Helpers.php` in that dir as a copy matching one listed hash (a test
   checks it).
4. `php tests/run.php`, bump `&version;`, CHANGES entry (including a truthful `RECOVERY drill:`
   line if `apply_patch.php`/`patch.php`/`uninstall.php` changed), push.

Never an anchor-only fallback: an unlisted hash is refused by design.

## Store format

JSON, not YAML (see correction above). Flat two-level: `{"namespace": {"key": "value"}}`, all
leaf values strings. `json_decode(..., JSON_THROW_ON_ERROR)` then explicit shape validation
(object of objects of strings) — any decode failure or shape mismatch aborts the whole load,
never a partial store. Values containing `\n` or `\r` are rejected at load time with a clear
error: `--env-file` cannot carry newline-bearing values, and silently mangling one is worse than
refusing to load.

## Token scope

Tokens are honoured **only in Variable-type Config fields.** A token found in any other field
type (Path, Port, Label, Device) — or in the free-text `ExtraParams`/`PostArgs` fields, which
aren't Config entries at all — aborts container creation, naming the field. This was a
deliberate, narrow starting scope: Labels land in `docker inspect` regardless and
`ExtraParams`/`PostArgs` are inserted directly into `$cmd`, so extending resolution there would
either offer no real protection or violate rule 6 above. **Widening this scope later is
additive; narrowing it would be breaking** — so start narrow, widen only on a demonstrated need.

## Shipping model

Packaging rules (strcmp versions, archive paths rooted at `usr/local/emhttp/plugins/<name>/`, `-f` not `-x`, the
stock-shaped `plugin/README.md`, never pre-placing files before testing an install, FILE/MD5 behaviour): see the
`unraid-plugin-packaging` skill. What is specific to this repo:

- Not going into Community Applications. Self-hosted `.plg` installed by URL from `git.kiztigs.com` (raw branch URL), with
  a packaged `.txz` attached to a Gitea release (see "Forge" and "Deploy and verify").
- `scripts/build-plugin.sh` assembles the installable tree. CI runs it (release-readiness on Gitea, then the GitHub
  release workflow at the mirrored tag). The archive is deterministic (sorted names, fixed mtime), so the md5 is the
  same on both runners. That determinism holds between the CI runners only: see "Deploy and verify".
- **Repo layout:** `plugin/src` and `plugin/reference` are symlinks to the top-level `src/` and `reference/`, so
  there is one copy of each file at rest. `plugin/` on its own matches the installed layout
  (`/usr/local/emhttp/plugins/unraid-secretsman/`). `build-plugin.sh` resolves the symlinks into real files.
- `plugin/README.md` is a real file and deliberately NOT the repo's `README.md` (issue #2: the repo README's H1
  blew up the Plugins-tab row). Keep it in the stock shape the packaging skill describes.
- **Version ordering is enforced, not remembered:** `scripts/release-gates.sh` (Gitea CI and the GitHub release
  workflow) refuses a version that does not sort after the previous tag as a plain string.
  `scripts/install-on-host.sh` compares against the host's installed version first, and names `--forced` when
  crossing a boundary is what you want. The 1.0.0 date→semver switch needed `forced` once, because the host was on
  `2026.08.25`.
- **Verify a `.plg` install by actually running it, never by pre-placing files.** This repo learned it from a
  package archived as `unraid-secretsman/...` that extracted to `/unraid-secretsman` unnoticed, because the tree had
  already been `scp`'d into place (`docs/history.md`, `docs/phase2-resume.md`). Any check of the install or remove
  path starts from a clean slate: no plugin directory, no package registry entry.

## Current design by component

The phase write-ups (Phases 0–4, with their incidents and verification narratives) are in `docs/history.md`. This
section keeps only what is still true and still constrains changes.

**Resolver (Phase 1):** `src/secretsman.php` handles token parsing, store load/validate, env-file materialisation
and the `secretsman_resolve()` entry point. `tests/run.php` is a plain-PHP assert runner (no Composer/PHPUnit),
failing-first per `/feature` discipline.

**Patch layer (Phase 2):** `src/patch.php` is the checksum-guarded, idempotent, marker-delimited injection and
reversion at the insertion point above (`secretsman_patch_apply`/`_revert`/`_verify`/`_apply_to_file`). The
injected text is just a guarded `require_once` plus `secretsman_resolve($xml, $xml['Name']);`, so it is
mode-agnostic. Removing `!secretfile` changed zero bytes of it.
- `plugin/scripts/apply_patch.php` patches at boot, looking up the per-version hash in the bundled `reference/`.
  `plugin/scripts/uninstall.php` reverts cleanly on Remove.
- There is no `event/` directory and no boot-time repopulation script. Those existed only for `!secretfile`.
- `tests/harness/` (`render_cmd.php` + `regression.php`) proved stock vs. patched output byte-identical across
  every real template on the host (48/48), plus a token-bearing fixture leaking nothing into `$cmd`. That fixture
  check is the required test that a resolved value never appears in the command string dockerMan renders.
- Clean removal was verified against a live host on 2026-08-25: stock hash `9a45421b387b733ad260e204308baa69`,
  0 markers, `store.json` byte-identical, a running `!secret` container untouched. It caught the remove block's
  `-x` gate on `uninstall.php`, which is invoked as `php <path>` and packaged 0644, so the revert never ran. Fixed
  to `-f` and regression-tested. **Re-run this drill after any change to `uninstall.php`, the `.plg`'s remove
  block, or `secretsman_patch_revert()`.**

**Settings GUI (Phase 3, rebuilt as a web app in 1.1.0):** it exists because a hand-edited `store.json` with a missing
comma produced a blank Docker "Update Container" page with no visible error.
- `plugin/SecretsMan.page` is a top-bar tab (`Menu="Tasks:91"`, `launch="SecretsMan"`) and, since 1.1.0, only a thin
  iframe host plus an "Open full page" link. It has no store access and no markup of its own, and must sit in the
  plugin's installed root. The iframe is sized by JS to the viewport below the header (the app scrolls inside it).
- The UI is a static Preact + Vite + TypeScript app in `ui/`. `scripts/build-plugin.sh` runs `npm ci && npm run build`
  and packages `ui/dist/assets/` plus `plugin/app/index.php` into `app/`. Output names are fixed (`assets/app.js`,
  `assets/app.css`) with no sourcemaps, so the archive stays byte-reproducible; CI pins Node (`22.23.4`) and
  `npm ci` pins the packages. `SECRETSMAN_UI_DIST` points the script at a prebuilt dist (the test suite uses it).
- `plugin/app/index.php` is the shell: it emits only `csrf_token` from `var.ini` (as `data-csrf`, escaped, `no-store`),
  derives `data-theme` from the Unraid theme (black/gray dark, white/azure light, per `ThemeHelper.php`) and loads the
  content-versioned assets. All app requests are urlencoded POSTs carrying the token as a field and as
  `X-CSRF-Token` (never multipart). The app path stays lowercase: an uppercase first letter routes to `template.php`.
- UI follows DESIGN.md (house design system, vendored in `ui/src/styles/`). No colour, spacing, radius or shadow
  values outside the vendored `tokens.css`; changes go upstream to kmbrimble/design-system and are re-vendored.
  `npm run check:contrast` in `ui/` runs the contrast check against the vendored tokens.
- **Namespaces and keys are case-sensitive** (exact-match lookup in `src/secretsman.php`): `Immich` and `immich` are two
  namespaces and the UI shows them as two sections, in their stored case (the vendored `.section-label` uppercases, so
  namespace names use `.sm-ns-name`, not that class). `secretsman_sort_store()` is natural case-insensitive with a
  case-sensitive tie-break, so the order is deterministic. Never merge or normalise case.
- Namespaces start collapsed. While a search is active the matching ones are open via a separate `searchOpen` map, so
  clearing the search restores the user's own `expanded` state.
- **Copy secret** goes through the same `reveal` request as Reveal: one named value per explicit click, held only in a
  local variable and a transient off-screen textarea (`copyText()`, `execCommand`, because the webGUI is plain http
  and `navigator.clipboard` is unavailable), never in state, the DOM or a toast. A component test guards this.
- The theme choice (`auto|light|dark`) lives in `localStorage` key `secretsman-theme`, shared by the iframe and the
  standalone page. `app/index.php` applies a stored light/dark choice in an inline script before the stylesheet (no
  flash) and exposes the Unraid-derived theme as `data-server-theme` for "auto".
- `plugin/scripts/store_api.php` and `backup_api.php` render no HTML any more: `list` and every mutation return one JSON
  payload (namespaces, keys with value LENGTH and `usedBy`, orphans, problems; backup config, `passwordSet`,
  archives, `lastRun`). Ordering is natural and case-insensitive (`secretsman_sort_store()`); a plain `ksort()`
  put every capital first. Only `reveal` ever returns a value, for one named key.
- The GUI functions in `src/secretsman.php` (`secretsman_default_store_path`, `secretsman_check_name`,
  `secretsman_save_store`, `secretsman_store_set`, `secretsman_store_delete`, `secretsman_scan_templates`) reuse the
  existing validators as the single source of truth. `secretsman_check_name()` round-trips through
  `secretsman_parse_token()` rather than holding a second copy of the `[A-Za-z0-9_.-]+` grammar.
  `secretsman_save_store()` validates the candidate by calling `secretsman_load_store()`, the exact function the
  resolver uses, before the atomic `rename()`.
- **A masked value is never sent to the client at all**: not a fragment, not a `data-value` attribute, nothing a
  "View Source" would catch. `list` and mutation JSON carry only a length. The *only* response that carries a
  plaintext value is `reveal`, for one explicitly named `ns/key` per request. Keep this true for any future page
  that touches the store. It is the easiest rule-3 violation to introduce by accident.
- **The GUI diagnoses a corrupt store; it never offers to repair or overwrite one.** A "reset store" button would be
  a one-click way to lose every secret over a missing comma.
- **No plugin-side CSRF check.** `local_prepend.php` validates and consumes the token, so a second check fails every
  legitimate request. This shipped once and was reverted. An upstream CSRF failure is a `200` with an empty body; a
  `403` can only come from the plugin's own code. Stock precedent: `ipmi/include/ipmi_config.php` has no CSRF code
  either. (Details: `unraid-webgui-plugin-pages`, "Requests: transport and CSRF".)
- `plugin/scripts/common.php`'s `secretsman_notify()` logs with `error_log()`, not `fwrite(STDERR, ...)`, because
  `backup_cron_register.php` includes it and is reachable from a web request.
- Both dispatchers (`store_api.php`, `backup_api.php`) catch `\Throwable` as well as `SecretsmanError`. They log the
  detail via `error_log()` and return a readable `Class: message` to the banner.

**Backup and restore (Phase 4):** it exists because `/mnt/user/appdata/.secrets/` is dotfile-prefixed and common
appdata-backup tooling skips it.
- `src/backup.php` (version-independent, tested like `patch.php`) handles archive create/verify/restore/prune.
  `plugin/scripts/backup_api.php` is the GUI backend: same shape as `store_api.php`, no CSRF check.
  `backup_download.php` streams downloads with `Content-Disposition` + `readfile()` and never stages the archive in
  the webroot. `backup_cron.php` is what cron runs. `backup_cron_register.php` regenerates the flash `.cron` from
  `backup-config.json` and calls `update_cron`, both on config save and from the `.plg` install block on every boot.
- **Archive format is detected from the file at restore time, never from what's installed locally.**
  - 7z (AES-256, encrypted headers) is used when present. It is **not stock Unraid**: it is on the host only via the
    third-party `zip_manager` plugin.
  - The guaranteed fallback is `tar` + `openssl enc -aes-256-cbc -pbkdf2`, both stock. This host's `openssl enc`
    refuses AEAD ciphers ("AEAD ciphers not supported", on 3.0.x and 3.5.x), and plain CBC has no integrity check.
    So an HMAC-SHA256 sidecar (independently keyed via PBKDF2 from the same password) is checked before every
    decrypt. This keeps the plain `openssl enc -d` one-liner working, which is the reason the fallback exists.
- **The archive password lives beside `store.json`** (`/mnt/user/appdata/.secrets/backup-password`, outside flash,
  `root:root 0600`). It is not in the store, because that is circular, and not on flash. Root can already read
  `store.json`, so this adds no exposure. The password protects the archive once it leaves the host. Non-sensitive
  config (destination, schedule, retention, last-run status) is on flash at `backup-config.json`.
- **Scheduling:** `/etc/cron.d` is wiped every boot. The flash `.cron` is regenerated from config every time, and
  no `event/disks_mounted` hook is needed (`update_cron` touches only `/boot/config` and `/etc/cron.d`). A real
  reboot verified this on 2026-08-26, including a post-reboot schedule actually firing.
- **The `.plg`'s remove block deregisters the cron entry *before* `rm -rf &plgPATH;`** deletes the config the
  registration depends on.
- **Restore has two modes.** *Replace* makes the archive the store (disaster recovery). *Merge* is add-only: a key
  present in both is left untouched and reported as a collision by name, never resolved silently in either
  direction. Both validate through `secretsman_load_store()` before touching the live store.
- **Restore transport:** the uploaded archive goes as a base64 field (`archive_b64`) in a normal urlencoded POST.
  The size ceiling is `secretsman_backup_upload_limit_bytes()` in `src/backup.php`. See the nginx STANDING NOTE
  below.
- **Client-side behaviours that must stay** (they replace the old jQuery page's `refreshTableOnly()`/`swal()`
  workarounds, which no longer exist):
  - Results are toasts with a word (Success/Error). Backup-now and restore results stay until dismissed, and a
    background reload never clears a toast. The backup settings form is seeded only by the first load, so a later
    payload never overwrites what the user is typing.
  - After a restore the secrets list reloads and revealed values from before it are dropped.
  - The destination field has a folder picker: the `browse` action of `backup_api.php`, confined to `/mnt` and `/boot`
    by `secretsman_backup_browse()` (realpath first, so `..` and symlinks cannot escape; directories only). Typing a
    path still works.

## Standing notes

These three are kept in full on purpose. Their generic forms are in `unraid-webgui-plugin-pages` ("PHP endpoints",
"Requests: transport and CSRF") and `unraid-stock-file-patching` ("Don't add a check on top of a platform
guarantee"), but each one here carries repo-specific obligations that the skills don't have. Where they say "the
correction above/below", that text is now in `docs/history.md`.

### STANDING NOTE — documenting a hazard is not the same as not reproducing it

During Phase 3 planning, this file already recorded that `secretsman_notify()`'s `STDERR`
fallback would fatal under php-fpm, named the exact mechanism, and deferred the fix on the
reasoning that nothing web-facing called it. That documentation did not stop the next piece of
code written — `backup_cron_register.php`, deliberately designed to run from both the CLI
install block and in-process from a web request — from making the identical mistake with its
own direct `fwrite(STDOUT/STDERR, ...)` calls. Knowing about a hazard in the abstract and
avoiding it while writing the next function that's actually exposed to it are different skills,
and only the second one prevents the bug. **Rule 8, above, is the response: a checklist to run
at the moment a function gains a second caller in a different execution context, not a fact to
have once noted and moved on from.**

This is also the *third* time a caller operating in a different execution context — web vs.
CLI, or a second enforcement layer running at a different point in the request lifecycle —
silently invalidated an assumption baked into code that had tested fine in its original
context: this `STDOUT`/`STDERR` bug, the redundant CSRF check (which read a `$_POST` field
after Unraid's own `auto_prepend_file` had already consumed it), and the `.plg` remove block's
`-x` check against a script that is never executed directly, only ever run via `php <path>`.
None of the three were caught by the test suite, because in each case the test suite's own
execution context (CLI, no real webGui request pipeline, no real `plugin remove` invocation)
was exactly the context in which the code was correct. **The pattern across all three: before
trusting that existing code still works after adding a new way to reach it, ask what's
different about how the new caller invokes it — not just whether the old caller still passes.**

**Audit performed after this fired (2026-08-25):** every `plugin/scripts/*.php` and everything
under `src/` checked for the same class — `STDOUT`/`STDERR`, `$argv`, `getcwd()`/`chdir()`,
`php_sapi_name()` branching, and the inverse (a CLI script assuming `$_POST`/`$_FILES`/
`REQUEST_METHOD`). `src/` is entirely clean. Two more functions had the identical shape
(direct `STDOUT`/`STDERR` writes, safe only because nothing currently calls them from a web
context): `backup_cron_main()` in `backup_cron.php`, and `apply_patch_main()`/`uninstall_main()`
in `apply_patch.php`/`uninstall.php`.

- **`backup_cron_main()` was fixed immediately, not left as a documented risk.** It was one
  duplication-driven refactor away from actually breaking: `backup_api.php`'s `backup_now`
  action independently re-implemented the same three steps instead of calling it, and
  resolving that duplication by wiring the natural direction (`backup_now` calls
  `backup_cron_main()`) would have reintroduced this exact bug. Converted to the same
  return-a-result shape as `backup_cron_register_main()`, then `backup_now` was made to call
  it — the duplication is gone *and* the trap it would have sprung is gone with it. Regression
  test in `tests/run.php` (`assert_function_avoids_cli_only_stdio()`, a shared helper — this
  is now the second function it guards) covers both.
- **`apply_patch_main()` (`apply_patch.php`) and `uninstall_main()` (`uninstall.php`) are
  deliberately left as they are.** Both still write directly to `STDOUT`/`STDERR`. This is a
  known, accepted shape, not an oversight: nothing in the current design has a plausible reason
  to call plugin install/removal logic from a web request, and changing code on the
  install/removal paths — freshly verified working end-to-end in step 7 — for a theoretical
  concern is a worse trade than leaving a low-probability latent issue alone. **If either of
  these ever gains a caller from a web-request script, it must be converted to the
  return-a-result shape FIRST, before that caller is wired in — not after, and not "since it
  probably still works."**

### STANDING NOTE — stop layering defensive checks on mechanisms you haven't fully verified

Twice now, a check added *in addition to* an Unraid-provided guarantee — believing it was
extra safety — was the actual cause of a failure, not a hedge against one: the `disks_mounted`
ordering assumption for `!secretfile` (relied on despite never being tested live — see the
dated correction below) and `store_api.php`'s redundant CSRF check (added "on top of" the
webGui's own enforcement, and could never succeed because that enforcement consumes the token
it was trying to re-check). Both were added out of caution, and both were the bug.
**When Unraid already provides a guarantee, the correct response is to rely on it and verify
the guarantee holds — not to add a second, independent mechanism alongside it.** A second
mechanism doubles the surface for a subtle interaction neither half's author fully modeled,
and "belt and braces" only works when the belt and the braces don't secretly share a buckle.
Before adding a check "just in case": find the real stock code path first (read the source,
as both corrections below eventually did), and confirm the case you're worried about is
actually possible given what that code does — don't guess at what a safety margin costs.

### STANDING NOTE — this host's nginx auth_request times out on multipart POST; don't build another file upload

`nginx.conf` runs `auth_request /auth-request.php;` globally, ahead of every request to any
plugin script. Confirmed live (`/var/log/nginx/error.log`): a `multipart/form-data` POST to
either `backup_api.php` or `store_api.php` — any script, any action, authenticated or not —
causes that internal subrequest itself to time out (`upstream timed out ... subrequest:
"/auth-request.php"`, then `auth request unexpected status: 504`), and nginx serves that to the
client as a failure before the plugin's own PHP ever runs. This is why restore's original
file-upload design (`multipart/form-data` via `fetch()`, needed only because jQuery's global
CSRF `$.ajaxPrefilter` can't touch a `FormData` body safely) failed in the browser twice — once
as total silence, once as a loud 500 paired with an unrelated `"POST required"` body from a
second, non-POST request racing it — while every CLI-simulated call to the same backend
function succeeded, because CLI invocation never goes through nginx or this subrequest at all.
**Plain urlencoded POST via `$.post` is the only proven-working transport on this host.**
Restore now sends an uploaded archive as a base64 field in a normal POST (`archive_b64`) rather
than a file, through the same path every other action already uses — see
`secretsman_backup_upload_limit_bytes()` in `src/backup.php` for the resulting size ceiling
(bounded by `post_max_size`/`memory_limit`, base64 inflation accounted for). **If a future
feature wants to upload a file through this webGui, this is why multipart won't work here and
base64-over-POST (or another transport that isn't multipart) is the starting point, not a
fallback to reach for after multipart fails again.**

The earlier curl-based reproduction of this exact hang (unconditional, unauthenticated, any
script) was found *before* the user's live 500 report, on a path about to ship, and was
initially treated as a possible dead end while a different symptom was chased — it wasn't; both
were the same bug. An unexplained hang on a path that's about to ship is itself a finding worth
chasing to ground, not a detail to park while looking for something louder.

## Second writer: homelab-access (`hl secret put`)

`store.json` has a writer outside this repo. The `hl` CLI (homelab-access) writes via this
plugin's own `secretsman_store_set()` under an `flock` on `store.json.lock`, leaving
`store.json.bak-hl-*` backups (newest 20 kept) beside the store. Two consequences:

- **Every writer takes the same lock (since 1.0.3).** `secretsman_with_store_lock($path, $fn)` in
  `src/secretsman.php` flocks `store.json.lock` (0600, 10 s then throws, reentrant per path within
  a process). `secretsman_store_set/_delete` (so the GUI's `store_api.php`) and
  `secretsman_backup_restore` (both modes) run their read-modify-write inside it. `hl secret put`
  checks `function_exists('secretsman_with_store_lock')` and, when present, runs its whole
  check→backup→set→read-back inside it and takes no lock of its own (output `lock=plugin`; `lock=hl`
  means the plugin is older than 1.0.3). Readers stay lock-free: the atomic rename gives them a
  whole file. Any new code path that writes `store.json` must go through the helper. The
  concurrency tests spawn real child processes; they prove CLI behaviour only (rule 8 still applies
  to anything web-reachable, and the helper uses no STDOUT/STDERR/`$argv`).
- `store.json.bak-*` files in `.secrets/` are not from this plugin's backup feature; the
  retention sweep in `src/backup.php` only matches `secretsman-backup*` names, so they don't
  interact. Don't "tidy" them.

Host access from this repo's sessions: `ssh unraid` (used by `scripts/install-on-host.sh`) and
`hl exec --endpoint unraid-api -- <cmd>` for the GraphQL API (`:81`). There is no `.env`.

## Usage convention: login passwords for new installs (household policy, 2026-11-02)

This is how the store is *used*, not something the plugin enforces. For any new container, plugin or app that needs a
**login** password (a person types it: admin account, web UI, user accounts), create the app's **own** key in its own
namespace (`<app>/admin_password`, or `<app>/<role>_password`) and set its **value by copying `All/default_user_password`**
(Kieren's memorable default; the `All` namespace is the household-wide default, not an app). Do not generate a random one.
The template references the per-app key (`!secret <app>/admin_password`), never `All/default_user_password` itself, so each
app's password can be reset individually while staying memorable. Copy with `hl secret put`'s own path, never printing or
argv: `hl exec --secret PW=All/default_user_password -- hl secret put <app>/admin_password --from-env PW` (`--replace` for an
existing key); confirm by comparing hashes in-process, print only match/no-match. Random generation stays correct for
**non-login** secrets: app secret keys, API tokens, DB passwords between containers, encryption keys. Worked example:
`paperless/` in unraid-ops.


## Test command

`php tests/run.php` — no framework, no fixtures beyond what's inline in the test file (plus
`tests/harness/fixtures/`, both throwaway/synthetic). All fixture values are obviously-fake
(`test-value-not-a-real-secret`, sentinel strings) — this repo is public; never put a real key or
real store contents in a commit, fixture, or example, even a throwaway one.

`tests/harness/` is a second, separate layer: `render_cmd.php` renders one template through one
Helpers.php variant by bootstrapping just enough of Unraid's own runtime (not the full webGui
request chain — see its header comment for why); `regression.php` orchestrates it across every
real template on a live host plus the committed fixture template, and is meant to be run ON that
host, read-only. It deliberately never prints full command content (real templates' real paths/
ports), only filenames and pass/fail — its own output is safe to paste anywhere, matching the
project's own premise.

A whole-repo review (2026-08-26) found that `tests/run.php` exercised every `src/` function but
never the web dispatchers themselves (`plugin/scripts/store_api.php`,
`plugin/scripts/backup_api.php`) — the actual code directly exposed to attacker-controlled POST
input, including the `restore` action's path-traversal guard on the client-supplied `selected`
filename. Closed with `run_web_script()`, a helper that runs a dispatcher as a real, separate
PHP process (both scripts declare top-level functions and call `exit()` unconditionally on
every response, so requiring either directly into the test process would redeclare functions on
the second test and kill the whole suite on the first `exit()`); env-var overrides
(`SECRETSMAN_STORE_PATH` etc.) give each subprocess an isolated scratch store/config, same
pattern already used for `src/` tests. New `web:`-prefixed tests cover: non-POST rejection,
`list` for both dispatchers, an unknown action, the oversized-POST-silently-empties-`$_POST`
case, and — the one that mattered most — a `selected=../secret-outside.txt` traversal attempt
against `backup_api.php restore`, asserting the file outside the configured destination is
never reached.

**Test PBKDF2 cost is overridden, deliberately.** `tests/run.php` sets `SECRETSMAN_BACKUP_OPENSSL_ITER_TEST_ONLY=1000`
once, globally, and every subprocess inherits it, including via `run_web_script()`. `secretsman_backup_openssl_iter()`
in `src/backup.php` falls back to the real `SECRETSMAN_BACKUP_OPENSSL_ITER` (600,000) when it is unset, and nothing
the shipped `.plg` or install scripts touch ever sets it. Without it, a run burned about 43 s of CPU on the shared host
and visibly slowed nginx/php-fpm (2026-08-26; `docs/history.md`, and `unraid-live-host-testing`, "Things this
environment does that surprise"). **If a future run does this again, find and remove the actual CPU cost. Don't reach
for `nice`/`ionice`.**

## Deploy and verify

Step-by-step release mechanics (worktree, PR, md5 from CI, merge, tag on Gitea, waiting for the mirror, watching the
release workflow, verifying assets) are in the `forge-release` skill. This section keeps what is specific to this repo.
The pre-2026-11-04 text is in `docs/history.md`.

**Forge (changed 2 Nov 2026): Gitea is primary, GitHub is a read-only push mirror.** Develop against
`https://git.kiztigs.com/kmbrimble/unraid-secretsman` (`origin`, gt credential helper; `gt`, not `gh`, for issues/PRs).
`github.com/kmbrimble/unraid-secretsman` receives every push to Gitea within about a minute. The push mirror is
`sync_on_commit: true`, `interval: 10m0s` (read from Gitea's `push_mirrors` API, 2026-11-04). It **must never be pushed
to directly**: the mirror force-syncs and prunes refs, so anything that exists only on GitHub is lost. GitHub has Issues,
Wiki, Projects, Discussions and PRs switched off. **Delivery is from Gitea (since 1.1.2, 14 Nov 2026; Kieren's decision: Gitea
delivers all his Unraid plugins, GitHub is for HACS add-ons only):** `pluginURL` is
`https://git.kiztigs.com/kmbrimble/unraid-secretsman/raw/branch/main/unraid-secretsman.plg` and the `.txz`/`.md5` come from the Gitea release.
The owner is public on Gitea (LAN-only behind Caddy's `@git_lan_only`), so no token is needed. Cost: plugin updates depend on the homelab
being up and on the host's `git.kiztigs.com` hosts entry (`/boot/config/go`).
`main` is protected on Gitea: changes go through a PR, both CI checks (`CI / php84 (pull_request)`,
`CI / php81 (pull_request)`) must be green, and butler-bot merges its own green PRs (no human approval).

**Releasing needs nobody's say-so, but it is two steps now.** Nothing writes back to the repo after the tag any more (a
push mirror would discard it), so the md5 goes in *before* tagging:

1. In the PR that bumps `&version;`, write the `###<version>` CHANGES entry and set `&md5;`. CI's release-readiness step
   (`php84` job, only when `&version;` is untagged) runs `scripts/release-gates.sh` (CHANGES entry, Rule 7, strcmp
   ordering) and then `scripts/check-md5.sh`, which builds the package and fails with `set &md5; to <value>`. Push again
   with that value, then merge.
2. Tag the merge commit on Gitea: `git tag v<version> && git push origin v<version>`. The tag runs
   `.gitea/workflows/release.yml`: the gates, lint and tests, then `scripts/gitea-release.sh` (shared by all plugin repos; canonical copy
   in unraid-flightplan) rebuilds, requires the same md5 and strcmp ordering, creates the **Gitea release** and attaches the `.txz` and
   `.md5`, then reads them back. Token: the job token (or Actions secret `RELEASE_TOKEN` if set). Its `workflow_dispatch` is a dry run
   by default, or with `tag` + `publish` recovers an existing tag whose run failed (tags never move). The GitHub copy has no release workflow any more (removed in the 15 Nov 2026 GitHub exit); Gitea is the only publisher.

Three things stay by hand, deliberately:

- **The CHANGES entry.** The gates refuse a version with no `###<version>` block, and its text is used verbatim as the
  release notes. A changelog is content, not mechanics. (This repo has no separate `CHANGELOG.md`; the `.plg`
  `<CHANGES>` block is the changelog.)
- **`&md5;` comes from CI's build, not from your head or your machine.** The plugin manager verifies the `.txz`
  against it and re-fetches on a mismatch. There is no second check in the `.plg`'s own install script (constraint 9),
  but a wrong value still breaks every install. **From PR #6 onwards, a local build and CI give the same md5**, because
  `build-plugin.sh` pins `xz -T1` (and normalises file modes). xz ≥ 5.6 compresses multi-threaded by default, which
  writes sizes into the block header: the claude-code container (xz 5.8) built `4285115b…` where Gitea CI built
  `960487e7…` from a byte-identical tar stream. `tests/run.php` guards it by building under different umask, TZ and
  `XZ_DEFAULTS` threading and requiring identical md5s. A tree without that pin (anything before #6, including `v1.0.5`)
  still builds differently locally. CI's printed value is still the one that goes in the PR.
- **Rule 7, the recovery drill.** It needs a real host and cannot run on a runner. What the gates *can* do, and do, is
  refuse to release: if `src/patch.php`, `plugin/scripts/apply_patch.php` or `plugin/scripts/uninstall.php` changed
  since the previous tag, the new version's CHANGES entry must contain a line starting `RECOVERY drill:` recording the
  outcome.

The Gitea-primary tag path is proven: `v1.1.0` (2026-11-13) went PR, CI-printed md5, tag on Gitea, mirror, GitHub
release workflow rebuild with the same md5, then `install-on-host.sh`. The md5 was identical from three builds (Gitea
CI, the claude-code container, the GitHub release run), including the Node/Vite UI build, again for `v1.1.1`.
`scripts/install-on-host.sh` calls `gh release view` on the GitHub mirror, which the `gh` shim's forge-guard refuses
for this repo; run it as `FORGE_GUARD=off bash scripts/install-on-host.sh <version>` (the guard's documented kill
switch). The release assets really are on GitHub, so that is the intended use.

`dist/` is gitignored: artifacts are release assets, not repo contents. `scripts/build-plugin.sh` runs locally for a
test build (`xz` is present in the claude-code container as of 2026-11-04), but see the md5 note above.

Gitea CI (`.gitea/workflows/ci.yml`) runs `php -l`, the UI unit/component tests and contrast check (`ui/`, Node pinned to `22.23.4`), and `php tests/run.php` on PHP 8.4 and 8.1 on every push and PR. The
GitHub release workflow repeats those checks rather than trusting a result it cannot see.

**Installing on the host is the one step CI cannot do.** GitHub's runners (and the Gitea runner VM) have no route to
192.168.0.10. `scripts/install-on-host.sh` does it from the claude-code container, and running it is part of releasing,
not a separate decision. It:
- confirms the release exists;
- waits until the raw `.plg`'s own `&version;` *and* `&md5;` match the published release;
- runs `plugin install <raw URL>` over SSH;
- then verifies the flash `.plg` version, the `/var/log/plugins` registration, the installed tree, **that `Helpers.php`
  actually carries the `SECRETSMAN-PATCH-BEGIN` marker**, and that the packaged README is still the stock-shaped
  description.

That patch check is the point. An install that lands its files but fails to patch looks identical to a good one until
a container fails to create. A failure there is a failed release, not a flaky script.

Currently released and installed on this host: **v1.1.1** (installed and checked 2026-11-13). The flash `.plg` shows
`1.1.1`, `/var/log/plugins/unraid-secretsman.plg` is registered, the flash `.txz` md5 is `5bf56712…` (the `&md5;`),
`Helpers.php` carries 1 `SECRETSMAN-PATCH-BEGIN` marker, and `/etc/unraid-version` is 7.3.4. Releases before 1.0.2 were
published but never installed (the host ran 2026.08.25 until 1.0.2, for the sidecar reason in constraint 9). Do not
trust `gh release list` as evidence of what is on the host; check `/boot/config/plugins/unraid-secretsman.plg`. The
live store is `/mnt/user/appdata/.secrets/store.json`, `root:root 0600`.

## Code review

For `code-diff-reviewer` / `code-audit` / `code-security-audit`. Verified live 2026-09-04.

**Exposure: LAN-only, behind the Unraid webGUI login.** `SecretsMan.page`, `app/` and everything under
`plugin/scripts/` is served by the host's own nginx, which binds `192.168.0.10:81`,
`192.168.66.10:81` and loopback only (`USE_SSL="no"` in `/boot/config/ident.cfg`, so :443 is
loopback-only). There is no tunnel route, no reverse-proxy route and no port forward reaching
it: `unraid.kiztigs.com` is NXDOMAIN at Cloudflare's own nameservers, and the single enabled
Nginx Proxy Manager entry for that name forwards to `172.17.0.1:81`, where nothing listens.
Unraid Connect remote access is unconfigured (`myservers.cfg` is 0 bytes) and the Tailscale
plugin sits in `plugins-removed`. Every request passes nginx's global
`auth_request /auth-request.php` — an unauthenticated GET to
`/plugins/unraid-secretsman/scripts/store_api.php` returns `302 → /login`. **Not
internet-facing; do not escalate on exposure alone.** The threat model this plugin actually
targets is a template or a `docker run` command line leaking a secret, not a remote attacker —
see rules 3 and 6.

**Modules that own data users rely on:**

- `secretsman_store_set()` / `secretsman_store_delete()` / the save path in
  `src/secretsman.php` — the only writers of `/mnt/user/appdata/.secrets/store.json`, which is
  the single copy of every secret on this host. Corrupt or truncate it and every container whose
  template carries a `!secret` token fails to create. Writes are tmp+rename with an explicit
  shape validation on load; keep both.
- `src/backup.php` — the only recovery path for that store: create (7z or openssl+HMAC sidecar),
  verify, restore, and the retention sweep, which `@unlink`s archives, their HMAC sidecars and
  READMEs. A bug in the sweep destroys the backups; a bug in restore overwrites the live store
  with the wrong contents.
- `plugin/scripts/backup_api.php` `case 'browse'` — lists directories for the folder picker; the `/mnt`+`/boot`
  confinement in `secretsman_backup_browse()` is the boundary (tested, including `..` and symlink escapes).
- `plugin/scripts/backup_api.php` `case 'restore'` — consumes an attacker-controlled `selected`
  filename and a base64 body. Its path-traversal guard is the boundary, and is the one web-layer
  behaviour with a dedicated test.
- `src/patch.php` with `plugin/scripts/apply_patch.php` and `plugin/scripts/uninstall.php` —
  these rewrite a **stock Unraid file**,
  `/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php`. A bad patch breaks
  Docker container creation host-wide, for every container, not just ones using secrets. The
  hash gate (rule 4) and `RECOVERY.md` exist for exactly this.
- `plugin/scripts/backup_cron_register.php` — writes
  `/boot/config/plugins/unraid-secretsman/unraid-secretsman.cron` on the flash drive, which
  persists across reboots.

**Infrastructure this repo depends on but does not contain.** A reviewer seeing "nothing in this
repo implements X" for any of these is wrong:

- **CSRF and authentication are enforced upstream** — `webGui/include/local_prepend.php`
  (Unraid's global `auto_prepend_file`) validates and then *consumes* the token, and nginx's
  `auth_request` gates the session. `store_api.php` and `backup_api.php` deliberately check
  neither; a second CSRF check would 403 every legitimate request. This is a known false
  positive, and it has already been made once.
- **`xmlToCommand()` in dockerMan's `Helpers.php`** — the function this plugin patches. The
  resolver's whole contract (rule 6: a resolved secret never enters `$cmd`) is about that
  function's behaviour, and the file is not in this repo. `reference/7.3.x/` holds a copy for
  hashing, not for execution.
- **`/usr/local/emhttp` is restored from the OS image on every boot**, which is why the patch is
  re-applied at boot rather than persisted. Code that looks like it re-does work needlessly is
  usually this.
- **The Unraid plugin manager** verifies the `.txz` against the `<MD5>` declared in the `.plg`.
- **`openssl` and `7z`/`tar` on the host** — `src/backup.php` shells out to them; the PBKDF2
  iteration count is real work done by `openssl`, not by PHP.
- **This host's nginx `auth_request` times out on any multipart POST** — see the standing note
  above. Base64-over-urlencoded is not a quirk of this code, it is the only transport that works
  here.

**Test reality.** `php tests/run.php` — no framework, all under the **CLI SAPI**. The count
moves with every merged PR, so read it from the summary line, not from here. For scale on
2026-11-04: `main` ran 137 in Gitea CI; PR #6 adds 1, PR #7 adds 5 and PR #8 adds 1 (144
with all three merged). Two of those are 7z backup tests that run only when a 7z binary is
present: CI has one, the claude-code container does not. Before #8 a local run silently
counts 2 fewer. From #8 on it prints `skipped: <name> (7z not installed)` and an
`N passed, N failed, N skipped` summary, and a skip fails the run under CI.

Well covered: `src/secretsman.php` (token grammar, store load and validation, field-type scope,
tmpfs materialisation, `secretsman_resolve()`), `src/patch.php` (apply/revert/verify, hash gate),
`src/backup.php` (create/verify/restore round-trips, retention), and
`plugin/scripts/apply_patch.php`, `uninstall.php`, `backup_cron_register.php`.

Thin or absent:

- **Web dispatchers (since 1.1.0):** `web:` subprocess tests cover `store_api.php` (non-POST, unknown action,
  `list` JSON shape and ordering, corrupt store, `add`/`edit`/`reveal`/`delete`, name validation, no value in any
  non-reveal response), `backup_api.php` (`list` shape, `config_set`, `backup_now` failure, restore traversal,
  invalid base64, oversized POST), `backup_download.php`, and `app/index.php` (token, theme, escaping). Still not
  covered at dispatcher level: a successful `backup_now` and a successful `restore` through `backup_api.php`.
- **The browser UI has Vitest unit tests (`ui/src/lib.test.ts`, `theme.test.ts`: validation, transport, error mapping,
  theme) and component tests (`Secrets.test.tsx`: collapse/expand, search restore, Copy NS+Key / Copy secret,
  delete icon, theme switch, add-opens-namespace), run in CI.** The click-through (add, copy, reveal, edit, delete) is a headless-Chromium script run by hand
  against the live host, not in CI.
- **The suite cannot catch a php-fpm-only defect** — it runs exclusively under CLI, where
  `STDOUT`/`STDERR`/`$argv` are all defined. This is rule 8, and it has already bitten three
  times.
- `tests/harness/regression.php` is a second layer that is **not run by CI** — it is manual,
  on-host, read-only, against real templates.

**A change to `plugin/scripts/*.php` or `SecretsMan.page` with no new `web:` test is the
strongest defect signal available in this repo.** Say so rather than assuming the `src/` tests
cover it.

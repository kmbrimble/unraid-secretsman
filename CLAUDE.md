# unraid-secretsman — CLAUDE.md

Central secret storage for Unraid Docker templates. See `README.md` for what this is and why;
this file is the design record and phase roadmap for whoever (human or Claude) picks this up
next.

## Non-negotiable design rules

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
   `reference/<version>/HASHES`. Never a whole-file replacement.
5. **Prefer leaving the system untouched over a partial patch.**
6. **Never in the command string.** Callers of `xmlToCommand()` echo the returned `$cmd`,
   including to the GUI. A resolved secret must never appear in that string — see "Insertion
   point" below for how the resolver avoids this structurally, not by scrubbing after the fact.
7. **Any change to the patch layer (Phase 2+) requires the recovery drill in `RECOVERY.md` to
   be re-run and confirmed working before that change ships.**
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

## Phase 0 recon (2026-08-24, read-only against the live host)

Host: Unraid 7.3.1, kernel 6.18.33, PHP 8.4.21.

- **The gate passed.** `@unraid/api` 4.34.0 (Connect / unraid-api stack) has no container-create
  GraphQL mutation. `DockerMutations` is `start, stop, pause, unpause, removeContainer,
  updateAutostartConfiguration, updateContainer, updateContainers, updateAllContainers` — no
  `createContainer`. The string `createContainer` appears nowhere in `/usr/local/unraid-api/dist`.
  `updateContainer` (the only re-create path) shells out to
  `scripts/update_container`, which is confirmed call site #5 below. **There is exactly one
  producer of the docker create command: `xmlToCommand()`.**
- **Single definition, confirmed:**
  `/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php:389`
  `function xmlToCommand($xml, $create_paths=false)` → returns `[$cmd, $Name, $Repository]`.
  The emhttp plugin directory is `dynamix.docker.manager`, NOT `dockerMan` — only the flash
  template path (`/boot/config/plugins/dockerMan/templates-user/`) kept the old name.
- **Five call sites, all covered by patching the single definition:**
  `dynamix/include/UpdateTwo.php:148`, `dynamix.docker.manager/include/CreateDocker.php:134`
  (GUI apply), `CreateDocker.php:254`, `scripts/rebuild_container:29`,
  `scripts/update_container:163`.
- **Insertion point:** one line, immediately before the `foreach ($xml['Config'] as $key =>
  $config)` loop (line 508 in the 7.3.1 copy in `reference/7.3.x/Helpers.php`). At that point
  Config values are still unescaped — exactly what the resolver needs — and mutating
  `$xml['Config']` / `$xml['ExtraParams']` there lets the *existing* loop do all the emitting:
  deleting a resolved `!secret` entry means no `-e` is ever generated for it; appending a
  synthetic `path`-type entry for `!secretfile` means the stock loop emits the bind mount with
  no new volume-handling code. This is why patching one line covers the security property for
  every caller without touching any of them.
- **Boot-time patch pattern:** `/etc/rc.d/rc.local` runs `/usr/local/sbin/plugin install
  "$PLUGIN"` for every `.plg` in `/boot/config/plugins` at boot — that's the re-patch hook, and
  it's also why "delete the `.plg`, reboot" is a complete recovery path. No plugin installed on
  the recon host does a checksum-guarded surgical patch of a stock emhttp PHP file (the closest
  local examples — `unassigned.devices`, `community.applications` — patch their own files or
  rename-aside a `.page` file, not a shared core file), so Phase 2 has no richer community
  precedent to copy; it follows the `.plg`-reinstall-at-boot mechanism with our own md5 gate on
  top.
- **Corrections to the original recon:**
  - Unraid's PHP has **no `yaml` extension** and none is installable in place — this is why the
    store is JSON, not YAML.
  - `/run` is tmpfs, 128 MB, mounted `noexec`. Fine for env-files and read-only bind-mount
    sources; worth remembering it's small.
  - **`!secretfile` bind-mount sources live in tmpfs and do not survive a reboot.** An
    autostarting container would bind-mount a path docker silently recreates as an empty
    directory. **Correction during Phase 2a: repopulation cannot live in the `.plg`'s `rc.local`
    boot block as originally assumed** — the store lives under `/mnt/user`, which isn't mounted
    that early. See "Array-start hook" below for the `disks_mounted` hook actually used, and why
    it's safe. This is still why `secretsman_resolve()` (Phase 1) is written to be idempotent
    when re-run for the same container/key — see the "idempotent" test in `tests/run.php` — since
    repopulation calls the same materialisation path a second time.

## CORRECTION — the array-start hook design below is DISPROVEN (2026-08-25, third Gate-2 reboot)

**Do not treat the "Array-start hook" section below as settled design.** It was derived entirely
by reading vendor source (Phase 2a recon), never by a live test — and the first live test broke
it. On the third Gate-2 reboot, `secretsman-smoketest`'s `!secretfile` bind source was still
missing when `rc.docker`'s autostart loop reached it: Docker auto-vivified the missing path as
an empty directory, and the container failed OCI init (mount type mismatch, exit 127) instead of
being held back. There is no syslog evidence either way for whether the `disks_mounted` hook
(`scripts/repopulate.php`) ran, errored, or lost a race — `emhttp_event` doesn't route
event-script output anywhere durable, so Phase 2a's ordering guarantee is **unfalsifiable by
inspection and now falsified by test.** Full incident and the resulting redesign plan are in
`docs/phase2-resume.md`'s "CORRECTION" section (top of file) — treat that as current design,
this section below as historical context for why the (now-disproven) choice was made.

**Separately, and independent of the above: `container_paths_exist()` (referenced below as the
enforcement mechanism) is confirmed insufficient for `!secretfile`.** It checks only existence,
not type — Docker's auto-vivification of a missing bind source as a directory defeats it
structurally. Deferring `!secretfile`'s fail-closed property to this native check was a Phase 2
design decision, and it was wrong.

**Net effect: `!secretfile` on an autostarting container was UNSAFE** — it could not guarantee
repopulation-before-autostart, and a missing/wrong-type source was not reliably held back.

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

## Array-start hook (Phase 2a recon, 2026-08-25, read-only against the live host) — SUPERSEDED, see correction above

The Phase 1 plan assumed `!secretfile` repopulation could live in the `.plg`'s `rc.local` boot
block. **That assumption was wrong** — the store lives at
`/mnt/user/appdata/.secrets/store.json`, which doesn't exist until the array starts, and
`rc.local` runs well before that. This needed a real Unraid event hook, found and verified as
follows, entirely by reading vendor scripts already on the host — no reboot, no live test:

- **The hook system:** `/usr/local/sbin/emhttp_event` (a real, readable shell script) is invoked
  by the compiled `emhttpd` daemon on each of a fixed set of named events, and for each one
  iterates `/usr/local/emhttp/plugins/*/event/<eventname>` — a plugin "registers" for an event
  simply by dropping an executable file at that path. Its own header comment states the full
  event order for array start: `starting → array_started → disks_mounted → svcs_restarted →
  docker_started → libvirt_started → started`, and warns that **`emhttpd` blocks on each event
  script until it completes** — this blocking property is what makes an ordering guarantee
  possible at all.
- **The hook to use is `disks_mounted`, not `docker_started`.** `docker_started` sounds like the
  natural choice for "before docker starts containers," but it is actively unsafe: reading
  `/etc/rc.d/rc.docker`'s `start)` case shows `docker_container_start &>/dev/null &` — the
  autostart loop is launched as an **explicitly backgrounded job**, while `docker_service_start`
  and `docker_network_start` run synchronously before it. `rc.docker start` therefore returns
  (and `docker_started` fires) right after that background job is *launched*, not after it
  finishes — a hook on `docker_started` races real container starts and can lose for early
  entries in `unraid-autostart`. `disks_mounted`, by contrast, fires and *fully completes*
  (blocking) before `svcs_restarted`, before `rc.docker start` is ever invoked, before
  `docker_container_start` is even launched — a strict happens-before guarantee, not a race.
- **`/var/lib/docker` (`$DOCKER_ROOT`) is already mounted by the time `disks_mounted` fires.**
  `rc.docker`'s `docker_service_start()` only *checks* `mountpoint $DOCKER_ROOT` and fails if it
  isn't — it never mounts it itself — confirming something upstream (the array's own disk-mount
  phase) already mounted it before docker's own startup code ever runs. `/var/lib/docker/
  unraid-autostart` (the container-name list `rc.docker` reads) is therefore already readable at
  `disks_mounted` time.
- **`rc.local`'s patch step has zero dependency on the array.** `rc.local` runs unconditionally
  during normal boot and ends by launching `/usr/local/sbin/emhttp`; array start (auto-triggered
  by config, or a manual click) is a *runtime action taken later by the now-running `emhttp`
  daemon*, entirely decoupled from `rc.local`. Confirmed by reading `rc.local` itself — nothing
  in it waits for or triggers an array start. So the Helpers.php patch applies every boot
  regardless of whether the array is ever started, exactly as Phase 1 assumed.
- **Corollary — the "block a container" mechanism doesn't need building.** `rc.docker`'s own
  autostart loop already calls `container_paths_exist()` before every `docker start`, which
  inspects `docker inspect --format='{{range .Mounts}}{{.Source}}|{{end}}'` and refuses to start
  a container if any bind-mount source is missing — logging it itself. Since a `!secretfile`
  entry is registered as an ordinary `Path`-type Config entry (Phase 1 design), this native
  check already "holds back" a container with an unrepopulated secret file, for free. Phase 2's
  `scripts/repopulate.php` therefore has exactly one job: win the race (guaranteed by the
  `disks_mounted` ordering above) and raise a clearer notification than the native syslog line —
  not implement blocking itself. See `plugin/scripts/repopulate.php`'s own header comment.

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

Not going into Community Applications. Self-hosted `.plg` installed by URL from
`raw.githubusercontent.com`, with a packaged `.txz` attached to a GitHub Release. The `.plg`'s
version entity and md5 are bumped per release via `scripts/build-plugin.sh`, which assembles
the installable tree (resolving the repo's `plugin/src` → `../src` symlink into real files —
see "Repo layout" below) and prints the exact next manual steps. **No release has been cut yet**
— see the Phase 2 roadmap entry above for why that's deliberate.

### Repo layout note

`plugin/src` and `plugin/reference` are symlinks to the top-level `src/` and `reference/` —
there is exactly one copy of each file at rest in the repo (avoiding drift between "the library"
and "what ships"), and `plugin/` on its own already matches the real installed layout
(`/usr/local/emhttp/plugins/unraid-secretsman/`) for local testing. `scripts/build-plugin.sh`
resolves the symlinks into real files when it packages a release, since a `.txz` has no business
shipping symlinks that only make sense inside this git checkout.

### Standing rule: verify the `.plg` install by actually running it, never by pre-placing files

A real Phase 2 Gate 2 boot failed because `scripts/build-plugin.sh` archived the package tree
rooted at `unraid-secretsman/...` instead of the real absolute destination
`usr/local/emhttp/plugins/unraid-secretsman/...` — Slackware's `upgradepkg` extracts relative to
`/`, not relative to any "plugins" convention, so it silently landed everything at
`/unraid-secretsman`. This went undetected through an earlier "successful" manual install
because the plugin tree had already been `scp`'d directly to the correct destination *before*
`plugin install` ran — `apply_patch.php` executed against those leftover manually-staged files
while `upgradepkg` mis-extracted the real package the whole time, unnoticed. **Pre-placing files
at the expected destination and then testing the script that's supposed to place them there
proves nothing about whether that script actually works.** Any future check of the `.plg`
install (or its remove path) must start from a clean slate — no plugin directory, no package
registry entry — and go through the real `upgradepkg`/`plugin install` mechanism end to end, the
same way a real boot would. See `docs/phase2-resume.md` for the full incident.

## Phase roadmap

- **Phase 0 — Gate (done, read-only).** Confirm the single-producer assumption; establish the
  insertion point; survey boot-time patch conventions. See recon above.
- **Phase 1 — Resolver library + scaffolding (this repo, current state).**
  `src/secretsman.php`: token parsing, store load/validate, tmpfs materialisation, the
  `secretsman_resolve()` entry point. `tests/run.php`: a plain-PHP assert runner (no
  Composer/PHPUnit — nothing here needs a framework), failing-first per `/feature` discipline.
  Fully isolated from the live Unraid install — no writes to `/usr/local/emhttp`, `/boot`, or
  the store location happen in this phase.
- **Phase 2 — Patch layer + `.plg` (in progress, this repo).**
  `src/patch.php`: the checksum-guarded, idempotent, marker-delimited injection/reversion at the
  confirmed insertion point (`secretsman_patch_apply`/`_revert`/`_verify`/`_apply_to_file`). The
  injected text is just a guarded `require_once` plus `secretsman_resolve($xml, $xml['Name']);`
  — mode-agnostic, so removing `!secretfile` (see the correction above) changed zero bytes of it.
  `plugin/`: the installed-plugin tree — `scripts/apply_patch.php` (boot-time patch, per-version
  hash lookup from bundled `reference/`), `scripts/uninstall.php` (clean revert on Remove). There
  is no `event/` directory and no boot-time repopulation script — those existed only for
  `!secretfile` and were deleted with it. `tests/harness/`: the staging harness (`render_cmd.php`
  + `regression.php`) that proved stock vs. patched byte-identical output across every real
  template on the recon host (48/48) plus a token-bearing fixture leaking nothing into `$cmd` —
  see the harness's own header comments for why it bootstraps `xmlToCommand()`'s dependencies
  manually rather than reusing the full webGui HTTP-request chain. `unraid-secretsman.plg` and
  `scripts/build-plugin.sh`: the installable artifact and its packaging step — **not yet
  released**; publishing a real GitHub Release is deliberately deferred until the live-system
  verification steps (applying to the real `Helpers.php`, then a real reboot) succeed. Shipping
  an installable plugin before that would let someone install a patch that's only been proven
  safe against copies.
  **Requires the `RECOVERY.md` drill to be run and confirmed working before it ships**, and
  again after every subsequent change to the patch layer. (Done for Phase 2, see
  `docs/phase2-resume.md`.)
  **Also required: a test asserting the resolved value appears nowhere in the command string
  dockerMan renders, or in the output it displays.** Delivered as
  `tests/harness/regression.php`'s "token-bearing fixture" check — it runs the real patched
  `xmlToCommand()` (via the staging harness bootstrap) against a fixture template and asserts
  the sentinel value from a fixture store appears nowhere in the returned `$cmd`. This runs
  against the patched function's real return value on the real host, not a mock of it.
  **Clean removal / revert-to-stock: VERIFIED (2026-08-25, step 7, pre-1.0.0).** Real
  `/usr/local/sbin/plugin remove unraid-secretsman.plg` against a live, currently-installed,
  currently-patched host: `Helpers.php` returned to the exact stock hash
  `9a45421b387b733ad260e204308baa69` (0 markers), the installed tree, staged package, package
  DB entry, and plugin-manager registry all cleanly gone, the `.plg` correctly landed in
  `/boot/config/plugins-removed/`, `store.json` was byte-identical before and after, a
  container already using `!secret` (`terrible-butler`) kept running with no restart, and a
  fresh Apply against a template holding a `!secret` token — genuinely stock now — passed the
  literal token through as an inert env-var value with no crash, exactly as expected once the
  plugin is actually gone. This caught a real, previously-unverified bug on the way: the
  remove block gated the revert on `[[ -x scripts/uninstall.php ]]`, but that script has no
  shebang and is invoked as `php <path>`, so it's packaged 0644 and the check was always
  false — the revert silently never ran. Invisible by accident, because a reboot restores
  stock `Helpers.php` anyway (rule 4), so "removal worked" looked true either way; found only
  by actually reading the removal path before trusting it, not by the drill happening to pass.
  Fixed to `-f`, regression-tested in `tests/run.php`. Re-run this drill after any future
  change to `uninstall.php`, the `.plg`'s remove block, or `secretsman_patch_revert()`.
- **Phase 3 — Settings GUI (this repo, current state).** Prompted by a real incident: a
  hand-edited `store.json` with a missing comma and a trailing comma produced a completely
  blank Docker "Update Container" page with no visible error. Two shipped files:
  `plugin/SecretsMan.page` (registered at Settings → User Utilities, `launch="Settings/SecretsMan"`
  — Unraid's `.page` loader globs `plugins/*/*.page` non-recursively, so the file must sit in
  the plugin's installed root, not a subdirectory) is a static shell with no store access at
  all; `plugin/scripts/store_api.php` is the only PHP that ever touches the store from the GUI,
  and the only HTML renderer for it — the page ships an empty table and calls `action=list` on
  load, so there is exactly one render path for the initial paint and every post-mutation
  repaint. `src/secretsman.php` gained six functions for this
  (`secretsman_default_store_path`, `secretsman_check_name`, `secretsman_save_store`,
  `secretsman_store_set`, `secretsman_store_delete`, `secretsman_scan_templates`), all
  reusing existing validators as the single source of truth rather than restating them:
  `secretsman_check_name()` validates a namespace/key pair by round-tripping through
  `secretsman_parse_token()` itself (not a second copy of the `[A-Za-z0-9_.-]+` grammar), and
  `secretsman_save_store()` (the project's first store *writer*) validates a candidate store by
  calling `secretsman_load_store()` on it — the exact function the resolver uses at
  container-create time — before the atomic `rename()`, so every shape/newline/permission rule
  is enforced identically for the GUI and the resolver with nothing duplicated.
  **Standing rule this phase established: a masked value is never sent to the client at all**
  — not a fragment, not a `data-value` attribute, nothing a "View Source" would catch. The
  `list`/mutation JSON carries only a length; the *only* response that ever carries a plaintext
  value is `reveal`, for one explicitly named `ns/key` per request. Keep this true for any
  future page touching the store — it's the easiest rule-3 violation to introduce by accident
  (e.g. "just put the value in a hidden attribute for JS to use later").
  **The GUI diagnoses a corrupt store; it never offers to repair or overwrite one** — a
  "reset store" button would be a one-click way to lose every secret over a missing comma, and
  the actual gap this phase closes is that nothing *told* the user about the comma, not that
  fixing it needed to be one click.
  Does not touch `src/patch.php`, the `Helpers.php` insertion point, or
  `secretsman_resolve()`'s behavior (one pure line-extraction refactor aside). The
  `RECOVERY.md` drill was not re-run for this phase — rule 7 scopes it to the patch layer,
  which this phase never touches — but the clean-slate install re-verification standing rule
  above **was** re-applied, since `scripts/build-plugin.sh` and the `.plg` both changed.
  **Fixed pre-emptively, after the same bug class fired for real elsewhere (see the STANDING
  NOTE below):** `plugin/scripts/common.php`'s `secretsman_notify()` used to fall back to
  `fwrite(STDERR, ...)` when the notify binary isn't executable — `STDERR` is undefined under
  php-fpm. It was flagged as a known-but-theoretical issue for a full phase, on the reasoning
  that `store_api.php`/`backup_api.php` never include `common.php`. That reasoning stopped
  being airtight the moment `backup_cron_register.php` (which *does* include `common.php`)
  became reachable from `backup_api.php`'s web request — and, separately, that exact class of
  bug (a direct `fwrite(STDOUT/STDERR, ...)` call inside `backup_cron_register.php` itself)
  did fire live, producing a bare 500 the instant a real user saved backup settings. Switched
  to `error_log()`, closing the gap before it fired through this specific path too rather than
  waiting for a second live incident to force it.
  **CSRF: do not add a plugin-side check.** `webGui/include/local_prepend.php` (the global
  `auto_prepend_file`) already enforces CSRF on every POST reaching any plugin PHP file — and,
  critically, it **consumes the token** (`unset($_POST['csrf_token'])`) immediately after
  validating it. A second check in plugin code reads an already-emptied field and fails on
  every legitimate request; this shipped once in `store_api.php` and had to be reverted (see
  the standing note below). The recognisable signature if this class of bug recurs: Unraid's
  own `csrf_terminate()` calls `exit` **without ever setting an HTTP status code**, so an
  upstream CSRF failure looks like a `200` with an empty body (a JSON-parse error client-side),
  never a `403` — a `403` from this endpoint can only be coming from the plugin's own code.
  Stock precedent confirms the fix: `ipmi/include/ipmi_config.php` has no CSRF-checking code
  of its own at all, and that's correct, not an oversight.
- **Phase 4 — Backup & restore (this repo, current state), before 1.0.0.** Prompted by a real
  gap: `/mnt/user/appdata/.secrets/` is dotfile-prefixed, which common appdata-backup tooling
  routinely skips, so the store had no copy anywhere. `src/backup.php` (version-independent,
  testable the same way `patch.php` is) does archive create/verify/restore/prune;
  `plugin/scripts/backup_api.php` (GUI backend, same shape as `store_api.php` — no CSRF check,
  for the same reason), `backup_download.php` (streaming download, `Content-Disposition` +
  `readfile()`, never stages the archive in the web-servable webroot), `backup_cron.php` (the
  script cron actually runs), and `backup_cron_register.php` (regenerates the flash `.cron`
  file from `backup-config.json` and calls `update_cron` — called both on config save and from
  the `.plg`'s install block on every boot).
  **Archive format is detected from the file at restore time, never from what's installed
  locally** — an archive made where 7z was available may need restoring where it isn't.
  7z (AES-256, encrypted headers) is used when present; it is confirmed **not stock Unraid**
  (only present via the third-party `zip_manager` plugin on the recon host) — the guaranteed
  fallback is `tar` + `openssl enc -aes-256-cbc -pbkdf2`, both stock. That fallback needed a
  fix beyond what was planned: **this host's `openssl enc` refuses AEAD ciphers outright**
  ("AEAD ciphers not supported", confirmed on both 3.0.x and 3.5.x), so GCM was not an option,
  and plain CBC has no integrity check — confirmed live by a test that flipped a byte and
  found decryption could still "succeed" with garbled output undetected outside the payload
  region. Fixed with an HMAC-SHA256 sidecar (independently keyed via PBKDF2 from the same
  password) checked before every decrypt attempt, rather than changing the ciphertext format
  and breaking the plain `openssl enc -d` one-liner the fallback exists to keep working.
  **The archive password lives beside `store.json`** (`/mnt/user/appdata/.secrets/backup-password`,
  outside flash, `root:root 0600`) — not in the store itself (circular: you'd need the store to
  restore the store) and not on flash (would put the one key that decrypts every secret
  exactly where the whole plugin exists to keep secrets out of). Documented plainly, not
  papered over: root on this host can already read `store.json` directly, so this adds no new
  exposure — the password's actual job is protecting the archive once it leaves the host.
  Non-sensitive config (destination, schedule, retention, last-run status) lives on flash at
  `backup-config.json`, same posture as every other piece of this plugin's config.
  **Scheduling verified, not assumed:** `/etc/cron.d` is Unraid's RAM rootfs, wiped every
  boot — confirmed live (`rootfs on / type rootfs`). Only the flash `.cron` file persists.
  `appdata.backup`'s own changelog documents losing exactly this ("Scheduling got lost after
  reboot") from trusting a stale file instead of regenerating it; this plugin copies its
  "regenerate from config every time, never trust the stored artifact" fix. No
  `event/disks_mounted` hook was added for this — `update_cron` only touches `/boot/config`
  and `/etc/cron.d`, neither needing the array mounted, so the `.plg`'s existing every-boot
  install block is sufficient on its own (see the standing note below on not layering a second
  mechanism where one already-verified one covers it).
  Restore is two modes, not one: **replace** (the archive becomes the store, disaster
  recovery) and **merge** (add-only; a key present in both is left untouched and reported as a
  collision by name, never silently resolved in either direction). Both validate through
  `secretsman_load_store()` before ever touching the live store — the same validator the
  resolver itself uses, nothing re-implemented.
  Does not touch `src/patch.php`, the `Helpers.php` insertion point, or `secretsman_resolve()`.
  The `RECOVERY.md` drill was not re-run — this phase touches no stock OS file — but the
  clean-slate install/remove re-verification standing rule was, since `build-plugin.sh` and
  the `.plg` both changed again; the `.plg`'s remove block now deregisters the cron entry
  *before* `rm -rf &plgPATH;` deletes the flash config that registration depends on, flagged
  specifically since a forgotten deregistration step is exactly the kind of thing that's
  silently lost (same category as the `-x`/`-f` bug step 7 caught).
  **Live incident after shipping:** `backup_cron_register.php` was deliberately written to be
  callable from two contexts — the CLI install block, and in-process from `backup_api.php`'s
  web request when a schedule is saved — but its `fwrite(STDOUT/STDERR, ...)` calls (fine in
  the CLI context it was written and tested against) fatal with "Undefined constant STDOUT"
  the moment they execute under php-fpm, which has no such constants. The result was a bare 500
  with no JSON body the instant a real user saved backup settings. Fixed by having the function
  return a result instead of printing, with only the CLI-only invocation guard doing any
  output; see the STANDING NOTE below for why the test suite couldn't have caught this and
  what to do differently. Also fixed in the same pass, from the same incident report: the
  dispatcher in both `backup_api.php` and `store_api.php` only caught `SecretsmanError`, so
  this (and any other uncaught `\Throwable`) reached the client as an empty 500 the page could
  only describe as "check the browser console" — the second time this project has told a user
  to go look elsewhere instead of saying what broke (the blank Docker Apply page was the
  first). Both now catch `\Throwable` too, logging full detail via `error_log()` (safe under
  php-fpm) and returning a readable `Class: message` to the banner.

  **Restore, verified end-to-end in the real browser (2026-08-26), after three rounds of
  browser-only failures the CLI/backend testing above could never have caught:** the fix was
  the multipart/`auth_request` bug in the nginx STANDING NOTE above — restore now sends an
  uploaded archive as a base64 field over plain POST, same transport as every other action.
  That fix surfaced two more real, purely client-side bugs, both now fixed and both confirmed
  live: (1) the result banner (merge/replace counts) never appeared because `doRestore()`
  triggered a second, concurrent table-refresh request that shared `banner()`'s
  unconditional-clear-on-response behavior — whichever response landed second wiped the other's
  message; fixed with a `refreshTableOnly()` that never touches the banner, used only from the
  restore path. (2) Even after that fix, the result popup could still be silently dropped by a
  SweetAlert v1 quirk: calling `swal()` again before a *previous* `swal()`'s close animation
  finishes can no-op — restore is the only action that chains a confirmation swal into a result
  swal, so it's the only one exposed to this; fixed with a 400ms delay between confirm and the
  restore actually starting. `banner()` (shared by every action, not just backup/restore) was
  also changed from a static `.notice` div — Unraid's stock styling for that class is a fixed
  warning-triangle look, identical for success and failure — to `swal()`'s built-in
  success/error types (green tick / red cross), with a same-page `alert()` fallback if `swal()`
  itself ever throws, so a result can't go unreported a fourth time. Separately, the Destination
  field's Browse button was overflowing off the page edge — same stock-CSS
  `input`/`select { width: 100% }` cause as the earlier password/schedule-row bugs — fixed with
  an inline-flex wrapper so the text input fills the remaining space instead of the full row.

## STANDING NOTE — documenting a hazard is not the same as not reproducing it

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

## STANDING NOTE — stop layering defensive checks on mechanisms you haven't fully verified

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

## STANDING NOTE — this host's nginx auth_request times out on multipart POST; don't build another file upload

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

## Testing

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

**Live incident (2026-08-26): running `tests/run.php` measurably degraded the host's own
network responsiveness while it ran.** This assistant runs in a container on the same physical
Unraid host it develops against, sharing the same CPU pool with no cgroup limit
(`cpu.max` = `max`) — so CPU-bound work in this container competes directly with `nginx`/
`php-fpm`/the array for the same cores, no different from load generated any other way on the
box. The suite's dozens of backup create/verify/restore round-trips were each doing real
`openssl enc -pbkdf2 -iter 600000` subprocess calls plus a matching `hash_pbkdf2()` for the HMAC
sidecar key — production-strength PBKDF2, at full cost, every time, just to prove functional
correctness (wrong password rejected, tampering detected) that doesn't need brute-force
resistance to demonstrate. Measured: ~43.5s of near-continuous CPU across a 48.5s run. Fixed by
making the iteration count overridable — `secretsman_backup_openssl_iter()` in `src/backup.php`
reads `SECRETSMAN_BACKUP_OPENSSL_ITER_TEST_ONLY`, falling back to the real
`SECRETSMAN_BACKUP_OPENSSL_ITER` (600,000) constant when unset; `tests/run.php` sets it to 1000
once, globally, for the whole run (inherited by every subprocess it spawns, including via
`run_web_script()`). Nothing the shipped `.plg` or install scripts touch ever sets this env var,
so a real backup always gets the full count regardless. Result: 48.5s → 4.9s wall,
43.5s → 0.27s CPU, same 125/125 passing. **If a future test run causes this again, don't reach
for `nice`/`ionice` as the fix — find and remove the actual CPU cost, the way this one was.**

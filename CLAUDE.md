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

**Net effect: `!secretfile` on an autostarting container is currently UNSAFE** — it cannot
guarantee repopulation-before-autostart, and a missing/wrong-type source is not reliably held
back. This must be stated plainly in `README.md`'s SECURITY section. `!secret` (env-file,
resolved at container-create time in the GUI, not at boot) is unaffected.

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
  confirmed insertion point (`secretsman_patch_apply`/`_revert`/`_verify`/`_apply_to_file`).
  `plugin/`: the installed-plugin tree — `scripts/apply_patch.php` (boot-time patch, per-version
  hash lookup from bundled `reference/`), `scripts/repopulate.php` (the `disks_mounted` hook
  logic above), `scripts/force_start.php` (the recovery override, see `RECOVERY.md`),
  `scripts/uninstall.php` (clean revert on Remove), `event/disks_mounted` (the registration
  file itself). `tests/harness/`: the staging harness (`render_cmd.php` + `regression.php`) that
  proved stock vs. patched byte-identical output across every real template on the recon host
  (48/48) plus a token-bearing fixture leaking nothing into `$cmd` — see the harness's own
  header comments for why it bootstraps `xmlToCommand()`'s dependencies manually rather than
  reusing the full webGui HTTP-request chain. `unraid-secretsman.plg` and
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
  **Clean removal / revert-to-stock is UNVERIFIED and deliberately DEFERRED, not skipped.**
  `Helpers.php` patching, boot-time re-patch, and `!secretfile` repopulation timing have all
  been proven live on the real host (see `docs/phase2-resume.md`) — but the `.plg`'s
  `Method="remove"` block and `scripts/uninstall.php` have never once been run against the live
  install, because the plugin works and stays installed. Do not assume that path works just
  because the install path does; it needs the same real-mechanism verification (clean slate,
  actual `plugin remove`, confirm stock hash returns) before it can be trusted, whenever there's
  an actual reason to remove it.
- **Phase 3 (not yet scoped) — GUI page** for managing the store (add/edit/remove secrets)
  without hand-editing `store.json` over SSH.

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

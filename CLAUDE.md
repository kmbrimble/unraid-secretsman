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
    directory. Phase 2 must repopulate `/run/secretsman/files/` from the `.plg` boot block
    *before* docker autostart runs. This is why `secretsman_resolve()` (Phase 1) is written to
    be idempotent when re-run for the same container/key — see the "idempotent" test in
    `tests/run.php`.

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

## Shipping model (Phase 2 — not built yet)

Not going into Community Applications. Self-hosted `.plg` installed by URL from
`raw.githubusercontent.com`, with a packaged `.txz` attached to a GitHub Release. The `.plg`'s
version entity and md5 are bumped per release.

## Phase roadmap

- **Phase 0 — Gate (done, read-only).** Confirm the single-producer assumption; establish the
  insertion point; survey boot-time patch conventions. See recon above.
- **Phase 1 — Resolver library + scaffolding (this repo, current state).**
  `src/secretsman.php`: token parsing, store load/validate, tmpfs materialisation, the
  `secretsman_resolve()` entry point. `tests/run.php`: a plain-PHP assert runner (no
  Composer/PHPUnit — nothing here needs a framework), failing-first per `/feature` discipline.
  Fully isolated from the live Unraid install — no writes to `/usr/local/emhttp`, `/boot`, or
  the store location happen in this phase.
- **Phase 2 — Patch layer + `.plg`.** The checksum-guarded injection into `Helpers.php` at the
  confirmed insertion point; the boot-time repopulation of `/run/secretsman/files/` before
  docker autostart; the `.plg` itself, packaging, and the GitHub Release shipping model above.
  **Requires the `RECOVERY.md` drill to be run and confirmed working before it ships**, and
  again after every subsequent change to the patch layer.
- **Phase 3 (not yet scoped) — GUI page** for managing the store (add/edit/remove secrets)
  without hand-editing `store.json` over SSH.

## Testing

`php tests/run.php` — no framework, no fixtures beyond what's inline in the test file. All
fixture values are obviously-fake (`test-value-not-a-real-secret`, sentinel strings) — this repo
is public; never put a real key or real store contents in a commit, fixture, or example, even a
throwaway one.

# Phase 2 resume brief — GATE 2

Written after steps 1–5 all passed on the live host, and after the plugin was genuinely
installed (persistently, from locally-staged artifacts — no public release). **This is the
handoff for you to reboot from, in a fresh session.** Read this fully before you reboot; it's
written to be followable if things go sideways, not just as a changelog.

## Current live-system state, as of this writing

- `/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php` is **patched**.
  `md5 cdb8204eb82b489d24ecabf906f858ac` (stock was `9a45421b387b733ad260e204308baa69`,
  unchanged from Phase 0/Gate 1 — no drift). `php -l` clean.
- The plugin is **installed for real**, via the actual `.plg` mechanism
  (`/usr/local/sbin/plugin install`), not just manually staged:
  - `/boot/config/plugins/unraid-secretsman.plg` — the installed `.plg`, persistent on flash.
  - `/boot/config/plugins/unraid-secretsman/unraid-secretsman-2026.08.25.txz` and
    `unraid-secretsman.md5` — the staged package, persistent on flash.
  - `/usr/local/emhttp/plugins/unraid-secretsman/` — the unpacked tree (`src/`, `scripts/`,
    `event/`, `reference/`), **not persistent** — this is what a reboot will re-derive from the
    flash-staged `.plg`, which is exactly what Gate 2 is testing.
  - `/var/log/plugins/unraid-secretsman.plg` — a symlink back to the flash `.plg`, meaning
    Unraid's own plugin manager considers this a normal, successfully-installed plugin.
- **This is a local-only install, not a public release.** The `.plg`'s `<FILE>` block for the
  `.txz` still has a `<URL>` pointing at a GitHub Release that doesn't exist — but Unraid's
  installer (`dynamix.plugin.manager/scripts/plugin`, the `"skipping: $name already exists"`
  path) never reaches that URL because the target file is already present on flash with a
  matching `<MD5>`. **The download path has never been exercised.** Don't assume it works until
  a real release is cut and tried — that's a separate, later, explicitly-confirmed decision, not
  something to infer from this install having succeeded.
- No throwaway test artifacts remain: the Gate-1 live-verification container, its template, and
  its store entry were all created and cleaned up during step 4/5 verification (see below) —
  nothing was left behind. `/mnt/user/appdata/.secrets/` exists (0700, root), currently empty —
  the real store location, ready for real use, holding nothing yet.
- **Two bugs were found and fixed live**, before the real install succeeded — see "What went
  wrong" below. Both are now covered by regression tests.

## What's been verified (steps 3–5, with evidence)

**Step 3 — applied to the live file.** Hash re-verified immediately before writing (still
`9a45421b387b733ad260e204308baa69`, matching Gate 1's brief exactly — zero drift across the
whole session). `secretsman_patch_apply_to_file()` wrote the patch; re-ran the full
`tests/harness/regression.php` sweep against the **actual live file** afterward (not a copy):
```
byte-identical check: 48 templates checked, 48 identical, 0 mismatches, 0 errors, 0 token-bearing skipped
token-bearing fixture check: PASS (no resolved value appears in $cmd)
```

**Step 4 — a real throwaway container, through the real code path.** Built `secretsman-
liveverify` (alpine, `sleep 3600`), with a `!secret` token in its one Variable field, a
throwaway store entry with a random sentinel value. Rendered via a script that requires the
**live patched** `dynamix.docker.manager/include/Helpers.php` exactly as `CreateDocker.php`
does, then calls `xmlToCommand($templatePath, true)` — the identical function, identical file,
identical `create_paths=true` real-creation mode the GUI uses; not a mock. (Went this route
instead of scripting an authenticated browser session against the webGUI's CSRF-protected form
— same code path either way, without needing to script real login credentials over HTTP.)
- **Grepped `$cmd` for the sentinel programmatically: absent.** The rendered command was:
  ```
  docker create --name='secretsman-liveverify' --net='bridge' --pids-limit 8192 \
    -e TZ="Australia/Brisbane" -e HOST_OS="Unraid" -e HOST_HOSTNAME="unRAID" \
    -e HOST_CONTAINERNAME="secretsman-liveverify" -l net.unraid.docker.managed=dockerman \
    --env-file=<redacted-path> 'alpine' sleep 3600
  ```
  No `-e THROWAWAY_SECRET=...` fragment — the env-file indirection worked exactly as designed.
- Then actually created it via `execCommand($cmd, false)` — the same function
  `CreateDocker.php` calls at its own line 226 for a real (non-dry-run) creation.
- Started it, then **`docker exec secretsman-liveverify printenv THROWAWAY_SECRET`** — matched
  the sentinel exactly. The container genuinely received the value; "clean command" and
  "app gets its secret" are separate properties, and both held.
- Confirmed `docker inspect` **does** show the value in `Config.Env` — expected, matches the
  documented SECURITY caveat in README.md, not a bug.
- Cleaned up: container stopped+removed, template deleted, store entry deleted, `/run/
  secretsman/` deleted.

**Step 5 — force-updated a real, already-running, unrelated container.** Picked **Unpackerr**
(a background extraction-automation utility with no DNS/proxy/user-facing duty — you said
"relaxed about breaking for ten minutes," this is about as low-stakes as the stack gets). Ran
the actual `scripts/update_container` script — **this is confirmed call site #5**, the exact
path `unraid-api`'s `updateContainer` GraphQL mutation shells out to (see Phase 0 recon) — via
`php update_container "Unpackerr"`, unmodified, with the live patched `Helpers.php` in effect.
- Container ID changed (`e1b193a7...` → `f5189fd0...`) confirming a genuine stop+remove+recreate,
  not a no-op.
- Same env var count before/after (22), fresh `docker logs` showing clean startup and successful
  reconnection to Sonarr/Radarr. No regression.

## Native fail-closed behaviour — read this before you go looking for a "blocking" feature

**secretsman does not implement its own "hold back a container" mechanism, on purpose.**
Unraid's own `/etc/rc.d/rc.docker`, in its autostart loop, already calls `container_paths_exist()`
before every `docker start` — which runs `docker inspect --format='{{range .Mounts}}{{.Source}}|
{{end}}'` and refuses to start (leaving the container simply stopped) if any bind-mount source
is missing. Since a `!secretfile` entry is registered as an ordinary `Path`-type Config entry,
this native check already covers our case for free. **It also logs it itself** — you'll find a
line like:
```
container "NAME" hostpath "/run/secretsman/files/NAME/key" does not exist
```
in the system log (`log` function output, same place all of rc.docker's own logging goes) —
**that's Unraid's own message, not a secretsman feature.** `scripts/repopulate.php`'s only job
is to win the race (guaranteed by the `disks_mounted` hook timing — see CLAUDE.md "Array-start
hook") and raise a clearer, secretsman-specific notification on top (naming the container and
the missing `ns/key`, not just an opaque tmpfs path) — not to implement the holding-back itself.
If a container doesn't come back after reboot and you go looking for "why did secretsman block
this," check the syslog line above and `RECOVERY.md`'s force-start section, not a secretsman
config file — there isn't one for this.

## What went wrong (both fixed, both now regression-tested)

Two bugs surfaced only when the `.plg` was actually installed for real — neither was, or could
have been, caught by the harness, since the harness never exercises `.plg` XML itself:

1. **A literal `<name>` inside a plain-English comment inside an `<INLINE>` bash block** (the
   comment was explaining the emhttp_event dispatch convention) was parsed by libxml as an
   unclosed XML tag, silently corrupting the rest of the document. Unraid's installer reported
   only an opaque `"XML file doesn't exist or xml parse error"` with no line number — reproduced
   locally with `simplexml_load_file()` + `libxml_get_errors()`, which does give a line number.
   Fixed by rewording the comment to avoid literal angle brackets (CDATA isn't an option here —
   it would stop `&entity;` substitution, which the whole `.plg` templating convention depends
   on).
2. **`<Inline>` (wrong case) instead of `<INLINE>`** for the `.md5` file's content-writing block.
   The installer's SimpleXML property access (`$file->INLINE`) is case-sensitive; the wrong-case
   element parsed as valid XML but was silently never read, so the `.md5` file was never
   written, and the install script's own `cat &plgPATH;/&name;.md5` failed with "No such file."
3. Both are now covered by two new tests in `tests/run.php` ("unraid-secretsman.plg is
   well-formed XML" and the INLINE-case check) so this class of bug can't silently reappear.

**Takeaway for future changes to the `.plg`:** always validate with
`simplexml_load_file() + libxml_get_errors()` (or just `php tests/run.php`) before staging it
anywhere, and prefer running an actual local install (as done here) over trusting that "the XML
looks right" — Unraid's installer's own error reporting for a malformed `.plg` is not helpful
enough to debug from alone.

## Exact commands to undo, right now, without a reboot

Same as Gate 1's brief, still accurate — `secretsman_patch_revert()` round-trips to
byte-identical stock (tested), and the standard `.plg` remove flow now also actually works
(verified installed, not just written):
```sh
# Preferred — cleanly reverts Helpers.php AND removes the plugin via the standard mechanism:
/usr/local/sbin/plugin remove /boot/config/plugins/unraid-secretsman.plg
# (runs plugin/scripts/uninstall.php via the .plg's Method="remove" block, then removes
#  /usr/local/emhttp/plugins/unraid-secretsman and /boot/config/plugins/unraid-secretsman)

# Or just the patch, leaving the plugin files in place:
php /usr/local/emhttp/plugins/unraid-secretsman/scripts/uninstall.php
```

## What the reboot is expected to prove

Three things, in this order:

1. **The patch re-applies at boot.** `rc.local` reinstalls every `.plg` in
   `/boot/config/plugins/*.plg` on every boot (see CLAUDE.md "Boot-time patch pattern") — this
   runs our `.plg`'s install block again, which re-runs `apply_patch.php`. Since the file will
   already be patched (unless something restored the stock image, which reboots don't do to
   `/boot`), the expected outcome is `"secretsman: Helpers.php already patched, no-op"` — same
   idempotent behaviour already verified live, now proven to actually fire from `rc.local`
   rather than from me running it by hand.
2. **`!secretfile` sources repopulate before docker autostart** — though as of this writing
   there are **no real containers configured with a `!secretfile` token** (the throwaway from
   step 4 was cleaned up), so this specific claim has **no live container to prove it against
   yet**. What the reboot *can* still confirm: `event/disks_mounted` actually fires (check the
   log line below) and exits cleanly with nothing to do. Proving actual repopulation timing
   needs a real `!secretfile`-using container configured first — a good next step after this
   reboot, in a later session, not part of this one.
3. **Containers come up.** Ordinary autostart, unaffected by any of this — Unpackerr and every
   other autostart container should return exactly as they would without this plugin installed,
   since the patch is a no-op for every token-free template (proven repeatedly above).

## Verification commands, in order, after the reboot

Run these over SSH once the box is back up. Each is read-only except where noted.

```sh
# 1. Patch survived / re-applied correctly
md5sum /usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php
#   expect: cdb8204eb82b489d24ecabf906f858ac
grep -c SECRETSMAN-PATCH-BEGIN /usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php
#   expect: 1
php -l /usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php
#   expect: No syntax errors detected

# 2. The plugin tree came back (it does NOT persist on its own — this proves rc.local
#    actually re-ran the install, not that emhttp happened to survive)
ls /usr/local/emhttp/plugins/unraid-secretsman/
find /usr/local/emhttp/plugins/unraid-secretsman -type f | wc -l
#   expect: 10 (same file count as the pre-reboot tree)

# 3. Evidence the install script actually ran this boot (not stale from before)
#    — check dmesg/syslog for the "secretsman:" lines rc.local's plugin install produces,
#    with a timestamp AFTER the reboot:
grep secretsman /var/log/syslog | tail -20
#   expect to see "secretsman: Helpers.php already patched, no-op" and
#   "secretsman: install complete" with post-reboot timestamps

# 4. disks_mounted fired
grep -i "disks_mounted\|secretsman" /var/log/syslog | tail -30
#   there's no repopulate.php-specific log line when there's nothing to repopulate (see
#   "no live container to prove it against yet" above) — absence of an error here is the
#   signal, not a specific success line. If you see a secretsman notification in the GUI
#   (bell icon) about a held-back container or a store problem, investigate before trusting
#   anything else in this list.

# 5. Ordinary containers are fine
docker ps --format '{{.Names}}\t{{.Status}}'
#   compare against your normal expectation — nothing here should look different from any
#   other reboot. Unpackerr in particular (touched in step 5) should be Up.

# 6. The plugin is still registered normally
ls -la /var/log/plugins/ | grep secretsman
#   expect: symlink to /boot/config/plugins/unraid-secretsman.plg, same as any other plugin
```

## If the reboot goes badly

Written assuming you're stressed and something's down. Work top to bottom; stop as soon as
you're back to a working state — you don't need to run every remaining check.

**If the webGUI doesn't come back at all:**
This is very unlikely to be caused by this plugin — the patch only affects `xmlToCommand()`,
which nothing calls during boot itself (only at container create/update time), and the `.plg`'s
install block has no early-boot dependency (see CLAUDE.md "rc.local's patch step has zero
dependency on the array"). But if it doesn't:
1. Wait a genuinely full boot cycle (a few minutes) — Unraid's first boot after installing a new
   plugin can be slower than usual as things get re-verified.
2. If still nothing: this is the scenario `RECOVERY.md` § "If the webGUI is unusable" is written
   for — pull the flash drive into another machine, delete `/boot/config/plugins/
   unraid-secretsman.plg` (and optionally the `unraid-secretsman/` directory next to it),
   reboot again. `Helpers.php` comes back stock from the OS image regardless, since
   `/usr/local/emhttp` is never persisted — this isn't a "hope it works" step, it's mechanically
   guaranteed by how Unraid boots.

**If the webGUI comes back but containers don't start:**
1. Check `docker ps -a` — are they `Created`/`Exited` rather than `Up`? That's very likely
   ordinary Unraid behaviour unrelated to this plugin (autostart order, dependent services not
   ready yet) — compare against what you'd expect on any normal reboot before assuming this
   plugin caused it.
2. Check the live hash (command 1 above). If it does **not** match `cdb8204eb82b489d24ecabf906f858ac`
   and does not match the stock `9a45421b387b733ad260e204308baa69` either, something unexpected
   changed the file — stop here, don't reinstall or patch again, and investigate what wrote to
   it before doing anything further.
3. If the hash is right but something still seems off, remove the plugin
   (`/usr/local/sbin/plugin remove /boot/config/plugins/unraid-secretsman.plg`, no reboot
   needed — this reverts `Helpers.php` immediately) and see if the problem persists. If it does,
   it wasn't this plugin.

**If container creation/update itself errors after the reboot** (i.e., you try to add or update
a container and get an error mentioning "secretsman"):
- `"resolver library missing at ..."` — the plugin directory didn't come back
  (`/usr/local/emhttp/plugins/unraid-secretsman/src/secretsman.php` missing). Re-run
  `/usr/local/sbin/plugin install /boot/config/plugins/unraid-secretsman.plg` by hand; if that
  also fails, remove the plugin (see above) to get back to a working, unpatched state, and
  investigate from there.
- Any other `secretsman:` error on a template with **no `!secret`/`!secretfile` token in it** —
  this should be impossible given the byte-identical regression evidence above, and would be a
  real bug. Save the exact error text (it will not contain a secret value, by design — safe to
  paste anywhere) and stop; don't keep retrying.
- A `secretsman:` error on a template that legitimately uses a token — that's very likely the
  fail-closed design working as intended (bad token, missing store, wrong permissions). The
  error names the shape of the problem, never a value.

## Not yet done — still deliberately deferred

- No public GitHub Release. Confirmed above: the download path in the `.plg` has never been
  exercised. Cutting a real release is a separate, later, explicitly-confirmed action.
- No `!secretfile`-using container exists yet to prove repopulation timing against a real
  autostart entry — worth setting up in a follow-up session once this reboot's basic result is
  in hand.
- Step 6 (verify per this brief) and step 7 (remove the plugin, second reboot, confirm clean
  stock return) both wait for you, in fresh sessions, per the original task's Gate 2 structure.

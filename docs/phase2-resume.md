# Phase 2 resume brief — PAUSED pending a live data-loss investigation unrelated to secretsman

## STATUS AS OF THE SECOND GATE-2 REBOOT (2026-08-25) — read this section only

**Boot-time re-patch: PROVEN.** `.plg` reinstalled cleanly this boot (`Helpers.php patched
(was 9a45421b387b733ad260e204308baa69)`), live hash confirmed `cdb8204eb82b489d24ecabf906f858ac`,
1 marker, `plugins-error/` clean. This is banked — do not re-verify it again unless the patch
layer itself changes.

**`!secretfile` repopulation timing: still UNPROVEN.** `secretsman-smoketest` did not survive
this boot to be checked (see incident below) — there's nothing left to test the resolver
against, same as the first reboot, for an unrelated reason this time.

**Phase 2 is paused.** Do not resume roadmap work (step 7, or anything else) until the
investigation below concludes. This is not a secretsman bug — see next section — but it makes
the host too unstable to trust for further live testing right now.

---

## ACTIVE INVESTIGATION — live container-directory disappearance, unrelated to secretsman

This reboot reproduced the same `RWLayer of container ... is unexpectedly nil` signature as the
first Gate-2 reboot, but this time **RAM-DISK-Dockerlog was already removed** and
`/var/lib/docker/containers` is confirmed real btrfs, not tmpfs — so that explanation is ruled
out for this round. Four containers hit the nil-RWLayer error during dockerd's own network-state
restore, 1 second after the daemon started and *before* `rc.docker` even begins starting
containers: **ESPHome, Overseerr, Immich-Kiosk, Lidarr** (confirmed by reading each container's
`config.v2.json` `Name` field directly off disk). Overseerr and Lidarr recovered and are running
fine — proving the error itself is a transient dockerd race, not reliably fatal. ESPHome (never
autostarts, wasn't even attempted) and Immich-Kiosk (started, then died, `Exited (128)` with a
stale "6 months ago" timestamp) did not recover.

**`secretsman-smoketest` is a separate, worse phenomenon.** It never appears anywhere in syslog
(no network-connect line, no start attempt, no error) and is *not* one of the four nil-RWLayer
containers. Its on-disk container directory
(`/var/lib/docker/containers/a338ea5bbaea764b3f040ce771c0c0e256599329098f47b0a6501fe87fd573c4`)
was confirmed present on the real btrfs filesystem after boot, mtime ~10:57 — then **disappeared
entirely between two consecutive read-only checks seconds apart, with zero corresponding syslog
entry.** This was watched happening live, not inferred after the fact.

**Why there's no log entry — the boring, confirmed explanation:** `dockerd` runs with
`--log-opt max-size=10m --log-opt max-file=2 --log-level=fatal`. Anything below fatal severity —
which would include a routine internal cleanup/reconciliation message — is never written,
independent of any exotic cause. This alone explains the silence; it doesn't by itself explain
the deletion.

**Near causes checked and ruled out:**
- *Our own diagnostics*: every command run during the investigation was read-only
  (`md5sum`/`grep`/`ls`/`cat`/`find`/`ps`/`tail`/`mount`/`findmnt`/`stat`); all throwaway
  containers used `--rm`, which only removes that container's own record on exit. **Cannot be
  fully ruled out as a trigger**, though: the investigation's own `ls -lt`/`grep -rl` scan read
  every container directory including `secretsman-smoketest`'s, seconds before it vanished — if
  dockerd/containerd reconciles-on-touch for a directory it can't map to a live record, that scan
  is a plausible proximate trigger even though it issued no delete itself.
- *Scheduled tasks*: root's crontab is stock (hourly/daily/weekly/monthly `run-parts` only).
  `ca.mover.tuning` runs monthly (`0 0 1 * *`, not this day) and only invokes `mover start`
  (cache↔array moves, not the docker store). `appdata.cleanup.plus` targets `/mnt/user/appdata/*`
  by design, a different tree from `/var/lib/docker/containers`. The only User Script that
  touches docker at all, `delete_dangling_images` (`docker rmi` on dangling *images*, not
  containers), is scheduled **disabled**. `butler-proxynet-autoconnect` (runs on boot) only
  watches `docker events` for `terrible-butler` starts and does a network connect — unrelated.
  Nothing scheduled on this host touches the container store.
- *dockerd/containerd auto-prune of unreconcilable metadata*: plausible and not yet ruled out —
  this is the leading hypothesis (the four nil-RWLayer containers are exactly the population that
  would hit such a path) but hasn't been confirmed, since `--log-level=fatal` hides it and
  `auditctl` isn't available on this host (Unraid's stock kernel doesn't ship the Linux audit
  subsystem enabled here) for PID-level attribution without installing new host packages, which
  hasn't been done pending explicit go-ahead.

**Live monitoring in place:** a standalone container, `secretsman-fswatch` (plain alpine +
inotify-tools, **not** privileged, **not** `--pid=host` — just a read-only bind mount of
`/var/lib/docker/containers` plus a writable log destination), runs `inotifywait -m -r` for
`delete,moved_from,delete_self,attrib,create` across the whole tree, logging timestamped events
to `/mnt/user/appdata/claude-code/config/secretsman-fswatch.log`. Restart policy
`unless-stopped`. It gives **what happened, when, and to which path** — not which process. Check
that log file first before anything else next session.

**Caveat on anything the watcher catches:** the investigation's own directory-wide `ls`/`grep`
scan touched `secretsman-smoketest`'s `config.v2.json` seconds before it vanished, and that
can't be excluded as a proximate trigger (see below). `secretsman-fswatch` itself does the same
kind of read — `inotifywait -r` has to stat the whole tree to establish watches, and its `-e`
mask includes `create`, which will also fire for its own future watch-list operations. If
dockerd reconciles-on-touch, the watcher's own presence is a candidate cause for anything it
logs, not just a neutral observer. Read any hit in that log with that in mind, not as clean
proof of an external actor.

**auditd: intentionally not installed.** Decision made explicitly: no new host packages, no
privileged containers beyond what's already run, while this investigation is open.

**Prepared, NOT applied — dockerd log-level change for the step 7 removal reboot:**
Unraid's stock `/etc/rc.d/rc.docker` hardcodes quiet logging, unconditionally, not driven by
`docker.cfg`:
```
line ~127-128:
  # Less verbose logging by default
  DOCKER_OPTS="--log-level=fatal $DOCKER_OPTS"
```
To get the daemon itself to say what it did (better evidence than PID attribution — the actual
actor, not just a bystander log), change `fatal` to `info` on that line before the next
`rc.docker start`. This is a stock OS file restored from the image at every boot (same category
as `Helpers.php`, rule 4) — editing it live needs no plugin and no persistence work, and reverts
for free on the next natural reboot without doing anything. To revert without a reboot, just
change `info` back to `fatal` on that line before any subsequent `rc.docker start`. Do this at
the step 7 removal reboot, since docker is stopping anyway then — not before, per the standing
"never stop the Docker service" constraint.

**Evidence preserved, do not touch:** ESPHome, Immich-Kiosk, Overseerr, and Lidarr are left
exactly as the reboot left them (not restarted, not recreated, not removed) — they're the only
surviving evidence of the nil-RWLayer side of this. `secretsman-smoketest`'s own evidence is
already gone; nothing to preserve there.

**What resuming Phase 2 requires:** this investigation to reach a real conclusion (ideally
catching the `secretsman-fswatch` log recording another disappearance, or a deliberate decision
to install `auditd` for PID attribution), not just "it hasn't happened again since."

---

## Superseded below — round-3 install completion detail (still accurate, kept for reference)

Since the round-2 update further down, the `.plg` install bug was found, fixed, and the full
install completed successfully end-to-end on the live host. **This is genuinely ready for you
to reboot from.** The round-2 section below is kept for its still-accurate detail (the
`RAM-DISK-Dockerlog` removal, the incident analysis) but its "not yet root-caused" and
"plugin tree absent" claims are now stale — this section supersedes them.

**The `.plg` bug, found by actually running the real mechanism (not by inspection):**
`scripts/build-plugin.sh` archived the package tree rooted at `unraid-secretsman/...` instead
of the real absolute destination `usr/local/emhttp/plugins/unraid-secretsman/...`. Slackware's
`upgradepkg` extracts relative to `/`, not relative to any "plugins" convention, so this landed
everything at `/unraid-secretsman` instead — confirmed by reproducing the exact failure live:
`php` on the (correct, but then-nonexistent) expected path printed `Could not open input file`
and exited 1, matching `rc.local: plugin: run failed: '/bin/bash' returned 1` byte-for-byte.
Fixed in `scripts/build-plugin.sh`; regression-tested in `tests/run.php` (runs the real
packaging script, inspects the real archive — confirmed to fail against the old script and
pass against the fix). See CLAUDE.md for the standing rule this added: the `.plg` install must
always be exercised through the genuine packaging + `upgradepkg` path, never verified by
pre-placing files at the destination — that's exactly what produced a false "it works" result
earlier in this phase.

**Full install completed end-to-end on the live host, for real, just now:**
1. Stock hash re-verified immediately before the write: `9a45421b387b733ad260e204308baa69` —
   unchanged, no drift, across the entire phase.
2. `/usr/local/sbin/plugin install /boot/config/plugins/unraid-secretsman.plg` run for real.
   Output: `secretsman: Helpers.php patched (was 9a45421b387b733ad260e204308baa69)` /
   `secretsman: install complete` / `plugin: unraid-secretsman.plg installed`. Exit 0.
3. Patched hash confirmed: `cdb8204eb82b489d24ecabf906f858ac`, exactly one
   `SECRETSMAN-PATCH-BEGIN` marker, `php -l` clean.
4. **The full 48-template regression harness re-run against the actual live patched file**
   (same bar as the original step 3): `48 templates checked, 48 identical, 0 mismatches, 0
   errors`; token-bearing fixture check `PASS`.
5. Plugin registration confirmed normal: `/var/log/plugins/unraid-secretsman.plg` symlinks to
   the flash `.plg`; `/boot/config/plugins-error/` is clean, nothing quarantined.

**Exact persistent flash paths** (so the next boot's `upgradepkg --install-new` skips the
download exactly as designed — these are the paths to check if anything looks wrong):
```
/boot/config/plugins/unraid-secretsman.plg                          (the installed .plg)
/boot/config/plugins/unraid-secretsman/unraid-secretsman-2026.08.25.txz   (md5 db9634bc355245df0a0f86cd44a96944)
/boot/config/plugins/unraid-secretsman/unraid-secretsman.md5              (content: db9634bc355245df0a0f86cd44a96944)
```
All three hash-consistent, confirmed together immediately before the install ran.

**`secretsman-smoketest` recreated, autostart re-enabled, verified pre-reboot exactly as
before:** store entry rewritten atomically at `/mnt/user/appdata/.secrets/store.json`; template
resaved at `/boot/config/plugins/dockerMan/templates-user/my-secretsman-smoketest.xml`;
container created via the same live `xmlToCommand()` path (sentinel confirmed absent from
`$cmd`); started; bind-mount source at `/run/secretsman/files/secretsman-smoketest/
throwaway-key` mode `0400`; `docker exec secretsman-smoketest cat /run/secrets/throwaway-key`
→ exact match `smoketest-value-not-a-real-secret`. Appended to `/var/lib/docker/
unraid-autostart` (original backed up to `unraid-autostart.pre-smoketest.bak` first, same
pattern as before). **This reboot will test repopulation timing, not just the re-patch.**

**Full pre-reboot state, confirmed together in one pass immediately before writing this:**
```
Helpers.php:           patched, md5 cdb8204eb82b489d24ecabf906f858ac, 1 marker, php -l clean
Plugin registration:   /var/log/plugins/unraid-secretsman.plg -> flash .plg, normal
plugins-error/:        clean
secretsman-smoketest:  Up, autostart enabled, verified working (see above)
RAM-DISK-Dockerlog:    still fully absent (rc.docker/monitor.php clean, confirmed again)
Radarr/TimeMachine/Unpackerr: still Up, healthy, unaffected by this round's work
```

**What's still unproven, honestly:** everything above is the *pre-boot* state. Boot-time
re-patch idempotency and `!secretfile` repopulation timing are both **ready to be tested, not
yet proven** — that's what your reboot is for. See "What the reboot is expected to prove" and
the verification command sections further down (still accurate for the retry), plus "The
reboot smoketest" section for the repopulation-specific checks, and the dedicated
"Post-reboot check: RAM-DISK-Dockerlog removal" section, still separate as before since two
independent changes are in play on this boot (RAM-Disk removal taking effect, and the re-patch
retry).

---

## Round 2 update (superseded above, kept for the still-accurate incident detail below)

## UPDATE — the first Gate 2 reboot already happened. Read this before anything below.

The sections below this point were written *before* that reboot and describe the state as it
stood then. They're kept for their evidence (steps 3–5 are still valid, still true) but **the
"current state" they describe is stale — do not act on it without reading this update first.**

**What the first reboot actually showed:**
1. **The `.plg` install failed this boot** — `rc.local: plugin: run failed: '/bin/bash'
   returned 1` right after the package extracted. `Helpers.php` came back **stock**
   (`9a45421b387b733ad260e204308baa69`), the plugin tree never landed at
   `/usr/local/emhttp/plugins/unraid-secretsman/`, and the `.plg` was quarantined to
   `/boot/config/plugins-error/`. **Boot-time re-patch was NOT proven this round — the plugin
   never installed, so nothing was there to prove it.**
2. **`!secretfile` repopulation timing was NOT proven either**, for the same reason —
   `disks_mounted` never had a resolver to run, since the plugin install failed before
   `apply_patch.php` (or anything after it) ran. `secretsman-smoketest` itself didn't survive
   the reboot at all (see below) — even if the plugin had installed, there'd have been nothing
   left to repopulate.
3. **An unrelated pre-existing plugin (`RAM-DISK-Dockerlog`) caused real damage**, exposed by
   an unclean shutdown: it kept `/var/lib/docker/containers` on tmpfs and only committed to
   real storage via a periodic sync that turned out to be badly stale (~6 hours) plus a
   clean-shutdown sync that never got a chance to run. Three real containers
   (Radarr, TimeMachine, Unpackerr) came back with corrupted containerd state
   (`RWLayer of container ... is unexpectedly nil`); `secretsman-smoketest` didn't come back
   at all — it was the most recently created container of the session, least likely to have
   been captured by any stale sync. **None of this was caused by secretsman** — the patch
   never applied this boot, and `repopulate.php`/`common.php` make exactly one `exec()` call in
   total, to Unraid's own notify script, never to `docker` — this was investigated and ruled
   out structurally, not just by absence of evidence.

**What's been done about it, all before this document was updated:**
- Radarr, TimeMachine, and Unpackerr were recreated from their saved templates (via the real
  `scripts/update_container` path) and started — verified healthy, new container IDs, clean
  logs.
- `secretsman-smoketest` and all its residue (template, store entry, `/run/secretsman`
  remnant, `unraid-autostart` entry, backup file) were removed — it was disposable and is
  gone.
- **`RAM-DISK-Dockerlog` has been fully removed** — see "RAM-DISK-Dockerlog removal" below for
  the full mechanism, why it mattered, and exactly what was done. `/var/lib/docker/containers`
  is *currently* still the live tmpfs mount (untouched, still backing running containers —
  disrupting it live would need stopping the docker service, which is out of scope for this
  session), but every future boot/docker-restart will find no trace of the plugin and will use
  the real, already-freshened btrfs directory directly. **Docker's native log rotation
  (`max-size=10m`, `max-file=2`) was already active independently — confirmed via
  `docker inspect`, nothing needed configuring to replace the plugin's benefit.**
- The `.plg` install bug itself is **not yet root-caused** — Unraid's installer only captures
  the script's stdout, not stderr, so the actual failure is still unknown. This is the next
  thing to investigate, before any second attempt to patch the live file.

**What this means for the next reboot:** it will carry **two independent changes** at once —
the `RAM-DISK-Dockerlog` removal taking effect (`/var/lib/docker/containers` should come up as
a real btrfs directory, no tmpfs) **and**, once the `.plg` bug is found and fixed, a retried
boot-time re-patch test. If something goes wrong on that boot, **both are in play** — don't
assume it's one or the other without checking both independently. See "Post-reboot check:
RAM-DISK-Dockerlog removal" below, kept deliberately separate from the secretsman verification
steps for exactly this reason.

**Current confirmed live state, as of this update:**
```
Helpers.php:        stock, md5 9a45421b387b733ad260e204308baa69
Plugin tree:         absent (/usr/local/emhttp/plugins/unraid-secretsman does not exist)
.plg on flash:        quarantined at /boot/config/plugins-error/unraid-secretsman.plg — NOT
                       reinstalled yet. The .txz/.md5 are still staged at
                       /boot/config/plugins/unraid-secretsman/ (untouched, still valid).
                       Reinstall waits until the install-script bug is found and fixed.
secretsman-smoketest: fully removed, no residue
RAM-DISK-Dockerlog:    fully removed (package, plugin tree, flash .plg, rc.docker patches,
                       monitor.php include — all gone; verified)
/var/lib/docker/containers: still live tmpfs (untouched), real underlying btrfs directory
                       freshened with current data for all 40 running/present containers
Docker log rotation:  active (max-size=10m, max-file=2), independent of any plugin
```

---

## RAM-DISK-Dockerlog removal — full detail

**Why it mattered enough to remove:** the plugin kept `/var/lib/docker/containers` on tmpfs,
committing to real (btrfs) storage only via a clean-shutdown sync (skipped on this unclean
reboot) and a periodic sync found to be ~6 hours stale — and separately, buggy: the interval
check `(date("i") * date("H") * 60 + date("i")) % $sync_interval_minutes` collapses to firing
only at the top of each hour regardless of the configured interval (at `minute=0` the whole
product is `0`, and `0 % anything` is always `0`). For the 60-minute default configured here
that's coincidentally close to intended, but it's still a real bug, and doesn't explain the
observed 6-hour gap on its own — `/var/log` is tmpfs and was wiped by the reboot, so the exact
history from the prior (78-day-uptime) session is unrecoverable. Independent of both bugs: even
a perfectly-working periodic sync only bounds staleness to the interval — it can never protect
against the final window before an unclean shutdown, which is exactly what happened.

**Why `plugin remove` alone wasn't enough:** the plugin's own `.plg` says outright —
*"Please stop your array once to fully remove the modification"*. Its `event/stopped` hook (the
actual `rc.docker` revert) only fires on an array stop; `plugin remove` just uninstalls the
package. Stopping the array or the docker service is the hard constraint for this whole phase,
so the plugin's own intended removal path wasn't available.

**What was done instead, in order, all verified safe (docker never stopped, live tmpfs never
touched):**
1. `mount --bind /var/lib/docker /var/lib/docker_bind` — a plain (non-recursive) bind mount of
   the *parent* doesn't carry the tmpfs submount with it, so this revealed the real underlying
   btrfs directory underneath, still holding stale pre-incident data (confirmed: it still had
   Unpackerr's old pre-force-update container ID; different `stat -f` filesystem ID from the
   live tmpfs — genuinely two different mounts, not an alias).
2. `rsync -aH --delete /var/lib/docker/containers/ /var/lib/docker_bind/containers` — the exact
   technique the plugin's own periodic sync already used, so nothing new or unproven. Copied
   live tmpfs content onto the real directory.
3. **Verified before unmounting the bind helper:** every one of the 40 containers in
   `docker ps -a` at that moment had a config directory on the real (bind-mounted) side; Radarr,
   TimeMachine, and Unpackerr specifically had their **new** (post-recreation) container IDs
   with readable `config.v2.json`, not the stale pre-incident ones; the stale pre-incident
   Unpackerr ID was gone (rsync `--delete` pruned it correctly).
4. `umount /var/lib/docker_bind` — clean, not lazy, no error.
5. Backed up and edited `/etc/rc.d/rc.docker`: removed both sed-inserted blocks (`# move
   json/logs to RAM-Disk` ... `logger -t docker RAM-Disk created`, and `# backup json/logs and
   remove RAM-Disk` ... `logger -t docker RAM-Disk removed`) using the exact same `sed` range
   markers the plugin's own `event/stopped` script uses. `diff` against the pre-edit backup
   showed only those two blocks removed, nothing else; `bash -n` syntax-checked clean.
6. Removed the `include_once('/tmp/RAM-DISK-Dockerlog/monitor');` line from
   `/usr/local/emhttp/plugins/dynamix/scripts/monitor` — single-line diff, `php -l` clean.
7. `removepkg RAM-DISK-Dockerlog-2026.04.22-x86_64-1`; removed
   `/usr/local/emhttp/plugins/RAM-DISK-Dockerlog`, `/boot/config/plugins/RAM-DISK-Dockerlog/`,
   `/boot/config/plugins/RAM-DISK-Dockerlog.plg`, `/var/log/plugins/RAM-DISK-Dockerlog.plg`,
   `/tmp/RAM-DISK-Dockerlog/`. Confirmed: no `RAM-Disk`/`RAM-DISK` string survives anywhere in
   `rc.docker` or `monitor`; nothing left on flash; package registry clean.

**Deliberately NOT done:** the live tmpfs mount itself was left exactly as it was — still
mounted, still backing the four currently-running-through-this-work containers checked
(Radarr, TimeMachine, Unpackerr, Sonarr all still `Up`, undisturbed throughout). Swapping the
mount live would need stopping docker. It doesn't need to happen live — the real underlying
directory is already fresh, and nothing will ever recreate the tmpfs mount again, so the swap
completes safely and automatically at whatever the next boot or docker restart turns out to be.

**Docker's native log rotation was already active independently**, confirmed via
`docker inspect Sonarr --format '{{json .HostConfig.LogConfig}}'` →
`{"max-file":"2","max-size":"10m"}`, driven by `docker.cfg`'s `DOCKER_LOG_ROTATION="yes"` /
`DOCKER_LOG_SIZE="10m"` / `DOCKER_LOG_FILES="2"` being turned into `--log-opt` flags by
`rc.docker` at daemon start. Nothing needed configuring — the SSD-write concern the plugin was
solving is already handled, with no durability trade.

## Post-reboot check: RAM-DISK-Dockerlog removal (run this SEPARATELY from the secretsman checks below)

Deliberately kept apart from "Verification commands" and "The smoketest itself" further down —
this is a different change with a different failure mode, and conflating the two would make it
harder to tell which of the two independent changes a problem belongs to.

```sh
# 1. containers/ must be a REAL directory now, not tmpfs
stat -f /var/lib/docker/containers | grep -i type
#   expect: Type: btrfs  (NOT tmpfs — if it still says tmpfs, something recreated the mount;
#   check for any leftover RAM-DISK-Dockerlog trace first: grep -ri "ram-disk" /etc/rc.d/rc.docker)

# 2. every container that was running/present before this reboot is still present after it,
#    with a real config directory (this directly checks the exposure the whole removal was for)
docker ps -a --format '{{.Names}}\t{{.Status}}'
#   compare against the 40-container manifest from this session (Radarr, TimeMachine, Unpackerr
#   included) — nothing should be missing or show RWLayer/inspect errors this time

# 3. spot-check a couple of containers' config actually persisted correctly
docker inspect Radarr --format '{{.State.Status}}'
docker inspect Sonarr --format '{{.State.Status}}'
#   expect clean output, no "RWLayer ... is unexpectedly nil" error

# 4. confirm no RAM-DISK-Dockerlog trace anywhere (it should never come back)
grep -ri "ram-disk" /etc/rc.d/rc.docker /usr/local/emhttp/plugins/dynamix/scripts/monitor
ls /boot/config/plugins/ | grep -i ram-disk
#   expect: nothing found for either
```

**If `containers/` still shows tmpfs after this reboot:** that means something recreated the
mount — since the plugin and its `rc.docker` patches are confirmed removed, this would be
unexpected and worth investigating fresh rather than assuming it's the same RAM-DISK-Dockerlog
mechanism (it shouldn't be able to reappear at all now).

**If a container is missing or shows an RWLayer-style error again:** this would NOT be the same
root cause as before (the mechanism that caused it is gone) — treat it as a new, separate
problem, not a recurrence.

## Original brief (written before the first reboot — steps 3–5 evidence still valid)

Written after steps 1–5 all passed on the live host, and after the plugin was genuinely
installed (persistently, from locally-staged artifacts — no public release). Read this fully
before you reboot; it's written to be followable if things go sideways, not just as a
changelog.

## Current live-system state, as of this writing (STALE — see update above)

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
- The Gate-1 live-verification container (`secretsman-liveverify`), its template, and its store
  entry were all created and cleaned up during step 4/5 verification — nothing from that round
  was left behind.
- **A second, new throwaway container exists specifically for this reboot:
  `secretsman-smoketest`.** See "The reboot smoketest" below — this is what makes repopulation
  timing provable this time, and it (plus its store entry) is disposable, to be removed in
  step 7.
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

## The reboot smoketest — `secretsman-smoketest` (set up specifically so this reboot proves repopulation timing)

Repopulation timing was unproven after step 5 because no autostart container used
`!secretfile`. Rather than let this reboot only re-confirm the patch re-applies, a disposable
`!secretfile` container was set up and verified working **before** the reboot, autostart
enabled, specifically so the reboot exercises the full `disks_mounted` → repopulate →
`container_paths_exist` → `docker start` chain for real.

**What was created, all through the real code path (same as step 4 — `xmlToCommand()` +
`execCommand()`, not a hand-rolled `docker run`):**

- **Store entry** (an explicit, deliberate exception to "don't write to the store location" —
  needed for this test): `/mnt/user/appdata/.secrets/store.json`, written via a tmp-file +
  `chmod 0600` + `rename` atomic sequence, preserving the 0600/root requirement
  `secretsman_load_store()` enforces:
  ```json
  { "secretsman-smoketest": { "throwaway-key": "smoketest-value-not-a-real-secret" } }
  ```
- **Template**, saved for real at
  `/boot/config/plugins/dockerMan/templates-user/my-secretsman-smoketest.xml`: `alpine`,
  `sleep 3600`, one Variable field holding `!secretfile secretsman-smoketest/throwaway-key`.
- **Container**, created via the live patched `xmlToCommand()` exactly as in step 4. Rendered
  command confirmed the sentinel absent and the expected shape present:
  ```
  ... -e 'THROWAWAY_TOKEN_FILE'='/run/secrets/throwaway-key' ...
  -v '/run/secretsman/files/secretsman-smoketest/throwaway-key':'/run/secrets/throwaway-key':'ro' ...
  ```
- **Autostart enabled** — appended `secretsman-smoketest` to `/var/lib/docker/unraid-autostart`
  (the plain list `rc.docker`'s autostart loop reads), after backing up the original to
  `/var/lib/docker/unraid-autostart.pre-smoketest.bak`. This is the part that makes the race
  real: without it, the container would only start after you're already logged in, well after
  any window where `disks_mounted` timing could matter.

**Verified before the reboot** (all passed):
- Bind-mount source materialised at `/run/secretsman/files/secretsman-smoketest/throwaway-key`,
  mode `0400`.
- Container started successfully.
- `docker exec secretsman-smoketest cat /run/secrets/throwaway-key` → exact match:
  `smoketest-value-not-a-real-secret`.

**This container and its store entry are DISPOSABLE.** Step 7 cleanup must remove, in addition
to the plugin itself: the container (`docker rm -f secretsman-smoketest`), its template
(`/boot/config/plugins/dockerMan/templates-user/my-secretsman-smoketest.xml`), its store entry
(either delete `/mnt/user/appdata/.secrets/store.json` entirely if nothing else has since used
it, or edit out just the `secretsman-smoketest` key), its
`/run/secretsman/files/secretsman-smoketest/` remnant, its `unraid-autostart` entry, and the
`.bak` file once you've confirmed the current autostart list is otherwise correct.

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
2. **`!secretfile` sources repopulate before docker autostart.** This is now genuinely testable:
   `secretsman-smoketest` (see "The reboot smoketest" above) is a real autostart container whose
   bind-mount source lives on tmpfs and will be gone the moment the box comes back up, exactly
   like every other `!secretfile` container will be after every future reboot. Whether it comes
   back correctly is the actual, direct test of the `disks_mounted` ordering guarantee argued in
   CLAUDE.md — not just a re-confirmation of the patch re-applying.
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
#   repopulate.php itself only logs (via notify) on a FAILURE — a clean repopulation of
#   secretsman-smoketest produces no log line of its own, only the file existing (see
#   step 7 below, which is the actual evidence). If you see a secretsman notification in the
#   GUI (bell icon) about a held-back container or a store problem, investigate before
#   trusting anything else in this list.

# 5. Ordinary containers are fine
docker ps --format '{{.Names}}\t{{.Status}}'
#   compare against your normal expectation — nothing here should look different from any
#   other reboot. Unpackerr in particular (touched in step 5) should be Up.

# 6. The plugin is still registered normally
ls -la /var/log/plugins/ | grep secretsman
#   expect: symlink to /boot/config/plugins/unraid-secretsman.plg, same as any other plugin
```

### 7. The smoketest itself — the part that actually proves repopulation timing

Run these in this exact order; each depends on the previous one having the expected result.

```sh
# 7a. Did it autostart at all?
docker ps -a --filter name=secretsman-smoketest --format '{{.Names}} {{.Status}}'
#   expect: Up ...

# 7b. Was the bind-mount source repopulated BEFORE docker tried to start it? (this is the
#     actual disks_mounted-vs-docker_started ordering claim, made concrete)
ls -la /run/secretsman/files/secretsman-smoketest/throwaway-key
#   expect: exists, mode 0400 — same as the pre-reboot state

# 7c. Does the container's own view of the file match exactly?
docker exec secretsman-smoketest cat /run/secrets/throwaway-key
#   expect: smoketest-value-not-a-real-secret   (byte-for-byte)
```

**What each failure mode here means — they are NOT equally bad:**

- **Container shows `Created` (not `Up`), and 7b's file does not exist:** repopulation lost the
  race or failed outright, and Unraid's own `container_paths_exist()` correctly refused to start
  it — see "Native fail-closed behaviour" above. **This is the safe failure.** Check
  `grep secretsman /var/log/syslog` for a "held back" notification naming the container and key,
  and cross-reference with `RECOVERY.md`'s force-start override. It means the ordering guarantee
  didn't hold on this particular boot (worth understanding why — see below) — but nothing wrong
  was ever exposed to the container.
- **Container is `Up`, but 7b's file is missing, or 7c returns empty/wrong content:** this is
  **worse and worth stopping on.** It would mean something got bind-mounted at
  `/run/secrets/throwaway-key` other than the intended secret file — possibly an empty directory
  Docker auto-vivified, which is exactly the fail-closed violation this whole design exists to
  prevent. If you see this, do not treat it as a minor glitch: capture `docker inspect
  secretsman-smoketest --format '{{json .Mounts}}'` and stop — this needs investigation before
  trusting the plugin with anything real, not a retry.
- **Container is `Up` and 7b/7c both pass:** the ordering guarantee held. This is the expected,
  hoped-for outcome.

Note this is a **blocking, not probabilistic, guarantee** by design (see CLAUDE.md "Array-start
hook" — `emhttpd` blocks on `disks_mounted` until it completes, strictly before `rc.docker start`
is ever invoked). A held-back result on this first reboot should NOT be read as "the race went
the wrong way this time, try again" — if the guarantee holds as designed, repopulation either
runs to completion before autostart, every time, or it doesn't run at all for some other reason
(store not readable yet, a path problem, `disks_mounted` not firing). If 7a shows held-back,
check `grep secretsman /var/log/syslog` and `grep disks_mounted /var/log/syslog` for what
actually happened before assuming it's a timing fluke — a genuine ordering failure here would be
a real bug in the design argument, worth reporting precisely, not smoothing over with a retry.

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

## Not yet done — current, as of the LATEST UPDATE at the top (supersedes everything below it)

- **Boot-time re-patch: ready to test, not yet proven.** The `.plg` install bug is fixed and
  the full install has succeeded live, for real, on this host — but that was done by manually
  running `plugin install`, not by an actual boot. Whether `rc.local` re-invokes it correctly
  and idempotently at boot is still what your reboot needs to prove.
- **`!secretfile` repopulation timing: ready to test, not yet proven.** `secretsman-smoketest`
  has been recreated with autostart enabled and verified working pre-reboot (see LATEST UPDATE
  at top) — this reboot is what actually tests the `disks_mounted` ordering guarantee.
- No public GitHub Release. The download path in the `.plg` has never been exercised (this
  round's install still hit the local-file skip-download path). Cutting a real release is a
  separate, later, explicitly-confirmed action.
- Step 6 (verify per this brief) and step 7 (remove the plugin, second reboot, confirm clean
  stock return) both wait for you, in a fresh session, starting now that the pre-reboot state
  is confirmed good.

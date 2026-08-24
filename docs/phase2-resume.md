# Phase 2 resume brief

Written at **GATE 1** — before any live change to the running Unraid host. Everything up to
this point (patch layer, staging harness, all 48 real templates diffed) has run only against
copies. This file is what a human needs to review, and later resume from, without re-deriving
context.

## Where things stand right now

- **Nothing on the live host has been modified.** Every step so far — hash verification,
  patched-copy generation, the full regression harness — ran against `/tmp` copies on the host
  or in a local sandbox. `/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php`
  is untouched, stock, unpatched.
- **Stock file's current hash** (re-verified live, 2026-08-25):
  ```
  9a45421b387b733ad260e204308baa69  /usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php
  ```
  This matches `reference/7.3.x/HASHES` in this repo exactly — no drift since the Phase 0
  recon capture. If you re-run `md5sum` on that path before doing anything else and get a
  **different** hash, STOP — something changed the file since this brief was written, and the
  rest of this document's assumptions may no longer hold.

## The exact change about to be made (step 3, pending your go-ahead)

A single `require_once` + one function call, inserted immediately before the line
`  foreach ($xml['Config'] as $key => $config) {` in `Helpers.php` (line 508 in the stock
7.3.1 copy), wrapped in marker comments:

```php
  // SECRETSMAN-PATCH-BEGIN v1 (github.com/kmbrimble/unraid-secretsman)
  if (!is_file('/usr/local/emhttp/plugins/unraid-secretsman/src/secretsman.php')) {
    throw new \RuntimeException('secretsman: resolver library missing at ' . '/usr/local/emhttp/plugins/unraid-secretsman/src/secretsman.php' . ' — reinstall the plugin or remove the patch');
  }
  require_once '/usr/local/emhttp/plugins/unraid-secretsman/src/secretsman.php';
  secretsman_resolve($xml, $xml['Name']);
  // SECRETSMAN-PATCH-END
```

Nothing else in the file changes. This is applied via `secretsman_patch_apply_to_file()`
(`src/patch.php`), which re-verifies the hash above immediately before writing, and only writes
if it still matches — so even if you wait a while before saying go, the check runs again at the
moment of the actual write, not just now.

### The exact command to undo this specific change

Two ways, in order of preference:

**1. The uninstall script** (once the plugin is actually installed at
`/usr/local/emhttp/plugins/unraid-secretsman/`):
```sh
php /usr/local/emhttp/plugins/unraid-secretsman/scripts/uninstall.php
```
Strips exactly the marker-delimited block above and nothing else — verified by
`secretsman_patch_revert()` round-tripping to byte-identical stock in
`tests/run.php` ("secretsman_patch_revert() round-trips the real reference/7.3.x/Helpers.php").

**2. Manual, if that script isn't available for any reason** — delete the three lines between
(and including) `// SECRETSMAN-PATCH-BEGIN` and `// SECRETSMAN-PATCH-END`, or just restore from
the reference copy:
```sh
cp /path/to/this/repo/reference/7.3.x/Helpers.php /usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php
```
(only safe if the host is still on Unraid 7.3.x — check `cat /etc/unraid-version` first).

**3. The universal fallback, if neither of the above works or the webGUI is unusable:** a
reboot alone restores `Helpers.php` from the OS image regardless of any of this, since
`/usr/local/emhttp` is never persisted. See "The `.plg`'s location and the reboot recovery"
below and the full drill in `RECOVERY.md`.

## The .plg's location on flash and the one-line removal + reboot recovery

Once installed, the plugin lives at:
```
/boot/config/plugins/unraid-secretsman.plg          <- the .plg descriptor itself
/boot/config/plugins/unraid-secretsman/              <- (if any config/state ends up here)
/usr/local/emhttp/plugins/unraid-secretsman/          <- the installed tree (not persistent)
```

**One-line removal + reboot recovery** (this is the drill already proven working — see
"Recovery drill: DONE" in the Phase 2 task and `RECOVERY.md`):
```sh
rm /boot/config/plugins/unraid-secretsman.plg
reboot
```
`/etc/rc.d/rc.local` only reinstalls `.plg`s it finds in `/boot/config/plugins/*.plg` at boot —
remove the file, and nothing re-patches `Helpers.php` on the next boot. Full stress-tested
version (including the "webGUI is unusable, pull the flash drive" path) is in `RECOVERY.md`.

## What's been verified so far (evidence for your review)

1. **Full unit suite: 81/81 passing** (`php tests/run.php`) — token parsing, store validation,
   field-scope enforcement, materialisation, the patch layer (apply/verify/revert/idempotency,
   including a real string-escaping bug the tests caught before it ever reached a file), and the
   new plugin-script helpers (template token scanning, autostart-list parsing, version/hash
   detection).
2. **The regression that matters, run on the live host, read-only, against every real
   template:**
   ```
   byte-identical check: 48 templates checked, 48 identical, 0 mismatches, 0 errors, 0 token-bearing skipped
   token-bearing fixture check: PASS (no resolved value appears in $cmd)
   ```
   Every one of your 48 real, token-free templates produces **byte-for-byte identical** output
   from stock vs. patched `Helpers.php`. The committed fixture template
   (`tests/harness/fixtures/token-bearing.xml`, synthetic, throwaway sentinel values) resolves
   through the patched copy with `--env-file=` in the output and neither sentinel value
   anywhere in it.
3. **`php -l` clean** on the patched copy under the host's real PHP 8.4.21.
4. **Live hash re-verified** immediately before this brief was written (see above) — no drift.

## What step 3 will actually do, concretely

Running `secretsman_patch_apply_to_file()` against the real path will:
1. Re-read and re-hash the live file.
2. Confirm it still matches `9a45421b387b733ad260e204308baa69`. If not, it aborts and raises an
   Unraid notification — it will not guess or force anything.
3. Write the patched contents to a temp file, preserve the original's permissions, and
   atomically rename over the live file.

No container is created or restarted by this step. It only changes what a *future* container
creation/update will do.

## Not yet done — deliberately deferred, not forgotten

- **No GitHub Release has been cut.** `unraid-secretsman.plg` and `scripts/build-plugin.sh`
  exist and the packaging step has been dry-run successfully, but publishing an installable
  `.txz` before this patch has been proven safe on a real host (steps 3–5 below, plus the
  reboot in Gate 2) would let someone install something not yet verified end-to-end. This is
  built to be releasable quickly once Gate 2 clears, not to be released now.
- Steps 4–5 (create a throwaway test container, force-update an existing unrelated container)
  happen after your go-ahead on this gate, in the same session.
- Gate 2 (the reboot) is a separate stop, later, performed by you, not this session.

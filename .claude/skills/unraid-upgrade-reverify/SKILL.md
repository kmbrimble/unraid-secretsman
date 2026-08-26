---
name: unraid-upgrade-reverify
description: Re-verify secretsman's Helpers.php patch layer after an Unraid upgrade, when the boot-time md5 gate has refused to patch (apply_patch.php's version/hash check failed). Encodes the exact procedure this project developed for this recurring maintenance cost — locate Helpers.php without assuming its path, diff it against the last known-good reference, gate on a plain-language human read of the diff, then re-verify and reinstall through the real mechanisms. Trigger phrases: "Unraid upgrade", "patch refused", "md5 gate", "re-verify the patch layer", "Helpers.php changed".
---

# Unraid upgrade re-verification

This is the recurring maintenance cost of secretsman's patch layer (CLAUDE.md rule 4/7): every
Unraid upgrade can change stock `Helpers.php`, and the plugin is *designed* to refuse to patch
rather than guess when that happens. This skill is what to do next — developed once, meant to be
followed exactly rather than re-derived, because two things in this project have already gone
wrong from skipping steps here: an ordering assumption relied on without a live test, and a
defensive check added without reading the mechanism it duplicated.

## Standing constraints (apply throughout, not just at the end)

- **No reboots.** Ever, in this skill. Step 9 ends by handing reboot-confirmation back to the
  user — they do all reboots.
- **No stopping the array or the Docker service.**
- **No privileged containers without disclosing the exact command and why, first** — same rule
  as every other host-touching action in this project.
- **Fail closed on anything unexpected.** If a step finds something that doesn't match what's
  recorded (a moved file, an extra call site, an unfamiliar diff region), that is itself the
  finding — stop and surface it rather than pushing through on an assumption.

## Hard-won lessons this procedure exists to encode

- **Verify paths; never assume them.** The emhttp plugin directory for Docker template handling
  was renamed once already (`dockerMan` → `dynamix.docker.manager` — only the flash template
  path kept the old name). It can move again. Locate files by what defines the function you
  need, not by a remembered path.
- **A reasoned guarantee from reading source is not a tested one.** The `disks_mounted`
  boot-ordering design was derived entirely from reading vendor source, documented as settled,
  and disproven by the first live reboot test. Passing tests and passing `php -l` are not
  evidence the injection point is still semantically correct — that's a human read of the diff
  (step 4), not a test result.
- **A function reachable from both CLI and web contexts must not assume CLI-only globals**
  (CLAUDE.md rule 8). Not directly relevant to `Helpers.php` itself, but relevant if this
  procedure leads to touching `apply_patch.php` or `uninstall.php` — check rule 8 before adding
  or changing any function there.
- **Never verify a plugin install by pre-placing files.** A real Gate 2 failure went undetected
  because files were already sitting at the expected destination before `plugin install` ran,
  so a packaging bug in `upgradepkg` extraction went unnoticed. Step 8 must go through the real
  `plugin install` mechanism from a clean slate — reinstalling over an already-correct tree
  proves nothing.
- **Don't layer a defensive check next to a mechanism you haven't verified.** If anything in
  this procedure tempts you to add an extra safety check "just in case" alongside an existing
  Unraid guarantee — don't. Read the real mechanism and confirm it holds instead (this is how
  the CSRF double-check bug shipped).

## Procedure

### 1. Establish the new version and locate Helpers.php — don't assume the path

Read `/etc/unraid-version` the same way `apply_patch.php`'s `detect_unraid_reference_dir()`
does (regex on `version="X.Y.Z"`, mapped to this repo's `reference/X.Y.x/` convention). Then
find the file that actually defines `xmlToCommand()` — don't assume it's still
`dynamix.docker.manager/include/Helpers.php`:

```
grep -rl 'function xmlToCommand' /usr/local/emhttp/plugins/*/include/*.php
```

If that comes back empty, widen the search (`/usr/local/emhttp` recursively) before concluding
anything — a renamed plugin directory is the known precedent, not a hypothetical.

### 2. Confirm `xmlToCommand()` is defined exactly once, and enumerate every call site

```
grep -rn 'function xmlToCommand' /usr/local/emhttp --include=*.php
grep -rln 'xmlToCommand(' /usr/local/emhttp --include=*.php
grep -rn  'xmlToCommand(' /usr/local/emhttp --include=*.php
```

Compare the count and the file:line set against what's recorded in `CLAUDE.md`'s Phase 0 recon
(currently: one definition, five call sites —
`dynamix/include/UpdateTwo.php`, `CreateDocker.php` ×2, `scripts/rebuild_container`,
`scripts/update_container`). **A changed count or a changed set is a material change** — it
means the single-insertion-point assumption this whole patch layer depends on needs
re-establishing, not just a version bump. Flag it explicitly in the step 4 report; don't proceed
past it silently even if the definition itself looks unchanged.

### 3. Diff the new Helpers.php against the most recent reference/ copy

```
diff -u reference/<closest-prior-version>.x/Helpers.php <path-found-in-step-1>
```

Use whatever `reference/` directory is closest to the new version (currently only `7.3.x`
exists). If no reference is close enough to be a meaningful baseline (a large major-version
jump), say so explicitly — a diff against nothing is not a diff, it's "everything is new."

Pay particular attention to:
- the `foreach ($xml['Config'] as $key => $config)` loop
- the `sprintf` (or equivalent string-building) that assembles the final `$cmd`
- the line immediately before that loop — the confirmed insertion point (line 508 in the 7.3.x
  reference copy; look for the equivalent location in the new file, not the same line number)

### 4. GATE — analyze and report, then stop

**Do not proceed on the strength of `php -l` or any test passing.** Whether the injection point
is still correct is a human read, not a test result — that's the entire reason this gate exists.

Produce a plain-language verdict, not a raw diff dump (the person approving this cannot read
PHP fluently and a diff means nothing to them without translation). Cover exactly these points:

1. **What changed, in terms of behavior** — not "these lines differ" but what the function now
   does differently, if anything.
2. **Whether anything touched the region the patch depends on**: the `foreach ($xml['Config'])`
   loop, the values it reads from each Config entry, or the `sprintf`/string-building that
   assembles the command.
3. **Whether the injection point is still valid** — still in the same conceptual place (right
   before that loop), and Config values at that point are still unescaped (the property the
   resolver structurally depends on to avoid ever putting a resolved secret into the command
   string — CLAUDE.md rule 6).
4. **Whether the call-site set changed** (from step 2).
5. **A clear recommendation**: **SAFE TO PROCEED** / **NEEDS ATTENTION** / **DO NOT PROCEED**,
   with the reason in one or two sentences.
6. **Confidence, and specifically what you'd want to be true but couldn't verify** — e.g.
   "I'm confident the loop body is unchanged; I could not confirm from source alone whether a
   new earlier code path can mutate `$xml['Config']` before this loop runs — that would need a
   live test, not a read."

**Then stop and wait for explicit go-ahead, regardless of the verdict.** If the verdict is
**NEEDS ATTENTION** or **DO NOT PROCEED**, say plainly what needs deciding or what would need
rebuilding, and go no further — do not draft a fix, do not touch `reference/`, do not touch the
live file. The gate is not conditional on how confident the analysis is; it exists because a
confident read of stock Unraid behavior has been wrong twice in this project already (the
`disks_mounted` ordering, the CSRF check), and an agent that both assesses the risk and acts on
it removes the one thing that catches that.

### 5. After go-ahead: stage the new reference copy and a patched scratch copy

- Copy the new `Helpers.php` into `reference/<version>.x/Helpers.php`.
- Compute its md5 and add it to `reference/<version>.x/HASHES`, following the existing file's
  format and header-comment convention (dated, one `Helpers.php  md5:<hash>` line).
- Apply the patch to a **copy**, never the source, using the existing harness:
  ```
  php tests/harness/make_patched_copy.php <new-helpers.php> /tmp/Helpers.patched.php <libPath>
  ```
- `php -l /tmp/Helpers.patched.php`

### 6. Run the byte-identical regression harness across every real template

```
php tests/harness/regression.php <stock-new-helpers.php> /tmp/Helpers.patched.php <real-templates-dir>
```

Every token-free real template must render byte-identical output stock vs. patched. The
token-bearing fixture template must resolve correctly with the sentinel value appearing nowhere
in the rendered command. **Anything less than 100% identical is a stop, not a warning** — do not
average across templates, do not treat one mismatch as noise.

### 7. Only then patch the live file, and re-run the harness against it

Apply through the real mechanism (`secretsman_patch_apply_to_file()` / `apply_patch.php`'s own
code path — the same thing the `.plg` runs at boot), not a hand copy of the `/tmp` version.
Re-run the regression harness a second time against the now-actually-live file, to confirm the
live result matches what step 6 already proved about the staged copy.

### 8. Rebuild and reinstall through the real plugin mechanism

```
bash scripts/build-plugin.sh <version>
```

Then reinstall via the real `plugin install`/`upgradepkg` path — **never** by pre-placing files
at the expected destination first. Start from a clean slate (no plugin directory, no package
registry entry already there) the same way the project's own standing rule requires for any
`.plg`/`build-plugin.sh` change. Pre-placing files and then running the installer proves nothing
about whether the installer itself works — that's exactly how the Gate 2 packaging bug went
undetected once already.

### 9. Report and stop

State plainly what still needs a real reboot to confirm (the boot-time re-patch surviving a
fresh boot, and — since this is a patch-layer change — that `CLAUDE.md` rule 7's `RECOVERY.md`
drill needs re-running and confirmed working before this ships). **Then stop. Reboots are the
user's action, not this skill's.**

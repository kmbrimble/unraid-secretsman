# unraid-secretsman

Central secret storage for Unraid Docker templates, so a secret typed into the Docker GUI
doesn't end up in plaintext everywhere that template gets copied, pasted, or backed up.

## The problem

Today, a password or API key typed into an Unraid Docker template's variable field shows up in
plaintext in three places at once:

- the `docker run` command the GUI displays back to you,
- the template XML at `/boot/config/plugins/dockerMan/templates-user/my-*.xml`, and
- any log that captures either of the above.

The GUI's password-masking (the dots in the field) is **cosmetic only** — it doesn't touch the
stored value, the displayed command, or the XML.

That template XML and that command string are exactly the things people routinely copy and
paste — into chat, into a GitHub issue, into a forum post asking for help — and it is very easy
to forget a value is sitting in there unredacted until after you've hit send.

With this plugin, both artefacts stay clean by construction: the template XML holds only
`!secret ns/key`, and the command the GUI displays holds only
`--env-file=/run/secretsman/env/<container>.env`. There is nothing to redact because the secret
was never in either string. That's the primary reason this exists.

**Limit, stated plainly:** this covers the template and the displayed command — the two
artefacts people actually paste around. It does not, and cannot, stop you from copying a value
straight out of the store editor or out of a running container's own configuration; nothing
automated protects you from *that* copy-paste.

It also closes a second, related problem: everything above is likewise true of your **flash
backup**, which is a full copy of that same template XML. A secret typed into the GUI today is
in plaintext in every flash backup you take, or that gets taken for you, for as long as that
backup exists.

## What this does

Store your secrets once, outside flash, and reference them from a template field with a token:

```
!secret terriblebutler/anthropic_api_key
```

or, for tools that support reading a secret from a file (the `_FILE` / `FILE__` convention):

```
!secretfile terriblebutler/anthropic_api_key
```

At container-create time, the plugin resolves the token against a central store and rewrites
the docker command so the template XML — and therefore your flash backup — only ever contains
the token, never the value.

- **`!secret`**: the value is written to a `root:root 0400` file on tmpfs and the variable is
  replaced with a single `--env-file=` reference. The template XML never sees the value.
- **`!secretfile`**: the value is written to a `root:root 0400` file on tmpfs, a read-only bind
  mount is added, and the variable is rewritten to `<VAR>_FILE=/run/secrets/<key>` for apps that
  support that convention.

## Install

Not yet in Community Applications. Install by URL from the **Plugins → Install Plugin** page,
using the `.plg` URL from the latest
[GitHub Release](https://github.com/kmbrimble/unraid-secretsman/releases) (Phase 2 — packaging
isn't built yet; see `CLAUDE.md` for the roadmap).

## SECURITY

Read this before you rely on this plugin.

**What `!secret` closes — exposures that leave the machine:**

- flash backups, including ones taken automatically
- the template XML, when it's shared in an issue, a forum post, or pasted into chat
- the `docker run` command the GUI echoes back to you, and any log that captures it

**What `!secret` does *not* close — exposures that already require root or docker-socket
access on the host:**

- `docker inspect <container>`
- `/proc/<pid>/environ`

`!secret` resolves to a real environment variable inside the container, so it's visible through
either of those. This is a smaller marginal exposure than it looks: anyone who can read
`docker inspect` output or `/proc/<pid>/environ` already has root or docker-group access to the
Unraid host — and with that access, they can simply read `store.json` directly. The plugin isn't
leaving a hole next to a locked door; it's declining to also guard a door that access already
opens.

**Why an env-var secret can't be hidden from `docker inspect` without the app's cooperation:**
something inside the container has to read the file and hand the value to the application, and
if that something is a wrapper script that re-exports it as an environment variable, the value
reappears in `/proc/<pid>/environ` for whatever process it hands off to. An entrypoint shim
*moves* the exposure from `docker inspect` to `/proc`; it doesn't remove it. The only way to
actually avoid this is for the application itself to read the secret from a file and never turn
it into an environment variable — which is exactly the `_FILE` / `FILE__` convention.

- **`!secretfile` is the hardened path**, for exactly that reason. The value never becomes an
  environment variable at all — only a file path does — so it closes both categories above:
  `docker inspect` and `/proc/<pid>/environ` never see it either. Use it for anything that
  supports the `_FILE` convention.
- **`!secretfile` on an autostarting container is currently UNSAFE — do not rely on it yet.**
  Its bind-mount source lives on tmpfs and has to be rewritten on every boot, before Docker
  autostarts the container, and a live test (2026-08-25) showed this repopulation is **not
  currently guaranteed to finish first**: Docker can auto-create the missing path as an empty
  directory and start the container against it, which fails instead of being safely held back.
  This is a real, open design gap, not a hedge — see `CLAUDE.md`'s correction note and
  `docs/phase2-resume.md` for the incident and the fix in progress. Until it's resolved, only use
  `!secretfile` on containers you start manually after confirming the secret file is in place.
  `!secret` (resolved once, at container-create time in the GUI) is not affected by this gap.
- **This plugin patches OS files.** It surgically modifies
  `/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php`, a stock Unraid file
  that is restored from the OS image on every boot, which is why the patch has to reapply at
  boot. The patch is checksum-guarded and refuses to apply against a `Helpers.php` it doesn't
  recognise — see `CLAUDE.md` for the exact rule — but you are still trusting a third-party
  plugin to modify a core system file. Read the patch before you install it.
- **Recovery**: because `/usr/local/emhttp` is restored from the OS image every boot, a broken
  patch is undone by removing the plugin and rebooting — no OS reinstall required, and it's
  doable from another machine with the USB stick if the webGUI itself is unusable. See
  `RECOVERY.md`.
- **The store itself** lives outside flash (default `/mnt/user/appdata/.secrets/store.json`),
  `root:root 0600`. Only a pointer to its path is kept on flash. Protect it the way you'd protect
  any file holding plaintext credentials — it is not encrypted at rest.
- Resolved secrets are never written to any log, debug output, or error message by this plugin.
  If you find a case where one is, that's a bug — please report it.

## Status

Phase 1 (this repo, right now): a version-independent resolver library with a failing-first unit
test suite, isolated from the live Unraid install. It is not yet wired into Unraid at all — see
`CLAUDE.md` for the phase roadmap.

## License

GPL-2.0 (see `LICENSE`). This project vendors and patches GPLv2 files from Unraid's own
`dynamix.docker.manager` plugin (see `reference/`), which requires the same license.

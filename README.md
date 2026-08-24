# unraid-secretsman

Central secret storage for Unraid Docker templates, so a secret typed into the Docker GUI
doesn't end up in plaintext on your flash drive.

## The problem

Today, when you type a password or API key into an Unraid Docker template's variable field, it
is written in plaintext into `/boot/config/plugins/dockerMan/templates-user/my-*.xml` — on the
flash drive. That means:

- it's in every flash backup you take or that gets taken for you,
- it's readable by anything with flash access, and
- the GUI's password-masking (the dots in the field) is **cosmetic only** — it does not encrypt
  or protect the stored value in any way.

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

- **`!secret` protects the command string and flash — not the container's runtime
  environment.** The resolved value is still passed into the container as a real environment
  variable. It remains fully visible via `docker inspect <container>` and via
  `/proc/<pid>/environ` for anyone with root or container-exec access to the host. This closes
  the flash-backup leak; it does not make the secret disappear from the running system.
- **`!secretfile` is the hardened path.** The value never becomes an environment variable at
  all — only a file path does — so it doesn't appear in `docker inspect` or `/proc/<pid>/environ`.
  Use it for anything that supports the `_FILE` convention.
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

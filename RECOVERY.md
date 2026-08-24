# Recovery

Written to be followable under stress — if the Unraid webGUI is unusable after this plugin
patches a system file, this is how you undo it.

## Why this works

`/usr/local/emhttp` is **not persistent**. It is restored from the OS image fresh on every
boot. This plugin's patch to `Helpers.php` only exists because the plugin re-applies it every
boot from a `.plg` install-time script (that's also *why* it has to re-apply every boot — the
patch itself doesn't survive a reboot on its own). Remove the thing that reapplies the patch,
reboot, and the stock file comes back untouched.

**No OS reinstall, no filesystem repair, no flash rebuild is needed.**

## Steps

### 1. If the webGUI still loads

1. Go to **Plugins**.
2. Find **unraid-secretsman**, click **Remove**.
3. Reboot the server (**Main → Reboot**, or `reboot` over SSH).
4. On boot, `/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php` is restored
   from the OS image, unpatched. Confirm with:
   ```
   md5sum /usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php
   ```
   and compare against `reference/7.3.x/HASHES` in this repo for your Unraid version.

### 2. If the webGUI is unusable

You don't need physical access to fix the plugin — you need access to the **flash drive's
filesystem**, which any other machine can mount.

1. Shut down the Unraid server (hold the power button if you must — the flash filesystem is
   FAT32 and tolerant of this, though a clean shutdown over SSH is always better if you can get
   a terminal).
2. Pull the USB flash drive and plug it into any other computer. It will mount as a normal FAT32
   volume.
3. Delete the plugin's `.plg` file:
   ```
   config/plugins/unraid-secretsman.plg
   ```
   (from the drive's root — on Unraid this is `/boot/config/plugins/unraid-secretsman.plg`).
4. Optionally also remove any leftover plugin directory:
   ```
   config/plugins/unraid-secretsman/
   ```
5. Unmount the drive safely, put it back in the server, and power it on.
6. Because `.plg`s are (re)installed from `config/plugins/*.plg` at every boot (see
   `/etc/rc.d/rc.local`), and this one is now gone, the patch is never reapplied.
   `Helpers.php` comes back stock from the OS image.

### 3. Confirm you're clean

Over SSH, once the server is back up:

```sh
md5sum /usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php
```

This should match the known-good hash in `reference/<your-version>/HASHES` in this repo. If it
doesn't match *and* doesn't match a hash you recognise as this plugin's patched version either,
something else changed the file — stop and investigate before reinstalling anything.

## A container is held back because a !secretfile source is missing

This is a different situation from "the plugin broke something" — the patch and the system are
both fine. This happens when a `!secretfile`-using container was set to autostart, its secret
source under `/run/secretsman/files/` didn't get repopulated (store was unreadable at boot, key
was renamed, etc.), and Unraid's own `container_paths_exist()` check (in `/etc/rc.d/rc.docker`)
correctly left it stopped rather than starting it with an empty directory bind-mounted in place.
You'll see an "secretsman: Container held back" notification naming the container and the
missing key when this happens.

**Once you've fixed the underlying problem** (the store is readable again, the key exists again,
etc.), you don't have to wait for the next array restart — one command re-attempts
materialisation for that one container and starts it immediately if it succeeds:

```sh
php /usr/local/emhttp/plugins/unraid-secretsman/scripts/force_start.php <ContainerName>
```

This still fails closed: it refuses to run `docker start` if the secret still can't be
materialised, and tells you exactly which `ns/key` is still the problem. It does **not** bypass
the check — if you genuinely want to start a container with a missing secret file anyway
(accepting that Docker will bind-mount an empty directory in its place), that's a decision this
script deliberately won't make for you; use `docker start <ContainerName>` directly if you're
sure.

## Standing rule

Any change to the patch layer (Phase 2+) requires this recovery drill to be re-run and
confirmed working before that change ships. A patch mechanism that can't be reliably undone is
worse than no plugin at all.

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

## Standing rule

Any change to the patch layer (Phase 2+) requires this recovery drill to be re-run and
confirmed working before that change ships. A patch mechanism that can't be reliably undone is
worse than no plugin at all.

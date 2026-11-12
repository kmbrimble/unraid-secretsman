import { useEffect, useState } from 'preact/hooks'
import { ApiError, backupFileToBase64, formatBytes, formatTime, type BackupPayload, type BrowsePayload } from './lib'
import { Field, FolderPicker, Modal, ShowToggle } from './ui'
import type { Api, Notify } from './App'

const DOWNLOAD_URL = '../scripts/backup_download.php'
const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

interface Form { destination: string; mode: string; hour: string; minute: string; weekday: string; dayOfMonth: string; retention: string; password: string }
const toForm = (p: BackupPayload): Form => ({
  destination: p.config.destination, mode: p.config.schedule.mode, hour: String(p.config.schedule.hour),
  minute: String(p.config.schedule.minute), weekday: String(p.config.schedule.weekday),
  dayOfMonth: String(p.config.schedule.dayOfMonth), retention: String(p.config.retention), password: '',
})

export function Backup({ api, notify, onRestored }: { api: Api; notify: Notify; onRestored: () => void }) {
  const [data, setData] = useState<BackupPayload | null>(null)
  const [form, setForm] = useState<Form | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [showPw, setShowPw] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)
  const [picking, setPicking] = useState(false)

  const [selected, setSelected] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [rpw, setRpw] = useState('')
  const [rmode, setRmode] = useState<'replace' | 'merge'>('replace')
  const [confirming, setConfirming] = useState(false)
  const [restoreErr, setRestoreErr] = useState<string | null>(null)

  // Only the first load seeds the form: later payloads must never overwrite settings the user is typing.
  useEffect(() => {
    api.backup<BackupPayload>({ action: 'list' })
      .then((d) => { setData(d); setForm(toForm(d)) })
      .catch((e) => setLoadError(e.message))
  }, [])

  const set = (k: keyof Form) => (e: Event) => setForm((f) => f && { ...f, [k]: (e.target as HTMLInputElement).value })

  const run = async (what: string, fn: () => Promise<void>) => {
    setBusy(what)
    try { await fn() } catch (e) { notify.err(e) } finally { setBusy(null) }
  }
  const save = () => run('save', async () => {
    const f = form!
    const d = await api.backup<BackupPayload>({
      action: 'config_set', destination: f.destination, mode: f.mode, hour: f.hour, minute: f.minute,
      weekday: f.weekday, dayOfMonth: f.dayOfMonth, retention: f.retention, password: f.password,
    })
    setData(d); setForm(toForm(d)); setShowPw(false)
    notify.ok('Backup settings saved.')
  })
  const now = () => run('now', async () => {
    const d = await api.backup<BackupPayload>({ action: 'backup_now' })
    setData(d)
    notify.ok(d.message || 'Backup complete.', true)
  })
  const restore = () => run('restore', async () => {
    const d0 = data!
    const post: Record<string, string> = { action: 'restore', password: rpw, mode: rmode }
    if (file) {
      if (file.size > d0.uploadLimitBytes) {
        throw new ApiError(`That archive is ${formatBytes(file.size)}; this server accepts uploads up to ${formatBytes(d0.uploadLimitBytes)}. Place it at the backup destination and choose it from the list instead.`)
      }
      post.archive_b64 = await backupFileToBase64(file)
    } else post.selected = selected
    try {
      const d = await api.backup<BackupPayload>(post)
      setData(d)
      const r = d.restored
      const n = (a?: string[]) => (a ? a.length : 0)
      notify.ok(r?.mode === 'merge'
        ? `Restore (merge) complete: ${n(r.added)} added, ${n(r.collisions)} already present and left untouched${n(r.collisions) ? ` (${r.collisions!.join(', ')})` : ''}.`
        : `Restore (replace) complete: the store now holds ${n(r?.added)} secrets from the archive; ${n(r?.removed)} not in the archive were removed.`, true)
      setRpw(''); setFile(null); setSelected(''); onRestored()
    } finally { setConfirming(false) }
  })
  const startRestore = () => {
    if (!rpw) return setRestoreErr('Enter the archive password.')
    if (!file && !selected) return setRestoreErr('Choose an existing archive or upload one.')
    setRestoreErr(null); setConfirming(true)
  }

  return (
    <details class="card sm-ns sm-backup">
      <summary class="sm-ns-bar sm-summary">
        <h2 class="section-label sm-ns-name">Backup &amp; Restore</h2>
        {data && (data.lastRun
          ? <span class={`chip ${data.lastRun.ok ? 'chip--ok' : 'chip--alert'}`}>{data.lastRun.ok ? 'Last backup OK' : 'Last backup FAILED'}</span>
          : <span class="chip chip--warn">No backup yet</span>)}
      </summary>
      {loadError && <p class="sm-note" role="alert"><span class="chip chip--alert">Error</span> {loadError}</p>}
      {!data && !loadError && <p class="sm-note">Loading&hellip;</p>}
      {data && form && (
        <div class="sm-backup-body">
          <p class="sm-note">
            {data.lastRun
              ? `Last backup: ${formatTime(data.lastRun.time)}, ${data.lastRun.ok ? 'succeeded' : 'failed'}${data.lastRun.ok ? '' : `: ${data.lastRun.message}`}.`
              : 'No backup has run yet.'}
            {' '}The store lives in a dot-folder that many appdata backup tools skip, so back it up here.
          </p>
          <div class="sm-grid">
            <Field label="Destination folder" id="bk-dest">
              <div class="sm-inline">
                <input id="bk-dest" class="input sm-grow" value={form.destination} placeholder="/mnt/user/backups/secretsman" onInput={set('destination')} />
                <button class="btn btn--secondary" type="button" onClick={() => setPicking(true)}>Browse&hellip;</button>
              </div>
            </Field>
            <Field label="Schedule" id="bk-mode">
              <div class="sm-inline">
                <select id="bk-mode" class="input" value={form.mode} onChange={set('mode')}>
                  <option value="off">Off</option><option value="daily">Daily</option>
                  <option value="weekly">Weekly</option><option value="monthly">Monthly</option>
                </select>
                {form.mode === 'weekly' && (
                  <select class="input" aria-label="Day of week" value={form.weekday} onChange={set('weekday')}>
                    {WEEKDAYS.map((d, i) => <option value={String(i)} key={d}>{d}</option>)}
                  </select>
                )}
                {form.mode === 'monthly' && (
                  <input class="input sm-num" type="number" min="1" max="28" aria-label="Day of month (1 to 28)" value={form.dayOfMonth} onInput={set('dayOfMonth')} />
                )}
                {form.mode !== 'off' && (
                  <>
                    <input class="input sm-num" type="number" min="0" max="23" aria-label="Hour (0 to 23)" value={form.hour} onInput={set('hour')} />
                    <span aria-hidden="true">:</span>
                    <input class="input sm-num" type="number" min="0" max="59" aria-label="Minute (0 to 59)" value={form.minute} onInput={set('minute')} />
                  </>
                )}
              </div>
            </Field>
            <Field label="Keep last (archives)" id="bk-ret" hint="0 keeps everything.">
              <input id="bk-ret" class="input sm-num" type="number" min="0" value={form.retention} onInput={set('retention')} />
            </Field>
            <Field label="Archive password" id="bk-pw" hint={data.passwordSet ? 'A password is set. Leave blank to keep it.' : 'Not set yet. A backup needs one.'}>
              <div class="sm-inline">
                <input id="bk-pw" class="input sm-grow" type={(showPw ? 'text' : 'password') as 'text'} autocomplete="new-password" value={form.password} onInput={set('password')} />
                <ShowToggle shown={showPw} onToggle={() => setShowPw(!showPw)} what="password" />
              </div>
            </Field>
          </div>
          <div class="sm-actions sm-actions-left">
            <button class="btn btn--secondary" type="button" disabled={busy !== null} onClick={save}>Save backup settings</button>
            <button class="btn btn--secondary" type="button" disabled={busy !== null || !data.passwordSet}
              title={data.passwordSet ? undefined : 'Set a password first'} onClick={now}>
              {busy === 'now' ? 'Backing up…' : 'Back up now'}
            </button>
            {data.archives.length > 0
              ? <a class="btn btn--secondary" href={DOWNLOAD_URL} download>Download most recent</a>
              : <button class="btn btn--secondary" type="button" disabled title="No archives yet">Download most recent</button>}
          </div>

          <h3 class="section-label sm-sub-h">Archives</h3>
          {data.archives.length === 0
            ? <p class="sm-note">No archives at the destination yet.</p>
            : <ul class="sm-rows">{data.archives.map((a) => (
                <li class="sm-row" key={a.name}><code class="sm-key sm-grow">{a.name}</code><span class="sm-note">{formatTime(a.mtime)}</span><span class="sm-note">{formatBytes(a.bytes)}</span></li>
              ))}</ul>}

          <h3 class="section-label sm-sub-h">Restore</h3>
          <div class="sm-grid">
            {data.archives.length > 0 && (
              <Field label="Existing archive" id="bk-sel">
                <select id="bk-sel" class="input" value={selected} disabled={!!file} onChange={(e) => setSelected((e.target as HTMLSelectElement).value)}>
                  <option value="">Choose an archive</option>
                  {data.archives.map((a) => <option value={a.name} key={a.name}>{a.name}</option>)}
                </select>
              </Field>
            )}
            <Field label="Or upload an archive" id="bk-file" hint={`Up to ${formatBytes(data.uploadLimitBytes)}.`}>
              <input id="bk-file" class="input" type="file" onChange={(e) => setFile((e.target as HTMLInputElement).files?.[0] ?? null)} />
            </Field>
            <Field label="Archive password" id="bk-rpw">
              <input id="bk-rpw" class="input" type="password" autocomplete="off" value={rpw} onInput={(e) => setRpw((e.target as HTMLInputElement).value)} />
            </Field>
            <fieldset class="sm-radios">
              <legend class="sm-label">Restore mode</legend>
              <label><input type="radio" name="bk-rmode" checked={rmode === 'replace'} onChange={() => setRmode('replace')} /> Replace store entirely</label>
              <label><input type="radio" name="bk-rmode" checked={rmode === 'merge'} onChange={() => setRmode('merge')} /> Merge (add missing only)</label>
            </fieldset>
          </div>
          {restoreErr && <div class="sm-err" role="alert"><span class="chip chip--alert">Error</span> {restoreErr}</div>}
          <div class="sm-actions sm-actions-left">
            <button class="btn btn--secondary" type="button" disabled={busy !== null} onClick={startRestore}>Restore&hellip;</button>
          </div>
        </div>
      )}
      {picking && form && (
        <FolderPicker start={form.destination} onClose={() => setPicking(false)}
          browse={(dir) => api.backup<BrowsePayload>({ action: 'browse', dir })}
          onPick={(path) => { setForm({ ...form, destination: path }); setPicking(false) }} />
      )}
      {confirming && (
        <Modal title="Restore from archive?" onClose={() => setConfirming(false)}>
          {rmode === 'replace'
            ? <p><span class="chip chip--warn">Replaces everything</span> The store will become exactly what is in <strong>{file ? file.name : selected}</strong>. Any secret not in that archive is removed.</p>
            : <p>Secrets in <strong>{file ? file.name : selected}</strong> that are not already in the store are added. Any that exist in both are left untouched and listed afterwards.</p>}
          <div class="sm-actions">
            <button class="btn btn--secondary" type="button" onClick={() => setConfirming(false)}>Cancel</button>
            <button class="btn btn--primary" type="button" disabled={busy !== null} onClick={restore}>{busy === 'restore' ? 'Restoring…' : `Restore (${rmode})`}</button>
          </div>
        </Modal>
      )}
    </details>
  )
}

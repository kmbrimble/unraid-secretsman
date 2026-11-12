import { useEffect, useMemo, useState } from 'preact/hooks'
import { copyText, nameError, tokenFor, type KeyRow, type ListPayload, type Namespace } from './lib'
import { Field, Modal, ShowToggle } from './ui'
import type { Api, Notify } from './App'

type Editing = { mode: 'add'; ns: string; key: string } | { mode: 'edit'; ns: string; key: string }
type Deleting = { ns: string; key: string; usedBy: string[] }

export function Secrets({ api, notify, version }: { api: Api; notify: Notify; version: number }) {
  const [data, setData] = useState<ListPayload | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [query, setQuery] = useState('')
  const [editing, setEditing] = useState<Editing | null>(null)
  const [deleting, setDeleting] = useState<Deleting | null>(null)
  const [revealed, setRevealed] = useState<Record<string, string>>({})
  const [open, setOpen] = useState<Record<string, boolean>>({})

  // Background reload: only touches the table state. User-action results live in the toasts, which this never clears.
  const reload = () =>
    api.store({ action: 'list' }).then((d) => { setData(d); setLoadError(null) }).catch((e) => setLoadError(e.message))
  // A restore can change any value, so revealed values from before it are stale: drop them.
  useEffect(() => { setRevealed({}); void reload() }, [version])

  const q = query.trim().toLowerCase()
  const shown = useMemo(() => {
    if (!data) return []
    if (!q) return data.namespaces
    return data.namespaces
      .map((n): Namespace => ({
        name: n.name,
        keys: n.keys.filter((k) => `${n.name}/${k.key} ${k.usedBy.join(' ')}`.toLowerCase().includes(q)),
      }))
      .filter((n) => n.keys.length > 0)
  }, [data, q])
  const total = data?.namespaces.reduce((a, n) => a + n.keys.length, 0) ?? 0

  const reveal = async (ns: string, key: string) => {
    const id = `${ns}/${key}`
    if (id in revealed) {
      setRevealed(({ [id]: _gone, ...rest }) => rest)
      return
    }
    try {
      const r = await api.store<{ value: string }>({ action: 'reveal', ns, key })
      setRevealed((cur) => ({ ...cur, [id]: r.value }))
    } catch (e) { notify.err(e) }
  }
  const copy = async (ns: string, key: string) => {
    const t = tokenFor(ns, key)
    if (await copyText(t)) notify.ok(`Copied ${t}. Paste it into a template's Variable field in place of the value.`)
    else notify.err(new Error(`Your browser blocked copying. Select and copy this by hand: ${t}`))
  }
  const del = async (d: Deleting) => {
    try {
      const r = await api.store({ action: 'delete', ns: d.ns, key: d.key })
      setData(r); setDeleting(null)
      setRevealed(({ [`${d.ns}/${d.key}`]: _g, ...rest }) => rest)
      notify.ok(`Deleted ${d.ns}/${d.key}.`)
    } catch (e) { notify.err(e); setDeleting(null) }
  }

  return (
    <>
      <header class="card sm-head">
        <div class="sm-head-top">
          <div>
            <h1 class="sm-title">SecretsMan</h1>
            <p class="sm-sub">Keep passwords and API keys out of Docker templates.</p>
          </div>
          <button class="btn btn--primary" type="button" onClick={() => setEditing({ mode: 'add', ns: '', key: '' })}>
            Add secret
          </button>
        </div>
        <details class="sm-explain">
          <summary>How this works, and what it does not protect against</summary>
          <p>
            A password or API key typed into a Docker template&rsquo;s Variable field is written in plaintext to flash,
            shows up in the <code>docker run</code> command the GUI displays back to you, and ends up in every flash
            backup and anything you paste (a forum post, a support chat). SecretsMan stores each secret once, outside
            flash, and lets a template hold a token instead of the value.
          </p>
          <p>
            <strong>To use it:</strong> add a secret, press <strong>Copy token</strong>, and paste the token (for example{' '}
            <code>!secret myapp/api_key</code>) into the template&rsquo;s Variable field <em>in place of the value</em>.
            Apply as normal. When the container is created, SecretsMan swaps the token for the real value, and the
            container receives it exactly as if you had typed it in.
          </p>
          <p>
            Namespace and key may contain only letters, digits and <code>_ . -</code>. The same rule applies if you type
            a token into a template by hand.
          </p>
          <p>
            <strong>What this does not protect against:</strong> the resolved value is still a real environment variable
            inside the running container, so it remains visible to <code>docker inspect</code> and{' '}
            <code>/proc/&lt;pid&gt;/environ</code>. Both already need root or docker-socket access to the host, and with
            that access the store file is just as readable. SecretsMan closes the exposures that <em>leave</em> the host
            (flash backups, a template pasted somewhere, the displayed command); it does not add a second lock on a door
            that access already opens.
          </p>
          <p>
            The store lives at <code>/mnt/user/appdata/.secrets/store.json</code>, outside flash, <code>root:root 0600</code>.
            It is not encrypted at rest, so protect it the way you would any file holding plaintext credentials.
          </p>
        </details>
        <div class="sm-search">
          <label for="sm-search" class="sm-label">Search secrets</label>
          <input id="sm-search" class="input" type="search" placeholder="Search by namespace, key or container" value={query}
            onInput={(e) => setQuery((e.target as HTMLInputElement).value)} />
        </div>
      </header>

      {loadError && (
        <section class="card sm-block" role="alert">
          <span class="chip chip--alert">Error</span> <strong>The store could not be read.</strong>
          <p class="sm-note">{loadError}</p>
          <p class="sm-note">SecretsMan will not offer to reset or overwrite it. Fix the file by hand (it is JSON), then reload.</p>
          <button class="btn btn--secondary" type="button" onClick={() => void reload()}>Try again</button>
        </section>
      )}
      {!data && !loadError && <p class="sm-note">Loading secrets&hellip;</p>}

      {data && total === 0 && !q && (
        <section class="card sm-block">
          <p class="sm-note">No secrets stored yet. Press <strong>Add secret</strong> to store your first one: a namespace, a key, and the value you want to protect.</p>
        </section>
      )}
      {data && q && shown.length === 0 && <p class="sm-note">No secrets match &ldquo;{query}&rdquo;.</p>}

      {shown.map((n) => (
        <section class="card sm-ns" key={n.name} aria-label={`Namespace ${n.name}`}>
          <div class="sm-ns-bar">
            <h2 class="section-label sm-ns-name">{n.name}</h2>
            <span class="chip">{n.keys.length} {n.keys.length === 1 ? 'secret' : 'secrets'}</span>
            <span class="sm-spacer" />
            <button class="btn btn--ghost" type="button" onClick={() => setEditing({ mode: 'add', ns: n.name, key: '' })}>
              Add to {n.name}
            </button>
          </div>
          <ul class="sm-rows">
            {n.keys.map((k) => (
              <Row key={k.key} ns={n.name} row={k} value={revealed[`${n.name}/${k.key}`]}
                usedOpen={!!open[`${n.name}/${k.key}`]}
                toggleUsed={() => setOpen((o) => ({ ...o, [`${n.name}/${k.key}`]: !o[`${n.name}/${k.key}`] }))}
                onReveal={() => reveal(n.name, k.key)} onCopy={() => copy(n.name, k.key)}
                onEdit={() => setEditing({ mode: 'edit', ns: n.name, key: k.key })}
                onDelete={() => setDeleting({ ns: n.name, key: k.key, usedBy: k.usedBy })} />
            ))}
          </ul>
        </section>
      ))}

      {data && data.orphans.length > 0 && (
        <section class="card sm-ns" aria-label="Secrets that templates reference but the store lacks">
          <div class="sm-ns-bar"><h2 class="section-label sm-ns-name">Referenced by a template but not stored</h2></div>
          <ul class="sm-rows">
            {data.orphans.map((o) => (
              <li class="sm-row" key={o.token}>
                <span class="chip chip--warn">Missing</span>
                <code class="sm-key">!secret {o.token}</code>
                <span class="sm-grow sm-note">Used by {o.usedBy.join(', ')}. Creating or updating {o.usedBy.length > 1 ? 'these containers' : 'this container'} will fail until it is added.</span>
                <button class="btn btn--secondary" type="button" onClick={() => setEditing({ mode: 'add', ns: o.ns, key: o.key })}>Add</button>
              </li>
            ))}
          </ul>
        </section>
      )}
      {data && data.problems.length > 0 && (
        <section class="card sm-ns" aria-label="Templates with a token that does not parse">
          <div class="sm-ns-bar"><h2 class="section-label sm-ns-name">Templates with a token that does not parse</h2></div>
          <ul class="sm-rows">
            {data.problems.map((p, i) => (
              <li class="sm-row" key={i}><span class="chip chip--alert">Invalid</span><span class="sm-grow">{p.container} ({p.field}): {p.message}</span></li>
            ))}
          </ul>
        </section>
      )}

      {editing && (
        <EditModal editing={editing} namespaces={data?.namespaces.map((n) => n.name) ?? []} api={api} onClose={() => setEditing(null)}
          onSaved={(d, label) => {
            setData(d); setEditing(null)
            setRevealed((r) => { const { [`${editing.ns}/${editing.key}`]: _g, ...rest } = r; return rest })
            notify.ok(label)
          }} />
      )}
      {deleting && (
        <Modal title={`Delete ${deleting.ns}/${deleting.key}?`} onClose={() => setDeleting(null)}>
          <p>This removes the secret from the store. It cannot be undone.</p>
          {deleting.usedBy.length > 0 && (
            <p><span class="chip chip--warn">In use</span> It is used by {deleting.usedBy.join(', ')}. Those containers will fail to start or update until the secret is added back.</p>
          )}
          <div class="sm-actions">
            <button class="btn btn--secondary" type="button" onClick={() => setDeleting(null)}>Cancel</button>
            <button class="btn btn--secondary sm-danger" type="button" onClick={() => void del(deleting)}>Delete secret</button>
          </div>
        </Modal>
      )}
    </>
  )
}

function Row(p: {
  ns: string; row: KeyRow; value?: string; usedOpen: boolean; toggleUsed: () => void
  onReveal: () => void; onCopy: () => void; onEdit: () => void; onDelete: () => void
}) {
  const n = p.row.usedBy.length
  return (
    <li class="sm-row">
      <code class="sm-key">{p.row.key}</code>
      <span class="sm-value">
        {p.value !== undefined
          ? <code class="sm-revealed" aria-label={`Value of ${p.ns}/${p.row.key}`}>{p.value}</code>
          : <span class="sm-note" aria-label={`Hidden value, ${p.row.length} ${p.row.length === 1 ? 'character' : 'characters'}`}>{'•'.repeat(8)} {p.row.length} {p.row.length === 1 ? 'char' : 'chars'}</span>}
      </span>
      {n > 0
        ? <button class="chip chip--ok sm-chipbtn" type="button" aria-expanded={p.usedOpen} onClick={p.toggleUsed}>Used by {n}</button>
        : <span class="chip">Unused</span>}
      <span class="sm-spacer" />
      <span class="sm-row-actions">
        <button class="btn btn--secondary" type="button" onClick={p.onCopy}>Copy token</button>
        <button class="btn btn--secondary" type="button" aria-pressed={p.value !== undefined} onClick={p.onReveal}>{p.value !== undefined ? 'Hide' : 'Reveal'}</button>
        <button class="btn btn--secondary" type="button" onClick={p.onEdit}>Edit</button>
        <button class="btn btn--ghost sm-danger-text" type="button" onClick={p.onDelete}>Delete</button>
      </span>
      {p.usedOpen && n > 0 && <div class="sm-usedlist sm-note">Used by: {p.row.usedBy.join(', ')}</div>}
    </li>
  )
}

function EditModal({ editing, namespaces, api, onClose, onSaved }: {
  editing: Editing; namespaces: string[]; api: Api; onClose: () => void; onSaved: (d: ListPayload, label: string) => void
}) {
  const adding = editing.mode === 'add'
  const [ns, setNs] = useState(editing.ns)
  const [key, setKey] = useState(editing.key)
  const [value, setValue] = useState('')
  const [show, setShow] = useState(false)
  const [touched, setTouched] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const nsErr = adding ? nameError(ns, 'Namespace') : null
  const keyErr = adding ? nameError(key, 'Key') : null
  const valErr = value === '' ? 'Value is required.' : /[\r\n]/.test(value) ? 'A value cannot contain a line break.' : null
  const submit = async (e: Event) => {
    e.preventDefault()
    setTouched(true)
    if (nsErr || keyErr || valErr) return
    setBusy(true); setError(null)
    try {
      const d = await api.store({ action: adding ? 'add' : 'edit', ns, key, value })
      onSaved(d, `${adding ? 'Added' : 'Updated'} ${ns}/${key}.`)
    } catch (err) { setError((err as Error).message); setBusy(false) }
  }
  return (
    <Modal title={adding ? 'Add secret' : `Edit ${ns}/${key}`} onClose={onClose}>
      <form onSubmit={submit} noValidate>
        {adding ? (
          <>
            <Field label="Namespace" id="sm-f-ns" hint={touched && nsErr ? undefined : 'Usually the app name, for example myapp.'}>
              <input id="sm-f-ns" class="input" list="sm-ns-list" value={ns} autocomplete="off" placeholder="myapp"
                aria-invalid={touched && !!nsErr} onInput={(e) => setNs((e.target as HTMLInputElement).value)} />
              <datalist id="sm-ns-list">{namespaces.map((n) => <option value={n} key={n} />)}</datalist>
              {touched && nsErr && <div class="sm-err" role="alert">{nsErr}</div>}
            </Field>
            <Field label="Key" id="sm-f-key">
              <input id="sm-f-key" class="input" value={key} autocomplete="off" placeholder="api_key"
                aria-invalid={touched && !!keyErr} onInput={(e) => setKey((e.target as HTMLInputElement).value)} />
              {touched && keyErr && <div class="sm-err" role="alert">{keyErr}</div>}
            </Field>
          </>
        ) : (
          <p class="sm-note">The current value is not shown here. Use Reveal on the row if you need to see it. Enter the new value below.</p>
        )}
        <Field label={adding ? 'Value' : 'New value'} id="sm-f-val">
          <div class="sm-inline">
            <input id="sm-f-val" class="input sm-grow" type={(show ? 'text' : 'password') as 'text'} value={value} autocomplete="new-password"
              aria-invalid={touched && !!valErr} onInput={(e) => setValue((e.target as HTMLInputElement).value)} />
            <ShowToggle shown={show} onToggle={() => setShow(!show)} what="value" />
          </div>
          {touched && valErr && <div class="sm-err" role="alert">{valErr}</div>}
        </Field>
        {error && <div class="sm-err" role="alert"><span class="chip chip--alert">Error</span> {error}</div>}
        <div class="sm-actions">
          <button class="btn btn--secondary" type="button" onClick={onClose}>Cancel</button>
          <button class="btn btn--primary" type="submit" disabled={busy}>{adding ? 'Add secret' : 'Save value'}</button>
        </div>
      </form>
    </Modal>
  )
}

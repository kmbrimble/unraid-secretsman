import type { ComponentChildren } from 'preact'
import { useEffect, useRef, useState } from 'preact/hooks'
import { crumbs, type BrowsePayload } from './lib'

export interface Toast { id: number; kind: 'ok' | 'alert'; text: string }

/** Toasts carry a word (Success / Error), never colour alone. Errors stay until dismissed; they are never cleared by a refresh. */
export function Toasts({ toasts, dismiss }: { toasts: Toast[]; dismiss: (id: number) => void }) {
  return (
    <div class="sm-toasts" role="status" aria-live="polite">
      {toasts.map((t) => (
        <div class="card sm-toast" key={t.id}>
          <span class={`chip chip--${t.kind}`}>{t.kind === 'ok' ? 'Success' : 'Error'}</span>
          <span class="sm-toast-text">{t.text}</span>
          <button class="btn btn--ghost" type="button" onClick={() => dismiss(t.id)} aria-label="Dismiss message">
            Dismiss
          </button>
        </div>
      ))}
    </div>
  )
}

export function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: ComponentChildren }) {
  const ref = useRef<HTMLDivElement>(null)
  useEffect(() => {
    const prev = document.activeElement as HTMLElement | null
    const first = ref.current?.querySelector<HTMLElement>('input:not([readonly]), select, button')
    first?.focus()
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
      if (e.key === 'Tab' && ref.current) {
        const f = ref.current.querySelectorAll<HTMLElement>('input, select, button, textarea, a[href]')
        const list = Array.from(f).filter((el) => !(el as HTMLButtonElement).disabled)
        if (!list.length) return
        const a = list[0]
        const z = list[list.length - 1]
        if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus() }
        else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus() }
      }
    }
    document.addEventListener('keydown', onKey)
    return () => { document.removeEventListener('keydown', onKey); prev?.focus() }
  }, [])
  return (
    <div class="modal-backdrop" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose() }}>
      <div class="modal sm-modal" role="dialog" aria-modal="true" aria-labelledby="sm-modal-title" ref={ref}>
        <h2 id="sm-modal-title" class="sm-modal-title">{title}</h2>
        {children}
      </div>
    </div>
  )
}

export function Field({ label, id, hint, children }: { label: string; id: string; hint?: string; children: ComponentChildren }) {
  return (
    <div class="sm-field">
      <label for={id} class="sm-label">{label}</label>
      {children}
      {hint && <div class="sm-hint">{hint}</div>}
    </div>
  )
}

export function ShowToggle({ shown, onToggle, what }: { shown: boolean; onToggle: () => void; what: string }) {
  return (
    <button type="button" class="btn btn--secondary" onClick={onToggle} aria-pressed={shown}>
      {shown ? `Hide ${what}` : `Show ${what}`}
    </button>
  )
}

/** Folder picker: breadcrumb, one-line rows, "Use this folder". Browsing is confined to the server's roots (/mnt, /boot). */
export function FolderPicker({ start, browse, onPick, onClose }: {
  start: string
  browse: (dir: string) => Promise<BrowsePayload>
  onPick: (path: string) => void
  onClose: () => void
}) {
  const [data, setData] = useState<BrowsePayload | null>(null)
  const [error, setError] = useState<string | null>(null)
  const go = (dir: string) => {
    setError(null)
    browse(dir).then(setData).catch((e: Error) => {
      // A typed destination that does not exist yet (or is outside the roots) falls back to the first root.
      if (data === null && dir !== '') go('')
      else setError(e.message)
    })
  }
  useEffect(() => go(start), [])
  return (
    <Modal title="Choose a folder" onClose={onClose}>
      {data && (
        <nav class="sm-crumbs" aria-label="Folder path">
          {data.roots.length > 1 && data.roots.map((r) => (
            <button key={r} type="button" class="btn btn--ghost sm-crumb" onClick={() => go(r)}>{r}</button>
          ))}
          {data.roots.length > 1 && <span class="sm-note" aria-hidden="true">|</span>}
          {crumbs(data.path, data.roots).map((c, i, a) => (
            <button key={c.path} type="button" class="btn btn--ghost sm-crumb" aria-current={i === a.length - 1 ? 'location' : undefined}
              onClick={() => go(c.path)}>{c.label}</button>
          ))}
        </nav>
      )}
      {error && <div class="sm-err" role="alert"><span class="chip chip--alert">Error</span> {error}</div>}
      {!data && !error && <p class="sm-note">Loading&hellip;</p>}
      {data && (
        <ul class="sm-rows sm-folders">
          {data.parent && <li class="sm-row"><button type="button" class="btn btn--ghost sm-folder" onClick={() => go(data.parent!)}>.. (up one level)</button></li>}
          {data.dirs.map((d) => (
            <li class="sm-row" key={d}>
              <button type="button" class="btn btn--ghost sm-folder" onClick={() => go(data.path.replace(/\/$/, '') + '/' + d)}>{d}</button>
            </li>
          ))}
          {data.dirs.length === 0 && <li class="sm-row sm-note">No subfolders here.</li>}
        </ul>
      )}
      <div class="sm-actions">
        <button class="btn btn--secondary" type="button" onClick={onClose}>Cancel</button>
        <button class="btn btn--primary" type="button" disabled={!data} onClick={() => data && onPick(data.path)}>Use this folder</button>
      </div>
    </Modal>
  )
}

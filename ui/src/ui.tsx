import type { ComponentChildren } from 'preact'
import { useEffect, useRef } from 'preact/hooks'

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

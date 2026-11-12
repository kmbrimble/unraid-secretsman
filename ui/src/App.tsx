import { useCallback, useEffect, useRef, useState } from 'preact/hooks'
import { ApiError, postJson, type ListPayload } from './lib'
import { Toasts, type Toast } from './ui'
import { Secrets } from './Secrets'
import { Backup } from './Backup'

const STORE_API = '../scripts/store_api.php'
const BACKUP_API = '../scripts/backup_api.php'

export interface Api {
  store: <T = ListPayload>(data: Record<string, string>) => Promise<T>
  backup: <T>(data: Record<string, string>) => Promise<T>
}
export interface Notify { ok: (text: string, sticky?: boolean) => void; err: (e: unknown) => void }

export function App({ csrf }: { csrf: string }) {
  const [toasts, setToasts] = useState<Toast[]>([])
  const seq = useRef(0)
  const push = useCallback((kind: Toast['kind'], text: string, sticky = false) => {
    const id = ++seq.current
    setToasts((t) => [...t, { id, kind, text }])
    if (kind === 'ok' && !sticky) setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 12000)
  }, [])
  const dismiss = useCallback((id: number) => setToasts((t) => t.filter((x) => x.id !== id)), [])
  const notify: Notify = {
    ok: (text, sticky) => push('ok', text, sticky),
    err: (e) => push('alert', e instanceof ApiError || e instanceof Error ? e.message : String(e)),
  }
  const api: Api = {
    store: (d) => postJson(STORE_API, d, csrf),
    backup: (d) => postJson(BACKUP_API, d, csrf),
  }
  // Bumped after a restore so the secrets table reloads: restored/replaced keys must show up without a page refresh.
  const [storeVersion, setStoreVersion] = useState(0)

  useEffect(() => {
    if (!csrf) push('alert', 'No security token was provided by the page. Reload it from the SecretsMan tab.')
  }, [])

  return (
    <div class="sm-wrap">
      <Secrets api={api} notify={notify} version={storeVersion} />
      <Backup api={api} notify={notify} onRestored={() => setStoreVersion((v) => v + 1)} />
      <Toasts toasts={toasts} dismiss={dismiss} />
    </div>
  )
}

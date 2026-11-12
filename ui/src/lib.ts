export class ApiError extends Error {}

const NAME_RE = /^[A-Za-z0-9_.-]+$/

/** Client-side mirror of the server's [A-Za-z0-9_.-]+ rule. The server re-validates; this only saves a round trip. */
export function nameError(value: string, label: string): string | null {
  if (value === '') return `${label} is required.`
  if (!NAME_RE.test(value)) return `${label} may only contain letters, digits and _ . -`
  return null
}

export const tokenFor = (ns: string, key: string): string => `!secret ${ns}/${key}`

export function formatBytes(n: number): string {
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`
  return `${(n / 1024 / 1024).toFixed(1)} MB`
}

export const formatTime = (epoch: number): string => new Date(epoch * 1000).toLocaleString()

/**
 * POST urlencoded with the CSRF token as both a body field and a header. Never multipart: this host's nginx
 * auth_request times out on multipart bodies. A CSRF failure is HTTP 200 with an empty body (csrf_terminate()
 * exits without a status), so an empty body is reported as exactly that.
 */
export async function postJson<T = Record<string, unknown>>(
  url: string,
  data: Record<string, string>,
  csrf: string,
  fetchImpl: typeof fetch = fetch,
): Promise<T> {
  const body = new URLSearchParams({ ...data, csrf_token: csrf }).toString()
  let res: Response
  try {
    res = await fetchImpl(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
      body,
    })
  } catch {
    throw new ApiError('Could not reach the server. Check your connection and try again.')
  }
  const text = await res.text()
  if (text === '') {
    throw new ApiError('The server returned an empty response: your session or security token has probably expired. Reload this page.')
  }
  let json: { ok?: boolean; error?: string }
  try {
    json = JSON.parse(text)
  } catch {
    throw new ApiError(`The server returned something unreadable (HTTP ${res.status}).`)
  }
  if (!json.ok) throw new ApiError(json.error || `The request failed (HTTP ${res.status}).`)
  return json as T
}

export function backupFileToBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const r = new FileReader()
    r.onerror = () => reject(new ApiError('Could not read that file.'))
    r.onload = () => resolve(String(r.result).replace(/^data:[^,]*,/, ''))
    r.readAsDataURL(file)
  })
}

/** Copy text. The webGUI is plain http on the LAN, where navigator.clipboard is unavailable, so fall back to execCommand. */
export async function copyText(text: string): Promise<boolean> {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)
      return true
    }
  } catch {
    /* fall through to the legacy path */
  }
  const ta = document.createElement('textarea')
  ta.value = text
  ta.setAttribute('readonly', '')
  ta.style.position = 'fixed'
  ta.style.opacity = '0'
  document.body.appendChild(ta)
  ta.select()
  let ok = false
  try {
    ok = document.execCommand('copy')
  } catch {
    ok = false
  }
  ta.remove()
  return ok
}

export interface KeyRow { key: string; length: number; usedBy: string[] }
export interface Namespace { name: string; keys: KeyRow[] }
export interface Orphan { token: string; ns: string; key: string; usedBy: string[] }
export interface Problem { container: string; field: string; message: string }
export interface ListPayload { ok: true; namespaces: Namespace[]; orphans: Orphan[]; problems: Problem[] }

export interface Schedule { mode: string; hour: number; minute: number; weekday: number; dayOfMonth: number }
export interface Archive { name: string; mtime: number; bytes: number }
export interface BackupPayload {
  ok: true
  config: { destination: string; schedule: Schedule; retention: number }
  passwordSet: boolean
  archives: Archive[]
  lastRun: { time: number; ok: boolean; message: string } | null
  uploadLimitBytes: number
  message?: string
  restored?: { added?: string[]; collisions?: string[]; removed?: string[]; mode?: string }
}

export interface BrowsePayload { ok: true; roots: string[]; path: string; parent: string | null; dirs: string[] }

/** Breadcrumb segments for the folder picker: [{label, path}], starting at the root that contains `path`. */
export function crumbs(path: string, roots: string[]): { label: string; path: string }[] {
  const root = roots.find((r) => path === r || path.startsWith(r.replace(/\/$/, '') + '/')) ?? roots[0] ?? '/'
  const out = [{ label: root, path: root }]
  let acc = root.replace(/\/$/, '')
  for (const seg of path.slice(root.length).split('/').filter(Boolean)) {
    acc += '/' + seg
    out.push({ label: seg, path: acc })
  }
  return out
}

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render } from 'preact'
import { act } from 'preact/test-utils'
import { Secrets } from './Secrets'
import type { ListPayload } from './lib'

const SECRET = 'SENTINEL-SECRET-VALUE-xyz'
const payload = (): ListPayload => ({
  ok: true,
  orphans: [],
  problems: [],
  namespaces: [
    { name: 'Immich', keys: [{ key: 'db', length: 5, usedBy: ['immich-server'] }] },
    { name: 'immich', keys: [{ key: 'db', length: 7, usedBy: [] }, { key: 'jwt', length: 3, usedBy: [] }] },
    { name: 'zeta', keys: [{ key: 'token', length: 9, usedBy: ['z1', 'z2'] }] },
  ],
})

let root: HTMLElement
let api: { store: ReturnType<typeof vi.fn>; backup: ReturnType<typeof vi.fn> }
let notify: { ok: ReturnType<typeof vi.fn>; err: ReturnType<typeof vi.fn> }
let copied: string[]

const flush = () => act(async () => { await new Promise((r) => setTimeout(r, 0)) })
const click = (el: Element | null) => act(() => { (el as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true })) })
const type = (el: HTMLInputElement, v: string) => act(() => { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })) })
const byText = (sel: string, text: string) => Array.from(root.querySelectorAll(sel)).find((e) => (e.textContent ?? '').includes(text)) ?? null
const names = () => Array.from(root.querySelectorAll('.sm-ns-name')).map((e) => e.textContent)
const rows = () => root.querySelectorAll('li.sm-row').length

async function mount() {
  await act(async () => { render(<Secrets api={api as never} notify={notify as never} version={0} />, root) })
  await flush()
}

beforeEach(() => {
  root = document.createElement('div')
  document.body.appendChild(root)
  copied = []
  api = {
    store: vi.fn(async (d: Record<string, string>) => (d.action === 'reveal' ? { ok: true, value: SECRET } : payload())),
    backup: vi.fn(),
  }
  notify = { ok: vi.fn(), err: vi.fn() }
  // The webGUI is plain http, so copying goes through the textarea + execCommand path. Capture what it copies.
  ;(document as unknown as { execCommand: (c: string) => boolean }).execCommand = (c: string) => {
    const a = document.activeElement as HTMLTextAreaElement | null
    if (c === 'copy' && a?.tagName === 'TEXTAREA') copied.push(a.value.substring(a.selectionStart, a.selectionEnd))
    return true
  }
})
afterEach(() => { render(null, root); root.remove() })

describe('namespaces', () => {
  it('start collapsed, show real case, count and used/unused summary; Immich and immich are two sections', async () => {
    await mount()
    expect(names()).toEqual(['Immich', 'immich', 'zeta'])
    expect(rows()).toBe(0)
    const imm = byText('.sm-ns-toggle', 'Immich')!
    expect(imm.getAttribute('aria-expanded')).toBe('false')
    expect(imm.hasAttribute('aria-controls')).toBe(false) // the list is not rendered while collapsed: no dangling reference
    expect(imm.textContent).toContain('1 secret')
    expect(imm.textContent).toContain('1 used')
    const lower = root.querySelectorAll('.sm-ns-toggle')[1]
    expect(lower.textContent).toContain('2 secrets')
    expect(lower.textContent).toContain('2 unused')
  })

  it('toggle, Expand all and Collapse all', async () => {
    await mount()
    await click(root.querySelectorAll('.sm-ns-toggle')[1])
    expect(rows()).toBe(2)
    const t = root.querySelectorAll('.sm-ns-toggle')[1]
    expect(document.getElementById(t.getAttribute('aria-controls')!)).not.toBeNull()
    await click(byText('button', 'Expand all'))
    expect(rows()).toBe(4)
    await click(byText('button', 'Collapse all'))
    expect(rows()).toBe(0)
  })

  it('search expands matching namespaces and hides the rest; clearing restores what the user had open', async () => {
    await mount()
    await click(root.querySelectorAll('.sm-ns-toggle')[0]) // user opens Immich only
    expect(rows()).toBe(1)
    const search = root.querySelector('#sm-search') as HTMLInputElement
    await type(search, 'token')
    expect(names()).toEqual(['zeta'])
    expect(rows()).toBe(1) // zeta auto-expanded
    await type(search, '')
    expect(names()).toEqual(['Immich', 'immich', 'zeta'])
    expect(rows()).toBe(1) // back to: Immich open, others collapsed
    expect(byText('.sm-ns-toggle', 'zeta')!.getAttribute('aria-expanded')).toBe('false')
  })

  it('opening a namespace while searching does not change the saved state', async () => {
    await mount()
    const search = root.querySelector('#sm-search') as HTMLInputElement
    await type(search, 'immich')
    expect(names()).toEqual(['Immich', 'immich']) // case-insensitive match, both shown, both open
    expect(rows()).toBe(3)
    await type(search, '')
    expect(rows()).toBe(0)
  })
})

describe('adding', () => {
  it('opens the namespace the secret was added to, including a brand-new one typed in the modal', async () => {
    await mount()
    api.store.mockImplementation(async (d: Record<string, string>) => {
      if (d.action === 'add') {
        const p = payload()
        p.namespaces.push({ name: 'brandnew', keys: [{ key: 'k', length: 3, usedBy: [] }] })
        return p
      }
      return payload()
    })
    await click(byText('button', 'Add secret'))
    const set = (id: string, v: string) => type(document.querySelector(id) as HTMLInputElement, v)
    await set('#sm-f-ns', 'brandnew'); await set('#sm-f-key', 'k'); await set('#sm-f-val', 'abc')
    await click(document.querySelector('[role="dialog"] button[type="submit"]'))
    await flush()
    expect(byText('.sm-ns-toggle', 'brandnew')!.getAttribute('aria-expanded')).toBe('true')
    expect(rows()).toBe(1)
  })
})

describe('row actions', () => {
  const open = async () => { await mount(); await click(byText('button', 'Expand all')) }

  it('Copy NS+Key copies the reference and never calls reveal', async () => {
    await open()
    const btn = byText('button', 'Copy NS+Key')!
    expect(btn.getAttribute('aria-label')).toContain('!secret Immich/db')
    await click(btn)
    await flush()
    expect(copied).toEqual(['!secret Immich/db'])
    expect(api.store.mock.calls.some((c) => c[0].action === 'reveal')).toBe(false)
  })

  it('Copy secret fetches one named value per click, copies it, and leaves no value in the DOM, state or toast', async () => {
    await open()
    const btn = byText('button', 'Copy secret')!
    expect(btn.getAttribute('aria-label')).toContain('secret value of Immich/db')
    expect(api.store.mock.calls.filter((c) => c[0].action === 'reveal')).toHaveLength(0) // never pre-fetched
    await click(btn)
    await flush()
    const reveals = api.store.mock.calls.filter((c) => c[0].action === 'reveal')
    expect(reveals).toHaveLength(1)
    expect(reveals[0][0]).toMatchObject({ ns: 'Immich', key: 'db' })
    expect(copied).toEqual([SECRET])
    expect(document.body.innerHTML).not.toContain(SECRET)
    expect(document.querySelectorAll('textarea')).toHaveLength(0)
    const msg = notify.ok.mock.calls.at(-1)![0] as string
    expect(msg).toContain('Secret copied')
    expect(msg).toContain('Immich/db')
    expect(msg).not.toContain(SECRET)
    await click(btn) // a second click fetches again: nothing is cached
    await flush()
    expect(api.store.mock.calls.filter((c) => c[0].action === 'reveal')).toHaveLength(2)
  })

  it('Delete is an icon-only trash button with a clear label, then the confirm modal', async () => {
    await open()
    const del = root.querySelector('button[aria-label="Delete Immich/db"]') as HTMLElement
    expect(del).not.toBeNull()
    expect(del.querySelector('svg')).not.toBeNull()
    expect((del.textContent ?? '').trim()).toBe('')
    await click(del)
    expect(document.querySelector('[role="dialog"]')?.textContent).toContain('Delete Immich/db?')
  })

  it('Add to <ns> is a real button', async () => {
    await mount()
    const b = byText('button', 'Add to Immich')!
    expect(b.className).toContain('btn')
  })
})

describe('theme switch', () => {
  it('Auto is the default; Light/Dark persist to localStorage and apply; Auto returns to the server theme', async () => {
    localStorage.clear()
    document.documentElement.dataset.serverTheme = 'dark'
    await mount()
    const btn = (t: string) => byText('.sm-theme button', t)!
    expect(btn('Auto').getAttribute('aria-pressed')).toBe('true')
    expect(document.documentElement.dataset.theme).toBe('dark')
    await click(btn('Light'))
    expect(localStorage.getItem('secretsman-theme')).toBe('light')
    expect(document.documentElement.dataset.theme).toBe('light')
    expect(btn('Light').getAttribute('aria-pressed')).toBe('true')
    await click(btn('Auto'))
    expect(document.documentElement.dataset.theme).toBe('dark')
  })
})

import { describe, expect, it, vi } from 'vitest'
import { crumbs, nameError, tokenFor, formatBytes, backupFileToBase64, postJson, ApiError } from './lib'

describe('nameError', () => {
  it('accepts letters, digits, underscore, dot and dash', () => {
    expect(nameError('my-app_1.x', 'Namespace')).toBeNull()
  })
  it('rejects empty, spaces, slashes and unicode', () => {
    expect(nameError('', 'Key')).toMatch(/Key/)
    expect(nameError('a b', 'Key')).not.toBeNull()
    expect(nameError('a/b', 'Namespace')).not.toBeNull()
    expect(nameError('é', 'Key')).not.toBeNull()
  })
})

describe('tokenFor', () => {
  it('builds the template token', () => {
    expect(tokenFor('myapp', 'api_key')).toBe('!secret myapp/api_key')
  })
})

describe('formatBytes', () => {
  it('scales units', () => {
    expect(formatBytes(512)).toBe('512 B')
    expect(formatBytes(2048)).toBe('2.0 KB')
    expect(formatBytes(5 * 1024 * 1024)).toBe('5.0 MB')
  })
})

describe('postJson', () => {
  const ok = (body: string) => vi.fn().mockResolvedValue({ ok: true, status: 200, text: () => Promise.resolve(body) })
  it('sends urlencoded POST with the csrf token as field and header, never multipart', async () => {
    const f = ok('{"ok":true}')
    await postJson('x.php', { action: 'list', value: 'a&b=c' }, 'TOK', f as unknown as typeof fetch)
    const [, init] = f.mock.calls[0]
    expect(init.method).toBe('POST')
    expect(init.headers['Content-Type']).toBe('application/x-www-form-urlencoded')
    expect(init.headers['X-CSRF-Token']).toBe('TOK')
    const body = new URLSearchParams(init.body as string)
    expect(body.get('csrf_token')).toBe('TOK')
    expect(body.get('value')).toBe('a&b=c')
  })
  it('turns an empty 200 body (a CSRF rejection) into a readable error', async () => {
    await expect(postJson('x.php', { action: 'list' }, 'TOK', ok('') as unknown as typeof fetch)).rejects.toThrow(/session|reload/i)
  })
  it('surfaces the server error message', async () => {
    const f = ok('{"ok":false,"error":"secretsman: nope"}')
    await expect(postJson('x.php', {}, 'T', f as unknown as typeof fetch)).rejects.toThrow(ApiError)
    await expect(postJson('x.php', {}, 'T', f as unknown as typeof fetch)).rejects.toThrow('secretsman: nope')
  })
  it('reports a network failure', async () => {
    const f = vi.fn().mockRejectedValue(new TypeError('x'))
    await expect(postJson('x.php', {}, 'T', f as unknown as typeof fetch)).rejects.toThrow(/reach/i)
  })
})

describe('backupFileToBase64', () => {
  it('encodes bytes without a data-URL prefix', async () => {
    const file = new File([new Uint8Array([104, 105])], 'a.bin')
    expect(await backupFileToBase64(file)).toBe('aGk=')
  })
})

describe('crumbs', () => {
  it('starts at the containing root and adds one crumb per folder', () => {
    expect(crumbs('/mnt/user/backups/sm', ['/mnt', '/boot'])).toEqual([
      { label: '/mnt', path: '/mnt' }, { label: 'user', path: '/mnt/user' },
      { label: 'backups', path: '/mnt/user/backups' }, { label: 'sm', path: '/mnt/user/backups/sm' },
    ])
    expect(crumbs('/boot', ['/mnt', '/boot'])).toEqual([{ label: '/boot', path: '/boot' }])
  })
})

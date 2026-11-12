import { beforeEach, describe, expect, it } from 'vitest'
import { applyTheme, parseThemePref, readThemePref, saveThemePref, THEME_KEY } from './lib'

describe('theme preference', () => {
  beforeEach(() => { localStorage.clear(); document.documentElement.removeAttribute('data-theme'); document.documentElement.dataset.serverTheme = 'dark' })

  it('only light and dark are real choices; anything else is auto', () => {
    expect(parseThemePref('light')).toBe('light')
    expect(parseThemePref('dark')).toBe('dark')
    expect(parseThemePref('auto')).toBe('auto')
    expect(parseThemePref('purple')).toBe('auto')
    expect(parseThemePref(null)).toBe('auto')
  })

  it('persists in localStorage under one shared key and reads it back', () => {
    expect(saveThemePref('light')).toBe(true)
    expect(localStorage.getItem(THEME_KEY)).toBe('light')
    expect(readThemePref()).toBe('light')
  })

  it('falls back to auto, without throwing, when storage is unavailable', () => {
    const broken = { getItem: () => { throw new Error('denied') }, setItem: () => { throw new Error('denied') } }
    expect(readThemePref(broken)).toBe('auto')
    expect(saveThemePref('dark', broken)).toBe(false)
  })

  it('auto follows the server (Unraid) theme; light/dark override it', () => {
    applyTheme('auto')
    expect(document.documentElement.dataset.theme).toBe('dark')
    applyTheme('light')
    expect(document.documentElement.dataset.theme).toBe('light')
    document.documentElement.dataset.serverTheme = 'light'
    applyTheme('auto')
    expect(document.documentElement.dataset.theme).toBe('light')
  })
})

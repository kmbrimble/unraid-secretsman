// Copied from kmbrimble/design-system scripts/contrast-check.mjs; only the tokens path differs.
// WCAG contrast check. Parses tokens.css directly (no duplicated hex).
// Run: node scripts/contrast-check.mjs   (exit 1 on any failure)
import { readFileSync } from 'node:fs'

const css = readFileSync(new URL('../src/styles/tokens.css', import.meta.url), 'utf8')

// Returns the body of the first rule whose selector line starts with `selector`.
// ponytail: assumes no nested braces inside the rule (true for tokens.css; @media is handled separately).
function block(src, selector) {
  const i = src.indexOf(selector)
  if (i < 0) throw new Error(`tokens.css: no block for ${selector}`)
  const open = src.indexOf('{', i)
  return src.slice(open + 1, src.indexOf('}', open))
}
function decls(body) {
  const out = {}
  for (const m of body.matchAll(/--([\w-]+):\s*([^;]+);/g)) out[m[1]] = m[2].trim()
  return out
}

const light = decls(block(css, ':root,\n[data-theme=\'light\']'))
const darkOnly = decls(block(css, ':root[data-theme=\'dark\']'))
const mediaOnly = decls(block(css.slice(css.indexOf('@media (prefers-color-scheme: dark)')), ':root:not('))
const dark = { ...light, ...darkOnly }

let failed = false
if (JSON.stringify(Object.entries(darkOnly).sort()) !== JSON.stringify(Object.entries(mediaOnly).sort())) {
  failed = true
  console.error('FAIL  dark block and prefers-color-scheme block differ in tokens.css')
}

function lin(c) {
  const s = c / 255
  return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
}
function luminance(hex) {
  const n = parseInt(hex.slice(1), 16)
  return 0.2126 * lin((n >> 16) & 255) + 0.7152 * lin((n >> 8) & 255) + 0.0722 * lin(n & 255)
}
function contrast(a, b) {
  const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (l1 + 0.05) / (l2 + 0.05)
}

// [label, foreground token | literal hex, background token, minimum ratio]
const pairs = [
  ['fg on bg', 'fg', 'bg', 4.5],
  ['fg on surface', 'fg', 'surface', 4.5],
  ['fg on surface-2', 'fg', 'surface-2', 4.5],
  ['fg-muted on bg', 'fg-muted', 'bg', 4.5],
  ['fg-muted on surface', 'fg-muted', 'surface', 4.5],
  ['fg-muted on surface-2', 'fg-muted', 'surface-2', 4.5],
  ['border-strong vs surface (non-text, 3:1)', 'border-strong', 'surface', 3],
  ['accent vs bg (non-text, 3:1)', 'accent', 'bg', 3],
  ['accent vs surface (non-text, 3:1)', 'accent', 'surface', 3],
  ['#ffffff on gradient-start', '#ffffff', 'gradient-start', 4.5],
  ['#ffffff on gradient-end', '#ffffff', 'gradient-end', 4.5],
  ['ok text on ok-tint', 'status-ok', 'status-ok-tint', 4.5],
  ['warn text on warn-tint', 'status-warn', 'status-warn-tint', 4.5],
  ['alert text on alert-tint', 'status-alert', 'status-alert-tint', 4.5],
  ['warn-on-fill text on warn fill', 'status-warn-on-fill', 'status-warn', 4.5],
  ['alert-on-fill text on alert fill', 'status-alert-on-fill', 'status-alert', 4.5],
  ['on-accent on accent', 'on-accent', 'accent', 4.5],
  ['accent text on surface', 'accent', 'surface', 4.5],
]

for (const [name, scheme] of Object.entries({ light, dark })) {
  console.log(`\n${name}`)
  for (const [label, a, b, min] of pairs) {
    const fa = a.startsWith('#') ? a : scheme[a]
    const fb = scheme[b]
    if (!fa || !fb) { failed = true; console.log(`  FAIL  ${label}: token missing`); continue }
    const ratio = contrast(fa, fb)
    const ok = ratio >= min
    if (!ok) failed = true
    console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${label.padEnd(42)} ${ratio.toFixed(2)}:1  (need ${min}:1)`)
  }
}

if (failed) {
  console.error('\nOne or more checks failed.')
  process.exit(1)
}
console.log('\nAll pairs pass.')

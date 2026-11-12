---
version: alpha
name: Kiztigs Design System
description: House design system for apps and pages Claude builds for Kieren. Neutral chrome, violet-to-magenta accent, real elevation.
colors:
  bg: "#f6f4fb"
  surface: "#ffffff"
  surface-2: "#efebf8"
  fg: "#1a1523"
  fg-muted: "#635f72"
  border: "#e2ddf0"
  border-strong: "#8f81b5"
  primary: "#7c3aed"
  accent: "#7c3aed"
  accent-2: "#a78bfa"
  on-accent: "#ffffff"
  gradient-start: "#7c3aed"
  gradient-end: "#be185d"
  status-ok: "#1e6b3a"
  status-ok-tint: "#e4f0e8"
  status-warn: "#8a5a00"
  status-warn-tint: "#fbeedc"
  status-warn-on-fill: "#ffffff"
  status-alert: "#b3261e"
  status-alert-tint: "#fbe4e2"
  status-alert-on-fill: "#ffffff"
typography:
  text-xs:
    fontFamily: system-ui
    fontSize: 13px
    fontWeight: 400
    lineHeight: 1.4
  text-sm:
    fontFamily: system-ui
    fontSize: 15px
    fontWeight: 400
    lineHeight: 1.4
  text-base:
    fontFamily: system-ui
    fontSize: 17px
    fontWeight: 400
    lineHeight: 1.4
  text-lg:
    fontFamily: system-ui
    fontSize: 20px
    fontWeight: 600
    lineHeight: 1.15
  text-xl:
    fontFamily: system-ui
    fontSize: 24px
    fontWeight: 700
    lineHeight: 1.15
  text-2xl:
    fontFamily: system-ui
    fontSize: 32px
    fontWeight: 700
    lineHeight: 1.15
rounded:
  sm: 8px
  md: 14px
  lg: 20px
  xl: 24px
  full: 9999px
spacing:
  space-1: 4px
  space-2: 8px
  space-3: 12px
  space-4: 16px
  space-5: 20px
  space-6: 24px
  space-8: 32px
---

# Kiztigs Design System

Source of truth for values is `tokens.css`; this file is the brief for agents. The front matter above must match the **light** values in `tokens.css` (`scripts/check-design-md.mjs` enforces it). Dark values live only in `tokens.css`.

## Overview

Calm, neutral chrome with one expressive thread: a violet → magenta accent used sparingly, on primary actions, the current item and focus. Surfaces have **real elevation**: layered, violet-tinted shadows and a top highlight. Cards are never flat hairline-only boxes. The light theme is a soft lavender off-white (`bg`), the dark theme a deep charcoal-navy. Never pure white or pure black page backgrounds.

## Colour rules

- Neutrals (`bg`, `surface`, `surface-2`, `fg`, `fg-muted`, `border`) make up almost everything. Do not tint chrome with extra hues.
- `accent` is for: focus rings, the current item's ring/glow, links, and the active marker. `accent-2` is its lighter companion for secondary highlights.
- The gradient (`gradient-start` → `gradient-end`) fills **primary actions and the `.pill` label only**.
- Status colours (`status-ok`, `status-warn`, `status-alert`) carry meaning (health, warning, failure), never decoration. Text uses the status colour on its `-tint`. Amber and red may fill a whole bar; text on that fill uses `status-*-on-fill`.
- **Never put white text on `--accent` in dark mode** (dark `accent` is light violet). Use `--on-accent` on a solid accent fill, or use the gradient (white text is safe there).
- Every new colour pairing must pass `node scripts/contrast-check.mjs`.

## Typography

`system-ui` only (`--font-sans`), `--font-mono` for code. **No webfonts**: these apps are offline-first and a failed font load causes layout shift. Numerals are tabular (`font-variant-numeric: tabular-nums`, set in `base.css`) so times and figures align. Section labels are small, uppercase, muted, letter-spaced (`.section-label`). Nothing may fall below ~15px effective at an 80% root font size: the scale (`--text-xs` 0.8125rem to `--text-2xl` 2rem) is checked against that; use `--text-xs` only for labels and metadata.

## Spacing and radius

4px base: `--space-1` (4px) to `--space-8` (32px). Radius is generous: `--radius-md` for inputs, `--radius-lg` for cards and modals, `--radius-xl` for hero cards, `--radius-full` for pills, chips and buttons.

## Elevation

Three levels: `--shadow-1` (resting controls), `--shadow-2` (cards, primary buttons), `--shadow-3` (current item, modals). Light uses violet-tinted layered shadows; dark adds a violet glow and a 1px top highlight because black shadows vanish on dark. Use the tokens; never write a one-off `box-shadow`.

## Components

All in `components.css`, framework-agnostic.

- `.btn`: base pill button (surface + hairline + shadow-1). Modifiers `.btn--primary` (gradient, **one per view**), `.btn--secondary` (outlined with `border-strong`), `.btn--ghost` (no chrome, tertiary actions).
- `.btn-icon`: circular 44px icon-only button. Always set `aria-label`.
- `.card`: the default raised container. `.card--current` marks the active item (stronger shadow, accent ring, soft glow).
- `.pill`: small uppercase gradient label such as NOW. Use at most one per card.
- `.chip` with `--ok`, `--warn`, `--alert`: status tags and indicators (tint background + status text). Plain `.chip` is neutral.
- `.input`: 44px text field, `border-strong`, accent border on focus.
- `.section-label`: muted uppercase heading above a group.
- `.modal-backdrop` + `.modal`: centred dialog over a 50% scrim.
- `.divider-dashed`: vertical dashed connector between sequential items; `--current` tints it accent.

## Touch and accessibility

- 44px minimum touch target on every control, via `max(44px, 2.75rem)` so small-font settings can't shrink it.
- Visible `:focus-visible` ring (2px accent, 2px offset) on everything interactive. No hover-only affordances: this is touch-first.
- Motion respects `prefers-reduced-motion` (zeroed globally in `base.css`).
- `scripts/contrast-check.mjs` must pass (text 4.5:1, non-text 3:1) in both schemes.

## Do / Don't

- Do take every value from `tokens.css`. Don't add a hex value anywhere else (the only literal is `#fff` on the gradient).
- Don't rely on hover to reveal an action or state.
- Don't make flat cards: no hairline-only containers, always a shadow token.
- Don't spray hue across chrome: borders, headers and icons stay neutral.
- Don't put gradients on anything but primary buttons, `.pill`, and brand wordmarks.
- Don't use white text on `--accent` in dark mode (use `--on-accent`).
- Don't add webfonts or `@import` remote CSS.

## How to adopt

1. Copy `tokens.css`, `base.css` and `components.css` into the app (`shadcn-bridge.css` only for Tailwind v4/shadcn apps).
2. Record the design-system commit SHA in a comment header of each copied file.
3. Apps with no theme script get dark mode from the OS automatically. Apps that set `data-theme="light|dark"` on `<html>` override it.
4. Never fork values locally. To change a value, change it in this repo, run the contrast check, regenerate `preview.png`, then re-copy.

## Android / Compose

Native Android apps (Kotlin, Jetpack Compose, Material 3) use the generated Kotlin in `android/`, built from the same `tokens.css`. Everything above applies unchanged (accent used sparingly, real elevation, no pure white or black backgrounds); this section only says how it lands in Compose. What has no Compose equivalent is listed in `android/README.md`.

### Vendoring

1. In this repo, `node scripts/gen-android.mjs --out <app>/<module>/src/main/java/com/kiztigs/design --sha $(git rev-parse --short HEAD)` writes `KiztigsTokens.kt` and `KiztigsTheme.kt`. Each carries `// vendored from kmbrimble/design-system@<sha> — do not edit, change upstream`.
2. Copy `DESIGN.md` to the app root and add the CLAUDE.md non-negotiable from the house-design-system skill's "Adoption line".
3. Wrap the app in `KiztigsTheme { }` once, at the root. No Material You dynamic colour.
4. Never edit the vendored Kotlin. To change a value, change `tokens.css`, run the checks (`contrast-check`, `check-design-md`, `check-android`), regenerate, then re-vendor.

### Component patterns

| House pattern | Compose |
| --- | --- |
| `.btn--primary` (gradient, one per view) | `Button` with `shape = KiztigsTokens.Radius.full`, `containerColor = Color.Transparent`, `contentColor = Color.White` and `Modifier.background(colors.gradientPrimary, KiztigsTokens.Radius.full)`; label in `labelLarge`. `Color.White` on the gradient is the one allowed literal, as `#fff` is on the web. |
| `.btn--secondary`, `.btn--ghost` | `OutlinedButton` with `BorderStroke(1.dp, colors.borderStrong)`; `TextButton`. Both `Radius.full`. |
| `.btn-icon` | `IconButton` (48dp minimum) with a `contentDescription` on the `Icon`. |
| `.card`, `.card--current` | `Surface`/`Card` with `shape = MaterialTheme.shapes.large`, `Modifier.kiztigsShadow(colors.shadow2, shape)`, container `surface`. Current item: `shadow3` plus a `colors.accent` border. Never a flat hairline-only card. |
| List row | `ListItem` on `surface` (or a `Row` with `Spacing.space3/space4` padding): `bodyLarge` title, `bodySmall`/`fgMuted` supporting text, `HorizontalDivider(color = colors.border)`. The current row uses the current-card treatment, not an accent background. |
| `.chip` + `--ok/--warn/--alert` | `AssistChip`/`Surface` with `Radius.full`, container `statusXTint`, text `statusX`, and always a word or icon. |
| `.input` | `OutlinedTextField`, `shape = MaterialTheme.shapes.medium`, border `borderStrong`, focused border `accent`. |
| `.section-label` | `Text` in `labelMedium`, `fgMuted`, uppercased. |
| `.modal` | `AlertDialog`/`BasicAlertDialog`, `shape = MaterialTheme.shapes.large`, container `surface`, `shadow3`. |
| Top bar | `TopAppBar` with `containerColor = background` (or `surface`), title `titleLarge`, icons `onSurface`. Neutral: no accent or gradient fill. A whole-screen risk state fills with `statusAlert`/`statusWarn` and the matching `…OnFill` text. |
| Bottom bar | `NavigationBar` on `surface`. The selected item uses `accent` for icon and indicator ring only; unselected stays `fgMuted`. |
| Accent used sparingly | Allowed: the one primary action, the current item's ring/glow, focus, links, the active nav marker. Everything else is neutral. At most one gradient `Button` per screen, and one NOW-style pill per card. |

Never white text on a solid `accent` fill in dark: use `onPrimary` (M3 does this by default). White text is only for the gradient.

### Banned in an adopting app

Outside the `com.kiztigs.design` package:

- `Color(0x…)`, `Color.Black`, `Color.Red` and other named colours (`Color.White` only as text on the gradient), `Color.parse`/`toColorInt` of literals. Use `MaterialTheme.colorScheme.*` or `KiztigsTokens.colors.*`.
- Literal `.dp` and `.sp` (`16.dp`, `14.sp`) and `RoundedCornerShape(12.dp)`. Use `KiztigsTokens.Spacing`, `Radius`, `Type`, `MaterialTheme.typography` and `MaterialTheme.shapes`. `0.dp`, `Dp.Unspecified` and border widths of `1.dp` or `2.dp` (the Android form of the web's allowed 1px/2px borders) are fine. Sizes with no token (artwork, fixed icon sizes) are justified individually in the handback, never silently kept.
- A one-off `Modifier.shadow(...)` or `elevation = N.dp`. Use `kiztigsShadow`.
- Webfonts or `FontFamily(Font(...))`. System fonts only.
- Hard-coded colours in drawables, `colors.xml` or `themes.xml`: vector `android:fillColor="#…"` other than the black tint mask `#FF000000` (tinted by `Icon` at the call site), `@color/` literals, `ShapeDrawable` colours. Use `?attr/…` or tint in Compose.
- `dynamicColorScheme`/Material You.

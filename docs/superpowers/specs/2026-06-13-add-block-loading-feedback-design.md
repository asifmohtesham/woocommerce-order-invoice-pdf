# Add Block loading feedback — design

**Date:** 2026-06-13
**Scope:** Customizer → Custom Blocks → "Add a block"

## Problem

Clicking **Add a block** fires an AJAX POST (`woi_pdf_templates_add_custom_block`)
and appends the returned form HTML on success. Between click and render there is
no feedback. On a slow connection the user gets no signal that anything happened
and can double-click, firing duplicate requests. The handler also has no `error`
callback, so a failed request leaves the user with nothing.

## Behavior

Click → button greys out + WP spinner spins → form appended (or inline error) →
button re-enabled.

## Changes (client-side only)

The `Add a block` control is a `<div class="button add-custom-block">` — a div
styled as a button, not a real `<button>` — so "disable" means a CSS class the
click handler respects, not the `disabled` attribute.

### 1. Markup — `includes/Editor/EditorSettings.php` (~line 1494)

Add a WP spinner and an inline error container next to the button:

```php
<br/><div class="button add-custom-block">…</div>
<span class="spinner woi-add-block-spinner"></span>
<div class="woi-add-block-error" style="display:none"></div>
```

### 2. Handler — `assets/js/editor.js` (lines 50–79)

- **Re-entry guard:** if the button has `is-loading`, `return` immediately
  (kills double-click duplicate requests).
- **Before AJAX:** add `is-loading` to button, `is-active` to the adjacent
  `.spinner`, hide any prior error.
- **`success`:** unchanged (append block, init selects/accordion/requirements).
- **`error`:** show `.woi-add-block-error` with "Could not add block, please try
  again."
- **`complete`:** remove `is-loading` and spinner `is-active` — always resets.

### 3. CSS — `assets/css/editor.css`

- `.add-custom-block.is-loading { opacity:.6; pointer-events:none; }`
- Vertical-align the spinner inline with the button.
- Red inline error text for `.woi-add-block-error`.

## Out of scope

The PHP AJAX handler is untouched — same action and nonce. No PHP test changes;
this is a JS/CSS feedback layer plus two markup hooks.

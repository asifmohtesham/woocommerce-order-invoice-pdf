# Scrollable Document Tab Bar — Design Spec

**Date:** 2026-06-10  
**Feature:** Customiser → document tab bar (Invoice, Packing Slip, etc.)  
**Problem:** Six document tabs wrap to two or three lines at normal admin widths, looking cluttered.  
**Solution:** Single-row scrollable strip with ghost ‹ › arrow buttons and gradient-fade edge cues (Option A).

---

## 1. Affected files

| File | Change type |
|------|-------------|
| `includes/Editor/EditorSettings.php` | Wrap `<ul class="document-tabs">` in new markup |
| `assets/css/editor.css` | New/updated rules for wrapper, track, buttons, fades |
| `assets/js/editor.js` | Add `initTabScroll()` called after jQuery UI tabs init |

---

## 2. HTML structure

The PHP that renders the tab list (around line 1369) changes from:

```html
<ul class="document-tabs">
  <li><a href="#…">Invoice</a></li>
  …
</ul>
```

to:

```html
<div class="tab-scroll-wrapper">
  <button class="tab-scroll-btn tab-scroll-prev" aria-label="Previous tabs">&#8249;</button>
  <div class="tab-scroll-track">
    <ul class="document-tabs">
      <li><a href="#…">Invoice</a></li>
      …
    </ul>
  </div>
  <button class="tab-scroll-btn tab-scroll-next" aria-label="Next tabs">&#8250;</button>
</div>
```

The `<ul>` content and attributes are unchanged. jQuery UI's `.tabs()` call on `#documents` continues to manage active state exactly as before — it targets `.document-tabs` children regardless of the new wrapper.

---

## 3. CSS

### `.tab-scroll-wrapper`
```css
display: flex;
align-items: flex-end;
overflow: hidden;
margin: 0 0 -2px 20px;   /* replaces current .document-tabs margin */
position: relative;
z-index: 999;
```

### `.tab-scroll-track`
```css
flex: 1;
overflow: hidden;
position: relative;
```

Gradient fade overlays (signal hidden tabs):
```css
.tab-scroll-track::before,
.tab-scroll-track::after {
  content: '';
  position: absolute;
  top: 0; bottom: 0;
  width: 28px;
  pointer-events: none;
  z-index: 2;
  transition: opacity 0.15s;
}
.tab-scroll-track::before {
  left: 0;
  background: linear-gradient(to right, #f0f0f1, transparent);
}
.tab-scroll-track::after {
  right: 0;
  background: linear-gradient(to left, #f0f0f1, transparent);
}
/* hidden when scrolled to that edge */
.tab-scroll-track.at-start::before { opacity: 0; }
.tab-scroll-track.at-end::after    { opacity: 0; }
```

### `.document-tabs` (updated)
```css
/* remove: display: block; overflow: hidden; */
display: flex;
flex-wrap: nowrap;
overflow: hidden;       /* JS drives scrollLeft, not native scroll */
list-style: none;
margin: 0;
padding: 0;
```

### `.tab-scroll-btn`
```css
flex-shrink: 0;
display: flex;
align-items: center;
justify-content: center;
width: 24px;
height: 38px;
padding: 0;
background: transparent;
border: none;
font-size: 20px;
font-weight: 300;
color: #aaa;
cursor: pointer;
transition: color 0.15s;
visibility: visible;
}
.tab-scroll-btn:hover { color: #1d2327; }
.tab-scroll-btn.hidden { visibility: hidden; }
```

The existing `.document-tabs` position/z-index/margin-left rules are **removed** (replaced by `.tab-scroll-wrapper`). All other `.document-tabs li` and `.ui-state-default` rules are unchanged.

---

## 4. JavaScript — `initTabScroll()`

Added to `assets/js/editor.js` immediately after the existing `$('#documents').tabs().show()` call.

### Responsibilities
1. **Measure** — read `ul.scrollLeft`, `ul.scrollWidth`, `ul.clientWidth` to determine whether left/right scroll is possible.
2. **Update button & fade visibility** — add/remove `.hidden` on the `<button>` elements; add/remove `.at-start` / `.at-end` on `.tab-scroll-track`.
3. **Scroll on click** — each button scrolls the `<ul>` by `Math.round(ul.clientWidth * 0.5)` pixels (half the visible width), using `ul.scrollLeft +=/-=`.
4. **React to scroll** — listen to `ul` `scroll` event, debounced 50 ms, call update.
5. **React to resize** — listen to `window` `resize` event, debounced 150 ms, call update.
6. **Initial call** — run update once on init so buttons/fades are correct before the user touches anything.

### Pseudocode
```js
function initTabScroll() {
  const $wrapper = $( '#documents .tab-scroll-wrapper' );
  if ( ! $wrapper.length ) return;

  const $track  = $wrapper.find( '.tab-scroll-track' );
  const $ul     = $wrapper.find( '.document-tabs' );
  const $prev   = $wrapper.find( '.tab-scroll-prev' );
  const $next   = $wrapper.find( '.tab-scroll-next' );
  const ul      = $ul[0];

  function update() {
    const atStart = ul.scrollLeft <= 0;
    const atEnd   = ul.scrollLeft + ul.clientWidth >= ul.scrollWidth - 1;
    $prev.toggleClass( 'hidden', atStart );
    $next.toggleClass( 'hidden', atEnd );
    $track.toggleClass( 'at-start', atStart );
    $track.toggleClass( 'at-end',   atEnd );
  }

  $prev.on( 'click', function() {
    ul.scrollLeft -= Math.round( ul.clientWidth * 0.5 );
  } );
  $next.on( 'click', function() {
    ul.scrollLeft += Math.round( ul.clientWidth * 0.5 );
  } );

  $ul.on( 'scroll', debounce( update, 50 ) );
  $( window ).on( 'resize.tabScroll', debounce( update, 150 ) );

  update();
}
```

A minimal `debounce` helper is added at the top of the file's closure if one doesn't already exist.

---

## 5. Edge cases

| Scenario | Behaviour |
|----------|-----------|
| All tabs fit (narrow doc count) | Both buttons hidden, no fades — strip looks identical to today |
| Only 1–2 tabs hidden on one side | Only the relevant arrow appears |
| Active tab scrolled off-screen | Not an issue in practice; jQuery UI always renders the active tab visible. If needed in future, `initTabScroll` can scroll the active `<li>` into view on tab change. |
| Window resize makes all tabs fit | `resize` listener hides both arrows automatically |
| Mobile (`max-width: 767px`) | Existing mobile overrides remain; wrapper doesn't change mobile flow |

---

## 6. What does NOT change

- jQuery UI `.tabs()` initialisation and active-state management
- All `.document-tabs li`, `.ui-state-default`, `.ui-tabs-active` CSS rules
- The PHP loop that generates `<li>` items
- The JS handlers for tab click → preview document type sync

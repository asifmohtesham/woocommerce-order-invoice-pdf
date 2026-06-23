# Collapsible line-item column property tiles

## Problem

The **LINE ITEMS COLUMNS** panel in the Block Invoice editor
(`src/block-editor/ColumnEditor.js`) renders each column as a tall tile showing
Title, Arabic header, Width, Align, type-specific options, Style, and Style
target. With several columns the panel becomes a long scroll, making it hard to
see the column order at a glance or jump between columns.

## Goal

Let the user collapse each tile down to just its header (column type name +
move/delete controls), and expand it again to edit. Provide a global
collapse-all / expand-all toggle.

## Decisions (from brainstorming)

- **Trigger:** both a dedicated chevron button in the tile header *and* clicking
  the header type-name area toggle that tile. Existing move/delete buttons keep
  their own behavior (do not toggle).
- **Default state:** all tiles start **collapsed** when the editor loads.
- **Persistence:** **ephemeral** — collapse state lives in React state and
  resets to the default (all collapsed) on reload. No config/schema/PHP change.
- **Global control:** one "Collapse all / Expand all" button at the top of the
  panel.
- **Newly-added column:** appears **expanded** so the user can edit it
  immediately; other tiles keep their current state.

## Design

### Collapse-state model — pure module `collapseState.js`

Collapse state is a `Set` of **collapsed column indices**. All transforms are
pure functions so they can be unit-tested without rendering:

- `allCollapsed(len)` → `Set` of `0..len-1` (the initial state).
- `toggle(set, i)` → new set with `i` flipped.
- `move(set, i, j)` → new set with membership of indices `i` and `j` swapped
  (mirrors the column array swap in `ColumnEditor.move`).
- `remove(set, i)` → new set with `i` dropped and every index `> i` shifted down
  by 1 (mirrors `Array.filter` removal).
- `add(set)` → unchanged set (the appended column's index is not in the set, so
  it renders expanded).
- `isAllCollapsed(set, len)` → `len > 0 && set.size === len` (drives the global
  toggle label).

Keying by index is simple and stays aligned because every column-array mutation
(`move`, `remove`, `add`) has a matching set transform applied in the same
handler.

### Component wiring — `ColumnEditor.js`

- Add `const [ collapsed, setCollapsed ] = useState( null )`. When columns first
  load (the existing `getEditorConfig` effect), seed `collapsed` with
  `allCollapsed( values.length )`.
- In `move`, `remove`, `add` handlers, apply the matching `collapseState`
  transform alongside the existing column-array update.
- Tile header (`.woi-col-head`):
  - Add a chevron `Button` (`chevronDown` expanded / `chevronUp` collapsed) that
    calls `setCollapsed( toggle( collapsed, i ) )`.
  - Make `.woi-col-type` a clickable element (button-like) that toggles the same
    way. Move/delete buttons already sit in `.woi-col-actions`; their clicks are
    unaffected.
- When `collapsed.has( i )`, render only `.woi-col-head`; skip all field
  controls below it.
- Global toggle: a `Button` above the tile list. Label = "Expand all" when
  `isAllCollapsed`, else "Collapse all". Click sets `collapsed` to
  `allCollapsed(len)` or an empty set accordingly.

### CSS — `assets/css/block-editor-shell.css`

- Make the clickable `.woi-col-type` show a pointer cursor and read as a button
  (reset default button styling: inherit font, no background/border, padding to
  keep the existing look).
- Minor spacing for the global toggle button row.

## Out of scope

- No persistence of collapse state across reloads.
- No PHP, no editor-config schema field, no token/PDF change.

## Testing

- **Unit (Jest):** `collapseState.test.js` covers `allCollapsed`, `toggle`,
  `move` (swap up/down, boundaries), `remove` (drop + shift), `add` (unchanged),
  `isAllCollapsed` (empty/partial/full, `len === 0`).
- **Build:** `npm run build` succeeds; bundle rebuilt at landing.
- **Manual:** in the editor, verify tiles load collapsed, chevron and header
  toggle, move/delete still work and keep state aligned, added column appears
  expanded, global toggle flips all and its label updates.

## Landing notes

Touches `src/` + assets, so it needs `npm run build` and a **two-string version
bump** at landing per CLAUDE.md. No version bump for this doc alone.

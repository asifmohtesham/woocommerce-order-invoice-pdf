# CLAUDE.md — WooCommerce Orders Invoice PDF

WordPress/WooCommerce plugin: PDF invoices, packing slips, proforma, credit
notes, receipts. PDF engine is **mPDF** (vendored/Strauss-prefixed). The active
authoring surface is the **Block Invoice Template** editor (`src/block-editor/`,
built with `@wordpress/scripts` to `assets/js/block-editor/index.js`); PDF HTML
is assembled server-side by `includes/Visual/TemplateTokens.php` from `{{token}}`
placeholders. Tests: PHPUnit (`tests/`) + Jest (`npm run test:unit`).

## Concurrent development — MANDATORY

**Multiple Claude instances work this repo at once. NEVER develop a feature in
the main checkout.** Two instances sharing one working tree stomp each other's
`checkout`/`commit`/`reset` — this has caused real incidents (wrong-base
landings, bundle/version collisions).

**Every feature gets its own git worktree, branched off the latest
`origin/master`:**

```bash
tools/new-feature.sh <name>            # creates ../woi-<name> on feat/<name> + npm ci
#   --junction  links node_modules to the main install (Windows, fast) — see teardown gotcha
```

(PowerShell: `tools\new-feature.ps1 <name> [-Junction]`.) The script also
installs the concurrency guard (`git config core.hooksPath tools/hooks`), which
**blocks direct commits to master/main** in every checkout. A pre-commit on
master fails with guidance; for an intentional release/merge commit use
`WOI_ALLOW_MASTER=1 git commit ...`.

One-time per fresh clone (if you didn't use the script): `git config core.hooksPath tools/hooks`.

### Landing a feature (Commit → sync → Push → Pull)

The shared, collision-prone artifacts are the **version string** and the
**built bundle** — do both LAST, after syncing:

```bash
# in the feature worktree
git add <source>; git commit -m "..."        # 1. commit SOURCE as you go
git fetch origin && git rebase origin/master # 2. sync to latest (linear history)

# 3. ONLY if plugin code/assets changed (skip for docs/tooling-only changes):
git show origin/master:woocommerce-orders-invoice-pdf.php | grep Version  # read TRUE version
#    bump BOTH strings (see below) to the next free patch, then:
npm run build                                # rebuild bundle on top of rebased source
git add -A && git commit -m "chore: vX.Y.Z + build"

git push origin HEAD:master                  # 4. fast-forward push (never --force)
```

Then in the main/deploy checkout: `git pull --ff-only origin master`.
If the push is rejected (someone landed in between): `git fetch && git rebase
origin/master`, rebuild, re-bump to the next free patch, push again.

### Version bump — TWO strings, together, only when assets change

`WOI_PDF_VERSION` is the asset `?ver=` cache-bust key. Bump **both**:
- `woocommerce-orders-invoice-pdf.php` line ~6 — `* Version:` header
- `woocommerce-orders-invoice-pdf.php` line ~24 — `public string $version = '…';`

Bumping only the header leaves caches stale. **Docs/tooling-only changes (no
`src/`, `includes/`, `templates/`, or asset edits) need NO version bump** —
bumping needlessly collides with another instance's in-flight release.

### Worktree teardown gotcha (Windows)

If a worktree uses a `node_modules` **junction** (`--junction`), do NOT
`rm -rf node_modules` or `git worktree remove --force` — both follow the
junction and empty the MAIN checkout's `node_modules`. Remove the link first:
`cmd //c rmdir node_modules`, then `git worktree remove <dir>` and
`git worktree prune`.

## Build & test

```bash
npm run build                 # block-editor bundle -> assets/js/block-editor/
npm run test:unit             # Jest
php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php <path>   # PHPUnit
```

PHPUnit **requires** the `-d auto_prepend_file=tests/bootstrap.php` flag (defines
ABSPATH) or it dies silently. Some baseline failures exist from helper functions
not loaded under the harness — establish the baseline before judging a change.

### mPDF rendering rules (PDF parity)

- Style PDF elements with **inline styles**, not stylesheet classes — mPDF
  ignores `body[data-*]` descendant selectors and the theme stylesheet.
- For any shape/graphic that must match the editor canvas and the PDF, emit an
  **SVG data-URI `<img>` sized inline** — mPDF won't honour div sizing /
  `border-radius`. Size images inline (`width:Nmm`); mPDF ignores width/height
  attributes and CSS `img` width rules.
- Verify PDFs locally without a deploy: `php tools/render-visual-sample.php`
  then `python tools/rasterize.py <pdf> <out-prefix>` (PyMuPDF).

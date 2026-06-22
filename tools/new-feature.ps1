<#
.SYNOPSIS
  Create an isolated git worktree for a feature, off the LATEST origin/master,
  so concurrent instances develop and land in parallel without sharing a
  working tree. See CLAUDE.md "Concurrent development".

.EXAMPLE
  tools\new-feature.ps1 my-feature
  tools\new-feature.ps1 my-feature -Junction
#>
param(
    [Parameter(Mandatory = $true)][string]$Name,
    [switch]$Junction
)
$ErrorActionPreference = 'Stop'

$branch = "feat/$Name"
$main   = (Resolve-Path (Join-Path (git rev-parse --git-common-dir) '..')).Path
$parent = Split-Path $main -Parent
$dir    = Join-Path $parent "woi-$Name"

if (Test-Path $dir) { throw "$dir already exists — pick another name or remove it first." }

git -C $main fetch origin
git -C $main worktree add -b $branch $dir origin/master

# Install the shared concurrency hooks repo-wide (idempotent).
git -C $main config core.hooksPath tools/hooks

if ($Junction) {
    # Fast path: link node_modules to the main install.
    # WARNING: never delete this worktree with `Remove-Item -Recurse` or
    # `git worktree remove --force` while the junction exists — it follows the
    # link and empties the MAIN node_modules. Remove the junction first:
    #   cmd /c rmdir (Join-Path $dir 'node_modules')   THEN  git worktree remove $dir
    cmd /c mklink /J (Join-Path $dir 'node_modules') (Join-Path $main 'node_modules')
} else {
    Push-Location $dir
    try { npm ci } finally { Pop-Location }
}

Write-Host ""
Write-Host "OK  Worktree ready: $dir   (branch $branch, based on origin/master)"
Write-Host "Next: cd `"$dir`"  ->  implement + commit SOURCE  ->  land (see CLAUDE.md 'Landing a feature')."

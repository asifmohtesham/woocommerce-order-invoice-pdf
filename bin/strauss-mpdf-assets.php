<?php
/**
 * Post-Strauss asset copier for mPDF.
 *
 * Strauss only copies a package's autoloaded source (mPDF's `src/`), so the
 * prefixed copy at vendor/strauss/mpdf/mpdf is missing mPDF's runtime assets:
 *   - data/    : required non-font data (upperCase.php, line-break dicts, CSS,
 *                collations, …). mPDF require()s these via hardcoded
 *                __DIR__/../data paths, so they MUST sit next to the prefixed src.
 *   - ttfonts/ : font files. The full set is ~88 MB of exotic scripts; we only
 *                ship the families the plugin actually uses (DejaVu for Latin,
 *                XB Riyaz + Lateef for Arabic).
 *
 * Runs after `vendor/bin/strauss` (see composer.json scripts). Idempotent.
 */

$root      = dirname(__DIR__);
$src       = $root . '/vendor/mpdf/mpdf';
$dest      = $root . '/vendor/strauss/mpdf/mpdf';

if (!is_dir($src)) {
    fwrite(STDERR, "[mpdf-assets] vendor/mpdf/mpdf not found — run composer install first.\n");
    exit(0); // non-fatal: nothing to do
}
if (!is_dir($dest)) {
    fwrite(STDERR, "[mpdf-assets] vendor/strauss/mpdf/mpdf not found — has Strauss run?\n");
    exit(0);
}

/** Recursively copy a directory. */
function woi_copy_dir(string $from, string $to): int {
    if (!is_dir($from)) {
        return 0;
    }
    if (!is_dir($to) && !mkdir($to, 0775, true) && !is_dir($to)) {
        fwrite(STDERR, "[mpdf-assets] could not create $to\n");
        return 0;
    }
    $count = 0;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        $target = $to . '/' . $items->getSubPathname();
        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0775, true);
            }
        } else {
            copy($item->getPathname(), $target);
            $count++;
        }
    }
    return $count;
}

// 1. data/ — copy in full (required by mPDF at runtime).
$dataCount = woi_copy_dir($src . '/data', $dest . '/data');

// 2. ttfonts/ — copy only the families we use.
$fontDir = $dest . '/ttfonts';
if (!is_dir($fontDir)) {
    mkdir($fontDir, 0775, true);
}
$keep = array_merge(
    glob($src . '/ttfonts/DejaVu*') ?: array(),       // Latin default (sans/serif/mono generics)
    glob($src . '/ttfonts/XB Riyaz*') ?: array(),      // Arabic — Naskh
    glob($src . '/ttfonts/LateefRegOT.ttf') ?: array() // Arabic — Lateef
);
$fontCount = 0;
foreach ($keep as $f) {
    if (copy($f, $fontDir . '/' . basename($f))) {
        $fontCount++;
    }
}

fwrite(STDOUT, "[mpdf-assets] copied {$dataCount} data files and {$fontCount} font files into vendor/strauss/mpdf/mpdf\n");

// 3. mpdf/qrcode data/ — the QR encoder require()s data/qrvN_M.dat via a
//    hardcoded __DIR__/../data path, but Strauss only copies the package's src/.
//    Copy the .dat tables next to the prefixed src so <barcode type="QR"> works.
$qrSrc  = $root . '/vendor/mpdf/qrcode';
$qrDest = $root . '/vendor/strauss/mpdf/qrcode';
if (is_dir($qrSrc) && is_dir($qrDest)) {
    $qrCount = woi_copy_dir($qrSrc . '/data', $qrDest . '/data');
    fwrite(STDOUT, "[mpdf-assets] copied {$qrCount} qrcode data files into vendor/strauss/mpdf/qrcode\n");
}

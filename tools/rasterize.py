#!/usr/bin/env python
"""Rasterize a PDF's pages to PNGs for visual inspection.
Usage: python tools/rasterize.py <pdf> <out_prefix> [dpi]
"""
import sys
import fitz  # PyMuPDF

pdf = sys.argv[1]
prefix = sys.argv[2]
dpi = int(sys.argv[3]) if len(sys.argv) > 3 else 130

doc = fitz.open(pdf)
for i, page in enumerate(doc):
    pix = page.get_pixmap(dpi=dpi)
    out = f"{prefix}-{i + 1}.png"
    pix.save(out)
    print(out, pix.width, "x", pix.height)

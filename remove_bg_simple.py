#!/usr/bin/env python3
"""
remove_bg_simple.py

Simple background removal using GrabCut. Keeps original image size and
writes a PNG with transparent background where the background was removed.

Usage: python remove_bg_simple.py input.jpg output.png
"""
import sys
import os
import cv2
import numpy as np


def remove_background_grabcut(img, iter_count=5, margin_ratio=0.02):
    h, w = img.shape[:2]
    margin = max(1, int(min(w, h) * margin_ratio))
    rect = (margin, margin, w - 2 * margin, h - 2 * margin)
    mask = np.zeros((h, w), np.uint8)
    bgdModel = np.zeros((1, 65), np.float64)
    fgdModel = np.zeros((1, 65), np.float64)
    cv2.grabCut(img, mask, rect, bgdModel, fgdModel, iter_count, cv2.GC_INIT_WITH_RECT)
    # mask: 0=bg,1=fg,2=prob bg,3=prob fg
    mask2 = np.where((mask == cv2.GC_FGD) | (mask == cv2.GC_PR_FGD), 255, 0).astype('uint8')
    # postprocess mask
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (7, 7))
    mask2 = cv2.morphologyEx(mask2, cv2.MORPH_CLOSE, kernel, iterations=2)
    mask2 = cv2.morphologyEx(mask2, cv2.MORPH_OPEN, kernel, iterations=1)
    # smooth edges for nicer alpha
    mask_blur = cv2.GaussianBlur(mask2.astype(np.float32), (7, 7), 0)
    alpha = np.clip(mask_blur, 0, 255).astype(np.uint8)
    return alpha


def main():
    if len(sys.argv) < 3:
        print("Usage: python remove_bg_simple.py input.jpg output.png")
        sys.exit(1)
    inp = sys.argv[1]
    out = sys.argv[2]
    if not os.path.exists(inp):
        print(f"Input not found: {inp}")
        sys.exit(1)
    img = cv2.imread(inp, cv2.IMREAD_COLOR)
    if img is None:
        print("Failed to read image")
        sys.exit(1)
    print(f"Removing background from {inp} (this may take a few seconds) ...")
    alpha = remove_background_grabcut(img, iter_count=5, margin_ratio=0.02)
    b, g, r = cv2.split(img)
    rgba = cv2.merge([b, g, r, alpha])
    cv2.imwrite(out, rgba)
    print(f"Saved transparent PNG to: {out}")


if __name__ == '__main__':
    main()

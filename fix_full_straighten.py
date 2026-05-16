#!/usr/bin/env python3
"""
fix_full_straighten.py

Fit a line to the top of the arch to compute a small deskew rotation, rotate
with an expanded canvas so nothing is lost, then run GrabCut seeded by the
transformed contour (plus a color mask) to remove background while keeping
the full subject.

Usage: python fix_full_straighten.py input.jpg output.png
"""
import sys
import os
import math
import cv2
import numpy as np


def rotate_with_canvas(img, angle, center=None):
    (h, w) = img.shape[:2]
    if center is None:
        center = (w / 2.0, h / 2.0)
    M = cv2.getRotationMatrix2D(center, angle, 1.0)
    cos = abs(M[0, 0])
    sin = abs(M[0, 1])
    nW = int((h * sin) + (w * cos))
    nH = int((h * cos) + (w * sin))
    M[0, 2] += (nW / 2) - center[0]
    M[1, 2] += (nH / 2) - center[1]
    border = (0, 0, 0, 0) if img.ndim == 3 and img.shape[2] == 4 else (0, 0, 0)
    rotated = cv2.warpAffine(img, M, (nW, nH), flags=cv2.INTER_LINEAR,
                             borderMode=cv2.BORDER_CONSTANT, borderValue=border)
    return rotated, M


def apply_grabcut_from_mask(img, init_mask, iter_count=5):
    # init_mask: uint8 where 1 indicates probable foreground
    mask = np.where(init_mask == 1, cv2.GC_PR_FGD, cv2.GC_PR_BGD).astype('uint8')
    bgdModel = np.zeros((1, 65), np.float64)
    fgdModel = np.zeros((1, 65), np.float64)
    cv2.grabCut(img, mask, None, bgdModel, fgdModel, iter_count, cv2.GC_INIT_WITH_MASK)
    res = np.where((mask == cv2.GC_FGD) | (mask == cv2.GC_PR_FGD), 255, 0).astype('uint8')
    return res


def main():
    if len(sys.argv) < 3:
        print("Usage: python fix_full_straighten.py input.jpg output.png")
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
    h, w = img.shape[:2]

    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    edges = cv2.Canny(gray, 50, 150)
    # close/dilate to join arc edges
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (7, 7))
    edges = cv2.dilate(edges, kernel, iterations=1)
    edges = cv2.morphologyEx(edges, cv2.MORPH_CLOSE, kernel, iterations=2)

    cnts, _ = cv2.findContours(edges, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_NONE)
    if not cnts:
        print("No contours found; aborting")
        sys.exit(1)
    cnts = sorted(cnts, key=cv2.contourArea, reverse=True)

    # pick a contour that likely corresponds to the arch (prefer wide contours)
    chosen = None
    for c in cnts[:6]:
        x, y, cw, ch = cv2.boundingRect(c)
        if cw > w * 0.3 and ch > h * 0.05:
            chosen = c
            break
    if chosen is None:
        chosen = cnts[0]

    # compute top band of contour to fit a line
    pts = chosen.reshape(-1, 2)
    y_min = int(pts[:, 1].min())
    top_thresh = y_min + max(5, int(0.08 * h))
    top_pts = pts[pts[:, 1] <= top_thresh]
    if len(top_pts) < 6:
        # expand threshold if too few
        top_thresh = y_min + max(5, int(0.12 * h))
        top_pts = pts[pts[:, 1] <= top_thresh]

    angle = 0.0
    if len(top_pts) >= 2:
        vx, vy, x0, y0 = cv2.fitLine(top_pts.astype(np.float32), cv2.DIST_L2, 0, 0.01, 0.01)
        # fitLine may return small numpy arrays; squeeze to scalars
        vx = float(np.squeeze(vx))
        vy = float(np.squeeze(vy))
        angle = math.degrees(math.atan2(vy, vx))
        print(f"Fitted top line angle: {angle:.3f} deg")
    else:
        print("Not enough top points for fitLine; skipping rotation")

    # rotate original image with expanded canvas
    img_bgra = cv2.cvtColor(img, cv2.COLOR_BGR2BGRA)
    rot_angle = -angle
    print(f"Rotating by {rot_angle:.3f} deg")
    rotated, M = rotate_with_canvas(img_bgra, rot_angle, center=(w / 2.0, h / 2.0))
    new_h, new_w = rotated.shape[:2]
    print(f"Rotated canvas size: {new_w}x{new_h}")

    # transform chosen contour to rotated canvas
    pts_in = chosen.reshape(-1, 1, 2).astype(np.float32)
    pts_tr = cv2.transform(pts_in, M).reshape(-1, 2)
    pts_tr_i = np.round(pts_tr).astype(np.int32)

    # create init mask from transformed contour
    init_mask = np.zeros((new_h, new_w), dtype=np.uint8)
    cv2.fillPoly(init_mask, [pts_tr_i], 1)

    # also add color-based mask for golden/orange regions (helps include top decorations)
    hsv = cv2.cvtColor(rotated[:, :, :3], cv2.COLOR_BGR2HSV)
    h_ch, s_ch, v_ch = cv2.split(hsv)
    # range for warm golden tones (h around 5-40)
    color_mask = cv2.inRange(hsv, (5, 60, 60), (40, 255, 255))
    color_mask = cv2.morphologyEx(color_mask, cv2.MORPH_CLOSE, kernel, iterations=1)
    color_mask = (color_mask > 0).astype(np.uint8)

    init_mask = np.where((init_mask == 1) | (color_mask == 1), 1, 0).astype(np.uint8)
    # dilate the mask to be generous
    init_mask = cv2.dilate(init_mask, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (25, 25)), iterations=1)

    # run GrabCut with this seed mask
    print("Running GrabCut...")
    mask = apply_grabcut_from_mask(rotated[:, :, :3], init_mask, iter_count=5)

    # postprocess
    kernel2 = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (11, 11))
    mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel2, iterations=2)
    mask = cv2.morphologyEx(mask, cv2.MORPH_OPEN, kernel2, iterations=1)

    # ensure top arc pieces included: if top rows have many edge pixels near arc region,
    # add them back (heuristic)
    edges_rot = cv2.Canny(cv2.cvtColor(rotated[:, :, :3], cv2.COLOR_BGR2GRAY), 50, 150)
    ys, xs = np.where(edges_rot > 0)
    if len(xs) > 0:
        # add small circles around edge points near top area
        for (xx, yy) in zip(xs[::50], ys[::50]):
            cv2.circle(mask, (int(xx), int(yy)), 6, 255, -1)
        mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel2, iterations=1)

    alpha = cv2.GaussianBlur(mask.astype(np.float32), (7, 7), 0)
    alpha = np.clip(alpha, 0, 255).astype(np.uint8)

    rgba = rotated.copy()
    rgba[:, :, 3] = alpha

    cv2.imwrite(out, rgba)
    print(f"Saved fixed image: {out}")


if __name__ == '__main__':
    main()

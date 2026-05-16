#!/usr/bin/env python3
"""
remove_bg_full_straight.py

Straighten the original image (small rotation), preserve the full subject
including top decorations, and remove the background. Saves a transparent
PNG at the rotated canvas size (no cropping of subject).

Usage: python remove_bg_full_straight.py input.jpg output.png
"""
import sys
import os
import cv2
import numpy as np
from math import atan2, degrees


def load_image(path):
    img = cv2.imread(path, cv2.IMREAD_COLOR)
    if img is None:
        raise FileNotFoundError(f"Can't open image: {path}")
    return img


def detect_circle(gray):
    h, w = gray.shape[:2]
    blur = cv2.medianBlur(gray, 5)
    minR = int(min(w, h) * 0.18)
    maxR = int(min(w, h) * 0.6)
    circles = None
    try:
        circles = cv2.HoughCircles(blur, cv2.HOUGH_GRADIENT, dp=1.2, minDist=200,
                                   param1=100, param2=42,
                                   minRadius=minR, maxRadius=maxR)
    except Exception:
        circles = None
    if circles is not None:
        circles = np.uint16(np.around(circles))
        best = max(circles[0, :], key=lambda c: c[2])
        return (int(best[0]), int(best[1])), int(best[2])
    return None, None


def pca_angle(pts):
    if len(pts) < 3:
        return 0.0
    mean = pts.mean(axis=0)
    xp = pts - mean
    cov = np.cov(xp.T)
    eigvals, eigvecs = np.linalg.eig(cov)
    idx = np.argmax(eigvals)
    v = eigvecs[:, idx]
    ang = degrees(atan2(v[1], v[0]))
    return ang


def rotate_with_canvas(img, angle, center=None):
    # rotate and expand canvas so nothing is cropped; keep alpha if present
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
    # ensure we rotate BGRA and use transparent border
    border = (0, 0, 0, 0) if img.ndim == 3 and img.shape[2] == 4 else (0, 0, 0)
    rotated = cv2.warpAffine(img, M, (nW, nH), flags=cv2.INTER_LINEAR,
                             borderMode=cv2.BORDER_CONSTANT, borderValue=border)
    return rotated, M


def apply_grabcut_from_mask(img, init_mask, iter_count=5):
    # init_mask: uint8 mask where 1 indicates probable foreground
    mask = np.where(init_mask == 1, cv2.GC_PR_FGD, cv2.GC_PR_BGD).astype('uint8')
    bgdModel = np.zeros((1, 65), np.float64)
    fgdModel = np.zeros((1, 65), np.float64)
    cv2.grabCut(img, mask, None, bgdModel, fgdModel, iter_count, cv2.GC_INIT_WITH_MASK)
    res = np.where((mask == cv2.GC_FGD) | (mask == cv2.GC_PR_FGD), 255, 0).astype('uint8')
    return res


def main():
    if len(sys.argv) < 3:
        print("Usage: python remove_bg_full_straight.py input.jpg output.png")
        sys.exit(1)
    inp = sys.argv[1]
    out = sys.argv[2]
    if not os.path.exists(inp):
        print(f"Input not found: {inp}")
        sys.exit(1)

    img = load_image(inp)
    h, w = img.shape[:2]
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)

    center, radius = detect_circle(gray)
    if center is None:
        center = (w // 2, int(h * 0.36))
        radius = int(min(w, h) * 0.45)
        print("No circle detected: using image center and default radius")
    else:
        print(f"Detected circle center={center} radius={radius}")

    # estimate orientation from edges inside circle
    mask_circle = np.zeros_like(gray, dtype=np.uint8)
    cv2.circle(mask_circle, center, radius, 255, -1)
    edges = cv2.Canny(gray, 50, 150)
    pts = np.column_stack(np.where((edges > 0) & (mask_circle > 0)))
    if len(pts) < 50:
        pts = np.column_stack(np.where(mask_circle > 0))
    pts_xy = pts[:, ::-1].astype('float32')
    angle = pca_angle(pts_xy)
    print(f"Estimated PCA angle: {angle:.2f} deg")

    # rotate original image (keep alpha) by -angle to make subject upright
    img_bgra = cv2.cvtColor(img, cv2.COLOR_BGR2BGRA)
    rot_angle = -angle
    rotated, M = rotate_with_canvas(img_bgra, rot_angle, center=(float(center[0]), float(center[1])))
    new_h, new_w = rotated.shape[:2]
    print(f"Rotated canvas size: {new_w}x{new_h}")

    # transform circle center
    pt = np.array([[[center[0], center[1]]]], dtype='float32')
    pt_rot = cv2.transform(pt, M)[0][0]
    new_center = (int(round(pt_rot[0])), int(round(pt_rot[1])))
    new_radius = int(round(radius * 1.08))

    # build init mask: mark circle area as probable foreground
    init_mask = np.zeros((new_h, new_w), dtype=np.uint8)
    cv2.circle(init_mask, new_center, new_radius, 1, -1)

    # run GrabCut using the rotated BGR image and init mask
    print("Running GrabCut on rotated image...")
    result_mask = apply_grabcut_from_mask(rotated[:, :, :3], init_mask, iter_count=5)

    # postprocess mask: close small holes and dilate to ensure full coverage
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (9, 9))
    result_mask = cv2.morphologyEx(result_mask, cv2.MORPH_CLOSE, kernel, iterations=2)
    result_mask = cv2.morphologyEx(result_mask, cv2.MORPH_DILATE, kernel, iterations=1)

    # ensure we didn't cut decorations near image border: if region near arc top is missing,
    # expand mask based on strong edges near the transformed circle boundary
    edges_rot = cv2.Canny(cv2.cvtColor(rotated[:, :, :3], cv2.COLOR_BGR2GRAY), 50, 150)
    # look for edge points near circle circumference and add to mask
    ys, xs = np.where(edges_rot > 0)
    d2 = (xs - new_center[0]) ** 2 + (ys - new_center[1]) ** 2
    on_ring = (d2 >= (new_radius - 30) ** 2) & (d2 <= (new_radius + 30) ** 2)
    if np.any(on_ring):
        add_xs = xs[on_ring]; add_ys = ys[on_ring]
        for (xx, yy) in zip(add_xs, add_ys):
            cv2.circle(result_mask, (int(xx), int(yy)), 6, 255, -1)
        result_mask = cv2.morphologyEx(result_mask, cv2.MORPH_CLOSE, kernel, iterations=1)

    # create alpha by smoothing edges
    alpha = cv2.GaussianBlur(result_mask.astype(np.float32), (7, 7), 0)
    alpha = np.clip(alpha, 0, 255).astype(np.uint8)

    rgba = rotated.copy()
    rgba[:, :, 3] = alpha

    # save full canvas PNG
    cv2.imwrite(out, rgba)
    print(f"Saved straightened transparent PNG: {out}")


if __name__ == '__main__':
    main()

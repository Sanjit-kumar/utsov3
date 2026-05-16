#!/usr/bin/env python3
"""
straighten_and_remove_bg_v2.py

Detect vertical structural lines (columns), compute a small rotation to
straighten the scene, then remove background while preserving the full
rotated canvas (no cropping of subject).

Usage: python straighten_and_remove_bg_v2.py input.jpg output.png
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


def detect_vertical_angle(gray):
    h, w = gray.shape[:2]
    edges = cv2.Canny(gray, 50, 150)
    min_len = max(100, int(min(w, h) * 0.12))
    lines = cv2.HoughLinesP(edges, 1, np.pi / 180, threshold=120, minLineLength=min_len, maxLineGap=50)
    angles = []
    if lines is not None:
        for l in lines[:, 0, :]:
            x1, y1, x2, y2 = l
            dx = x2 - x1
            dy = y2 - y1
            if abs(dy) < 1:
                continue
            # prefer mostly vertical lines
            if abs(dx) > abs(dy) * 0.6:
                continue
            # angle to vertical (radians): atan2(dx, dy)
            ang = degrees(atan2(dx, dy))
            angles.append(ang)
    if len(angles) >= 3:
        median_ang = float(np.median(angles))
        return median_ang
    return None


def apply_grabcut_from_mask(img, init_mask, iter_count=5):
    mask = np.where(init_mask == 1, cv2.GC_PR_FGD, cv2.GC_PR_BGD).astype('uint8')
    bgdModel = np.zeros((1, 65), np.float64)
    fgdModel = np.zeros((1, 65), np.float64)
    cv2.grabCut(img, mask, None, bgdModel, fgdModel, iter_count, cv2.GC_INIT_WITH_MASK)
    res = np.where((mask == cv2.GC_FGD) | (mask == cv2.GC_PR_FGD), 255, 0).astype('uint8')
    return res


def main():
    if len(sys.argv) < 3:
        print("Usage: python straighten_and_remove_bg_v2.py input.jpg output.png")
        sys.exit(1)
    inp = sys.argv[1]
    out = sys.argv[2]
    if not os.path.exists(inp):
        print(f"Input not found: {inp}")
        sys.exit(1)

    img = load_image(inp)
    h, w = img.shape[:2]
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)

    angle_to_vertical = detect_vertical_angle(gray)
    if angle_to_vertical is None:
        print("Couldn't detect enough vertical lines; falling back to small PCA-based rotation.")
        # fallback: use PCA on central region to estimate small tilt
        cx, cy = w // 2, h // 3
        region = gray[max(0, cy - 300):min(h, cy + 300), max(0, cx - 300):min(w, cx + 300)]
        # use edges
        edges = cv2.Canny(region, 50, 150)
        pts = np.column_stack(np.where(edges > 0))
        if len(pts) < 10:
            angle_to_vertical = 0.0
        else:
            pts_xy = pts[:, ::-1].astype('float32')
            mean = pts_xy.mean(axis=0)
            xp = pts_xy - mean
            cov = np.cov(xp.T)
            eigvals, eigvecs = np.linalg.eig(cov)
            vec = eigvecs[:, np.argmax(eigvals)]
            pca_ang = degrees(atan2(vec[1], vec[0]))
            # convert to angle-to-vertical
            angle_to_vertical = (degrees(atan2(vec[0], vec[1])))
    else:
        print(f"Detected median angle to vertical: {angle_to_vertical:.3f} deg")

    # rotate by negative of angle_to_vertical to align vertical structures
    rot_angle = -angle_to_vertical
    print(f"Rotating by {rot_angle:.3f} deg to straighten")

    img_bgra = cv2.cvtColor(img, cv2.COLOR_BGR2BGRA)
    rotated, M = rotate_with_canvas(img_bgra, rot_angle)
    new_h, new_w = rotated.shape[:2]
    print(f"Rotated canvas: {new_w}x{new_h}")

    # estimate circle on rotated to get a good foreground seed
    gray_rot = cv2.cvtColor(rotated[:, :, :3], cv2.COLOR_BGR2GRAY)
    # attempt HoughCircles on rotated
    blur = cv2.medianBlur(gray_rot, 5)
    minR = int(min(new_w, new_h) * 0.18)
    maxR = int(min(new_w, new_h) * 0.6)
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
        center = (int(best[0]), int(best[1]))
        radius = int(best[2] * 1.05)
        print(f"Detected circle on rotated image: center={center} radius={radius}")
    else:
        # fallback: use center of canvas
        center = (new_w // 2, int(new_h * 0.36))
        radius = int(min(new_w, new_h) * 0.45)
        print("No circle detected on rotated image; using canvas center and default radius")

    init_mask = np.zeros((new_h, new_w), dtype=np.uint8)
    cv2.circle(init_mask, center, radius, 1, -1)

    print("Running GrabCut to remove background...")
    mask = apply_grabcut_from_mask(rotated[:, :, :3], init_mask, iter_count=5)

    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (9, 9))
    mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel, iterations=2)
    mask = cv2.morphologyEx(mask, cv2.MORPH_DILATE, kernel, iterations=1)

    alpha = cv2.GaussianBlur(mask.astype(np.float32), (7, 7), 0)
    alpha = np.clip(alpha, 0, 255).astype(np.uint8)
    rgba = rotated.copy()
    rgba[:, :, 3] = alpha

    cv2.imwrite(out, rgba)
    print(f"Saved: {out}")


if __name__ == '__main__':
    main()

#!/usr/bin/env python3
"""
deskew_nobg_reverse.py

Rotate the transparent PNG the opposite way from the usual deskew.
Computes the top-line angle from the alpha channel and applies the
reverse rotation (i.e., rotates by +angle instead of -angle).

Usage: python deskew_nobg_reverse.py input.png output.png
"""
import sys
import os
import math
import cv2
import numpy as np


def load_image(path):
    img = cv2.imread(path, cv2.IMREAD_UNCHANGED)
    if img is None:
        raise FileNotFoundError(f"Can't open image: {path}")
    if img.ndim == 2:
        img = cv2.cvtColor(img, cv2.COLOR_GRAY2BGRA)
    if img.shape[2] == 3:
        img = cv2.cvtColor(img, cv2.COLOR_BGR2BGRA)
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
    rotated = cv2.warpAffine(img, M, (nW, nH), flags=cv2.INTER_LINEAR,
                             borderMode=cv2.BORDER_CONSTANT, borderValue=(0,0,0,0))
    return rotated, M


def fit_top_line_angle(alpha):
    h, w = alpha.shape[:2]
    mask = (alpha > 10).astype('uint8') * 255
    cnts, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_NONE)
    if not cnts:
        return 0.0
    cnts = sorted(cnts, key=cv2.contourArea, reverse=True)
    c = cnts[0]
    pts = c.reshape(-1, 2)
    y_min = int(pts[:,1].min())
    top_thresh = y_min + max(5, int(0.08 * h))
    top_pts = pts[pts[:,1] <= top_thresh]
    if len(top_pts) < 6:
        top_thresh = y_min + max(5, int(0.15 * h))
        top_pts = pts[pts[:,1] <= top_thresh]
    if len(top_pts) >= 2:
        vx, vy, x0, y0 = cv2.fitLine(top_pts.astype(np.float32), cv2.DIST_L2, 0, 0.01, 0.01)
        vx = float(np.squeeze(vx)); vy = float(np.squeeze(vy))
        angle = math.degrees(math.atan2(vy, vx))
        return angle
    # fallback PCA
    fg_pts = np.column_stack(np.where(mask > 0))
    if len(fg_pts) < 10:
        return 0.0
    fg_xy = fg_pts[:, ::-1].astype('float32')
    mean = fg_xy.mean(axis=0)
    xp = fg_xy - mean
    cov = np.cov(xp.T)
    eigvals, eigvecs = np.linalg.eig(cov)
    vec = eigvecs[:, np.argmax(eigvals)]
    angle = math.degrees(math.atan2(vec[1], vec[0]))
    return angle


def main():
    if len(sys.argv) < 3:
        print("Usage: python deskew_nobg_reverse.py input.png output.png")
        sys.exit(1)
    inp = sys.argv[1]
    out = sys.argv[2]
    if not os.path.exists(inp):
        print(f"Input not found: {inp}")
        sys.exit(1)
    img = load_image(inp)
    alpha = img[:, :, 3]
    angle = fit_top_line_angle(alpha)
    if angle > 10: angle = 10.0
    if angle < -10: angle = -10.0
    print(f"Detected angle: {angle:.3f} deg — rotating by +{angle:.3f} deg")
    rotated, M = rotate_with_canvas(img, angle)
    cv2.imwrite(out, rotated)
    print(f"Saved: {out}")


if __name__ == '__main__':
    main()

#!/usr/bin/env python3
"""
edit_maa1.py

Straighten the image, crop the arc, and remove the background.
Usage: python edit_maa1.py input.jpg output.png

Produces a clipped PNG with transparent background.
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


def detect_circle(gray, w, h):
    # Try HoughCircles on blurred gray image
    blur = cv2.medianBlur(gray, 5)
    minR = int(min(w, h) * 0.20)
    maxR = int(min(w, h) * 0.60)
    try:
        circles = cv2.HoughCircles(blur, cv2.HOUGH_GRADIENT, dp=1.2, minDist=100,
                                   param1=100, param2=40,
                                   minRadius=minR, maxRadius=maxR)
    except Exception:
        circles = None
    if circles is not None:
        circles = np.uint16(np.around(circles))
        best = max(circles[0, :], key=lambda c: c[2])
        return (int(best[0]), int(best[1])), int(best[2])
    return None, None


def pca_orientation(pts):
    # pts: Nx2 (x,y)
    if len(pts) < 3:
        return 0.0
    mean = pts.mean(axis=0)
    xp = pts - mean
    cov = np.cov(xp.T)
    eigvals, eigvecs = np.linalg.eig(cov)
    idx = np.argmax(eigvals)
    vec = eigvecs[:, idx]
    angle = degrees(atan2(vec[1], vec[0]))
    return angle


def rotate_image(img, angle, center=None):
    (h, w) = img.shape[:2]
    if center is None:
        center = (w // 1, h // 2)
    M = cv2.getRotationMatrix2D(center, angle, 1.0)
    cos = abs(M[0, 0])
    sin = abs(M[0, 1])
    nW = int((h * sin) + (w * cos))
    nH = int((h * cos) + (w * sin))
    M[0, 2] += (nW / 2) - center[0]
    M[1, 2] += (nH / 2) - center[1]
    # choose border fill based on channels (use transparent for 4-channel images)
    if img.ndim == 3 and img.shape[2] == 4:
        border_value = (0, 0, 0, 0)
    else:
        border_value = (0, 0, 0)
    rotated = cv2.warpAffine(img, M, (nW, nH), flags=cv2.INTER_LINEAR,
                             borderMode=cv2.BORDER_CONSTANT, borderValue=border_value)
    return rotated, M


def apply_grabcut(img, init_binary_mask):
    # init_binary_mask: 0/1 uint8 mask where 1 indicates probable foreground
    mask = np.where(init_binary_mask == 1, cv2.GC_PR_FGD, cv2.GC_PR_BGD).astype('uint8')
    bgdModel = np.zeros((1, 65), np.float64)
    fgdModel = np.zeros((1, 65), np.float64)
    cv2.grabCut(img, mask, None, bgdModel, fgdModel, 5, cv2.GC_INIT_WITH_MASK)
    res = np.where((mask == cv2.GC_FGD) | (mask == cv2.GC_PR_FGD), 255, 0).astype('uint8')
    return res


def main():
    if len(sys.argv) < 3:
        print("Usage: python edit_maa1.py input.jpg output.png")
        sys.exit(1)
    inp = sys.argv[1]
    out = sys.argv[2]
    if not os.path.exists(inp):
        print(f"Input not found: {inp}")
        sys.exit(1)
    print(f"Loading {inp}")
    img = load_image(inp)
    h, w = img.shape[:2]
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    # work in BGRA so rotation fills borders with transparent alpha
    img_bgra = cv2.cvtColor(img, cv2.COLOR_BGR2BGRA)

    center, radius = detect_circle(gray, w, h)
    if center is None:
        print("No clear circle detected; using image center for arc crop.")
        center = (w // 2, int(h * 0.36))
        radius = int(min(w, h) * 0.45)
    else:
        print(f"Detected arc center={center}, radius={radius}")

    # compute edges within circle to estimate orientation
    mask_circle = np.zeros((h, w), np.uint8)
    cv2.circle(mask_circle, (int(center[0]), int(center[1])), int(radius), 255, -1)
    edges = cv2.Canny(gray, 50, 150)
    pts_idx = np.column_stack(np.where((edges > 0) & (mask_circle > 0)))
    if len(pts_idx) < 50:
        pts_idx = np.column_stack(np.where(mask_circle > 0))
    # pts_idx are (row, col) -> convert to (x,y)
    pts_xy = pts_idx[:, ::-1].astype('float32')
    angle = pca_orientation(pts_xy)
    # rotate by negative PCA angle to make the principal axis vertical
    rot_angle = -angle
    print(f"Estimated orientation={angle:.2f} deg; rotating by {rot_angle:.2f} deg to straighten")

    rotated, M = rotate_image(img_bgra, rot_angle, center=(int(center[0]), int(center[1])))
    # transform center to rotated image coords
    pt = np.array([[[center[0], center[1]]]], dtype='float32')
    pt_rot = cv2.transform(pt, M)[0][0]
    new_center = (int(pt_rot[0]), int(pt_rot[1]))
    new_h, new_w = rotated.shape[:2]
    print(f"Rotated size: {new_w}x{new_h}; new center: {new_center}")


    # build init mask for grabCut (fill slightly larger than radius)
    mask_init = np.zeros((new_h, new_w), np.uint8)
    adj_radius = int(radius * 1.02)
    cv2.circle(mask_init, new_center, adj_radius, 1, -1)

    print("Running GrabCut to refine foreground...")
    try:
        # grabCut expects BGR image and mask where 1=probable foreground
        result_mask = apply_grabcut(rotated[:, :, :3], mask_init)
    except Exception as e:
        print("GrabCut failed, falling back to circular mask:", e)
        result_mask = (mask_init * 255).astype('uint8')

    # smooth mask
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (7, 7))
    result_mask = cv2.morphologyEx(result_mask, cv2.MORPH_CLOSE, kernel)

    # combine with rotated alpha (keep border transparent)
    rot_alpha = rotated[:, :, 3]
    final_alpha = np.where((result_mask > 0) & (rot_alpha > 0), 255, 0).astype('uint8')
    out_img = rotated.copy()
    out_img[:, :, 3] = final_alpha

    # crop to bounding box of foreground
    ys, xs = np.where(result_mask > 0)
    if len(xs) == 0 or len(ys) == 0:
        print("No foreground found after GrabCut; saving full rotated image (with transparency).")
        save_img = out_img
    else:
        x1, x2 = xs.min(), xs.max()
        y1, y2 = ys.min(), ys.max()
        pad = max(10, int(0.02 * max(new_w, new_h)))
        x1 = max(0, x1 - pad)
        y1 = max(0, y1 - pad)
        x2 = min(new_w - 1, x2 + pad)
        y2 = min(new_h - 1, y2 + pad)
        save_img = out_img[y1:y2 + 1, x1:x2 + 1]

    cv2.imwrite(out, save_img)
    print(f"Saved edited image to: {out}")


if __name__ == '__main__':
    main()

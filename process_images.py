"""
UTSOV3 Image Processing Script
- Maa1.jpeg  → arch-shaped crop + 50% opacity PNG
- Day images  → same size 320x560, high quality upscale
- Artist images → same size 820x460
- Tradition images → same size 660x520
"""

from PIL import Image, ImageDraw, ImageFilter, ImageOps, ImageEnhance
import os

BASE = r"C:\Users\sanji\OneDrive\Documents\UTSOV3"

def p(name): return os.path.join(BASE, name)

def open_img(name):
    return Image.open(p(name))

def save_jpg(img, name, quality=92):
    rgb = img.convert('RGB')
    out = p(name)
    rgb.save(out, 'JPEG', quality=quality, optimize=True, subsampling=0)
    kb = os.path.getsize(out) // 1024
    print(f"  OK {name}  {img.size[0]}x{img.size[1]}  {kb}KB")

def save_png(img, name):
    out = p(name)
    img.save(out, 'PNG', optimize=True)
    kb = os.path.getsize(out) // 1024
    print(f"  OK {name}  {img.size[0]}x{img.size[1]}  {kb}KB")

def composite_on_bg(img, bg_color=(255, 255, 255)):
    """Flatten RGBA onto a solid background color → RGB."""
    img = img.convert('RGBA')
    bg = Image.new('RGB', img.size, bg_color)
    bg.paste(img, mask=img.split()[3])
    return bg

def sharpen_after_upscale(img, amount=160):
    """Apply Unsharp Mask to restore crispness after LANCZOS upscale."""
    return img.filter(ImageFilter.UnsharpMask(radius=0.8, percent=amount, threshold=2))

# ══════════════════════════════════════════════════════════════
# 1.  MAA1 — arch-shaped crop  +  50% opacity  →  PNG
# ══════════════════════════════════════════════════════════════
print("[ Maa1 — arch crop + 50% opacity ]")

img = open_img('Maa1.jpeg').convert('RGBA')
orig_w, orig_h = img.size   # 2048 × 1261

# ── resize to web-suitable size (800px wide, aspect preserved)
TARGET_W = 800
scale   = TARGET_W / orig_w
img     = img.resize((TARGET_W, int(orig_h * scale)), Image.LANCZOS)
w, h    = img.size            # 800 × 493

# ── build arch / dome mask ─────────────────────────────────
# The arch is a half-ellipse whose:
#   • top  = y 0  (very top of image, center)
#   • wide = full image width at y = arch_h (≈ 20% from top)
# Below arch_h → solid white rectangle (fully included)
mask = Image.new('L', (w, h), 0)
draw = ImageDraw.Draw(mask)

arch_h = int(h * 0.20)   # dome height in pixels (~99px)
cx     = w // 2
rx     = w // 2           # half-width = full image width at dome base
cy     = arch_h           # ellipse centre at 20%-from-top line
ry     = arch_h           # radius == cy  →  top of ellipse exactly at y = 0

# Draw the filled upper-half ellipse (the dome)
draw.ellipse([cx - rx, cy - ry, cx + rx, cy + ry], fill=255)
# Fill the lower rectangle so nothing below the dome baseline is cut
draw.rectangle([0, cy, w, h], fill=255)

# Feather the dome edge so it blends softly (no hard cut)
mask = mask.filter(ImageFilter.GaussianBlur(radius=16))

# Apply 50% opacity:  each alpha byte = mask_byte × 0.5
final_alpha = mask.point(lambda px: min(int(px * 0.5), 128))
img.putalpha(final_alpha)

save_png(img, 'maa1_hero.png')

# ══════════════════════════════════════════════════════════════
# 2.  FIVE SACRED DAYS  →  same size, upscaled, sharpened
# ══════════════════════════════════════════════════════════════
print("\n[ Five Sacred Days images ]")

# All originals are ≈161×278 portrait; target is 320×560 (≈2× upscale)
DAY_W, DAY_H = 320, 560

days = [
    ('Shasthi.png',  'day_shasthi.jpg'),
    ('Saptami.png',  'day_saptami.jpg'),
    ('Ashtami.png',  'day_ashtami.jpg'),
    ('Navami.png',   'day_navami.jpg'),
    ('Dashami.png',  'day_dashami.jpg'),
]

for src, dst in days:
    img = open_img(src).convert('RGBA')
    # Flatten any existing transparency onto white
    rgb = composite_on_bg(img, (255, 255, 255))
    # Resize with LANCZOS, center-crop to exact target
    fitted = ImageOps.fit(rgb, (DAY_W, DAY_H), Image.LANCZOS, centering=(0.5, 0.35))
    # Sharpen after 2× upscale to fight softness
    sharpened = sharpen_after_upscale(fitted, amount=170)
    save_jpg(sharpened, dst, quality=90)

# ══════════════════════════════════════════════════════════════
# 3.  ARTIST IMAGES  →  same size, high quality
# ══════════════════════════════════════════════════════════════
print("\n[ Artist images ]")

# Display context: ar-visual panel ≈ 680×400px → target slightly larger for sharpness
ART_W, ART_H = 820, 460

artists = [
    ('NandiSisters.png',  'artist_nandi.jpg',    (0.50, 0.35)),   # centre both dims
    ('RupankarBagchi.png','artist_rupankar.jpg',  (0.40, 0.30)),   # slightly left, upper
    ('lak.png',           'artist_lak.jpg',       (0.50, 0.25)),   # centre, keep top
]

for src, dst, centre in artists:
    img = open_img(src).convert('RGBA')
    rgb = composite_on_bg(img, (20, 0, 0))   # dark bg for concert/stage photos
    fitted = ImageOps.fit(rgb, (ART_W, ART_H), Image.LANCZOS, centering=centre)
    # Slight contrast/sharpness boost for photos
    fitted = ImageEnhance.Sharpness(fitted).enhance(1.25)
    fitted = ImageEnhance.Contrast(fitted).enhance(1.10)
    save_jpg(fitted, dst, quality=93)

# ══════════════════════════════════════════════════════════════
# 4.  SOUL OF DURGA PUJA / TRADITIONS  →  same size
# ══════════════════════════════════════════════════════════════
print("\n[ Tradition / Soul-of-Puja images ]")

# Display context: trad-card ≈ 580×500px → target 660×520
TRD_W, TRD_H = 660, 520

traditions = [
    ('Dhakbeats.png',     'trad_dhak.jpg',      (0.50, 0.50), (230,220,210)),  # neutral warm bg
    ('Dhunichinaach.png', 'trad_dhunuchi.jpg',  (0.50, 0.40), (245,240,235)),
    ('Sindoorkhela.png',  'trad_sindoor.jpg',   (0.50, 0.50), (250,245,245)),
    ('Communitybhog.png', 'trad_bhog.jpg',      (0.50, 0.50), (245,235,220)),
]

for src, dst, centre, bg_color in traditions:
    img = open_img(src).convert('RGBA')
    rgb = composite_on_bg(img, bg_color)
    fitted = ImageOps.fit(rgb, (TRD_W, TRD_H), Image.LANCZOS, centering=centre)
    fitted = ImageEnhance.Sharpness(fitted).enhance(1.20)
    save_jpg(fitted, dst, quality=90)

print("\nAll images processed successfully.")

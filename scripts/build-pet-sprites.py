#!/usr/bin/env python3
"""CoreAI 0.7.1: the built-in pets of Settings > Pets as pixel-art sprite sheets.

    python3 scripts/build-pet-sprites.py          # writes images/pets/<id>.png
    python3 scripts/build-pet-sprites.py --check  # fails when a committed sheet differs

Every pet is original CoreAI art (Terran space companions), drawn here with simple shapes on a
32 x 32 grid and an automatic dark outline. A sheet is one row of six square frames, the same
format a person may upload (api/pets_store.php). Needs Pillow.
"""
import io
import math
import sys
from pathlib import Path

from PIL import Image, ImageDraw

SIZE = 32
FRAMES = 6
OUT = (34, 28, 52, 255)
ROOT = Path(__file__).resolve().parent.parent
TARGET = ROOT / 'images' / 'pets'

BOB = [0, -1, -1, 0, 1, 1]


def layer():
    return Image.new('RGBA', (SIZE, SIZE), (0, 0, 0, 0))


def outline(img):
    """Dark outline around every opaque pixel (4-neighbourhood); keeps glow pixels unoutlined."""
    src = img.load()
    out = img.copy()
    dst = out.load()
    for y in range(SIZE):
        for x in range(SIZE):
            if src[x, y][3] != 0:
                continue
            for dx, dy in ((1, 0), (-1, 0), (0, 1), (0, -1)):
                nx, ny = x + dx, y + dy
                if 0 <= nx < SIZE and 0 <= ny < SIZE and src[nx, ny][3] == 255:
                    dst[x, y] = OUT
                    break
    return out


def face(d, cx, cy, blink=False, cheeks=(255, 140, 160, 255), mouth=True):
    if blink:
        d.line([(cx - 4, cy), (cx - 3, cy)], fill=OUT)
        d.line([(cx + 3, cy), (cx + 4, cy)], fill=OUT)
    else:
        d.rectangle([cx - 4, cy - 1, cx - 3, cy], fill=OUT)
        d.rectangle([cx + 3, cy - 1, cx + 4, cy], fill=OUT)
    if cheeks:
        d.point([(cx - 5, cy + 2), (cx + 5, cy + 2)], fill=cheeks)
    if mouth:
        d.point([(cx - 1, cy + 2), (cx + 1, cy + 2), (cx, cy + 3)], fill=OUT)


def star_points(cx, cy, outer, inner, turn=-math.pi / 2):
    pts = []
    for i in range(10):
        r = outer if i % 2 == 0 else inner
        a = turn + i * math.pi / 5
        pts.append((cx + r * math.cos(a), cy + r * math.sin(a)))
    return pts


def sparkle(d, x, y, color):
    d.point([(x, y), (x - 1, y), (x + 1, y), (x, y - 1), (x, y + 1)], fill=color)


# --- The pets ------------------------------------------------------------------------------------------

def nova(f):
    """Nova: a small star that sparkles with ideas."""
    img = layer()
    d = ImageDraw.Draw(img)
    cy = 17 + BOB[f]
    d.polygon(star_points(16, cy, 12, 5.6), fill=(255, 205, 64, 255))
    d.polygon(star_points(16, cy - 1, 7, 3.2), fill=(255, 232, 140, 255))
    img = outline(img)
    d = ImageDraw.Draw(img)
    face(d, 16, cy + 1, blink=(f == 3))
    spots = [((4, 6), (27, 26)), ((27, 5), (5, 25)), ((3, 15), (28, 15))]
    for i, (a, b) in enumerate(spots):
        if f % 3 == i:
            sparkle(d, *a, (255, 255, 255, 255))
            sparkle(d, *b, (255, 236, 150, 255))
    return img


def komet(f):
    """Komet: races with you through long tasks."""
    img = layer()
    d = ImageDraw.Draw(img)
    hx, hy = 20, 13 + BOB[f]
    tail = [3, 5, 2, 4, 3, 6][f]
    d.polygon([(hx - 2, hy - 6), (tail, hy + 9), (hx + 2, hy + 6)], fill=(255, 150, 60, 255))
    d.polygon([(hx - 1, hy - 3), (tail + 4, hy + 8), (hx + 1, hy + 4)], fill=(255, 220, 120, 255))
    d.ellipse([hx - 7, hy - 7, hx + 7, hy + 7], fill=(170, 226, 255, 255))
    d.ellipse([hx - 4, hy - 6, hx + 2, hy - 2], fill=(225, 246, 255, 255))
    img = outline(img)
    d = ImageDraw.Draw(img)
    face(d, hx, hy + 1, blink=(f == 4))
    if f in (1, 4):
        d.point([(tail + 1, hy + 13), (tail + 6, hy + 14)], fill=(255, 200, 110, 255))
    return img


def orbi(f):
    """Orbi: a tiny planet that circles your questions."""
    img = layer()
    d = ImageDraw.Draw(img)
    cy = 16 + BOB[f]
    angle = f * math.pi / 3
    mx, my = 16 + 13 * math.cos(angle), cy + 5 * math.sin(angle)
    behind = math.sin(angle) < 0
    if behind:
        d.ellipse([mx - 2, my - 2, mx + 2, my + 2], fill=(210, 214, 230, 255))
    d.arc([2, cy - 5, 29, cy + 5], 180, 360, fill=(240, 196, 110, 255), width=2)
    d.ellipse([7, cy - 9, 25, cy + 9], fill=(84, 178, 140, 255))
    d.chord([7, cy - 9, 25, cy + 9], 20, 160, fill=(64, 150, 120, 255))
    d.ellipse([10, cy - 7, 15, cy - 3], fill=(150, 220, 190, 255))
    d.arc([2, cy - 5, 29, cy + 5], 0, 180, fill=(240, 196, 110, 255), width=2)
    if not behind:
        d.ellipse([mx - 2, my - 2, mx + 2, my + 2], fill=(210, 214, 230, 255))
    img = outline(img)
    d = ImageDraw.Draw(img)
    face(d, 16, cy - 1, blink=(f == 2), mouth=False)
    d.point([(15, cy + 2), (17, cy + 2), (16, cy + 3)], fill=OUT)
    return img


def luna(f):
    """Luna: a sleepy crescent moon for calm night shifts."""
    img = layer()
    px = img.load()
    cy = 17 + BOB[f]
    for y in range(SIZE):
        for x in range(SIZE):
            in_a = (x - 15) ** 2 + (y - cy) ** 2 <= 11 ** 2
            in_b = (x - 21) ** 2 + (y - cy + 3) ** 2 <= 9 ** 2
            if in_a and not in_b:
                px[x, y] = (250, 232, 160, 255) if (x + y) % 7 else (236, 210, 130, 255)
    img = outline(img)
    d = ImageDraw.Draw(img)
    d.line([(7, cy + 1), (9, cy + 1)], fill=OUT)
    d.point([(8, cy + 4), (9, cy + 5)], fill=OUT)
    d.point([(6, cy + 3)], fill=(255, 150, 170, 255))
    z = [(21, 9), (22, 7), (23, 5), (24, 4), (25, 3), (26, 2)][f]
    zc = (150, 165, 245, 255)
    d.line([(z[0], z[1]), (z[0] + 3, z[1])], fill=zc)
    d.point([(z[0] + 2, z[1] + 1), (z[0] + 1, z[1] + 2)], fill=zc)
    d.line([(z[0], z[1] + 3), (z[0] + 3, z[1] + 3)], fill=zc)
    return img


def quanti(f):
    """Quanti: a quantum cube flickering between its states."""
    img = layer()
    d = ImageDraw.Draw(img)
    cy = 15 + BOB[f]
    glow = [0, 20, 40, 60, 40, 20][f]
    top = (120 + glow, 220, 255, 255)
    left = (90, 120 + glow, 230, 255)
    right = (150 + glow // 2, 90, 220, 255)
    d.polygon([(16, cy - 9), (27, cy - 3), (16, cy + 3), (5, cy - 3)], fill=top)
    d.polygon([(5, cy - 3), (16, cy + 3), (16, cy + 15), (5, cy + 9)], fill=left)
    d.polygon([(16, cy + 3), (27, cy - 3), (27, cy + 9), (16, cy + 15)], fill=right)
    img = outline(img)
    d = ImageDraw.Draw(img)
    d.line([(16, cy + 3), (16, cy + 14)], fill=(220, 240, 255, 255))
    blink = f == 5
    for ex in (9, 22):
        if blink:
            d.line([(ex - 1, cy + 6), (ex, cy + 6)], fill=OUT)
        else:
            d.rectangle([ex - 1, cy + 5, ex, cy + 6], fill=OUT)
    d.point([(15, cy + 9), (17, cy + 9)], fill=OUT)
    for i, (sx, sy) in enumerate([(3, 4), (28, 6), (27, 26)]):
        if (f + i) % 3 == 0:
            sparkle(d, sx, sy, (180, 240, 255, 255))
    return img


def glimm(f):
    """Glimm: a firefly that lights the way."""
    img = layer()
    d = ImageDraw.Draw(img)
    cy = 15 + BOB[f]
    up = f % 2 == 0
    wing = (210, 236, 255, 255)
    if up:
        d.ellipse([6, cy - 11, 15, cy - 3], fill=wing)
        d.ellipse([17, cy - 11, 26, cy - 3], fill=wing)
    else:
        d.ellipse([3, cy - 6, 13, cy], fill=wing)
        d.ellipse([19, cy - 6, 29, cy], fill=wing)
    d.ellipse([10, cy - 4, 22, cy + 6], fill=(126, 98, 170, 255))
    pulse = [0, 1, 2, 3, 2, 1][f]
    d.ellipse([12, cy + 4, 20, cy + 12], fill=(170 + pulse * 25, 240, 110, 255))
    img = outline(img)
    d = ImageDraw.Draw(img)
    face(d, 16, cy + 1, blink=(f == 4), cheeks=None, mouth=False)
    d.point([(16, cy + 3)], fill=OUT)
    d.line([(13, cy - 5), (11, cy - 8)], fill=OUT)
    d.line([(19, cy - 5), (21, cy - 8)], fill=OUT)
    if pulse >= 2:
        for gx, gy in [(10, cy + 9), (22, cy + 9), (16, cy + 14)]:
            d.point([(gx, gy)], fill=(220, 255, 150, 255))
    return img


def raki(f):
    """Raki: a little rocket, ready for the next step."""
    img = layer()
    d = ImageDraw.Draw(img)
    cy = 14 + BOB[f]
    flame = [4, 6, 5, 7, 4, 6][f]
    d.polygon([(13, cy + 9), (16, cy + 9 + flame), (19, cy + 9)], fill=(255, 150, 50, 255))
    d.polygon([(14, cy + 9), (16, cy + 6 + flame), (18, cy + 9)], fill=(255, 225, 110, 255))
    d.polygon([(10, cy + 4), (13, cy), (13, cy + 9), (10, cy + 9)], fill=(230, 80, 90, 255))
    d.polygon([(22, cy + 4), (19, cy), (19, cy + 9), (22, cy + 9)], fill=(230, 80, 90, 255))
    d.ellipse([11, cy - 9, 21, cy + 10], fill=(236, 240, 248, 255))
    d.polygon([(12, cy - 5), (16, cy - 12), (20, cy - 5)], fill=(230, 80, 90, 255))
    d.ellipse([13, cy - 4, 19, cy + 2], fill=(110, 190, 250, 255))
    img = outline(img)
    d = ImageDraw.Draw(img)
    blink = f == 2
    for ex in (15, 17):
        d.point([(ex, cy - 1)] if not blink else [], fill=OUT)
    if blink:
        d.line([(15, cy - 1), (17, cy - 1)], fill=OUT)
    d.point([(16, cy + 1)], fill=OUT)
    if f in (1, 3, 5):
        d.point([(13, cy + 12 + flame), (19, cy + 11 + flame)], fill=(255, 210, 120, 255))
    return img


def sputni(f):
    """Sputni: a satellite that listens to the Terra-Net."""
    img = layer()
    d = ImageDraw.Draw(img)
    cy = 18 + BOB[f]
    for x0 in (2, 22):
        d.rectangle([x0, cy - 4, x0 + 7, cy + 3], fill=(70, 110, 210, 255))
        d.line([(x0 + 2, cy - 4), (x0 + 2, cy + 3)], fill=(120, 170, 255, 255))
        d.line([(x0 + 5, cy - 4), (x0 + 5, cy + 3)], fill=(120, 170, 255, 255))
    d.rectangle([9, cy - 1, 22, cy], fill=(180, 180, 200, 255))
    d.rounded_rectangle([10, cy - 7, 21, cy + 5], radius=3, fill=(236, 192, 90, 255))
    d.line([(16, cy - 7), (16, cy - 11)], fill=(200, 200, 215, 255), width=1)
    d.ellipse([14, cy - 13, 18, cy - 10], fill=(255, 110, 120, 255) if f % 2 == 0 else (255, 200, 205, 255))
    img = outline(img)
    d = ImageDraw.Draw(img)
    face(d, 16, cy - 2, blink=(f == 3), cheeks=(255, 150, 120, 255))
    waves = [0, 1, 2, 3, 2, 1][f]
    wc = (130, 230, 200, 255)
    for i in range(waves):
        r = 4 + i * 3
        d.arc([16 - r, cy - 13 - r, 16 + r, cy - 13 + r], 215, 325, fill=wc)
    return img


PETS = {'nova': nova, 'komet': komet, 'orbi': orbi, 'luna': luna, 'quanti': quanti, 'glimm': glimm, 'raki': raki, 'sputni': sputni}


def sheet(draw):
    out = Image.new('RGBA', (SIZE * FRAMES, SIZE), (0, 0, 0, 0))
    for f in range(FRAMES):
        out.paste(draw(f), (f * SIZE, 0))
    return out


def png_bytes(img):
    buf = io.BytesIO()
    img.save(buf, 'PNG', optimize=True)
    return buf.getvalue()


def main():
    check = '--check' in sys.argv[1:]
    TARGET.mkdir(parents=True, exist_ok=True)
    differs = []
    for name, draw in PETS.items():
        data = png_bytes(sheet(draw))
        path = TARGET / (name + '.png')
        if check:
            # Compare pixels, not bytes: another zlib may compress the same image differently.
            if not path.is_file() or Image.open(path).convert('RGBA').tobytes() != Image.open(io.BytesIO(data)).tobytes():
                differs.append(name)
        else:
            path.write_bytes(data)
    if differs:
        print('Pet sprites differ from the generator: ' + ', '.join(differs), file=sys.stderr)
        return 1
    print(('Checked ' if check else 'Wrote ') + str(len(PETS)) + ' pet sprite sheets (' + str(FRAMES) + ' frames of ' + str(SIZE) + ' px).')
    return 0


if __name__ == '__main__':
    sys.exit(main())

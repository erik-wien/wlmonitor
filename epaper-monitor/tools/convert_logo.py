#!/usr/bin/env python3
"""Wandelt eine gerasterte Wiener-Linien-Logo-PNG in ein zweifarbiges
(Schwarz/Rot) Bitmap-Arraypaar fuer GxEPD2::drawBitmap() um.

Nutzung:
    rsvg-convert -w 960 -h 224 logo.svg -o logo.png
    python3 convert_logo.py logo.png ../src/logo.h
"""
import sys
from PIL import Image

TARGET_W, TARGET_H = 240, 56
# Aus der Spec (§7): Logo-Farben #e3000f (rot) / #240c4b (schwarz gerendert).
RED = (0xe3, 0x00, 0x0f)
BLACK_ISH = (0x24, 0x0c, 0x4b)


def nearest(rgb, candidates):
    r, g, b = rgb
    best, best_dist = None, None
    for name, (cr, cg, cb) in candidates.items():
        d = (r - cr) ** 2 + (g - cg) ** 2 + (b - cb) ** 2
        if best_dist is None or d < best_dist:
            best, best_dist = name, d
    return best


def classify_pixel(rgba, candidates):
    r, g, b, a = rgba
    if a < 128:
        return "white"
    return nearest((r, g, b), candidates)


def pack_planes(pixels, width, height, candidates):
    """pixels: Liste von RGBA-Tupeln, zeilenweise, Laenge == width*height."""
    bytes_per_row = (width + 7) // 8
    black_plane = bytearray(bytes_per_row * height)
    red_plane = bytearray(bytes_per_row * height)

    for y in range(height):
        for x in range(width):
            color = classify_pixel(pixels[y * width + x], candidates)
            byte_index = y * bytes_per_row + (x // 8)
            bit = 0x80 >> (x % 8)
            if color == "black":
                black_plane[byte_index] |= bit
            elif color == "red":
                red_plane[byte_index] |= bit

    return bytes(black_plane), bytes(red_plane)


def emit_array(name, data):
    lines = [f"const unsigned char {name}[] PROGMEM = {{"]
    for i in range(0, len(data), 16):
        row = ", ".join(f"0x{b:02x}" for b in data[i:i + 16])
        lines.append(f"    {row},")
    lines.append("};")
    return "\n".join(lines)


def main():
    if len(sys.argv) != 3:
        print("Usage: convert_logo.py <input.png> <output.h>", file=sys.stderr)
        sys.exit(1)

    img = Image.open(sys.argv[1]).convert("RGBA")
    img = img.resize((TARGET_W, TARGET_H), Image.LANCZOS)
    pixels = list(img.getdata())

    candidates = {"white": (255, 255, 255), "red": RED, "black": BLACK_ISH}
    black_plane, red_plane = pack_planes(pixels, TARGET_W, TARGET_H, candidates)

    with open(sys.argv[2], "w") as f:
        f.write("#pragma once\n")
        f.write("#include <pgmspace.h>\n\n")
        f.write("// Erzeugt aus dem Wiener-Linien-Logo per tools/convert_logo.py.\n")
        f.write(f"#define LOGO_WIDTH {TARGET_W}\n")
        f.write(f"#define LOGO_HEIGHT {TARGET_H}\n\n")
        f.write(emit_array("LOGO_BLACK", black_plane))
        f.write("\n\n")
        f.write(emit_array("LOGO_RED", red_plane))
        f.write("\n")


if __name__ == "__main__":
    main()

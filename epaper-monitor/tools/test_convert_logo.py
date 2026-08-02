import unittest
from convert_logo import nearest, classify_pixel, pack_planes, RED, BLACK_ISH

CANDIDATES = {"white": (255, 255, 255), "red": RED, "black": BLACK_ISH}


class TestNearest(unittest.TestCase):
    def test_pure_red_classifies_as_red(self):
        self.assertEqual(nearest(RED, CANDIDATES), "red")

    def test_pure_black_classifies_as_black(self):
        self.assertEqual(nearest(BLACK_ISH, CANDIDATES), "black")

    def test_white_classifies_as_white(self):
        self.assertEqual(nearest((255, 255, 255), CANDIDATES), "white")


class TestClassifyPixel(unittest.TestCase):
    def test_transparent_pixel_is_white(self):
        self.assertEqual(classify_pixel((0, 0, 0, 0), CANDIDATES), "white")

    def test_opaque_red_is_red(self):
        self.assertEqual(classify_pixel((*RED, 255), CANDIDATES), "red")


class TestPackPlanes(unittest.TestCase):
    def test_2x1_red_then_black(self):
        pixels = [(*RED, 255), (*BLACK_ISH, 255)]
        black, red = pack_planes(pixels, 2, 1, CANDIDATES)
        # Ein Byte pro Zeile bei Breite 2. Pixel 0 = MSB (0x80), Pixel 1 = 0x40.
        self.assertEqual(red[0], 0x80)
        self.assertEqual(black[0], 0x40)

    def test_row_padding_to_byte_boundary(self):
        # Breite 9 -> 2 Bytes pro Zeile (9 Bit runden auf 16 auf).
        pixels = [(255, 255, 255, 255)] * 9
        black, red = pack_planes(pixels, 9, 1, CANDIDATES)
        self.assertEqual(len(black), 2)
        self.assertEqual(len(red), 2)


if __name__ == "__main__":
    unittest.main()

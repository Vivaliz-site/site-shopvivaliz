import unittest

class TestExample(unittest.TestCase):

    def test_subtraction(self):
        self.assertEqual(5 - 3, 2)

    def test_multiplication(self):
        self.assertEqual(2 * 3, 6)

if __name__ == '__main__':
    unittest.main()

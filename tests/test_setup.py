import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import importlib.util


class SetupTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        (self.root / 'scripts').mkdir()
        shutil.copy(Path(__file__).resolve().parents[1] / 'scripts/setup.py', self.root / 'scripts')

    def tearDown(self):
        self.temp.cleanup()

    def run_setup(self, *args):
        return subprocess.run(['python3', str(self.root / 'scripts/setup.py'), *args],
                              capture_output=True, text=True)

    def test_setup_keeps_secrets_private_and_is_not_destructive(self):
        first = self.run_setup()
        self.assertEqual(first.returncode, 0, first.stderr)
        password = (self.root / 'secrets/db_password').read_text().strip()
        keys = json.loads((self.root / 'secrets/wp_salts.json').read_text())
        env = (self.root / '.env').read_text()
        self.assertNotIn(password, first.stdout + first.stderr + env)
        self.assertGreaterEqual(len(password), 48)
        self.assertEqual(len(set(keys.values())), 8)
        self.assertTrue(all(len(k) == 128 for k in keys.values()))
        self.assertEqual((self.root / 'secrets').stat().st_mode & 0o777, 0o700)
        self.assertEqual((self.root / '.env').stat().st_mode & 0o777, 0o600)
        self.assertNotEqual(self.run_setup().returncode, 0)
        self.assertEqual((self.root / '.env').read_text(), env)
        self.assertEqual((self.root / 'secrets/db_password').read_text().strip(), password)

    def test_rotation_only_changes_signing_keys(self):
        self.assertEqual(self.run_setup().returncode, 0)
        env = (self.root / '.env').read_bytes()
        password = (self.root / 'secrets/db_password').read_bytes()
        salts = (self.root / 'secrets/wp_salts.json').read_bytes()
        result = self.run_setup('--rotate-salts')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((self.root / '.env').read_bytes(), env)
        self.assertEqual((self.root / 'secrets/db_password').read_bytes(), password)
        self.assertNotEqual((self.root / 'secrets/wp_salts.json').read_bytes(), salts)

    def test_symlink_secret_directory_is_rejected(self):
        (self.root / 'destination').mkdir()
        (self.root / 'secrets').symlink_to(self.root / 'destination')
        self.assertNotEqual(self.run_setup().returncode, 0)
        self.assertEqual(list((self.root / 'destination').iterdir()), [])

    def test_interrupted_rotation_preserves_current_keys_and_cleans_temporary_file(self):
        self.assertEqual(self.run_setup().returncode, 0)
        before = (self.root / 'secrets/wp_salts.json').read_bytes()
        spec = importlib.util.spec_from_file_location('isolated_setup', self.root / 'scripts/setup.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        with patch.object(module.os, 'replace', side_effect=OSError('fixture failure')):
            with patch('sys.argv', ['setup.py', '--rotate-salts']):
                with self.assertRaises(OSError):
                    module.main()
        self.assertEqual((self.root / 'secrets/wp_salts.json').read_bytes(), before)
        self.assertEqual(list((self.root / 'secrets').glob('salts-*')), [])


if __name__ == '__main__':
    unittest.main()

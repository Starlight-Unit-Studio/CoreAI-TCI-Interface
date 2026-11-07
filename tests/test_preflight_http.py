"""Run the actual preflight page checks with real curl file transfers, no server needed."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]

class PreflightHTTPTest(unittest.TestCase):
    def check_pages(self, settings, admin, failure=0, source=None):
        source = source or (ROOT / 'scripts/preflight.sh').read_text()
        start = source.index('if curl -fsS --max-time 10 "http://127.0.0.1:${COREUI_HTTP_PORT}/settings.html"')
        checks = source[start:source.index('SESSION_ROUTE_STATUS=', start)]
        with tempfile.TemporaryDirectory() as folder:
            Path(folder, 'settings.html').write_text(settings)
            Path(folder, 'index.html').write_text(admin)
            script = '''set -uo pipefail
COREUI_HTTP_PORT=9080
failures=0
ok() { :; }
fail() { failures=$((failures + 1)); }
curl() {
  local url="${!#}"
  if [[ "$FAIL_TRANSFER" != 0 ]]; then
    printf 'EINSTELLUNGEN id="adminNavGroup" ADMIN CORE'
    return "$FAIL_TRANSFER"
  fi
  command curl -fsS "file://$FIXTURE_DIR/${url##*/}"
}
'''
            result = subprocess.run(['bash', '-c', script + checks + '\nexit "$failures"'],
                env={**os.environ, 'FIXTURE_DIR': folder, 'FAIL_TRANSFER': str(failure)}, capture_output=True, text=True)
            return result

    def test_large_responses_are_drained(self):
        result = self.check_pages('EINSTELLUNGEN id="adminNavGroup"\n' + 'x' * 2_000_000,
                                  'ADMIN CORE\n' + 'y' * 2_000_000)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, '')

    def test_missing_markers_fail(self):
        self.assertEqual(self.check_pages('wrong page', 'wrong page').returncode, 2)

    def test_transfer_errors_with_matching_partial_body_fail(self):
        for status in (22, 23, 28):
            with self.subTest(status=status):
                self.assertEqual(self.check_pages('', '', status).returncode, 2)

    def test_original_quiet_grep_reproduces_curl_23(self):
        source = (ROOT / 'scripts/preflight.sh').read_text()
        for marker in ('EINSTELLUNGEN', 'id="adminNavGroup"', 'ADMIN CORE'):
            source = source.replace("grep -F '" + marker + "' >/dev/null", "grep -Fq '" + marker + "'")
        result = self.check_pages('EINSTELLUNGEN id="adminNavGroup"\n' + 'x' * 2_000_000,
                                  'ADMIN CORE\n' + 'y' * 2_000_000, source=source)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('curl: (23)', result.stderr)

if __name__ == '__main__':
    unittest.main()

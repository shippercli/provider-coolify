"""Bound automatic retry waits and prevent retrying mutation requests."""
import http.server
import json
from pathlib import Path
import subprocess
import threading
import unittest


class RetryTest(unittest.TestCase):
    def test_http_retry_bounds_and_request_methods(self):
        calls = []
        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass
            def respond(self):
                path = self.path.removeprefix('/api/v1')
                calls.append((self.command, path))
                count = calls.count((self.command, path))
                status = 200 if path == '/retry-short' and count == 3 else 429
                body = json.dumps({'uuid': 'ready'} if status == 200 else {'message': 'rate limited'}).encode()
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(body)))
                if status == 429:
                    delay = '3600' if path == '/retry-long' else ('invalid' if path == '/retry-invalid' else '0')
                    self.send_header('Retry-After', delay)
                self.end_headers()
                self.wfile.write(body)
            do_GET = respond
            do_POST = respond
        with http.server.HTTPServer(('127.0.0.1', 0), Handler) as server:
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                result = subprocess.run(['php', str(Path(__file__).with_name('verify-retries.php')),
                                         'http://127.0.0.1:'+str(server.server_port)],
                                        capture_output=True, text=True, timeout=10)
                self.assertEqual(result.returncode, 0, result.stdout+result.stderr)
                self.assertEqual(calls.count(('GET', '/retry-short')), 3)
                self.assertEqual(calls.count(('POST', '/retry-post')), 1)
                self.assertEqual(calls.count(('GET', '/retry-long')), 1)
                self.assertEqual(calls.count(('GET', '/retry-invalid')), 1)
            finally:
                server.shutdown()
                thread.join(timeout=5)


if __name__ == '__main__':
    unittest.main()

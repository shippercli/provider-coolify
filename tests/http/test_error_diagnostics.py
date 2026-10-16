"""Ensure real HTTP failures do not echo credentials in client diagnostics."""
import http.server
import json
from pathlib import Path
import subprocess
import threading
import unittest


class ErrorDiagnosticsTest(unittest.TestCase):
    def test_failed_json_text_and_multipart_requests(self):
        calls = []
        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass
            def respond(self):
                body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
                path = self.path.removeprefix('/api/v1')
                calls.append((self.command, path, self.headers.get('Authorization'), body))
                status = {'/fail-json': 422, '/fail-text': 401, '/fail-multipart': 403,
                          '/fail-html': 502, '/fail-rate': 429}.get(path, 404)
                message = 'synthetic-api-token synthetic-db-secret synthetic-upload-secret'
                response = message.encode() if path == '/fail-html' else json.dumps({'message': message}).encode()
                self.send_response(status)
                self.send_header('Content-Type', 'text/html' if path == '/fail-html' else 'application/json')
                self.send_header('Content-Length', str(len(response)))
                if status == 429:
                    self.send_header('Retry-After', '2')
                self.end_headers()
                self.wfile.write(response)
            do_GET = respond
            do_POST = respond
        with http.server.HTTPServer(('127.0.0.1', 0), Handler) as server:
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                script = Path(__file__).with_name('verify-error-diagnostics.php')
                result = subprocess.run(['php', str(script), 'http://127.0.0.1:'+str(server.server_port)],
                                        capture_output=True, text=True, timeout=20)
                self.assertEqual(result.returncode, 0, result.stdout+result.stderr)
                self.assertEqual(len(calls), 5)
                self.assertTrue(all(call[2] == 'Bearer synthetic-api-token' for call in calls))
                self.assertIn(b'synthetic-db-secret', next(c[3] for c in calls if c[1] == '/fail-json'))
                upload = next(c[3] for c in calls if c[1] == '/fail-multipart')
                self.assertIn(b'synthetic-db-secret', upload)
                self.assertIn(b'synthetic-upload-secret', upload)
            finally:
                server.shutdown()
                thread.join(timeout=5)


if __name__ == '__main__':
    unittest.main()

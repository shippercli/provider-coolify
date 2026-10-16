"""Exercise the actual PHP cURL/JSON boundary against a local synthetic server."""
import http.server
from pathlib import Path
import subprocess
import threading
import unittest


class InventoryShapeTest(unittest.TestCase):
    def test_http_shapes_and_provider_flows(self):
        calls = []
        responses = {
            '/empty-object': b'{}', '/numeric-object': b'{"0":{"uuid":"one"}}',
            '/wrapped-object': b'{"data":{}}', '/wrapped-items-object': b'{"items":{}}',
            '/blank': b'', '/both': b'{"data":[],"items":[]}',
            '/empty-array': b'[]', '/wrapped-array': b'{"data":[]}',
            '/items-array': b'{"items":[]}', '/ack': b'{}', '/databases': b'{}',
        }
        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass
            def respond(self):
                if not self.path.startswith('/api/v1/'):
                    self.send_error(404)
                    return
                path = self.path[len('/api/v1'):]
                calls.append((self.command, path))
                body = responses.get(path.split('?')[0], b'[]')
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(body)))
                self.end_headers()
                self.wfile.write(body)
            do_GET = respond
            do_PATCH = respond
            do_DELETE = respond
            do_POST = respond
        with http.server.HTTPServer(('127.0.0.1', 0), Handler) as server:
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                script = Path(__file__).with_name('verify-inventory-shapes.php')
                result = subprocess.run(['php', str(script), 'http://127.0.0.1:'+str(server.server_port)],
                                        capture_output=True, text=True, timeout=20)
                self.assertEqual(result.returncode, 0, result.stdout+result.stderr)
                self.assertIn('checks passed', result.stdout)
                self.assertFalse(any(method in ('DELETE', 'POST') for method, _ in calls), calls)
                self.assertEqual([('PATCH', '/ack')], [call for call in calls if call[0] != 'GET'])
            finally:
                server.shutdown()
                thread.join(timeout=5)


if __name__ == '__main__':
    unittest.main()

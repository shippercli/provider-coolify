"""Exercise public Coolify status against secret-bearing local HTTP fixtures."""
import hashlib
import http.server
import json
from pathlib import Path
import subprocess
import threading
import unittest


class StatusPrivacyTest(unittest.TestCase):
    def test_deployment_summaries(self):
        calls = []
        scope = 'shippercli-' + hashlib.sha256(b'["fixture","production"]').hexdigest()[:16]
        task_prefix = 'shipper-' + hashlib.sha256(b'["fixture","production"]').hexdigest()[:10] + '-'

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_GET(self):
                calls.append((self.command, self.path, self.headers.get('Authorization')))
                data = []
                if self.path == '/api/v1/applications/app-1':
                    data = {'uuid': 'app-1', 'id': 1, 'name': 'fixture', 'status': 'running', 'tags': ['shippercli-managed', scope]}
                elif self.path == '/api/v1/databases':
                    data = [{'uuid': 'db-1', 'name': 'main', 'type': 'mysql', 'tags': ['shippercli-managed', scope + '-db-0123456789']}]
                elif self.path == '/api/v1/databases/db-1':
                    data = {'uuid': 'db-1', 'name': 'main', 'type': 'mysql', 'status': 'running'}
                elif self.path == '/api/v1/databases/db-1/backups/backup-1/executions':
                    data = {'executions': [{'uuid': 'backup-execution-1', 'status': 'success', 'output': 'synthetic-status-secret', 'credentials': {'token': 'synthetic-status-secret'}}]}
                elif self.path == '/api/v1/applications/app-1/scheduled-tasks':
                    data = [{'uuid': 'task-1', 'name': task_prefix + 'nightly', 'command': 'echo synthetic-status-secret', 'frequency': 'daily', 'enabled': True}]
                elif self.path == '/api/v1/applications/app-1/scheduled-tasks/task-1/executions':
                    data = [{'uuid': 'execution-1', 'status': 'success', 'created_at': '2026-10-16T12:00:00Z', 'output': 'synthetic-status-secret', 'extra': {'token': 'synthetic-status-secret'}}]
                elif self.path == '/api/v1/deployments' or self.path.startswith('/api/v1/deployments/applications/app-1?'):
                    data = [{'deployment_uuid': 'deployment-1', 'application_id': 1, 'status': 'finished',
                             'created_at': '2026-10-16T12:00:00Z', 'logs': 'DB_PASSWORD=synthetic-status-secret',
                             'extra': {'token': 'synthetic-status-secret'}, 'env': {'TOKEN': 'synthetic-status-secret'}}]
                payload = json.dumps(data).encode()
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

        with http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler) as server:
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                result = subprocess.run(['php', str(Path(__file__).with_name('verify-status-privacy.php')),
                                         f'http://127.0.0.1:{server.server_port}'], capture_output=True, text=True, timeout=15)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertTrue(calls)
                self.assertTrue(all(method == 'GET' and token == 'Bearer synthetic-status-token' for method, _, token in calls))
                self.assertEqual(sum(path == '/api/v1/deployments' for _, path, _ in calls), 1)
                self.assertEqual(sum(path.startswith('/api/v1/deployments/applications/app-1?') for _, path, _ in calls), 1)
            finally:
                server.shutdown()
                thread.join(timeout=2)


if __name__ == '__main__':
    unittest.main()

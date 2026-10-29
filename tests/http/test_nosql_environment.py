"""Verify namespaced non-SQL environment delivery through real local HTTP."""
import http.server
import json
from pathlib import Path
import subprocess
import threading
import unittest


class NonSqlEnvironmentTest(unittest.TestCase):
    def test_missing_fields_and_explicit_empty_credentials(self):
        calls = []

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def respond(self):
                body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
                calls.append((self.command, self.path, self.headers.get('Authorization'), body))
                payload = b'[]' if self.command == 'GET' else b'{}'
                if self.command == 'GET' and self.path == '/api/v1/databases/literal-env/envs':
                    payload = json.dumps([
                        {'uuid': 'existing', 'key': 'PASSWORD', 'comment': 'Managed by ShipperCLI'},
                        {'uuid': 'stale', 'key': 'OLD', 'comment': 'Managed by ShipperCLI'},
                        {'uuid': 'unmanaged', 'key': 'OTHER', 'comment': 'Unmanaged'}]).encode()
                elif self.command == 'POST' and self.path == '/api/v1/databases/postgresql':
                    payload = b'{"uuid":"literal-created"}'
                elif self.command == 'GET' and self.path == '/api/v1/databases/literal-created':
                    payload = json.dumps({'uuid': 'literal-created', 'internal_db_host': 'db.internal',
                        'postgres_db': 'main', 'postgres_user': 'user',
                        'postgres_password': 'literal-${PROJECT_NAME}-${PROFILE}-+%:@',
                        'server_uuid': 'server', 'environment_uuid': 'environment'}).encode()
                self.send_response(200)
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

            do_GET = respond
            do_POST = respond
            do_PATCH = respond
            do_DELETE = respond

        with http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler) as server:
            threading.Thread(target=server.serve_forever, daemon=True).start()
            try:
                result = subprocess.run(['php', str(Path(__file__).with_name('verify-nosql-environment.php')),
                    f'http://127.0.0.1:{server.server_port}'], capture_output=True, text=True, timeout=20)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertEqual(len(calls), 63)
                self.assertEqual([row[:2] for row in calls if row[1] in ['/api/v1/databases', '/api/v1/databases/postgresql', '/api/v1/databases/literal-created', '/api/v1/databases/literal-created/envs']], [('GET', '/api/v1/databases'), ('POST', '/api/v1/databases/postgresql'), ('GET', '/api/v1/databases/literal-created'), ('GET', '/api/v1/databases/literal-created/envs')])
                self.assertTrue(all(auth == 'Bearer synthetic-nosql-token' for _, _, auth, _ in calls))
                for engine in ['redis', 'dragonfly', 'keydb', 'mongodb', 'clickhouse']:
                    path = '/api/v1/applications/' + engine + '/envs'
                    scoped = [call for call in calls if call[1] == path]
                    self.assertEqual(sum(method == 'GET' for method, _, _, _ in scoped), 1)
                    rows = [json.loads(body) for method, _, _, body in scoped if method == 'POST']
                    expected = {'SHIPPER_DB_MAIN_HOST': 'db.internal', 'SHIPPER_DB_MAIN_PASSWORD': ''}
                    if engine in ['mongodb', 'clickhouse']: expected['SHIPPER_DB_MAIN_USERNAME'] = ''
                    self.assertEqual({row['key']: row['value'] for row in rows}, expected)
                    self.assertEqual(len(rows), len(expected))
                    self.assertTrue(all(row['is_preview'] is False for row in rows))
                    preview_path = '/api/v1/applications/' + engine + '-preview/envs'
                    scoped = [call for call in calls if call[1] == preview_path]
                    self.assertEqual(sum(method == 'GET' for method, _, _, _ in scoped), 1)
                    rows = [json.loads(body) for method, _, _, body in scoped if method == 'POST']
                    expected = {'SHIPPER_DB_MAIN_PR_42_HOST': 'preview.internal', 'SHIPPER_DB_MAIN_PR_42_PASSWORD': 'preview-only'}
                    if engine in ['mongodb', 'clickhouse']: expected['SHIPPER_DB_MAIN_PR_42_USERNAME'] = 'preview-user'
                    self.assertEqual({row['key']: row['value'] for row in rows}, expected)
                    self.assertEqual(len(rows), len(expected))
                    self.assertTrue(all(row['is_preview'] is True for row in rows))
                self.assertTrue(all(b'production-secret' not in body for _, _, _, body in calls))
                url_calls = [call for call in calls if call[1] == '/api/v1/applications/mongodb-url/envs']
                self.assertEqual(sum(method == 'GET' for method, _, _, _ in url_calls), 1)
                rows = [json.loads(body) for method, _, _, body in url_calls if method == 'POST']
                self.assertEqual(len(rows), 6)
                expected_url = 'mongodb://user:updated%3A%40secret@first.internal:27017,second.internal:27018/main?replicaSet=rs0&authSource=admin&tls=true'
                self.assertTrue(all(row['is_preview'] is False for row in rows))
                fields = {row['key']: row['value'] for row in rows}
                self.assertEqual(fields['SHIPPER_DB_MAIN_URL'], expected_url)
                self.assertEqual(fields['SHIPPER_DB_MAIN_PASSWORD'], 'updated:@secret')
                self.assertTrue(all(b'old-secret' not in body for _, _, _, body in calls))
                pg_calls = [call for call in calls if call[1] == '/api/v1/applications/postgres-query/envs']
                self.assertEqual(sum(method == 'GET' for method, _, _, _ in pg_calls), 1)
                rows = [json.loads(body) for method, _, _, body in pg_calls if method == 'POST']
                self.assertEqual(len(rows), 12)
                self.assertTrue(all(row['is_preview'] is False for row in rows))
                fields = {row['key']: row['value'] for row in rows}
                self.assertEqual(fields['SHIPPER_DB_MAIN_URL'], 'postgresql://new-user:new%2Bsecret@db.internal:5432/new%20db?user=new-user&password=new%2Bsecret&dbname=new%20db&sslmode=require')
                self.assertEqual(fields['DB_PASSWORD'], 'new+secret')
                self.assertEqual(fields['DB_DATABASE'], 'new db')
                self.assertTrue(all(b'query-secret' not in body for _, _, _, body in calls))
                creates = [json.loads(body) for method, path, _, body in calls if method == 'POST' and path == '/api/v1/databases/postgresql']
                self.assertEqual(len(creates), 1)
                self.assertEqual(creates[0]['postgres_password'], 'literal-${PROJECT_NAME}-${PROFILE}-+%:@')
                env_calls = [row for row in calls if row[1].startswith('/api/v1/databases/literal-env/envs')]
                self.assertEqual(len(env_calls), 5)
                self.assertEqual([(method, path) for method, path, _, _ in env_calls if method == 'DELETE'], [('DELETE', '/api/v1/databases/literal-env/envs/stale')])
                writes = [(method, json.loads(body)) for method, _, _, body in env_calls if method in ['POST', 'PATCH']]
                self.assertEqual([method for method, _ in writes], ['PATCH', 'POST', 'POST'])
                self.assertEqual({row['key']: row['value'] for _, row in writes}, {'PASSWORD': 'literal-${PROJECT_NAME}-${PROFILE}-+%:@', 'TOKEN': 'literal-${PROJECT_NAME}-${PROFILE}-+%:@', 'EMPTY': ''})
            finally:
                server.shutdown()


if __name__ == '__main__':
    unittest.main()

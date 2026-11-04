"""Default-off broker integration on ephemeral HTTP only; no service mutation."""
import http.client
import importlib.util
import io
import json
import threading
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('marina_broker_integration', ROOT / 'marina-direct-mcp/server.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
GRANT = {'operation': 'olist_browser_restart', 'pr_number': 4000, 'head_sha': 'a'*40, 'merge_sha': 'b'*40}
REQUEST = {**GRANT, 'idempotency_key': 'broker-'+'a'*32, 'fencing_token': 1}

class FakeClient:
    def __init__(self):
        self.calls = []
    def prepare(self, data):
        self.calls.append(('prepare', data))
        return {'ok': True, **REQUEST, 'expires_at': 1060}
    def execute(self, data):
        self.calls.append(('execute', data))
        return {'ok': True, 'reason': 'restart_requested'}

class IntegrationTests(unittest.TestCase):
    def serve(self, client):
        class Verifier:
            def verify(self, value):
                return {'email': 'test@example.invalid'} if value == 'valid-test-assertion' else None
        with patch.object(m.socket, 'gethostname', return_value=m.EXPECTED_HOST):
            server = m.create_server(b'x'*64, port=0, admin_client=client, access_verifier=Verifier())
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(lambda: (server.shutdown(), server.server_close(), thread.join(3)))
        return server.server_address[1]
    def rpc(self, port, method, params=None, external=False):
        conn = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
        headers = {'Content-Type':'application/json', 'Accept':'application/json, text/event-stream'}
        headers.update({'Cf-Access-Jwt-Assertion':'valid-test-assertion'} if external else {'Authorization':'Bearer '+'x'*64})
        request = {'jsonrpc':'2.0','id':1,'method':method}
        if params is not None:
            request['params'] = params
        try:
            conn.request('POST','/mcp',json.dumps(request),headers)
            response = conn.getresponse()
            self.assertEqual(response.status,200)
            return json.loads(response.read())
        finally:
            conn.close()
    def test_local_prepare_then_execute_reaches_client_not_fake_root_operator(self):
        client = FakeClient()
        port = self.serve(client)
        names = [x['name'] for x in self.rpc(port,'tools/list')['result']['tools']]
        self.assertIn('marina_olist_browser_restart_prepare',names)
        self.assertIn('marina_olist_browser_restart',names)
        for name, args in [('marina_olist_browser_restart_prepare',GRANT),('marina_olist_browser_restart',REQUEST)]:
            result = self.rpc(port,'tools/call',{'name':name,'arguments':args})
            self.assertFalse(result['result']['isError'])
        self.assertEqual(client.calls,[('prepare',GRANT),('execute',REQUEST)])
    def test_external_access_stays_readonly_even_with_local_broker(self):
        client = FakeClient()
        port = self.serve(client)
        tools = self.rpc(port,'tools/list',external=True)['result']['tools']
        self.assertEqual(len(tools),6)
        for name in ('marina_olist_browser_restart_prepare','marina_olist_browser_restart'):
            self.assertIn('error',self.rpc(port,'tools/call',{'name':name,'arguments':REQUEST},external=True))
        self.assertEqual(client.calls,[])
    def test_absent_client_hides_prepare_and_preserves_reads(self):
        port = self.serve(None)
        self.assertEqual(len(self.rpc(port,'tools/list')['result']['tools']),6)
        self.assertIn('error',self.rpc(port,'tools/call',{'name':'marina_olist_browser_restart_prepare','arguments':GRANT}))
    def test_denied_or_indeterminate_execution_is_not_success_and_not_retried(self):
        client = FakeClient()
        def denied(data):
            client.calls.append(('execute',data))
            return {'ok':False,'reason':'broker_result_indeterminate'}
        client.execute = denied
        port = self.serve(client)
        result = self.rpc(port,'tools/call',{'name':'marina_olist_browser_restart','arguments':REQUEST})
        self.assertTrue(result['result']['isError'])
        self.assertEqual(len(client.calls),1)
        self.assertIn('broker_result_indeterminate',result['result']['content'][0]['text'])

class StartupTests(unittest.TestCase):
    def invoke(self, enabled=False, check=False, unavailable=False):
        args = ['server.py'] + (['--enable-approved-admin-broker'] if enabled else []) + (['--check'] if check else [])
        client = FakeClient()
        def bootstrap():
            if unavailable:
                raise RuntimeError('test-unavailable')
            return client
        module = {'bootstrap_client':bootstrap, 'bootstrap_service_client':lambda: None}
        server = Mock()
        with patch.object(m.sys,'argv',args), patch.object(m.socket,'gethostname',return_value=m.EXPECTED_HOST), patch.object(m,'load_token',return_value=b'x'*64), patch.object(m.runpy,'run_path',return_value=module) as loader, patch.object(m,'create_server',return_value=server) as created, redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
            try:
                code = m.main()
            except SystemExit as exc:
                code = exc.code
        return code, created, loader, client
    def test_default_does_not_load_or_advertise_admin(self):
        code,created,loader,_ = self.invoke()
        self.assertEqual(code,0)
        loader.assert_not_called()
        self.assertIsNone(created.call_args.kwargs.get('admin_client'))
    def test_optin_bootstraps_verified_client(self):
        code,created,_,client = self.invoke(enabled=True)
        self.assertEqual(code,0)
        self.assertIs(created.call_args.kwargs.get('admin_client'),client)
        self.assertIsNone(created.call_args.kwargs.get('service_client'))
    def test_unavailable_broker_downgrades_reads_and_strict_check_denies(self):
        code,created,_,_ = self.invoke(enabled=True,unavailable=True)
        self.assertEqual(code,0)
        self.assertIsNone(created.call_args.kwargs.get('admin_client'))
        code,created,_,_ = self.invoke(enabled=True,check=True,unavailable=True)
        self.assertEqual(code,1)
        created.assert_not_called()

class PackagingTests(unittest.TestCase):
    def test_optional_client_bundled_immutably_without_runtime_enable(self):
        stage = (ROOT / 'marina-direct-mcp/operator_stage_service.sh').read_text()
        upgrade = (ROOT / 'marina-direct-mcp/operator_upgrade_release.sh').read_text()
        self.assertIn('for file in server.py broker_client.py ', stage)
        self.assertIn('check_target "$source_dir/broker_client.py" "$release_dir/broker_client.py" 444', stage)
        self.assertIn('stage_file "$source_dir/broker_client.py" "$release_dir/broker_client.py" 0444', stage)
        self.assertIn('    broker_client.py', upgrade)
        self.assertIn('for name in server.py broker_client.py ', upgrade)
        for unit in (ROOT / 'marina-direct-mcp/systemd').glob('*'):
            # Only the MCP daemon unit enables the client; the broker's own socket and service never do.
            if unit.name == 'shopvivaliz-marina-mcp.service':
                self.assertIn('--enable-approved-admin-broker', unit.read_text())
            else:
                self.assertNotIn('--enable-approved-admin-broker', unit.read_text())


    def test_root_broker_templates_are_bundled_for_separate_granted_install(self):
        upgrade = (ROOT / 'marina-direct-mcp/operator_upgrade_release.sh').read_text()
        required = upgrade.split('required=(', 1)[1].split(')', 1)[0].split()
        for unit in ('shopvivaliz-marina-root-broker.service', 'shopvivaliz-marina-root-broker.socket'):
            self.assertIn('systemd/' + unit, required)
            self.assertIn(
                'copy_immutable "$source_checkout/marina-direct-mcp/systemd/' + unit + '" "$release/' + unit + '"',
                upgrade)
        self.assertNotIn('systemctl enable shopvivaliz-marina-root-broker', upgrade)
        self.assertNotIn('systemctl start shopvivaliz-marina-root-broker', upgrade)

if __name__ == '__main__':
    unittest.main(verbosity=2)

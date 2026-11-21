# Mock ERPNext for local tests: token auth, Bin list, Stock Reconciliation.
import json, sys, urllib.parse
from http.server import BaseHTTPRequestHandler, HTTPServer
KEY, SECRET = 'testkey123', 'testsecret456'
BINS = {('A-100', 'Stores - SFM'): 10.0, ('B-200', 'Stores - SFM'): 0.0, ('C-300', 'Stores - SFM'): 7.0, ('X-999', 'Other - SFM'): 3.0}
RECOS = []
INVOICES = []
WAREHOUSES = {'Stores - SFM': 'SFM4U BD', 'Online - LLC': 'SFM4U LLC', 'Other - SFM': 'SFM4U BD'}
class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def send(self, code, obj):
        b = json.dumps(obj).encode(); self.send_response(code); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(b))); self.end_headers(); self.wfile.write(b)
    def authed(self):
        return self.headers.get('Authorization') == 'token %s:%s' % (KEY, SECRET)
    def do_GET(self):
        u = urllib.parse.urlparse(self.path); q = urllib.parse.parse_qs(u.query)
        if u.path == '/_state': return self.send(200, {'bins': {k[0] + '|' + k[1]: v for k, v in BINS.items()}, 'recos': RECOS, 'invoices': INVOICES})
        if not self.authed(): return self.send(401, {'exc_type': 'AuthenticationError'})
        if u.path == '/api/method/frappe.auth.get_logged_user': return self.send(200, {'message': 'website-api@sfm4u.com'})
        if u.path == '/api/resource/Bin':
            f = json.loads(q.get('filters', ['[]'])[0]); wh = f[0][2] if f else None
            start = int(q.get('limit_start', ['0'])[0]); n = int(q.get('limit_page_length', ['20'])[0])
            rows = [{'item_code': k[0], 'actual_qty': v} for k, v in sorted(BINS.items()) if wh is None or k[1] == wh]
            return self.send(200, {'data': rows[start:start + n]})
        if u.path.startswith('/api/resource/Warehouse/'):
            w = urllib.parse.unquote(u.path.split('/api/resource/Warehouse/', 1)[1])
            if w in WAREHOUSES: return self.send(200, {'data': {'name': w, 'company': WAREHOUSES[w]}})
        self.send(404, {'exc_type': 'DoesNotExistError'})
    def do_POST(self):
        u = urllib.parse.urlparse(self.path)
        if not self.authed(): return self.send(401, {'exc_type': 'AuthenticationError'})
        body = json.loads(self.rfile.read(int(self.headers.get('Content-Length', '0'))) or b'{}')
        if urllib.parse.unquote(u.path) == '/api/resource/Stock Reconciliation':
            if body.get('purpose') != 'Stock Reconciliation' or body.get('docstatus') != 1: return self.send(417, {'exception': 'bad doc'})
            for it in body['items']: BINS[(it['item_code'], it['warehouse'])] = float(it['qty'])
            RECOS.append(body); return self.send(200, {'data': {'name': 'MAT-RECO-%04d' % len(RECOS)}})
        if urllib.parse.unquote(u.path) == '/api/resource/Sales Invoice':
            if body.get('docstatus') != 1 or not body.get('company') or not body.get('items'): return self.send(417, {'exception': 'bad invoice'})
            if WAREHOUSES.get(body.get('set_warehouse')) != body['company']: return self.send(417, {'exception': 'warehouse not in company'})
            for it in body['items']:
                k = (it['item_code'], it['warehouse']); BINS[k] = BINS.get(k, 0.0) - float(it['qty'])
            INVOICES.append(body); return self.send(200, {'data': {'name': 'ACC-SINV-%04d' % len(INVOICES)}})
        self.send(404, {})
HTTPServer(('127.0.0.1', int(sys.argv[1])), H).serve_forever()

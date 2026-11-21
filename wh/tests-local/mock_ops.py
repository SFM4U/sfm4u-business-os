# Mock Steadfast + WhatsApp Cloud API for local tests.
import json, sys
from http.server import BaseHTTPRequestHandler, HTTPServer
KEY, SECRET, TOKEN = 'sfkey', 'sfsecret', 'watoken'
ORDERS = {}; WA = []
class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def send(self, code, obj):
        b = json.dumps(obj).encode(); self.send_response(code); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(b))); self.end_headers(); self.wfile.write(b)
    def sf_ok(self): return self.headers.get('Api-Key') == KEY and self.headers.get('Secret-Key') == SECRET
    def do_GET(self):
        p = self.path
        if p == '/_state': return self.send(200, {'orders': ORDERS, 'wa': WA})
        if p.startswith('/api/v1/'):
            if not self.sf_ok(): return self.send(401, {'message': 'Unauthorized'})
            if p == '/api/v1/get_balance': return self.send(200, {'status': 200, 'current_balance': 1250})
            if p.startswith('/api/v1/status_by_cid/'):
                cid = p.rsplit('/', 1)[1]
                o = ORDERS.get(cid)
                return self.send(200, {'status': 200, 'delivery_status': 'delivered' if o else 'unknown'})
        self.send(404, {})
    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get('Content-Length', '0'))) or b'{}')
        if self.path == '/api/v1/create_order':
            if not self.sf_ok(): return self.send(401, {'message': 'Unauthorized'})
            for f in ['invoice', 'recipient_name', 'recipient_phone', 'recipient_address', 'cod_amount']:
                if f not in body or body[f] in ('', None): return self.send(422, {'status': 400, 'errors': {f: ['required']}})
            if any(o['invoice'] == body['invoice'] for o in ORDERS.values()): return self.send(422, {'status': 400, 'errors': {'invoice': ['The invoice has already been taken.']}})
            cid = str(1000 + len(ORDERS))
            ORDERS[cid] = body
            return self.send(200, {'status': 200, 'message': 'Consignment has been created successfully.', 'consignment': {'consignment_id': int(cid), 'invoice': body['invoice'], 'tracking_code': 'TRK' + cid, 'status': 'in_review', 'cod_amount': body['cod_amount']}})
        if self.path.endswith('/messages'):
            if self.headers.get('Authorization') != 'Bearer ' + TOKEN: return self.send(401, {'error': {'message': 'Invalid OAuth access token.', 'code': 190}})
            WA.append({'path': self.path, 'body': body})
            return self.send(200, {'messaging_product': 'whatsapp', 'contacts': [{'input': body['to'], 'wa_id': body['to']}], 'messages': [{'id': 'wamid.TEST%d' % len(WA)}]})
        self.send(404, {})
HTTPServer(('127.0.0.1', int(sys.argv[1])), H).serve_forever()

#!/usr/bin/env python3
"""Replay SFM4U Business OS ERP documents against a real ERPNext TEST site (inside the backend container).
Token comes from env K/S (never printed). Site chosen by Host header. Prints PASS/FAIL lines only."""
import json, os, sys, urllib.request, urllib.parse, urllib.error

SITE = os.environ.get('SITE', 'erp-test.localhost')
BASE = 'http://127.0.0.1:8000'
AUTH = 'token %s:%s' % (os.environ['K'], os.environ['S'])
ok = fail = 0

def call(method, path, query=None, body=None):
    url = BASE + urllib.parse.quote(path) + ('?' + urllib.parse.urlencode(query) if query else '')
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method, headers={'Host': SITE, 'Authorization': AUTH,
        'Accept': 'application/json', 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.status, json.loads(r.read() or b'{}')
    except urllib.error.HTTPError as e:
        raw = e.read().decode(errors='replace')
        try:
            j = json.loads(raw); msg = j.get('exception') or j.get('_server_messages') or j.get('message') or raw
        except Exception:
            msg = raw
        return e.code, {'error': str(msg)[:400]}

def check(cond, label):
    global ok, fail
    if cond: ok += 1; print('PASS', label)
    else: fail += 1; print('FAIL', label)

def get(doctype, name, field):
    s, d = call('GET', '/api/resource/%s/%s' % (doctype, name))
    return (d.get('data') or {}).get(field)

def bin_qty(item):
    s, d = call('GET', '/api/resource/Bin', {'fields': json.dumps(['actual_qty']), 'filters': json.dumps([['item_code', '=', item], ['warehouse', '=', 'Stores - SBT']])})
    rows = d.get('data') or []
    return float(rows[0]['actual_qty']) if rows else 0.0

plan = json.load(open(sys.argv[1]))
member = plan['member']
names = {}

def subst(o):
    if isinstance(o, dict): return {k: subst(v) for k, v in o.items()}
    if isinstance(o, list): return [subst(v) for v in o]
    if isinstance(o, str):
        if o == '@MEMBER@': return member
        if o.startswith('@') and o.endswith('@') and o[1:-1] in names: return names[o[1:-1]]
    return o

# setup the walk-in customer the plugin expects (owner setup step on a real ERP)
s, d = call('GET', '/api/resource/Customer', {'filters': json.dumps([['name', '=', 'Walk-in Customer']])})
if not d.get('data'):
    s, d = call('POST', '/api/resource/Customer', body={'customer_name': 'Walk-in Customer', 'customer_type': 'Individual', 'customer_group': 'Individual'})
    check(s == 200, 'setup: Walk-in Customer created')

rice0, salt0 = bin_qty('RN-RICE'), bin_qty('RN-SALT')
for st in plan['steps']:
    if st['name'] == 'customer':
        # same calls as ErpNextClient::ensureCustomer (leaf group Individual)
        s, d = call('GET', '/api/resource/Customer', {'fields': json.dumps(['name']), 'filters': json.dumps([['name', '=', member]]), 'limit_page_length': 1})
        if not d.get('data'):
            s, d = call('POST', '/api/resource/Customer', body={'customer_name': member, 'customer_type': 'Individual', 'customer_group': 'Individual'})
        check(s == 200, 'khata member customer accepted by ERPNext: %s' % (d.get('data', {}).get('name') or d.get('error')))
        continue
    doc = subst(st['doc'])
    s, d = call('POST', '/api/resource/%s' % st['doctype'], body=doc)
    name = (d.get('data') or {}).get('name')
    check(s == 200 and name, '%s accepted (%s): %s' % (st['name'], st['doctype'], name or d.get('error')))
    if not name: continue
    names[st['name']] = name
    data = d['data']
    check(data.get('docstatus') == 1, '%s submitted' % st['name'])
    if 'expect_total' in st:
        gt = float(data.get('grand_total') or 0)
        check(abs(gt - st['expect_total']) < 0.001, '%s grand total %.2f = %.2f' % (st['name'], gt, st['expect_total']))
    if 'expect_outstanding' in st:
        inv, want = st['expect_outstanding']
        out = float(get('Sales Invoice', names[inv], 'outstanding_amount') or 0)
        check(abs(out - want) < 0.001, '%s outstanding %.2f = %.2f' % (inv, out, want))
    if st['doctype'] == 'Payment Entry':
        s2, d2 = call('GET', '/api/resource/Payment Entry', {'fields': json.dumps(['name']), 'filters': json.dumps([['reference_no', '=', doc['reference_no']], ['docstatus', '=', 1]]), 'limit_page_length': 1})
        check((d2.get('data') or [{}])[0].get('name') == name, '%s found again by reference_no (idempotency lookup)' % st['name'])

rice1, salt1 = bin_qty('RN-RICE'), bin_qty('RN-SALT')
check(abs((rice1 - rice0) - (-2 + 1)) < 0.001, 'ERP rice stock: sold 2, resellable return 1 -> net -1 (%.0f -> %.0f)' % (rice0, rice1))
check(abs((salt1 - salt0) - (-3)) < 0.001, 'ERP salt stock: sold 3, damaged return NOT back in stock -> net -3 (%.0f -> %.0f)' % (salt0, salt1))
print('pass %d fail %d' % (ok, fail))

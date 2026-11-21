#!/usr/bin/env python3
"""Inventory translation coverage in shipped PHP admin/vendor screens."""
from pathlib import Path
import json
import re

root = Path(__file__).resolve().parent.parent
catalog = (root / 'plugin/sfm4u-wholesale/languages/sfm4u-wholesale-bn_BD.l10n.php').read_text()
paths = [
    *list((root / 'plugin/sfm4u-wholesale/src').glob('*.php')),
    *list((root / 'modules/b2b-pro/src').glob('*Page.php')),
    root / 'modules/b2b-pro/src/VendorDashboard.php',
    root / 'modules/b2b-pro/src/Dashboard.php',
    *list((root / 'modules/inventory-bridge/src').glob('*Page.php')),
    root / 'modules/inventory-bridge/src/DailyClose.php',
    root / 'modules/pos/src/Runtime.php',
    root / 'modules/pos/src/CounterPage.php',
    root / 'modules/pos/src/CounterSale.php',
    root / 'modules/pos/src/RegisterAssignments.php',
    root / 'modules/pos/src/RegisterPage.php',
    root / 'modules/pos/src/InvoiceProfilePage.php',
    root / 'modules/pos/src/InvoiceTemplate.php',
    root / 'modules/pos/src/RegisterSettingsPage.php',
    root / 'modules/inventory-bridge/src/NativeOrderInventory.php',
]
entries = []
for path in sorted(paths):
    source = path.read_text()
    keys = set(re.findall(r"(?:__|esc_html__|esc_attr__)\(\s*'((?:\\.|[^'\\])*)'\s*,\s*'sfm4u-wholesale'", source))
    missing = sorted(key for key in keys if "'" + key + "' =>" not in catalog)
    raw = sorted(set(text.strip() for text in re.findall(r'>\s*([A-Za-z][^<>\n]*)<', source)
        if "'" not in text and '$' not in text and '->' not in text and 'getMessage()' not in text and not re.search(r'\w+\(', text)))
    if keys or raw:
        entries.append({'screen': str(path.relative_to(root)), 'gettext_keys': len(keys),
            'missing_bangla_keys': missing, 'hardcoded_markup_candidates': raw})
print(json.dumps({'scope': 'static literal inventory; dynamic errors and browser layout require separate review',
    'screens': entries}, ensure_ascii=False, indent=2))

if any(row['missing_bangla_keys'] or row['hardcoded_markup_candidates'] for row in entries):
    raise SystemExit(1)

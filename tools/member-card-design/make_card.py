#!/usr/bin/env python3
"""SFM4U member card design (front + back PNG) from one JSON file.

Skill 228. The card shows only what the SFM4U account system holds: name (EN/BN), member number,
level, title, services, contact the member chose to print. It never prints NID/BRN, passport,
date of birth or address. The JSON never holds an ID number.

Usage:  python3 make_card.py card.json OUT_DIR
Needs:  Pillow, Node + Playwright (Chromium), PHP CLI (barcode from the plugin), npm (Bangla font).
"""
import base64, io, json, os, subprocess, sys, html as H
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..'))
BARCODE_PHP = os.path.join(REPO, 'wh', 'sfm4u-wholesale', 'src', 'Members', 'Barcode.php')
FONT_PKG = '@fontsource/noto-sans-bengali@5.1.0'
SERVICES = {'algibazar': 'AlgiBazar', 'goldenclub': 'Golden Club', 'aiagent': 'SFM4U AI Agent', 'realestate': 'SFM4U Real Estate'}
BN_DIGITS = str.maketrans('0123456789', '০১২৩৪৫৬৭৮৯')


def e(s):
    return H.escape(str(s or ''))


def uri(im, jpeg=False):
    b = io.BytesIO()
    (im.convert('RGB').save(b, 'JPEG', quality=88) if jpeg else im.save(b, 'PNG', optimize=True))
    return ('data:image/jpeg;base64,' if jpeg else 'data:image/png;base64,') + base64.b64encode(b.getvalue()).decode()


def portrait(path, crop):
    im = Image.open(path)
    if crop:
        im = im.crop(tuple(crop))
    else:   # centre crop to 3:4
        w, h = im.size
        tw = min(w, int(h * 3 / 4)); th = int(tw * 4 / 3)
        im = im.crop(((w - tw) // 2, 0, (w - tw) // 2 + tw, th))
    return uri(im.resize((360, 480), Image.LANCZOS), jpeg=True)


def logo(path):
    im = Image.open(path); im.thumbnail((170, 170), Image.LANCZOS)
    return uri(im)


def fonts(work):
    d = os.path.join(work, 'fonts')
    f = os.path.join(d, 'package', 'files', 'noto-sans-bengali-bengali-{}-normal.woff2')
    if not os.path.exists(f.format(400)):
        os.makedirs(d, exist_ok=True)
        tgz = subprocess.check_output(['npm', 'pack', FONT_PKG, '--silent'], cwd=d, text=True).strip().splitlines()[-1]
        subprocess.check_call(['tar', 'xzf', tgz], cwd=d)
    return {w: 'data:font/woff2;base64,' + base64.b64encode(open(f.format(w), 'rb').read()).decode() for w in (400, 700)}


def barcode(text):
    php = ('namespace SFM4U\\Wholesale\\Members; function esc_attr($s){return htmlspecialchars($s,ENT_QUOTES);} '
           'function esc_html($s){return htmlspecialchars($s);} require $argv[1]; echo Barcode::code128Svg($argv[2]);')
    return subprocess.check_output(['php', '-r', php, BARCODE_PHP, text], text=True).replace('#0b0f0e', '#111')


def flag(code):
    if code == 'BD':   # 10:6, disc centre at 45 % of the length, radius 20 %
        return '<svg viewBox="0 0 100 60" class="flag"><rect width="100" height="60" fill="#006a4e"/><circle cx="45" cy="30" r="20" fill="#f42a41"/></svg>'
    if code == 'US':   # 19:10, 13 stripes, canton 7 stripes x 0.4, 50 stars
        st = ''.join(f'<rect y="{i*10/13:.4f}" width="19" height="{10/13:.4f}" fill="#b22234"/>' for i in range(0, 13, 2))
        stars = ''.join(f'<circle cx="{0.633 + (0 if r % 2 == 0 else 0.633) + c*1.267:.3f}" cy="{0.538 + r*0.538:.3f}" r="0.22" fill="#fff"/>'
                        for r in range(9) for c in range(6 if r % 2 == 0 else 5))
        return f'<svg viewBox="0 0 19 10" class="flag"><rect width="19" height="10" fill="#fff"/>{st}<rect width="7.6" height="{70/13:.4f}" fill="#3c3b6e"/>{stars}</svg>'
    raise SystemExit('flag not drawn yet: ' + code)


FLAG_NAME = {'BD': 'বাংলাদেশ', 'US': 'USA'}


def build(c, work):
    no = str(c['member_no']).zfill(7)
    if not no.isdigit() or len(no) != 7:
        raise SystemExit('member_no must be the 7-digit number from the account system')
    for bad in ('nid', 'brn', 'passport', 'dob', 'address'):
        if c.get(bad):
            raise SystemExit(f'"{bad}" must not be in the card file (never printed, Skill 227/228)')
    f = fonts(work)
    title = c.get('title', '')
    logos = ''.join(f'<img class="{"r" if l.get("round") else ""}" src="{logo(l["path"])}">' for l in c.get('logos', []))
    flags = ''.join(f'<div>{flag(x)}<small>{FLAG_NAME.get(x, x)}</small></div>' for x in c.get('flags', []))
    svc = ''.join(f'<span>{e(SERVICES.get(s, s))}</span>' for s in c.get('services', []))
    phones = '<br>'.join(e(p) for p in c.get('phones', []))
    css = open(os.path.join(HERE, 'card.css'), encoding='utf-8').read().replace('FONT400', f[400]).replace('FONT700', f[700])
    return f'''<!doctype html><html><head><meta charset="utf-8"><style>{css}</style></head><body>
<div class="card" id="front">
 <div class="top"><div><div class="brand">SFM<span>4U</span></div><div class="tag">{e(c.get('card_label', 'MEMBER CARD'))}</div></div>
  <div class="lvl">MEMBER LEVEL<b>{e(c.get('level_en', 'MEMBER'))}</b>{e(c.get('countries_en', ''))}</div></div>
 <div class="photo"><img src="{portrait(c['photo'], c.get('photo_crop'))}"></div>
 <div class="info"><div class="name">{e(c['name_en'])}</div>
  {f'<div class="ceo">{e(title)}</div>' if title else ''}<div class="role">{e(c.get('role_en', ''))}</div>
  <div class="lab">MEMBER NUMBER</div><div class="no">{no}</div></div>
 <div class="site">sfm4u.com · accounts.sfm4u.com</div>
 <div class="logos">{logos}</div>
</div>
<div class="card back" id="back">
 <div class="bh"><div class="bt">{e(c.get('card_label_bn', 'SFM4U সদস্য কার্ড'))}</div><div class="flags">{flags}</div></div>
 <div class="bname">{e(c.get('name_bn', c['name_en']))}</div>
 <div class="brole">{e(c.get('role_bn', ''))}</div>
 <div class="rows">সদস্য নম্বর: {no.translate(BN_DIGITS)}<br>সদস্য স্তর: {e(c.get('level_bn', 'সদস্য'))}{f'<div class="ph">{phones}</div>' if phones else ''}</div>
 <div class="barbox">{barcode(no)}<div>{no}</div></div>
 <div class="svc">{svc}</div>
 <div class="note">এই কার্ড SFM4U-এর সব সেবায় একটি অ্যাকাউন্টের সদস্য কার্ড। <b>এটি সরকারি পরিচয়পত্র নয়।</b><br>
 This card is an SFM4U membership card, not a government ID. If found, please return to SFM4U · sfm4u.com</div>
</div></body></html>'''


def main():
    if len(sys.argv) != 3:
        raise SystemExit(__doc__)
    c = json.load(open(sys.argv[1], encoding='utf-8'))
    out = os.path.abspath(sys.argv[2]); os.makedirs(out, exist_ok=True)
    page = os.path.join(out, 'card.html')
    open(page, 'w', encoding='utf-8').write(build(c, out))
    base = os.path.join(out, 'SFM4U-card-' + str(c['member_no']).zfill(7))
    js = ("const {chromium}=require('playwright');(async()=>{const b=await chromium.launch();"
          "const p=await b.newPage({viewport:{width:1080,height:1400},deviceScaleFactor:2});"
          f"await p.goto('file://{page}');await p.evaluate(()=>document.fonts.ready);"
          f"await p.locator('#front').screenshot({{path:'{base}-front.png'}});"
          f"await p.locator('#back').screenshot({{path:'{base}-back.png'}});await b.close();}})();")
    subprocess.check_call(['node', '-e', js])
    print(base + '-front.png'); print(base + '-back.png')


if __name__ == '__main__':
    main()

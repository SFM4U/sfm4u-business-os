import {execFile} from 'node:child_process';
import {mkdtemp, readFile, rm, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join, resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {promisify} from 'node:util';

const directory = process.argv[2];
if (!directory) throw new Error('Specify the Playground evidence directory.');
const chrome = process.env.CHROME_BIN || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const temporary = await mkdtemp(join(tmpdir(), 'sfm4u-dashboard-'));
const escape = value => value.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;');
try {
    const pages = ['quotes', 'lists', 'statement'];
    const fixtures = await Promise.all(pages.map(page => readFile(resolve(directory, 'mysql-' + page + '.html'), 'utf8')));
    const frames = [320, 390].flatMap(width => fixtures.map((html, index) =>
        '<iframe title="' + pages[index] + '" width="' + width + '" height="950" srcdoc="' + escape(html) + '"></iframe>'));
    const test = join(temporary, 'dashboard.html');
    await writeFile(test, '<!doctype html><meta charset="utf-8">' + frames.join('') + `
<script>
window.addEventListener('load', () => {
    const log = document.createElement('pre');
    log.id = 'results';
    const checks = [];
    for (const frame of document.querySelectorAll('iframe')) {
        const doc = frame.contentDocument;
        const width = Number(frame.width);
        const root = doc.documentElement;
        const controls = [...doc.querySelectorAll('.sfm4u-b2b input:not([type=hidden]), .sfm4u-b2b textarea')];
        const buttons = [...doc.querySelectorAll('.sfm4u-b2b button')];
        const labeled = controls.every(input => input.labels?.length) && buttons.every(button => button.textContent.trim());
        const targets = buttons.every(button => button.getBoundingClientRect().height >= 44);
        const fit = root.clientWidth <= width && root.scrollWidth <= root.clientWidth;
        const control = buttons[0] || doc.querySelector('a[href]');
        control?.focus();
        const accessibleFocus = !control || (doc.activeElement === control && doc.defaultView.getComputedStyle(control).outlineStyle !== 'none');
        checks.push([fit && labeled && targets && accessibleFocus, frame.title + ' at ' + width + 'px']);
    }
    log.textContent = checks.map(([ok, label]) => (ok ? 'PASS ' : 'FAIL ') + label).join('\\n');
    document.body.append(log);
});
</script>`, 'utf8');
    const {stdout} = await promisify(execFile)(chrome, [
        '--headless', '--disable-background-networking', '--user-data-dir=' + join(temporary, 'profile'),
        '--dump-dom', pathToFileURL(test).href,
    ], {maxBuffer: 2 * 1024 * 1024, timeout: 30000});
    const results = stdout.match(/<pre id="results">([^<]+)<\/pre>/)?.[1];
    if (!results || results.split('\n').length !== 6 || results.includes('FAIL')) {
        throw new Error(results || 'Chrome did not return the dashboard checks.');
    }
    console.log(results);
} finally {
    await rm(temporary, {recursive: true, force: true});
}

// Layout check (web design system section 7) and screenshots through
// Edge's DevTools protocol: no sideways scrolling, nothing but <main> (or a
// dialog) with its own scrollbar, no button under 32px. Needs Edge and the
// app running (php artisan serve).
// usage: node scripts/layout-check.mjs <out-dir> <width>x<height> <name>=<url> [...]
// Optional per page: <name>=<url>@@<js to run before the shot>
import { spawn } from 'node:child_process';
import { writeFileSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [outDir, size, ...pages] = process.argv.slice(2);
const [width, height] = size.split('x').map(Number);
mkdirSync(outDir, { recursive: true });

const edge = spawn('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', [
    '--headless=new', '--disable-gpu', '--hide-scrollbars', '--remote-debugging-port=9333',
    `--user-data-dir=${join(tmpdir(), 'elikas-layout-check-edge')}`, 'about:blank',
], { stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let target;
for (let i = 0; i < 120 && !target; i++) {
    await sleep(250);
    try {
        const list = await (await fetch('http://127.0.0.1:9333/json/list')).json();
        target = list.find((t) => t.type === 'page');
    } catch {}
}
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((r) => ws.addEventListener('open', r));
let id = 0;
const waiting = new Map();
ws.addEventListener('message', (m) => {
    const msg = JSON.parse(m.data);
    if (msg.id && waiting.has(msg.id)) { waiting.get(msg.id)(msg.result ?? msg.error); waiting.delete(msg.id); }
});
const send = (method, params = {}) => new Promise((r) => { const n = ++id; waiting.set(n, r); ws.send(JSON.stringify({ id: n, method, params })); });
const evaluate = async (expression) => (await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })).result?.value;

await send('Page.enable');
await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });

const CHECK = `(() => {
    const out = [];
    if (!document.querySelector('main, .login-card')) return ['NOT THE APP (server down?): ' + document.title];
    const de = document.documentElement;
    if (de.scrollWidth > innerWidth + 1) out.push('page scrolls sideways (' + de.scrollWidth + ' > ' + innerWidth + ')');
    const main = document.querySelector('main');
    for (const el of document.querySelectorAll('body *')) {
        const r = el.getBoundingClientRect();
        if (!r.width || getComputedStyle(el).visibility === 'hidden') continue;
        const clipped = (() => { for (let p = el.parentElement; p; p = p.parentElement) { const ps = getComputedStyle(p); if (/(hidden|clip)/.test(ps.overflowX) && p.getBoundingClientRect().right <= innerWidth + 1) return true; } return false; })();
        if (r.right > innerWidth + 1 && !clipped && !el.closest('[hidden]')) out.push('wider than window: ' + el.tagName.toLowerCase() + '.' + [...el.classList].slice(0, 3).join('.'));
        const s = getComputedStyle(el);
        const scrolls = /(auto|scroll)/.test(s.overflowY) && el.scrollHeight > el.clientHeight + 1;
        if (scrolls && el !== main && !el.closest('.modal, [role=dialog], [role=alertdialog]')) out.push('own scrollbar: ' + el.tagName.toLowerCase() + '.' + [...el.classList].slice(0, 3).join('.'));
        if (el.matches('button, a.btn, .btn') && r.height && r.height < 32) out.push('small target (' + Math.round(r.height) + 'px): ' + (el.textContent || '').trim().slice(0, 30));
    }
    return [...new Set(out)];
})()`;

for (const page of pages) {
    const [name, rest] = page.split(/=(.+)/);
    const [url, before] = rest.split('@@');
    await send('Page.navigate', { url });
    // Wait for the page and its stylesheet to finish loading (max 20 s).
    for (let i = 0; i < 40 && (await evaluate('document.readyState')) !== 'complete'; i++) await sleep(500);
    await sleep(1200);
    if (before) { await evaluate(before); await sleep(700); }
    const problems = await evaluate(CHECK);
    const shot = await send('Page.captureScreenshot', { format: 'png' });
    writeFileSync(`${outDir}/${name}-${width}x${height}.png`, Buffer.from(shot.data, 'base64'));
    console.log(`${name} ${width}x${height}: ${problems?.length ? problems.join(' | ') : 'ok'}`);
}
ws.close();
edge.kill();
process.exit(0);

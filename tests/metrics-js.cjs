const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
    constructor(tag) { this.tag = tag; this.children = []; this.dataset = {}; this.attributes = {}; this.style = {setProperty() {}}; this.isConnected = true; }
    appendChild(child) { this.children.push(child); child.parent = this; return child; }
    removeChild(child) { this.children.splice(this.children.indexOf(child), 1); }
    after(child) { this.parent.appendChild(child); }
    setAttribute(name, value) { this.attributes[name] = value; }
    addEventListener(name, callback) { this[name] = callback; }
    get firstChild() { return this.children[0]; }
    get childElementCount() { return this.children.length; }
    querySelector(selector) {
        for (const child of this.children) {
            if (selector.startsWith('.') ? child.className === selector.slice(1) : child.tag === selector) return child;
            const nested = child.querySelector(selector); if (nested) return nested;
        }
    }
}
async function run(contentType) {
    const section = new Element('section'); section.dataset.metricsUrl = '/kubernetes/resources/metrics?id=example';
    for (const [tag, cls] of [['h2', ''], ['p', 'metrics-status'], ['div', 'metric-charts-row']]) { const e = new Element(tag); e.className = cls; section.appendChild(e); }
    let ready, request;
    const document = {documentElement: {}, addEventListener: (_, cb) => ready = cb, querySelector: () => null,
        querySelectorAll: () => [section], createElement: tag => new Element(tag), createElementNS: (_, tag) => new Element(tag)};
    const payload = {freshness: 'live', metrics: [
        {key: 'cpu', label: 'CPU', unit: 'cores', data: {data: {result: [{metric: {container: 'db'}, values: [[100, '1'], [200, '2'], [1000, '3']]}]}}},
        {key: 'absent', label: 'Unavailable metric', unit: 'count', error: 'metric unavailable'}
    ]};
    vm.runInNewContext(fs.readFileSync(require.resolve('../public/js/module.js'), 'utf8'), {
        document, window: {location: {href: 'https://example.test/kubernetes/resources/show?id=example'}},
        MutationObserver: class { observe() {} }, AbortController, URL,
        setInterval: () => 1, clearInterval() {}, setTimeout: () => 1, clearTimeout() {},
        fetch: async (url, options) => { request = {url, options}; return {ok: true, redirected: false, headers: {get: () => contentType}, json: async () => payload}; }
    });
    ready(); await new Promise(resolve => setImmediate(resolve));
    return {section, request};
}
(async () => {
    const {section, request} = await run('application/json');
    assert.equal(request.options.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.equal(new URL(request.url).searchParams.get('range'), '3600');
    const row = section.querySelector('.metric-charts-row'); assert.equal(row.childElementCount, 1);
    const svg = row.children[0].children.find(e => e.tag === 'svg');
    assert.equal(svg.attributes.viewBox, '0 0 600 190');
    assert.equal(svg.children.filter(e => e.tag === 'text').length, 4, 'time and value axes');
    assert.match(section.querySelector('.metrics-missing').textContent, /Unavailable metric/);
    const html = await run('text/html');
    assert.match(html.section.querySelector('.metrics-status').textContent, /web page/);
    assert.equal(html.section.querySelector('.metric-charts-row').childElementCount, 0);
    console.log('metrics browser behavior tests: ok');
})().catch(error => { console.error(error); process.exitCode = 1; });

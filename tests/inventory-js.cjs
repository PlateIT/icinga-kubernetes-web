const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
    constructor(tag) { this.tag = tag; this.children = []; this.dataset = {}; this.attributes = {}; this.classes = new Set(); this.classList = {toggle: (key, on) => on ? this.classes.add(key) : this.classes.delete(key)}; }
    setAttribute(key, value) { this.attributes[key] = value; if (key === 'href') this.href = new URL(value, 'https://example.test').href; }
    removeAttribute(key) { delete this.attributes[key]; }
    querySelector() { return this.children[0]; }
    appendChild(child) { this.children.push(child); child.parentElement = this; return child; }
    querySelectorAll(selector) { return this.children.filter(child => selector === 'a' ? child.tag === 'a' : child.dataset.kubeKind); }
    remove() { this.parentElement.children.splice(this.parentElement.children.indexOf(this), 1); }
    set textContent(value) { this.text = value; this.children = []; }
    get textContent() { return this.text; }
}
(async () => {
    let ready, observer, request, calls = 0;
    const list = new Element('ul'), root = list.appendChild(new Element('li')), browser = new Element('nav');
    const base = new Element('a'); base.href = 'https://example.test/icingaweb2/kubernetes/resources'; base.closest = () => root;
    const context = {dataset: {kind: 'Pod', group: 'core', version: 'v1'}};
    const document = {documentElement: {}, addEventListener: (_, cb) => ready = cb,
        querySelector: selector => selector.startsWith('#sidebar') ? base : selector.includes('data-kube-context') ? context : null,
        querySelectorAll: selector => selector === '.kube-kind-browser' ? [browser] : [],
        createElement: tag => new Element(tag)};
    vm.runInNewContext(fs.readFileSync(require.resolve('../public/js/module.js'), 'utf8'), {
        document, window: {location: {href: base.href}}, URL, AbortController,
        MutationObserver: class { constructor(cb) { observer = cb; } observe() {} },
        setInterval() { return 1; }, clearInterval() {}, setTimeout() { return 1; },
        fetch: async (url, options) => { calls++; request = {url, options}; return {ok: true, json: async () => ({items: [
            {group: 'core', version: 'v1', kind: 'Pod', count: 41},
            {group: 'example.test', version: 'v1', kind: 'CustomThing', count: null}
        ]})}; }
    });
    ready(); await new Promise(resolve => setImmediate(resolve)); observer();
    assert.equal(calls, 1, 'DOM changes must not repeatedly fetch inventory');
    assert.equal(request.url, base.href + '/types');
    assert.equal(list.children.length, 3, 'permanent Resources entry plus only present kinds');
    assert.equal(browser.children.length, 2);
    assert.equal(browser.children[0].textContent, 'Pods (41)');
    assert.equal(browser.children[1].textContent, 'CustomThing');
    assert.equal(new URL(browser.children[1].href).searchParams.get('group'), 'example.test');
    for (const link of [...browser.children, ...list.children.slice(1).map(li => li.children[0])]) {
        assert.match(link.attributes.href, /^\/icingaweb2\/kubernetes\/resources\?/);
        assert.doesNotMatch(link.attributes.href, /^(?:(?:mailto|javascript|data):|[a-z]+:\/\/)/, 'Internal links must enter the Icinga AJAX loader');
    }
    assert.equal(root.classes.has('active'), false);
    assert.equal(list.children[1].classes.has('active'), true, 'Pod view selects Pods');
    context.dataset = {kind: 'CustomThing', group: 'example.test', version: 'v1'}; observer();
    assert.equal(list.children[1].classes.has('active'), false);
    assert.equal(list.children[2].classes.has('active'), true, 'SPA navigation updates selection without refetch');
    context.dataset = {}; observer();
    assert.equal(root.classes.has('active'), true, 'Unfiltered view selects Resources');
    assert.equal(list.children[2].classes.has('active'), false);
    console.log('inventory navigation behavior tests: ok');
})().catch(error => { console.error(error); process.exitCode = 1; });

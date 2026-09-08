(function () {
    'use strict';

    let source;
    let sourceMarker;
    let refreshTimer;
    let refreshRequest;
    let lastRefresh = 0;
    let pollTimer;
    let inventoryItems;
    let inventoryFetched = 0;
    let inventoryPending = false;
    function connectInventory() {
        const base = document.querySelector('#sidebar a[href$="/kubernetes/resources"]');
        if (! base) return;
        const list = base.closest('li').parentElement;
        function paint(items) {
            const signature = JSON.stringify(items);
            const context = document.querySelector('#col1 [data-kube-context]') || document.querySelector('[data-kube-context]');
            function matches(a) {
                if (! context) return false;
                const q = new URL(a.href, window.location.href).searchParams;
                if (! context.dataset.kind) return ! q.get('kind');
                return (q.get('kind') || '') === (context.dataset.kind || '')
                    && (! context.dataset.group || (q.get('group') || 'core') === context.dataset.group)
                    && (! context.dataset.version || q.get('version') === context.dataset.version);
            }
            function mark(a, target) {
                const active = matches(a);
                target.classList.toggle('active', active);
                target.classList.toggle('selected', active);
                if (active) a.setAttribute('aria-current', 'page');
                else a.removeAttribute('aria-current');
            }
            const labels = {Pod: 'Pods', StatefulSet: 'Stateful Sets', Deployment: 'Deployments', ReplicaSet: 'Replica Sets',
                DaemonSet: 'Daemon Sets', ConfigMap: 'Config Maps', PersistentVolumeClaim: 'Persistent Volume Claims',
                PersistentVolume: 'Persistent Volumes', CronJob: 'Cron Jobs', Service: 'Services', Secret: 'Secrets',
                Namespace: 'Namespaces', Node: 'Nodes', Job: 'Jobs', Event: 'Events', Ingress: 'Ingresses', Route: 'Routes'};
            function link(item) {
                const a = document.createElement('a');
                const url = new URL(base.href, window.location.href);
                ['group', 'version', 'kind'].forEach(key => url.searchParams.set(key, item[key] || (key === 'group' ? 'core' : '')));
                // Icinga deliberately lets absolute URLs bypass its AJAX loader,
                // even for the same origin. Keep internal links root-relative.
                a.setAttribute('href', url.pathname + url.search);
                a.textContent = (labels[item.kind] || item.kind) + (item.count === null ? '' : ' (' + item.count + ')');
                a.title = [item.group || 'core', item.version, item.kind].join('/');
                return a;
            }
            if (list.dataset.kubeInventory !== signature || list.querySelectorAll('[data-kube-kind]').length !== items.length) {
                list.querySelectorAll('[data-kube-kind]').forEach(node => node.remove());
                items.forEach(item => { const li = document.createElement('li'); li.className = 'nav-item'; li.dataset.kubeKind = item.kind; li.appendChild(link(item)); list.appendChild(li); });
                list.dataset.kubeInventory = signature;
            }
            mark(base, base.closest('li'));
            list.querySelectorAll('[data-kube-kind]').forEach(li => mark(li.querySelector('a'), li));
            // The core navigation stores a DOM path and restores it on refresh.
            // Register the dynamically selected entry there too, not only in CSS.
            const selected = list.querySelector('a[aria-current="page"]');
            if (selected && window.icinga && window.icinga.behaviors.navigation) {
                const navigation = window.icinga.behaviors.navigation;
                const path = window.icinga.utils.getDomPath(selected.closest('li'));
                if (JSON.stringify(navigation.active) !== JSON.stringify(path)
                    || ! list.closest('li').classList.contains('active')) {
                    navigation.setActiveAndSelected(window.jQuery(selected));
                }
            }
            document.querySelectorAll('.kube-kind-browser').forEach(browser => {
                if (browser.dataset.inventory !== signature) {
                    browser.textContent = '';
                    items.forEach(item => browser.appendChild(link(item)));
                    browser.dataset.inventory = signature;
                }
                browser.querySelectorAll('a').forEach(a => mark(a, a));
            });
        }
        if (inventoryItems) paint(inventoryItems);
        if (inventoryPending || Date.now() - inventoryFetched < 60000) return;
        inventoryPending = true;
        inventoryFetched = Date.now();
        fetch(base.href.replace(/\/$/, '') + '/types', {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'})
            .then(response => { if (! response.ok) throw new Error('Inventory unavailable'); return response.json(); })
            .then(payload => { if (! Array.isArray(payload.items)) throw new Error('Invalid inventory'); inventoryItems = payload.items; paint(inventoryItems); })
            .catch(() => { /* Keep the last inventory and the permanent Resources entry available. */ })
            .finally(() => { inventoryPending = false; });
    }
    const chartColors = ['#0095bf', '#44bb77', '#ff9900', '#cc4455', '#7755cc', '#00a6a6', '#aa3377', '#777777'];

    function refreshResources() {
        const marker = document.querySelector('.icinga-kubernetes-live[data-stream-url]');
        if (! marker) return;
        if (refreshRequest) refreshRequest.abort();
        refreshRequest = new AbortController();
        fetch(window.location.href, {
            headers: {'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest'},
            credentials: 'same-origin',
            signal: refreshRequest.signal
        })
            .then(function (response) {
                if (! response.ok) throw new Error('HTTP ' + response.status);
                return response.text();
            })
            .then(function (html) {
                const parsed = new DOMParser().parseFromString(html, 'text/html');
                const updated = parsed.querySelector('.icinga-kubernetes-live[data-stream-url]');
                if (! updated || ! marker.isConnected) throw new Error('Invalid resource update');
                marker.innerHTML = updated.innerHTML;
                marker.dataset.streamUrl = updated.dataset.streamUrl;
                lastRefresh = Date.now();
            })
            .catch(function (error) {
                if (error.name !== 'AbortError' && ! pollTimer) {
                    pollTimer = setInterval(scheduleRefresh, 30000);
                }
            })
            .finally(function () { refreshRequest = undefined; });
    }

    function scheduleRefresh() {
        if (refreshTimer) return;
        const delay = Math.max(1000, 10000 - (Date.now() - lastRefresh));
        refreshTimer = setTimeout(function () {
            refreshTimer = undefined;
            refreshResources();
        }, delay);
    }

    function connect() {
        const marker = document.querySelector('.icinga-kubernetes-live[data-stream-url]');
        if (source && (! sourceMarker || ! sourceMarker.isConnected)) {
            source.close();
            source = undefined;
            sourceMarker = undefined;
        }
        if (! marker) {
            clearInterval(pollTimer);
            pollTimer = undefined;
            if (refreshRequest) refreshRequest.abort();
            return;
        }
        if (! marker.dataset.streamUrl) {
            if (! pollTimer) pollTimer = setInterval(scheduleRefresh, 30000);
            return;
        }
        if (source) return;
        sourceMarker = marker;
        source = new EventSource(marker.dataset.streamUrl);
        source.addEventListener('resource', scheduleRefresh);
        source.onopen = function () {
            clearInterval(pollTimer);
            pollTimer = undefined;
        };
        source.onerror = function () {
            if (! pollTimer) pollTimer = setInterval(scheduleRefresh, 30000);
        };
    }

    function sample(points, limit) {
        if (points.length <= limit) return points;
        const sampled = [points[0]];
        const buckets = Math.max(1, Math.floor((limit - 2) / 2));
        const width = (points.length - 2) / buckets;
        for (let bucket = 0; bucket < buckets; bucket += 1) {
            const start = 1 + Math.floor(bucket * width);
            const end = Math.min(points.length - 1, 1 + Math.floor((bucket + 1) * width));
            const slice = points.slice(start, Math.max(start + 1, end));
            let low = slice[0];
            let high = slice[0];
            slice.forEach(function (point) {
                if (Number(point[1]) < Number(low[1])) low = point;
                if (Number(point[1]) > Number(high[1])) high = point;
            });
            if (Number(low[0]) <= Number(high[0])) sampled.push(low, high);
            else sampled.push(high, low);
        }
        sampled.push(points[points.length - 1]);
        return sampled;
    }

    function series(payload) {
        const results = payload && payload.data && Array.isArray(payload.data.result)
            ? payload.data.result.slice(0, 12)
            : [];
        return results.map(function (result, index) {
            const raw = Array.isArray(result.values) ? result.values : (result.value ? [result.value] : []);
            const points = sample(raw.filter(function (point) {
                return Array.isArray(point) && point.length > 1 && Number.isFinite(Number(point[1]));
            }), 600);
            const labels = result.metric || {};
            const preferred = ['pod', 'container', 'node', 'instance', 'service', 'endpoint', 'name'];
            let label = '';
            preferred.some(function (key) {
                if (labels[key]) {
                    label = key + '=' + labels[key];
                    return true;
                }
                return false;
            });
            return {points: points, label: label || ('series ' + (index + 1))};
        }).filter(function (item) { return item.points.length > 0; });
    }

    function draw(svg, allSeries, unit) {
        while (svg.firstChild) svg.removeChild(svg.firstChild);
        if (! allSeries.length) return;
        const numeric = [].concat.apply([], allSeries.map(function (item) {
            return item.points.map(function (point) { return Number(point[1]); });
        }));
        let min = Math.min.apply(null, numeric);
        let max = Math.max.apply(null, numeric);
        if (min === max) {
            min -= Math.abs(min || 1) * 0.05;
            max += Math.abs(max || 1) * 0.05;
        }
        const span = Math.max(max - min, 1e-12);
        const times = [].concat.apply([], allSeries.map(function (item) { return item.points.map(function (p) { return Number(p[0]); }); }));
        const start = Math.min.apply(null, times), end = Math.max.apply(null, times);
        function axis(x, y, text, anchor) {
            const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            label.setAttribute('x', x); label.setAttribute('y', y); label.setAttribute('fill', 'currentColor');
            label.setAttribute('font-size', '12'); label.setAttribute('text-anchor', anchor || 'start');
            label.textContent = text; svg.appendChild(label);
        }
        axis(0, 12, formatted(max, unit)); axis(0, 155, formatted(min, unit));
        axis(75, 178, new Date(start * 1000).toLocaleTimeString());
        axis(595, 178, new Date(end * 1000).toLocaleTimeString(), 'end');
        allSeries.forEach(function (item, seriesIndex) {
            const path = item.points.map(function (point, index) {
                const x = 75 + (Number(point[0]) - start) / Math.max(end - start, 1) * 520;
                const y = 150 - ((Number(point[1]) - min) / span * 140);
                return (index ? 'L' : 'M') + x.toFixed(1) + ',' + y.toFixed(1);
            }).join(' ');
            const line = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            line.setAttribute('d', path);
            line.setAttribute('fill', 'none');
            line.setAttribute('stroke', chartColors[seriesIndex % chartColors.length]);
            line.setAttribute('stroke-width', '2');
            line.setAttribute('vector-effect', 'non-scaling-stroke');
            const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
            title.textContent = item.label;
            line.appendChild(title);
            svg.appendChild(line);
        });
    }

    function formatted(value, unit) {
        if (! Number.isFinite(value)) return 'n/a';
        if (unit === 'bytes' || unit === 'bytes_per_second') {
            const suffix = unit === 'bytes_per_second' ? '/s' : '';
            const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
            let index = 0;
            while (Math.abs(value) >= 1024 && index < units.length - 1) {
                value /= 1024;
                index += 1;
            }
            return value.toFixed(index ? 1 : 0) + ' ' + units[index] + suffix;
        }
        if (unit === 'ratio') return (value * 100).toFixed(1) + ' %';
        if (unit === 'cores') return value.toFixed(3) + ' cores';
        return value.toFixed(2).replace(/\.00$/, '') + (unit === 'count' || unit === 'boolean' ? '' : ' ' + unit);
    }

    function addLegend(figure, metricSeries, unit) {
        if (metricSeries.length < 2) return;
        const legend = document.createElement('ul');
        legend.className = 'metric-series-legend';
        metricSeries.slice(0, 8).forEach(function (item, index) {
            const entry = document.createElement('li');
            entry.style.setProperty('--metric-color', chartColors[index % chartColors.length]);
            const latest = Number(item.points[item.points.length - 1][1]);
            entry.textContent = item.label + ': ' + formatted(latest, unit);
            legend.appendChild(entry);
        });
        figure.appendChild(legend);
    }

    function refreshMetrics(section) {
        if (section.metricsRequest) section.metricsRequest.abort();
        section.metricsRequest = new AbortController();
        const url = new URL(section.dataset.metricsUrl, window.location.href);
        url.searchParams.set('range', section.querySelector('.metrics-range').value);
        fetch(url.toString(), {
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            credentials: 'same-origin',
            signal: section.metricsRequest.signal
        })
            .then(function (response) {
                if (! response.ok) throw new Error('HTTP ' + response.status);
                if (response.redirected || !(response.headers.get('Content-Type') || '').includes('application/json')) {
                    throw new Error('Metrics request returned a web page. Reload the page and check your session.');
                }
                return response.json();
            })
            .then(function (payload) {
                const row = section.querySelector('.metric-charts-row');
                while (row.firstChild) row.removeChild(row.firstChild);
                const missing = [];
                (payload.metrics || []).forEach(function (metric) {
                    const metricSeries = metric.error ? [] : series(metric.data);
                    if (! metricSeries.length) { missing.push(metric.label); return; }
                    const figure = document.createElement('figure');
                    figure.dataset.metric = metric.key;
                    const caption = document.createElement('figcaption');
                    const current = Number(metricSeries[0].points[metricSeries[0].points.length - 1][1]);
                    caption.textContent = metric.label + ': ' + formatted(current, metric.unit)
                        + (metricSeries.length > 1 ? ' (' + metricSeries.length + ' series)' : '');
                    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                    svg.setAttribute('viewBox', '0 0 600 190');
                    svg.setAttribute('role', 'img');
                    svg.setAttribute('aria-label', metric.label);
                    figure.appendChild(caption);
                    figure.appendChild(svg);
                    addLegend(figure, metricSeries, metric.unit);
                    row.appendChild(figure);
                    draw(svg, metricSeries, metric.unit);
                });
                section.querySelector('.metrics-status').textContent = row.childElementCount
                    ? row.childElementCount + ' metrics · ' + (payload.freshness || 'live')
                    : 'No matching live metrics are currently available';
                section.querySelector('.metrics-missing').textContent = missing.length ? 'No data for this time range: ' + missing.join(', ') : '';
            })
            .catch(function (error) {
                if (error.name !== 'AbortError') {
                    section.querySelector('.metrics-status').textContent = error.message;
                }
            });
    }

    function connectMetrics() {
        document.querySelectorAll('.icinga-kubernetes-metrics[data-metrics-url]').forEach(function (section) {
            if (section.dataset.connected) return;
            section.dataset.connected = 'yes';
            const controls = document.createElement('label');
            controls.textContent = 'Time range ';
            const range = document.createElement('select'); range.className = 'metrics-range';
            [['900', '15 minutes'], ['3600', '1 hour'], ['10800', '3 hours'], ['21600', '6 hours']].forEach(function (entry) {
                const option = document.createElement('option'); option.value = entry[0]; option.textContent = entry[1]; range.appendChild(option);
            });
            range.value = '3600'; range.addEventListener('change', function () { refreshMetrics(section); });
            controls.appendChild(range); section.querySelector('h2').after(controls);
            const missing = document.createElement('p'); missing.className = 'metrics-missing'; section.appendChild(missing);
            refreshMetrics(section);
            section.metricsTimer = setInterval(function () {
                if (! section.isConnected) {
                    clearInterval(section.metricsTimer);
                    if (section.metricsRequest) section.metricsRequest.abort();
                    return;
                }
                refreshMetrics(section);
            }, 30000);
        });
    }

    document.addEventListener('DOMContentLoaded', function () { connect(); connectMetrics(); connectInventory(); });
    setInterval(connectInventory, 60000);
    new MutationObserver(function () { connect(); connectMetrics(); connectInventory(); })
        .observe(document.documentElement, {childList: true, subtree: true});
}());

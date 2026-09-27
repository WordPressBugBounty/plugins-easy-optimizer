/*! Easy Optimizer — LCP beacon (1.6.2). Uses PerformanceObserver to capture
    the largest-contentful-paint entry and reports it via sendBeacon when the
    page becomes hidden, on pagehide, or after a 10s settle timeout.

    1.6.2 changes:
      • Skip elements that are visually hidden (opacity:0 / visibility:hidden
        / display:none / zero rendered dimensions).
      • Parse image-set(url(a) 1x, url(b) 2x) from computed background-image
        and emit all candidate URLs.
      • Mobile/desktop bucket frozen at observer-setup time.
      • 10s timeout fallback so visitors who never close the tab still report.
      • Tag the element kind (img | video-poster | background) so the server
        can pick the right preload strategy.

    Runs once per page. */
(function () {
    if (typeof easyoptLcp === 'undefined') { return; }
    if (!('PerformanceObserver' in window)) { return; }

    var BUCKET = (window.innerWidth < (easyoptLcp.mobileBreak || 768)) ? 'mobile' : 'desktop';

    var lastEntry = null;
    var observer;

    try {
        observer = new PerformanceObserver(function (list) {
            var entries = list.getEntries();
            for (var i = 0; i < entries.length; i++) {
                lastEntry = entries[i];
            }
        });
        observer.observe({ type: 'largest-contentful-paint', buffered: true });
    } catch (e) { return; }

    var reported = false;

    function isElementVisible(el) {
        if (!el || el.nodeType !== 1) { return false; }
        if (el.offsetWidth === 0 || el.offsetHeight === 0) { return false; }
        var cs = window.getComputedStyle ? window.getComputedStyle(el) : null;
        if (!cs) { return true; }
        if (cs.display === 'none') { return false; }
        if (cs.visibility === 'hidden' || cs.visibility === 'collapse') { return false; }
        var op = parseFloat(cs.opacity || '1');
        if (!isNaN(op) && op < 0.1) { return false; }
        return true;
    }

    function buildSelector(el) {
        if (!el || !el.nodeType) { return ''; }
        var parts = [], cur = el, depth = 0;
        while (cur && cur.nodeType === 1 && depth < 6) {
            var tag = cur.nodeName.toLowerCase();
            if (cur.id) { parts.unshift(tag + '#' + cur.id); break; }
            var cls = (typeof cur.className === 'string') ? cur.className.trim() : '';
            if (cls) {
                var first = cls.split(/\s+/).slice(0, 2).join('.');
                tag += '.' + first;
            }
            parts.unshift(tag);
            cur = cur.parentElement;
            depth++;
        }
        return parts.join(' > ');
    }

    function extractBackgroundUrls(cssValue) {
        if (!cssValue || cssValue === 'none') { return []; }
        var urls = [], seen = {};
        var re = /url\((?:"([^"]+)"|'([^']+)'|([^)]+))\)/g;
        var m;
        while ((m = re.exec(cssValue)) !== null) {
            var u = (m[1] || m[2] || m[3] || '').trim();
            if (u && !seen[u]) { seen[u] = true; urls.push(u); }
        }
        return urls;
    }

    function describeElement(el) {
        if (!el) { return { kind: '', url: '', urls: [] }; }
        var tag = (el.tagName || '').toLowerCase();
        if (tag === 'img') {
            var src = el.currentSrc || el.src || '';
            return { kind: 'img', url: src, urls: src ? [src] : [] };
        }
        if (tag === 'video' && el.poster) {
            return { kind: 'video-poster', url: el.poster, urls: [el.poster] };
        }
        if (window.getComputedStyle) {
            var bg = window.getComputedStyle(el).backgroundImage || '';
            var bgUrls = extractBackgroundUrls(bg);
            if (bgUrls.length > 0) {
                return { kind: 'background', url: bgUrls[0], urls: bgUrls };
            }
        }
        return { kind: tag, url: '', urls: [] };
    }

    function report() {
        if (reported || !lastEntry) { return; }
        reported = true;
        try { observer.disconnect(); } catch (e) {}

        var el = lastEntry.element;
        if (!el || !isElementVisible(el)) { return; }

        var size = lastEntry.size || 0;
        if (size < (easyoptLcp.minLcpSize || 10000)) { return; }

        var desc = describeElement(el);
        var url  = desc.url || lastEntry.url || '';
        if (!url) { return; }

        var urls = desc.urls.length ? desc.urls : (lastEntry.url ? [lastEntry.url] : []);

        var params = new URLSearchParams();
        params.append('action',       'easyopt_report_lcp');
        params.append('nonce',        easyoptLcp.nonce);
        params.append('url_type',     easyoptLcp.urlType);
        params.append('viewport',     BUCKET);
        params.append('element_tag',  (el.tagName || '').toLowerCase());
        params.append('element_kind', desc.kind);
        params.append('image_url',    url);
        params.append('image_urls',   urls.join(','));
        params.append('srcset',       el.srcset || '');
        params.append('sizes',        el.sizes  || '');
        params.append('selector',     buildSelector(el));
        params.append('lcp_size',     String(Math.round(size)));

        if (navigator.sendBeacon) {
            try { navigator.sendBeacon(easyoptLcp.ajaxUrl, params); return; } catch (e) {}
        }
        try {
            fetch(easyoptLcp.ajaxUrl, { method: 'POST', body: params, keepalive: true, credentials: 'same-origin' });
        } catch (e) {}
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') { report(); }
    }, { once: true });
    window.addEventListener('pagehide', report, { once: true });
    setTimeout(report, 10000);
})();

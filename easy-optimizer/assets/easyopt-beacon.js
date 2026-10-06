/*!
 * Easy Optimizer — unified beacon (2.4.3).
 *
 * One lightweight script that reports up to three things in a single request:
 *   • LCP element (PerformanceObserver) — for per-URL image preloading
 *   • JS-generated class set            — for Used CSS tree-shake safety
 *   • Above-the-fold font URLs          — for font preloading
 *
 * The server tells us which parts are still cold via easyoptBeacon.need, so a
 * warm page collects nothing. Everything is sent ONCE, at end-of-life
 * (visibilitychange→hidden / pagehide) or after a short settle timer,
 * whichever comes first, via navigator.sendBeacon (fetch keepalive fallback).
 *
 * Build: this is the readable source; assets/easyopt-beacon.min.js is the
 * shipped, minified copy.
 */
(function () {
	if (typeof easyoptBeacon === 'undefined') {
		return;
	}

	var cfg  = easyoptBeacon;
	var rawNeed = cfg.need || {};
	// wp_localize_script may serialize nested values as strings; coerce so
	// "0" doesn't read as truthy.
	var need = {
		lcp:     +rawNeed.lcp     || 0,
		classes: +rawNeed.classes || 0,
		fonts:   +rawNeed.fonts   || 0
	};
	var viewport = window.innerWidth < (cfg.mobileBreak || 768) ? 'mobile' : 'desktop';

	var sent = false;
	var lcpEntry = null;
	var lcpObserver = null;
	var classSet = Object.create(null);
	var haveClasses = false;

	// (2.4.5) Does this browser support the largest-contentful-paint entry?
	// Older Safari/Firefox don't — we fall back to a rendered-area scan so
	// those audiences still get an LCP preload instead of nothing.
	function lcpApiSupported() {
		return ('PerformanceObserver' in window)
			&& !!PerformanceObserver.supportedEntryTypes
			&& PerformanceObserver.supportedEntryTypes.indexOf('largest-contentful-paint') !== -1;
	}
	var lcpApiOk = lcpApiSupported();

	/* ── LCP observation ─────────────────────────────────────────────── */
	if (need.lcp && lcpApiOk) {
		try {
			lcpObserver = new PerformanceObserver(function (list) {
				var entries = list.getEntries();
				for (var i = 0; i < entries.length; i++) {
					lcpEntry = entries[i];
				}
			});
			lcpObserver.observe({ type: 'largest-contentful-paint', buffered: true });
		} catch (e) { lcpApiOk = false; /* fall back to the rect scan in send() */ }
	}

	function isVisible(el) {
		if (!el || el.nodeType !== 1) { return false; }
		if (el.offsetWidth === 0 || el.offsetHeight === 0) { return false; }
		var cs = window.getComputedStyle ? window.getComputedStyle(el) : null;
		if (!cs) { return true; }
		if (cs.display === 'none') { return false; }
		if (cs.visibility === 'hidden' || cs.visibility === 'collapse') { return false; }
		var o = parseFloat(cs.opacity || '1');
		return !(!isNaN(o) && o < 0.1);
	}

	function bgUrls(value) {
		if (!value || value === 'none') { return []; }
		var out = [], seen = {}, re = /url\((?:"([^"]+)"|'([^']+)'|([^)]+))\)/g, m;
		while ((m = re.exec(value)) !== null) {
			var u = (m[1] || m[2] || m[3] || '').trim();
			if (u && !seen[u] && u.indexOf('data:') !== 0) { seen[u] = 1; out.push(u); }
		}
		return out;
	}

	// (2.4.5) Build an imagesrcset-style string ("url 1x, url 2x") from a CSS
	// image-set() background so the server can emit a responsive
	// <link rel=preload as=image imagesrcset=…> and the browser picks the
	// correct DPR candidate — the same win the responsive-<img> path gets.
	function bgImageSetSrcset(value) {
		var inner = String(value).match(/image-set\(([\s\S]*)\)/i);
		if (!inner) { return ''; }
		var parts = [], seen = {};
		var re = /url\((?:"([^"]+)"|'([^']+)'|([^)]+))\)\s*([0-9.]+(?:x|w|dppx)?)?/g, m;
		while ((m = re.exec(inner[1])) !== null) {
			var u = (m[1] || m[2] || m[3] || '').trim();
			if (!u || u.indexOf('data:') === 0 || seen[u]) { continue; }
			seen[u] = 1;
			var d = (m[4] || '').trim();
			if (d && /^[0-9.]+$/.test(d)) { d += 'x'; }   // bare number → x descriptor
			parts.push(d ? (u + ' ' + d) : u);
		}
		return parts.length ? parts.join(', ') : '';
	}

	// Resolve the LCP element into { kind, url, srcset, sizes }.
	function resolveLcp(el) {
		if (!el) { return null; }
		var tag = (el.tagName || '').toLowerCase();

		if (tag === 'img') {
			var url = el.currentSrc || el.src || '';
			if (!url) { return null; }
			var inPicture = el.parentNode && (el.parentNode.tagName || '').toLowerCase() === 'picture';
			return {
				tag: 'img',
				kind: inPicture ? 'picture' : (el.srcset ? 'img-srcset' : 'img'),
				url: url,
				srcset: el.srcset || '',
				sizes: el.sizes || ''
			};
		}
		if (tag === 'video' && el.poster) {
			return { tag: 'video', kind: 'video-poster', url: el.poster, srcset: '', sizes: '' };
		}
		// Background image (incl. image-set resolved by the browser).
		if (window.getComputedStyle) {
			var bgRaw = window.getComputedStyle(el).backgroundImage || '';
			var urls = bgUrls(bgRaw);
			if (urls.length) {
				// (2.4.5) image-set() → responsive background preload.
				if (/image-set\(/i.test(bgRaw)) {
					var ss = bgImageSetSrcset(bgRaw);
					if (ss) {
						return { tag: tag, kind: 'background-set', url: urls[0], srcset: ss, sizes: '' };
					}
				}
				return { tag: tag, kind: 'background', url: urls[0], srcset: '', sizes: '' };
			}
		}
		return { tag: tag, kind: tag, url: '', srcset: '', sizes: '' };
	}

	// (2.4.5) Fallback LCP detection for browsers without the LCP API: the
	// largest image painting inside the first viewport, by visible rendered
	// area. Cheap & bounded — only <img>, <video poster> and inline
	// background-image elements are examined.
	function findLcpFallback() {
		var vh = window.innerHeight || (document.documentElement && document.documentElement.clientHeight) || 0;
		var vw = window.innerWidth  || (document.documentElement && document.documentElement.clientWidth)  || 0;
		if (!vh || !vw) { return null; }
		var best = null, bestArea = 0, i;
		function consider(el) {
			if (!isVisible(el)) { return; }
			var rect;
			try { rect = el.getBoundingClientRect(); } catch (e) { return; }
			if (rect.top >= vh || rect.left >= vw || rect.bottom <= 0 || rect.right <= 0) { return; }
			var h = Math.min(rect.bottom, vh) - Math.max(rect.top, 0);
			var w = Math.min(rect.right, vw) - Math.max(rect.left, 0);
			var area = (h > 0 && w > 0) ? (h * w) : 0;
			if (area > bestArea) { bestArea = area; best = el; }
		}
		var imgs = document.getElementsByTagName('img');
		for (i = 0; i < imgs.length && i < 600; i++) { consider(imgs[i]); }
		var vids = document.querySelectorAll('video[poster]');
		for (i = 0; i < vids.length && i < 50; i++) { consider(vids[i]); }
		var bgs = document.querySelectorAll('[style*="background-image"]');
		for (i = 0; i < bgs.length && i < 200; i++) { consider(bgs[i]); }
		if (!best || bestArea < (cfg.minLcpSize || 10000)) { return null; }
		return { element: best, size: bestArea, url: '' };
	}

	function cssPath(el) {
		if (!el || !el.nodeType) { return ''; }
		var parts = [], node = el, depth = 0;
		while (node && node.nodeType === 1 && depth < 6) {
			var name = node.nodeName.toLowerCase();
			if (node.id) { parts.unshift(name + '#' + node.id); break; }
			var cls = typeof node.className === 'string' ? node.className.trim() : '';
			if (cls) { name += '.' + cls.split(/\s+/).slice(0, 2).join('.'); }
			parts.unshift(name);
			node = node.parentElement;
			depth++;
		}
		return parts.join(' > ');
	}

	/* ── Class snapshot ──────────────────────────────────────────────── */
	function snapshotClasses() {
		if (!need.classes) { return; }
		try {
			var els = document.querySelectorAll('[class]');
			for (var i = 0; i < els.length; i++) {
				var list = els[i].classList;
				if (!list) { continue; }
				for (var j = 0; j < list.length; j++) {
					classSet[list[j]] = 1;
				}
			}
			haveClasses = true;
		} catch (e) { /* ignore */ }
	}

	/* ── Above-the-fold font usage ───────────────────────────────────── */
	// Report which font FAMILIES + weights + styles actually render in the
	// first viewport, plus the code points each one draws. Deliberately NO
	// URLs: the server owns the @font-face rules (self-hosted AND the Google
	// CSS it fetches for Used CSS), so it resolves URL + subset itself. Reading
	// computed styles works regardless of when CSS/fonts load, so delayed CSS
	// no longer hides typography fonts the way resource-timing did.
	var GENERIC = { 'serif':1,'sans-serif':1,'monospace':1,'cursive':1,'fantasy':1,
		'system-ui':1,'ui-sans-serif':1,'ui-serif':1,'ui-monospace':1,'ui-rounded':1,
		'math':1,'emoji':1,'fangsong':1,'-apple-system':1,'blinkmacsystemfont':1,
		'inherit':1,'initial':1,'unset':1,'revert':1 };

	function primaryFamily(ff) {
		var first = (String(ff).split(',')[0] || '').trim().replace(/^['"]+|['"]+$/g, '');
		if (!first || GENERIC[first.toLowerCase()]) { return ''; }
		return first;
	}
	function normWeight(w) {
		w = String(w).toLowerCase();
		if (w === 'normal') { return '400'; }
		if (w === 'bold') { return '700'; }
		var n = parseInt(w, 10);
		return isNaN(n) ? '400' : String(n);
	}
	function directText(el) {
		var t = '';
		for (var n = el.firstChild; n; n = n.nextSibling) {
			if (n.nodeType === 3 && n.nodeValue) { t += n.nodeValue; }
		}
		return t;
	}
	// Memoised so the idle warm-up below is what send() reuses. A null result
	// is a legitimate answer ("no above-the-fold fonts") and is cached too,
	// otherwise every call would re-walk the DOM looking for nothing.
	var fontSigCache = null, fontSigDone = false;
	function collectFonts() {
		if (!need.fonts) { return null; }
		if (fontSigDone) { return fontSigCache; }
		fontSigDone = true;
		try {
			var vh = window.innerHeight || (document.documentElement && document.documentElement.clientHeight) || 0;
			var vw = window.innerWidth  || (document.documentElement && document.documentElement.clientWidth)  || 0;
			if (!vh || !document.body) { return null; }
			var map = Object.create(null);   // "family|weight|style" -> {cp:{}, n:0}
			var els = document.body.getElementsByTagName('*');
			var MAXEL = 5000, MAXKEYS = 12, MAXCP = 300;
			for (var i = 0; i < els.length && i < MAXEL; i++) {
				var el = els[i];
				var txt = directText(el);
				if (!txt || !/\S/.test(txt)) { continue; }
				var rect;
				try { rect = el.getBoundingClientRect(); } catch (e) { continue; }
				if (!rect || rect.width === 0 || rect.height === 0) { continue; }
				// Must intersect the first viewport.
				if (rect.top >= vh || rect.bottom <= 0 || rect.left >= vw || rect.right <= 0) { continue; }
				var cs = window.getComputedStyle ? window.getComputedStyle(el) : null;
				if (!cs || cs.display === 'none' || cs.visibility === 'hidden' || cs.visibility === 'collapse') { continue; }
				var fam = primaryFamily(cs.fontFamily || '');
				if (!fam) { continue; }
				var key = fam + '|' + normWeight(cs.fontWeight || '400') + '|' +
					(String(cs.fontStyle || '').indexOf('italic') === 0 ? 'italic' : 'normal');
				var rec = map[key];
				if (!rec) {
					if (Object.keys(map).length >= MAXKEYS) { continue; }
					rec = map[key] = { cp: Object.create(null), n: 0 };
				}
				for (var c = 0; c < txt.length && rec.n < MAXCP; c++) {
					var cp = txt.charCodeAt(c);
					if (cp > 32 && !rec.cp[cp]) { rec.cp[cp] = 1; rec.n++; }
				}
			}
			var out = [];
			for (var k in map) {
				var p = k.split('|');
				var cps = Object.keys(map[k].cp).map(Number);
				cps.sort(function (a, b) { return a - b; });
				out.push({ f: p[0], w: p[1], s: p[2], u: cps });
			}
			fontSigCache = out.length ? out : null;
			return fontSigCache;
		} catch (e) { fontSigCache = null; return null; }
	}

	/* ── Per-session de-dupe ─────────────────────────────────────────── */
	// Once this browser session has reported a given (url_type, viewport) we
	// skip re-sending on later page loads in the same session. The server
	// already stops enqueuing the beacon once a bucket is warm; this just
	// trims redundant in-session reports so the origin isn't hit repeatedly.
	function ssKey() { return 'eo_beacon:' + (cfg.urlType || '') + ':' + viewport; }
	function alreadyReported() {
		try { return !!window.sessionStorage && !!sessionStorage.getItem(ssKey()); } catch (e) { return false; }
	}
	function markReported() {
		try { if (window.sessionStorage) { sessionStorage.setItem(ssKey(), '1'); } } catch (e) {}
	}

	/* ── Send (once) ─────────────────────────────────────────────────── */
	function send() {
		if (sent) { return; }
		sent = true;
		if (alreadyReported()) { return; }

		if (lcpObserver) { try { lcpObserver.disconnect(); } catch (e) {} }
		snapshotClasses();

		var data = new FormData();
		data.append('action', 'easyopt_beacon');
		data.append('nonce', cfg.nonce);
		if (cfg.token) { data.append('token', cfg.token); }
		data.append('url_type', cfg.urlType || '');
		data.append('page_url', cfg.pageUrl || location.href);
		data.append('viewport', viewport);

		var anything = false;

		// LCP
		if (need.lcp && !lcpEntry && !lcpApiOk) {
			lcpEntry = findLcpFallback();
		}
		if (need.lcp && lcpEntry) {
			var el = lcpEntry.element;
			var size = lcpEntry.size || 0;
			if (el && isVisible(el) && size >= (cfg.minLcpSize || 10000)) {
				var r = resolveLcp(el);
				var url = r && r.url ? r.url : (lcpEntry.url || '');
				var kind = r ? r.kind : '';
				// (2.7.2) No image to preload — still report it, so the server
				// records the page as measured and stops asking. A poster-less
				// <video> sends its source (for matching only, never preloaded);
				// text and anything else image-less send 'none'.
				if (r && 'video' === r.kind) {
					url = el.currentSrc || url;
				} else if (r && !url) {
					kind = 'none';
				}
				if (r && (url || 'none' === kind)) {
					data.append('element_tag', r.tag);
					data.append('element_kind', kind);
					data.append('image_url', url);
					data.append('srcset', r.srcset || '');
					data.append('sizes', r.sizes || '');
					data.append('selector', cssPath(el));
					data.append('lcp_size', String(Math.round(size)));
					anything = true;
				}
			}
		}

		// Classes
		if (need.classes && haveClasses) {
			var classes = Object.keys(classSet);
			if (classes.length) {
				// Trim to a safe cap; server re-sanitizes and unions anyway.
				if (classes.length > 5000) { classes = classes.slice(0, 5000); }
				data.append('classes', classes.join(','));
				anything = true;
			}
		}

		// Fonts (above-the-fold family/weight/style + code points)
		if (need.fonts) {
			var fontSig = collectFonts();
			if (fontSig) {
				data.append('fonts', JSON.stringify(fontSig));
				anything = true;
			}
		}

		if (!anything) { return; }

		// Mark as reported only once the transport accepts the payload, so a
		// sendBeacon that the UA refuses (e.g. queue full) is retried next load.
		if (navigator.sendBeacon) {
			try { if (navigator.sendBeacon(cfg.ajaxUrl, data)) { markReported(); } return; } catch (e) {}
		}
		try {
			fetch(cfg.ajaxUrl, { method: 'POST', body: data, keepalive: true, credentials: 'same-origin' });
			markReported();
		} catch (e) { /* give up silently */ }
	}

	/* ── Lifecycle ───────────────────────────────────────────────────── */
	function onInteract() {
		// Interaction-driven classes (menus, tabs, accordions) appear now.
		snapshotClasses();
	}

	// FORCED REFLOW.
	//
	// snapshotClasses() and collectFonts() read getBoundingClientRect and
	// getComputedStyle. Those are layout reads: harmless on their own, but
	// running them in the same frame as `load` — or worse, inside a scroll
	// handler while the browser is mid-frame — is exactly the pattern
	// PageSpeed reports as a forced reflow.
	//
	// Nothing is removed. The work is simply moved to browser idle time,
	// after paint, where a layout read costs nothing the user can see.
	function idle(fn, timeout) {
		if (window.requestIdleCallback) {
			window.requestIdleCallback(fn, { timeout: timeout || 2000 });
		} else {
			setTimeout(fn, 200);
		}
	}

	// Snapshot at load, on first interaction, and after a short settle.
	if (document.readyState === 'complete') {
		idle(snapshotClasses);
	} else {
		window.addEventListener('load', function () { idle(snapshotClasses); }, { once: true });
	}

	var interactEvents = ['pointerdown', 'keydown', 'touchstart', 'scroll'];
	interactEvents.forEach(function (ev) {
		window.addEventListener(ev, function () { idle(onInteract); }, { once: true, passive: true });
	});

	setTimeout(function () { idle(snapshotClasses); }, 3500);

	// Precompute the font signature while idle and cache it, so the
	// end-of-life send() — which can fire on pagehide, when there is no time
	// budget at all — never has to walk the DOM.
	idle(function () { try { collectFonts(); } catch (e) {} }, 4000);

	// Send once at end-of-life or after a settle window.
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') { send(); }
	}, { once: true });
	window.addEventListener('pagehide', send, { once: true });
	setTimeout(send, 8000);
})();

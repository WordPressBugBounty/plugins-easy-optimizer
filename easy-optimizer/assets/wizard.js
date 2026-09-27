(function () {
"use strict";
"use strict";
const React = wp.element;
const { createElement, useState, useEffect } = wp.element;
const { createRoot } = wp.element;
const CFG = () => window.__EASYOPT_WIZARD__ || {};
const api = {
    post: (path, body) => fetch(CFG().restUrl + path, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG().restNonce },
        body: body ? JSON.stringify(body) : '{}'
    }).then(r => r.json()).catch(() => null),
    get: (path) => fetch(CFG().restUrl + path, {
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': CFG().restNonce }
    }).then(r => r.json()).catch(() => null),
};
const BoltIcon = () => (React.createElement("svg", { viewBox: "0 0 24 24", width: "22", height: "22", fill: "currentColor", "aria-hidden": "true" },
    React.createElement("polygon", { points: "13 2 3 14 12 14 11 22 21 10 12 10 13 2" })));
const CheckIcon = () => (React.createElement("svg", { viewBox: "0 0 24 24", width: "15", height: "15", fill: "none", stroke: "currentColor", strokeWidth: "3", strokeLinecap: "round", strokeLinejoin: "round", "aria-hidden": "true" },
    React.createElement("polyline", { points: "20 6 9 17 4 12" })));
const PRESETS = [
    {
        key: 'safe',
        name: 'Safe',
        tag: 'Lowest risk',
        desc: 'The essentials that help almost every site with zero compatibility risk.',
        features: [
            'Page cache + browser caching + Gzip',
            'Font-display: swap',
            'LCP image preload',
            'Lazy load (images, iframes, videos)',
            'Cache preload + moderate prefetch',
        ],
    },
    {
        key: 'balanced',
        name: 'Balanced',
        tag: 'Best compatibility',
        recommended: true,
        desc: 'Everything in Safe, plus the bigger speed wins tuned to stay compatible.',
        features: [
            'Everything in Safe',
            'Remove Unused CSS (Async)',
            'Delay JavaScript (jQuery excluded)',
            'Add missing image dimensions',
        ],
    },
    {
        key: 'maximum',
        name: 'Maximum',
        tag: 'Best score',
        desc: 'The most aggressive setup for the highest PageSpeed scores. Test after enabling.',
        features: [
            'Everything in Balanced',
            'Remove Unused CSS (Delay)',
            'Delay JavaScript (incl. jQuery)',
        ],
    },
];
function PresetCard({ p, selected, onSelect }) {
    return (React.createElement("button", { type: "button", className: 'eop-wiz-card' + (selected ? ' is-selected' : ''), onClick: () => onSelect(p.key), "aria-pressed": selected },
        p.recommended && React.createElement("span", { className: "eop-wiz-badge" }, "Recommended"),
        React.createElement("span", { className: "eop-wiz-card__radio", "aria-hidden": "true" }, selected && React.createElement(CheckIcon, null)),
        React.createElement("span", { className: "eop-wiz-card__name" }, p.name),
        React.createElement("span", { className: "eop-wiz-card__tag" }, p.tag),
        React.createElement("span", { className: "eop-wiz-card__desc" }, p.desc),
        React.createElement("ul", { className: "eop-wiz-card__list" }, p.features.map((f, i) => (React.createElement("li", { key: i },
            React.createElement("span", { className: "eop-wiz-tick" },
                React.createElement(CheckIcon, null)),
            f))))));
}
function PreflightPanel({ scan, onDeactivate, deactivating }) {
    if (!scan)
        return null;
    const findings = scan.findings || [];
    const notes = scan.notes || [];
    const warnings = scan.warnings || [];
    const plugins = scan.plugins || [];
    const server = scan.server || '';
    if (!findings.length && !notes.length && !warnings.length && !plugins.length)
        return null;
    // The red/orange helper line under the deactivate rows. Names the plugin
    // when there is exactly one; stays generic for several. The "Fully
    // compatible with X" reassurance is appended only on a known-good server
    // stack (LiteSpeed / Cloudways / SiteGround) AND only when there is a
    // conflict to act on — see the server-gated append below.
    const foot = plugins.length === 1
        ? `Deactivates ${plugins[0].name} and lets Easy Optimizer take over these jobs.`
        : `Deactivating switches the plugin off and lets Easy Optimizer take over its jobs.`;
    return (React.createElement("div", { className: "eop-wiz__preflight" },
        React.createElement("p", { className: "eop-wiz__preflight-head" },
            React.createElement(CheckIcon, null),
            " We checked your site"),
        plugins.length > 0 ? (React.createElement("ul", { className: "eop-wiz__preflight-plugins" }, plugins.map((p, i) => (React.createElement("li", { key: 'p' + i, className: "eop-wiz__pfrow" },
            React.createElement("span", { className: "eop-wiz__pfrow-text" },
                React.createElement("strong", null, p.name),
                " is doing ",
                (p.jobs && p.jobs.length ? p.jobs.join(', ') : 'the same work'),
                " too. Running both can cause issues."),
            React.createElement("button", { type: "button", className: "eop-wiz__deact", disabled: !!deactivating, onClick: () => onDeactivate && onDeactivate(p.file) }, deactivating === p.file ? 'Deactivating…' : 'Deactivate')))))) : (React.createElement("ul", { className: "eop-wiz__preflight-list" },
            findings.map((f, i) => (React.createElement("li", { key: 'f' + i },
                React.createElement("strong", null, f.feature),
                " \u2014 ",
                f.reason))),
            notes.map((n, i) => (React.createElement("li", { key: 'n' + i }, n))),
            warnings.map((w, i) => (React.createElement("li", { key: 'w' + i, className: "eop-wiz__preflight-warn" }, w))))),
        plugins.length > 0 && (React.createElement("p", { className: "eop-wiz__preflight-foot eop-wiz__preflight-brand" },
            foot,
            server ? ` Fully compatible with ${server}.` : ''))));
}
function Wizard() {
    const [preset, setPreset] = useState('balanced');
    const [tracking, setTracking] = useState(true);
    const [showInfo, setShowInfo] = useState(false);
    const [busy, setBusy] = useState(false);
    const [scan, setScan] = useState(null);
    const [deactivating, setDeactivating] = useState('');
    // Deactivate ONE conflicting plugin, then reload so the pre-flight re-runs
    // with it gone. After the reload there is no conflict for that plugin, so
    // finishing setup applies the full preset with nothing excluded on its
    // behalf — exactly "take over these jobs". Real deactivation server-side, not
    // a cosmetic toggle.
    const deactivate = (file) => {
        if (deactivating || !file)
            return;
        setDeactivating(file);
        api.post('/wizard/deactivate', { file }).then((r) => {
            if (r && r.ok) {
                window.location.reload();
                return;
            }
            setDeactivating('');
        }).catch(() => setDeactivating(''));
    };
    // Pre-flight is advisory and must never gate setup: if the request fails,
    // times out, or the endpoint is missing, the wizard renders exactly as it
    // did before with its three static cards.
    useEffect(() => {
        let alive = true;
        api.get('/wizard/preflight').then((r) => {
            if (alive && r && r.ok)
                setScan(r);
        }).catch(() => { });
        return () => { alive = false; };
    }, []);
    const finish = () => {
        if (busy)
            return;
        setBusy(true);
        api.post('/wizard/apply', { preset, tracking }).then((r) => {
            if (r && r.redirect) {
                window.location.href = r.redirect;
                return;
            }
            window.location.href = CFG().dashboardUrl || '';
        }).catch(() => { setBusy(false); });
    };
    const skip = () => {
        if (busy)
            return;
        setBusy(true);
        api.post('/wizard/skip', { tracking }).then((r) => {
            window.location.href = (r && r.redirect) ? r.redirect : (CFG().dashboardUrl || '');
        }).catch(() => { window.location.href = CFG().dashboardUrl || ''; });
    };
    return (React.createElement("div", { className: "eop-wiz" },
        React.createElement("div", { className: "eop-wiz__shell" },
            React.createElement("button", { type: "button", className: "eop-wiz__skip", onClick: skip, disabled: busy }, "Skip for now"),
            React.createElement("div", { className: "eop-wiz__head" },
                React.createElement("div", { className: "eop-wiz__mark" },
                    React.createElement(BoltIcon, null)),
                React.createElement("h1", { className: "eop-wiz__title" }, "Welcome to Easy Optimizer"),
                React.createElement("p", { className: "eop-wiz__sub" }, "Pick a starting point below. Nothing is enabled until you choose \u2014 and you can fine-tune every setting afterwards.")),
            React.createElement(PreflightPanel, { scan: scan, onDeactivate: deactivate, deactivating: deactivating }),
            React.createElement("div", { className: "eop-wiz__cards" }, PRESETS.map((p) => (React.createElement(PresetCard, { key: p.key, p: p, selected: preset === p.key, onSelect: setPreset })))),
            React.createElement("div", { className: "eop-wiz__consent" },
                React.createElement("label", { className: "eop-wiz__consent-row" },
                    React.createElement("input", { type: "checkbox", checked: tracking, onChange: () => setTracking(!tracking) }),
                    React.createElement("span", null, "Help improve Easy Optimizer"),
                    React.createElement("button", { type: "button", className: "eop-wiz__whats", onClick: () => setShowInfo(!showInfo) }, "What's this?")),
                showInfo && (React.createElement("div", { className: "eop-wiz__info" },
                    React.createElement("p", null,
                        "Allow Easy Optimizer to collect non-sensitive diagnostic data. This helps us improve ",
                        React.createElement("span", { className: "eop-wiz__hl" }, "compatibility"),
                        " and deliver ",
                        React.createElement("span", { className: "eop-wiz__hl" }, "new features"),
                        " faster."),
                    React.createElement("p", null,
                        React.createElement("strong", null, "Collected:"),
                        " plugin, WordPress & PHP versions, active theme, site language, enabled features."),
                    React.createElement("p", null,
                        React.createElement("strong", null, "We never collect:"),
                        " your email address, IP address, site content, user data, or any personally identifiable information. You can change this anytime in Settings.")))),
            React.createElement("div", { className: "eop-wiz__foot" },
                React.createElement("button", { type: "button", className: "eop-wiz__cta", onClick: finish, disabled: busy }, busy ? 'Applying…' : 'Apply & Finish'),
                React.createElement("span", { className: "eop-wiz__foot-note" },
                    "Applying the ",
                    React.createElement("strong", null, PRESETS.find(p => p.key === preset)?.name),
                    " preset")))));
}
const container = document.getElementById('easyopt-wizard-app');
if (container) {
    if (createRoot) {
        createRoot(container).render(React.createElement(Wizard, null));
    }
    else {
        wp.element.render(React.createElement(Wizard, null), container);
    }
}

})();

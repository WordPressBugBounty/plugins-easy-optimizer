(function () {
"use strict";
/**
 * Decides whether to show the WordPress.org review ask.
 *
 * Pure: no DOM, no clock, no storage. `now` is passed in so the 90-day
 * re-ask window is deterministic and testable.
 *
 * WordPress.org forbids incentivised reviews — nothing may be offered in
 * exchange for a rating. This gate only decides WHEN it is honest to ask.
 *
 *   results  { mobile: {before,after}, desktop: {before,after} }   (data.results)
 *   state    { reviewed, jobRunning, dismissedAt, now }
 *   returns  { fire, reason, facts? }
 */
var REVIEW_GATE = { MIN_DELTA: 10, MIN_MOBILE: 70, MIN_DESKTOP: 80, REASK_DAYS: 90 };

function reviewGate(results, state) {
    state = state || {};

    // Decisive and cheap: nothing below can change these answers.
    if (state.reviewed) { return { fire: false, reason: 'already_reviewed' }; }
    if (state.jobRunning) { return { fire: false, reason: 'scan_running' }; }

    var m = (results && results.mobile) || {};
    var d = (results && results.desktop) || {};

    // A score is usable only when the scan actually produced a number.
    // Both devices are checked, not just the one the panel is showing.
    var usable = function (r) { return !!r && typeof r.score === 'number' && r.failed !== true; };
    if (!usable(m.before) || !usable(m.after) || !usable(d.before) || !usable(d.after)) {
        return { fire: false, reason: 'incomplete' };
    }

    var facts = {
        mobileBefore: m.before.score,
        mobileAfter: m.after.score,
        mobileDelta: m.after.score - m.before.score,
        desktopBefore: d.before.score,
        desktopAfter: d.after.score,
        desktopDelta: d.after.score - d.before.score,
    };
    var no = function (reason) { return { fire: false, reason: reason, facts: facts }; };

    // Mobile carries the delta: desktop usually starts high, so mobile is
    // where an optimizer's work actually shows. The desktop floor below is
    // what stops us celebrating on a site that is still bad on desktop.
    if (facts.mobileDelta < REVIEW_GATE.MIN_DELTA) { return no('delta_too_small'); }
    if (!(facts.mobileAfter > REVIEW_GATE.MIN_MOBILE)) { return no('mobile_floor'); }
    if (!(facts.desktopAfter > REVIEW_GATE.MIN_DESKTOP)) { return no('desktop_floor'); }

    // Checked last, so `reason` on a snoozed install still tells you it
    // would otherwise have fired.
    if (state.dismissedAt) {
        if (((state.now || 0) - state.dismissedAt) < REVIEW_GATE.REASK_DAYS * 86400) {
            return no('snoozed');
        }
    }

    return { fire: true, reason: 'ok', facts: facts };
}

if (typeof module !== 'undefined' && module.exports) { module.exports = { reviewGate, REVIEW_GATE }; }
"use strict";
const React = wp.element;
const { createElement, Fragment, useState, useEffect, useCallback, useMemo, useRef } = wp.element;
const { createRoot } = wp.element;
// Cross-mount cache for dashboard stats (survives tab switches / remounts).
let __eopStatsCache = null;
// ── Config ──
const CFG = () => window.__EASYOPT__ || {};
// Free-tier size fallback. The live numbers come from the server (the /analyze
// response and the /cloud/usage payload); this is only used before either has
// arrived. Change the server config and every "Up to N" line follows.
// Trial sizing. The service is authoritative and sends these on /connect and
// /cloud/usage; this is only the pre-arrival fallback, so a slow first render
// never shows a blank or a wrong number.
const TRIAL_FALLBACK = { days: 14, gb: 10 };
// Deliberately leads with the time limit, not the allowance. A trial's shape
// is "14 days, then it stops" — burying that under a GB figure would make the
// end of the trial feel like a bait-and-switch.
const trialLine = (days, gb) => (days || TRIAL_FALLBACK.days) + " days of the full CDN, up to "
    + (gb || TRIAL_FALLBACK.gb) + " GB. No card, and your site keeps working when it ends.";
// ── REST API ──
const api = {
    get: (path) => fetch(CFG().restUrl + path, {
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': CFG().restNonce }
    }).then(r => r.json()).catch(() => null),
    post: (path, body) => fetch(CFG().restUrl + path, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG().restNonce },
        body: body ? JSON.stringify(body) : '{}'
    }).then(r => r.json()).catch(() => null),
};
// ── Helpers ──
const Icon = ({ id, cls }) => (React.createElement("svg", { className: cls || 'eop-icon' }, React.createElement("use", { href: '#eop-i-' + id })));
const nf = (n) => n != null && n.toLocaleString ? n.toLocaleString() : (n ?? '—');
// ── NAV CONFIG (2.6.5) ──
//
// Was 13 items across 7 groups — averaging 1.9 items per group, with
// 'Performance' holding exactly one. 'Speed' / 'Performance' / 'Assets' /
// 'Infrastructure' were four labels for one thing a beginner holds as "make
// my site fast", and none of them predicted its contents: someone looking for
// image settings had to guess between three groups. That is a taxonomy
// failure, and it would still hurt at eight items.
//
// Now 4 groups. Every item is kept and nothing is renamed — only regrouped —
// so no existing user relearns a location and no support screenshot goes
// stale. Fonts joins Speed (it is a front-end speed feature, and the panel
// already cross-links into Optimization). Object Cache joins Advanced, which
// removes the one-item group and the Speed/Performance ambiguity together.
//
// ADVANCED IS COLLAPSED BY DEFAULT (see collapsible). A beginner sees eight
// items. That group holds the settings where a beginner does real damage —
// Redis, Cloudflare, cron, and Bloat Removal's "Disable RSS Feeds" and
// "Limit REST API to Logged-in Users", both of which carry breakage warnings —
// and none of them are on the path to "my site is faster". Reading "Advanced"
// and correctly inferring "not for me yet" is the feature, not a side effect.
//
// Icons: five of thirteen items used to share two glyphs (eop-i-cache and
// eop-i-db are byte-identical; Dashboard and Backend Analyzer both used
// gauge). Caching → layers, Object Cache → chip, Backend Analyzer → pulse.
const NAV = [
    { group: '', items: [{ key: 'dashboard', label: 'Dashboard', icon: 'gauge' }] },
    { group: 'Speed', items: [
            { key: 'cache', label: 'Caching', icon: 'layers' },
            { key: 'optimization', label: 'Optimization', icon: 'bolt' },
            { key: 'imgopt', label: 'Image Optimization', icon: 'image' },
            { key: 'fonts', label: 'Fonts', icon: 'font' },
        ] },
    { group: 'Advanced', collapsible: true, items: [
            { key: 'objectcache', label: 'Object Cache', icon: 'chip' },
            { key: 'cloudflare', label: 'Cloudflare', icon: 'cloud' },
            { key: 'bloat', label: 'Bloat Removal', icon: 'broom' },
            { key: 'database', label: 'Database Optimization', icon: 'db' },
            { key: 'heartbeat', label: 'Heartbeat & Cron', icon: 'heart' },
        ] },
    { group: 'Tools', items: [
            { key: 'accessibility', label: 'Accessibility & SEO', icon: 'eye' },
            { key: 'backend', label: 'Backend Analyzer', icon: 'pulse' },
        ] },
    { group: '', items: [
            // Cloud lives in the sidebar upgrade card / the ServiceBar, not as a
            // plain nav row (differentiates free vs Pro). Kept in NAV so the tab
            // still routes and the breadcrumb still resolves its label; `hidden`
            // only stops it rendering as a sidebar link.
            { key: 'flux', label: 'Cloud Optimization', icon: 'cloud', hidden: true },
            { key: 'settings', label: 'Settings', icon: 'gear' },
        ] },
];
// Settings that, when on, mean this site is already using the Advanced group —
// so an upgrading install never has its nav quietly fold up around features it
// is actively running.
const ADVANCED_ACTIVE_KEYS = [
    'easyopt_object_cache', 'easyopt_cf_enabled', 'easyopt_bloat_heartbeat',
];
const OPT_SUBTABS = [
    { key: 'js', label: 'JavaScript', icon: 'code' },
    { key: 'css', label: 'CSS', icon: 'brush' },
    { key: 'lazy', label: 'Lazy Load', icon: 'image' },
    { key: 'lcp', label: 'Preload LCP', icon: 'zap' },
    { key: 'prefetch', label: 'Prefetch Pages', icon: 'refresh' },
];
const DB_SUBTABS = [
    { key: 'cleanup', label: 'Database Cleanup', icon: 'trash' },
    { key: 'autoload', label: 'Autoload Health', icon: 'db' },
];
// ── Collapsible help ──
function Collapsible({ title, children, defaultOpen }) {
    const [open, setOpen] = useState(!!defaultOpen);
    return (React.createElement("div", { className: `eop-collapsible ${open ? 'eop-collapsible--open' : ''}` }, React.createElement("button", { type: "button", className: "eop-collapsible__toggle", onClick: () => setOpen(!open) }, React.createElement(Icon, { id: open ? 'chev-down' : 'chev-right', cls: "eop-icon eop-icon--xs" }), React.createElement("span", null, title)), open && React.createElement("div", { className: "eop-collapsible__body" }, children)));
}
// ── Sub-tab navigation ──
function SubTabNav({ tabs, active, onChange }) {
    return (React.createElement("div", { className: "eop-subtabs" }, tabs.map((t) => (React.createElement("button", { key: t.key, type: "button", className: `eop-subtab ${active === t.key ? 'eop-subtab--active' : ''}`, onClick: () => onChange(t.key) }, t.icon && React.createElement(Icon, { id: t.icon, cls: "eop-icon eop-icon--sm" }), React.createElement("span", null, t.label))))));
}
// ── Popup Modal ──
function PopupModal({ open, title, help, placeholder, value, onSave, onClose }) {
    const [text, setText] = useState(value || '');
    useEffect(() => {
        if (open)
            setText(value || '');
    }, [open, value]);
    if (!open)
        return null;
    return (React.createElement("div", { id: "easyopt-popup-overlay", className: "easyopt-popup-overlay open", onClick: (e) => {
            if (e.target === e.currentTarget)
                onClose();
        } }, React.createElement("div", { className: "easyopt-popup", role: "dialog", "aria-modal": "true" }, React.createElement("div", { className: "easyopt-popup-header" }, React.createElement("h2", { className: "easyopt-popup-title" }, title), React.createElement("button", { type: "button", className: "easyopt-popup-close", onClick: onClose }, "\u00D7")), React.createElement("div", { className: "easyopt-popup-body" }, React.createElement("p", { className: "easyopt-popup-help", style: { whiteSpace: 'pre-line' } }, help), React.createElement("textarea", { rows: 14, value: text, onChange: (e) => setText(e.target.value), placeholder: placeholder, spellCheck: false, autoCapitalize: "off", autoCorrect: "off" })), React.createElement("div", { className: "easyopt-popup-footer" }, React.createElement("button", { type: "button", className: "button button-secondary easyopt-popup-cancel", onClick: onClose }, "Cancel"), React.createElement("button", { type: "button", className: "button button-primary easyopt-popup-save", onClick: () => { onSave(text); onClose(); } }, "Save")))));
}
function ConfirmModal({ open, title, message, confirmLabel, onConfirm, onCancel, danger }) {
    if (!open)
        return null;
    return (React.createElement("div", { className: "easyopt-popup-overlay open", onClick: (e) => {
            if (e.target === e.currentTarget)
                onCancel();
        } }, React.createElement("div", { className: "easyopt-popup", role: "dialog", "aria-modal": "true", style: { maxWidth: '440px' } }, React.createElement("div", { className: "easyopt-popup-header" }, React.createElement("h2", { className: "easyopt-popup-title" }, title), React.createElement("button", { type: "button", className: "easyopt-popup-close", onClick: onCancel }, "\u00D7")), React.createElement("div", { className: "easyopt-popup-body" }, React.createElement("p", { style: { margin: 0, fontSize: '13px', lineHeight: '1.6' } }, message)), React.createElement("div", { className: "easyopt-popup-footer" }, React.createElement("button", { type: "button", className: "button button-secondary easyopt-popup-cancel", onClick: onCancel }, "Cancel"), React.createElement("button", { type: "button", className: danger ? 'button eop-snap-delete' : 'button button-primary', onClick: onConfirm }, confirmLabel || 'Confirm')))));
}
function PopupField({ name, title, help, placeholder, value, onChange, compact }) {
    const [open, setOpen] = useState(false);
    const lines = (value || '').split(/\r\n|\r|\n/).filter((s) => s.trim().length > 0).length;
    const label = lines === 1 ? 'Edit list (1 entry)' : `Edit list (${lines} entries)`;
    const cls = compact ? 'easyopt-field-row easyopt-field-row--compact' : 'easyopt-field-row';
    return (React.createElement("div", { className: cls }, React.createElement("label", null, React.createElement("strong", null, title)), React.createElement("div", { className: "easyopt-popup-field" }, React.createElement("textarea", { name: name, className: "easyopt-popup-textarea", hidden: true, value: value || '', readOnly: true }), React.createElement("button", { type: "button", className: "button easyopt-popup-open", onClick: () => setOpen(true) }, label)), !compact && React.createElement("p", { className: "description" }, help), React.createElement(PopupModal, { open: open, title: title, help: help, placeholder: placeholder, value: value, onSave: (v) => onChange(name, v), onClose: () => setOpen(false) })));
}
function PopupGrid({ entries, cols, settings, onChange }) {
    return (React.createElement("div", { className: `easyopt-popup-grid easyopt-popup-grid--${cols}` }, entries.map((e) => (React.createElement(PopupField, { key: e.name, name: e.name, title: e.title, help: e.help, placeholder: e.placeholder, value: settings[e.name] || '', onChange: onChange, compact: true })))));
}
// ── Save Bar ──
function SaveBar({ dirty, onSave, onDiscard, saving }) {
    const n = Object.keys(dirty).length;
    useEffect(() => {
        const h = (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 's') {
                e.preventDefault();
                if (n > 0)
                    onSave();
            }
        };
        document.addEventListener('keydown', h);
        return () => document.removeEventListener('keydown', h);
    }, [n, onSave]);
    useEffect(() => {
        const h = (e) => {
            if (n > 0) {
                e.preventDefault();
                e.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', h);
        return () => window.removeEventListener('beforeunload', h);
    }, [n]);
    return (React.createElement("div", { className: `eop-savebar ${n > 0 ? 'eop-savebar--visible' : ''}`, role: "region", "aria-live": "polite", "aria-hidden": n === 0 ? 'true' : 'false' }, React.createElement("span", { className: "eop-savebar__dot", "aria-hidden": "true" }), React.createElement("span", { className: "eop-savebar__label" }, "You have ", React.createElement("strong", { className: "eop-savebar__count" }, n, " unsaved ", n === 1 ? 'change' : 'changes'), "."), React.createElement("button", { type: "button", className: "eop-savebar__discard", onClick: onDiscard }, "Discard"), React.createElement("button", { type: "button", className: `eop-savebar__save ${saving ? 'eop-savebar__save--busy' : ''}`, disabled: saving, onClick: onSave }, "Save changes")));
}
// ── Toast ──
function Toast({ text, kind }) {
    if (!text)
        return null;
    return React.createElement("div", { className: `eop-toast eop-toast--visible ${kind ? 'eop-toast--' + kind : ''}`, role: "status", "aria-live": "polite" }, text);
}
// ── Field helpers ──
function Check({ name, label, desc, settings, onChange, bold, cls, disabled }) {
    const checked = Number(settings[name]) === 1;
    const rowCls = "easyopt-field-row" + (disabled ? " easyopt-field-row--disabled" : "");
    return (React.createElement("div", { className: rowCls }, React.createElement("label", { className: cls }, React.createElement("input", { type: "checkbox", name: name, className: cls, value: "1", checked: checked, disabled: !!disabled, onChange: () => { if (disabled) {
            return;
        } onChange(name, checked ? '0' : '1'); } }), bold ? React.createElement("strong", null, label) : label), desc && React.createElement("p", { className: "description" }, desc)));
}
function SelectField({ name, label, settings, onChange, options }) {
    return (React.createElement("div", { className: "easyopt-row" }, React.createElement("label", { htmlFor: name }, label), React.createElement("select", { id: name, name: name, value: settings[name] || '', onChange: (e) => onChange(name, e.target.value) }, options.map((o) => React.createElement("option", { key: o.value, value: o.value, disabled: o.disabled }, o.label)))));
}
// ── Dependency warning ──
function DepWarning({ message, linkLabel, onLink }) {
    return (React.createElement("div", { className: "eop-dep-warning" }, React.createElement(Icon, { id: "warn", cls: "eop-icon eop-icon--sm" }), React.createElement("span", null, message), linkLabel && React.createElement("button", { type: "button", className: "eop-dep-warning__link", onClick: onLink }, linkLabel)));
}
// ═══════════════════════════════════════════════════
//  DASHBOARD PANEL
// ═══════════════════════════════════════════════════
// ═══════════════════════════════════════════════════
//  PSI BENCHMARK — Before vs After rings (2.4.0)
// ═══════════════════════════════════════════════════
function ScoreRing({ score, faded, label }) {
    const R = 52, C = 2 * Math.PI * R;
    const has = typeof score === 'number';
    const col = !has ? 'var(--eop-border, #d6d9de)' : faded ? '#9aa1ad' : score >= 90 ? '#0c9d57' : score >= 50 ? '#f0a429' : '#e5484d';
    return (React.createElement("div", { style: { flex: 1, border: '1px solid var(--eop-border, #e3e5e8)', borderRadius: '14px', padding: '18px 10px 14px', display: 'flex', flexDirection: 'column', alignItems: 'center', position: 'relative', background: faded ? 'var(--eop-bg-alt, #fafafb)' : 'rgba(226,245,236,.35)' } }, React.createElement("span", { style: { position: 'absolute', top: '-11px', padding: '3px 12px', borderRadius: '20px', fontSize: '12px', fontWeight: 700, border: '1px solid var(--eop-border, #e3e5e8)', background: '#fff', color: faded ? '#9aa1ad' : '#0c9d57' } }, faded ? 'Before' : 'After'), React.createElement("div", { style: { position: 'relative', width: '116px', height: '116px' } }, React.createElement("svg", { width: "116", height: "116", viewBox: "0 0 116 116", style: { transform: 'rotate(-90deg)', display: 'block' } }, React.createElement("circle", { cx: "58", cy: "58", r: R, fill: "none", stroke: "#eceef2", strokeWidth: "10" }), React.createElement("circle", { cx: "58", cy: "58", r: R, fill: "none", stroke: col, strokeWidth: "10", strokeLinecap: "round", strokeDasharray: C, strokeDashoffset: has ? C * (1 - score / 100) : C, style: { transition: 'stroke-dashoffset 1s cubic-bezier(.4,0,.2,1)' } })), React.createElement("div", { style: { position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '36px', fontWeight: 800, color: col } }, has ? score : '—')), React.createElement("div", { style: { fontWeight: 700, fontSize: '13px', marginTop: '10px', color: faded ? 'var(--eop-text-soft, #6b7280)' : 'inherit' } }, label)));
}
function HostingCTA() {
    return React.createElement("div", { className: "eop-host-row" }, React.createElement("span", { className: "eop-host-row__text" }, React.createElement("strong", null, "On slow hosting?"), " WordPress on high-performance cloud servers — often the single biggest speed win."), React.createElement("a", { href: "https://unified.cloudways.com/signup?id=662383", target: "_blank", rel: "sponsored nofollow noopener", className: "eop-host-cta" }, React.createElement("span", { className: "eop-host-cta__shine", "aria-hidden": "true" }), "\u26A1 Upgrade hosting"));
}
// ═══════════════════════════════════════════════════
//  REVIEW ASK (2.6.3)
// ═══════════════════════════════════════════════════
// The decision lives in reviewGate() — react-src/review-gate.js, concatenated
// into this bundle by build.sh and covered by tests/review-gate.test.js.
// This component only renders what the gate already decided, which is why the
// gate could be tested before the card existed.
//
// WordPress.org forbids incentivised reviews: nothing is offered in exchange.
//
// reviewGate is a bundle-level global, like `wp` above: build.sh concatenates
// review-gate.js into the same IIFE. Deliberately not `declare`d, so this file
// stays parseable as plain JS and can be syntax-checked without tsc.
function ReviewAsk({ data, settings }) {
    const [step, setStep] = useState(null);
    const s = settings || {};
    const verdict = reviewGate((data && data.results) || {}, {
        reviewed: !!Number(s['easyopt_review_done']),
        jobRunning: !!(data && data.job && data.job.status === 'running'),
        dismissedAt: Number(s['easyopt_review_dismissed_at']) || null,
        now: Math.floor(Date.now() / 1000),
    });
    if (!verdict.fire && !step) {
        return null;
    }
    const remember = (changes) => { api.post('/settings', { changes: changes }); };
    // Snooze for the gate's 90-day window (REASK_DAYS).
    const dismiss = () => remember({ easyopt_review_dismissed_at: Math.floor(Date.now() / 1000) });
    const snooze = () => { dismiss(); setStep('gone'); };
    const done = () => { remember({ easyopt_review_done: 1 }); setStep('gone'); };
    if (step === 'gone') {
        return null;
    }
    if (step === 'help') {
        return React.createElement("div", { style: { marginTop: '14px', padding: '12px 14px', border: '1px solid var(--eop-border, #e6e8ee)', borderRadius: 'var(--eop-r-sm, 8px)' } }, React.createElement("div", { style: { fontWeight: 700, marginBottom: '4px' } }, "Sorry — what went wrong?"), React.createElement("p", { style: { margin: '0 0 10px', fontSize: '13px', color: 'var(--eop-sub, #475569)' } }, "Tell us on the support forum and we'll take a look. Most issues turn out to be a plugin conflict we can pin down quickly."), React.createElement("a", { className: "button button-primary", href: "https://wordpress.org/support/plugin/easy-optimizer/#new-topic-0", target: "_blank", rel: "noopener", onClick: () => setStep('gone') }, "Open a support thread"));
    }
    const f = verdict.facts;
    return React.createElement("div", { style: { marginTop: '14px', display: 'flex', alignItems: 'center', gap: '14px', flexWrap: 'wrap', padding: '12px 14px', background: 'var(--eop-ok-soft, #d1fae5)', border: '1px solid var(--eop-ok, #10b981)', borderRadius: 'var(--eop-r-sm, 8px)' } }, React.createElement("div", { style: { fontSize: '22px', fontWeight: 800, color: 'var(--eop-ok-fg, #047857)', lineHeight: 1 } }, "+" + f.mobileDelta), React.createElement("div", { style: { flex: '1 1 260px', fontSize: '13px', color: 'var(--eop-ok-fg, #047857)' } }, React.createElement("strong", null, "Mobile PageSpeed went " + f.mobileBefore + " → " + f.mobileAfter + "."), " Desktop is at " + f.desktopAfter + ". If Easy Optimizer earned it, a review helps other people find it."), React.createElement("div", { style: { display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' } }, React.createElement("a", { className: "button button-primary button-small", href: "https://wordpress.org/support/plugin/easy-optimizer/reviews/#new-post", target: "_blank", rel: "noopener", onClick: done }, "★ Leave a review"), React.createElement("button", { type: "button", className: "button button-small", onClick: snooze }, "Not now"), React.createElement("button", { type: "button", onClick: () => { dismiss(); setStep('help'); }, style: { background: 'none', border: 0, padding: 0, cursor: 'pointer', fontSize: '12px', color: 'var(--eop-sub, #475569)', textDecoration: 'underline' } }, "Something's wrong")));
}
function PsiBenchmark({ showToast: toast, data, refresh, onCloudways, dev, setDev, settings }) {
    // (2.6.0) `dev` is owned by DashboardPanel now: the "What to fix next"
    // card below shows findings for the SAME device, and two independent
    // toggles showing mobile scores next to desktop recommendations would be
    // quietly wrong. Still mobile-first, still mobile on the left.
    const [starting, setStarting] = useState(false);
    const results = (data && data.results) || {};
    const d = results[dev] || {};
    const before = d.before, after = d.after;
    const delta = before && after ? after.score - before.score : null;
    const job = data && data.job;
    const running = job && job.status === 'running';
    const doneSteps = running ? Object.keys(job.steps).filter((k) => job.steps[k] !== 'pending').length : 0;
    const totalSteps = running ? Object.keys(job.steps).length : 0;
    const start = () => {
        setStarting(true);
        api.post('/psi/benchmark', {}).then((r) => {
            setStarting(false);
            if (!r || r.code) {
                toast((r && r.message) || 'Could not start the speed test.', 'error');
                return;
            }
            refresh(); // shared store begins polling
        });
    };
    const setAuto = (on) => {
        api.post('/psi/auto', { on: on ? 1 : 0 }).then(() => refresh());
    };
    const vital = (name, key) => (React.createElement("div", { style: { display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '9px' } }, React.createElement("span", { style: { fontWeight: 700, fontSize: '13px' } }, name), React.createElement("span", { style: { fontSize: '13px', color: '#9aa1ad', textDecoration: 'line-through' } }, (before && before[key]) || '—'), React.createElement("span", { style: { color: '#9aa1ad' } }, "\u203A"), React.createElement("span", { style: { fontSize: '14px', color: '#0c9d57', fontWeight: 800 } }, (after && after[key]) || '—')));
    const lastTs = after && after.tested_at ? new Date(after.tested_at * 1000).toLocaleString() : null;
    const shownScore = after ? after.score : (before ? before.score : null);
    // Host recommendation CTA is disabled. Flip ENABLE_HOST_CTA to true to
    // bring it back later. Hosting detection / Varnish purge are unaffected.
    const ENABLE_HOST_CTA = false;
    const showCTA = ENABLE_HOST_CTA && !onCloudways && typeof shownScore === 'number' && shownScore < 85;
    return (React.createElement("div", { style: { border: '1px solid var(--eop-border, #e3e5e8)', borderRadius: '14px', padding: '20px', marginBottom: '22px' } }, React.createElement("div", { style: { display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: '20px' } }, React.createElement("div", null, React.createElement("div", { style: { fontSize: '11px', fontWeight: 800, letterSpacing: '.07em', color: '#9aa1ad' } }, "GOOGLE PAGESPEED"), React.createElement("div", { style: { fontSize: '18px', fontWeight: 800, marginTop: '4px' } }, "Your speed, before and after")), React.createElement("div", { style: { display: 'inline-flex', gap: '4px', background: '#eef0f4', padding: '4px', borderRadius: '10px' } }, ['mobile', 'desktop'].map(s => React.createElement("button", { key: s, type: "button", onClick: () => setDev(s), style: { border: 'none', cursor: 'pointer', padding: '6px 14px', borderRadius: '7px', fontSize: '12px', fontWeight: 700, background: dev === s ? '#fff' : 'transparent', color: dev === s ? 'var(--eop-accent, #5b4ee8)' : '#6b7280', boxShadow: dev === s ? '0 1px 3px rgba(20,18,40,.12)' : 'none' } }, s === 'mobile' ? 'Mobile' : 'Desktop')))), React.createElement("div", { style: { display: 'flex', alignItems: 'center', gap: '18px', marginBottom: '18px' } }, React.createElement(ScoreRing, { score: before ? before.score : null, faded: true, label: "Without Easy Optimizer" }), React.createElement("div", { style: { display: 'flex', flexDirection: 'column', alignItems: 'center', padding: '0 4px' } }, React.createElement("div", { style: { fontSize: '30px', fontWeight: 800, color: delta !== null && delta >= 0 ? '#0c9d57' : '#e5484d', lineHeight: 1 } }, delta !== null ? (delta >= 0 ? '+' : '') + delta : '—'), React.createElement("div", { style: { color: '#9aa1ad', fontSize: '11px', fontWeight: 700, marginTop: '6px', letterSpacing: '.04em' } }, "POINTS")), React.createElement(ScoreRing, { score: after ? after.score : null, faded: false, label: "With Easy Optimizer" })), React.createElement("div", { style: { display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '12px', background: 'var(--eop-bg-alt, #fafafb)', border: '1px solid var(--eop-border, #e3e5e8)', borderRadius: '10px', padding: '12px 14px' } }, vital('LCP', 'lcp'), vital('CLS', 'cls'), vital('INP', 'inp')), showCTA && React.createElement(HostingCTA, null), React.createElement(ReviewAsk, { data: data, settings: settings }), React.createElement("div", { style: { display: 'flex', alignItems: 'center', gap: '12px', justifyContent: 'flex-end', marginTop: '14px', flexWrap: 'wrap' } }, running
        ? React.createElement("span", { style: { display: 'inline-flex', alignItems: 'center', gap: '8px', color: 'var(--eop-accent, #5b4ee8)', fontSize: '13px', fontWeight: 600 } }, React.createElement("span", { className: "eop-spinner", "aria-hidden": "true" }), (job && job.status === 'running' && !job.cron_seen && (Math.floor(Date.now() / 1000) - (Number(job.started) || 0)) > 15
            ? "Testing your site… " + doneSteps + "/" + totalSteps + " done — running via your browser (wp-cron looks blocked by a firewall); keep this page open"
            : "Testing your site… " + doneSteps + "/" + totalSteps + " done (1\u20132 min — you can leave this page)"))
        : React.createElement(React.Fragment, null, data && data.own_key_valid && React.createElement("label", { style: { display: 'inline-flex', alignItems: 'center', gap: '6px', fontSize: '12px', color: 'var(--eop-text-soft, #6b7280)', fontWeight: 600 } }, React.createElement("input", { type: "checkbox", checked: Number(data.auto) === 1, onChange: (e) => setAuto(e.target.checked), style: { margin: 0 } }), "Re-test automatically every day"), React.createElement("span", { style: { color: '#9aa1ad', fontSize: '12px', fontWeight: 600 } }, lastTs ? 'Last tested ' + lastTs : (data ? 'Not tested yet' : 'Loading…'), data && !data.own_key && ' · ' + data.manual_left + ' of ' + data.manual_limit + ' free tests left today'), React.createElement("button", { type: "button", className: "button button-small button-primary", disabled: starting || !data, onClick: start }, starting ? 'Starting…' : before ? 'Run speed test' : 'Run first speed test')))));
}
// ═══════════════════════════════════════════════════
//  WHAT TO FIX NEXT (2.6.0)
// ═══════════════════════════════════════════════════
// Replaces the old "Key Features / Feature status" card, which was six
// read-only toggles duplicating the sidebar — it told the user nothing they
// could act on. This card is driven by real PageSpeed findings, each tied to
// one Easy Optimizer setting, ranked by estimated saving.
//
// The audit → setting mapping, the already-on handling and the ranking all
// live server-side in EasyOpt\Psi\Client (see action_map() /
// recommendations_from()), because those decisions need to know which
// settings are on and which audit IDs this build understands. React only
// renders what it is given.
//
// Set to true to bring the old feature-status list back to the dashboard;
// get_module_statuses() and its REST field are untouched either way, and the
// list itself is unchanged, so this is a one-flag revert.
const SHOW_FEATURE_STATUS_CARD = false;
// Chips name the Core Web Vital each finding moves. Distinct hues so the eye
// can group by metric down the list; LCP and CLS come straight from the token
// set, INP uses a sky pair from the same Tailwind family the palette is built
// on (there is no existing token in that hue).
const EOP_METRIC_CHIP = {
    LCP: { bg: 'var(--eop-brand-soft, #eeebff)', fg: 'var(--eop-brand, #5b4ed9)' },
    CLS: { bg: 'var(--eop-warn-soft, #fef3c7)', fg: 'var(--eop-warn-fg, #92400e)' },
    INP: { bg: '#e0f2fe', fg: '#0369a1' },
};
function eopSaving(ms) {
    const n = Number(ms) || 0;
    if (n <= 0) {
        return '';
    }
    return n >= 1000
        ? 'est. saving ' + (n / 1000).toFixed(1) + ' s'
        : 'est. saving ' + Math.round(n) + ' ms';
}
// How much this feature is typically worth. Red / orange / grey reads as a
// priority ladder at a glance, which is the whole job of the badge.
const EOP_IMPACT_CHIP = {
    high: { bg: 'var(--eop-bad-soft, #fee2e2)', fg: '#b91c1c', label: 'HIGH' },
    med: { bg: 'var(--eop-warn-soft, #fef3c7)', fg: 'var(--eop-warn-fg, #92400e)', label: 'MED' },
    low: { bg: 'var(--eop-line-soft, #f0f2f6)', fg: 'var(--eop-text-muted, #475569)', label: 'LOW' },
};
function Chip({ bg, fg, children }) {
    return createElement('span', {
        style: {
            display: 'inline-block', padding: '2px 7px', borderRadius: '5px',
            background: bg, color: fg, fontSize: '10.5px', fontWeight: 800,
            letterSpacing: '.04em', lineHeight: 1.5
        }
    }, children);
}
function MetricChip({ name }) {
    const c = EOP_METRIC_CHIP[name] || { bg: 'var(--eop-line-soft, #f0f2f6)', fg: 'var(--eop-text-muted, #475569)' };
    return createElement(Chip, { bg: c.bg, fg: c.fg }, name);
}
function ImpactChip({ level }) {
    const c = EOP_IMPACT_CHIP[level] || EOP_IMPACT_CHIP.med;
    return createElement(Chip, { bg: c.bg, fg: c.fg }, c.label);
}
// ── Compatibility card (2.6.5) ──
//
// One surface answering one question: what on this site is Easy Optimizer NOT
// doing, and why? It holds four things that were previously scattered or
// invisible:
//   • features the setup left off because something else owns the job — this
//     used to be a dismissible admin notice whose own docblock said "this is
//     reference information, not an alert", and once dismissed the answer to
//     "why is Remove Unused CSS off?" was gone;
//   • a foreign object-cache drop-in — pre-flight has detected this since
//     2.6.0 and nothing ever displayed it, so installing Redis Object Cache
//     after setup was silent;
//   • the SiteGround "keep this plugin" correction;
//   • soft-tier overlaps — plugins that do overlapping work but whose
//     settings we cannot read. These deliberately never become an admin
//     notice: an interruption should cost a claim we can defend.
//
// Fetched over REST rather than embedded in the page's initial data, matching
// the existing rule in class-easyopt-settings.php that dashboard stats are
// never computed inline (it caused 502s on large sites). Renders nothing when
// there is nothing to say.
function CompatibilityCard() {
    const [data, setData] = useState(null);
    const [busy, setBusy] = useState(false);
    const load = (refresh) => {
        setBusy(true);
        api.get('/compat' + (refresh ? '?refresh=1' : ''))
            .then((r) => { if (r && r.ok) {
            setData(r);
        } })
            .finally(() => setBusy(false));
    };
    useEffect(() => { load(false); }, []);
    if (!data || !data.count) {
        return null;
    }
    const KIND = {
        excluded: { dot: 'var(--eop-text-muted, #888)', label: 'Off on purpose' },
        info: { dot: 'var(--eop-success, #22c55e)', label: 'Working together' },
        check: { dot: 'var(--eop-warn, #f59e0b)', label: 'Worth checking' },
    };
    return (React.createElement("div", { className: "eop-card", style: { marginTop: '16px' } }, React.createElement("div", { className: "eop-card__header" }, React.createElement("div", null, React.createElement("div", { className: "eop-card__kicker" }, "Compatibility"), React.createElement("div", { className: "eop-card__title" }, "What Easy Optimizer is leaving alone")), React.createElement("button", { type: "button", className: "eop-btn eop-btn--soft eop-btn--sm", onClick: () => load(true), disabled: busy }, busy ? 'Checking…' : 'Re-check')), React.createElement("p", { className: "description", style: { margin: '0 0 12px' } }, "Other plugins on this site already handle some of these jobs. Nothing here is broken — it is what Easy Optimizer has stepped back from, and why."), React.createElement("ul", { style: { listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: '10px' } }, data.items.map((it, i) => {
        const k = KIND[it.kind] || KIND.info;
        return (React.createElement("li", { key: i, style: { display: 'flex', gap: '10px', alignItems: 'flex-start' } }, React.createElement("span", { style: { width: 7, height: 7, borderRadius: '50%', background: k.dot, flexShrink: 0, marginTop: '6px' }, title: k.label }), React.createElement("div", null, React.createElement("strong", null, it.title), " — ", React.createElement("span", { style: { color: 'var(--eop-text-muted, #666)' } }, it.detail), it.url ? React.createElement("a", { href: it.url, style: { marginLeft: '6px', whiteSpace: 'nowrap' } }, "Open its settings") : null)));
    }))));
}
// ── Sidebar group (2.6.5) ──
// Plain groups render exactly as before. A `collapsible` group ships closed on
// a fresh install so a beginner sees eight sidebar items instead of thirteen,
// with three guards so it can never become a discoverability trap:
//   1. it opens if any feature inside it is already switched on — an upgrading
//      install never has its nav fold up around features it is running;
//   2. it opens if the active tab lives inside it, so a deep link or a
//      restored tab can never leave the user on a page whose nav entry is
//      hidden;
//   3. once the user opens or closes it, that choice is remembered.
function NavGroup({ g, tab, switchTab, settings }) {
    const storeKey = 'easyopt_nav_' + (g.group || 'bottom').toLowerCase();
    const holdsActiveTab = g.items.some((i) => i.key === tab);
    const usesAdvanced = ADVANCED_ACTIVE_KEYS.some((k) => Number(settings[k]) === 1);
    const [open, setOpen] = useState(() => {
        if (!g.collapsible) {
            return true;
        }
        try {
            const stored = localStorage.getItem(storeKey);
            if (stored !== null) {
                return stored === '1';
            }
        }
        catch (e) { /* private mode — fall through to the computed default */ }
        return holdsActiveTab || usesAdvanced;
    });
    useEffect(() => { if (holdsActiveTab && !open) {
        setOpen(true);
    } }, [holdsActiveTab]);
    const toggle = () => {
        const next = !open;
        setOpen(next);
        try {
            localStorage.setItem(storeKey, next ? '1' : '0');
        }
        catch (e) { /* nothing to persist to; the session still works */ }
    };
    const items = g.items.filter((item) => !item.hidden).map((item) => (React.createElement("a", { href: "#", key: item.key, className: `eop-tab eop-nav__item ${tab === item.key ? 'active' : ''}`, "data-tab": item.key, onClick: (e) => { e.preventDefault(); switchTab(item.key); } }, React.createElement(Icon, { id: item.icon }), React.createElement("span", null, item.label))));
    if (!g.collapsible) {
        return (React.createElement("div", { className: "eop-nav__group" }, g.group && React.createElement("div", { className: "eop-nav__group-label" }, g.group), items));
    }
    // Rendered as a bordered, filled PILL (prototype variant A). A plain
    // uppercase label that happened to toggle read as static text no matter how
    // the chevron was placed — users did not know it was clickable. Giving it a
    // real button chrome (border + surface fill) separates it unmistakably from
    // the flat SPEED / TOOLS section labels: it looks like a control because it
    // is one. A leading "layers" glyph anchors it, and the trailing chevron
    // rotates on open. No item count — the user did not want a number.
    return (React.createElement("div", { className: `eop-nav__group eop-nav__group--collapsible ${open ? 'is-open' : ''}` }, React.createElement("button", { type: "button", className: "eop-nav__group-toggle", onClick: toggle, "aria-expanded": open ? 'true' : 'false' }, React.createElement(Icon, { id: 'layers', cls: "eop-icon eop-nav__group-glyph" }), React.createElement("span", { className: "eop-nav__group-text" }, g.group), React.createElement(Icon, { id: 'chev-right', cls: "eop-icon eop-nav__group-chev" })), open && items));
}
function FixNextCard({ psiData, dev, switchTab, settings }) {
    const [showAll, setShowAll] = useState(false);
    const job = psiData && psiData.job;
    const running = !!(job && job.status === 'running');
    const measured = !!(psiData && psiData.audits_available && psiData.audits_available[dev]);
    const recs = (psiData && psiData.recommendations && psiData.recommendations[dev]) || [];
    const suggested = (psiData && psiData.suggested) || [];
    const after = (psiData && psiData.results && psiData.results[dev] && psiData.results[dev].after) || null;
    const tested = after && after.tested_at ? new Date(after.tested_at * 1000).toLocaleDateString() : '';
    // Measured findings win; the settings-derived list is the fallback and is
    // labelled as such — presenting a static checklist as a measurement is
    // exactly the dishonesty this card exists to avoid.
    const source = measured ? recs : suggested;
    // (2.6.0) Reconcile against LIVE settings on every render.
    //
    // `recommendations` and `suggested` are computed server-side and only
    // change when /psi/data is refetched, so after the user turned a feature on
    // in its settings tab and came back, the card still listed it — it looked
    // like the dashboard had not noticed. React already holds the authoritative
    // current settings, so an "enable this" row is dropped the instant its
    // setting reads 1. No refetch, no reload, no extra request.
    //
    // Only 'enable' rows are filtered. A 'tune' row means the feature is
    // already on and PSI still flagged it — that one stays until a fresh test
    // says otherwise, which is the whole point of it.
    const items = source.filter((r) => 'enable' !== r.state
        || !r.opt
        || Number(settings && settings[r.opt]) !== 1);
    const shown = showAll ? items : items.slice(0, 3);
    // (2.6.0) The button navigates; it does not flip the setting. Turning an
    // optimization on can change how every page renders, so it belongs on the
    // screen that also shows the exclusions and the accompanying options —
    // not behind a one-word button on the dashboard.
    const goto = (r) => switchTab(r.tab, r.sub || undefined);
    const header = createElement('div', { className: 'eop-card__header' }, createElement('div', null, createElement('div', { className: 'eop-card__kicker' }, 'What to fix next'), createElement('div', { className: 'eop-card__title' }, measured
        ? (tested ? 'From your ' + dev + ' test · ' + tested : 'From your last ' + dev + ' test')
        : 'Recommended next steps')));
    let body;
    if (running && !items.length) {
        body = createElement('div', { className: 'eop-empty-state' }, createElement('div', { className: 'eop-empty-state__icon' }, createElement('span', { className: 'eop-spinner', 'aria-hidden': 'true' })), createElement('div', { className: 'eop-empty-state__text' }, 'Testing your site — findings appear here when it finishes.'));
    }
    else if (!items.length) {
        // Two very different "nothing here" cases, and conflating them would
        // either take credit we have not earned or hide a real all-clear.
        body = createElement('div', { className: 'eop-empty-state' }, createElement('div', { className: 'eop-empty-state__icon' }, createElement(Icon, { id: measured ? 'check' : 'info' })), createElement('div', { className: 'eop-empty-state__text' }, measured
            ? 'Nothing left that Easy Optimizer can fix on this page. What remains is your theme, your plugins or your host.'
            : 'Every Easy Optimizer feature is already on. Run a speed test to see what is still slowing the page down.'));
    }
    else {
        body = createElement('div', null, shown.map((r) => createElement('div', {
            key: r.id,
            style: {
                display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between',
                gap: '12px', padding: '11px 0',
                borderTop: '1px solid var(--eop-line-soft, #f0f2f6)'
            }
        }, createElement('div', { style: { minWidth: 0, flex: 1 } }, createElement('div', {
            style: { fontSize: '13.5px', fontWeight: 700, lineHeight: 1.35 }
        }, r.title), createElement('div', {
            style: { display: 'flex', alignItems: 'center', gap: '6px', marginTop: '6px', flexWrap: 'wrap' }
        }, 
        // "Impact" labels the row so the chips are self-explanatory: which
        // Core Web Vital this moves, and how much it is typically worth.
        createElement('span', {
            style: { fontSize: '10.5px', fontWeight: 700, letterSpacing: '.04em', color: 'var(--eop-mute, #94a3b8)', textTransform: 'uppercase' }
        }, 'Impact'), (r.metrics || []).map((m) => createElement(MetricChip, { key: m, name: m })), createElement(ImpactChip, { level: r.impact }), (r.display || eopSaving(r.savings_ms)) && createElement('span', {
            style: { fontSize: '11.5px', color: 'var(--eop-text-muted, #475569)', fontWeight: 600 }
        }, r.display || eopSaving(r.savings_ms))), 
        // Only shown when the feature is ALREADY on and the audit still
        // fails. That is the genuinely useful case — a bare "Review
        // settings" button with no reason would just puzzle the user.
        r.state === 'tune' && r.note && createElement('div', {
            style: { fontSize: '11.5px', color: 'var(--eop-text-muted, #475569)', marginTop: '6px', lineHeight: 1.45 }
        }, r.note)), createElement('button', {
            type: 'button',
            className: 'eop-btn eop-btn--sm ' + (r.state === 'enable' ? 'eop-btn--soft' : 'eop-btn--ghost'),
            style: { flexShrink: 0, whiteSpace: 'nowrap' },
            onClick: () => goto(r)
        }, r.label))), items.length > 3 && createElement('button', {
            type: 'button', className: 'eop-btn eop-btn--ghost eop-btn--sm',
            style: { marginTop: '12px' }, onClick: () => setShowAll(!showAll)
        }, showAll ? 'Show less' : 'Show all ' + items.length), !measured && createElement('p', {
            className: 'description', style: { margin: '12px 0 0', fontSize: '11.5px', lineHeight: 1.5 }
        }, 'Based on your settings — not measured on your site. Run a speed test for findings from your actual pages.'));
    }
    return createElement('div', { className: 'eop-card' }, header, body);
}
function DashboardPanel({ settings, stats, modules, switchTab, latestStats, showToast, psiData, refreshPsi }) {
    const [psiDev, setPsiDev] = useState('mobile'); // (2.6.0) shared by the score panel and the fix-next card
    const baselineKicked = useRef(false);
    useEffect(() => {
        if (!psiData || baselineKicked.current)
            return;
        const r = psiData.results || {};
        const hasAny = ['mobile', 'desktop'].some((s) => r[s] && (r[s].before || r[s].after));
        const jobRunning = psiData.job && psiData.job.status === 'running';
        const canRun = psiData.own_key ? psiData.own_key_valid : true;
        if (!hasAny && !jobRunning && canRun) {
            baselineKicked.current = true;
            api.post('/psi/benchmark', {}).then(() => refreshPsi());
        }
    }, [psiData]);
    const onCloudways = !!(CFG().onCloudways);
    const cacheOn = Number(settings.easyopt_cache) === 1;
    const preloadOn = Number(settings.easyopt_cache_preload) === 1;
    const [actionMsg, setActionMsg] = useState({ text: '', ok: null });
    const [busy, setBusy] = useState('');
    // Cross-mount cache so switching tabs shows the last value immediately
    // instead of resetting to 0 until the next fetch lands.
    const [liveStats, setLiveStats] = useState(__eopStatsCache || latestStats || stats);
    const pollRef = useRef(null);
    const inFlight = useRef(false);
    const refresh = useCallback(() => {
        // In-flight guard: never stack a new request on top of a pending one.
        // On cheap shared hosting each /dashboard-stats holds a PHP worker, so
        // overlapping polls pile up and starve every other request.
        if (inFlight.current)
            return;
        // Don't poll a hidden tab.
        if (typeof document !== 'undefined' && document.hidden)
            return;
        inFlight.current = true;
        api.get('/dashboard-stats').then((r) => {
            if (r) {
                __eopStatsCache = r;
                setLiveStats(r);
            }
        }).catch(() => { }).finally(() => { inFlight.current = false; });
    }, []);
    useEffect(() => {
        const ps = liveStats?.preload_status;
        if (ps && (ps.status === 'running' || ps.status === 'paused')) {
            if (!pollRef.current)
                pollRef.current = setInterval(refresh, 20000);
        }
        else {
            if (pollRef.current) {
                clearInterval(pollRef.current);
                pollRef.current = null;
            }
        }
        return () => {
            if (pollRef.current) {
                clearInterval(pollRef.current);
                pollRef.current = null;
            }
        };
    }, [liveStats?.preload_status?.status]);
    useEffect(() => {
        refresh();
        const onVis = () => {
            if (!document.hidden)
                refresh();
        };
        document.addEventListener('visibilitychange', onVis);
        return () => document.removeEventListener('visibilitychange', onVis);
    }, []);
    const msg = (t, ok) => {
        setActionMsg({ text: t, ok });
        if (ok !== null)
            setTimeout(() => setActionMsg({ text: '', ok: null }), 4000);
    };
    const cfConnected = !!(settings.easyopt_cf_enabled && settings.easyopt_cf_api_token && settings.easyopt_cf_zone_id);
    const clearCfCache = () => { setBusy('cf'); msg('Purging Cloudflare cache…', null); api.post('/cloudflare/purge').then((r) => { msg(r?.success ? 'Cloudflare cache purged.' : (r?.message || 'Failed.'), !!r?.success); }).finally(() => setBusy('')); };
    const clearCache = () => {
        setBusy('clear');
        msg('Clearing cache…', null);
        api.post('/cache/clear').then((r) => {
            msg(r?.success ? 'Cache cleared.' : (r?.message || 'Failed.'), !!r?.success);
            if (r?.stats)
                setLiveStats(r.stats);
            setTimeout(refresh, 5000);
            setTimeout(refresh, 12000);
            setTimeout(refresh, 25000);
            setTimeout(refresh, 40000);
        }).finally(() => setBusy(''));
    };
    const startPreload = () => { setBusy('preload'); msg('Starting preload…', null); api.post('/cache/preload-start').then((r) => { msg(r?.success ? 'Preload started.' : (r?.message || 'Failed.'), !!r?.success); refresh(); }).finally(() => setBusy('')); };
    const clearAndPreload = () => {
        setBusy('both');
        msg('Clearing, then preloading…', null);
        api.post('/cache/clear').then((r) => {
            if (r?.stats)
                setLiveStats(r.stats);
            return api.post('/cache/preload-start');
        }).then((r) => { msg('Cache cleared, preload started.', true); refresh(); }).catch(() => msg('Failed.', false)).finally(() => setBusy(''));
    };
    const mode = settings.easyopt_cache_mode;
    const fallback = Number(settings.easyopt_htaccess_fallback_to_php || 0);
    let kpiMode = 'Off', kpiClass = 'is-warn';
    if (cacheOn && mode === 'htaccess' && !fallback) {
        kpiMode = 'Apache (Fastest)';
        kpiClass = 'is-ok';
    }
    else if (cacheOn) {
        kpiMode = 'PHP';
        kpiClass = 'is-brand';
    }
    const ps = liveStats?.preload_status || {};
    return (React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-dashboard" }, React.createElement("header", { className: "eop-page-header" }, React.createElement("h1", { className: "eop-page-title" }, "Performance overview"), React.createElement("p", { className: "eop-page-desc" }, "A glance at your cache, active modules and recent activity. Use the quick actions on the right to clear or warm the cache.")), React.createElement(PsiBenchmark, { showToast: showToast, data: psiData, refresh: refreshPsi, onCloudways: onCloudways, dev: psiDev, setDev: setPsiDev, settings: settings }), React.createElement("div", { className: "eop-kpi-strip" }, React.createElement("div", { className: "eop-metric" }, React.createElement("div", { className: "eop-metric__label" }, "Pages cached"), React.createElement("div", { className: `eop-metric__value ${cacheOn ? 'is-brand' : ''}`, id: "eop-pages-cached" }, (liveStats?.pages_label != null && liveStats.pages_label !== "") ? liveStats.pages_label : nf(liveStats?.pages)), 
    // (2.6.0) With separate mobile cache on, one URL has several
    // variant files on disk but counts once — which is what the
    // number means and what get_stats() computes.
    React.createElement("div", { className: "eop-metric__sub" }, cacheOn
        ? (Number(liveStats?.separate_mobile) === 1
            ? 'Unique URLs'
            : 'Static HTML on disk')
        : 'Enable caching to start')), React.createElement("div", { className: "eop-metric" }, React.createElement("div", { className: "eop-metric__label" }, "URLs in waiting"), React.createElement("div", { className: "eop-metric__value", id: "eop-urls-waiting" }, nf(liveStats?.waiting)), React.createElement("div", { className: "eop-metric__sub" }, preloadOn ? 'Pages queued for preloading' : 'No preload running')), React.createElement("div", { className: "eop-metric" }, React.createElement("div", { className: "eop-metric__label" }, "Serving mode"), React.createElement("div", { className: `eop-metric__value ${kpiClass}`, style: { fontSize: '18px' } }, kpiMode), React.createElement("div", { className: "eop-metric__sub" }, "Cache ", cacheOn ? 'active' : 'disabled'))), React.createElement("div", { className: "eop-grid-2" }, 
    // (2.6.0) "Feature status" was six read-only toggles duplicating
    // the sidebar. Flip SHOW_FEATURE_STATUS_CARD to restore it — the
    // markup below is byte-identical to what shipped in 2.5.x, and
    // get_module_statuses() still feeds `modules` either way.
    SHOW_FEATURE_STATUS_CARD
        ? React.createElement("div", { className: "eop-card" }, React.createElement("div", { className: "eop-card__header" }, React.createElement("div", null, React.createElement("div", { className: "eop-card__kicker" }, "Key Features"), React.createElement("div", { className: "eop-card__title" }, "Feature status"))), React.createElement("div", { className: "eop-module-list" }, (modules || []).map((m) => (React.createElement("div", { className: "eop-module", key: m.opt, style: { cursor: 'pointer' }, onClick: () => switchTab(m.tab, m.sub || undefined) }, React.createElement("div", { className: "eop-module__left" }, React.createElement("span", { className: "eop-switch eop-switch--readonly", "aria-hidden": "true" }, React.createElement("input", { type: "checkbox", disabled: true, checked: m.enabled }), React.createElement("span", null)), React.createElement("div", null, React.createElement("div", { className: "eop-module__title" }, m.title))), React.createElement("span", { className: "eop-module__link" }, React.createElement(Icon, { id: "chev-right", cls: "eop-icon eop-icon--sm" })))))))
        : React.createElement(FixNextCard, { psiData: psiData, dev: psiDev, switchTab: switchTab, settings: settings }), React.createElement("div", { className: "eop-card" }, React.createElement("div", { className: "eop-card__header" }, React.createElement("div", null, React.createElement("div", { className: "eop-card__kicker" }, "Operations"), React.createElement("div", { className: "eop-card__title" }, "Quick actions"))), cacheOn && preloadOn && React.createElement(React.Fragment, null, React.createElement("button", { type: "button", className: "eop-btn eop-btn--primary eop-btn--full", disabled: !!busy, "aria-busy": busy === 'preload', onClick: startPreload }, React.createElement("span", { className: "eop-btn-icon" }, React.createElement(Icon, { id: "refresh" })), React.createElement("span", { className: "eop-btn-label" }, "Preload Cache"), React.createElement("span", { className: "eop-btn-spinner" })), React.createElement("button", { type: "button", className: "eop-btn eop-btn--ghost eop-btn--full", disabled: !!busy, "aria-busy": busy === 'both', onClick: clearAndPreload }, React.createElement("span", { className: "eop-btn-icon" }, React.createElement(Icon, { id: "trash" })), React.createElement("span", { className: "eop-btn-label" }, "Clear Cache & Preload"), React.createElement("span", { className: "eop-btn-spinner" })), cfConnected && React.createElement("button", { type: "button", className: "eop-btn eop-btn--ghost eop-btn--full", disabled: !!busy, "aria-busy": busy === 'cf', onClick: clearCfCache }, React.createElement("span", { className: "eop-btn-icon" }, React.createElement(Icon, { id: "cloud" })), React.createElement("span", { className: "eop-btn-label" }, "Clear Cloudflare Cache"), React.createElement("span", { className: "eop-btn-spinner" })), ps.status === 'paused' && React.createElement("div", { className: "eop-action-status", style: { color: '#d97706' } }, "Paused — server busy", ps.paused_reason ? ` (${ps.paused_reason})` : '', ". Resumes automatically.")), cacheOn && !preloadOn && React.createElement(React.Fragment, null, React.createElement("button", { type: "button", className: "eop-btn eop-btn--primary eop-btn--full", disabled: !!busy, onClick: clearCache }, React.createElement("span", { className: "eop-btn-icon" }, React.createElement(Icon, { id: "trash" })), React.createElement("span", { className: "eop-btn-label" }, "Clear Cache"), React.createElement("span", { className: "eop-btn-spinner" })), cfConnected && React.createElement("button", { type: "button", className: "eop-btn eop-btn--ghost eop-btn--full", disabled: !!busy, "aria-busy": busy === 'cf', onClick: clearCfCache }, React.createElement("span", { className: "eop-btn-icon" }, React.createElement(Icon, { id: "cloud" })), React.createElement("span", { className: "eop-btn-label" }, "Clear Cloudflare Cache"), React.createElement("span", { className: "eop-btn-spinner" }))), !cacheOn && React.createElement("div", { className: "eop-empty-state" }, React.createElement("div", { className: "eop-empty-state__icon" }, React.createElement(Icon, { id: "info" })), React.createElement("div", { className: "eop-empty-state__text" }, "Enable Page Cache in the Caching tab to access quick actions."), React.createElement("a", { href: "#", className: "eop-tab eop-btn eop-btn--soft eop-btn--sm", onClick: (e) => { e.preventDefault(); switchTab('cache'); } }, "Go to Caching")), React.createElement("div", { id: "eop-action-msg", className: "eop-action-msg", style: { color: actionMsg.ok === false ? 'var(--eop-danger)' : actionMsg.ok === true ? 'var(--eop-success)' : 'var(--eop-text-muted)' } }, actionMsg.text), 
    // Pro upgrade card — inside the Quick actions card, below the buttons.
    (settings.easyopt_cloud_account || Number(settings.easyopt_fluxcdn_verified) === 1) ? null : React.createElement("div", { className: "eop-upsell eop-upsell--dash" }, React.createElement("div", { className: "eop-upsell__tag" }, "⚡ Pro"), React.createElement("div", { className: "eop-upsell__title" }, "Unlock the edge"), React.createElement("div", { className: "eop-upsell__body" }, "Guaranteed 90+ PageSpeed. Images, CSS, JS & fonts served from 119 locations."), React.createElement("button", { type: "button", className: "eop-upsell__btn", onClick: () => switchTab('flux') }, "Unlock 90+ PageSpeed Now")))), (() => {
        const sc = liveStats?.server_cache;
        const layers = sc?.layers || [];
        if (!layers.length)
            return null;
        return (React.createElement("div", { className: "eop-card", style: { marginTop: '16px' } }, React.createElement("div", { className: "eop-card__header" }, React.createElement("div", null, React.createElement("div", { className: "eop-card__kicker" }, "Server Environment"), React.createElement("div", { className: "eop-card__title" }, "Detected server caches (", layers.length, ")"))), React.createElement("p", { className: "description", style: { margin: '0 0 12px' } }, "These server-side caches are automatically purged when you clear all cache. Per-URL purges (on post save) only target the changed URL."), React.createElement("div", { style: { display: 'flex', flexWrap: 'wrap', gap: '8px' } }, layers.map((l) => (React.createElement("span", { key: l.key, style: { display: 'inline-flex', alignItems: 'center', gap: '6px', padding: '5px 12px', borderRadius: '6px', background: 'var(--eop-bg-subtle, #f0f0f0)', fontSize: '13px', fontWeight: 500, color: 'var(--eop-text, #333)' } }, React.createElement("span", { style: { width: 7, height: 7, borderRadius: '50%', background: 'var(--eop-success, #22c55e)', flexShrink: 0 } }), l.label))))));
    })(), React.createElement(CompatibilityCard, null)));
}
// ═══════════════════════════════════════════════════
//  CACHE PANEL — 3-col excludes + collapsible help
// ═══════════════════════════════════════════════════
function CachePanel({ settings, onChange }) {
    const on = Number(settings.easyopt_cache) === 1;
    return (React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-cache" }, React.createElement("h2", null, "Page Caching"), React.createElement("label", { className: "eop-cache-toggle" }, React.createElement("input", { type: "checkbox", name: "easyopt_cache", value: "1", checked: on, onChange: () => onChange('easyopt_cache', on ? '0' : '1') }), " Enable Page Cache"), React.createElement("p", { className: "description" }, "Saves the fully-optimized HTML to disk and serves it on subsequent visits — bypassing PHP, theme rendering and the database."), React.createElement(Collapsible, { title: "How does page caching work?" }, React.createElement("p", null, "When a visitor requests a page, WordPress normally boots PHP, queries the database, loads your theme, and renders the HTML from scratch — even if the page hasn't changed. With caching enabled, the first visit generates the HTML normally, but then saves a static copy to disk. Every subsequent visitor gets that pre-built HTML file directly, skipping the entire WordPress stack. This can reduce response time from 500ms+ to under 10ms."), React.createElement("p", null, React.createElement("strong", null, "Apache (.htaccess) mode"), " serves cached files before PHP even starts — the fastest option. ", React.createElement("strong", null, "PHP mode"), " works on all servers but still loads a tiny PHP bootstrap. Both produce identical HTML.")), !on && React.createElement("div", { className: "eop-cache-disabled-prompt" }, React.createElement("div", { className: "eop-prompt-icon" }, "\u26A1"), React.createElement("h3", null, "Caching is currently disabled"), React.createElement("p", null, "Enable Page Cache above to configure caching options.")), React.createElement("div", { className: "cachefield", style: !on ? { display: 'none' } : {} }, React.createElement(SelectField, { name: "easyopt_cache_mode", label: "Serving Mode", settings: settings, onChange: onChange, options: [
            { value: 'php', label: 'PHP (works everywhere)' },
            { value: 'htaccess', label: '.htaccess rewrites (Apache, fastest)', disabled: !CFG().isApache },
        ] }), React.createElement(SelectField, { name: "easyopt_cache_ttl", label: "Cache Lifetime", settings: settings, onChange: onChange, options: [
            { value: '0', label: 'Until cleared manually (recommended)' },
            { value: '3600', label: '1 Hour' }, { value: '43200', label: '12 Hours' },
            { value: '86400', label: '1 Day' }, { value: '604800', label: '1 Week' },
        ] }), React.createElement(Check, { name: "easyopt_cache_separate_mobile", label: "Separate Mobile Cache", bold: true, settings: settings, onChange: onChange, desc: "Create separate cache files for mobile and desktop." }), React.createElement(Check, { name: "easyopt_cache_logged_in", label: "Cache for Logged-in Users", bold: true, settings: settings, onChange: onChange, desc: "Not recommended for dynamic content." }), React.createElement(Collapsible, { title: "What does 'Cache for Logged-in Users' do?" }, React.createElement("p", null, "Creates role-based cache files (one per WordPress role: administrator, editor, subscriber, etc.). Dynamic per-user content like shopping carts, dashboards, or \"Hello, Username\" may display cached data from the wrong user. Only enable this on sites where logged-in pages show the same content to all users of the same role.")), React.createElement(Check, { name: "easyopt_cache_browser_caching", label: "Browser Caching", bold: true, settings: settings, onChange: onChange, desc: "Adds Expires + Cache-Control headers so browsers cache CSS/JS/fonts/images." }), React.createElement(Check, { name: "easyopt_cache_gzip", label: "Gzip / Brotli Compression", bold: true, settings: settings, onChange: onChange, desc: "Compresses your HTML, CSS, JS, JSON, SVG and fonts before sending them, so pages download faster." }), React.createElement(Check, { name: "easyopt_preconnect", label: "Preconnect to External Origins", bold: true, settings: settings, onChange: onChange, desc: "Connects early to external services like Google Fonts and CDNs so their files start loading sooner." }), React.createElement(Check, { name: "easyopt_cache_preload", label: "Preload Cache", bold: true, settings: settings, onChange: onChange, desc: "Warm the cache by crawling the site's sitemap." }), Number(settings.easyopt_cache_preload) === 1 && React.createElement("div", { style: { margin: '10px 0 6px 0' } }, React.createElement(SelectField, { name: "easyopt_cache_preload_speed", label: "Preload Speed", settings: settings, onChange: onChange, options: [
            { value: 'gentle', label: 'Gentle — one page at a time (best for shared hosting)' },
            { value: 'balanced', label: 'Balanced — up to 3 pages at a time (recommended)' },
            { value: 'turbo', label: 'Turbo — up to 5 pages at a time (VPS / managed)' },
        ] })), React.createElement("h3", { style: { marginTop: '24px', marginBottom: '12px' } }, "Cache Exclusions"), React.createElement(PopupGrid, { entries: [
            { name: 'easyopt_cache_exclude_urls', title: 'Excluded URLs', help: 'One URL fragment per line. Matching pages bypass cache.', placeholder: "/cart\n/checkout\n/my-account" },
            { name: 'easyopt_cache_exclude_cookies', title: 'Excluded Cookies', help: 'Visitors with these cookies bypass cache.', placeholder: "my_custom_session" },
            { name: 'easyopt_cache_strip_query_params', title: 'Ignored Query Parameters', help: 'IGNORED — every value shares ONE cache file.\n\n/page/?utm_source=google and /page/?utm_source=facebook are both served the cached copy of /page/. This is what keeps campaign links fast: without it, one newsletter send would split a page into thousands of separate cache files.\n\nAround 90 tracking parameters (utm_*, gclid, fbclid, msclkid…) are already ignored by default — add your own here, one per line.\n\nUse the other box, Cache Query String, when the parameter actually changes what the page shows.', placeholder: "my_tracking_param\npartner_id" },
            { name: 'easyopt_cache_query_strings', title: 'Cache Query String', help: 'CACHED SEPARATELY — each value gets its OWN cache file.\n\nUse this when your server renders the parameter into the page: a form that writes UTM values into hidden fields, or a plugin that shows different content per ?ref=. /page/?utm_source=google and /page/?utm_source=facebook then get one cached copy each, instead of sharing one.\n\nListed here, a parameter also stops being ignored — so this is how you override one of the ~90 defaults.\n\nEmpty by default. Add only what your pages genuinely vary on, and list EVERY parameter your page reads: keying on utm_source alone still leaves utm_campaign showing the first visitor’s value.\n\nPer-click IDs (gclid, fbclid, msclkid, _ga…) are refused — their values are unique per visitor, so each one would create a cache file per visit and fill your disk.', placeholder: "utm_source\nutm_medium\nutm_campaign" },
        ], cols: 2, settings: settings, onChange: onChange }), (!CFG().isApache && CFG().nginxSnippet && String(settings.easyopt_cache_mode || 'php') === 'php') ? React.createElement("div", { style: { marginTop: '20px' } }, React.createElement(Collapsible, { title: "Advanced: optional Nginx server rules", defaultOpen: false }, React.createElement("p", { className: "description" }, "Your cache is already working — nothing here is required. These optional rules let Nginx serve cached pages without starting PHP, which helps on busy sites. Many managed hosts don't allow server changes; if yours doesn't, just ignore this."), React.createElement("textarea", { readOnly: true, value: CFG().nginxSnippet, onFocus: (e) => e.target.select(), spellCheck: false, style: { width: '100%', minHeight: '200px', fontFamily: 'monospace', fontSize: '12px', marginTop: '8px' } }), React.createElement("p", { className: "description", style: { marginTop: '6px' } }, "Not sure? Send this to your host — it's a standard server-block change."))) : null)));
}
// ═══════════════════════════════════════════════════
//  OPTIMIZATION — JAVASCRIPT sub-tab
// ═══════════════════════════════════════════════════
function JsSubPanel({ settings, onChange }) {
    const dj = Number(settings.easyopt_delay_js) === 1;
    return (React.createElement("div", null, React.createElement("h2", { className: "eop-section-h" }, "JavaScript Optimization"), React.createElement("label", null, React.createElement("input", { type: "checkbox", id: "easyopt_minify_js", name: "easyopt_minify_js", value: "1", checked: Number(settings.easyopt_minify_js) === 1, onChange: () => onChange('easyopt_minify_js', Number(settings.easyopt_minify_js) === 1 ? '0' : '1') }), " ", React.createElement("strong", null, "Minify JavaScript")), React.createElement("p", { className: "description" }, "Minifies all local JavaScript files."), React.createElement("label", { style: { marginTop: '16px' } }, React.createElement("input", { type: "checkbox", id: "easyopt_delay_js", name: "easyopt_delay_js", value: "1", checked: dj, onChange: () => onChange('easyopt_delay_js', dj ? '0' : '1') }), " ", React.createElement("strong", null, "Delay JavaScript Execution")), React.createElement("p", { className: "description" }, "Optimizes JS loading to improve page speed. Choose a method below."), React.createElement(Collapsible, { title: "Delay vs Defer — what's the difference?" }, React.createElement("p", null, React.createElement("strong", null, "Delay until interaction"), " rewrites all scripts so they don't load at all until the visitor clicks, scrolls, or presses a key. This gives the best PageSpeed scores because the browser can focus entirely on rendering the page. However, it can break features that depend on JavaScript running immediately (sliders, analytics tracking, popups, payment gateways)."), React.createElement("p", null, React.createElement("strong", null, "Defer (native browser defer)"), " adds the standard HTML ", React.createElement("code", null, "defer"), " attribute to scripts. They download in parallel with HTML parsing and execute after the document is parsed — but before user interaction. This is safer and rarely breaks anything, though the speed improvement is smaller."), React.createElement("p", null, React.createElement("strong", null, "Recommendation:"), " Start with Defer. If your site works fine, try Delay for better scores. Always test checkout and contact forms after switching.")), React.createElement("div", { className: "delayjsfield", style: !dj ? { display: 'none' } : {} }, React.createElement(SelectField, { name: "easyopt_delay_js_method", label: "Method", settings: settings, onChange: onChange, options: [{ value: 'delay', label: 'Delay until interaction (best scores)' }, { value: 'defer', label: 'Defer (native browser defer — safer)' }] }), settings.easyopt_delay_js_method === 'defer'
        ? React.createElement("p", { className: "description", style: { marginTop: '-6px', fontSize: '12px', color: 'var(--eop-text-muted)' } }, "Adds the native ", React.createElement("code", null, "defer"), " attribute to external scripts. Scripts download in parallel and execute after HTML parsing.")
        : React.createElement("p", { className: "description", style: { marginTop: '-6px', fontSize: '12px', color: 'var(--eop-text-muted)' } }, "Rewrites scripts to load only after user interaction (click, scroll, keypress). Most aggressive — best scores, but make sure everything is working."), React.createElement(Check, { name: "easyopt_delay_js_exclude_jquery", label: "Exclude jQuery", bold: true, settings: settings, onChange: onChange, desc: "Keeps jQuery and WordPress core scripts out of the delay/defer queue. Recommended for most sites." }), React.createElement(PopupGrid, { entries: [
            { name: 'easyopt_delay_js_exclude', title: 'Exclude Scripts', help: 'Keywords to exclude from optimization (one per line). Matches against script src and inline content.', placeholder: "jquery.min.js\ngtag/js\nwoocommerce" },
            { name: 'easyopt_delay_js_exclude_urls', title: 'Exclude URLs', help: 'Pages where JS optimization should not run.', placeholder: "/checkout/\n/cart/" },
            ...(settings.easyopt_delay_js_method !== 'defer' ? [{ name: 'easyopt_delay_js_include_inline', title: 'Include Inline Scripts', help: 'Convert matching inline scripts to delayed external files.', placeholder: "gtag\nfbq" }] : []),
        ], cols: settings.easyopt_delay_js_method !== 'defer' ? 3 : 2, settings: settings, onChange: onChange }))));
}
// ═══════════════════════════════════════════════════
//  OPTIMIZATION — CSS sub-tab
// ═══════════════════════════════════════════════════
function CssSubPanel({ settings, onChange }) {
    const uc = Number(settings.easyopt_unused_css) === 1;
    const [cssMsg, setCssMsg] = useState('');
    const clearCSS = () => { setCssMsg('Clearing…'); api.post('/css/clear').then((r) => { setCssMsg(r?.message || 'Done!'); setTimeout(() => setCssMsg(''), 3000); }); };
    const behavior = settings.easyopt_unused_css_behavior || 'delayed';
    const isRemove = behavior === 'remove';
    const postTypesOn = !isRemove && Number(settings.easyopt_unused_css_post_types_only) === 1;
    // "Process Post Types Only" only makes sense for the Delay/Async behaviours
    // (they keep the originals as a fallback). With Remove there is no fallback,
    // so sharing one Used CSS across a whole post type is unsafe — force it off.
    const onBehaviorChange = (name, value) => {
        onChange(name, value);
        if (value === 'remove')
            onChange('easyopt_unused_css_post_types_only', '0');
    };
    return (React.createElement("div", null, React.createElement("h2", { className: "eop-section-h" }, "Remove Unused CSS"), React.createElement("label", null, React.createElement("input", { type: "checkbox", id: "easyopt_minify_css", name: "easyopt_minify_css", value: "1", checked: Number(settings.easyopt_minify_css) === 1, onChange: () => onChange('easyopt_minify_css', Number(settings.easyopt_minify_css) === 1 ? '0' : '1') }), " ", React.createElement("strong", null, "Minify CSS")), React.createElement("p", { className: "description" }, "Minifies all local CSS stylesheets."), React.createElement("label", { style: { marginTop: '16px' } }, React.createElement("input", { type: "checkbox", id: "easyopt_unused_css", name: "easyopt_unused_css", value: "1", checked: uc, onChange: () => onChange('easyopt_unused_css', uc ? '0' : '1') }), " ", React.createElement("strong", null, "Enable Remove Unused CSS")), React.createElement("p", { className: "description" }, "Strips unused selectors per page type and injects only the used CSS."), React.createElement(Collapsible, { title: "How does Unused CSS removal work?" }, React.createElement("p", null, "WordPress themes and plugins load CSS for every feature they offer, even features not used on a given page. A typical page may load 300\u2013500 KB of CSS when only 30\u201350 KB is actually used. This module analyzes each page type (homepage, single posts, archives, etc.), identifies which CSS selectors are used in the HTML, and generates a slim \"used CSS\" file containing only those rules."), React.createElement("p", null, React.createElement("strong", null, "Inline"), " embeds the used CSS directly in the page — fastest for many sites. ", React.createElement("strong", null, "File"), " saves it as a separate cacheable .css file — better for large sites where the CSS exceeds ~500 KB, but may cause layout shift."), React.createElement("p", null, React.createElement("strong", null, "Stylesheet Behavior"), " controls what happens to the original full stylesheets:"), React.createElement("ul", { style: { margin: '8px 0', paddingLeft: '20px', fontSize: '13px' } }, React.createElement("li", null, React.createElement("strong", null, "Delay"), " — loads originals on user interaction (safest fallback)"), React.createElement("li", null, React.createElement("strong", null, "Async"), " — loads originals non-render-blocking same as Critical CSS"), React.createElement("li", null, React.createElement("strong", null, "Remove"), " — permanently strips originals (\u26A0 may break JS-injected styles)"))), React.createElement("div", { className: "unusedcssfield", style: !uc ? { display: 'none' } : {} }, React.createElement(SelectField, { name: "easyopt_unused_css_method", label: "CSS Delivery Method", settings: settings, onChange: onChange, options: [{ value: 'inline', label: 'Inline — fastest (embeds the CSS in the page)' }, { value: 'file', label: 'File (external .css file)' }] }), React.createElement(SelectField, { name: "easyopt_unused_css_behavior", label: "Stylesheet Behavior", settings: settings, onChange: onBehaviorChange, options: [{ value: 'delayed', label: 'Delay (load on interaction — safest)' }, { value: 'async', label: 'Async (non-render-blocking - same as critical CSS)' }, { value: 'remove', label: 'Remove (strip originals — ⚠ advanced)' }] }), isRemove && (React.createElement("div", { className: "eop-dep-warning", style: { borderColor: 'var(--eop-danger)' } }, React.createElement(Icon, { id: "warn", cls: "eop-icon eop-icon--sm" }), React.createElement("span", { style: { color: 'var(--eop-danger)' } }, "Warning: \"Remove\" permanently strips original stylesheets. Only the generated used CSS will load. Sites with JS-injected styles or dynamic class changes may break. Test thoroughly."))), React.createElement("div", { className: "easyopt-field-row" }, React.createElement("label", { style: isRemove ? { opacity: 0.5 } : {} }, React.createElement("input", { type: "checkbox", name: "easyopt_unused_css_post_types_only", value: "1", checked: postTypesOn, disabled: isRemove, onChange: () => onChange('easyopt_unused_css_post_types_only', postTypesOn ? '0' : '1') }), React.createElement("strong", null, "Process Post Types Only")), React.createElement("p", { className: "description" }, "Pages are skipped. Posts, Products, CPTs share one CSS per type. Enable this if you have many Posts, Categories, Tags, etc. — one Used CSS is shared across all Posts, Products, CPTs of that type. ", isRemove ? React.createElement("em", null, "(Disabled for the \"Remove\" behavior — per-page processing is required when originals are stripped.)") : null)), React.createElement(PopupGrid, { entries: [
            { name: 'easyopt_unused_css_exclude_selectors', title: 'Exclude Selectors', help: 'CSS selectors to keep regardless of usage.', placeholder: ".my-class\n#my-id" },
            { name: 'easyopt_unused_css_exclude_stylesheets', title: 'Exclude Stylesheets', help: 'Stylesheet handles or src fragments to skip. Also used by Minify CSS.', placeholder: "dashicons.min.css" },
            { name: 'easyopt_unused_css_exclude_urls', title: 'Exclude URLs', help: 'Pages where unused-CSS processing should not run.', placeholder: "/checkout/" },
            { name: 'easyopt_unused_css_include_inline', title: 'Include Inline Styles', help: 'Convert matching inline style tags to external files.', placeholder: "wp-custom-css" },
            { name: 'easyopt_unused_css_passthrough_stylesheets', title: 'Keep Whole Stylesheets', help: 'Page-builder CSS that is already page-specific. Included in full, without removing unused selectors \u2014 safer for builders that add classes with JavaScript. /et-cache/ (Divi) is included by default.', placeholder: "/uploads/elementor/css/post-" },
        ], cols: 3, settings: settings, onChange: onChange }), React.createElement("div", { className: "easyopt-field-row", style: { marginTop: '10px' } }, React.createElement("button", { type: "button", className: "button button-secondary", onClick: clearCSS }, "Clear Used CSS Cache"), React.createElement("span", { style: { marginLeft: '10px', color: 'green' } }, cssMsg)))));
}
// ═══════════════════════════════════════════════════
//  OPTIMIZATION — LAZYLOAD sub-tab
// ═══════════════════════════════════════════════════
function LazySubPanel({ settings, onChange }) {
    const li = Number(settings.easyopt_lazy_images) === 1;
    const lf = Number(settings.easyopt_lazy_iframes) === 1;
    const lv = Number(settings.easyopt_lazy_videos) === 1;
    const anyLazy = li || lf || lv;
    const ad = Number(settings.easyopt_add_missing_dims) === 1;
    const ln = Number(settings.easyopt_lazy_native ?? 1) === 1;
    return (React.createElement("div", null, React.createElement("h2", { className: "eop-section-h" }, "Lazy Load"), React.createElement("p", { className: "description", style: { marginBottom: '14px' } }, "Defer loading of offscreen media so the browser focuses on visible content first. Reduces initial page weight and speeds up Largest Contentful Paint."), React.createElement(Collapsible, { title: "How does lazy loading improve performance?" }, React.createElement("p", null, "By default, browsers download every image, iframe, and video on a page before displaying it — even images far below the fold that the visitor may never scroll to. Lazy loading tells the browser to only download media when it's about to enter the viewport (visible area). This reduces initial bandwidth, speeds up rendering, and improves Core Web Vitals — especially LCP and Speed Index."), React.createElement("p", null, "The \"Exclude First N Images\" setting is important: images visible immediately (your hero image, logo) should NOT be lazy-loaded because they're needed for the initial paint. Setting this to 1\u20133 ensures above-the-fold images load eagerly.")), React.createElement("label", null, React.createElement("input", { type: "checkbox", className: "eop-lazy-toggle", name: "easyopt_lazy_images", value: "1", checked: li, onChange: () => onChange('easyopt_lazy_images', li ? '0' : '1') }), " ", React.createElement("strong", null, "Lazy Load Images")), React.createElement("p", { className: "description" }, "Defers offscreen images and background images."), React.createElement("div", { className: "lazyfield", style: !li ? { display: 'none' } : { marginBottom: '10px' } }, React.createElement("label", null, React.createElement("input", { type: "checkbox", name: "easyopt_lazy_native", value: "1", checked: ln, onChange: () => onChange('easyopt_lazy_native', ln ? '0' : '1') }), " ", React.createElement("strong", null, "Browser-native lazy load"), " ", React.createElement("span", { className: "eop-badge" }, "Recommended")), React.createElement("p", { className: "description" }, "Lazy loads images using the browser's built-in method — faster and needs no extra JavaScript.")), React.createElement("label", null, React.createElement("input", { type: "checkbox", className: "eop-lazy-toggle", name: "easyopt_lazy_iframes", value: "1", checked: lf, onChange: () => onChange('easyopt_lazy_iframes', lf ? '0' : '1') }), " ", React.createElement("strong", null, "Lazy Load Iframes")), React.createElement("p", { className: "description" }, "Defers iframes (YouTube, maps, etc.)."), React.createElement("label", null, React.createElement("input", { type: "checkbox", className: "eop-lazy-toggle", name: "easyopt_lazy_videos", value: "1", checked: lv, onChange: () => onChange('easyopt_lazy_videos', lv ? '0' : '1') }), " ", React.createElement("strong", null, "Lazy Load Videos")), React.createElement("div", { className: "lazyfield", style: !anyLazy ? { display: 'none' } : {} }, React.createElement(PopupField, { name: "easyopt_lazyload_exclude", title: "Exclude from Lazy Load", help: "Class names or URL fragments to skip.", placeholder: "logo\nhero-image", value: settings.easyopt_lazyload_exclude, onChange: onChange }), React.createElement("div", { className: "easyopt-field-row" }, React.createElement("label", null, React.createElement("strong", null, "Exclude First N Images")), React.createElement("input", { type: "number", name: "easyopt_lazyload_exclude_first", value: settings.easyopt_lazyload_exclude_first ?? 1, onChange: (e) => onChange('easyopt_lazyload_exclude_first', e.target.value), min: "0", step: "1", style: { width: '80px' } }), React.createElement("p", { className: "description", style: { marginTop: '4px' } }, "Images above the fold should not be lazy-loaded. Set to the number of images visible without scrolling."))), React.createElement("div", { style: { marginTop: '24px', paddingTop: '18px', borderTop: '1px solid var(--eop-border-soft)' } }, React.createElement("label", null, React.createElement("input", { type: "checkbox", name: "easyopt_add_missing_dims", value: "1", checked: ad, onChange: () => onChange('easyopt_add_missing_dims', ad ? '0' : '1') }), " ", React.createElement("strong", null, "Add Missing Image Dimensions")), React.createElement("p", { className: "description" }, "Reads actual image files to add missing width/height attributes. Prevents CLS (Cumulative Layout Shift)."), React.createElement("div", { className: "adddimsfield", style: !ad ? { display: 'none' } : {} }, React.createElement(PopupField, { name: "easyopt_dims_exclude", title: "Exclude from Missing Dimensions", help: "Class names, src fragments to skip.", placeholder: "logo\nsvg-icon", value: settings.easyopt_dims_exclude, onChange: onChange })))));
}
// ═══════════════════════════════════════════════════
//  Small REST-backed "clear data" button (transient feedback)
// ═══════════════════════════════════════════════════
function EopClearButton({ label, path, busyLabel, doneLabel, confirm }) {
    const [st, setSt] = useState('idle'); // idle | busy | done | error
    const [msg, setMsg] = useState('');
    const onClick = () => {
        if (st === 'busy')
            return;
        if (confirm && !window.confirm(confirm))
            return;
        setSt('busy');
        setMsg('');
        api.post(path).then((res) => {
            const ok = !!(res && res.success);
            setSt(ok ? 'done' : 'error');
            setMsg((res && res.message) ? res.message : (ok ? '' : 'Request failed.'));
            setTimeout(() => { setSt('idle'); setMsg(''); }, ok ? 3000 : 4500);
        }).catch(() => {
            setSt('error');
            setMsg('Request failed.');
            setTimeout(() => { setSt('idle'); setMsg(''); }, 4500);
        });
    };
    const txt = st === 'busy' ? (busyLabel || 'Clearing…')
        : st === 'done' ? (doneLabel || 'Cleared \u2713')
            : st === 'error' ? 'Failed — retry'
                : label;
    return (React.createElement("div", { style: { marginTop: '12px', display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' } }, React.createElement("button", {
        type: "button",
        className: "button button-secondary",
        onClick: onClick,
        disabled: st === 'busy',
    }, txt), msg && React.createElement("span", {
        className: "description",
        style: { margin: 0, color: st === 'error' ? 'var(--eop-danger)' : 'var(--eop-success)' }
    }, msg)));
}
// ═══════════════════════════════════════════════════
//  OPTIMIZATION — PRELOAD LCP sub-tab
// ═══════════════════════════════════════════════════
function LcpSubPanel({ settings, onChange }) {
    const on = Number(settings.easyopt_lcp_preload) === 1;
    return (React.createElement("div", null, React.createElement("h2", { className: "eop-section-h" }, "LCP Optimization"), React.createElement("label", null, React.createElement("input", { type: "checkbox", id: "easyopt_lcp_preload", name: "easyopt_lcp_preload", value: "1", checked: on, onChange: () => onChange('easyopt_lcp_preload', on ? '0' : '1') }), " ", React.createElement("strong", null, "Auto Preload Largest Image")), React.createElement("p", { className: "description" }, "Finds each page's largest image (the one visitors notice first) and preloads it at high priority."), React.createElement(EopClearButton, {
        label: "Clear all LCP data",
        path: "/lcp/clear",
        busyLabel: "Clearing…",
        doneLabel: "LCP data cleared \u2713",
        confirm: "Clear all detected LCP images for every page type? They will be re-detected on the next visits."
    }), React.createElement(Collapsible, { title: "How does LCP detection work?" }, React.createElement("p", null, "The Largest Contentful Paint (LCP) is the largest visible image or text block in the viewport — it's one of Google's three Core Web Vitals. This module uses a lightweight JavaScript beacon (PerformanceObserver) to detect which image is the LCP element on each page type."), React.createElement("p", null, React.createElement("strong", null, "First visit:"), " The beacon observes the page and reports the LCP image back to the server. This is the \"training\" visit — no optimization happens yet."), React.createElement("p", null, React.createElement("strong", null, "Subsequent visits:"), " A ", React.createElement("code", null, "<link rel=\"preload\">"), " tag with ", React.createElement("code", null, "fetchpriority=\"high\""), " is injected into <head> for the detected LCP image, and the matching <img> tag gets ", React.createElement("code", null, "loading=\"eager\""), ". Results are stored per URL type and viewport (mobile/desktop) with a 30-day refresh cycle.")), React.createElement("div", { className: "lcppreloadfield", style: !on ? { display: 'none' } : {} }, React.createElement(PopupField, { name: "easyopt_lcp_exclude_urls", title: "Exclude URLs", help: "Pages where LCP detection should not run.", placeholder: "/checkout/\n/cart/", value: settings.easyopt_lcp_exclude_urls, onChange: onChange }))));
}
// ═══════════════════════════════════════════════════
//  OPTIMIZATION — PREFETCH PAGES sub-tab
// ═══════════════════════════════════════════════════
function PrefetchSubPanel({ settings, onChange }) {
    const on = Number(settings.easyopt_instant_preload) === 1;
    return (React.createElement("div", null, React.createElement("h2", { className: "eop-section-h" }, "Prefetch Pages"), React.createElement("p", { className: "description", style: { marginTop: 0 } }, "Start loading the next page while the visitor is still deciding, so the click feels instant."), React.createElement(Collapsible, { title: "How does page prefetching work?" }, React.createElement("p", null, "Easy Optimizer writes a small set of ", React.createElement("strong", null, "speculation rules"), " into the top of every page. The browser reads them while it is still parsing the HTML, then watches the visitor and decides for itself when to start loading a link — including how many pages it can afford to load at once on this device and connection."), React.createElement("p", null, "On top of that, Easy Optimizer makes its own predictions and tells the browser to start immediately. Once the page is idle it finds the destination your page links to most often — the one in your header, hero button and footer — and starts that one with no interaction at all. It also watches for a “Next page” link, a dropdown or mobile menu opening, the pointer coming to rest near a link, and — on phones, where there is no hover — scrolling coming to a stop with a link in the middle of the screen. Predictions are capped at four pages per visit, so this never turns into bulk downloading."), React.createElement("p", null, "No JavaScript scans your links, and nothing is downloaded up front. A page with 30 links costs what a page with 10 links costs."), React.createElement("p", null, React.createElement("strong", null, "Prerender"), " goes further: the browser builds the entire page in the background, so the click is instant rather than merely fast. It is much more expensive — a full page load, including scripts and images — so it is off by default and only worth turning on if you have measured that it helps your site."), React.createElement("p", null, React.createElement("strong", null, "Eagerness"), " decides how early the cheap prefetch starts:"), React.createElement("ul", { style: { margin: '6px 0', paddingLeft: '20px', fontSize: '13px' } }, React.createElement("li", null, React.createElement("strong", null, "Conservative"), " — only when the mouse button goes down, just before the click. Predictions are switched off."), React.createElement("li", null, React.createElement("strong", null, "Moderate"), " — after a short hover or on keyboard focus, plus predictions (recommended)"), React.createElement("li", null, React.createElement("strong", null, "Eager"), " — as soon as the browser sees the link (uses more bandwidth)")), React.createElement("p", null, "Links in your footer are always treated as low-intent and stay on pointer-down, whichever setting you pick. Cart, checkout, account, feed, admin, download and query-string URLs are never speculated, and neither are links you exclude below."), React.createElement("p", null, "While this is on, Easy Optimizer replaces WordPress's own built-in speculative loading so a page never carries two competing rule sets. Turn it off and WordPress goes back to handling it.")), React.createElement("label", { style: { display: 'inline-flex', alignItems: 'center', gap: '8px', marginTop: '6px' } }, React.createElement("input", { type: "checkbox", id: "easyopt_instant_preload", name: "easyopt_instant_preload", value: "1", checked: on, onChange: () => onChange('easyopt_instant_preload', on ? '0' : '1') }), React.createElement("strong", null, "Enable Page Prefetching")), React.createElement("div", { className: "instantfield", style: !on ? { display: 'none' } : { marginTop: '18px' } }, React.createElement(PopupGrid, { entries: [
            { name: 'easyopt_instant_preload_exclude_urls', title: 'Exclude URLs', help: 'URLs to never prefetch. One per line. A line without * matches anywhere in the URL; * is a wildcard.', placeholder: "/cart\nprivate/*\nref=" },
            { name: 'easyopt_instant_preload_exclude_selectors', title: 'Exclude CSS Selectors', help: 'CSS selectors for links to skip. One per line.', placeholder: "a.no-prefetch\n[data-no-prefetch]" },
        ], cols: 2, settings: settings, onChange: onChange }), React.createElement(Check, { name: "easyopt_instant_prerender", label: "Prerender (Prefetch the full page in advance)", bold: true, settings: settings, onChange: onChange, desc: "Off by default — builds the whole page in the background, which costs memory and CPU. Chromium only; Safari and Firefox keep plain prefetching." }), React.createElement(SelectField, { name: "easyopt_instant_eagerness", label: "Eagerness", settings: settings, onChange: onChange, options: [
            { value: 'conservative', label: 'Conservative — pointer down only, no predictions' },
            { value: 'moderate', label: 'Moderate — short hover, focus and predictions (recommended)' },
            { value: 'eager', label: 'Eager — as soon as a link is seen (more bandwidth)' },
        ] }))));
}
// ═══════════════════════════════════════════════════
//  OPTIMIZATION PANEL — wrapper with sub-tabs
// ═══════════════════════════════════════════════════
function OptimizationPanel({ settings, onChange }) {
    const [sub, setSub] = useState(() => {
        const stored = localStorage.getItem('easyopt_opt_sub');
        return OPT_SUBTABS.find(t => t.key === stored) ? stored : 'js';
    });
    const switchSub = (k) => { setSub(k); localStorage.setItem('easyopt_opt_sub', k); };
    return (React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-optimization" }, React.createElement(SubTabNav, { tabs: OPT_SUBTABS, active: sub, onChange: switchSub }), React.createElement("div", { style: { marginTop: '20px' } }, sub === 'js' && React.createElement(JsSubPanel, { settings: settings, onChange: onChange }), sub === 'css' && React.createElement(CssSubPanel, { settings: settings, onChange: onChange }), sub === 'lazy' && React.createElement(LazySubPanel, { settings: settings, onChange: onChange }), sub === 'lcp' && React.createElement(LcpSubPanel, { settings: settings, onChange: onChange }), sub === 'prefetch' && React.createElement(PrefetchSubPanel, { settings: settings, onChange: onChange }))));
}
// ═══════════════════════════════════════════════════
//  FONTS PANEL — with dependency check
// ═══════════════════════════════════════════════════
function FontsPanel({ settings, onChange, switchTab }) {
    const fs = Number(settings.easyopt_font_display_swap) === 1;
    const lf = Number(settings.easyopt_lazyload_fonts) === 1;
    const unusedCssOn = Number(settings.easyopt_unused_css) === 1;
    return (React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-fonts" }, React.createElement("h2", null, "Font Optimization"), React.createElement("label", null, React.createElement("input", { type: "checkbox", name: "easyopt_font_display_swap", value: "1", checked: fs, onChange: () => onChange('easyopt_font_display_swap', fs ? '0' : '1') }), " ", React.createElement("strong", null, "Force Font Display Swap")), React.createElement("p", { className: "description" }, "Adds font-display: swap to all @font-face declarations. Prevents invisible text while fonts load."), React.createElement(Collapsible, { title: "What is font-display: swap?" }, React.createElement("p", null, "When a web font hasn't loaded yet, browsers can either show invisible text (FOIT — Flash of Invisible Text) or show a fallback system font (FOUT — Flash of Unstyled Text). The ", React.createElement("code", null, "font-display: swap"), " rule tells the browser to immediately show text in a fallback font, then swap to the web font once it's ready. Google Lighthouse specifically recommends this for better perceived performance.")), React.createElement("div", { style: { marginTop: '14px' } }, React.createElement(Check, { name: "easyopt_preload_fonts", label: "Smart Preload Fonts", bold: true, settings: settings, onChange: onChange, disabled: !unusedCssOn, desc: "Detects the above-the-fold fonts each page actually uses (via a one-time visitor beacon)." }), React.createElement(Check, { name: "easyopt_lazyload_fonts", label: "Smart Lazyload Fonts", bold: true, settings: settings, onChange: onChange, disabled: !unusedCssOn, desc: "Defers below-the-fold fonts; keeps only above-the-fold ones in critical CSS." }), !unusedCssOn && (React.createElement(DepWarning, { message: "Smart Preload Fonts and Smart Lazyload Fonts both build on the used-CSS engine, so they require Remove Unused CSS to be enabled. Turn it on to use these options.", linkLabel: "Go to CSS settings \u2192", onLink: () => switchTab('optimization') }))), React.createElement(EopClearButton, {
        label: "Clear fonts data",
        path: "/fonts/clear",
        busyLabel: "Clearing…",
        doneLabel: "Fonts data cleared \u2713",
        confirm: "Clear the collected above-the-fold fonts for every page type? They will be re-collected on the next visits."
    }), React.createElement("div", { style: !lf ? { display: 'none' } : {} }, React.createElement(PopupGrid, { entries: [
            { name: 'easyopt_fonts_exclude', title: 'Exclude Fonts', help: 'Font families or URLs to keep in Used CSS (one per line).', placeholder: "font-awesome\nicons" },
            { name: 'easyopt_fonts_exclude_urls', title: 'Exclude URLs', help: 'Pages where font optimization should not run.', placeholder: "/my-custom-page/\n/landing/" },
        ], cols: 2, settings: settings, onChange: onChange }))));
}
// ═══════════════════════════════════════════════════
//  IMAGE CDN PANEL
// ═══════════════════════════════════════════════════
// ═══════════════════════════════════════════════════
//  ADAPTIVE IMAGES PANEL (FluxPress v3 — shared endpoint)
// ═══════════════════════════════════════════════════
//  Renders the analyze → email → live funnel. Falls back to the original
//  ImageCdnPanel (below, unchanged) whenever a legacy v2.5 connection
//  exists, so an already-connected site sees exactly the panel it has today.
function fmtBytes(b) {
    b = Number(b) || 0;
    if (b >= 1e9)
        return (b / 1e9).toFixed(2) + ' GB';
    if (b >= 1e6)
        return (b / 1e6).toFixed(1) + ' MB';
    return Math.max(0, Math.round(b / 1e3)) + ' KB';
}
// (Phase 1) LOCAL IMAGE OPTIMIZATION
// Runs entirely on this server: no account, no bandwidth, no limits. Sits
// above the cloud panel because it works for everyone, including people who
// will never connect FluxPress.
//
// The capability line is deliberately blunt. A host without libavif cannot
// produce AVIF no matter what the user ticks, and saying so plainly is both
// honest and the most persuasive upgrade argument we have.
// (2.7.0) SMART IMAGES — variant B, the engine picker.
//
// One job — optimize this site's images — with a choice of WHERE it runs:
// on this server for free, or on FluxPress for per-device sizes and edge
// delivery. The segmented control is the whole idea; everything below it
// reconfigures to the chosen engine.
function SmartImagesPanel({ settings, onChange, applyServerSettings }) {
    // The picker defaults to whichever engine is actually in use: if the
    // account is delivering, Cloud; otherwise This server. A stored choice
    // wins so a deliberate switch survives a reload.
    const cloudLive = (typeof CFG === 'function') && CFG().cloudDelivering;
    const [engine, setEngine] = React.useState(() => {
        try {
            return localStorage.getItem('eopImgEngine') || (cloudLive ? 'cloud' : 'server');
        }
        catch (e) {
            return cloudLive ? 'cloud' : 'server';
        }
    });
    const pick = (e) => {
        setEngine(e);
        try {
            localStorage.setItem('eopImgEngine', e);
        }
        catch (err) { /* private mode */ }
    };
    return React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-imgopt" }, React.createElement("h2", null, "Image Optimization"), React.createElement("p", { className: "description", style: { marginTop: 0 } }, "Optimize every image on your site. Choose where the work happens \u2014 everything else stays the same."), React.createElement("div", { className: "eop-seg", role: "tablist", style: { margin: '16px 0 22px' } }, React.createElement("button", { type: "button", role: "tab", className: "eop-seg__btn " + (engine === 'server' ? 'is-on' : ''),
        "aria-selected": engine === 'server' ? 'true' : 'false', onClick: () => pick('server') }, React.createElement(Icon, { id: 'gauge', cls: "eop-icon eop-icon--xs" }), React.createElement("span", null, "This server"), React.createElement("span", { className: "eop-seg__tag" }, "Free")), React.createElement("button", { type: "button", role: "tab", className: "eop-seg__btn " + (engine === 'cloud' ? 'is-on' : ''),
        "aria-selected": engine === 'cloud' ? 'true' : 'false', onClick: () => pick('cloud') }, React.createElement(Icon, { id: 'cloud', cls: "eop-icon eop-icon--xs" }), React.createElement("span", null, "Smart Images"), React.createElement("span", { className: "eop-seg__tag" }, "Pro"))), engine === 'server'
        ? React.createElement(LocalImageEngine, { settings: settings, onChange: onChange })
        : React.createElement(AdaptiveImagesPanel, { settings: settings, onChange: onChange, applyServerSettings: applyServerSettings, embedded: true }));
}
// LOCAL ENGINE — the "This server" side of the picker. Real-time: while a
// bulk run is in flight it polls /images/stats so the count and the bar move
// on their own, the way every other optimizer people have used does. The
// poll is the difference between "I clicked a button and nothing happened"
// and "I can watch it work".
function LocalImageEngine({ settings, onChange }) {
    const [stats, setStats] = React.useState(null);
    const [busy, setBusy] = React.useState('');
    const timer = React.useRef(null);
    const on = Number(settings.easyopt_images) === 1;
    const load = React.useCallback(() => {
        return api.get('/images/stats').then((r) => { if (r && r.success) {
            setStats(r);
        } return r; });
    }, []);
    // Poll only while there is live work. Each tick reschedules itself, and
    // it stops the moment the queue drains — no fixed interval left running
    // on an idle panel, no request storm.
    const poll = React.useCallback(() => {
        load().then((r) => {
            if (r && r.running) {
                timer.current = window.setTimeout(poll, 2000);
            }
            else {
                timer.current = null;
            }
        });
    }, [load]);
    React.useEffect(() => {
        load().then((r) => { if (r && r.running) {
            poll();
        } });
        return () => { if (timer.current) {
            window.clearTimeout(timer.current);
        } };
    }, [load, poll]);
    const canAvif = !!stats && (stats.formats || []).indexOf('avif') !== -1;
    const canWebp = !!stats && (stats.formats || []).indexOf('webp') !== -1;
    const noFormats = !!stats && (stats.formats || []).length === 0;
    const act = (path, label) => {
        setBusy(label);
        api.post(path, {}).then(() => { setBusy(''); load().then((r) => { if (r && r.running) {
            poll();
        } }); })
            .catch(() => setBusy(''));
    };
    const qualityOpts = (lo, hi) => {
        const out = [];
        for (let v = hi; v >= lo; v -= 5) {
            out.push({ value: String(v), label: v === hi ? (v + ' \u2014 best quality') : (v === lo ? (v + ' \u2014 smallest files') : String(v)) });
        }
        return out;
    };
    const total = stats ? Number(stats.total) : 0;
    const done = stats ? Number(stats.optimized) : 0;
    const pending = stats ? Number(stats.pending || 0) : 0;
    const failed = stats ? Number(stats.failed || 0) : 0;
    const skipped = stats ? Number(stats.skipped || 0) : 0;
    const running = !!(stats && stats.running);
    const pct = total > 0 ? Math.round(done / total * 100) : 0;
    return React.createElement("div", null, stats && React.createElement("div", { className: "eop-callout", style: noFormats ? { borderLeft: '3px solid var(--eop-warn, #dba617)', marginBottom: '16px' } : { marginBottom: '16px' } }, React.createElement("p", { style: { margin: 0, fontSize: '13px' } }, React.createElement("strong", null, noFormats ? 'Limited on this host: ' : 'This host supports: '), stats.capability)), React.createElement(Check, { name: "easyopt_images", label: "Optimize images on this server", bold: true, settings: settings, onChange: onChange, disabled: noFormats,
        desc: noFormats ? "Nothing to enable until this host can encode WebP or AVIF." : "AVIF and WebP copies are made next to your originals, which are never modified." }), on && stats && React.createElement("div", { className: "eop-imgstat", style: { marginTop: '18px' } }, 
    // Live headline. During a run it reads "Optimizing N of M"; at
    // rest it reads how many are done and what was saved.
    React.createElement("div", { className: "eop-imgstat__head" }, React.createElement("div", null, running
        ? React.createElement("span", null, React.createElement("span", { className: "eop-imgstat__spin" }), " Optimizing \u2014 ", React.createElement("strong", null, nf(done) + " of " + nf(total)), " done, ", nf(pending), " to go")
        : React.createElement("span", null, React.createElement("strong", null, nf(done) + " of " + nf(total) + " images"), " optimized", stats.saved > 0 ? React.createElement("span", null, " \u00b7 ", React.createElement("strong", null, stats.saved_human), " saved") : null)), React.createElement("div", { className: "eop-imgstat__pct" }, pct + "%")), React.createElement("div", { className: "eop-imgstat__bar" }, React.createElement("div", { className: "eop-imgstat__fill" + (running ? " is-running" : ""), style: { width: pct + '%' } })), (skipped + failed) > 0 && React.createElement("p", { className: "description", style: { margin: '8px 0 0', color: 'var(--eop-warn-fg, #92400e)' } }, nf(skipped + failed) + " couldn't be optimized (unsupported, animated, too large, or already small) and were skipped."), React.createElement("p", { style: { margin: '14px 0 0' } }, React.createElement("button", { type: "button", className: "button button-primary", disabled: !!busy || running || stats.remaining === 0, onClick: () => act('/images/optimize', 'optimize') }, running ? 'Optimizing\u2026' : (stats.remaining > 0 ? 'Optimize ' + nf(stats.remaining) + ' remaining' : 'All images optimized')), React.createElement("button", { type: "button", className: "button", style: { marginLeft: '8px' }, disabled: !!busy || running || done === 0,
        onClick: () => { if (window.confirm('Delete every optimized copy and restore your originals? Your originals were never modified, so nothing is lost.')) {
            act('/images/restore', 'restore');
        } } }, busy === 'restore' ? 'Queueing\u2026' : 'Restore all'), !running && React.createElement("button", { type: "button", className: "button", style: { marginLeft: '8px' }, onClick: () => load() }, "Refresh")), React.createElement("p", { className: "description", style: { margin: '8px 0 0' } }, running ? "Live \u2014 this updates on its own. You can leave the page; it keeps going." : "Runs in the background. You can leave this page.")), on && React.createElement("div", { style: { marginTop: '20px' } }, React.createElement(Check, { name: "easyopt_images_auto", label: "Optimize new uploads automatically", settings: settings, onChange: onChange, desc: "Queues each image as it is uploaded." }), React.createElement(Check, { name: "easyopt_images_avif", label: "Generate AVIF", settings: settings, onChange: onChange, disabled: !canAvif, desc: canAvif ? "Smallest files. Supported by every current browser." : "Unavailable \u2014 this host's image library was built without AVIF support." }), React.createElement(Check, { name: "easyopt_images_webp", label: "Generate WebP", settings: settings, onChange: onChange, disabled: !canWebp, desc: canWebp ? "Broader support on older browsers." : "Unavailable on this host." }), React.createElement(Check, { name: "easyopt_images_picture", label: "Serve optimized images", settings: settings, onChange: onChange, desc: "Wraps images in a <picture> element so each browser picks the best format. Off keeps generating copies without serving them." }), React.createElement("div", { style: { marginTop: '18px' } }, canAvif && React.createElement(SelectField, { name: "easyopt_images_quality_avif", label: "AVIF quality", settings: settings, onChange: onChange, options: qualityOpts(40, 75) }), canWebp && React.createElement(SelectField, { name: "easyopt_images_quality_webp", label: "WebP quality", settings: settings, onChange: onChange, options: qualityOpts(60, 90) })), React.createElement(Collapsible, { title: "Advanced" }, React.createElement(SelectField, { name: "easyopt_images_max_dimension", label: "Maximum image dimension", settings: settings, onChange: onChange, options: [
            { value: '0', label: "Don't resize (recommended)" },
            { value: '2560', label: '2560px \u2014 full-width hero images' },
            { value: '1920', label: '1920px \u2014 standard desktop width' },
            { value: '1280', label: '1280px \u2014 smaller sites' },
        ] }), React.createElement(Check, { name: "easyopt_images_resize_originals", label: "Also shrink existing oversized originals", settings: settings, onChange: onChange, desc: "\u26a0 The only setting that modifies your uploaded files. A backup is saved first; Restore all puts every original back." }), React.createElement(SelectField, { name: "easyopt_images_core_format", label: "Ask WordPress to save uploads as", settings: settings, onChange: onChange, options: [
            { value: '', label: 'Leave WordPress alone (recommended)' },
            { value: 'webp', label: 'WebP' },
        ] }), React.createElement("p", { className: "description" }, "WordPress 7.1+ can convert uploads in the browser. This tells it which format \u2014 new uploads only, on supporting browsers; everything else is handled here."))));
}
// (2.7.0) FLUXPRESS SERVICE BAR
// Variant J: cloud is not a place you visit, it is an account state that is
// true everywhere. The bar renders on every screen so the trial countdown is
// visible from whichever tab the user happens to be on — a trial nobody can
// see the end of converts nobody, and finding out by noticing images got
// slower is the worst possible way to learn it lapsed.
//
// Reads the cached /cloud/usage payload rather than fetching: the panel that
// owns that request refreshes it, and a bar on every screen must not add a
// request to every screen. Renders NOTHING when disconnected — an empty
// upsell strip on all thirteen tabs is the exact nagging J exists to avoid.
// (2.7.1) CLOUD BAR — slim, on every screen.
//
// Not a usage meter (Freemius owns the trial and its limits now, not us) and
// not the heavy dark strip it was. A slim accent band that states the promise
// and, until connected, offers the trial. When connected it steps back to a
// quiet "active" line so it stops selling something you already bought.
// Open the Freemius Checkout overlay to start the 14-day no-card trial.
// No PHP SDK: checkout.min.js is enqueued on the settings page and exposes
// the global FS. The overlay NEVER returns the licence key to the browser
// (only ids + the buyer's email), so activation still runs through our own
// /cloud/connect with the key Freemius emails the buyer. onComplete fires
// when the trial/purchase completes; if Checkout.js is blocked we fall back
// to the hosted trial page rather than dead-ending.
function openFreemiusCheckout(opts) {
    opts = opts || {};
    const cfg = (CFG() && CFG().checkout) || {};
    const FS = window.FS;
    // Two Checkout.js builds exist and expose FS.Checkout differently: the
    // classic buy-button script makes it an OBJECT with a static .configure(),
    // the newer build makes it a CONSTRUCTOR. Calling `new` on the classic one
    // throws "FS.Checkout is not a constructor" (the 2.7 bug). Detect which we
    // got. plugin_id/product_id both sent so either naming convention binds.
    const params = { product_id: cfg.productId, plugin_id: cfg.productId, plan_id: cfg.planId, public_key: cfg.publicKey };
    let handler = null;
    if (FS && FS.Checkout && typeof FS.Checkout.configure === 'function') {
        handler = FS.Checkout.configure(params);
    }
    else if (FS && typeof FS.Checkout === 'function') {
        handler = new FS.Checkout(params);
    }
    if (!handler || !cfg.productId) {
        // Script blocked, offline, or an unexpected global — fall back to the
        // hosted trial page rather than dead-ending.
        window.open((CFG() && CFG().trialUrl) || 'https://fluxpress.io/pricing', '_blank', 'noopener');
        return;
    }
    handler.open({
        name: 'Easy Optimizer Cloud',
        trial: 'free',
        user_email: (opts.email || '').trim(),
        purchaseCompleted: () => { if (opts.onComplete) {
            opts.onComplete();
        } },
    });
}
function ServiceBar({ switchTab }) {
    const [d, setD] = React.useState(null);
    const [checked, setChecked] = React.useState(false);
    React.useEffect(() => {
        api.get('/cloud/usage').then((r) => { setD(r || {}); setChecked(true); });
    }, []);
    if (!checked) {
        return null;
    }
    const connected = !!(d && d.connected);
    if (connected) {
        const u = (d && d.usage) || {};
        const inactive = Number(u.active || 0) === 0;
        return React.createElement("div", { className: "eop-cloudbar eop-cloudbar--on" }, React.createElement("span", { className: "eop-cloudbar__dot" }), React.createElement("span", { className: "eop-cloudbar__title" }, "Cloud Optimization"), React.createElement("span", { className: "eop-cloudbar__note" }, inactive ? "Connect to activate" : "Active \u00b7 serving from the edge"), React.createElement("span", { style: { flex: 1 } }), React.createElement("button", { type: "button", className: "eop-cloudbar__btn eop-cloudbar__btn--ghost", onClick: () => switchTab('flux') }, "Manage"));
    }
    return React.createElement("div", { className: "eop-cloudbar" }, React.createElement("span", { className: "eop-cloudbar__spark" }, "\u26a1"), React.createElement("span", { className: "eop-cloudbar__title" }, "Cloud Optimization"), React.createElement("span", { className: "eop-cloudbar__pitch" }, "Guaranteed 90+ PageSpeed scores"), React.createElement("span", { style: { flex: 1 } }), React.createElement("button", { type: "button", className: "eop-cloudbar__btn eop-cloudbar__btn--ghost", onClick: () => switchTab('flux') }, "Learn more"), React.createElement("button", { type: "button", className: "eop-cloudbar__btn eop-cloudbar__btn--solid", onClick: () => switchTab('flux') }, "Try Free Now"));
}
// (2.7.1) CLOUD OPTIMIZATION — the account screen (variant J).
//
// The whole feature map is shown whether or not an account is connected, so a
// visitor can see exactly what the cloud covers before committing. Until
// connected every toggle is frozen and the trial is started through FREEMIUS,
// not through us — we no longer run our own signup. Connecting a licence key
// (the thing a Freemius trial hands back) is what unfreezes the switches.
function FluxPressPanel({ settings, onChange, switchTab, showToast }) {
    const [d, setD] = React.useState(null);
    const [busy, setBusy] = React.useState(false);
    const [keyVal, setKeyVal] = React.useState('');
    const [msg, setMsg] = React.useState('');
    const load = React.useCallback(() => { api.get('/cloud/usage').then((r) => setD(r || {})); }, []);
    React.useEffect(() => { load(); }, [load]);
    if (!d) {
        return React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-flux" }, React.createElement("h2", null, "Cloud Optimization"), React.createElement("p", { className: "description" }, "Checking your account\u2026"));
    }
    const connected = !!d.connected;
    const u = d.usage || {};
    const inactive = connected && Number(u.active || 0) === 0;
    const live = connected && !inactive;
    const doConnect = () => {
        const k = (keyVal || '').trim();
        if (k.length < 6) {
            setMsg('Paste the licence key from your Easy Optimizer Cloud account.');
            return;
        }
        setBusy(true);
        setMsg('Connecting\u2026');
        api.post('/cloud/connect', { license_key: k }).then((r) => {
            setBusy(false);
            if (r && r.success) {
                setMsg('Connected. Reloading\u2026');
                setTimeout(() => window.location.reload(), 1000);
            }
            else {
                setMsg((r && r.message) || 'That key could not be verified.');
            }
        }).catch(() => { setBusy(false); setMsg('That key could not be verified.'); });
    };
    const disconnect = () => {
        if (!window.confirm('Disconnect Cloud Optimization? Everything goes back to running on your own server. Nothing breaks.')) {
            return;
        }
        setBusy(true);
        api.post('/cloud/disconnect', {}).then(() => window.location.reload()).catch(() => setBusy(false));
    };
    // Every feature is locked until an account is connected; connecting
    // unlocks the toggle. 'cloud' flips an edge-delivery setting, 'local' an
    // on-server optimization, 'soon' is context only, 'outcome' is an X/check.
    const rows = [
        { name: 'Images', kind: 'cloud', toggle: 'easyopt_img_opt',
            local: 'AVIF & WebP at fixed sizes', cloud: 'Per-device sizes from the edge' },
        { name: 'CSS, JS & fonts', kind: 'cloud', toggle: 'easyopt_cloud_assets',
            local: 'Served from your own server', cloud: 'Served from 119 edge locations' },
        { name: 'JavaScript Optimization', kind: 'local',
            on: Number(settings.easyopt_delay_js) === 1,
            set: (v) => { if (v) {
                onChange('easyopt_delay_js', '1');
                onChange('easyopt_delay_js_exclude_jquery', '0');
            }
            else {
                onChange('easyopt_delay_js', '0');
            } },
            local: 'Delay & defer on interaction', cloud: 'Delay Js with faster executions' },
        { name: 'Unused CSS', kind: 'local',
            on: Number(settings.easyopt_unused_css) === 1,
            set: (v) => { if (v) {
                onChange('easyopt_unused_css', '1');
                onChange('easyopt_unused_css_behavior', 'delayed');
                onChange('easyopt_cloud_unused_css', '1');
            }
            else {
                onChange('easyopt_unused_css', '0');
                onChange('easyopt_cloud_unused_css', '0');
            } },
            local: 'Beacon heuristic', cloud: 'Headless render, cross-site learning' },
        { name: 'Font optimization', kind: 'local',
            on: Number(settings.easyopt_lazyload_fonts) === 1,
            set: (v) => onChange('easyopt_lazyload_fonts', v ? '1' : '0'),
            local: 'Preload + swap', cloud: 'Subset + edge-served' },
        { name: 'HTML pages', kind: 'soon', local: 'Local page cache', cloud: 'Full-page edge cache' },
        { name: '90+ scores, mobile & desktop', kind: 'outcome' },
        { name: 'Priority Support', kind: 'outcome' },
    ];
    const band = connected
        ? React.createElement("div", { className: "eop-cloudhero eop-cloudhero--on" }, React.createElement("div", null, React.createElement("div", { className: "eop-cloudhero__row" }, React.createElement("span", { className: "eop-cloudhero__dot" }), React.createElement("strong", null, live ? "Active" : "Connected"), React.createElement("span", { className: "eop-cloudhero__acct" }, " \u00b7 account ", React.createElement("code", null, d.account))), inactive ? React.createElement("p", { className: "description", style: { margin: '8px 0 0' } }, "Your plan is not active. Everything is running on your own server, free.") : null), React.createElement("button", { type: "button", className: "eop-btn eop-btn--ghost", disabled: busy, onClick: disconnect }, "Disconnect"))
        : React.createElement("div", { className: "eop-cloudpitch" }, React.createElement("div", { className: "eop-cloudhero eop-cloudhero--pitch" }, React.createElement("div", { className: "eop-gauge" }, React.createElement("svg", { viewBox: "0 0 132 132", width: "116", height: "116", "aria-hidden": "true" }, React.createElement("circle", { cx: 66, cy: 66, r: 58, fill: "none", stroke: "var(--eop-brand-soft)", strokeWidth: 12 }), React.createElement("circle", { cx: 66, cy: 66, r: 58, fill: "none", stroke: "var(--eop-brand)", strokeWidth: 12, strokeLinecap: "round", strokeDasharray: "364.4", strokeDashoffset: "14.6", transform: "rotate(-90 66 66)" })), React.createElement("div", { className: "eop-gauge__val" }, React.createElement("b", null, "96"), React.createElement("small", null, "Mobile"))), React.createElement("div", { className: "eop-cloudhero__pitchbody" }, React.createElement("h3", { className: "eop-cloudhero__hd eop-cloudhero__hd--lg" }, "Guaranteed 90+ PageSpeed \u2014 or you don't pay."), React.createElement("p", { className: "description", style: { margin: '8px 0 0', maxWidth: '46ch' } }, "Move images, CSS, JS and fonts onto our edge. We tune it until Google scores you 90+ on mobile and desktop."), React.createElement("div", { className: "eop-cloudhero__cta" }, React.createElement("button", { type: "button", className: "eop-btn eop-btn--solid eop-btn--lg", onClick: () => openFreemiusCheckout({ onComplete: () => setMsg('Trial started \ud83c\udf89 Check your email for your licence key, then paste it below.') }) }, "Start 14-day free trial"), React.createElement("span", { className: "eop-cloudhero__trust" }, "No card \u00b7 Cancel anytime")))), React.createElement("div", { className: "eop-cloudstats" }, React.createElement("div", null, React.createElement("b", null, "119"), React.createElement("span", null, "edge locations")), React.createElement("div", null, React.createElement("b", null, "79%"), React.createElement("span", null, "smaller images")), React.createElement("div", null, React.createElement("b", null, "AVIF"), React.createElement("span", null, "per-browser")), React.createElement("div", null, React.createElement("b", null, "0"), React.createElement("span", null, "server load"))));
    return React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-flux" }, React.createElement("h2", null, "Cloud Optimization"), React.createElement("p", { className: "description", style: { marginTop: 0 } }, "One account upgrades features you already have. Anything left off keeps running on your own server, free."), band, React.createElement("table", { className: "eop-flux-tbl", style: { marginTop: '20px' } }, React.createElement("tbody", null, React.createElement("tr", null, React.createElement("th", null, "Feature"), React.createElement("th", null, "On your server"), React.createElement("th", null, "With Cloud"), React.createElement("th", { style: { textAlign: 'right' } }, connected ? "Cloud" : "")), rows.map((r) => React.createElement("tr", { key: r.name, className: r.kind === 'soon' ? 'is-soon' : '' }, React.createElement("td", { className: "eop-flux-tbl__name" }, React.createElement("strong", null, r.name)), r.kind === 'outcome'
        ? React.createElement("td", null, React.createElement("span", { className: "eop-flux-x" }, "\u2715"))
        : React.createElement("td", { className: "eop-flux-tbl__sub" }, r.local), r.kind === 'outcome'
        ? React.createElement("td", null, React.createElement("span", { className: "eop-flux-check" }, "\u2713"))
        : React.createElement("td", { className: "eop-flux-tbl__sub" }, r.cloud), React.createElement("td", { style: { textAlign: 'right' } }, r.kind === 'soon'
        ? React.createElement("span", { className: "eop-flux-pill eop-flux-pill--soon" }, "Soon")
        : r.kind === 'outcome'
            ? null
            : React.createElement("label", { className: "eop-flux-sw" + (live ? "" : " is-frozen"), title: live ? "" : "Connect an account to unlock" }, React.createElement("input", { type: "checkbox",
                checked: r.kind === 'cloud' ? (live && Number(settings[r.toggle]) === 1) : (live && r.on),
                disabled: !live,
                onChange: () => { if (!live) {
                    return;
                } if (r.kind === 'cloud') {
                    onChange(r.toggle, Number(settings[r.toggle]) === 1 ? '0' : '1');
                }
                else {
                    r.set(!r.on);
                } } }), React.createElement("span", { className: "eop-flux-sw__track" }, React.createElement("span", { className: "eop-flux-sw__knob" })), !live ? React.createElement("span", { className: "eop-flux-sw__lock" }, "\ud83d\udd12") : null)))))), React.createElement("p", { className: "description", style: { marginTop: '10px' } }, "Edge delivery unlocks when you connect an account. Guaranteed best possible result."), !connected ? React.createElement("div", { className: "eop-keybox" }, React.createElement("div", { className: "eop-keybox__label" }, "Already started a trial or bought a plan?"), React.createElement("div", { className: "eop-keybox__row" }, React.createElement("input", { type: "text", value: keyVal, placeholder: "Paste your licence key", disabled: busy,
        onChange: (e) => setKeyVal(e.target.value),
        onKeyDown: (e) => { if (e.key === 'Enter') {
            doConnect();
        } },
        className: "eop-keybox__input" }), React.createElement("button", { type: "button", className: "eop-btn eop-btn--solid", disabled: busy, onClick: doConnect }, busy ? "Connecting\u2026" : "Connect")), msg ? React.createElement("p", { className: "description", style: { margin: '8px 0 0' } }, msg) : null) : null);
}
function AdaptiveImagesPanel({ settings, onChange, applyServerSettings }) {
    // A site already on the v2.5 per-zone service keeps its existing panel
    // untouched — the migration is opt-in, not something that happens to
    // someone mid-session.
    const legacyConnected = Number(settings.easyopt_fluxcdn_verified) === 1;
    const [state, setState] = useState('loading');
    const [cloud, setCloud] = useState(null);
    const [analysis, setAnalysis] = useState(null);
    const [email, setEmail] = useState('');
    const [msg, setMsg] = useState('');
    const [msgColor, setMsgColor] = useState('');
    const [busy, setBusy] = useState(false);
    const [showKey, setShowKey] = useState(false);
    const [keyInput, setKeyInput] = useState('');
    const [fallback, setFallback] = useState('');
    const [upgradeUrl, setUpgradeUrl] = useState('');
    const refresh = useCallback(() => {
        api.get('/cloud/usage').then((r) => {
            setCloud(r || null);
            if (r && r.analysis)
                setAnalysis(r.analysis);
            setState(r && r.connected ? 'connected' : (r && r.analysis ? 'analyzed' : 'idle'));
        }).catch(() => setState('idle'));
    }, []);
    useEffect(() => { if (!legacyConnected)
        refresh(); }, [legacyConnected, refresh]);
    const doAnalyze = () => {
        // Remember where we came from. A connected customer re-checking their
        // images must land back on their account panel, not in the signup
        // flow they completed weeks ago.
        const wasConnected = state === 'connected';
        setBusy(true);
        setMsg('');
        setState('analyzing');
        api.post('/cloud/analyze', {}).then((r) => {
            setBusy(false);
            if (r && r.success) {
                setAnalysis(r);
                if (!wasConnected) {
                    setEmail(r.email || '');
                }
                setState(wasConnected ? 'connected' : 'analyzed');
                if (wasConnected) {
                    setMsg('Re-checked: ' + r.pct + '% smaller across ' + ((r.rows || []).length) + ' images.');
                    setMsgColor('var(--eop-success)');
                }
            }
            else {
                // (2.6.1) Drop the previous result. Leaving it on screen put
                // "96% smaller across 4 images" directly above "we could not
                // find any images to measure" — two contradictory statements
                // about the same click, the stale one looking authoritative.
                setAnalysis(null);
                setState(wasConnected ? 'connected' : 'idle');
                setMsg((r && r.message) || 'The analysis could not be completed.');
                setMsgColor('var(--eop-danger)');
            }
        }).catch(() => {
            setBusy(false);
            setAnalysis(null);
            setState(wasConnected ? 'connected' : 'idle');
            setMsg('Could not reach the service. Please try again.');
            setMsgColor('var(--eop-danger)');
        });
    };
    // Start the 14-day no-card trial through the Freemius Checkout overlay.
    // No PHP SDK: checkout.min.js is enqueued on this page and exposes the
    // global FS. The overlay NEVER returns the licence key to the browser —
    // only ids and the buyer's email — so we cannot connect from here.
    // Freemius emails the key on trial start; the trialstarted screen shows
    // the paste box for it, which runs through the existing /cloud/connect.
    const doTrial = () => {
        openFreemiusCheckout({
            email: email,
            onComplete: () => { setShowKey(true); setMsg(''); setState('trialstarted'); },
        });
    };
    const doConnect = () => {
        const k = (keyInput || '').trim();
        if (!k) {
            setMsg('Paste your licence key first.');
            setMsgColor('var(--eop-danger)');
            return;
        }
        setBusy(true);
        setMsg('Connecting…');
        setMsgColor('');
        api.post('/cloud/connect', { license_key: k }).then((r) => {
            setBusy(false);
            setMsg((r && r.message) || (r && r.success ? 'Connected.' : 'Could not connect that key.'));
            setMsgColor(r && r.success ? 'var(--eop-success)' : 'var(--eop-danger)');
            if (r && r.success) {
                applyServerSettings({ easyopt_img_opt: '1' });
                setTimeout(() => window.location.reload(), 1200);
            }
        }).catch(() => { setBusy(false); setMsg('Connection failed.'); setMsgColor('var(--eop-danger)'); });
    };
    // Lost keys are now recovered through the Freemius customer portal
    // (keyBox links to it), so the old /cloud/resend flow is gone.
    const doDisconnect = () => {
        if (!confirm('Disconnect Easy Optimizer Cloud? Images will be served from your own server again.'))
            return;
        setBusy(true);
        api.post('/cloud/disconnect', {}).then(() => { window.location.reload(); }).catch(() => setBusy(false));
    };
    // Freemius checkout is hosted, so the plugin cannot complete a purchase.
    // It opens the checkout window, then asks the server whether the webhook
    // has landed. Nothing about the plan is decided on this side — a client
    // that could talk itself into a paid plan would be a free upgrade for
    // anyone who can open devtools.
    const doUpgrade = (plan) => {
        setBusy(true);
        setMsg('Opening checkout…');
        setMsgColor('');
        api.post('/cloud/upgrade', { plan: plan || 'single' }).then((r) => {
            setBusy(false);
            if (!r || !r.success || !r.checkout_url) {
                setMsg((r && r.message) || 'Could not open checkout.');
                setMsgColor('var(--eop-danger)');
                return;
            }
            window.open(r.checkout_url, '_blank', 'noopener');
            setMsg('Complete your purchase in the new tab, then come back — this page picks it up automatically.');
            // Poll for the webhook, bounded at ~2 minutes rather than
            // spinning forever if the checkout was abandoned.
            let tries = 0;
            const t = setInterval(() => {
                tries++;
                api.post('/cloud/refresh', {}).then((u) => {
                    if (u && u.plan && u.plan !== 'free' && u.plan !== 'trial') {
                        clearInterval(t);
                        window.location.reload();
                    }
                }).catch(() => { });
                if (tries > 24) {
                    clearInterval(t);
                    setMsg('Still waiting on your purchase. Reload this page once it completes.');
                }
            }, 5000);
        }).catch(() => {
            setBusy(false);
            setMsg('Could not reach the service.');
            setMsgColor('var(--eop-danger)');
        });
    };
    if (legacyConnected) {
        // Existing v2.5 connection — hand straight back to the panel they
        // already know. Nothing about their setup changes.
        return React.createElement(ImageCdnPanel, { settings: settings, onChange: onChange, applyServerSettings: applyServerSettings });
    }
    const msgEl = msg
        ? React.createElement("p", { style: { margin: '10px 0 0', color: msgColor, fontSize: '13px' } }, msg, fallback ? React.createElement("a", { href: fallback, target: "_blank", rel: "noopener", style: { marginLeft: '8px' } }, "Continue in your browser →") : null, upgradeUrl ? React.createElement("a", { href: upgradeUrl, target: "_blank", rel: "noopener", style: { marginLeft: '8px', fontWeight: 600 } }, "Upgrade to Pro →") : null)
        : null;
    const keyLink = React.createElement("p", { style: { margin: '14px 0 0', fontSize: '13px' } }, React.createElement("a", { href: "#", onClick: (e) => { e.preventDefault(); setShowKey(!showKey); }, style: { color: '#50575e' } }, "I already have a licence key"));
    const keyBox = showKey && React.createElement("div", { style: { marginTop: '10px' } }, React.createElement("input", { type: "password", value: keyInput, placeholder: "Licence key, or fpfree_… account key", onChange: (e) => setKeyInput(e.target.value), autoComplete: "new-password", style: { minWidth: '340px', fontFamily: 'monospace' } }), React.createElement("button", { type: "button", className: "button", style: { marginLeft: '8px' }, disabled: busy, onClick: doConnect }, busy ? 'Connecting…' : 'Connect'), React.createElement("p", { className: "description", style: { marginTop: '6px' } }, "Lost your licence key? ", React.createElement("a", { href: (CFG() && CFG().recoverUrl) || 'https://customers.freemius.com/store/14517/password/recover', target: "_blank", rel: "noopener" }, "Recover it from your account"), " \u2014 opens your Easy Optimizer Cloud account."));
    // ── Connected ─────────────────────────────────────
    if (state === 'connected' && cloud) {
        const u = cloud.usage || {};
        const isTrial = cloud.plan === 'trial' || cloud.plan === 'free';
        const daysLeft = Number(u.trial_days_left || 0);
        const trialOver = u.reason === 'trial_expired';
        const cap = Number(u.image_cap || 0);
        const imgs = Number(u.images_used || 0);
        const used = Number(u.used_bytes || 0);
        const quota = Number(u.quota_bytes || 0);
        const bytePct = quota > 0 ? Math.min(100, Math.round(used / quota * 100)) : 0;
        const imgPct = cap > 0 ? Math.min(100, Math.round(imgs / cap * 100)) : 0;
        // (2.6.1) "No usage data yet" is NOT "delivery is paused".
        //
        // This was `Number(u.active || 0) === 0`, which cannot tell the two
        // apart. A freshly connected site has no usage payload at all — the
        // connect handler deletes the stale transient, and the panel then
        // loads over REST, where usage() bails on `!is_admin()` because a
        // REST request is not an admin page load. So `u` was empty, `u.active`
        // was undefined, and a working paid account was told its delivery had
        // stopped while its images were being served from the CDN perfectly
        // well. Only claim paused when the server has actually said so.
        const hasUsage = u && typeof u.active !== 'undefined';
        const inactive = hasUsage && Number(u.active) === 0;
        return React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-imgopt" }, React.createElement("h2", null, "Adaptive Images — automatic AVIF/WebP & resizing"), React.createElement("div", { className: "eop-callout", style: { borderLeft: '3px solid ' + (inactive ? 'var(--eop-warning, #dba617)' : 'var(--eop-success, #0c9d57)') } }, React.createElement("p", { style: { margin: 0 } }, 
        // Honest headline: only claim "Live" when the server actually
        // says we are delivering. When it says inactive (revoked,
        // trial ended, over quota) the badge must read Paused, not a
        // green "✓ Live — Paid plan" over a site served from origin.
        inactive
            ? React.createElement("strong", null, "Paused")
            : React.createElement("strong", null, "✓ Live"), " — ", inactive
            ? (isTrial ? (trialOver ? 'Trial ended' : 'Trial') : 'Serving from your own server')
            : (isTrial
                ? (trialOver ? 'Trial ended' : (daysLeft > 0 ? 'Trial · ' + daysLeft + (daysLeft === 1 ? ' day left' : ' days left') : 'Trial'))
                : 'Paid plan'), " · account ", React.createElement("code", null, cloud.account), React.createElement("button", { type: "button", className: "button button-secondary", style: { marginLeft: '12px' }, disabled: busy, onClick: doDisconnect }, "Disconnect")), inactive && React.createElement("p", { style: { margin: '10px 0 0', fontSize: '13px' } }, React.createElement("strong", null, "Serving from your own server right now. "), u.reason === 'trial_expired'
            ? "Your trial has ended. Easy Optimizer is still optimizing your images locally, for free — you are just not getting per-device sizes or edge delivery any more."
            : u.reason === 'quota'
                ? "You've used this month's allowance. Optimization resumes when the month resets — your site is working normally in the meantime."
                : "Delivery is paused. Your site is working normally, just unoptimized."), 
        // Images is the number that means something to a site owner.
        // Bandwidth is shown second because almost nobody can
        // self-assess "1 GB of images".
        // What the free plan actually covers, stated up front. Without
        // this a new user sees "Live" plus a small number and
        // reasonably assumes their whole site is optimized.
        isTrial && React.createElement("p", { style: { margin: '12px 0 0', fontSize: '13px' } }, React.createElement("strong", null, "Your free plan: "), trialLine(daysLeft, quota ? Math.round(quota / 1e9) : 0) + " ", "Images beyond that load from your own server, exactly as before."), isTrial && cap > 0 && React.createElement("div", { style: { marginTop: '14px' } }, React.createElement("div", { style: { fontSize: '13px', marginBottom: '4px' } }, React.createElement("strong", null, imgs + " of " + cap + " images"), " optimized this month"), React.createElement("div", { style: { background: '#e2e4e7', borderRadius: '4px', height: '8px', overflow: 'hidden', maxWidth: '420px' } }, React.createElement("div", { style: { width: imgPct + '%', height: '100%', background: imgPct >= 90 ? '#d63638' : (imgPct >= 70 ? '#dba617' : '#0c9d57') } }))), 
        // Bandwidth is read from CDN logs and rolled up twice a day.
        // Showing "0 KB of 1 GB" before the first roll-up states a
        // number we do not have; say we are still measuring instead.
        quota > 0 && (used > 0 || !u.meter_stale)
            ? React.createElement("div", { style: { marginTop: '12px' } }, React.createElement("div", { style: { fontSize: '12px', color: '#50575e', marginBottom: '4px' } }, fmtBytes(used) + " of " + fmtBytes(quota) + " delivered this month"), React.createElement("div", { style: { background: '#e2e4e7', borderRadius: '4px', height: '6px', overflow: 'hidden', maxWidth: '420px' } }, React.createElement("div", { style: { width: bytePct + '%', height: '100%', background: bytePct >= 90 ? '#d63638' : (bytePct >= 70 ? '#dba617' : '#0c9d57') } })))
            : React.createElement("p", { style: { margin: '12px 0 0', fontSize: '12px', color: '#8c8f94' } }, "Measuring bandwidth — the first figures appear within a day."), 
        // A quiet, permanent line rather than a surprise at 70%, and
        // it names a concrete difference instead of just "upgrade".
        isTrial && React.createElement("div", { style: { marginTop: '16px', paddingTop: '14px', borderTop: '1px solid #e5e5e5' } }, React.createElement("p", { style: { margin: 0, fontSize: '13px' } }, (imgPct >= 70 || bytePct >= 70) ? React.createElement("strong", null, typeof u.days_left === 'number'
            ? (u.days_left === 0
                ? "You are out of room for this month. "
                : "About " + u.days_left + (u.days_left === 1 ? " day" : " days") + " left at your current rate. ")
            : "You are running out of room. ") : null, React.createElement("strong", null, "Paid removes the limits"), " — unlimited images and 100× the bandwidth (100 GB), so every page stays fully optimized as your traffic grows. From $4.90/mo, cancel anytime."), React.createElement("p", { style: { margin: '10px 0 0' } }, React.createElement("button", { type: "button", className: "button button-primary", disabled: busy, onClick: () => doUpgrade('single') }, "Upgrade"), React.createElement("a", { href: (u.pricing_url || 'https://fluxpress.io/') + "?utm_source=plugin&utm_medium=cloud_panel", target: "_blank", rel: "noopener", style: { marginLeft: '12px', fontSize: '13px' } }, "Compare plans →"))), 
        // A customer who bought on the website must be able to paste
        // their key HERE. Requiring them to disconnect first means
        // deliberately breaking a working site in order to upgrade it.
        isTrial && React.createElement("div", { style: { marginTop: '12px' } }, keyLink, keyBox), 
        // (2.6.1) NO RE-CHECK ON A CONNECTED ACCOUNT, free or paid.
        //
        // The analyzer measures the homepage only — it exists to earn
        // the email during signup, where a six-image sample is exactly
        // the right promise. Offering it after connection framed a
        // spot-check as a site measurement, which is wrong on paid
        // (the whole site is optimized, so one page proves nothing)
        // and merely redundant on free (the images bar above already
        // states coverage against the cap).
        //
        // doAnalyze() and /cloud/analyze are untouched and still drive
        // the signup funnel; this only stops rendering the entry point
        // in the connected state.
        u.meter_stale && used > 0 ? React.createElement("p", { style: { margin: '10px 0 0', fontSize: '12px', color: '#8c8f94' } }, "Usage figures are catching up and may be a few hours behind.") : null, msg ? React.createElement("p", { style: { margin: '12px 0 0', fontSize: '13px', color: msgColor } }, msg) : null), React.createElement(CloudSettings, { settings: settings, onChange: onChange, isFree: isTrial }));
    }
    // ── Trial started → paste the key Freemius just emailed ──
    if (state === 'trialstarted') {
        return React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-imgopt" }, React.createElement("h2", null, "Adaptive Images — automatic AVIF/WebP & resizing"), React.createElement("div", { className: "eop-callout", style: { borderLeft: '3px solid var(--eop-success, #0c9d57)' } }, React.createElement("h3", { style: { marginTop: 0 } }, "🎉 Your 14-day free trial has started"), React.createElement("p", { style: { margin: '4px 0 0', fontSize: '13px' } }, "We've emailed your licence key" + (email ? " to " + email : "") + ". Paste it below to switch on edge delivery — everything else is already set up."), keyBox, msgEl));
    }
    // ── Analyzing ─────────────────────────────────────
    if (state === 'analyzing') {
        return React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-imgopt" }, React.createElement("h2", null, "Adaptive Images — automatic AVIF/WebP & resizing"), React.createElement("div", { className: "eop-callout" }, React.createElement("p", null, React.createElement("strong", null, "Measuring your images…")), React.createElement("p", { className: "description" }, "Reading your homepage, then compressing a sample through Easy Optimizer Cloud to compare real file sizes. Usually about five seconds.")));
    }
    // ── Analyzed → the ask ────────────────────────────
    // (2.6.1) Analyzed, but the before/after preview could not be measured
    // (locked-down origin, CDN still warming). DO NOT dead-end the user — the
    // images are signed and connecting works. Show the offer with an honest
    // note and no fabricated number.
    if (state === 'analyzed' && analysis && analysis.measured === false) {
        return React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-imgopt" }, React.createElement("h2", null, "Adaptive Images — automatic AVIF/WebP & resizing"), React.createElement("div", { className: "eop-callout", style: { borderLeft: '3px solid var(--eop-accent, #2271b1)' } }, React.createElement("h3", { style: { marginTop: 0 } }, "Your images are ready to optimize"), React.createElement("p", { style: { margin: '4px 0 0', fontSize: '13px', color: '#8a6d1a', background: '#fcf3d7', borderLeft: '3px solid #dba617', padding: '6px 10px', borderRadius: '3px' } }, analysis.note || "We could not measure a before/after preview from this server, but connecting will start optimizing your images."), React.createElement("p", { className: "description", style: { marginTop: '12px' } }, trialLine(analysis.trial_days, analysis.trial_quota_gb) + " Setup is one click; there is nothing to configure afterwards."), React.createElement("p", { style: { marginTop: '12px' } }, React.createElement("button", { type: "button", className: "button button-primary", onClick: doTrial }, "Start 14-day free trial — no card")), React.createElement("p", { style: { margin: '10px 0 0', fontSize: '13px' } }, React.createElement("a", { href: "#", onClick: (e) => { e.preventDefault(); doAnalyze(); } }, busy ? 'Trying again…' : 'Try the preview again')), msgEl, keyLink, keyBox));
    }
    if (state === 'analyzed' && analysis && analysis.rows && analysis.rows.length) {
        const rows = analysis.rows.slice(0, 4);
        return React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-imgopt" }, React.createElement("h2", null, "Adaptive Images \u2014 automatic AVIF/WebP & resizing"), React.createElement("div", { className: "eop-af" }, React.createElement("div", { className: "eop-af__proof" }, React.createElement("div", { className: "eop-af__stat" }, React.createElement("span", { className: "eop-af__pct" }, analysis.pct + "%"), React.createElement("span", { className: "eop-af__pctlabel" }, "smaller, homepage-wide")), React.createElement("p", { className: "eop-af__bytes" }, React.createElement("s", null, fmtBytes(analysis.before)), " \u2192 ", React.createElement("strong", null, fmtBytes(analysis.after)), " \u00b7 measured on your own images just now, not an estimate."), React.createElement("p", { className: "eop-af__sample" }, "A sample of your ", React.createElement("strong", null, "homepage"), " \u2014 every page is optimized the same way once connected."), React.createElement("table", { className: "eop-af__tbl" }, React.createElement("thead", null, React.createElement("tr", null, React.createElement("th", null, "Image"), React.createElement("th", null, "Now"), React.createElement("th", null, "After"), React.createElement("th", null, "Saved"))), React.createElement("tbody", null, rows.map((r, i) => React.createElement("tr", { key: i }, React.createElement("td", null, React.createElement("code", null, r.name)), React.createElement("td", null, fmtBytes(r.before)), React.createElement("td", { className: "eop-af__after" }, fmtBytes(r.after)), React.createElement("td", null, React.createElement("strong", { className: "eop-af__saved" }, r.pct + "%")))))), React.createElement("p", { className: "eop-af__reanalyze" }, React.createElement("a", { href: "#", onClick: (e) => { e.preventDefault(); doAnalyze(); } }, busy ? 'Re-analyzing\u2026' : 'Re-analyze')), (function () {
            const sk = analysis.skipped_local || {};
            const label = { svg: 'SVG logos', gif: 'GIFs', noext: 'unrecognised files', foreign: 'images on other domains', encoded: 'filenames with spaces or symbols' };
            const parts = Object.keys(sk).filter((k) => sk[k] > 0).map((k) => sk[k] + ' ' + (label[k] || k));
            if (!parts.length) {
                return null;
            }
            return React.createElement("p", { className: "eop-af__skip" }, "Skipped here: " + parts.join(', ') + " \u2014 these keep loading from your own server.");
        })(), keyLink, keyBox, msgEl), React.createElement("div", { className: "eop-af__offer" }, React.createElement("div", { className: "eop-af__eyebrow" }, "14-day free trial"), React.createElement("div", { className: "eop-af__head" }, "Keep every image this light \u2014 everywhere."), React.createElement("ul", { className: "eop-af__checks" }, React.createElement("li", null, "No card needed. Start free."), React.createElement("li", null, "Unlimited Images across 119 edge locations"), React.createElement("li", null, "Your site keeps working when it ends")), React.createElement("button", { type: "button", className: "eop-btn eop-btn--solid eop-btn--lg eop-btn--block eop-af__cta", onClick: doTrial }, "Start free trial \u2192"), React.createElement("p", { className: "eop-af__stars" }, "\u2605\u2605\u2605\u2605\u2605  \u201cWent from 61 to 91 on mobile.\u201d"), React.createElement("p", { className: "eop-af__terms" }, "By continuing you agree to the ", React.createElement("a", { href: "https://fluxpress.io/terms", target: "_blank", rel: "noopener" }, "Terms"), " and ", React.createElement("a", { href: "https://fluxpress.io/privacy", target: "_blank", rel: "noopener" }, "Privacy"), "."))));
    }
    // Idle -> the offer
    return React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-imgopt" }, React.createElement("h2", null, "Adaptive Images \u2014 automatic AVIF/WebP & resizing"), React.createElement("div", { className: "eop-callout" }, React.createElement("h3", { style: { marginTop: 0 } }, "Next-gen images from 119 edges \u2014 without touching your server"), React.createElement("div", { className: "eop-tiles" }, React.createElement("div", { className: "eop-tile" }, React.createElement("div", { className: "eop-tile__ic" }, React.createElement("svg", { width: "20", height: "20", viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", strokeWidth: "2", strokeLinecap: "round", strokeLinejoin: "round" }, React.createElement("rect", { x: 3, y: 3, width: 18, height: 18, rx: 2 }), React.createElement("circle", { cx: 9, cy: 9, r: 2 }), React.createElement("path", { d: "m21 15-5-5L5 21" }))), React.createElement("h5", null, "AVIF + WebP"), React.createElement("p", null, "The right format per browser, automatically. No queues, no credits.")), React.createElement("div", { className: "eop-tile" }, React.createElement("div", { className: "eop-tile__ic" }, React.createElement("svg", { width: "20", height: "20", viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", strokeWidth: "2", strokeLinecap: "round", strokeLinejoin: "round" }, React.createElement("polygon", { points: "13 2 3 14 12 14 11 22 21 10 12 10 13 2" }))), React.createElement("h5", null, "Zero server load"), React.createElement("p", null, "No extra copies on disk, no CPU burn. Your originals are never touched.")), React.createElement("div", { className: "eop-tile" }, React.createElement("div", { className: "eop-tile__ic" }, React.createElement("svg", { width: "20", height: "20", viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", strokeWidth: "2", strokeLinecap: "round", strokeLinejoin: "round" }, React.createElement("circle", { cx: 12, cy: 12, r: 10 }), React.createElement("path", { d: "M2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20" }))), React.createElement("h5", null, "Exact edge sizes"), React.createElement("p", null, "Every device downloads the pixels it renders \u2014 faster LCP, better Core Web Vitals."))), React.createElement("div", { className: "eop-scan-row" }, React.createElement("button", { type: "button", className: "eop-btn eop-btn--solid eop-btn--lg", disabled: busy, onClick: doAnalyze }, "Analyze my images"), React.createElement("span", { className: "eop-scan-note" }, "No account, no email \u2014 about five seconds")), msgEl, keyLink, keyBox));
}
// Transform settings, shown only once connected. Nothing is removed —
// these are the same controls the legacy panel exposes, behind a
// disclosure so they are not pre-sale noise.
function CloudSettings({ settings, onChange, isFree }) {
    const [open, setOpen] = useState(false);
    // (2.6.1) Uses the shared eop-collapsible markup and the chevron Icon,
    // matching every other disclosure in the panel. It was a bare <a> with
    // literal ▾/▸ characters, which rendered at a different size and weight
    // to the real toggles and did not pick up their focus or hover styling.
    //
    // Not the <Collapsible> component itself: the body here must stay mounted
    // and hidden rather than unmounted, so the settings fields keep their
    // state — and their pending unsaved changes — while the section is shut.
    return React.createElement("div", { className: `eop-collapsible ${open ? 'eop-collapsible--open' : ''}`, style: { marginTop: '18px' } }, React.createElement("button", { type: "button", className: "eop-collapsible__toggle", "aria-expanded": open ? 'true' : 'false', onClick: () => setOpen(!open) }, React.createElement(Icon, { id: open ? 'chev-down' : 'chev-right', cls: "eop-icon eop-icon--xs" }), React.createElement("span", null, "Advanced settings")), React.createElement("div", { className: "eop-collapsible__body", style: !open ? { display: 'none' } : { marginTop: '12px' } }, 
    // Image-only settings. The CSS/JS/fonts (static-asset) toggle lives
    // on the Cloud Optimization tab — this tab is images only.
    React.createElement(SelectField, { name: "easyopt_fluxcdn_format", label: "Output format", settings: settings, onChange: onChange, options: [
            { value: 'auto', label: 'Auto (recommended) — AVIF, then WebP, then original, per browser' },
            { value: 'webp', label: 'Always WebP' },
            { value: 'avif', label: 'Always AVIF (large images fall back to WebP)' },
        ] }), React.createElement(SelectField, { name: "easyopt_fluxcdn_quality", label: "Quality", settings: settings, onChange: onChange, options: [
            { value: '0', label: 'Smart (recommended) — best quality per browser and format' },
            { value: '85', label: '85 — highest' },
            { value: '80', label: '80' },
            { value: '75', label: '75' },
            { value: '70', label: '70' },
            { value: '60', label: '60 — smallest files' },
        ] }), React.createElement("div", { className: "easyopt-row" }, React.createElement("label", { htmlFor: "easyopt_fluxcdn_max_width" }, "Max image width (px)"), React.createElement("input", { type: "number", id: "easyopt_fluxcdn_max_width", name: "easyopt_fluxcdn_max_width", min: 0, max: 4096, step: 1, value: settings.easyopt_fluxcdn_max_width === '' ? 2560 : Number(settings.easyopt_fluxcdn_max_width), onChange: (e) => onChange('easyopt_fluxcdn_max_width', e.target.value), style: { width: '90px' } })), React.createElement("p", { className: "description" }, "Caps oversized originals — images are never upscaled. 2560 matches WordPress' large-image threshold. 0 disables the cap."), React.createElement(Check, { name: "easyopt_fluxcdn_srcset_resize", label: "Exact srcset sizes", bold: true, settings: settings, onChange: onChange, desc: isFree
            ? "Delivers each responsive candidate at its declared width. On the free plan each image is optimized at a fixed set of widths; paid plans size every candidate exactly."
            : "Resize each responsive (srcset) candidate to its declared width, so every device downloads exactly the pixels it renders." }), React.createElement(Check, { name: "easyopt_fluxcdn_error_fallback", label: "Fallback to original on error", bold: true, settings: settings, onChange: onChange, desc: "If a CDN image fails to load in the browser, automatically swap back to the original image URL. Keeps images from ever appearing broken." }), React.createElement(PopupField, { name: "easyopt_image_exclude", title: "Exclude from CDN", help: "Class names or file paths to skip.", placeholder: ".no-cdn\n/uploads/2024/", value: settings.easyopt_image_exclude, onChange: onChange }), CFG().isElementor && React.createElement("div", { style: { marginTop: '14px' } }, React.createElement(Check, { name: "easyopt_elementor_bg_cdn", label: "Rewrite Elementor Background Images", bold: true, settings: settings, onChange: onChange, desc: "Applies CDN rewriting to Elementor's inline background-image styles." }))));
}
function ImageCdnPanel({ settings, onChange, applyServerSettings }) {
    const on = Number(settings.easyopt_img_opt) === 1;
    const isElementor = CFG().isElementor;
    const storedKey = settings.easyopt_fluxcdn_api_key || '';
    const verified = Number(settings.easyopt_fluxcdn_verified) === 1;
    // Key shape must match server-side EasyOpt_CDN::parse_key():
    // fpcdn_{account}.{key_hex}.{salt_hex}
    const KEY_RE = /^fpcdn_[a-z0-9][a-z0-9_-]{2,31}\.[0-9a-fA-F]{32,128}\.[0-9a-fA-F]{32,128}$/;
    const connected = verified && KEY_RE.test(storedKey);
    const account = connected ? storedKey.replace(/^fpcdn_/, '').split('.')[0] : '';
    const [keyInput, setKeyInput] = useState('');
    const [endpointInput, setEndpointInput] = useState(settings.easyopt_fluxcdn_endpoint || '');
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState('');
    const [msgColor, setMsgColor] = useState('');
    const maxWidth = settings.easyopt_fluxcdn_max_width === '' ? 2560 : Number(settings.easyopt_fluxcdn_max_width);
    const doConnect = () => {
        const key = keyInput.trim();
        if (!key) {
            setMsg('Paste your license key first.');
            setMsgColor('var(--eop-danger)');
            return;
        }
        setBusy(true);
        setMsg('Verifying key and requesting a test image through the CDN…');
        setMsgColor('');
        api.post('/fluxcdn/connect', { api_key: key }).then((r) => {
            setBusy(false);
            if (r && r.success) {
                applyServerSettings({
                    easyopt_fluxcdn_api_key: key,
                    easyopt_fluxcdn_verified: '1',
                });
                setKeyInput('');
                setMsg((r.message) || 'Connected! Reloading…');
                setMsgColor('var(--eop-success)');
                setTimeout(function () { window.location.reload(); }, r.ssl_prov ? 1500 : 900);
            }
            else {
                applyServerSettings({ easyopt_fluxcdn_verified: '0' });
                setMsg((r && r.message) || 'Connection failed.');
                setMsgColor('var(--eop-danger)');
            }
        }).catch(() => { setBusy(false); setMsg('Connection failed — could not reach the server. Please try again.'); setMsgColor('var(--eop-danger)'); });
    };
    const doDisconnect = () => {
        if (!confirm('Disconnect FluxCDN? Images will be served from your own server again.'))
            return;
        setBusy(true);
        api.post('/fluxcdn/disconnect').then((r) => {
            setBusy(false);
            setMsg((r && r.message) || 'Disconnected.');
            setMsgColor('');
            applyServerSettings({ easyopt_fluxcdn_api_key: '', easyopt_fluxcdn_verified: '0' });
        }).catch(() => { setBusy(false); });
    };
    const maskedKey = connected ? storedKey.slice(0, 13) + '…' + storedKey.slice(-4) : '';
    const [usage, setUsage] = useState(null);
    const [probeState, setProbeState] = useState('');
    useEffect(() => {
        if (connected) {
            api.get('/fluxcdn/usage?force=1').then((r) => {
                if (r && r.license_status === 'revoked') {
                    window.location.reload();
                    return;
                }
                if (r && r.quota_bytes > 0)
                    setUsage(r);
                if (r && r.probe_state)
                    setProbeState(r.probe_state);
            }).catch(() => { });
        }
    }, [connected]);
    const usedGB = usage ? (usage.used_bytes / 1e9) : 0;
    const quotaGB = usage ? (usage.quota_bytes / 1e9) : 0;
    const usedPct = usage ? Math.min(100, Math.round(usedGB / quotaGB * 100)) : 0;
    return (React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-imgopt" }, React.createElement("h2", null, "Adaptive Images — automatic AVIF/WebP & resizing, delivered globally"), !connected && React.createElement("div", { className: "eop-callout" }, React.createElement("h3", null, "Serve next-gen images from 119 global locations — without touching your server"), React.createElement("p", null, React.createElement("strong", null, "AVIF + WebP automatically, per browser."), " No bulk-conversion queues, no credits — every image you've ever uploaded is optimized instantly, on the fly."), React.createElement("p", null, React.createElement("strong", null, "Zero server load, zero disk bloat."), " Local optimizers store 2\u20133 extra copies of every thumbnail and burn your CPU. FluxCDN transforms at the edge — your originals are never modified."), React.createElement("p", null, React.createElement("strong", null, "Exactly-sized images from the nearest edge."), " Smaller downloads, faster LCP, better Core Web Vitals — measure it yourself with the built-in Speed Test."), React.createElement("p", { className: "description" }, "Fully reversible — disconnect anytime and your originals serve exactly as before. If the CDN is ever unreachable, original images load automatically."), !connected && React.createElement("p", { style: { marginTop: '10px' } }, React.createElement("a", { className: "button button-primary", href: "https://fluxpress.io/fluxpress-adaptive-images/?utm_source=plugin&utm_medium=imgcdn_panel#pricing", target: "_blank", rel: "noopener" }, "Try It Free \u2192"), React.createElement("span", { style: { marginLeft: '10px', fontSize: '12px', color: '#666' } }, "Instant setup \u2022 No card needed \u2022 $4.90/mo after the trial"))), !connected && (React.createElement("div", { className: "eop-callout", style: { borderLeft: '3px solid var(--eop-accent, #2271b1)' } }, React.createElement("h3", null, "Already have a license? Connect it here"), React.createElement("div", { className: "easyopt-row", style: { marginTop: '10px' } }, React.createElement("label", { htmlFor: "easyopt_fluxcdn_key_input" }, "License Key"), React.createElement("input", { type: "password", id: "easyopt_fluxcdn_key_input", value: keyInput, placeholder: "Your FluxCDN license key", onChange: (e) => setKeyInput(e.target.value), autoComplete: "new-password", style: { minWidth: '420px', fontFamily: 'monospace' } })), React.createElement("div", { className: "easyopt-field-row", style: { marginTop: '12px' } }, React.createElement("button", { type: "button", className: "button button-primary", disabled: busy, onClick: doConnect }, busy ? 'Connecting…' : 'Connect'), React.createElement("span", { style: { marginLeft: '12px', color: msgColor, fontSize: '13px' } }, msg)))), connected && (probeState === 'ssl_pending' || probeState === 'warming') && React.createElement("div", { className: "notice notice-info inline", style: { margin: '10px 0', padding: '8px 12px' } }, React.createElement("strong", null, "Warming up — your CDN's SSL certificate is being issued. "), "Images serve from your own server until it's confirmed (usually 1\u20133 minutes), then optimization starts automatically."), connected && probeState === 'origin_blocked' && React.createElement("div", { className: "notice notice-warning inline", style: { margin: '10px 0', padding: '8px 12px' } }, React.createElement("strong", null, "Your hosting firewall is blocking image delivery. "), "Ask your host to whitelist the \"FluxCDN/1.0\" user agent, then click Test. Images serve from your own server until then."), connected && (React.createElement("div", { className: "eop-callout", style: { borderLeft: '3px solid var(--eop-success, #0c9d57)' } }, React.createElement("p", { style: { margin: 0 } }, React.createElement("strong", null, "\u2713 Connected"), " — account ", React.createElement("code", null, account), " (", React.createElement("code", null, maskedKey), ") ", React.createElement("button", { type: "button", className: "button button-secondary", style: { marginLeft: '10px' }, disabled: busy, onClick: doDisconnect }, "Disconnect")), usage && React.createElement("div", { style: { marginTop: '10px' } }, React.createElement("div", { style: { fontSize: '12px', marginBottom: '4px' } }, (usedGB < 1 ? (usage.used_bytes / 1048576).toFixed(1) + " MB" : usedGB.toFixed(1) + " GB") + " of " + quotaGB.toFixed(0) + " GB used this month" + (usedPct >= 100 ? " — quota reached, images are serving from your server" : "")), React.createElement("div", { style: { background: '#e2e4e7', borderRadius: '4px', height: '8px', overflow: 'hidden', maxWidth: '420px' } }, React.createElement("div", { style: { width: usedPct + '%', height: '100%', background: usedPct >= 90 ? '#d63638' : (usedPct >= 70 ? '#dba617' : '#0c9d57') } })), usedPct >= 70 && React.createElement("a", { href: "https://fluxpress.io/fluxpress-adaptive-images/?utm_source=plugin&utm_medium=usage_bar#pricing", target: "_blank", rel: "noopener", style: { fontSize: '12px' } }, "Upgrade for more bandwidth \u2192")), msg && React.createElement("p", { style: { margin: '8px 0 0', color: msgColor, fontSize: '13px' } }, msg))), React.createElement("div", { style: !connected ? { opacity: 0.45, pointerEvents: 'none' } : {} }, React.createElement("label", null, React.createElement("input", { type: "checkbox", id: "easyopt_img_opt", name: "easyopt_img_opt", value: "1", checked: on, disabled: !connected, onChange: () => onChange('easyopt_img_opt', on ? '0' : '1') }), " ", React.createElement("strong", null, "Enable Real-Time Delivery")), React.createElement("div", { className: "imgoptfield", style: !on ? { display: 'none' } : {} }, React.createElement(SelectField, { name: "easyopt_fluxcdn_format", label: "Output format", settings: settings, onChange: onChange, options: [
            { value: 'auto', label: 'Auto (recommended) — AVIF, then WebP, then original, per browser' },
            { value: 'webp', label: 'Always WebP' },
            { value: 'avif', label: 'Always AVIF (large images fall back to WebP)' },
        ] }), React.createElement(SelectField, { name: "easyopt_fluxcdn_quality", label: "Quality", settings: settings, onChange: onChange, options: [
            { value: '0', label: 'Smart (recommended) — automatically picks the best quality for each browser and format (AVIF / WebP / JPEG)' },
            { value: '85', label: '85 — highest' },
            { value: '80', label: '80' },
            { value: '75', label: '75' },
            { value: '70', label: '70' },
            { value: '60', label: '60 — smallest files' },
        ] }), React.createElement("div", { className: "easyopt-row" }, React.createElement("label", { htmlFor: "easyopt_fluxcdn_max_width" }, "Max image width (px)"), React.createElement("input", { type: "number", id: "easyopt_fluxcdn_max_width", name: "easyopt_fluxcdn_max_width", min: 0, max: 4096, step: 1, value: maxWidth, onChange: (e) => onChange('easyopt_fluxcdn_max_width', e.target.value), style: { width: '90px' } })), React.createElement("p", { className: "description" }, "Caps oversized originals — images are never upscaled. 2560 matches WordPress' large-image threshold. 0 disables the cap."), React.createElement(Check, { name: "easyopt_fluxcdn_srcset_resize", label: "Exact srcset sizes", bold: true, settings: settings, onChange: onChange, desc: "Resize each responsive (srcset) candidate to its declared width, so every device downloads exactly the pixels it renders." }), React.createElement(Check, { name: "easyopt_fluxcdn_error_fallback", label: "Fallback to original on error", bold: true, settings: settings, onChange: onChange, desc: "If a CDN image fails to load in the browser, automatically swap back to the original image URL. Keeps images from ever appearing broken." }), React.createElement(PopupField, { name: "easyopt_image_exclude", title: "Exclude from CDN", help: "Class names, file paths to skip.", placeholder: ".no-cdn\n/uploads/2024/", value: settings.easyopt_image_exclude, onChange: onChange }), isElementor && (React.createElement("div", { style: { marginTop: '14px' } }, React.createElement(Check, { name: "easyopt_elementor_bg_cdn", label: "Rewrite Elementor Background Images", bold: true, settings: settings, onChange: onChange, desc: "Applies CDN rewriting to Elementor's inline background-image styles." })))))));
}
// ═══════════════════════════════════════════════════
//  BLOAT PANEL — without heartbeat/cron (moved to own tab)
// ═══════════════════════════════════════════════════
function BloatPanel({ settings, onChange }) {
    const items = [
        ['emojis', 'Disable Emojis', 'Removes the wp-emoji JS + the emoji detection script.'],
        ['embeds', 'Disable WP Embeds', 'Removes wp-embed.min.js and oEmbed discovery.'],
        ['xmlrpc', 'Disable XML-RPC', 'Closes /xmlrpc.php and removes X-Pingback header.'],
        ['jquery_migrate', 'Remove jQuery Migrate', 'Strips jquery-migrate (only needed for old themes).'],
        ['wp_version', 'Hide WordPress Version', 'Removes the generator meta tag and ?ver= query strings.'],
        ['rsd_wlw', 'Remove RSD / WLW Manifest', 'Removes legacy RSD/WLW blog-editor links from your page header (most sites don\'t need them).'],
        ['shortlinks', 'Remove Shortlinks', 'Removes the wp-shortlink tag from your page header.'],
        ['rss_feeds', 'Disable RSS Feeds', 'Returns 404 for /feed/ URLs. ⚠ Breaks podcast plugins and RSS readers.'],
        ['self_pingbacks', 'Disable Self Pingbacks', 'Stops your site from pinging itself.'],
        ['rest_api_logged_out', 'Limit REST API to Logged-in Users', 'Returns 401 to anonymous wp-json requests. ⚠ May break contact forms, WooCommerce Store API, and headless setups.'],
        ['wc_cart_fragments', 'Disable WooCommerce Cart Fragments', 'Removes wc-cart-fragments AJAX script.'],
        ['app_passwords', 'Disable Application Passwords', 'Hides the Application Passwords UI.'],
        ['dashicons', 'Disable Dashicons (front-end)', 'Stops loading dashicons.css for logged-out visitors.'],
        ['block_css', 'Disable Block Library CSS', 'Removes wp-block-library CSS on the frontend. Safe if you don\'t use Gutenberg blocks in your content.'],
    ];
    return (React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-bloat" }, React.createElement("h2", null, "Bloat Removal"), React.createElement("p", { className: "description", style: { marginBottom: '18px' } }, "Strip optional WordPress features that most sites do not use. Each toggle is independent."), items.map(([key, label, desc]) => React.createElement(Check, { key: key, name: `easyopt_bloat_${key}`, label: label, bold: true, desc: desc, settings: settings, onChange: onChange }))));
}
// ═══════════════════════════════════════════════════
//  CLOUDFLARE PANEL
// ═══════════════════════════════════════════════════
function CloudflarePanel({ settings, onChange }) {
    const on = Number(settings.easyopt_cf_enabled) === 1;
    const [msg, setMsg] = useState('');
    const [msgColor, setMsgColor] = useState('');
    const doTest = () => {
        setMsg('Testing…');
        setMsgColor('');
        api.post('/cloudflare/test', { token: settings.easyopt_cf_api_token || '', zone_id: settings.easyopt_cf_zone_id || '' }).then((r) => {
            setMsg(r?.message || (r?.success ? 'OK' : 'Failed'));
            setMsgColor(r?.success ? 'var(--eop-success)' : 'var(--eop-danger)');
        });
    };
    const doPurge = () => {
        if (!confirm('Purge entire Cloudflare cache?'))
            return;
        setMsg('Purging…');
        api.post('/cloudflare/purge').then((r) => { setMsg(r?.message || 'Done'); setMsgColor(r?.success ? 'var(--eop-success)' : 'var(--eop-danger)'); });
    };
    return (React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-cloudflare" }, React.createElement("h2", null, "Cloudflare"), React.createElement("p", { className: "description", style: { marginBottom: '18px' } }, "When page cache is cleared, also tell Cloudflare to drop its edge copy."), React.createElement("label", null, React.createElement("input", { type: "checkbox", id: "easyopt_cf_enabled", name: "easyopt_cf_enabled", value: "1", checked: on, onChange: () => onChange('easyopt_cf_enabled', on ? '0' : '1') }), " ", React.createElement("strong", null, "Enable Cloudflare integration")), React.createElement("div", { className: "cffield", style: !on ? { display: 'none' } : {} }, React.createElement(Collapsible, { title: "How to get your API Token & Zone ID" }, React.createElement("div", { style: { lineHeight: '1.7', fontSize: '13px' } }, React.createElement("strong", null, "API Token"), React.createElement("ol", { style: { margin: '6px 0 14px', paddingLeft: '20px' } }, React.createElement("li", null, "Log in to your ", React.createElement("a", { href: "https://dash.cloudflare.com/profile/api-tokens", target: "_blank", rel: "noopener" }, "Cloudflare dashboard \u2192 My Profile \u2192 API Tokens")), React.createElement("li", null, "Click ", React.createElement("strong", null, "Create Token")), React.createElement("li", null, "Use the ", React.createElement("em", null, "\"Edit zone DNS\""), " template, or create a custom token with ", React.createElement("strong", null, "Zone \u2192 Cache Purge \u2192 Purge"), " permission"), React.createElement("li", null, "Set zone resources to ", React.createElement("strong", null, "Include \u2192 Specific zone \u2192 your domain")), React.createElement("li", null, "Click ", React.createElement("strong", null, "Continue to summary \u2192 Create Token")), React.createElement("li", null, "Copy the token — it's shown only once")), React.createElement("strong", null, "Zone ID"), React.createElement("ol", { style: { margin: '6px 0 0', paddingLeft: '20px' } }, React.createElement("li", null, "Go to your ", React.createElement("a", { href: "https://dash.cloudflare.com/", target: "_blank", rel: "noopener" }, "Cloudflare dashboard"), " \u2192 select your site"), React.createElement("li", null, "On the ", React.createElement("strong", null, "Overview"), " page, scroll down to the right sidebar"), React.createElement("li", null, "Your ", React.createElement("strong", null, "Zone ID"), " is listed under the ", React.createElement("strong", null, "API"), " section"), React.createElement("li", null, "Click to copy it")))), React.createElement("div", { className: "easyopt-row", style: { marginTop: '14px' } }, React.createElement("label", { htmlFor: "easyopt_cf_api_token" }, "API Token"), React.createElement("input", { type: "password", id: "easyopt_cf_api_token", name: "easyopt_cf_api_token", value: settings.easyopt_cf_api_token || '', onChange: (e) => onChange('easyopt_cf_api_token', e.target.value), autoComplete: "new-password", style: { minWidth: '380px', fontFamily: 'monospace' } })), React.createElement("div", { className: "easyopt-row" }, React.createElement("label", { htmlFor: "easyopt_cf_zone_id" }, "Zone ID"), React.createElement("input", { type: "text", id: "easyopt_cf_zone_id", name: "easyopt_cf_zone_id", value: settings.easyopt_cf_zone_id || '', onChange: (e) => onChange('easyopt_cf_zone_id', e.target.value), style: { minWidth: '380px', fontFamily: 'monospace' } })), React.createElement(SelectField, { name: "easyopt_cf_purge_strategy", label: "Purge Strategy", settings: settings, onChange: onChange, options: [{ value: 'host', label: 'By cache tag (Enterprise) — fall back to everything' }, { value: 'everything', label: 'Purge everything (all plans)' }] }), React.createElement("div", { className: "easyopt-field-row", style: { marginTop: '14px' } }, React.createElement("button", { type: "button", className: "button button-secondary", onClick: doTest }, "Test Connection"), ' ', React.createElement("button", { type: "button", className: "button button-secondary", onClick: doPurge }, "Purge Cloudflare Now"), React.createElement("span", { style: { marginLeft: '12px', color: msgColor, fontSize: '13px' } }, msg)))));
}
// ═══════════════════════════════════════════════════
//  DATABASE — CLEANUP sub-tab
// ═══════════════════════════════════════════════════
function DbCleanupSubPanel({ settings, onChange }) {
    const [cleaning, setCleaning] = useState(false);
    const [cleanMsg, setCleanMsg] = useState('');
    const [snapshots, setSnapshots] = useState([]);
    const [retention, setRetention] = useState(14);
    const tasks = CFG().dbTasks || {};
    const [counts, setCounts] = useState(null);
    const [countsLoading, setCountsLoading] = useState(false);
    // Fetch counts via REST (async, not blocking render)
    useEffect(() => {
        setCountsLoading(true);
        api.get('/database/counts').then((c) => {
            if (c)
                setCounts(c);
        }).finally(() => setCountsLoading(false));
    }, []);
    const loadSnaps = useCallback(() => {
        api.get('/database/snapshots').then((r) => {
            if (r?.snapshots)
                setSnapshots(r.snapshots);
            if (r?.retention)
                setRetention(r.retention);
        });
    }, []);
    useEffect(() => { loadSnaps(); }, []);
    const runCleanup = useCallback(async () => {
        setCleaning(true);
        setCleanMsg('Starting…');
        const sid = Date.now().toString();
        let total = 0;
        let safety = 200;
        while (safety-- > 0) {
            try {
                const r = await api.post('/database/run', { task: 'all', session_id: sid });
                if (r?.counts)
                    setCounts(r.counts);
                const rep = r?.report || {};
                Object.keys(rep).forEach(k => { total += parseInt(rep[k], 10) || 0; });
                setCleanMsg(`Running… ${total} items removed so far.`);
                if (r?.done) {
                    setCleanMsg(`Done. ${total} items removed.`);
                    setCleaning(false);
                    loadSnaps();
                    return;
                }
            }
            catch {
                setCleanMsg('Network error.');
                setCleaning(false);
                return;
            }
            await new Promise(r => setTimeout(r, 100));
        }
        setCleaning(false);
    }, []);
    const deleteSnap = (basename) => {
        if (!confirm('Delete this snapshot permanently?'))
            return;
        api.post('/database/snapshot-delete', { basename }).then(() => loadSnaps());
    };
    const [restoring, setRestoring] = useState('');
    const [restoreMsg, setRestoreMsg] = useState('');
    const [confirmRestore, setConfirmRestore] = useState('');
    const restoreSnap = async (basename) => {
        setConfirmRestore('');
        setRestoring(basename);
        setRestoreMsg('Restoring…');
        let offset = 0;
        let total = 0;
        let safety = 500;
        while (safety-- > 0) {
            try {
                const r = await api.post('/database/snapshot-restore', { basename, offset });
                if (!r?.ok) {
                    setRestoreMsg('Restore failed: ' + (r?.error || 'Unknown error'));
                    setRestoring('');
                    return;
                }
                total += r.inserted || 0;
                setRestoreMsg(`Restoring… ${total} rows so far.`);
                if (r.done) {
                    setRestoreMsg(`✓ Restore complete. ${total} rows restored.`);
                    api.get('/database/counts').then((c) => {
                        if (c)
                            setCounts(c);
                    });
                    loadSnaps();
                    setTimeout(() => setRestoreMsg(''), 8000);
                    break;
                }
                offset = r.next_offset || 0;
            }
            catch {
                setRestoreMsg('Network error during restore.');
                break;
            }
        }
        setRestoring('');
    };
    const taskHelp = { post_revisions: 'Old revision rows.', auto_drafts: 'Auto-created drafts.', trashed_posts: 'Posts in trash.', spam_comments: 'Spam comments.', trashed_comments: 'Trashed comments.', expired_transients: 'Expired cached values.', all_transients: 'All transients.', optimize_tables: 'Defragment tables.' };
    return (React.createElement("div", null, React.createElement("h2", { className: "eop-section-h", style: { marginTop: 0 } }, "Database Cleanup"), React.createElement("p", { className: "description", style: { marginBottom: '18px' } }, "Reclaim space by removing revisions, trash, expired transients and orphaned data."), React.createElement("div", { className: "easyopt-field-row" }, Object.entries(tasks).map(([key, label]) => {
        const opt = `easyopt_db_${key}`;
        const cnt = counts ? counts[key] : null;
        return (React.createElement("div", { key: key, className: "easyopt-db-row", style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '10px 0', borderBottom: '1px solid var(--eop-border-soft)' } }, React.createElement("div", { style: { flex: 1, minWidth: 0 } }, React.createElement("label", { style: { display: 'block', fontWeight: 500 } }, React.createElement("input", { type: "checkbox", name: opt, value: "1", checked: Number(settings[opt]) === 1, onChange: () => onChange(opt, Number(settings[opt]) === 1 ? '0' : '1') }), " ", label), React.createElement("p", { className: "description", style: { margin: '4px 0 0 24px', fontSize: '12.5px' } }, taskHelp[key] || '')), cnt != null && key !== 'optimize_tables' && React.createElement("span", { className: "easyopt-db-count", style: { marginLeft: '14px', fontSize: '13px', background: 'var(--eop-bg-tab)', padding: '2px 10px', borderRadius: '999px', color: 'var(--eop-text-muted)' } }, nf(cnt), " items"), cnt == null && countsLoading && key !== 'optimize_tables' && React.createElement("span", { style: { marginLeft: '14px', fontSize: '12px', color: 'var(--eop-text-muted)' } }, "…")));
    })), React.createElement("div", { className: "easyopt-field-row", style: { marginTop: '18px' } }, React.createElement("button", { type: "button", className: "button button-secondary", disabled: cleaning, onClick: runCleanup }, cleaning ? 'Running…' : 'Run Cleanup Now'), React.createElement("span", { style: { marginLeft: '12px', fontSize: '13px' } }, cleanMsg)), React.createElement("h3", { style: { marginTop: '28px' } }, "Scheduled Cleanup"), React.createElement(Check, { name: "easyopt_db_cron_enabled", label: "Enable scheduled cleanup", settings: settings, onChange: onChange }), React.createElement("div", { className: "easyopt-cron-fields", style: Number(settings.easyopt_db_cron_enabled) !== 1 ? { display: 'none' } : {} }, React.createElement(SelectField, { name: "easyopt_db_schedule", label: "Frequency", settings: settings, onChange: onChange, options: [{ value: 'daily', label: 'Daily' }, { value: 'weekly', label: 'Weekly' }, { value: 'monthly', label: 'Monthly' }] })), React.createElement("h3", { style: { marginTop: '28px' } }, "Snapshots"), React.createElement(Check, { name: "easyopt_db_snapshot_enabled", label: "Enable automatic snapshots before cleanup", settings: settings, onChange: onChange }), React.createElement("div", { className: "easyopt-snapshot-fields", style: Number(settings.easyopt_db_snapshot_enabled) !== 1 ? { display: 'none' } : {} }, React.createElement("div", { className: "easyopt-row" }, React.createElement("label", null, "Retention (days)"), React.createElement("input", { type: "number", name: "easyopt_db_snapshot_retention_days", value: settings.easyopt_db_snapshot_retention_days ?? 14, onChange: (e) => onChange('easyopt_db_snapshot_retention_days', e.target.value), min: "1", max: "90", style: { width: '80px' } }))), React.createElement("div", { id: "easyopt_db_snapshots_list", style: { marginTop: '14px' } }, snapshots.length === 0 ? React.createElement("p", null, "No snapshots yet.") : React.createElement(React.Fragment, null, React.createElement("p", { className: "description" }, "Snapshots kept for ", React.createElement("strong", null, retention, " days"), "."), React.createElement("table", { className: "widefat striped" }, React.createElement("thead", null, React.createElement("tr", null, React.createElement("th", null, "When"), React.createElement("th", null, "Task"), React.createElement("th", null, "Rows"), React.createElement("th", null, "Size"), React.createElement("th", null))), React.createElement("tbody", null, snapshots.map((s, i) => React.createElement("tr", { key: s.basename || i }, React.createElement("td", null, s.started_at ? new Date(s.started_at * 1000).toLocaleString() : '—'), React.createElement("td", null, React.createElement("code", null, s.task || '')), React.createElement("td", null, nf(s.row_count), " rows"), React.createElement("td", null, s.size_bytes > 1024 * 1024 ? (s.size_bytes / 1024 / 1024).toFixed(1) + ' MB' : Math.round(s.size_bytes / 1024) + ' KB'), React.createElement("td", null, React.createElement("button", { type: "button", className: "button", disabled: !!restoring, onClick: () => setConfirmRestore(s.basename) }, restoring === s.basename ? 'Restoring…' : 'Restore'), " ", React.createElement("button", { type: "button", className: "button eop-snap-delete", onClick: () => deleteSnap(s.basename) }, "Delete"))))))), restoreMsg && React.createElement("p", { style: { marginTop: '10px', fontSize: '13px', color: restoreMsg.startsWith('✓') ? '#00a32a' : restoreMsg.includes('fail') || restoreMsg.includes('error') ? '#d63638' : '#666' } }, restoreMsg)), React.createElement(ConfirmModal, { open: !!confirmRestore, title: "Restore Snapshot", message: "Are you sure you want to restore this snapshot? Existing rows with matching IDs will be skipped.", confirmLabel: "Restore", onConfirm: () => restoreSnap(confirmRestore), onCancel: () => setConfirmRestore('') })));
}
// ═══════════════════════════════════════════════════
//  DATABASE — AUTOLOAD HEALTH sub-tab
// ═══════════════════════════════════════════════════
function DbAutoloadSubPanel({ settings, onChange }) {
    const [autoload, setAutoload] = useState(null);
    const [autoloadLoading, setAutoloadLoading] = useState(false);
    const [alToggleBusy, setAlToggleBusy] = useState('');
    const [alConfirm, setAlConfirm] = useState(null);
    const fetchAutoload = () => {
        setAutoloadLoading(true);
        api.get('/database/autoload').then((r) => { setAutoload(r); setAutoloadLoading(false); }).catch(() => setAutoloadLoading(false));
    };
    const doAutoloadToggle = (optionName, newValue) => {
        setAlConfirm(null);
        setAlToggleBusy(optionName);
        api.post('/database/autoload-toggle', { option_name: optionName, autoload: newValue }).then((r) => {
            setAlToggleBusy('');
            if (r?.success)
                fetchAutoload();
        }).catch(() => setAlToggleBusy(''));
    };
    return (React.createElement("div", null, React.createElement("h2", { className: "eop-section-h", style: { marginTop: 0 } }, "Autoload Health"), React.createElement("p", { className: "description", style: { marginBottom: '14px' } }, "WordPress loads all autoloaded options into memory on every page request. Large autoload sizes slow down every page."), !autoload && React.createElement("button", { type: "button", className: "button button-secondary", disabled: autoloadLoading, onClick: fetchAutoload }, autoloadLoading ? 'Loading…' : 'Check Autoload Size'), autoload && React.createElement(React.Fragment, null, React.createElement("div", { style: { display: 'flex', gap: '24px', marginBottom: '18px', flexWrap: 'wrap' } }, React.createElement("div", { style: { background: 'var(--eop-bg-tab)', borderRadius: '8px', padding: '14px 20px', minWidth: '140px' } }, React.createElement("div", { style: { fontSize: '12px', color: 'var(--eop-text-muted)', marginBottom: '4px' } }, "Total Autoload Size"), React.createElement("div", { style: { fontSize: '22px', fontWeight: 700, color: (autoload.total_bytes || 0) > 1048576 ? 'var(--eop-danger)' : (autoload.total_bytes || 0) > 524288 ? '#d97706' : 'var(--eop-success)' } }, autoload.total_human)), React.createElement("div", { style: { background: 'var(--eop-bg-tab)', borderRadius: '8px', padding: '14px 20px', minWidth: '140px' } }, React.createElement("div", { style: { fontSize: '12px', color: 'var(--eop-text-muted)', marginBottom: '4px' } }, "Autoloaded Options"), React.createElement("div", { style: { fontSize: '22px', fontWeight: 700 } }, nf(autoload.count))), React.createElement("div", { style: { background: 'var(--eop-bg-tab)', borderRadius: '8px', padding: '14px 20px', minWidth: '140px' } }, React.createElement("div", { style: { fontSize: '12px', color: 'var(--eop-text-muted)', marginBottom: '4px' } }, "Health"), React.createElement("div", { style: { fontSize: '22px', fontWeight: 700, color: (autoload.total_bytes || 0) > 1048576 ? 'var(--eop-danger)' : (autoload.total_bytes || 0) > 524288 ? '#d97706' : 'var(--eop-success)' } }, (autoload.total_bytes || 0) > 1048576 ? 'Needs attention' : (autoload.total_bytes || 0) > 524288 ? 'Fair' : 'Healthy'))), (autoload.total_bytes || 0) > 1048576 && React.createElement("p", { style: { color: 'var(--eop-danger)', fontSize: '13px', margin: '0 0 14px' } }, "\u26A0 Autoload size exceeds 1 MB. Consider reviewing the largest options below and disabling autoload on non-essential options."), autoload.top && autoload.top.length > 0 && React.createElement(React.Fragment, null, React.createElement("h4", { style: { margin: '0 0 8px' } }, "Autoloaded Options"), React.createElement("p", { className: "description", style: { margin: '0 0 10px', fontSize: '12px' } }, "Disable autoload for non-essential options to reduce memory usage. Core WordPress options are protected."), React.createElement("table", { className: "widefat striped", style: { maxWidth: '780px' } }, React.createElement("thead", null, React.createElement("tr", null, React.createElement("th", null, "Option Name"), React.createElement("th", { style: { textAlign: 'right' } }, "Size"), React.createElement("th", { style: { textAlign: 'center', width: '90px' } }, "Autoload"))), React.createElement("tbody", null, autoload.top.map((row) => React.createElement("tr", { key: row.option_name, style: row.autoload === 'no' ? { opacity: 0.65 } : {} }, React.createElement("td", null, React.createElement("code", { style: { fontSize: '12px' } }, row.option_name), row.autoload === 'no' && React.createElement("span", { style: { fontSize: '10px', color: 'var(--eop-text-muted)', marginLeft: '6px' } }, "(disabled)")), React.createElement("td", { style: { textAlign: 'right', whiteSpace: 'nowrap' } }, row.size_human), React.createElement("td", { style: { textAlign: 'center' } }, row.protected
        ? React.createElement("span", { style: { fontSize: '11px', color: 'var(--eop-text-muted)' }, title: "Core WP option — cannot be disabled" }, "\uD83D\uDD12 On")
        : row.autoload === 'no'
            ? React.createElement("button", { type: "button", className: "button button-small", disabled: alToggleBusy === row.option_name, style: { minWidth: '60px', fontSize: '12px', color: 'var(--eop-success)' }, onClick: () => doAutoloadToggle(row.option_name, 'yes') }, alToggleBusy === row.option_name ? '…' : 'Enable')
            : React.createElement("button", { type: "button", className: "button button-small", disabled: alToggleBusy === row.option_name, style: { minWidth: '60px', fontSize: '12px' }, onClick: () => setAlConfirm({ name: row.option_name, action: 'no' }) }, alToggleBusy === row.option_name ? '…' : 'Disable'))))))), React.createElement("button", { type: "button", className: "button button-secondary", style: { marginTop: '12px' }, onClick: fetchAutoload }, autoloadLoading ? 'Refreshing…' : 'Refresh')), React.createElement(ConfirmModal, { open: !!alConfirm, title: "Disable Autoload", message: alConfirm ? 'Disable autoload for "' + alConfirm.name + '"? The option will still exist and can be re-enabled.' : '', confirmLabel: "Disable Autoload", onConfirm: () => alConfirm && doAutoloadToggle(alConfirm.name, 'no'), onCancel: () => setAlConfirm(null) })));
}
// ═══════════════════════════════════════════════════
//  DATABASE PANEL — wrapper with sub-tabs
// ═══════════════════════════════════════════════════
function DatabasePanel({ settings, onChange }) {
    const [sub, setSub] = useState(() => {
        const stored = localStorage.getItem('easyopt_db_sub');
        return DB_SUBTABS.find(t => t.key === stored) ? stored : 'cleanup';
    });
    const switchSub = (k) => { setSub(k); localStorage.setItem('easyopt_db_sub', k); };
    return (React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-database" }, React.createElement(SubTabNav, { tabs: DB_SUBTABS, active: sub, onChange: switchSub }), React.createElement("div", { style: { marginTop: '20px' } }, sub === 'cleanup' && React.createElement(DbCleanupSubPanel, { settings: settings, onChange: onChange }), sub === 'autoload' && React.createElement(DbAutoloadSubPanel, { settings: settings, onChange: onChange }))));
}
// ═══════════════════════════════════════════════════
//  HEARTBEAT & CRON PANEL (moved from Bloat + Database)
// ═══════════════════════════════════════════════════
function HeartbeatCronPanel({ settings, onChange }) {
    const hbOn = Number(settings.easyopt_bloat_heartbeat) === 1;
    const [cronData, setCronData] = useState(null);
    const [cronLoading, setCronLoading] = useState(false);
    const [cronBusy, setCronBusy] = useState('');
    const fetchCron = () => {
        setCronLoading(true);
        api.get('/cron/events').then((r) => { setCronData(r); setCronLoading(false); }).catch(() => setCronLoading(false));
    };
    const cronRun = (hook, hash) => {
        setCronBusy(hook + hash);
        api.post('/cron/run', { hook, hash }).then(() => { setCronBusy(''); fetchCron(); }).catch(() => setCronBusy(''));
    };
    const cronDelete = (hook, hash, timestamp) => {
        setCronBusy(hook + hash);
        api.post('/cron/delete', { hook, hash, timestamp }).then(() => { setCronBusy(''); fetchCron(); }).catch(() => setCronBusy(''));
    };
    return (React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-heartbeat" }, React.createElement("h2", null, "Heartbeat & Cron"), React.createElement("p", { className: "description", style: { marginBottom: '18px' } }, "Control WordPress Heartbeat API overhead and manage WP-Cron scheduling."), React.createElement("div", { style: { marginBottom: '24px' } }, React.createElement("h3", { style: { margin: '0 0 8px' } }, React.createElement(Icon, { id: "heart", cls: "eop-icon eop-icon--sm" }), " Control Heartbeat API"), React.createElement(Check, { name: "easyopt_bloat_heartbeat", label: "Enable Heartbeat Control", bold: true, desc: "Manage where and how often WordPress Heartbeat runs. Reduces admin AJAX overhead.", settings: settings, onChange: onChange }), React.createElement("div", { style: !hbOn ? { display: 'none' } : { marginLeft: '28px', marginTop: '8px' } }, React.createElement(SelectField, { name: "easyopt_heartbeat_location", label: "Allowed Location", settings: settings, onChange: onChange, options: [
            { value: 'everywhere', label: 'Everywhere (just control frequency)' },
            { value: 'allow_admin', label: 'Admin pages only (disable on frontend)' },
            { value: 'allow_editor', label: 'Post editor only (disable elsewhere)' },
            { value: 'disabled', label: 'Disabled everywhere' },
        ] }), settings.easyopt_heartbeat_location !== 'disabled' && React.createElement(SelectField, { name: "easyopt_heartbeat_frequency", label: "Frequency", settings: settings, onChange: onChange, options: [
            { value: '15', label: '15 seconds (default)' },
            { value: '30', label: '30 seconds' },
            { value: '60', label: '60 seconds (recommended)' },
            { value: '120', label: '120 seconds' },
            { value: '300', label: '300 seconds (5 minutes)' },
        ] }))), React.createElement("div", { style: { paddingTop: '18px', borderTop: '1px solid var(--eop-border-soft)', marginBottom: '24px' } }, React.createElement("h3", { style: { margin: '0 0 6px' } }, React.createElement(Icon, { id: "refresh", cls: "eop-icon eop-icon--sm" }), " WP-Cron Frequency"), React.createElement("p", { className: "description", style: { marginBottom: '10px' } }, "WordPress fires WP-Cron on every page load. Throttle it to reduce overhead on busy sites."), React.createElement(Check, { name: "easyopt_cron_throttle", label: "Enable WP-Cron Throttle", bold: true, desc: "Limit how often WP-Cron runs. Recommended only for high-traffic sites using a system cron.", settings: settings, onChange: onChange }), React.createElement("div", { style: !Number(settings.easyopt_cron_throttle) ? { display: 'none' } : { marginLeft: '28px', marginTop: '8px' } }, React.createElement(SelectField, { name: "easyopt_cron_frequency", label: "Run WP-Cron at most every", settings: settings, onChange: onChange, options: [
            { value: '120', label: 'Every 2 minutes' },
            { value: '300', label: 'Every 5 minutes' },
            { value: '600', label: 'Every 10 minutes' },
        ] }))), React.createElement("div", { style: { paddingTop: '18px', borderTop: '1px solid var(--eop-border-soft)' } }, React.createElement("h3", { style: { margin: '0 0 6px' } }, React.createElement(Icon, { id: "db", cls: "eop-icon eop-icon--sm" }), " Cron Events"), React.createElement("p", { className: "description", style: { marginBottom: '14px' } }, "View, run, or delete scheduled WP-Cron events."), !cronData && React.createElement("button", { type: "button", className: "button button-secondary", disabled: cronLoading, onClick: fetchCron }, cronLoading ? 'Loading…' : 'View Cron Events'), cronData && React.createElement(React.Fragment, null, React.createElement("div", { style: { display: 'flex', gap: '24px', marginBottom: '18px', flexWrap: 'wrap' } }, React.createElement("div", { style: { background: 'var(--eop-bg-tab)', borderRadius: '8px', padding: '14px 20px', minWidth: '140px' } }, React.createElement("div", { style: { fontSize: '12px', color: 'var(--eop-text-muted)', marginBottom: '4px' } }, "Total Events"), React.createElement("div", { style: { fontSize: '22px', fontWeight: 700 } }, nf(cronData.total))), React.createElement("div", { style: { background: 'var(--eop-bg-tab)', borderRadius: '8px', padding: '14px 20px', minWidth: '140px' } }, React.createElement("div", { style: { fontSize: '12px', color: 'var(--eop-text-muted)', marginBottom: '4px' } }, "WP-Cron"), React.createElement("div", { style: { fontSize: '22px', fontWeight: 700, color: cronData.wp_cron ? 'var(--eop-success)' : '#d97706' } }, cronData.wp_cron ? 'Active' : 'Disabled'))), !cronData.wp_cron && React.createElement("p", { style: { color: '#d97706', fontSize: '13px', margin: '0 0 14px' } }, "DISABLE_WP_CRON is defined. Make sure a system cron is hitting wp-cron.php.", cronData.last_cron_ago ? ' Last background run: ' + cronData.last_cron_ago + ' ago.' : ' No background run has been observed yet — your system cron may not be reaching wp-cron.php.'), cronData.events && cronData.events.length > 0 && React.createElement("div", { style: { maxHeight: '500px', overflowY: 'auto' } }, React.createElement("table", { className: "widefat striped", style: { maxWidth: '900px' } }, React.createElement("thead", null, React.createElement("tr", null, React.createElement("th", null, "Hook"), React.createElement("th", null, "Schedule"), React.createElement("th", null, "Next Run"), React.createElement("th", { style: { textAlign: 'center', width: '140px' } }, "Actions"))), React.createElement("tbody", null, cronData.events.map((ev, i) => React.createElement("tr", { key: ev.hook + ev.hash + i }, React.createElement("td", null, React.createElement("code", { style: { fontSize: '11px', wordBreak: 'break-all' } }, ev.hook)), React.createElement("td", { style: { whiteSpace: 'nowrap', fontSize: '12px' } }, ev.interval), React.createElement("td", { style: { whiteSpace: 'nowrap', fontSize: '12px', color: ev.overdue ? 'var(--eop-danger)' : undefined } }, ev.next_run, ev.overdue ? ' (overdue)' : ''), React.createElement("td", { style: { textAlign: 'center' } }, React.createElement("button", { type: "button", className: "button button-small", disabled: cronBusy === ev.hook + ev.hash, style: { fontSize: '11px', marginRight: '4px' }, onClick: () => cronRun(ev.hook, ev.hash) }, cronBusy === ev.hook + ev.hash ? '…' : 'Run'), React.createElement("button", { type: "button", className: "button button-small", disabled: cronBusy === ev.hook + ev.hash, style: { fontSize: '11px', color: 'var(--eop-danger)' }, onClick: () => cronDelete(ev.hook, ev.hash, ev.timestamp) }, cronBusy === ev.hook + ev.hash ? '…' : 'Delete'))))))), React.createElement("button", { type: "button", className: "button button-secondary", style: { marginTop: '12px' }, onClick: fetchCron }, cronLoading ? 'Refreshing…' : 'Refresh')))));
}
// ═══════════════════════════════════════════════════
//  ACCESSIBILITY & SEO PANEL
// ═══════════════════════════════════════════════════
function AccessibilityPanel({ settings, onChange }) {
    const a11y = [
        ['easyopt_a11y_inputs', 'Form elements do not have associated labels'],
        ['easyopt_a11y_links', 'Links do not have a discernible name'],
        ['easyopt_a11y_buttons', 'Buttons do not have an accessible name'],
        ['easyopt_a11y_viewport', '[user-scalable=\"no\"] is used in viewport, or [maximum-scale] < 5'],
        ['easyopt_a11y_role_elements', 'button, link, menuitem elements do not have accessible names'],
        ['easyopt_a11y_iframes', '<frame>/<iframe> elements do not have a title'],
        ['easyopt_a11y_progressbar', 'ARIA progressbar elements do not have accessible names'],
        ['easyopt_a11y_tabindex', 'Some elements have a [tabindex] value greater than 0'],
    ];
    return (React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-accessibility" }, React.createElement("h2", { className: "eop-section-h" }, "Accessibility"), React.createElement("p", { className: "description", style: { marginBottom: '18px' } }, "Fix common Lighthouse accessibility audits. Enable only the options relevant to your site."), a11y.map(([key, label]) => React.createElement(Check, { key: key, name: key, label: label, settings: settings, onChange: onChange })), React.createElement("h2", { className: "eop-section-h", style: { marginTop: '34px' } }, "SEO"), React.createElement("p", { className: "description", style: { marginBottom: '18px' } }, "Fix common Lighthouse SEO audits."), React.createElement(Check, { name: "easyopt_seo_crawlable_links", label: "Make non-crawlable links crawlable", settings: settings, onChange: onChange, desc: 'Any <a> with missing/empty/javascript:void(0) href is rewritten to "#".' }), React.createElement(Check, { name: "easyopt_seo_image_alts", label: "Add missing image alt attributes", settings: settings, onChange: onChange, desc: "Fills missing alt from image title or filename." })));
}
// ═══════════════════════════════════════════════════
//  BACKEND ANALYZER PANEL (2.4.0)
// ═══════════════════════════════════════════════════
function BackendPanel({ settings, onChange, saveOne, showToast: toast }) {
    const SUBS = [
        { key: 'callbacks', label: 'Slow Callbacks', icon: 'plug' },
        { key: 'queries', label: 'Slow Database Queries', icon: 'database' },
    ];
    const [sub, setSub] = useState(localStorage.getItem('easyopt_backend_sub') || 'callbacks');
    const pickSub = (k) => { setSub(k); localStorage.setItem('easyopt_backend_sub', k); };
    const cbOn = Number(settings['easyopt_backend_callbacks']) === 1;
    const qOn = Number(settings['easyopt_backend_queries']) === 1;
    const setSubFeature = (name, on) => {
        saveOne('easyopt_backend_analyzer', (on || (name === 'easyopt_backend_callbacks' ? qOn : cbOn)) ? 1 : 0);
        saveOne(name, on ? 1 : 0);
    };
    const homeUrl = CFG().homeUrl || '';
    const [url, setUrl] = useState(homeUrl);
    const [running, setRunning] = useState(0);
    const [lastRun, setLastRun] = useState(null);
    const [cbData, setCbData] = useState(null);
    const [qData, setQData] = useState(null);
    const [selUrl, setSelUrl] = useState('');
    const [expanded, setExpanded] = useState('');
    const [expandedComp, setExpandedComp] = useState('');
    const fetchResults = (hash) => {
        api.get('/backend/callbacks' + (hash ? '?url_hash=' + encodeURIComponent(hash) : '')).then((r) => setCbData(r));
        api.get('/backend/queries').then((r) => setQData(r));
    };
    const iframePass = (target, token, pass) => new Promise((resolve, reject) => {
        const sep = target.indexOf('?') === -1 ? '?' : '&';
        const frame = document.createElement('iframe');
        frame.style.cssText = 'position:absolute;width:1px;height:1px;left:-9999px;visibility:hidden;';
        const done = (ok) => { clearTimeout(timer); frame.remove(); ok ? resolve() : reject(new Error('timeout')); };
        const timer = setTimeout(() => done(false), 60000);
        frame.onload = () => done(true);
        frame.src = target + sep + 'easyopt_profile=1&eoptoken=' + encodeURIComponent(token) + '&eopass=' + pass;
        document.body.appendChild(frame);
    });
    const runProfile = async () => {
        const target = url.trim() || homeUrl || '/';
        setRunning(1);
        let last = null;
        let needFallback = false;
        // 0) Make sure the analyzer feature is enabled AND saved server-side
        //    before we call its REST routes. On a fresh enable the routes only
        //    register once the option is persisted, so without this a quick
        //    Test click can hit a not-yet-registered route ("could not start
        //    profiling"). Awaiting one idempotent save removes that race.
        try {
            await api.post('/settings', { changes: { easyopt_backend_analyzer: 1, [sub === 'callbacks' ? 'easyopt_backend_callbacks' : 'easyopt_backend_queries']: 1 } });
        }
        catch (e) { /* non-fatal: fall through and try anyway */ }
        // 1) Try the server-side loopback first. It's the most compatible path
        //    and security firewalls (Sucuri, Wordfence, Cloudflare WAF) trust a
        //    server-to-server request, so it isn't flagged the way a browser
        //    request with token query-params can be.
        try {
            for (let pass = 1; pass <= 3; pass++) {
                setRunning(pass);
                const r = await api.post('/backend/profile', { url: target, pass });
                if (!r || r.code) {
                    needFallback = true;
                    break;
                } // loopback blocked, or an admin URL
                last = r;
            }
        }
        catch (e) {
            needFallback = true;
        }
        // 2) Only if loopback couldn't do it (host blocks loopbacks, or it's a
        //    /wp-admin URL): fall back to a hidden browser iframe.
        if (needFallback) {
            try {
                last = null;
                const t = await api.post('/backend/token', {});
                if (!t || !t.token) {
                    toast('Couldn\u2019t start profiling. Make sure \u201CFind slow callbacks\u201D is on, give it a second to save, then try again.', 'error');
                    setRunning(0);
                    return;
                }
                for (let pass = 1; pass <= 3; pass++) {
                    setRunning(pass);
                    await iframePass(target, t.token, pass);
                }
                last = { status: 200, elapsed_ms: 0, url: target, url_hash: '' };
            }
            catch (e) {
                toast('Profile timed out — the page took over 60s to load.', 'error');
                setRunning(0);
                return;
            }
        }
        setRunning(0);
        setLastRun(last);
        if (last && last.url_hash)
            setSelUrl(last.url_hash);
        fetchResults(last && last.url_hash ? last.url_hash : undefined);
        toast('Profile complete.', 'ok');
    };
    const purge = (what) => {
        api.post('/backend/purge', { what }).then(() => { setCbData(null); setQData(null); setLastRun(null); toast('Profiling data cleared.', 'ok'); });
    };
    const phaseRows = (cbData && cbData.rows ? cbData.rows : []).filter((r) => r.hook.indexOf('_phase:') === 0);
    const hookRows = (cbData && cbData.rows ? cbData.rows : []).filter((r) => r.hook.indexOf('_phase:') !== 0);
    const compRows = (cbData && cbData.by_component ? cbData.by_component : []).filter((c) => c.component !== '');
    const activeOn = sub === 'callbacks' ? cbOn : qOn;
    return React.createElement("div", { style: { display: 'block' }, className: "eop-panel", id: "eop-panel-backend" }, React.createElement("h2", { className: "eop-section-h" }, "Backend Analyzer"), React.createElement("p", { className: "description", style: { marginBottom: '14px' } }, "Find out what slows your site down behind the scenes. Pick a URL and we run a few test loads of it — nothing runs on your normal visitors, so there's no slowdown for them."), React.createElement(SubTabNav, { tabs: SUBS, active: sub, onChange: pickSub }), sub === 'callbacks' && React.createElement("div", { style: { marginTop: '16px' } }, React.createElement(Check, { name: "easyopt_backend_callbacks", label: "Find slow callbacks", bold: true, settings: settings, onChange: (_n, v) => setSubFeature('easyopt_backend_callbacks', Number(v) === 1), desc: "Shows how much time each plugin and your theme adds while a page is being built, so you can spot the heavy ones." })), sub === 'queries' && React.createElement("div", { style: { marginTop: '16px' } }, React.createElement(Check, { name: "easyopt_backend_queries", label: "Find slow database queries", bold: true, settings: settings, onChange: (_n, v) => setSubFeature('easyopt_backend_queries', Number(v) === 1), desc: "Catches database lookups that take too long and points to what's responsible. All saved queries have their values hidden — nothing private is stored." }), qOn && React.createElement("div", { style: { display: 'flex', alignItems: 'center', gap: '8px', marginLeft: '22px', marginTop: '12px', flexWrap: 'nowrap' } }, React.createElement("label", { htmlFor: "eop-q-threshold", style: { whiteSpace: 'nowrap' } }, "Only record queries slower than"), React.createElement("input", { id: "eop-q-threshold", type: "number", min: "1", max: "5000", style: { width: '80px', flex: 'none' }, value: settings['easyopt_backend_query_threshold_ms'] || 50, onChange: (e) => saveOne('easyopt_backend_query_threshold_ms', e.target.value) }), React.createElement("span", { style: { color: 'var(--eop-text-soft, #6b7280)' } }, "ms"))), !activeOn && React.createElement("p", { className: "description", style: { marginTop: '14px', padding: '12px 14px', background: 'var(--eop-bg-alt, #fafafb)', border: '1px solid var(--eop-border-soft)', borderRadius: '8px' } }, "Turn this on above to start profiling."), activeOn && React.createElement(React.Fragment, null, React.createElement("h2", { className: "eop-section-h", style: { marginTop: '26px' } }, "Test a page"), React.createElement("p", { className: "description", style: { margin: '0 0 10px' } }, "We load this page a few times to measure it (the first load is just a warm-up and isn't counted). If your host blocks our background request, we automatically fall back to measuring it through your own browser. Admin pages (/wp-admin/) are always measured through your browser."), React.createElement("div", { style: { display: 'flex', gap: '8px', maxWidth: '640px' } }, React.createElement("input", { type: "url", placeholder: homeUrl, value: url, onChange: (e) => setUrl(e.target.value), style: { flex: 1 }, disabled: running > 0 }), React.createElement("button", { type: "button", className: "button button-primary", disabled: running > 0, onClick: runProfile }, running === 1 ? 'Warming up…' : running > 1 ? 'Test ' + (running - 1) + '/2…' : 'Test now')), lastRun && React.createElement("p", { className: "description", style: { marginTop: '6px' } }, lastRun.elapsed_ms > 0 ? 'Last load: ' + nf(lastRun.elapsed_ms) + ' ms (HTTP ' + lastRun.status + ').' : 'Test complete — see results below.'), React.createElement("div", { style: { display: 'flex', gap: '8px', alignItems: 'center', marginTop: '24px' } }, React.createElement("h2", { className: "eop-section-h", style: { margin: 0, flex: 1 } }, "Results"), React.createElement("button", { type: "button", className: "button button-small", onClick: () => fetchResults(selUrl) }, "Refresh"), React.createElement("button", { type: "button", className: "button button-small", style: { color: 'var(--eop-danger)' }, onClick: () => purge('all') }, "Clear data")), sub === 'callbacks' && React.createElement(React.Fragment, null, !cbData && React.createElement("p", { className: "description", style: { marginTop: '10px' } }, React.createElement("button", { type: "button", className: "button button-secondary", onClick: () => fetchResults() }, "Load results")), cbData && cbData.urls && cbData.urls.length > 0 && React.createElement("div", { style: { margin: '10px 0' } }, React.createElement("select", { value: selUrl, onChange: (e) => { setSelUrl(e.target.value); fetchResults(e.target.value); }, style: { maxWidth: '480px' } }, React.createElement("option", { value: "" }, "All tested pages"), cbData.urls.map((u) => React.createElement("option", { key: u.url_hash, value: u.url_hash }, u.url + ' — ' + nf(Math.round(u.sum_ms)) + ' ms')))), cbData && phaseRows.length > 0 && React.createElement("div", { style: { display: 'flex', gap: '8px', flexWrap: 'wrap', margin: '0 0 10px' } }, phaseRows.map((r) => React.createElement("span", { key: r.id, style: { background: 'var(--eop-bg-alt, #f1f5f9)', borderRadius: '6px', padding: '4px 10px', fontSize: '12px' } }, r.hook.replace('_phase:', '') + ': ', React.createElement("strong", null, nf(Math.round(r.total_ms)) + ' ms')))), cbData && hookRows.length === 0 && React.createElement("p", { className: "description", style: { marginTop: '10px' } }, "No data yet — run a test above."), cbData && compRows.length > 0 && React.createElement("div", { className: "eop-table-scroll" }, React.createElement("table", { className: "widefat striped", style: { marginTop: '4px', minWidth: '560px' } }, React.createElement("thead", null, React.createElement("tr", null, React.createElement("th", null, "Plugin / theme"), React.createElement("th", { style: { textAlign: 'right' } }, "Total ms"), React.createElement("th", { style: { textAlign: 'right' } }, "Steps"), React.createElement("th", { style: { textAlign: 'right' } }, "Slowest ms"), React.createElement("th", { style: { textAlign: 'right' } }, "Memory"))), React.createElement("tbody", null, compRows.slice(0, 60).map((c) => {
        const comp = c.component || '—';
        const isOpen = expandedComp === comp;
        const detail = hookRows.filter((r) => (r.component || '') === c.component).slice(0, 40);
        const label = comp === '(page render / core)' ? 'Page render / WordPress core'
            : comp === '(shared)' ? 'Shared (multiple plugins)'
                : comp === '(code after hook)' ? 'Theme / core code'
                    : comp === 'core' ? 'WordPress core'
                        : comp.indexOf('plugin:') === 0 ? 'Plugin: ' + comp.slice(7)
                            : comp.indexOf('theme:') === 0 ? 'Theme: ' + comp.slice(6)
                                : comp;
        return [
            React.createElement("tr", { key: comp, style: { cursor: 'pointer' }, onClick: () => setExpandedComp(isOpen ? '' : comp) }, React.createElement("td", null, React.createElement("strong", null, (isOpen ? '\u25BE ' : '\u25B8 ') + label)), React.createElement("td", { style: { textAlign: 'right', fontWeight: Number(c.total_ms) > 100 ? 700 : 400, color: Number(c.total_ms) > 100 ? 'var(--eop-danger)' : 'inherit' } }, nf(Math.round(c.total_ms * 10) / 10)), React.createElement("td", { style: { textAlign: 'right' } }, nf(c.steps)), React.createElement("td", { style: { textAlign: 'right' } }, nf(Math.round(c.max_ms * 10) / 10)), React.createElement("td", { style: { textAlign: 'right', fontSize: '12px' } }, c.mem_kb > 1024 ? (c.mem_kb / 1024).toFixed(1) + ' MB' : c.mem_kb + ' KB')),
            isOpen && React.createElement("tr", { key: comp + '_d' }, React.createElement("td", { colSpan: 5, style: { background: 'var(--eop-bg-alt, #f8fafc)', padding: '8px 12px' } }, detail.length === 0 ? React.createElement("span", { className: "description" }, "No individual steps recorded for this one.") :
                React.createElement("table", { className: "widefat", style: { margin: 0 } }, React.createElement("thead", null, React.createElement("tr", null, React.createElement("th", null, "Step / hook"), React.createElement("th", { style: { textAlign: 'right' } }, "Times run"), React.createElement("th", { style: { textAlign: 'right' } }, "Total ms"), React.createElement("th", { style: { textAlign: 'right' } }, "Slowest ms"))), React.createElement("tbody", null, detail.map((r) => React.createElement("tr", { key: r.id }, React.createElement("td", null, React.createElement("code", { style: { fontSize: '12px' } }, r.hook)), React.createElement("td", { style: { textAlign: 'right' } }, nf(r.calls)), React.createElement("td", { style: { textAlign: 'right' } }, nf(Math.round(r.total_ms * 10) / 10)), React.createElement("td", { style: { textAlign: 'right' } }, nf(Math.round(r.max_ms * 10) / 10))))))))
        ];
    })))), cbData && compRows.length > 0 && React.createElement("p", { className: "description", style: { marginTop: '6px', fontSize: '12px' } }, "Each row is one plugin or theme and the total time spent running its own code while the page was built — heaviest at the top. Click a row to see which steps. \u201CPage render / WordPress core\u201D is the time spent in WordPress itself (templates, options, database) that doesn\u2019t belong to any single plugin.")), sub === 'queries' && React.createElement(React.Fragment, null, !qData && React.createElement("p", { className: "description", style: { marginTop: '10px' } }, React.createElement("button", { type: "button", className: "button button-secondary", onClick: () => fetchResults() }, "Load results")), qData && (!qData.rows || qData.rows.length === 0) && React.createElement("p", { className: "description", style: { marginTop: '10px' } }, "No slow queries recorded yet — run a test above. Fast queries aren't stored."), qData && qData.rows && qData.rows.length > 0 && React.createElement("div", { className: "eop-table-scroll" }, React.createElement("table", { className: "widefat striped", style: { marginTop: '10px', minWidth: '640px' } }, React.createElement("thead", null, React.createElement("tr", null, React.createElement("th", null, "Query (values hidden)"), React.createElement("th", null, "Plugin / theme"), React.createElement("th", { style: { textAlign: 'right' } }, "Times"), React.createElement("th", { style: { textAlign: 'right' } }, "Total ms"), React.createElement("th", { style: { textAlign: 'right' } }, "Slowest ms"))), React.createElement("tbody", null, qData.rows.slice(0, 60).map((r) => React.createElement(React.Fragment, { key: r.id }, React.createElement("tr", { style: { cursor: 'pointer' }, onClick: () => setExpanded(expanded === r.fingerprint ? '' : r.fingerprint) }, React.createElement("td", null, React.createElement("code", { style: { fontSize: '11px' } }, (r.sample_sql || '').slice(0, 110) + ((r.sample_sql || '').length > 110 ? '…' : '')), r.findings && r.findings.length > 0 && React.createElement("span", { style: { marginLeft: '6px', background: '#fef3c7', color: '#92400e', borderRadius: '4px', padding: '1px 6px', fontSize: '10px' } }, r.findings.length + ' tip' + (r.findings.length > 1 ? 's' : ''))), React.createElement("td", { style: { fontSize: '12px' } }, r.component || '—'), React.createElement("td", { style: { textAlign: 'right' } }, nf(r.hits)), React.createElement("td", { style: { textAlign: 'right', fontWeight: Number(r.total_ms) > 200 ? 700 : 400, color: Number(r.total_ms) > 200 ? 'var(--eop-danger)' : 'inherit' } }, nf(Math.round(r.total_ms))), React.createElement("td", { style: { textAlign: 'right' } }, nf(Math.round(r.max_ms)))), expanded === r.fingerprint && React.createElement("tr", null, React.createElement("td", { colSpan: 5, style: { background: 'var(--eop-bg-alt, #f8fafc)' } }, React.createElement("code", { style: { fontSize: '11px', display: 'block', whiteSpace: 'pre-wrap', margin: '6px 0' } }, r.sample_sql), r.tables_used && React.createElement("p", { className: "description", style: { margin: '4px 0', fontSize: '12px' } }, "Tables: " + r.tables_used), r.caller && React.createElement("p", { className: "description", style: { margin: '4px 0', fontSize: '12px' } }, "Comes from: ", React.createElement("code", { style: { fontSize: '11px' } }, r.caller)), (r.findings || []).map((f, i) => React.createElement("p", { key: i, style: { margin: '4px 0', fontSize: '12px', color: '#92400e' } }, "\uD83D\uDCA1 " + f))))))))), React.createElement("p", { className: "description", style: { marginTop: '14px', fontSize: '12px' } }, "Results are kept for 14 days, then cleared automatically."))));
}
// ═══════════════════════════════════════════════════
//  SETTINGS PANEL — Import/Export + Delete on Uninstall
// ═══════════════════════════════════════════════════
function SettingsPanel({ settings, onChange, showToast: toast }) {
    const fileRef = useRef(null);
    const [importMsg, setImportMsg] = useState('');
    const [tracking, setTracking] = useState(!!CFG().trackingOptin);
    const toggleTracking = () => {
        const next = !tracking;
        setTracking(next);
        api.post('/tracking', { enabled: next }).then((r) => {
            if (!r || !r.ok) {
                setTracking(!next);
                toast('Could not update', 'error');
            }
            else {
                // This toggle saves itself instantly (consent must apply
                // immediately) — confirm it, since no Save bar will appear.
                toast(next ? 'Saved — diagnostics sharing enabled' : 'Saved — diagnostics sharing disabled', 'ok');
            }
        }).catch(() => { setTracking(!next); toast('Could not update', 'error'); });
    };
    // (2.5.7) Keys holding live third-party credentials. NEVER written to an
    // export file: these travel to support inboxes, forum posts, shared drives
    // and repos, because the UI calls the file a configuration backup. The
    // Redis set in particular exported host + port + username + password
    // together — everything needed to connect.
    // Mirrors secret_setting_keys() in class-easyopt-rest-dashboard.php.
    // (2.6.1) The four easyopt_cloud_* credentials are on both lists now: they
    // live in their own option but are still declared in the registry, so the
    // settings payload carries them as empty strings. Exporting those empties
    // and importing them back deleted the real credentials server-side.
    const SECRET_KEYS = [
        'easyopt_cf_api_token',
        'easyopt_oc_password',
        'easyopt_oc_username',
        'easyopt_fluxcdn_api_key',
        'easyopt_fluxcdn_activation_id',
        'easyopt_cloud_key',
        'easyopt_cloud_salt',
        'easyopt_cloud_token',
        'easyopt_cloud_license_key',
    ];
    const exportSettings = () => {
        const exported = { ...settings, _exported_at: new Date().toISOString(), _plugin_version: CFG().version };
        let redacted = 0;
        SECRET_KEYS.forEach((k) => {
            // Delete unconditionally, not only when truthy — an empty value is
            // exactly what must not reach the import side.
            if (k in exported) {
                if (exported[k]) {
                    redacted++;
                }
                delete exported[k];
            }
        });
        exported._redacted_keys = SECRET_KEYS;
        const blob = new Blob([JSON.stringify(exported, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `easy-optimizer-settings-${new Date().toISOString().split('T')[0]}.json`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        toast(redacted
            ? `Settings exported — ${redacted} credential${redacted > 1 ? 's' : ''} left out for safety. Re-enter them on the destination site.`
            : 'Settings exported', 'ok');
    };
    const importSettings = (e) => {
        const file = e.target.files?.[0];
        if (!file)
            return;
        setImportMsg('Importing…');
        const reader = new FileReader();
        reader.onload = (ev) => {
            try {
                const parsed = JSON.parse(ev.target.result);
                // Remove metadata keys
                delete parsed._exported_at;
                delete parsed._plugin_version;
                api.post('/settings/import', { settings: parsed }).then((r) => {
                    if (r?.success && r?.settings) {
                        // Force full reload of settings
                        window.location.reload();
                    }
                    else {
                        setImportMsg(r?.message || 'Import failed.');
                        setTimeout(() => setImportMsg(''), 4000);
                    }
                }).catch(() => { setImportMsg('Import failed.'); setTimeout(() => setImportMsg(''), 4000); });
            }
            catch {
                setImportMsg('Invalid JSON file.');
                setTimeout(() => setImportMsg(''), 4000);
            }
        };
        reader.readAsText(file);
        // Reset input so re-importing the same file works
        e.target.value = '';
    };
    return (React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-settings" }, React.createElement("h2", { style: { marginBottom: '20px' } }, "Settings"), React.createElement("div", { className: "eop-settings-card" }, React.createElement("h3", { style: { margin: '0 0 8px' } }, "Google PageSpeed"), React.createElement("p", { className: "description", style: { marginBottom: '14px' } }, "The Dashboard's speed test compares your site's Google PageSpeed score with and without Easy Optimizer's improvements."), React.createElement("div", { style: { marginBottom: '8px' } }, React.createElement("label", { style: { display: 'block', fontWeight: 600, marginBottom: '6px' } }, "Google API key ", React.createElement("span", { style: { fontWeight: 400, color: 'var(--eop-text-soft, #6b7280)' } }, "(optional)")), React.createElement("input", { type: "text", style: { width: '100%', maxWidth: '460px', display: 'block' }, placeholder: "Paste your key here for unlimited tests", value: settings['easyopt_psi_api_key'] || '', onChange: (e) => onChange('easyopt_psi_api_key', e.target.value) }), React.createElement("p", { className: "description", style: { marginTop: '6px' } }, "You can run 3 free speed tests per day without any setup. Want unlimited tests? Get a free key from Google: visit console.cloud.google.com, enable \"PageSpeed Insights API\", create an API key, and paste it above. Takes about 2 minutes."))), React.createElement("div", { className: "eop-settings-card" }, React.createElement("h3", { style: { margin: '0 0 8px' } }, "Uninstall Behavior"), React.createElement(Check, { name: "easyopt_delete_on_uninstall", label: "Delete all plugin data on uninstall", bold: true, settings: settings, onChange: onChange, desc: "When enabled, deleting the plugin from WordPress will remove all settings, custom tables, cache files, and the caching helper file Easy Optimizer installed (advanced-cache.php). Disable this if you plan to reinstall later and want to keep your configuration." })), React.createElement("div", { style: { marginBottom: '28px', paddingTop: '18px', borderTop: '1px solid var(--eop-border-soft)' } }, React.createElement("h3", { style: { margin: '0 0 8px' } }, "Debug Logging"), React.createElement("p", { className: "description", style: { marginBottom: '14px' } }, "Controls what Easy Optimizer records to its Debug Log (open it from the button in the top bar). Logging only happens on rare events, so it has no measurable performance cost."), React.createElement(Check, { name: "easyopt_log_errors", label: "Log Errors", bold: true, settings: settings, onChange: onChange, desc: "Records failures such as cache-write failures, background-task failures, and .htaccess write failures. Recommended — leave enabled." }), React.createElement(Check, { name: "easyopt_log_warnings", label: "Log Warnings", bold: true, settings: settings, onChange: onChange, desc: "Records early-warning signals such as excessive cache purges, a stalled or looping preload queue, and an unconfirmed Apache serving mode. Useful for diagnosing cache/preload issues." }), React.createElement("div", { style: { marginTop: '18px', padding: '12px 14px', background: 'var(--eop-bg-alt)', border: '1px solid var(--eop-border-soft)', borderRadius: 'var(--eop-radius-sm)' } }, React.createElement("strong", { style: { display: 'block', marginBottom: '6px' } }, "Query-string debug switches"), React.createElement("p", { className: "description", style: { margin: '0 0 8px' } }, "Add one of these parameters to any front-end URL to disable a single feature for that one request only — without changing any settings. Handy for checking whether a feature is causing an issue. The switched request is never served from or written to the page cache, so you always see fresh output."), React.createElement("ul", { style: { margin: '0', paddingLeft: '18px', fontSize: '13px', lineHeight: 1.7 } }, React.createElement("li", null, React.createElement("code", null, "?nooptimize"), " — disable ", React.createElement("em", null, "all"), " optimizations for this request"), React.createElement("li", null, React.createElement("code", null, "?nocache"), " — bypass the page cache for this request"), React.createElement("li", null, React.createElement("code", null, "?nodelayjs"), " — don't delay/defer JavaScript for this request"), React.createElement("li", null, React.createElement("code", null, "?norucss"), " — don't apply Remove Unused CSS for this request")), React.createElement("p", { className: "description", style: { margin: '8px 0 0', fontSize: '12px' } }, "Example: ", React.createElement("code", null, "https://example.com/?nodelayjs"), " (use ", React.createElement("code", null, "&"), " instead of ", React.createElement("code", null, "?"), " if the URL already has a query string, e.g. ", React.createElement("code", null, "?ver=2&nooptimize"), ")."))), React.createElement("div", { style: { paddingTop: '18px', borderTop: '1px solid var(--eop-border-soft)' } }, React.createElement("h3", { style: { margin: '0 0 8px' } }, "Import / Export Settings"), React.createElement("p", { className: "description", style: { marginBottom: '14px' } }, "Transfer your Easy Optimizer configuration between sites or create a backup before making changes."), React.createElement("div", { style: { display: 'flex', gap: '12px', alignItems: 'center', flexWrap: 'wrap' } }, React.createElement("button", { type: "button", className: "button button-secondary", onClick: exportSettings }, React.createElement(Icon, { id: "download", cls: "eop-icon eop-icon--sm" }), " Export Settings"), React.createElement("button", { type: "button", className: "button button-secondary", onClick: () => fileRef.current?.click() }, React.createElement(Icon, { id: "upload", cls: "eop-icon eop-icon--sm" }), " Import Settings"), React.createElement("input", { ref: fileRef, type: "file", accept: ".json", style: { display: 'none' }, onChange: importSettings }), importMsg && React.createElement("span", { style: { fontSize: '13px', color: importMsg.includes('fail') || importMsg.includes('Invalid') ? 'var(--eop-danger)' : 'var(--eop-text-muted)' } }, importMsg)), React.createElement("p", { className: "description", style: { marginTop: '10px', fontSize: '12px' } }, "Importing will overwrite all current settings and reload the page.")), React.createElement("div", { style: { marginTop: '28px', paddingTop: '18px', borderTop: '1px solid var(--eop-border-soft)' } }, React.createElement("label", null, React.createElement("input", { type: "checkbox", id: "easyopt_help_improve", checked: tracking, onChange: toggleTracking }), " ", React.createElement("strong", null, "Help improve Easy Optimizer")), React.createElement("p", { className: "description" }, "Allow Easy Optimizer to collect non-sensitive diagnostic data. This helps us improve compatibility and deliver new features faster. Saves instantly — no need to press Save."))));
}
// ═══════════════════════════════════════════════════
//  DEBUG LOG DRAWER (2.1.1)
// ═══════════════════════════════════════════════════
function DebugLogDrawer({ entries, counts, loading, onClose, onClear, onRefresh }) {
    return createElement('div', {
        style: {
            position: 'fixed', top: 0, right: 0, bottom: 0, width: '460px', maxWidth: '100vw',
            background: 'var(--eop-bg, #fff)', boxShadow: '-4px 0 24px rgba(0,0,0,0.12)',
            zIndex: 100000, display: 'flex', flexDirection: 'column', borderLeft: '1px solid var(--eop-border, #e2e8f0)'
        }
    }, createElement('div', {
        style: {
            display: 'flex', alignItems: 'center', justifyContent: 'space-between',
            padding: '16px 20px', borderBottom: '1px solid var(--eop-border, #e2e8f0)',
            flexShrink: 0
        }
    }, createElement('div', { style: { display: 'flex', alignItems: 'center', gap: '10px' } }, createElement('strong', { style: { fontSize: '15px' } }, 'Debug Log'), counts.errors > 0 && createElement('span', {
        style: { background: '#fef2f2', color: '#dc2626', fontSize: '12px', fontWeight: 600, padding: '2px 8px', borderRadius: '10px' }
    }, counts.errors + ' error' + (counts.errors !== 1 ? 's' : '')), counts.warnings > 0 && createElement('span', {
        style: { background: '#fffbeb', color: '#d97706', fontSize: '12px', fontWeight: 600, padding: '2px 8px', borderRadius: '10px' }
    }, counts.warnings + ' warning' + (counts.warnings !== 1 ? 's' : ''))), createElement('div', { style: { display: 'flex', gap: '6px' } }, createElement('button', { onClick: onRefresh, className: 'eop-btn eop-btn--ghost eop-btn--sm', disabled: loading }, loading ? 'Loading…' : 'Refresh'), entries.length > 0 && createElement('button', { onClick: onClear, className: 'eop-btn eop-btn--ghost eop-btn--sm', style: { color: '#dc2626' } }, 'Clear'), createElement('button', { onClick: onClose, className: 'eop-btn eop-btn--ghost eop-btn--sm' }, '✕'))), createElement('div', {
        style: { flex: 1, overflowY: 'auto', padding: '12px 20px', fontSize: '12.5px', fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace', lineHeight: '1.6' }
    }, entries.length === 0
        ? createElement('div', { style: { color: 'var(--eop-text-muted, #94a3b8)', textAlign: 'center', padding: '40px 0', fontSize: '13px', fontFamily: 'inherit' } }, 'No warnings or errors logged.')
        : entries.map((e, i) => createElement('div', {
            key: i,
            style: {
                padding: '8px 10px', marginBottom: '4px', borderRadius: '6px',
                background: e.level === 'error' ? '#fef2f2' : '#fffbeb',
                borderLeft: '3px solid ' + (e.level === 'error' ? '#ef4444' : '#f59e0b')
            }
        }, createElement('div', { style: { display: 'flex', justifyContent: 'space-between', marginBottom: '3px' } }, createElement('span', {
            style: { fontWeight: 700, textTransform: 'uppercase', fontSize: '10px', letterSpacing: '0.5px',
                color: e.level === 'error' ? '#dc2626' : '#d97706' }
        }, e.level), createElement('span', { style: { color: '#94a3b8', fontSize: '11px' } }, e.time)), createElement('div', { style: { color: '#334155' } }, createElement('span', { style: { color: '#6366f1', fontWeight: 600 } }, '[' + e.subsystem + '] '), e.message)))));
}
// ═══════════════════════════════════════════════════
//  DEBUG ISSUES DRAWER (2.6.0)
// ═══════════════════════════════════════════════════
// easyopt_debug_switch() (easy-optimizer.php) has read per-request query
// switches for several releases, but nothing in the UI ever mentioned them, so
// the only self-service tools a user had when a page broke were "toggle
// settings one at a time and clear cache" or Safe Mode, which rewrites their
// settings. This panel exposes the switches and, more importantly, turns the
// answer into a next action.
//
// Deliberately limited to the FOUR switches that already exist. Each maps to a
// whole category (everything / cache / JS / CSS), which is the level a
// non-expert can reason about; a longer list would be a worse tool, not a more
// powerful one, and adding switches would mean touching every module's render
// gate for no diagnostic gain.
const EOP_DEBUG_SWITCHES = [
    {
        key: 'nooptimize',
        label: 'All page optimizations',
        note: 'Minify, CSS, JS, images, fonts — the whole pipeline',
        opt: null,
    },
    {
        key: 'nocache',
        label: 'Page cache',
        note: 'Force a fresh render instead of the stored HTML',
        opt: 'easyopt_cache',
        tab: 'cache',
    },
    {
        key: 'nodelayjs',
        label: 'Delay / Defer JavaScript',
        note: 'The most common cause of broken sliders, menus and forms',
        opt: 'easyopt_delay_js',
        tab: 'optimization',
        sub: 'js',
    },
    {
        key: 'norucss',
        label: 'Remove Unused CSS',
        note: 'The most common cause of missing styles and layout shifts',
        opt: 'easyopt_unused_css',
        tab: 'optimization',
        sub: 'css',
    },
];
function eopBuildDebugUrl(base, keys) {
    if (!base) {
        return '';
    }
    const clean = String(base).split('#')[0];
    const list = (keys || []).filter(Boolean);
    if (!list.length) {
        return clean;
    }
    // nocache is emitted FIRST when present. It is matched as a plain substring
    // in the URL-exclusion list, and while 2.6.0 also accepts '&nocache', the
    // leading position stays the most compatible with older drop-in files that
    // a site may not have regenerated yet.
    const ordered = list.slice().sort((a, b) => (a === 'nocache' ? -1 : b === 'nocache' ? 1 : 0));
    // (2.6.0) `eopview` rides along on every generated link. Delay JS and
    // Defer JS skip administrators, and Remove Unused CSS and LCP Preload skip
    // any logged-in user, so without it a test run from an admin session
    // switches off things that were never on — the page looks unchanged and
    // the user concludes, wrongly, that the feature is not the cause. See the
    // easyopt_view_as_visitor block in easy-optimizer.php.
    return clean + (clean.indexOf('?') === -1 ? '?' : '&') + ordered.join('&') + '&eopview=1';
}
function DebugIssuesDrawer({ settings, onClose, switchTab }) {
    const home = CFG().homeUrl || CFG().siteUrl || '';
    const [url, setUrl] = useState(home);
    const [manual, setManual] = useState({});
    const [asked, setAsked] = useState(false);
    const [answered, setAnswered] = useState('');
    const [copied, setCopied] = useState(false);
    const [status, setStatus] = useState(null);
    const [statusBusy, setStatusBusy] = useState(false);
    // One call when the panel opens, one per manual re-check. Two file_exists()
    // server-side; never polled.
    const checkStatus = useCallback((target) => {
        if (!target) {
            return;
        }
        setStatusBusy(true);
        api.get('/debug/url-status?url=' + encodeURIComponent(target))
            .then((r) => setStatus(r || null))
            .finally(() => setStatusBusy(false));
    }, []);
    useEffect(() => { checkStatus(home); }, []);
    const selected = EOP_DEBUG_SWITCHES.filter((s) => manual[s.key]);
    const manualKeys = selected.map((s) => s.key);
    const manualUrl = eopBuildDebugUrl(url, manualKeys);
    // The result step can only name a culprit when exactly ONE feature was
    // switched off. `nooptimize` disables everything at once, so it proves the
    // plugin is involved but not which part.
    const featureOnly = selected.filter((s) => s.key !== 'nooptimize' && s.key !== 'nocache');
    const culprit = (1 === featureOnly.length && featureOnly[0].tab) ? featureOnly[0] : null;
    const open = (target) => {
        if (!target) {
            return;
        }
        window.open(target, '_blank', 'noopener');
        setAsked(true);
        setAnswered('');
    };
    const copy = () => {
        if (!manualUrl) {
            return;
        }
        const done = () => { setCopied(true); setTimeout(() => setCopied(false), 1800); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(manualUrl).then(done).catch(() => { });
            return;
        }
        // http:// admin origins get no navigator.clipboard — it is a
        // secure-context API — and plenty of self-hosted installs are exactly
        // that. Fall back rather than silently doing nothing.
        try {
            const ta = document.createElement('textarea');
            ta.value = manualUrl;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            done();
        }
        catch (e) { /* clipboard unavailable — the field is selectable */ }
    };
    const sectionTitle = (t) => createElement('div', {
        style: {
            fontSize: '11px', fontWeight: 800, letterSpacing: '.07em',
            textTransform: 'uppercase', color: 'var(--eop-mute, #94a3b8)', marginBottom: '8px'
        }
    }, t);
    const divider = () => createElement('div', {
        style: { height: '1px', background: 'var(--eop-line, #e6e8ee)', margin: '18px 0' }
    });
    // ── Cache status line ──
    let statusNode = null;
    if (status) {
        const cacheOff = Number(status.cache_enabled) !== 1;
        const hit = !!status.desktop || !!status.mobile;
        const tone = cacheOff || status.excluded ? 'warn' : (hit ? 'ok' : 'warn');
        const colours = {
            ok: { bg: 'var(--eop-ok-soft, #d1fae5)', fg: 'var(--eop-ok-fg, #047857)', dot: 'var(--eop-ok, #10b981)' },
            warn: { bg: 'var(--eop-warn-soft, #fef3c7)', fg: 'var(--eop-warn-fg, #92400e)', dot: 'var(--eop-warn, #f59e0b)' }
        };
        const c = colours[tone];
        let text;
        if (cacheOff) {
            text = 'Page cache is switched off, so this page is rendered fresh every time.';
        }
        else if (status.excluded) {
            text = 'This URL matches a cache exclusion rule, so it is never cached.';
        }
        else if (hit) {
            const both = Number(status.separate_mobile) === 1;
            text = both
                ? 'In cache — ' + (status.desktop && status.mobile ? 'desktop and mobile' : (status.desktop ? 'desktop only' : 'mobile only'))
                : 'In cache.';
        }
        else {
            text = 'Not in cache yet. Visit it once, or run a preload, before judging its speed.';
        }
        statusNode = createElement('div', {
            style: {
                display: 'flex', alignItems: 'flex-start', gap: '8px', marginTop: '10px',
                padding: '9px 11px', borderRadius: 'var(--eop-r-sm, 8px)',
                background: c.bg, color: c.fg, fontSize: '12.5px', lineHeight: 1.45, fontWeight: 500
            }
        }, createElement('span', {
            style: { width: 7, height: 7, borderRadius: '50%', background: c.dot, flexShrink: 0, marginTop: '6px' }
        }), createElement('span', null, text));
    }
    // ── Result capture ──
    // Only appears once a test link has actually been opened. Kept to a single
    // question, because the value of this panel is not the URL builder — it is
    // turning "yes, that fixed it" into the exclusion field that makes the fix
    // permanent.
    let resultNode = null;
    if (asked) {
        resultNode = createElement('div', { style: { marginTop: '14px' } }, !answered && createElement('div', null, createElement('div', {
            style: { fontSize: '13px', fontWeight: 700, marginBottom: '8px' }
        }, 'Did that fix the problem?'), createElement('div', { style: { display: 'flex', gap: '8px' } }, createElement('button', {
            type: 'button', className: 'eop-btn eop-btn--soft eop-btn--sm', onClick: () => setAnswered('yes')
        }, 'Yes'), createElement('button', {
            type: 'button', className: 'eop-btn eop-btn--ghost eop-btn--sm', onClick: () => setAnswered('no')
        }, 'No, still broken'))), answered && createElement('div', {
            style: {
                border: '1px solid ' + ('yes' === answered ? 'var(--eop-ok, #10b981)' : 'var(--eop-warn, #f59e0b)'),
                background: 'yes' === answered ? 'var(--eop-ok-soft, #d1fae5)' : 'var(--eop-warn-soft, #fef3c7)',
                borderRadius: 'var(--eop-r-sm, 8px)', padding: '12px 13px'
            }
        }, createElement('div', {
            style: { fontSize: '12.5px', lineHeight: 1.5, color: 'var(--eop-text, #0f172a)' }
        }, 'yes' === answered
            ? (culprit
                ? createElement('span', null, createElement('strong', null, culprit.label + ' is the cause.'), ' Add the file or selector that breaks to its exclusion list to keep the fix.')
                : 'Easy Optimizer is involved. Switch one item off at a time to find which.')
            : (manual.nooptimize
                ? 'With every page optimization off the page is still wrong, so the cause is your theme, another plugin, or the server.'
                : 'Try another switch, or tick "All page optimizations" to rule Easy Optimizer out entirely.')), createElement('div', {
            style: { display: 'flex', gap: '8px', marginTop: '10px', flexWrap: 'wrap' }
        }, 'yes' === answered && culprit && createElement('button', {
            type: 'button', className: 'eop-btn eop-btn--primary eop-btn--sm',
            onClick: () => { switchTab(culprit.tab, culprit.sub || undefined); onClose(); }
        }, 'Open ' + culprit.label + ' settings'), createElement('button', {
            type: 'button', className: 'eop-btn eop-btn--ghost eop-btn--sm',
            onClick: () => { setAsked(false); setAnswered(''); }
        }, 'Test something else'))));
    }
    return createElement('div', {
        style: {
            position: 'fixed', top: 0, right: 0, bottom: 0, width: '460px', maxWidth: '100vw',
            background: 'var(--eop-bg, #fff)', boxShadow: '-4px 0 24px rgba(0,0,0,0.12)',
            zIndex: 100000, display: 'flex', flexDirection: 'column',
            borderLeft: '1px solid var(--eop-border, #e2e8f0)'
        }
    }, 
    // Header — mirrors DebugLogDrawer so the two read as one family.
    createElement('div', {
        style: {
            display: 'flex', alignItems: 'center', justifyContent: 'space-between',
            padding: '16px 20px', borderBottom: '1px solid var(--eop-border, #e2e8f0)', flexShrink: 0
        }
    }, createElement('div', { style: { display: 'flex', alignItems: 'center', gap: '10px' } }, createElement(Icon, { id: 'wrench', cls: 'eop-icon eop-icon--sm' }), createElement('strong', { style: { fontSize: '15px' } }, 'Debug Issues')), createElement('button', {
        onClick: onClose, className: 'eop-btn eop-btn--ghost eop-btn--sm', 'aria-label': 'Close'
    }, '✕')), 
    // Body
    createElement('div', { style: { flex: 1, overflowY: 'auto', padding: '18px 20px' } }, 
    // ── 1. Which page ──
    sectionTitle('Page to test'), createElement('input', {
        type: 'url', value: url, spellCheck: false,
        onChange: (e) => setUrl(e.target.value),
        onBlur: () => checkStatus(url),
        style: { width: '100%', boxSizing: 'border-box', fontSize: '13px' },
        placeholder: home
    }), statusBusy
        ? createElement('div', { style: { marginTop: '10px', fontSize: '12px', color: 'var(--eop-mute, #94a3b8)' } }, 'Checking cache status…')
        : statusNode, divider(), 
    // ── 2. The switches. This IS the tool; everything else supports it. ──
    sectionTitle('Switch things off yourself'), createElement('div', null, EOP_DEBUG_SWITCHES.map((s) => {
        // A feature that is already off cannot be the cause, so its switch
        // would prove nothing. Shown greyed rather than hidden: a user whose
        // settings drifted needs to see that it IS off, and a panel that
        // changes shape between sites is harder to compare in a support
        // thread. nooptimize has no single setting behind it (opt: null) and
        // is therefore always available.
        const off = s.opt ? Number(settings[s.opt]) !== 1 : false;
        return createElement('label', {
            key: s.key,
            style: {
                display: 'flex', alignItems: 'flex-start', gap: '10px', padding: '9px 0',
                borderBottom: '1px solid var(--eop-line-soft, #f0f2f6)',
                cursor: off ? 'not-allowed' : 'pointer', opacity: off ? 0.55 : 1
            }
        }, createElement('input', {
            type: 'checkbox', checked: !!manual[s.key], disabled: off,
            style: { margin: '2px 0 0' },
            onChange: () => setManual((p) => ({ ...p, [s.key]: !p[s.key] }))
        }), createElement('span', null, createElement('span', {
            style: { display: 'block', fontSize: '13px', fontWeight: 600 }
        }, s.label, off && createElement('span', {
            style: { marginLeft: '6px', fontSize: '11px', fontWeight: 600, color: 'var(--eop-mute, #94a3b8)' }
        }, '· already off')), createElement('span', {
            style: { display: 'block', fontSize: '11.5px', color: 'var(--eop-text-muted, #475569)', marginTop: '2px', lineHeight: 1.4 }
        }, s.note)));
    })), 
    // ── 3. The generated URL ──
    manualKeys.length > 0 && createElement('div', { style: { marginTop: '14px' } }, createElement('div', {
        style: {
            fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
            fontSize: '11.5px', wordBreak: 'break-all', background: 'var(--eop-bg-alt, #f6f7f9)',
            border: '1px solid var(--eop-line, #e6e8ee)', borderRadius: 'var(--eop-r-sm, 8px)',
            padding: '10px 11px', color: 'var(--eop-text, #0f172a)', lineHeight: 1.5
        }
    }, manualUrl), createElement('div', { style: { display: 'flex', gap: '8px', marginTop: '9px' } }, createElement('button', {
        type: 'button', className: 'eop-btn eop-btn--soft eop-btn--sm', onClick: () => open(manualUrl)
    }, 'Open in new tab'), createElement('button', {
        type: 'button', className: 'eop-btn eop-btn--ghost eop-btn--sm', onClick: copy
    }, copied ? 'Copied' : 'Copy link'))), 
    // ── 4. Result capture — the step that turns an answer into a fix ──
    resultNode, divider(), 
    // ── 5. Why these links work from a logged-in session ──
    // Replaces the old "use a private window" instruction. Every generated
    // link carries &eopview=1, which makes this one request render the way a
    // logged-out visitor sees it (see easy-optimizer.php).
    createElement('div', {
        style: {
            display: 'flex', gap: '9px', alignItems: 'flex-start', padding: '11px 12px',
            background: 'var(--eop-brand-soft-2, #f4f2ff)', border: '1px solid var(--eop-brand-soft, #eeebff)',
            borderRadius: 'var(--eop-r-sm, 8px)'
        }
    }, createElement(Icon, { id: 'info', cls: 'eop-icon eop-icon--sm' }), createElement('div', {
        style: { fontSize: '12.5px', lineHeight: 1.5, color: 'var(--eop-text, #0f172a)' }
    }, createElement('strong', null, 'These links show the visitor version.'), ' Normally some optimizations are skipped while you are logged in, so no private window is needed — just open the link.'))));
}
// ═══════════════════════════════════════════════════
//  MAIN APP
// ═══════════════════════════════════════════════════
// ═══════════════════════════════════════════════════
//  OBJECT CACHE PANEL (2.4.7)
// ═══════════════════════════════════════════════════
function ocBytes(n) {
    if (!n || n <= 0)
        return '—';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0, v = n;
    while (v >= 1024 && i < u.length - 1) {
        v /= 1024;
        i++;
    }
    return v.toFixed(v >= 10 || i === 0 ? 0 : 1) + ' ' + u[i];
}
function OcChip({ ok, label, note, tone }) {
    var t = tone || (ok ? 'ok' : 'off');
    var palette = {
        ok: { fg: '#0c9d57', bg: 'rgba(12,157,87,.10)', dot: '#0c9d57' },
        warn: { fg: '#b45309', bg: 'rgba(217,119,6,.12)', dot: '#f59e0b' },
        off: { fg: 'var(--eop-text-muted, #6b7280)', bg: 'var(--eop-bg-alt, #f6f7f9)', dot: '#c4c8cf' }
    }[t];
    return React.createElement("span", { style: {
            display: 'inline-flex', alignItems: 'center', gap: '6px',
            padding: '4px 10px', borderRadius: '20px', fontSize: '12px', fontWeight: 600,
            border: '1px solid var(--eop-border, #e3e5e8)',
            background: palette.bg,
            color: palette.fg,
        } }, React.createElement("span", { style: { width: '8px', height: '8px', borderRadius: '50%', background: palette.dot } }), label, note ? React.createElement("span", { style: { opacity: .7, fontWeight: 400 } }, note) : null);
}
function OcRedisWarning({ error, errorCode, errorHint, onCloudways, wooActive }) {
    // Title + guidance branch on the server-side error classification, so we
    // never tell someone with a NOAUTH error that "no server is running".
    const titles = {
        auth_required: 'Redis requires authentication',
        auth_failed: 'Redis credentials are incorrect',
        auth_noperm: 'Redis user lacks permission',
        bad_database: 'Invalid Redis database number',
        loading: 'Redis is starting up',
        ext_missing: 'No Redis client available',
        no_server: "Redis isn't connected",
    };
    const title = titles[errorCode] || "Redis isn't connected";
    const isAuth = errorCode === 'auth_required' || errorCode === 'auth_failed' || errorCode === 'auth_noperm';
    // "No server reachable" and the unknown/unclassified bucket both mean the
    // same thing to a site owner — nothing is answering — so they share the
    // promo block below. The 6 specific codes (auth*, bad_database, loading,
    // ext_missing) keep their own hint and show NO promo.
    const isNoServer = errorCode === 'no_server' || !errorCode;
    // The hosting suggestion is shown only for no_server/unknown AND only when
    // the site isn't already on Cloudways (they'd just add Redis in-console).
    const showHostCta = isNoServer && !onCloudways;
    return React.createElement("div", { style: { marginTop: '14px', padding: '14px 16px', borderRadius: '10px', background: 'rgba(217,119,6,.08)', border: '1px solid rgba(217,119,6,.35)' } }, React.createElement("div", { style: { display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 700, color: '#b45309', marginBottom: '6px', fontSize: '14px' } }, React.createElement(Icon, { id: "warn", cls: "eop-icon eop-icon--sm" }), title), 
    // Actionable hint from the server-side classifier. For no_server/
    // unknown when a hosting suggestion will follow, we lead with the
    // promo copy instead of the generic classifier line (they say the
    // same thing; the promo copy says it better). Auth cases keep the
    // classifier hint plus the ACL tip.
    React.createElement("p", { style: { margin: '0 0 8px', fontSize: '13px', color: 'var(--eop-text, #374151)', lineHeight: 1.55 } }, showHostCta
        ? React.createElement(Fragment, null, "Most shared hosting plans include the Redis PHP extension—but not an actual Redis server—so the connection can't be established. ", wooActive
            ? "Redis keeps sessions and frequently accessed data in memory, helping deliver a faster checkout experience. "
            : "Redis-ready hosting reduces database load and improves backend response times. ", React.createElement("a", { href: "https://www.cloudways.com/en/wordpress-hosting.php?id=662383&a_bid=4869f424#basic", target: "_blank", rel: "sponsored nofollow noopener", style: { color: 'var(--eop-accent, #5b4ee8)', fontWeight: 700, textDecoration: 'underline', whiteSpace: 'nowrap' } }, "Try Redis-Ready Hosting \u2192"))
        : React.createElement(Fragment, null, errorHint || "Redis could not be reached with the current settings. Check the connection details below, then use Test Connection.", isAuth ? React.createElement(Fragment, null, " ", React.createElement("strong", null, "Tip:"), " on Cloudways and other Redis 6+ ACL hosts, copy the Username and Password (and the assigned Database number) from your hosting panel into the fields above.") : null)), 
    // Raw error for support/debugging — small and unmissable-by-devs.
    error ? React.createElement("p", { style: { margin: onCloudways && isNoServer ? '0 0 8px' : 0, fontSize: '12px', color: 'var(--eop-text-muted, #6b7280)', fontFamily: 'var(--eop-mono, monospace)', lineHeight: 1.5, wordBreak: 'break-word' } }, error) : null, (onCloudways && isNoServer)
        ? React.createElement("p", { style: { margin: 0, fontSize: '13px', color: 'var(--eop-text-soft, #6b7280)', lineHeight: 1.55 } }, "Your server (Cloudways) can run Redis — add it from your Cloudways console, then enter the connection details above or set WP_REDIS_* in wp-config.php.")
        : null);
}
function OcStat({ label, value }) {
    return React.createElement("div", { style: { display: 'flex', justifyContent: 'space-between', padding: '9px 0', borderBottom: '1px solid var(--eop-border, #eceef2)', fontSize: '13px' } }, React.createElement("span", { style: { color: 'var(--eop-text-muted, #6b7280)' } }, label), React.createElement("span", { style: { fontWeight: 600, fontFamily: 'var(--eop-mono, monospace)' } }, value));
}
function ObjectCachePanel({ settings, onChange, showToast }) {
    const on = Number(settings.easyopt_object_cache) === 1;
    const [status, setStatus] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState('');
    const [msg, setMsg] = useState('');
    const [msgColor, setMsgColor] = useState('');
    const load = useCallback(() => {
        setLoading(true);
        api.get('/objectcache/status').then((r) => {
            if (r && r.status)
                setStatus(r.status);
            setLoading(false);
        }).catch(() => setLoading(false));
    }, []);
    useEffect(() => { load(); }, []);
    // Refresh status once after a save that touched Object Cache settings — no
    // polling (a dead Redis would hang each poll ~1s). The drop-in is installed
    // server-side during the save, so this next request reflects the new state.
    useEffect(() => {
        const onSaved = (e) => {
            const ks = (e && e.detail && e.detail.keys) || [];
            if (ks.some((k) => k === 'easyopt_object_cache' || k.indexOf('easyopt_oc_') === 0)) {
                load();
            }
        };
        window.addEventListener('easyopt:saved', onSaved);
        return () => window.removeEventListener('easyopt:saved', onSaved);
    }, []);
    const doFlush = () => {
        setBusy('flush');
        api.post('/objectcache/flush').then((r) => {
            setBusy('');
            if (r && r.status)
                setStatus(r.status);
            if (showToast)
                showToast(r && r.message ? r.message : 'Done', r && r.success ? 'ok' : 'error');
        }).catch(() => { setBusy(''); if (showToast)
            showToast('Flush failed', 'error'); });
    };
    const doTest = () => {
        setBusy('test');
        setMsg('Testing…');
        setMsgColor('');
        api.post('/objectcache/test', {
            client: settings.easyopt_oc_client || 'auto',
            host: settings.easyopt_oc_host || '',
            port: settings.easyopt_oc_port || '',
            username: settings.easyopt_oc_username || '',
            password: settings.easyopt_oc_password || '',
            database: settings.easyopt_oc_database || '',
            prefix: settings.easyopt_oc_prefix || '',
            tls: Number(settings.easyopt_oc_tls) === 1 ? 1 : 0,
        }).then((r) => {
            setBusy('');
            setMsg(r && r.message ? r.message : (r && r.success ? 'OK' : 'Failed'));
            setMsgColor(r && r.success ? 'var(--eop-success)' : 'var(--eop-danger)');
            load();
        }).catch(() => { setBusy(''); setMsg('Test failed'); setMsgColor('var(--eop-danger)'); });
    };
    const d = (status && status.detect) || {};
    const foreign = !!d.dropin_foreign;
    const ocActive = !!(status && status.active);
    const ocConnected = !!(status && status.connected);
    const redisDown = ocActive && !ocConnected && !!d.dropin_ours;
    const onCloudways = !!(CFG().onCloudways);
    const configSources = (status && status.config_sources) || {};
    const lockedKeys = Object.keys(configSources);
    const hasWrc = !!(status && status.has_wp_redis_config);
    return React.createElement("div", { style: { display: "block" }, className: "eop-panel", id: "eop-panel-objectcache" }, React.createElement("h2", null, "Object Cache"), React.createElement("p", { className: "description", style: { marginBottom: '18px' } }, "Persistent Redis object cache. Speeds up database-heavy, logged-in and WooCommerce pages by keeping query and option lookups in Redis. Supports Relay, PhpRedis and Predis."), foreign && React.createElement("div", { className: "eop-dep-warning", style: { marginBottom: '16px' } }, React.createElement(Icon, { id: "warn", cls: "eop-icon eop-icon--sm" }), React.createElement("span", null, `Another object-cache drop-in (${d.foreign_name || 'unknown'}) is installed. Easy Optimizer is deferring to it and will not overwrite it — you can still flush from here.`)), React.createElement("label", null, React.createElement("input", { type: "checkbox", name: "easyopt_object_cache", value: "1", checked: on, disabled: foreign, onChange: () => onChange('easyopt_object_cache', on ? '0' : '1') }), " ", React.createElement("strong", null, "Enable Object Cache"), React.createElement("span", { style: { marginLeft: '8px', fontSize: '12px', fontWeight: 400, color: 'var(--eop-text-muted, #6b7280)' } }, "Requires a Redis server on your host")), foreign
        ? React.createElement("p", { className: "description" }, "Disabled while another object-cache plugin is active. Remove it to let Easy Optimizer manage caching.")
        : React.createElement("p", { className: "description" }, "Connects WordPress to Redis so repeat database lookups are served from memory. Turning this off reverts to WordPress's built-in cache."), React.createElement("div", { className: "ocfields", style: !on ? { display: 'none' } : { marginTop: '14px' } }, React.createElement(Collapsible, { title: "Connection settings (optional — defaults to localhost)" }, React.createElement("p", { className: "description", style: { marginTop: 0 } }, "If your host defines WP_REDIS_* constants or a WP_REDIS_CONFIG array in wp-config.php, those always take priority and you can leave these blank."), lockedKeys.length > 0 && React.createElement("div", { style: { margin: '0 0 12px', padding: '10px 14px', borderRadius: '8px', background: 'rgba(91,78,232,.07)', border: '1px solid rgba(91,78,232,.25)', fontSize: '13px', color: 'var(--eop-text, #374151)', lineHeight: 1.55 } }, React.createElement("strong", null, "Managed by wp-config.php: "), lockedKeys.join(', '), hasWrc ? " — detected a WP_REDIS_CONFIG array (Object Cache Pro format, common on Cloudways). Easy Optimizer reads its connection details automatically; the matching fields below are ignored." : " — these constants override the matching fields below."), React.createElement(SelectField, { name: "easyopt_oc_client", label: "Client", settings: settings, onChange: onChange, options: [
            { value: 'auto', label: 'Auto (Relay → PhpRedis → Predis)' },
            { value: 'relay', label: 'Relay' },
            { value: 'phpredis', label: 'PhpRedis' },
            { value: 'predis', label: 'Predis (bundled — no extension needed)' },
        ] }), React.createElement("div", { className: "easyopt-row" }, React.createElement("label", { htmlFor: "easyopt_oc_host" }, "Host"), React.createElement("input", { type: "text", id: "easyopt_oc_host", name: "easyopt_oc_host", value: settings.easyopt_oc_host || '', placeholder: "127.0.0.1 or /path/to/redis.sock", onChange: (e) => onChange('easyopt_oc_host', e.target.value), style: { minWidth: '280px', fontFamily: 'monospace' } }), React.createElement("p", { className: "description", style: { margin: '4px 0 0' } }, "Hostname, IP, or a unix socket path (starts with /) — the port is ignored for sockets.")), React.createElement("div", { className: "easyopt-row" }, React.createElement("label", { htmlFor: "easyopt_oc_port" }, "Port"), React.createElement("input", { type: "number", id: "easyopt_oc_port", name: "easyopt_oc_port", value: settings.easyopt_oc_port || '', placeholder: "6379", onChange: (e) => onChange('easyopt_oc_port', e.target.value), style: { width: '120px', fontFamily: 'monospace' } })), React.createElement("div", { className: "easyopt-row" }, React.createElement("label", { htmlFor: "easyopt_oc_username" }, "Username"), React.createElement("input", { type: "text", id: "easyopt_oc_username", name: "easyopt_oc_username", value: settings.easyopt_oc_username || '', autoComplete: "off", placeholder: "(none — only for Redis 6+ ACL hosts)", onChange: (e) => onChange('easyopt_oc_username', e.target.value), style: { minWidth: '280px', fontFamily: 'monospace' } }), React.createElement("p", { className: "description", style: { margin: '4px 0 0' } }, "Leave blank unless your host uses Redis ACLs (e.g. Cloudways). Password-only and no-auth servers work without it.")), React.createElement("div", { className: "easyopt-row" }, React.createElement("label", { htmlFor: "easyopt_oc_password" }, "Password"), React.createElement("input", { type: "password", id: "easyopt_oc_password", name: "easyopt_oc_password", value: settings.easyopt_oc_password || '', autoComplete: "new-password", placeholder: "(none)", onChange: (e) => onChange('easyopt_oc_password', e.target.value), style: { minWidth: '280px', fontFamily: 'monospace' } })), React.createElement("div", { className: "easyopt-row" }, React.createElement("label", { htmlFor: "easyopt_oc_database" }, "Database"), React.createElement("input", { type: "number", id: "easyopt_oc_database", name: "easyopt_oc_database", value: settings.easyopt_oc_database || '', placeholder: "0", onChange: (e) => onChange('easyopt_oc_database', e.target.value), style: { width: '120px', fontFamily: 'monospace' } })), React.createElement("div", { className: "easyopt-row" }, React.createElement("label", { htmlFor: "easyopt_oc_prefix" }, "Key prefix"), React.createElement("input", { type: "text", id: "easyopt_oc_prefix", name: "easyopt_oc_prefix", value: settings.easyopt_oc_prefix || '', placeholder: "auto", onChange: (e) => onChange('easyopt_oc_prefix', e.target.value), style: { minWidth: '220px', fontFamily: 'monospace' } })), React.createElement("div", { className: "easyopt-row", style: { marginTop: '8px' } }, React.createElement("label", { htmlFor: "easyopt_oc_tls" }, "Use TLS (rediss://)"), React.createElement("div", null, React.createElement("input", { type: "checkbox", id: "easyopt_oc_tls", name: "easyopt_oc_tls", checked: Number(settings.easyopt_oc_tls) === 1, onChange: () => onChange('easyopt_oc_tls', Number(settings.easyopt_oc_tls) === 1 ? '0' : '1') })), React.createElement("p", { className: "description", style: { margin: '4px 0 0' } }, "Required by some managed Redis providers (Upstash, DigitalOcean, Azure). Ignored for unix sockets.")), React.createElement("div", { className: "easyopt-field-row", style: { marginTop: '12px' } }, React.createElement("button", { type: "button", className: "button button-secondary", onClick: doTest, disabled: busy === 'test' }, busy === 'test' ? 'Testing…' : 'Test Connection'), React.createElement("span", { style: { marginLeft: '12px', color: msgColor, fontSize: '13px' } }, msg)))), React.createElement("div", { className: "eop-card", style: { marginTop: '18px' } }, React.createElement("div", { className: "eop-card__header" }, React.createElement("div", null, React.createElement("div", { className: "eop-card__kicker" }, "Status"), React.createElement("div", { className: "eop-card__title" }, "Redis & environment")), React.createElement("button", { type: "button", className: "button button-secondary button-small", onClick: load, disabled: loading }, loading ? 'Refreshing…' : 'Refresh')), (loading && !status)
        ? React.createElement("div", { className: "eop-spinner", style: { margin: '20px auto' } })
        : React.createElement(Fragment, null, React.createElement("div", { style: { display: 'flex', flexWrap: 'wrap', gap: '8px', margin: '6px 0 16px' } }, React.createElement(OcChip, { tone: ocActive ? (ocConnected ? 'ok' : 'warn') : 'off', label: ocActive ? (ocConnected ? 'Object cache active' : 'Active — not connected') : 'Not active' }), React.createElement(OcChip, { ok: !!d.phpredis, label: 'PhpRedis', note: d.phpredis && d.phpredis_version ? 'v' + d.phpredis_version : '' }), React.createElement(OcChip, { ok: !!d.relay, label: 'Relay' }), React.createElement(OcChip, { ok: !!d.predis, label: 'Predis' }), React.createElement(OcChip, { ok: !!d.igbinary, label: 'igbinary' }), React.createElement(OcChip, { ok: !!d.dropin_present, label: d.dropin_ours ? 'Drop-in: Easy Optimizer' : (d.dropin_foreign ? 'Drop-in: ' + (d.foreign_name || 'other') : 'Drop-in: none') }), d.plugin ? React.createElement(OcChip, { ok: true, label: d.plugin }) : null), status ? React.createElement("div", null, React.createElement(OcStat, { label: 'Provider', value: status.provider || '—' }), React.createElement(OcStat, { label: 'Connection', value: status.connected ? (status.client || 'connected') : 'not connected' }), React.createElement(OcStat, { label: 'Redis version', value: status.redis_version || '—' }), React.createElement(OcStat, { label: 'Memory used', value: ocBytes(status.memory_used) }), React.createElement(OcStat, { label: 'Memory limit', value: ocBytes(status.memory_total) }), React.createElement(OcStat, { label: 'Database', value: String(status.database) }), React.createElement(OcStat, { label: 'Key prefix', value: status.prefix || '—' }), (status.hits !== null && status.hits !== undefined) ? React.createElement(OcStat, { label: ocConnected ? 'Hits / misses (this request)' : 'Runtime hits / misses (not Redis)', value: status.hits + ' / ' + status.misses }) : null, (redisDown ? React.createElement(OcRedisWarning, { error: status.error, errorCode: status.error_code || '', errorHint: status.error_hint || '', onCloudways: onCloudways, wooActive: !!(CFG().wooActive) }) : ((on && status.error) ? React.createElement("p", { className: "description", style: { color: 'var(--eop-danger)', marginTop: '10px' } }, status.error) : null))) : null, React.createElement("div", { style: { marginTop: '14px' } }, React.createElement("button", { type: "button", className: "button button-secondary", onClick: doFlush, disabled: busy === 'flush' || !(status && status.active) }, busy === 'flush' ? 'Flushing…' : 'Flush Object Cache')))));
}
function App() {
    const init = CFG();
    const [tab, setTab] = useState(() => localStorage.getItem('easyopt_tab') || 'dashboard');
    const [settings, setSettings] = useState(init.settings || {});
    const [baseline, setBaseline] = useState(init.settings || {});
    // FluxCDN connect/disconnect writes settings server-side directly, then
    // returns the authoritative values. Adopt them into BOTH settings and
    // baseline so fields read as saved (not dirty) and survive reload.
    const applyServerSettings = useCallback((changes) => {
        setSettings((p) => ({ ...p, ...changes }));
        setBaseline((b) => ({ ...b, ...changes }));
    }, []);
    const [saving, setSaving] = useState(false);
    const [latestStats, setLatestStats] = useState(null);
    const [toast, setToast] = useState({ text: '', kind: '' });
    const [logOpen, setLogOpen] = useState(false);
    const [debugOpen, setDebugOpen] = useState(false); // (2.6.0) Debug Issues drawer
    const [logEntries, setLogEntries] = useState([]);
    const [logCounts, setLogCounts] = useState({ warnings: 0, errors: 0, total: 0 });
    const [logLoading, setLogLoading] = useState(false);
    const [safeMode, setSafeMode] = useState(!!init.safeMode);
    const [safeBusy, setSafeBusy] = useState(false);
    const toggleSafeMode = useCallback(() => {
        if (safeBusy)
            return;
        const next = !safeMode;
        setSafeBusy(true);
        api.post('/safe-mode', { enable: next }).then((r) => {
            if (r && r.ok) {
                // Settings changed wholesale — reload so every panel reflects them.
                window.location.reload();
            }
            else {
                setSafeBusy(false);
            }
        }).catch(() => setSafeBusy(false));
    }, [safeMode, safeBusy]);
    const fetchLog = useCallback(() => {
        setLogLoading(true);
        api.get('/debug-log').then((data) => {
            if (data) {
                setLogEntries(data.entries || []);
                setLogCounts(data.counts || { warnings: 0, errors: 0, total: 0 });
            }
            setLogLoading(false);
        });
    }, []);
    const clearLog = useCallback(() => {
        api.post('/debug-log/clear').then(() => {
            setLogEntries([]);
            setLogCounts({ warnings: 0, errors: 0, total: 0 });
        });
    }, []);
    // Fetch log counts on mount (lightweight).
    useEffect(() => { fetchLog(); }, []);
    const dirty = useMemo(() => {
        const d = {};
        for (const k of Object.keys(settings)) {
            if (String(settings[k]) !== String(baseline[k]))
                d[k] = settings[k];
        }
        return d;
    }, [settings, baseline]);
    const showToast = (text, kind) => { setToast({ text, kind }); setTimeout(() => setToast({ text: '', kind: '' }), 2500); };
    const onChange = useCallback((name, value) => {
        setSettings((p) => ({ ...p, [name]: value }));
    }, []);
    const saveOne = useCallback((name, value) => {
        setSettings((p) => ({ ...p, [name]: value }));
        api.post('/settings', { changes: { [name]: value } }).then((r) => {
            if (r?.settings) {
                setBaseline((b) => ({ ...b, [name]: r.settings[name] }));
            }
        });
    }, []);
    const [psiData, setPsiData] = useState(null);
    const psiPollRef = useRef(null);
    // Loopback-blocked fallback (2.5.5): when the job has been 'running' for
    // ~15s and cron never checked in (no cron_seen), wp-cron's loopback is
    // blocked (typically a firewall such as Sucuri challenging the server's
    // request to its own domain). Drive the job from this browser session
    // instead via /psi/run-step. Fire once per job (keyed by job.started).
    const psiFallbackRef = useRef(0);
    const maybeRunViaBrowser = (rr) => {
        const j = rr && rr.job;
        if (!j || j.status !== 'running' || j.cron_seen)
            return;
        const age = Math.floor(Date.now() / 1000) - (Number(j.started) || 0);
        if (age < 15 || psiFallbackRef.current === Number(j.started))
            return;
        psiFallbackRef.current = Number(j.started);
        api.post('/psi/run-step', {}).then((sr) => { if (sr && sr.job)
            setPsiData((cur) => ({ ...cur, job: sr.job })); });
    };
    const refreshPsi = useCallback(() => api.get('/psi/data').then((r) => {
        setPsiData(r);
        const running = r && r.job && r.job.status === 'running';
        if (running && !psiPollRef.current) {
            psiPollRef.current = setInterval(() => api.get('/psi/data').then((rr) => {
                setPsiData(rr);
                maybeRunViaBrowser(rr);
                if (!rr.job || rr.job.status !== 'running') {
                    clearInterval(psiPollRef.current);
                    psiPollRef.current = null;
                    if (rr.job && rr.job.status === 'done')
                        showToast('Speed test complete.', 'ok');
                    else if (rr.job && rr.job.status === 'partial')
                        showToast('Speed test finished with some failures.', 'error');
                    else if (rr.job && rr.job.status === 'error')
                        showToast((rr.job && rr.job.message) || 'Speed test failed.', 'error');
                }
            }), 5000);
        }
    }), []);
    useEffect(() => { refreshPsi(); return () => { if (psiPollRef.current)
        clearInterval(psiPollRef.current); }; }, []);
    const switchTab = useCallback((t, sub) => {
        if (sub)
            localStorage.setItem('easyopt_opt_sub', sub);
        setTab(t);
        localStorage.setItem('easyopt_tab', t);
    }, []);
    const save = useCallback(() => {
        const keys = Object.keys(dirty);
        if (!keys.length)
            return;
        setSaving(true);
        api.post('/settings', { changes: dirty }).then((r) => {
            if (r?.settings) {
                setBaseline(r.settings);
                setSettings((p) => ({ ...p, ...r.settings }));
            }
            if (r?.stats) {
                setLatestStats(r.stats);
            }
            showToast('All changes saved', 'ok');
            try {
                window.dispatchEvent(new CustomEvent('easyopt:saved', { detail: { keys: keys } }));
            }
            catch (e) { /* CustomEvent unsupported — ignore */ }
        }).catch(() => showToast('Save failed', 'error')).finally(() => setSaving(false));
    }, [dirty]);
    const discard = useCallback(() => { setSettings({ ...baseline }); showToast('Changes discarded', 'muted'); }, [baseline]);
    const cacheOn = Number(settings.easyopt_cache) === 1;
    const mode = settings.easyopt_cache_mode;
    const modeLabel = cacheOn ? (mode === 'htaccess' ? 'Apache mode' : 'PHP mode') : 'Cache off';
    const crumb = NAV.flatMap(g => g.items).find(i => i.key === tab)?.label || 'Dashboard';
    return (React.createElement("div", { className: "eop_wrap" }, React.createElement("div", { className: "eop-app" }, React.createElement("aside", { className: "eop-sidebar" }, React.createElement("div", { className: "eop-brand" }, React.createElement("div", { className: "eop-brand__mark" }, React.createElement(Icon, { id: "zap" })), React.createElement("div", null, React.createElement("div", { className: "eop-brand__name" }, "Easy Optimizer"), React.createElement("div", { className: "eop-brand__meta" }, "v", init.version))), React.createElement("nav", { className: "eop-nav" }, NAV.map((g, gi) => (React.createElement(NavGroup, { key: g.group || ('bottom' + gi), g: g, tab: tab, switchTab: switchTab, settings: settings })))), React.createElement("div", { className: "eop-sidebar__help" }, React.createElement("div", { className: "eop-help__title" }, "Need help?"), React.createElement("div", { className: "eop-help__body" }, "Contact us or ", React.createElement("a", { href: "https://wordpress.org/support/plugin/easy-optimizer/", target: "_blank", rel: "noopener" }, "open a support ticket"), " on WordPress.org")), React.createElement("div", { className: "eop-sidebar__rate", style: { padding: '12px 16px', fontSize: '13px', color: 'var(--eop-text-muted)', textAlign: 'center' } }, "Enjoying Easy Optimizer? ", React.createElement("div", { style: { marginTop: '6px' } }, React.createElement("a", { href: "https://wordpress.org/support/plugin/easy-optimizer/reviews/#new-post", target: "_blank", rel: "noopener", style: { fontWeight: 600, color: 'var(--eop-brand)', textDecoration: 'none' } }, "\u2B50 Rate us")))), React.createElement("main", { className: "eop-main" }, React.createElement("div", { className: "eop-topbar" }, React.createElement("div", { className: "eop-crumbs" }, React.createElement("span", { className: "eop-crumbs__home" }, "Easy Optimizer"), React.createElement("span", { className: "eop-crumbs__sep" }, "\u203A"), React.createElement("span", { className: "eop-crumbs__active" }, crumb)), React.createElement("div", { className: "eop-topbar__right" }, React.createElement("span", { className: `eop-pill ${cacheOn ? 'eop-pill--ok' : 'eop-pill--warn'}` }, React.createElement("span", { className: "eop-pill__dot" }), cacheOn ? 'Healthy' : 'Idle'), React.createElement("span", { className: "eop-pill eop-pill--brand" }, modeLabel), React.createElement("button", { onClick: toggleSafeMode, disabled: safeBusy, title: safeMode ? 'Safe Mode is ON — click to restore your previous settings' : 'Apply the Safe preset (your current settings are backed up so you can revert)', className: 'eop-btn eop-btn--sm ' + (safeMode ? 'eop-btn--safe-on' : 'eop-btn--ghost'), style: { position: 'relative' } }, React.createElement(Icon, { id: "lock", cls: "eop-icon eop-icon--sm" }), " ", safeBusy ? '…' : (safeMode ? 'Safe Mode: On' : 'Safe Mode')), 
    // (2.6.0) Sits between Safe Mode and Debug Log because
    // that is the order of escalation: something looks
    // wrong → find out what (Debug Issues) → read the
    // errors (Debug Log) → give up and neutralise the
    // settings (Safe Mode). Uses eop-btn--soft so it reads
    // as the actionable control between two ghost buttons
    // without competing with Safe Mode's amber warning
    // state — brand indigo, already in the token set, no
    // new colour introduced.
    React.createElement("button", { onClick: () => setDebugOpen(true), title: "Find which optimization is breaking a page", className: "eop-btn eop-btn--soft eop-btn--sm" }, React.createElement(Icon, { id: "wrench", cls: "eop-icon eop-icon--sm" }), " Debug Issues"), React.createElement("button", { onClick: () => { setLogOpen(true); fetchLog(); }, className: "eop-btn eop-btn--ghost eop-btn--sm", style: { position: 'relative' } }, "Debug Log ", React.createElement(Icon, { id: "ext", cls: "eop-icon eop-icon--sm" }), logCounts.total > 0 && React.createElement("span", { style: {
            position: 'absolute', top: '-4px', right: '-6px',
            background: logCounts.errors > 0 ? '#ef4444' : '#f59e0b',
            color: '#fff', fontSize: '10px', fontWeight: 700,
            borderRadius: '8px', padding: '1px 5px', lineHeight: '14px',
            minWidth: '16px', textAlign: 'center'
        } }, logCounts.total)))), React.createElement("div", { className: "eop-content" }, React.createElement(ServiceBar, { switchTab: switchTab }), tab === 'dashboard' && React.createElement(DashboardPanel, { settings: settings, stats: init.stats, modules: (init.modules || []).map((m) => ({ ...m, enabled: Number(settings[m.opt]) === 1 })), switchTab: switchTab, latestStats: latestStats, showToast: showToast, psiData: psiData, refreshPsi: refreshPsi }), tab === 'cache' && React.createElement(CachePanel, { settings: settings, onChange: onChange }), tab === 'objectcache' && React.createElement(ObjectCachePanel, { settings: settings, onChange: onChange, showToast: showToast }), tab === 'optimization' && React.createElement(OptimizationPanel, { settings: settings, onChange: onChange }), tab === 'fonts' && React.createElement(FontsPanel, { settings: settings, onChange: onChange, switchTab: switchTab }), tab === 'flux' && React.createElement(FluxPressPanel, { settings: settings, onChange: onChange, switchTab: switchTab, showToast: showToast, applyServerSettings: applyServerSettings }), tab === 'imgopt' && React.createElement(SmartImagesPanel, { settings: settings, onChange: onChange, applyServerSettings: applyServerSettings }), tab === 'bloat' && React.createElement(BloatPanel, { settings: settings, onChange: onChange }), tab === 'cloudflare' && React.createElement(CloudflarePanel, { settings: settings, onChange: onChange }), tab === 'database' && React.createElement(DatabasePanel, { settings: settings, onChange: onChange }), tab === 'heartbeat' && React.createElement(HeartbeatCronPanel, { settings: settings, onChange: onChange }), tab === 'accessibility' && React.createElement(AccessibilityPanel, { settings: settings, onChange: onChange }), tab === 'backend' && React.createElement(BackendPanel, { settings: settings, onChange: onChange, saveOne: saveOne, showToast: showToast }), tab === 'settings' && React.createElement(SettingsPanel, { settings: settings, onChange: onChange, showToast: showToast })))), logOpen && React.createElement(DebugLogDrawer, { entries: logEntries, counts: logCounts, loading: logLoading, onClose: () => setLogOpen(false), onClear: clearLog, onRefresh: fetchLog }), debugOpen && React.createElement(DebugIssuesDrawer, { settings: settings, onClose: () => setDebugOpen(false), switchTab: switchTab }), React.createElement(SaveBar, { dirty: dirty, onSave: save, onDiscard: discard, saving: saving }), React.createElement(Toast, { text: toast.text, kind: toast.kind })));
}
// ── MOUNT ──
const container = document.getElementById('easyopt-app');
if (container) {
    if (createRoot) {
        createRoot(container).render(React.createElement(App, null));
    }
    else {
        wp.element.render(React.createElement(App, null), container);
    }
}

})();

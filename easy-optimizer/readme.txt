=== Easy Optimizer – PageSpeed, Cache & Core Web Vitals (Unused CSS, Delay JS, Lazy Load) ===
Contributors: easywpstuff, uzairwp
Donate link: https://paypal.me/uzairwp
Tags: performance, cache, pagespeed, core web vitals, speed
Requires at least: 6.4
Tested up to: 7.1.3
Requires PHP: 7.4
Stable tag: 2.7.2
License: GNU General Public License v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free all-in-one performance optimization: page cache, unused CSS, delay JS, lazy load, LCP preload and WebP for faster speed and Core Web Vitals.

== Description ==

Speed up your WordPress site and improve your Google PageSpeed score from a single dashboard. **Easy Optimizer** is a free, all-in-one performance optimization plugin that can replace the 3–5 separate speed plugins most sites run today — page caching, unused-CSS removal, JavaScript delay/defer, image lazy loading, LCP preloading, WebP/AVIF delivery and more — to help you pass Core Web Vitals: Largest Contentful Paint (LCP), Cumulative Layout Shift (CLS) and Interaction to Next Paint (INP). It runs on Apache, Nginx, LiteSpeed and OpenLiteSpeed, and is fully WooCommerce-aware.

One cache plugin, one dashboard, no premium upsells for core features. Prefer to keep your current cache plugin? Turn off the modules that overlap and use only the tools you want (see the FAQ).

Every toggle maps to a specific Google Lighthouse recommendation — enable it, save, and your Core Web Vitals improve immediately. Features you don't enable never touch your page load or your admin.

= Core Features =

* **Full Page Caching:** Serve static HTML with sub-millisecond TTFB, with separate mobile and desktop caches and automatic sitemap-based preloading that warms your pages in the background.
* **Compression & Browser Caching:** Turn on Gzip or Brotli text compression and long-lived browser-cache (Expires) headers to shrink transfer size and stop repeat visitors re-downloading the same assets — resolving Lighthouse's "Enable text compression" and "Serve static assets with an efficient cache policy".
* **Redis Object Cache:** Add a persistent object cache that keeps repeated database and option lookups in Redis, cutting MySQL load on dynamic, logged-in and WooCommerce pages. Works with Relay, PhpRedis or the bundled Predis client (no PHP extension required) and reads your host's `WP_REDIS_*` constants automatically. It defers to any object-cache drop-in already installed instead of overwriting it, never edits `wp-config.php`, and includes a one-click flush plus a live status panel (connection, memory, Redis version).
* **Remove Unused CSS:** Fix Lighthouse's "Reduce unused CSS" by serving a slim, per-page-type stylesheet — eliminating render-blocking CSS and lifting your LCP. A built-in fail-safe keeps your original styles until a valid stylesheet is generated, so pages never render unstyled.
* **Minify CSS & JavaScript:** Strip comments and whitespace from your local CSS and JS files, caching each minified file on disk so it is processed only once. Already-minified files are skipped, and relative `url()`/`@import` paths are rewritten so assets keep resolving. Works independently of Remove Unused CSS and Delay JS.
* **Delay & Defer JavaScript:** Cut Total Blocking Time and improve INP by delaying script execution until user interaction, or by deferring scripts natively. jQuery is excluded by default, and you can exclude any script that needs to run early.
* **Smart Lazy Loading:** Defer offscreen images, iframes, videos and CSS background images, and add missing width/height attributes to prevent layout shift (CLS).
* **Automatic LCP Preload:** Detect each page's Largest Contentful Paint image and preload it — including responsive `srcset`, `<picture>` and CSS background heroes — stored per URL so every page preloads its own correct hero for faster visual loading.
* **Image CDN (WebP & AVIF):** Rewrite your image URLs to a global CDN that serves modern WebP/AVIF formats and every responsive size, addressing Lighthouse's "Serve images in next-gen formats". Optional and off until you enable it.
* **Prefetch Pages:** Start loading the next page while the visitor is still deciding. Easy Optimizer writes one compact set of browser speculation rules into the top of every page, so the browser itself picks the moment — a short hover starts a lightweight prefetch, and the mouse going down starts a full prerender. No JavaScript scans your links, so a page with 50 links costs no more than a page with 10. Cart, checkout, account, feed, admin, download and query-string URLs are never touched, and while it is on it replaces WordPress's own speculative loading so a page never carries two competing rule sets.
* **Font Optimization:** Add `font-display: swap` so text stays visible during webfont loads, with optional per-device font preloading learned from a lightweight beacon.
* **Bloat Removal:** 15 independent toggles to safely switch off unused WordPress core scripts and features (emojis, embeds, XML-RPC, jQuery Migrate, Dashicons, Block Library CSS and more).
* **Database Cleanup & Optimization:** Schedule cleanups of revisions, auto-drafts, trashed posts, spam and transients — each with a pre-cleanup snapshot so you can restore safely. The built-in Autoload Health auditor finds the heaviest autoloaded options and lets you switch off the ones you don't need, directly resolving the Site Health warning "Autoloaded options could affect performance."
* **Heartbeat & Cron Manager:** Limit WordPress Heartbeat frequency and throttle WP-Cron from the dashboard (no `wp-config.php` edits), and view, run or delete scheduled cron events.
* **Cloudflare Integration:** Purge your Cloudflare edge cache (tag-based or full zone) whenever the page cache clears, and test your API connection instantly without saving credentials first.
* **Hosting Integrations:** Detect your environment and purge server-level cache on managed hosts including Kinsta, WP Engine, SiteGround, Cloudways, GridPane, RunCloud, SpinupWP, Rocket.net and WordPress.com.
* **Accessibility & SEO Fixes:** Resolve common Lighthouse accessibility warnings in real time — missing form labels, ARIA names, iframe titles and un-crawlable links — without editing your theme.
* **PageSpeed Speed Test:** Run a Google PageSpeed test from your dashboard and compare your score with and without Easy Optimizer, for both mobile and desktop, including Core Web Vitals (LCP, CLS, INP). Works out of the box, or add your own free Google API key for unlimited tests.
* **Debug Issues Panel:** If a page ever looks wrong, switch individual optimizations off for a single page load and find the cause in a couple of clicks — no settings changed, no cache cleared, and no private browsing window needed. It tells you which feature caused it and takes you straight to the exclusion field that makes the fix permanent.
* **Find Slow Plugins & Themes (Backend Analyzer):** Profile any page or your admin area on demand to see exactly which plugins, themes and database queries take the most time — so you can pinpoint what is slowing your site down. Nothing runs for real visitors, so there is no added overhead.

= PageSpeed Insights & Lighthouse Recommendations Addressed =

Lighthouse 13 renamed and merged many performance audits. Where the name changed, the previous wording is shown in brackets so you can match either against your report.

**Performance**

* Render blocking requests (was: Eliminate render-blocking resources)
* Reduce unused CSS
* Minimize main-thread work
* Improve image delivery (was: Serve images in next-gen formats / Properly size images)
* Layout shift culprits (was: Image elements do not have explicit width and height)
* LCP request discovery (was: Preload Largest Contentful Paint image)
* Font display (was: Ensure text remains visible during webfont load)
* Use efficient cache lifetimes (was: Serve static assets with an efficient cache policy)
* Document request latency (was: Enable text compression / Reduce server response time)
* Network dependency tree (was: Preconnect to required origins)

**Accessibility**

* Form elements do not have associated labels
* Links do not have a discernible name
* Buttons do not have an accessible name
* [user-scalable="no"] in viewport or [maximum-scale] < 5
* Frame or iframe elements do not have a title
* ARIA progressbar elements do not have accessible names
* Some elements have a [tabindex] value greater than 0
* Image elements do not have [alt] attributes

**SEO**

* Links are not crawlable
* Image elements do not have [alt] attributes.

= Works With Your Setup =
 
* **WordPress & PHP:** WP 6.4+ and PHP 7.4+
* **Web Servers:** Apache, Nginx, LiteSpeed, OpenLiteSpeed
* **WooCommerce:** Fully compatible. Cart, checkout, and my-account pages are automatically excluded from caching and optimization to protect the purchase flow.
* **Page Builders:** Elementor, Beaver Builder, Divi, Gutenberg, Bricks, Oxygen, Breakdance.

= A Free Alternative to Premium Speed Plugins =

Easy Optimizer is a genuine free alternative to paid performance plugins such as WP Rocket, FlyingPress and Perfmatters: page caching, Remove Unused CSS, Delay/Defer JavaScript, LCP preload, lazy load, Cloudflare and hosting-cache purging, database cleanup and the Backend Analyzer are all included, with no premium-locked features and no upsells. Run it as a complete all-in-one, or use just the tools you need alongside your existing setup — the only rule is to never run two of the same optimization (especially two page caches) at once.

== Installation ==

1. In your WordPress admin, go to **Plugins → Add New** and search for "Easy Optimizer", or upload the plugin to `/wp-content/plugins/easy-optimizer/`.
2. Activate the plugin.
3. Open **Easy Optimizer** in the admin sidebar. On a new install the setup wizard opens automatically — nothing is enabled until you choose a preset.
4. Pick a preset: **Safe** (best for shared hosting), **Balanced** (recommended for most sites) or **Maximum**. If you're not sure, choose **Balanced**.
5. Save. The cache preloader starts warming your site automatically. You can fine-tune any individual feature from the tabs at any time, and the Settings tab includes a one-click **Safe Mode** if you ever need a known-good baseline.

== Frequently Asked Questions ==

= Is Easy Optimizer compatible with WP Rocket, FlyingPress, Perfmatters, LiteSpeed Cache and other popular plugins? =
Yes. Easy Optimizer is built to play nicely alongside other plugins, including WP Rocket, FlyingPress, Perfmatters, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, Autoptimize, NitroPack and the common page builders (Elementor, Divi, Beaver Builder, Oxygen) and SEO plugins (Yoast, Rank Math). It also works with WooCommerce and Cloudflare.

The only rule is simple: don't run the same optimization twice. Every module is independent and only runs when you switch it on, so just turn off whatever overlaps with another plugin you're already using. Most importantly, never run two page caches at once — if another plugin is handling caching, leave Easy Optimizer's Page Cache off (or turn the other one off and let Easy Optimizer handle it). The same applies to features like unused CSS, delay JavaScript, or lazy load: pick one plugin to do each job.

This means you can use Easy Optimizer as a complete all-in-one replacement, or keep your current setup and use only the parts you want — for example the Backend Analyzer, Autoload Health, database cleanup, or heartbeat and cron controls — while another plugin keeps doing the caching. Either way works.

= How is Easy Optimizer different from WP Rocket, FlyingPress or Perfmatters? =
Easy Optimizer is free and open, with no premium-locked core features and no upsells: caching, Remove Unused CSS, Delay/Defer JS, LCP preload, lazy load, Cloudflare and hosting-cache purging, database cleanup and the Backend Analyzer are all included. Those paid plugins are mature and excellent; Easy Optimizer aims to give you the same core speed wins for free, and to coexist cleanly if you already run one — just don't run two of the same optimization at once.

= Something on my site looks broken — how do I find which feature caused it? =
Use the **Debug Issues** button in the top bar. Pick the page that looks wrong, tick one optimization at a time, and open the generated link — that single page load runs with the feature switched off, while your settings and your cache stay exactly as they are. Answer "did that fix it?" and Easy Optimizer tells you which feature was responsible and takes you to the exclusion field that keeps the fix permanently.

You do not need a private browsing window. Some optimizations are normally skipped while you are logged in, so the panel's links quietly render the page the way a visitor sees it. If the page is still wrong with everything switched off, the cause is your theme, another plugin or your server — and the panel says so rather than sending you round the loop again.

= Will Easy Optimizer speed up my WooCommerce store? =
Yes. Every optimization module runs on your shop, while Cart, Checkout and My Account are automatically excluded so the purchase flow is never cached or altered — including custom or translated slugs. Multi-currency stores cache a correct copy per selected currency, and you can disable WooCommerce cart fragments from the Bloat Removal tab to speed up pages that don't need them.

= Does it work on Nginx, LiteSpeed and OpenLiteSpeed, or only Apache? =
All of them. On Apache it can optionally write `.htaccess` rules (after a per-server safety probe that prevents a bad rule from ever 500-ing your site); on Nginx, LiteSpeed and OpenLiteSpeed it serves cached pages through PHP. On hosts with their own server-level cache (Varnish / LiteSpeed / nginx FastCGI), preloading and on-visit caching are handled correctly.

= What's the difference between Delay JS and Defer JS — which should I use? =
Defer keeps scripts in order but runs them after HTML parsing; it is the safer choice and rarely breaks anything. Delay goes further, holding scripts until the first user interaction (scroll, tap, move) for the biggest INP and blocking-time win, at a slightly higher chance of needing exclusions. Start with Defer if you're cautious, or Delay (jQuery excluded by default) for maximum speed, and add any problem script to the exclusion list.

= Does Delay JavaScript break Google Analytics, the Facebook Pixel or ads? =
Usually not. Delayed scripts run on the first interaction, so analytics and pixels still fire for engaged visitors. If you need a tag to load immediately — for example to measure bounces or run consent logic — add it to the Delay JS exclusion list and it will load normally.

= My site looks broken after enabling Remove Unused CSS — how do I fix it? =
Add the affected stylesheet or CSS selector to the exclusion fields on the CSS tab and rebuild. Remove Unused CSS also learns JavaScript-generated classes (sliders, menus, tabs) automatically so their styles aren't stripped. To confirm it really is the cause, use the Debug Issues panel. To revert quickly, use Safe Mode on the Settings tab to return to a conservative baseline.

= How do I exclude a page from caching, or a script from Delay JS? =
Use the exclusion fields on each tab — the Cache tab has URL and cookie exclusions for pages you never want cached, and the JavaScript tab lets you exclude scripts by name or URL from Delay/Defer. For a one-off test, the Debug Issues panel builds the right link for you; you can also append `?nocache`, `?nodelayjs`, `?norucss` or `?nooptimize` to any front-end URL by hand to disable that feature for that single request.

= Does it minify and combine CSS and JavaScript? =
It minifies your local CSS and JS (caching each file on disk and processing it once). It does not force-combine files into one — under HTTP/2 and HTTP/3 that usually no longer helps and can hurt caching — so Easy Optimizer focuses on minification, delay/defer and removing unused CSS instead.

= Do I need a CDN to use Easy Optimizer? =
No. Every core feature works without a CDN. If you want next-gen image delivery you can optionally enable the built-in Image CDN (WebP/AVIF), and you can connect Cloudflare for edge-cache purging — but neither is required.

= Will it slow down my wp-admin dashboard? =
No. Optimization features run on your front end, and modules you don't enable are never loaded, so your admin stays responsive. The Backend Analyzer's profiling is on-demand and never runs for real visitors.

= What's the best starting configuration to improve my PageSpeed / Core Web Vitals score? =
Enable Page Cache, Remove Unused CSS, Delay or Defer JavaScript, Lazy Load, LCP Preload and Font Display Swap, then turn on the accessibility and SEO toggles relevant to your site. Save and re-run the built-in speed test to compare before and after. The dashboard's "What to fix next" card then lists what is still worth changing, ranked by impact, so you don't have to guess. If you used the setup wizard, the Balanced preset already enables most of these.

= Which preset and preload speed should I use for my hosting? =
As a rule of thumb — shared hosting: the Safe wizard preset with the Gentle preload speed; most sites and managed hosting: Balanced; VPS or high-performance managed hosting: Maximum with the Turbo (or Balanced) preload speed. Preload also auto-tunes to your server's real capacity and backs off automatically if it detects errors or rate-limiting, so you can't overload your host.

= Do I need Redis, and does it work without the PHP extension? =
The Object Cache module needs a running Redis server (many shared hosts ship the PhpRedis extension but no server — if the status panel shows "not connected", leave Object Cache off). You do not need a Redis PHP extension, though: the pure-PHP Predis client is bundled, so the object cache works without PhpRedis or Relay. Enter your host, port and password in the settings, or define `WP_REDIS_*` constants in `wp-config.php` and they'll be used automatically. Easy Optimizer never edits `wp-config.php`, and if another object-cache plugin is active it defers to it rather than overwriting its drop-in.

= What do "Pages cached" and "URLs in waiting" actually count? =
Both count unique URLs, not files. With Separate Mobile Cache on, one page can have a desktop copy and a mobile copy on disk, but it is still one page in both figures. The two numbers are not meant to converge: a page whose desktop copy is cached while its mobile copy is still queued is legitimately counted in each at the same time.

= Can I use this with Cloudflare? =
Yes. Add your API token and Zone ID in the Cloudflare tab. Cache purges sync automatically.

= Does it support multisite? =
Works on individual sites within a multisite network.

= How do I migrate settings between sites? =
Export settings as JSON from the Settings tab, then import on the destination site. Credentials (Cloudflare token, Redis password, CDN key) are deliberately left out of the export, so re-enter those on the destination site.

= What happens to my cache when I edit a post or product? =
Editing content clears only the affected URLs — the post or product itself plus its archives, author, date and taxonomy pages (with pagination) — instead of wiping the whole cache, and changing a slug also clears the old URL. Comment and term edits trigger the same targeted, lightweight clears.

= Does Easy Optimizer collect any data? Is it privacy-friendly? =
The usage analytics are opt-in and off by default. When enabled they send only technical, non-personal data (plugin/WordPress/PHP versions, theme, locale, host flags and your site URL) — never your email, visitor IPs, user data or page content. With analytics left off, nothing is sent anywhere. See the Privacy and External Services sections for the full list.

= How do I completely uninstall Easy Optimizer and remove all of its data? =
On the Settings tab, turn on "Delete all plugin data on uninstall" before deleting the plugin. With it on, removing the plugin cleans up its options, cache files, drop-ins and snapshots; with it off, your settings are preserved in case you reinstall.

= Is there a developer API for clearing the cache? =
Yes. You can trigger purges from your own code with these action hooks:

`do_action( 'easyopt_purge_all' );` — clear the entire page cache.
`do_action( 'easyopt_purge_url', 'https://example.com/some-page/' );` — clear one URL.
`do_action( 'easyopt_purge_url_type', 'home' );` — clear a key page type (home, front or posts page).

The plugin also fires events you can listen to: `easyopt_cache_cleared_all`, `easyopt_cache_cleared_url` (passes the URL) and `easyopt_page_cached`. For Cloudflare, `EasyOpt_Cloudflare::purge_url( $url )` and `EasyOpt_Cloudflare::purge_all()` are available when the integration is connected.

== External Services ==

Each service below is optional and used only for the feature noted.

* FluxPress Adaptive Images (optional, paid) — with Smart Images connected, your public image URLs are rewritten to your CDN for WebP/AVIF conversion and resizing. Contacts fluxpress.io with your license key and site URL on connect and about hourly. Originals are never uploaded. [Terms & Privacy](https://fluxpress.io/privacy)
* FluxPress PageSpeed proxy — the default speed test; sends your public home URL so no Google API key is needed. [Terms & Privacy](https://fluxpress.io/privacy)
* Google PageSpeed Insights — if you use your own API key, your public home URL is sent to Google. [Terms](https://developers.google.com/terms) · [Privacy](https://policies.google.com/privacy)
* Cloudflare API — if connected, purges your edge cache when the page cache clears; sends only the URLs and zone ID. [Terms](https://www.cloudflare.com/website-terms/) · [Privacy](https://www.cloudflare.com/privacypolicy/)
* FluxPress usage analytics — opt-in, off by default; non-personal data only (versions, theme, host, site URL). [Terms & Privacy](https://fluxpress.io/privacy)

== Privacy ==
 
Easy Optimizer's usage analytics are opt-in and disabled by default. When enabled, it sends the data listed under External Services over an encrypted connection. It never collects your email address, IP address, user data, or site content.

== Screenshots ==

1. Dashboard — performance overview with cache statistics, preload progress, feature status toggles and quick-action buttons for clearing cache and Cloudflare.
2. Cache settings — page cache with serving mode selection (PHP or .htaccess), cache lifetime, separate mobile cache, browser caching, Gzip/Brotli, preload toggle and granular exclusion fields.
3. JavaScript optimization — delay until interaction or native defer method, jQuery exclusion, script and URL exclusion lists, inline script externalization.
4. CSS optimization — remove unused CSS with inline or file method, stylesheet behavior (delay, async, remove), selector and stylesheet exclusion, per-post-type mode.
5. Lazy load — toggle images, iframes and videos independently; add missing dimensions; exclude first N images; class and URL exclusion.
6. LCP preload — automatic detection with zero configuration; URL exclusion for edge cases.
7. Prefetch pages — eagerness selection, prerender toggle, URL and CSS selector exclusions.
8. Font optimization — font-display:swap and lazy-load font stylesheets with exclusion support.
9. Bloat removal — 15 independent toggles for emojis, embeds, XML-RPC, jQuery Migrate, version meta, RSD/WLW, shortlinks, RSS, self-pingbacks, REST API, Heartbeat, cart fragments, App Passwords, Dashicons and Block Library CSS.
10. Cloudflare — API token and zone ID with test connection button, purge strategy selection.
11. Database cleanup — task toggles with item counts, scheduled cleanup, snapshot and restore, chunked processing.
12. Autoload health — total autoload size, per-option size breakdown, one-click autoload disable/enable with core option protection.
13. Heartbeat & Cron — heartbeat location and frequency control, WP-Cron throttle, cron event viewer with manual run and delete.
14. Accessibility & SEO — eight accessibility audit toggles and two SEO audit toggles with clear Lighthouse audit descriptions.

== Changelog ==

= 2.7.2 =
* Fixed: Remove Unused CSS stopped reading a stylesheet partway through when it met a Tailwind arbitrary-value class such as `bg-[url('/hero.png')]`. Every rule after that point was missing from the critical CSS until the visitor interacted, so padding, gradients and image fitting could jump on first paint. Escaped selectors are now read correctly and matched to the classes on the page.
* Fixed: with Cloud Optimization connected, the script that loads deferred stylesheets was printed twice. It is now printed once.
* Fixed: an iframe or image inside `<noscript>` (for example the Google Tag Manager fallback) was being lazy-loaded. Lazy-loading also skipped the wrong elements around the admin bar and could miss `<picture>` sources. All three now behave as intended.
* Fixed: connecting Cloud Optimization now always clears Used CSS, LCP data and the page cache, so a reconnect no longer serves CSS built under the old settings. Switching edge CSS/JS delivery or cloud Unused CSS in Settings now clears the page cache too.
* Improved: with Cloud Optimization, Smart Lazyload Fonts no longer loads every font before it has measured a page. Fonts stay out of the critical CSS until the first visit measures which ones are visible, and only those are loaded from then on.
* Improved: with Cloud Optimization, fonts referenced in the critical (Used) CSS are now served from the edge too, not only the stylesheets and scripts named in the page.
* Fixed: switching Images off in Cloud Optimization also stopped CSS, JS and fonts from being served from the edge. They are now independent; only an inactive plan stops both.
* Fixed: with Cloud Optimization connected, LCP preload never learned anything — every measurement of an image served from the edge was discarded, so no page got its hero preloaded. Edge-served LCP images are now recorded and matched to the page's image at any width.
* Fixed: when only one screen size had been measured, its LCP preload was sent to every screen size, so desktop could download a mobile-only hero image it never shows. Each preload is now limited to the screen size it was measured on.
* Improved: pages whose largest element is text or a video without a poster are now recorded as measured, so LCP detection stops re-running on every visit, and that video is never lazy-loaded.
* Improved: connecting Cloud Optimization now also switches on LCP preload.
* Fixed: "Hide WordPress version" removed the version from every stylesheet and script, including the ones plugins, themes and Elementor use to load a fresh copy after an update. With edge delivery that could keep serving an old file for up to a year. It now removes only the WordPress version itself.
* Fixed: Excluded CSS Selectors did not protect Tailwind-style classes such as `md:hidden`, `w-1/2` or `2xl:p-4`, because the stylesheet writes them escaped. Exclusions now match whether you type the class as it appears in your HTML or in its escaped form.
* Fixed: changing edge asset exclusions, <picture> image delivery, native lazy loading, preconnect, font preloading or passthrough stylesheets now clears the page cache (and, for passthrough stylesheets, rebuilds Used CSS) instead of waiting for the cache to expire.

= 2.7.1 =
* Fixed: background images set in page-builder CSS files (Elementor and similar) now load through the CDN. They are already found during analysis; enabling edge CSS/JS delivery on connect is what lets them actually be served optimized, so the hero background — usually your largest image — is no longer left on your own server.
* Fixed: the Debug Log could show a warning count in its header while the panel underneath read "No warnings or errors logged." A run of routine info lines could push the real warnings out of the view window. The count and the list are now read together, so they always agree, and older warnings are no longer hidden behind recent activity.
* Removed: "Minify CSS" and "Minify JavaScript" no longer appear in Recommended next steps. Their impact is minimal next to Remove Unused CSS and Delay JavaScript, and they were adding noise to the list. The settings themselves are unchanged and still available under Optimization.

= 2.7.0 =
* New: Cloud Optimization — connect an account to deliver your images, CSS, JS and fonts from 119 edge locations, with per-device image sizing. 14-day free trial, then paid; if it ever ends your site keeps working on your own server.
* New: Local Image Optimization — convert your images to AVIF and WebP on your own server, free. The original file is never touched: variants are saved alongside it and the modern format is served automatically with a full fallback.

= 2.6.7 =
* Fixed: sliders could show an empty gap that only filled in once you clicked or scrolled, when Remove Unused CSS was on. Swiper adds its layout classes when its script starts, so those classes are absent from the page Remove Unused CSS reads — and their styles were being stripped while the "before the slider starts" styles survived. Swiper's state classes and sticky-header placeholders are now kept automatically. Affects Elementor sliders, WooCommerce galleries and most block themes.
* Fixed: Excluded CSS Selectors silently missed minified rules. A selector written without spaces around `>`, `+` or `~` — which is how every minified stylesheet writes them — was not recognised, so an exclusion you added could still be stripped. Of the twelve rules carrying one Swiper class, only three were being kept. This applies to every exclusion, including ones you had already added.
* New: Prefetch Pages now predicts where the visitor is going instead of only reacting. Once the page is idle it works out which destination your own page links to most often — the one you put in the header, the hero button and the footer — and starts that one with no interaction at all. It also watches for a "Next page" link, a dropdown or mobile menu being opened, the pointer coming to rest near a link (before it even touches it), and, on phones where there is no hover, scrolling coming to a stop with a link in the middle of the screen. Each prediction hands the browser one URL to start on immediately. Capped at four pages per visit, so it never becomes bulk downloading.
* New: mobile finally gets a real head start. The browser's own trigger needs a hover, which a touchscreen cannot provide, so phones previously got nothing until the finger came down. Scrolling to a stop now warms the page you are reading, seconds before you tap it.
* Changed: Prerender is now OFF by default, and is switched off on update. It builds the entire page in the background — scripts, images and all — and testing showed it never finishing in the fraction of a second between the mouse button going down and the page opening; the ordinary prefetch served every navigation instead. It is still one click away for sites that want it.
* New: Prefetch Pages has been rebuilt from scratch. It no longer ships any link-scanning JavaScript. Instead it writes one small set of browser speculation rules into the top of every page, so the browser starts loading the next page as soon as the visitor hovers a link — and starts a full prerender the moment the mouse button goes down. The rules are in place before the page has finished loading, instead of after a script downloads in the footer and waits for an idle moment.
* Fixed: Easy Optimizer and WordPress were both prefetching. WordPress 6.8+ adds its own speculative loading, and the old module never switched it off — every page carried two competing systems. Prefetch Pages now takes ownership while it is on, and hands WordPress back its own behaviour the moment you turn it off.
* Fixed: the per-second throttle could delay the very first prefetch by up to a full second — on a feature whose entire job is to start sooner. There is no queue any more; the browser schedules the work and enforces its own limits.
* Improved: links in your footer are treated as lower intent than links in your content and navigation, so a hover over a privacy-policy link no longer costs the same as a hover over the next article.
* Improved: `*` wildcards now work in Prefetch Pages → Exclude URLs, the same as in every other Excluded URLs field. Lines naming a query parameter (`ref=`) are matched against the query string rather than the path.
* Improved: query-string URLs, `/wp-json/`, feeds, downloads and WooCommerce cart/checkout/account pages are excluded before the browser ever sees them, and exclusions added by other plugins through WordPress's own `wp_speculation_rules_href_exclude_paths` filter are honoured too.
* Changed: the "Max prefetch requests/second" and "Per-page limit" settings are gone — they configured the old request queue, which no longer exists. Chromium enforces its own caps (50 prefetches, 2 prerenders in flight). Both stored values are removed automatically on update.
* Changed: the "Immediate" eagerness option is gone. On a whole-document rule it means "fetch every link on this page right now", which WordPress Core forbids for the same reason. Existing sites set to Immediate move to Eager.
* Removed: the bundled quicklink-derived runtime (`assets/easyoptlink.js`) and its licence notice. No code derived from it remains.

= 2.6.6 =
* Fixed: "Cache for logged-in users" now actually serves cached pages to logged-in visitors. The page was being generated and rewritten on every visit but never served (headers stayed on MISS), because the serving drop-in treated the login cookie as a reason to skip the cache even when this option was on.
* Changed: Instant Load prerenders far fewer pages per view by default (8 instead of 50) and no longer prerenders filtered/sorted URLs that can never be cached — big reduction in background server load on shops and archives. If you set your own limit, it is kept.
* Fixed: Remove Unused CSS no longer re-attempts a build it just failed on every single request (e.g. when stylesheets live on a CDN it can't read) — a failed build is remembered briefly, so the page serves normally without repeating the expensive work. Clears automatically and on "Clear Used CSS".

= 2.6.5 =
* New: takes over page caching from another cache plugin (like WP Rocket and LiteSpeed do) — but backs up the other plugin's cache file first and restores it if you turn our cache off or uninstall.
* New: setup wizard can deactivate a conflicting plugin for you so Easy Optimizer can handle its jobs.
* Fixed: Excluded URLs now work with the Defer JavaScript method — before, switching to Defer silently ignored them (reported on Tutor LMS).
* Fixed: wildcards like /shop/* now work in every Excluded URLs field, not just JavaScript.
* Fixed: conflict warnings no longer flag plugins that are installed but switched off — each plugin's own settings are read now (WP Rocket, LiteSpeed, Autoptimize, Perfmatters, Jetpack Boost, SiteGround, WP-Optimize, Breeze, Hummingbird, WP Fastest Cache, Asset CleanUp, W3 Total Cache, Optimole, Smush).
* Fixed: combine, critical CSS, async, defer and lazy-load clashes are detected too, not just plain minify.
* Fixed: SiteGround sites are no longer told to deactivate SiteGround Optimizer — Easy Optimizer purges through it.
* Fixed: Windows-hosting bug that could switch off another cache plugin.
* Fixed: setup no longer turns Lazy Load on next to a plugin already lazy-loading.
* Changed: simpler sidebar — seven groups become four, Advanced starts collapsed (eight items instead of thirteen). Nothing removed or renamed.
* Changed: distinct sidebar icons; new Compatibility panel on the Dashboard.
* Changed: plugin-conflict detection is cached instead of running on every admin page.

= 2.6.4 =
* Changed: Remove Unused CSS uses a new built-in CSS parser — about 2.4 MB smaller, ~5× faster and far less memory. Builder CSS (Elementor/Divi) passed through is now minified too.
* Fixed: a single malformed stylesheet (e.g. an unclosed @media in a builder's Custom CSS) can no longer break your layout — generated CSS is always balanced.
* Fixed: modern CSS nesting is kept instead of dropped; mis-encoded stylesheets handled more robustly.

= 2.6.3 =
* Fixed: Remove Unused CSS now keeps @layer, @scope, @container, @starting-style and modern colour syntax that were being dropped.
* Fixed: the automatic before/after speed test never ran — the "After" score only appeared if you pressed Run yourself. It runs now, and retries up to 3 times on failure.
* Fixed: WordPress 7.1 — "Clear All Cache" no longer shows in the Post/Site editor toolbar.
* New: Smart Images warnings show time left ("about 6 days left"), not just amount.
* New: an optional review prompt, shown only after a real speed gain (10+ mobile points); dismissible for 90 days.
* Changed: free Smart Images plan raised to 500 images/month (was 50); tested with WordPress 7.1.

= 2.6.2 =
* Fixed: fatal error on Flywheel, WP Engine and other hardened hosts (disabled PHP functions on PHP 8) that could take a site down, including wp-admin — every such call is checked first now.
* Fixed: cache purging no longer stops when page caching is off — your host cache and Cloudflare are purged on content changes again.
* Fixed: Flywheel cache clearing works now (clears object cache) and tells you what it can't do; wp-config.php found on split-directory hosts.
* Changed: optimization is off for logged-in users unless "Cache for Logged-in Users" is on (Delay/Defer JS, Minify, Lazy load, Fonts, Preconnect). Logged-out visitors unaffected. Filter: easyopt_skip_for_logged_in.

= 2.6.1 =
* New: Cache Query String — give chosen parameters their own cache file, for sites that render the value into the page. Per-click IDs (gclid, fbclid…) are refused.
* Fixed: the strip-query-params filter now applies to the cache drop-in too, so ignoring or adding a parameter actually works.
* Fixed: installing Polylang/WPML on a cached site no longer serves one language to everyone.
* Fixed: Nginx/Apache edge cases (mobile rule, non-standard ports); cache directories no longer created from an unknown Host header.
* Security: CDN credentials moved out of the main settings row — no longer in the admin payload or exports; image URLs with \ or @ in the host are no longer signed.

= 2.6.0 =
* New: Debug Issues panel — find which optimization breaks a page in a couple of clicks.
* New: "What to fix next" dashboard card — your PageSpeed findings as a ranked to-do list.
* New: setup wizard checks your site and skips anything another plugin or host already handles.
* New: GoDaddy Managed WordPress Varnish/CDN purging.
* Security: preload requests are signed now (were unauthenticated); beacon token tied to its page; no longer overwrites another active cache plugin's drop-in; cached pages declare what they vary on; exports no longer contain Cloudflare/Redis/CDN secrets.
* Fixed: page cache now works on Pantheon and read-only hosts; cache paths built by one shared routine (ends permanent misses); "Pages cached" counts accurately.
* Fixed: Remove Unused CSS no longer crashes on memory-limited hosts; Delay JS no longer breaks <script type="module">; Safe Mode can switch minify off.
* Improved: conflict notices name the other plugin and link to Plugins; detection extended to 20+ more plugins and to fonts (OMGF, Perfmatters); disabled modules no longer load on the front end.
* Developers: new EasyOpt_Preflight::scan(), wizard/preflight REST route, and many new filters.

= 2.5.6 =
* Fixed: Smart Lazyload/Preload Fonts had no effect on mobile (wrong data name); desktop was fine.
* Fixed: robots.txt/llms.txt no longer cached for 30 days (now 5 minutes); preconnect no longer opens connections to metadata-only domains.
* Changed: preconnect no longer emits a duplicate dns-prefetch tag.

= 2.5.5 =
* Fixed: OpenLiteSpeed pages could download instead of display — now served in a universal format.
* Fixed: preload no longer false-stalls on shared hosting; firewall-blocked requests retry with backoff.
* Fixed: multilingual (Polylang/WPML) no longer serves the wrong language; <picture> images no longer stay blank with sliders/AJAX.
* New: browser-native lazy load (on by default); preconnect to external origins; optional Nginx rules.
* Improved: WooCommerce shoppers keep getting cached pages after add-to-cart; many more ad/email tracking parameters no longer bust the cache.
* Diagnostics (opt-in): sends less data; optional one-click deactivation feedback; Smart Images health reporting.

= 2.5.4 =
* Performance release — no feature or settings changes. Faster cache purges (deduplicated, run after the response), faster minify and Used CSS, lighter preload and object cache, and many micro-optimizations across the plugin.

= 2.5.3 =
* New: Smart Images (FluxPress CDN) — on-the-fly AVIF/WebP, per-device resizing and compression from a global edge; originals never uploaded or modified. Smart quality per browser/format.
* New: cache for logged-in users (per role; per-user via filter).
* New: Cron Events tool in your site's timezone with overdue markers and Run-now.
* Fixed: Object Cache drop-in install on PHP-FPM hosts (atomic, in-process syntax check); xCloud Redis ACL format supported.
* Improved: real "Don't show again" on notices; clearer Smart Images connection status.

= 2.5.2 =
* Fixed: Remove Unused CSS — missing styles across template variants of the same post type; shared CSS now updates as new layouts appear.
* Fixed: LCP — correctly de-lazifies the detected image with lazysizes-style lazy loading.
* Fixed: blank pages on very large pages (PCRE handling, incomplete-output safeguards).
* Fixed: Lazy Load — fewer resize events, less flicker.
* Changed: lazy iframes without dimensions reserve a 16:9 placeholder.

= 2.5.1 =
* New: Object Cache — Redis 6+ ACL username, host-provisioned WP_REDIS_CONFIG, TLS (rediss://) and unix sockets; shows which values come from wp-config.php.
* Fixed: Remove Unused CSS — Elementor entrance/hover animations (zoomInUp etc.) no longer break; helper classes safelisted.
* Improved: precise Redis connection diagnostics; Test Connection works with bundled Predis; changing DB/prefix flushes the old location.

= 2.5.0 =
* New: Object Cache module — one-click Redis (Relay/PhpRedis/Predis) for database-heavy, logged-in and WooCommerce pages; never touches another plugin's drop-in.
* New: adaptive preload speed (Gentle/Balanced/Turbo) that tunes to your server; Preload Fonts per device; Remove Unused CSS keeps JS-added classes.
* Improved: per-URL LCP preload covering background, <picture> and image-set heroes; responsive srcset via the image CDN; link prefetch rate cap and back-off.
* Fixed: preloading now works on server-cache hosts (WPX, Varnish/LiteSpeed/nginx); redirected URLs never cached under the original address.
* Fixed: URLs merely containing "sitemap/cart/checkout/my-account" are no longer wrongly excluded.

= 2.4.2 =
* Fixed: PHP open_basedir warnings on restricted hosts (CloudLinux, GridPane, RunCloud, Cloudways).
* Improved: conflict notices are feature-aware — only warn when the overlapping feature is actually on.
* Fixed: Perfmatters not detected for overlap notices (wrong path).

= 2.4.1 =
* New: Backend Analyzer measures each plugin/theme's own code (per-callback) and groups by plugin; WordPress core time shown separately.
* Improved: each test shows the average and replaces the previous result; browser fallback when the host blocks the server request.
* New: developer purge hooks — easyopt_purge_all, easyopt_purge_url, easyopt_purge_url_type.
* Added: donation link.

= 2.4.0 =
* New: dashboard speed test (PageSpeed before/after, mobile and desktop, Core Web Vitals).
* New: Backend Analyzer — find slow plugins, themes and queries.
* Fixed: admin toolbar menu restored; blank admin panel when another plugin loads React; autoload toggle on WP 6.6+.
* Fixed: cache correctness — cookie-keyed variants in .htaccess mode, debounced builder purges, settings-import pipeline, .xml/.txt slugs, HEAD requests, cached-page counter.
* New: per-site multisite drop-in config; unused-asset garbage collection; batched Cloudflare per-URL purges.
* Improved: cache engine split into modules with a classmap autoloader.

= 2.3.2 =
* Fixed: Astra .ast-header-break-point styles excluded from desktop Used CSS.
* Improved: targeted cache invalidation — editing a post clears only its URLs; comments/terms/authors clear just the affected page; multisite clears only the current site.
* Improved: WooCommerce cart/checkout/account reliably uncached (including translated slugs); per-currency cache copies.
* Improved: simplified admin-bar menu to three actions; minified prefetch/LCP scripts.

= 2.3.0 =
* New: first-run setup wizard with three presets (Safe / Balanced / Maximum); nothing enabled until you choose. Existing sites keep their settings.
* New: Minify CSS and JavaScript (local files, cached on disk, processed once).
* New: Safe Mode button — one-click revert to the conservative preset for troubleshooting.
* New: query-string debug switches (?nooptimize, ?nocache, ?nodelayjs, ?norucss).
* Fixed: Remove Unused CSS fail-safe — original styles kept until a valid Used CSS passes a confidence check.

= 2.2.2 =
* Fixed: cached page count no longer drops to near-zero in a clear→re-preload loop (recording LCP refreshes only that page).
* Improved: cache files written atomically; better Vary handling.
* Added: Debug Log records excessive purges, stalled preload and cache-write failures; log moved out of the cache directory.

= 2.2.1 =
* Fixed: HTTP 500 on some Apache/LiteSpeed servers after writing .htaccess (removed obsolete BrowserMatch gzip directives; correct mod_filter wrapper).
* New: per-server safety probe — risky .htaccess rules are tested in a throwaway directory first so they can never 500 the site.
* New: OpenLiteSpeed detected, gzip block skipped (it compresses natively).

= 2.2.0 =
* New: auto-detect and purge server-level caches across major hosts (Cloudways, Kinsta, WP Engine, SiteGround, Pantheon, Flywheel, Pressable and more); dashboard card showing detected layers.
* New: direct Varnish PURGE fallback for Cloudways without Breeze.
* Improved: server purge rate-limited and non-blocking; full purge only on explicit clear.

= 2.1.0 =
* New: Optimization tab split into JavaScript, CSS, Lazy Load, Preload LCP and Prefetch Pages; Database split into Cleanup and Autoload Health; new Heartbeat & Cron and Settings (Import/Export) tabs.
* New: Disable Block Library CSS; Rewrite Elementor Background Images; Stylesheet "Remove" option.
* Improved: "Instant Load" renamed "Prefetch Pages"; preload on by default with auto-restart.

= 2.0.3 =
* New: Heartbeat control, WP-Cron throttle, Cron Event Manager, Defer JavaScript method, separate mobile/desktop Used CSS, Autoload Health.
* Fixed: Cloudflare test connection no longer needs a pre-save.

= 2.0.2 =
* Improved: database snapshot restore.

= 2.0.1 =
* Improved: conditional module loading.

= 2.0.0 =
* Major rewrite — 25+ features: page cache, cache preload, remove unused CSS, delay JavaScript, LCP preload, prefetch, font optimization, database cleanup with snapshots, Cloudflare, 9 hosting integrations, accessibility and bloat toggles, and a new dashboard.

= 1.1 =
* Initial release — lazy load and Image CDN.

== Upgrade Notice ==

= 2.7.2 =
Fixes Remove Unused CSS dropping rules on Tailwind sites, lazy-loaded <noscript> iframes, and several Cloud Optimization issues (LCP preload, edge-served fonts, the Images toggle). Used CSS is rebuilt once after updating, so expect a short regeneration period.
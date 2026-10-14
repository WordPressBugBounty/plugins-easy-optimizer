/* Easy Optimizer: Restore / Optimize one image from the Media Library. */
(function () {
	'use strict';
	var cfg = window.easyoptMedia;
	if (!cfg || !window.fetch) {
		return;
	}
	// Capture phase: the media modal's own handlers must not swallow the click.
	document.addEventListener('click', function (e) {
		var btn = e.target && e.target.closest ? e.target.closest('[data-easyopt-img]') : null;
		if (!btn || btn.disabled) {
			return;
		}
		e.preventDefault();
		var id = btn.getAttribute('data-id');
		btn.disabled = true;
		btn.textContent = btn.getAttribute('data-busy');
		fetch(cfg.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify({ id: Number(id), do: btn.getAttribute('data-easyopt-img') })
		}).then(function (r) {
			return r.json().catch(function () { return null; });
		}).then(function (r) {
			if (!r || !r.success || !r.html) {
				throw new Error((r && r.message) || cfg.failed);
			}
			// The media modal can re-render this row from its own cached copy
			// (e.g. when the library finishes loading), so update every live
			// copy of the row and that cache, not just the clicked one.
			[].forEach.call(document.querySelectorAll('[data-easyopt-img][data-id="' + id + '"]'), function (b) {
				var el = b.closest('.easyopt-img-status');
				if (el && el.parentNode) {
					el.outerHTML = r.html;
				}
			});
			var m = window.wp && wp.media && wp.media.model && wp.media.model.Attachment ? wp.media.model.Attachment.get(id) : null;
			var c = m && m.get('compat');
			if (c && c.item) {
				var tmp = document.createElement('div');
				tmp.innerHTML = c.item;
				var old = tmp.querySelector('.easyopt-img-status');
				if (old) {
					old.outerHTML = r.html;
					m.set('compat', { item: tmp.innerHTML, meta: c.meta }, { silent: true });
				}
			}
		}).catch(function (err) {
			btn.disabled = false;
			btn.textContent = btn.getAttribute('data-label');
			window.alert((err && err.message) || cfg.failed);
		});
	}, true);
})();

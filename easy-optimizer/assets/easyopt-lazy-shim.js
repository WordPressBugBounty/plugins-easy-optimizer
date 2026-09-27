/**
 * Easy Optimizer — minimal lazy runtime (native mode).
 *
 * Ships ONLY when the page contains markup the browser cannot defer by
 * itself: CSS background images (`data-bg`), deferred `<video>`, and
 * iframes (`data-src` — kept on the JS path deliberately, because native
 * iframe lazy loading is weakly supported and fires too early for heavy
 * embeds). Images and <picture> are handled natively by the browser in
 * this mode and are not touched here.
 *
 * Emits the same lazyload -> lazyloading -> lazyloaded class sequence as
 * the full runtime, so existing theme CSS keeps working.
 *
 * @since 2.5.5
 */
(function () {
	'use strict';

	var SEL = '.lazyload[data-bg],video.lazyload,iframe.lazyload';

	function reveal( el ) {

		if ( ! el || el.dataset.easyoptDone ) {
			return;
		}
		el.dataset.easyoptDone = '1';

		el.classList.remove( 'lazyload' );
		el.classList.add( 'lazyloading' );

		var bg = el.getAttribute( 'data-bg' );

		if ( bg ) {
			el.style.backgroundImage = 'url("' + bg.replace( /"/g, '\\"' ) + '")';
			el.removeAttribute( 'data-bg' );
		}

		if ( 'IFRAME' === el.tagName ) {
			var src = el.getAttribute( 'data-src' );
			if ( src ) {
				el.setAttribute( 'src', src );
				el.removeAttribute( 'data-src' );
			}
		}

		if ( 'VIDEO' === el.tagName ) {
			restoreVideo( el );
		}

		el.classList.remove( 'lazyloading' );
		el.classList.add( 'lazyloaded' );
	}

	function restoreVideo( video ) {

		// Restore the preload level the markup originally asked for, so
		// duration/scrub data is ready by the time the visitor can press play.
		var preload = video.getAttribute( 'data-preload' );
		if ( preload ) {
			video.setAttribute( 'preload', preload );
			video.removeAttribute( 'data-preload' );
		}

		if ( ! video.hasAttribute( 'data-autoplay' ) ) {
			return;
		}
		video.removeAttribute( 'data-autoplay' );

		// Autoplay is only permitted for muted (and, on iOS, inline) video.
		// The MUTED PROPERTY is what the policy checks — setting the
		// attribute alone does not reliably mute an already-parsed element.
		video.muted = true;
		video.setAttribute( 'muted', '' );
		video.setAttribute( 'playsinline', '' );
		video.setAttribute( 'autoplay', '' );

		// The element was parsed with preload="none", so there may be no
		// media data yet — load() before play() avoids a rejected promise.
		try {
			video.load();
		} catch ( e ) {}

		var attempt = video.play();
		if ( attempt && 'function' === typeof attempt.catch ) {
			// Swallow the rejection: a blocked autoplay is a normal browser
			// decision, not an error worth surfacing in the console.
			attempt.catch( function () {} );
		}
	}

	function boot() {

		var nodes = document.querySelectorAll( SEL );

		if ( ! nodes.length ) {
			return;
		}

		// No IntersectionObserver (very old browsers): reveal everything
		// immediately. Degraded performance, never a broken page.
		if ( ! ( 'IntersectionObserver' in window ) ) {
			Array.prototype.forEach.call( nodes, reveal );
			return;
		}

		var io = new IntersectionObserver(
			function ( entries ) {
				for ( var i = 0; i < entries.length; i++ ) {
					if ( entries[ i ].isIntersecting ) {
						io.unobserve( entries[ i ].target );
						reveal( entries[ i ].target );
					}
				}
			},
			{
				// Start work slightly before the element scrolls in.
				rootMargin: '200px 0px',
				threshold: 0
			}
		);

		Array.prototype.forEach.call( nodes, function ( el ) {
			io.observe( el );
		} );

		// Content injected later (Ajax filters, infinite scroll) is picked
		// up without re-running a full document scan.
		if ( 'MutationObserver' in window ) {
			new MutationObserver( function ( records ) {
				for ( var i = 0; i < records.length; i++ ) {
					var added = records[ i ].addedNodes;
					for ( var j = 0; j < added.length; j++ ) {
						var node = added[ j ];
						if ( 1 !== node.nodeType ) {
							continue;
						}
						if ( node.matches && node.matches( SEL ) ) {
							io.observe( node );
						}
						if ( node.querySelectorAll ) {
							Array.prototype.forEach.call(
								node.querySelectorAll( SEL ),
								function ( child ) {
									io.observe( child );
								}
							);
						}
					}
				}
			} ).observe( document.documentElement, { childList: true, subtree: true } );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
})();

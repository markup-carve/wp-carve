(function () {
	'use strict';

	function setupDeck(deck) {
		if (deck.dataset.wpCarveSlidesReady === '1') {
			return;
		}
		deck.dataset.wpCarveSlidesReady = '1';

		const slides = Array.prototype.slice.call(deck.querySelectorAll('.wpcarve-slide'));
		const current = deck.querySelector('[data-wpcarve-slide-current]');
		const prev = deck.querySelector('.wpcarve-slides-prev');
		const next = deck.querySelector('.wpcarve-slides-next');
		const fullscreen = deck.querySelector('.wpcarve-slides-fullscreen');
		let index = Math.max(0, Number(deck.dataset.current || 1) - 1);

		function show(nextIndex) {
			if (!slides.length) {
				return;
			}
			index = Math.max(0, Math.min(slides.length - 1, nextIndex));
			slides.forEach(function (slide, slideIndex) {
				const active = slideIndex === index;
				slide.classList.toggle('is-active', active);
				slide.setAttribute('aria-hidden', active ? 'false' : 'true');
			});
			deck.dataset.current = String(index + 1);
			if (current) {
				current.textContent = String(index + 1);
			}
			if (prev) {
				prev.disabled = index === 0;
			}
			if (next) {
				next.disabled = index === slides.length - 1;
			}
		}

		if (prev) {
			prev.addEventListener('click', function () {
				show(index - 1);
			});
		}
		if (next) {
			next.addEventListener('click', function () {
				show(index + 1);
			});
		}
		if (fullscreen) {
			fullscreen.addEventListener('click', function () {
				if (document.fullscreenElement === deck) {
					document.exitFullscreen();
					return;
				}
				if (deck.requestFullscreen) {
					deck.requestFullscreen();
				}
			});
		}

		deck.addEventListener('keydown', function (event) {
			if (event.key === 'ArrowRight' || event.key === 'PageDown' || event.key === ' ') {
				event.preventDefault();
				show(index + 1);
			}
			if (event.key === 'ArrowLeft' || event.key === 'PageUp') {
				event.preventDefault();
				show(index - 1);
			}
			if (event.key === 'Home') {
				event.preventDefault();
				show(0);
			}
			if (event.key === 'End') {
				event.preventDefault();
				show(slides.length - 1);
			}
			if (event.key === 'f' || event.key === 'F') {
				event.preventDefault();
				if (fullscreen) {
					fullscreen.click();
				}
			}
		});

		show(index);
	}

	function init(root) {
		(root || document).querySelectorAll('[data-wpcarve-slides]').forEach(setupDeck);
	}

	document.addEventListener('DOMContentLoaded', function () {
		init(document);
	});

	if (window.MutationObserver) {
		new MutationObserver(function (records) {
			records.forEach(function (record) {
				record.addedNodes.forEach(function (node) {
					if (node.nodeType === 1) {
						init(node);
					}
				});
			});
		}).observe(document.documentElement, { childList: true, subtree: true });
	}
})();

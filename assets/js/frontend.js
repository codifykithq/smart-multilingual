(function () {
	'use strict';

	function closeSwitcher(switcher) {
		if (!switcher) return;
		switcher.classList.remove('is-open');
		var toggle = switcher.querySelector('.sml-switcher-toggle');
		if (toggle) toggle.setAttribute('aria-expanded', 'false');
	}

	function closeOthers(except) {
		document.querySelectorAll('.sml-switcher-dropdown.is-open').forEach(function (switcher) {
			if (switcher !== except) closeSwitcher(switcher);
		});
	}

	document.addEventListener('click', function (event) {
		var toggle = event.target.closest('.sml-switcher-toggle');
		if (toggle) {
			var switcher = toggle.closest('.sml-switcher-dropdown');
			if (!switcher) return;

			event.preventDefault();
			event.stopPropagation();
			var shouldOpen = !switcher.classList.contains('is-open');
			closeOthers(switcher);
			switcher.classList.toggle('is-open', shouldOpen);
			toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
			return;
		}

		if (!event.target.closest('.sml-switcher-dropdown')) {
			closeOthers(null);
		}
	});

	document.addEventListener('keydown', function (event) {
		if (event.key !== 'Escape') return;
		var open = document.querySelector('.sml-switcher-dropdown.is-open');
		if (!open) return;
		var toggle = open.querySelector('.sml-switcher-toggle');
		closeSwitcher(open);
		if (toggle) toggle.focus();
	});
})();

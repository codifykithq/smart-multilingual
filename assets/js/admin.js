jQuery(function ($) {
	'use strict';

	function selectSettingsTab(tab) {
		$('.sml-settings-tabs [data-sml-settings-tab]').removeClass('is-active').attr('aria-selected', 'false');
		$('.sml-settings-tabs [data-sml-settings-tab="' + tab + '"]').addClass('is-active').attr('aria-selected', 'true');
		$('.sml-settings-panel').removeClass('is-active');
		$('.sml-settings-panel[data-sml-settings-panel="' + tab + '"]').addClass('is-active');
		try { window.localStorage.setItem('sml-settings-tab', tab); } catch (error) {}
	}

	$(document).on('click', '[data-sml-settings-tab]', function () { selectSettingsTab($(this).data('sml-settings-tab')); });
	if ($('.sml-settings-shell').length) {
		let tab = 'general';
		try { tab = window.localStorage.getItem('sml-settings-tab') || tab; } catch (error) {}
		selectSettingsTab(tab);
	}

	$(document).on('click', '[data-sml-type-language]', function () {
		const language = $(this).data('sml-type-language');
		$('.sml-language-tabs [data-sml-type-language]').removeClass('is-active');
		$(this).addClass('is-active');
		$('.sml-type-language-panel').removeClass('is-active');
		$('.sml-type-language-panel[data-sml-type-language-panel="' + language + '"]').addClass('is-active');
	});

	function refreshFontRow($row) {
		const type = $row.find('.sml-font-source-type').val() || 'existing';
		$row.attr('data-font-type', type);
	}

	$(document).on('change', '.sml-font-source-type', function () { refreshFontRow($(this).closest('.sml-font-source-row')); });
	$('.sml-font-source-row').each(function () { refreshFontRow($(this)); });

	$(document).on('click', '.sml-add-font-source', function () {
		const language = $(this).data('language');
		const $rows = $('[data-sml-font-rows="' + language + '"]');
		const template = $('#tmpl-sml-font-source-' + language).html();
		if (!template || !$rows.length) return;
		const index = parseInt($rows.attr('data-next-index') || '0', 10);
		$rows.append(template.split('__INDEX__').join(String(index)));
		$rows.attr('data-next-index', String(index + 1));
		refreshFontRow($rows.children().last());
	});

	$(document).on('click', '.sml-remove-font-source', function () {
		const $rows = $(this).closest('.sml-font-rows');
		$(this).closest('.sml-font-source-row').remove();
		if (!$rows.children('.sml-font-source-row').length) {
			$('.sml-add-font-source[data-language="' + $rows.data('sml-font-rows') + '"]').trigger('click');
		}
	});

	$(document).on('change', '.sml-rule-enabled', function () { $(this).closest('.sml-type-rule').toggleClass('is-enabled', this.checked); });

	$(document).on('click', '.sml-font-upload', function (event) {
		event.preventDefault();
		const $input = $(this).closest('.sml-font-source-row').find('.sml-font-url');
		if (typeof wp === 'undefined' || !wp.media) {
			window.alert('WordPress Media Library could not be loaded. Reload this page and try again.');
			return;
		}
		const frame = wp.media({ title: 'Choose or upload WOFF / WOFF2 font', button: { text: 'Use this font' }, multiple: false });
		frame.on('select', function () {
			const attachment = frame.state().get('selection').first();
			if (attachment) $input.val(attachment.toJSON().url).trigger('change');
		});
		frame.open();
	});
});


jQuery(function ($) {
	'use strict';
	let smlLanguageIndex = 0;
	$('#sml-add-language').on('click', function () {
		const template = $('#tmpl-sml-language-row').html();
		if (!template) return;
		smlLanguageIndex += 1;
		const key = 'new_' + Date.now() + '_' + smlLanguageIndex;
		$('#sml-language-rows').append(template.split('__CODE__').join(key));
	});
	$(document).on('click', '.sml-remove-language', function () {
		$(this).closest('tr').remove();
	});
});

jQuery(function ($) {
	'use strict';

	function updateMediaTranslationStatus($box, language) {
		const $inputs = $box.find('[data-sml-media-lang="' + language + '"]');
		let filled = 0;
		$inputs.each(function () {
			if ($.trim($(this).val()) !== '') filled += 1;
		});

		const $tab = $box.find('[data-sml-media-language="' + language + '"]');
		const $status = $tab.find('.sml-media-tab-status');
		$status.removeClass('is-complete is-partial is-empty');
		if (filled === $inputs.length && $inputs.length) {
			$status.addClass('is-complete').text('Complete');
		} else if (filled > 0) {
			$status.addClass('is-partial').text('Partial');
		} else {
			$status.addClass('is-empty').text('Empty');
		}

		let complete = 0;
		$box.find('.sml-media-tab-status.is-complete').each(function () { complete += 1; });
		$box.find('.sml-media-progress b').text(complete);
	}

	$(document).on('click', '.sml-media-tab', function () {
		const $tab = $(this);
		const $box = $tab.closest('.sml-media-translations');
		const language = $tab.data('sml-media-language');
		$box.find('.sml-media-tab').removeClass('is-active').attr('aria-selected', 'false');
		$tab.addClass('is-active').attr('aria-selected', 'true');
		$box.find('.sml-media-panel').removeClass('is-active');
		$box.find('[data-sml-media-panel="' + language + '"]').addClass('is-active');
	});

	$(document).on('input change', '.sml-media-translations [data-sml-media-lang]', function () {
		const $input = $(this);
		updateMediaTranslationStatus($input.closest('.sml-media-translations'), $input.data('sml-media-lang'));
	});

	$(document).on('click', '.sml-copy-media-source', function () {
		const $button = $(this);
		const $box = $button.closest('.sml-media-translations');
		const language = $button.data('target-language');
		const sourceLanguage = $button.data('source-language');
		$box.find('[data-sml-media-lang="' + language + '"]').each(function () {
			const $target = $(this);
			const field = $target.data('sml-media-field');
			const source = $box.find('[data-sml-media-lang="' + sourceLanguage + '"][data-sml-media-field="' + field + '"]').val() || '';
			if ($.trim($target.val()) === '') $target.val(source).trigger('input');
		});
	});
});

jQuery(function ($) {
	'use strict';
	if ($.fn.wpColorPicker) {
		$('.sml-color-text').each(function () {
			const $input = $(this);
			$input.wpColorPicker({
				change: function (event, ui) {
					$input.val(ui.color.toString()).trigger('change');
				},
				clear: function () {
					$input.val('transparent').trigger('change');
				}
			});
		});
	}
});

jQuery(function ($) {
	'use strict';

	$('#sml-add-slider-map').on('click', function () {
		const $rows = $('#sml-slider-map-rows');
		const template = $('#tmpl-sml-slider-map-row').html();
		if (!template || !$rows.length) return;
		const index = parseInt($rows.attr('data-next-index') || '0', 10);
		$rows.append(template.split('__INDEX__').join(String(index)));
		$rows.attr('data-next-index', String(index + 1));
	});

	$(document).on('click', '.sml-remove-slider-map', function () {
		const $rows = $('#sml-slider-map-rows');
		$(this).closest('.sml-slider-map-row').remove();
		if (!$rows.children('.sml-slider-map-row').length) {
			$('#sml-add-slider-map').trigger('click');
		}
	});
});

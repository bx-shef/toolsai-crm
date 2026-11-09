/**
 * Статья справки в узком слайдере (1.75.0, #432): «?» настроек, «Подробнее»
 * мастера промптов, «Как это работает?». Адрес — документ «Базы знаний 2.0»
 * ('/note/document/<id>/#якорь', Settings\KbLink). После загрузки слайдера —
 * прокрутка к якорю: хеш фрейма может потеряться, якорь берём и из адреса.
 *
 * Один файл на оба места: settings/component.php и settings/promptlibrary.js
 * (подключается раньше обоих). В node — module.exports (тесты).
 */
(function(root)
{
	'use strict';

	/** Якорь из адреса: '/x#якорь' → 'якорь' (хеш раскодирован). */
	function anchorOf(url)
	{
		var text = String(url || '');
		var i = text.indexOf('#');
		if (i < 0) { return ''; }
		var hash = text.slice(i + 1);
		try { hash = decodeURIComponent(hash); } catch (e) {}
		return hash;
	}

	/**
	 * Прокрутить страницу в слайдере к якорю: ждём заголовок до 8 с (страница
	 * дорисовывается после onLoad), потом прокручиваем ещё дважды.
	 */
	function scrollToAnchor(win, fallback)
	{
		var anchor = '';
		try { anchor = anchorOf(win.location.hash); } catch (e) { anchor = ''; }
		anchor = anchor || anchorOf(fallback);
		if (!anchor) { return; }
		var tries = 0;
		var scroll = function()
		{
			try
			{
				var el = win.document.getElementById(anchor);
				if (el) { el.scrollIntoView({ block: 'start' }); }
			}
			catch (e) {}
		};
		var tick = function()
		{
			var el = null, ready = true;
			try
			{
				el = win.document.getElementById(anchor);
				var app = win.document.getElementById('__nuxt');
				ready = !app || !app.hasAttribute || app.hasAttribute('data-v-app');
			}
			catch (e) { return; }
			if (el && ready)
			{
				scroll();
				win.setTimeout(scroll, 300);
				win.setTimeout(scroll, 1000);
				return;
			}
			if (++tries < 40) { win.setTimeout(tick, 200); }
		};
		tick();
	}

	/** Открыть адрес узким слайдером bx (BX верхнего окна) с прокруткой к якорю. */
	function open(bx, url)
	{
		bx.SidePanel.Instance.open(url, {
			width: 760, cacheable: false, allowChangeHistory: false,
			events: { onLoad: function(event) { scrollToAnchor(event.getSlider().getFrameWindow(), url); } }
		});
	}

	/** Щелчок, который слайдер не перехватывает: Ctrl/Cmd/Shift, не левая кнопка. */
	function isPlainClick(event)
	{
		return !event.defaultPrevented && event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey;
	}

	var Slider = { anchorOf: anchorOf, scrollToAnchor: scrollToAnchor, open: open, isPlainClick: isPlainClick };
	if (typeof module !== 'undefined' && module.exports) { module.exports = Slider; }
	root.ShefKbSlider = Slider;
})(typeof window !== 'undefined' ? window : globalThis);

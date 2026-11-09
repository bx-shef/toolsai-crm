/**
 * Переход по левому меню настроек (1.62.1): старый раздел сразу затемняется,
 * пункт меню подсвечивается, по центру — штатный загрузчик ядра (BX.Loader,
 * main.loader). Меню — bitrix:ui.sidepanel.wrappermenu, пункты — обычные
 * ссылки: переход — полная загрузка страницы слайдера (IFRAME_TYPE=SIDE_SLIDER),
 * поэтому загрузчик держится до ухода со страницы. Не ушли за TIMEOUT —
 * сообщение и «Повторить».
 *
 * Подставляется сервером (settings/component.php) в try/catch.
 * Тест — tests/navloader_test.mjs.
 */
(function (root) {
	'use strict';
	var TIMEOUT = 15000;
	var MENU_LINK = 'a.ui-sidepanel-menu-link';
	var ACTIVE = 'ui-sidepanel-menu-active';
	var DROP = ['IFRAME', 'IFRAME_TYPE', 'saved', 'audioSaved', 'sessid'];

	/**
	 * Адрес перехода по щелчку в меню или '' — щелчок не наш (не меню, другая
	 * вкладка, Ctrl/Shift/средняя кнопка, другой сайт, тот же раздел).
	 */
	function navigationUrl(event, link, location) {
		if (!link || !event || event.defaultPrevented || event.button !== 0) {
			return '';
		}
		if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
			return '';
		}
		var target = link.getAttribute('target');
		if (target && target !== '_self') {
			return '';
		}
		var href = link.getAttribute('href') || '';
		if (href === '' || href.charAt(0) === '#' || /^javascript:/i.test(href)) {
			return '';
		}
		if (/^[a-z]+:\/\//i.test(href) && href.indexOf(location.origin + '/') !== 0) {
			return '';
		}
		return href;
	}

	/** Подсветить пункт меню сразу, остальные — снять. */
	function activate(link) {
		var item = link.closest ? link.closest('.ui-sidepanel-menu-item') : null;
		var menu = item && item.parentNode;
		if (!item || !menu) {
			return false;
		}
		var all = menu.querySelectorAll('.ui-sidepanel-menu-item');
		for (var i = 0; i < all.length; i++) {
			all[i].classList.remove(ACTIVE);
		}
		item.classList.add(ACTIVE);
		return true;
	}

	/** Состояние области раздела: 'loading' | 'error' | '' (как было). */
	function setState(win, area, state, url) {
		var doc = win.document;
		var cover = doc.getElementById('shef-tai-nav-cover');
		if (!cover) {
			cover = doc.createElement('div');
			cover.id = 'shef-tai-nav-cover';
			cover.className = 'shef-tai-nav-cover';
			area.appendChild(cover);
		}
		area.classList.toggle('--nav-loading', state !== '');
		cover.hidden = state === '';
		cover.textContent = '';
		if (state === 'loading') {
			if (win.BX && typeof win.BX.Loader === 'function') {
				try { new win.BX.Loader({ target: cover, size: 80 }).show(); } catch (e) {}
			} else {
				cover.textContent = 'Загрузка…';
			}
		}
		if (state === 'error') {
			var text = doc.createElement('p');
			text.className = 'shef-tai-nav-error';
			text.textContent = 'Раздел загружается слишком долго или не открылся. Проверьте связь и повторите.';
			var again = doc.createElement('button');
			again.type = 'button';
			again.className = 'ui-btn ui-btn-primary ui-btn-sm';
			again.textContent = 'Повторить';
			again.onclick = function () { setState(win, area, 'loading', url); win.location.href = url; };
			cover.appendChild(text);
			cover.appendChild(again);
		}
		return cover;
	}

	/**
	 * Адрес раздела для слайдера и строки браузера (#299): текущий адрес
	 * страницы без служебных IFRAME и IFRAME_TYPE (их слайдер добавляет сам)
	 * и без разовых отметок после действия (saved, audioSaved, sessid):
	 * обновление не должно снова показать «Настройки сохранены».
	 */
	function sliderUrl(href) {
		var hash = '';
		var at = href.indexOf('#');
		if (at >= 0) {
			hash = href.slice(at);
			href = href.slice(0, at);
		}
		var q = href.indexOf('?');
		if (q < 0) {
			return href + hash;
		}
		var kept = href.slice(q + 1).split('&').filter(function (pair) {
			var name = pair.split('=')[0];
			return pair !== '' && DROP.indexOf(name) < 0;
		});
		return href.slice(0, q) + (kept.length ? '?' + kept.join('&') : '') + hash;
	}

	/**
	 * Раздел сменился полной загрузкой страницы в слайдере, а слайдер помнит
	 * адрес, с которым открыт, — и пишет его в строку браузера. Сообщаем ему
	 * новый адрес (Slider::setUrl) и обновляем строку штатно
	 * (SliderManager::setBrowserHistory — replaceState, если слайдеру можно
	 * менять историю). Обновление страницы и «Скопировать ссылку» откроют
	 * текущий раздел.
	 */
	function syncUrl(win) {
		var top = win.top;
		if (!top || top === win || !top.BX || !top.BX.SidePanel || !top.BX.SidePanel.Instance) {
			return false;
		}
		var manager = top.BX.SidePanel.Instance;
		var slider = typeof manager.getSliderByWindow === 'function' ? manager.getSliderByWindow(win) : null;
		if (!slider || typeof slider.setUrl !== 'function') {
			return false;
		}
		slider.setUrl(sliderUrl(win.location.pathname + win.location.search + win.location.hash));
		if (typeof manager.setBrowserHistory === 'function') {
			manager.setBrowserHistory(slider);
		} else if (typeof slider.canChangeHistory === 'function' && slider.canChangeHistory() && top.history) {
			// setBrowserHistory у ядра помечен @private — пропал, делаем то же сами
			top.history.replaceState({}, '', slider.getUrl());
		}
		return true;
	}

	/** Запуск: щелчки по меню, уход со страницы, возврат из кэша браузера. */
	function start(win) {
		var doc = win.document;
		var area = doc.querySelector('.shef-tai');
		if (!area) {
			return false;
		}
		try { syncUrl(win); } catch (e) {}
		var timer = 0;
		// url — щелчок по меню: не ушли за TIMEOUT — ошибка и «Повторить».
		// Без url (beforeunload: форма, скачивание файла) — через 5 с просто снять.
		var busy = function (url) {
			setState(win, area, 'loading', url);
			win.clearTimeout(timer);
			timer = url
				? win.setTimeout(function () { setState(win, area, 'error', url); }, TIMEOUT)
				: win.setTimeout(function () { setState(win, area, '', ''); }, 5000);
		};
		doc.addEventListener('click', function (event) {
			try {
				var link = event.target && event.target.closest ? event.target.closest(MENU_LINK) : null;
				var url = navigationUrl(event, link, win.location);
				if (!url) {
					return;
				}
				activate(link);
				busy(url);
			} catch (e) {}
		});
		// Отправка формы раздела и прочие уходы — тоже загрузчик
		win.addEventListener('beforeunload', function () {
			try { if (!area.classList.contains('--nav-loading')) { busy(''); } } catch (e) {}
		});
		// Назад из кэша браузера — страница как была
		win.addEventListener('pageshow', function (event) {
			if (event && event.persisted) { win.clearTimeout(timer); setState(win, area, '', ''); }
		});
		return true;
	}

	var api = { navigationUrl: navigationUrl, sliderUrl: sliderUrl, syncUrl: syncUrl, activate: activate, setState: setState, start: start, TIMEOUT: TIMEOUT };
	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	} else if (root && root.document) {
		root.ShefToolsAiNavLoader = api;
		start(root);
	}
})(typeof window !== 'undefined' ? window : this);

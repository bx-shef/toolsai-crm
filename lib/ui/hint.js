/**
 * Подсказка «?» на месте (bx-shef/toolsai#734): объясняет, не уводя со страницы.
 * Узкий экран — шторка снизу, страница под ней затемнена; широкий — всплывающая подсказка у значка.
 * В подсказке: заголовок, короткий ответ («что это», «зачем»), «Подробнее» (статья в базе знаний) и «Понятно».
 *
 * Значок — <button class="shef-tai-q" data-hint-title data-hint-what [data-hint-why] [data-hint-more]>?</button>
 * (Settings\Faq::icon()). Обработчик один, на документе: значки, которые страница дорисовала скриптом
 * (форма настроек), работают без привязки. Тексты берутся только из атрибутов и ставятся как textContent.
 *
 * Закрывается: «Понятно», щелчок по затемнению, Esc, на телефоне — свайп шторки вниз; фокус возвращается на «?».
 * Открывается щелчком, касанием, Enter и Пробелом (штатная кнопка). Стили — .shef-hint* в lib/ui/tai.css.
 *
 * Эталон — этот файл в shef.toolsai, у соседей линейки (shef.toolsaicrm, shef.toolsaiassistant) копия:
 * правки только здесь, сверка — tools/hint-sync.sh. В node — module.exports (tests/hint_test.mjs).
 */
(function(root)
{
	'use strict';

	var NARROW = 640;  // px: не шире — шторка снизу, шире — подсказка у значка
	var SWIPE = 60;    // px вниз: шторка закрывается
	var GAP = 8;       // px между значком и подсказкой
	var EDGE = 12;     // px до края окна
	var uid = 0;

	/** Адрес «Подробнее»: только свой портал, '/путь' — без '//', '\', пробелов и схем. */
	function safeUrl(url)
	{
		var text = String(url || '');
		return /^\/(?![\/\\])[^\s\\]*$/.test(text) ? text : '';
	}

	/** Содержимое подсказки из атрибутов значка; нет «что это» — подсказки нет. */
	function read(node)
	{
		var get = function(name)
		{
			return String(node.getAttribute('data-hint-' + name) || '').trim();
		};
		var what = get('what');

		return what === '' ? null : { title: get('title'), what: what, why: get('why'), more: safeUrl(get('more')) };
	}

	function clamp(value, min, max)
	{
		return Math.max(min, Math.min(value, max));
	}

	/**
	 * Где поставить всплывающую подсказку: под значком, по центру значка, в границах окна;
	 * не влезает вниз — над значком; не влезает никуда — прижата к краю.
	 * anchor — {left, right, top, bottom} значка, size — {w, h} подсказки, view — {w, h} окна.
	 */
	function place(anchor, size, view)
	{
		var left = clamp(anchor.left + (anchor.right - anchor.left) / 2 - size.w / 2, EDGE, Math.max(EDGE, view.w - size.w - EDGE));
		var below = anchor.bottom + GAP;
		if (below + size.h + EDGE <= view.h)
		{
			return { left: left, top: below, above: false };
		}
		var above = anchor.top - GAP - size.h;
		if (above >= EDGE)
		{
			return { left: left, top: above, above: true };
		}

		return { left: left, top: clamp(below, EDGE, Math.max(EDGE, view.h - size.h - EDGE)), above: false };
	}

	/** Подключает подсказку к окну win; возвращает {open, close, isOpen} (для тестов). */
	function bind(win)
	{
		var doc = win.document;
		var state = null;

		var make = function(tag, className, text)
		{
			var node = doc.createElement(tag);
			if (className) { node.className = className; }
			if (text) { node.textContent = text; }

			return node;
		};

		var narrow = function()
		{
			return (win.innerWidth || 0) <= NARROW;
		};

		function close(keepFocus)
		{
			if (!state) { return; }
			var closing = state;
			state = null;
			if (closing.root.parentNode) { closing.root.parentNode.removeChild(closing.root); }
			closing.trigger.setAttribute('aria-expanded', 'false');
			doc.removeEventListener('keydown', onKey, true);
			doc.removeEventListener('scroll', onScroll, true);
			win.removeEventListener('resize', onResize);
			if (keepFocus !== true && closing.trigger.focus) { closing.trigger.focus(); }
		}

		function onKey(event)
		{
			if (!state) { return; }
			if (event.key === 'Escape' || event.key === 'Esc')
			{
				// Слайдер портала тоже закрывается по Esc: подсказка забирает нажатие себе
				event.preventDefault();
				event.stopPropagation();
				close();
				return;
			}
			if (event.key !== 'Tab') { return; }
			// Фокус не уходит за подсказку: «Подробнее» ↔ «Понятно»
			var items = state.more ? [state.more, state.ok] : [state.ok];
			var index = items.indexOf(doc.activeElement);
			if (event.shiftKey ? index <= 0 : (index === -1 || index === items.length - 1))
			{
				event.preventDefault();
				items[event.shiftKey ? items.length - 1 : 0].focus();
			}
		}

		// Подсказка у значка уезжает, если страница прокручена или окно изменилось; шторка стоит на месте
		function onScroll(event)
		{
			if (state && !state.sheet && !state.root.contains(event.target)) { close(true); }
		}

		function onResize()
		{
			if (state && (!state.sheet || !narrow())) { close(true); }
		}

		/**
		 * Свайп шторки вниз: тянется за пальцем, дальше SWIPE px — закрывается. Начинается, только когда
		 * текст в начале: иначе тот же жест листает длинный ответ вверх (листается body, а не box).
		 */
		function swipe(box, body)
		{
			var startY = null;
			var dy = 0;
			var reset = function()
			{
				box.style.transition = '';
				box.style.transform = '';
				startY = null;
			};
			box.addEventListener('touchstart', function(event)
			{
				if (event.touches.length === 1 && body.scrollTop <= 0)
				{
					startY = event.touches[0].clientY;
					dy = 0;
				}
			}, { passive: true });
			box.addEventListener('touchmove', function(event)
			{
				if (startY === null) { return; }
				dy = Math.max(0, event.touches[0].clientY - startY);
				box.style.transition = 'none';
				box.style.transform = dy > 0 ? 'translateY(' + dy + 'px)' : '';
			}, { passive: true });
			box.addEventListener('touchend', function()
			{
				if (startY !== null && dy > SWIPE) { close(); return; }
				reset();
			});
			box.addEventListener('touchcancel', reset);
		}

		function open(trigger)
		{
			var data = read(trigger);
			if (!data) { return; }
			var sheet = narrow();
			var id = 'shef-hint-t' + (++uid);
			var rootNode = make('div', 'shef-hint ' + (sheet ? '--sheet' : '--pop'));
			var dim = make('div', 'shef-hint__dim');
			var box = make('div', 'shef-hint__box');
			box.setAttribute('role', 'dialog');
			box.setAttribute('aria-modal', 'true');
			box.setAttribute('tabindex', '-1');
			var body = make('div', 'shef-hint__body');
			body.appendChild(make('div', 'shef-hint__grip'));
			var title = make('div', 'shef-hint__title', data.title || data.what);
			title.id = id;
			box.setAttribute('aria-labelledby', id);
			body.appendChild(title);
			if (data.title) { body.appendChild(make('p', 'shef-hint__text', data.what)); }
			if (data.why) { body.appendChild(make('p', 'shef-hint__text --why', data.why)); }
			box.appendChild(body);
			var foot = make('div', 'shef-hint__foot');
			var more = null;
			if (data.more)
			{
				more = make('a', 'shef-hint__more', 'Подробнее');
				more.setAttribute('href', data.more);
				more.setAttribute('target', '_blank');
				more.setAttribute('rel', 'noopener');
				// Статью открывает общий обработчик a[data-kb] страницы настроек (слайдер поверх портала)
				more.setAttribute('data-kb', data.more);
				more.addEventListener('click', function() { win.setTimeout(function() { close(true); }, 0); });
				foot.appendChild(more);
			}
			var ok = make('button', 'shef-hint__ok', 'Понятно');
			ok.setAttribute('type', 'button');
			ok.addEventListener('click', function() { close(); });
			foot.appendChild(ok);
			box.appendChild(foot);
			dim.addEventListener('click', function() { close(); });
			rootNode.appendChild(dim);
			rootNode.appendChild(box);
			doc.body.appendChild(rootNode);

			state = { root: rootNode, trigger: trigger, sheet: sheet, more: more, ok: ok };
			trigger.setAttribute('aria-expanded', 'true');
			if (sheet)
			{
				swipe(box, body);
			}
			else
			{
				var at = place(trigger.getBoundingClientRect(), { w: box.getBoundingClientRect().width, h: box.getBoundingClientRect().height }, { w: win.innerWidth, h: win.innerHeight });
				box.style.left = at.left + 'px';
				box.style.top = at.top + 'px';
			}
			doc.addEventListener('keydown', onKey, true);
			doc.addEventListener('scroll', onScroll, true);
			win.addEventListener('resize', onResize);
			ok.focus();
		}

		doc.addEventListener('click', function(event)
		{
			var trigger = event.target && event.target.closest ? event.target.closest('[data-hint-what]') : null;
			if (!trigger) { return; }
			// «?» внутри подписи, ссылки или пункта меню не нажимает их
			event.preventDefault();
			event.stopPropagation();
			if (state && state.trigger === trigger)
			{
				close();
				return;
			}
			close(true);
			open(trigger);
		});

		return { open: open, close: close, isOpen: function() { return state !== null; } };
	}

	var Hint = { read: read, place: place, safeUrl: safeUrl, bind: bind, NARROW: NARROW, SWIPE: SWIPE };
	if (typeof module !== 'undefined' && module.exports) { module.exports = Hint; }
	root.ShefHint = Hint;
	// В браузере подключаем сразу; повторный вызов (скрипт на странице дважды) — холостой
	if (root.document && !root.shefHintBound)
	{
		root.shefHintBound = true;
		Hint.controller = bind(root);
	}
})(typeof window !== 'undefined' ? window : globalThis);

/**
 * Форма настроек в стиле «Настроек BitrixGPT» CRM (1.64.0, bx-shef/toolsai#320 T;
 * образец — crm/install/js/crm/ai/settings-slider: карточка, шапка с иконкой
 * и «Как это работает?», строки «подпись — поле», кнопки внизу).
 *
 *   ShefUiSettingsForm.render(container, sections)
 *
 * sections — Ui\SettingsForm::fromTabs(): карточка на вкладку shef.options,
 * строка на поле. Поля — штатные ui-ctl и ui.switcher; имена — как у
 * shef.options, поэтому форма отправляется прежним POST.
 */
(function()
{
	'use strict';

	if(!window.BX || !BX.UI || !BX.UI.Switcher)
	{
		// Штатные поля не загрузились: render() не определён, флаг отрисовки
		// не ставится, и сохранение ничего не затрёт (SettingsForm::collect()).
		return;
	}

	var el = function(tag, className, text)
	{
		var node = document.createElement(tag);
		if(className)
		{
			node.className = className;
		}
		if(text)
		{
			node.textContent = text;
		}

		return node;
	};

	var htmlNode = function(className, html)
	{
		var node = el('div', className);
		node.innerHTML = html;

		return node;
	};

	var uid = 0;

	var control = function(field)
	{
		var id = 'shef-ui-f' + (++uid);
		var box, input;
		switch(field.type)
		{
			case 'checker':
				box = el('span', 'shef-ui-row__switch');
				var switcher = new BX.UI.Switcher({
					inputName: field.name,
					checked: field.checked === true,
					size: 'small',
					color: 'green'
				});
				switcher.renderTo(box);
				// Скрытое поле переключателя пусто до первого щелчка, а пустое
				// значение SettingsForm::collect() прочтёт как «нет»
				if(switcher.inputNode)
				{
					switcher.inputNode.value = field.checked === true ? 'Y' : 'N';
				}

				return {node: box, id: ''};
			case 'selector':
				box = el('div', 'ui-ctl ui-ctl-after-icon ui-ctl-dropdown ui-ctl-w100 shef-ui-row__select');
				box.appendChild(el('div', 'ui-ctl-after ui-ctl-icon-angle'));
				input = el('select', 'ui-ctl-element');
				(field.items || []).forEach(function(item)
				{
					var option = el('option', '', item.name);
					option.value = item.value;
					option.selected = item.value === field.value;
					input.appendChild(option);
				});
				break;
			case 'textarea':
				box = el('div', 'ui-ctl ui-ctl-textarea ui-ctl-w100 ui-ctl-resize-y');
				input = el('textarea', 'ui-ctl-element');
				input.rows = 4;
				input.value = field.value || '';
				break;
			case 'secret':
				// Ключ API (#283): значения в странице нет — поле пустое; что сохранено —
				// строкой под полем (secretState, #527); «Показать» — только набранное
				box = el('div', 'ui-ctl ui-ctl-textbox ui-ctl-after-icon ui-ctl-w100');
				input = el('input', 'ui-ctl-element');
				input.type = 'password';
				input.autocomplete = 'new-password';
				input.maxLength = 2000;
				input.placeholder = field.placeholder || '';
				var eye = el('button', 'ui-ctl-after shef-ui-row__eye', 'Показать');
				eye.type = 'button';
				eye.addEventListener('click', function()
				{
					var shown = input.type === 'text';
					input.type = shown ? 'password' : 'text';
					eye.textContent = shown ? 'Показать' : 'Скрыть';
				});
				box.appendChild(eye);
				break;
			default:
				box = el('div', 'ui-ctl ui-ctl-textbox ui-ctl-w100');
				input = el('input', 'ui-ctl-element');
				input.type = field.type === 'number' ? 'number' : 'text';
				input.maxLength = 2000;
				input.value = field.value || '';
				input.placeholder = field.placeholder || '';
		}
		input.name = field.name;
		input.id = id;
		box.appendChild(input);

		return {node: box, id: id};
	};

	var row = function(field)
	{
		if(field.type === 'info')
		{
			return htmlNode('shef-ui-row__info', field.html);
		}
		var wide = field.type === 'textarea';
		var node = el('div', 'shef-ui-row' + (field.type === 'checker' ? ' --switch' : '') + (wide ? ' --wide' : ''));
		var made = control(field);
		var label = el('label', 'shef-ui-row__label');
		label.appendChild(document.createTextNode(field.label));
		if(made.id)
		{
			label.htmlFor = made.id;
		}
		// «?» — кнопка подсказки на месте (Faq::icon)
		if(field.hintIcon)
		{
			label.appendChild(document.createTextNode(' '));
			label.appendChild(htmlNode('shef-ui-row__q', field.hintIcon));
		}
		if(field.hint)
		{
			label.title = field.hint;
		}
		node.appendChild(label);
		var body = el('div', 'shef-ui-row__body');
		body.appendChild(made.node);
		// Ключ: «Сохранён: ••••XXXX» / «Берётся из: …» / «Не задан» — текстом, не серым в поле (#527)
		if(field.type === 'secret' && field.secretState)
		{
			body.appendChild(el('div', 'shef-ui-row__secret' + (field.secretEmpty === true ? ' --empty' : ''), field.secretState));
		}
		if(field.description)
		{
			body.appendChild(htmlNode('shef-ui-row__desc', field.description));
		}
		else if(field.hint)
		{
			body.appendChild(el('div', 'shef-ui-row__desc', field.hint));
		}
		node.appendChild(body);

		return node;
	};

	var card = function(sectionData, open)
	{
		var node = el('section', 'shef-ui-card' + (open ? '' : ' --collapsed'));
		node.id = 'shef-ui-section-' + sectionData.id;
		var head = el('div', 'shef-ui-card__head');
		var icon = el('span', 'shef-ui-card__icon');
		icon.appendChild(el('span', 'ui-icon-set ' + (sectionData.icon || '--o-settings')));
		head.appendChild(icon);
		head.appendChild(el('span', 'shef-ui-card__title', sectionData.title));
		if(sectionData.help)
		{
			var help = el('a', 'ui-btn ui-btn-sm ui-btn-no-caps --air --style-outline shef-ui-card__help');
			help.href = sectionData.help;
			help.target = '_blank';
			help.setAttribute('data-kb', sectionData.help);
			help.appendChild(el('span', 'ui-btn-text', 'Как это работает?'));
			head.appendChild(help);
		}
		var toggle = el('button', 'shef-ui-card__toggle');
		toggle.type = 'button';
		head.appendChild(toggle);
		node.appendChild(head);

		var body = el('div', 'shef-ui-card__body');
		if(sectionData.description)
		{
			body.appendChild(el('div', 'shef-ui-card__desc', sectionData.description));
		}
		sectionData.fields.forEach(function(field)
		{
			body.appendChild(row(field));
		});
		node.appendChild(body);

		var sync = function()
		{
			var collapsed = node.classList.contains('--collapsed');
			toggle.textContent = collapsed ? 'Развернуть' : 'Свернуть';
			toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
		};
		var flip = function()
		{
			node.classList.toggle('--collapsed');
			sync();
		};
		toggle.addEventListener('click', flip);
		head.addEventListener('click', function(event)
		{
			if(node.classList.contains('--collapsed') && !event.target.closest('a, button'))
			{
				flip();
			}
		});
		sync();

		return node;
	};

	var render = function(container, sections)
	{
		sections.forEach(function(sectionData, index)
		{
			container.appendChild(card(sectionData, index === 0 || sections.length <= 2));
		});

		return true;
	};

	window.ShefUiSettingsForm = {render: render};
})();

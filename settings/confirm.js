/**
 * Окно подтверждения — штатный BX.UI.Dialogs.MessageBox в виде Air, как «Закрыть без сохранения?»
 * в задачах Битрикс24: заголовок вопросом, короткое пояснение, кнопки обычным регистром; опасное
 * действие — красная кнопка. Esc — отмена, Enter — основная кнопка (на ней фокус, кроме сенсорных
 * экранов). Тёмная тема портала — штатный контекст --ui-context-content-dark; на узком экране окно
 * уже экрана на 16 px с каждой стороны.
 *
 * ShefToolsAiConfirm({title, message (строка или узел), ok, cancel, danger, width, focus,
 * onOk(box) → false оставляет окно открытым}).
 *
 * Формы: <form data-confirm-title="Удалить…?" data-confirm-text="…" data-confirm-ok="Удалить"
 * data-confirm-danger="Y" [data-confirm-if="mode=again"]> — отправка только после «да».
 *
 * Подключают settings/component.php и view/page.php (нужен ui.dialogs.messagebox).
 */
(function(root)
{
	'use strict';

	function ask(o)
	{
		var BX = root.BX;
		var doc = root.document;
		var screen = Math.min(root.innerWidth || 9999, doc.documentElement.clientWidth || 9999);
		var width = Math.max(260, Math.min(o.width || 400, screen - 32));
		var dark = doc.body.classList.contains('bitrix24-dark-theme') || doc.documentElement.classList.contains('shef-aip-dark');
		var box = BX.UI.Dialogs.MessageBox.create({
			useAirDesign: true,
			title: o.title,
			message: o.message || '',
			modal: true,
			buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
			okCaption: o.ok,
			cancelCaption: o.cancel || 'Отмена',
			minWidth: width,
			maxWidth: width,
			onOk: function(mb) { return !(o.onOk && o.onOk(mb) === false); },
			onCancel: function() { return true; },
			popupOptions: {closeIcon: false, closeByEsc: true, focusTrap: true, autoHide: false}
		});
		if (o.danger) { box.getOkButton().setStyle(BX.UI.AirButtonStyle.FILLED_ALERT); }
		box.show();
		var node = box.getPopupWindow().getPopupContainer();
		node.classList.add('shef-tai-dialog');
		if (dark)
		{
			node.classList.add('--ui-context-content-dark');
			node.style.setProperty('--popup-window-background-color', 'var(--ui-color-bg-content-primary)');
		}
		var touch = root.matchMedia && root.matchMedia('(hover: none)').matches;
		if (o.focus !== false && !touch) { root.setTimeout(function() { box.getOkButton().getContainer().focus(); }, 0); }
		return box;
	}

	/** Условие data-confirm-if="поле=значение": спрашивать только при этом значении поля. */
	function needed(form)
	{
		var rule = form.getAttribute('data-confirm-if');
		if (!rule) { return true; }
		var pair = rule.split('=');
		var field = form.elements[pair[0]];
		return !!field && String(field.value) === pair.slice(1).join('=');
	}

	root.ShefToolsAiConfirm = ask;
	if (!root.document || root.ShefToolsAiConfirmForms) { return; }
	root.ShefToolsAiConfirmForms = true;
	root.document.addEventListener('submit', function(event)
	{
		var form = event.target;
		if (!form || !form.hasAttribute || !form.hasAttribute('data-confirm-title')) { return; }
		if (form.getAttribute('data-confirmed') === 'Y') { form.removeAttribute('data-confirmed'); return; }
		if (!needed(form)) { return; }
		event.preventDefault();
		var submitter = event.submitter || null;
		ask({
			title: form.getAttribute('data-confirm-title'),
			message: form.getAttribute('data-confirm-text') || '',
			ok: form.getAttribute('data-confirm-ok') || 'Да',
			danger: form.getAttribute('data-confirm-danger') === 'Y',
			onOk: function()
			{
				form.setAttribute('data-confirmed', 'Y');
				if (form.requestSubmit) { form.requestSubmit(submitter && submitter.form === form ? submitter : undefined); } else { form.submit(); }
			}
		});
	}, true);
})(typeof window !== 'undefined' ? window : this);

<?php
$MESS['SH_TOOLSAICRM_PROFILES_INTRO'] = 'Профиль говорит агенту, какие сделки, лиды или элементы смарт-процесса проверять (воронка, тип клиента), с каким промптом и что делать по риску. Запись без включённого профиля не анализируется; из нескольких подходящих главнее профиль с типом клиента, затем меньшая сортировка.';
$MESS['SH_TOOLSAICRM_PROFILES_F_TITLE'] = 'Название';
$MESS['SH_TOOLSAICRM_PROFILES_F_IS_ENABLED'] = 'Включён';
$MESS['SH_TOOLSAICRM_PROFILES_F_SORT'] = 'Сортировка';
$MESS['SH_TOOLSAICRM_PROFILES_F_CATEGORY_ID'] = 'Где анализировать';
$MESS['SH_TOOLSAICRM_PROFILES_F_CLIENT_TYPES'] = 'Тип клиента';
$MESS['SH_TOOLSAICRM_PROFILES_F_CLIENT_TYPES_DESCR'] = 'Не отмечено ни одного — любой клиент, в том числе сделки, где тип не определился.';
$MESS['SH_TOOLSAICRM_PROFILES_CLIENT_HELP'] = 'Как считается тип. Берётся компания сделки, нет компании — контакт. Нет ни того ни другого — тип не определён, и сделке подходят только профили без отмеченных типов.
• Вернувшийся покупатель — у клиента есть хотя бы одна успешная сделка.
• Повторное обращение — успешных сделок нет, но есть проваленная.
• Новый — клиент создан меньше часа назад. В CRM с лидами контакт и компания новыми не бывают вовсе, а сделку анализируют через дни после создания — для анализа сделок почти не встречается.
• В работе — все остальные.';
$MESS['SH_TOOLSAICRM_PROFILES_F_IDLE_DAYS'] = 'Порог тишины: анализ — когда дел и звонков не было, дней';
$MESS['SH_TOOLSAICRM_PROFILES_F_IDLE_DAYS_DESCR'] = 'Свежие и активные сделки — работа менеджера: агент их не трогает. Любое новое дело, звонок или письмо откладывает анализ ещё на столько дней. Поэтому сегодняшних и вчерашних сделок в анализе нет — так задумано. По умолчанию 3; 0 — анализировать сразу.';
$MESS['SH_TOOLSAICRM_PROFILES_F_ACTIVE_DAYS'] = 'Только живые сделки: активность не старше, дней';
$MESS['SH_TOOLSAICRM_PROFILES_F_ACTIVE_DAYS_DESCR'] = 'Сделка, где дел и звонков не было дольше, в анализ не идёт и модель не зовёт: «мёртвая» сделка — только расход. Дела, которые ставит сам агент, активностью не считаются. 0 — без ограничения. По умолчанию 60.';
$MESS['SH_TOOLSAICRM_PROFILES_F_REANALYZE_DAYS'] = 'Повторный анализ и повторные дела не чаще, дней';
$MESS['SH_TOOLSAICRM_PROFILES_F_LOW_BORDER'] = 'Нижняя граница риска, 0-100';
$MESS['SH_TOOLSAICRM_PROFILES_F_LOW_BORDER_DESCR'] = 'С неё — дело ответственному, если у сделки нет запланированных дел.';
$MESS['SH_TOOLSAICRM_PROFILES_F_HIGH_BORDER'] = 'Верхняя граница риска, 0-100';
$MESS['SH_TOOLSAICRM_PROFILES_F_HIGH_BORDER_DESCR'] = 'С неё, если AI считает, что нужен старший, — комментарий в таймлайне и дело старшему. Не ниже нижней.';
$MESS['SH_TOOLSAICRM_PROFILES_F_SENIOR_ID'] = 'Старший (ID пользователя)';
$MESS['SH_TOOLSAICRM_PROFILES_F_TOTAL_CONTROL'] = 'Тотальный контроль';
$MESS['SH_TOOLSAICRM_PROFILES_F_NO_CONVERSATION'] = 'Не передавать переписку модели';
$MESS['SH_TOOLSAICRM_PROFILES_F_NO_CONVERSATION_DESCR'] = 'Включено — содержание писем и чатов записи модели не уходит (нет «Переписки», резюме писем, тем и сути в ленте — только факт касания): модель видит звонки, дела и стадии, а работу менеджера оценивает без переписки. По умолчанию выключено — переписка передаётся.';
$MESS['SH_TOOLSAICRM_PROFILES_F_TOTAL_CONTROL_DESCR'] = 'Включён — при риске не ниже HIGH старший получает дело всегда, даже если модель считает его вмешательство бесполезным (в тексте дела будет пометка). Если дел старшему слишком много — выключите тотальный контроль: тогда старшего зовут, только если модель считает, что он поможет.';
$MESS['SH_TOOLSAICRM_PROFILES_F_SENIOR_ID_DESCR'] = 'Пусто — при высоком риске только комментарий в таймлайне.';
$MESS['SH_TOOLSAICRM_PROFILES_F_PROMPT'] = 'Промпт';
$MESS['SH_TOOLSAICRM_PROFILES_F_PROMPT_DESCR'] = 'Свой системный промпт целиком. Пока своего нет, действует общий — он показан текстом; «Изменить» открывает его для правки. Очистите поле и сохраните — профиль вернётся к общему промпту. Форму ответа (JSON) модуль добавляет сам.';
$MESS['SH_TOOLSAICRM_PROFILES_PROMPT_EDIT'] = 'Изменить';
$MESS['SH_TOOLSAICRM_PROFILES_COL_SCALE'] = 'Шкала';
$MESS['SH_TOOLSAICRM_PROFILES_SCALE'] = 'менеджер от #LOW#%, старший от #HIGH#%; зависла через #IDLE# дн.; повтор через #REANALYZE# дн.; живые — до #ACTIVE# дн.';
$MESS['SH_TOOLSAICRM_PROFILES_CLIENT_ANY'] = 'любой';
$MESS['SH_TOOLSAICRM_PROFILES_CLIENT_1'] = 'Новый (для сделок почти не встречается)';
$MESS['SH_TOOLSAICRM_PROFILES_CLIENT_2'] = 'В работе';
$MESS['SH_TOOLSAICRM_PROFILES_CLIENT_3'] = 'Повторное обращение';
$MESS['SH_TOOLSAICRM_PROFILES_CLIENT_4'] = 'Вернувшийся покупатель';
$MESS['SH_TOOLSAICRM_PROFILES_PROMPT_COMMON'] = 'общий';
$MESS['SH_TOOLSAICRM_PROFILES_PROMPT_OWN'] = 'свой';
$MESS['SH_TOOLSAICRM_PROFILES_YES'] = 'да';
$MESS['SH_TOOLSAICRM_PROFILES_NO'] = 'нет';
$MESS['SH_TOOLSAICRM_PROFILES_ADD'] = 'Добавить профиль';
$MESS['SH_TOOLSAICRM_PROFILES_SAVE'] = 'Сохранить';
$MESS['SH_TOOLSAICRM_PROFILES_CANCEL'] = 'Отмена';
$MESS['SH_TOOLSAICRM_PROFILES_DELETE'] = 'Удалить';
$MESS['SH_TOOLSAICRM_PROFILES_DELETE_CONFIRM_TITLE'] = 'Удалить профиль?';
$MESS['SH_TOOLSAICRM_PROFILES_DELETE_CONFIRM'] = 'Сделки его направления не анализируются, если другого профиля нет.';
$MESS['SH_TOOLSAICRM_PROFILES_SAVED'] = 'Профиль сохранён';
$MESS['SH_TOOLSAICRM_PROFILES_DELETED'] = 'Профиль удалён';
$MESS['SH_TOOLSAICRM_PROFILES_ERRORS'] = 'Профиль не сохранён';
$MESS['SH_TOOLSAICRM_PROFILES_F_STAGES'] = 'Стадии';
$MESS['SH_TOOLSAICRM_PROFILES_F_STAGES_DESCR'] = 'Итог «успех» или «провал» — для закрытых записей с любой стадией этого итога. Итог «любой» — стадии из списка (несколько — с Ctrl или Cmd); ничего не выбрано — любая стадия. Подходит самый точный профиль: стадия, потом итог, потом любая стадия.';
$MESS['SH_TOOLSAICRM_PROFILES_OUTCOME_ANY'] = 'Итог любой — стадии из списка';
$MESS['SH_TOOLSAICRM_PROFILES_OUTCOME_S'] = 'Успех — запись закрыта успешно';
$MESS['SH_TOOLSAICRM_PROFILES_OUTCOME_F'] = 'Провал — запись закрыта неуспешно';
$MESS['SH_TOOLSAICRM_PROFILES_ERROR_STAGES'] = 'Стадия не разобралась — выберите из списка';
$MESS['SH_TOOLSAICRM_PROFILES_ERROR_STAGE_SEMANTIC'] = 'Итог не разобрался — выберите из списка';
$MESS['SH_TOOLSAICRM_PROFILES_ERROR_TITLE'] = 'Не задано название';
$MESS['SH_TOOLSAICRM_PROFILES_ERROR_SORT'] = 'Сортировка — целое число';
$MESS['SH_TOOLSAICRM_PROFILES_ERROR_CATEGORY_ID'] = 'Направление не разобралось';
$MESS['SH_TOOLSAICRM_PROFILES_ERROR_IDLE_DAYS'] = 'Дни «зависла» — целое число 0-365';
$MESS['SH_TOOLSAICRM_PROFILES_ERROR_ACTIVE_DAYS'] = 'Дни «живой» сделки — целое число 0-3650';
$MESS['SH_TOOLSAICRM_PROFILES_ERROR_REANALYZE_DAYS'] = 'Дни повтора — целое число 1-365';
$MESS['SH_TOOLSAICRM_PROFILES_ERROR_LOW_BORDER'] = 'Нижняя граница — целое число 0-100';
$MESS['SH_TOOLSAICRM_PROFILES_ERROR_HIGH_BORDER'] = 'Верхняя граница — целое число 0-100, не ниже нижней';
$MESS['SH_TOOLSAICRM_PROFILES_ERROR_SENIOR_ID'] = 'Старший — ID пользователя числом';

$MESS['SH_TOOLSAICRM_PROFILES_ERROR_ENTITY_KEY'] = 'Не выбрано, где анализировать (сделки, лиды или смарт-процесс)';
$MESS['SH_TOOLSAICRM_PROFILES_F_LEAD_CLIENT'] = 'Тип клиента лида';
$MESS['SH_TOOLSAICRM_PROFILES_F_LEAD_CLIENT_DESCR'] = 'Только для профиля лидов. У сделок и смарт-процессов не используется.';
$MESS['SH_TOOLSAICRM_PROFILES_LEAD_NEW'] = 'Новый';
$MESS['SH_TOOLSAICRM_PROFILES_LEAD_REPEAT'] = 'Повторный';
$MESS['SH_TOOLSAICRM_PROFILES_LEAD_LEGAL'] = 'Юрлицо';
$MESS['SH_TOOLSAICRM_PROFILES_LEAD_PERSON'] = 'Физлицо';
$MESS['SH_TOOLSAICRM_PROFILES_LEAD_CLIENT_HELP'] = 'Как считается.
• Новый — к лиду не привязан ни контакт, ни компания. Повторный — привязан хотя бы один.
• Юрлицо — к лиду привязана компания или заполнено поле «Название компании». Иначе — физлицо.
Если подходят несколько профилей лидов, главнее тот, где совпало больше признаков; «любой» по обоим — последний. Галочки «Тип клиента» выше у лида не действуют.';
$MESS['SH_TOOLSAICRM_PROFILES_CHECKS'] = 'Последние проверки агента моделью — #N# последних';
$MESS['SH_TOOLSAICRM_PROFILES_CHECKS_ALL'] = 'Последние проверки агента, с пропусками — #N# последних';
$MESS['SH_TOOLSAICRM_PROFILES_CHECKS_SHOW_ALL'] = 'Показать и пропуски (записи, которые агент не отдавал модели)';
$MESS['SH_TOOLSAICRM_PROFILES_CHECKS_SHOW_MODEL'] = 'Только проверки моделью';
$MESS['SH_TOOLSAICRM_PROFILES_CHECKS_COL_ITEM'] = 'Запись';
$MESS['SH_TOOLSAICRM_PROFILES_CHECKS_COL_TIME'] = 'Время';
$MESS['SH_TOOLSAICRM_PROFILES_CHECKS_COL_RISK'] = 'Риск';
$MESS['SH_TOOLSAICRM_PROFILES_CHECKS_COL_WHY'] = 'Почему';
$MESS['SH_TOOLSAICRM_PROFILES_CHECKS_COL_ACTION'] = 'Что сделано';
$MESS['SH_TOOLSAICRM_PROFILES_CHECKS_ESCALATED_OLD'] = 'старшему: #AT#';
$MESS['SH_TOOLSAICRM_PROFILES_CHECKS_EMPTY'] = 'Агент ещё не проверял записи этого типа.';

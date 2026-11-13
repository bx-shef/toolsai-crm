<?php
$MESS['shef.toolsaicrm_MODULE_NAME'] = '[SH] Tools AI: автооценка CRM';
$MESS['shef.toolsaicrm_MODULE_DESC'] = 'Автоматическая оценка сделок, лидов и смарт-процессов, звонков, писем и чатов: риск потерять запись, что сделать дальше, отчёты по менеджерам. Работает на движке shef.toolsai.';
$MESS['shef.toolsaicrm_PARTNER_NAME'] = 'ООО «БэИксШефБай»';
$MESS['shef.toolsaicrm_PARTNER_URI'] = 'https://bx-shef.by';

$MESS['SH_MOD_BACK'] = 'К списку модулей';
$MESS['SH_MOD_INST_OK'] = 'Модуль успешно установлен';
$MESS['SH_TOOLSAICRM_SETUP_HINT'] = 'Настройки, профили, история оценок и промпты анализа из shef.toolsai переносятся при установке и сверяются; если в отчёте есть строка с ✖, нажмите «Проверить и включить» в разделе настроек модуля ещё раз. Страница модуля — /shef/toolsaicrm/ (CRM → Настройки → «Tools AI: автооценка CRM»).';
$MESS['SH_TOOLSAICRM_PUBLIC_PAGE_FAIL'] = 'не записана: нет прав на запись или там чужой файл — страница или робот БП не откроется';

$MESS['SH_NEED_RIGHT_VER'] = 'Для установки данного решения необходима версия главного модуля #NEED# или выше.';
$MESS['SH_NEED_MODULES'] = 'Для установки данного решения необходимо наличие модуля <a target="_blank" href="https://www.1c-bitrix.ru/products/cms/versions.php?module=#NEED#">#NEED#</a>.';
$MESS['SH_NEED_MODULES_BY_VERSION'] = 'Для установки данного решения необходимо наличие модуля <a target="_blank" href="#URL#">#NEED#</a> версии #VER# и выше. Установлена: #CURRENT#. Обновите модуль и повторите установку.';
$MESS['SH_NOT_INSTALLED'] = 'нет';
$MESS['SH_VERSION_UNKNOWN'] = 'версия не читается';

$MESS['SH_NEED_UTF8'] = 'Модуль поставляется в кодировке UTF-8 и может быть установлен только на проект в UTF-8. Текущий проект работает в другой кодировке.';
$MESS['SH_NEED_PHP_VER'] = 'Для модуля требуется версия PHP выше #NEED#. Ваша версия #CURRENT#';
$MESS['SH_PROBLEM_UNINSTALL_MODULE'] = 'Нельзя удалить модуль:';
$MESS['SH_NEED_UNINSTALL_MODULE_BEFORE'] = '. зависимость в модуле <a target="_blank" href="/bitrix/admin/settings.php?mid=#TARGET#">#TARGET#</a>';

$MESS['SH_TOOLSAICRM_UNINSTALL_WARN'] = 'Внимание: роботы бизнес-процессов «AI: оценить сейчас» в схемах перестанут работать, а агенты автоматической оценки остановятся. Поля «AI: …» в карточках CRM остаются, но без модуля их кнопки отметок не работают, а тип поля остаётся без зарегистрированного обработчика: карточки CRM могут показывать значения полей «AI: …» текстом. Данные оценок, профили и промпты хранятся в таблицах модуля: чтобы не потерять их, оставьте отметку «Сохранить данные».';
$MESS['SH_TOOLSAICRM_UNINSTALL_SAVEDATA'] = 'Сохранить данные (таблицы и настройки модуля)';
$MESS['SH_TOOLSAICRM_UNINSTALL_BUTTON'] = 'Удалить модуль';

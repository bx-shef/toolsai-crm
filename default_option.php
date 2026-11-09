<?php
/**
 * Значения настроек по умолчанию — их отдаёт Option::get(), пока настройку
 * не сохранили.
 *
 * Коды те же, что у анализа были в shef.toolsai (данные переносит установщик, Main\Migration):
 * DEAL_enabled — агенты анализа работают (по умолчанию нет), DEAL_crmevents — запись модуля в CRM
 * запускает роботы, БП и историю (по умолчанию нет), DEAL_maxperrun — анализов за прогон агента,
 * DEAL_interval — интервал агента, минуты. ANALYSIS_interval_<тип> и ANALYSIS_maxperrun_<тип> —
 * свои значения типа; без них действуют общие. CALLSCRIPT_mode — подбор скрипта звонка:
 * core (штатно) или rules (по правилам), CALLSCRIPT_fallback — запасной скрипт.
 */
$shef_toolsaicrm_default_option = [
	'DEAL_enabled' => 'N',
	'DEAL_crmevents' => 'N',
	'DEAL_maxperrun' => '50',
	'DEAL_interval' => '60',
	'CALLSCRIPT_mode' => 'core',
];

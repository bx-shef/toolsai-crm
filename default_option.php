<?php
/**
 * Значения настроек по умолчанию — их отдаёт Option::get(), пока настройку
 * не сохранили.
 *
 * ANNOUNCE_list — свой список объявлений (JSON), правится на странице
 * «Оповещения для сотрудников». Пусто — только объявления модулей из события
 * OnAnnouncementsCollect. ANNOUNCE_testusers — тестовые пользователи:
 * JSON-список выбора сотрудников (по умолчанию — администратор, ID 1);
 * старое «ID через запятую» тоже читается и при показе страницы
 * настроек переводится в JSON (Main\TestUsersOption).
 *
 * Коды те же, что были в shef.toolsai до выноса (данные перенесены до 1.2.0).
 */
$shef_toolsaicrm_default_option = [
	'ANNOUNCE_list' => '',
	'ANNOUNCE_testusers' => '[{"id":1,"title":"#1","entityId":"user","entityType":"employee","link":"","avatar":""}]',
];

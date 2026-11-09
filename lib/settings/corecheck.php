<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Settings;

use Shef\ToolsAiCrm\Main\Constants;

/**
 * Движок на месте: модуль shef.toolsai установлен и не старше нужной версии.
 *
 * Без него страницы не работают — модель, расход, лицензия и права живут там.
 * Вместо ошибки в коде страница показывает внятное сообщение.
 */
final class CoreCheck
{
	/**
	 * Что не так с движком; null — всё в порядке. Чистая функция.
	 *
	 * @param bool $installed shef.toolsai установлен
	 * @param string $version установленная версия ('' — не прочиталась)
	 */
	public static function problem(bool $installed, string $version, string $min = Constants::CORE_MIN_VERSION): ?string
	{
		if(!$installed)
		{
			return 'Для работы нужен модуль '.Constants::CORE_MODULE_ID.' версии '.$min.' или выше: он не установлен. Установите его.';
		}
		if($version === '' || version_compare($version, $min, '<'))
		{
			return 'Модуль '.Constants::CORE_MODULE_ID.($version !== '' ? ' версии '.$version : '').' слишком старый: нужна версия '.$min.' или выше. Обновите его.';
		}

		return null;
	}

	/** Проверка на портале. */
	public static function check(): ?string
	{
		$installed = \Bitrix\Main\ModuleManager::isModuleInstalled(Constants::CORE_MODULE_ID);
		$version = $installed ? (string)\Bitrix\Main\ModuleManager::getVersion(Constants::CORE_MODULE_ID) : '';

		$problem = static::problem($installed, $version);
		if($problem === null && (!\Bitrix\Main\Loader::includeModule(Constants::CORE_MODULE_ID) || !class_exists(\Shef\ToolsAi\Public\Service\AccessService::class)))
		{
			return 'Модуль '.Constants::CORE_MODULE_ID.' не подключился: проверьте его в списке модулей.';
		}

		return $problem;
	}
}

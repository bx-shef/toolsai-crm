<?php declare(strict_types=1);

namespace Shef\ToolsAiCrm\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Shef\ToolsAiCrm\Deal\Field\AckType;
use Shef\ToolsAiCrm\Deal\Field\AckValue;
use Shef\ToolsAiCrm\Analysis\EntityFields;
use Shef\ToolsAiCrm\Analysis\EntityType;
use Shef\ToolsAiCrm\Analysis\EntityTypes;

/**
 * Кнопки «Риск под контролем» / «Отработано» в карточке сделки (AckType).
 *
 *   BX.ajax.runAction('shef:toolsaicrm.dealflag.mark', {data: {dealId, role}})
 *
 * Только POST, авторизованный пользователь, sessid (ActionFilter\Csrf).
 * Права проверяются заново по базе: адресат — ответственный (manager) или
 * старший профиля сделки (senior); администратор портала — любая отметка,
 * записывается его ID с пометкой «за адресата» (AckValue::markValue). Запись — Analysis\EntityFields::write() → CrmWrite
 * (роботы и БП — по DEAL_crmevents).
 *
 * С 1.9.0 — и лиды, и смарт-процессы: entityTypeId (по умолчанию сделка —
 * прежние кнопки 1.8.x шлют без него). Запись — Analysis\EntityFields::write().
 */
class DealFlag extends Controller
{
	public function configureActions(): array
	{
		return [
			'mark' => [
				'prefilters' => [
					new ActionFilter\Authentication(),
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\Csrf(),
				],
			],
		];
	}

	public function markAction(int $dealId, string $role, int $entityTypeId = EntityType::DEAL, string $reason = ''): ?array
	{
		$role = AckValue::role($role);
		$userId = (int)$this->getCurrentUser()?->getId();
		$type = EntityTypes::get($entityTypeId);
		if($role === null || $dealId <= 0 || $type === null)
		{
			$this->addError(new Error('Неверный запрос'));

			return null;
		}

		[$assigned, $senior] = AckType::getAddressees($dealId, $entityTypeId);
		$isAdmin = (bool)$this->getCurrentUser()?->isAdmin();
		$value = AckValue::markValue($role, $userId, $assigned, $senior, $isAdmin, time());
		if($value === null)
		{
			$this->addError(new Error('Отметку ставит только '.($role === AckValue::ROLE_SENIOR ? 'старший профиля анализа' : 'ответственный')));

			return null;
		}

		// Старший снимает риск только с пояснением (1.46.0): оно — комментарием в ленту записи
		$reasonError = AckValue::reasonError($role, $reason);
		if($reasonError !== null)
		{
			$this->addError(new Error($reasonError));

			return null;
		}
		$byAdmin = (bool)(AckValue::parse($value)['byAdmin'] ?? false);
		if($role === AckValue::ROLE_SENIOR && !static::addComment($entityTypeId, $dealId, $userId, AckValue::reasonComment(AckType::getUserName($userId), $reason, $byAdmin)))
		{
			$this->addError(new Error('Не удалось записать пояснение в ленту — отметка не поставлена'));

			return null;
		}

		$code = EntityFields::ackCode($role);
		if(!EntityFields::write($type, $dealId, [$code => $value, EntityFields::DONE_FLAGS[$code] => EntityFields::doneFlag($value)], null, \Shef\ToolsAiCrm\Analysis\CrmWrite::SOURCE_FLAG))
		{
			$this->addError(new Error('Не удалось записать отметку'));

			return null;
		}

		$mark = AckValue::parse($value);
		$at = (int)($mark['at'] ?? time());

		return ['html' => AckType::buildHtml($role, $dealId, AckValue::VIEW_DONE, AckType::getUserName($userId), $at, (bool)($mark['byAdmin'] ?? false), $entityTypeId)];
	}

	private static function addComment(int $entityTypeId, int $id, int $authorId, string $text): bool
	{
		if(!class_exists(\Bitrix\Crm\Timeline\CommentEntry::class))
		{
			return false;
		}
		try
		{
			return (int)\Bitrix\Crm\Timeline\CommentEntry::create([
				'TEXT' => $text,
				'AUTHOR_ID' => $authorId,
				'BINDINGS' => [['ENTITY_TYPE_ID' => $entityTypeId, 'ENTITY_ID' => $id]],
			]) > 0;
		}
		catch(\Throwable)
		{
			return false;
		}
	}
}

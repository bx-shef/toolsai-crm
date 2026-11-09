<?php
/**
 * Робот «AI: оценить сейчас» (1.29.0, #73): вся работа — Shef\ToolsAiCrm\Robot\AnalyzeNow.
 * Заглушка в /bitrix/activities/custom/shtoolsaianalyzeactivity/ ведёт сюда (Main\PublicPage).
 */
if(!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPShToolsAiAnalyzeActivity extends CBPActivity
{
	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			'Risk' => null,
			'Why' => null,
			'NextStep' => null,
			'Status' => null,
		];
		$this->SetPropertiesTypes([
			'Risk' => ['Type' => 'int'],
			'Why' => ['Type' => 'text'],
			'NextStep' => ['Type' => 'text'],
			'Status' => ['Type' => 'string'],
		]);
	}

	public function Execute()
	{
		if(!\Bitrix\Main\Loader::includeModule('crm') || !\Bitrix\Main\Loader::includeModule('shef.toolsaicrm'))
		{
			$this->WriteToTrackingService('Модуль shef.toolsaicrm не подключён.');

			return CBPActivityExecutionStatus::Closed;
		}

		try
		{
			[$entityTypeId, $id] = \CCrmBizProcHelper::resolveEntityId($this->GetDocumentId());
			$result = \Shef\ToolsAiCrm\Robot\AnalyzeNow::run((int)$entityTypeId, (int)$id);
		}
		catch(\Throwable $throwable)
		{
			\Shef\ToolsAiCrm\Container::getLogger(static::class)?->error($throwable);
			$result = ['status' => 'error', 'risk' => null, 'why' => '', 'nextStep' => '', 'message' => 'Ошибка: '.$throwable->getMessage()];
		}

		$this->Risk = $result['risk'];
		$this->Why = $result['why'];
		$this->NextStep = $result['nextStep'];
		$this->Status = $result['status'];
		$this->WriteToTrackingService('AI: '.$result['message']);

		return CBPActivityExecutionStatus::Closed;
	}

	public static function GetPropertiesDialog($documentType, $activityName, $arWorkflowTemplate, $arWorkflowParameters, $arWorkflowVariables, $arCurrentValues = null, $formName = '', $popupWindow = null, $siteId = '')
	{
		$dialog = new \Bitrix\Bizproc\Activity\PropertiesDialog(__FILE__, [
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $arWorkflowTemplate,
			'workflowParameters' => $arWorkflowParameters,
			'workflowVariables' => $arWorkflowVariables,
			'currentValues' => $arCurrentValues,
			'formName' => $formName,
			'siteId' => $siteId,
		]);
		$dialog->setMap([]);

		return $dialog;
	}

	public static function GetPropertiesDialogValues($documentType, $activityName, &$arWorkflowTemplate, &$arWorkflowParameters, &$arWorkflowVariables, $arCurrentValues, &$errors)
	{
		$errors = [];
		$activity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
		$activity['Properties'] = ['Title' => $activity['Properties']['Title'] ?? ''];

		return true;
	}
}

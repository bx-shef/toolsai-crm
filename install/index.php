<?php

use Bitrix\Main\Application;
use Bitrix\Main\Config;
use Bitrix\Main\Error;
use Bitrix\Main\EventManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Result;

if(class_exists('shef_toolsaicrm'))
{
	return;
}

Loc::loadMessages(__FILE__);

/**
 * Установка shef.toolsaicrm — «Tools AI: автооценка CRM» (вынесена из shef.toolsai, #639).
 *
 * Канон — установщики shef.toolsai и shef.toolsaiannounce (проверки UTF-8, PHP, версий;
 * savedata; каталог модуля от самого установщика). Что делает:
 *
 *   1) проверки до любых изменений: UTF-8, PHP, главный модуль, shef.options, shef.problems,
 *      движок shef.toolsai не ниже 1.96.0 (публичный API), модули crm и bizproc;
 *   2) таблицы модуля (init() у каждой);
 *   3) постоянные подписки из installEvents (.settings.php): тип поля «AI: …», защита полей,
 *      подбор скрипта звонка; снимает парный UnInstallEvents();
 *   4) заглушки пишутся, а не копируются, удаляются только свои (Main\PublicPage): страница
 *      /shef/toolsaicrm/, компонент, робот БП «AI: оценить сейчас» (заменяет заглушку,
 *      которую до выноса писал shef.toolsai);
 *   5) Main\Setup::prepare(): перенос данных из shef.toolsai (остановить старые агенты и
 *      подписки, копия, сверка — Main\Migration), агенты анализа по включённым профилям,
 *      агент доведения. Перенос мягкий: не сошёлся — установка не падает, отчёт говорит почему.
 *
 * Поля «AI: …» (UF_SHEF_AI_*) и их тип в базе установка не создаёт и не трогает: они остались от
 * shef.toolsai и работают как были. Удаление их тоже не трогает.
 *
 * На классы модуля в установщике автозагрузка не рассчитана: всё, что нужно до регистрации
 * модуля, подключается явным require_once.
 */
class shef_toolsaicrm
	extends CModule
{
	public $MODULE_ID = 'shef.toolsaicrm';
	public $MODULE_VERSION;
	public $MODULE_VERSION_DATE;
	public $MODULE_NAME;
	public $MODULE_DESCRIPTION;
	public $MODULE_SORT;
	public $MODULE_GROUP_RIGHTS = 'N';

	public $PARTNER_NAME;
	public $PARTNER_URI;

	/** @var string */
	public $PHP_MIN_VER = '8.2.0';
	/** @var string */
	public $NEED_MAIN_VERSION = '22.600.300';
	/**
	 * crm — сделки, лиды, звонки; bizproc — робот «AI: оценить сейчас». В requireModules их нет:
	 * autoload.php поднимал бы их на каждой загрузке модуля.
	 *
	 * @var string[]
	 */
	public $NEED_MODULES = [
		'crm',
		'bizproc',
	];
	/**
	 * shef.options 3.0.0 — страница настроек на ShOptionsConfig без indexDoc; shef.problems 2.0.0 —
	 * логгер проблем; shef.toolsai 1.96.0 — публичный API движка (LlmService, AccessService):
	 * Main\Constants::CORE_MIN_VERSION, повторено литералом (держит tests/install_test.php).
	 *
	 * @var array<string, string>
	 */
	public $NEED_MODULES_BY_VERSION = [
		'shef.options' => '3.0.0',
		'shef.problems' => '2.0.0',
		'shef.toolsai' => '1.96.0',
	];

	/**
	 * Агент доведения (Main\Maintenance::AGENT_NAME, AGENT_INTERVAL) литералами: установщик
	 * классам модуля не доверяет.
	 */
	private const AGENT_NAME = '\\Shef\\ToolsAiCrm\\Agent\\Maintenance::run();';
	private const AGENT_INTERVAL = 600;

	/** Префикс групп блокировок агентов анализа: Main\Constants::LOCK_GROUP_ANALYSIS_PREFIX. */
	private const LOCK_GROUP_ANALYSIS = 'shef.toolsaicrm.analysis.';

	/** @var \CMain */
	private $application;

	/** Отчёт Main\Setup — показывается в форме после установки. */
	private array $setupReport = [];

	/** Версия, название, партнёр — из version.php и языкового файла. */
	public function __construct()
	{
		$arModuleVersion = [];

		require __DIR__.'/version.php';

		$this->MODULE_VERSION = $arModuleVersion['VERSION'];
		$this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];

		$this->MODULE_NAME = Loc::getMessage('shef.toolsaicrm_MODULE_NAME');
		$this->MODULE_DESCRIPTION = Loc::getMessage('shef.toolsaicrm_MODULE_DESC');

		$sort = 'MODULE_SORT';
		$this->{$sort} = (int)'1006';

		$this->PARTNER_NAME = Loc::getMessage('shef.toolsaicrm_PARTNER_NAME');
		$this->PARTNER_URI = Loc::getMessage('shef.toolsaicrm_PARTNER_URI');

		$this->application = $GLOBALS['APPLICATION'] ?? null;
	}

	// region DB && Register Module ////
	/**
	 * Регистрация модуля и таблицы.
	 *
	 * @memo Need call first
	 */
	public function InstallDB(array $arParams = []): bool
	{
		RegisterModule($this->MODULE_ID);

		if(\Bitrix\Main\Loader::includeModule($this->MODULE_ID))
		{
			// Таблицы заводит Main\Setup (список — Setup::TABLES); сбой таблиц — в отчёт, не в падение
			try
			{
				$result = (new \Shef\ToolsAiCrm\Main\Setup(new \Shef\ToolsAiCrm\Config()))->ensureTables();
				if(!$result['ok'])
				{
					$this->setupReport['tables'] = $result;
				}
			}
			catch(\Throwable $throwable)
			{
				$this->setupReport['tables'] = ['ok' => false, 'message' => $throwable->getMessage()];
			}
		}

		return true;
	}

	/**
	 * Снятие регистрации.
	 *
	 * @param array $arParams ключ savedata = 'Y' оставляет настройки и таблицы модуля: в них
	 *                        история оценок, профили и промпты — после выноса анализа других
	 *                        копий этих данных нет
	 * @memo Need call last
	 */
	public function UnInstallDB(array $arParams = []): bool
	{
		$isSaveData = ($arParams['savedata'] ?? 'N') === 'Y';

		if(!$isSaveData)
		{
			try
			{
				if(\Bitrix\Main\Loader::includeModule($this->MODULE_ID))
				{
					foreach(\Shef\ToolsAiCrm\Main\Setup::TABLES as $table)
					{
						$table::drop();
					}
				}
			}
			catch(\Throwable $throwable)
			{
			}
			// Настройки уходят вместе с таблицами. Поля «AI: …» и тип поля не трогаем.
			Config\Option::delete($this->MODULE_ID);
		}

		UnRegisterModule($this->MODULE_ID);

		$GLOBALS['CACHE_MANAGER']->CleanAll();

		return true;
	}
	// endregion ////

	// region Events ////
	/**
	 * Постоянные подписки из installEvents (.settings.php).
	 *
	 * @return list<array>
	 */
	private function getEventsList(): array
	{
		$list = Config\Configuration::getInstance($this->MODULE_ID)->get('installEvents');

		return is_array($list) ? $list : [];
	}

	/** Зарегистрировать подписки и агент доведения; повторная установка не плодит дублей. */
	public function InstallEvents(): bool
	{
		$eventManager = EventManager::getInstance();
		foreach($this->getEventsList() as $event)
		{
			$eventManager->unRegisterEventHandler(
				$event['from']['module'],
				$event['from']['event'],
				$event['to']['module'],
				$event['to']['class'],
				$event['to']['function'],
			);
			$method = ($event['isCompatible'] ?? false) ? 'registerEventHandlerCompatible' : 'registerEventHandler';
			$eventManager->{$method}(
				$event['from']['module'],
				$event['from']['event'],
				$event['to']['module'],
				$event['to']['class'],
				$event['to']['function'],
				$event['to']['sort'] ?? 100,
			);
		}

		// Агент доведения, литералом — как Main\Maintenance::AGENT_NAME (держит тест)
		\CAgent::RemoveAgent(self::AGENT_NAME, $this->MODULE_ID);
		\CAgent::AddAgent(self::AGENT_NAME, $this->MODULE_ID, 'N', self::AGENT_INTERVAL);

		return true;
	}

	/**
	 * Снять подписки — те же, что ставит InstallEvents(): при удалении список ещё читается
	 * (модуль зарегистрирован, UnInstallDB() — последним). Класс — с ведущим «\» и без: ядро
	 * сравнивает строку как есть.
	 */
	public function UnInstallEvents(): bool
	{
		$eventManager = EventManager::getInstance();
		foreach($this->getEventsList() as $event)
		{
			foreach(array_unique([$event['to']['class'], ltrim($event['to']['class'], '\\'), '\\'.ltrim($event['to']['class'], '\\')]) as $class)
			{
				$eventManager->unRegisterEventHandler(
					$event['from']['module'],
					$event['from']['event'],
					$event['to']['module'],
					$class,
					$event['to']['function'],
				);
			}
		}

		return true;
	}
	// endregion ////

	// region Files ////
	/** Каталог модуля — там, где он стоит на самом деле: /bitrix/modules или /local/modules. */
	private function getModuleDir(): string
	{
		return dirname(__DIR__);
	}

	/** Класс заглушек — подключается явно: на автозагрузку классов модуля в установщике полагаться нельзя. */
	private function getPublicPage(): string
	{
		require_once $this->getModuleDir().'/lib/main/constants.php';
		require_once $this->getModuleDir().'/lib/main/publicpage.php';

		return \Shef\ToolsAiCrm\Main\PublicPage::class;
	}

	/** Заглушки страницы, компонента и робота БП. Чужой файл не трогает. */
	public function InstallFiles(array $arParams = []): bool
	{
		$page = $this->getPublicPage();
		foreach($page::installAll((string)Application::getDocumentRoot(), $this->getModuleDir()) as $file => $ok)
		{
			if(!$ok)
			{
				$this->setupReport['file '.$file] = ['ok' => false, 'message' => (string)Loc::getMessage('SH_TOOLSAICRM_PUBLIC_PAGE_FAIL')];
			}
		}

		return true;
	}

	/** Убрать свои заглушки; модуль, зависящий от этого, удалить не даст. */
	public function UnInstallFiles(): bool
	{
		$response = $this->checkChildModules();
		if(!$response->isSuccess())
		{
			$this->application->ThrowException(
				join(PHP_EOL.'<br>',
					array_merge(
						[Loc::getMessage('SH_PROBLEM_UNINSTALL_MODULE')],
						$response->getErrorMessages()
					)
				)
			);

			return false;
		}

		$page = $this->getPublicPage();
		$page::uninstallAll((string)Application::getDocumentRoot(), $this->getModuleDir());

		return true;
	}
	// endregion ////

	// region Install.Public ////
	/** Проверки окружения — до любых изменений; затем регистрация, таблицы, подписки, файлы, перенос. */
	public function DoInstall(): void
	{
		$error = $this->requirementError();
		if($error !== null)
		{
			$this->ShowForm('ERROR', $error);

			return;
		}

		$this->InstallDB();
		$this->InstallEvents();
		$this->InstallFiles();
		$this->InstallEngine();
		$this->installModuleData();

		$this->ShowForm(
			'OK',
			Loc::getMessage('SH_MOD_INST_OK').$this->renderSetupReport()
		);
	}

	/**
	 * Подготовка (Main\Setup::prepare()): перенос данных из shef.toolsai, агенты анализа,
	 * агент доведения. Мягко: сбой — строка отчёта, установка идёт дальше.
	 */
	public function InstallEngine(): bool
	{
		try
		{
			\Bitrix\Main\Loader::includeModule($this->MODULE_ID);

			$setup = new \Shef\ToolsAiCrm\Main\Setup(new \Shef\ToolsAiCrm\Config());
			$this->setupReport = array_merge($this->setupReport, $setup->prepare(
				(string)Application::getDocumentRoot(),
				$this->getModuleDir()
			));
		}
		catch(\Throwable $throwable)
		{
			$this->setupReport['setup'] = ['ok' => false, 'message' => $throwable->getMessage()];
		}

		return true;
	}

	/**
	 * Повторяемые данные установки (зовут DoInstall() и обновление — shef.options, команда
	 * shef:modules:sync): импорт справки в «Базу знаний» (shef.toolsaikb). Мягко: нет
	 * shef.toolsaikb, занято или ошибка — установка идёт дальше.
	 */
	public function installModuleData(): void
	{
		try
		{
			if(\Bitrix\Main\Loader::includeModule($this->MODULE_ID))
			{
				\Shef\ToolsAiCrm\Internals\Integration\Shef\ToolsAiKb\KbImport::run();
			}
		}
		catch(\Throwable)
		{
		}
	}

	/**
	 * Чего не хватает порталу для установки: текст отказа или null.
	 *
	 * Модуль поставляется в UTF-8 и перекодировкой не занимается: на проекте в CP1251 весь русский
	 * текст превратился бы в мусор молча. Публичный — для тестов (tests/install_test.php).
	 */
	public function requirementError(): ?string
	{
		if(!Application::isUtfMode())
		{
			return (string)Loc::getMessage('SH_NEED_UTF8');
		}

		$phpVer = phpversion();
		if(version_compare($phpVer, $this->PHP_MIN_VER, '<'))
		{
			return (string)Loc::getMessage('SH_NEED_PHP_VER', [
				'#CURRENT#' => $phpVer,
				'#NEED#' => $this->PHP_MIN_VER,
			]);
		}

		foreach($this->NEED_MODULES as $module)
		{
			if(!ModuleManager::isModuleInstalled($module))
			{
				return (string)Loc::getMessage('SH_NEED_MODULES', [
					'#NEED#' => $module,
				]);
			}
		}

		foreach($this->NEED_MODULES_BY_VERSION as $module => $ver)
		{
			$installed = ModuleManager::isModuleInstalled($module);
			$current = $installed ? (string)ModuleManager::getVersion($module) : '';
			if($current === '' || version_compare($current, $ver) < 0)
			{
				return (string)Loc::getMessage('SH_NEED_MODULES_BY_VERSION', [
					'#URL#' => 'https://github.com/bx-shef/'.substr($module, strlen('shef.')),
					'#NEED#' => $module,
					'#VER#' => $ver,
					'#CURRENT#' => htmlspecialcharsbx($current !== '' ? $current : (string)Loc::getMessage($installed ? 'SH_VERSION_UNKNOWN' : 'SH_NOT_INSTALLED')),
				]);
			}
		}

		if(
			mb_strlen($this->NEED_MAIN_VERSION) > 0
			&& version_compare(SM_VERSION, $this->NEED_MAIN_VERSION) < 0
		)
		{
			return (string)Loc::getMessage('SH_NEED_RIGHT_VER', [
				'#NEED#' => $this->NEED_MAIN_VERSION,
			]);
		}

		return null;
	}

	/**
	 * Удаление: первый заход — форма с предупреждениями и вопросом «сохранить данные»
	 * (по умолчанию да), второй (step=2) — файлы с проверкой зависимых модулей, подписки,
	 * агенты и блокировки, регистрация — последней.
	 */
	public function DoUninstall(): void
	{
		$request = Application::getInstance()->getContext()->getRequest();
		if((int)$request->get('step') !== 2)
		{
			$this->ShowUninstallForm();

			return;
		}

		if(!$this->UnInstallFiles())
		{
			$problem = $this->application->GetException();
			$this->ShowForm(
				'ERROR',
				$problem instanceof \CApplicationException ? $problem->GetString() : 'Error Uninstall'
			);

			return;
		}
		$this->UnInstallEvents();
		// агент доведения и агенты анализа — до снятия регистрации
		\CAgent::RemoveModuleAgents($this->MODULE_ID);
		$this->removeLocks();
		$this->UnInstallDB(['savedata' => (string)$request->get('savedata') === 'Y' ? 'Y' : 'N']);
	}

	/** Блокировки агентов анализа — литералами: при удалении автозагрузка модуля уже не работает. */
	private function removeLocks(): void
	{
		try
		{
			if(!\Bitrix\Main\Loader::includeModule('shef.options'))
			{
				return;
			}
			foreach(glob(dirname(\Shef\Options\Main\TempFile\Pid::getBasePath(self::LOCK_GROUP_ANALYSIS.'0')).'/'.self::LOCK_GROUP_ANALYSIS.'*', GLOB_ONLYDIR) ?: [] as $dir)
			{
				$group = basename($dir);
				\Shef\Options\Main\TempFile\Pid::removeByGroup($group, 0);
				\Bitrix\Main\IO\Directory::deleteDirectory(\Shef\Options\Main\TempFile\Pid::getBasePath($group));
			}
		}
		catch(\Throwable $throwable)
		{
		}
	}

	private function renderSetupReport(): string
	{
		if($this->setupReport === [])
		{
			return '';
		}

		$rows = [];
		foreach($this->setupReport as $step => $row)
		{
			$rows[] = sprintf(
				'%s %s — %s',
				$row['ok'] ? '✔' : '✖',
				htmlspecialcharsbx((string)$step),
				htmlspecialcharsbx((string)$row['message'])
			);
		}

		return '<br><br>'.implode('<br>', $rows).'<br><br>'.Loc::getMessage('SH_TOOLSAICRM_SETUP_HINT');
	}

	/**
	 * Административная страница установщика: пролог, содержимое, эпилог — в одной области.
	 * Файлы ядра берут $USER, $APPLICATION, $adminPage… из области, куда их подключили
	 * (epilog_admin_before.php: `$USER->IsAuthorized()`), поэтому все глобальные объявлены здесь,
	 * как в CMain::IncludeAdminFile(). Пролог и эпилог подключаются только этим методом
	 * (держит tests/install_test.php). Заканчивается die(): прервать установку можно только так.
	 */
	private function showAdminPage(\Closure $content): void
	{
		foreach(array_keys($GLOBALS) as $key)
		{
			if(!in_array($key, ['GLOBALS', 'this', 'key', 'content'], true))
			{
				global ${$key};
			}
		}

		$this->application->SetTitle(Loc::getMessage('shef.toolsaicrm_MODULE_NAME'));

		include(Application::getDocumentRoot().'/bitrix/modules/main/include/prolog_admin_after.php');
		$content();
		include(Application::getDocumentRoot().'/bitrix/modules/main/include/epilog_admin.php');
		die();
	}

	/** Форма с итогом. Заканчивается die(): прервать установку можно только так. */
	private function ShowForm(string $type, string $message, string $buttonName = ''): void
	{
		$this->showAdminPage(function() use ($type, $message, $buttonName): void {
			\CAdminMessage::ShowMessage([
				'MESSAGE' => $message,
				'TYPE' => $type,
				'HTML' => true,
			]);
			?>
			<form action="<?=$this->application->GetCurPage()?>" method="get">
				<p>
					<input type="hidden" name="lang" value="<?=LANG?>">
					<input type="submit" value="<?=($buttonName <> '' ? $buttonName : Loc::getMessage('SH_MOD_BACK'))?>">
				</p>
			</form>
			<?php
		});
	}

	/**
	 * Форма удаления: что останется и что сломается (роботы БП), «сохранить данные» — отмечено.
	 * Без отметки таблицы и настройки модуля удаляются: других копий истории оценок, профилей и
	 * промптов нет.
	 */
	private function ShowUninstallForm(): void
	{
		$this->showAdminPage(function(): void {
			\CAdminMessage::ShowMessage([
				'MESSAGE' => Loc::getMessage('SH_TOOLSAICRM_UNINSTALL_WARN'),
				'TYPE' => 'ERROR',
				'HTML' => true,
			]);
			?>
			<form action="<?=$this->application->GetCurPage()?>" method="get">
				<?=bitrix_sessid_post()?>
				<input type="hidden" name="lang" value="<?=LANG?>">
				<input type="hidden" name="id" value="<?=htmlspecialcharsbx($this->MODULE_ID)?>">
				<input type="hidden" name="uninstall" value="Y">
				<input type="hidden" name="step" value="2">
				<p>
					<label>
						<input type="checkbox" name="savedata" value="Y" checked>
						<?=Loc::getMessage('SH_TOOLSAICRM_UNINSTALL_SAVEDATA')?>
					</label>
				</p>
				<p>
					<input type="submit" value="<?=Loc::getMessage('SH_TOOLSAICRM_UNINSTALL_BUTTON')?>">
				</p>
			</form>
			<?php
		});
	}
	// endregion ////

	// region Tools ////
	/** Установленные модули, у которых этот — в requireModules. */
	private function checkChildModules(): Result
	{
		$result = new Result();
		foreach(ModuleManager::getInstalledModules() as $moduleId => $moduleDesc)
		{
			if($this->MODULE_ID === $moduleId)
			{
				continue;
			}

			$requireModules = Config\Configuration::getInstance($moduleId)->get('requireModules');
			if(empty($requireModules) || !is_array($requireModules))
			{
				continue;
			}

			if(in_array($this->MODULE_ID, $requireModules, true))
			{
				$result->addError(new Error(Loc::getMessage('SH_NEED_UNINSTALL_MODULE_BEFORE', [
					'#TARGET#' => $moduleId,
				])));
			}
		}

		return $result;
	}
	// endregion ////
}

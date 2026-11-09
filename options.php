<?php declare(strict_types=1);

/**
 * @global string $Update
 * @global string $mid
 */

use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Config;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Main\UI\Extension;
use Shef\Options\Main\Options;
use Shef\Options\Main\Utils;
use Shef\Options\Ui\SettingsForm;

Loc::loadMessages(Application::getDocumentRoot().'/bitrix/modules/main/options.php');
Loc::loadMessages(__FILE__);

try {
    // region Include Module shef.options ////
    if (!Loader::includeModule('shef.options')) {
        CAdminMessage::ShowMessage('Need install module shef.options');
        return;
    }

    Loc::loadMessages(Application::getDocumentRoot().'/bitrix/modules/shef.options/optionsconfig.php');
    // endregion ////

    // region include class ShOptionsConfig ////
    $configClass = Application::getDocumentRoot().'/bitrix/modules/shef.options/optionsconfig.php';
    if (!is_file($configClass)) {
        CAdminMessage::ShowMessage('Not find file bitrix/modules/shef.options/optionsconfig.php');
        return;
    }

    require_once $configClass;
    // endregion ////

    // region Right ////
    $modulePerms = Utils::getCMainApplication()->GetGroupRight($mid);
    if ($modulePerms < 'R') {
        CAdminMessage::ShowMessage('Wrong right');
        return;
    }
    // endregion ////

    // region Options ////
    $tabs = require __DIR__.'/options_conf.php';
    if ($tabs instanceof Result) {
        if (!$tabs->isSuccess()) {
            \CAdminMessage::ShowMessage([
                'HTML' => true,
                'TYPE' => 'ERROR',
                'MESSAGE' => Loc::getMessage('shef_options_fail', [
                    '#MODULE#' => $mid
                ]),
                'DETAILS' => '<ul><li>'.join(
                    '</li><li>',
                    $tabs->getErrorMessages()
                ).'</li></ul>',
            ]);
        }

        return;
    } elseif (!is_array($tabs)) {
        CAdminMessage::ShowMessage('Wrong tabs at options_conf.php');
        return;
    }
    // endregion ////

    // region Test Shareware ////
    $response = Loader::includeSharewareModule($mid);
    if ($response === Loader::MODULE_DEMO) {
        \CAdminMessage::ShowMessage([
            'HTML' => true,
            'TYPE' => 'OK',
            'MESSAGE' => Loc::getMessage('shef_trial', [
                '#MODULE#' => $mid
            ]),
            'DETAILS' => Loc::getMessage('shef_trial_action', [
                '#MODULE#' => $mid
            ]),
        ]);
    }
    // endregion ////

    $tabControl = new CAdminTabControl('tabControl', Options\Tab::prepareListForBitrixAdminArray($tabs));

    // region Operations ////
    $request = Context::getCurrent()?->getRequest();
    if (!$request instanceof Request) {
        CAdminMessage::ShowMessage('Wrong request instance');
        return;
    }

    if (
        $request->isPost() && $Update <> '' && check_bitrix_sessid()
    ) {
        if ($modulePerms < 'W') {
            CAdminMessage::ShowMessage('Wrong right for save');
        } else {
            if (class_exists(SettingsForm::class) && (string)$request->getPost(SettingsForm::RENDERED) === 'Y') {
                // Карточки (3.6.0): только пришедшие поля — форма без JS ничего не затирает
                $cardTabs = array_filter($tabs, static fn ($tab): bool => $tab instanceof Options\Tab);
                foreach (SettingsForm::collect($cardTabs, $request->getPostList()->toArray()) as $complexCode => $value) {
                    Config\Option::set($mid, $complexCode, $value);
                }
            } else {
                /** @var Options\Tab $tab */
                foreach ($tabs as $tab) {
                    if (!($tab instanceof Options\Tab)) {
                        continue;
                    }

                    /** @var Options\AOption $option */
                    foreach ($tab->getOptionList() as $option) {
                        if (
                            !($option instanceof Options\AOption) || $option instanceof Options\RowInfo
                        ) {
                            continue;
                        }

                        $complexCode = $option->getComplexCode();
                        $value = $request->getPost('param_'.$complexCode);

                        if (
                            $option instanceof Options\Checkbox && (string)$value !== $option->getValueY()
                        ) {
                            $value = $option->getValueN();
                        }

                        if (is_array($value)) {
                            $value = serialize($value);
                        } else {
                            $value = trim((string)$value);
                        }

                        Config\Option::set($mid, $complexCode, $value);
                    }
                }
            }
        }

        if (
            $Update <> '' && (string)$request->get('back_url_settings') <> ''
        ) {
            LocalRedirect($request->get('back_url_settings'));
        } else {
            LocalRedirect(
                Utils::getCMainApplication()->GetCurPage()
                .'?mid='.urlencode((string)$mid)
                .'&lang='.urlencode((string)LANGUAGE_ID)
                .'&back_url_settings='.urlencode((string)$request->get('back_url_settings'))
                .'&'.$tabControl->ActiveTabParam()
            );
        }
    }
    // endregion ////
} catch (Throwable $throwable) {
    CAdminMessage::ShowMessage(implode("\n", [
        'Throwable: '.$throwable->getMessage(),
        'File: '.$throwable->getFile(),
        'Line: '.$throwable->getLine(),
        'Trace: '.print_r(str_replace(Application::getDocumentRoot(), '', $throwable->getTraceAsString()), true)
    ]));
    return;
}
// region Render ////
// Карточки в виде «Настроек BitrixGPT» (3.6.0): если форма умеет все поля вкладок;
// иначе — прежние вкладки CAdminTabControl (поле вроде «Сотрудники» не пропадёт)
$cardTabs = array_filter($tabs, static fn ($tab): bool => $tab instanceof Options\Tab);
// Копия страницы в модуле при старом shef.options (без SettingsForm) — прежние вкладки, без ошибки
if (class_exists(SettingsForm::class) && SettingsForm::supports($cardTabs)) {
    Extension::load(SettingsForm::extensions());
    $sections = SettingsForm::fromTabs(
        $cardTabs,
        static fn (string $code): string => (string)Config\Option::get($mid, $code, '')
    );
    $formAction = Utils::getCMainApplication()->GetCurPage().'?mid='.urlencode((string)$mid).'&lang='.LANGUAGE_ID;
    $readOnly = $modulePerms < 'W' ? ' disabled' : '';
    ?>
<style><?=SettingsForm::style()?>
.shef-opt-buttons { display: flex; justify-content: center; gap: 12px; padding: 16px 0 4px; }
</style>
<form method="POST" action="<?=htmlspecialcharsbx($formAction)?>" name="opt_form" id="shef_opt_form">
    <?=bitrix_sessid_post();?>
    <input type="hidden" name="<?=SettingsForm::RENDERED?>" value="" id="shef_opt_rendered">
    <div id="shef-opt-cards"></div>
    <div class="shef-opt-buttons">
        <a href="<?=htmlspecialcharsbx($formAction)?>" class="ui-btn ui-btn-lg ui-btn-no-caps --air --style-plain"><?=Loc::getMessage('MAIN_RESET')?></a>
        <button type="submit" name="Update" value="Y" class="ui-btn ui-btn-lg ui-btn-no-caps --air --style-filled"<?=$readOnly?>><?=GetMessage('MAIN_SAVE')?></button>
    </div>
</form>
<script><?=SettingsForm::script()?></script>
<script>
BX.ready(function()
{
    window.ShefUiSettingsForm && ShefUiSettingsForm.render(document.getElementById('shef-opt-cards'), <?=json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>) && (document.getElementById('shef_opt_rendered').value = 'Y');
});
</script>
<?php
    return;
}

Extension::load([
    'shef-options-admin',
]);

$tabControl->Begin();
?>
<form
    method="POST"
    action="<?=Utils::getCMainApplication()->GetCurPage();?>?mid=<?=urlencode((string)$mid)?>&lang=<?=LANGUAGE_ID;?>"
    name="opt_form"
>
<?php
    Utils::renderTab(
        $mid,
        $tabControl,
        $tabs
    );
$tabControl->Buttons();?>
        <input
            type="submit"
            name="Update"
            <?=($modulePerms < 'W' ? 'disabled' : '')?>
            value="<?=GetMessage('MAIN_SAVE')?>"
            title="<?=GetMessage('MAIN_OPT_SAVE_TITLE')?>"
            class="adm-btn-save"
        >
        <input
            type="reset"
            name="reset"
            value="<?=Loc::getMessage('MAIN_RESET');?>"
        >
        <?=bitrix_sessid_post();?>
    <?php $tabControl->End();?>
</form>
<?php
// endregion ////

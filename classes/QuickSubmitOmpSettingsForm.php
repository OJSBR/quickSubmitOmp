<?php

/**
 * @file plugins/generic/quickSubmitOmp/classes/QuickSubmitOmpSettingsForm.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class QuickSubmitOmpSettingsForm
 *
 * @brief Settings of the quick submission in a press: what a new contributor gets
 *  (e-mail and country) and the user group of each contributor role.
 */

namespace APP\plugins\generic\quickSubmitOmp\classes;

use APP\plugins\generic\quickSubmitOmp\QuickSubmitOmpPlugin;
use APP\template\TemplateManager;
use PKP\context\Context;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;
use PKP\security\Role;
use PKP\userGroup\UserGroup;
use Sokil\IsoCodes\IsoCodesFactory;

class QuickSubmitOmpSettingsForm extends Form
{
    public function __construct(private QuickSubmitOmpPlugin $plugin, private Context $context)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        $this->addCheck(new FormValidatorCustom(
            $this,
            'defaultEmail',
            'optional',
            'validator.email',
            fn ($email) => $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        ));
        $authorGroupIds = array_keys($this->getAuthorGroups());
        foreach (array_keys(QuickSubmitOmpPlugin::ROLE_GROUPS) as $role) {
            $this->addCheck(new FormValidatorCustom(
                $this,
                'group' . ucfirst($role),
                'optional',
                'plugins.generic.quickSubmitOmp.error.noUserGroup',
                fn ($groupId) => in_array((int) $groupId, $authorGroupIds, true)
            ));
        }
    }

    /**
     * The author groups of the press, by id.
     *
     * @return array<int,string>
     */
    protected function getAuthorGroups(): array
    {
        $groups = [];
        foreach (UserGroup::withContextIds([$this->context->getId()])->withRoleIds([Role::ROLE_ID_AUTHOR])->get() as $group) {
            $groups[(int) $group->id] = (string) $group->getLocalizedData('name');
        }
        return $groups;
    }

    /**
     * Load the settings of the press, with the groups the plugin uses today.
     */
    public function initData()
    {
        $contextId = $this->context->getId();
        $this->setData('defaultEmail', $this->plugin->getSetting($contextId, 'defaultEmail'));
        $this->setData('defaultCountry', $this->plugin->getSetting($contextId, 'defaultCountry'));
        foreach ($this->plugin->getRoleGroups($contextId) as $role => $groupId) {
            $this->setData('group' . ucfirst($role), $groupId);
        }
        parent::initData();
    }

    /**
     * Read the submitted settings.
     */
    public function readInputData()
    {
        $this->readUserVars(array_merge(
            ['defaultEmail', 'defaultCountry'],
            array_map(fn ($role) => 'group' . ucfirst($role), array_keys(QuickSubmitOmpPlugin::ROLE_GROUPS))
        ));
        $this->setData('defaultEmail', trim((string) $this->getData('defaultEmail')));
        parent::readInputData();
    }

    /**
     * Render the form.
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $countries = ['' => ''];
        foreach (app(IsoCodesFactory::class)->getCountries() as $country) {
            $countries[$country->getAlpha2()] = $country->getLocalName();
        }
        asort($countries);
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'countries' => $countries,
            'authorGroups' => $this->getAuthorGroups(),
            'emailInUse' => $this->plugin->getDefaultEmail($this->context),
        ]);
        return parent::fetch($request, $template, $display);
    }

    /**
     * Save the settings of the press.
     */
    public function execute(...$functionArgs)
    {
        $contextId = $this->context->getId();
        $this->plugin->updateSetting($contextId, 'defaultEmail', (string) $this->getData('defaultEmail'), 'string');
        $country = strtoupper((string) $this->getData('defaultCountry'));
        $this->plugin->updateSetting($contextId, 'defaultCountry', preg_match('/^[A-Z]{2}$/', $country) ? $country : '', 'string');
        foreach (array_keys(QuickSubmitOmpPlugin::ROLE_GROUPS) as $role) {
            $this->plugin->updateSetting($contextId, 'group' . ucfirst($role), (int) $this->getData('group' . ucfirst($role)), 'int');
        }
        return parent::execute(...$functionArgs);
    }
}

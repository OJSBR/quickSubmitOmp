<?php

/**
 * @file plugins/generic/quickSubmitOmp/QuickSubmitOmpPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class QuickSubmitOmpPlugin
 *
 * @brief Quick submission of OMP books on one page: the book, its contributors, its
 *  chapters with their PDF and the final formats of the book, published at once with
 *  every file approved and open. In an edited volume the book metadata the press
 *  serves (Google Scholar and Dublin Core tags, OAI, ONIX) names the volume editors
 *  only, and each chapter keeps its authors.
 */

namespace APP\plugins\generic\quickSubmitOmp;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\quickSubmitOmp\classes\EditedVolumeMetadata;
use APP\plugins\generic\quickSubmitOmp\classes\QuickSubmitOmpHandler;
use APP\plugins\generic\quickSubmitOmp\classes\QuickSubmitOmpSettingsForm;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\context\Context;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\userGroup\UserGroup;

class QuickSubmitOmpPlugin extends GenericPlugin
{
    /** The page of the quick submission. */
    public const PAGE = 'quickSubmitOmp';

    /** Roles that may use the quick submission: the handler and the menu use the same list. */
    public const ALLOWED_ROLES = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN];

    /** The contributor roles of the form, and the core user group each one defaults to. */
    public const ROLE_GROUPS = [
        'volumeEditor' => 'default.groups.name.volumeEditor',
        'chapterAuthor' => 'default.groups.name.chapterAuthor',
        'author' => 'default.groups.name.author',
    ];

    /**
     * Register the plugin and its hooks.
     *
     * @param string $category
     * @param string $path
     * @param null|int $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success || Application::isUnderMaintenance()) {
            return $success;
        }

        // The hooks are always registered and each callback checks whether the plugin is
        // enabled in the press of the book (pkp/pkp-lib#11793): the OAI and ONIX exports also
        // run from the command line and from jobs, with no press in the request.
        Hook::add('LoadHandler', $this->callbackLoadHandler(...));
        Hook::add('TemplateManager::setupBackendPage', $this->callbackSetupBackendPage(...));
        Hook::add('TemplateManager::display', $this->callbackBookPage(...));
        Hook::add('Dc11SchemaPublicationFormatAdapter::extractMetadataFromDataObject', $this->callbackOai(...));
        Hook::add('monographonix30xmlfilter::execute', $this->callbackOnix(...));

        return $success;
    }

    /**
     * Name shown in the plugins list.
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.quickSubmitOmp.displayName');
    }

    /**
     * Description shown in the plugins list.
     */
    public function getDescription(): string
    {
        return __('plugins.generic.quickSubmitOmp.description');
    }

    /**
     * Serve the quick submission page.
     *
     * @param string $hookName
     * @param array $args [&$page, &$op, &$sourceFile, &$handler]
     */
    public function callbackLoadHandler($hookName, $args): bool
    {
        [&$page, &$op, &$sourceFile, &$handler] = $args;
        $context = Application::get()->getRequest()->getContext();
        if ($page !== self::PAGE || !$context || !$this->getEnabled($context->getId())) {
            return Hook::CONTINUE;
        }

        $handler = new QuickSubmitOmpHandler($this);
        return Hook::ABORT;
    }

    /**
     * Add the quick submission to the backend menu, for the roles the page authorizes.
     * The hook is called without arguments.
     */
    public function callbackSetupBackendPage($hookName, $args): bool
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user || !$this->getEnabled($context->getId())) {
            return Hook::CONTINUE;
        }

        $templateMgr = TemplateManager::getManager($request);
        $menu = $templateMgr->getState('menu');
        if (!is_array($menu) || !$menu || !$this->userCanAccess($user->getId(), $context->getId())) {
            return Hook::CONTINUE;
        }

        $menu[self::PAGE] = [
            'name' => __('plugins.generic.quickSubmitOmp.menu'),
            'icon' => 'MySubmissions',
            'url' => $this->getPageUrl($request),
            'isCurrent' => $request->getRequestedPage() === self::PAGE,
        ];
        $templateMgr->setState(['menu' => $menu]);

        return Hook::CONTINUE;
    }

    /**
     * On the page of an edited volume, name the volume editors only in the Google Scholar
     * and Dublin Core tags of the book.
     *
     * Called by Hook::call('TemplateManager::display', [$templateMgr, &$template, &$output]).
     */
    public function callbackBookPage($hookName, $args): bool
    {
        $templateMgr = $args[0];
        $template = $args[1];
        if ($template !== 'frontend/pages/book.tpl' || $templateMgr->getTemplateVars('isChapterRequest')) {
            return Hook::CONTINUE;
        }
        $monograph = $templateMgr->getTemplateVars('publishedSubmission');
        $publication = $templateMgr->getTemplateVars('publication');
        if (!$monograph || !$publication || !$this->getEnabled($monograph->getData('contextId'))) {
            return Hook::CONTINUE;
        }
        $editors = EditedVolumeMetadata::volumeEditors($monograph, $publication);
        if (!$editors) {
            return Hook::CONTINUE;
        }

        $people = EditedVolumeMetadata::describe($editors, $publication->getData('locale'));
        $templateMgr->registerFilter(
            'output',
            fn (string $output) => EditedVolumeMetadata::rewriteHeadTags($output, $people),
            'quickSubmitOmpEditedVolume'
        );
        return Hook::CONTINUE;
    }

    /**
     * Name the volume editors only as dc:creator of an edited volume in the OAI records.
     *
     * Called by Hook::call('Dc11SchemaPublicationFormatAdapter::extractMetadataFromDataObject', [&$adapter, $monograph, $press, &$dc11Description]).
     */
    public function callbackOai($hookName, $args): bool
    {
        $monograph = $args[1];
        $description = &$args[3];
        if (!$monograph instanceof Submission || !$this->getEnabled($monograph->getData('contextId'))) {
            return Hook::CONTINUE;
        }
        $publication = $monograph->getCurrentPublication();
        $editors = $publication ? EditedVolumeMetadata::volumeEditors($monograph, $publication) : [];
        if ($editors) {
            EditedVolumeMetadata::rewriteOaiCreators($description, $editors);
        }
        return Hook::CONTINUE;
    }

    /**
     * Keep the volume editors only as contributors of the products of an edited volume in
     * an ONIX export.
     *
     * Called by Hook::call('monographonix30xmlfilter::execute', [&$preliminaryOutput]).
     */
    public function callbackOnix($hookName, $args): bool
    {
        $document = $args[0];
        if ($document instanceof \DOMDocument) {
            EditedVolumeMetadata::rewriteOnixContributors(
                $document,
                fn (int $formatId) => $this->authorsOfFormat($formatId)
            );
        }
        return Hook::CONTINUE;
    }

    /**
     * The authors of the publication a publication format belongs to, each marked as volume
     * editor or not, when the format is of an edited volume of a press where the plugin is
     * enabled; null otherwise.
     *
     * @return null|array<int,bool> In the order of the publication, true for a volume editor.
     */
    protected function authorsOfFormat(int $formatId): ?array
    {
        $format = Application::getRepresentationDAO()->getById($formatId);
        $publication = $format ? Repo::publication()->get($format->getData('publicationId')) : null;
        $monograph = $publication ? Repo::submission()->get($publication->getData('submissionId')) : null;
        if (!$monograph || !$this->getEnabled($monograph->getData('contextId')) || !EditedVolumeMetadata::volumeEditors($monograph, $publication)) {
            return null;
        }
        $authors = [];
        foreach ($publication->getData('authors') as $author) {
            $authors[] = (bool) $author->getIsVolumeEditor();
        }
        return $authors;
    }

    /**
     * Whether the user is a manager of the press or a site administrator.
     */
    public function userCanAccess(int $userId, int $contextId): bool
    {
        foreach (Repo::userGroup()->userUserGroups($userId, $contextId) as $userGroup) {
            if (in_array((int) $userGroup->roleId, self::ALLOWED_ROLES, true)) {
                return true;
            }
        }
        // The site administrator role belongs to no press: all the user's groups are checked.
        foreach (Repo::userGroup()->userUserGroups($userId) as $userGroup) {
            if ((int) $userGroup->roleId === Role::ROLE_ID_SITE_ADMIN) {
                return true;
            }
        }
        return false;
    }

    /**
     * The URL of the quick submission in the current press.
     */
    public function getPageUrl($request): string
    {
        return $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, self::PAGE, 'index');
    }

    /**
     * The e-mail address a new contributor gets: the one set in the plugin, or else the
     * technical support contact of the press, or else its principal contact.
     */
    public function getDefaultEmail(Context $context): string
    {
        foreach ([$this->getSetting($context->getId(), 'defaultEmail'), $context->getData('supportEmail'), $context->getData('contactEmail')] as $email) {
            if (is_string($email) && trim($email) !== '') {
                return trim($email);
            }
        }
        return '';
    }

    /**
     * The country a new contributor gets: the one set in the plugin, or else the press's.
     */
    public function getDefaultCountry(Context $context): string
    {
        return (string) ($this->getSetting($context->getId(), 'defaultCountry') ?: $context->getData('country'));
    }

    /**
     * The user group of each contributor role of the form: the one set in the plugin, or
     * the author group named as in the core, or the first author group of the press.
     *
     * @return array<string,?int> Role => user group id.
     */
    public function getRoleGroups(int $contextId): array
    {
        $authorGroups = UserGroup::withContextIds([$contextId])->withRoleIds([Role::ROLE_ID_AUTHOR])->get();
        $groups = [];
        foreach (self::ROLE_GROUPS as $role => $nameKey) {
            $chosen = (int) $this->getSetting($contextId, 'group' . ucfirst($role));
            $group = $authorGroups->first(fn ($group) => (int) $group->id === $chosen)
                ?? $authorGroups->first(fn ($group) => $group->nameLocaleKey === $nameKey)
                ?? $authorGroups->first();
            $groups[$role] = $group ? (int) $group->id : null;
        }
        return $groups;
    }

    /**
     * Add the settings and the quick submission to the plugin entry in the plugins list.
     */
    public function getActions($request, $actionArgs): array
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$request->getContext() || !$this->getEnabled()) {
            return $actions;
        }
        $router = $request->getRouter();
        array_unshift(
            $actions,
            new LinkAction(
                'settings',
                new AjaxModal(
                    $router->url($request, null, null, 'manage', null, [
                        'verb' => 'settings',
                        'plugin' => $this->getName(),
                        'category' => 'generic',
                    ]),
                    $this->getDisplayName()
                ),
                __('manager.plugins.settings'),
                null
            ),
            new LinkAction(
                'openQuickSubmit',
                new RedirectAction($this->getPageUrl($request)),
                __('plugins.generic.quickSubmitOmp.menu')
            )
        );
        return $actions;
    }

    /**
     * Show and save the settings form.
     */
    public function manage($args, $request): JSONMessage
    {
        // The settings belong to a press; there is nothing to configure site-wide.
        $context = $request->getContext();
        if ($request->getUserVar('verb') !== 'settings' || !$context) {
            return parent::manage($args, $request);
        }

        $form = new QuickSubmitOmpSettingsForm($this, $context);
        if (!$request->getUserVar('save')) {
            $form->initData();
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }
        $form->execute();
        return new JSONMessage(true);
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\quickSubmitOmp\QuickSubmitOmpPlugin', '\QuickSubmitOmpPlugin');
}

<?php

/**
 * @file plugins/generic/quickSubmitOmp/classes/QuickSubmitOmpHandler.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class QuickSubmitOmpHandler
 *
 * @brief The quick submission page and the request that makes the book.
 */

namespace APP\plugins\generic\quickSubmitOmp\classes;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\quickSubmitOmp\QuickSubmitOmpPlugin;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\components\forms\FieldOptions;
use PKP\components\forms\FieldRichTextarea;
use PKP\components\forms\FieldSelect;
use PKP\components\forms\FieldText;
use PKP\components\forms\FieldUpload;
use PKP\components\forms\FieldUploadImage;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\core\PKPString;
use PKP\file\PublicFileManager;
use PKP\file\TemporaryFileManager;
use PKP\security\authorization\ContextAccessPolicy;
use Sokil\IsoCodes\IsoCodesFactory;

class QuickSubmitOmpHandler extends Handler
{
    /** @copydoc PKPHandler::_isBackendPage */
    public $_isBackendPage = true;

    /** @var QuickSubmitOmpPlugin */
    protected $plugin;

    public function __construct(QuickSubmitOmpPlugin $plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;
        $this->addRoleAssignment(QuickSubmitOmpPlugin::ALLOWED_ROLES, ['index', 'save']);
    }

    /**
     * Only managers of the press and site administrators.
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * The quick submission page.
     */
    public function index($args, $request)
    {
        $context = $request->getContext();
        $templateMgr = TemplateManager::getManager($request);
        $this->setupTemplate($request);

        $templateMgr->setState(['quickSubmitOmp' => $this->getPageConfig($request)]);
        $templateMgr->assign([
            'pageComponent' => 'Page',
            'pageTitle' => __('plugins.generic.quickSubmitOmp.pageTitle'),
        ]);

        $assetsUrl = $request->getBaseUrl() . '/' . $this->plugin->getPluginPath();
        $templateMgr->addStyleSheet('quickSubmitOmp', $assetsUrl . '/css/quickSubmitOmp.css', ['contexts' => 'backend']);
        // After the core scripts, so that the component is registered before the page mounts.
        $templateMgr->addJavaScript('quickSubmitOmp', $assetsUrl . '/js/quickSubmitOmp.js', [
            'contexts' => 'backend',
            'priority' => TemplateManager::STYLE_SEQUENCE_LAST,
        ]);

        $templateMgr->display($this->plugin->getTemplateResource('quickSubmit.tpl'));
    }

    /**
     * Make the book from the JSON the page posts, and answer with the errors or with the
     * addresses of the book.
     */
    public function save($args, $request): JSONMessage
    {
        $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$request->isPost() || $token === '' || !hash_equals((string) $request->getSession()->token(), $token)) {
            http_response_code(403);
            return new JSONMessage(false, __('form.csrfInvalid'));
        }

        $context = $request->getContext();
        $user = $request->getUser();
        $raw = json_decode((string) file_get_contents('php://input'), true);
        $data = BookBuilder::normalize(is_array($raw) ? $raw : []);

        $roleGroups = $this->plugin->getRoleGroups($context->getId());
        $defaultEmail = $this->plugin->getDefaultEmail($context);
        $temporaryFileManager = new TemporaryFileManager();
        $errors = BookBuilder::validate(
            $data,
            [
                'locales' => array_keys($context->getSupportedSubmissionLocaleNames()),
                'seriesIds' => array_map('intval', array_column($this->getSeries($context->getId()), 'value')),
                'defaultEmail' => $defaultEmail,
                'roleGroups' => $roleGroups,
            ],
            function (int $id) use ($temporaryFileManager, $user) {
                $file = $temporaryFileManager->getFile($id, $user->getId());
                if (!$file || !is_file($file->getFilePath())) {
                    return null;
                }
                // The type stored at upload, or else the one of the content.
                $type = (string) $file->getFileType() ?: (string) PKPString::mime_content_type($file->getFilePath(), pathinfo((string) $file->getOriginalFileName(), PATHINFO_EXTENSION));
                return ['name' => (string) $file->getOriginalFileName(), 'type' => $type];
            }
        );
        if ($errors) {
            return new JSONMessage(false, ['errors' => $errors]);
        }

        $builder = new BookBuilder($context, $user, $defaultEmail, $this->plugin->getDefaultCountry($context), $roleGroups);
        try {
            $submission = $builder->create($data);
        } catch (BookBuilderException $e) {
            return new JSONMessage(false, ['errors' => ['publish' => array_values(array_map(fn ($error) => implode(' ', (array) $error), $e->errors))]]);
        } catch (\Throwable $e) {
            error_log('quickSubmitOmp: ' . $e);
            return new JSONMessage(false, ['errors' => ['save' => [__('plugins.generic.quickSubmitOmp.error.save')]]]);
        }

        $publication = $submission->getCurrentPublication();
        $dispatcher = $request->getDispatcher();
        return new JSONMessage(true, [
            'submissionId' => $submission->getId(),
            'title' => $publication->getLocalizedFullTitle(),
            'published' => in_array($publication->getData('status'), [Submission::STATUS_PUBLISHED, Submission::STATUS_SCHEDULED], true),
            'bookUrl' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, null, 'catalog', 'book', [$submission->getBestId()]),
            'workflowUrl' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, null, 'dashboard', 'editorial', null, ['workflowSubmissionId' => $submission->getId()]),
        ]);
    }

    /**
     * What the page needs: the choices of the press, its defaults and the texts.
     */
    protected function getPageConfig($request): array
    {
        $context = $request->getContext();
        $dispatcher = $request->getDispatcher();
        $locales = [];
        foreach ($context->getSupportedSubmissionLocaleNames() as $key => $label) {
            $locales[] = ['value' => $key, 'label' => $label];
        }
        $countries = [];
        foreach (app(IsoCodesFactory::class)->getCountries() as $country) {
            $countries[] = ['value' => $country->getAlpha2(), 'label' => $country->getLocalName()];
        }
        usort($countries, fn ($a, $b) => strcmp($a['label'], $b['label']));
        $p = 'plugins.generic.quickSubmitOmp.';

        $temporaryFilesUrl = $dispatcher->url($request, PKPApplication::ROUTE_API, $context->getPath(), 'temporaryFiles');

        return [
            'fields' => $this->getFieldPrototypes($request, $temporaryFilesUrl),
            'saveUrl' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, null, QuickSubmitOmpPlugin::PAGE, 'save'),
            'locales' => $locales,
            'primaryLocale' => $context->getPrimaryLocale(),
            'series' => $this->getSeries($context->getId()),
            'countries' => $countries,
            'defaultEmail' => $this->plugin->getDefaultEmail($context),
            'defaultCountry' => $this->plugin->getDefaultCountry($context),
            'today' => date('Y-m-d'),
            'workTypes' => [
                ['value' => Submission::WORK_TYPE_EDITED_VOLUME, 'label' => __($p . 'workType.editedVolume'), 'roles' => ['volumeEditor', 'chapterAuthor']],
                ['value' => Submission::WORK_TYPE_AUTHORED_WORK, 'label' => __($p . 'workType.authoredWork'), 'roles' => ['author']],
            ],
            'roles' => [
                'volumeEditor' => __($p . 'role.volumeEditor'),
                'chapterAuthor' => __($p . 'role.chapterAuthor'),
                'author' => __($p . 'role.author'),
            ],
            'i18n' => $this->getTexts(),
        ];
    }

    /**
     * One field of each kind the page repeats, configured by the core classes, so that each
     * copy on the page gets the props, texts and upload options the core forms give it.
     *
     * @return array<string,array>
     */
    protected function getFieldPrototypes($request, string $temporaryFilesUrl): array
    {
        $publicFilesUrl = $request->getBaseUrl() . '/' . (new PublicFileManager())->getContextFilesPath($request->getContext()->getId());
        $prototypes = [
            'text' => new FieldText('prototype', ['label' => '-']),
            'select' => new FieldSelect('prototype', ['label' => '-', 'options' => []]),
            'options' => new FieldOptions('prototype', ['label' => '-', 'options' => [], 'value' => []]),
            'richText' => new FieldRichTextarea('prototype', ['label' => '-']),
            'pdf' => new FieldUpload('prototype', ['label' => '-', 'options' => ['url' => $temporaryFilesUrl, 'acceptedFiles' => 'application/pdf']]),
            'file' => new FieldUpload('prototype', ['label' => '-', 'options' => ['url' => $temporaryFilesUrl]]),
            'image' => new FieldUploadImage('prototype', [
                'label' => '-',
                'baseUrl' => $publicFilesUrl,
                'altTextLabel' => __('common.altText'),
                'altTextDescription' => __('common.altTextInstructions'),
                'options' => ['url' => $temporaryFilesUrl, 'acceptedFiles' => 'image/*'],
            ]),
        ];
        return array_map(fn ($field) => $field->getConfig(), $prototypes);
    }

    /**
     * The series of the press, for the select.
     *
     * @return array<int,array{value:int,label:string}>
     */
    protected function getSeries(int $contextId): array
    {
        $series = [];
        foreach (Repo::section()->getCollector()->filterByContextIds([$contextId])->getMany() as $item) {
            $series[] = ['value' => (int) $item->getId(), 'label' => (string) $item->getLocalizedTitle()];
        }
        return $series;
    }

    /**
     * The texts of the page, translated here once.
     *
     * @return array<string,string>
     */
    protected function getTexts(): array
    {
        $p = 'plugins.generic.quickSubmitOmp.';
        $keys = [
            'intro' => $p . 'intro',
            'book' => $p . 'book',
            'workType' => $p . 'workType',
            'series' => $p . 'series',
            'seriesNone' => $p . 'seriesNone',
            'seriesPosition' => $p . 'seriesPosition',
            'locale' => $p . 'locale',
            'prefix' => 'common.prefix',
            'title' => 'common.title',
            'subtitle' => 'common.subtitle',
            'abstract' => 'common.abstract',
            'keywords' => 'common.keywords',
            'keywordsDescription' => $p . 'keywordsDescription',
            'cover' => $p . 'cover',
            'datePublished' => $p . 'datePublished',
            'contributors' => $p . 'contributors',
            'contributorsDescription' => $p . 'contributorsDescription',
            'addContributor' => $p . 'addContributor',
            'givenName' => 'user.givenName',
            'familyName' => 'user.familyName',
            'role' => $p . 'role',
            'email' => 'user.email',
            'country' => 'common.country',
            'affiliation' => 'user.affiliation',
            'orcid' => 'user.orcid',
            'moreDetails' => $p . 'moreDetails',
            'remove' => 'common.remove',
            'moveUp' => $p . 'moveUp',
            'moveDown' => $p . 'moveDown',
            'chapters' => $p . 'chapters',
            'chaptersDescription' => $p . 'chaptersDescription',
            'addChapter' => $p . 'addChapter',
            'chapter' => $p . 'chapter',
            'chapterAuthors' => $p . 'chapterAuthors',
            'noContributorsYet' => $p . 'noContributorsYet',
            'pages' => $p . 'pages',
            'chapterFile' => $p . 'chapterFile',
            'formats' => $p . 'formats',
            'formatsDescription' => $p . 'formatsDescription',
            'addFormat' => $p . 'addFormat',
            'formatName' => $p . 'formatName',
            'isbn' => $p . 'isbn',
            'formatFile' => $p . 'formatFile',
            'uploadFile' => 'common.upload.addFile',
            'publishNow' => $p . 'publishNow',
            'publishNowDescription' => $p . 'publishNowDescription',
            'save' => $p . 'save',
            'saving' => $p . 'saving',
            'saved' => $p . 'saved',
            'savedUnpublished' => $p . 'savedUnpublished',
            'viewBook' => $p . 'viewBook',
            'viewWorkflow' => $p . 'viewWorkflow',
            'another' => $p . 'another',
            'errorsFound' => $p . 'errorsFound',
            'saveFailed' => $p . 'error.save',
            'required' => 'common.required',
        ];
        return array_map(fn ($key) => __($key), $keys);
    }
}

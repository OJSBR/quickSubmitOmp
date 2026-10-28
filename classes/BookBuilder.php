<?php

/**
 * @file plugins/generic/quickSubmitOmp/classes/BookBuilder.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BookBuilder
 *
 * @brief Checks what the quick submission page sends and makes the book from it: the
 *  submission in the production stage, its contributors, chapters, publication formats
 *  and files, every format and file approved and open, and the publication published.
 *
 * The book is made through the repositories and DAOs of OMP, in the same way as the
 * forms of the workflow make each of its parts. When any step fails, the submission is
 * deleted, so nothing is left half made.
 */

namespace APP\plugins\generic\quickSubmitOmp\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\publication\Publication;
use APP\submission\Submission;
use PKP\context\Context;
use PKP\core\Core;
use PKP\db\DAORegistry;
use PKP\file\FileManager;
use PKP\file\TemporaryFileManager;
use PKP\security\Role;
use PKP\submission\PKPSubmission;
use PKP\submissionFile\SubmissionFile;
use PKP\user\User;
use PKP\userGroup\UserGroup;

class BookBuilder
{
    /** Roles a contributor may have in each kind of book. */
    public const ROLES_BY_WORK_TYPE = [
        Submission::WORK_TYPE_EDITED_VOLUME => ['volumeEditor', 'chapterAuthor'],
        Submission::WORK_TYPE_AUTHORED_WORK => ['author'],
    ];

    /** The genres of the files: the whole book and a chapter. */
    public const GENRE_BOOK = 'MANUSCRIPT';
    public const GENRE_CHAPTER = 'CHAPTER';

    /** The name of the format that receives the chapter files when the book has none. */
    public const CHAPTER_FORMAT_NAME = 'PDF';

    /** ONIX code of a digital product with no detail (List 150). */
    public const DIGITAL_ENTRY_KEY = 'DA';

    /**
     * Bring what the page sent to the shape the builder works with: trimmed strings,
     * integers where ids are expected, and lists where lists are expected.
     */
    public static function normalize(array $raw): array
    {
        $text = fn ($value) => is_scalar($value) ? trim((string) $value) : '';
        $fileId = fn ($file) => is_array($file) && isset($file['temporaryFileId']) ? (int) $file['temporaryFileId'] : null;
        $list = fn ($value) => is_array($value) ? array_values($value) : [];

        $contributors = [];
        foreach ($list($raw['contributors'] ?? []) as $contributor) {
            $contributor = is_array($contributor) ? $contributor : [];
            $contributors[] = [
                'key' => $text($contributor['key'] ?? ''),
                'givenName' => $text($contributor['givenName'] ?? ''),
                'familyName' => $text($contributor['familyName'] ?? ''),
                'role' => $text($contributor['role'] ?? ''),
                'email' => $text($contributor['email'] ?? ''),
                'country' => strtoupper($text($contributor['country'] ?? '')),
                'affiliation' => $text($contributor['affiliation'] ?? ''),
                'orcid' => $text($contributor['orcid'] ?? ''),
            ];
        }

        $chapters = [];
        foreach ($list($raw['chapters'] ?? []) as $chapter) {
            $chapter = is_array($chapter) ? $chapter : [];
            $chapters[] = [
                'title' => $text($chapter['title'] ?? ''),
                'subtitle' => $text($chapter['subtitle'] ?? ''),
                'abstract' => $text($chapter['abstract'] ?? ''),
                'pages' => $text($chapter['pages'] ?? ''),
                'authors' => array_values(array_unique(array_map($text, $list($chapter['authors'] ?? [])))),
                'file' => $fileId($chapter['file'] ?? null),
            ];
        }

        $formats = [];
        foreach ($list($raw['formats'] ?? []) as $format) {
            $format = is_array($format) ? $format : [];
            $formats[] = [
                'name' => $text($format['name'] ?? ''),
                'isbn' => preg_replace('/[\s-]/', '', $text($format['isbn'] ?? '')),
                'file' => $fileId($format['file'] ?? null),
            ];
        }

        $keywords = array_values(array_filter(array_map('trim', preg_split('/[;,\n]/', $text($raw['keywords'] ?? ''))), 'strlen'));

        return [
            'workType' => (int) ($raw['workType'] ?? 0),
            'locale' => $text($raw['locale'] ?? ''),
            'seriesId' => (int) ($raw['seriesId'] ?? 0) ?: null,
            'seriesPosition' => $text($raw['seriesPosition'] ?? ''),
            'prefix' => $text($raw['prefix'] ?? ''),
            'title' => $text($raw['title'] ?? ''),
            'subtitle' => $text($raw['subtitle'] ?? ''),
            'abstract' => $text($raw['abstract'] ?? ''),
            'keywords' => array_values(array_unique($keywords)),
            'coverImage' => $fileId($raw['coverImage'] ?? null),
            'coverAltText' => $text(is_array($raw['coverImage'] ?? null) ? ($raw['coverImage']['altText'] ?? '') : ''),
            'datePublished' => $text($raw['datePublished'] ?? ''),
            'publish' => !empty($raw['publish']),
            'contributors' => $contributors,
            'chapters' => $chapters,
            'formats' => $formats,
        ];
    }

    /**
     * The errors of the data, keyed by the path of the field ("title",
     * "contributors.0.familyName", "chapters.2.file"); none when it can be made.
     *
     * @param array{locales:string[],seriesIds:int[],defaultEmail:string,roleGroups:array<string,?int>} $options
     * @param callable(int):?array{name:string,type:string} $temporaryFile Describes a temporary file of the user, or null.
     *
     * @return array<string,string[]>
     */
    public static function validate(array $data, array $options, callable $temporaryFile): array
    {
        $errors = [];
        $add = function (string $path, string $key, array $params = []) use (&$errors) {
            $errors[$path][] = __($key, $params);
        };

        $roles = self::ROLES_BY_WORK_TYPE[$data['workType']] ?? null;
        if ($roles === null) {
            $add('workType', 'validator.required');
            $roles = [];
        }
        if (!in_array($data['locale'], $options['locales'], true)) {
            $add('locale', 'validator.required');
        }
        if ($data['seriesId'] !== null && !in_array($data['seriesId'], $options['seriesIds'], true)) {
            $add('seriesId', 'plugins.generic.quickSubmitOmp.error.series');
        }
        if ($data['title'] === '') {
            $add('title', 'validator.required');
        }
        if ($data['datePublished'] !== '' && !self::isDate($data['datePublished'])) {
            $add('datePublished', 'plugins.generic.quickSubmitOmp.error.date');
        }
        if ($data['coverImage'] !== null) {
            $cover = $temporaryFile($data['coverImage']);
            if (!$cover) {
                $add('coverImage', 'plugins.generic.quickSubmitOmp.error.file');
            } elseif (!str_starts_with($cover['type'], 'image/')) {
                $add('coverImage', 'plugins.generic.quickSubmitOmp.error.image');
            }
        }

        // Contributors.
        if (!$data['contributors']) {
            $add('contributors', 'plugins.generic.quickSubmitOmp.error.noContributors');
        }
        $keys = [];
        foreach ($data['contributors'] as $i => $contributor) {
            $path = "contributors.{$i}.";
            foreach (['givenName', 'familyName'] as $name) {
                if ($contributor[$name] === '') {
                    $add($path . $name, 'validator.required');
                }
            }
            if (!in_array($contributor['role'], $roles, true)) {
                $add($path . 'role', 'validator.required');
            } elseif (empty($options['roleGroups'][$contributor['role']])) {
                $add($path . 'role', 'plugins.generic.quickSubmitOmp.error.noUserGroup');
            }
            $email = $contributor['email'] !== '' ? $contributor['email'] : $options['defaultEmail'];
            if ($email === '') {
                $add($path . 'email', 'plugins.generic.quickSubmitOmp.error.noEmail');
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $add($path . 'email', 'validator.email');
            }
            if ($contributor['country'] !== '' && !preg_match('/^[A-Z]{2}$/', $contributor['country'])) {
                $add($path . 'country', 'plugins.generic.quickSubmitOmp.error.country');
            }
            if ($contributor['orcid'] !== '' && self::orcidUrl($contributor['orcid']) === null) {
                $add($path . 'orcid', 'plugins.generic.quickSubmitOmp.error.orcid');
            }
            if ($contributor['key'] === '' || isset($keys[$contributor['key']])) {
                $add($path . 'key', 'plugins.generic.quickSubmitOmp.error.contributorKey');
            }
            $keys[$contributor['key']] = true;
        }
        if ($data['workType'] === Submission::WORK_TYPE_EDITED_VOLUME
            && !in_array('volumeEditor', array_column($data['contributors'], 'role'), true)) {
            $add('contributors', 'plugins.generic.quickSubmitOmp.error.noVolumeEditor');
        }

        // Chapters.
        foreach ($data['chapters'] as $i => $chapter) {
            $path = "chapters.{$i}.";
            if ($chapter['title'] === '') {
                $add($path . 'title', 'validator.required');
            }
            foreach ($chapter['authors'] as $key) {
                if (!isset($keys[$key])) {
                    $add($path . 'authors', 'plugins.generic.quickSubmitOmp.error.chapterAuthor');
                    break;
                }
            }
            if ($chapter['file'] !== null) {
                $file = $temporaryFile($chapter['file']);
                if (!$file) {
                    $add($path . 'file', 'plugins.generic.quickSubmitOmp.error.file');
                } elseif ($file['type'] !== 'application/pdf') {
                    $add($path . 'file', 'plugins.generic.quickSubmitOmp.error.pdf');
                }
            }
        }

        // Formats of the whole book.
        $names = [];
        foreach ($data['formats'] as $i => $format) {
            $path = "formats.{$i}.";
            if ($format['name'] === '') {
                $add($path . 'name', 'validator.required');
            } elseif (isset($names[mb_strtolower($format['name'])])) {
                $add($path . 'name', 'plugins.generic.quickSubmitOmp.error.formatName');
            }
            $names[mb_strtolower($format['name'])] = true;
            if ($format['isbn'] !== '' && !self::isIsbn13($format['isbn'])) {
                $add($path . 'isbn', 'plugins.generic.quickSubmitOmp.error.isbn');
            }
            if ($format['file'] === null) {
                $add($path . 'file', 'plugins.generic.quickSubmitOmp.error.noFile');
            } elseif (!$temporaryFile($format['file'])) {
                $add($path . 'file', 'plugins.generic.quickSubmitOmp.error.file');
            }
        }

        return $errors;
    }

    /**
     * Whether a value is a calendar date written as YYYY-MM-DD.
     */
    public static function isDate(string $value): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Whether a value is an ISBN-13 with a right check digit.
     */
    public static function isIsbn13(string $value): bool
    {
        if (!preg_match('/^97[89]\d{10}$/', $value)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $value[$i] * ($i % 2 ? 3 : 1);
        }
        return (10 - $sum % 10) % 10 === (int) $value[12];
    }

    /**
     * The ORCID URL of an iD written in any of its usual forms, or null when the iD is not
     * valid (ISO 7064 11,2 check digit).
     */
    public static function orcidUrl(string $value): ?string
    {
        if (!preg_match('/(\d{4})-?(\d{4})-?(\d{4})-?(\d{3}[\dX])$/i', trim($value), $m)) {
            return null;
        }
        $digits = $m[1] . $m[2] . $m[3] . strtoupper($m[4]);
        $total = 0;
        for ($i = 0; $i < 15; $i++) {
            $total = ($total + (int) $digits[$i]) * 2;
        }
        $check = (12 - $total % 11) % 11;
        if (($check === 10 ? 'X' : (string) $check) !== $digits[15]) {
            return null;
        }
        return 'https://orcid.org/' . implode('-', str_split($digits, 4));
    }

    /**
     * Abstract text as HTML: the page sends the HTML of the rich text editor; plain text,
     * from an older browser or the API, becomes paragraphs.
     */
    public static function abstractHtml(string $value): string
    {
        // HTML is recognized by the tags the editor writes, not by any angle bracket.
        if ($value === '' || preg_match('/<\/?(p|br|em|strong|i|b|u|sup|sub|a|ul|ol|li|span|div|blockquote)\b[^>]*>/i', $value)) {
            return $value;
        }
        $paragraphs = preg_split('/\R{2,}/', $value);
        return implode('', array_map(fn ($p) => '<p>' . nl2br(htmlspecialchars(trim($p)), false) . '</p>', $paragraphs));
    }

    public function __construct(
        protected Context $context,
        protected User $user,
        protected string $defaultEmail,
        protected string $defaultCountry,
        protected array $roleGroups
    ) {
    }

    /**
     * Make the book, and publish it when asked. The data has passed validate().
     *
     * @return Submission The book as it was saved.
     */
    public function create(array $data): Submission
    {
        $submission = null;
        try {
            $submission = $this->addSubmission($data);
            $publication = $submission->getCurrentPublication();
            $authorIds = $this->addContributors($publication, $data);
            $this->completePublication($publication, $data, $authorIds);
            $formatIds = $this->addFormats($publication, $data);
            $this->addChapters($submission, $publication, $data, $authorIds, $formatIds);

            $submission = Repo::submission()->get($submission->getId());
            if ($data['publish']) {
                $this->publish($submission);
            }
            $this->index(Repo::submission()->get($submission->getId()));
            $this->deleteTemporaryFiles($data);
            return Repo::submission()->get($submission->getId());
        } catch (\Throwable $e) {
            if ($submission && ($saved = Repo::submission()->get($submission->getId()))) {
                Repo::submission()->delete($saved);
            }
            throw $e;
        }
    }

    /**
     * The submission, complete and in the production stage, with its publication, and the
     * user assigned to it as a manager of the press, as the quick submission of OJS does.
     */
    protected function addSubmission(array $data): Submission
    {
        $locale = $data['locale'];
        $submission = Repo::submission()->dao->newDataObject();
        $submission->setData('contextId', $this->context->getId());
        $submission->setData('locale', $locale);
        $submission->setData('workType', $data['workType']);
        $submission->setData('status', PKPSubmission::STATUS_QUEUED);
        $submission->setData('submissionProgress', '');
        $submission->setData('stageId', WORKFLOW_STAGE_ID_PRODUCTION);
        $submission->setData('dateSubmitted', Core::getCurrentDate());
        $submission->stampLastActivity();

        $publication = new Publication();
        $publication->setData('locale', $locale);
        $publication->setData('status', PKPSubmission::STATUS_QUEUED);
        $publication->setData('version', 1);
        $publication->setData('title', $data['title'], $locale);
        foreach (['prefix', 'subtitle'] as $name) {
            if ($data[$name] !== '') {
                $publication->setData($name, $data[$name], $locale);
            }
        }
        if ($data['abstract'] !== '') {
            $publication->setData('abstract', self::abstractHtml($data['abstract']), $locale);
        }
        if ($data['seriesId']) {
            $publication->setData('seriesId', $data['seriesId']);
            $publication->setData('seriesPosition', $data['seriesPosition'] !== '' ? $data['seriesPosition'] : null);
        }
        if ($data['datePublished'] !== '') {
            $publication->setData('datePublished', $data['datePublished']);
        }

        $submissionId = Repo::submission()->add($submission, $publication, $this->context);

        $managerGroup = UserGroup::withContextIds([$this->context->getId()])
            ->withRoleIds([Role::ROLE_ID_MANAGER])
            ->withUserIds([$this->user->getId()])
            ->first();
        if ($managerGroup) {
            Repo::stageAssignment()->build($submissionId, $managerGroup->id, $this->user->getId());
        }

        return Repo::submission()->get($submissionId);
    }

    /**
     * The contributors, in the order of the page. Volume editors and the authors of an
     * authored work appear in the catalog; chapter authors appear in their chapters only.
     *
     * @return array<string,int> Key of the page => author id.
     */
    protected function addContributors(Publication $publication, array $data): array
    {
        $locale = $data['locale'];
        $authorIds = [];
        foreach ($data['contributors'] as $seq => $contributor) {
            $author = Repo::author()->newDataObject();
            $author->setData('publicationId', $publication->getId());
            $author->setData('givenName', $contributor['givenName'], $locale);
            $author->setData('familyName', $contributor['familyName'], $locale);
            $author->setData('email', $contributor['email'] !== '' ? $contributor['email'] : $this->defaultEmail);
            $country = $contributor['country'] !== '' ? $contributor['country'] : $this->defaultCountry;
            if ($country !== '') {
                $author->setData('country', $country);
            }
            if ($contributor['orcid'] !== '') {
                $author->setData('orcid', self::orcidUrl($contributor['orcid']));
            }
            $author->setData('userGroupId', $this->roleGroups[$contributor['role']]);
            $author->setData('isVolumeEditor', $contributor['role'] === 'volumeEditor');
            $author->setData('includeInBrowse', $contributor['role'] !== 'chapterAuthor');
            $author->setData('seq', $seq);
            if ($contributor['affiliation'] !== '') {
                $affiliation = Repo::affiliation()->newDataObject();
                $affiliation->setData('name', $contributor['affiliation'], $locale);
                $author->setAffiliations([$affiliation]);
            }
            $authorIds[$contributor['key']] = Repo::author()->add($author);
        }
        return $authorIds;
    }

    /**
     * The primary contact, the keywords and the cover, which the core saves through an
     * edit of the publication (the cover is moved from the temporary files there).
     *
     * @param array<string,int> $authorIds
     */
    protected function completePublication(Publication $publication, array $data, array $authorIds): void
    {
        $locale = $data['locale'];
        $params = ['primaryContactId' => reset($authorIds) ?: null];
        if ($data['keywords']) {
            $params['keywords'] = [$locale => array_map(fn ($keyword) => ['name' => $keyword], $data['keywords'])];
        }
        if ($data['coverImage'] !== null) {
            $params['coverImage'] = [$locale => ['temporaryFileId' => $data['coverImage'], 'altText' => $data['coverAltText']]];
        }
        Repo::publication()->edit(Repo::publication()->get($publication->getId()), $params);
    }

    /**
     * The publication formats, approved and available, each with its file of the whole book
     * and its ISBN-13. The chapter files go to the format named PDF, or to the first one, or
     * to a PDF format made for them.
     *
     * @return array{book:array<int,int>,chapters:?int} Format ids by position, and the id of the format of the chapters.
     */
    protected function addFormats(Publication $publication, array $data): array
    {
        $locale = $data['locale'];
        $formatIds = [];
        foreach ($data['formats'] as $seq => $format) {
            $formatId = $this->addFormat($publication, $format['name'], $locale, $seq);
            $formatIds[$seq] = $formatId;
            if ($format['isbn'] !== '') {
                $identificationCodeDao = DAORegistry::getDAO('IdentificationCodeDAO');
                $code = $identificationCodeDao->newDataObject();
                $code->setPublicationFormatId($formatId);
                $code->setCode('15');
                $code->setValue($format['isbn']);
                $identificationCodeDao->insertObject($code);
            }
            $this->addFile($publication, $format['file'], $formatId, self::GENRE_BOOK, null, $locale);
        }

        $chapterFormatId = null;
        if (array_filter(array_column($data['chapters'], 'file'))) {
            foreach ($data['formats'] as $seq => $format) {
                if (mb_strtolower($format['name']) === mb_strtolower(self::CHAPTER_FORMAT_NAME)) {
                    $chapterFormatId = $formatIds[$seq];
                }
            }
            $chapterFormatId ??= $formatIds[0] ?? $this->addFormat($publication, self::CHAPTER_FORMAT_NAME, $locale, count($formatIds));
        }

        return ['book' => $formatIds, 'chapters' => $chapterFormatId];
    }

    /**
     * A digital publication format, approved and available, as the formats grid makes one
     * and as its approval and availability switches leave it.
     */
    protected function addFormat(Publication $publication, string $name, string $locale, int $seq): int
    {
        $publicationFormatDao = DAORegistry::getDAO('PublicationFormatDAO');
        $format = $publicationFormatDao->newDataObject();
        $format->setData('publicationId', $publication->getId());
        $format->setName($name, $locale);
        $format->setEntryKey(self::DIGITAL_ENTRY_KEY);
        $format->setPhysicalFormat(false);
        $format->setIsApproved(true);
        $format->setIsAvailable(true);
        $format->setSequence($seq);
        return (int) $publicationFormatDao->insertObject($format);
    }

    /**
     * The chapters, with their authors and their file.
     *
     * @param array<string,int> $authorIds
     * @param array{book:array<int,int>,chapters:?int} $formatIds
     */
    protected function addChapters(Submission $submission, Publication $publication, array $data, array $authorIds, array $formatIds): void
    {
        $locale = $data['locale'];
        $chapterDao = DAORegistry::getDAO('ChapterDAO');
        foreach ($data['chapters'] as $seq => $chapterData) {
            $chapter = $chapterDao->newDataObject();
            $chapter->setData('publicationId', $publication->getId());
            $chapter->setData('title', $chapterData['title'], $locale);
            if ($chapterData['subtitle'] !== '') {
                $chapter->setData('subtitle', $chapterData['subtitle'], $locale);
            }
            if ($chapterData['abstract'] !== '') {
                $chapter->setData('abstract', self::abstractHtml($chapterData['abstract']), $locale);
            }
            $chapter->setData('pages', $chapterData['pages'] !== '' ? $chapterData['pages'] : null);
            // Each chapter gets its own page, where its authors are named.
            $chapter->setPageEnabled(true);
            $chapter->setSequence($seq);
            $chapterId = $chapterDao->insertChapter($chapter);

            foreach ($chapterData['authors'] as $authorSeq => $key) {
                Repo::author()->addToChapter($authorIds[$key], $chapterId, false, $authorSeq);
            }
            if ($chapterData['file'] !== null) {
                $this->addFile($publication, $chapterData['file'], $formatIds['chapters'], self::GENRE_CHAPTER, $chapterId, $locale);
            }
        }
    }

    /**
     * A file of a publication format, approved for publication and in open access, moved
     * from the user's temporary files as the files API stores an upload.
     */
    protected function addFile(Publication $publication, int $temporaryFileId, int $formatId, string $genreKey, ?int $chapterId, string $locale): int
    {
        $temporaryFile = (new TemporaryFileManager())->getFile($temporaryFileId, $this->user->getId());
        $submissionId = $publication->getData('submissionId');
        $extension = (new FileManager())->parseFileExtension($temporaryFile->getOriginalFileName());
        $fileId = app()->get('file')->add(
            $temporaryFile->getFilePath(),
            Repo::submissionFile()->getSubmissionDir($this->context->getId(), $submissionId) . '/' . uniqid() . '.' . $extension
        );

        $genre = DAORegistry::getDAO('GenreDAO')->getByKey($genreKey, $this->context->getId());
        $submissionFile = Repo::submissionFile()->newDataObject([
            'fileId' => $fileId,
            'submissionId' => $submissionId,
            'uploaderUserId' => $this->user->getId(),
            'fileStage' => SubmissionFile::SUBMISSION_FILE_PROOF,
            'assocType' => Application::ASSOC_TYPE_REPRESENTATION,
            'assocId' => $formatId,
            'genreId' => $genre?->getId(),
            'name' => [$locale => $temporaryFile->getOriginalFileName()],
            'chapterId' => $chapterId,
            // Approved for publication, and open access as the approval form sets it.
            'viewable' => true,
            'salesType' => 'openAccess',
            'directSalesPrice' => 0,
        ]);
        return Repo::submissionFile()->add($submissionFile);
    }

    /**
     * Publish the current publication, refused with the same errors as the workflow.
     */
    protected function publish(Submission $submission): void
    {
        $publication = $submission->getCurrentPublication();
        $errors = Repo::publication()->validatePublish(
            $publication,
            $submission,
            $submission->getPublicationLanguages($this->context->getSupportedSubmissionMetadataLocales()),
            $submission->getData('locale')
        );
        if ($errors) {
            throw new BookBuilderException($errors);
        }
        Repo::publication()->publish($publication);
    }

    /**
     * Index the metadata and the files of the book for the search.
     */
    protected function index(Submission $submission): void
    {
        $searchIndex = Application::getSubmissionSearchIndex();
        $searchIndex->submissionMetadataChanged($submission);
        $searchIndex->submissionFilesChanged($submission);
        $searchIndex->submissionChangesFinished();
    }

    /**
     * Remove the temporary files the book was made from.
     */
    protected function deleteTemporaryFiles(array $data): void
    {
        $manager = new TemporaryFileManager();
        $ids = array_merge(array_column($data['chapters'], 'file'), array_column($data['formats'], 'file'));
        foreach (array_filter($ids) as $id) {
            $manager->deleteById($id, $this->user->getId());
        }
    }
}

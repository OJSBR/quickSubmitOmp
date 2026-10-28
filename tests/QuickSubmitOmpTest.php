<?php

/**
 * @file plugins/generic/quickSubmitOmp/tests/QuickSubmitOmpTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class QuickSubmitOmpTest
 *
 * @brief What the page sends, how it is checked, the defaults of a new contributor, and
 *        the book metadata of an edited volume rewritten from its volume editors.
 */

namespace APP\plugins\generic\quickSubmitOmp\tests;

use APP\core\Application;
use APP\plugins\generic\quickSubmitOmp\classes\BookBuilder;
use APP\plugins\generic\quickSubmitOmp\classes\EditedVolumeMetadata;
use APP\plugins\generic\quickSubmitOmp\QuickSubmitOmpPlugin;
use APP\submission\Submission;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\metadata\MetadataDescription;
use PKP\tests\PKPTestCase;

#[CoversClass(BookBuilder::class)]
#[CoversClass(EditedVolumeMetadata::class)]
#[CoversClass(QuickSubmitOmpPlugin::class)]
class QuickSubmitOmpTest extends PKPTestCase
{
    /** An edited volume as the page sends it, with every part filled. */
    protected function editedVolume(): array
    {
        return [
            'workType' => Submission::WORK_TYPE_EDITED_VOLUME,
            'locale' => 'en',
            'seriesId' => '',
            'title' => '  A Book  ',
            'abstract' => 'First paragraph.' . "\n\n" . 'Second <one>.',
            'keywords' => 'one, two; three' . "\n" . 'one',
            'datePublished' => '2026-10-28',
            'publish' => true,
            'contributors' => [
                ['key' => 'c1', 'givenName' => 'Ana', 'familyName' => 'Silva', 'role' => 'volumeEditor', 'email' => '', 'country' => 'br', 'orcid' => ''],
                ['key' => 'c2', 'givenName' => 'Bruno', 'familyName' => 'Costa', 'role' => 'chapterAuthor', 'email' => 'bruno@example.com', 'country' => '', 'orcid' => '0000-0002-1825-0097'],
            ],
            'chapters' => [
                ['title' => 'One', 'authors' => ['c2', 'c2'], 'file' => ['temporaryFileId' => '11']],
                ['title' => 'Two', 'authors' => ['c1', 'c2'], 'file' => null],
            ],
            'formats' => [
                ['name' => 'PDF', 'isbn' => '978-0-306-40615-7', 'file' => ['temporaryFileId' => 12]],
            ],
        ];
    }

    protected function options(array $changes = []): array
    {
        return array_merge([
            'locales' => ['en', 'pt_BR'],
            'seriesIds' => [3],
            'defaultEmail' => 'support@press.example',
            'roleGroups' => ['volumeEditor' => 14, 'chapterAuthor' => 15, 'author' => 13],
        ], $changes);
    }

    /** Temporary files of the user: 11 is a PDF, 12 an EPUB, 13 an image. */
    protected function files(): callable
    {
        $files = [11 => 'application/pdf', 12 => 'application/epub+zip', 13 => 'image/png'];
        return fn (int $id) => isset($files[$id]) ? ['name' => "file{$id}", 'type' => $files[$id]] : null;
    }

    protected function errors(array $raw, array $options = []): array
    {
        return BookBuilder::validate(BookBuilder::normalize($raw), $this->options($options), $this->files());
    }

    public function testThePageDataIsBroughtToOneShape(): void
    {
        $data = BookBuilder::normalize($this->editedVolume());

        $this->assertSame('A Book', $data['title']);
        $this->assertNull($data['seriesId']);
        $this->assertSame(['one', 'two', 'three'], $data['keywords']);
        $this->assertSame('BR', $data['contributors'][0]['country']);
        $this->assertSame(['c2'], $data['chapters'][0]['authors'], 'An author is linked once.');
        $this->assertSame(11, $data['chapters'][0]['file']);
        $this->assertNull($data['chapters'][1]['file']);
        $this->assertSame('9780306406157', $data['formats'][0]['isbn']);
        $this->assertSame([], BookBuilder::normalize(['contributors' => 'x', 'chapters' => null])['contributors']);
    }

    public function testAWholeEditedVolumeIsAccepted(): void
    {
        $this->assertSame([], $this->errors($this->editedVolume()));
    }

    public function testOnlyTheNamesOfAContributorAreRequired(): void
    {
        $raw = $this->editedVolume();
        $raw['contributors'][0] = ['key' => 'c1', 'givenName' => '', 'familyName' => ' ', 'role' => 'volumeEditor'];
        $errors = $this->errors($raw);
        $this->assertSame(['contributors.0.givenName', 'contributors.0.familyName'], array_keys($errors));

        // Without an e-mail of its own, a contributor takes the one of the press...
        $raw = $this->editedVolume();
        $this->assertArrayNotHasKey('contributors.0.email', $this->errors($raw));
        // ...and only a press without one makes the field required.
        $this->assertArrayHasKey('contributors.0.email', $this->errors($raw, ['defaultEmail' => '']));
        $this->assertArrayNotHasKey('contributors.1.email', $this->errors($raw, ['defaultEmail' => '']));
    }

    public function testEachKindOfBookHasItsRoles(): void
    {
        $raw = $this->editedVolume();
        $raw['contributors'][0]['role'] = 'chapterAuthor';
        $this->assertArrayHasKey('contributors', $this->errors($raw), 'An edited volume needs a volume editor.');

        $raw = $this->editedVolume();
        $raw['workType'] = Submission::WORK_TYPE_AUTHORED_WORK;
        $errors = $this->errors($raw);
        $this->assertArrayHasKey('contributors.0.role', $errors);
        $this->assertArrayHasKey('contributors.1.role', $errors);

        $raw['contributors'][0]['role'] = $raw['contributors'][1]['role'] = 'author';
        $this->assertSame([], $this->errors($raw));

        $this->assertArrayHasKey('contributors.0.role', $this->errors($this->editedVolume(), ['roleGroups' => ['volumeEditor' => null, 'chapterAuthor' => 15]]));
        $this->assertArrayHasKey('workType', $this->errors(['workType' => 9] + $this->editedVolume()));
    }

    public function testChaptersAndFormatsAreChecked(): void
    {
        $raw = $this->editedVolume();
        $raw['chapters'][0]['title'] = '';
        $raw['chapters'][0]['file'] = ['temporaryFileId' => 12];
        $raw['chapters'][1]['authors'] = ['c9'];
        $raw['formats'][] = ['name' => 'pdf', 'isbn' => '9780306406158', 'file' => null];
        $raw['formats'][] = ['name' => 'EPUB', 'file' => ['temporaryFileId' => 99]];

        $this->assertSame([
            'chapters.0.title',
            'chapters.0.file',
            'chapters.1.authors',
            'formats.1.name',
            'formats.1.isbn',
            'formats.1.file',
            'formats.2.file',
        ], array_keys($this->errors($raw)));
    }

    public function testTheBookFieldsAreChecked(): void
    {
        $raw = ['title' => '', 'locale' => 'fr', 'seriesId' => 4, 'datePublished' => '2026-02-30', 'coverImage' => ['temporaryFileId' => 11]] + $this->editedVolume();
        $this->assertSame(['locale', 'seriesId', 'title', 'datePublished', 'coverImage'], array_keys($this->errors($raw)));

        $raw = ['seriesId' => '3', 'coverImage' => ['temporaryFileId' => 13, 'altText' => 'Cover']] + $this->editedVolume();
        $this->assertSame([], $this->errors($raw));
    }

    public function testIdentifiersAreChecked(): void
    {
        $this->assertTrue(BookBuilder::isIsbn13('9780306406157'));
        $this->assertFalse(BookBuilder::isIsbn13('9780306406158'));
        $this->assertFalse(BookBuilder::isIsbn13('0306406152'), 'ISBN-10 is not taken.');

        $this->assertSame('https://orcid.org/0000-0002-1825-0097', BookBuilder::orcidUrl('https://orcid.org/0000-0002-1825-0097'));
        $this->assertSame('https://orcid.org/0000-0002-1694-233X', BookBuilder::orcidUrl('000000021694233x'));
        $this->assertNull(BookBuilder::orcidUrl('0000-0002-1825-0098'));

        $this->assertTrue(BookBuilder::isDate('2024-02-29'));
        $this->assertFalse(BookBuilder::isDate('2026-13-01'));
    }

    public function testPlainAbstractsBecomeParagraphs(): void
    {
        $this->assertSame('<p>First paragraph.</p><p>Second &lt;one&gt;.<br>' . "\n" . 'line</p>', BookBuilder::abstractHtml("First paragraph.\n\nSecond <one>.\nline"));
        $this->assertSame('<p>Rich <em>text</em></p>', BookBuilder::abstractHtml('<p>Rich <em>text</em></p>'));
    }

    public function testANewContributorGetsTheDefaultsOfThePress(): void
    {
        $settings = [];
        $plugin = new class ($settings) extends QuickSubmitOmpPlugin {
            public function __construct(public array $settings)
            {
            }

            public function getSetting($contextId, $name)
            {
                return $this->settings[$name] ?? null;
            }
        };
        $context = Application::getContextDAO()->newDataObject();
        $context->setId(1);
        $context->setData('contactEmail', 'contact@press.example');
        $context->setData('country', 'BR');

        $this->assertSame('contact@press.example', $plugin->getDefaultEmail($context), 'Without technical support, the principal contact.');
        $context->setData('supportEmail', 'support@press.example');
        $this->assertSame('support@press.example', $plugin->getDefaultEmail($context), 'The technical support contact first.');
        $plugin->settings['defaultEmail'] = ' books@press.example ';
        $this->assertSame('books@press.example', $plugin->getDefaultEmail($context), 'The address set in the plugin above all.');

        $this->assertSame('BR', $plugin->getDefaultCountry($context));
        $plugin->settings['defaultCountry'] = 'PT';
        $this->assertSame('PT', $plugin->getDefaultCountry($context));
    }

    public function testTheHeadTagsOfAnEditedVolumeNameItsVolumeEditors(): void
    {
        $head = "<head>\n"
            . "\t<meta name=\"gs_meta_revision\" content=\"1.1\"/>\n"
            . "\t<meta name=\"citation_title\" content=\"A Book\"/>\n"
            . "\t<meta name=\"citation_author\" content=\"Ana Silva\"/>\n"
            . "\t<meta name=\"citation_author_institution\" content=\"University\"/>\n"
            . "\t<meta name=\"citation_author\" content=\"Bruno Costa\"/>\n"
            . "\t<meta name=\"citation_author\" content=\"Carla Dias\"/>\n"
            . "\t<meta name=\"citation_author_institution\" content=\"Institute\"/>\n"
            . "\t<meta name=\"DC.Creator.PersonalName\" content=\"Ana Silva\"/>\n"
            . "\t<meta name=\"DC.Creator.PersonalName\" content=\"Bruno Costa\"/>\n"
            . "\t<meta name=\"DC.Creator.PersonalName\" content=\"Carla Dias\"/>\n"
            . "\t<meta name=\"DC.Title\" content=\"A Book\"/>\n"
            . "</head>\n<body><p>Bruno Costa</p></body>";
        $editors = [
            ['name' => 'Ana Silva', 'affiliations' => ['University']],
            ['name' => 'Dora "D" O\'Neil', 'affiliations' => []],
        ];

        $result = EditedVolumeMetadata::rewriteHeadTags($head, $editors);

        preg_match_all('/<meta name="(citation_author|citation_author_institution|DC\.Creator\.PersonalName)" content="([^"]*)"\/>/', $result, $m, PREG_SET_ORDER);
        $this->assertSame([
            ['citation_author', 'Ana Silva'],
            ['citation_author_institution', 'University'],
            ['citation_author', 'Dora &quot;D&quot; O&#039;Neil'],
            ['DC.Creator.PersonalName', 'Ana Silva'],
            ['DC.Creator.PersonalName', 'Dora &quot;D&quot; O&#039;Neil'],
        ], array_map(fn ($match) => [$match[1], $match[2]], $m));
        // The other tags stay where they were, and the body is not touched.
        $this->assertLessThan(strpos($result, 'citation_author"'), strpos($result, 'citation_title'));
        $this->assertLessThan(strpos($result, 'DC.Title'), strpos($result, 'DC.Creator'));
        $this->assertStringContainsString('<body><p>Bruno Costa</p></body>', $result);

        // A page without one family of tags (its plugin is off) does not get it.
        $withoutDublinCore = preg_replace('/\t<meta name="DC\.Creator[^>]*>\n/', '', $head);
        $this->assertStringNotContainsString('DC.Creator', EditedVolumeMetadata::rewriteHeadTags($withoutDublinCore, $editors));
        // Twice is the same as once.
        $this->assertSame($result, EditedVolumeMetadata::rewriteHeadTags($result, $editors));
    }

    public function testOnlyAnEditedVolumeWithVolumeEditorsIsRewritten(): void
    {
        $author = fn (bool $editor) => new class ($editor) {
            public function __construct(private bool $editor)
            {
            }

            public function getIsVolumeEditor()
            {
                return $this->editor;
            }
        };
        $publication = new class ([$author(false), $author(true), $author(false)]) {
            public function __construct(private array $authors)
            {
            }

            public function getData($name)
            {
                return $name === 'authors' ? $this->authors : null;
            }
        };
        $monograph = new Submission();

        $monograph->setData('workType', Submission::WORK_TYPE_EDITED_VOLUME);
        $this->assertCount(1, EditedVolumeMetadata::volumeEditors($monograph, $publication));
        $monograph->setData('workType', Submission::WORK_TYPE_AUTHORED_WORK);
        $this->assertSame([], EditedVolumeMetadata::volumeEditors($monograph, $publication));
    }

    public function testTheOaiRecordNamesTheVolumeEditorsOnly(): void
    {
        $description = $this->getMockBuilder(MetadataDescription::class)->disableOriginalConstructor()->onlyMethods(['removeStatement', 'addStatement'])->getMock();
        $calls = [];
        $description->method('removeStatement')->willReturnCallback(function ($name) use (&$calls) {
            $calls[] = ['remove', $name];
            return true;
        });
        $description->method('addStatement')->willReturnCallback(function ($name, $value, $locale = null) use (&$calls) {
            $calls[] = ['add', $name, $value, $locale];
            return true;
        });
        $editor = new class () {
            public function getFullNames($includePrefix, $familyFirst)
            {
                return $familyFirst ? ['en' => 'Silva, Ana', 'pt_BR' => 'Silva, Ana', 'fr' => ''] : [];
            }
        };

        EditedVolumeMetadata::rewriteOaiCreators($description, [$editor]);

        $this->assertSame([
            ['remove', 'dc:creator'],
            ['add', 'dc:creator', 'Silva, Ana', 'en'],
            ['add', 'dc:creator', 'Silva, Ana', 'pt_BR'],
        ], $calls);
    }

    public function testTheOnixProductsOfAnEditedVolumeKeepTheirVolumeEditors(): void
    {
        $contributor = fn (int $seq, string $name, string $role) => "<Contributor><SequenceNumber>{$seq}</SequenceNumber><ContributorRole>{$role}</ContributorRole><PersonName>{$name}</PersonName></Contributor>";
        $product = fn (int $formatId, array $people) => "<Product><RecordReference>press.example.edited.{$formatId}</RecordReference><DescriptiveDetail>"
            . implode('', $people) . '<TitleDetail/></DescriptiveDetail></Product>';
        $xml = '<?xml version="1.0"?><ONIXMessage xmlns="http://ns.editeur.org/onix/3.0/reference">'
            . $product(7, [$contributor(1, 'Bruno', 'A01'), $contributor(2, 'Ana', 'B01'), $contributor(3, 'Carla', 'A01'), $contributor(4, 'Dora', 'B01')])
            . $product(8, [$contributor(1, 'Eva', 'A01'), $contributor(2, 'Fabio', 'A01')])
            . '</ONIXMessage>';
        $document = new \DOMDocument();
        $document->loadXML($xml);

        // Format 7 belongs to an edited volume (Ana and Dora are its volume editors); format 8
        // to a book the plugin leaves alone.
        EditedVolumeMetadata::rewriteOnixContributors($document, fn (int $formatId) => $formatId === 7 ? [false, true, false, true] : null);

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('o', 'http://ns.editeur.org/onix/3.0/reference');
        $people = fn (int $formatId) => array_map(
            fn ($node) => $xpath->evaluate('string(o:SequenceNumber)', $node) . ':' . $xpath->evaluate('string(o:PersonName)', $node),
            iterator_to_array($xpath->query("//o:Product[o:RecordReference='press.example.edited.{$formatId}']//o:Contributor"))
        );
        $this->assertSame(['1:Ana', '2:Dora'], $people(7));
        $this->assertSame(['1:Eva', '2:Fabio'], $people(8));
        $this->assertSame(1, $xpath->query('//o:Product[1]//o:TitleDetail')->length, 'The rest of the product stays.');
    }

    public function testTheBookPageFilterIsOnlyRegisteredForAnEditedVolume(): void
    {
        $plugin = new class () extends QuickSubmitOmpPlugin {
            public function __construct()
            {
            }

            public function getEnabled($contextId = null)
            {
                return true;
            }
        };
        $editor = new class () {
            public function getIsVolumeEditor()
            {
                return true;
            }

            public function getAffiliations()
            {
                return [];
            }

            public function getFullName($a, $b, $locale)
            {
                return 'Ana Silva';
            }
        };
        $publication = new class ([$editor]) {
            public function __construct(private array $authors)
            {
            }

            public function getData($name)
            {
                return ['authors' => $this->authors, 'locale' => 'en'][$name] ?? null;
            }
        };
        $templateMgr = new class () {
            public array $vars = [];
            public array $filters = [];

            public function getTemplateVars($name)
            {
                return $this->vars[$name] ?? null;
            }

            public function registerFilter($type, $callback, $name = null)
            {
                $this->filters[] = $name;
            }
        };
        $monograph = new Submission();
        $monograph->setData('contextId', 1);
        $monograph->setData('workType', Submission::WORK_TYPE_EDITED_VOLUME);
        $templateMgr->vars = ['publishedSubmission' => $monograph, 'publication' => $publication, 'isChapterRequest' => false];

        $plugin->callbackBookPage('TemplateManager::display', [$templateMgr, 'frontend/pages/catalog.tpl']);
        $this->assertSame([], $templateMgr->filters, 'Only the book page.');
        $templateMgr->vars['isChapterRequest'] = true;
        $plugin->callbackBookPage('TemplateManager::display', [$templateMgr, 'frontend/pages/book.tpl']);
        $this->assertSame([], $templateMgr->filters, 'A chapter page names the authors of the chapter.');
        $templateMgr->vars['isChapterRequest'] = false;
        $monograph->setData('workType', Submission::WORK_TYPE_AUTHORED_WORK);
        $plugin->callbackBookPage('TemplateManager::display', [$templateMgr, 'frontend/pages/book.tpl']);
        $this->assertSame([], $templateMgr->filters, 'Not a monograph.');
        $monograph->setData('workType', Submission::WORK_TYPE_EDITED_VOLUME);
        $plugin->callbackBookPage('TemplateManager::display', [$templateMgr, 'frontend/pages/book.tpl']);
        $this->assertSame(['quickSubmitOmpEditedVolume'], $templateMgr->filters);
    }

    public function testThePageGetsEveryFieldAndText(): void
    {
        // Built as the page builds it, for the first press of the installation, by its
        // first site administrator.
        $context = Application::getContextDAO()->getAll()->next();
        $admin = \APP\facades\Repo::user()->getCollector()->filterByRoleIds([\PKP\security\Role::ROLE_ID_SITE_ADMIN])->getMany()->first();
        \PKP\core\Registry::delete('user');
        $this->mockRequest($context->getPath() . '/quickSubmitOmp/index', $admin->getId());
        $plugin = new QuickSubmitOmpPlugin();
        $plugin->register('generic', 'plugins/generic/quickSubmitOmp', $context->getId());
        $handler = new \APP\plugins\generic\quickSubmitOmp\classes\QuickSubmitOmpHandler($plugin);
        $config = (new \ReflectionMethod($handler, 'getPageConfig'))->invoke($handler, \APP\core\Application::get()->getRequest());

        $this->assertSame(['text', 'select', 'options', 'richText', 'pdf', 'file', 'image'], array_keys($config['fields']));
        $this->assertSame('field-upload', $config['fields']['pdf']['component']);
        $this->assertSame('application/pdf', $config['fields']['pdf']['options']['acceptedFiles']);
        $this->assertStringEndsWith('/temporaryFiles', $config['fields']['file']['options']['url']);
        $this->assertStringContainsString('/quickSubmitOmp/save', $config['saveUrl']);
        $this->assertSame([Submission::WORK_TYPE_EDITED_VOLUME, Submission::WORK_TYPE_AUTHORED_WORK], array_column($config['workTypes'], 'value'));
        foreach ($config['i18n'] as $name => $text) {
            $this->assertStringNotContainsString('##', $text, "The text {$name} is not translated.");
        }
        $this->assertNotEmpty($config['countries']);
    }
}

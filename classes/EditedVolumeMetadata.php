<?php

/**
 * @file plugins/generic/quickSubmitOmp/classes/EditedVolumeMetadata.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class EditedVolumeMetadata
 *
 * @brief The book metadata of an edited volume names its volume editors only.
 *
 * The book page of OMP already lists the volume editors only, but the Google Scholar
 * and Dublin Core tags of the page, the OAI records and the ONIX export take every
 * contributor of the publication, chapter authors included. These functions rewrite
 * each of them from the volume editors, and leave a book without volume editors as it
 * is. The chapter pages, which name the authors of the chapter, are not touched.
 */

namespace APP\plugins\generic\quickSubmitOmp\classes;

use APP\author\Author;
use APP\submission\Submission;
use PKP\metadata\MetadataDescription;

class EditedVolumeMetadata
{
    /** The head tags of a book page that name its contributors, by family of tags. */
    public const HEAD_TAGS = [
        'scholar' => ['citation_author', 'citation_author_institution'],
        'dublinCore' => ['DC.Creator.PersonalName'],
    ];

    /**
     * The volume editors of an edited volume, in the order of the publication; none for any
     * other book, or for an edited volume whose contributors were not marked as editors.
     *
     * @return Author[]
     */
    public static function volumeEditors(Submission $monograph, object $publication): array
    {
        if ((int) $monograph->getData('workType') !== Submission::WORK_TYPE_EDITED_VOLUME) {
            return [];
        }
        $editors = [];
        foreach ($publication->getData('authors') ?? [] as $author) {
            if ($author->getIsVolumeEditor()) {
                $editors[] = $author;
            }
        }
        return $editors;
    }

    /**
     * The name and the affiliations of each person, as the core tags write them.
     *
     * @param Author[] $authors
     *
     * @return array<int,array{name:string,affiliations:string[]}>
     */
    public static function describe(array $authors, ?string $locale): array
    {
        $people = [];
        foreach ($authors as $author) {
            $affiliations = [];
            foreach ($author->getAffiliations() as $affiliation) {
                $name = trim((string) $affiliation->getLocalizedName($locale));
                if ($name !== '') {
                    $affiliations[] = $name;
                }
            }
            $people[] = ['name' => (string) $author->getFullName(false, false, $locale), 'affiliations' => $affiliations];
        }
        return $people;
    }

    /**
     * Rewrite the Google Scholar and Dublin Core tags that name the contributors of a book
     * page so that they name the given people, where the first of those tags was. A family
     * of tags missing from the page (its plugin is off) stays missing.
     *
     * @param array<int,array{name:string,affiliations:string[]}> $people
     */
    public static function rewriteHeadTags(string $html, array $people): string
    {
        // Escaped as the core plugins escape them.
        $escape = fn (string $value) => htmlspecialchars($value);
        $replacements = [
            'scholar' => function () use ($people, $escape) {
                $tags = [];
                foreach ($people as $person) {
                    $tags[] = '<meta name="citation_author" content="' . $escape($person['name']) . '"/>';
                    foreach ($person['affiliations'] as $affiliation) {
                        $tags[] = '<meta name="citation_author_institution" content="' . $escape($affiliation) . '"/>';
                    }
                }
                return $tags;
            },
            'dublinCore' => fn () => array_map(fn ($person) => '<meta name="DC.Creator.PersonalName" content="' . $escape($person['name']) . '"/>', $people),
        ];

        foreach (self::HEAD_TAGS as $family => $names) {
            $pattern = '/[ \t]*<meta\s+name="(?:' . implode('|', array_map(fn ($name) => preg_quote($name, '/'), $names)) . ')"\s+content="[^"]*"\s*\/?>[ \t]*\r?\n?/';
            if (!preg_match($pattern, $html, $first, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $without = preg_replace($pattern, '', $html);
            $offset = $first[0][1];
            $tags = $replacements[$family]();
            $html = substr($without, 0, $offset) . ($tags ? implode("\n", $tags) . "\n" : '') . substr($without, $offset);
        }
        return $html;
    }

    /**
     * Make the volume editors the only dc:creator of an OAI record.
     *
     * @param Author[] $editors
     */
    public static function rewriteOaiCreators(MetadataDescription $description, array $editors): void
    {
        $description->removeStatement('dc:creator');
        foreach ($editors as $editor) {
            foreach ((array) $editor->getFullNames(false, true) as $locale => $name) {
                if (trim((string) $name) !== '') {
                    $description->addStatement('dc:creator', $name, $locale);
                }
            }
        }
    }

    /**
     * Keep the volume editors only as contributors of each product of an edited volume in
     * an ONIX document, numbered again from one.
     *
     * The export numbers the contributors in the order of the publication; for each product
     * the function is handed the publication format of the product (the last part of its
     * record reference) and answers, in that same order, which contributors are volume
     * editors, or null for a product it must leave alone.
     *
     * @param callable(int):?array<int,bool> $authorsOfFormat
     */
    public static function rewriteOnixContributors(\DOMDocument $document, callable $authorsOfFormat): void
    {
        foreach (iterator_to_array($document->getElementsByTagNameNS('*', 'Product')) as $product) {
            $reference = self::childText($product, 'RecordReference');
            if (!preg_match('/\.(\d+)$/', (string) $reference, $match)) {
                continue;
            }
            $isEditor = $authorsOfFormat((int) $match[1]);
            if (!$isEditor) {
                continue;
            }
            $sequence = 0;
            foreach (iterator_to_array($product->getElementsByTagNameNS('*', 'Contributor')) as $contributor) {
                $position = (int) self::childText($contributor, 'SequenceNumber');
                if (empty($isEditor[$position - 1])) {
                    $contributor->parentNode->removeChild($contributor);
                    continue;
                }
                foreach ($contributor->childNodes as $child) {
                    if ($child instanceof \DOMElement && $child->localName === 'SequenceNumber') {
                        $child->nodeValue = (string) ++$sequence;
                    }
                }
            }
        }
    }

    /**
     * The text of the first child element with the given local name.
     */
    protected static function childText(\DOMElement $element, string $localName): ?string
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $localName) {
                return trim($child->textContent);
            }
        }
        return null;
    }
}

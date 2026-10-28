<?php

/**
 * @file plugins/generic/quickSubmitOmp/classes/BookBuilderException.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BookBuilderException
 *
 * @brief The core refused to publish the book; carries the errors it gave.
 */

namespace APP\plugins\generic\quickSubmitOmp\classes;

class BookBuilderException extends \RuntimeException
{
    /**
     * @param array<string,string|string[]> $errors The errors of the core, by property.
     */
    public function __construct(public array $errors)
    {
        parent::__construct(implode(' ', array_map(fn ($error) => implode(' ', (array) $error), $errors)));
    }
}

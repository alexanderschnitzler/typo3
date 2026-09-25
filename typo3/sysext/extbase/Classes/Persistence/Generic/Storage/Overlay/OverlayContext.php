<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Extbase\Persistence\Generic\Storage\Overlay;

use TYPO3\CMS\Core\Context\LanguageAspect;

/**
 * Everything the row overlay needs to know about the query that fetched the rows.
 *
 * @internal only to be used within Extbase, not part of TYPO3 Core API.
 */
final readonly class OverlayContext
{
    /**
     * @param string $tableName The table the rows belong to; for a join the table of the right-hand side
     * @param bool $isJoin Whether the rows come from a join and may carry columns of other tables
     * @param LanguageAspect $languageAspect The language aspect of the query settings
     * @param bool $respectSysLanguage Whether the query restricted the rows to the requested language
     * @param bool $ignoreEnableFields Whether the query ignored enable fields
     * @param string[] $enableFieldsToBeIgnored The ignored enable fields; empty means all of them
     * @param bool $isRootQuery Whether the query fetches the aggregate root (and not a relation of it)
     * @param int $limit The limit of the query, 0 for none
     */
    public function __construct(
        public string $tableName,
        public bool $isJoin,
        public LanguageAspect $languageAspect,
        public bool $respectSysLanguage,
        public bool $ignoreEnableFields,
        public array $enableFieldsToBeIgnored,
        public bool $isRootQuery,
        public int $limit,
    ) {}
}

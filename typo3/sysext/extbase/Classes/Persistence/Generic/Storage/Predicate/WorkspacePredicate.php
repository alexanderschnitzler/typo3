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

namespace TYPO3\CMS\Extbase\Persistence\Generic\Storage\Predicate;

use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Builds the workspace condition of an Extbase query for the aggregate root table:
 * only live records and records newly created in a workspace are selected, versions
 * of live records are applied later by the workspace overlay.
 *
 * @internal only to be used within Extbase, not part of TYPO3 Core API.
 */
final readonly class WorkspacePredicate
{
    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * @return string The condition, or an empty string if the table is not workspace aware
     */
    public function build(ExpressionBuilder $expressionBuilder, string $tableName, string $tableAlias): string
    {
        if ($this->tcaSchemaFactory->has($tableName) && $this->tcaSchemaFactory->get($tableName)->isWorkspaceAware()) {
            // Always prevent workspace records from being returned (except for newly created records)
            return $expressionBuilder->eq($tableAlias . '.t3ver_oid', 0);
        }
        return '';
    }
}

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
use TYPO3\CMS\Core\Schema\Capability\RootLevelCapability;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Extbase\Persistence\Generic\Exception\InconsistentQuerySettingsException;

/**
 * Builds the storage page (pid) condition of an Extbase query, respecting the
 * root level configuration of the table.
 *
 * @internal only to be used within Extbase, not part of TYPO3 Core API.
 */
final readonly class StoragePagePredicate
{
    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * @param int[] $storagePageIds
     * @return string The condition, or an empty string if the table has no usable root level configuration
     * @throws InconsistentQuerySettingsException
     */
    public function build(ExpressionBuilder $expressionBuilder, string $tableName, string $tableAlias, array $storagePageIds): string
    {
        if (!$this->tcaSchemaFactory->has($tableName)) {
            return '';
        }

        /** @var RootLevelCapability $rootLevelCapability */
        $rootLevelCapability = $this->tcaSchemaFactory->get($tableName)->getCapability(TcaSchemaCapability::RestrictionRootLevel);
        switch ($rootLevelCapability->getRootLevelType()) {
            // Only in pid 0
            case RootLevelCapability::TYPE_ONLY_ON_ROOTLEVEL:
                $storagePageIds = [0];
                break;
                // Pid 0 and pagetree
            case RootLevelCapability::TYPE_BOTH:
                if ($storagePageIds === []) {
                    $storagePageIds = [0];
                } else {
                    $storagePageIds[] = 0;
                }
                break;
                // Only pagetree or not set
            case RootLevelCapability::TYPE_ONLY_ON_PAGES:
                if (empty($storagePageIds)) {
                    throw new InconsistentQuerySettingsException('Missing storage page ids.', 1365779762);
                }
                break;
                // Invalid configuration
            default:
                return '';
        }
        $storagePageIds = array_map(intval(...), $storagePageIds);
        if (count($storagePageIds) === 1) {
            return $expressionBuilder->eq($tableAlias . '.pid', reset($storagePageIds));
        }
        return $expressionBuilder->in($tableAlias . '.pid', $storagePageIds);
    }
}

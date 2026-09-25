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

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Exception\InconsistentQuerySettingsException;
use TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;

/**
 * Builds the enable-field and deleted condition of an Extbase query for one table alias.
 *
 * Frontend queries use the default constraints of the PageRepository (enable fields, deleted,
 * workspace), backend queries use BackendUtility::BEenableFields() plus the deleted flag.
 *
 * @internal only to be used within Extbase, not part of TYPO3 Core API.
 */
final readonly class VisibilityPredicate
{
    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
        private PageRepository $pageRepository,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Frontend or backend rules are taken from the query settings, see Typo3QuerySettings::isFrontendContext().
     *
     * @return string The condition, or an empty string if nothing needs to be restricted
     * @throws InconsistentQuerySettingsException
     */
    public function build(QuerySettingsInterface $querySettings, string $tableName, string $tableAlias): string
    {
        if (!$this->tcaSchemaFactory->has($tableName)) {
            return '';
        }
        $isFrontend = $querySettings instanceof Typo3QuerySettings
            ? $querySettings->isFrontendContext()
            : Typo3QuerySettings::isFrontendRequest();

        $ignoreEnableFields = $querySettings->getIgnoreEnableFields();
        $enableFieldsToBeIgnored = $querySettings->getEnableFieldsToBeIgnored();
        $includeDeleted = $querySettings->getIncludeDeleted();
        if ($isFrontend) {
            $statement = $this->getFrontendConstraintStatement($tableName, $tableAlias, $ignoreEnableFields, $enableFieldsToBeIgnored, $includeDeleted);
        } else {
            // applicationType backend
            $statement = $this->getBackendConstraintStatement($tableName, $ignoreEnableFields, $includeDeleted);
            if (!empty($statement)) {
                $statement = $this->replaceTableNameWithAlias($statement, $tableName, $tableAlias);
                $statement = strtolower(substr($statement, 1, 3)) === 'and' ? substr($statement, 5) : $statement;
            }
        }
        return $statement;
    }

    /**
     * Returns constraint statement for frontend context
     *
     * @param bool $ignoreEnableFields A flag indicating whether the enable fields should be ignored
     * @param array $enableFieldsToBeIgnored If $ignoreEnableFields is true, this array specifies enable fields to be ignored. If it is NULL or an empty array (default) all enable fields are ignored.
     * @param bool $includeDeleted A flag indicating whether deleted records should be included
     * @throws InconsistentQuerySettingsException
     */
    private function getFrontendConstraintStatement(string $tableName, string $tableAlias, bool $ignoreEnableFields, array $enableFieldsToBeIgnored, bool $includeDeleted): string
    {
        $statement = '';
        if ($ignoreEnableFields && !$includeDeleted) {
            if (!empty($enableFieldsToBeIgnored)) {
                $constraints = $this->pageRepository->getDefaultConstraints($tableName, $enableFieldsToBeIgnored, $tableAlias);
                if ($constraints !== []) {
                    $statement = implode(' AND ', $constraints);
                }
            } else {
                $schema = $this->tcaSchemaFactory->get($tableName);
                if ($schema->hasCapability(TcaSchemaCapability::SoftDelete)) {
                    $deleteField = $schema->getCapability(TcaSchemaCapability::SoftDelete)->getFieldName();
                    $statement = $tableAlias . '.' . $deleteField . '=0';
                }
            }
        } elseif (!$ignoreEnableFields && !$includeDeleted) {
            $constraints = $this->pageRepository->getDefaultConstraints($tableName, [], $tableAlias);
            if ($constraints !== []) {
                $statement = implode(' AND ', $constraints);
            }
        } elseif (!$ignoreEnableFields) {
            throw new InconsistentQuerySettingsException('Query setting "ignoreEnableFields=FALSE" can not be used together with "includeDeleted=TRUE" in frontend context.', 1460975922);
        }
        return $statement;
    }

    /**
     * Returns constraint statement for backend context
     *
     * @param bool $ignoreEnableFields A flag indicating whether the enable fields should be ignored
     * @param bool $includeDeleted A flag indicating whether deleted records should be included
     */
    private function getBackendConstraintStatement(string $tableName, bool $ignoreEnableFields, bool $includeDeleted): string
    {
        $statement = '';
        // In case of versioning-preview, enableFields are ignored (checked in Typo3DbBackend::doLanguageAndWorkspaceOverlay)
        $isUserInWorkspace = GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('workspace', 'isOffline');
        if (!$ignoreEnableFields && !$isUserInWorkspace) {
            $statement .= BackendUtility::BEenableFields($tableName);
        }
        $schema = $this->tcaSchemaFactory->get($tableName);
        if (!$includeDeleted && $schema->hasCapability(TcaSchemaCapability::SoftDelete)) {
            $deleteField = $schema->getCapability(TcaSchemaCapability::SoftDelete)->getFieldName();
            $statement .= ' AND ' . $tableName . '.' . $deleteField . '=0';
        }
        return $statement;
    }

    /**
     * If the table name does not match the table alias all occurrences of
     * "tableName." are replaced with "tableAlias." in the given SQL statement.
     */
    private function replaceTableNameWithAlias(string $statement, string $tableName, string $tableAlias): string
    {
        if ($tableAlias !== $tableName) {
            $connection = $this->connectionPool->getConnectionForTable($tableName);
            $quotedTableName = $connection->quoteIdentifier($tableName);
            $quotedTableAlias = $connection->quoteIdentifier($tableAlias);
            $statement = str_replace(
                [$tableName . '.', $quotedTableName . '.'],
                [$tableAlias . '.', $quotedTableAlias . '.'],
                $statement
            );
        }
        return $statement;
    }
}

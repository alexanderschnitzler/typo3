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

namespace TYPO3\CMS\Extbase\Persistence\Generic\Storage;

use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Exception\TypesException;
use Doctrine\DBAL\Types\Type;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Core\Cache\Event\AddCacheTagEvent;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\DomainObject\AbstractDomainObject;
use TYPO3\CMS\Extbase\DomainObject\AbstractValueObject;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\JoinInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\SelectorInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\Statement;
use TYPO3\CMS\Extbase\Persistence\Generic\Query;
use TYPO3\CMS\Extbase\Persistence\Generic\Storage\Exception\BadConstraintException;
use TYPO3\CMS\Extbase\Persistence\Generic\Storage\Exception\SqlErrorException;
use TYPO3\CMS\Extbase\Persistence\Generic\Storage\Overlay\OverlayContext;
use TYPO3\CMS\Extbase\Persistence\Generic\Storage\Overlay\RowOverlayService;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Reflection\ReflectionService;
use TYPO3\CMS\Extbase\Service\CacheService;
use TYPO3\CMS\Frontend\Cache\CacheLifetimeCalculator;

/**
 * A Storage backend
 * @internal only to be used within Extbase, not part of TYPO3 Core API.
 */
#[Autoconfigure(public: true)]
readonly class Typo3DbBackend implements BackendInterface
{
    public function __construct(
        protected CacheService $cacheService,
        protected ConnectionPool $connectionPool,
        protected ReflectionService $reflectionService,
        protected EventDispatcherInterface $eventDispatcher,
        protected CacheLifetimeCalculator $cacheLifetimeCalculator,
        #[Autowire(expression: 'service("features").isFeatureEnabled("frontend.cache.autoTagging")')]
        protected bool $autoTagging,
        protected RowOverlayService $rowOverlayService,
    ) {}

    /**
     * Adds a row to the storage
     *
     * @param string $tableName The database table name
     * @param array $fieldValues The row to be inserted
     * @param bool $isRelation TRUE if we are currently inserting into a relation table, FALSE by default
     * @return int The uid of the inserted row
     * @throws SqlErrorException
     */
    public function addRow(string $tableName, array $fieldValues, bool $isRelation = false): int
    {
        if (isset($fieldValues['uid'])) {
            unset($fieldValues['uid']);
        }
        try {
            $connection = $this->connectionPool->getConnectionForTable($tableName);
            $connection->insert($tableName, $fieldValues, $this->getTypesForDataset($tableName, $fieldValues));
        } catch (DBALException $e) {
            throw new SqlErrorException($e->getMessage(), 1470230766, $e);
        }

        $uid = 0;
        if (!$isRelation) {
            // Relation tables have no auto_increment column, so no retrieval must be tried.
            $uid = (int)$connection->lastInsertId();
            $this->cacheService->clearCacheForRecord($tableName, $uid);
        }
        return $uid;
    }

    /**
     * Updates a row in the storage
     *
     * @param string $tableName The database table name
     * @param array $fieldValues The row to be updated
     * @param bool $isRelation TRUE if we are currently inserting into a relation table, FALSE by default
     * @throws \InvalidArgumentException
     * @throws SqlErrorException
     */
    public function updateRow(string $tableName, array $fieldValues, bool $isRelation = false): void
    {
        if (!isset($fieldValues['uid'])) {
            throw new \InvalidArgumentException('The given row must contain a value for "uid".', 1476045164);
        }

        $uid = (int)$fieldValues['uid'];
        unset($fieldValues['uid']);

        try {
            $connection = $this->connectionPool->getConnectionForTable($tableName);
            $connection->update($tableName, $fieldValues, ['uid' => $uid], $this->getTypesForDataset($tableName, $fieldValues));
        } catch (DBALException $e) {
            throw new SqlErrorException($e->getMessage(), 1470230767, $e);
        }

        if (!$isRelation) {
            $this->cacheService->clearCacheForRecord($tableName, $uid);
        }
    }

    /**
     * Updates a relation row in the storage.
     *
     * @param string $tableName The database relation table name
     * @param array $fieldValues The row to be updated
     * @throws SqlErrorException
     * @throws \InvalidArgumentException
     */
    public function updateRelationTableRow(string $tableName, array $fieldValues): void
    {
        if (!isset($fieldValues['uid_local']) && !isset($fieldValues['uid_foreign'])) {
            throw new \InvalidArgumentException(
                'The given fieldValues must contain a value for "uid_local" and "uid_foreign".',
                1360500126
            );
        }

        $where = [];
        $where['uid_local'] = (int)$fieldValues['uid_local'];
        $where['uid_foreign'] = (int)$fieldValues['uid_foreign'];
        unset($fieldValues['uid_local']);
        unset($fieldValues['uid_foreign']);

        if (!empty($fieldValues['tablenames'])) {
            $where['tablenames'] = $fieldValues['tablenames'];
            unset($fieldValues['tablenames']);
        }
        if (!empty($fieldValues['fieldname'])) {
            $where['fieldname'] = $fieldValues['fieldname'];
            unset($fieldValues['fieldname']);
        }

        try {
            $this->connectionPool->getConnectionForTable($tableName)->update(
                $tableName,
                $fieldValues,
                $where,
                $this->getTypesForDataset($tableName, $fieldValues),
            );
        } catch (DBALException $e) {
            throw new SqlErrorException($e->getMessage(), 1470230768, $e);
        }
    }

    /**
     * Deletes a row in the storage
     *
     * @param string $tableName The database table name
     * @param array $where An array of where array('fieldname' => value).
     * @param bool $isRelation TRUE if we are currently manipulating a relation table, FALSE by default
     * @throws SqlErrorException
     */
    public function removeRow(string $tableName, array $where, bool $isRelation = false): void
    {
        try {
            $this->connectionPool->getConnectionForTable($tableName)->delete($tableName, $where);
        } catch (DBALException $e) {
            throw new SqlErrorException($e->getMessage(), 1470230769, $e);
        }

        if (!$isRelation && isset($where['uid'])) {
            $this->cacheService->clearCacheForRecord($tableName, (int)$where['uid']);
        }
    }

    /**
     * Returns the object data matching the $query.
     *
     * @throws SqlErrorException
     */
    public function getObjectDataByQuery(QueryInterface $query): array
    {
        $statement = $query->getStatement();
        if ($statement instanceof Statement && !$statement->getStatement() instanceof QueryBuilder) {
            $rows = $this->getObjectDataByRawQuery($statement);
        } else {
            $queryParser = GeneralUtility::makeInstance(Typo3DbQueryParser::class);
            if ($statement instanceof Statement
                && $statement->getStatement() instanceof QueryBuilder
            ) {
                $queryBuilder = $statement->getStatement();
            } else {
                $queryBuilder = $queryParser->convertQueryToDoctrineQueryBuilder($query);
            }
            $selectParts = $queryBuilder->getSelect();
            if ($queryParser->isDistinctQuerySuggested() && !empty($selectParts)) {
                $selectParts[0] = 'DISTINCT ' . $selectParts[0];
                $queryBuilder->selectLiteral(...$selectParts);
            }
            if ($query->getOffset()) {
                $queryBuilder->setFirstResult($query->getOffset());
            }
            if ($query->getLimit()) {
                // Only set the "real" limit in LIVE workspace, as we do not need to make WS overlays here
                // And can calculate with the direct result from the RDBMS without needing to calculate this in
                // PHP (see below).
                // What we do in workspace, is making a "best guess". Why do we do this? If we have content that
                // is hidden in a workspace, we need to get the "next" record in line, but we cannot do this
                // with overlays in SQL. So we use the "best guess" by adding twice the limit. Imagine you have
                // 2000 news records, and we need to manually calculate the first 10 records, we just take 20 records
                // from SQL and hope that this matches for "most" usecases (Pareto Principle).
                if (GeneralUtility::makeInstance(Context::class)->getAspect('workspace')->isLive()) {
                    $queryBuilder->setMaxResults($query->getLimit());
                } else {
                    $queryBuilder->setMaxResults($query->getLimit() * 2);
                }
            }
            try {
                $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
            } catch (DBALException $e) {
                throw new SqlErrorException($e->getMessage(), 1472074485, $e);
            }
        }

        if (!empty($rows)) {
            $rows = $this->overlayRows($query, $rows);
            if ($this->autoTagging) {
                $source = $query->getSource();
                if ($source instanceof JoinInterface) {
                    $source = $source->getRight();
                }
                if (!$source instanceof SelectorInterface) {
                    throw new \RuntimeException(get_class($source) . ' must implement SelectorInterface at this point.', 1726753183);
                }
                $tableName = $source->getSelectorName();
                $this->addCacheTagsForRows($tableName, $rows);
            }
        }

        return $rows;
    }

    /**
     * Returns the object data using a custom statement
     *
     * @throws SqlErrorException when the raw SQL statement fails in the database
     */
    protected function getObjectDataByRawQuery(Statement $statement): array
    {
        $realStatement = $statement->getStatement();
        $parameters = $statement->getBoundVariables();

        // The real statement is an instance of the Doctrine DBAL QueryBuilder, so fetching
        // this directly is possible
        if ($realStatement instanceof QueryBuilder) {
            try {
                $result = $realStatement->executeQuery();
            } catch (DBALException $e) {
                throw new SqlErrorException($e->getMessage(), 1472064721, $e);
            }
            $rows = $result->fetchAllAssociative();
            // Prepared Doctrine DBAL statement
        } elseif ($realStatement instanceof \Doctrine\DBAL\Statement) {
            try {
                foreach ($parameters as $parameterIdentifier => $parameterValue) {
                    $realStatement->bindValue($parameterIdentifier, $parameterValue);
                }
                $result = $realStatement->executeQuery();
            } catch (DBALException $e) {
                throw new SqlErrorException($e->getMessage(), 1481281404, $e);
            }
            $rows = $result->fetchAllAssociative();
        } else {
            // Do a real raw query. This is very stupid, as it does not allow to use DBAL's real power if
            // several tables are on different databases, so this is used with caution and could be removed
            // in the future
            try {
                $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
                $statement = $connection->executeQuery($realStatement, $parameters);
            } catch (DBALException $e) {
                throw new SqlErrorException($e->getMessage(), 1472064775, $e);
            }

            $rows = $statement->fetchAllAssociative();
        }

        return $rows;
    }

    /**
     * Returns the number of tuples matching the query.
     *
     * @return int The number of matching tuples
     * @throws BadConstraintException
     * @throws SqlErrorException
     */
    public function getObjectCountByQuery(QueryInterface $query): int
    {
        if ($query->getConstraint() instanceof Statement) {
            throw new BadConstraintException('Could not execute count on queries with a constraint of type TYPO3\\CMS\\Extbase\\Persistence\\Generic\\Qom\\Statement', 1256661045);
        }

        $statement = $query->getStatement();
        if ($statement instanceof Statement
            && !$statement->getStatement() instanceof QueryBuilder
        ) {
            $rows = $this->getObjectDataByQuery($query);
            $count = count($rows);
        } else {
            $queryParser  = GeneralUtility::makeInstance(Typo3DbQueryParser::class);
            $queryBuilder = $queryParser
                ->convertQueryToDoctrineQueryBuilder($query)
                ->resetOrderBy();

            if ($queryParser->isDistinctQuerySuggested()) {
                $source = $queryBuilder->getFrom()[0];
                // Tablename is already quoted for the DBMS, we need to treat table and field names separately
                $tableName = $source->alias ?: $source->table;
                $fieldName = $queryBuilder->quoteIdentifier('uid');
                $queryBuilder
                    ->resetGroupBy()
                    ->selectLiteral(sprintf('COUNT(DISTINCT %s.%s)', $tableName, $fieldName));
            } else {
                $queryBuilder->count('*');
            }
            // Ensure to count only records in the current workspace
            $context = GeneralUtility::makeInstance(Context::class);
            $workspaceUid = (int)$context->getPropertyFromAspect('workspace', 'id');
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceUid));

            try {
                $count = $queryBuilder->executeQuery()->fetchOne();
            } catch (DBALException $e) {
                throw new SqlErrorException($e->getMessage(), 1472074379, $e);
            }
            if ($query->getOffset()) {
                $count -= $query->getOffset();
            }
            if ($query->getLimit()) {
                $count = min($count, $query->getLimit());
            }
        }
        return (int)max(0, $count);
    }

    /**
     * Checks if a Value Object equal to the given Object exists in the database
     *
     * @param AbstractValueObject $object The Value Object
     * @return int|null The matching uid if an object was found, else FALSE
     * @throws SqlErrorException
     */
    public function getUidOfAlreadyPersistedValueObject(AbstractValueObject $object): ?int
    {
        $className = get_class($object);
        /** @var DataMapper $dataMapper */
        $dataMapper = GeneralUtility::makeInstance(DataMapper::class);
        $dataMap = $dataMapper->getDataMap($className);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($dataMap->tableName);
        if (Typo3QuerySettings::isFrontendRequest()) {
            $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        }
        $whereClause = [];
        // loop over all properties of the object to exactly set the values of each database field
        $classSchema = $this->reflectionService->getClassSchema($className);
        foreach ($classSchema->getDomainObjectProperties() as $property) {
            $propertyName = $property->getName();
            // @todo We couple the Backend to the Entity implementation (uid, isClone); changes there breaks this method
            if ($dataMap->isPersistableProperty($propertyName) && $propertyName !== AbstractDomainObject::PROPERTY_UID && $propertyName !== AbstractDomainObject::PROPERTY_PID && $propertyName !== 'isClone') {
                $propertyValue = $object->_getProperty($propertyName);
                $columnMap = $dataMap->getColumnMap($propertyName);
                $fieldName = $columnMap->columnName;
                if ($propertyValue === null) {
                    $whereClause[] = $queryBuilder->expr()->isNull($fieldName);
                } else {
                    $whereClause[] = $queryBuilder->expr()->eq($fieldName, $queryBuilder->createNamedParameter($dataMapper->getPlainValue($propertyValue, $columnMap)));
                }
            }
        }
        $queryBuilder
            ->select('uid')
            ->from($dataMap->tableName)
            ->where(...$whereClause);

        try {
            $uid = (int)$queryBuilder
                ->executeQuery()
                ->fetchOne();
            if ($uid > 0) {
                return $uid;
            }
            return null;
        } catch (DBALException $e) {
            throw new SqlErrorException($e->getMessage(), 1470231748, $e);
        }
    }

    /**
     * Performs workspace and language overlay on the given rows, see RowOverlayService.
     * Rows of a query without a proper source (no table name) are returned unchanged.
     */
    private function overlayRows(QueryInterface $query, array $rows): array
    {
        $source = $query->getSource();
        if ($source instanceof SelectorInterface) {
            $tableName = $source->getSelectorName();
            $isJoin = false;
        } elseif ($source instanceof JoinInterface) {
            $tableName = $source->getRight()->getSelectorName();
            $isJoin = true;
        } else {
            return $rows;
        }
        $querySettings = $query->getQuerySettings();
        return $this->rowOverlayService->overlayRows($rows, new OverlayContext(
            tableName: $tableName,
            isJoin: $isJoin,
            languageAspect: $querySettings->getLanguageAspect(),
            respectSysLanguage: $querySettings->getRespectSysLanguage(),
            ignoreEnableFields: $querySettings->getIgnoreEnableFields(),
            enableFieldsToBeIgnored: $querySettings->getEnableFieldsToBeIgnored(),
            isRootQuery: !$query instanceof Query || !$query->getParentQuery(),
            limit: (int)$query->getLimit(),
        ));
    }

    protected function addCacheTagsForRows(string $tableName, array $rows): void
    {
        foreach ($rows as $row) {
            $lifetime = $this->cacheLifetimeCalculator->calculateLifetimeForRow($tableName, $row);
            $this->eventDispatcher->dispatch(
                new AddCacheTagEvent(
                    new CacheTag(sprintf('%s_%s', $tableName, ($row['uid'] ?? 0)), $lifetime)
                )
            );
        }
    }

    /**
     * @param array<string, mixed> $fieldValues
     * @return array<string, Type|ParameterType>
     */
    private function getTypesForDataset(string $tableName, array $fieldValues): array
    {
        $connection = $this->connectionPool->getConnectionForTable($tableName);
        $tableInfo = $connection->getSchemaInformation()->getTableInfo($tableName);
        $types = [];
        foreach ($fieldValues as $key => $value) {
            if (!$tableInfo->hasColumnInfo($key)) {
                // Field is not part of the database schema information, therefore no type is set here and
                // Doctrine DBAL handles the value with its default binding type (ParameterType::STRING).
                continue;
            }
            try {
                // `ColumnInfo->getType()` returns the Doctrine type (e.g. JsonType), which carries the
                // `PHP value <-> database value` conversion methods applied by Doctrine DBAL. Each Doctrine
                // type maps to a plain binding type (e.g. ParameterType::STRING for VARCHAR/CHAR/TEXT/...),
                // which binds the value as-is without applying any conversion. Extbase already performs that
                // conversion itself, which is why the plain binding type is enforced here. This additionally
                // prevents `Connection::ensureDatabaseValueTypes()` from adding the Doctrine type looked up
                // from the database schema.
                $types[$key] = $tableInfo->getColumnInfo($key)->getType()->getBindingType();
            } catch (TypesException) {
                // Ignore, no type to be set
            }
        }
        return $types;
    }
}

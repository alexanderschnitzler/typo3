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

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Applies the workspace and language overlay to rows fetched by an Extbase query.
 *
 * Rows go in as fetched from the database and come out overlaid: versions replace live content,
 * translations replace default language content, and rows that must not be shown (delete placeholders,
 * moved records, missing translations in strict modes, hidden versions) are removed.
 * All record lookups are done through PageRepository.
 *
 * @internal only to be used within Extbase, not part of TYPO3 Core API.
 */
final readonly class RowOverlayService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
        private PageRepository $pageRepository,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function overlayRows(array $rows, OverlayContext $overlayContext): array
    {
        // A custom query is needed for the language, so a custom context is cloned
        $context = clone GeneralUtility::makeInstance(Context::class);
        $context->setAspect('language', $overlayContext->languageAspect);
        if ($overlayContext->ignoreEnableFields) {
            // The language overlay is fetched by PageRepository, which applies the frontend restrictions based on
            // the visibility aspect. Ignored enable fields must therefore be mirrored into that aspect, otherwise a
            // hidden or scheduled translation is never found and the default language record is dropped or kept
            // untranslated. An empty list of enable fields means "ignore all of them".
            $ignoredEnableFields = $overlayContext->enableFieldsToBeIgnored;
            $ignoreAll = $ignoredEnableFields === [];
            $includeHidden = $ignoreAll || in_array('disabled', $ignoredEnableFields, true);
            $includeScheduled = $ignoreAll
                || in_array('starttime', $ignoredEnableFields, true)
                || in_array('endtime', $ignoredEnableFields, true);
            $visibility = $context->getAspect('visibility');
            if ($includeHidden) {
                $visibility = $visibility
                    ->withIncludeHiddenPages(true)
                    ->withIncludeHiddenContent(true);
            }
            if ($includeScheduled) {
                $visibility = $visibility->withIncludeScheduledRecords(true);
            }
            $context->setAspect('visibility', $visibility);
        }

        $workspaceUid = (int)$context->getPropertyFromAspect('workspace', 'id');
        $pageRepository = $this->pageRepository->withContext($context);
        $tableName = $overlayContext->tableName;
        if (!$overlayContext->isJoin) {
            $rows = $this->resolveMovedRecordsInWorkspace($tableName, $rows, $workspaceUid);
            return $this->overlayLanguageAndWorkspaceForSelect($tableName, $rows, $pageRepository, $overlayContext, $context);
        }
        // Special handling of joined select is only needed when doing workspace overlays, which does not happen
        // in live workspace
        if ($workspaceUid === 0) {
            return $this->overlayLanguageAndWorkspaceForSelect($tableName, $rows, $pageRepository, $overlayContext, $context);
        }
        return $this->overlayLanguageAndWorkspaceForJoinedSelect($tableName, $rows, $pageRepository, $overlayContext, $context);
    }

    /**
     * If the result is a plain SELECT (no JOIN) then the regular overlay process works for tables
     *  - overlay workspace
     *  - overlay language of versioned record again
     */
    private function overlayLanguageAndWorkspaceForSelect(string $tableName, array $rows, PageRepository $pageRepository, OverlayContext $overlayContext, Context $context): array
    {
        $limit = 0;
        $overlaidRows = [];
        $countOverlaidRows = 0;
        if ($overlayContext->limit && !$context->getAspect('workspace')->isLive()) {
            $limit = $overlayContext->limit;
        }

        foreach ($rows as $row) {
            $row = $this->overlayLanguageAndWorkspaceForSingleRecord($tableName, $row, $pageRepository, $overlayContext);
            if (is_array($row)) {
                $overlaidRows[] = $row;
                $countOverlaidRows++;
                // We need to calculate the number of overlaid rows manually in PHP
                // (via the is_array() above), because some overlays do not exist in a Workspace
                if ($limit === $countOverlaidRows) {
                    return $overlaidRows;
                }
            }
        }
        return $overlaidRows;
    }

    /**
     * If the result consists of a JOIN (usually happens if a property is a relation with a MM table) then it is necessary
     * to only do overlays for the fields that are contained in the main database table, otherwise a SQL error is thrown.
     * In order to make this happen, a single SQL query is made to fetch all possible field names (= array keys) of
     * a record (TCA[$tableName][columns] does not contain all needed information), which is then used to compute
     * a separate subset of the row which can be overlaid properly.
     */
    private function overlayLanguageAndWorkspaceForJoinedSelect(string $tableName, array $rows, PageRepository $pageRepository, OverlayContext $overlayContext, Context $context): array
    {
        // No valid rows, so this is skipped
        if (!isset($rows[0]['uid'])) {
            return $rows;
        }

        $limit = 0;
        $overlaidRows = [];
        $countOverlaidRows = 0;
        if ($overlayContext->limit && !$context->getAspect('workspace')->isLive()) {
            $limit = $overlayContext->limit;
        }

        // First, find out the fields that belong to the "main" selected table which is defined by TCA, and take the first
        // record to find out all possible fields in this database table
        $fieldsOfMainTable = $pageRepository->getRawRecord($tableName, (int)$rows[0]['uid']);
        if (is_array($fieldsOfMainTable)) {
            foreach ($rows as $row) {
                $mainRow = array_intersect_key($row, $fieldsOfMainTable);
                $joinRow = array_diff_key($row, $mainRow);
                $mainRow = $this->overlayLanguageAndWorkspaceForSingleRecord($tableName, $mainRow, $pageRepository, $overlayContext);
                if (is_array($mainRow)) {
                    $overlaidRows[] = array_replace($joinRow, $mainRow);
                    $countOverlaidRows++;
                    // We need to calculate the number of overlaid rows manually in PHP
                    // (via the is_array() above), because some overlays do not exist in a Workspace
                    if ($limit === $countOverlaidRows) {
                        return $overlaidRows;
                    }
                }
            }
        }
        return $overlaidRows;
    }

    /**
     * Takes one specific row, as defined in TCA and does all overlays.
     *
     * @return array|int|mixed|null the overlaid row or false or null if overlay failed.
     */
    private function overlayLanguageAndWorkspaceForSingleRecord(string $tableName, array $row, PageRepository $pageRepository, OverlayContext $overlayContext)
    {
        $languageAspect = $overlayContext->languageAspect;
        $languageUid = $languageAspect->getContentId();
        $schema = $this->tcaSchemaFactory->get($tableName);
        $languageOfCurrentRecord = 0;
        $languageField = null;
        $translationParentPointerField = null;
        // If current row is a translation select its parent
        if ($schema->isLanguageAware()) {
            $languageCapability = $schema->getCapability(TcaSchemaCapability::Language);
            $languageField = $languageCapability->getLanguageField()->getName();
            $translationParentPointerField = $languageCapability->getTranslationOriginPointerField()->getName();
        }
        if ($languageField && ($row[$languageField] ?? false)) {
            $languageOfCurrentRecord = $row[$languageField];
        }
        // Note #1: In case of ->findByUid([uid-of-translated-record]) the translated record should be fetched at all times
        // Example: you've fetched a translation directly via findByUid(11) which is a translated record, but the
        // request was to do overlays. In this case, the default record is loaded again, and then reapplied again.
        // Note #2: We cannot use $languageAspect->doOverlays() as it also checks for ID > 0
        $fetchLocalizedRecord = $languageAspect->getOverlayType() !== LanguageAspect::OVERLAYS_OFF;
        // We have a translated record from the DB, but we do overlays, so let's take the default language record
        // and do overlays again later-on
        if ($languageOfCurrentRecord > 0
            && $fetchLocalizedRecord
            && ($row[$translationParentPointerField] ?? 0) > 0
        ) {
            $row = $pageRepository->getRawRecord(
                $tableName,
                (int)$row[$translationParentPointerField]
            );
            $languageUid = $languageOfCurrentRecord;
        }

        // Handle workspace overlays
        $pageRepository->versionOL($tableName, $row, true, $overlayContext->ignoreEnableFields);
        if (is_array($row) && $fetchLocalizedRecord) {
            if ($tableName === 'pages') {
                $row = $pageRepository->getLanguageOverlay($tableName, $row);
            } else {
                if (!$overlayContext->respectSysLanguage
                    && $languageOfCurrentRecord > 0
                    && $overlayContext->isRootQuery
                ) {
                    // No parent query means we're processing the aggregate root.
                    // respectSysLanguage is false which means that records returned by the query
                    // might be from different languages (which is desired).
                    // So we must set the language used for overlay to the language of the current record
                    $languageUid = $languageOfCurrentRecord;
                }
                if ($translationParentPointerField
                    && ($row[$translationParentPointerField] ?? 0) > 0
                    && $languageOfCurrentRecord > 0
                ) {
                    // Force overlay by faking default language record, as getRecordOverlay can only handle default language records
                    $row['uid'] = $row[$translationParentPointerField];
                    $row[$languageField] = 0;
                }
                // The overlay type (and fallback chain) of the language aspect is respected, so translation
                // behavior is consistent with the regular page / content rendering. The content language
                // however may have been adjusted above to the language of the actually fetched record
                // (see Note #1 and the respectSysLanguage handling), so a custom aspect is passed here.
                $customLanguageAspect = new LanguageAspect(
                    $languageAspect->getId(),
                    $languageUid,
                    $languageAspect->getOverlayType(),
                    $languageAspect->getFallbackChain()
                );
                $row = $pageRepository->getLanguageOverlay($tableName, $row, $customLanguageAspect);
            }
        } elseif (is_array($row)) {
            // If an already localized record is fetched, the "uid" of the default language is used
            // as the record is re-fetched in the DataMapper
            if ($translationParentPointerField
                && ($row[$translationParentPointerField] ?? 0) > 0
                && $languageOfCurrentRecord > 0
            ) {
                $row['_LOCALIZED_UID'] = (int)$row['uid'];
                $row['uid'] = $row[$translationParentPointerField];
            }
        }
        return $row;
    }

    /**
     * Fetches the moved record in case it is supported
     * by the table and if there's only one row in the result set
     * (applying this to all rows does not work, since the sorting
     * order would be destroyed and possible limits are not met anymore)
     * The move pointers are later unset (see versionOL() last argument)
     */
    private function resolveMovedRecordsInWorkspace(string $tableName, array $rows, int $workspaceUid): array
    {
        if ($workspaceUid === 0) {
            return $rows;
        }
        if (!$this->tcaSchemaFactory->has($tableName) || !$this->tcaSchemaFactory->get($tableName)->hasCapability(TcaSchemaCapability::Workspace)) {
            return $rows;
        }
        if (count($rows) !== 1) {
            return $rows;
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
        $queryBuilder->getRestrictions()->removeAll();
        $movedRecords = $queryBuilder
            ->select('*')
            ->from($tableName)
            ->where(
                $queryBuilder->expr()->eq('t3ver_state', $queryBuilder->createNamedParameter(VersionState::MOVE_POINTER->value, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($rows[0]['uid'], Connection::PARAM_INT))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAllAssociative();
        if (!empty($movedRecords)) {
            $rows = $movedRecords;
        }
        return $rows;
    }
}

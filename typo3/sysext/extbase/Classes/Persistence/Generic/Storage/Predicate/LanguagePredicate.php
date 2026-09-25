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

use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface;

/**
 * Builds the language condition of an Extbase query for the aggregate root table,
 * depending on the language aspect of the query settings (content language and overlay type).
 *
 * @internal only to be used within Extbase, not part of TYPO3 Core API.
 */
final readonly class LanguagePredicate
{
    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * @param QueryBuilder $queryBuilder The query the condition is built for; sub-selects use its connection
     * @param \Closure(string): string $visibilityConstraint Returns the enable-field condition for a table alias
     *                                                     of $tableName, used inside the sub-selects
     */
    public function build(
        QueryBuilder $queryBuilder,
        string $tableName,
        string $tableAlias,
        QuerySettingsInterface $querySettings,
        \Closure $visibilityConstraint,
    ): CompositeExpression|string {
        if (!$this->tcaSchemaFactory->has($tableName)) {
            return '';
        }
        $schema = $this->tcaSchemaFactory->get($tableName);
        if (!$schema->isLanguageAware()) {
            return '';
        }
        $languageCapability = $schema->getCapability(TcaSchemaCapability::Language);

        // Select all entries for the current language
        // If any language is set -> get those entries which are not translated yet
        // They will be removed by \TYPO3\CMS\Core\Domain\Repository\PageRepository::getRecordOverlay if not matching overlay mode
        $languageField = $languageCapability->getLanguageField()->getName();
        $transOrigPointerField = $languageCapability->getTranslationOriginPointerField()->getName();

        $languageAspect = $querySettings->getLanguageAspect();
        if (!$languageAspect->getContentId()) {
            return $queryBuilder->expr()->in(
                $tableAlias . '.' . $languageField,
                [$languageAspect->getContentId(), -1]
            );
        }

        if (!$languageAspect->doOverlays()) {
            return $queryBuilder->expr()->in(
                $tableAlias . '.' . $languageField,
                [$languageAspect->getContentId(), -1]
            );
        }

        $defLangTableAlias = $tableAlias . '_dl';
        $defaultLanguageRecordsSubSelect = $queryBuilder->getConnection()->createQueryBuilder();
        $defaultLanguageRecordsSubSelect->getRestrictions()->removeAll();
        $defaultLanguageRecordsSubSelect
            ->select($defLangTableAlias . '.uid')
            ->from($tableName, $defLangTableAlias)
            ->where(
                $defaultLanguageRecordsSubSelect->expr()->eq($defLangTableAlias . '.' . $transOrigPointerField, 0),
                $defaultLanguageRecordsSubSelect->expr()->eq($defLangTableAlias . '.' . $languageField, 0),
                $visibilityConstraint($defLangTableAlias)
            );

        $andConditions = [];
        // records in language 'all'
        $andConditions[] = $queryBuilder->expr()->eq($tableAlias . '.' . $languageField, -1);
        // translated records where a default language exists
        $andConditions[] = $queryBuilder->expr()->and(
            $queryBuilder->expr()->eq($tableAlias . '.' . $languageField, $languageAspect->getContentId()),
            $queryBuilder->expr()->in(
                $tableAlias . '.' . $transOrigPointerField,
                $defaultLanguageRecordsSubSelect->getSQL()
            )
        );
        // Records in translation with no default language
        if ($languageAspect->getOverlayType() === LanguageAspect::OVERLAYS_ON_WITH_FLOATING) {
            $andConditions[] = $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq($tableAlias . '.' . $languageField, $languageAspect->getContentId()),
                $queryBuilder->expr()->eq($tableAlias . '.' . $transOrigPointerField, 0),
                $queryBuilder->expr()->notIn(
                    $tableAlias . '.' . $transOrigPointerField,
                    $defaultLanguageRecordsSubSelect->getSQL()
                )
            );
        }
        if ($languageAspect->getOverlayType() === LanguageAspect::OVERLAYS_MIXED) {
            // returns records from current language which have a default language
            // together with not translated default language records
            $translatedOnlyTableAlias = $tableAlias . '_to';
            $queryBuilderForSubselect = $queryBuilder->getConnection()->createQueryBuilder();
            $queryBuilderForSubselect->getRestrictions()->removeAll();
            $queryBuilderForSubselect
                ->select($translatedOnlyTableAlias . '.' . $transOrigPointerField)
                ->from($tableName, $translatedOnlyTableAlias)
                ->where(
                    $queryBuilderForSubselect->expr()->gt($translatedOnlyTableAlias . '.' . $transOrigPointerField, 0),
                    $queryBuilderForSubselect->expr()->eq($translatedOnlyTableAlias . '.' . $languageField, $languageAspect->getContentId()),
                    //  The records in default language should also respect the visibility constraints
                    $visibilityConstraint($translatedOnlyTableAlias)
                );
            // records in default language, which do not have a translation
            $andConditions[] = $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq($tableAlias . '.' . $languageField, 0),
                $queryBuilder->expr()->notIn(
                    $tableAlias . '.uid',
                    $queryBuilderForSubselect->getSQL()
                )
            );
        }

        return $queryBuilder->expr()->or(...$andConditions);
    }
}

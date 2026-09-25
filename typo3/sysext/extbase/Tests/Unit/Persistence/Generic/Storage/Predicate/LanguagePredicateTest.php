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

namespace TYPO3\CMS\Extbase\Tests\Unit\Persistence\Generic\Storage\Predicate;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Storage\Predicate\LanguagePredicate;

final class LanguagePredicateTest extends AbstractPredicateTestCase
{
    /**
     * @var list<array{alias: string, where: list<string>}>
     */
    private array $subSelects = [];

    /**
     * @var list<string>
     */
    private array $visibilityAliases = [];

    private function createQuerySettings(LanguageAspect $languageAspect): QuerySettingsInterface
    {
        $querySettings = self::createStub(QuerySettingsInterface::class);
        $querySettings->method('getLanguageAspect')->willReturn($languageAspect);
        return $querySettings;
    }

    /**
     * A sub-select renders as "SUBSELECT <alias>"; its FROM alias and WHERE parts are recorded.
     */
    private function createSubSelect(): QueryBuilder
    {
        $index = count($this->subSelects);
        $this->subSelects[$index] = ['alias' => '', 'where' => []];
        $subSelect = self::createStub(QueryBuilder::class);
        $subSelect->method('getRestrictions')->willReturn(self::createStub(QueryRestrictionContainerInterface::class));
        $subSelect->method('expr')->willReturn($this->createExpressionBuilder());
        $subSelect->method('select')->willReturnSelf();
        $subSelect->method('from')->willReturnCallback(function (string $table, string $alias) use ($subSelect, $index): QueryBuilder {
            $this->subSelects[$index]['alias'] = $alias;
            return $subSelect;
        });
        $subSelect->method('where')->willReturnCallback(function (string ...$predicates) use ($subSelect, $index): QueryBuilder {
            $this->subSelects[$index]['where'] = $predicates;
            return $subSelect;
        });
        $subSelect->method('getSQL')->willReturnCallback(fn(): string => 'SUBSELECT ' . $this->subSelects[$index]['alias']);
        return $subSelect;
    }

    private function createQueryBuilder(): QueryBuilder
    {
        $connection = $this->createConnectionStub();
        $connection->method('createQueryBuilder')->willReturnCallback(fn(): QueryBuilder => $this->createSubSelect());
        $queryBuilder = self::createStub(QueryBuilder::class);
        $queryBuilder->method('expr')->willReturn($this->createExpressionBuilder($connection));
        $queryBuilder->method('getConnection')->willReturn($connection);
        return $queryBuilder;
    }

    private function build(string $tableName, LanguageAspect $languageAspect): string
    {
        $subject = new LanguagePredicate($this->createTcaSchemaFactory());
        return (string)$subject->build(
            $this->createQueryBuilder(),
            $tableName,
            'a',
            $this->createQuerySettings($languageAspect),
            function (string $alias): string {
                $this->visibilityAliases[] = $alias;
                return 'VISIBLE ' . $alias;
            }
        );
    }

    #[Test]
    public function buildReturnsEmptyStringForTableWithoutTca(): void
    {
        self::assertSame('', $this->build('tx_unknown', new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_ON)));
    }

    #[Test]
    public function buildReturnsEmptyStringForTableWithoutLanguageFields(): void
    {
        self::assertSame('', $this->build('tx_test_plain', new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_ON)));
    }

    public static function withoutOverlaysDataProvider(): array
    {
        return [
            'default language, overlays off' => [new LanguageAspect(0, 0, LanguageAspect::OVERLAYS_OFF), '`a`.`sys_language_uid` IN (0, -1)'],
            'default language, overlays on' => [new LanguageAspect(0, 0, LanguageAspect::OVERLAYS_ON), '`a`.`sys_language_uid` IN (0, -1)'],
            'default language, mixed' => [new LanguageAspect(0, 0, LanguageAspect::OVERLAYS_MIXED), '`a`.`sys_language_uid` IN (0, -1)'],
            'content id -1' => [new LanguageAspect(-1, -1, LanguageAspect::OVERLAYS_ON), '`a`.`sys_language_uid` IN (-1, -1)'],
            'language 1, overlays off' => [new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_OFF), '`a`.`sys_language_uid` IN (1, -1)'],
            'content id differs from language id' => [new LanguageAspect(2, 1, LanguageAspect::OVERLAYS_OFF), '`a`.`sys_language_uid` IN (1, -1)'],
        ];
    }

    #[DataProvider('withoutOverlaysDataProvider')]
    #[Test]
    public function buildSelectsContentLanguageAndAllLanguagesWithoutOverlays(LanguageAspect $languageAspect, string $expected): void
    {
        self::assertSame($expected, $this->build('tx_test_language', $languageAspect));
        self::assertSame([], $this->subSelects);
        self::assertSame([], $this->visibilityAliases);
    }

    public static function withOverlaysDataProvider(): array
    {
        $allLanguages = '`a`.`sys_language_uid` = -1';
        $translatedWithDefault = '((`a`.`sys_language_uid` = 1) AND (`a`.`l10n_parent` IN (SUBSELECT a_dl)))';
        return [
            'overlays on' => [
                LanguageAspect::OVERLAYS_ON,
                '((' . $allLanguages . ') OR (' . $translatedWithDefault . '))',
                ['a_dl'],
            ],
            // @todo 91903: the NOT IN part is redundant because l10n_parent is 0 on these rows.
            'overlays on with floating' => [
                LanguageAspect::OVERLAYS_ON_WITH_FLOATING,
                '((' . $allLanguages . ') OR (' . $translatedWithDefault . ') OR (((`a`.`sys_language_uid` = 1) AND (`a`.`l10n_parent` = 0) AND (`a`.`l10n_parent` NOT IN (SUBSELECT a_dl)))))',
                ['a_dl'],
            ],
            'mixed' => [
                LanguageAspect::OVERLAYS_MIXED,
                '((' . $allLanguages . ') OR (' . $translatedWithDefault . ') OR (((`a`.`sys_language_uid` = 0) AND (`a`.`uid` NOT IN (SUBSELECT a_to)))))',
                ['a_dl', 'a_to'],
            ],
        ];
    }

    #[DataProvider('withOverlaysDataProvider')]
    #[Test]
    public function buildSelectsTranslationsOfVisibleDefaultRecords(string $overlayType, string $expected, array $expectedVisibilityAliases): void
    {
        self::assertSame($expected, $this->build('tx_test_language', new LanguageAspect(1, 1, $overlayType)));
        self::assertSame($expectedVisibilityAliases, $this->visibilityAliases);
        self::assertSame(
            ['`a_dl`.`l10n_parent` = 0', '`a_dl`.`sys_language_uid` = 0', 'VISIBLE a_dl'],
            $this->subSelects[0]['where']
        );
        if ($overlayType === LanguageAspect::OVERLAYS_MIXED) {
            self::assertSame(
                ['`a_to`.`l10n_parent` > 0', '`a_to`.`sys_language_uid` = 1', 'VISIBLE a_to'],
                $this->subSelects[1]['where']
            );
        }
    }
}

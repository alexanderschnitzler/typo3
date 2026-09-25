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

namespace TYPO3\CMS\Extbase\Tests\Unit\Persistence\Generic\Mapper;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Frontend\NullFrontend;
use TYPO3\CMS\Core\DataHandling\TableColumnType;
use TYPO3\CMS\Core\Schema\Field\InlineFieldType;
use TYPO3\CMS\Core\Schema\Field\SelectRelationFieldType;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\ColumnMap;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\ColumnMap\Relation;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\ColumnMapFactory;
use TYPO3\CMS\Extbase\Reflection\ReflectionService;
use TYPO3\CMS\Extbase\Tests\Unit\Persistence\Generic\Mapper\Fixtures\ColumnMapFactoryEntityFixture;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Pins ColumnMapFactory's relation-key reading for the TCA keys without a typed
 * Schema accessor (MM_opposite_field, foreign_table_field, match fields), built
 * directly from field type objects (no TcaSchemaFactory/RelationMap wiring
 * needed for this, since the MM/foreign_field relation *type* decision is
 * driven by TCA configuration, not by RelationalFieldTypeInterface::getRelations()).
 *
 * RelationalFieldTypeInterface::getRelations() would be a typed alternative for
 * MM/MM_opposite_field/foreign_field, but it is populated from an external
 * RelationMap that the sibling functional ColumnMapFactoryTest always
 * constructs empty per field under test - switching ColumnMapFactory to read
 * it would silently turn every MM/1:n case in that frozen suite into "no
 * relation detected". Left as a raw config read for that reason.
 */
final class ColumnMapFactoryTest extends UnitTestCase
{
    private function createColumnMapFactory(): ColumnMapFactory
    {
        return new ColumnMapFactory(new ReflectionService(new NullFrontend('extbase'), 'ClassSchemata'));
    }

    #[Test]
    public function mmRelationUsesUidLocalAsParentKeyByDefault(): void
    {
        $columnConfiguration = [
            'type' => 'select',
            'foreign_table' => 'tx_myextension_righttable',
            'MM' => 'tx_myextension_mm',
        ];
        $field = new SelectRelationFieldType('has_and_belongs_to_many', $columnConfiguration, []);

        $columnMap = $this->createColumnMapFactory()->create($field, 'hasAndBelongsToMany', ColumnMapFactoryEntityFixture::class);

        self::assertEquals(
            new ColumnMap(
                columnName: 'has_and_belongs_to_many',
                type: TableColumnType::SELECT,
                typeOfRelation: Relation::HAS_AND_BELONGS_TO_MANY,
                childTableName: 'tx_myextension_righttable',
                relationTableName: 'tx_myextension_mm',
                parentKeyFieldName: 'uid_local',
                childKeyFieldName: 'uid_foreign',
                childSortByFieldName: 'sorting',
            ),
            $columnMap
        );
    }

    #[Test]
    public function mmOppositeFieldSwapsParentAndChildKeyAndSortColumn(): void
    {
        $columnConfiguration = [
            'type' => 'select',
            'foreign_table' => 'tx_myextension_lefttable',
            'MM' => 'tx_myextension_mm',
            'MM_opposite_field' => 'rights',
        ];
        $field = new SelectRelationFieldType('has_and_belongs_to_many', $columnConfiguration, []);

        $columnMap = $this->createColumnMapFactory()->create($field, 'hasAndBelongsToMany', ColumnMapFactoryEntityFixture::class);

        self::assertEquals(
            new ColumnMap(
                columnName: 'has_and_belongs_to_many',
                type: TableColumnType::SELECT,
                typeOfRelation: Relation::HAS_AND_BELONGS_TO_MANY,
                childTableName: 'tx_myextension_lefttable',
                relationTableName: 'tx_myextension_mm',
                parentKeyFieldName: 'uid_foreign',
                childKeyFieldName: 'uid_local',
                childSortByFieldName: 'sorting_foreign',
            ),
            $columnMap
        );
    }

    #[Test]
    public function mmMatchFieldsAreExposedOnRelationTableMatchFields(): void
    {
        $columnConfiguration = [
            'type' => 'select',
            'foreign_table' => 'tx_myextension_righttable',
            'MM' => 'tx_myextension_mm',
            'MM_match_fields' => ['fieldname' => 'foo_model'],
        ];
        $field = new SelectRelationFieldType('has_and_belongs_to_many', $columnConfiguration, []);

        $columnMap = $this->createColumnMapFactory()->create($field, 'hasAndBelongsToMany', ColumnMapFactoryEntityFixture::class);

        self::assertSame(['fieldname' => 'foo_model'], $columnMap->relationTableMatchFields);
    }

    #[Test]
    public function foreignFieldAndForeignTableFieldBuildOneToManyColumnMap(): void
    {
        $columnConfiguration = [
            'type' => 'inline',
            'foreign_table' => 'tx_myextension_bar',
            'foreign_field' => 'parentid',
            'foreign_table_field' => 'parenttable',
            'foreign_sortby' => 'sorting',
        ];
        $field = new InlineFieldType('has_many', $columnConfiguration, []);

        $columnMap = $this->createColumnMapFactory()->create($field, 'hasMany', ColumnMapFactoryEntityFixture::class);

        self::assertEquals(
            new ColumnMap(
                columnName: 'has_many',
                type: TableColumnType::INLINE,
                typeOfRelation: Relation::HAS_MANY,
                childTableName: 'tx_myextension_bar',
                parentKeyFieldName: 'parentid',
                parentTableFieldName: 'parenttable',
                childSortByFieldName: 'sorting',
            ),
            $columnMap
        );
    }

    #[Test]
    public function foreignMatchFieldsAreExposedOnRelationTableMatchFieldsForOneToMany(): void
    {
        $columnConfiguration = [
            'type' => 'inline',
            'foreign_table' => 'tx_myextension_bar',
            'foreign_field' => 'parentid',
            'foreign_match_fields' => ['fieldname' => 'foo_model'],
        ];
        $field = new InlineFieldType('has_many', $columnConfiguration, []);

        $columnMap = $this->createColumnMapFactory()->create($field, 'hasMany', ColumnMapFactoryEntityFixture::class);

        self::assertSame(['fieldname' => 'foo_model'], $columnMap->relationTableMatchFields);
    }
}

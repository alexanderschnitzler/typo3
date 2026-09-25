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

namespace TYPO3\CMS\Extbase\Tests\Unit\Persistence\Generic;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\DomainObject\AbstractDomainObject;
use TYPO3\CMS\Extbase\Persistence\Generic\Backend;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMap;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapFactory;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceCorrelationScope;
use TYPO3\CMS\Extbase\Persistence\Generic\Session;
use TYPO3\CMS\Extbase\Persistence\Generic\Storage\BackendInterface as StorageBackendInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\WriteTarget;
use TYPO3\CMS\Extbase\Reflection\ReflectionService;
use TYPO3\CMS\Extbase\Tests\Unit\Persistence\Fixture\Model\Entity2;
use TYPO3\TestingFramework\Core\AccessibleObjectInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Pins Backend::resolveWriteUid()'s matrix: which uid each write operation
 * targets today, including the inconsistencies between operations.
 */
#[AllowMockObjectsWithoutExpectations]
final class BackendTest extends UnitTestCase
{
    private function createBackend(?DataMap $dataMap = null): Backend&AccessibleObjectInterface
    {
        $dataMapFactory = self::createStub(DataMapFactory::class);
        $dataMapFactory->method('buildDataMap')->willReturn($dataMap ?? new DataMap(className: Entity2::class, tableName: 'tx_test_entity'));

        return $this->getAccessibleMock(
            Backend::class,
            null,
            [
                self::createStub(ConfigurationManagerInterface::class),
                self::createStub(Session::class),
                self::createStub(ReflectionService::class),
                self::createStub(StorageBackendInterface::class),
                $dataMapFactory,
                self::createStub(EventDispatcherInterface::class),
                self::createStub(ReferenceIndex::class),
                self::createStub(TcaSchemaFactory::class),
                new PersistenceCorrelationScope(new Random()),
            ]
        );
    }

    private function createObject(int $uid, ?int $localizedUid): Entity2
    {
        $object = new Entity2();
        $object->_setProperty(AbstractDomainObject::PROPERTY_UID, $uid);
        $object->_setProperty(AbstractDomainObject::PROPERTY_LOCALIZED_UID, $localizedUid);
        return $object;
    }

    /**
     * The resolver's matrix: each target names the former inline decision it replaced.
     * `uid` is the object's own uid, `localizedUid` its `_localizedUid` (null for
     * a default-language/new object). `languageAware` only matters for WriteTarget::Update.
     */
    public static function writeUidMatrixDataProvider(): iterable
    {
        // One-to-many insert (insertObject() FK on the new child's own row): always own uid.
        yield 'one-to-many insert, default language' => [WriteTarget::OneToManyInsert, 5, null, false, 5];
        yield 'one-to-many insert, translated (localizedUid ignored)' => [WriteTarget::OneToManyInsert, 5, 11, false, 5];

        // One-to-many attach (attachObjectToParentObjectRelationHasMany()): localizedUid ?: uid.
        yield 'one-to-many attach, default language' => [WriteTarget::OneToManyAttach, 5, null, false, 5];
        yield 'one-to-many attach, translated' => [WriteTarget::OneToManyAttach, 5, 11, false, 11];
        yield 'one-to-many attach, localizedUid=0 falls back to uid (Elvis quirk)' => [WriteTarget::OneToManyAttach, 5, 0, false, 5];

        // MM insert (insertRelationInRelationtable()): localizedUid if not null, else uid.
        yield 'mm insert, default language' => [WriteTarget::MmInsert, 5, null, false, 5];
        yield 'mm insert, translated' => [WriteTarget::MmInsert, 5, 11, false, 11];
        yield 'mm insert, localizedUid=0 is kept (inconsistent with attach)' => [WriteTarget::MmInsert, 5, 0, false, 0];

        // MM update/delete-all/delete-one: always own uid, regardless of localizedUid.
        yield 'mm update, default language' => [WriteTarget::MmUpdate, 5, null, false, 5];
        yield 'mm update, translated (localizedUid ignored)' => [WriteTarget::MmUpdate, 5, 11, false, 5];
        yield 'mm delete-all, translated (localizedUid ignored)' => [WriteTarget::MmDeleteAll, 5, 11, false, 5];
        yield 'mm delete-one, translated (localizedUid ignored)' => [WriteTarget::MmDeleteOne, 5, 11, false, 5];

        // Update (updateObject()): localizedUid only if the table is language-aware AND it is not null.
        yield 'update, language-aware table, translated' => [WriteTarget::Update, 5, 11, true, 11];
        yield 'update, language-aware table, default language' => [WriteTarget::Update, 5, null, true, 5];
        yield 'update, non-language-aware table, translated (localizedUid ignored)' => [WriteTarget::Update, 5, 11, false, 5];

        // Delete (removeEntity()): always own uid - the known hole, a translated delete
        // still targets the default row, is left unchanged by design (@todo Forge #<T13>).
        yield 'delete, default language' => [WriteTarget::Delete, 5, null, false, 5];
        yield 'delete, translated (known hole: localizedUid ignored)' => [WriteTarget::Delete, 5, 11, false, 5];
    }

    #[DataProvider('writeUidMatrixDataProvider')]
    #[Test]
    public function resolveWriteUidReturnsExpectedUid(WriteTarget $target, int $uid, ?int $localizedUid, bool $languageAwareTable, int $expected): void
    {
        $dataMap = $languageAwareTable
            ? new DataMap(className: Entity2::class, tableName: 'tx_test_entity', languageIdColumnName: 'sys_language_uid')
            : null;
        $backend = $this->createBackend($dataMap);
        $object = $this->createObject($uid, $localizedUid);

        self::assertSame($expected, $backend->_call('resolveWriteUid', $object, $target));
    }
}

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
use TYPO3\CMS\Extbase\Persistence\Generic\Exception\InconsistentQuerySettingsException;
use TYPO3\CMS\Extbase\Persistence\Generic\Storage\Predicate\StoragePagePredicate;

final class StoragePagePredicateTest extends AbstractPredicateTestCase
{
    public static function buildDataProvider(): array
    {
        return [
            'table without TCA' => ['tx_unknown', 'tx_unknown', [1], ''],
            'pages only, one storage page' => ['tx_test_rootlevel_page', 'tx_test_rootlevel_page', [20], '`tx_test_rootlevel_page`.`pid` = 20'],
            'pages only, two storage pages, alias' => ['tx_test_rootlevel_page', 'tx_test_rootlevel_page0', [20, '21'], '`tx_test_rootlevel_page0`.`pid` IN (20, 21)'],
            'root level only ignores storage pages' => ['tx_test_rootlevel_root', 'tx_test_rootlevel_root', [20, 21], '`tx_test_rootlevel_root`.`pid` = 0'],
            'both, no storage page' => ['tx_test_rootlevel_both', 'tx_test_rootlevel_both', [], '`tx_test_rootlevel_both`.`pid` = 0'],
            'both, storage pages plus root' => ['tx_test_rootlevel_both', 'tx_test_rootlevel_both', [20], '`tx_test_rootlevel_both`.`pid` IN (20, 0)'],
            'invalid rootLevel value' => ['tx_test_rootlevel_invalid', 'tx_test_rootlevel_invalid', [20], ''],
            'no rootLevel setting behaves like pages only' => ['tx_test_plain', 'tx_test_plain', [20], '`tx_test_plain`.`pid` = 20'],
        ];
    }

    #[DataProvider('buildDataProvider')]
    #[Test]
    public function buildReturnsPidCondition(string $tableName, string $tableAlias, array $storagePageIds, string $expected): void
    {
        $subject = new StoragePagePredicate($this->createTcaSchemaFactory());
        self::assertSame($expected, $subject->build($this->createExpressionBuilder(), $tableName, $tableAlias, $storagePageIds));
    }

    #[Test]
    public function buildThrowsWithoutStoragePagesForPageTable(): void
    {
        $subject = new StoragePagePredicate($this->createTcaSchemaFactory());
        $this->expectException(InconsistentQuerySettingsException::class);
        $this->expectExceptionCode(1365779762);
        $subject->build($this->createExpressionBuilder(), 'tx_test_rootlevel_page', 'tx_test_rootlevel_page', []);
    }
}

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
use TYPO3\CMS\Extbase\Persistence\Generic\Storage\Predicate\WorkspacePredicate;

final class WorkspacePredicateTest extends AbstractPredicateTestCase
{
    public static function buildDataProvider(): array
    {
        return [
            'table without TCA' => ['tx_unknown', 'tx_unknown', ''],
            'table without versioning' => ['tx_test_plain', 'tx_test_plain', ''],
            'workspace-aware table' => ['tx_test_workspace', 'tx_test_workspace', '`tx_test_workspace`.`t3ver_oid` = 0'],
            'workspace-aware table with alias' => ['tx_test_workspace', 'tx_test_workspace1', '`tx_test_workspace1`.`t3ver_oid` = 0'],
        ];
    }

    #[DataProvider('buildDataProvider')]
    #[Test]
    public function buildReturnsLiveAndNewRecordCondition(string $tableName, string $tableAlias, string $expected): void
    {
        $subject = new WorkspacePredicate($this->createTcaSchemaFactory());
        self::assertSame($expected, $subject->build($this->createExpressionBuilder(), $tableName, $tableAlias));
    }
}

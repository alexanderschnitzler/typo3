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

use PHPUnit\Framework\MockObject\Stub;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Schema\FieldTypeFactory;
use TYPO3\CMS\Core\Schema\RelationMapBuilder;
use TYPO3\CMS\Core\Schema\TcaSchemaBuilder;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Shared setup for the predicate builder tests: a TcaSchemaFactory loaded from a small TCA array and
 * an ExpressionBuilder over a connection stub that quotes identifiers MySQL-style.
 */
abstract class AbstractPredicateTestCase extends UnitTestCase
{
    protected const TCA = [
        'tx_test_plain' => [
            'ctrl' => ['title' => 'plain'],
            'columns' => [],
        ],
        'tx_test_rootlevel_page' => [
            'ctrl' => ['title' => 'pages only', 'rootLevel' => 0],
            'columns' => [],
        ],
        'tx_test_rootlevel_root' => [
            'ctrl' => ['title' => 'root only', 'rootLevel' => 1],
            'columns' => [],
        ],
        'tx_test_rootlevel_both' => [
            'ctrl' => ['title' => 'both', 'rootLevel' => -1],
            'columns' => [],
        ],
        'tx_test_rootlevel_invalid' => [
            'ctrl' => ['title' => 'invalid root level', 'rootLevel' => 2],
            'columns' => [],
        ],
        'tx_test_workspace' => [
            'ctrl' => ['title' => 'workspace', 'versioningWS' => true],
            'columns' => [],
        ],
        'tx_test_language' => [
            'ctrl' => [
                'title' => 'language',
                'languageField' => 'sys_language_uid',
                'transOrigPointerField' => 'l10n_parent',
                'delete' => 'deleted',
                'enablecolumns' => ['disabled' => 'hidden'],
            ],
            'columns' => [
                'sys_language_uid' => ['config' => ['type' => 'language']],
                'l10n_parent' => ['config' => ['type' => 'select', 'renderType' => 'selectSingle', 'foreign_table' => 'tx_test_language', 'items' => []]],
                'hidden' => ['config' => ['type' => 'check']],
            ],
        ],
        'tx_test_deleted' => [
            'ctrl' => ['title' => 'soft delete', 'delete' => 'deleted', 'enablecolumns' => ['disabled' => 'hidden']],
            'columns' => [
                'hidden' => ['config' => ['type' => 'check']],
            ],
        ],
    ];

    protected function createTcaSchemaFactory(): TcaSchemaFactory
    {
        $cache = self::createStub(PhpFrontend::class);
        $cache->method('has')->willReturn(false);
        $tcaSchemaFactory = new TcaSchemaFactory(
            new TcaSchemaBuilder(
                new RelationMapBuilder(self::createStub(FlexFormTools::class)),
                new FieldTypeFactory(),
            ),
            '',
            $cache
        );
        $tcaSchemaFactory->load(self::TCA, true);
        return $tcaSchemaFactory;
    }

    protected function createConnectionStub(): Connection&Stub
    {
        $connection = self::createStub(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn(string $identifier): string => implode('.', array_map(static fn(string $part): string => '`' . $part . '`', explode('.', $identifier)))
        );
        return $connection;
    }

    protected function createExpressionBuilder(?Connection $connection = null): ExpressionBuilder
    {
        return new ExpressionBuilder($connection ?? $this->createConnectionStub(), self::createStub(ContainerInterface::class));
    }
}

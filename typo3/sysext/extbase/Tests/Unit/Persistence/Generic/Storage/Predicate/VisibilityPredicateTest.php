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
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Exception\InconsistentQuerySettingsException;
use TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Storage\Predicate\VisibilityPredicate;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;

final class VisibilityPredicateTest extends AbstractPredicateTestCase
{
    protected bool $resetSingletonInstances = true;

    private TcaSchemaFactory $tcaSchemaFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['EXEC_TIME'] = 1700000040;
        $this->tcaSchemaFactory = $this->createTcaSchemaFactory();
    }

    private function createQuerySettings(bool $ignoreEnableFields, array $enableFieldsToBeIgnored, bool $includeDeleted, bool $frontend): Typo3QuerySettings
    {
        $querySettings = self::createStub(Typo3QuerySettings::class);
        $querySettings->method('isFrontendContext')->willReturn($frontend);
        $querySettings->method('getIgnoreEnableFields')->willReturn($ignoreEnableFields);
        $querySettings->method('getEnableFieldsToBeIgnored')->willReturn($enableFieldsToBeIgnored);
        $querySettings->method('getIncludeDeleted')->willReturn($includeDeleted);
        return $querySettings;
    }

    private function createConnectionPool(): ConnectionPool
    {
        $connection = $this->createConnectionStub();
        $connection->method('getExpressionBuilder')->willReturn($this->createExpressionBuilder($connection));
        $connectionPool = self::createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);
        return $connectionPool;
    }

    #[Test]
    public function buildReturnsEmptyStringForTableWithoutTca(): void
    {
        $pageRepository = $this->createMock(PageRepository::class);
        $pageRepository->expects($this->never())->method('getDefaultConstraints');
        $subject = new VisibilityPredicate($this->tcaSchemaFactory, $pageRepository, $this->createConnectionPool());
        self::assertSame('', $subject->build($this->createQuerySettings(false, [], false, true), 'tx_unknown', 'tx_unknown'));
        self::assertSame('', $subject->build($this->createQuerySettings(false, [], false, false), 'tx_unknown', 'tx_unknown'));
    }

    public static function frontendDataProvider(): array
    {
        return [
            'enable fields respected' => [false, [], false, [], ['deleted' => 'a.deleted = 0', 'disabled' => 'a.hidden = 0'], 'a.deleted = 0 AND a.hidden = 0'],
            'enable fields respected, no constraints' => [false, [], false, [], [], ''],
            'partial ignore list is passed on' => [true, ['disabled'], false, ['disabled'], ['deleted' => 'a.deleted = 0'], 'a.deleted = 0'],
            'partial ignore list, no constraints' => [true, ['fe_group'], false, ['fe_group'], [], ''],
        ];
    }

    #[DataProvider('frontendDataProvider')]
    #[Test]
    public function frontendUsesDefaultConstraintsOfPageRepository(
        bool $ignoreEnableFields,
        array $enableFieldsToBeIgnored,
        bool $includeDeleted,
        array $expectedIgnoreList,
        array $constraints,
        string $expected,
    ): void {
        $pageRepository = $this->createMock(PageRepository::class);
        $pageRepository->expects($this->once())->method('getDefaultConstraints')
            ->with('tx_test_deleted', $expectedIgnoreList, 'a')
            ->willReturn($constraints);
        $subject = new VisibilityPredicate($this->tcaSchemaFactory, $pageRepository, $this->createConnectionPool());
        self::assertSame($expected, $subject->build($this->createQuerySettings($ignoreEnableFields, $enableFieldsToBeIgnored, $includeDeleted, true), 'tx_test_deleted', 'a'));
    }

    public static function frontendWithoutPageRepositoryDataProvider(): array
    {
        return [
            // An empty ignore list means "ignore all": only the deleted flag remains, no workspace or group condition.
            'ignore all, soft delete table' => [true, [], false, 'tx_test_deleted', 'a.deleted=0'],
            'ignore all, table without soft delete' => [true, [], false, 'tx_test_plain', ''],
            'ignore all and include deleted' => [true, [], true, 'tx_test_deleted', ''],
            'partial list and include deleted' => [true, ['disabled'], true, 'tx_test_deleted', ''],
        ];
    }

    #[DataProvider('frontendWithoutPageRepositoryDataProvider')]
    #[Test]
    public function frontendIgnoringAllEnableFieldsOnlyChecksDeletedFlag(
        bool $ignoreEnableFields,
        array $enableFieldsToBeIgnored,
        bool $includeDeleted,
        string $tableName,
        string $expected,
    ): void {
        $pageRepository = $this->createMock(PageRepository::class);
        $pageRepository->expects($this->never())->method('getDefaultConstraints');
        $subject = new VisibilityPredicate($this->tcaSchemaFactory, $pageRepository, $this->createConnectionPool());
        self::assertSame($expected, $subject->build($this->createQuerySettings($ignoreEnableFields, $enableFieldsToBeIgnored, $includeDeleted, true), $tableName, 'a'));
    }

    #[Test]
    public function otherQuerySettingsImplementationsFallBackToGlobalRequest(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = new ServerRequest()->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $querySettings = self::createStub(QuerySettingsInterface::class);
        $pageRepository = $this->createMock(PageRepository::class);
        $pageRepository->expects($this->once())->method('getDefaultConstraints')->with('tx_test_deleted', [], 'a')->willReturn(['deleted' => 'a.deleted = 0']);
        $subject = new VisibilityPredicate($this->tcaSchemaFactory, $pageRepository, $this->createConnectionPool());
        self::assertSame('a.deleted = 0', $subject->build($querySettings, 'tx_test_deleted', 'a'));
        unset($GLOBALS['TYPO3_REQUEST']);
    }

    #[Test]
    public function frontendThrowsWhenIncludingDeletedWithEnableFields(): void
    {
        $subject = new VisibilityPredicate($this->tcaSchemaFactory, self::createStub(PageRepository::class), $this->createConnectionPool());
        $this->expectException(InconsistentQuerySettingsException::class);
        $this->expectExceptionCode(1460975922);
        $subject->build($this->createQuerySettings(false, [], true, true), 'tx_test_deleted', 'a');
    }

    public static function backendDataProvider(): array
    {
        return [
            'enable fields, same alias' => [false, false, 0, 'tx_test_deleted', '`tx_test_deleted`.`hidden` = 0 AND tx_test_deleted.deleted=0'],
            'enable fields, other alias' => [false, false, 0, 'a', '`a`.`hidden` = 0 AND a.deleted=0'],
            'enable fields ignored' => [true, false, 0, 'a', 'a.deleted=0'],
            'enable fields ignored, deleted included' => [true, true, 0, 'a', ''],
            'deleted included' => [false, true, 0, 'a', '`a`.`hidden` = 0'],
            // In a workspace the enable fields are checked by the overlay, not in SQL.
            'workspace, enable fields not in SQL' => [false, false, 1, 'a', 'a.deleted=0'],
        ];
    }

    #[DataProvider('backendDataProvider')]
    #[Test]
    public function backendUsesBackendEnableFieldsAndDeletedFlag(
        bool $ignoreEnableFields,
        bool $includeDeleted,
        int $workspaceId,
        string $tableAlias,
        string $expected,
    ): void {
        $context = new Context();
        $context->setAspect('workspace', new WorkspaceAspect($workspaceId));
        GeneralUtility::setSingletonInstance(Context::class, $context);
        if (!$ignoreEnableFields && $workspaceId === 0) {
            // BackendUtility::BEenableFields() resolves both services through makeInstance().
            GeneralUtility::addInstance(TcaSchemaFactory::class, $this->tcaSchemaFactory);
            GeneralUtility::addInstance(ConnectionPool::class, $this->createConnectionPool());
        }
        $subject = new VisibilityPredicate($this->tcaSchemaFactory, self::createStub(PageRepository::class), $this->createConnectionPool());
        self::assertSame($expected, $subject->build($this->createQuerySettings($ignoreEnableFields, [], $includeDeleted, false), 'tx_test_deleted', $tableAlias));
    }

    #[Test]
    public function backendReturnsEmptyStringForTableWithoutEnableFieldsAndSoftDelete(): void
    {
        GeneralUtility::setSingletonInstance(Context::class, new Context());
        GeneralUtility::addInstance(TcaSchemaFactory::class, $this->tcaSchemaFactory);
        GeneralUtility::addInstance(ConnectionPool::class, $this->createConnectionPool());
        $subject = new VisibilityPredicate($this->tcaSchemaFactory, self::createStub(PageRepository::class), $this->createConnectionPool());
        self::assertSame('', $subject->build($this->createQuerySettings(false, [], false, false), 'tx_test_plain', 'a'));
    }
}

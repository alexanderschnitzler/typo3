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

namespace TYPO3\CMS\Extbase\Tests\Functional\Persistence;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Storage\Typo3DbQueryParser;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3Tests\BlogExample\Domain\Repository\BlogRepository;

/**
 * Documents when the frontend/backend decision for enable fields is taken: frontend queries restrict
 * workspaces through PageRepository::getDefaultConstraints() (t3ver_state), backend queries use
 * BackendUtility::BEenableFields() without a workspace condition.
 */
final class QuerySettingsApplicationTypeTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['typo3/sysext/extbase/Tests/Functional/Fixtures/Extensions/blog_example'];

    protected function setUp(): void
    {
        parent::setUp();
        // The configuration manager gets its own request, so only the global request decides frontend/backend.
        $this->get(ConfigurationManager::class)->setConfiguration(['extensionName' => 'blog_example', 'pluginName' => 'test']);
        $this->get(ConfigurationManagerInterface::class)->setRequest(
            new ServerRequest()->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    private static function setRequest(?int $applicationType): void
    {
        if ($applicationType === null) {
            unset($GLOBALS['TYPO3_REQUEST']);
            return;
        }
        $GLOBALS['TYPO3_REQUEST'] = new ServerRequest()->withAttribute('applicationType', $applicationType);
    }

    private function createBlogQuery(): QueryInterface
    {
        $query = $this->get(BlogRepository::class)->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);
        $query->getQuerySettings()->setRespectSysLanguage(false);
        return $query;
    }

    private function whereClause(QueryInterface $query): string
    {
        return (string)$this->get(Typo3DbQueryParser::class)->convertQueryToDoctrineQueryBuilder($query)->getWhere();
    }

    #[Test]
    public function frontendQueryUsesFrontendRestrictions(): void
    {
        self::setRequest(SystemEnvironmentBuilder::REQUESTTYPE_FE);
        self::assertStringContainsString('t3ver_state', $this->whereClause($this->createBlogQuery()));
    }

    #[Test]
    public function queryWithoutRequestUsesBackendRestrictions(): void
    {
        self::setRequest(null);
        self::assertStringNotContainsString('t3ver_state', $this->whereClause($this->createBlogQuery()));
    }

    /**
     * The decision is taken when the query settings are created (Typo3QuerySettings::isFrontendContext()),
     * not when the query is executed: a query created during a frontend request keeps frontend restrictions
     * even if the global request changes before it runs. Before, the request at execution time decided.
     */
    #[Test]
    public function queryCreatedInFrontendAndExecutedInBackendKeepsFrontendRestrictions(): void
    {
        self::setRequest(SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $query = $this->createBlogQuery();
        self::setRequest(SystemEnvironmentBuilder::REQUESTTYPE_BE);
        self::assertStringContainsString('t3ver_state', $this->whereClause($query));
    }

    #[Test]
    public function queryCreatedWithoutRequestAndExecutedInFrontendKeepsBackendRestrictions(): void
    {
        self::setRequest(null);
        $query = $this->createBlogQuery();
        self::setRequest(SystemEnvironmentBuilder::REQUESTTYPE_FE);
        self::assertStringNotContainsString('t3ver_state', $this->whereClause($query));
    }

    #[Test]
    public function frontendContextCanBeSetExplicitly(): void
    {
        self::setRequest(null);
        $query = $this->createBlogQuery();
        $querySettings = $query->getQuerySettings();
        self::assertInstanceOf(Typo3QuerySettings::class, $querySettings);
        $querySettings->setFrontendContext(true);
        self::assertStringContainsString('t3ver_state', $this->whereClause($query));
    }
}

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

namespace TYPO3\CMS\Extensionmanager\Tests\Unit\Utility;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Service\OpcodeCacheService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\CMS\Extensionmanager\Exception\ExtensionManagerException;
use TYPO3\CMS\Extensionmanager\Utility\EmConfUtility;
use TYPO3\CMS\Extensionmanager\Utility\FileHandlingUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FileHandlingUtilityTest extends UnitTestCase
{
    /**
     * @var array List of created fake extensions
     */
    private array $fakedExtensions = [];

    private string $testRoot;

    protected function setUp(): void
    {
        $this->testRoot = Environment::getVarPath() . '/tests/';
        GeneralUtility::mkdir_deep($this->testRoot);
        $this->testFilesToDelete[] = $this->testRoot;
        parent::setUp();
    }

    /**
     * Creates a fake extension inside typo3temp/. No configuration is created,
     * just the folder
     *
     * @return string The extension key
     */
    private function createFakeExtension(): string
    {
        $extKey = strtolower(StringUtility::getUniqueId('testing'));
        $absExtPath = $this->testRoot . 'ext-' . $extKey . '/';
        GeneralUtility::mkdir_deep($absExtPath);
        $this->fakedExtensions[$extKey] = [
            'packagePath' => $absExtPath,
        ];
        return $extKey;
    }

    /**
     * @test
     */
    public function makeAndClearExtensionDirRemovesExtensionDirIfAlreadyExists(): void
    {
        $extKey = $this->createFakeExtension();
        $path = $this->fakedExtensions[$extKey]['packagePath'];
        $subject = $this->getAccessibleMock(FileHandlingUtility::class, ['removeDirectory', 'addDirectory', 'getExtensionDir'], [], '', false);
        $subject->expects(self::once())->method('removeDirectory')->with($path);
        $subject->method('getExtensionDir')->willReturn($path);
        $subject->_call('makeAndClearExtensionDir', $extKey);
    }

    /**
     * @test
     */
    public function makeAndClearExtensionDirAddsDir(): void
    {
        $extKey = $this->createFakeExtension();
        $subject = $this->getAccessibleMock(FileHandlingUtility::class, ['removeDirectory', 'addDirectory', 'getExtensionDir'], [], '', false);
        $subject->expects(self::once())->method('addDirectory')->with($this->testRoot . 'ext-' . $extKey . '/');
        $subject->method('getExtensionDir')->willReturn($this->testRoot . 'ext-' . $extKey . '/');
        $subject->_call('makeAndClearExtensionDir', $extKey);
    }

    /**
     * @test
     */
    public function makeAndClearExtensionDirThrowsExceptionOnInvalidPath(): void
    {
        $this->expectException(ExtensionManagerException::class);
        $this->expectExceptionCode(1337280417);
        $GLOBALS['BE_USER'] = $this->createMock(BackendUserAuthentication::class);
        $languageServiceMock = $this->createMock(LanguageService::class);
        $languageServiceFactoryMock = $this->createMock(LanguageServiceFactory::class);
        $languageServiceFactoryMock->method('createFromUserPreferences')->with(self::anything())->willReturn($languageServiceMock);
        $subject = $this->getAccessibleMock(
            FileHandlingUtility::class,
            ['removeDirectory', 'addDirectory'],
            [$this->createMock(PackageManager::class), $this->createMock(EmConfUtility::class), $this->createMock(OpcodeCacheService::class), $languageServiceFactoryMock]
        );
        $subject->_call('makeAndClearExtensionDir', 'testing123', 'fakepath');
    }

    /**
     * @test
     */
    public function addDirectoryAddsDirectory(): void
    {
        $extDirPath = $this->testRoot . StringUtility::getUniqueId('test-extensions-');
        $subject = $this->getAccessibleMock(FileHandlingUtility::class, null, [], '', false);
        $subject->_call('addDirectory', $extDirPath);
        self::assertDirectoryExists($extDirPath);
    }

    /**
     * @test
     */
    public function removeDirectoryRemovesDirectory(): void
    {
        $extDirPath = $this->testRoot . StringUtility::getUniqueId('test-extensions-');
        @mkdir($extDirPath);
        $subject = $this->getAccessibleMock(FileHandlingUtility::class, null, [], '', false);
        $subject->_call('removeDirectory', $extDirPath);
        self::assertDirectoryDoesNotExist($extDirPath);
    }

    /**
     * @test
     */
    public function removeDirectoryRemovesSymlink(): void
    {
        $absoluteSymlinkPath = $this->testRoot . StringUtility::getUniqueId('test_symlink_');
        $absoluteFilePath = $this->testRoot . StringUtility::getUniqueId('test_file_');
        touch($absoluteFilePath);
        symlink($absoluteFilePath, $absoluteSymlinkPath);
        $subject = new FileHandlingUtility(
            $this->createMock(PackageManager::class),
            $this->createMock(EmConfUtility::class),
            $this->createMock(OpcodeCacheService::class),
            $this->createMock(LanguageServiceFactory::class)
        );
        $subject->removeDirectory($absoluteSymlinkPath);
        self::assertFalse(is_link($absoluteSymlinkPath));
    }

    /**
     * @test
     */
    public function removeDirectoryDoesNotRemoveContentOfSymlinkedTargetDirectory(): void
    {
        $absoluteSymlinkPath = $this->testRoot . StringUtility::getUniqueId('test_symlink_');
        $absoluteDirectoryPath = $this->testRoot . StringUtility::getUniqueId('test_dir_') . '/';
        $relativeFilePath = StringUtility::getUniqueId('test_file_');
        GeneralUtility::mkdir_deep($absoluteDirectoryPath);
        touch($absoluteDirectoryPath . $relativeFilePath);
        symlink($absoluteDirectoryPath, $absoluteSymlinkPath);
        $subject = new FileHandlingUtility(
            $this->createMock(PackageManager::class),
            $this->createMock(EmConfUtility::class),
            $this->createMock(OpcodeCacheService::class),
            $this->createMock(LanguageServiceFactory::class)
        );
        $subject->removeDirectory($absoluteSymlinkPath);
        self::assertTrue(is_file($absoluteDirectoryPath . $relativeFilePath));
    }

    /**
     * @test
     */
    public function unpackExtensionFromExtensionDataArrayCreatesTheExtensionDirectory(): void
    {
        $extensionKey = 'test';
        $subject = $this->getAccessibleMock(
            FileHandlingUtility::class,
            [
                'makeAndClearExtensionDir',
                'writeEmConfToFile',
                'extractDirectoriesFromExtensionData',
                'createDirectoriesForExtensionFiles',
                'writeExtensionFiles',
                'reloadPackageInformation',
            ],
            [],
            '',
            false
        );
        $subject->expects(self::once())->method('extractDirectoriesFromExtensionData')->willReturn([]);
        $subject->expects(self::once())->method('makeAndClearExtensionDir')->with($extensionKey)->willReturn('my_path');
        $subject->unpackExtensionFromExtensionDataArray($extensionKey, []);
    }

    /**
     * @test
     */
    public function unpackExtensionFromExtensionDataArrayStripsDirectoriesFromFilesArray(): void
    {
        $extensionData = [
            'extKey' => 'test',
            'FILES' => [
                'ChangeLog' => [
                    'name' => 'ChangeLog',
                    'size' => 4559,
                    'mtime' => 1219448527,
                    'is_executable' => false,
                    'content' => 'some content to write',
                ],
                'doc/' => [
                    'name' => 'doc/',
                    'size' => 0,
                    'mtime' => 1219448527,
                    'is_executable' => false,
                    'content' => '',
                ],
                'doc/ChangeLog' => [
                    'name' => 'ChangeLog',
                    'size' => 4559,
                    'mtime' => 1219448527,
                    'is_executable' => false,
                    'content' => 'some content to write',
                ],
            ],
        ];
        $cleanedFiles = [
            'ChangeLog' => [
                'name' => 'ChangeLog',
                'size' => 4559,
                'mtime' => 1219448527,
                'is_executable' => false,
                'content' => 'some content to write',
            ],
            'doc/ChangeLog' => [
                'name' => 'ChangeLog',
                'size' => 4559,
                'mtime' => 1219448527,
                'is_executable' => false,
                'content' => 'some content to write',
            ],
        ];
        $directories = [
            'doc/',
            'mod/doc/',
        ];

        $subject = $this->getAccessibleMock(
            FileHandlingUtility::class,
            [
                'makeAndClearExtensionDir',
                'writeEmConfToFile',
                'extractDirectoriesFromExtensionData',
                'createDirectoriesForExtensionFiles',
                'writeExtensionFiles',
                'reloadPackageInformation',
            ],
            [],
            '',
            false
        );
        $subject->expects(self::once())->method('extractDirectoriesFromExtensionData')->willReturn($directories);
        $subject->expects(self::once())->method('createDirectoriesForExtensionFiles')->with($directories);
        $subject->expects(self::once())->method('makeAndClearExtensionDir')->with($extensionData['extKey'])->willReturn('my_path');
        $subject->expects(self::once())->method('writeExtensionFiles')->with($cleanedFiles);
        $subject->expects(self::once())->method('reloadPackageInformation')->with('test');
        $subject->unpackExtensionFromExtensionDataArray('test', $extensionData);
    }

    /**
     * @test
     */
    public function writeExtensionFilesWritesFiles(): void
    {
        $files = [
            'ChangeLog' => [
                'name' => 'ChangeLog',
                'size' => 4559,
                'mtime' => 1219448527,
                'is_executable' => false,
                'content' => 'some content to write',
            ],
            'README' => [
                'name' => 'README',
                'size' => 4566,
                'mtime' => 1219448533,
                'is_executable' => false,
                'content' => 'FEEL FREE TO ADD SOME DOCUMENTATION HERE',
            ],
        ];
        $rootPath = $this->fakedExtensions[$this->createFakeExtension()]['packagePath'];
        $subject = $this->getAccessibleMock(FileHandlingUtility::class, ['makeAndClearExtensionDir'], [], '', false);
        $subject->_call('writeExtensionFiles', $files, $rootPath);
        self::assertFileExists($rootPath . 'ChangeLog');
    }

    /**
     * @test
     */
    public function extractDirectoriesFromExtensionDataExtractsDirectories(): void
    {
        $files = [
            'ChangeLog' => [
                'name' => 'ChangeLog',
                'size' => 4559,
                'mtime' => 1219448527,
                'is_executable' => false,
                'content' => 'some content to write',
            ],
            'doc/' => [
                'name' => 'doc/',
                'size' => 0,
                'mtime' => 1219448527,
                'is_executable' => false,
                'content' => '',
            ],
            'doc/ChangeLog' => [
                'name' => 'ChangeLog',
                'size' => 4559,
                'mtime' => 1219448527,
                'is_executable' => false,
                'content' => 'some content to write',
            ],
            'doc/README' => [
                'name' => 'README',
                'size' => 4566,
                'mtime' => 1219448533,
                'is_executable' => false,
                'content' => 'FEEL FREE TO ADD SOME DOCUMENTATION HERE',
            ],
            'mod/doc/README' => [
                'name' => 'README',
                'size' => 4566,
                'mtime' => 1219448533,
                'is_executable' => false,
                'content' => 'FEEL FREE TO ADD SOME DOCUMENTATION HERE',
            ],
        ];
        $subject = $this->getAccessibleMock(FileHandlingUtility::class, ['makeAndClearExtensionDir'], [], '', false);
        $extractedDirectories = $subject->_call('extractDirectoriesFromExtensionData', $files);
        $expected = [
            'doc/',
            'mod/doc/',
        ];
        self::assertSame($expected, array_values($extractedDirectories));
    }

    /**
     * @test
     */
    public function createDirectoriesForExtensionFilesCreatesDirectories(): void
    {
        $rootPath = $this->fakedExtensions[$this->createFakeExtension()]['packagePath'];
        $directories = [
            'doc/',
            'mod/doc/',
        ];
        $subject = $this->getAccessibleMock(FileHandlingUtility::class, ['makeAndClearExtensionDir'], [], '', false);
        self::assertDirectoryDoesNotExist($rootPath . 'doc/');
        self::assertDirectoryDoesNotExist($rootPath . 'mod/doc/');
        $subject->_call('createDirectoriesForExtensionFiles', $directories, $rootPath);
        self::assertDirectoryExists($rootPath . 'doc/');
        self::assertDirectoryExists($rootPath . 'mod/doc/');
    }

    /**
     * @test
     */
    public function writeEmConfWritesEmConfFile(): void
    {
        $extKey = $this->createFakeExtension();
        $emConfData = [
            'title' => 'Plugin cache engine',
            'description' => 'Provides an interface to cache plugin content elements based on 4.3 caching framework',
            'category' => 'Frontend',
        ];
        $rootPath = $this->fakedExtensions[$extKey]['packagePath'];
        $subject = $this->getAccessibleMock(
            FileHandlingUtility::class,
            ['makeAndClearExtensionDir'],
            [$this->createMock(PackageManager::class), new EmConfUtility(), $this->createMock(OpcodeCacheService::class), $this->createMock(LanguageServiceFactory::class)]
        );
        $subject->_call('writeEmConfToFile', $extKey, $emConfData, $rootPath);
        self::assertFileExists($rootPath . 'ext_emconf.php');
    }
}

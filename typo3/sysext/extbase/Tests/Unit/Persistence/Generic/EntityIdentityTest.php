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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Extbase\Persistence\Generic\EntityIdentity;
use TYPO3\CMS\Extbase\Persistence\Generic\Session;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Pins the string form of identity map keys: "uid[_localizedUid]@contentId-overlayType-fallbackChain".
 */
final class EntityIdentityTest extends UnitTestCase
{
    public static function identifierDataProvider(): array
    {
        return [
            'default language row' => [
                ['uid' => 2],
                new LanguageAspect(0, 0, LanguageAspect::OVERLAYS_ON),
                '2@0-on-',
            ],
            'translated row' => [
                ['uid' => 2, '_LOCALIZED_UID' => 11],
                new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_MIXED, [0]),
                '2_11@1-mixed-0',
            ],
            'content id -1' => [
                ['uid' => 4],
                new LanguageAspect(-1, -1, LanguageAspect::OVERLAYS_OFF),
                '4@-1-off-',
            ],
            'fallback chain with a non-numeric entry' => [
                ['uid' => 2, '_LOCALIZED_UID' => 22],
                new LanguageAspect(3, 3, LanguageAspect::OVERLAYS_ON_WITH_FLOATING, [2, 'pageNotFound']),
                '2_22@3-includeFloating-2,pageNotFound',
            ],
            'content id differs from language id' => [
                ['uid' => 2],
                new LanguageAspect(5, 1, LanguageAspect::OVERLAYS_ON, [1, 0]),
                '2@1-on-1,0',
            ],
            // Newly inserted objects have no language aspect: the default language configuration is used.
            'new object without localized uid' => [
                ['uid' => 5, '_LOCALIZED_UID' => null],
                null,
                '5@0-includeFloating-',
            ],
            'new object with localized uid' => [
                ['uid' => 5, '_LOCALIZED_UID' => 7],
                null,
                '5_7@0-includeFloating-',
            ],
        ];
    }

    #[DataProvider('identifierDataProvider')]
    #[Test]
    public function sessionBuildsIdentifierFromRow(array $row, ?LanguageAspect $languageAspect, string $expected): void
    {
        self::assertSame($expected, new Session()->buildIdentifier($row, $languageAspect));
    }

    #[DataProvider('identifierDataProvider')]
    #[Test]
    public function entityIdentityFromRowHasSameStringForm(array $row, ?LanguageAspect $languageAspect, string $expected): void
    {
        self::assertSame($expected, (string)EntityIdentity::fromRow($row, $languageAspect));
    }

    #[Test]
    public function entityIdentityFromBaseIdentifierHasSameStringForm(): void
    {
        self::assertSame('2_11@1-on-0', (string)EntityIdentity::fromBaseIdentifier('2_11', new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_ON, [0])));
        self::assertSame('42@0-includeFloating-', (string)EntityIdentity::fromBaseIdentifier('42'));
    }

    #[Test]
    public function baseIdentifierOfStripsContentIdentifier(): void
    {
        self::assertSame('2_11', EntityIdentity::baseIdentifierOf('2_11@1-on-0'));
        self::assertSame('42', EntityIdentity::baseIdentifierOf('42'));
    }

    #[Test]
    public function sessionBuildsIdentifierFromBaseIdentifier(): void
    {
        $session = new Session();
        self::assertSame('2_11@1-on-0', $session->buildIdentifier('2_11', new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_ON, [0])));
        self::assertSame('42@0-includeFloating-', $session->buildIdentifier('42'));
    }
}

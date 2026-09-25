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

namespace TYPO3\CMS\Extbase\Tests\Unit\Persistence\Generic\Mapper\Fixtures;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * Minimal fixture for ColumnMapFactoryTest (unit). The 1:n/foreign_field branch
 * in ColumnMapFactory is only taken when the model property itself is typed as
 * a collection (it overrules the TCA schema lookup), so `$hasMany` must stay a
 * typed `ObjectStorage`, mirroring the functional fixture this test is modelled on.
 */
class ColumnMapFactoryEntityFixture extends AbstractEntity
{
    /**
     * @var ObjectStorage<ColumnMapFactoryEntityFixture>
     */
    public ObjectStorage $hasMany;
}

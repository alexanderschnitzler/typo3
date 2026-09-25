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

namespace TYPO3\CMS\Extbase\Persistence\Generic;

/**
 * The write operations {@see Backend::resolveWriteUid()} resolves a target uid for.
 *
 * @internal only to be used within Extbase, not part of TYPO3 Core API.
 */
enum WriteTarget: string
{
    case OneToManyInsert = 'oneToManyInsert';
    case OneToManyAttach = 'oneToManyAttach';
    case MmInsert = 'mmInsert';
    case MmUpdate = 'mmUpdate';
    case MmDeleteAll = 'mmDeleteAll';
    case MmDeleteOne = 'mmDeleteOne';
    case Update = 'update';
    case Delete = 'delete';
}

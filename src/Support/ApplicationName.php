<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Support;

use InvalidArgumentException;

final class ApplicationName
{
    private const PATTERN = '/^[a-z0-9_-]+$/';

    public static function validate(?string $application): string
    {
        if ($application === null || $application === '') {
            throw new InvalidArgumentException(
                'The Centrifugo application identifier is missing. Set the CENTRIFUGO_APP environment variable to a value matching [a-z0-9_-]+.'
            );
        }

        if (preg_match(self::PATTERN, $application) !== 1) {
            throw new InvalidArgumentException(
                "The Centrifugo application identifier [{$application}] is invalid. CENTRIFUGO_APP must match [a-z0-9_-]+."
            );
        }

        return $application;
    }
}

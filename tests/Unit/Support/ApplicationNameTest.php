<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Tests\Unit\Support;

use InvalidArgumentException;
use Jestays\Centrifugo\Support\ApplicationName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationNameTest extends TestCase
{
    public function test_accepts_valid_application_identifiers(): void
    {
        $this->assertSame('pos', ApplicationName::validate('pos'));
        $this->assertSame('pro-plus_2', ApplicationName::validate('pro-plus_2'));
    }

    #[DataProvider('invalidApplicationIdentifiers')]
    public function test_rejects_invalid_application_identifiers(?string $application): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApplicationName::validate($application);
    }

    public static function invalidApplicationIdentifiers(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'uppercase and spaces' => ['Bad App!'],
            'trailing newline' => ["pos\n"],
        ];
    }
}

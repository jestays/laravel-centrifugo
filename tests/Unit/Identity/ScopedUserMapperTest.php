<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Tests\Unit\Identity;

use Jestays\Centrifugo\Identity\ScopedUserMapper;
use PHPUnit\Framework\TestCase;

final class ScopedUserMapperTest extends TestCase
{
    public function test_maps_user_id_within_application_scope(): void
    {
        $this->assertSame('pos:123', (new ScopedUserMapper('pos'))->map(123));
        $this->assertSame('proplus:123', (new ScopedUserMapper('proplus'))->map(123));
    }

    public function test_different_applications_produce_different_identities_for_the_same_user(): void
    {
        $pos = (new ScopedUserMapper('pos'))->map(123);
        $proplus = (new ScopedUserMapper('proplus'))->map(123);

        $this->assertNotSame($pos, $proplus);
    }
}

<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Tests\Unit\Channels;

use Jestays\Centrifugo\Channels\ScopedChannelMapper;
use Jestays\Centrifugo\Exceptions\InvalidCentrifugoChannel;
use PHPUnit\Framework\TestCase;

final class ScopedChannelMapperTest extends TestCase
{
    private ScopedChannelMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ScopedChannelMapper('pos', [
            'public' => 'public',
            'private' => 'private',
            'presence' => 'presence',
        ]);
    }

    public function test_maps_public_channel_to_centrifugo(): void
    {
        $this->assertSame('public:pos.stock.updated', $this->mapper->toCentrifugo('stock.updated'));
    }

    public function test_maps_private_channel_to_centrifugo(): void
    {
        $this->assertSame('private:pos.user.123', $this->mapper->toCentrifugo('private-user.123'));
    }

    public function test_maps_presence_channel_to_centrifugo(): void
    {
        $this->assertSame('presence:pos.branch.10', $this->mapper->toCentrifugo('presence-branch.10'));
    }

    public function test_maps_centrifugo_channel_back_to_laravel(): void
    {
        $this->assertSame('user.123', $this->mapper->toLaravel('private:pos.user.123'));
        $this->assertSame('branch.10', $this->mapper->toLaravel('presence:pos.branch.10'));
        $this->assertSame('stock.updated', $this->mapper->toLaravel('public:pos.stock.updated'));
    }

    public function test_rejects_channel_belonging_to_another_application(): void
    {
        $this->expectException(InvalidCentrifugoChannel::class);

        $this->mapper->toLaravel('private:proplus.user.123');
    }

    public function test_rejects_unknown_namespace(): void
    {
        $this->expectException(InvalidCentrifugoChannel::class);

        $this->mapper->toLaravel('unknown:pos.user.123');
    }

    public function test_rejects_channel_without_namespace_separator(): void
    {
        $this->expectException(InvalidCentrifugoChannel::class);

        $this->mapper->toLaravel('pos.user.123');
    }

    public function test_rejects_channel_with_empty_channel_name(): void
    {
        $this->expectException(InvalidCentrifugoChannel::class);

        $this->mapper->toLaravel('private:pos.');
    }

    public function test_rejects_a_prefix_trick_where_the_application_segment_only_starts_with_the_real_application(): void
    {
        $this->expectException(InvalidCentrifugoChannel::class);

        $this->mapper->toLaravel('private:pos2.user.123');
    }

    public function test_rejects_a_prefix_trick_where_the_application_segment_is_prefixed_by_the_real_application(): void
    {
        $this->expectException(InvalidCentrifugoChannel::class);

        $this->mapper->toLaravel('private:posx.user.123');
    }

    public function test_rejects_a_channel_name_containing_an_extra_colon(): void
    {
        $this->expectException(InvalidCentrifugoChannel::class);

        $this->mapper->toLaravel('private:pos.a:b');
    }

    public function test_rejects_a_channel_name_with_trailing_whitespace(): void
    {
        $this->expectException(InvalidCentrifugoChannel::class);

        $this->mapper->toLaravel('private:pos.orders.1 ');
    }

    public function test_rejects_a_channel_name_containing_a_hash(): void
    {
        $this->expectException(InvalidCentrifugoChannel::class);

        $this->mapper->toLaravel('private:pos.orders#1');
    }

    public function test_rejects_a_double_wrap_attempt_where_a_pre_mapped_channel_is_passed_to_to_centrifugo(): void
    {
        $this->expectException(InvalidCentrifugoChannel::class);

        $this->mapper->toCentrifugo('private-pos:extra');
    }

    public function test_rejects_an_empty_laravel_channel_name(): void
    {
        $this->expectException(InvalidCentrifugoChannel::class);

        $this->mapper->toCentrifugo('private-');
    }

    public function test_honours_custom_namespaces(): void
    {
        $mapper = new ScopedChannelMapper('pos', [
            'public' => 'pub',
            'private' => 'priv',
            'presence' => 'pres',
        ]);

        $this->assertSame('priv:pos.user.123', $mapper->toCentrifugo('private-user.123'));
        $this->assertSame('user.123', $mapper->toLaravel('priv:pos.user.123'));
    }

    public function test_is_presence_detects_the_presence_namespace(): void
    {
        $this->assertTrue($this->mapper->isPresence('presence:pos.branch.10'));
        $this->assertFalse($this->mapper->isPresence('private:pos.user.123'));
    }

    public function test_is_presence_rejects_invalid_channels(): void
    {
        $this->expectException(InvalidCentrifugoChannel::class);

        $this->mapper->isPresence('private:proplus.user.123');
    }
}

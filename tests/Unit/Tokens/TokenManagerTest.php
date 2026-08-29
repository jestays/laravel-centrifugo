<?php

declare(strict_types=1);

namespace Jestays\Centrifugo\Tests\Unit\Tokens;

use InvalidArgumentException;
use Jestays\Centrifugo\Identity\ScopedUserMapper;
use Jestays\Centrifugo\Tests\Support\DecodesJwt;
use Jestays\Centrifugo\Tokens\TokenManager;
use phpcent\Client;
use PHPUnit\Framework\TestCase;

final class TokenManagerTest extends TestCase
{
    use DecodesJwt;

    private TokenManager $tokens;

    protected function setUp(): void
    {
        $client = (new Client('http://unused'))->setSecret('secret');

        $this->tokens = new TokenManager($client, new ScopedUserMapper('pos'), 3600);
    }

    public function test_connection_token_uses_scoped_identity_and_default_ttl(): void
    {
        $payload = $this->decodeJwtPayload($this->tokens->connectionToken(123));

        $this->assertSame('pos:123', $payload['sub']);
        $this->assertEqualsWithDelta(time() + 3600, $payload['exp'], 2);
    }

    public function test_connection_token_with_zero_ttl_omits_expiration_claim(): void
    {
        $payload = $this->decodeJwtPayload($this->tokens->connectionToken(123, 0));

        $this->assertArrayNotHasKey('exp', $payload);
    }

    public function test_connection_token_includes_info_claim(): void
    {
        $payload = $this->decodeJwtPayload($this->tokens->connectionToken(123, null, ['role' => 'admin']));

        $this->assertSame(['role' => 'admin'], $payload['info']);
    }

    public function test_subscription_token_includes_channel_claim(): void
    {
        $payload = $this->decodeJwtPayload($this->tokens->subscriptionToken(123, 'private:pos.orders.1'));

        $this->assertSame('pos:123', $payload['sub']);
        $this->assertSame('private:pos.orders.1', $payload['channel']);
        $this->assertEqualsWithDelta(time() + 3600, $payload['exp'], 2);
    }

    public function test_subscription_token_with_zero_ttl_omits_expiration_claim(): void
    {
        $payload = $this->decodeJwtPayload($this->tokens->subscriptionToken(123, 'private:pos.orders.1', 0));

        $this->assertArrayNotHasKey('exp', $payload);
    }

    public function test_tokens_use_an_explicit_ttl_over_the_default(): void
    {
        $payload = $this->decodeJwtPayload($this->tokens->connectionToken(123, 60));

        $this->assertEqualsWithDelta(time() + 60, $payload['exp'], 2);
    }

    public function test_rejects_a_negative_default_ttl(): void
    {
        $client = (new Client('http://unused'))->setSecret('secret');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[-1]');

        new TokenManager($client, new ScopedUserMapper('pos'), -1);
    }

    public function test_connection_token_rejects_a_negative_ttl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[-60]');

        $this->tokens->connectionToken(123, -60);
    }

    public function test_subscription_token_rejects_a_negative_ttl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[-60]');

        $this->tokens->subscriptionToken(123, 'private:pos.orders.1', -60);
    }
}

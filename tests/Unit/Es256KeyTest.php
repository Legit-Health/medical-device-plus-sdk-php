<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Tests\Unit;

use InvalidArgumentException;
use LegitHealth\MedicalDevicePlus\Crypto\Base64Url;
use LegitHealth\MedicalDevicePlus\Crypto\Es256Key;
use LegitHealth\MedicalDevicePlus\Crypto\Jwt;
use LegitHealth\MedicalDevicePlus\Tests\Support\JwtInspector;
use PHPUnit\Framework\TestCase;

final class Es256KeyTest extends TestCase
{
    public function testSignaturesAre64BytesAndVerify(): void
    {
        $key = Es256Key::generate();
        for ($i = 0; $i < 50; ++$i) {
            $jwt = Jwt::signEs256(['typ' => 'JWT'], ['n' => $i], $key);
            self::assertSame(64, strlen(Base64Url::decode(explode('.', $jwt)[2])));
            self::assertTrue(JwtInspector::verifyWithKey($jwt, $key));
        }
    }

    public function testPublicJwkHasPaddedCoordinates(): void
    {
        $jwk = Es256Key::generate()->publicJwk();
        self::assertSame('EC', $jwk['kty']);
        self::assertSame('P-256', $jwk['crv']);
        self::assertSame(32, strlen(Base64Url::decode($jwk['x'])));
        self::assertSame(32, strlen(Base64Url::decode($jwk['y'])));
        self::assertArrayNotHasKey('d', $jwk);
    }

    public function testReadsPemAndBase64Pem(): void
    {
        $key = Es256Key::generate();
        $pem = $key->toPem();

        self::assertSame($key->publicJwk(), Es256Key::fromPem($pem)->publicJwk());
        self::assertSame($key->publicJwk(), Es256Key::fromPem(base64_encode($pem))->publicJwk());
    }

    public function testRejectsKeysOnOtherCurves(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
        openssl_pkey_export($key, $pem);

        $this->expectException(InvalidArgumentException::class);
        Es256Key::fromPem($pem);
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Es256Key::fromPem('not a key');
    }
}

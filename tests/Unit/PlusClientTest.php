<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Tests\Unit;

use InvalidArgumentException;
use LegitHealth\MedicalDevicePlus\Arguments\DiagnosisSupportArguments;
use LegitHealth\MedicalDevicePlus\Arguments\EncodedImage;
use LegitHealth\MedicalDevicePlus\Arguments\ImageFileFormat;
use LegitHealth\MedicalDevicePlus\Auth\Psr6TokenCache;
use LegitHealth\MedicalDevicePlus\Crypto\DpopProof;
use LegitHealth\MedicalDevicePlus\Exception\RequestException;
use LegitHealth\MedicalDevicePlus\PlusClient;
use LegitHealth\MedicalDevicePlus\Tests\Support\FakeClock;
use LegitHealth\MedicalDevicePlus\Tests\Support\Fixtures;
use LegitHealth\MedicalDevicePlus\Tests\Support\JwtInspector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PlusClientTest extends TestCase
{
    private FakeClock $clock;
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];
    /** @var list<MockResponse> */
    private array $plusResponses = [];
    private int $tokensIssued = 0;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
    }

    public function testSendsTheRequestWithADpopTokenAndProof(): void
    {
        $this->plusResponses = [Fixtures::json(Fixtures::report(), 200, ['legithp-report-id' => 'report-123'])];

        $response = $this->client()->diagnosisSupport(new DiagnosisSupportArguments([
            EncodedImage::fromBinary('jpeg-bytes'),
            new EncodedImage(base64_encode('png-bytes'), ImageFileFormat::Png, identifier: 'img-2'),
        ]));

        $call = $this->plusCalls()[0];
        self::assertSame('POST', $call['method']);
        self::assertSame(Fixtures::DIAGNOSIS_SUPPORT_URL, $call['url']);
        self::assertSame('DPoP access-token-1', self::header($call['options'], 'authorization'));

        $proofJwt = self::header($call['options'], 'dpop');
        self::assertTrue(JwtInspector::verifyWithEmbeddedJwk($proofJwt));
        $proof = JwtInspector::decode($proofJwt);
        self::assertSame('dpop+jwt', $proof['header']['typ']);
        self::assertSame('POST', $proof['claims']['htm']);
        self::assertSame(Fixtures::DIAGNOSIS_SUPPORT_URL, $proof['claims']['htu']);
        self::assertSame($this->clock->timestamp, $proof['claims']['iat']);
        self::assertSame(DpopProof::accessTokenHash('access-token-1'), $proof['claims']['ath']);
        self::assertSame(rtrim(strtr(base64_encode(hash('sha256', 'access-token-1', true)), '+/', '-_'), '='), $proof['claims']['ath']);

        self::assertSame([
            'images' => [
                ['data' => base64_encode('jpeg-bytes'), 'fileFormat' => 'jpeg', 'colorModel' => 'rgb'],
                ['data' => base64_encode('png-bytes'), 'fileFormat' => 'png', 'colorModel' => 'rgb', 'identifier' => 'img-2'],
            ],
        ], json_decode($call['options']['body'], true));

        self::assertSame('report-123', $response->reportId);
        self::assertSame(12.0, $response->riskMetrics()->referralProbability);
    }

    public function testEveryRequestHasANewProofAndReusesTheToken(): void
    {
        $this->plusResponses = [Fixtures::json(Fixtures::report()), Fixtures::json(Fixtures::report())];
        $client = $this->client();

        $client->diagnosisSupport(self::arguments());
        $this->clock->advance(200);
        $client->diagnosisSupport(self::arguments());

        self::assertSame(1, $this->tokensIssued);
        [$first, $second] = $this->plusCalls();
        self::assertNotSame(
            JwtInspector::decode(self::header($first['options'], 'dpop'))['claims']['jti'],
            JwtInspector::decode(self::header($second['options'], 'dpop'))['claims']['jti'],
        );
    }

    public function testAsksForANewTokenWhenLessThan30SecondsAreLeft(): void
    {
        $this->plusResponses = [Fixtures::json(Fixtures::report()), Fixtures::json(Fixtures::report())];
        $client = $this->client();

        $client->diagnosisSupport(self::arguments());
        $this->clock->advance(271);
        $client->diagnosisSupport(self::arguments());

        self::assertSame(2, $this->tokensIssued);
        self::assertSame('DPoP access-token-2', self::header($this->plusCalls()[1]['options'], 'authorization'));
    }

    public function testSharesTokenAndDpopKeyThroughAPsr6Pool(): void
    {
        $pool = new ArrayAdapter();
        $this->plusResponses = [Fixtures::json(Fixtures::report()), Fixtures::json(Fixtures::report())];

        $this->client(new Psr6TokenCache($pool))->diagnosisSupport(self::arguments());
        $this->client(new Psr6TokenCache($pool))->diagnosisSupport(self::arguments());

        self::assertSame(1, $this->tokensIssued);
        [$first, $second] = $this->plusCalls();
        self::assertSame(
            JwtInspector::decode(self::header($first['options'], 'dpop'))['header']['jwk'],
            JwtInspector::decode(self::header($second['options'], 'dpop'))['header']['jwk'],
        );
    }

    public function testRetriesOnceWithANewTokenWhenTheTokenIsRejected(): void
    {
        $this->plusResponses = [
            Fixtures::json(['detail' => 'invalid'], 401, ['www-authenticate' => 'DPoP error="invalid_token"']),
            Fixtures::json(Fixtures::report()),
        ];

        $this->client()->diagnosisSupport(self::arguments());

        self::assertSame(2, $this->tokensIssued);
        self::assertSame('DPoP access-token-2', self::header($this->plusCalls()[1]['options'], 'authorization'));
    }

    public function testDoesNotLoopWhenTheTokenKeepsBeingRejected(): void
    {
        $rejected = fn () => Fixtures::json([], 401, ['www-authenticate' => 'DPoP error="invalid_token"']);
        $this->plusResponses = [$rejected(), $rejected()];

        try {
            $this->client()->diagnosisSupport(self::arguments());
            self::fail('expected an exception');
        } catch (RequestException $exception) {
            self::assertSame(401, $exception->statusCode);
            self::assertSame('invalid_token', $exception->error);
            self::assertCount(2, $this->plusCalls());
        }
    }

    public function testDoesNotRetryAnInvalidProof(): void
    {
        $this->plusResponses = [Fixtures::json([], 401, ['www-authenticate' => 'DPoP error="invalid_dpop_proof"'])];

        try {
            $this->client()->diagnosisSupport(self::arguments());
            self::fail('expected an exception');
        } catch (RequestException $exception) {
            self::assertSame('invalid_dpop_proof', $exception->error);
            self::assertCount(1, $this->plusCalls());
        }
    }

    public function testReportsInsufficientScope(): void
    {
        $this->plusResponses = [Fixtures::json([], 403, ['www-authenticate' => 'DPoP error="insufficient_scope"'])];

        try {
            $this->client()->diagnosisSupport(self::arguments());
            self::fail('expected an exception');
        } catch (RequestException $exception) {
            self::assertSame(403, $exception->statusCode);
            self::assertSame('insufficient_scope', $exception->error);
        }
    }

    public function testReportsADeviceThatIsSaturated(): void
    {
        $this->plusResponses = [Fixtures::json(['detail' => 'DeviceSaturated'], 503, ['retry-after' => '7'])];

        try {
            $this->client()->diagnosisSupport(self::arguments());
            self::fail('expected an exception');
        } catch (RequestException $exception) {
            self::assertTrue($exception->isDeviceSaturated());
            self::assertFalse($exception->isDeviceNotReady());
            self::assertSame(7, $exception->retryAfter);
            self::assertSame(['detail' => 'DeviceSaturated'], $exception->content);
        }
    }

    public function testReportsADeviceThatIsNotReadyOrTimedOut(): void
    {
        $this->plusResponses = [Fixtures::json([], 503), new MockResponse('Gateway Timeout', ['http_code' => 504])];
        $client = $this->client();

        try {
            $client->diagnosisSupport(self::arguments());
            self::fail('expected an exception');
        } catch (RequestException $exception) {
            self::assertTrue($exception->isDeviceNotReady());
        }
        try {
            $client->diagnosisSupport(self::arguments());
            self::fail('expected an exception');
        } catch (RequestException $exception) {
            self::assertTrue($exception->isDeviceTimeout());
            self::assertNull($exception->content);
        }
    }

    public function testReportsValidationErrors(): void
    {
        $detail = ['detail' => [['loc' => ['body', 'images', 0, 'foo'], 'msg' => 'Extra inputs are not permitted', 'type' => 'extra_forbidden']]];
        $this->plusResponses = [Fixtures::json($detail, 422)];

        try {
            $this->client()->diagnosisSupport(self::arguments());
            self::fail('expected an exception');
        } catch (RequestException $exception) {
            self::assertSame(422, $exception->statusCode);
            self::assertSame($detail, $exception->content);
        }
    }

    public function testRequiresBetweenOneAndFiveImages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DiagnosisSupportArguments(array_fill(0, 6, EncodedImage::fromBinary('x')));
    }

    public function testRequiresAtLeastOneImage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DiagnosisSupportArguments([]);
    }

    private function client(?\LegitHealth\MedicalDevicePlus\Auth\TokenCache $cache = null): PlusClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = compact('method', 'url', 'options');
            if (str_ends_with($url, '/.well-known/openid-configuration')) {
                return Fixtures::discovery();
            }
            if ($url === Fixtures::TOKEN_ENDPOINT) {
                ++$this->tokensIssued;

                return Fixtures::token('access-token-' . $this->tokensIssued);
            }

            return array_shift($this->plusResponses) ?? self::fail('unexpected request to ' . $url);
        });

        return PlusClient::create(Fixtures::BASE_URL, Fixtures::credentials(), $cache, $http, $this->clock);
    }

    /**
     * @return list<array{method: string, url: string, options: array<string, mixed>}>
     */
    private function plusCalls(): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => str_starts_with($r['url'], Fixtures::BASE_URL)));
    }

    private static function arguments(): DiagnosisSupportArguments
    {
        return new DiagnosisSupportArguments([EncodedImage::fromBinary('jpeg-bytes')]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function header(array $options, string $name): string
    {
        foreach ($options['headers'] as $line) {
            [$key, $value] = explode(': ', $line, 2);
            if (strtolower($key) === $name) {
                return $value;
            }
        }
        self::fail("header $name not sent");
    }
}

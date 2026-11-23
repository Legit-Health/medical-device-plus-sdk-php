<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Tests\Integration;

use LegitHealth\MedicalDevicePlus\Arguments\DiagnosisSupportArguments;
use LegitHealth\MedicalDevicePlus\Arguments\EncodedImage;
use LegitHealth\MedicalDevicePlus\Arguments\RequestOptions;
use LegitHealth\MedicalDevicePlus\Auth\ClientCredentials;
use LegitHealth\MedicalDevicePlus\Crypto\Es256Key;
use LegitHealth\MedicalDevicePlus\PlusClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Calls Legit.Health Plus preproduction for real. Needs these environment variables:
 * PLUS_BASE_URL, PLUS_ISSUER, PLUS_CLIENT_ID and PLUS_PRIVATE_KEY (PEM or base64-encoded PEM).
 */
#[Group('integration')]
final class DiagnosisSupportIntegrationTest extends TestCase
{
    private PlusClient $client;

    protected function setUp(): void
    {
        $env = [];
        foreach (['PLUS_BASE_URL', 'PLUS_ISSUER', 'PLUS_CLIENT_ID', 'PLUS_PRIVATE_KEY'] as $name) {
            $value = getenv($name);
            if (!is_string($value) || $value === '') {
                self::markTestSkipped("Set $name to run the integration tests");
            }
            $env[$name] = $value;
        }

        $this->client = PlusClient::create(
            $env['PLUS_BASE_URL'],
            new ClientCredentials($env['PLUS_ISSUER'], $env['PLUS_CLIENT_ID'], Es256Key::fromPem($env['PLUS_PRIVATE_KEY'])),
        );
    }

    public function testDiagnosisSupportOfPsoriasis(): void
    {
        $response = $this->client->diagnosisSupport(
            new DiagnosisSupportArguments([EncodedImage::fromBinary((string) file_get_contents(__DIR__ . '/../resources/psoriasis_01.jpg'))]),
            new RequestOptions(maxDuration: 120),
        );

        self::assertNotNull($response->reportId, 'Plus must return the Legithp-Report-Id header');
        self::assertNotEmpty($response->studyAggregate->hypotheses);
        self::assertCount(1, $response->imageAnalyses);
        $risk = $response->riskMetrics();
        foreach ([$risk->referralProbability, $risk->urgentReferralProbability, $risk->highPriorityReferralProbability] as $probability) {
            self::assertGreaterThanOrEqual(0, $probability);
            self::assertLessThanOrEqual(100, $probability);
        }
        self::assertGreaterThan(0, $response->analysisDurationInSeconds());
    }

    public function testTwoImagesOfTheSameLesionReuseTheToken(): void
    {
        $image = EncodedImage::fromBinary((string) file_get_contents(__DIR__ . '/../resources/acne.jpg'));
        $first = $this->client->diagnosisSupport(new DiagnosisSupportArguments([$image, $image]), new RequestOptions(maxDuration: 120));
        $second = $this->client->diagnosisSupport(new DiagnosisSupportArguments([$image]), new RequestOptions(maxDuration: 120));

        self::assertCount(2, $first->imageAnalyses);
        self::assertNotSame($first->reportId, $second->reportId);
    }
}

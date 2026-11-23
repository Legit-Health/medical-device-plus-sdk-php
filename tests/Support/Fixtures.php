<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Tests\Support;

use LegitHealth\MedicalDevicePlus\Auth\ClientCredentials;
use LegitHealth\MedicalDevicePlus\Crypto\Es256Key;
use Symfony\Component\HttpClient\Response\MockResponse;

final class Fixtures
{
    public const ISSUER = 'https://id-staging.legit.health/realms/legit-health';
    public const TOKEN_ENDPOINT = 'https://id-staging.legit.health/realms/legit-health/protocol/openid-connect/token';
    public const BASE_URL = 'https://plus-staging.legit.health';
    public const DIAGNOSIS_SUPPORT_URL = 'https://plus-staging.legit.health/v1.0/clinical/diagnosis-support';

    public static function credentials(?Es256Key $key = null): ClientCredentials
    {
        return new ClientCredentials(self::ISSUER, 'app-backend', $key ?? Es256Key::generate());
    }

    public static function discovery(string $issuer = self::ISSUER): MockResponse
    {
        return self::json(['issuer' => $issuer, 'token_endpoint' => self::TOKEN_ENDPOINT]);
    }

    public static function token(string $value = 'access-token-1', int $expiresIn = 300): MockResponse
    {
        return self::json(['access_token' => $value, 'token_type' => 'DPoP', 'expires_in' => $expiresIn, 'scope' => 'organization:* diagnosis:write device:read']);
    }

    /**
     * @param array<string, list<string>|string> $headers
     */
    public static function json(mixed $body, int $status = 200, array $headers = []): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json', ...$headers],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function report(): array
    {
        $risk = [
            'anyConditionProbability' => 97.5,
            'malignantConditionProbability' => 1.2,
            'pigmentedConditionProbability' => 0.8,
            'referralProbability' => 12.0,
            'urgentReferralProbability' => 2.5,
            'highPriorityReferralProbability' => 9.5,
        ];
        $findings = [
            'hypotheses' => [
                [
                    'identifier' => 'psoriasis',
                    'probability' => 88.1,
                    'concepts' => [['name' => 'Psoriasis', 'code' => 'EA90', 'terminologySystem' => 'ICD-11']],
                    'explanation' => ['attentionMap' => ['data' => 'aGVhdG1hcA==', 'fileFormat' => 'jpeg', 'colorModel' => 'rgb']],
                ],
                ['identifier' => 'eczema', 'probability' => 11.9],
            ],
            'riskMetrics' => $risk,
            'entropy' => 23.4,
        ];

        return [
            'identifier' => '3f1c1b8e-8a5e-4b8a-9a43-0c6f7c1e2d11',
            'issuedAt' => '2026-11-23T10:15:30.123456Z',
            'notes' => null,
            'analysisDuration' => 'PT1.25S',
            'studyAggregate' => ['findings' => $findings],
            'imageAnalyses' => [
                [
                    'sourceImage' => ['data' => 'aW1hZ2U=', 'fileFormat' => 'jpeg', 'colorModel' => 'rgb'],
                    'technicalSummary' => [
                        'isAssessable' => true,
                        'imageQuality' => ['score' => 8.5, 'interpretation' => 'good', 'qualityIssues' => [], 'notesForReacquisition' => null],
                        'imageDomain' => ['category' => 'dermatological', 'confidence' => 99.9],
                        'imageModality' => ['category' => 'clinical', 'confidence' => 98.0],
                    ],
                    'findings' => $findings,
                ],
                [
                    'sourceImage' => ['data' => 'aW1hZ2Uy'],
                    'technicalSummary' => [
                        'isAssessable' => false,
                        'imageQuality' => ['score' => 2.0, 'interpretation' => 'poor', 'qualityIssues' => ['blur'], 'notesForReacquisition' => 'Hold the camera steady'],
                        'imageDomain' => ['category' => 'dermatological', 'confidence' => 91.0],
                        'imageModality' => null,
                    ],
                    'findings' => $findings,
                ],
            ],
        ];
    }
}

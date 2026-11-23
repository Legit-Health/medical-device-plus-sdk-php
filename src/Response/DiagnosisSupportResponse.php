<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Response;

use DateTimeImmutable;
use LegitHealth\MedicalDevicePlus\Response\Value\DifferentialDiagnosis;
use LegitHealth\MedicalDevicePlus\Response\Value\Json;
use LegitHealth\MedicalDevicePlus\Response\Value\PerImageAnalysis;
use LegitHealth\MedicalDevicePlus\Response\Value\RiskMetrics;

/**
 * A CanonicalDiagnosisReport from Legit.Health Plus.
 */
final readonly class DiagnosisSupportResponse
{
    /**
     * @param string|null $reportId value of the Legithp-Report-Id header. Store it next to the organisation that made the request:
     *                              Plus audit logs name the client (app-backend), not the organisation, and this id links them.
     * @param string $analysisDuration ISO 8601 duration, e.g. PT1.25S
     * @param list<PerImageAnalysis> $imageAnalyses one per image, in request order
     */
    public function __construct(
        public ?string $reportId,
        public ?string $identifier,
        public ?DateTimeImmutable $issuedAt,
        public ?string $notes,
        public string $analysisDuration,
        public DifferentialDiagnosis $studyAggregate,
        public array $imageAnalyses,
    ) {
    }

    /**
     * @param array<mixed> $json
     */
    public static function fromJson(array $json, ?string $reportId = null): self
    {
        $issuedAt = Json::optionalString($json, 'issuedAt');

        return new self(
            $reportId,
            Json::optionalString($json, 'identifier'),
            $issuedAt === null ? null : new DateTimeImmutable($issuedAt),
            Json::optionalString($json, 'notes'),
            Json::string($json, 'analysisDuration'),
            DifferentialDiagnosis::fromJson(Json::object(Json::object($json, 'studyAggregate'), 'findings')),
            array_map(PerImageAnalysis::fromJson(...), Json::list($json, 'imageAnalyses')),
        );
    }

    /**
     * Risk metrics of the whole study, including the referral probabilities.
     */
    public function riskMetrics(): RiskMetrics
    {
        return $this->studyAggregate->riskMetrics;
    }

    public function analysisDurationInSeconds(): float
    {
        if (preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/', $this->analysisDuration, $m) !== 1) {
            throw new \UnexpectedValueException(sprintf('Unexpected ISO 8601 duration "%s"', $this->analysisDuration));
        }

        return (int) ($m[1] ?? 0) * 86400 + (int) ($m[2] ?? 0) * 3600 + (int) ($m[3] ?? 0) * 60 + (float) ($m[4] ?? 0);
    }

    /**
     * Indexes of the images that cannot be assessed (bad quality or not dermatological).
     *
     * @return list<int>
     */
    public function indexesOfNonAssessableImages(): array
    {
        $indexes = [];
        foreach ($this->imageAnalyses as $index => $analysis) {
            if (!$analysis->technicalSummary->isAssessable) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }
}

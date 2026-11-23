<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Response\Value;

final readonly class DifferentialDiagnosis
{
    /**
     * @param list<ConditionHypothesis> $hypotheses ranked, highest probability first
     */
    public function __construct(
        public array $hypotheses,
        public RiskMetrics $riskMetrics,
        public float $entropy,
    ) {
    }

    /**
     * @param array<string, mixed> $json
     */
    public static function fromJson(array $json): self
    {
        return new self(
            array_map(ConditionHypothesis::fromJson(...), Json::list($json, 'hypotheses')),
            RiskMetrics::fromJson(Json::object($json, 'riskMetrics')),
            Json::float($json, 'entropy'),
        );
    }

    public function topHypothesis(): ?ConditionHypothesis
    {
        return $this->hypotheses[0] ?? null;
    }
}

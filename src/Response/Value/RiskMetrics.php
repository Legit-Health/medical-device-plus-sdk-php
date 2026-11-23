<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Response\Value;

/**
 * Probabilities from 0 to 100. The referral is here.
 */
final readonly class RiskMetrics
{
    /**
     * @param float $referralProbability             referral of any urgency (urgent or high priority)
     * @param float $urgentReferralProbability       referral within 0 to 48 hours
     * @param float $highPriorityReferralProbability referral within 7 to 15 days
     */
    public function __construct(
        public float $anyConditionProbability,
        public float $malignantConditionProbability,
        public float $pigmentedConditionProbability,
        public float $referralProbability,
        public float $urgentReferralProbability,
        public float $highPriorityReferralProbability,
    ) {
    }

    /**
     * @param array<string, mixed> $json
     */
    public static function fromJson(array $json): self
    {
        return new self(
            Json::float($json, 'anyConditionProbability'),
            Json::float($json, 'malignantConditionProbability'),
            Json::float($json, 'pigmentedConditionProbability'),
            Json::float($json, 'referralProbability'),
            Json::float($json, 'urgentReferralProbability'),
            Json::float($json, 'highPriorityReferralProbability'),
        );
    }
}

<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Response\Value;

final readonly class ConditionHypothesis
{
    /**
     * @param list<ConditionConcept> $concepts
     * @param ImageData|null $attentionMap saliency overlay, only for the top hypotheses
     */
    public function __construct(
        public string $identifier,
        public float $probability,
        public array $concepts,
        public ?ImageData $attentionMap,
    ) {
    }

    /**
     * @param array<string, mixed> $json
     */
    public static function fromJson(array $json): self
    {
        $explanation = Json::optionalObject($json, 'explanation');

        return new self(
            Json::string($json, 'identifier'),
            Json::float($json, 'probability'),
            array_map(ConditionConcept::fromJson(...), Json::optionalList($json, 'concepts')),
            $explanation === null ? null : ImageData::fromJson(Json::object($explanation, 'attentionMap')),
        );
    }
}

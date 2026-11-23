<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Tests\Unit;

use LegitHealth\MedicalDevicePlus\Response\DiagnosisSupportResponse;
use LegitHealth\MedicalDevicePlus\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class DiagnosisSupportResponseTest extends TestCase
{
    public function testParsesTheCanonicalDiagnosisReport(): void
    {
        $response = DiagnosisSupportResponse::fromJson(Fixtures::report(), 'report-123');

        self::assertSame('report-123', $response->reportId);
        self::assertSame('3f1c1b8e-8a5e-4b8a-9a43-0c6f7c1e2d11', $response->identifier);
        self::assertSame('2026-11-23T10:15:30+00:00', $response->issuedAt?->format(DATE_ATOM));
        self::assertSame(1.25, $response->analysisDurationInSeconds());

        $findings = $response->studyAggregate;
        self::assertSame('psoriasis', $findings->topHypothesis()?->identifier);
        self::assertSame(88.1, $findings->hypotheses[0]->probability);
        self::assertSame('EA90', $findings->hypotheses[0]->concepts[0]->code);
        self::assertSame('aGVhdG1hcA==', $findings->hypotheses[0]->attentionMap?->data);
        self::assertSame([], $findings->hypotheses[1]->concepts);
        self::assertNull($findings->hypotheses[1]->attentionMap);
        self::assertSame(23.4, $findings->entropy);

        $risk = $response->riskMetrics();
        self::assertSame(97.5, $risk->anyConditionProbability);
        self::assertSame(1.2, $risk->malignantConditionProbability);
        self::assertSame(0.8, $risk->pigmentedConditionProbability);
        self::assertSame(12.0, $risk->referralProbability);
        self::assertSame(2.5, $risk->urgentReferralProbability);
        self::assertSame(9.5, $risk->highPriorityReferralProbability);

        self::assertCount(2, $response->imageAnalyses);
        $first = $response->imageAnalyses[0]->technicalSummary;
        self::assertTrue($first->isAssessable);
        self::assertSame('good', $first->imageQuality->interpretation);
        self::assertSame('dermatological', $first->imageDomain->category);
        self::assertSame('clinical', $first->imageModality?->category);
        $second = $response->imageAnalyses[1]->technicalSummary;
        self::assertSame(['blur'], $second->imageQuality->qualityIssues);
        self::assertNull($second->imageModality);
        self::assertSame([1], $response->indexesOfNonAssessableImages());
    }

    public function testFailsClearlyOnAnUnexpectedShape(): void
    {
        $report = Fixtures::report();
        unset($report['studyAggregate']['findings']['riskMetrics']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('"riskMetrics"');
        DiagnosisSupportResponse::fromJson($report);
    }

    #[DataProvider('durations')]
    public function testParsesIso8601Durations(string $duration, float $seconds): void
    {
        $report = Fixtures::report();
        $report['analysisDuration'] = $duration;

        self::assertEqualsWithDelta($seconds, DiagnosisSupportResponse::fromJson($report)->analysisDurationInSeconds(), 1e-9);
    }

    /**
     * @return iterable<array{string, float}>
     */
    public static function durations(): iterable
    {
        yield ['PT0.5S', 0.5];
        yield ['PT2S', 2.0];
        yield ['PT1M3.25S', 63.25];
        yield ['PT1H', 3600.0];
        yield ['P1DT1S', 86401.0];
    }
}

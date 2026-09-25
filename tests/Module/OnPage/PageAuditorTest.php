<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Module\OnPage;

use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\PageSignals;
use Lbonnet\SeoBundle\Module\OnPage\PageAuditor;
use PHPUnit\Framework\TestCase;

final class PageAuditorTest extends TestCase
{
    private PageAuditor $auditor;

    protected function setUp(): void
    {
        $this->auditor = new PageAuditor(maxTitleLength: 20, maxDescriptionLength: 40);
    }

    public function testFlagsMissingTitleAndDescription(): void
    {
        $signals = new PageSignals(h1Headings: ['Only heading']);

        $issues = $this->auditor->audit($signals);

        $this->assertContainsIssueType(IssueType::MissingTitle, $issues);
        $this->assertContainsIssueType(IssueType::MissingDescription, $issues);
    }

    public function testFlagsTitleAndDescriptionTooLong(): void
    {
        $signals = new PageSignals(
            title: 'This title is definitely way too long',
            metaDescription: 'This meta description is also going to be far too long for the configured limit',
            h1Headings: ['Heading'],
        );

        $issues = $this->auditor->audit($signals);

        $this->assertContainsIssueType(IssueType::TitleTooLong, $issues);
        $this->assertContainsIssueType(IssueType::DescriptionTooLong, $issues);
    }

    public function testDoesNotFlagTitleAndDescriptionWithinLimits(): void
    {
        $signals = new PageSignals(
            title: 'Short title',
            metaDescription: 'Short description.',
            h1Headings: ['Heading'],
        );

        $issues = $this->auditor->audit($signals);

        $this->assertIssueTypeAbsent(IssueType::MissingTitle, $issues);
        $this->assertIssueTypeAbsent(IssueType::TitleTooLong, $issues);
        $this->assertIssueTypeAbsent(IssueType::MissingDescription, $issues);
        $this->assertIssueTypeAbsent(IssueType::DescriptionTooLong, $issues);
    }

    public function testFlagsMissingH1(): void
    {
        $signals = new PageSignals(title: 'Title', metaDescription: 'Description.', h1Headings: []);

        $issues = $this->auditor->audit($signals);

        $this->assertContainsIssueType(IssueType::MissingH1, $issues);
    }

    public function testFlagsMultipleH1(): void
    {
        $signals = new PageSignals(title: 'Title', metaDescription: 'Description.', h1Headings: ['First', 'Second']);

        $issues = $this->auditor->audit($signals);

        $this->assertContainsIssueType(IssueType::MultipleH1, $issues);
    }

    public function testFlagsEachImageMissingAlt(): void
    {
        $signals = new PageSignals(
            title: 'Title',
            metaDescription: 'Description.',
            h1Headings: ['Heading'],
            imagesMissingAlt: ['/a.png', '/b.png'],
        );

        $issues = $this->auditor->audit($signals);
        $imageIssues = array_values(
            array_filter($issues, static fn(Issue $issue): bool => $issue->type === IssueType::ImageMissingAlt)
        );

        $this->assertCount(2, $imageIssues);
    }

    /**
     * @param list<Issue> $issues
     */
    private function assertContainsIssueType(IssueType $type, array $issues): void
    {
        $types = array_map(static fn(Issue $issue): IssueType => $issue->type, $issues);

        $this->assertContains($type, $types);
    }

    /**
     * @param list<Issue> $issues
     */
    private function assertIssueTypeAbsent(IssueType $type, array $issues): void
    {
        $types = array_map(static fn(Issue $issue): IssueType => $issue->type, $issues);

        $this->assertNotContains($type, $types);
    }
}

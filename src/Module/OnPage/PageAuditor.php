<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\OnPage;

use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\PageSignals;

final class PageAuditor
{
    public function __construct(
        private readonly int $maxTitleLength = 60,
        private readonly int $maxDescriptionLength = 155,
    ) {
    }

    /**
     * @return list<Issue>
     */
    public function audit(PageSignals $signals): array
    {
        return [
            ...$this->auditTitle($signals),
            ...$this->auditDescription($signals),
            ...$this->auditHeadings($signals),
            ...$this->auditImages($signals),
        ];
    }

    /**
     * @return list<Issue>
     */
    private function auditTitle(PageSignals $signals): array
    {
        if ($signals->title === null) {
            return [new Issue(IssueType::MissingTitle, 'The page has no <title> element.')];
        }

        if (mb_strlen($signals->title) > $this->maxTitleLength) {
            return [
                new Issue(
                    IssueType::TitleTooLong,
                    sprintf(
                        'The title is %d characters long, longer than the recommended %d.',
                        mb_strlen($signals->title),
                        $this->maxTitleLength
                    ),
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<Issue>
     */
    private function auditDescription(PageSignals $signals): array
    {
        if ($signals->metaDescription === null) {
            return [new Issue(IssueType::MissingDescription, 'The page has no meta description.')];
        }

        if (mb_strlen($signals->metaDescription) > $this->maxDescriptionLength) {
            return [
                new Issue(
                    IssueType::DescriptionTooLong,
                    sprintf(
                        'The meta description is %d characters long, longer than the recommended %d.',
                        mb_strlen($signals->metaDescription),
                        $this->maxDescriptionLength
                    ),
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<Issue>
     */
    private function auditHeadings(PageSignals $signals): array
    {
        $count = count($signals->h1Headings);

        if ($count === 0) {
            return [new Issue(IssueType::MissingH1, 'The page has no <h1> heading.')];
        }

        if ($count > 1) {
            return [
                new Issue(
                    IssueType::MultipleH1,
                    sprintf('The page has %d <h1> headings, expected exactly one.', $count),
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<Issue>
     */
    private function auditImages(PageSignals $signals): array
    {
        return array_map(
            static fn(string $src): Issue => new Issue(
                IssueType::ImageMissingAlt,
                sprintf('Image "%s" is missing an alt attribute.', $src),
            ),
            $signals->imagesMissingAlt,
        );
    }
}

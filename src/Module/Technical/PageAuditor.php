<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical;

use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Model\PageSignals;
use Lbonnet\SeoBundle\Module\Technical\Hreflang\HreflangValidator;
use Lbonnet\SeoBundle\Url\UrlResolver;

final class PageAuditor implements PageAuditorInterface
{
    public function audit(PageResponse $response, PageSignals $signals): array
    {
        return [
            ...$this->auditCanonical($signals),
            ...$this->auditIndexingDirectives($response, $signals),
            ...$this->auditMetaRefresh($signals),
            ...$this->auditHtmlLang($signals),
            ...$this->auditHreflang($response, $signals),
        ];
    }

    /**
     * @return list<Issue>
     */
    private function auditCanonical(PageSignals $signals): array
    {
        $issues = [];
        $distinct = array_values(array_unique($signals->canonicalHrefs));

        if (count($distinct) > 1) {
            $issues[] = new Issue(
                IssueType::CanonicalMultiple,
                sprintf(
                    'The page declares %d conflicting canonical URLs (%s); search engines ignore all of them.',
                    count($distinct),
                    implode(', ', array_map(static fn(string $href): string => '"'.$href.'"', $distinct)),
                ),
            );
        }

        if ($signals->bodyCanonicalHrefs !== []) {
            $issues[] = new Issue(
                IssueType::CanonicalNotInHead,
                sprintf(
                    'A canonical link ("%s") sits outside <head> and is ignored. Check for an invalid element '
                    .'earlier in <head>, which ends it sooner than the source suggests.',
                    $signals->bodyCanonicalHrefs[0],
                ),
            );
        }

        foreach ($distinct as $href) {
            if (UrlResolver::isAbsoluteHttpUrl($href)) {
                continue;
            }

            $issues[] = new Issue(
                IssueType::CanonicalRelative,
                $href === ''
                    ? 'The canonical link has an empty href, which silently makes the page canonical to itself.'
                    : sprintf('The canonical href "%s" is not an absolute URL.', $href),
            );
        }

        return $issues;
    }

    /**
     * @return list<Issue>
     */
    private function auditIndexingDirectives(PageResponse $response, PageSignals $signals): array
    {
        $issues = [];
        $meta = $signals->metaRobotsDirectives();
        $header = $response->headerRobotsDirectives();

        if (($meta->hasIndex() && $header->hasNoindex()) || ($meta->hasNoindex() && $header->hasIndex())) {
            $issues[] = new Issue(
                IssueType::RobotsDirectiveConflict,
                sprintf(
                    'The robots meta tag (%s) and the X-Robots-Tag header (%s) contradict each other; '
                    .'the most restrictive one wins, which is rarely what was intended.',
                    implode(', ', $meta->directives),
                    implode(', ', $header->directives),
                ),
            );
        }

        if ($response->depth >= 1 && ($meta->hasNoindex() || $header->hasNoindex())) {
            $issues[] = new Issue(
                IssueType::NoindexOnLinkedPage,
                sprintf(
                    'The page is linked from the site but carries a noindex directive (%s).',
                    $meta->hasNoindex()
                        ? 'robots meta tag: '.implode(', ', $meta->directives)
                        : 'X-Robots-Tag: '.implode(', ', $header->directives),
                ),
            );
        }

        $canonical = $signals->canonicalElsewhere($response->url);

        if ($canonical !== null && ($meta->hasNoindex() || $header->hasNoindex())) {
            $issues[] = new Issue(
                IssueType::NoindexConflictsWithCanonical,
                sprintf(
                    'The page carries a noindex directive yet declares "%s" as its canonical; '
                    .'the two signals contradict each other, keep only one.',
                    $canonical,
                ),
            );
        }

        return $issues;
    }

    /**
     * @return list<Issue>
     */
    private function auditMetaRefresh(PageSignals $signals): array
    {
        if ($signals->metaRefreshUrl === null) {
            return [];
        }

        return [
            new Issue(
                IssueType::MetaRefreshRedirect,
                sprintf(
                    'The page redirects to "%s" with a meta refresh; use a 301 response instead.',
                    $signals->metaRefreshUrl,
                ),
            ),
        ];
    }

    /**
     * @return list<Issue>
     */
    private function auditHtmlLang(PageSignals $signals): array
    {
        if ($signals->htmlLang !== null) {
            return [];
        }

        return [new Issue(IssueType::MissingHtmlLang, 'The <html> element has no lang attribute.')];
    }

    /**
     * @return list<Issue>
     */
    private function auditHreflang(PageResponse $response, PageSignals $signals): array
    {
        if ($signals->isCanonicalizedVariant($response->url)) {
            return [];
        }

        $issues = [];

        if ($signals->bodyHreflangLinks !== []) {
            $first = $signals->bodyHreflangLinks[0];
            $issues[] = new Issue(
                IssueType::HreflangNotInHead,
                sprintf(
                    '%d hreflang link(s) sit outside <head> and are ignored (first: "%s" for "%s"). Check for an '
                    .'invalid element earlier in <head>, which ends it sooner than the source suggests.',
                    count($signals->bodyHreflangLinks),
                    $first->hreflang,
                    $first->href,
                ),
            );
        }

        if ($signals->hreflangLinks === []) {
            return $issues;
        }
        /** @var array<string, array<string, string>> $urlsByValue lower-cased hreflang => dedup key => URL */
        $urlsByValue = [];
        $hasXDefault = false;

        foreach ($signals->hreflangLinks as $link) {
            $reason = HreflangValidator::explain($link->hreflang);

            if ($reason !== null) {
                $issues[] = new Issue(
                    IssueType::HreflangInvalidCode,
                    sprintf('The hreflang value "%s" is invalid: %s.', $link->hreflang, $reason),
                );
            }

            if (!UrlResolver::isAbsoluteHttpUrl($link->href)) {
                $issues[] = new Issue(
                    IssueType::HreflangRelativeUrl,
                    sprintf(
                        'The "%s" alternate "%s" is not a fully-qualified URL, which hreflang requires.',
                        $link->hreflang,
                        $link->href,
                    ),
                );
            }

            $url = UrlResolver::resolve($response->url, $link->href);

            if ($url !== null) {
                $urlsByValue[strtolower($link->hreflang)][UrlResolver::dedupKey($url)] = $url;
            }

            $hasXDefault = $hasXDefault || $link->isXDefault();
        }

        foreach ($urlsByValue as $value => $urls) {
            if (count($urls) < 2) {
                continue;
            }

            $issues[] = new Issue(
                IssueType::HreflangConflictingUrls,
                sprintf(
                    'The hreflang value "%s" is declared for %d different URLs (%s).',
                    $value,
                    count($urls),
                    implode(', ', array_map(static fn(string $url): string => '"'.$url.'"', array_values($urls))),
                ),
            );
        }

        if (!isset($signals->hreflangUrls($response->url)[UrlResolver::dedupKey($response->url)])) {
            $issues[] = new Issue(
                IssueType::HreflangMissingSelf,
                'The page lists its hreflang alternates but not itself; each language version must list itself too.',
            );
        }

        $canonical = $signals->canonicalElsewhere($response->url);

        if ($canonical !== null) {
            $issues[] = new Issue(
                IssueType::HreflangCanonicalMismatch,
                sprintf(
                    'The page lists itself as a language version but declares "%s" as its canonical; a language '
                    .'version must be its own canonical, or search engines may drop it in favor of that URL.',
                    $canonical,
                ),
            );
        }

        if (!$hasXDefault) {
            $issues[] = new Issue(
                IssueType::HreflangMissingXDefault,
                'The page declares hreflang alternates without an "x-default" fallback for unmatched languages.',
            );
        }

        return $issues;
    }
}

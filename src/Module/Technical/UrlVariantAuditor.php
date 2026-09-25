<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical;

use Lbonnet\SeoBundle\Html\HtmlPageParser;
use Lbonnet\SeoBundle\Http\PageFetcher;
use Lbonnet\SeoBundle\Model\DisabledChecks;
use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\RedirectHop;
use Lbonnet\SeoBundle\Module\Technical\Model\CrawlContext;
use Lbonnet\SeoBundle\Module\Technical\Model\PageAudit;
use Lbonnet\SeoBundle\Robots\RobotsTxtCheckerInterface;
use Lbonnet\SeoBundle\Url\UrlResolver;

final class UrlVariantAuditor implements UrlVariantAuditorInterface
{
    private const INDEX_FILES = ['index.php', 'index.html'];

    private readonly DisabledChecks $disabledChecks;

    /**
     * @param list<string> $disabledChecks IssueType values whose variants are not even requested
     */
    public function __construct(
        private readonly PageFetcher $pageFetcher,
        private readonly HtmlPageParser $parser,
        private readonly int $sampleSize = 10,
        array $disabledChecks = [],
        private readonly ?RobotsTxtCheckerInterface $robotsTxtChecker = null,
    ) {
        $this->disabledChecks = new DisabledChecks($disabledChecks);
    }

    public function audit(array $pages, CrawlContext $context): array
    {
        $startPage = self::startPage($pages);
        $parts = $startPage !== null ? parse_url($startPage->url) : null;

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return [];
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $rootUrl = sprintf('%s://%s%s/', $scheme, $host, $port);
        $rootCanonical = self::canonicalOf($rootUrl, $pages);

        $audits = [];

        if ($scheme === 'https' && $port === '') {
            $audits[] = $this->auditVariant(
                'http://'.$host.'/',
                $rootCanonical,
                IssueType::HttpNotRedirectedToHttps,
                '"%1$s" answers %3$d instead of redirecting to "%2$s": '
                .'the whole site can be crawled and indexed over plain http too.',
                $context,
            );
        }

        $alternateHost = self::alternateHost($host);

        if ($alternateHost !== null) {
            $audits[] = $this->auditVariant(
                sprintf('%s://%s%s/', $scheme, $alternateHost, $port),
                $rootCanonical,
                IssueType::HostVariantNotRedirected,
                '"%1$s" answers %3$d instead of redirecting to "%2$s": '
                .'the whole site is duplicated on a second host name.',
                $context,
            );
        }

        foreach (self::INDEX_FILES as $indexFile) {
            $audits[] = $this->auditSameHostVariant(
                $rootUrl.$indexFile,
                $rootCanonical,
                IssueType::IndexFileDuplicate,
                '"%1$s" answers %3$d with a copy of the home page: '
                .'redirect it to "%2$s" or declare "%2$s" as its canonical.',
                $context,
            );
        }

        foreach ($this->sample($pages, $scheme, $host) as $page) {
            $audits[] = $this->auditSameHostVariant(
                self::trailingSlashVariant($page->url),
                $page->url,
                IssueType::TrailingSlashDuplicate,
                '"%1$s", the same URL with or without its trailing slash, answers %3$d: '
                .'redirect it to "%2$s" or declare "%2$s" as its canonical.',
                $context,
            );

            $caseVariant = self::caseVariant($page->url);

            if ($caseVariant !== null) {
                $audits[] = $this->auditSameHostVariant(
                    $caseVariant,
                    $page->url,
                    IssueType::CaseDuplicate,
                    '"%1$s", the same URL in another letter case, answers %3$d: '
                    .'redirect it to "%2$s" or declare "%2$s" as its canonical.',
                    $context,
                );
            }
        }

        return array_values(array_filter($audits));
    }

    private function auditSameHostVariant(
        string $variantUrl,
        string $canonicalUrl,
        IssueType $type,
        string $message,
        CrawlContext $context,
    ): ?PageAudit {
        if ($this->robotsTxtChecker?->isAllowed($variantUrl) === false) {
            return null;
        }

        return $this->auditVariant($variantUrl, $canonicalUrl, $type, $message, $context);
    }

    /**
     * @param string $message sprintf format receiving the variant URL, the canonical URL and the status code
     */
    private function auditVariant(
        string $variantUrl,
        string $canonicalUrl,
        IssueType $type,
        string $message,
        CrawlContext $context,
    ): ?PageAudit {
        if ($this->disabledChecks->has($type)) {
            return null;
        }

        $crawled = $context->responseFor($variantUrl);

        if ($crawled !== null && !UrlResolver::isSameUrl($crawled->url, $variantUrl)) {
            $crawled = null;
        }

        if ($crawled?->isRedirect() === true) {
            return null;
        }

        $response = $crawled ?? $this->pageFetcher->fetch($variantUrl);

        if ($response === null) {
            return null;
        }

        if ($response->isRedirect()) {
            $hop = new RedirectHop($variantUrl, $response->statusCode, $response->redirectLocation ?? '');

            if (!$hop->isTemporary()) {
                return null;
            }

            return new PageAudit(
                url: $variantUrl,
                statusCode: $response->statusCode,
                issues: [
                    new Issue(
                        IssueType::TemporaryRedirect,
                        sprintf(
                            'The redirect from "%s" is temporary (%d): search engines tend to keep the original URL '
                            .'indexed. Use 301 or 308 if the move is permanent.',
                            $variantUrl,
                            $response->statusCode,
                        ),
                    ),
                ],
            );
        }

        if (!$response->isSuccessful() || $response->html === null) {
            return null;
        }

        $href = $this->parser->parse($response->html, $variantUrl)->effectiveCanonicalHref();
        $canonical = $href !== null ? UrlResolver::resolve($variantUrl, $href) : null;

        if ($canonical !== null && UrlResolver::isSameUrl($canonical, $canonicalUrl)) {
            return null;
        }

        return new PageAudit(
            url: $variantUrl,
            statusCode: $response->statusCode,
            issues: [new Issue($type, sprintf($message, $variantUrl, $canonicalUrl, $response->statusCode))],
        );
    }

    /**
     * @param list<PageAudit> $pages
     *
     * @return list<PageAudit>
     */
    private function sample(array $pages, string $scheme, string $host): array
    {
        if ($this->sampleSize === 0) {
            return [];
        }

        $candidates = array_values(
            array_filter($pages, static fn(PageAudit $page): bool => self::isSampleable($page, $scheme, $host))
        );

        usort($candidates, static fn(PageAudit $a, PageAudit $b): int => $a->depth <=> $b->depth);

        return array_slice($candidates, 0, $this->sampleSize);
    }

    private static function isSampleable(PageAudit $page, string $scheme, string $host): bool
    {
        $parts = parse_url($page->url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === $scheme
            && strtolower($parts['host'] ?? '') === $host
            && !isset($parts['query'])
            && ($parts['path'] ?? '/') !== '/'
            && $page->canonicalElsewhere() === null;
    }

    /**
     * @param list<PageAudit> $pages
     */
    private static function startPage(array $pages): ?PageAudit
    {
        $startPage = null;

        foreach ($pages as $page) {
            if ($startPage === null || $page->depth < $startPage->depth) {
                $startPage = $page;
            }
        }

        return $startPage;
    }

    /**
     * @param list<PageAudit> $pages
     */
    private static function canonicalOf(string $url, array $pages): string
    {
        foreach ($pages as $page) {
            if (UrlResolver::isSameUrl($page->url, $url)) {
                return $page->canonicalElsewhere() ?? $url;
            }
        }

        return $url;
    }

    private static function alternateHost(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        if (str_starts_with($host, 'www.')) {
            return substr($host, 4);
        }

        return substr_count($host, '.') === 1 ? 'www.'.$host : null;
    }

    private static function trailingSlashVariant(string $url): string
    {
        return str_ends_with($url, '/') ? substr($url, 0, -1) : $url.'/';
    }

    private static function caseVariant(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (!is_string($path)) {
            return null;
        }

        $letters = (string)preg_replace('/%[0-9A-Fa-f]{2}/', '', $path);
        $toLower = preg_match('/[A-Z]/', $letters) === 1;

        $variantPath = (string)preg_replace_callback(
            '/%[0-9A-Fa-f]{2}|[^%]+/',
            static fn(array $match): string => match (true) {
                str_starts_with($match[0], '%') => $match[0],
                $toLower => strtolower($match[0]),
                default => strtoupper($match[0]),
            },
            $path,
        );

        if ($variantPath === $path) {
            return null;
        }

        return substr($url, 0, (int)strrpos($url, $path)).$variantPath;
    }
}

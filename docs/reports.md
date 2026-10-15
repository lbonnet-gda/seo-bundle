# Reports

Unless `storage.dir` is disabled, each audit is stored as JSON in that directory, one file per audit, named
`report-<date>-<hash>-<id>.json`. `<hash>` is derived from the start URL, so all the reports of one site share it,
and past `storage.max_reports` files for that URL the oldest are deleted.

```json
{
    "startUrl": "https://example.com",
    "createdAt": "2026-09-25T03:00:12+00:00",
    "modules": [
        "links",
        "on_page",
        "technical"
    ],
    "pagesRead": 128,
    "urlsChecked": 412,
    "totalDuration": 41.7,
    "truncated": false,
    "blockedByRobotsTxt": false,
    "startUrlStatusCode": null,
    "urlsDisallowedByRobotsTxt": 0,
    "urlsBeyondMaxDepth": 0,
    "issuesCount": 6,
    "issuesBySeverity": {
        "error": 2,
        "warning": 4,
        "notice": 0
    },
    "issuesByModule": {
        "links": 1,
        "on_page": 3,
        "technical": 2
    },
    "pages": [
        {
            "url": "https://example.com/about",
            "statusCode": 200,
            "depth": 1,
            "canonical": "https://example.com/about",
            "issues": [
                {
                    "type": "broken_internal_link",
                    "module": "links",
                    "severity": "error",
                    "message": "This page links to \"https://example.com/team\", which answers 404.",
                    "context": {
                        "target": "https://example.com/team",
                        "statusCode": 404,
                        "anchorText": "Our team"
                    }
                }
            ]
        }
    ]
}
```

`startUrlStatusCode` is null as long as the audit read at least one page. When it read none — the start URL
answers an error, redirects to one, or cannot be requested at all (then `0`) — it carries that status, the
console command fails, and nothing else in the report is meaningful.

`truncated` is true when the crawl stopped at `crawl.max_pages`: the site was only partly audited, and pages it did
not reach are neither listed nor checked.

`blockedByRobotsTxt` is true when the site's `robots.txt` answers a server error (5xx, 429, or no response at all).
Like Google, the crawl then goes no further than the start page. It is always false with
`crawl.respect_robots_txt: false`.

`urlsDisallowedByRobotsTxt` counts the internal URLs the crawl left out because `robots.txt` disallows them for
the configured user agent. They are audited by nobody, so the console command says so rather than letting the report
look complete.

`urlsBeyondMaxDepth` counts the internal URLs linked from pages sitting at `crawl.max_depth`, whose own content
was therefore never read. When the `links` module runs, their status was still checked — a broken link among them
is reported — but nothing else about them was: their title, their canonical, their directives were never looked at.
A URL reached by some shallower route as well is not counted, since it was read after all.

`pagesRead` counts the HTML pages parsed; `urlsChecked` counts what the crawl itself requested, redirect hops
included. The extra requests the modules make afterward — external links, sitemaps, URL variants, canonical and
hreflang targets — are not counted there. A page with no issue is still listed, so the report says what was audited,
not only what is wrong; an audit running the `links` module alone is the exception, since that module only knows the
pages carrying a link it flagged. URLs that are not crawled pages — a redirect, a sitemap, the `http://` version of
the home page — get their own entry, with `depth` set to `null`.

Pages are listed by depth, then by URL. Since answers come back in whatever order the network decides, crawl order
would differ from one audit to the next; sorting them means the same site always produces the same report, which is
what makes two reports worth comparing.

## Reading a report back

`ReportStorageInterface` only writes. Reading is a second, separate interface, so a storage that only ever writes
stays a valid storage:

```php
use Lbonnet\SeoBundle\Storage\ReportReaderInterface;

final class LastAudit
{
    // Nothing is injected when storage.dir is disabled, so keep the argument optional.
    public function __construct(private readonly ?ReportReaderInterface $reports = null)
    {
    }

    public function summary(string $startUrl): string
    {
        $stored = $this->reports?->latest($startUrl);

        if ($stored === null) {
            return 'No audit stored for that URL.';
        }

        return sprintf(
            '%s: %d issue(s) over %d page(s), read back from %s',
            $stored->createdAt->format('Y-m-d H:i'),
            $stored->report->getIssuesCount(),
            count($stored->report->pages),
            $stored->location,
        );
    }
}
```

`$stored->report` is a `SeoReport`, exactly as the audit built it. `history($startUrl, limit: 7)` returns the
reports still kept for that URL as `StoredReport` objects, newest first; the default limit of `0` returns all of
them.

The start URL is matched the way the crawl matches URLs, so `https://example.com` and `https://example.com/` find
the same history. Reading never fails on a report it cannot use: a file that is not valid JSON, or that has no
`startUrl` or no readable date, is skipped rather than hiding the ones behind it, and an issue naming a check that
no longer exists is dropped rather than costing the whole file. The counts are not read back either — `issuesCount`,
`issuesBySeverity` and `issuesByModule` are recomputed from the pages, so they cannot disagree with them.

`storage.max_reports` is therefore a retention window as much as a cleanup setting: at the default 30, a daily audit
can be compared against any of the last 30 days, and against nothing older.

## What has changed since the previous audit

Every audit compares itself with the previous stored report of the same site. The console command prints what it
found, and `SeoAuditCompletedEvent` carries it as `$event->changes`, a `ReportComparison`:

- `appeared` — issues this audit found and the previous one did not;
- `fixed` — issues the previous audit found and this one no longer reports;
- `stayed` — how many issues both audits found;
- `pagesNoLongerAudited` and `issuesNoLongerAudited` — pages the previous audit read and this one did not, and how
  many issues sat on them;
- `previousAuditAt` — when the audit being compared against ran.

`appeared` and `fixed` are lists of `IssueChange` (`pageUrl`, `issue`, `count`), ordered like the pages they sit on.
`countAppeared()`, `countFixed()` and `countPreviously()` sum them up, and `hasChanges()` is false when both audits
found exactly the same things.

`$event->changes` is `null` when there is nothing to compare against: the first audit of a site, or `storage.dir`
disabled. The previous report is read **before** the crawl starts — by the time an audit is over, the report it
would compare itself against is its own.

### What makes two issues the same issue

Two issues are the same issue when they sit on the same page and share their type and their context, `statusCode`,
`anchorText` and `timeout` excluded. That rule is what the words "appeared" and "fixed" mean here, so it is worth
knowing what it implies:

- a broken link that answered `404` and now times out is **not** a new issue, and rewriting the anchor text of a
  link does not get the issue counted as fixed and found again;
- the **message is not part of the identity**, so rewording a check in a new release of the bundle does not report
  every issue on the site as having appeared;
- two issues of the same type on one page are told apart by their context — two broken links by their `target`.
  When nothing distinguishes them, they are compared by **how many** there are: two yesterday and three today is
  one that appeared, without saying which of the three;
- pages are matched on the same key as the rest of the crawl, so a site moving from `http` to `https` does not
  report all of its issues as new.

### A page not audited again is not a page that was fixed

An issue that sat on a page the previous audit read and this one did not is neither fixed nor still there: it is
counted in `issuesNoLongerAudited` and appears in neither list. The page may well have been deleted. However, it may
just as well be blocked by `robots.txt` now, or left out by `max_pages` or `max_depth` — and a monitoring job that
announced those as fixes would be wrong on the day the crawl was simply cut short.

# Reports

Unless `storage.dir` is disabled, each audit is stored as JSON:

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

`pagesRead` counts the HTML pages parsed; `urlsChecked` counts what the crawl itself requested, redirect hops
included. The extra requests the modules make afterward — external links, sitemaps, URL variants, canonical and
hreflang targets — are not counted there. A
page with no issue is still listed, so the report says what was audited, not only what is wrong. URLs that are not
crawled pages — a redirect, a sitemap, the `http://` version of the home page — get their own entry, with `depth` set
to `null`.

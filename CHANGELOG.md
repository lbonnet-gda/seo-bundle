# Changelog

Notable changes to `lbonnet/seo-bundle`, newest first. Versions follow [semantic versioning](https://semver.org/),
with the usual issue for a 0.x package: a minor release may change behavior or break compatibility, and says so.

## [Unreleased]

### Changed

- `SeoReport::getIssuesByType()` now returns `list<IssueTypeCount>` rather than a list of array shapes. Reading
  `$group['count']` becomes `$counted->count`. The console output is byte for byte the same; what changes is that
  the structure can gain a field later without breaking whoever reads it, like every other value object the bundle
  hands out.
- `Severity::weight()` is private again, and `Severity::compareTo()` replaces it. Making the weights public in
  0.5.0 exposed `0`, `1` and `2` as part of the API for no reason beyond sorting; the order is now stated once, in
  the enum, and asked for as a comparison rather than read as a number.

## [0.5.0] - 2026-10-16

### Changed

- **The console summarizes before it lists.** The output now opens with a count per check, the worst severity first,
  with how many pages each one sits on: the whole audit in a dozen lines, however big the site. The table that
  follows lists the issues at or above `--fail-on`, the ones that decide the exit code, and a line says how many
  are counted but not listed. `-v` lists every one of them, as before.
- What is displayed does not change what fails: `--fail-on` alone still decides the exit code.

> On a 152-page site, 161 issues used to print 161 rows, nine of them errors buried in the middle of 113 notices.
> The same report now opens with 11 summary lines and lists the 9 errors — and the summary says the 94
> `hreflang_missing_x_default` sit on 94 distinct pages, which the old table could not answer.

## [0.4.0] - 2026-10-15

### Added

- **Stored reports can be read back.** `ReportReaderInterface` — `latest()` and `history()` — is kept apart from
  `ReportStorageInterface`, so a storage that only writes stays a valid storage. A report that names a check which
  no longer exists, or that misses a field a later version added, is still read; a file that cannot be read at all
  is skipped instead of hiding the reports behind it.
- **Every audit says what has changed since the previous one** on the same site: the issues that appeared, those that
  were fixed, and how many stayed. The console prints it after the issue table, and `SeoAuditCompletedEvent`
  carries it as `$event->changes`, so a listener can report the three new broken links instead of the same 161
  issues every morning. The previous report is read before the crawl starts, since by the end of an audit the
  report it would compare itself against is its own.
- Two issues are the same issue when they sit on the same page and share their type and their context, `statusCode`,
  `anchorText` and `timeout` excluded: a link that answered 404 and now times out has not been fixed and found
  again, and rewording a check's message does not report every issue on the site as new. Issues found on a page the
  new audit did not read are counted on their own — a page missing from a crawl is not a page that was fixed.

> Two audits of a 152-page site, run back to back, report what they should: the second says *Nothing have changed since
> the previous audit: the same 161 issue (s)* — not one false positive over 161 issues, through a real container, a
> real crawl, and a real report read back from disk.

## [0.3.0] - 2026-10-12

### Added

- `urlsBeyondMaxDepth` in the report, and a warning on the console: how many internal URLs were linked from pages
  sitting at the `crawl.max_depth` limit, so their own content was never read. When the `links` module runs their
  status was still checked, so a broken link among them is still reported — what they never got is the on-page and
  technical audit. URLs reached by a shorter route, and those `robots.txt` already accounts for, are not counted.

### Changed

- **`crawl.max_depth` now defaults to 10, instead of 3.** The low default guarded against crawls that run away;
  `max_pages: 500` has done that job since it was added, and unlike the depth limit it says when it fires. Set
  `max_depth: 3` back to keep the old shape.

> On a 152-page site, the old default silently audited 151 of them. It now audits all 152, for the same 158
> requests, and an audit run at depth 3 says plainly that 3 URLs were left out.

## [0.2.1] - 2026-10-06

### Fixed

- A host's slot was kept until the next request to that same host, which the concurrent crawl never makes: every
  audit ran one slot short of `crawl.concurrency`, and a host capped at one slot could stay locked. The slot now
  comes back as soon as the response is read, canceled, or dropped.
- `max_pages` counted requests instead of pages read, so a URL answering a redirect or a file cost a page that was
  never audited — and reaching the limit stopped the crawl for good. A site whose start URL redirects lost one page
  of its budget, and `--max-pages=1` read nothing at all.

> Audited a 152-page site before and after: the same 152 pages, the same 158 requests, the same 162 issues.

## [0.2.0] - 2026-10-02

### Added

- `crawl.concurrency` (default 4): how many requests may be in flight at once. A host is still called one request
  at a time, after its own delay, so this only helps when several hosts are involved — or on the audited site,
  which is exempt from the delay and given those slots unless its `robots.txt` asks for a `Crawl-delay`.

### Changed

- The per-host delay is counted from the end of a request rather than from its start: a host is left alone for
  `request_delay_ms` after we are done with it, however, long it took to answer.
- Report pages are listed by depth, then by URL. Answers now come back in whatever order the network decides, so
  crawl order would differ between two audits of the same site.
- Durations are measured with a monotonic clock, so an NTP step mid-audit cannot write a wrong duration into a
  report.

> On a 152-page site, an audit went from 152 s to 52 s, reporting the same 162 issues.

## [0.1.1] - 2026-09-28

### Fixed

- A URL first requested without its body — as the link target of a page at `max_depth` — stayed marked as visited,
  so a redirect leading the crawl back to it could no longer read it. The page silently never reached the report.
- External links were grouped with the audited site's rules, which fold `http://` onto `https://`. On another host
  these are two endpoints: a page linking to both collected two issues, both naming whichever URL was seen first.
  Each version is now checked on its own and reported with the URL the page actually carries.

## [0.1.0] - 2026-09-28

First release. It merges `lbonnet/link-checker-bundle`, `lbonnet/on-page-seo-bundle`,
`lbonnet/technical-seo-bundle` and `lbonnet/crawler-toolkit`, all four now abandoned, into one bundle that crawls a
site **once** and audits it on three fronts — 60 checks across the `links`, `on_page` and `technical` modules, each
of which can be turned off. Runs as a console command, a Messenger message, or behind an event listener, and writes
a JSON report per audit.

[Unreleased]: https://github.com/lbonnet-gda/seo-bundle/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/lbonnet-gda/seo-bundle/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/lbonnet-gda/seo-bundle/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/lbonnet-gda/seo-bundle/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/lbonnet-gda/seo-bundle/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/lbonnet-gda/seo-bundle/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/lbonnet-gda/seo-bundle/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/lbonnet-gda/seo-bundle/releases/tag/v0.1.0

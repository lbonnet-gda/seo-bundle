# SeoBundle

[![CI](https://github.com/lbonnet-gda/seo-bundle/actions/workflows/ci.yaml/badge.svg)](https://github.com/lbonnet-gda/seo-bundle/actions/workflows/ci.yaml)
[![Latest Version](https://img.shields.io/packagist/v/lbonnet/seo-bundle.svg)](https://packagist.org/packages/lbonnet/seo-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/lbonnet/seo-bundle.svg)](https://packagist.org/packages/lbonnet/seo-bundle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A Symfony bundle that crawls a site **once** and audits it on three fronts: its links, its on-page content, and the
technical signals that decide **whether a page gets indexed, and indexed once**.

Designed to run **outside the request/response cycle** — as a console command, a scheduled cron, or an async Messenger
worker — so it fits both CI pipelines and continuous monitoring of a live site.

Unlike a content crawler, it never lets the HTTP client follow redirects: a 3xx is a finding, not a detour. Every page
is requested with `max_redirects = 0` and chains are walked explicitly, so each hop stays visible.

## Requirements

- PHP >= 8.1
- Symfony 6.4, 7.x, or 8.x

## Installation

```bash
composer require lbonnet/seo-bundle
```

If you don't use Symfony Flex, enable the bundle manually in `config/bundles.php`:

```php
return [
    // ...
    Lbonnet\SeoBundle\SeoBundle::class => ['all' => true],
];
```

## Modules

The audit runs three modules over a single crawl. Each one can be turned off, and a module that is off sends no
request:

| Module      | What it audits                                                                 |
|-------------|--------------------------------------------------------------------------------|
| `links`     | Broken internal and external links                                             |
| `on_page`   | Titles, meta descriptions, headings and images                                 |
| `technical` | Canonical tags, indexing directives, robots.txt, sitemaps, redirects, hreflang |

## Quick start

```yaml
# config/packages/seo.yaml
seo:
    base_url: 'https://example.com'
```

```bash
php bin/console seo:check
```

The command prints every issue it finds and exits with `1` when one of them is an `error`, so it doubles as a CI
check. The audit also runs as a Messenger message, on a schedule, or behind your own event listener.

## Documentation

- [Checks](docs/checks.md) — the 60 checks, their severity, and what each one catches
- [Configuration](docs/configuration.md) — every option, with its default
- [Usage](docs/usage.md) — console command, Messenger, Scheduler, and notifications
- [Reports](docs/reports.md) — the JSON written after each audit

## Known trade-offs

- **A host is only ever called one request at a time.** Requests run concurrently — `crawl.concurrency` of them — but
  never two at once to the same host, and never before that host's delay has elapsed since the last answer. The audited
  site is the exception the setting exists for: it is given those slots, unless its `robots.txt` asks for a
  `Crawl-delay`, which puts it back to one at a time. So concurrency pays off on a site, not against one.
- **A redirect target is requested twice**: once while resolving the chain (headers only, the body is canceled), then
  again to read its markup. This keeps chain resolution independent of crawling, at the cost of one extra HEAD-sized
  request per redirect. Chains are walked one hop at a time, while the rest of the crawl carries on.
- **Pages are read with libxml**, not with DomCrawler, whose parser changes across PHP and Symfony versions and,
  through `masterminds/html5`, never closes `<head>` early. Like browsers, libxml closes `<head>` on the usual culprits
  (a stray `<div>`, a tracking `<img>`, stray text, a misplaced `<iframe>`), but not on an `<svg>` or a custom element.
  It also keeps a `<noscript>` holding an `<img>` inside `<head>`, which is how a JavaScript-enabled crawler reads it.
  This is what `canonical_not_in_head` and `hreflang_not_in_head` rely on.
- **JavaScript is not executed.** A link, a canonical, or a title that only exists after hydration is invisible to the
  audit, as it is to a search engine that does not render the page.

## Security

To report a vulnerability, please don't open a public issue — see [SECURITY.md](SECURITY.md) for how to report it
privately.

## License

MIT — see [LICENSE](LICENSE).

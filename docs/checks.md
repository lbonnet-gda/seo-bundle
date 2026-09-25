# Checks

Every issue carries a **severity**, and only `error` breaks a build by default (see `fail_on`). Any check can be
left out of the report entirely with `disabled_checks`; for the URL variant and sitemap checks, a disabled check
also sends no request.

### Links

| Check                          | Severity | What it catches                                                                                |
|--------------------------------|----------|------------------------------------------------------------------------------------------------|
| `broken_internal_link`         | error    | An internal link answering 4xx/5xx, unreachable, or redirecting to one of those                |
| `broken_external_link`         | error    | A link to another site answering 4xx/5xx, or unreachable                                       |
| `external_link_likely_blocked` | notice   | A 403/429/503 carrying a bot-protection signature (Cloudflare, Akamai...): probably not broken |

Internal targets cost no extra request: the crawl already fetched them. Links to other sites are checked once per URL
after the crawl: a HEAD, then a GET retry when the answer suggests the server mishandles it (400, 403, 404, 405, 406,
501). Each issue is reported on every page carrying the link, which is where it gets fixed.

### Titles, descriptions, and content

| Check                   | Severity | What it catches                                                |
|-------------------------|----------|----------------------------------------------------------------|
| `missing_title`         | error    | The page has no `<title>`                                      |
| `title_too_long`        | warning  | A title longer than `max_title_length`                         |
| `duplicate_title`       | warning  | A title shared with at least one other crawled page            |
| `missing_description`   | warning  | The page has no `<meta name="description">`                    |
| `description_too_long`  | notice   | A meta description longer than `max_description_length`        |
| `duplicate_description` | notice   | A meta description shared with at least one other crawled page |
| `missing_h1`            | warning  | The page has no `<h1>`                                         |
| `multiple_h1`           | notice   | The page has several `<h1>`                                    |
| `image_missing_alt`     | warning  | An `<img>` without an `alt` attribute                          |

Read from the page itself, except the two duplicate checks, which compare every crawled page with the others. Those two
leave out the pages search engines will not index anyway — a noindex directive, or a canonical pointing elsewhere —
since sharing a title with them is not a duplicate-content problem.

### Canonical tags

| Check                        | Severity | What it catches                                                                             |
|------------------------------|----------|---------------------------------------------------------------------------------------------|
| `canonical_multiple`         | error    | Several conflicting `<link rel="canonical">`: search engines ignore all of them             |
| `canonical_not_in_head`      | error    | A canonical outside `<head>` — usually an invalid element ending `<head>` early             |
| `canonical_target_not_ok`    | error    | The canonical URL answers 4xx/5xx                                                           |
| `canonical_target_redirects` | error    | The canonical URL answers 3xx instead of 200                                                |
| `canonical_target_noindex`   | error    | The canonical URL carries a `noindex` (meta tag, or `X-Robots-Tag` for an uncrawled target) |
| `canonical_relative`         | warning  | A canonical href that is not an absolute URL (an empty href included)                       |
| `canonical_chain`            | warning  | The canonical URL declares yet another canonical (A → B → C), or points back (A ↔ B)        |

### Indexing directives

| Check                              | Severity | What it catches                                                                      |
|------------------------------------|----------|--------------------------------------------------------------------------------------|
| `noindex_on_linked_page`           | error    | A page the site links to, but tells search engines not to index                      |
| `noindex_conflicts_with_canonical` | error    | A `noindex` combined with a canonical pointing at another URL: contradictory signals |
| `robots_directive_conflict`        | error    | The `robots` meta tag and the `X-Robots-Tag` header contradict each other            |

### robots.txt

| Check                                  | Severity | What it catches                                                                                                   |
|----------------------------------------|----------|-------------------------------------------------------------------------------------------------------------------|
| `robots_txt_server_error`              | error    | `robots.txt` answers 5xx or 429, or cannot be fetched at all (timeout, DNS): Google stops crawling the whole site |
| `robots_txt_disallow_all`              | error    | `robots.txt` blocks Googlebot from the site root                                                                  |
| `robots_txt_blocks_canonical_target`   | error    | A canonical URL is blocked for Googlebot                                                                          |
| `robots_txt_blocks_hreflang_alternate` | error    | An hreflang alternate is blocked for Googlebot                                                                    |

These read `robots.txt` the way Google does and evaluate its rules for Googlebot, whatever user agent the crawler sends,
and whatever `respect_robots_txt` says. A problem with the file itself is reported once, on its URL.

### Redirects

| Check                       | Severity | What it catches                                                                    |
|-----------------------------|----------|------------------------------------------------------------------------------------|
| `redirect_loop`             | error    | A chain that comes back to a URL it already visited                                |
| `redirect_to_error`         | error    | A chain that ends on a 4xx/5xx                                                     |
| `redirect_chain_too_long`   | warning  | More hops than `max_redirect_hops` allows                                          |
| `temporary_redirect`        | warning  | A 302, 303 or 307 on the way: search engines tend to keep the original URL indexed |
| `internal_link_to_redirect` | warning  | An internal link pointing at a redirect instead of its target                      |
| `meta_refresh_redirect`     | warning  | `<meta http-equiv="refresh">` used instead of a 301                                |

An issue about a redirect itself is reported **once**, on the redirecting URL, however many pages link to it. Each
linking page gets its own `internal_link_to_redirect` instead.

### URL variants

| Check                          | Severity | What it catches                                                          |
|--------------------------------|----------|--------------------------------------------------------------------------|
| `http_not_redirected_to_https` | error    | The `http://` home page answers 200 instead of redirecting to `https://` |
| `host_variant_not_redirected`  | error    | The home page answers 200 on both `example.com` and `www.example.com`    |
| `index_file_duplicate`         | warning  | `/index.php` or `/index.html` answers 200 with a copy of the home page   |
| `trailing_slash_duplicate`     | warning  | A page also answers 200 with its trailing slash added or removed         |
| `case_duplicate`               | warning  | A page also answers 200 with its path in another letter case             |

A variant is fine when it redirects permanently, answers an error, or declares the crawled URL as its canonical. The
first three checks cost up to four requests per audit, on the home page; the last two run on a sample of
`url_variants_sample_size` pages, shallowest first.

### Sitemaps

| Check                               | Severity | What it catches                                                                              |
|-------------------------------------|----------|----------------------------------------------------------------------------------------------|
| `sitemap_missing`                   | notice   | No `Sitemap:` line in `robots.txt`, and no `/sitemap.xml` either                             |
| `sitemap_not_ok`                    | error    | A declared sitemap, or one listed in a sitemap index, does not answer 200                    |
| `sitemap_invalid`                   | error    | A sitemap that is not valid XML, over 50,000 entries or 50 MB, or a nested sitemap index     |
| `sitemap_url_invalid`               | error    | A listed URL that is relative or on another host, or a sitemap outside its index's directory |
| `sitemap_url_not_ok`                | error    | A listed URL answering 4xx/5xx                                                               |
| `sitemap_url_redirects`             | warning  | A listed URL answering 3xx instead of the final URL                                          |
| `sitemap_url_noindex`               | error    | A listed URL carrying a noindex directive                                                    |
| `sitemap_url_not_canonical`         | error    | A listed URL whose canonical points elsewhere                                                |
| `sitemap_url_blocked_by_robots_txt` | error    | A listed URL blocked for Googlebot                                                           |
| `page_missing_from_sitemap`         | notice   | A crawled page that is indexable and canonical, but listed in no sitemap                     |

Sitemaps come from the `Sitemap:` lines of `robots.txt`, or from `/sitemap.xml` when there are none. A sitemap index is
followed one level deep, gzip is supported, and at most `max_sitemap_files` files are fetched.

### hreflang

| Check                           | Severity | What it catches                                                                                                                            |
|---------------------------------|----------|--------------------------------------------------------------------------------------------------------------------------------------------|
| `hreflang_invalid_code`         | error    | Not an ISO 639-1 language, with an optional ISO 15924 script and ISO 3166-1 alpha-2 region (`en-UK`, `es-419`, `fr_FR`, a region alone...) |
| `hreflang_not_in_head`          | error    | hreflang links outside `<head>` — usually an invalid element ending `<head>` early                                                         |
| `hreflang_relative_url`         | error    | An alternate URL that is not fully qualified                                                                                               |
| `hreflang_conflicting_urls`     | error    | The same hreflang value declared for several URLs                                                                                          |
| `hreflang_missing_self`         | error    | The page lists its alternates but not itself                                                                                               |
| `hreflang_not_reciprocal`       | error    | A crawled alternate does not link back to the page, so both annotations are ignored                                                        |
| `hreflang_target_not_ok`        | error    | An alternate answers 4xx/5xx                                                                                                               |
| `hreflang_target_redirects`     | error    | An alternate answers 3xx instead of 200                                                                                                    |
| `hreflang_target_not_canonical` | error    | A crawled alternate declares another URL as its canonical                                                                                  |
| `hreflang_target_noindex`       | error    | An alternate carries a `noindex` (meta tag, or `X-Robots-Tag` for an uncrawled alternate)                                                  |
| `hreflang_canonical_mismatch`   | error    | A page listing itself as a language version declares another URL as its canonical                                                          |
| `hreflang_missing_x_default`    | notice   | No `x-default` fallback for unmatched languages                                                                                            |

### Markup

| Check               | Severity | What it catches                     |
|---------------------|----------|-------------------------------------|
| `missing_html_lang` | warning  | `<html>` without a `lang` attribute |

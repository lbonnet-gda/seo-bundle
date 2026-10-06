# Configuration

Create `config/packages/seo.yaml`:

```yaml
seo:
    base_url: 'https://example.com' # default site to audit (example; default: null, pass the URL to the command instead)
    fail_on: error # lowest severity that makes the command exit non-zero: error, warning or notice
    disabled_checks: [ ] # issue types to leave out entirely, e.g. ['image_missing_alt']

    crawl:
        max_depth: 3 # crawl depth from the start URL (0 = the start page only)
        max_pages: 500 # pages read per audit before stopping; the report is then marked as truncated (0 = no limit)
        concurrency: 4 # requests in flight at once, all hosts together; a host is still called one at a time
        timeout: 10 # per-request timeout (seconds)
        user_agent: 'Mozilla/5.0 (compatible; SeoBundle/1.0; +https://github.com/lbonnet-gda/seo-bundle)'
        exclude_patterns: # URLs matching these regexes are never requested (examples; default: none)
            - '#/admin#'
            - '#\.pdf$#'
        request_delay_ms: 200 # delay between requests to a host, from one end to the next start; audited host exempt
        respect_robots_txt: true # skip what robots.txt disallows and honor its Crawl-delay
        allow_private_network: false # set true only to intentionally audit an internal network (SSRF risk otherwise)

    links: # "links: false" disables the module
        check_external: true # check the status of links pointing to other sites

    on_page: # "on_page: false" disables the module
        max_title_length: 60
        max_description_length: 155

    technical: # "technical: false" disables the module
        max_redirect_hops: 1 # how many redirects a URL may go through before the chain is reported
        resolve_external_targets: true # request canonical, hreflang and sitemap targets the crawl did not visit
        max_external_target_checks: 200 # cap on those extra requests per audit, robots.txt included (0 = unlimited)
        url_variants_sample_size: 10 # pages checked for trailing slash and letter case duplicates (0 = none)
        max_sitemap_files: 10 # sitemap files, indexes included, fetched per audit (0 = unlimited)

    storage:
        dir: '%kernel.project_dir%/var/seo' # JSON reports directory; set to null/empty to disable
        max_reports: 30 # oldest reports are deleted past this count per audited URL (0 = keep forever)
```

Apart from `base_url` and `exclude_patterns`, which are examples, every value above is the default, so only the
keys you want to change need to be set.

`max_pages` counts the pages that were read, not the URLs that were requested: one that answers a redirect, a PDF
or nothing at all gives its place back to the next page in the queue. On an audit that does reach the limit, which
pages make up the last few depends on the order the answers came back in. Since a place is only freed once an
answer is in — below the limit, two audits of the same site still read the same pages.

`disabled_checks` values are validated against the [known issue types](checks.md) at container build time, and
`exclude_patterns` entries against the regex engine, so a typo fails fast instead of silently doing nothing. A
pattern missing its delimiters — `'/admin'` instead of `'#/admin#'` — would otherwise exclude no URL at all. Patterns
passed to the command or to `CheckSeoMessage` are checked the same way.

# SeoBundle

A Symfony bundle that crawls a site **once** to audit its links, on-page content and technical SEO signals: broken
links, titles and descriptions, canonical tags, indexing directives, redirects, robots.txt, sitemaps and hreflang.

## Requirements

- PHP >= 8.1
- Symfony 6.4, 7.x or 8.x

## Configuration

```yaml
seo:
    base_url: 'https://example.com'
    fail_on: error # lowest severity that makes seo:check fail: error, warning or notice
    disabled_checks: [ ] # issue types to leave out entirely; a disabled check sends no request

    crawl:
        max_depth: 3
        max_pages: 500 # 0 = no limit
        timeout: 10
        user_agent: 'Mozilla/5.0 (compatible; SeoBundle/1.0; +https://github.com/lbonnet-gda/seo-bundle)'
        exclude_patterns: [ ]
        request_delay_ms: 200
        respect_robots_txt: true
        allow_private_network: false

    links: # "links: false" disables the module
        check_external: true

    on_page:
        max_title_length: 60
        max_description_length: 155

    technical:
        max_redirect_hops: 1
        resolve_external_targets: true
        max_external_target_checks: 200
        url_variants_sample_size: 10
        max_sitemap_files: 10

    storage:
        dir: '%kernel.project_dir%/var/seo' # null to disable
        max_reports: 30
```

## License

MIT

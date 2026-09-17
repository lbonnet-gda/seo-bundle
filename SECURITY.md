# Security Policy

## Supported Versions

| Version    | Supported |
|------------|-----------|
| Latest 0.x | ✅        |
| Older 0.x  | ❌        |

This project is pre-1.0 and follows [SemVer](https://semver.org/): only the latest `0.x` release receives security
fixes.

## Reporting a Vulnerability

Please **don't** open a public issue for a security vulnerability. Instead, use GitHub's
[private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing/privately-reporting-a-security-vulnerability)
via this repository's **Security** tab.

You should expect an initial response within a few days.

This bundle crawls and requests URLs discovered on the audited site's own pages; SSRF, request-forgery, and
denial-of-service concerns around that behavior are explicitly in scope, including in its underlying dependencies.

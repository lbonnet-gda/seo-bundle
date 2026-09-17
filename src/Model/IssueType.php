<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Model;

enum IssueType: string
{
    // --- Links ---
    case BrokenInternalLink = 'broken_internal_link';
    case BrokenExternalLink = 'broken_external_link';
    case ExternalLinkLikelyBlocked = 'external_link_likely_blocked';

    // --- On-page: titles and descriptions ---
    case MissingTitle = 'missing_title';
    case TitleTooLong = 'title_too_long';
    case DuplicateTitle = 'duplicate_title';
    case MissingDescription = 'missing_description';
    case DescriptionTooLong = 'description_too_long';
    case DuplicateDescription = 'duplicate_description';

    // --- On-page: headings and images ---
    case MissingH1 = 'missing_h1';
    case MultipleH1 = 'multiple_h1';
    case ImageMissingAlt = 'image_missing_alt';

    // --- Technical: Canonical tags ---
    case CanonicalMultiple = 'canonical_multiple';
    case CanonicalNotInHead = 'canonical_not_in_head';
    case CanonicalRelative = 'canonical_relative';
    case CanonicalTargetNotOk = 'canonical_target_not_ok';
    case CanonicalTargetRedirects = 'canonical_target_redirects';
    case CanonicalTargetNoindex = 'canonical_target_noindex';
    case CanonicalChain = 'canonical_chain';

    // --- Technical: Indexing directives ---
    case NoindexOnLinkedPage = 'noindex_on_linked_page';
    case NoindexConflictsWithCanonical = 'noindex_conflicts_with_canonical';
    case RobotsDirectiveConflict = 'robots_directive_conflict';

    // --- Technical: robots.txt ---
    case RobotsTxtServerError = 'robots_txt_server_error';
    case RobotsTxtDisallowAll = 'robots_txt_disallow_all';
    case RobotsTxtBlocksCanonicalTarget = 'robots_txt_blocks_canonical_target';
    case RobotsTxtBlocksHreflangAlternate = 'robots_txt_blocks_hreflang_alternate';

    // --- Technical: Sitemaps ---
    case SitemapMissing = 'sitemap_missing';
    case SitemapNotOk = 'sitemap_not_ok';
    case SitemapInvalid = 'sitemap_invalid';
    case SitemapUrlInvalid = 'sitemap_url_invalid';
    case SitemapUrlNotOk = 'sitemap_url_not_ok';
    case SitemapUrlRedirects = 'sitemap_url_redirects';
    case SitemapUrlNoindex = 'sitemap_url_noindex';
    case SitemapUrlNotCanonical = 'sitemap_url_not_canonical';
    case SitemapUrlBlockedByRobotsTxt = 'sitemap_url_blocked_by_robots_txt';
    case PageMissingFromSitemap = 'page_missing_from_sitemap';

    // --- Technical: Redirects ---
    case RedirectLoop = 'redirect_loop';
    case RedirectToError = 'redirect_to_error';
    case RedirectChainTooLong = 'redirect_chain_too_long';
    case TemporaryRedirect = 'temporary_redirect';
    case InternalLinkToRedirect = 'internal_link_to_redirect';
    case MetaRefreshRedirect = 'meta_refresh_redirect';

    // --- Technical: URL variants ---
    case HttpNotRedirectedToHttps = 'http_not_redirected_to_https';
    case HostVariantNotRedirected = 'host_variant_not_redirected';
    case IndexFileDuplicate = 'index_file_duplicate';
    case TrailingSlashDuplicate = 'trailing_slash_duplicate';
    case CaseDuplicate = 'case_duplicate';

    // --- Technical: hreflang ---
    case HreflangInvalidCode = 'hreflang_invalid_code';
    case HreflangNotInHead = 'hreflang_not_in_head';
    case HreflangRelativeUrl = 'hreflang_relative_url';
    case HreflangConflictingUrls = 'hreflang_conflicting_urls';
    case HreflangMissingSelf = 'hreflang_missing_self';
    case HreflangNotReciprocal = 'hreflang_not_reciprocal';
    case HreflangTargetNotOk = 'hreflang_target_not_ok';
    case HreflangTargetRedirects = 'hreflang_target_redirects';
    case HreflangTargetNotCanonical = 'hreflang_target_not_canonical';
    case HreflangTargetNoindex = 'hreflang_target_noindex';
    case HreflangCanonicalMismatch = 'hreflang_canonical_mismatch';
    case HreflangMissingXDefault = 'hreflang_missing_x_default';

    // --- Technical: Page-level markup ---
    case MissingHtmlLang = 'missing_html_lang';

    public function severity(): Severity
    {
        return match ($this) {
            self::BrokenInternalLink,
            self::BrokenExternalLink,
            self::MissingTitle,
            self::CanonicalMultiple,
            self::CanonicalNotInHead,
            self::CanonicalTargetNotOk,
            self::CanonicalTargetRedirects,
            self::CanonicalTargetNoindex,
            self::NoindexOnLinkedPage,
            self::NoindexConflictsWithCanonical,
            self::RobotsDirectiveConflict,
            self::RobotsTxtServerError,
            self::RobotsTxtDisallowAll,
            self::RobotsTxtBlocksCanonicalTarget,
            self::RobotsTxtBlocksHreflangAlternate,
            self::SitemapNotOk,
            self::SitemapInvalid,
            self::SitemapUrlInvalid,
            self::SitemapUrlNotOk,
            self::SitemapUrlNoindex,
            self::SitemapUrlNotCanonical,
            self::SitemapUrlBlockedByRobotsTxt,
            self::RedirectLoop,
            self::RedirectToError,
            self::HttpNotRedirectedToHttps,
            self::HostVariantNotRedirected,
            self::HreflangInvalidCode,
            self::HreflangNotInHead,
            self::HreflangRelativeUrl,
            self::HreflangConflictingUrls,
            self::HreflangMissingSelf,
            self::HreflangNotReciprocal,
            self::HreflangTargetNotOk,
            self::HreflangTargetRedirects,
            self::HreflangTargetNotCanonical,
            self::HreflangTargetNoindex,
            self::HreflangCanonicalMismatch => Severity::Error,

            self::CanonicalRelative,
            self::CanonicalChain,
            self::RedirectChainTooLong,
            self::TemporaryRedirect,
            self::SitemapUrlRedirects,
            self::InternalLinkToRedirect,
            self::MetaRefreshRedirect,
            self::IndexFileDuplicate,
            self::TrailingSlashDuplicate,
            self::CaseDuplicate,
            self::MissingHtmlLang,
            self::TitleTooLong,
            self::DuplicateTitle,
            self::MissingDescription,
            self::MissingH1,
            self::ImageMissingAlt => Severity::Warning,

            self::SitemapMissing,
            self::PageMissingFromSitemap,
            self::HreflangMissingXDefault,
            self::ExternalLinkLikelyBlocked,
            self::DescriptionTooLong,
            self::DuplicateDescription,
            self::MultipleH1 => Severity::Notice,
        };
    }

    public function module(): Module
    {
        return match ($this) {
            self::BrokenInternalLink,
            self::BrokenExternalLink,
            self::ExternalLinkLikelyBlocked => Module::Links,

            self::MissingTitle,
            self::TitleTooLong,
            self::DuplicateTitle,
            self::MissingDescription,
            self::DescriptionTooLong,
            self::DuplicateDescription,
            self::MissingH1,
            self::MultipleH1,
            self::ImageMissingAlt => Module::OnPage,

            self::CanonicalMultiple,
            self::CanonicalNotInHead,
            self::CanonicalRelative,
            self::CanonicalTargetNotOk,
            self::CanonicalTargetRedirects,
            self::CanonicalTargetNoindex,
            self::CanonicalChain,
            self::NoindexOnLinkedPage,
            self::NoindexConflictsWithCanonical,
            self::RobotsDirectiveConflict,
            self::RobotsTxtServerError,
            self::RobotsTxtDisallowAll,
            self::RobotsTxtBlocksCanonicalTarget,
            self::RobotsTxtBlocksHreflangAlternate,
            self::SitemapMissing,
            self::SitemapNotOk,
            self::SitemapInvalid,
            self::SitemapUrlInvalid,
            self::SitemapUrlNotOk,
            self::SitemapUrlRedirects,
            self::SitemapUrlNoindex,
            self::SitemapUrlNotCanonical,
            self::SitemapUrlBlockedByRobotsTxt,
            self::PageMissingFromSitemap,
            self::RedirectLoop,
            self::RedirectToError,
            self::RedirectChainTooLong,
            self::TemporaryRedirect,
            self::InternalLinkToRedirect,
            self::MetaRefreshRedirect,
            self::HttpNotRedirectedToHttps,
            self::HostVariantNotRedirected,
            self::IndexFileDuplicate,
            self::TrailingSlashDuplicate,
            self::CaseDuplicate,
            self::HreflangInvalidCode,
            self::HreflangNotInHead,
            self::HreflangRelativeUrl,
            self::HreflangConflictingUrls,
            self::HreflangMissingSelf,
            self::HreflangNotReciprocal,
            self::HreflangTargetNotOk,
            self::HreflangTargetRedirects,
            self::HreflangTargetNotCanonical,
            self::HreflangTargetNoindex,
            self::HreflangCanonicalMismatch,
            self::HreflangMissingXDefault,
            self::MissingHtmlLang => Module::Technical,
        };
    }
}

# Changelog

All notable changes to REST Posts Embedder will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.9.0] - 2025-10-25

### Added
- **PHPDoc comments** for all functions with detailed descriptions and parameters
- **Plugin constants** for default values (endpoint, count, cache expiration, min/max values)
- **Cache management** section in admin with clear cache button
- **Lazy loading** for featured images using native `loading="lazy"` attribute
- **Developer filters/hooks** for extensibility:
  - `rest_posts_embedder_endpoint` - Filter endpoint URL
  - `rest_posts_embedder_count` - Filter post count
  - `rest_posts_embedder_post_html` - Filter individual post HTML
  - `rest_posts_embedder_output` - Filter complete output HTML
- **Admin notices** with success/error messages for settings and cache operations
- **Nonce verification** for cache clear action

### Improved
- **Error messages** now include specific details (error codes, endpoint URLs) for easier debugging
- Error messages include helpful suggestions for resolution
- All default values now use centralized constants
- CSS version now uses plugin version constant for automatic cache busting
- Better code organization with comprehensive documentation

### Changed
- Replaced hardcoded default values with constants throughout codebase
- Enhanced validation error messages with context and ranges
- Cache expiration now uses constant instead of hardcoded value

## [2.8.1] - 2025-10-25

### Fixed
- Replaced deprecated `date_i18n()` with modern `wp_date()` function for better timezone handling
- Removed overly specific CSS selectors targeting post ID #1423
- Added generic `.embed-posts-inline` class for reusable inline image layouts

### Improved
- Added REST API endpoint URL validation with warning messages
- Added check for "wp-json" in endpoint URL to ensure valid WordPress REST API
- Improved CSS portability across different sites and themes
- Better responsive design for inline image layouts

## [2.8.0] - 2025-10-25

### Fixed
- Fixed namespace issues in admin and shortcode action/filter hooks
- Added server-side validation for post count (1-20 range)
- Added JSON decode error handling with proper error messages
- Replaced deprecated `_e()` translation functions with `esc_html_e()`
- Updated CSS versioning to use plugin version instead of hardcoded value

### Improved
- Enhanced error handling with specific messages for JSON parsing failures
- Added validation error messages for admin settings
- Better array validation for remote posts response

## [2.7.1] - 2025-10-25

### Fixed
- Fixed namespace inconsistency in hook registrations (plugins_loaded, plugin_action_links, shortcode)
- Fixed incorrect uninstall constant check (WP_UNINSTALL_HOOK → WP_UNINSTALL_PLUGIN)
- Fixed cache cleanup to properly delete all transients with plugin prefix pattern

### Security
- Added rel="noopener noreferrer" to all external links to prevent tabnabbing attacks

## [2.7.0] - Previous Release
- Enhanced features (existing version)

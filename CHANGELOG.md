# Changelog

All notable changes to REST Posts Embedder will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] - 2025-10-25

### 🚀 Major Feature: Multi-Source Feed Support

This is a **major release** with breaking changes in the admin interface, but full backward compatibility for existing installations.

### Added
- **Multiple feed source management** - Configure and manage unlimited REST API sources
- **New admin interface** with tabbed navigation:
  - **Feed Sources tab** - Add, edit, delete, and manage multiple sources
  - **Legacy Settings tab** - Backward compatible single-source settings
  - **Cache Management tab** - Dedicated cache clearing interface
- **Source-specific shortcodes** - Use `[posts_embedder source="source-id"]` to display different feeds
- **Source enable/disable toggle** - Control which sources are active
- **Source metadata** - Each source has unique ID, name, endpoint, and post count
- **Automatic migration** - Existing single-source configurations automatically migrate to new format
- **Visual source status indicators** - See at a glance which sources are enabled/disabled

### Changed
- Admin interface completely redesigned with tabbed navigation
- Settings page now focuses on multi-source management
- Old single-source settings moved to "Legacy Settings" tab
- Cache management moved to dedicated tab

### Technical Details
- New database option: `rest_posts_embedder_sources` (array of source configurations)
- Source structure: `id`, `name`, `endpoint`, `count`, `enabled`
- Shortcode accepts new `source` parameter
- Full backward compatibility maintained for existing installations

### Usage Examples

**Display default source (backward compatible):**
```
[posts_embedder]
```

**Display specific source:**
```
[posts_embedder source="my-blog"]
[posts_embedder source="news-feed"]
[posts_embedder source="portfolio-posts"]
```

**Override source settings:**
```
[posts_embedder source="my-blog" count="10"]
```

### Migration Notes
- Existing single-source configurations automatically convert to a "default" source on activation
- Legacy settings still work and are available in the "Legacy Settings" tab
- No action required from users - everything continues to work as before

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

## [2.7.1] - 2024-10-17

### Fixed
- Fixed namespace inconsistency in hook registrations (plugins_loaded, plugin_action_links, shortcode)
- Fixed incorrect uninstall constant check (WP_UNINSTALL_HOOK → WP_UNINSTALL_PLUGIN)
- Fixed cache cleanup to properly delete all transients with plugin prefix pattern

### Security
- Added rel="noopener noreferrer" to all external links to prevent tabnabbing attacks

## [2.7.0] - 2024-12-14
- Enhanced features (existing version)

## [2.0.1] - 2023-11-10
- Added internationalization support
- Improved error handling
- Enhanced caching mechanism

## [1.5.2] - 2022-10-25
- Enhanced features (existing version)

## [1.4.0] - 2022-10-28
- Added admin panel

## [1.0] - 2022-10-11 
- Initial release

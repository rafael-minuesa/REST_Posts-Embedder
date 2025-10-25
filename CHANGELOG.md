# Changelog

All notable changes to REST Posts Embedder will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.5.1] - 2025-10-25

### Fixed
- **Featured images not displaying** - Added multiple fallback sizes (medium → thumbnail → full → source_url)
- **Grid layout broken** - Fixed `.wrapper` CSS causing posts to stack instead of display side-by-side
- **CSS width issues** - Changed wrapper from 50% width with float to 100% width without float
- Grid columns now properly applied and posts display in configured columns

### Added
- **Show/Hide Featured Images** settings in Styling tab:
  - Option to show/hide images on Desktop
  - Option to show/hide images on Mobile
  - Independent control for each device type
- Dynamic CSS to hide images based on settings

### Improved
- Featured image detection now tries multiple image sizes before giving up
- Better fallback handling when medium size doesn't exist
- More robust image URL extraction from REST API response

### Technical Details
- New options: `embed_posts_show_images_desktop`, `embed_posts_show_images_mobile`
- Wrapper CSS override in inline styles to fix layout
- Conditional CSS injection for image visibility

## [3.5.0] - 2025-10-25

### Summary
Complete admin interface overhaul with styling controls, custom CSS, configurable caching, and comprehensive documentation. This version represents the culmination of all v3.x improvements.

### All Features from v3.1.0 - v3.4.0
- Comprehensive API endpoint documentation
- Styling tab with column controls
- Custom CSS support
- Configurable cache expiration
- Enhanced user experience throughout

---

## [3.4.0] - 2025-10-25

### Added
- **Configurable Cache Expiration** - Choose how long to cache API responses:
  - Preset options: 1 hour, 3 hours, 6 hours, 12 hours, 24 hours
  - Custom hours option (1-168 hours / 1 week maximum)
  - Dynamic JavaScript to show/hide custom hours field
- New function: `get_cache_expiration()` to calculate cache time dynamically

### Changed
- Cache Management tab reorganized with separate sections
- Cache duration no longer hardcoded to 1 hour
- Shortcode now uses configurable cache expiration

### Technical Details
- New options: `embed_posts_cache_expiration`, `embed_posts_cache_custom_hours`
- Dynamic cache calculation based on user selection
- Supports preset and custom durations

---

## [3.3.0] - 2025-10-25

### Added
- **Custom CSS Textarea** in Styling tab
  - Large code-formatted textarea for custom styles
  - Helpful examples and usage instructions
  - Automatically enqueued on frontend
  - No need for `<style>` tags
- Custom CSS sanitization and validation

### Technical Details
- New option: `embed_posts_custom_css`
- Inline CSS injection via `wp_add_inline_style()`
- Automatic sanitization with `wp_strip_all_tags()`

---

## [3.2.0] - 2025-10-24

### Added
- **Column Configuration** for responsive grid layouts:
  - Desktop columns: 1-5 columns (default: 2)
  - Mobile columns: 1-3 columns (default: 1)
  - Responsive breakpoint at 768px
- Dynamic CSS grid generation based on settings
- Column validation with min/max constraints

### Changed
- CSS grid columns now dynamically set via inline CSS
- Removed hardcoded `auto-fit` grid template

### Technical Details
- New options: `embed_posts_columns_desktop`, `embed_posts_columns_mobile`
- New sanitization functions: `sanitize_columns_desktop()`, `sanitize_columns_mobile()`
- Inline CSS generated on every page load with current settings

---

## [3.1.0] - 2025-10-23

### Added
- **Comprehensive API Endpoint Documentation** in Feed Sources tab:
  - How to construct REST API endpoint URLs
  - Basic endpoint example with `?_embed` parameter
  - Filter by categories with example (`?categories=111`)
  - Filter by tags with example (`?tags=222`)
  - Combine multiple filters example
  - Instructions on finding category/tag IDs in WordPress admin
  - Note about importance of `?_embed` parameter
- Info box with prominent styling and clear examples

### Changed
- Renamed "Legacy Settings" tab to "Styling"
- Removed REST API Endpoint field from Styling tab
- Endpoint configuration now only in Feed Sources tab
- Improved admin interface organization

### Improved
- Better user guidance throughout admin
- Clearer separation of concerns between tabs
- More helpful field descriptions

## [3.0.0] - 2025-10-22

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

## [2.9.0] - 2025-10-21

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

## [2.8.1] - 2025-10-10

### Fixed
- Replaced deprecated `date_i18n()` with modern `wp_date()` function for better timezone handling
- Removed overly specific CSS selectors targeting post ID #1423
- Added generic `.embed-posts-inline` class for reusable inline image layouts

### Improved
- Added REST API endpoint URL validation with warning messages
- Added check for "wp-json" in endpoint URL to ensure valid WordPress REST API
- Improved CSS portability across different sites and themes
- Better responsive design for inline image layouts

## [2.8.0] - 2025-10-20

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

## [2.7.1] - 2025-10-17

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

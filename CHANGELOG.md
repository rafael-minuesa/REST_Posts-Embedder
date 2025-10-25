# Changelog

All notable changes to REST Posts Embedder will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

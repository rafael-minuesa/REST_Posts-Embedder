# Changelog

All notable changes to REST Posts Embedder will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.7.1] - 2025-10-25

### Fixed
- Fixed namespace inconsistency in hook registrations (plugins_loaded, plugin_action_links, shortcode)
- Fixed incorrect uninstall constant check (WP_UNINSTALL_HOOK → WP_UNINSTALL_PLUGIN)
- Fixed cache cleanup to properly delete all transients with plugin prefix pattern

### Security
- Added rel="noopener noreferrer" to all external links to prevent tabnabbing attacks

## [2.7.0] - Previous Release
- Enhanced features (existing version)

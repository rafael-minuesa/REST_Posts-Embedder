=== REST Posts Embedder ===
Contributors: Rafael Minuesa
Donate link: https://prowoos.com/
Tags: rest, api, posts, embed
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 3.7.0
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embed posts from a specified WordPress REST API endpoint with enhanced features.

== Description ==
REST Posts Embedder allows you to easily embed posts from any WordPress REST API endpoint into your website. 
Features include:

* Customizable endpoint configuration
* Responsive grid layout
* Caching mechanism
* Internationalization support

== Installation ==
1. Upload the plugin files to the `/wp-content/plugins/restpostsembedder` directory
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the shortcode `[posts_embedder]` in your posts or pages

== Frequently Asked Questions ==
= How do I change the REST API endpoint? =
Go to Settings > REST Posts Embedder and enter your desired endpoint URL.

= Can I customize the number of posts? =
Yes, use the shortcode attribute: `[posts_embedder count="10"]`

== Screenshots ==
1. Plugin settings page
2. Example of embedded posts

== Changelog ==
= 3.7.0 =
* Added a "Load More" button (localised "Cargar más") at the bottom of each feed; it loads the next page of posts via AJAX and appends them without a page reload.
* Added per-feed excerpt length, in characters: each feed source has its own Excerpt Length field, plus a global default on the Styling tab. Use 0 to show the full excerpt. Can be overridden inline with [posts_embedder excerpt_length="150"].
* The source "Number of Posts" setting is now the per-batch size (posts loaded per "Load More" click).
* Each fetched page is cached separately so repeated clicks don't re-hit the remote site.

= 3.6.0 =
* Fixed "Unknown Author": the request now forces _embed and the author link reads the correct REST field.
* Added Spanish (es_ES) translation and a locale-aware byline ("8 de junio 2026, por Autor").
* Added self-hosted auto-updates via a hosted JSON manifest.
* Security: restricted the shortcode endpoint to http/https and hardened admin input handling.

= 3.5.2 =
* Fixed grid layout structure so posts display in columns.

=== REST Posts Embedder ===
Contributors: rafaelminuesa
Donate link: https://prowoos.com/
Tags: rest api, embed posts, posts grid, shortcode, load more
Requires at least: 5.3
Tested up to: 7.1
Stable tag: 3.7.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show posts from any WordPress site on your own site, using the WordPress REST API and a shortcode.

== Description ==

REST Posts Embedder displays posts from any WordPress site as a responsive grid of cards. Each feed has its own endpoint, batch size and excerpt length, and visitors can load more posts without reloading the page.

* Multiple feed sources, each with its own shortcode
* Filter by category, tag or any other REST API parameter
* Responsive grid with separate column settings for desktop and mobile
* Featured images with a responsive srcset
* "Load More" button
* Excerpt length per feed
* Built-in caching, with a button to clear it
* Spanish (es_ES) translation included

= Demo feed =

Right after activation the plugin shows a demo feed of posts from [ProWoos](https://prowoos.com/), so you can see how embedded posts look. The demo feed is for demonstration only. On pages that show it, administrators see a notice above the posts explaining how to replace it. Visitors never see that notice.

= Show your own posts =

1. Go to Settings → REST Posts Embedder → Feed Sources and click "Add New Source".
2. Enter a name and the REST API endpoint of the site whose posts you want to show, for example `https://example.com/wp-json/wp/v2/posts`.
3. Save, then place the shortcode shown for that source on any page or post, for example `[posts_embedder source="my-feed_1757923200"]`.

To show a single category or tag, add it to the endpoint, for example `https://example.com/wp-json/wp/v2/posts?categories=12` or `https://example.com/wp-json/wp/v2/posts?tags=34`. The Feed Sources tab explains how to find category and tag IDs.

`[posts_embedder]` without a `source` attribute shows the demo feed.

= Shortcode attributes =

* `source` is the ID of a configured feed source.
* `endpoint` is a REST API endpoint URL, for use without a source.
* `count` is the number of posts per batch (1 to 20). Each "Load More" click loads another batch.
* `excerpt_length` is the excerpt length in characters. `0` shows the full excerpt. The default is 200.

When `source` is set, the source's endpoint and post count are used and the `endpoint` and `count` attributes are ignored. `excerpt_length` still overrides the source setting.

== Installation ==

1. In WordPress, go to Plugins → Add Plugin → Upload Plugin and upload the plugin zip, or extract it to `/wp-content/plugins/restpostsembedder/`.
2. Activate the plugin on the Plugins screen.
3. Add `[posts_embedder]` to a page to see the demo feed, then follow "Show your own posts" above.

== Frequently Asked Questions ==

= Why do I see posts from ProWoos? =

That is the demo feed. Follow "Show your own posts" to replace it with posts from the site of your choice.

= How do I change the number of posts? =

Set "Number of Posts" on the feed source. Each "Load More" click loads another batch of that size.

= New posts don't appear right away =

Feeds are cached for one hour by default. Change the duration, or clear the cache, under Settings → REST Posts Embedder → Cache Management.

== Changelog ==

= 3.7.0 =
* Added a "Load More" button (localized "Cargar más") at the bottom of each feed; it loads the next page of posts via AJAX and appends them without a page reload.
* Added per-feed excerpt length, in characters: each feed source has its own Excerpt Length field, plus a global default on the Styling tab. Use 0 to show the full excerpt. Can be overridden inline with [posts_embedder excerpt_length="150"].
* The source "Number of Posts" setting is now the per-batch size (posts loaded per "Load More" click).
* Each fetched page is cached separately so repeated clicks don't re-hit the remote site.

= 3.6.1 =
* Fixed blurry featured images. Images now use a responsive srcset and default to the largest available size.
* The update checker now also runs under WP-CLI, so `wp plugin update` and cron-based updaters pick up new releases.

= 3.6.0 =
* Fixed "Unknown Author": the request now forces _embed and the author link reads the correct REST field.
* Added Spanish (es_ES) translation and a locale-aware byline ("8 de junio 2026, por Autor").
* Added self-hosted auto-updates via a hosted JSON manifest.
* Security: restricted the shortcode endpoint to http/https and hardened admin input handling.

= 3.5.2 =
* Fixed grid layout structure so posts display in columns.

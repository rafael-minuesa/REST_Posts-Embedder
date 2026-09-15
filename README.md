
![repository-open-graph](https://github.com/user-attachments/assets/016efde7-a836-4f32-8c25-d60bc7d4c3fe)

# REST_Posts-Embedder
Show posts from any WordPress site on your own site, using the WordPress REST API and a shortcode.

Requires WordPress 5.3+ and PHP 7.4+. Tested up to WordPress 7.1.

## Demo feed

Right after activation the plugin shows a demo feed of English posts from [ProWoos](https://prowoos.com/), so you can see how embedded posts look. It is for demonstration only. On pages that show it, administrators see a notice above the posts explaining how to replace it. Visitors never see that notice.

## Show your own posts

1. Go to **Settings → REST Posts Embedder → Feed Sources** and click **Add New Source**.
2. Enter a name and the REST API endpoint of the site whose posts you want to show, for example `https://example.com/wp-json/wp/v2/posts`.
3. Save, then place the shortcode shown for that source on any page or post, for example `[posts_embedder source="my-feed_1757923200"]`.

To show a single category or tag, add it to the endpoint, for example `?categories=12` or `?tags=34`.

`[posts_embedder]` without a `source` attribute shows the demo feed.

## Usage

```
[posts_embedder source="my-feed-id"]
[posts_embedder endpoint="https://example.com/wp-json/wp/v2/posts" count="6" excerpt_length="150"]
```

### Shortcode attributes

| Attribute        | Description                                                                 |
|------------------|-----------------------------------------------------------------------------|
| `source`         | ID of a configured feed source (Feed Sources tab).                          |
| `endpoint`       | REST API endpoint URL, for use without a source.                            |
| `count`          | Posts per batch (1 to 20). Each "Load More" click loads another batch.      |
| `excerpt_length` | Excerpt length in characters. `0` shows the full excerpt. Default `200`.    |

When `source` is set, the source's endpoint and post count are used and the `endpoint` and `count` attributes are ignored. `excerpt_length` still overrides the source setting.

### Features

- **Load More button** at the bottom of each feed (localized "Cargar más"), loading the next page of posts via AJAX.
- **Per-feed excerpt length** (characters), set per source or via a global default on the Styling tab.
- Responsive grid, featured-image `srcset`, per-page caching, self-hosted auto-updates and Spanish (es_ES) translation.

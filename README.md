
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
| `excerpt_words`  | Excerpt length in words (1 to 200). Wins over `excerpt_length`.            |
| `layout`         | Card layout: `classic` or `grid`. Default: the source's layout, then the site's. |
| `columns`        | Columns on desktop (1 to 5). Default: the Styling setting.                  |
| `columns_tablet` | Columns on tablets (1 to 5), for layouts with a tablet step such as Grid. Default: the smaller of `columns` and 2. |

| `category`, `tag` | `source="this-site"` only: category or tag slugs, comma-separated (any of them). |
| `orderby`, `order` | `source="this-site"` only: `date` (default), `modified`, `title` or `comment_count`, and `DESC` (default) or `ASC`. |
| `offset`         | `source="this-site"` only: posts to skip at the start (0 to 100).           |

When `source` is set, the source's endpoint and post count are used and the `endpoint` and `count` attributes are ignored. `excerpt_length` and `layout` still override the source settings.

### Block editor

The **Posts Grid** block (Widgets category) does the same as the shortcode, with settings in the sidebar: source (this site or a feed source), number of posts, category and order for this site's posts, layout, columns, and excerpt length in words or characters. It is rendered by the server like the shortcode, and the editor shows a live preview. It needs no build step and works with WordPress 5.3 and later.

### Posts from this site

`[posts_embedder source="this-site"]` shows this site's own published posts, read from its database instead of a REST API. It works with every layout, the column attributes and Load More, and accepts `category`, `tag`, `orderby`, `order` and `offset`. It is not cached, so a new post shows at once. Cards show the publication date, and links open in the same tab. On a multilingual site (WC Multilang, Polylang) each language shows its own posts, on the page and in Load More.

### ProWoos Posts Grid shortcode

The ProWoos Posts Grid plugin is merged into this one. `[prowoos_posts_grid]` keeps working: it shows a `source="this-site"` feed in the Grid layout, with `posts`, `columns`, `columns_tablet`, `category`, `excerpt_length` (in words), `orderby`, `order` and `offset` as before. `show_date`, `show_excerpt`, `show_readmore` and `readmore_text` are not supported. While ProWoos Posts Grid is still active, its own shortcode is used; deactivate it afterwards.

### Layouts

The card layout is chosen on the Styling tab for the whole site, per feed source, or per shortcode with `layout`. The plugin ships two layouts. Classic shows the title and byline above the image. Grid shows cards with the image on top, then the date, title, excerpt and a "Read More" link, in 1, 2 or 3 columns by screen width (phone, tablet from 544px, desktop from 921px). A theme or plugin can add one with the `rest_posts_embedder_layouts` filter, giving a label and a template file. The template receives the card data as `$card`, already escaped (see `prepare_card()` in `shortcodes/functions.php`).

### Features

- **Load More button** at the bottom of each feed (localized "Cargar más"), loading the next page of posts via AJAX.
- **Per-feed excerpt length** (characters), set per source or via a global default on the Styling tab.
- Responsive grid, featured-image `srcset`, per-page caching, self-hosted auto-updates and Spanish (es_ES) translation.

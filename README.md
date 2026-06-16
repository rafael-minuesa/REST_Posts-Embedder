
![repository-open-graph](https://github.com/user-attachments/assets/016efde7-a836-4f32-8c25-d60bc7d4c3fe)

# REST_Posts-Embedder
Embed Posts from a specified REST API endpoint.

## Usage

Place the shortcode in any post, page or widget:

```
[posts_embedder]
[posts_embedder source="my-feed-id"]
[posts_embedder count="6" excerpt_length="150"]
```

Configure feeds under **Settings → REST Posts Embedder**.

### Shortcode attributes

| Attribute        | Description                                                                 |
|------------------|-----------------------------------------------------------------------------|
| `source`         | ID of a configured feed source (Feed Sources tab).                          |
| `endpoint`       | REST API endpoint URL (when not using a source).                            |
| `count`          | Posts per batch. Each "Load More" click loads another batch.                |
| `excerpt_length` | Excerpt length in characters. `0` shows the full excerpt. Default `200`.    |

### Features

- **Load More button** at the bottom of each feed (localised "Cargar más"), loading the next page of posts via AJAX.
- **Per-feed excerpt length** (characters), set per source or via a global default on the Styling tab.
- Responsive grid, featured-image `srcset`, per-page caching, self-hosted auto-updates and Spanish (es_ES) translation.

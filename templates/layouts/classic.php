<?php
/**
 * Classic card layout: title, byline, image, excerpt and a "Read more..." link.
 *
 * The markup is the one every release before 3.10.0 printed, kept exactly so
 * existing custom CSS and rest_posts_embedder_post_html filters still match.
 *
 * @package restpostsembedder
 * @since 3.10.0
 *
 * @var array $card Card data, see RestPostsEmbedder\Shortcodes\prepare_card().
 */

if (!defined('ABSPATH')) {
    exit;
}

$rpe_image = '';
if ('' !== $card['image_src']) {
    $rpe_image = '<a href="' . $card['link'] . '"' . $card['link_target'] . '><img src="' . $card['image_src'] . '"'
        . ('' !== $card['image_srcset'] ? ' srcset="' . $card['image_srcset'] . '" sizes="' . $card['image_sizes'] . '"' : '')
        . ' alt="' . $card['image_alt'] . '" loading="lazy" /></a>';
}

echo '<article class="embed-posts">
                    <a href="' . $card['link'] . '"' . $card['link_target'] . '>
                        <h3>' . $card['title'] . '</h3>
                    </a>
                    <small>' . $card['byline'] . '</small>
                    <div class="embed-post-content">
                        ' . $rpe_image . '
                        ' . $card['excerpt'] . '
                        <a href="' . $card['link'] . '"' . $card['link_target'] . ' class="read-more">
                            <b>' . $card['read_more'] . '</b>
                        </a>
                    </div>
                </article>';

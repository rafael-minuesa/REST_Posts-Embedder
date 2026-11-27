<?php
/**
 * Grid card layout: image on top, then date, title, excerpt and a link.
 *
 * The look of the ProWoos Posts Grid plugin: 16:10 image, hover lift, date
 * above the title. Styles are in assets/css/custom.css under
 * .embed-posts-card.
 *
 * @package restpostsembedder
 * @since 3.10.0
 *
 * @var array $card Card data, see RestPostsEmbedder\Shortcodes\prepare_card().
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<article class="embed-posts-card">
<?php if ('' !== $card['image_src']) : ?>
    <a href="<?php echo $card['link']; ?>"<?php echo $card['link_target']; ?> class="embed-posts-card-image" aria-hidden="true" tabindex="-1"><img src="<?php echo $card['image_src']; ?>"<?php if ('' !== $card['image_srcset']) : ?> srcset="<?php echo $card['image_srcset']; ?>" sizes="<?php echo $card['image_sizes']; ?>"<?php endif; ?> alt="" loading="lazy" /></a>
<?php endif; ?>
    <div class="embed-posts-card-body">
<?php if ('' !== $card['date']) : ?>
        <time class="embed-posts-card-date" datetime="<?php echo $card['date_iso']; ?>"><?php echo $card['date']; ?></time>
<?php endif; ?>
        <h3 class="embed-posts-card-title"><a href="<?php echo $card['link']; ?>"<?php echo $card['link_target']; ?>><?php echo $card['title']; ?></a></h3>
<?php if ('' !== $card['excerpt']) : ?>
        <div class="embed-posts-card-excerpt"><?php echo $card['excerpt']; ?></div>
<?php endif; ?>
        <a href="<?php echo $card['link']; ?>"<?php echo $card['link_target']; ?> class="embed-posts-card-readmore"><?php esc_html_e('Read More', 'restpostsembedder'); ?> <span aria-hidden="true">&rarr;</span></a>
    </div>
</article>

<?php
function rest_posts_embedder() {
    $allposts = '';
    $endpoint = get_option('embed_posts_endpoint', 'https://prowoos.com/wp-json/wp/v2/posts?_embed'); // Default to the original URL if not set
    $response = wp_remote_get(add_query_arg(array('per_page' => 5), $endpoint));

    if (!is_wp_error($response) && $response['response']['code'] == 200 && !empty($response['body'])) {
        $remote_posts = json_decode($response['body']);
        foreach ($remote_posts as $remote_post) {
            $fordate = date('jS \of F Y', strtotime($remote_post->modified));
            $thumb_url = '';
            $author_name = '';
            $author_name_url = '';

            if (!empty($remote_post->featured_media) && isset($remote_post->_embedded)) {
                $thumb_url = $remote_post->_embedded->{'wp:featuredmedia'}[0]->media_details->sizes->medium->source_url;
            }
            if (!empty($remote_post->author) && isset($remote_post->_embedded)) {
                $author_name = $remote_post->_embedded->author[0]->name;
                $author_name_url = $remote_post->_embedded->author[0]->source_url;
            }

            $allposts .= '<div class="wrapper">
                                <div class="embed-posts-wrapper">
                                    <article class="embed-posts">
                                        <a href="' . esc_url($remote_post->link) . '" target="_blank">
                                            <h3>' . esc_html($remote_post->title->rendered) . '</h3>
                                        </a>
                                        <small>' . esc_html($fordate) . ', by <a href="' . esc_url($author_name_url) . '" target="_blank">' . esc_html($author_name) . '</small></a>
                                        <p style="margin: 1em 0;">
                                            <a href="' . esc_url($remote_post->link) . '" target="_blank">
                                                <img src="' . esc_url($thumb_url) . '" />
                                            </a>' . $remote_post->excerpt->rendered . '
                                            <a href="' . esc_url($remote_post->link) . '" target="_blank">
                                                <b>Read more ...</b>
                                            </a>
                                        </p>
                                    </article>
                                </div>
                            </div>';
        }
    }

    return $allposts;
}

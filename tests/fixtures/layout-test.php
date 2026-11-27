<?php
// Test layout: prints every card field and the variables in scope.
echo '<div class="test-card" data-scope="' . esc_attr(implode(',', array_keys(get_defined_vars()))) . '">'
    . '<h4>' . $card['title'] . '</h4>'
    . '<time data-ts="' . esc_attr((string) $card['timestamp']) . '">' . $card['date'] . '</time>'
    . '<span class="author">' . $card['author_html'] . '</span>'
    . '<img src="' . $card['image_src'] . '" alt="' . $card['image_alt'] . '">'
    . $card['excerpt']
    . '<a href="' . $card['link'] . '">' . $card['read_more'] . '</a>'
    . '<i>' . esc_html((string) $card['post']->id) . '</i>'
    . '</div>';

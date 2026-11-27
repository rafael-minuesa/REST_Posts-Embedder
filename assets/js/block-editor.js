/**
 * "Posts Grid" block, editor side. Plain JavaScript, no build step.
 *
 * The block is dynamic: the preview comes from the server through
 * ServerSideRender, and save() stores attributes only.
 */
(function (wp, data) {
    'use strict';

    if (!wp || !wp.blocks || !data) {
        return;
    }

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var __ = wp.i18n.__;
    var blockEditor = wp.blockEditor || wp.editor;
    var InspectorControls = blockEditor.InspectorControls;
    var components = wp.components;
    var PanelBody = components.PanelBody;
    var SelectControl = components.SelectControl;
    var RangeControl = components.RangeControl;
    var ToggleControl = components.ToggleControl;
    var ExternalLink = components.ExternalLink;
    var ServerSideRender = wp.serverSideRender;
    var useSelect = wp.data && wp.data.useSelect;
    var useBlockProps = blockEditor.useBlockProps;

    var TEXT_DOMAIN = 'restpostsembedder';

    function sourceOptions() {
        var options = [{ value: data.localSource, label: __('This site', TEXT_DOMAIN) }];
        data.sources.forEach(function (source) {
            if (source.id !== data.localSource) {
                options.push({ value: source.id, label: source.name });
            }
        });
        return options;
    }

    function layoutOptions() {
        return data.layouts.map(function (layout) {
            return { value: layout.id, label: layout.label };
        });
    }

    function useCategories() {
        if (!useSelect) {
            return [];
        }
        return useSelect(function (select) {
            return select('core').getEntityRecords('taxonomy', 'category', { per_page: 100, hide_empty: true, context: 'view' });
        }, []) || [];
    }

    function LocalQueryPanel(props) {
        var attributes = props.attributes;
        var setAttributes = props.setAttributes;
        var categories = useCategories();
        var categoryOptions = [{ value: '', label: __('All categories', TEXT_DOMAIN) }].concat(
            categories.map(function (term) {
                return { value: term.slug, label: term.name };
            })
        );

        return el(PanelBody, { title: __('Posts from this site', TEXT_DOMAIN), initialOpen: true },
            el(SelectControl, {
                label: __('Category', TEXT_DOMAIN),
                value: attributes.category,
                options: categoryOptions,
                onChange: function (value) { setAttributes({ category: value }); }
            }),
            el(SelectControl, {
                label: __('Order by', TEXT_DOMAIN),
                value: attributes.orderby,
                options: [
                    { value: 'date', label: __('Publication date', TEXT_DOMAIN) },
                    { value: 'modified', label: __('Last modified', TEXT_DOMAIN) },
                    { value: 'title', label: __('Title', TEXT_DOMAIN) },
                    { value: 'comment_count', label: __('Number of comments', TEXT_DOMAIN) }
                ],
                onChange: function (value) { setAttributes({ orderby: value }); }
            }),
            el(SelectControl, {
                label: __('Order', TEXT_DOMAIN),
                value: attributes.order,
                options: [
                    { value: 'DESC', label: __('Descending', TEXT_DOMAIN) },
                    { value: 'ASC', label: __('Ascending', TEXT_DOMAIN) }
                ],
                onChange: function (value) { setAttributes({ order: value }); }
            }),
            el(RangeControl, {
                label: __('Skip posts', TEXT_DOMAIN),
                help: __('Number of posts to leave out at the start.', TEXT_DOMAIN),
                value: attributes.offset,
                min: 0,
                max: data.maxOffset,
                onChange: function (value) { setAttributes({ offset: value || 0 }); }
            })
        );
    }

    function edit(props) {
        var attributes = props.attributes;
        var setAttributes = props.setAttributes;
        var isLocal = attributes.source === data.localSource;
        var wrapperProps = useBlockProps ? useBlockProps() : { className: props.className };

        var sourcePanel = el(PanelBody, { title: __('Feed', TEXT_DOMAIN), initialOpen: true },
            el(SelectControl, {
                label: __('Source', TEXT_DOMAIN),
                value: attributes.source,
                options: sourceOptions(),
                help: isLocal
                    ? __('Posts published on this site.', TEXT_DOMAIN)
                    : __('A feed source set up in the plugin settings. Its number of posts is set there.', TEXT_DOMAIN),
                onChange: function (value) { setAttributes({ source: value }); }
            }),
            data.settingsUrl ? el(ExternalLink, { href: data.settingsUrl }, __('Manage feed sources', TEXT_DOMAIN)) : null,
            isLocal ? el(RangeControl, {
                label: __('Number of posts', TEXT_DOMAIN),
                help: __('Per batch. "Load More" shows the next batch.', TEXT_DOMAIN),
                value: attributes.count,
                min: 1,
                max: data.maxCount,
                allowReset: true,
                onChange: function (value) { setAttributes({ count: value }); }
            }) : null
        );

        var displayPanel = el(PanelBody, { title: __('Display', TEXT_DOMAIN), initialOpen: true },
            el(SelectControl, {
                label: __('Layout', TEXT_DOMAIN),
                value: attributes.layout,
                options: layoutOptions(),
                onChange: function (value) { setAttributes({ layout: value }); }
            }),
            el(RangeControl, {
                label: __('Columns', TEXT_DOMAIN),
                help: __('Empty uses the plugin\'s Styling setting.', TEXT_DOMAIN),
                value: attributes.columns,
                min: 1,
                max: data.maxColumns,
                allowReset: true,
                onChange: function (value) { setAttributes({ columns: value }); }
            }),
            el(RangeControl, {
                label: __('Columns on tablets', TEXT_DOMAIN),
                value: attributes.columnsTablet,
                min: 1,
                max: data.maxColumns,
                allowReset: true,
                onChange: function (value) { setAttributes({ columnsTablet: value }); }
            }),
            el(SelectControl, {
                label: __('Excerpt length in', TEXT_DOMAIN),
                value: attributes.excerptUnit,
                options: [
                    { value: 'words', label: __('Words', TEXT_DOMAIN) },
                    { value: 'characters', label: __('Characters', TEXT_DOMAIN) }
                ],
                onChange: function (value) { setAttributes({ excerptUnit: value }); }
            }),
            el(RangeControl, {
                label: __('Excerpt length', TEXT_DOMAIN),
                help: attributes.excerptUnit === 'characters'
                    ? __('0 shows the whole excerpt. Empty uses the plugin setting.', TEXT_DOMAIN)
                    : __('Empty uses the plugin setting.', TEXT_DOMAIN),
                value: attributes.excerptLength,
                min: attributes.excerptUnit === 'characters' ? 0 : 1,
                max: attributes.excerptUnit === 'characters' ? 2000 : 200,
                allowReset: true,
                onChange: function (value) { setAttributes({ excerptLength: value }); }
            }),
            el(ToggleControl, {
                label: __('Show "Load More" button', TEXT_DOMAIN),
                checked: attributes.loadMore !== false,
                onChange: function (value) { setAttributes({ loadMore: value }); }
            })
        );

        return el(Fragment, null,
            el(InspectorControls, null, sourcePanel, isLocal ? el(LocalQueryPanel, props) : null, displayPanel),
            el('div', wrapperProps,
                el(ServerSideRender, { block: data.name, attributes: attributes })
            )
        );
    }

    wp.blocks.registerBlockType(data.name, {
        apiVersion: 3,
        title: __('Posts Grid', TEXT_DOMAIN),
        description: __('Posts from this site or from another WordPress site, as cards in a grid.', TEXT_DOMAIN),
        category: 'widgets',
        icon: 'grid-view',
        keywords: [__('posts', TEXT_DOMAIN), __('blog', TEXT_DOMAIN), __('embed', TEXT_DOMAIN), 'rest'],
        supports: { align: ['wide', 'full'], html: false },
        edit: edit,
        save: function () {
            return null;
        }
    });
}(window.wp, window.restPostsEmbedderBlock));

<?php return [
    'plugin' => [
        'name' => 'Pages',
        'description' => 'Plugin for pages and its content management',
    ],
    'permission' => [
        'default' => 'Plugin access',
        'page' => [
            'label' => 'Page',
            'create' => [
                'label' => 'Create',
            ],
            'update' => [
                'label' => 'Update',
            ],
            'delete' => [
                'label' => 'Delete',
            ],
            'reorder' => [
                'label' => 'Order',
            ],
            'slider' => [
                'label' => 'Slider',
            ],
        ],
        'content' => [
            'label' => 'Content',
            'create' => [
                'label' => 'Create',
            ],
            'update' => [
                'label' => 'Edit',
                'type' => [
                    'label' => 'Type',
                ],
                'posts_category' => [
                    'label' => 'Posts category',
                ],
                'files_category' => [
                    'label' => 'Files category',
                ],
                'flashmessages_category' => [
                    'label' => 'Flash messages category',
                ],
                'contacts_category' => [
                    'label' => 'Contacts category',
                ],
                'jobs_category' => [
                    'label' => 'Jobs category',
                ],
                'links_category' => [
                    'label' => 'Links category',
                ],
                'partial' => [
                    'label' => 'Partial',
                ],
                'row_cols' => [
                    'label' => 'Posts in row',
                ],
                'posts_sort_order' => [
                    'label' => 'Posts sort order',
                ],
                'gallery' => [
                    'label' => 'Gallery',
                ],
                'pricelist' => [
                    'label' => 'Price list',
                ],
                'opening_hours' => [
                    'label' => 'Opening hours',
                ],
                'Slider' => [
                    'label' => 'Slider',
                ],
            ],
            'delete' => [
                'label' => 'Delete',
            ],
            'reorder' => [
                'label' => 'Order',
            ],
        ],
        'homepage' => [
            'label' => 'Úvodní stránka',
        ],
        'block' => [
            'label' => 'Blocks',
            'create' => [
                'label' => 'Create',
            ],
            'update' => [
                'label' => 'Update',
            ],
            'delete' => [
                'label' => 'Delete',
            ],
            'reorder' => [
                'label' => 'Order',
            ],
        ],
        'structure' => [
            'label' => 'Struktura',
        ],
    ],
    'page' => [
        'column' => [
            'title' => [
                'label' => 'Title',
            ],
            'sort_order' => [
                'label' => 'Order',
            ],
        ],
        'field' => [
            'contents' => [
                'label' => 'Content',
            ],
            'title' => [
                'label' => 'Title',
            ],
            'slug' => [
                'label' => 'Slug',
            ],
            'parent' => [
                'label' => 'Parent',
                'prompt' => '--None--',
            ],
            'sort_order' => [
                'label' => 'Order',
            ],
            'fullslug' => [
                'label' => 'Full Slug',
            ],
            'breadcrumb' => [
                'label' => 'Breadcrumb URL',
                'option' => [
                    'auto' => 'Automatic',
                    'manual' => 'Manual',
                ],
            ],
            'menu' => [
                'label' => 'Menu',
                'prompt' => '--None--',
            ],
            'meta_title' => [
                'label' => 'Title',
                'comment' => 'Left blank to use page title.',
            ],
            'meta_description' => [
                'label' => 'Description',
            ],
            'slider' => [
                'label' => 'Slider',
                'prompt' => '--None--',
            ],
        ],
        'tab' => [
            'meta' => [
                'label' => 'Meta',
            ],
        ],
        'create' => [
            'title' => 'Create page',
            'flash' => 'Page has been successfully created',
        ],
        'update' => [
            'title' => 'Edit page',
            'flash' => 'Page has been successfully edited',
        ],
        'delete' => [
            'flash' => 'Page has been successfully deleted',
        ],
    ],
    'content' => [
        'column' => [
            'title' => [
                'label' => 'Title',
            ],
            'type' => [
                'label' => 'Type',
            ],
            'sort_order' => [
                'label' => 'Order',
            ],
            'is_published' => [
                'label' => 'Published',
            ],
        ],
        'field' => [
            'is_published' => [
                'label' => 'Published',
            ],
            'title' => [
                'label' => 'Title',
                'comment' => 'Internal name for display in the administration. It will not be displayed on the website.',
            ],
            'type' => [
                'label' => 'Type',
                'option' => [
                    'posts' => [
                        'label' => 'Posts',
                    ],
                    'gallery' => [
                        'label' => 'Gallery',
                    ],
                    'files' => [
                        'label' => 'Files',
                    ],
                    'contacts' => [
                        'label' => 'Contacts',
                    ],
                    'cookies' => [
                        'label' => 'Cookies settings',
                    ],
                    'pricelist' => [
                        'label' => 'Price list',
                    ],
                    'opening_hours' => [
                        'label' => 'Opening hours',
                    ],
                    'slider' => [
                        'label' => 'Slider',
                    ],
                    'embed' => [
                        'label' => 'Embed',
                    ],
                    'image_text' => [
                        'label' => 'Image text',
                    ],
                    'jobs' => [
                        'label' => 'Free occupations',
                    ],
                    'links' => [
                        'label' => 'Links',
                    ],
                    'links_slider' => [
                        'label' => 'Links slider',
                    ],
                    'partial' => [
                        'label' => 'Partial',
                    ],
                    'contact_form' => [
                        'label' => 'Contact form',
                    ],
                    'timeline' => [
                        'label' => 'Timeline',
                    ],
                    'flash_message' => [
                        'label' => 'Flash message',
                    ],
                ],
            ],
            'text' => [
                'label' => 'Text',
            ],
            'heading' => [
                'label' => 'Heading',
                'comment' => 'Content heading on the website',
            ],
            'sort_order' => [
                'label' => 'Order',
            ],
            'is_fluid' => [
                'label' => 'Full width',
            ],
            'no_gutters' => [
                'label' => 'No gutters',
            ],
            'no_gutters_breakpoint' => [
                'label' => 'No gutters breakpoint',
            ],
            'posts_categories' => [
                'label' => 'Categories',
                'empty_option' => '-- Any --',
            ],
            'gallery' => [
                'label' => 'Gallery',
                'prompt' => '--Choose--',
            ],
            'files_categories' => [
                'label' => 'Categories',
            ],
            'flashmessages_categories' => [
                'label' => 'Categories',
            ],
            'contacts_categories' => [
                'label' => 'Categories',
            ],
            'pricelist' => [
                'label' => 'Price list',
            ],
            'opening_hours' => [
                'label' => 'Opening hours',
            ],
            'slider' => [
                'label' => 'Slider',
            ],
            'embed' => [
                'label' => 'Iframe code',
            ],
            'partial' => [
                'label' => 'Partial',
            ],
            'row_cols' => [
                'label' => 'Posts in row',
            ],
            'items_per_page' => [
                'label' => 'Posts per page',
            ],
            'image' => [
                'label' => 'Image',
            ],
            'switch_order' => [
                'label' => 'Switch order',
            ],
            'jobs_categories' => [
                'label' => 'Categories',
            ],
            'post_partial' => [
                'label' => 'Post partial',
            ],
            'links_categories' => [
                'label' => 'Categories',
            ],
            'posts_sort_order' => [
                'label' => 'Posts sort order',
            ],
            'variables' => [
                'label' => 'Variables',
                'prompt' => 'Add new variable',
                'field' => [
                    'name' => [
                        'label' => 'Name',
                    ],
                    'type' => [
                        'label' => 'Type',
                        'option' => [
                            'text' => [
                                'label' => 'Text',
                            ],
                            'link' => [
                                'label' => 'Link',
                            ],
                        ],
                    ],
                    'value' => [
                        'label' => 'Value',
                    ],
                    'link' => [
                        'label' => 'Link',
                    ]
                ],
            ],
            'timeline' => [
                'label' => 'Timeline',
            ],
            'padding_top' => [
                'label' => 'Padding top on desktop',
            ],
            'padding_bottom' => [
                'label' => 'Padding bottom on desktop',
            ],
            'padding_top_sm' => [
                'label' => 'Padding top',
            ],
            'padding_bottom_sm' => [
                'label' => 'Padding bottom',
            ],
            'border_top' => [
                'label' => 'Border width top',
            ],
            'border_bottom' => [
                'label' => 'Border width bottom',
            ],
            'options_inherited' => [
                'label' => 'Inherit template options',
            ],
        ],
        'create' => [
            'title' => 'Create content',
        ],
        'update' => [
            'title' => 'Edit content',
        ],
    ],
    'block' => [
        'column' => [
            'title' => [
                'label' => 'Title',
            ],
            'type' => [
                'label' => 'Type',
            ],
            'sort_order' => [
                'label' => 'Order',
            ],
            'is_published' => [
                'label' => 'Published',
            ],
        ],
        'field' => [
            'title' => [
                'label' => 'Title',
                'comment' => 'Internal name for display in the administration. It will not be displayed on the website.',
            ],
            'heading' => [
                'label' => 'Heading',
                'comment' => 'Content heading on the website',
            ],
            'is_fluid' => [
                'label' => 'Full width',
            ],
            'no_gutters' => [
                'label' => 'No gutters',
            ],
            'no_gutters_breakpoint' => [
                'label' => 'No gutters breakpoint',
            ],
            'padding_top' => [
                'label' => 'Padding top on desktop',
            ],
            'padding_bottom' => [
                'label' => 'Padding bottom on desktop',
            ],
            'padding_top_sm' => [
                'label' => 'Padding top',
            ],
            'padding_bottom_sm' => [
                'label' => 'Padding bottom',
            ],
            'border_top' => [
                'label' => 'Border width top',
            ],
            'border_bottom' => [
                'label' => 'Border width bottom',
            ],
            'sort_order' => [
                'label' => 'Order',
            ],
            'is_published' => [
                'label' => 'Published',
            ],
            'text' => [
                'label' => 'Text',
            ],
            'image' => [
                'label' => 'Image',
            ],
            'posts_category' => [
                'label' => 'Posts category',
                'empty_option' => '-- Any --',
            ],
            'partial' => [
                'label' => 'Partial',
            ],
            'row_cols' => [
                'label' => 'Posts in row',
            ],
            'items_per_page' => [
                'label' => 'Total posts',
            ],
            'switch_order' => [
                'label' => 'Switch order',
            ],
            'slider' => [
                'label' => 'Slider',
            ],
            'embed' => [
                'label' => 'Iframe code',
            ],
            'links_category' => [
                'label' => 'Links category',
            ],
            'flashmessages_category' => [
                'label'        => 'Flash messages category',
                'empty_option' => '-- Choose --',
            ],
            'posts_sort_order' => [
                'label' => 'Posts sort order',
            ],
            'more_button_is_published' => [
                'label' => '"Show more" button',
            ],
            'more_button_title' => [
                'label' => '"Show more" button title',
            ],
            'more_button_link' => [
                'label' => 'Posts page',
            ],
            'variables' => [
                'label' => 'Variables',
                'prompt' => 'Add new variable',
                'field' => [
                    'name' => [
                        'label' => 'Name',
                    ],
                    'type' => [
                        'label' => 'Type',
                        'option' => [
                            'text' => [
                                'label' => 'Text',
                            ],
                            'link' => [
                                'label' => 'Link',
                            ],
                        ],
                    ],
                    'value' => [
                        'label' => 'Value',
                    ],
                    'link' => [
                        'label' => 'Link',
                    ]
                ],
            ],
            'options_inherited' => [
                'label' => 'Inherit template options',
            ],
            'options' => [
                'label' => 'Options',
            ],
            'type' => [
                'label' => 'Type',
                'option' => [
                    'posts' => [
                        'label' => 'Posts',
                    ],
                    'posts_slider' => [
                        'label' => 'Posts slider',
                    ],
                    'partial' => [
                        'label' => 'Custom partial',
                    ],
                    'image_text' => [
                        'label' => 'Image text',
                    ],
                    'flash_message' => [
                        'label' => 'Flash message',
                    ],
                    'embed' => [
                        'label' => 'Embed',
                    ],
                    'links' => [
                        'label' => 'Links',
                    ],
                    'links_slider' => [
                        'label' => 'Links slider',
                    ],
                    'contact_form' => [
                        'label' => 'Kontaktní formulář',
                    ],
                ],
            ],
        ],
        'create' => [
            'title' => 'Create block',
            'flash' => 'Block has been successfully created',
        ],
        'update' => [
            'title' => 'Edit block',
            'flash' => 'Block has been successfully edited',
        ],
        'delete' => [
            'flash' => 'Block has been successfully deleted',
        ],
    ],
    'menuitem' => [
        'listtype' => [
            'page' => [
                'label' => 'Page',
            ],
            'builder_page' => [
                'label' => 'Page Builder page',
            ],
        ],
        'submenu' => [
            'structure' => [
                'label' => 'Structure',
            ],
            'homepage' => [
                'label' => 'Homepage',
            ],
        ],
    ],
    'component' => [
        'page' => [
            'name' => 'Page',
            'description' => 'Component for insert page variables to the theme',
            'column' => [
                'title' => 'Column',
                'description' => 'Column name (index) to match with the primary record.',
            ],
            'value' => [
                'title' => 'Value',
                'description' => 'URL code (slug) used to find the primary record.',
            ],
        ],
        'breadcrumbs' => [
            'name' => 'Breadcrumbs',
            'description' => 'Component for insert page breadcrumbs',
        ],
        'homepage' => [
            'name' => 'Homepage',
            'description' => 'Component for insert homepage variables to the theme',
        ],
    ],
];

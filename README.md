# Pages plugin
This is plugin for creating pages and managing its content.

Documentation for the current Page Builder generation:

- [Section motion contract](docs/motion.md)

## Example homepage

````htm
url = "/"
layout = "default"
title = "Úvod"

[collection partners]
handle = "Partners\Partner"

[homepage]

[blogPosts posts]
pageNumber = "{{ :page }}"
noPostsMessage = "Nenalezeny žádné příspěvky"
categoryPage = "page"
postPage = "page-post"

[blogPosts postsslider]
pageNumber = "{{ :page }}"
postsPerPage = 10
noPostsMessage = "Nenalezeny žádné příspěvky"
categoryPage = "blog/category"
postPage = "blog/post"

[contactForm contactform]

[flashmessage]
==
{% for block in homepage.blocks %}
    {% set variables = {} %}

    {% for variable in block.variables %}
        {% set variables = variables|merge({(variable.name): variable.type == "text" ? variable.value : variable.link}) %}
    {% endfor %}

    {% set options = block.options_inherited ? attribute(this.theme, (block.type|replace({"_slider": ""}))) : block.options %}

    <div class="container-fluid{% if block.no_gutters %} g-{% if block.no_gutters_breakpoint != "xs" %}{{ block.no_gutters_breakpoint }}-{% endif %}0{% endif %} pt-{{ block.padding.top_sm }} pb-{{ block.padding.bottom_sm }} pt-xl-{{ block.padding.top }} pb-xl-{{ block.padding.bottom }} {{ block.type|slug }} {{ block.type|slug }}--{{ block.title|slug }} color-scheme--{{ options.color_scheme }}">
        <div class="container{% if block.is_fluid %}-fluid{% else %}-lg{% endif %}{% if block.no_gutters %} g-{% if block.no_gutters_breakpoint != "xs" %}{{ block.no_gutters_breakpoint }}-{% endif %}0{% endif %}">
            {% if block.heading %}
                <h2 class="mb-5 text-center">
                    <span>
                        {{ block.heading }}
                    </span>
                </h2>
            {% endif %}

            {% if block.type == "text" %}
                <div class="pt-{{ options.text.padding_top_sm }} pb-{{ options.text.padding_bottom_sm }} ps-{{ options.text.padding_left_sm }} pe-{{ options.text.padding_right_sm }} pt-xl-{{ options.text.padding_top }} pb-xl-{{ options.text.padding_bottom }} ps-xl-{{ options.text.padding_left }} pe-xl-{{ options.text.padding_right }} color-scheme--{{ options.text.color_scheme }} border rounded-{{ options.text.border_radius }}" style="--bs-border-width: {{ options.text.border_width }}px;">
                    {{ block.text|bootstrap|content }}
                </div>
            {% elseif block.type == "slider" %}
                {% partial "_swiper/slider" slides=block.slider.slides settings=block.slider %}
            {% elseif block.type == "image_text" %}
                {% partial block.partial image=block.image text=block.text heading=block.heading switched=block.switch_order size=(block.is_fluid ? "lg") %}
            {% elseif block.type == "embed" %}
                {% partial "_block/embed" embed=block.embed %}
            {% elseif block.type == "posts" %}
                {% component "posts" categoryFilter=block.posts_category.slug rowCols=block.row_cols postsPerPage=block.items_per_page showPagination=false partial=block.partial sortOrder=block.posts_sort_order moreButtonIsPublished=block.more_button_is_published moreButtonTitle=block.more_button_title moreButtonLink=block.more_button_link options=options %}
            {% elseif block.type == "posts_slider" %}
                {% component "postsslider" categoryFilter=block.posts_category.slug rowCols=block.row_cols sortOrder=block.posts_sort_order moreButtonTitle=block.more_button_title moreButtonLink=block.more_button_link partial=block.partial options=options %}
            {% elseif block.type == "flash_message" %}
                {% component "flashmessage" options=options %}
            {% elseif block.type == "partial" %}
                {% partial block.partial %}
            {% elseif block.type == "links" %}
                {% partial "_links/default" partial=block.partial row_cols=block.row_cols category=block.links_category more_button_is_published=block.more_button_is_published more_button_title=block.more_button_title more_button_link=block.more_button_link %}
            {% elseif block.type == "links_slider" %}
                {% partial "_links/slider" items=block.links_category.links.toNested.lists("link") slides_per_view=block.row_cols partial=block.partial more_button_is_published=block.more_button_is_published more_button_title=block.more_button_title more_button_link=block.more_button_link %}
            {% elseif block.type == "contact_form" %}
                {% component "contactform" %}
            {% endif %}
        </div>
    </div>
{% endfor %}
````

## Example page

````htm
url = "/:fullslug*"
layout = "default"
title = "Page"
seoOptionsMetaTitle = "{{ page.metaTitle ?: page.title }}"
seoOptionsMetaDescription = "{{ page.metaDescription }}"

[page]
column = "fullslug"
value = "{{ :fullslug }}"

[Gallery gallery]
imagesPerPage = 16

[staticMenu sidebarmenu]

[breadcrumbs]

[collection faq]
handle = "FAQ\Question"

[cookiesManage cookiesmanage]

[blogPosts posts]
pageNumber = "{{ :page }}"
noPostsMessage = "Nenalezeny žádné příspěvky"
sortOrder = "published_at desc"
categoryPage = "page"
postPage = "page-post"

[Files files]
order = "sort_order asc"

[pricelist]

[openinghours]

[contactForm contactform]

[timeline]
==
<?php
function onEnd()
{
    $code = $this["page"]->menu;

    $this["sidebarmenu"]->resetMenu($code);
}
?>
==
{% if page.slider %}
    <div class="container-fluid g-0 color-scheme--{{ this.theme.slider.color_scheme }}">
        <div class="container-fluid g-0">
            {% partial "_swiper/slider" slides=page.slider.slides settings=page.slider options=this.theme.slider %}
        </div>
    </div>
{% endif %}

{% if this.theme.breadcrumbs.is_visible %}
    <div class="container-fluid pt-{{ this.theme.breadcrumbs.padding_top }} pb-{{ this.theme.breadcrumbs.padding_bottom }} color-scheme--{{ this.theme.breadcrumbs.color_scheme }}">
        <div class="container-lg">
            <div id="breadcrumb">
                <div class="row g-5">
                    <div class="col {% if sidebarmenu.menuItems is not null %}col-xl-9 offset-xl-3{% endif %}">
                        {% component "breadcrumbs" %}
                    </div>
                </div>
            </div>
        </div>
    </div>
{% endif %}

<div class="container-fluid py-5{% if sidebarmenu.menuItems is null %} g-0{% endif %}" id="page">
    <div class="container-{% if sidebarmenu.menuItems is null %}fluid g-0{% else %}lg{% endif %} pb-xl-4">
        <div class="row g-0 g-xl-5">
            {% if sidebarmenu.menuItems is not null %}
                <div class="sidebar d-none d-xl-block col-xl-3">
                    {% component "sidebarmenu" %}
                </div>
            {% endif %}

            <div class="col {% if sidebarmenu.menuItems is not null %}col-xl-9{% endif %}">
                <div class="container-fluid{% if sidebarmenu.menuItems is not null %} g-0{% endif %}">
                    <h1 class="mb-4 container-lg{% if sidebarmenu.menuItems is not null %} g-0{% endif %}">
                        <span>
                            {{ page.title }}
                        </span>
                    </h1>
                </div>

                <div class="d-flex flex-column">
                    {% for content in page.contents %}
                        {% set variables = {} %}

                        {% for variable in content.variables %}
                            {% set variables = variables|merge({(variable.name): variable.type == "text" ? variable.value : variable.link}) %}
                        {% endfor %}

                        {% set options = content.options_inherited ? attribute(this.theme, (content.type|replace({"_slider": ""}))) : content.options %}

                        <div class="container-fluid{% if content.no_gutters %} g-{% if content.no_gutters_breakpoint != "xs" %}{{ content.no_gutters_breakpoint }}-{% endif %}0{% endif %} pt-{{ content.padding.top_sm }} pb-{{ content.padding.bottom_sm }} pt-xl-{{ content.padding.top }} pb-xl-{{ content.padding.bottom }} {{ content.type|slug }} {{ content.type|slug }}--{{ content.title|slug }} color-scheme--{{ options.color_scheme }}">
                            <div class="container{% if content.is_fluid %}-fluid{% else %}-lg{% endif %}{% if content.no_gutters %} g-{% if content.no_gutters_breakpoint != "xs" %}{{ content.no_gutters_breakpoint }}-{% endif %}0{% endif %}">
                                {% if content.heading %}
                                    <h2 class="mb-4">
                                        <span>
                                            {{ content.heading }}
                                        </span>
                                    </h2>
                                {% endif %}

                                {% if content.type == "text" %}
                                    <div class="pt-{{ options.text.padding_top_sm }} pb-{{ options.text.padding_bottom_sm }} ps-{{ options.text.padding_left_sm }} pe-{{ options.text.padding_right_sm }} pt-xl-{{ options.text.padding_top }} pb-xl-{{ options.text.padding_bottom }} ps-xl-{{ options.text.padding_left }} pe-xl-{{ options.text.padding_right }} color-scheme--{{ options.text.color_scheme }} border rounded-{{ options.text.border_radius }}" style="--bs-border-width: {{ options.text.border_width }}px;">
                                        {{ content.text|bootstrap|content }}
                                    </div>
                                {% elseif content.type == "image_text" %}
                                    {% partial content.partial image=content.image text=content.text heading=content.heading switched=content.switch_order size=(content.is_fluid ? "lg") options=options %}
                                {% elseif content.type == "posts" %}
                                    {% component "posts" categoryFilter=content.posts_category.slug rowCols=content.row_cols postsPerPage=content.items_per_page showPagination=true partial=content.partial sortOrder=content.posts_sort_order options=options %}
                                {% elseif content.type == "gallery" %}
                                    {% component "gallery" gallery=content.gallery.slug %}
                                {% elseif content.type == "files" %}
                                    {% component "files" category=content.files_category.slug %}
                                {% elseif content.type == "pricelist" %}
                                    {% component "pricelist" slug=content.pricelist.slug %}
                                {% elseif content.type == "opening_hours" %}
                                    {% component "openinghours" slug=content.opening_hours.slug %}
                                {% elseif content.type == "faq" %}
                                    {% partial "_accordion/faq" items=faq %}
                                {% elseif content.type == "slider" %}
                                    {% partial "_swiper/slider" slides=content.slider.slides settings=content.slider %}
                                {% elseif content.type == "embed" %}
                                    {% partial "_block/embed" embed=content.embed %}
                                {% elseif content.type == "cookies" %}
                                    {% component "cookiesmanage" %}
                                {% elseif content.type == "contacts" %}
                                    {% partial "_contacts/default" partial=content.partial row_cols=content.row_cols options=options %}
                                {% elseif content.type == "jobs" %}
                                    {% partial "_jobs/default" partial=content.partial row_cols=content.row_cols post_page=content.post_page options=options %}
                                {% elseif content.type == "links" %}
                                    {% partial "_links/default" partial=content.partial row_cols=content.row_cols category=content.links_category options=options %}
                                {% elseif content.type == "links_slider" %}
                                    {% partial "_swiper/scrollable" items=content.links_category.links.toNested.lists("link") slides_per_view=content.row_cols partial=content.partial options=options %}
                                {% elseif content.type == "partial" %}
                                    {% partial content.partial %}
                                {% elseif content.type == "contact_form" %}
                                    {% component "contactform" %}
                                {% elseif content.type == "timeline" %}
                                    {% component "timeline" slug=content.timeline.slug %}
                                {% endif %}
                            </div>
                        </div>
                    {% endfor %}
                </div>
            </div>
        </div>
    </div>
</div>
````

## Example post page

````htm
url = "/:fullslug*/p/:slug"
layout = "default"
title = "Post"
seoOptionsMetaTitle = "{{ post.id ? post.title }} | {{ page.title }}"

[breadcrumbs]
pageCode = "page"

[page]
column = "fullslug"
value = "{{ :fullslug }}"

[blogPost post]
slug = "{{ :slug }}"
categoryPage = "page"
==
<div class="container-fluid">
    <div class="container-lg">
        <div id="breadcrumb">
            <div class="row g-5">
                <div class="col">
                    {% component "breadcrumbs" %}
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid py-5" id="page-post">
    <div class="container-lg pb-xl-4">
        {% component "post" %}
    </div>
</div>
````

## Example job page

````htm
url = "/:fullslug*/j/:slug"
layout = "default"
title = "Job"
seoOptionsMetaTitle = "{{ job.id ? job.title }} | {{ page.title }}"

[section job]
handle = "Jobs\Job"
identifier = "slug"
value = "{{ :slug }}"

[breadcrumbs]
pageCode = "page"

[page]
column = "fullslug"
value = "{{ :fullslug }}"
==
<div class="container-fluid">
    <div class="container-lg">
        <div id="breadcrumb">
            <div class="row g-5">
                <div class="col">
                    {% component "breadcrumbs" %}
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid py-5" id="page-post">
    <div class="container-lg pb-xl-4">
        {% set job_post_partial = null %}

        {% for content in page.contents %}
            {% if content.type == "jobs" %}
                {% set job_post_partial = content.post_partial %}
            {% endif %}
        {% endfor %}

        {% partial job_post_partial post=job %}
    </div>
</div>
````

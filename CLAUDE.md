# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plugin Overview

`humlnetcreative/pages` is an October CMS plugin (namespace `HumlnetCreative\Pages`) providing:
- A hierarchical **page tree** (slug + fullslug) with multisite support
- A **content block system** for per-page content sections
- A **homepage block builder** for global blocks independent of the page tree
- Menu item integration with both `cms.pageLookup` and `pages.menuitem` events
- Fine-grained **per-permission** controls including per-page structure permissions

This plugin is a standalone Git repository deployed as a subtree/submodule inside the parent skeleton project at `plugins/humlnetcreative/pages/`.

## Common Commands

Run from the skeleton root (`plugins/humlnetcreative/pages/../../..`):

```bash
php artisan october:migrate        # Apply new migrations (run after adding updates/)
composer test                      # Run all tests
composer lint                      # PSR-2 code style check
./vendor/bin/phpcbf                # Auto-fix code style issues
```

## Architecture

### Models

Three core models with the corresponding DB tables:

| Model | Table | Purpose |
|-------|-------|---------|
| `Page` | `lzaplata_pages_pages` | Hierarchical pages (SimpleTree, Sortable, Multisite, SoftDelete) |
| `Content` | `lzaplata_pages_contents` | Per-page content sections (Sortable, Multisite, SoftDelete) |
| `Block` | `lzaplata_pages_blocks` | Homepage builder blocks (Multisite, SoftDelete) |

**`Page`** uses `SimpleTree` for parent/child relationships. `fullslug` is computed recursively from all ancestors' `slug` values; `afterSave()` triggers `updateChildrenFullslug()` to cascade changes. The `filterFields()` method auto-computes `fullslug` and `sort_order` on form refresh.

**`Block`** and **`Content`** both store type-specific options in a JSON `options` column plus a `padding` JSON column and an `options_inherited` flag. On `beforeSave()` the relevant `options_*` attribute (e.g. `options_posts`, `options_text`) is written into `options` and unset; `afterFetch()` reverses this. `options_inherited` (default `true`) signals whether the theme's config should be used instead of the record's own `options`. Field definitions for each type live in separate YAML files under `models/block/` or `models/content/`.

**`Content`** belongs to a `Page` and supports a broader set of content types than `Block`.

### DB Columns (blocks and contents tables)

Both `lzaplata_pages_blocks` and `lzaplata_pages_contents` share:
- `padding` — JSON (`top`, `bottom`, `top_sm`, `bottom_sm`)
- `options` — JSON, type-specific options
- `options_inherited` — tinyint, default 1
- `posts_category_id` — FK to `rainlab_blog_categories`
- `posts_sort_order` — varchar(20)

### Content / Block Types

Available types are determined dynamically in `getTypeOptions()` based on installed plugins:

**Block** (homepage builder):
- Always: `text`, `image_text`, `embed`, `partial`
- Conditional: `slider`, `posts`, `posts_slider`, `flash_message`, `links`, `links_slider`, `contact_form`

**Content** (per-page):
- Always: `text`, `image_text`, `embed`, `partial`
- Conditional: `posts`, `gallery`, `files`, `pricelist`, `opening_hours`, `faq`, `contacts`, `slider`, `jobs`, `links`, `links_slider`, `cookies`, `contact_form`, `timeline`

### Options YAML Files

Type-specific nestedform definitions under `models/block/` and `models/content/`:

| File | Types it serves |
|------|----------------|
| `block/text.yaml` | `text` |
| `block/image-text.yaml` | `image_text` |
| `block/posts.yaml` | `posts`, `posts_slider` |
| `block/links.yaml` | `links`, `links_slider` |
| `block/slider.yaml` | `slider` |
| `block/flash-message.yaml` | `flash_message` |
| `block/partial.yaml` | `partial`, `embed`, `contact_form` |
| `content/posts.yaml` | `posts` |
| `content/gallery.yaml` | `gallery` |
| `content/contacts.yaml` | `contacts` |
| `content/jobs.yaml` | `jobs` |
| `content/faq.yaml` | `faq` |
| `content/cookies.yaml` | `cookies` |
| `content/timeline.yaml` | `timeline` |

### Components

- **`page`** (`Page.php`) — Loads a `Page` model by `column`/`value` properties (slug or fullslug), exposes `title`, `slug`, `contents`, `menu`, `metaTitle`, `metaDescription`, `slider`. Throws 404 if not found. Handles multisite URL parameter translation.
- **`breadcrumbs`** (`Breadcrumbs.php`) — Walks the page tree upward from the current page to build a breadcrumb trail. Requires the `page` component on the same CMS page. Supports an optional `pageCode` property to override which CMS page is used for URL generation.
- **`homepage`** (`Homepage.php`) — Exposes homepage `Block` records to the template.

### Form Widgets

Three custom form widgets registered in `Plugin.php`:

- **`blocktypeselector`** — Visual icon-based selector for block/content type. Icons in `formwidgets/blocktypeselector/assets/images/`.
- **`colorschemeselector`** — Color scheme picker (v2+).
- **`rangeselector`** — Numeric range slider, used for padding values (v2+). Supports `max` and `unit` options.

### Permission System

Permissions are defined in `plugin.yaml`. The `registerPermissions()` method dynamically adds one permission per `Page` record (`humlnetcreative.pages.structure.<fullslug-dot-notation>`), enabling per-page access control. These permissions gate which pages appear as selectable parents and which pages are listed in the backend.

### Menu Integration

`Plugin::boot()` binds to both `cms.pageLookup.*` and `pages.menuitem.*` event families so pages appear as menu item types in both the CMS page picker and the RainLab.Pages menu builder. Resolution delegates to `Page::getMenuTypeInfo()` and `Page::resolveMenuItem()`.

### Import Models

- `PageImport` / `ContentImport` — Standard October CMS `ImportModel` subclasses for bulk CSV import of pages and content.
- `ThemeImport` — Imports theme customization data (ThemeData) by theme code.

### Adding a New Content/Block Type

1. Add the type key/label to `getTypeOptions()` in `Block.php` or `Content.php` (guard with `class_exists` / `BlueprintIndexer` check if it depends on another plugin).
2. Add trigger conditions to the relevant fields in `models/block/fields.yaml` or `models/content/fields.yaml`.
3. Create a `models/block/<type>.yaml` or `models/content/<type>.yaml` nestedform for type-specific options and wire it up via `options_<type>` field + `beforeSave`/`afterFetch` match arms in the model.
4. Add the corresponding `{% elseif block.type == "..." %}` or `{% elseif content.type == "..." %}` branch in the theme template (see README.md for examples).
5. Add an SVG icon to `formwidgets/blocktypeselector/assets/images/` if desired.
6. Add a corresponding config YAML in the theme's `config/` directory and reference it from `config/fields.yaml`.
7. Run `php artisan october:migrate` to apply any schema changes.

### Versioning & Migrations

Migrations are registered in `updates/version.yaml`. The current version is `v2.1.0`.

Migrations:

- **v2.0.0** (`v2_changes.php`) — Added `padding` JSON, `options` JSON, `options_inherited` columns to both tables; renamed `blog_category_id` → `posts_category_id` and `blog_sort_order` → `posts_sort_order` on both `lzaplata_pages_blocks` and `lzaplata_pages_contents`.
- **v2.1.0** (`v2_1_0_migrate_scheme_keys.php`) — Migrates color scheme references from positional numeric indices (`"0"`–`"4"`) to stable string keys (`"primary"`, `"secondary"`, `"tertiary"`, `"slider"`, `"flash"`) in theme data and all block/content `options` JSON.

> Do not create a new migration file if an existing unrun migration can be edited instead.
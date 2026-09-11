# Page Builder Motion 4.4.1

Motion provides optional one-shot reveal and count-up behaviours owned by the Pages plugin. It does not require a database migration or a snapshot schema change. Sections without an active behaviour keep their existing JSON, markup and loading behaviour.

## Editor contract

Supported core sections are `text`, `image_text`, `cta`, `accordion`, `cards` and `gallery`. Project section `links` can opt in through the section registry. `hero`, `carousel`, `columns` and `embed` deliberately do not support motion.

The stored value is optional `style.motion`. Reveal and count-up are independent:

```json
{
  "effect": "fade-up",
  "duration": "normal",
  "delay_ms": 100,
  "stagger_items": false,
  "count_up": {
    "enabled": true,
    "duration": "normal"
  }
}
```

Allowed reveal effects are `fade`, `fade-up`, `fade-left`, `fade-right` and `scale-in`. Reveal durations are `fast` (300 ms), `normal` (500 ms) and `slow` (700 ms); delay is 0–400 ms in 100 ms steps. Count-up durations are `fast` (1.2 s), `normal` (2 s) and `slow` (4 s). The `style.motion` subtree is removed only when both behaviours are disabled.

Stagger is supported only for Cards, Gallery and opted-in list-like project sections. Its interval is fixed at 75 ms and it applies only when 1–7 renderer items are present; larger lists reveal as one section.

## Theme renderer contract

Read the normalized `section.motion` accessor. On every active section except the first top-level page section, render:

```twig
data-hucr-motion="{{ section.motion.effect }}"
data-hucr-motion-duration="{{ section.motion.duration }}"
data-hucr-motion-delay="{{ section.motion.delay_ms }}"
```

For a supported staggered list also render `data-hucr-motion-stagger="1"` on the section and `data-hucr-motion-item` on every direct reveal item. Do not render any of these attributes for `none` or the first page section.

For active count-up render its independent duration on the same section root:

```twig
data-hucr-motion-count-up="{{ section.motion.count_up.duration }}"
```

The content stores an exact positive marker around every value to animate:

```html
Až <span data-hucr-count-up>1 125</span> km
```

One marker contains exactly one localized number. Supported forms include signs, decimal comma or point, and space/NBSP/narrow-NBSP thousands grouping. Prefixes, units and a second value remain outside and receive their own marker. Optional canonical attributes `data-hucr-count-up-start`, `data-hucr-count-up-end` and `data-hucr-count-up-decimals` are accepted for importers; editors normally use the visible value and the default start `0`. Invalid or nested marker content is saved as ordinary text and never animated.

Core `cards` declares Count-up support for section and item `content.heading` / `content.text`. In the backend, headings use a compact one-line annotated editor and rich text adds a `Počítadlo` toolbar action. Select the exact number, click the action, then click the highlighted marker to preview or remove it. Canvas lists the discovered values and previews Count-up even when reveal is `none`.

The `BuilderPage` component loads `assets/css/frontend-motion.css` and `assets/js/frontend-motion.js` only if the prepared page tree contains a renderable reveal or an enabled Count-up with at least one marker. The runtime uses one shared `IntersectionObserver`, runs every behaviour once, handles October AJAX content, and fails open when browser APIs are unavailable. Reduced-motion users and print output always receive final visible values without transitions.

Themes that define additional section types can enable the feature through `config/page-builder.php` or the `humlnetcreative.pages.extendSectionDefinitions` event:

```php
'links' => [
    'motion' => [
        'enabled' => true,
        'stagger_items' => true,
        'count_up' => [
            'section_fields' => ['content.heading', 'content.text'],
            'item_fields' => ['content.heading'],
        ],
    ],
],
```

The declared field lists are both the editor allowlist and the sanitization/asset-detection boundary. The theme remains responsible for emitting the section renderer attributes and reveal item markers for that type; Count-up value markers are emitted from the annotated content itself.

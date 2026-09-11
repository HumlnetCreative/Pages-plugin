# Page Builder Motion 4.4.0

Motion is an optional, one-shot reveal effect owned by the Pages plugin. It does not require a database migration or a snapshot schema change. Sections without an active effect keep their existing JSON, markup and loading behaviour.

## Editor contract

Supported core sections are `text`, `image_text`, `cta`, `accordion`, `cards` and `gallery`. Project section `links` can opt in through the section registry. `hero`, `carousel`, `columns` and `embed` deliberately do not support motion.

The stored value is optional `style.motion`:

```json
{
  "effect": "fade-up",
  "duration": "normal",
  "delay_ms": 100,
  "stagger_items": false
}
```

Allowed effects are `fade`, `fade-up`, `fade-left`, `fade-right` and `scale-in`. Durations are `fast` (300 ms), `normal` (500 ms) and `slow` (700 ms); delay is 0–400 ms in 100 ms steps. Selecting “Bez efektu” removes the complete `style.motion` subtree.

Stagger is supported only for Cards, Gallery and opted-in list-like project sections. Its interval is fixed at 75 ms and it applies only when 1–7 renderer items are present; larger lists reveal as one section.

## Theme renderer contract

Read the normalized `section.motion` accessor. On every active section except the first top-level page section, render:

```twig
data-hucr-motion="{{ section.motion.effect }}"
data-hucr-motion-duration="{{ section.motion.duration }}"
data-hucr-motion-delay="{{ section.motion.delay_ms }}"
```

For a supported staggered list also render `data-hucr-motion-stagger="1"` on the section and `data-hucr-motion-item` on every direct reveal item. Do not render any of these attributes for `none` or the first page section.

The `BuilderPage` component loads `assets/css/frontend-motion.css` and `assets/js/frontend-motion.js` only if the prepared page tree contains at least one renderable effect. The runtime uses one shared `IntersectionObserver`, reveals every target once, handles October AJAX content, and fails open when browser APIs are unavailable. Reduced-motion users and print output never receive hidden content or transitions.

Themes that define additional section types can enable the feature through `config/page-builder.php` or the `humlnetcreative.pages.extendSectionDefinitions` event:

```php
'links' => [
    'motion' => ['enabled' => true, 'stagger_items' => true],
],
```

The theme remains responsible for emitting the renderer attributes and item markers for that type.

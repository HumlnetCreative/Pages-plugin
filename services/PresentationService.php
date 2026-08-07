<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SliderMediaContext;

class PresentationService
{
    public function build(Section $section): array
    {
        $slider = $section->slider;
        if (!$slider) {
            return ['slider' => null, 'slides' => [], 'mode' => 'empty', 'suppressed_images' => 0];
        }

        $visible = collect($slider->slides ?: [])->filter(fn($slide) => (bool) $slide->is_enabled)->values();
        $videos = $visible->filter(fn($slide) => ($slide->type ?: 'image') === 'video')->values();
        $images = $visible->filter(fn($slide) => ($slide->type ?: 'image') !== 'video')->values();
        $effective = $videos->isNotEmpty() ? $videos->take(1) : $images;

        $contexts = SliderMediaContext::with('media.asset')
            ->where('slider_id', $slider->id)
            ->whereIn('slide_id', $effective->pluck('id'))
            ->get()
            ->keyBy(fn($context) => $context->slide_id.'@'.($context->site_id ?? 'null'));

        $slides = $effective->map(function($slide) use ($contexts) {
            $context = $contexts->get($slide->id.'@'.($slide->site_id ?? 'null'))
                ?: $contexts->firstWhere('slide_id', $slide->id);
            if (!$context) {
                return null;
            }
            $media = $context->media;
            return [
                'record' => $slide,
                'type' => ($slide->type ?: 'image'),
                'title' => $slide->title_type === 'custom' ? $slide->title_custom : ($slide->title_type === 'title' ? $slide->title : null),
                'text' => $slide->text,
                'link' => $slide->link,
                'link_type' => $slide->link_type,
                'button' => $slide->btn,
                'desktop' => $media->first(fn($use) => str_starts_with($use->slot, 'slider_desktop_')),
                'mobile' => $media->first(fn($use) => str_starts_with($use->slot, 'slider_mobile_')),
                'mp4' => $media->firstWhere('slot', 'slider_video_mp4'),
                'webm' => $media->firstWhere('slot', 'slider_video_webm'),
                'poster_desktop' => $media->first(fn($use) => str_starts_with($use->slot, 'slider_poster_desktop_')),
                'poster_mobile' => $media->first(fn($use) => str_starts_with($use->slot, 'slider_poster_mobile_')),
            ];
        })->filter(function($slide) {
            if (!$slide) {
                return false;
            }
            return $slide['type'] === 'video' ? (bool) $slide['mp4'] : (bool) ($slide['desktop'] && $slide['mobile']);
        })->values()->all();

        return [
            'slider' => $slider,
            'slides' => $slides,
            'mode' => $videos->isNotEmpty() ? 'video' : ($slides ? 'images' : 'empty'),
            'suppressed_images' => $videos->isNotEmpty() ? $images->count() : 0,
        ];
    }
}

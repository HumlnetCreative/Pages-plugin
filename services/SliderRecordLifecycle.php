<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SliderMediaContext;
use Tailor\Models\EntryRecord;

class SliderRecordLifecycle
{
    public static function bind(EntryRecord $model): void
    {
        $model->bindEvent('model.beforeCreate', function() use ($model) {
            if ($model->blueprint_uuid === 'lzaplata_slider_slides' && $model->is_enabled === null) {
                $model->is_enabled = true;
            }
        });
        $model->bindEvent('model.beforeSave', function() use ($model) {
            static::validateSlidePublication($model);
            static::validateSliderDimensions($model);
        });
        $model->bindEvent('model.afterSave', fn() => static::regenerateSliderMedia($model));
        $model->bindEvent('model.beforeDelete', fn() => static::beforeDelete($model));
        $model->bindEvent('model.afterDelete', fn() => static::afterDelete($model));
    }

    protected static function validateSlidePublication(EntryRecord $model): void
    {
        if (!$model->exists || $model->blueprint_uuid !== 'lzaplata_slider_slides' || !$model->is_enabled) return;
        foreach (($model->sliders ?: collect()) as $slider) {
            $context = SliderMediaContext::where('slider_id', $slider->id)->where('slide_id', $model->id)
                ->where(fn($query) => is_null($model->site_id) ? $query->whereNull('site_id') : $query->where('site_id', $model->site_id))->first();
            if (!$context) throw new \ValidationException(['slider_media' => 'Před publikováním připravte média položky pro Slider „'.$slider->title.'“.']);
            if (($model->type ?: 'image') === 'video') {
                if (!$context->media()->whereIn('slot', ['slider_video_mp4', 'slider_video_webm'])->exists()) throw new \ValidationException(['slider_media' => 'Video položka musí mít alespoň jeden MP4 nebo WebM soubor.']);
                if ($slider->slides->contains(fn($slide) => $slide->id !== $model->id && $slide->is_enabled && ($slide->type ?: 'image') === 'video')) {
                    throw new \ValidationException(['type' => 'V jednom Slideru může být publikována nejvýše jedna video položka.']);
                }
            }
            else {
                foreach (['slider_desktop_', 'slider_mobile_'] as $prefix) {
                    if (!$context->media()->where('slot', 'like', $prefix.'%')->exists()) throw new \ValidationException(['slider_media' => 'Obrázková položka potřebuje desktopový i mobilní výřez.']);
                }
            }
        }
    }

    protected static function validateSliderDimensions(EntryRecord $model): void
    {
        $fields = ['image_sm_width', 'image_sm_height', 'image_lg_width', 'image_lg_height'];
        if (!$model->exists || $model->blueprint_uuid !== 'lzaplata_slider_sliders' || !$model->isDirty($fields)) return;
        $problems = [];
        foreach (SliderMediaContext::with('media.asset')->where('slider_id', $model->id)->get() as $context) {
            foreach (['desktop' => ['image_lg_width', 'image_lg_height'], 'mobile' => ['image_sm_width', 'image_sm_height']] as $viewport => [$widthField, $heightField]) {
                foreach ($context->media->filter(fn($use) => str_starts_with($use->slot, 'slider_'.$viewport.'_') || str_starts_with($use->slot, 'slider_poster_'.$viewport.'_')) as $use) {
                    if ($use->asset->width < (int) $model->{$widthField} || $use->asset->height < (int) $model->{$heightField}) $problems[] = ($context->slide()?->title ?: 'Položka #'.$context->slide_id).' — '.$use->asset->original_name;
                }
            }
        }
        if ($problems) throw new \ValidationException(['image_lg_width' => 'Nové rozměry přesahují zdrojová média: '.implode('; ', array_unique($problems))]);
    }

    protected static function regenerateSliderMedia(EntryRecord $model): void
    {
        $fields = ['image_sm_width', 'image_sm_height', 'image_lg_width', 'image_lg_height'];
        if ($model->blueprint_uuid !== 'lzaplata_slider_sliders' || !$model->wasChanged($fields)) return;
        $service = new MediaService();
        foreach (SliderMediaContext::with('media.asset')->where('slider_id', $model->id)->get() as $context) {
            foreach (['desktop', 'mobile'] as $viewport) {
                [$width, $height] = $context->dimensions($viewport);
                foreach ($context->media->filter(fn($use) => str_starts_with($use->slot, 'slider_'.$viewport.'_') || str_starts_with($use->slot, 'slider_poster_'.$viewport.'_')) as $use) {
                    $use->slot = (str_contains($use->slot, 'poster_') ? 'slider_poster_' : 'slider_').$viewport.'_'."{$width}x{$height}";
                    $use->crop = $service->cropFromFocus($use->asset, $use->slot);
                    $use->variants = $service->regenerateVariants($use);
                    $use->save();
                }
            }
        }
    }

    protected static function beforeDelete(EntryRecord $model): void
    {
        if ($model->blueprint_uuid === 'lzaplata_slider_sliders' && !SliderLifecycle::isForceDeleting()) {
            $uses = Section::where('slider_id', $model->id)->count();
            $word = $uses === 1 ? 'blok' : ($uses >= 2 && $uses <= 4 ? 'bloky' : 'bloků');
            if ($uses) throw new \ApplicationException("Slider používá {$uses} {$word} Prezentace. Odpojte jej, nebo použijte vynucené odstranění v panelu použití Slideru.");
        }
        if ($model->blueprint_uuid === 'lzaplata_slider_slides') SliderMediaContext::where('slide_id', $model->id)->get()->each->delete();
    }

    protected static function afterDelete(EntryRecord $model): void
    {
        if ($model->blueprint_uuid === 'lzaplata_slider_sliders') SliderMediaContext::where('slider_id', $model->id)->get()->each->delete();
    }
}

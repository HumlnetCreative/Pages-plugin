<?php namespace HumlnetCreative\Pages\Services;

use Tailor\Models\EntryRecord;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SliderMediaContext;

class SliderLifecycle
{
    protected static bool $forceDeleting = false;

    public static function isForceDeleting(): bool
    {
        return static::$forceDeleting;
    }

    public static function forceDelete(EntryRecord $slider): void
    {
        static::$forceDeleting = true;
        try {
            Section::where('slider_id', $slider->id)->update(['slider_id' => null, 'is_published' => false]);
            SliderMediaContext::where('slider_id', $slider->id)->get()->each->delete();
            $slider->delete();
        }
        finally {
            static::$forceDeleting = false;
        }
    }
}

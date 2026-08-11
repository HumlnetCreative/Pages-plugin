<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Models\Section;
use LZaplata\Gallery\Models\Gallery;

class GalleryRecordLifecycle
{
    public static function bind(Gallery $gallery): void
    {
        $gallery->bindEvent('model.beforeDelete', function() use ($gallery) {
            $uses = Section::where('gallery_id', $gallery->id)->count();
            if (!$uses) {
                return;
            }

            $word = $uses === 1 ? 'blok' : ($uses >= 2 && $uses <= 4 ? 'bloky' : 'bloků');
            throw new \ApplicationException("Galerii používá {$uses} {$word}. Před odstraněním ji z těchto bloků odpojte.");
        });
    }
}

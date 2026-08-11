<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Models\Section;
use Tailor\Models\EntryRecord;

class FaqLifecycle
{
    protected static bool $forceDeleting = false;

    public static function isForceDeleting(): bool
    {
        return static::$forceDeleting;
    }

    public static function forceDelete(EntryRecord $group): void
    {
        static::$forceDeleting = true;

        try {
            Section::where('faq_group_id', $group->id)->update([
                'faq_group_id' => null,
                'is_published' => false,
            ]);
            $group->delete();
        }
        finally {
            static::$forceDeleting = false;
        }
    }
}

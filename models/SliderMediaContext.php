<?php namespace HumlnetCreative\Pages\Models;

use Illuminate\Support\Str;
use October\Rain\Database\Model;
use Tailor\Models\EntryRecord;

class SliderMediaContext extends Model
{
    public $table = 'humlnetcreative_pages_slider_media_contexts';
    protected $guarded = [];
    protected $jsonable = ['settings'];

    public $morphMany = [
        'media' => [MediaUse::class, 'name' => 'owner', 'order' => 'slot'],
    ];

    public function beforeSave()
    {
        $this->uuid ??= (string) Str::uuid();
    }

    public function slider(): ?EntryRecord
    {
        return EntryRecord::inSection('Slider\\Slider')->find($this->slider_id);
    }

    public function slide(): ?EntryRecord
    {
        return EntryRecord::inSection('Slider\\Slide')->find($this->slide_id);
    }

    public static function forEntries(EntryRecord $slider, EntryRecord $slide): self
    {
        return static::firstOrCreate([
            'slider_id' => $slider->getKey(),
            'slide_id' => $slide->getKey(),
            'site_id' => $slide->site_id,
        ], [
            'uuid' => (string) Str::uuid(),
            'settings' => [],
        ]);
    }

    public function dimensions(string $viewport): array
    {
        $slider = $this->slider();
        $prefix = $viewport === 'mobile' ? 'image_sm' : 'image_lg';
        $fallback = $viewport === 'mobile' ? [640, 640] : [1920, 550];

        return [
            max(1, (int) ($slider?->{$prefix.'_width'} ?: $fallback[0])),
            max(1, (int) ($slider?->{$prefix.'_height'} ?: $fallback[1])),
        ];
    }

    public function imageSlot(string $viewport): string
    {
        [$width, $height] = $this->dimensions($viewport);
        return "slider_{$viewport}_{$width}x{$height}";
    }

    public function mediaFor(string $prefix): ?MediaUse
    {
        return $this->media->first(fn(MediaUse $use) => str_starts_with($use->slot, $prefix));
    }

    public function afterDelete()
    {
        $this->media()->get()->each(fn(MediaUse $use) => $use->delete());
    }
}

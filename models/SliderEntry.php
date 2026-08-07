<?php namespace HumlnetCreative\Pages\Models;

use Tailor\Models\EntryRecord;

/** Blueprint-bound Tailor model safe for ordinary Eloquent relationships. */
class SliderEntry extends EntryRecord
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->extendWithBlueprint('lzaplata_slider_sliders');
    }
}

<?php namespace HumlnetCreative\Pages\Models;

use Tailor\Models\EntryRecord;

/** Blueprint-bound Tailor model safe for ordinary Eloquent relationships. */
class FaqGroupEntry extends EntryRecord
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->extendWithBlueprint('humlnetcreative_faq_groups');
    }
}

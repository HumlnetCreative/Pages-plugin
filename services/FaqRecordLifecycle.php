<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Models\Section;
use Tailor\Models\EntryRecord;

class FaqRecordLifecycle
{
    public static function bind(EntryRecord $model): void
    {
        $model->bindEvent('model.beforeCreate', function() use ($model) {
            if (in_array($model->blueprint_uuid, ['humlnetcreative_faq_groups', 'lzaplata_faq'], true)
                && $model->is_enabled === null) {
                $model->is_enabled = true;
            }
        });

        $model->bindEvent('model.beforeSave', function() use ($model) {
            if ($model->blueprint_uuid !== 'lzaplata_faq' || !$model->is_enabled) {
                return;
            }

            if (trim((string) $model->title) === '') {
                throw new \ValidationException(['title' => 'Publikovaná FAQ položka musí obsahovat otázku.']);
            }

            if (trim(strip_tags((string) $model->answer)) === '') {
                throw new \ValidationException(['answer' => 'Publikovaná FAQ položka musí obsahovat odpověď.']);
            }
        });

        $model->bindEvent('model.beforeDelete', function() use ($model) {
            if ($model->blueprint_uuid !== 'humlnetcreative_faq_groups' || FaqLifecycle::isForceDeleting()) {
                return;
            }

            $uses = Section::where('faq_group_id', $model->id)->count();
            if (!$uses) {
                return;
            }

            $word = $uses === 1 ? 'blok' : ($uses >= 2 && $uses <= 4 ? 'bloky' : 'bloků');
            throw new \ApplicationException("FAQ skupinu používá {$uses} {$word}. Odpojte ji, nebo použijte vynucené odstranění v panelu použití.");
        });
    }
}

<?php if ($model->exists): ?>
    <div class="callout fade in <?= $sections->isEmpty() ? 'callout-success' : 'callout-warning' ?>">
        <div class="header"><h4>Použití Slideru</h4></div>
        <div class="content">
            <?php
                $visibleSlides = collect($model->slides ?: [])->filter(fn($slide) => $slide->is_enabled);
                $visibleVideos = $visibleSlides->filter(fn($slide) => ($slide->type ?: 'image') === 'video');
                $visibleImages = $visibleSlides->filter(fn($slide) => ($slide->type ?: 'image') !== 'video');
            ?>
            <?php if ($visibleVideos->isNotEmpty() && $visibleImages->isNotEmpty()): ?><p><strong>Video má přednost:</strong> <?= $visibleImages->count() ?> publikovaných obrázkových položek se na webu nezobrazí. Po skrytí videa se automaticky znovu použijí.</p><?php endif ?>
            <?php if ($sections->isEmpty()): ?><p>Slider momentálně nepoužívá žádný blok Prezentace.</p>
            <?php else: ?><p>Slider používá <?= $sections->count() ?> bloků:</p><ul><?php foreach ($sections as $section): ?><li><a href="<?= Backend::url('humlnetcreative/pages/builderpages/update/'.$section->page_id) ?>" target="_blank"><?= e($section->page?->title ?: 'Stránka #'.$section->page_id) ?> — <?= e($section->title ?: 'Prezentace') ?></a></li><?php endforeach ?></ul><?php endif ?>
            <?php if (\Backend\Facades\BackendAuth::userHasPermission('humlnetcreative.pages.slider.force_delete')): ?>
                <button type="button" class="btn btn-danger oc-icon-trash-o" data-request="<?= e($handler) ?>" data-request-confirm="Slider bude odstraněn. Všechny navázané bloky Prezentace se odpojí a skryjí. Pokračovat?">Vynuceně odstranit Slider</button>
            <?php endif ?>
        </div>
    </div>
<?php endif ?>

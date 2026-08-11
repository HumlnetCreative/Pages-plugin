<?php if ($model->exists): ?>
    <div class="callout fade in <?= $sections->isEmpty() ? 'callout-success' : 'callout-warning' ?>">
        <div class="header"><h4>Použití FAQ skupiny</h4></div>
        <div class="content">
            <?php if ($sections->isEmpty()): ?>
                <p>FAQ skupinu momentálně nepoužívá žádný blok.</p>
            <?php else: ?>
                <p>FAQ skupinu používá <?= $sections->count() ?> bloků:</p>
                <ul>
                    <?php foreach ($sections as $section): ?>
                        <li><a href="<?= Backend::url('humlnetcreative/pages/builderpages/update/'.$section->page_id) ?>" target="_blank"><?= e($section->page?->title ?: 'Stránka #'.$section->page_id) ?> — <?= e($section->title ?: 'FAQ') ?></a></li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>

            <?php if (\Backend\Facades\BackendAuth::userHasPermission('humlnetcreative.pages.faq.force_delete')): ?>
                <button type="button" class="btn btn-danger oc-icon-trash-o" data-request="<?= e($handler) ?>" data-request-confirm="FAQ skupina bude odstraněna. Všechny navázané FAQ bloky se odpojí a skryjí. Pokračovat?">Vynuceně odstranit FAQ skupinu</button>
            <?php endif ?>
        </div>
    </div>
<?php endif ?>

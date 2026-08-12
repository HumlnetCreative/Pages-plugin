<div class="btn-group">
    <a
        href="<?= Backend::url('humlnetcreative/pages/builderpages/update/'.$record->id) ?>"
        class="btn btn-default btn-sm oc-icon-pencil"
        title="Upravit stránku"
        aria-label="Upravit stránku <?= e($record->title) ?>">
    </a>
    <?php if (!$record->published_revision_id): ?>
        <button
            type="button"
            class="btn btn-danger btn-sm"
            title="Odstranit nepublikovanou stránku"
            aria-label="Odstranit nepublikovanou stránku <?= e($record->title) ?>"
            data-request="onDeleteUnpublishedFromList"
            data-request-data="page_id: <?= (int) $record->id ?>"
            data-load-indicator="Odstraňuji stránku…"
            data-request-confirm="Opravdu chcete odstranit dosud nepublikovanou stránku „<?= e($record->title) ?>“?">
            <i class="icon-trash-o" aria-hidden="true"></i>
            <span>Odstranit</span>
        </button>
    <?php else: ?>
        <button
            type="button"
            class="btn btn-danger btn-sm"
            disabled
            title="Publikovanou stránku bude možné odstranit až řízeným návrhem 410/301 v etapě URL workflow"
            aria-label="Publikovanou stránku nyní nelze odstranit">
            <i class="icon-trash-o" aria-hidden="true"></i>
            <span>Odstranit</span>
        </button>
    <?php endif ?>
</div>

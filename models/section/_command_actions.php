<div class="btn-group">
    <?php if (BackendAuth::userHasPermission('humlnetcreative.pages.structure.duplicate')): ?>
        <button type="button" class="btn btn-default btn-sm" data-request="onDuplicateSection" data-request-data="page_id: <?= (int) $record->page_id ?>, section_id: <?= (int) $record->id ?>" data-load-indicator="Duplikuji…" title="Duplikovat sekci"><i class="icon-copy" aria-hidden="true"></i> Duplikovat</button>
    <?php endif ?>
    <?php if (BackendAuth::userHasPermission('humlnetcreative.pages.structure.copy')): ?>
        <button type="button" class="btn btn-default btn-sm" data-request="onCopySection" data-request-data="page_id: <?= (int) $record->page_id ?>, section_id: <?= (int) $record->id ?>" data-load-indicator="Kopíruji…" title="Zkopírovat sekci pro vložení na této nebo jiné stránce"><i class="icon-clipboard" aria-hidden="true"></i> Kopírovat</button>
    <?php endif ?>
</div>

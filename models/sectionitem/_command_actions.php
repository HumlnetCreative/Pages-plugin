<?php if (BackendAuth::userHasPermission('humlnetcreative.pages.structure.duplicate')): ?>
    <button type="button" class="btn btn-default btn-sm" data-request="onDuplicateItem" data-request-data="item_id: <?= (int) $record->id ?>" data-load-indicator="Duplikuji…" title="Duplikovat položku"><i class="icon-copy" aria-hidden="true"></i> Duplikovat</button>
<?php endif ?>

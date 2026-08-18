<button
    type="button"
    class="btn btn-primary btn-sm oc-icon-undo"
    data-request="onRestorePageFromTrash"
    data-request-data="page_id: <?= (int) $record->id ?>"
    data-load-indicator="Obnovuji stránku…"
    data-request-confirm="Obnovit stránku „<?= e($record->title) ?>“ z koše jako nový koncept? Původní 410/301 zůstane aktivní až do další publikace.">
    Obnovit jako koncept
</button>

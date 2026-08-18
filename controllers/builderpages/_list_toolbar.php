<div class="toolbar">
    <a href="<?= Backend::url('humlnetcreative/pages/builderpages/create') ?>" class="btn btn-primary oc-icon-plus">Nová stránka</a>
    <?php if (BackendAuth::userHasPermission('humlnetcreative.pages.builder.trash')): ?>
        <a href="<?= Backend::url('humlnetcreative/pages/builderpages/trash') ?>" class="btn btn-default oc-icon-trash-o">Koš</a>
    <?php endif ?>
</div>

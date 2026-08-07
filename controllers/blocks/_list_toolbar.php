<div data-control="toolbar">
    <?php if (BackendAuth::userHasPermission("humlnetcreative.pages.block.create")): ?>
        <a
            href="<?= Backend::url('humlnetcreative/pages/blocks/create') ?>"
            class="btn btn-primary oc-icon-plus">
            <?= e(trans('humlnetcreative.pages::lang.block.create.title')) ?>
        </a>
    <?php endif ?>

    <?php if (BackendAuth::userHasPermission("humlnetcreative.pages.block.delete")): ?>
        <button
            class="btn btn-default oc-icon-trash-o"
            data-request="onDelete"
            data-request-confirm="<?= e(trans('backend::lang.list.delete_selected_confirm')) ?>"
            data-list-checked-trigger
            data-list-checked-request
            data-stripe-load-indicator>
            <?= e(trans('backend::lang.list.delete_selected')) ?>
        </button>
    <?php endif ?>
</div>

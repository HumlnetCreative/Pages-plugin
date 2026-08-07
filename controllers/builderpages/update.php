<?php Block::put('breadcrumb') ?>
    <ul>
        <li><a href="<?= Backend::url('humlnetcreative/pages/builderpages') ?>">Page Builder</a></li>
        <li><?= e($this->pageTitle) ?></li>
    </ul>
<?php Block::endPut() ?>

<?php if (!$this->fatalError): ?>
    <?= Form::open(['class' => 'layout']) ?>
        <div class="layout-row"><?= $this->formRender() ?></div>
        <div class="form-buttons">
            <div class="loading-indicator-container">
                <button type="submit" data-request="onSave" data-request-data="redirect:0" data-hotkey="ctrl+s, cmd+s" data-load-indicator="Ukládám…" class="btn btn-primary">Uložit</button>
                <button type="button" data-request="onSave" data-request-data="close:1" data-hotkey="ctrl+enter, cmd+enter" data-load-indicator="Ukládám…" class="btn btn-default">Uložit a zavřít</button>
                <button type="button" class="oc-icon-trash-o btn-icon danger pull-right" title="Odstranit stránku" data-request="onDelete" data-load-indicator="Odstraňuji…" data-request-confirm="Opravdu chcete tuto stránku odstranit?"></button>
                <span class="btn-text">nebo <a href="<?= Backend::url('humlnetcreative/pages/builderpages') ?>">zrušit</a></span>
            </div>
        </div>
    <?= Form::close() ?>
<?php else: ?>
    <p class="flash-message static error"><?= e(trans($this->fatalError)) ?></p>
    <p><a href="<?= Backend::url('humlnetcreative/pages/builderpages') ?>" class="btn btn-default">Zpět na seznam</a></p>
<?php endif ?>

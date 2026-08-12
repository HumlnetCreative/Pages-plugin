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
                <button type="submit" data-hucr-save-primary data-request="onSave" data-hotkey="ctrl+s, cmd+s" data-load-indicator="Vytvářím koncept…" class="btn btn-primary">Vytvořit koncept</button>
                <button type="button" data-hucr-save-close data-request="onSave" data-request-data="close:1" data-hotkey="ctrl+shift+s, cmd+shift+s" data-load-indicator="Vytvářím koncept…" class="btn btn-default">Vytvořit a zavřít</button>
                <span class="btn-text">nebo <a href="<?= Backend::url('humlnetcreative/pages/builderpages') ?>">zrušit</a></span>
            </div>
        </div>
    <?= Form::close() ?>
<?php else: ?>
    <p class="flash-message static error"><?= e(trans($this->fatalError)) ?></p>
    <p><a href="<?= Backend::url('humlnetcreative/pages/builderpages') ?>" class="btn btn-default">Zpět na seznam</a></p>
<?php endif ?>

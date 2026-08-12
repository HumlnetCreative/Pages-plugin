<?php Block::put('breadcrumb') ?>
    <ul>
        <li><a href="<?= Backend::url('humlnetcreative/pages/builderpages') ?>">Page Builder</a></li>
        <li><?= e($this->pageTitle) ?></li>
    </ul>
<?php Block::endPut() ?>

<?php if (!$this->fatalError): ?>
    <?= Form::open(['class' => 'layout', 'data-hucr-revision-editor' => '', 'data-page-id' => (int) $builderPage->id]) ?>
        <?= $this->makePartial('revision_status', [
            'builderPage' => $builderPage,
            'pageLockState' => $pageLockState,
            'pageReadOnly' => $pageReadOnly,
            'draftSourceVersion' => $draftSourceVersion,
        ]) ?>
        <fieldset class="layout-row hucr-revision-editor__fields" <?= $pageReadOnly ? 'disabled' : '' ?>>
            <?= $this->formRender() ?>
        </fieldset>
        <div class="form-buttons">
            <div class="loading-indicator-container">
                <a href="#" data-request="onReleaseLock" data-request-data="page_id: <?= (int) $builderPage->id ?>" data-load-indicator="Zavírám editor…">Zavřít editor</a>
            </div>
        </div>
        <?php if (!$pageReadOnly): ?>
            <button type="button" hidden data-hucr-heartbeat data-request="onHeartbeat" data-request-data="page_id: <?= (int) $builderPage->id ?>"></button>
        <?php endif ?>
    <?= Form::close() ?>
<?php else: ?>
    <p class="flash-message static error"><?= e(trans($this->fatalError)) ?></p>
    <p><a href="<?= Backend::url('humlnetcreative/pages/builderpages') ?>" class="btn btn-default">Zpět na seznam</a></p>
<?php endif ?>

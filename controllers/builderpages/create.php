<?php if (!$this->fatalError): ?>
    <?= Form::open(['class' => 'layout']) ?>
        <div class="hucr-revision-status" data-has-published="false" data-has-draft="false">
            <div class="hucr-revision-status__state">
                <div class="hucr-revision-status__identity">
                    <strong class="hucr-revision-status__page-title">Nová stránka</strong>
                    <span class="hucr-revision-status__badge">Dosud neuloženo</span>
                </div>
                <span data-hucr-public-status>Veřejná URL do první publikace vrací 404.</span>
                <small>Nejdříve vytvořte koncept. Potom bude možné skládat obsah a stránku publikovat.</small>
            </div>
            <div class="hucr-revision-status__actions">
                <div class="hucr-revision-status__group" aria-label="Historie aktuální relace">
                    <button type="button" class="btn btn-default btn-sm" disabled><i class="icon-undo" aria-hidden="true"></i> Zpět</button>
                    <button type="button" class="btn btn-default btn-sm" disabled><i class="icon-repeat" aria-hidden="true"></i> Znovu</button>
                </div>
                <button type="button" class="btn btn-default btn-sm" disabled><i class="icon-history" aria-hidden="true"></i> Historie verzí</button>
                <div class="btn-group hucr-revision-status__split">
                    <button type="button" class="btn btn-default btn-sm" disabled><i class="icon-eye" aria-hidden="true"></i> Náhled konceptu</button>
                    <button type="button" class="btn btn-default btn-sm dropdown-toggle" disabled aria-label="Další možnosti náhledu"><span class="caret"></span></button>
                </div>
                <div class="btn-group hucr-revision-status__split dropdown">
                    <button type="submit" data-hucr-save-primary data-request="onSave" data-hotkey="ctrl+s, cmd+s" data-load-indicator="Vytvářím koncept…" class="btn btn-primary btn-sm"><i class="icon-save" aria-hidden="true"></i> Vytvořit koncept</button>
                    <button type="button" class="btn btn-primary btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Další možnosti vytvoření" aria-label="Další možnosti vytvoření"><span class="caret"></span></button>
                    <ul class="dropdown-menu dropdown-menu-right" role="menu">
                        <li><button type="button" data-hucr-save-close data-request="onSave" data-request-data="close:1" data-hotkey="ctrl+shift+s, cmd+shift+s" data-load-indicator="Vytvářím koncept…"><i class="icon-check" aria-hidden="true"></i> Vytvořit a zavřít</button></li>
                    </ul>
                </div>
                <button type="button" class="btn btn-success btn-sm" disabled><i class="icon-upload" aria-hidden="true"></i> Publikovat</button>
                <div class="dropdown hucr-revision-status__more">
                    <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Další akce stránky" aria-label="Další akce stránky"><i class="icon-ellipsis-h" aria-hidden="true"></i></button>
                    <ul class="dropdown-menu dropdown-menu-right" role="menu">
                        <li><a href="<?= Backend::url('humlnetcreative/pages/builderpages') ?>"><i class="icon-times" aria-hidden="true"></i> Zrušit vytváření</a></li>
                    </ul>
                </div>
                <button type="button" class="btn btn-default btn-sm hucr-revision-status__fullscreen" disabled><i class="icon-expand" aria-hidden="true"></i> <span>Celá obrazovka</span></button>
            </div>
        </div>
        <div class="layout-row"><?= $this->formRender() ?></div>
    <?= Form::close() ?>
<?php else: ?>
    <p class="flash-message static error"><?= e(trans($this->fatalError)) ?></p>
    <p><a href="<?= Backend::url('humlnetcreative/pages/builderpages') ?>" class="btn btn-default">Zpět na seznam</a></p>
<?php endif ?>

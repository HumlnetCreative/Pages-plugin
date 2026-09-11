<div class="hucr-count-up-text" data-hucr-count-up-text>
    <?php if (!$readOnly): ?>
        <div class="hucr-count-up-text__toolbar">
            <button type="button" class="btn btn-default btn-sm" data-hucr-count-up-wrap title="Označit vybrané číslo jako počítadlo">
                <i class="icon-sort-numeric-asc" aria-hidden="true"></i> Počítadlo
            </button>
            <small>Označte přesně číslo v textu.</small>
        </div>
    <?php endif ?>
    <div
        id="<?= e($id) ?>-editor"
        class="form-control hucr-count-up-text__editor"
        data-hucr-count-up-editor
        role="textbox"
        aria-multiline="false"
        contenteditable="<?= $readOnly ? 'false' : 'true' ?>"><?= $value ?></div>
    <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" data-hucr-count-up-value hidden><?= e($value) ?></textarea>
    <?php if (!$readOnly): ?>
        <div class="hucr-count-up-text__popover" data-hucr-count-up-popover hidden>
            <strong data-hucr-count-up-label></strong>
            <button type="button" class="btn btn-default btn-sm" data-hucr-count-up-preview>Přehrát</button>
            <button type="button" class="btn btn-danger btn-sm" data-hucr-count-up-remove>Odebrat</button>
            <button type="button" class="btn btn-link btn-sm" data-hucr-count-up-close aria-label="Zavřít">×</button>
        </div>
    <?php endif ?>
</div>

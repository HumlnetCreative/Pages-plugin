<?php if ($this->previewMode): ?>

    <div class="form-control">
        <?= $value ?>
    </div>

<?php else: ?>

    <div class="form-container color-scheme">

        <div class="form-check <?php if (!$value): ?>checked<?php endif; ?>">
            <input
                type="radio"
                name="<?= $name ?>"
                value="__inherit__"
                id="<?= $id ?>--inherit"
                class="form-check-input"
                <?php if (!$value): ?>checked<?php endif; ?>>
            <label for="<?= $id ?>--inherit" class="form-check-label">
                <div class="visual visual-inherit">
                    <span class="text">↳</span>
                </div>
                <div class="name">Dědit z nadřazené úrovně</div>
            </label>
        </div>

        <?php foreach ($options as $key => $option): ?>
            <?php $schemeKey = $option['key'] ?? (string) $key; ?>
            <div class="form-check <?php if ($schemeKey == $value): ?>checked<?php endif; ?>" style="--cs-bg: <?= $option["bg"] ?>; --cs-text: <?= $option["text"] ?>;">
                <input
                    type="radio"
                    name="<?= $name ?>"
                    value="<?= $schemeKey ?>"
                    id="<?= $id ?>--<?= $schemeKey ?>"
                    class="form-check-input"
                    <?php if ($schemeKey == $value): ?>checked<?php endif; ?>>

                <label for="<?= $id ?>--<?= $schemeKey ?>" class="form-check-label">
                    <div class="visual">
                        <span class="text">
                            Aa
                        </span>

                        <span class="buttons" style="--cs-btn-primary-bg: <?= $option["button_primary"]["bg"] ?>; --cs-btn-primary-border-color: <?= $option["button_primary"]["border"] ?>; --cs-btn-secondary-bg: <?= $option["button_secondary"]["bg"] ?>; --cs-btn-secondary-border-color: <?= $option["button_secondary"]["border"] ?>;">
                            <span class="button button-primary"></span>
                            <span class="button button-secondary"></span>
                        </span>
                    </div>

                    <div class="name">
                        <?= $option["title"] ?>
                    </div>
                </label>
            </div>

        <?php endforeach; ?>

    </div>

<?php endif ?>

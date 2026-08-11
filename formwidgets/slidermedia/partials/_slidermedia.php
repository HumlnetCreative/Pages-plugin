<div id="<?= e($widgetId) ?>" class="hucr-slider-media">
<?php if (!$model->exists): ?>
    <div class="callout fade in callout-info"><div class="content"><p>Nejprve položku uložte. Potom bude možné připravit její média.</p></div></div>
<?php elseif ($contexts->isEmpty()): ?>
    <div class="callout fade in callout-warning"><div class="content"><p>Položku nejprve přiřaďte ke Slideru a uložte. Média se připravují podle rozměrů konkrétního Slideru.</p></div></div>
<?php else: ?>
    <?php foreach ($contexts as $context): ?>
        <?php
            $slider = $context->slider();
            $desktop = $context->media->first(fn($use) => str_starts_with($use->slot, 'slider_desktop_'));
            $mobile = $context->media->first(fn($use) => str_starts_with($use->slot, 'slider_mobile_'));
            $mp4 = $context->media->firstWhere('slot', 'slider_video_mp4');
            $webm = $context->media->firstWhere('slot', 'slider_video_webm');
            $posterDesktop = $context->media->first(fn($use) => str_starts_with($use->slot, 'slider_poster_desktop_'));
            $posterMobile = $context->media->first(fn($use) => str_starts_with($use->slot, 'slider_poster_mobile_'));
            [$desktopWidth, $desktopHeight] = $context->dimensions('desktop');
            [$mobileWidth, $mobileHeight] = $context->dimensions('mobile');
        ?>
        <section class="hucr-slider-media__context">
            <header><h4><?= e($slider?->title ?: 'Slider #'.$context->slider_id) ?></h4><p>Desktop <?= $desktopWidth ?>×<?= $desktopHeight ?> px · mobil <?= $mobileWidth ?>×<?= $mobileHeight ?> px</p></header>

            <?php if (($model->type ?: 'image') === 'video'): ?>
                <div class="hucr-slider-media__uploads">
                    <?php foreach (['mp4' => ['MP4 video (povinné)', $mp4], 'webm' => ['WebM video (volitelné)', $webm]] as $format => [$label, $use]): ?>
                        <?php $uploadField = 'slider_media_file_video_'.$context->id.'_'.$format; ?>
                        <div class="hucr-slider-media__upload" id="slider-video-<?= $context->id ?>-<?= $format ?>">
                            <label><?= e($label) ?></label><input type="file" class="form-control" name="<?= e($uploadField) ?>" accept="video/<?= $format ?>">
                            <button type="button" class="btn btn-primary" data-request="<?= e($handler('onUploadVideo')) ?>" data-request-files data-request-form="#tailor-form" data-request-data="context_id: <?= $context->id ?>, format: '<?= $format ?>', upload_field: '<?= e($uploadField) ?>'">Nahrát <?= strtoupper($format) ?></button>
                            <?php if (!$use): ?><button type="button" class="btn btn-default" data-request="<?= e($handler('onReuseLocaleMedia')) ?>" data-request-data="context_id: <?= $context->id ?>, media_kind: 'video_<?= $format ?>'">Použít z jiné jazykové verze</button><?php endif ?>
                            <?php if ($use): ?><button type="button" class="btn btn-danger" data-request="<?= e($handler('onDeleteMedia')) ?>" data-request-data="context_id: <?= $context->id ?>, media_use_id: <?= $use->id ?>" data-request-confirm="Odstranit toto video?">Odstranit</button><small><?= e($use->asset->original_name) ?></small><?php endif ?>
                        </div>
                    <?php endforeach ?>
                </div>

                <?php $previewVideo = $webm ?: $mp4; ?>
                <?php if ($previewVideo): ?>
                    <?php foreach (['desktop' => [$desktopWidth, $desktopHeight], 'mobile' => [$mobileWidth, $mobileHeight]] as $viewport => [$width, $height]): ?>
                        <?php $positionUse = $mp4 ?: $previewVideo; $position = data_get($positionUse->crop, $viewport, ['x' => 50, 'y' => 50]); ?>
                        <div class="hucr-slider-video-position" data-video-position>
                            <div class="hucr-slider-video-position__preview" style="aspect-ratio: <?= $width ?>/<?= $height ?>"><video src="<?= e($previewVideo->public_asset_url) ?>" muted loop playsinline controls style="object-position: <?= (float) $position['x'] ?>% <?= (float) $position['y'] ?>%"></video></div>
                            <div class="hucr-slider-video-position__controls">
                                <strong><?= $viewport === 'desktop' ? 'Pozice pro počítač' : 'Pozice pro mobil' ?></strong>
                                <label>Vodorovně <input type="range" min="0" max="100" value="<?= (float) $position['x'] ?>" data-position-x></label>
                                <label>Svisle <input type="range" min="0" max="100" value="<?= (float) $position['y'] ?>" data-position-y></label>
                                <button type="button" class="btn btn-primary btn-sm" data-position-handler="<?= e($handler('onUpdateVideoPosition')) ?>" data-context-id="<?= $context->id ?>" data-media-use-id="<?= $positionUse->id ?>" data-viewport="<?= $viewport ?>" data-hucr-video-position-save>Uložit pozici</button>
                            </div>
                        </div>
                    <?php endforeach ?>
                <?php endif ?>

                <h5>Volitelný poster</h5>
                <div class="hucr-slider-media__uploads">
                    <?php foreach (['desktop' => ['Poster pro počítač', $posterDesktop], 'mobile' => ['Poster pro mobil', $posterMobile]] as $viewport => [$label, $use]): ?>
                        <?php $uploadField = 'slider_media_file_poster_'.$context->id.'_'.$viewport; ?>
                        <div class="hucr-slider-media__upload" id="slider-poster-<?= $context->id ?>-<?= $viewport ?>">
                            <label><?= e($label) ?></label><input type="file" class="form-control" name="<?= e($uploadField) ?>" accept="image/jpeg,image/png,image/webp,image/avif">
                            <button type="button" class="btn btn-default" data-request="<?= e($handler('onUploadPoster')) ?>" data-request-files data-request-form="#tailor-form" data-request-data="context_id: <?= $context->id ?>, viewport: '<?= $viewport ?>', upload_field: '<?= e($uploadField) ?>'">Nahrát poster</button>
                            <?php if (!$use): ?><button type="button" class="btn btn-default" data-request="<?= e($handler('onReuseLocaleMedia')) ?>" data-request-data="context_id: <?= $context->id ?>, media_kind: 'poster_<?= $viewport ?>'">Použít z jiné jazykové verze</button><?php endif ?>
                            <?php if ($use): ?><img src="<?= e($mediaService->backendPreviewUrl($use)) ?>" alt=""><button type="button" class="btn btn-default btn-sm oc-icon-crop" data-control="popup" data-handler="<?= e($handler('onLoadMediaCropEditor')) ?>" data-request-data="context_id: <?= $context->id ?>, media_use_id: <?= $use->id ?>" data-size="huge">Upravit ořez</button><button type="button" class="btn btn-danger btn-sm" data-request="<?= e($handler('onDeleteMedia')) ?>" data-request-data="context_id: <?= $context->id ?>, media_use_id: <?= $use->id ?>">Odstranit</button><?php endif ?>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php else: ?>
                <div class="hucr-slider-media__uploads">
                    <?php foreach (['desktop' => ['Obrázek pro počítač', $desktop], 'mobile' => ['Obrázek pro mobil', $mobile]] as $viewport => [$label, $use]): ?>
                        <?php $uploadField = 'slider_media_file_image_'.$context->id.'_'.$viewport; ?>
                        <div class="hucr-slider-media__upload" id="slider-image-<?= $context->id ?>-<?= $viewport ?>">
                            <label><?= e($label) ?></label><input type="file" class="form-control" name="<?= e($uploadField) ?>" accept="image/jpeg,image/png,image/webp,image/avif">
                            <button type="button" class="btn btn-primary" data-request="<?= e($handler('onUploadImage')) ?>" data-request-files data-request-form="#tailor-form" data-request-data="context_id: <?= $context->id ?>, viewport: '<?= $viewport ?>', upload_field: '<?= e($uploadField) ?>'">Nahrát obrázek</button>
                            <?php if (!$use): ?><button type="button" class="btn btn-default" data-request="<?= e($handler('onReuseLocaleMedia')) ?>" data-request-data="context_id: <?= $context->id ?>, media_kind: '<?= $viewport ?>'">Použít z jiné jazykové verze</button><?php endif ?>
                            <?php if ($viewport === 'mobile' && !$use && $desktop): ?><button type="button" class="btn btn-default" data-request="<?= e($handler('onReuseDesktopAsMobile')) ?>" data-request-data="context_id: <?= $context->id ?>">Použít desktopový originál</button><?php endif ?>
                            <?php if ($use): ?>
                                <img src="<?= e($mediaService->backendPreviewUrl($use)) ?>" alt="">
                                <label>Alternativní text <input class="form-control" type="text" name="media_alt_text" value="<?= e($use->alt_text ?: '') ?>" placeholder="Prázdný text = dekorativní obrázek"></label>
                                <label class="hucr-slider-media__decorative"><input type="checkbox" name="media_decorative" value="1" <?= $use->is_decorative ? 'checked' : '' ?>> <span>Dekorativní obrázek</span></label>
                                <div><button type="button" class="btn btn-default btn-sm oc-icon-crop" data-control="popup" data-handler="<?= e($handler('onLoadMediaCropEditor')) ?>" data-request-data="context_id: <?= $context->id ?>, media_use_id: <?= $use->id ?>" data-size="huge">Upravit ořez</button>
                                <button type="button" class="btn btn-primary btn-sm" data-hucr-slider-update-metadata data-handler="<?= e($handler('onUpdateImageMetadata')) ?>" data-context-id="<?= $context->id ?>" data-media-use-id="<?= $use->id ?>">Uložit popis</button>
                                <button type="button" class="btn btn-danger btn-sm" data-request="<?= e($handler('onDeleteMedia')) ?>" data-request-data="context_id: <?= $context->id ?>, media_use_id: <?= $use->id ?>" data-request-confirm="Odstranit obrázek?">Odstranit</button></div>
                            <?php endif ?>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
        </section>
    <?php endforeach ?>
<?php endif ?>
</div>

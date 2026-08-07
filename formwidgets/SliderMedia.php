<?php namespace HumlnetCreative\Pages\FormWidgets;

use Backend\Classes\FormWidgetBase;
use Backend\Facades\BackendAuth;
use Flash;
use HumlnetCreative\Pages\Models\MediaUse;
use HumlnetCreative\Pages\Models\SliderMediaContext;
use HumlnetCreative\Pages\Services\MediaService;
use Illuminate\Support\Facades\Storage;

class SliderMedia extends FormWidgetBase
{
    protected $defaultAlias = 'slidermedia';

    public function render()
    {
        $this->prepareVars();
        return $this->makePartial('slidermedia');
    }

    public function prepareVars(): void
    {
        $contexts = collect();
        if ($this->model->exists && $this->model->blueprint_uuid === 'lzaplata_slider_slides') {
            foreach ($this->model->sliders ?: [] as $slider) {
                $contexts->push(SliderMediaContext::forEntries($slider, $this->model));
            }
        }

        $this->vars['contexts'] = $contexts->load('media.asset');
        $this->vars['model'] = $this->model;
        $this->vars['widgetId'] = $this->getId();
        $this->vars['handler'] = fn(string $name) => $this->getEventHandler($name);
        $this->vars['mediaService'] = new MediaService();
    }

    public function loadAssets()
    {
        $this->addJs('/plugins/humlnetcreative/pages/assets/js/backend-media-crop.js');
        $this->addJs('/plugins/humlnetcreative/pages/assets/js/slider-media.js');
        $this->addCss('/plugins/humlnetcreative/pages/assets/css/backend-media-crop.css');
        $this->addCss('/plugins/humlnetcreative/pages/assets/css/slider-media.css');
    }

    public function onUploadImage()
    {
        $this->assertPermission('humlnetcreative.pages.slider.media.image');
        $context = $this->contextFromRequest();
        $viewport = request()->input('viewport') === 'mobile' ? 'mobile' : 'desktop';
        $upload = request()->file('slider_media_file');
        if (!$upload) {
            throw new \ValidationException(['slider_media_file' => 'Vyberte obrázek.']);
        }

        $service = new MediaService();
        $slot = $context->imageSlot($viewport);
        $asset = $service->storeMaster($upload, $context->site_id);
        try {
            $use = $service->createUse($asset, $context::class, $context->id, $slot, [], null, true, $context->site_id);
            $this->replaceSlotPrefix($context, 'slider_'.$viewport.'_', $use);
        }
        catch (\Throwable $exception) {
            Storage::disk($asset->disk)->delete($asset->path);
            $asset->delete();
            throw $exception;
        }

        Flash::success('Obrázek a optimalizované varianty byly uloženy.');
        return $this->refreshWidget();
    }

    public function onReuseDesktopAsMobile()
    {
        $this->assertPermission('humlnetcreative.pages.slider.media.image');
        $context = $this->contextFromRequest();
        $desktop = $context->media()->with('asset')->get()->first(fn($use) => str_starts_with($use->slot, 'slider_desktop_'));
        if (!$desktop) {
            throw new \ApplicationException('Nejprve nahrajte desktopový obrázek.');
        }

        $service = new MediaService();
        $use = $service->createUse($desktop->asset, $context::class, $context->id, $context->imageSlot('mobile'), [], $desktop->alt_text, $desktop->is_decorative, $context->site_id);
        $this->replaceSlotPrefix($context, 'slider_mobile_', $use);
        Flash::success('Pro mobil byl použit stejný originál se samostatným ořezem.');
        return $this->refreshWidget();
    }

    public function onUploadVideo()
    {
        $this->assertPermission('humlnetcreative.pages.slider.media.video');
        $context = $this->contextFromRequest();
        $format = request()->input('format') === 'webm' ? 'webm' : 'mp4';
        $upload = request()->file('slider_media_file');
        if (!$upload) {
            throw new \ValidationException(['slider_media_file' => 'Vyberte video.']);
        }

        $service = new MediaService();
        $asset = $service->storeVideoMaster($upload, $context->site_id);
        try {
            $use = $service->createVideoUse($asset, $context, 'slider_video_'.$format);
            $otherVideo = $context->media()->where('slot', $format === 'mp4' ? 'slider_video_webm' : 'slider_video_mp4')->first();
            if ($otherVideo?->crop) {
                $use->crop = $otherVideo->crop;
                $use->save();
            }
            $this->replaceExactSlot($context, $use->slot, $use);
        }
        catch (\Throwable $exception) {
            Storage::disk($asset->disk)->delete($asset->path);
            $asset->delete();
            throw $exception;
        }

        Flash::success(strtoupper($format).' video bylo uloženo.');
        return $this->refreshWidget();
    }

    public function onUploadPoster()
    {
        $this->assertPermission('humlnetcreative.pages.slider.media.image');
        $context = $this->contextFromRequest();
        $viewport = request()->input('viewport') === 'mobile' ? 'mobile' : 'desktop';
        $upload = request()->file('slider_media_file');
        if (!$upload) {
            throw new \ValidationException(['slider_media_file' => 'Vyberte obrázek posteru.']);
        }
        [$width, $height] = $context->dimensions($viewport);
        $slot = "slider_poster_{$viewport}_{$width}x{$height}";
        $service = new MediaService();
        $asset = $service->storeMaster($upload, $context->site_id);
        try {
            $use = $service->createUse($asset, $context::class, $context->id, $slot, [], null, true, $context->site_id);
            $this->replaceSlotPrefix($context, 'slider_poster_'.$viewport.'_', $use);
        }
        catch (\Throwable $exception) {
            Storage::disk($asset->disk)->delete($asset->path);
            $asset->delete();
            throw $exception;
        }
        Flash::success('Poster byl uložen.');
        return $this->refreshWidget();
    }

    public function onUpdateVideoPosition()
    {
        $this->assertPermission('humlnetcreative.pages.slider.media.crop');
        $use = $this->ownedUseFromRequest();
        if (!str_starts_with($use->slot, 'slider_video_')) {
            throw new \ApplicationException('Pozici lze nastavit pouze videu.');
        }
        $viewport = request()->input('viewport') === 'mobile' ? 'mobile' : 'desktop';
        $crop = $use->crop ?: [];
        $crop[$viewport] = [
            'x' => max(0, min(100, (float) request()->input('position_x', 50))),
            'y' => max(0, min(100, (float) request()->input('position_y', 50))),
        ];
        $use->crop = $crop;
        $use->save();
        Flash::success('Pozice videa byla uložena.');
        return $this->refreshWidget();
    }

    public function onDeleteMedia()
    {
        $this->assertPermission('humlnetcreative.pages.slider.media.delete');
        $this->ownedUseFromRequest()->delete();
        Flash::success('Médium bylo odstraněno.');
        return $this->refreshWidget();
    }

    public function onUpdateImageMetadata()
    {
        $this->assertPermission('humlnetcreative.pages.slider.media.image');
        $use = $this->ownedUseFromRequest();
        $alt = trim((string) request()->input('media_alt_text'));
        $use->is_decorative = request()->boolean('media_decorative') || $alt === '';
        $use->alt_text = $use->is_decorative ? null : $alt;
        $use->save();
        Flash::success('Alternativní text byl uložen.');
        return $this->refreshWidget();
    }

    public function onReuseLocaleMedia()
    {
        $context = $this->contextFromRequest();
        $kind = (string) request()->input('media_kind');
        $slide = $context->slide();
        $slider = $context->slider();
        if (!$slide || !$slider) {
            throw new \ApplicationException('Zdrojovou jazykovou variantu se nepodařilo určit.');
        }

        $slideRoot = $slide->site_root_id ?: $slide->id;
        $sliderRoot = $slider->site_root_id ?: $slider->id;
        $slideIds = $slide->newQuery()->where('id', $slideRoot)->orWhere('site_root_id', $slideRoot)->pluck('id');
        $sliderIds = $slider->newQuery()->where('id', $sliderRoot)->orWhere('site_root_id', $sliderRoot)->pluck('id');
        $otherContexts = SliderMediaContext::with('media.asset')
            ->where('id', '<>', $context->id)->whereIn('slide_id', $slideIds)->whereIn('slider_id', $sliderIds)->get();

        $prefixes = [
            'desktop' => 'slider_desktop_', 'mobile' => 'slider_mobile_',
            'poster_desktop' => 'slider_poster_desktop_', 'poster_mobile' => 'slider_poster_mobile_',
            'video_mp4' => 'slider_video_mp4', 'video_webm' => 'slider_video_webm',
        ];
        if (!isset($prefixes[$kind])) {
            throw new \ApplicationException('Neplatný typ média.');
        }
        $source = $otherContexts->flatMap->media->first(fn($use) => str_starts_with($use->slot, $prefixes[$kind]));
        if (!$source) {
            throw new \ApplicationException('V jiné jazykové verzi zatím takové médium není.');
        }

        $service = new MediaService();
        if (str_starts_with($kind, 'video_')) {
            $this->assertPermission('humlnetcreative.pages.slider.media.video');
            $newUse = $service->createVideoUse($source->asset, $context, $prefixes[$kind]);
            $this->replaceExactSlot($context, $prefixes[$kind], $newUse);
        }
        else {
            $this->assertPermission('humlnetcreative.pages.slider.media.image');
            $viewport = str_contains($kind, 'mobile') ? 'mobile' : 'desktop';
            [$width, $height] = $context->dimensions($viewport);
            $slot = str_starts_with($kind, 'poster_') ? "slider_poster_{$viewport}_{$width}x{$height}" : $context->imageSlot($viewport);
            $newUse = $service->createUse($source->asset, $context::class, $context->id, $slot, [], $source->alt_text, $source->is_decorative, $context->site_id);
            $this->replaceSlotPrefix($context, $prefixes[$kind], $newUse);
        }

        Flash::success('Originál byl převzat z jiné jazykové verze; ořez zůstává samostatný.');
        return $this->refreshWidget();
    }

    public function onLoadMediaCropEditor()
    {
        $this->assertPermission('humlnetcreative.pages.slider.media.crop');
        $use = $this->ownedUseFromRequest();
        if (str_starts_with($use->asset->mime_type, 'video/')) {
            throw new \ApplicationException('Video používá editor pozice, nikoli obrazový ořez.');
        }
        $service = new MediaService();
        $this->vars['mediaUse'] = $use;
        $this->vars['slotDefinition'] = $service->slotDefinition($use->slot);
        $this->vars['previewUrl'] = \Backend::url('humlnetcreative/pages/builderpages/previewmedia/'.$use->id);
        $this->vars['applyHandler'] = $this->getEventHandler('onApplyMediaCrop');
        return $this->makePartial('media_crop_editor');
    }

    public function onApplyMediaCrop()
    {
        $this->assertPermission('humlnetcreative.pages.slider.media.crop');
        $use = $this->ownedUseFromRequest();
        $service = new MediaService();
        $use->crop = $service->validateCropSelection($use->asset, $use->slot, [
            'x' => request()->input('crop_x'), 'y' => request()->input('crop_y'),
            'width' => request()->input('crop_width'), 'height' => request()->input('crop_height'),
        ]);
        $use->variants = $service->regenerateVariants($use);
        $use->save();
        Flash::success('Ořez a optimalizované varianty byly uloženy.');
        return $this->refreshWidget();
    }

    protected function contextFromRequest(): SliderMediaContext
    {
        $context = SliderMediaContext::findOrFail((int) request()->input('context_id'));
        if ((int) $context->slide_id !== (int) $this->model->getKey()) {
            throw new \ApplicationException('Mediální kontext nepatří k této položce.');
        }
        return $context;
    }

    protected function ownedUseFromRequest(): MediaUse
    {
        $context = $this->contextFromRequest();
        return $context->media()->with('asset')->findOrFail((int) request()->input('media_use_id'));
    }

    protected function replaceSlotPrefix(SliderMediaContext $context, string $prefix, MediaUse $newUse): void
    {
        $context->media()->where('id', '<>', $newUse->id)->get()->filter(fn($use) => str_starts_with($use->slot, $prefix))->each(fn($use) => $use->delete());
    }

    protected function replaceExactSlot(SliderMediaContext $context, string $slot, MediaUse $newUse): void
    {
        $context->media()->where('slot', $slot)->where('id', '<>', $newUse->id)->get()->each(fn($use) => $use->delete());
    }

    protected function refreshWidget(): array
    {
        return ['#'.$this->getId() => $this->render()];
    }

    protected function assertPermission(string $permission): void
    {
        if (!BackendAuth::userHasPermission($permission)) {
            throw new \ApplicationException('K této akci nemáte oprávnění.');
        }
    }
}

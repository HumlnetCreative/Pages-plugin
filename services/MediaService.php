<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Models\MediaAsset;
use HumlnetCreative\Pages\Models\MediaUse;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SectionItem;
use HumlnetCreative\Pages\Models\SliderMediaContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Stores readable private masters and produces immutable public responsive derivatives. */
class MediaService
{
    public function storeMaster(UploadedFile $upload, ?int $siteId = null): MediaAsset
    {
        if (!$upload->isValid()) {
            throw new \ValidationException(['media_file' => 'Nahrání obrázku se nezdařilo. Zkuste soubor nahrát znovu.']);
        }

        $mime = (string) $upload->getMimeType();
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
        ];
        if (!isset($extensions[$mime])) {
            throw new \ValidationException(['media_file' => 'Podporované jsou pouze obrázky JPG, PNG, WebP a AVIF.']);
        }

        $imageInfo = getimagesize($upload->getRealPath());
        if (!$imageInfo || empty($imageInfo[0]) || empty($imageInfo[1])) {
            throw new \ValidationException(['media_file' => 'Soubor není platný obrázek.']);
        }

        $base = Str::slug(pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'image';
        $extension = $extensions[$mime];
        $path = 'pages-masters/'.now()->format('Y/m').'/'.$base.'-'.Str::lower(Str::random(8)).'.'.$extension;
        Storage::disk('local')->put($path, file_get_contents($upload->getRealPath()));
        [$width, $height] = $imageInfo;

        return MediaAsset::create([
            'uuid' => (string) Str::uuid(), 'site_id' => $siteId, 'disk' => 'local', 'path' => $path,
            'original_name' => $upload->getClientOriginalName(), 'mime_type' => $mime,
            'size' => $upload->getSize(), 'width' => $width, 'height' => $height,
        ]);
    }

    /** Stores a browser-ready video master without transcoding. */
    public function storeVideoMaster(UploadedFile $upload, ?int $siteId = null): MediaAsset
    {
        if (!$upload->isValid()) {
            throw new \ValidationException(['media_file' => 'Nahrání videa se nezdařilo.']);
        }

        $mime = (string) $upload->getMimeType();
        $extensions = ['video/mp4' => 'mp4', 'video/webm' => 'webm'];
        $videoConfig = $this->videoConfig();
        if (!isset($extensions[$mime]) || !in_array($mime, $videoConfig['video_formats'] ?? array_keys($extensions), true)) {
            throw new \ValidationException(['media_file' => 'Podporována jsou pouze videa MP4 a WebM.']);
        }
        $maxBytes = max(1, (int) ($videoConfig['video_max_mb'] ?? 100)) * 1024 * 1024;
        if ((int) $upload->getSize() > $maxBytes) {
            throw new \ValidationException(['media_file' => 'Video překračuje limit '.($videoConfig['video_max_mb'] ?? 100).' MB.']);
        }

        $base = Str::slug(pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'video';
        $path = 'pages-video/'.now()->format('Y/m').'/'.$base.'-'.Str::lower(Str::random(8)).'.'.$extensions[$mime];
        Storage::disk('media')->put($path, file_get_contents($upload->getRealPath()));
        [$width, $height, $duration] = $this->probeVideo(Storage::disk('media')->path($path));

        return MediaAsset::create([
            'uuid' => (string) Str::uuid(), 'site_id' => $siteId, 'disk' => 'media', 'path' => $path,
            'original_name' => $upload->getClientOriginalName(), 'mime_type' => $mime,
            'size' => $upload->getSize(), 'width' => $width, 'height' => $height, 'duration_ms' => $duration,
        ]);
    }

    public function createUse(MediaAsset $asset, string $ownerType, int $ownerId, string $slot, array $crop, ?string $altText, bool $decorative, ?int $siteId = null): MediaUse
    {
        $this->assertMinimumDimensions($asset, $slot);
        $crop = $crop ?: $this->defaultCrop($asset, $slot);
        $use = MediaUse::create([
            'uuid' => (string) Str::uuid(), 'media_asset_id' => $asset->id, 'owner_type' => $ownerType, 'owner_id' => $ownerId,
            'site_id' => $siteId, 'slot' => $slot, 'crop' => $crop, 'alt_text' => $altText, 'is_decorative' => $decorative,
        ]);
        try {
            $use->variants = $this->generateVariants($use);
            $use->save();
        }
        catch (\Throwable $exception) {
            $use->delete();
            throw $exception;
        }
        return $use;
    }

    public function createVideoUse(MediaAsset $asset, SliderMediaContext $owner, string $slot): MediaUse
    {
        if (!in_array($slot, ['slider_video_mp4', 'slider_video_webm'], true)) {
            throw new \ValidationException(['media_slot' => 'Neplatné umístění videa.']);
        }
        $expectedMime = $slot === 'slider_video_mp4' ? 'video/mp4' : 'video/webm';
        if ($asset->mime_type !== $expectedMime) {
            throw new \ValidationException(['media_file' => 'Formát videa neodpovídá vybranému umístění.']);
        }

        return MediaUse::create([
            'uuid' => (string) Str::uuid(), 'media_asset_id' => $asset->id,
            'owner_type' => $owner::class, 'owner_id' => $owner->id, 'site_id' => $owner->site_id,
            'slot' => $slot, 'crop' => ['desktop' => ['x' => 50, 'y' => 50], 'mobile' => ['x' => 50, 'y' => 50]],
            'alt_text' => null, 'is_decorative' => true, 'variants' => [],
        ]);
    }

    /**
     * Produces a fixed-ratio crop from a 0–100 focal point without ever exceeding
     * the master image. A use stores physical coordinates, so its crop is stable.
     */
    public function cropFromFocus(MediaAsset $asset, string $slot, float $focusX = 50, float $focusY = 50): array
    {
        $ratio = $this->ratio($this->slotDefinition($slot)['ratio']);
        $focusX = max(0, min(100, $focusX)) / 100;
        $focusY = max(0, min(100, $focusY)) / 100;
        $sourceRatio = $asset->width / max(1, $asset->height);

        if ($sourceRatio > $ratio) {
            $height = $asset->height;
            $width = (int) round($height * $ratio);
            return ['x' => (int) round(($asset->width - $width) * $focusX), 'y' => 0, 'width' => $width, 'height' => $height];
        }

        $width = $asset->width;
        $height = (int) round($width / $ratio);
        return ['x' => 0, 'y' => (int) round(($asset->height - $height) * $focusY), 'width' => $width, 'height' => $height];
    }

    /** Validates a fixed-ratio crop expressed in original master pixels. */
    public function validateCropSelection(MediaAsset $asset, string $slot, array $selection): array
    {
        $crop = [];
        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (!isset($selection[$key]) || !is_numeric($selection[$key])) {
                throw new \ValidationException(['crop' => 'Ořez neobsahuje platné souřadnice.']);
            }
            $crop[$key] = (int) round((float) $selection[$key]);
        }

        if ($crop['x'] < 0 || $crop['y'] < 0 || $crop['width'] < 1 || $crop['height'] < 1
            || $crop['x'] + $crop['width'] > $asset->width
            || $crop['y'] + $crop['height'] > $asset->height) {
            throw new \ValidationException(['crop' => 'Ořez musí ležet uvnitř původního obrázku.']);
        }

        $definition = $this->slotDefinition($slot);
        [$minimumWidth, $minimumHeight] = $definition['minimum'];
        if ($crop['width'] < $minimumWidth || $crop['height'] < $minimumHeight) {
            throw new \ValidationException([
                'crop' => "Výřez musí mít alespoň {$minimumWidth}×{$minimumHeight} px.",
            ]);
        }

        $ratio = $this->ratio($definition['ratio']);
        if (abs($crop['width'] - ($crop['height'] * $ratio)) > 2) {
            throw new \ValidationException(['crop' => "Ořez musí zachovat poměr {$definition['ratio']}."]);
        }

        return $crop;
    }

    /** Replaces all public derivatives so obsolete sizes never remain on disk. */
    public function regenerateVariants(MediaUse $use): array
    {
        $oldPaths = [];
        foreach (($use->variants ?: []) as $variants) {
            $oldPaths = array_merge($oldPaths, array_values($variants ?: []));
        }

        $newVariants = $this->generateVariants($use);
        $newPaths = [];
        foreach ($newVariants as $variants) {
            $newPaths = array_merge($newPaths, array_values($variants ?: []));
        }

        Storage::disk('media')->delete(array_values(array_diff($oldPaths, $newPaths)));
        return $newVariants;
    }

    public function slotDefinition(string $slot): array
    {
        $slots = $this->slots();
        if (!isset($slots[$slot]) && ($dynamic = $this->dynamicSliderSlot($slot))) {
            return $dynamic;
        }
        if (!isset($slots[$slot])) {
            throw new RuntimeException("Neznámý mediální slot {$slot}.");
        }
        return $slots[$slot];
    }

    public function slotOptionsForOwner(Section|SectionItem|SliderMediaContext $owner): array
    {
        if ($owner instanceof SliderMediaContext) {
            return [
                $owner->imageSlot('desktop') => 'Obrázek pro počítač',
                $owner->imageSlot('mobile') => 'Obrázek pro mobil',
                'slider_poster_desktop_'.$this->dimensionsSuffix($owner, 'desktop') => 'Poster videa pro počítač',
                'slider_poster_mobile_'.$this->dimensionsSuffix($owner, 'mobile') => 'Poster videa pro mobil',
            ];
        }
        $section = $owner instanceof SectionItem ? $owner->section : $owner;
        if (!$section || !SectionRegistry::instance()->has($section->type)) {
            return [];
        }
        $slotNames = $owner instanceof SectionItem
            ? SectionRegistry::instance()->itemMediaSlots($section->type)
            : SectionRegistry::instance()->sectionMediaSlots($section->type);
        $slots = $this->slots();
        $result = [];
        foreach ($slotNames as $slotName) {
            if (isset($slots[$slotName])) {
                $result[$slotName] = $slots[$slotName]['label'] ?? $slotName;
            }
        }
        return $result;
    }

    public function assertSlotAllowed(Section|SectionItem|SliderMediaContext $owner, string $slot): void
    {
        if (!array_key_exists($slot, $this->slotOptionsForOwner($owner))) {
            throw new \ValidationException(['media_slot' => 'Tento mediální slot není pro daný typ obsahu povolený.']);
        }
    }

    public function slotLabel(string $slot): string
    {
        return $this->slotDefinition($slot)['label'] ?? $slot;
    }

    /** Returns a cache-safe URL for the thumbnail shown in backend media editors. */
    public function backendPreviewUrl(MediaUse $use): string
    {
        $publicVariants = $use->public_variants;
        $previewVariants = $publicVariants['webp'] ?? ($publicVariants['avif'] ?? []);

        if ($previewVariants) {
            $url = array_values($previewVariants)[count($previewVariants) - 1];
        }
        else {
            $backendUrl = \Backend::url('humlnetcreative/pages/builderpages/previewmedia/'.$use->id);
            $url = parse_url($backendUrl, PHP_URL_PATH) ?: $backendUrl;
            $basePath = rtrim((string) request()->getBasePath(), '/');
            if ($basePath !== '' && $url !== $basePath && !str_starts_with($url, $basePath.'/')) {
                $url = $basePath.'/'.ltrim($url, '/');
            }
        }

        $revision = substr(hash('sha256', json_encode([
            'crop' => $use->crop,
            'variants' => $use->variants,
            'updated_at' => $use->updated_at?->format('Y-m-d H:i:s.u'),
        ])), 0, 12);

        return $url.(str_contains($url, '?') ? '&' : '?').'v='.$revision;
    }

    protected function assertMinimumDimensions(MediaAsset $asset, string $slot): void
    {
        [$minimumWidth, $minimumHeight] = $this->slotDefinition($slot)['minimum'];
        if ($asset->width < $minimumWidth || $asset->height < $minimumHeight) {
            throw new \ValidationException([
                'media_file' => "Obrázek pro slot {$slot} musí mít alespoň {$minimumWidth}×{$minimumHeight} px.",
            ]);
        }
    }

    public function generateVariants(MediaUse $use): array
    {
        $slot = $this->slotDefinition($use->slot);
        $asset = $use->asset;
        $sourcePath = Storage::disk($asset->disk)->path($asset->path);
        $source = $this->openImage($sourcePath, $asset->mime_type);
        $result = [];
        $crop = $use->crop ?: [];
        $srcX = (int) ($crop['x'] ?? 0); $srcY = (int) ($crop['y'] ?? 0);
        $srcW = (int) ($crop['width'] ?? imagesx($source)); $srcH = (int) ($crop['height'] ?? imagesy($source));
        $revision = substr(hash('sha256', json_encode([
            'crop' => ['x' => $srcX, 'y' => $srcY, 'width' => $srcW, 'height' => $srcH],
            'ratio' => $slot['ratio'],
            'recipe' => 1,
        ])), 0, 10);
        $widths = array_values(array_filter($slot['widths'], fn($width) => $width <= $srcW));
        if (!$widths) {
            $widths = [$srcW];
        }
        foreach ($widths as $width) {
            $height = (int) round($width / $this->ratio($slot['ratio']));
            $image = imagecreatetruecolor($width, $height);
            imagealphablending($image, false); imagesavealpha($image, true);
            $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
            imagefill($image, 0, 0, $transparent);
            imagecopyresampled($image, $source, 0, 0, $srcX, $srcY, $width, $height, $srcW, $srcH);
            $basename = Str::slug(pathinfo($asset->original_name, PATHINFO_FILENAME))
                .'-'.$use->slot.'-'.$width.'w-'.$revision;
            foreach (['avif', 'webp'] as $format) {
                $path = 'pages-variants/'.$asset->uuid.'/'.$use->uuid.'/'.$basename.'.'.$format;
                $absolute = Storage::disk('media')->path($path);
                if (!is_dir(dirname($absolute))) { mkdir(dirname($absolute), 0755, true); }
                $format === 'avif' ? imageavif($image, $absolute, 55) : imagewebp($image, $absolute, 82);
                // Store only the portable path. MediaUse resolves it against
                // the current request base path when it is displayed.
                $result[$format][$width] = $path;
            }
            imagedestroy($image);
        }
        imagedestroy($source);
        return $result;
    }

    protected function slots(): array
    {
        $theme = \Cms\Classes\Theme::getActiveTheme();
        $path = $theme ? $theme->getPath().'/config/media-slots.php' : null;
        $slots = $path && is_file($path) ? require $path : [];
        return $slots;
    }

    protected function videoConfig(): array
    {
        $theme = \Cms\Classes\Theme::getActiveTheme();
        $path = $theme ? $theme->getPath().'/config/page-builder-media.php' : null;
        return $path && is_file($path) ? require $path : [];
    }

    protected function dynamicSliderSlot(string $slot): ?array
    {
        if (!preg_match('/^slider_(?:desktop|mobile|poster_desktop|poster_mobile)_(\d+)x(\d+)$/', $slot, $matches)) {
            return null;
        }

        $width = (int) $matches[1];
        $height = (int) $matches[2];
        return [
            'label' => str_contains($slot, 'mobile') ? 'Mobilní výřez' : 'Desktopový výřez',
            'ratio' => $width.':'.$height,
            'minimum' => [$width, $height],
            'widths' => array_values(array_unique(array_filter([480, 768, 1280, $width], fn($candidate) => $candidate <= $width))) ?: [$width],
        ];
    }

    protected function dimensionsSuffix(SliderMediaContext $context, string $viewport): string
    {
        return implode('x', $context->dimensions($viewport));
    }

    protected function probeVideo(string $path): array
    {
        $command = sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=width,height:format=duration -of json %s 2>/dev/null',
            escapeshellarg($path)
        );
        $json = shell_exec($command);
        $data = $json ? json_decode($json, true) : [];
        $stream = $data['streams'][0] ?? [];

        return [
            isset($stream['width']) ? (int) $stream['width'] : null,
            isset($stream['height']) ? (int) $stream['height'] : null,
            isset($data['format']['duration']) ? (int) round(((float) $data['format']['duration']) * 1000) : null,
        ];
    }

    protected function ratio(string $ratio): float
    {
        [$width, $height] = array_map('floatval', explode(':', $ratio));
        return $width / $height;
    }

    /** Centre-crop source material into a slot until the visual crop UI supplies coordinates. */
    protected function defaultCrop(MediaAsset $asset, string $slot): array
    {
        return $this->cropFromFocus($asset, $slot);
    }

    protected function openImage(string $path, string $mime)
    {
        return match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path), 'image/png' => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path), 'image/avif' => imagecreatefromavif($path),
            default => throw new RuntimeException('Nepodporovaný formát obrázku.'),
        };
    }
}

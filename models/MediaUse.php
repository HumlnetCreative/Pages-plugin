<?php namespace HumlnetCreative\Pages\Models;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use October\Rain\Database\Model;
use HumlnetCreative\Pages\Services\CompliancePolicy;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SectionItem;
use HumlnetCreative\Pages\Services\DraftStateService;
use HumlnetCreative\Pages\Services\MediaReferenceService;
use HumlnetCreative\Pages\Services\PageMutationGuard;

class MediaUse extends Model
{
    protected ?int $draftPageId = null;
    public $table = 'humlnetcreative_pages_media_uses';
    protected $guarded = [];
    protected $jsonable = ['crop', 'variants'];
    public $belongsTo = ['asset' => [MediaAsset::class, 'key' => 'media_asset_id']];
    public $morphTo = ['owner' => []];

    public function beforeSave()
    {
        $this->draftPageId = $this->resolveDraftPageId();
        app(PageMutationGuard::class)->assertWritable($this->resolveBuilderPage());
        $this->uuid ??= (string) Str::uuid();
        if ($this->is_decorative) {
            $this->alt_text = null;
        }
        elseif (!$this->alt_text && CompliancePolicy::shouldBlock()) {
            throw new \ValidationException(['alt_text' => 'Vyplňte alternativní text nebo označte obrázek jako dekorativní.']);
        }
    }

    public function afterSave()
    {
        if ($this->draftPageId) {
            app(DraftStateService::class)->touch($this->draftPageId, 'media.changed', $this);
        }
    }

    public function beforeDelete()
    {
        $this->draftPageId = $this->resolveDraftPageId();
        app(PageMutationGuard::class)->assertWritable($this->resolveBuilderPage());
    }

    /**
     * Resolves stored variant paths against the current web base path.
     * This keeps media portable between a domain root and installations such
     * as /pages-theme without persisting an environment-specific host name.
     */
    public function getPublicVariantsAttribute(): array
    {
        $basePath = rtrim((string) request()->getBasePath(), '/');
        $storageMarkers = ['/storage/app/public/', '/storage/app/media/'];
        $publicPrefix = '/storage/app/media/';
        $variants = $this->variants ?: [];

        foreach ($variants as &$formatVariants) {
            foreach ($formatVariants as &$storedPath) {
                $path = parse_url((string) $storedPath, PHP_URL_PATH) ?: (string) $storedPath;
                $relativePath = ltrim($path, '/');
                foreach ($storageMarkers as $marker) {
                    if (($markerPosition = strpos($path, $marker)) !== false) {
                        $relativePath = ltrim(substr($path, $markerPosition + strlen($marker)), '/');
                        break;
                    }
                }
                $storedPath = $basePath.$publicPrefix.$relativePath;
            }
        }

        return $variants;
    }

    public function getPublicAssetUrlAttribute(): ?string
    {
        if (!$this->asset || $this->asset->disk !== 'media') {
            return null;
        }
        $basePath = rtrim((string) request()->getBasePath(), '/');
        return $basePath.'/storage/app/media/'.ltrim($this->asset->path, '/');
    }

    /** Removes generated public variants and an unshared private master. */
    public function afterDelete()
    {
        app(MediaReferenceService::class)->removeUseFilesWhenOrphaned($this);
        if ($this->draftPageId) {
            app(DraftStateService::class)->touch($this->draftPageId, 'media.deleted', $this);
        }
    }

    private function resolveBuilderPage(): ?BuilderPage
    {
        $owner = $this->owner;
        if ($owner instanceof Section) {
            return $owner->page;
        }
        if ($owner instanceof SectionItem) {
            return $owner->section?->page;
        }

        return null;
    }

    private function resolveDraftPageId(): ?int
    {
        return $this->resolveBuilderPage()?->id;
    }
}

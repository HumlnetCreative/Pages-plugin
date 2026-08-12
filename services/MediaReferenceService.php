<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Models\MediaAsset;
use HumlnetCreative\Pages\Models\MediaUse;
use HumlnetCreative\Pages\Models\PageRevisionMedia;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class MediaReferenceService
{
    public function useIsReferenced(string $useUuid): bool
    {
        return Schema::hasTable('humlnetcreative_pages_revision_media')
            && PageRevisionMedia::where('media_use_uuid', $useUuid)->exists();
    }

    public function assetIsReferenced(MediaAsset $asset): bool
    {
        return Schema::hasTable('humlnetcreative_pages_revision_media')
            && PageRevisionMedia::where('media_asset_uuid', $asset->uuid)->exists();
    }

    public function removeUseFilesWhenOrphaned(MediaUse $use): void
    {
        $asset = $use->asset;
        if (!$asset) {
            return;
        }

        if (!$this->useIsReferenced((string) $use->uuid)) {
            Storage::disk('media')->deleteDirectory('pages-variants/'.$asset->uuid.'/'.$use->uuid);
        }

        if (!MediaUse::where('media_asset_id', $asset->id)->exists() && !$this->assetIsReferenced($asset)) {
            Storage::disk($asset->disk)->delete($asset->path);
            $asset->delete();
        }
    }

    /** Idempotently removes only masters with no working or retained snapshot reference. */
    public function collectGarbage(): int
    {
        $deleted = 0;
        MediaAsset::query()->orderBy('id')->chunkById(100, function($assets) use (&$deleted): void {
            foreach ($assets as $asset) {
                if ($asset->uses()->exists() || $this->assetIsReferenced($asset)) {
                    continue;
                }
                Storage::disk($asset->disk)->delete($asset->path);
                $asset->delete();
                $deleted++;
            }
        });

        return $deleted;
    }

    /** Removes files released by pruned history only after checking all live references again. */
    public function cleanupReleasedReferences(iterable $references): void
    {
        foreach ($references as $reference) {
            $useUuid = (string) $reference->media_use_uuid;
            $assetUuid = (string) $reference->media_asset_uuid;
            if (!MediaUse::where('uuid', $useUuid)->exists() && !$this->useIsReferenced($useUuid)) {
                Storage::disk('media')->deleteDirectory('pages-variants/'.$assetUuid.'/'.$useUuid);
            }

            $asset = MediaAsset::where('uuid', $assetUuid)->first();
            if ($asset && !$asset->uses()->exists() && !$this->assetIsReferenced($asset)) {
                Storage::disk($asset->disk)->delete($asset->path);
                $asset->delete();
            }
        }
    }
}

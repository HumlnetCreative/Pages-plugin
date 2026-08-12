<?php namespace HumlnetCreative\Pages\Services;

use Backend\Facades\BackendAuth;
use HumlnetCreative\Pages\Models\BuilderPage;

final class PageMutationGuard
{
    public function assertWritable(?BuilderPage $page): void
    {
        $user = BackendAuth::getUser();
        if (!$page?->exists || !$user || app()->runningInConsole()) {
            return;
        }
        if (!BackendAuth::userHasPermission('humlnetcreative.pages.draft.edit')) {
            throw new \ApplicationException('Nemáte oprávnění upravovat koncept stránky.');
        }

        app(PageEditLockService::class)->assertWritable(
            $page,
            $user,
            app(EditorSessionService::class)->id(),
        );
    }
}

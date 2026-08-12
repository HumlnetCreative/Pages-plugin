<?php namespace HumlnetCreative\Pages\Services;

use Illuminate\Support\Str;

final class EditorSessionService
{
    public function id(): string
    {
        $key = 'humlnetcreative.pages.editor_session_uuid';
        $id = session()->get($key);
        if (!is_string($id) || !Str::isUuid($id)) {
            $id = (string) Str::uuid();
            session()->put($key, $id);
        }

        return $id;
    }
}

<?php namespace HumlnetCreative\Pages\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use HumlnetCreative\Pages\Services\SiteBlueprintImporter;
use Flash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class Blueprints extends Controller
{
    public $requiredPermissions = ['humlnetcreative.pages.builder.import'];

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('HumlnetCreative.Pages', 'main-menu-item', 'side-menu-builder');
    }

    public function index() {}

    public function onPreview()
    {
        $upload = request()->file('blueprint');
        if (!$upload) { throw new \ApplicationException('Vyberte YAML soubor manifestu.'); }
        $this->vars['blueprintPath'] = $upload->getRealPath();
        $this->vars['preview'] = (new SiteBlueprintImporter())->preview($upload->getRealPath());
        $this->vars['blueprintToken'] = (string) Str::uuid();
        Session::put('hucr.pages.blueprint.'.$this->vars['blueprintToken'], $this->vars['preview']);
        return ['#blueprint-result' => $this->makePartial('preview')];
    }

    public function onImport()
    {
        $token = (string) request()->input('blueprint_token');
        $data = Session::pull('hucr.pages.blueprint.'.$token);
        if (!$token || !is_array($data)) {
            throw new \ApplicationException('Náhled manifestu vypršel. Nahrajte a ověřte soubor znovu.');
        }
        (new SiteBlueprintImporter())->importData($data);
        Flash::success('Kostra webu byla vytvořena.');
    }
}

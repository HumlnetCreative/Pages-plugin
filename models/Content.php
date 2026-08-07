<?php namespace HumlnetCreative\Pages\Models;

use Backend\Facades\BackendAuth;
use Cms\Classes\Partial;
use Cms\Classes\Theme;
use Cms\Models\ThemeData;
use Illuminate\Support\Facades\Lang;
use JanVince\SmallGDPR\Models\CookiesSettings;
use HumlnetCreative\Files\Models\Category as FilesCategory;
use LZaplata\FlashMessages\Models\Category as FlashMessagesCategory;
use LZaplata\FlashMessages\Models\Message;
use HumlnetCreative\Files\Models\File;
use LZaplata\Gallery\Models\Gallery;
use LZaplata\OpeningHours\Models\OpeningHour;
use LZaplata\Pricelists\Models\Pricelist;
use LZaplata\Timelines\Models\Timeline;
use Model;
use October\Rain\Database\Traits\Multisite;
use October\Rain\Database\Traits\Sortable;
use RainLab\Blog\Models\Post as BlogPost;
use Tailor\Classes\BlueprintIndexer;
use RainLab\Blog\Models\Category;
use RainLab\Blog\Models\Post;
use Tailor\Models\EntryRecord;
use Tailor\Traits\BlueprintRelationModel;
use System\Models\File as SystemFile;
use Cms\Classes\Page as CmsPage;
use JanVince\SmallContactForm\Models\Settings as SmallContactFormSettings;

/**
 * Model
 */
class Content extends Model
{
    use \October\Rain\Database\Traits\Validation;
    use \October\Rain\Database\Traits\SoftDelete;
    use Multisite;
    use BlueprintRelationModel;
    use Sortable;

    /**
     * @var array dates to cast from the database.
     */
    protected $dates = ['deleted_at'];

    /**
     * @var string table in the database used by the model.
     */
    public $table = 'lzaplata_pages_contents';

    /**
     * @var array rules for validation.
     */
    public $rules = [
        "pricelist"     => "required_if:type,pricelist",
        "opening_hours" => "required_if:type,opening_hours",
    ];

    /**
     * @var array
     */
    public $propagatable = [];

    /**
     * @var array
     */
    public $fillable = [
        "id",
    ];

    /**
     * @var array
     */
    public $jsonable = ["variables", "padding", "border", "options"];

    /**
     * @return array
     */
    public function getTypeOptions()
    {
        $types = [
            "text"          => "Text",
            "image_text"    => e(trans("humlnetcreative.pages::lang.content.field.type.option.image_text.label")),
            "embed"         => e(trans("humlnetcreative.pages::lang.content.field.type.option.embed.label")),
            "partial"       => e(trans("humlnetcreative.pages::lang.content.field.type.option.partial.label")),
        ];

        if (class_exists(Post::class)) {
            $types["posts"] = e(trans("humlnetcreative.pages::lang.content.field.type.option.posts.label"));
        }

        if (class_exists(Gallery::class)) {
            $types["gallery"] = e(trans("humlnetcreative.pages::lang.content.field.type.option.gallery.label"));
        }

        if (class_exists(File::class)) {
            $types["files"] = e(trans("humlnetcreative.pages::lang.content.field.type.option.files.label"));
        }

        if (class_exists(Pricelist::class)) {
            $types["pricelist"] = e(trans("humlnetcreative.pages::lang.content.field.type.option.pricelist.label"));
        }

        if (class_exists(OpeningHour::class)) {
            $types["opening_hours"] = e(trans("humlnetcreative.pages::lang.content.field.type.option.opening_hours.label"));
        }

        if (BlueprintIndexer::instance()->findSectionByHandle("FAQ\Question")) {
            $types["faq"] = "FAQ";
        }

        if (BlueprintIndexer::instance()->findSectionByHandle("Contacts\Contact")) {
            $types["contacts"] = e(trans("humlnetcreative.pages::lang.content.field.type.option.contacts.label"));
        }

        if (BlueprintIndexer::instance()->findSectionByHandle("Slider\Slider")) {
            $types["slider"] = e(trans("humlnetcreative.pages::lang.content.field.type.option.slider.label"));
        }

        if (BlueprintIndexer::instance()->findSectionByHandle("Jobs\Job")) {
            $types["jobs"] = e(trans("humlnetcreative.pages::lang.content.field.type.option.jobs.label"));
        }

        if (BlueprintIndexer::instance()->findSectionByHandle("Links\Link")) {
            $types["links"]         = e(trans("humlnetcreative.pages::lang.content.field.type.option.links.label"));
            $types["links_slider"]  = e(trans("humlnetcreative.pages::lang.content.field.type.option.links_slider.label"));
        }

        if (class_exists(CookiesSettings::class)) {
            $types["cookies"] = e(trans("humlnetcreative.pages::lang.content.field.type.option.cookies.label"));
        }

        if (class_exists(SmallContactFormSettings::class)) {
            $types["contact_form"] = e(trans("humlnetcreative.pages::lang.content.field.type.option.contact_form.label"));
        }

        if (class_exists(Timeline::class)) {
            $types["timeline"] = e(trans("humlnetcreative.pages::lang.content.field.type.option.timeline.label"));
        }

        if (class_exists(Message::class)) {
            $types["flash_message"] = e(trans("humlnetcreative.pages::lang.content.field.type.option.flash_message.label"));
        }

        return $types;
    }

    /**
     * @param string|null $value
     * @param Content $formData
     * @return array
     * @throws \ApplicationException
     */
    public function getPartialOptions(?string $value, Content $formData): array
    {
        $theme = Theme::getActiveTheme();
        $partials = Partial::listInTheme($theme);
        $partialOptions = [];

        foreach ($partials as $partial) {
            if ($this->type == "contacts" && preg_match("@_contact/[a-z]+@", $partial->getBaseFileName())) {
                $partialOptions[$partial->getBaseFileName()] = $partial->getBaseFileName();
            }

            if ($this->type == "posts" && preg_match("@_post/[a-z]+@", $partial->getBaseFileName())) {
                $partialOptions[$partial->getBaseFileName()] = $partial->getBaseFileName();
            }

            if ($this->type == "jobs" && preg_match("@_post/card@", $partial->getBaseFileName())) {
                $partialOptions[$partial->getBaseFileName()] = $partial->getBaseFileName();
            }

            if (($this->type == "links" || $this->type == "links_slider") && preg_match("@_link/[a-z]+@", $partial->getBaseFileName())) {
                $partialOptions[$partial->getBaseFileName()] = $partial->getBaseFileName();
            }

            if ($this->type == "partial") {
                $partialOptions[$partial->getBaseFileName()] = $partial->getBaseFileName();
            }

            if ($this->type == "image_text" && preg_match("@_image-text/[a-z0-9]+@", $partial->getBaseFileName())) {
                $partialOptions[$partial->getBaseFileName()] = $partial->getBaseFileName();
            }

            if ($this->type == "pricelist" && preg_match("@_pricelist/[a-z]+@", $partial->getBaseFileName())) {
                $partialOptions[$partial->getBaseFileName()] = $partial->getBaseFileName();
            }
        }

        asort($partialOptions);

        return $partialOptions;
    }

    /**
     * @return array
     */
    public function getRowColsOptions(): array
    {
        return [
            "1"     => "1",
            "2"     => "2",
            "3"     => "3",
            "4"     => "4",
            "5"     => "5",
            "6"     => "6",
        ];
    }

    /**
     * @return array
     * @throws \ApplicationException
     */
    public function getPostPartialOptions(): array
    {
        $theme = Theme::getActiveTheme();
        $partials = Partial::listInTheme($theme);
        $partialOptions = [];

        foreach ($partials as $partial) {
            if ($this->type == "jobs" && preg_match("@_job/def[a-z]+@", $partial->getBaseFileName())) {
                $partialOptions[$partial->getBaseFileName()] = $partial->getBaseFileName();
            }
        }

        asort($partialOptions);

        return $partialOptions;
    }

    /**
     * @return array
     */
    public function getPostsSortOrderOptions(): array
    {
        $options = BlogPost::$allowedSortingOptions;

        foreach ($options as $key => $value) {
            $options[$key] = Lang::get($value);
        }

        return $options;
    }

    /**
     * @var array
     */
    public $attachOne = [
        "image" => SystemFile::class,
    ];

    /**
     * @var array
     */
    public $belongsTo = [
        "gallery"           => Gallery::class,
        "contacts_category" => [EntryRecord::class, "blueprint" => "lzaplata_contacts_categories"],
        "posts_category"    => Category::class,
        "files_category"          => FilesCategory::class,
        "flashmessages_category"  => FlashMessagesCategory::class,
        "page"              => Page::class,
        "pricelist"         => Pricelist::class,
        "opening_hours"     => OpeningHour::class,
        "slider"            => [EntryRecord::class, "blueprint" => "lzaplata_slider_sliders"],
        "jobs_category"     => [EntryRecord::class, "blueprint" => "lzaplata_jobs_categories"],
        "links_category"    => [EntryRecord::class, "blueprint" => "lzaplata_links_categories"],
        "timeline"          => Timeline::class,
    ];

    /**
     * @param $fields
     * @param $context
     *
     * @return void
     */
    public function filterFields($fields, $context = null)
    {
        if (!BackendAuth::userHasPermission("humlnetcreative.pages.content.update.type")) {
            $fields->type->disabled = true;
        }

        if (!BackendAuth::userHasPermission("humlnetcreative.pages.content.update.posts_category")) {
            $fields->posts_category->disabled = true;
        }

        if (!BackendAuth::userHasPermission("humlnetcreative.pages.content.update.files_category")) {
            $fields->files_category->disabled = true;
        }

        if (!BackendAuth::userHasPermission("humlnetcreative.pages.content.update.flashmessages_category")) {
            $fields->flashmessages_category->disabled = true;
        }

        if (!BackendAuth::userHasPermission("humlnetcreative.pages.content.update.contacts_category")) {
            $fields->contacts_category->disabled = true;
        }

        if (!BackendAuth::userHasPermission("humlnetcreative.pages.content.update.jobs_category")) {
            $fields->jobs_category->disabled = true;
        }

        if (!BackendAuth::userHasPermission("humlnetcreative.pages.content.update.links_category")) {
            $fields->links_category->disabled = true;
        }

        if (!BackendAuth::userHasPermission("humlnetcreative.pages.content.update.gallery")) {
            $fields->gallery->disabled = true;
        }

        if (!BackendAuth::userHasPermission("humlnetcreative.pages.content.update.pricelist")) {
            $fields->pricelist->disabled = true;
        }

        if (!BackendAuth::userHasPermission("humlnetcreative.pages.content.update.opening_hours")) {
            $fields->opening_hours->disabled = true;
        }

        if (!BackendAuth::userHasPermission("humlnetcreative.pages.content.update.slider")) {
            $fields->slider->disabled = true;
        }

        if (BackendAuth::userHasPermission("humlnetcreative.pages.content.reorder") && isset($fields->sort_order)) {
            $latestSibling = Content::where("page_id", $this->page->id)
                ->orderBy("sort_order", "desc")
                ->first();

            if ($latestSibling) {
                $order = $fields->sort_order->value ?: $latestSibling->sort_order + 10;
            } else {
                $order = 10;
            }

            $fields->sort_order->value = $order;
        }

        if (preg_match("@[a-z]+/row@", $fields->partial?->value)) {
            $fields->row_cols->hidden = true;
        }

        $optionsAttr = match($this->type) {
            "text"          => "options_text",
            "image_text"    => "options_image_text",
            "posts"         => "options_posts",
            "gallery"       => "options_gallery",
            "faq"           => "options_accordion",
            "contacts"      => "options_contacts",
            "slider"        => "options_slider",
            "jobs"          => "options_jobs",
            "links",
            "links_slider"  => "options_links",
            "contact_form"  => "options_partial",
            "timeline"      => "options_timeline",
            default         => null,
        };

        if ($optionsAttr && (!$this->exists || empty($this->getOriginal("options")))) {
            $themeOptions = $this->getThemeOptionsForType();

            if ($themeOptions !== null) {
                $this->{$optionsAttr} = $themeOptions;

                if (isset($fields->{$optionsAttr})) {
                    $fields->{$optionsAttr}->value = $themeOptions;
                }
            }
        }
    }

    /**
     * @return array
     */
    public function getNoGuttersBreakpointOptions(): array
    {
        return [
            "xs"    => "XS",
            "sm"    => "SM",
            "md"    => "MD",
            "lg"    => "LG",
            "xl"    => "XL",
            "xxl"   => "XXL",
        ];
    }

    /**
     * @return void
     */
    public function beforeSave(): void
    {
        $this->options = match($this->type) {
            "text"          => $this->options_text,
            "image_text"    => $this->options_image_text,
            "posts"         => $this->options_posts,
            "gallery"       => $this->options_gallery,
            "files"         => $this->options_partial,
            "pricelist"     => $this->options_partial,
            "opening_hours" => $this->options_partial,
            "faq"           => $this->options_accordion,
            "contacts"      => $this->options_contacts,
            "slider"        => $this->options_slider,
            "jobs"          => $this->options_jobs,
            "links"         => $this->options_links,
            "links_slider"  => $this->options_links,
            "cookies"       => $this->options_partial,
            "contact_form"  => $this->options_partial,
            "embed"         => $this->options_partial,
            "partial"       => $this->options_partial,
            "timeline"      => $this->options_timeline,
            default         => null,
        };

        unset($this->options_text);
        unset($this->options_image_text);
        unset($this->options_posts);
        unset($this->options_gallery);
        unset($this->options_accordion);
        unset($this->options_contacts);
        unset($this->options_slider);
        unset($this->options_jobs);
        unset($this->options_links);
        unset($this->options_partial);
        unset($this->options_timeline);
    }

    /**
     * @return void
     */
    public function afterFetch(): void
    {
        if (empty($this->options)) {
            $this->options = $this->getThemeOptionsForType();
        }

        match($this->type) {
            "text"          => $this->options_text = $this->options,
            "image_text"    => $this->options_image_text = $this->options,
            "posts"         => $this->options_posts = $this->options,
            "gallery"       => $this->options_gallery = $this->options,
            "files"         => $this->options_partial = $this->options,
            "pricelist"     => $this->options_partial = $this->options,
            "opening_hours" => $this->options_partial = $this->options,
            "faq"           => $this->options_accordion = $this->options,
            "contacts"      => $this->options_contacts = $this->options,
            "slider"        => $this->options_slider = $this->options,
            "jobs"          => $this->options_jobs = $this->options,
            "links"         => $this->options_links = $this->options,
            "links_slider"  => $this->options_links = $this->options,
            "cookies"       => $this->options_partial = $this->options,
            "contact_form"  => $this->options_partial = $this->options,
            "embed"         => $this->options_partial = $this->options,
            "partial"       => $this->options_partial = $this->options,
            "timeline"      => $this->options_timeline = $this->options,
            default         => null,
        };
    }

    /**
     * Returns theme options for the current content type, or null if none exist.
     *
     * @return array|null
     * @throws \ApplicationException
     */
    private function getThemeOptionsForType(): ?array
    {
        if (!$this->type) {
            return null;
        }

        $theme = Theme::getActiveTheme();

        if (!$theme) {
            return null;
        }

        $themeKey = match($this->type) {
            "links_slider"  => "links",
            "faq"           => "accordion",
            "files",
            "pricelist",
            "opening_hours",
            "cookies",
            "embed",
            "partial"       => null,
            default         => $this->type,
        };

        if (!$themeKey) {
            return null;
        }

        return ThemeData::forTheme($theme)->{$themeKey} ?? null;
    }
}

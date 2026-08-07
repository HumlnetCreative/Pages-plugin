<?php namespace HumlnetCreative\Pages\Models;

use Backend\Facades\BackendAuth;
use Cms\Classes\Partial;
use Cms\Classes\Theme;
use Cms\Models\ThemeData;
use Illuminate\Support\Facades\Lang;
use JanVince\SmallContactForm\Models\Settings as SmallContactFormSettings;
use LZaplata\FlashMessages\Models\Category as FlashMessagesCategory;
use LZaplata\FlashMessages\Models\Message;
use Model;
use October\Rain\Database\Traits\Multisite;
use RainLab\Blog\Models\Category;
use RainLab\Blog\Models\Post;
use RainLab\Blog\Models\Post as BlogPost;
use System\Models\File;
use Tailor\Classes\BlueprintIndexer;
use Tailor\Models\EntryRecord;
use Tailor\Traits\BlueprintRelationModel;

/**
 * Model
 */
class Block extends Model
{
    use \October\Rain\Database\Traits\Validation;
    use \October\Rain\Database\Traits\SoftDelete;
    use BlueprintRelationModel;
    use Multisite;

    /**
     * @var array dates to cast from the database.
     */
    protected $dates = ['deleted_at'];

    /**
     * @var string table in the database used by the model.
     */
    public $table = 'lzaplata_pages_blocks';

    /**
     * @var array rules for validation.
     */
    public $rules = [
        "title"         => "required",
        "type"          => "required",
        "sort_order"    => ["required", "numeric"],
    ];

    /**
     * @var array
     */
    public $attachOne = [
        "image" => File::class,
    ];

    /**
     * @var array
     */
    public $belongsTo = [
        "posts_category"          => Category::class,
        "flashmessages_category"  => FlashMessagesCategory::class,
        "slider"                  => [EntryRecord::class, "blueprint" => "lzaplata_slider_sliders"],
        "links_category"          => [EntryRecord::class, "blueprint" => "lzaplata_links_categories"],
    ];

    /**
     * @var array
     */
    public $propagatable = [];

    /**
     * @var array
     */
    public $jsonable = ["variables", "padding", "border", "options"];

    /**
     * @return array
     */
    public function getTypeOptions(): array
    {
        $types = [
            "text"          => "Text",
            "image_text"    => e(trans("humlnetcreative.pages::lang.block.field.type.option.image_text.label")),
            "partial"       => e(trans("humlnetcreative.pages::lang.block.field.type.option.partial.label")),
            "embed"         => e(trans("humlnetcreative.pages::lang.block.field.type.option.embed.label")),
        ];

        if (BlueprintIndexer::instance()->findSectionByHandle("Slider\Slide")) {
            $types["slider"] = "Slider";
        }

        if (class_exists(Post::class)) {
            $types["posts"]         = e(trans("humlnetcreative.pages::lang.block.field.type.option.posts.label"));
            $types["posts_slider"]  = e(trans("humlnetcreative.pages::lang.block.field.type.option.posts_slider.label"));
        }

        if (class_exists(Message::class)) {
            $types["flash_message"] = e(trans("humlnetcreative.pages::lang.block.field.type.option.flash_message.label"));
        }

        if (BlueprintIndexer::instance()->findSectionByHandle("Links\Link")) {
            $types["links"]         = e(trans("humlnetcreative.pages::lang.block.field.type.option.links.label"));
            $types["links_slider"]  = e(trans("humlnetcreative.pages::lang.block.field.type.option.links_slider.label"));
        }

        if (class_exists(SmallContactFormSettings::class)) {
            $types["contact_form"] = e(trans("humlnetcreative.pages::lang.block.field.type.option.contact_form.label"));
        }

        return $types;
    }

    /**
     * @param string|null $value
     * @param Block $formData
     * @return array
     * @throws \ApplicationException
     */
    public function getPartialOptions(?string $value, Block $formData): array
    {
        $theme = Theme::getActiveTheme();
        $partials = Partial::listInTheme($theme);
        $partialOptions = [];

        foreach ($partials as $partial) {
            if (($this->type == "posts" || $this->type == "posts_slider") && preg_match("@_post/[a-z]+@", $partial->getBaseFileName())) {
                $partialOptions[$partial->getBaseFileName()] = $partial->getBaseFileName();
            }

            if ($this->type == "partial") {
                $partialOptions[$partial->getBaseFileName()] = $partial->getBaseFileName();
            }

            if (($this->type == "links" || $this->type == "links_slider") && preg_match("@_link/[a-z]+@", $partial->getBaseFileName())) {
                $partialOptions[$partial->getBaseFileName()] = $partial->getBaseFileName();
            }

            if ($this->type == "image_text" && preg_match("@_image-text/[a-z0-9]+@", $partial->getBaseFileName())) {
                $partialOptions[$partial->getBaseFileName()] = $partial->getBaseFileName();
            }
        }

        asort($partialOptions);

        return $partialOptions;
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
     * @param $fields
     * @param $context
     *
     * @return void
     */
    public function filterFields($fields, $context = null)
    {
        if (BackendAuth::userHasPermission("humlnetcreative.pages.block.reorder") && isset($fields->sort_order)) {
            $latestSibling = Block::orderBy("sort_order", "desc")
                ->first();

            if ($latestSibling) {
                $order = $fields->sort_order->value ?: $latestSibling->sort_order + 10;
            } else {
                $order = 10;
            }

            $fields->sort_order->value = $order;
        }

        $optionsAttr = match($this->type) {
            "slider"        => "options_slider",
            "flash_message" => "options_flash_message",
            "links",
            "links_slider"  => "options_links",
            "image_text"    => "options_image_text",
            "text"          => "options_text",
            "posts",
            "posts_slider"  => "options_posts",
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
     * @return void
     * @throws \Exception
     */
    public function beforeSave(): void
    {
        $this->options = match($this->type) {
            "slider"        => $this->options_slider,
            "flash_message" => $this->options_flash_message,
            "links"         => $this->options_links,
            "links_slider"  => $this->options_links,
            "image_text"    => $this->options_image_text,
            "text"          => $this->options_text,
            "posts"         => $this->options_posts,
            "posts_slider"  => $this->options_posts,
            "partial"       => $this->options_partial,
            "embed"         => $this->options_partial,
            "contact_form"  => $this->options_partial,
            default         => null,
        };

        unset($this->options_slider);
        unset($this->options_flash_message);
        unset($this->options_links);
        unset($this->options_image_text);
        unset($this->options_text);
        unset($this->options_posts);
        unset($this->options_partial);
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function afterFetch(): void
    {
        if (empty($this->options)) {
            $this->options = $this->getThemeOptionsForType();
        }

        match($this->type) {
            "slider"        => $this->options_slider = $this->options,
            "flash_message" => $this->options_flash_message = $this->options,
            "links"         => $this->options_links = $this->options,
            "links_slider"  => $this->options_links = $this->options,
            "image_text"    => $this->options_image_text = $this->options,
            "text"          => $this->options_text = $this->options,
            "posts"         => $this->options_posts = $this->options,
            "posts_slider"  => $this->options_posts = $this->options,
            "partial"       => $this->options_partial = $this->options,
            "embed"         => $this->options_partial = $this->options,
            "contact_form"  => $this->options_partial = $this->options,
            default         => null,
        };
    }

    /**
     * Returns theme options for the current block type, or null if none exist.
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
            "posts_slider"  => "posts",
            "links_slider"  => "links",
            "partial",
            "embed",
            "contact_form"  => null,
            default         => $this->type,
        };

        if (!$themeKey) {
            return null;
        }

        return ThemeData::forTheme($theme)->{$themeKey} ?? null;
    }
}

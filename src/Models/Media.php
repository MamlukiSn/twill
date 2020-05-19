<?php

namespace A17\Twill\Models;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ImageService;
use Illuminate\Database\Eloquent\Relations\Relation;
use A17\Twill\Models\Behaviors\HasSlug;

class Media extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'uuid',
        'filename',
        'alt_text',
        'caption',
        'width',
        'height',
    ];

    public function __construct(array $attributes = [])
    {
        $this->fillable(array_merge($this->fillable, Collection::make(config('twill.media_library.extra_metadatas_fields'))->map(function ($field) {
            return $field['name'];
        })->toArray()));

        Collection::make(config('twill.media_library.translatable_metadatas_fields'))->each(function ($field) {
            $this->casts[$field] = 'json';
        });

        parent::__construct($attributes);
    }

    public function getDimensionsAttribute()
    {
        return $this->width . 'x' . $this->height;
    }

    public function altTextFrom($filename)
    {
        $filename = pathinfo($filename, PATHINFO_FILENAME);
        if (Str::endsWith($filename, '@2x')) {
            $filename = substr($filename, 0, -2);
        }

        return ucwords(preg_replace('/[^a-zA-Z0-9]/', ' ', sanitizeFilename($filename)));
    }

    public function canDeleteSafely()
    {
        return DB::table(config('twill.mediables_table', 'twill_mediables'))->where('media_id', $this->id)->count() === 0;
    }

    public function toCmsArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->filename,
            'thumbnail' => ImageService::getCmsUrl($this->uuid, ["h" => "256"]),
            'original' => ImageService::getRawUrl($this->uuid),
            'medium' => ImageService::getUrl($this->uuid, ["h" => "430"]),
            'width' => $this->width,
            'height' => $this->height,
            'tags' => $this->tags->map(function ($tag) {
                return $tag->name;
            }),
            'deleteUrl' => $this->canDeleteSafely() ? moduleRoute('medias', 'media-library', 'destroy', $this->id) : null,
            'updateUrl' => route('admin.media-library.medias.single-update'),
            'updateBulkUrl' => route('admin.media-library.medias.bulk-update'),
            'deleteBulkUrl' => route('admin.media-library.medias.bulk-delete'),
            'metadatas' => [
                'default' => [
                    'caption' => $this->caption,
                    'altText' => $this->alt_text,
                    'video' => null,
                ] + Collection::make(config('twill.media_library.extra_metadatas_fields'))->mapWithKeys(function ($field) {
                    return [
                        $field['name'] => $this->{$field['name']},
                    ];
                })->toArray(),
                'custom' => [
                    'caption' => null,
                    'altText' => null,
                    'video' => null,
                ],
            ],
            'owners' => $this->getOwnerDetails(),
        ];
    }

    public function getMetadata($name, $fallback = null)
    {
        $metadatas = (object) json_decode($this->pivot->metadatas);
        $language = app()->getLocale();

        if ($metadatas->$name->$language ?? false) {
            return $metadatas->$name->$language;
        }

        $fallbackLocale = config('translatable.fallback_locale');

        if (in_array($name, config('twill.media_library.translatable_metadatas_fields', [])) && config('translatable.use_property_fallback', false) && ($metadatas->$name->$fallbackLocale ?? false)) {
            return $metadatas->$name->$fallbackLocale;
        }

        $fallbackValue = $fallback ? $this->$fallback : $this->$name;

        $fallback = $fallback ?? $name;

        if (in_array($fallback, config('twill.media_library.translatable_metadatas_fields', []))) {
            $fallbackValue = $fallbackValue[$language] ?? '';

            if ($fallbackValue === '' && config('translatable.use_property_fallback', false)) {
                $fallbackValue = $this->$fallback[config('translatable.fallback_locale')] ?? '';
            }
        }

        if (is_object($metadatas->$name ?? null)) {
            return $fallbackValue ?? '';
        }

        return $metadatas->$name ?? $fallbackValue ?? '';
    }

    public function getTable()
    {
        return config('twill.medias_table', 'twill_medias');
    }

    public function getOwners()
    {
        $morphMap = Relation::morphMap();

        $owners = collect(
            DB::table(config('twill.mediables_table', 'twill_mediables'))
                ->where('media_id', $this->id)->get()
            );

        return $owners->map(function ($owner) use ($morphMap){
            $resolvedClass =  array_key_exists($owner->mediable_type, $morphMap) ? $morphMap[ $owner->mediable_type ] : $owner->mediable_type;

            return resolve($resolvedClass)::find($owner->mediable_id);

        });
    }

    public function getOwnerDetails()
    {
        $owners =  $this->getOwners();

        return collect(($owners))->filter(function ($value){
            return is_object($value);
        })->map(function ($item){
            $module = Str::plural(lcfirst((new \ReflectionClass($item))->getShortName()));

            if ($item instanceof Block){
                $model=$item->blockable;

                $module = $model ? Str::plural(lcfirst((new \ReflectionClass($model))->getShortName())): null;

                return ($model && $module) ? [
                    'id' => $model->id,
                    'slug' =>classHasTrait($model, HasSlug::class) ? $model->slug : null,
                    'name' => $model->{$model->titleKey},
                    'titleKey' => $model->titleKey,
                    'model'=>$model,
                    'module'=>$module,
                    'edit' => moduleRoute($module, config('twill.block_editor.browser_route_prefixes.' . $module), 'edit', $model->id),
                ] : [];

            }

            return [
                'id' => $item->id,
                'slug' => classHasTrait($item, HasSlug::class) ? $item->slug : null,
                'name' => $item->{$item->titleKey},
                'titleKey' => $item->titleKey,
                'model'=>$item,
                'module'=>$module,
                'edit' => moduleRoute($module, config('twill.block_editor.browser_route_prefixes.' . $module), 'edit', $item->id),
            ];

        })->filter()->values()->toArray();

    }
}

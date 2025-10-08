<?php

namespace Spatie\Translatable;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spatie\Translatable\Events\TranslationHasBeenSetEvent;
use Spatie\Translatable\Exceptions\AttributeIsNotTranslatable;

trait HasTranslations
{
    protected ?string $translationLocale = null;

    /**
     * Translation drivers for each translatable attribute.
     *
     * @var array<string, \Spatie\Translatable\Contracts\TranslationDriver>
     */
    protected array $translationDrivers = [];

    public function initializeHasTranslations(): void
    {
        $translatableConfig = app(Translatable::class);
        $registry = $translatableConfig->registry();

        // Get raw translatable attributes (may include array configs)
        $translatablesRaw = $this->getTranslatableAttributesRaw();

        foreach ($translatablesRaw as $key => $config) {
            // Normalize config
            if (is_int($key)) {
                // Simple string attribute
                $attribute = $config;
                $config = [];
            } else {
                // Array config
                $attribute = $key;
            }

            // Resolve driver
            $driver = $registry->resolve($this, $attribute, $config);
            $this->translationDrivers[$attribute] = $driver;

            // Only add array cast for JSON driver
            if ($driver instanceof \Spatie\Translatable\Drivers\JsonColumnDriver) {
                $this->mergeCasts([$attribute => 'array']);
            }
        }
    }

    public function setAttr($attribute,$value){
        $this->attributes[$attribute] = $value;
    }

    public static function usingLocale(string $locale): self
    {
        return (new self)->setLocale($locale);
    }

    public function useFallbackLocale(): bool
    {
        if (property_exists($this, 'useFallbackLocale')) {
            return $this->useFallbackLocale;
        }

        return true;
    }

    public function getAttributeValue($key): mixed
    {
        if (! $this->isTranslatableAttribute($key)) {
            return parent::getAttributeValue($key);
        }

        $driver = $this->driver($key);

        return $driver->get($this, $this->getLocale(), $this->useFallbackLocale());
    }

    protected function mutateAttributeForArray($key, $value): mixed
    {
        if (! $this->isTranslatableAttribute($key)) {
            return parent::mutateAttributeForArray($key, $value);
        }

        $translations = $this->getTranslations($key);

        return array_map(fn ($value) => parent::mutateAttributeForArray($key, $value), $translations);
    }

    public function setAttribute($key, $value)
    {
        if (! $this->isTranslatableAttribute($key)) {
            return parent::setAttribute($key, $value);
        }

        $driver = $this->driver($key);

        if (is_array($value) && (! array_is_list($value) || count($value) === 0)) {
            $driver->setMany($this, $value);

            return $this;
        }

        $driver->set($this, $this->getLocale(), $value);

        return $this;
    }

    public function translate(string $key, string $locale = '', bool $useFallbackLocale = true): mixed
    {
        return $this->getTranslation($key, $locale, $useFallbackLocale);
    }

    public function getTranslation(string $key, string $locale, bool $useFallbackLocale = true): mixed
    {
        $driver = $this->driver($key);
        $translation = $driver->get($this, $locale, $useFallbackLocale);

        // Apply mutators if they exist
        $mutatorKey = str_replace('->', '-', $key);

        if ($this->hasGetMutator($mutatorKey)) {
            return $this->mutateAttribute($mutatorKey, $translation);
        }

        if ($this->hasAttributeMutator($mutatorKey)) {
            return $this->mutateAttributeMarkedAttribute($mutatorKey, $translation);
        }

        return $translation;
    }

    public function getTranslationWithFallback(string $key, string $locale): mixed
    {
        return $this->getTranslation($key, $locale, true);
    }

    public function getTranslationWithoutFallback(string $key, string $locale): mixed
    {
        return $this->getTranslation($key, $locale, false);
    }

    public function getTranslations(?string $key = null, ?array $allowedLocales = null): array
    {
        if ($key !== null) {
            $this->guardAgainstNonTranslatableAttribute($key);
            $driver = $this->driver($key);

            return $driver->all($this, $allowedLocales);
        }

        return array_reduce($this->getTranslatableAttributes(), function ($result, $item) use ($allowedLocales) {
            $result[$item] = $this->getTranslations($item, $allowedLocales);

            return $result;
        }, []);
    }

    public function setTranslation(string $key, string $locale, $value): self
    {
        $this->guardAgainstNonTranslatableAttribute($key);

        $mutatorKey = str_replace('->', '-', $key);

        // Apply set mutators if they exist
        if ($this->hasSetMutator($mutatorKey)) {
            $method = 'set'.Str::studly($mutatorKey).'Attribute';
            $this->{$method}($value, $locale);
            $value = $this->attributes[$key] ?? $value;
        } elseif ($this->hasAttributeSetMutator($mutatorKey)) {
            $this->setAttributeMarkedMutatedAttributeValue($mutatorKey, $value);
            $value = $this->attributes[$mutatorKey] ?? $value;
        }

        $driver = $this->driver($key);
        $driver->set($this, $locale, $value);

        return $this;
    }

    public function setTranslations(string $key, array $translations): self
    {
        $this->guardAgainstNonTranslatableAttribute($key);

        $driver = $this->driver($key);
        $driver->setMany($this, $translations);

        return $this;
    }

    public function forgetTranslation(string $key, string $locale): self
    {
        $this->guardAgainstNonTranslatableAttribute($key);

        $driver = $this->driver($key);
        $driver->forget($this, $locale, false);

        return $this;
    }

    public function forgetTranslations(string $key, bool $asNull = false): self
    {
        $this->guardAgainstNonTranslatableAttribute($key);

        $driver = $this->driver($key);
        $driver->forget($this, null, $asNull);

        return $this;
    }

    public function forgetAllTranslations(string $locale): self
    {
        collect($this->getTranslatableAttributes())->each(function (string $attribute) use ($locale) {
            $this->forgetTranslation($attribute, $locale);
        });

        return $this;
    }

    public function getTranslatedLocales(string $key): array
    {
        $driver = $this->driver($key);

        return $driver->locales($this);
    }

    public function isTranslatableAttribute(string $key): bool
    {
        return in_array($key, $this->getTranslatableAttributes());
    }

    public function hasTranslation(string $key, ?string $locale = null): bool
    {
        $locale = $locale ?: $this->getLocale();

        return isset($this->getTranslations($key)[$locale]);
    }

    public function replaceTranslations(string $key, array $translations): self
    {
        $this->guardAgainstNonTranslatableAttribute($key);

        $driver = $this->driver($key);
        $driver->forget($this, null, false);
        $driver->setMany($this, $translations);

        return $this;
    }

    protected function guardAgainstNonTranslatableAttribute(string $key): void
    {
        if (! $this->isTranslatableAttribute($key)) {
            throw AttributeIsNotTranslatable::make($key, $this);
        }
    }

    public function setLocale(string $locale): self
    {
        $this->translationLocale = $locale;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->translationLocale ?: config('app.locale');
    }

    public function getTranslatableAttributes(): array
    {
        $raw = $this->getTranslatableAttributesRaw();
        $attributes = [];

        foreach ($raw as $key => $value) {
            if (is_int($key)) {
                // Simple string attribute
                $attributes[] = $value;
            } else {
                // Array config - key is the attribute name
                $attributes[] = $key;
            }
        }

        return $attributes;
    }

    /**
     * Get raw translatable attributes configuration (may include arrays).
     */
    protected function getTranslatableAttributesRaw(): array
    {
        return is_array($this->translatable)
            ? $this->translatable
            : [];
    }

    public function translations(): Attribute
    {
        return Attribute::get(function () {
            return collect($this->getTranslatableAttributes())
                ->mapWithKeys(function (string $key) {
                    return [$key => $this->getTranslations($key)];
                })
                ->toArray();
        });
    }

    public function locales(): array
    {
        return array_unique(
            array_reduce($this->getTranslatableAttributes(), function ($result, $item) {
                $driver = $this->driver($item);

                return array_merge($result, $driver->locales($this));
            }, [])
        );
    }

    public function scopeWhereLocale(Builder $query, string $column, string $locale): void
    {
        if (! method_exists($query->getModel(), 'isTranslatableAttribute')) {
            // Fallback for models not using HasTranslations
            $query->whereNotNull("{$column}->{$locale}");

            return;
        }

        $model = $query->getModel();
        if (! $model->isTranslatableAttribute($column)) {
            $query->whereNotNull("{$column}->{$locale}");

            return;
        }

        $driver = $model->driver($column);
        $driver->scopeWhereLocale($query, $locale);
    }

    public function scopeWhereLocales(Builder $query, string $column, array $locales): void
    {
        if (! method_exists($query->getModel(), 'isTranslatableAttribute')) {
            // Fallback for models not using HasTranslations
            $query->where(function (Builder $query) use ($column, $locales) {
                foreach ($locales as $locale) {
                    $query->orWhereNotNull("{$column}->{$locale}");
                }
            });

            return;
        }

        $model = $query->getModel();
        if (! $model->isTranslatableAttribute($column)) {
            $query->where(function (Builder $query) use ($column, $locales) {
                foreach ($locales as $locale) {
                    $query->orWhereNotNull("{$column}->{$locale}");
                }
            });

            return;
        }

        $driver = $model->driver($column);
        $driver->scopeWhereLocales($query, $locales);
    }

    public function scopeWhereJsonContainsLocale(Builder $query, string $column, string $locale, mixed $value, string $operand = '='): void
    {
        $query->where("{$column}->{$locale}", $operand, $value);
    }

    public function scopeWhereJsonContainsLocales(Builder $query, string $column, array $locales, mixed $value, string $operand = '='): void
    {
        $query->where(function (Builder $query) use ($column, $locales, $value, $operand) {
            foreach ($locales as $locale) {
                $query->orWhere("{$column}->{$locale}", $operand, $value);
            }
        });
    }

    /**
     * @deprecated
     */
    public static function whereLocale(string $column, string $locale): Builder
    {
        return static::query()->whereNotNull("{$column}->{$locale}");
    }

    /**
     * @deprecated
     */
    public static function whereLocales(string $column, array $locales): Builder
    {
        return static::query()->where(function (Builder $query) use ($column, $locales) {
            foreach ($locales as $locale) {
                $query->orWhereNotNull("{$column}->{$locale}");
            }
        });
    }

    /**
     * Get translation driver for an attribute.
     *
     * @param  string  $attribute  Attribute name
     * @return \Spatie\Translatable\Contracts\TranslationDriver
     */
    protected function driver(string $attribute): \Spatie\Translatable\Contracts\TranslationDriver
    {
        if (! isset($this->translationDrivers[$attribute])) {
            throw new \InvalidArgumentException("No translation driver found for attribute [{$attribute}]. Make sure the model is properly initialized.");
        }

        return $this->translationDrivers[$attribute];
    }
}

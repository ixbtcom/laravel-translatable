<?php

namespace Spatie\Translatable\Drivers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Spatie\Translatable\Events\TranslationHasBeenSetEvent;

class HybridColumnDriver extends AbstractTranslationDriver
{
    protected ?string $cachedStorageColumn = null;

    protected ?string $cachedBaseLocale = null;

    public function get(Model $model, string $locale, bool $withFallback = true): mixed
    {
        $baseLocale = $this->resolveBaseLocale($model);
        $storageColumn = $this->resolveStorageColumn($model);
        $attribute = $this->attribute;

        // If requesting base locale, return plain column value
        if ($locale === $baseLocale) {
            return Arr::get($model->getAttributes(), $attribute);
        }

        // Try to get from storage column
        $storageData = $this->getStorageData($model, $storageColumn);
        $value = data_get($storageData, "{$locale}.{$attribute}");

        if ($value !== null || ! $withFallback) {
            return $value;
        }

        // Fallback to base locale in storage
        $value = data_get($storageData, "{$baseLocale}.{$attribute}");
        if ($value !== null) {
            return $value;
        }

        // Fallback to plain column
        return Arr::get($model->getAttributes(), $attribute);
    }

    public function set(Model $model, string $locale, mixed $value): void
    {
        $baseLocale = $this->resolveBaseLocale($model);
        $storageColumn = $this->resolveStorageColumn($model);
        $attribute = $this->attribute;

        $oldValue = $this->get($model, $locale, false);

        if ($locale === $baseLocale) {
            // Set plain column for base locale
            $model->attributes[$attribute] = $value;

            // Remove from storage column if not forcing base in storage
            if (! $this->getOption('forceBaseInStorage', false)) {
                $storageData = $this->getStorageData($model, $storageColumn);
                unset($storageData[$baseLocale][$attribute]);
                $this->setStorageData($model, $storageColumn, $storageData);
            }
        } else {
            // Set in storage column for non-base locales
            $storageData = $this->getStorageData($model, $storageColumn);
            data_set($storageData, "{$locale}.{$attribute}", $value);
            $this->setStorageData($model, $storageColumn, $storageData);
        }

        event(new TranslationHasBeenSetEvent($model, $attribute, $locale, $oldValue, $value));
    }

    public function forget(Model $model, ?string $locale = null, bool $asNull = false): void
    {
        $attribute = $this->attribute;

        if ($locale === null) {
            // Forget all translations
            $baseLocale = $this->resolveBaseLocale($model);
            $model->attributes[$attribute] = $asNull ? null : '';

            $storageColumn = $this->resolveStorageColumn($model);
            $storageData = $this->getStorageData($model, $storageColumn);

            // Remove all locale entries for this attribute
            foreach ($storageData as $loc => $fields) {
                if (is_array($fields) && isset($fields[$attribute])) {
                    unset($storageData[$loc][$attribute]);
                }
            }

            $this->setStorageData($model, $storageColumn, $storageData);

            return;
        }

        $baseLocale = $this->resolveBaseLocale($model);

        if ($locale === $baseLocale) {
            $model->attributes[$attribute] = $asNull ? null : '';
        } else {
            $storageColumn = $this->resolveStorageColumn($model);
            $storageData = $this->getStorageData($model, $storageColumn);
            unset($storageData[$locale][$attribute]);
            $this->setStorageData($model, $storageColumn, $storageData);
        }
    }

    public function all(Model $model, ?array $allowedLocales = null): array
    {
        $baseLocale = $this->resolveBaseLocale($model);
        $storageColumn = $this->resolveStorageColumn($model);
        $attribute = $this->attribute;

        $translations = [];

        // Get base locale from plain column
        $baseValue = Arr::get($model->getAttributes(), $attribute);
        if ($baseValue !== null) {
            $translations[$baseLocale] = $baseValue;
        }

        // Get other locales from storage column
        $storageData = $this->getStorageData($model, $storageColumn);
        foreach ($storageData as $locale => $fields) {
            if (is_array($fields) && isset($fields[$attribute])) {
                $translations[$locale] = $fields[$attribute];
            }
        }

        return $this->filterTranslations($translations, $allowedLocales);
    }

    public function scopeWhereLocale(Builder $query, string $locale): void
    {
        $baseLocale = $this->resolveBaseLocale($query->getModel());
        $storageColumn = $this->resolveStorageColumn($query->getModel());
        $attribute = $this->attribute;

        if ($locale === $baseLocale) {
            $query->whereNotNull($attribute);
        } else {
            $query->whereNotNull("{$storageColumn}->{$locale}->{$attribute}");
        }
    }

    public function scopeWhereLocales(Builder $query, array $locales): void
    {
        $baseLocale = $this->resolveBaseLocale($query->getModel());
        $storageColumn = $this->resolveStorageColumn($query->getModel());
        $attribute = $this->attribute;

        $query->where(function (Builder $query) use ($locales, $baseLocale, $storageColumn, $attribute) {
            foreach ($locales as $locale) {
                if ($locale === $baseLocale) {
                    $query->orWhereNotNull($attribute);
                } else {
                    $query->orWhereNotNull("{$storageColumn}->{$locale}->{$attribute}");
                }
            }
        });
    }

    /**
     * Resolve storage column name from model configuration.
     */
    protected function resolveStorageColumn(Model $model): string
    {
        if ($this->cachedStorageColumn !== null) {
            return $this->cachedStorageColumn;
        }

        $this->cachedStorageColumn = $this->getOption('storageColumn')
            ?? (defined(get_class($model).'::EXTRA_JSON_COLUMN') ? $model::EXTRA_JSON_COLUMN : null)
            ?? config('common.translations.storage_column')
            ?? 'translations';

        return $this->cachedStorageColumn;
    }

    /**
     * Resolve base locale from model configuration.
     */
    protected function resolveBaseLocale(Model $model): string
    {
        if ($this->cachedBaseLocale !== null) {
            return $this->cachedBaseLocale;
        }

        $this->cachedBaseLocale = $this->getOption('baseLocale')
            ?? (defined(get_class($model).'::BASE_LOCALE') ? $model::BASE_LOCALE : null)
            ?? config('common.translations.base_locale')
            ?? config('app.fallback_locale', 'en');

        return $this->cachedBaseLocale;
    }

    /**
     * Get storage data from model.
     */
    protected function getStorageData(Model $model, string $storageColumn): array
    {
        $raw = Arr::get($model->getAttributes(), $storageColumn);

        if ($raw === null) {
            return [];
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Set storage data on model.
     */
    protected function setStorageData(Model $model, string $storageColumn, array $data): void
    {
        $model->attributes[$storageColumn] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

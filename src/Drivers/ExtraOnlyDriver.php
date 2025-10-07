<?php

namespace Spatie\Translatable\Drivers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Spatie\Translatable\Events\TranslationHasBeenSetEvent;

class ExtraOnlyDriver extends AbstractTranslationDriver
{
    protected ?string $cachedStorageColumn = null;

    protected ?string $cachedBaseLocale = null;

    public function get(Model $model, string $locale, bool $withFallback = true): mixed
    {
        $storageColumn = $this->resolveStorageColumn($model);
        $attribute = $this->attribute;

        $storageData = $this->getStorageData($model, $storageColumn);
        $value = data_get($storageData, "{$locale}.{$attribute}");

        if ($value !== null || ! $withFallback) {
            return $value;
        }

        // Fallback to base locale
        if ($withFallback) {
            $baseLocale = $this->resolveBaseLocale($model);
            $value = data_get($storageData, "{$baseLocale}.{$attribute}");
        }

        return $value;
    }

    public function set(Model $model, string $locale, mixed $value): void
    {
        $storageColumn = $this->resolveStorageColumn($model);
        $attribute = $this->attribute;

        $oldValue = $this->get($model, $locale, false);

        $storageData = $this->getStorageData($model, $storageColumn);
        data_set($storageData, "{$locale}.{$attribute}", $value);
        $this->setStorageData($model, $storageColumn, $storageData);

        event(new TranslationHasBeenSetEvent($model, $attribute, $locale, $oldValue, $value));
    }

    public function forget(Model $model, ?string $locale = null, bool $asNull = false): void
    {
        $storageColumn = $this->resolveStorageColumn($model);
        $attribute = $this->attribute;

        if ($locale === null) {
            // Forget all translations
            $storageData = $this->getStorageData($model, $storageColumn);

            foreach ($storageData as $loc => $fields) {
                if (is_array($fields) && isset($fields[$attribute])) {
                    unset($storageData[$loc][$attribute]);
                }
            }

            $this->setStorageData($model, $storageColumn, $storageData);

            return;
        }

        // Forget single locale
        $storageData = $this->getStorageData($model, $storageColumn);
        unset($storageData[$locale][$attribute]);
        $this->setStorageData($model, $storageColumn, $storageData);
    }

    public function all(Model $model, ?array $allowedLocales = null): array
    {
        $storageColumn = $this->resolveStorageColumn($model);
        $attribute = $this->attribute;

        $translations = [];
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
        $storageColumn = $this->resolveStorageColumn($query->getModel());
        $attribute = $this->attribute;

        $query->whereNotNull("{$storageColumn}->{$locale}->{$attribute}");
    }

    public function scopeWhereLocales(Builder $query, array $locales): void
    {
        $storageColumn = $this->resolveStorageColumn($query->getModel());
        $attribute = $this->attribute;

        $query->where(function (Builder $query) use ($locales, $storageColumn, $attribute) {
            foreach ($locales as $locale) {
                $query->orWhereNotNull("{$storageColumn}->{$locale}->{$attribute}");
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

<?php

namespace Spatie\Translatable\Drivers;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spatie\Translatable\Events\TranslationHasBeenSetEvent;

class JsonColumnDriver extends AbstractTranslationDriver
{
    public function get(Model $model, string $locale, bool $withFallback = true): mixed
    {
        $normalizedLocale = $this->normalizeLocale($model, $locale, $withFallback);

        $isKeyMissingFromLocale = ($locale !== $normalizedLocale);

        $translations = $this->all($model);

        $attribute = $this->attribute;
        $baseKey = Str::before($attribute, '->');

        if (is_null($model->getAttributeFromArray($baseKey))) {
            $translation = null;
        } else {
            $translation = $translations[$normalizedLocale] ?? null;
            $translation ??= ($this->translatableConfig->allowNullForTranslation) ? null : '';
        }

        if ($isKeyMissingFromLocale && $this->translatableConfig->missingKeyCallback) {
            try {
                $callbackReturnValue = ($this->translatableConfig->missingKeyCallback)(
                    $model,
                    $attribute,
                    $locale,
                    $translation,
                    $normalizedLocale
                );
                if (is_string($callbackReturnValue)) {
                    $translation = $callbackReturnValue;
                }
            } catch (Exception) {
                // prevent the fallback to crash
            }
        }

        return $translation;
    }

    public function set(Model $model, string $locale, mixed $value): void
    {
        $attribute = $this->attribute;
        $translations = $this->all($model);

        $oldValue = $translations[$locale] ?? '';

        $translations[$locale] = $value;

        if ($this->isNestedKey($attribute)) {
            unset($model->attributes[$attribute]);
            $this->fillJsonAttribute($model, $attribute, $translations);
        } else {
            $model->attributes[$attribute] = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        event(new TranslationHasBeenSetEvent($model, $attribute, $locale, $oldValue, $value));
    }

    public function forget(Model $model, ?string $locale = null, bool $asNull = false): void
    {
        $attribute = $this->attribute;

        if ($locale === null) {
            // Forget all translations
            $translatedLocales = $this->locales($model);
            foreach ($translatedLocales as $loc) {
                $this->forget($model, $loc, false);
            }

            if ($asNull) {
                $model->attributes[$attribute] = null;
            }

            return;
        }

        // Forget single locale
        $translations = $this->all($model);
        unset($translations[$locale]);

        $this->setMany($model, $translations);
    }

    public function all(Model $model, ?array $allowedLocales = null): array
    {
        $attribute = $this->attribute;

        if ($this->isNestedKey($attribute)) {
            [$key, $nestedKey] = explode('.', str_replace('->', '.', $attribute), 2);
        } else {
            $key = $attribute;
            $nestedKey = null;
        }

        $rawValue = $model->getAttributeFromArray($key);
        $decoded = $model->fromJson($rawValue);

        $translations = Arr::get($decoded, $nestedKey, []);

        if (! is_array($translations)) {
            return [];
        }

        return $this->filterTranslations($translations, $allowedLocales);
    }

    public function scopeWhereLocale(Builder $query, string $locale): void
    {
        $query->whereNotNull("{$this->attribute}->{$locale}");
    }

    public function scopeWhereLocales(Builder $query, array $locales): void
    {
        $query->where(function (Builder $query) use ($locales) {
            foreach ($locales as $locale) {
                $query->orWhereNotNull("{$this->attribute}->{$locale}");
            }
        });
    }

    /**
     * Check if attribute uses nested JSON key notation.
     */
    protected function isNestedKey(string $key): bool
    {
        return str_contains($key, '->');
    }

    /**
     * Fill a JSON attribute with translations.
     */
    protected function fillJsonAttribute(Model $model, string $key, array $translations): void
    {
        [$baseKey, $nestedPath] = explode('.', str_replace('->', '.', $key), 2);

        $currentValue = $model->fromJson($model->getAttributeFromArray($baseKey)) ?? [];

        Arr::set($currentValue, $nestedPath, $translations);

        $model->attributes[$baseKey] = $model->asJson($currentValue);
    }
}

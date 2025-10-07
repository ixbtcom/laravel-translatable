<?php

namespace Spatie\Translatable\Drivers;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\Contracts\TranslationDriver;
use Spatie\Translatable\Translatable;

abstract class AbstractTranslationDriver implements TranslationDriver
{
    protected string $attribute;

    protected Translatable $translatableConfig;

    protected array $options;

    public function __construct(string $attribute, Translatable $translatableConfig, array $options = [])
    {
        $this->attribute = $attribute;
        $this->translatableConfig = $translatableConfig;
        $this->options = $options;
    }

    public function getAttribute(): string
    {
        return $this->attribute;
    }

    public function setMany(Model $model, array $translations): void
    {
        foreach ($translations as $locale => $value) {
            $this->set($model, $locale, $value);
        }
    }

    public function locales(Model $model): array
    {
        return array_keys($this->all($model));
    }

    /**
     * Get fallback locale for the model.
     */
    protected function getFallbackLocale(Model $model): ?string
    {
        if (method_exists($model, 'getFallbackLocale')) {
            return $model->getFallbackLocale();
        }

        return $this->translatableConfig->fallbackLocale ?? config('app.fallback_locale');
    }

    /**
     * Normalize locale with fallback logic.
     */
    protected function normalizeLocale(Model $model, string $locale, bool $useFallbackLocale): string
    {
        $translatedLocales = $this->locales($model);

        if (in_array($locale, $translatedLocales)) {
            return $locale;
        }

        if (! $useFallbackLocale) {
            return $locale;
        }

        $fallbackLocale = $this->getFallbackLocale($model);

        if (! is_null($fallbackLocale) && in_array($fallbackLocale, $translatedLocales)) {
            return $fallbackLocale;
        }

        if (! empty($translatedLocales) && $this->translatableConfig->fallbackAny) {
            return $translatedLocales[0];
        }

        return $locale;
    }

    /**
     * Filter translations based on allowed locales and null/empty settings.
     */
    protected function filterTranslations(array $translations, ?array $allowedLocales = null): array
    {
        return array_filter(
            $translations,
            fn ($value, $locale) => $this->shouldIncludeTranslation($value, $locale, $allowedLocales),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Check if translation should be included based on config.
     */
    protected function shouldIncludeTranslation(mixed $value, string $locale, ?array $allowedLocales): bool
    {
        if ($value === null && ! $this->translatableConfig->allowNullForTranslation) {
            return false;
        }

        if ($value === '' && ! $this->translatableConfig->allowEmptyStringForTranslation) {
            return false;
        }

        if ($allowedLocales !== null && ! in_array($locale, $allowedLocales)) {
            return false;
        }

        return true;
    }

    /**
     * Get option value with fallback.
     */
    protected function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}

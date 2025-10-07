<?php

namespace Spatie\Translatable\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface TranslationDriver
{
    /**
     * Get the attribute name this driver manages.
     */
    public function getAttribute(): string;

    /**
     * Get translation for a specific locale.
     *
     * @param  Model  $model  The model instance
     * @param  string  $locale  The locale to get translation for
     * @param  bool  $withFallback  Whether to use fallback locales
     * @return mixed The translated value
     */
    public function get(Model $model, string $locale, bool $withFallback = true): mixed;

    /**
     * Set translation for a specific locale.
     *
     * @param  Model  $model  The model instance
     * @param  string  $locale  The locale to set translation for
     * @param  mixed  $value  The value to set
     */
    public function set(Model $model, string $locale, mixed $value): void;

    /**
     * Set multiple translations at once.
     *
     * @param  Model  $model  The model instance
     * @param  array  $translations  Array of locale => value pairs
     */
    public function setMany(Model $model, array $translations): void;

    /**
     * Forget translation(s) for given locale(s).
     *
     * @param  Model  $model  The model instance
     * @param  string|null  $locale  The locale to forget (null = all locales)
     * @param  bool  $asNull  Whether to set the attribute to null after forgetting
     */
    public function forget(Model $model, ?string $locale = null, bool $asNull = false): void;

    /**
     * Get all translations for this attribute.
     *
     * @param  Model  $model  The model instance
     * @param  array|null  $allowedLocales  Filter results to these locales only
     * @return array Array of locale => value pairs
     */
    public function all(Model $model, ?array $allowedLocales = null): array;

    /**
     * Get all locales that have translations for this attribute.
     *
     * @param  Model  $model  The model instance
     * @return array Array of locale codes
     */
    public function locales(Model $model): array;

    /**
     * Add WHERE clause to query for records having translation in given locale.
     *
     * @param  Builder  $query  The query builder instance
     * @param  string  $locale  The locale to filter by
     */
    public function scopeWhereLocale(Builder $query, string $locale): void;

    /**
     * Add WHERE clause to query for records having translation in any of given locales.
     *
     * @param  Builder  $query  The query builder instance
     * @param  array  $locales  Array of locales to filter by
     */
    public function scopeWhereLocales(Builder $query, array $locales): void;
}

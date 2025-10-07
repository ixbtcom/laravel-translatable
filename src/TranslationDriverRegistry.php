<?php

namespace Spatie\Translatable;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\Contracts\TranslationDriver;
use Spatie\Translatable\Drivers\ExtraOnlyDriver;
use Spatie\Translatable\Drivers\HybridColumnDriver;
use Spatie\Translatable\Drivers\JsonColumnDriver;

class TranslationDriverRegistry
{
    /**
     * Registered drivers by name.
     *
     * @var array<string, class-string<TranslationDriver>>
     */
    protected array $drivers = [];

    /**
     * Cast class to driver name mapping.
     *
     * @var array<class-string, string>
     */
    protected array $castMapping = [];

    /**
     * Cache of resolved driver instances.
     *
     * @var array<string, TranslationDriver>
     */
    protected array $cache = [];

    protected Translatable $translatableConfig;

    public function __construct(Translatable $translatableConfig)
    {
        $this->translatableConfig = $translatableConfig;
        $this->registerDefaultDrivers();
    }

    /**
     * Register a translation driver.
     *
     * @param  string  $name  Driver name
     * @param  class-string<TranslationDriver>  $driverClass  Driver class name
     */
    public function register(string $name, string $driverClass): self
    {
        $this->drivers[$name] = $driverClass;

        return $this;
    }

    /**
     * Register a cast to driver mapping.
     *
     * @param  class-string  $castClass  Cast class name
     * @param  string  $driverName  Driver name
     */
    public function registerCast(string $castClass, string $driverName): self
    {
        $this->castMapping[$castClass] = $driverName;

        return $this;
    }

    /**
     * Resolve a driver for a model's attribute.
     *
     * @param  Model  $model  The model instance
     * @param  string  $attribute  The attribute name
     * @param  array|string  $config  Attribute configuration from $translatable
     * @return TranslationDriver The resolved driver instance
     */
    public function resolve(Model $model, string $attribute, array|string $config): TranslationDriver
    {
        // Parse config if it's a string (simple attribute name)
        if (is_string($config)) {
            $config = ['driver' => null];
        }

        // Determine driver name
        $driverName = $this->determineDriverName($model, $attribute, $config);

        // Create cache key
        $cacheKey = get_class($model).'::'.$attribute.'::'.$driverName;

        // Return cached instance if available
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // Resolve driver class
        if (! isset($this->drivers[$driverName])) {
            throw new \InvalidArgumentException("Translation driver [{$driverName}] not registered.");
        }

        $driverClass = $this->drivers[$driverName];

        // Extract options from config
        $options = array_diff_key($config, ['driver' => null]);

        // Create driver instance
        $driver = new $driverClass($attribute, $this->translatableConfig, $options);

        // Cache and return
        $this->cache[$cacheKey] = $driver;

        return $driver;
    }

    /**
     * Clear the driver cache.
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Determine driver name from model and config.
     */
    protected function determineDriverName(Model $model, string $attribute, array $config): string
    {
        // 1. Explicit driver in config
        if (isset($config['driver'])) {
            return $config['driver'];
        }

        // 2. Check cast mapping
        $casts = $model->getCasts();
        if (isset($casts[$attribute])) {
            $castType = $casts[$attribute];

            // Handle cast with parameters (e.g., "HybridTranslatable:extra")
            if (is_string($castType)) {
                $castClass = explode(':', $castType)[0];

                if (isset($this->castMapping[$castClass])) {
                    return $this->castMapping[$castClass];
                }
            }
        }

        // 3. Default to json driver
        return 'json';
    }

    /**
     * Register default drivers.
     */
    protected function registerDefaultDrivers(): void
    {
        $this->register('json', JsonColumnDriver::class);
        $this->register('hybrid', HybridColumnDriver::class);
        $this->register('extra_only', ExtraOnlyDriver::class);

        // Register cast mappings (only for full class names from common package)
        $this->registerCast('Ixbtcom\Common\Casts\HybridTranslatable', 'hybrid');
        $this->registerCast('Ixbtcom\Common\Casts\ExtraOnlyTranslatable', 'extra_only');
    }
}

---
title: Hybrid and ExtraOnly Drivers
weight: 5
---

In addition to the standard JSON column storage, this package supports two additional storage strategies through a flexible driver system:

- **Hybrid Driver**: Stores the base locale in a plain column and other locales in a JSON column
- **ExtraOnly Driver**: Stores all locales (including base) only in a JSON column

These drivers are particularly useful when you need to optimize database queries for the primary language or when you want to keep the base translation separate from additional languages.

## Driver System Architecture

The package uses a driver-based architecture that allows different storage strategies for translatable attributes. Each translatable attribute can use a different driver.

### Available Drivers

1. **JSON Driver** (default) - All translations in a single JSON column
2. **Hybrid Driver** - Base locale in plain column + others in JSON
3. **ExtraOnly Driver** - All locales in JSON column only

## Using Hybrid Driver

The Hybrid driver stores your base locale translation in a regular database column (for direct SQL queries and indexing) while keeping additional locales in a JSON column.

### Setup

First, create the necessary database columns:

```php
Schema::table('articles', function (Blueprint $table) {
    $table->string('title')->nullable(); // For base locale
    $table->json('translations')->nullable(); // For other locales
});
```

### Model Configuration

There are three ways to configure the Hybrid driver:

#### 1. Using Casts (Recommended)

```php
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;
use Ixbtcom\Common\Casts\HybridTranslatable;

class Article extends Model
{
    use HasTranslations;

    protected $translatable = ['title', 'description'];

    protected $casts = [
        'title' => HybridTranslatable::class,
        'description' => HybridTranslatable::class,
    ];
}
```

#### 2. Using Explicit Driver Configuration

```php
class Article extends Model
{
    use HasTranslations;

    protected $translatable = [
        'title' => [
            'driver' => 'hybrid',
            'storageColumn' => 'translations',
            'baseLocale' => 'en',
        ],
    ];
}
```

#### 3. Using Model Constants

```php
class Article extends Model
{
    use HasTranslations;

    const EXTRA_JSON_COLUMN = 'translations';
    const BASE_LOCALE = 'en';

    protected $translatable = ['title'];

    protected $casts = [
        'title' => HybridTranslatable::class,
    ];
}
```

### How It Works

With the Hybrid driver:

```php
$article = new Article();
$article->setTranslation('title', 'en', 'Hello World');
$article->setTranslation('title', 'es', 'Hola Mundo');
$article->setTranslation('title', 'fr', 'Bonjour le Monde');
$article->save();
```

**Database storage:**
- `title` column: "Hello World" (base locale)
- `translations` column: `{"es": {"title": "Hola Mundo"}, "fr": {"title": "Bonjour le Monde"}}`

### Benefits

- **Better Performance**: Base locale queries are faster (no JSON extraction)
- **Database Indexing**: You can index the plain column for better search performance
- **SQL Friendly**: Direct WHERE clauses work on the base locale
- **Backward Compatible**: Works with existing queries that expect a plain column

## Using ExtraOnly Driver

The ExtraOnly driver stores all translations (including the base locale) in a JSON column. Unlike Hybrid, it doesn't use the plain column at all.

### Setup

```php
Schema::table('articles', function (Blueprint $table) {
    $table->json('translations')->nullable();
    // No plain column needed
});
```

### Model Configuration

#### Using Casts

```php
use Ixbtcom\Common\Casts\ExtraOnlyTranslatable;

class Article extends Model
{
    use HasTranslations;

    protected $translatable = ['subtitle', 'meta_description'];

    protected $casts = [
        'subtitle' => ExtraOnlyTranslatable::class,
        'meta_description' => ExtraOnlyTranslatable::class,
    ];
}
```

#### Using Explicit Configuration

```php
class Article extends Model
{
    use HasTranslations;

    protected $translatable = [
        'subtitle' => [
            'driver' => 'extra_only',
            'storageColumn' => 'translations',
        ],
    ];
}
```

### How It Works

```php
$article->setTranslation('subtitle', 'en', 'Base subtitle');
$article->setTranslation('subtitle', 'es', 'Subtítulo');
$article->save();
```

**Database storage:**
- `translations` column: `{"en": {"subtitle": "Base subtitle"}, "es": {"subtitle": "Subtítulo"}}`

### Benefits

- **Cleaner Schema**: No need for separate columns
- **Consistent Storage**: All translations in one place
- **Flexible**: Easy to add new locales without schema changes

## Mixed Driver Usage

You can use different drivers for different attributes in the same model:

```php
class Article extends Model
{
    use HasTranslations;

    protected $translatable = [
        'title',      // JSON driver (default)
        'seo_title',  // Hybrid driver
        'meta',       // ExtraOnly driver
    ];

    protected $casts = [
        'seo_title' => HybridTranslatable::class,
        'meta' => ExtraOnlyTranslatable::class,
    ];
}
```

## Configuration Options

### Storage Column

By default, Hybrid and ExtraOnly drivers use the `translations` column. You can customize this:

**Per-attribute:**
```php
protected $translatable = [
    'title' => [
        'driver' => 'hybrid',
        'storageColumn' => 'extra', // Custom column name
    ],
];
```

**Global (in config/common.php):**
```php
'translations' => [
    'storage_column' => 'translations', // Default for all models
]
```

**Model constant:**
```php
const EXTRA_JSON_COLUMN = 'custom_column';
```

### Base Locale

Configure the base locale for Hybrid driver:

**Per-attribute:**
```php
protected $translatable = [
    'title' => [
        'driver' => 'hybrid',
        'baseLocale' => 'ru',
    ],
];
```

**Global (in config/common.php):**
```php
'translations' => [
    'base_locale' => 'ru',
]
```

**Model constant:**
```php
const BASE_LOCALE = 'ru';
```

## Query Scopes

All query scopes work with Hybrid and ExtraOnly drivers:

### whereLocale

```php
// Hybrid: Checks plain column for base locale, JSON for others
Article::whereLocale('title', 'en')->get();

// ExtraOnly: Always checks JSON column
Article::whereLocale('subtitle', 'en')->get();
```

### whereLocales

```php
Article::whereLocales('title', ['en', 'es'])->get();
```

## Migration from JSON to Hybrid

If you want to migrate existing JSON translations to Hybrid storage:

### Step 1: Add Plain Column

```php
Schema::table('articles', function (Blueprint $table) {
    $table->string('title')->nullable()->after('id');
});
```

### Step 2: Migrate Data

```php
Article::chunk(100, function ($articles) {
    foreach ($articles as $article) {
        $translations = $article->getTranslations('title');
        $baseLocale = config('common.translations.base_locale', 'en');

        if (isset($translations[$baseLocale])) {
            $article->title = $translations[$baseLocale];
            unset($translations[$baseLocale]);

            $article->translations = array_merge(
                $article->translations ?? [],
                [$baseLocale => $translations]
            );

            $article->saveQuietly();
        }
    }
});
```

### Step 3: Update Model

```php
protected $translatable = ['title'];

protected $casts = [
    'title' => HybridTranslatable::class,
];
```

## Limitations

### Nested Keys

Nested JSON keys (e.g., `meta->description`) are **not supported** for Hybrid and ExtraOnly drivers. They work only with the default JSON driver.

**Won't work:**
```php
// With Hybrid/ExtraOnly driver
$model->setTranslation('meta->title', 'en', 'Value'); // Error!
```

**Works:**
```php
// With JSON driver
protected $translatable = ['meta'];
$model->setTranslation('meta->title', 'en', 'Value'); // OK
```

### Casts

The Hybrid and ExtraOnly drivers use custom cast classes. Don't add `'array'` cast for these attributes - the driver handles it automatically.

**Don't do this:**
```php
protected $casts = [
    'title' => HybridTranslatable::class,
    'title' => 'array', // ❌ Wrong! Driver handles this
];
```

## Custom Drivers

You can create your own translation drivers by implementing the `TranslationDriver` interface:

```php
use Spatie\Translatable\Contracts\TranslationDriver;

class RedisCachedDriver implements TranslationDriver
{
    // Implement required methods
}
```

Register your driver in a service provider:

```php
use Spatie\Translatable\Facades\Translatable;

public function boot()
{
    Translatable::extendDrivers(function ($registry) {
        $registry->register('redis', RedisCachedDriver::class);
        $registry->registerCast(CustomCast::class, 'redis');
    });
}
```

Use in your model:

```php
protected $translatable = [
    'frequently_accessed' => ['driver' => 'redis'],
];
```

## Compatibility with Filament

Hybrid and ExtraOnly drivers are fully compatible with Filament and other packages that use the standard `HasTranslations` API. No special configuration is needed.

```php
use Filament\Forms\Components\TextInput;

TextInput::make('title')
    ->required()
    ->translateLabel(); // Works with all driver types
```

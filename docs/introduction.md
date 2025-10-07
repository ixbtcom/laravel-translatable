---
title: Introduction
weight: 1
---

> **Note:** This is an extended fork of the original [spatie/laravel-translatable](https://github.com/spatie/laravel-translatable) package with additional storage strategies and driver system support.

This package contains a trait to make Eloquent models translatable. Translations can be stored as JSON using multiple storage strategies through a flexible driver system. There is no extra table needed to hold them.

## Storage Strategies

The package supports three storage drivers:

- **JSON Driver** (default): All translations in a single JSON column - original Spatie behavior
- **Hybrid Driver**: Base locale in a plain column + other locales in JSON for better query performance
- **ExtraOnly Driver**: All locales stored only in JSON column with consistent structure

See [Hybrid and ExtraOnly Drivers](advanced-usage/hybrid-and-extra-only-drivers.md) for detailed information.

## Basic Usage

Once the trait is installed on the model you can do these things:

```php
$newsItem = new NewsItem(); // This is an Eloquent model
$newsItem
   ->setTranslation('name', 'en', 'Name in English')
   ->setTranslation('name', 'nl', 'Naam in het Nederlands')
   ->save();

$newsItem->name; // Returns 'Name in English' given that the current app locale is 'en'
$newsItem->getTranslation('name', 'nl'); // returns 'Naam in het Nederlands'

app()->setLocale('nl');

$newsItem->name; // Returns 'Naam in het Nederlands'
```


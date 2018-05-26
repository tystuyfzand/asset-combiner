Asset Combiner for Laravel
==========================

[OctoberCMS'](https://octobercms.com) Asset Combiner for Laravel, with a few extra features.

Why
---

Compiling LESS/SASS/JS is a pain, especially during development. In many projects I found that this feature of OctoberCMS simplified a lot of the hassle.

What changed
------------

Compiled scripts/styles can be uploaded to storage, allowing use from a CDN or local storage.

Support for other forms of caching and output are planned, along with many more features.

Installing
----------

Install the package:

```
composer require tystuyfzand/assetcombiner
```

If you intend to use the `controller` option for generating files, register the route:

```
Route::get('/combine/{file}', 'AssetCombiner\Controllers\CombinerController');
```

Publis the config:

```
php artisan vendor:publish --provider="AssetCombiner\\AssetCombinerServiceProvider"
```

Using
-----

In your code or template, you can combine a list of files:

```php
CombineAssets::combine([ 'assets/less/file.less' ], resource_path());
```

Example:

```html
<link rel="stylesheet" href="{{ CombineAssets::combine([ 'assets/less/file.less' ], resource_path()) }}">
```

License
-------

To maintain compatibility with OctoberCMS and the code borrowed/forked from the library, this library is licensed under the MIT license.
# Laravel Rate Limiter

[![Latest Stable Version](https://poser.pugx.org/aporat/laravel-rate-limiter/version.png)](https://packagist.org/packages/aporat/laravel-rate-limiter)
[![Composer Downloads](https://poser.pugx.org/aporat/laravel-rate-limiter/d/total.png)](https://packagist.org/packages/aporat/laravel-rate-limiter)
[![Build Status](https://github.com/aporat/laravel-rate-limiter/workflows/Tests/badge.svg)](https://github.com/aporat/laravel-rate-limiter/actions)
[![Code Coverage](https://scrutinizer-ci.com/g/aporat/laravel-rate-limiter/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/aporat/laravel-rate-limiter/?branch=master)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/aporat/laravel-rate-limiter/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/aporat/laravel-rate-limiter/?branch=master)

Request and actions rate limiter middleware for Laravel and Lumen

## Installation

The rate-limiter service provider can be installed via [Composer](https://getcomposer.org/).

```
composer require aporat/laravel-rate-limiter
```

To use the RateLimiter service provider, you must register the provider when bootstrapping your application.

### Laravel

#### Laravel 5.5 and above

The package will automatically register provider and facade.

#### Laravel 5.4 and below

Add `Aporat\RateLimiter\RateLimiterServiceProvider` to the `providers` section of your `config/app.php`:

```php
    'providers' => [
        // ...
        Aporat\RateLimiter\RateLimiterServiceProvider::class,
    ];
```

Add RateLimiter facade to the `aliases` section of your `config/app.php`:

```php
    'aliases' => [
        // ...
        'RateLimiter' => Aporat\RateLimiter\Facade\RateLimiter::class,
    ];
```

Or use the facade class directly:

```php
  use Aporat\RateLimiter\Facade\RateLimiter;
```

Now run `php artisan vendor:publish` to publish `config/rate-limiter.php` file in your config directory.

#### Lumen

Register the `Aporat\RateLimiter\RateLimiterServiceProvider` in your `bootstrap/app.php`:

```php
    $app->register(Aporat\RateLimiter\RateLimiterServiceProvider::class);
```

Copy the `rate-limiter.php` config file in to your project:

```
    mkdir config
    cp vendor/aporat/laravel-rate-limiter/Config/rate-limiter.php config/rate-limiter.php
```


## Configuration

## Usage


## Credits

- [Adar Porat][link-author]
- [All Contributors][link-contributors]

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

[ico-version]: https://img.shields.io/packagist/v/aporat/laravel-rate-limiter.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-build]: https://img.shields.io/travis/aporat/laravel-rate-limiter/master.svg?style=flat-square
[ico-scrutinizer]: https://img.shields.io/scrutinizer/coverage/g/aporat/laravel-rate-limiter.svg?style=flat-square
[ico-code-quality]: https://img.shields.io/scrutinizer/g/aporat/laravel-rate-limiter.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/aporat/laravel-rate-limiter.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/aporat/laravel-rate-limiter
[link-travis]: https://travis-ci.org/aporat/laravel-rate-limiter
[link-scrutinizer]: https://scrutinizer-ci.com/g/aporat/laravel-rate-limiter/code-structure
[link-code-quality]: https://scrutinizer-ci.com/g/aporat/laravel-rate-limiter
[link-downloads]: https://packagist.org/packages/aporat/laravel-rate-limiter
[link-author]: https://github.com/aporat
[link-contributors]: ../../contributors

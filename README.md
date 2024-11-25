# Laravel Rate Limiter

[![codecov](https://codecov.io/gh/aporat/laravel-rate-limiter/graph/badge.svg?token=N2077WRCUD)](https://codecov.io/gh/aporat/laravel-rate-limiter)
[![StyleCI](https://github.styleci.io/repos/289521601/shield?branch=master)](https://github.styleci.io/repos/289521601?branch=master)
[![Latest Version](http://img.shields.io/packagist/v/aporat/laravel-rate-limiter.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-rate-limiter)
[![Latest Dev Version](https://img.shields.io/packagist/vpre/aporat/laravel-rate-limiter.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-rate-limiter#dev-develop)
[![Monthly Downloads](https://img.shields.io/packagist/dm/aporat/laravel-rate-limiter.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-rate-limiter)

Request and actions rate limiter middleware for Laravel and Lumen

## Installation

The rate-limiter service provider can be installed via [Composer](https://getcomposer.org/).

```
composer require aporat/laravel-rate-limiter
```

To use the RateLimiter service provider, you must register the provider when bootstrapping your application.


Copy the `rate-limiter.php` config file in to your project:

```
    mkdir config
    cp vendor/aporat/laravel-rate-limiter/Config/rate-limiter.php config/rate-limiter.php
```


## Configuration

## Usage


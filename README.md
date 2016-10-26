# Logger - LogManager for Monolog PHP

[![Total Downloads](https://img.shields.io/packagist/dt/dfar/logger.svg)](https://packagist.org/packages/dfar/logger)
[![Latest Stable Version](https://img.shields.io/packagist/v/dfar/logger.svg)](https://packagist.org/packages/dfar/logger)

AutoLoagger wraps composer loader and does initialization of all public static class properties with name `$LOGGER`.

AutoLoagger adds calling `__afterload` method after load class by composer autoloader.

LogManager can be configured with `logger.property` file placed by default in `/resourses` directory.

## Basic Usage

```php
<?php
use Logger\AutoLoagger;

$composerLoader = require 'vendor/autoload.php';
AutoLoagger::wrapLoader ($composerLoader);
```

### Sample class with $LOGGER:
TestLogger will be initialyzed with logger instance after class loading.
```php
<?php
use \Monolog\Logger;

class TestLogger {
	/**
	 * @var Logger
	 */
	public static $LOGGER;

	public static function logDebug($param) {
		self::$LOGGER->debug("this is debug message '$param'");
	}
	public static function logInfo($param) {
		self::$LOGGER->info("this is info message '$param'");
	}
	public static function __afterload() {
 		self::$LOGGER->info("autoload works!");
 		self::$autoloadCnt++;
	}
}
```

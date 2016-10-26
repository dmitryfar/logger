<?php
namespace Logger;

class AutoLoagger {
	private static $loader;
	private static $composerLoader;

	public static function loadClassLoader($class) {
		if ('Logger\AutoLoagger' === $class) {
			require __DIR__ . '/AutoLoagger.php';
		}
	}
	public static function wrapLoader($composerLoader) {
		if (null !== self::$loader) {
			return self::$loader;
		}

		self::$loader = new AutoLoagger();

		self::$composerLoader = $composerLoader;
		self::$composerLoader->unregister();

		$prepend = true;

		spl_autoload_register(array(self::$loader, 'loadClass'), true, $prepend);

		return self::$loader;
	}

	public function loadClass($class) {
		$res = self::$composerLoader->loadClass($class);

		// check logger for init

		$vars = get_class_vars($class);
		if ($vars !== false) {
			$keys = array_keys($vars);
			$reflectionClass = null;
			if (in_array("LOGGER", $keys)) {
				$reflectionClass = new \ReflectionClass($class);
				$namespace = $reflectionClass->getNamespaceName();
				$logger = LogManager::getLogger($class, $namespace);
				$reflectionClass->setStaticPropertyValue("LOGGER", $logger);
			}
		}

		// call __afterload
		$methods = get_class_methods($class);
		if ($methods !== null && in_array("__afterload", $methods)) {
			$reflectionClass = new \ReflectionClass($class);

			$method = $reflectionClass->getMethod("__afterload");
			if ($method->isStatic()) {
				call_user_func($class.'::__afterload');
			}
		}
	}

}
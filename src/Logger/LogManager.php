<?php
namespace Logger;
use \Logger\LogConfigurer;
use Monolog\Logger;

class LogManager {

	/**
	 *
	 * @var Logger
	 */
	public static $LOGGER;

	private static $logConfigurer;
	private static $defaultLogger;
	private static $loggers = array();


	public static function getConfigurer() {
		if (!self::$logConfigurer) {
			self::$logConfigurer = new LogConfigurer ();
		}
		return self::$logConfigurer;
	}

	public static function loadConfiguration($propertiesPath) {
		self::$logConfigurer = new LogConfigurer($propertiesPath);
	}

	/**
	 * Creates logger for each class defined in logger.properties with own level.
	 * Returns default logger if no custom level defined for the class.
	 * @param string $fullClassName
	 */
	public static function getLogger($fullClassName = null, $namespace = null) {

		if (self::$LOGGER == null) {
			self::$LOGGER = self::createLogger( $fullClassName);
		}
		self::$LOGGER->trace("get logger fullClassName: $fullClassName");

		if ($fullClassName != null) {
			$logger = null;
			if (array_key_exists($fullClassName, self::$loggers)) {
				$logger = self::$loggers[$fullClassName];
			}
			if ($logger != null) {
				return $logger;
			}

			if ($namespace == null) {
				$clazz = getReflectionClass($fullClassName);
				$namespace = $clazz->getNamespaceName();
			}

			$logConfigurer = self::getConfigurer();
			$classNamespaceLevel = $logConfigurer->getClassNamespaceLevel($namespace, $fullClassName);
			if ($classNamespaceLevel == null) {
				return self::getDefaultLogger();
			}

			$handlers = $logConfigurer->makeHandlers($fullClassName, $classNamespaceLevel);
			$logger = self::createLogger( $fullClassName, $handlers);
			self::$loggers[$fullClassName] = $logger;
			return self::$loggers[$fullClassName];
		} else {
			return self::getDefaultLogger();
		}
	}

	private static function getDefaultLogger() {
		if (self::$defaultLogger == null) {
			/**
			 * @var LogConfigurer
			 */
			$logConfigurer = self::getConfigurer();
			self::$defaultLogger = self::createLogger( "defaultLogger", $logConfigurer->getHandlerInstances());
		}
		return self::$defaultLogger;
	}

	private static  function createLogger($name, $handlers = null, $processors = null) {
		if (!$handlers) {
			$handlers = array();
		}
		if (!$processors) {
			$processors = array();
		}
		return new \Monolog\Logger ( $name, $handlers, $processors);
	}
}
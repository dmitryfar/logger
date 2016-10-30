<?php
namespace Logger;
use \Properties\Properties;

class LogConfigurer {
	const DEFAULT_ROOTLOGGER_LEVEL = \Monolog\Logger::INFO;

	private $propertiesPath;
	public $properties;
	private $rootloggerLevel;
	private $handlersNames = [];
	private $handlerInstances = [];

	public function __construct($propertiesPath = NULL) {
		$defaultLoggerPropertiesPath = $_SERVER['DOCUMENT_ROOT'] . "/resources/logger.properties";
		$this->propertiesPath = ($propertiesPath != null) ? $propertiesPath : $defaultLoggerPropertiesPath;

		$this->initProperties();
		$this->initHandlers();
	}

	private function initProperties() {
		if ($this->properties == null) {
			$this->properties = Properties::read ( $this->propertiesPath, "logger" );

			$this->rootloggerLevel = $this->_getRootloggerLevel();
		}
	}

	/**
	 * Parse parameter value, change to use parameters in logger, i.e. log levels
	 * @param string $value
	 */
	private function parseParameterValue($handlerSection, $value) {
		$levels = \Monolog\Logger::getLevels();
		if (array_key_exists($value, $levels)) {
			return $levels[$value];
		}
		$rootLoggerName = $this->properties->getProperty("rootLogger.name");
		$fileDatetimeFormat = $this->properties->getSection($handlerSection, "logger.$handlerSection.file.datetimeFormat");
		$date = ($fileDatetimeFormat) ? date($fileDatetimeFormat) : "Y-m-d";
		$pattern = [
				"%module%",
				"%datetime%"
		];
		$replacement = [
			$rootLoggerName,
			$date
		];
		$value = str_replace($pattern, $replacement, $value);

		return $value;
	}

	private function initHandlers() {
		if ($this->properties == null) {
			return;
		}

		$this->handlersNames = $this->getAllHandlersNames();

		$this->handlerInstances = $this->makeHandlers();
	}

	/**
	 * Returns all defined handlers names
	 */
	private function getAllHandlersNames() {
		$names = array();
		foreach ($this->properties->properties as $handlerSection => $value) {
			if (!is_array($value)) {
				continue;
			}
			$names[] = $handlerSection;
		}
		return $names;
	}

	public function makeHandlers($class = null, $classNamespaceLevel = null) {
		$handlers = array();

		$availableHanlerNames = $this->getAvailableHandlerNames($class);

		foreach ($availableHanlerNames as $handlerSection) {
			$handlerParameters = $this->properties->getSection($handlerSection);

			$handlerClassName = $this->properties->getSection($handlerSection, "logger.$handlerSection.handlerLoader");

			$handler = $this->instantiateClass($handlerSection, "logger.$handlerSection.handlerLoader", "logger.$handlerSection", function($constructorParameter, $constructorValue, $defaultConstructorValue) use ($handlerSection, $classNamespaceLevel) {
				$value = null;
				if ($constructorParameter == "level") {
					if ($classNamespaceLevel != null) {
						$value = $classNamespaceLevel;
					} else if ($constructorValue == null) {
						// set rootlogger level if not is set for handler
						$value = $this->rootloggerLevel;
					}
				} else {
					$value = ($constructorValue != null) ?
					$constructorValue = $this->parseParameterValue($handlerSection, $constructorValue) :
					$defaultConstructorValue;
				}

				return $value;
			});

			if ($handler != null) {
				// add rootLogger processor if exists
				$rootLoggerPocessor = $this->properties->getProperty("rootLogger.processor");
				$this->pushProcessorToHandler($handlerSection, $handler, $rootLoggerPocessor, $class);

				// get processors for log handler
				$processor = $this->properties->getSection($handlerSection, "logger.$handlerSection.processor");
				$this->pushProcessorToHandler($handlerSection, $handler, $processor, $class);

				// make formatter for handler

				$formatter = $this->instantiateClass($handlerSection, "logger.$handlerSection.formatter", "logger.$handlerSection.formatter", function($parameterName, $parameterValue) {
					// new line parser
					if ($parameterValue != null && is_string($parameterValue)) {
						$parameterValue = preg_replace("/\\\\n$/", "\n", $parameterValue);
					}
					return $parameterValue;
				});
				if ($formatter != null) {
					$handler->setFormatter($formatter);
				}

				$handlers[$handlerSection] = $handler;
			}
		}
		return $handlers;
	}

	private function pushProcessorToHandler($handlerSection, $handler, $processor, $class) {
		if ($processor != null && $handler instanceof \Monolog\Handler\HandlerInterface) {

			// check if processor class exists
			$accessorPos = strpos($processor, "::");
			if ($accessorPos >= 0) {
				$processorClass = substr($processor, 0, $accessorPos);
			} else {
				$processorClass = $processor;
			}
			if (class_exists($processorClass, TRUE)) {
				$processorCallback = new LogConfigurer\LogProcessorCallback($handlerSection, $processor, $class);
				$handler->pushProcessor($processorCallback);
			}
		}
	}

	/**
	 * Get available handler names for the class.
	 * If no class defined then get rootLogger available handler names;
	 * @param unknown $class
	 */
	private function getAvailableHandlerNames($className = null) {
		$rootHandlers = $this->properties->getProperty("rootLogger.handlers");
		if ($className) {
			$clazz = new \ReflectionClass($className);
			$namespace = $clazz->getNamespaceName();

			if ($namespace != null) {
				$namespaceHandlers = $this->properties->getProperty("logger." . $namespace . ".handlers");
			}
			$classHandlers = $this->properties->getProperty("logger." . $className . ".handlers");

			$handlers = ($classHandlers != null) ? $classHandlers : (($namespaceHandlers != null) ? $namespaceHandlers : $rootHandlers);
		} else {
			$handlers = $rootHandlers;
		}

		// get all available handler names or something defined
		$names = $this->handlersNames;
		if ($handlers != null) {
			// $handlers = preg_replace("//", $replacement, $subject)
			$array = explode(",", $handlers);
			$names = array_map("trim", $array);
		}
		return $names;
	}

	private function instantiateClass($sectionName, $propertyClassName, $propertyConstructorPrefix, $argumentProcessor = null) {
		$className = $this->properties->getSection($sectionName, $propertyClassName);
		$instance = null;
		if ($className) {
			$clazz = new \ReflectionClass($className);

			$constructor = $clazz->getConstructor();
			/**
			 * @var ReflectionParameter $parameters
			 */
			$parameters = null;
			if ($constructor) {
				$parameters = $constructor->getParameters();
			}
			$constructorArgs = [];
			if ($parameters) {
				foreach ($parameters as $parameter) {
					$defaultValue = null;
					if ($parameter->isDefaultValueAvailable()) {
						$defaultValue = $parameter->getDefaultValue();
					}
					$parameterValue = $this->properties->getSection($sectionName, "$propertyConstructorPrefix.constructor.{$parameter->getName()}");

					if ($argumentProcessor != null && is_callable($argumentProcessor)) {
						$parameterValue = call_user_func_array($argumentProcessor, array($parameter->getName(), $parameterValue, $defaultValue));
					} else {
						$parameterValue = ($parameterValue != null) ?
						$parameterValue = $this->parseParameterValue($sectionName, $parameterValue) :
						$defaultValue;
					}
					$constructorArgs[$parameter->getName()] = $parameterValue;
				}
				$instance = $clazz->newInstanceArgs($constructorArgs);
			} else {
				$instance = $clazz->newInstanceWithoutConstructor();
			}
		}
		return $instance;
	}

	/**
	 * Get rootlogger level.
	 * If level is undefined in properties file then return default level
	 */
	private function _getRootloggerLevel() {
		$rootloggerLevel = $this->properties->getProperty ( "rootLogger" );
		if ($rootloggerLevel == null) {
			return self::DEFAULT_ROOTLOGGER_LEVEL;
		}
		$levels = \Monolog\Logger::getLevels ();
		return $levels [$rootloggerLevel];
	}

	public function getRootloggerLevel() {
		return $this->rootloggerLevel;
	}

	public function getHandlerInstances() {
		return $this->handlerInstances;
	}

	public function getClassLevel($class) {
		$classLevel = $this->properties->getProperty ( "logger." . $class );
		if ($classLevel == null) {
			return null;
		}
		$levels = \Monolog\Logger::getLevels ();
		return $levels [$classLevel];
	}

	public function getClassNamespaceLevel($namespace, $fullClassName) {
		if ($namespace != null) {
			$namespaceLevelCode = $this->getClassLevel ( $namespace );
		}
		$classLevelCode = $this->getClassLevel ( $fullClassName );

		$configClassNamespaceLevelCode = ($classLevelCode != null) ? $classLevelCode : $namespaceLevelCode;
		return $configClassNamespaceLevelCode;
	}
}

namespace Logger\LogConfigurer;
class LogProcessorCallback {
	private $handlerSection;
	private $processor;
	private $class;
	public function __construct($handlerSection, $processor, $class = null) {
		$this->handlerSection = $handlerSection;
		$this->processor = $processor;
		$this->class = $class;
	}
	public function __invoke ($record) {
		$record["handler"] = $this->handlerSection;
		if ($this->class) {
			$record["class"] = $this->class;
		}
		$record["logContext"] = $this->getTraceContext();

		return call_user_func($this->processor, $record);
	}
	private function getTraceContext() {
		$trace = debug_backtrace (DEBUG_BACKTRACE_IGNORE_ARGS);
		$traceContext = array();

		$classTraceIndex = 7;
		$countTrace = count($trace);
		if ($countTrace == 7) {
			$classTraceIndex = 6;
		}
		$traceContext = $trace[$classTraceIndex];
		$idx = $classTraceIndex;
		if ($countTrace != 7) {
			$idx = $classTraceIndex - 1;
		} else {
			unset($traceContext["class"]);
			unset($traceContext["type"]);
		}
		$traceContext["line"] = $trace[$idx]["line"];
		$traceContext["file"] = $trace[$idx]["file"];
		return $traceContext;
	}
}

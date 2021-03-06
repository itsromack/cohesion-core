<?php
namespace Cohesion\Structure\Factory;

use \Cohesion\Auth\Auth;
use \Cohesion\Config\Config;
use \ReflectionClass;

class ServiceFactory extends AbstractFactory {

    protected $config;
    protected $user;

    protected $daoFactory;
    protected $services;
    protected $cyclicDependancies;

    const SERVICE_CONFIG_SECTION = 'application';
    const UTILITY_CONFIG_SECTION = 'utility';
    const DATA_ACCESS_CONFIG_SECTION = 'data_access';
    const DEFAULT_SERVICE_PREFIX = '';
    const DEFAULT_SERVICE_SUFFIX = 'Service';
    const DEFAULT_DATA_ACCESS_PREFIX = '';
    const DEFAULT_DATA_ACCESS_SUFFIX = 'DAO';

    public function __construct(DataAccessFactory $daoFactory, Config $config = null, $user = null) {
        $this->daoFactory = $daoFactory;
        $this->config = $config;
        if ($config) {
            $utilConfig = $config->getConfig(static::UTILITY_CONFIG_SECTION);
        } else {
            $utilConfig = null;
        }
        $this->utilFactory = new UtilityFactory($utilConfig);
        $this->user = $user;
        $this->services = array();
        $this->cyclicDependancies = array();
    }

    public function get($class) {
        if (isset($this->services[$class])) {
            return $this->services[$class];
        }
        if (!class_exists($class)) {
            throw new InvalidClassException("$class doesn't exist");
        }
        $reflection = new ReflectionClass($class);
        $params = $this->getConstructor($reflection)->getParameters();
        $values = array();
        foreach ($params as $i => $param) {
            unset($value);
            // Config parameter
            if (($param->getClass() && $param->getClass()->getName() == 'Cohesion\\Config\\Config') || $param->getName() === 'config') {
                if (!$this->config) {
                    if (!$param->isOptional()) {
                        throw new LogicException('No configuration is available and is required by ' . $param->getName());
                    } else {
                        $value = $param->getDefaultValue();
                    }
                } else {
                    $value = $this->config->getConfig(static::SERVICE_CONFIG_SECTION);
                }
            // ServiceFactory parameter
            } else if ($param->getClass() && ($param->getClass()->getName() == 'Cohesion\\Structure\\Factory\\ServiceFactory' || $param->getClass()->isSubclassOf('Cohesion\\Structure\\Factory\\ServiceFactory'))) {
                $value = $this;
            // abstract DAO parameter
            } else if (($param->getClass() && ($param->getClass()->getName() == 'Cohesion\\Structure\\DAO' || ($param->getClass()->isSubclassOf('Cohesion\\Structure\\DAO') && !$param->getClass()->isInstantiable()))) || $param->getName() === 'dao') {
                $value = $this->getServiceDAO($class);
            // DAO instance parameter
            } else if ($param->getClass() && $param->getClass()->isSubclassOf('Cohesion\\Structure\\DAO')) {
                $value = $this->daoFactory->get($param->getClass()->getName());
            // Service parameter
            } else if ($param->getClass() && $param->getClass()->isSubclassOf('Cohesion\\Structure\\Service')) {
                if (in_array($param->getClass()->getName(), $this->cyclicDependancies)) {
                    $dependancies = implode(' -> ', $this->cyclicDependancies) . ' -> ' . $param->getClass()->getName();
                    $this->cyclicDependancies = array();
                    throw new CyclicDependancyException("Cyclic dependancy discovered while loading $dependancies");
                }
                $this->cyclicDependancies[] = $param->getClass()->getName();
                $value = $this->get($param->getClass()->getName());
                array_pop($this->cyclicDependancies);
            // User parameter
            } else if ($this->user && ($param->getClass() && $param->getClass()->isSubclassOf('Cohesion\\Auth\\User')) || $param->getName() === 'user') {
                $value = $this->user;
            // Default, try to instaciate it as a utility
            } else if ($param->getClass() && $this->utilFactory) {
                try {
                    $util = $this->getUtil($param->getClass());
                    if ($util) {
                        $value = $util;
                    }
                } catch (InvalidClassException $e) {
                    throw new InvalidPropertyException('Invalid service property ' . $param->getName(), 0, $e);
                }
            }
            if (isset($value)) {
                $values[$i] = $value;
            } else if ($param->isOptional()) {
                $values[$i] = $param->getDefaultValue();
            } else {
                throw new InvalidPropertyException('Invalid Service property ' . $param->getName());
            }
        }
        $service = $reflection->newInstanceArgs($values);
        if ($this->user && $reflection->hasMethod('setUser')) {
            $service->setUser($this->user);
        }
        $this->services[$class] = $service;
        return $service;
    }

    public function setUser($user) {
        $this->user = $user;
    }

    public function getUtil($className) {
        if (!$this->utilFactory) {
            throw new LogicException("Util factory hasn't been initialized");
        }
        return $this->utilFactory->get($className);
    }

    private function getServiceDAO($serviceClass) {
        $servicePrefix = $this->config ? $this->config->get(static::SERVICE_CONFIG_SECTION . '.class.prefix') : static::DEFAULT_SERVICE_PREFIX;
        $serviceSuffix = $this->config ? $this->config->get(static::SERVICE_CONFIG_SECTION . '.class.suffix') : static::DEFAULT_SERVICE_SUFFIX;
        $name = preg_replace(["/^$servicePrefix/", "/$serviceSuffix$/"], '', $serviceClass);
        $daoPrefix = $this->config ? $this->config->get(static::DATA_ACCESS_CONFIG_SECTION . '.class.prefix') : static::DEFAULT_DATA_ACCESS_PREFIX;
        $daoSuffix = $this->config ? $this->config->get(static::DATA_ACCESS_CONFIG_SECTION . '.class.suffix') : static::DEFAULT_DATA_ACCESS_SUFFIX;
        $class = $daoPrefix . $name . $daoSuffix;
        if (!class_exists($class)) {
            throw new InvalidClassException("$class does not exist");
        }
        return $this->daoFactory->get($class);
    }
}

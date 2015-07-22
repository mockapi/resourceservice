<?php

namespace Mockapi\ResourceService;

use \Mockapi\Interfaces\ResourceServiceInterface;
use \Mockapi\Interfaces\ResourceServiceFactoryInterface;

use \Mockapi\Validate\Validate;
use \Exception;

class ResourceServiceFactory implements ResourceServiceFactoryInterface
{
    protected $defaultResourceServiceClass = null;
    protected $defaultResourceServiceProvider = null;
    protected $defaultResourceServiceEndpoint = '/';

    protected $services = [];
    protected $strict = true;

    /**
     * Factory constructor
     *
     * Example:
     *
     * ```
     * $services = [
     *   // Specific service
     *   'messages' => new Mockapi\ResourceService\FlatFileImplementation('messages', [
     *     'type' => 'messages',
     *     'root' => './storage/',
     *     'serializer' => new \Mockapi\ResourceService\FlatFileImplementation\YamlSerializer
     *   ]),
     *   // Default service to create any possible ResourceService
     *   ['Mockapi\ResourceService\FlatFileImplementation', [
     *     'root' => './storage/',
     *     'serializer' => new \Mockapi\ResourceService\FlatFileImplementation\YamlSerializer
     *   ]]
     * ];
     *
     * $factory = new Factory($services);
     *
     * // Most flexible Factory:
     * $factory = new Factory([['Mockapi\ResourceService\FlatFileImplementation', [
     *     'root' => './storage/',
     *     'serializer' => new \Mockapi\ResourceService\FlatFileImplementation\YamlSerializer
     *   ]]);
     * ```
     *
     * @param array $services
     * @returns void
     *
     */

    public static function validateServiceClass($v, $description = '')
    {
        Validate::isNonEmptyString($v, ucfirst($description));

        if (!class_exists($v)) {
            throw new Exception("[Resource Service Factory service Error][DEV]: Missing {$description} `{$v}`");
        }

        if (!in_array('Mockapi\Interfaces\ResourceServiceInterface', class_implements($v))) {
            throw new Exception("[Resource Service Factory service Error][DEV]: Service `$v` must implement ResourceServiceInterface");
        }

        return true;
    }

    public static function validateResourceProvider($v, $description)
    {
        if (is_object($v) && $v instanceof \Mockapi\ResourceProvider\ResourceProviderFactory) {
            return true;
        } elseif /* Lazyloaders */ (
            is_array($v) &&
            count($v) === 2 &&
            is_array($v) &&
            in_array('Mockapi\Interfaces\ResourceProviderFactoryInterface', class_implements($v[0]))) {
            return true;
        }

        throw new Exception("{$description} must be array of arguments or Resource Provider Factory");
    }

    public function __construct(array $services = [])
    {
        // Look for defaults
        foreach ($services as $index => &$service) {
            if (is_numeric($index)) {
                if (is_array($service) && count($service) === 2 && isset($service[1]['provider'])) {
                    static::validateServiceClass($service[0], 'default resource service argument[0], class name,');
                    static::validateResourceProvider($service[1]['provider'], 'default resource service argument[1][provider]');

                    if (isset($service[1]['endpoint'])) {
                        Validate::isUrl($service[1]['endpoint'], 'default resource service argument[1][endpoint]');

                        $this->defaultResourceServiceEndpoint = rtrim($service[1]['endpoint'], '/');

                        if (is_array($service[1]['provider']) &&
                            isset($service[1]['provider'][1]) &&
                            isset($service[1]['provider'][1][0]) &&
                            isset($service[1]['provider'][1][0][1]) &&
                            !isset($service[1]['provider'][1][0][1]['endpoint'])
                            ) {
                            $service[1]['provider'][1][0][1]['endpoint'] = $this->defaultResourceServiceEndpoint;
                        }
                    }

                    $this->defaultResourceServiceClass = $service[0];
                    $this->defaultResourceServiceProvider = $service[1]['provider'];

                    if (is_array($this->defaultResourceServiceProvider) && count($this->defaultResourceServiceProvider) ===2) {
                        $this->defaultResourceServiceProvider = new $this->defaultResourceServiceProvider[0](
                            $this->defaultResourceServiceProvider[1]
                        );
                    }

                    unset($services[$index]);
                    $this->strict = false;
                }
            }
        }

        foreach ($services as $type => &$service) {
            if (is_numeric($type)) {
                // But we're whitelisting
                if ($this->defaultResourceServiceClass && $this->defaultResourceServiceProvider && Validate::isNonEmptyString($service, false)) {
                    $this->services[$service] = [
                        'class' => $this->defaultResourceServiceClass,
                        'resource' => $service,
                        'provider' => $this->defaultResourceServiceProvider
                    ];

                    // Set stict back and only whitelisted services will be factored
                    $this->strict = true;
                }

                continue;
            }

            if (is_object($service)) {
                if (!$service instanceof \Mockapi\Interfaces\ResourceServiceInterface) {
                    if ($this->defaultResourceServiceClass) {
                        if (!$service instanceof \Mockapi\Interfaces\ResourceProviderInterface) {
                            throw new Exception('Provider must inherit ResourceServiceInterface or ResourceProviderInterface when default class is set');
                        }

                        $this->services[$type] = [
                            'class' => $this->defaultResourceServiceClass,
                            'resource' => $type,
                            'provider' => $service
                        ];
                    } else {
                        throw new Exception('Provider must inherit ResourceServiceInterface if no default class is set');
                    }
                } else {
                    $this->services[$type] = $service;
                }
            } elseif (is_array($service) && count($service) === 2) { // Lazyloaders >>>
                static::validateServiceClass($service[0], '`$service[0]` in `$services`');
                static::validateResourceProvider($service[1], '`$service[0]` in `$services`');

                $this->services[$type] = $service;
            } elseif ($this->defaultResourceServiceProvider && Validate::isNonEmptyString($service, false)) { // Lazyloaders with default provider >>>
                static::validateServiceClass($service, '`$service` in `$services`');

                $this->services[$type] = [
                    'class' => $service,
                    'resource' => $type,
                    'provider' => $this->defaultResourceServiceProvider
                ];
            } else {
                throw new Exception('Service argument must be object or array');
            }
        }
    }

    public function index()
    {
        return [
            'endpoint' => $this->defaultResourceServiceEndpoint,
            'requests' => '/resource.type/resource.id/resource.attr',
            'methods'  => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
            'resources' => array_map(function($v) {
                return (object) [
                    'type' => $v,
                    'link' => $this->endpoint."/{$v}"
                ];
            }, array_keys($this->services))
        ];
    }

    public function get($type)
    {
        // Return if already set
        if (isset($this->services[$type]) && $this->services[$type] !== null && is_object($this->services[$type])) {
            return $this->services[$type];
        }

        if (!$this->strict || isset($this->services[$type])) {
            if (!isset($this->services[$type]) && $this->defaultResourceServiceClass && $this->defaultResourceServiceProvider) {
                $this->services[$type] = [
                    'class' => $this->defaultResourceServiceClass,
                    'resource' => $type,
                    'provider' => $this->defaultResourceServiceProvider
                ];
            }

            if (is_array($this->services[$type])) {
                $this->services[$type] = new $this->services[$type]['class']([
                    'resource' => $this->services[$type]['resource'],
                    'provider' => is_object($this->services[$type]['provider']) ? $this->services[$type]['provider'] : new $this->services[$type]['provider'][0](
                        array_merge(['type' => $type], $this->services[$type]['provider'][1])
                    )
                ]);
            }

            return $this->services[$type];
        }

        throw new Exception("Unable to instantiate Resource {$type} service");
    }
}

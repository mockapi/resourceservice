<?php

require_once '../vendor/autoload.php';

header('Content-Type: text/plain');

$endpoint = $_SERVER['REQUEST_URI'];

class UserResourceService extends \Mockapi\ResourceService\ResourceService
{}

try {
    echo "Creating resources router:";

    $resourcesProvidersFactory = new \Mockapi\ResourceProvider\Factory([
        ['\Mockapi\ResourceProvider\FlatFileImplementation',[
            'root' => dirname(dirname(__FILE__)).'/storage',
            'serializer' => new \Mockapi\ResourceProvider\FlatFileImplementation\YamlSerializer
        ]]
    ]);

    $resourceServicesFactory = new \Mockapi\ResourceService\Factory([
        // Default Resource Service factory
        [
            // Default Resource Service class name
            '\Mockapi\ResourceService\ResourceService',
            // Default Resource Service class constructor arguments
            [
                // 'type' => ':any:', // ommited
                'provider' => $resourcesProvidersFactory
                'endpoint' => $endpoint
            ]
        ],
        // Specific Resource service
        'users' => [
            // Specific Resource service class name
            'UserResourceService'/*,
            // Specific Resource service class constructor arguments
            // Ommitted to use default
            [
                'type' => 'users', // ommitted anyway, no need, the type is already set by the array key
                'provider' => $resourcesProvidersFactory
            ]*/
        ]
    ]
    );

    $resources = new \Mockapi\ResourceService\Factory('\Mockapi\ResourceProvider\FlatFileImplementation', [
        'root' => dirname(dirname(__FILE__)).'/storage',
        'serializer' => new \Mockapi\ResourceProvider\FlatFileImplementation\YamlSerializer
    ], $endpoint);
    print_r($resources);
} catch (Exception $e) {
    echo 'Error - '.$e->getMessage();
}

try {
    echo "Get messages resource:";
    $messages = $resources->get('messages');
    echo "Options: ";print_r($messages->options());
} catch (Exception $e) {
    echo 'Error - '.$e->getMessage();
}

echo "\nOK & Ready";

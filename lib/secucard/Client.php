<?php
/**
 * Api Client class
 */

namespace secucard;

use secucard\client\oauth\GrantType\ClientCredentials;
use secucard\client\oauth\GrantType\PasswordCredentials;
use secucard\client\oauth\GrantType\RefreshTokenCredentials;
use secucard\client\oauth\OauthProvider;
use secucard\client\log\Logger;
use secucard\client\log\GuzzleSubscriber;
use Psr\Log\LoggerInterface;
use secucard\client\storage\StorageInterface;
use secucard\client\storage\DummyStorage;

use GuzzleHttp\Collection;

/**
 * Secucard Api Client
 * Uses GuzzleHttp client library
 * @author Jakub Elias <j.elias@secupay.ag>
 */
class Client
{
    /**
     * Configuration array
     * @var array
     */
    protected $config;

    /**
     * GuzzleHttp client
     * @var object GuzzleHttp
     */
    protected $client;

    /**
     * Array that maps category names
     * @var array
     */
    protected $category_map;

    /**
     * Logger used for logging
     * @var \Psr\Log\LoggerInterface
     */
    public $logger;

    /**
     * Storage used to store authorization
     * @var client\storage\StorageInterface
     */
    public $storage;

    /**
     * Object to call when the push is called
     * @var mixed
     */
    protected $callback_push_object;

    /**
     * Api version
     * @var string
     */
    const VERSION = '0.0.1';

    const HTTP_STATUS_CODE_OK = 200;

    /**
     * Constructor
     * @param array $config - options to correctly initialize Guzzle Client
     * @param $logger - pass here LoggerInterface to use for logging
     * @param $storage - pass here StorageInterface for storing runtime data (like oauth-tokens)
     */
    public function __construct(array $config, $logger = null, $storage = null)
    {
        // array of base configuration
        $default = array(
            'base_url' => 'https://connect.secucard.com',
            'auth_path' => '/oauth/token',
            'api_path' => '/api/v2',
            'debug' => false,
        );

        // The following fields are required when creating the client
        $required = array(
            'base_url',
            'auth_path',
            'api_path',
            'client_id',
            'client_secret'
        );

        // Merge in default settings and validate the config
        $this->config = Collection::fromConfig($config, $default, $required);

        if ($this->config['debug']) {
            // Add HTTP-Requests to log
            $_logger = new GuzzleSubscriber($logger);
        } else {
            $_logger = $logger;
        }

        // Create a new Secucard client
        $this->client = new \GuzzleHttp\Client($this->config->toArray());

        // Create logger
        if ($_logger instanceof GuzzleSubscriber) {
            // add subscriber to guzzle
            $this->logger = $_logger->getLogger();
            $this->client->getEmitter()->attach($_logger);
        } elseif ($_logger instanceof LoggerInterface) {
            // initialize logger with a logger parameter
            $this->logger = $_logger;
        } else {
            // initialize default logger - with logging disabled
            $this->logger = new Logger(null, false);
        }

        // Create storage
        if ($storage instanceof StorageInterface) {
            $this->storage = $storage;
        } else {
            $this->storage = new DummyStorage();
        }

        // Ensure that the OauthProvider is attached to the client, when grant_type is not device
        // but only when authorization is needed
        $this->setAuthorization();
    }

    /**
     * Private function to set Authorization on client
     *
     */
    private function setAuthorization()
    {
        // conditions for client authorization types
        // specify $config['auth']['type'] = 'none', when you dont want the Client to use authorization (useful for authorization for devices)
        // specify $config['auth']['type'] = 'refresh_token' when you already have refresh token
        if ($this->config['auth']['type'] === 'none') {
            return;
        }

        // create credentials
        $client_credentials = new ClientCredentials($this->config['client_id'], $this->config['client_secret']);

        // default credentials are client_credentials
        $credentials = $client_credentials;
        // check which credentials should be added to the client auth provider
        if ($this->config['auth']['type'] === 'refresh_token') {
            $credentials = new RefreshTokenCredentials($this->config['auth']['refresh_token']);
        }

        // create OAuthProvider
        $oauthProvider = new OauthProvider($this->config['auth_path'], $this->client, $this->storage, $client_credentials, $credentials);
        // assign OAuthProvider to guzzle client
        $this->client->getEmitter()->attach($oauthProvider);
    }

    /**
     * Function to get device verification
     *
     * @param string $vendor
     * @param string $uid
     * @throws \Exception
     * @return array $response
     */
    public function obtainDeviceVerification($vendor, $uid)
    {
        $params = array(
            'grant_type' => 'device',
            'uuid' => $vendor . '/' . $uid,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
        );

        // if the guzzle gets response http_status other than 200, it will throw an exception even when there is response available
        try {
            $response = $this->client->post($this->config['auth_path'], array('body' => $params));
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->hasResponse()) {
                return $e->getResponse()->json();
            }
            throw $e;
        }

        return $response->json();
    }

    /**
     * Function to get access_token and refresh_token for device authorization
     *
     * @param string $device_code
     * @throws \Exception
     * @return array $response
     */
    public function pollDeviceAccessToken($device_code)
    {
        $params = array(
            'grant_type' => 'device',
            'code' => $device_code,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
        );

        try {
            $response = $this->client->post($this->config['auth_path'], array('body' => $params));
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->hasResponse()) {
                return $e->getResponse()->json();
            }
            throw $e;
        }

        return $response->json();
    }

    /**
     * Magic getter for getting the model object inside category
     *
     * uses lazy loading for categories
     * @param string $name
     * @return client\base\BaseModelCategory $category
     * @throws \Exception
     */
    public function __get($name)
    {
        // if the $name is property of current object
        if (isset($this->$name)) {
            return $this->$name;
        }
        if (isset($this->category_map[strtolower($name)])) {
            return $this->category_map[strtolower($name)];
        }
        $category = null;
        if (file_exists(__DIR__ . "/../../models/" . ucfirst($name) . "Category.php")) {
            // create subcategory if there is a class for it
            $category_class = '\\secucard\\models\\' . $name . 'Category';
            $category = new $category_class($this, $name);
        } else {
            // it is not checked if the category exists
            $category = new \secucard\client\base\BaseModelCategory($this, $name);
        }
        // create category inside category_map
        if ($category) {
            $this->category_map[strtolower($name)] = $category;
            return $category;
        }

        throw new \Exception('Invalid category name: ' . $name);
    }

    /**
     * Function to build URL to access API function
     * @param string $path
     * @return string $url
     */
    protected function buildApiUrl($path)
    {
        $url = $this->config['base_url'] . $this->config['api_path'] . "/" . $path;
        return $url;
    }

    /**
     * GET request method
     *
     * @param string $path path to call
     * @param array $options
     * @return false|$response
     */
    public function get($path, $options)
    {
        $options = array_merge(['auth' => 'oauth'], $options);
        $response = $this->client->get($this->buildApiUrl($path), $options);
        if (!$response) {
            return false;
        }

        return $response;
    }

    /**
     * DELETE function
     * used to delete data for model
     *
     * @param string $path (url path to the model)
     * @param array $data (empty array)
     * @param array $options
     * @return bool
     */
    public function delete($path, $data, $options)
    {
        $options = array_merge(['auth' => 'oauth', 'body' => $data], $options);
        $response = $this->client->delete($this->buildApiUrl($path), $options);
        if (!$response || $response->getStatusCode() != self::HTTP_STATUS_CODE_OK) {
            return false;
        }

        return true;
    }

    /**
     * POST json request method
     *
     * @param string $path path to call
     * @param mixed $data (data to post)
     * @param array $options
     * @return false|$response
     */
    public function post($path, $data, $options)
    {
        $options = array_merge(['auth' => 'oauth', 'json' => $data], $options);
        $response = $this->client->post($this->buildApiUrl($path), $options);
        if (!$response) {
            return false;
        }

        return $response;
    }

    /**
     * POST url_encoded request method
     *
     * @param string $path path to call
     * @param mixed $data
     * @param array $options
     * @return false|array $response
     */
    public function postUrlEncoded($path, $data, $options)
    {
        $options = array_merge(['auth' => 'oauth', 'body' => $data], $options);
        $response = $this->client->post($this->buildApiUrl($path), $options);
        if (!$response) {
            return false;
        }

        return json_decode($response, TRUE);
    }

    /**
     * PUT function with JSON body
     * used to update data for model
     *
     * @param string $path
     * @param array $data (data of model to update)
     * @param array $options
     * @return array $ret
     */
    public function put($path, $data, $options)
    {
        $options = array_merge(['auth' => 'oauth', 'json' => $data], $options);
        $response = $this->client->put($this->buildApiUrl($path), $options);
        if (!$response || $response->getStatusCode() != self::HTTP_STATUS_CODE_OK) {
            return false;
        }

        return json_decode($response, TRUE);
    }

    /**
     * Function to register callback object
     * @param mixed $callable
     */
    public function registerCallbackObject($callable)
    {
        $this->callback_push_object = $callable;
    }

    /**
     * Function that will be called to process Push request from API
     *
     * @param array $get
     * @param array $post
     * @param object $postRaw
     * @throws \Exception
     */
    public function processPush($get = null, $post = null, $postRaw = null)
    {
        // GET
        if (!$get) {
            $get = $_GET;
        }

        // POST
        if (!$post) {
            $post = $_POST;
        }

        $post_data = null;
        // POST-RAW
        if (!$postRaw) {
            $postRaw = @file_get_contents('php://input');
            $post_data = json_decode($postRaw);
        } else {
            $post_data = $postRaw;
        }

        $this->logger->info('Received Push with Posted data: ' . json_encode($post_data));

        $referenced_objects = [];

        if ($post_data && $post_data->object) {
            // process the post_data
            $event_info = $post_data->object;   // normally 'event.pushes'
            $event_id = $post_data->id;         // event_id

            $event_action = $post_data->type;
            if ($event_action == 'deleted') {
                return;
            }

            $event_data = $post_data->data;
            if (empty($event_data) || !is_array($event_data)) {
                throw new \Exception('Invalid empty or not array event[data] field from post_data: ' . json_encode($post_data));
            }

            foreach ($event_data as $event_object) {
                if (empty($event_object)) {
                    throw new \Exception('Invalid empty event object in post_data: ' . json_encode($post_data));
                }
                if (is_array($event_object)) {
                    // cast $event_object to object
                    $event_object = json_decode(json_encode($event_object), false);
                }
                // get the category and model from $event_object, the category and model delimiter is '.' or '/'
                $model_info = explode('.', $event_object->object);
                if (count($model_info) <= 1) {
                    $model_info = explode('/', $event_object->object);
                }

                $object_id = $event_object->id;
                $category = strtolower($model_info[0]);
                $model = strtolower($model_info[1]);
                if (count($model_info) != 2 || empty($object_id) || empty($category) || empty($model)) {
                    throw new \Exception('Unknown event_object definition with value: ' . json_encode($event_object));
                }

                // load referenced object
                $referenced_objects[] = $this->__get($category)->$model->get($object_id);
            }
        }

        if ($this->callback_push_object) {
            // Call callback on all objects in event->data array
            foreach ($referenced_objects as $referenced_object) {
                call_user_func($this->callback_push_object, $referenced_object);
            }
        }
    }

    /**
     * Factory function to create object of expected model type
     *
     * @param string $object
     * @throws \Exception
     * @return object
     */
    public function factory($object)
    {

        $product = 'secucard\models\\' . $object;
        if (class_exists($product)) {
            return new $product($this);
        }
        throw new \Exception("Invalid product type given.");
    }
}
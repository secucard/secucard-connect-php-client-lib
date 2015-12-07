<?php
/**
 * Api Client class
 */

namespace SecucardConnect;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Client\ClientError;
use Psr\Log\LoggerInterface;
use SecucardConnect\Auth\GrantTypeInterface;
use SecucardConnect\Auth\OauthProvider;
use SecucardConnect\Client\ClientContext;
use SecucardConnect\Client\DummyStorage;
use SecucardConnect\Client\Product;
use SecucardConnect\Client\ResourceMetadata;
use SecucardConnect\Client\StorageInterface;
use SecucardConnect\Util\GuzzleSubscriber;
use SecucardConnect\Util\Logger;

/**
 * Secucard Api Client
 * Uses GuzzleHttp client library
 * @author Jakub Elias <j.elias@secupay.ag>
 */
class SecucardConnect
{
    /**
     * @var OAuthProvider
     */
    private $oauthProvider;

    /**
     * Configuration array
     * @var array
     */
    protected $config;

    /**
     * GuzzleHttp client
     * @var object GuzzleHttp
     */
    protected $httpClient;

    /**
     * @var ClientContext
     */
    private $clientContext;

    /**
     * Array that maps category names
     * @var array
     */
    protected $productMap;

    /**
     * Logger used for logging
     * @var \Psr\Log\LoggerInterface
     */
    public $logger;

    /**
     * Storage used to store authorization
     * @var \SecucardConnect\Client\StorageInterface
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
    private $credentials;

    /**
     * Constructor
     * @param array $config - options to correctly initialize Guzzle Client
     * @param LoggerInterface $logger - pass here LoggerInterface to use for logging
     * @param StorageInterface $dataStorage - pass here StorageInterface for storing runtime data (like oauth-tokens)
     * @param StorageInterface $tokenStorage
     * @param GrantTypeInterface $credentials The credentials to use when operations need authorization
     */
    public function __construct(
        array $config,
        LoggerInterface $logger = null,
        StorageInterface $dataStorage = null,
        StorageInterface $tokenStorage,
        GrantTypeInterface $credentials
    ) {
        // array of base configuration
        $default = array(
            'base_url' => 'https://connect.secucard.com',
            'auth_path' => '/oauth/token',
            'api_path' => '/api/v2',
            'debug' => false,
            'auth' => ['type' => null]
        );

        // The following fields are required when creating the client
        $required = array(
            'base_url',
            'auth_path',
            'api_path',
        );

        // Merge in default settings and validate the config
        $this->config = $this->mergeCfg($config, $default, $required);

        if ($logger == null) {
            // initialize default logger - with logging disabled
            $logger = new Logger(null, false);
        }

        $this->logger = $logger;

        // Create a new Secucard client
        $this->httpClient = new Client($this->config);

        if ($this->config['debug']) {
            // Add HTTP-Requests to log
            $this->httpClient->getEmitter()->attach(new GuzzleSubscriber($this->logger));
        }

        // Create storage
        if ($dataStorage instanceof StorageInterface) {
            $this->storage = $dataStorage;
        } else {
            $this->storage = new DummyStorage();
        }

        $this->clientContext = new ClientContext($this->httpClient, $this->config, $this->logger);

        // Ensure that the OauthProvider is attached to the client, when grant_type is not device
        // but only when authorization is needed
        $this->setAuthorization($tokenStorage, $credentials);
        $this->credentials = $credentials;
    }


    private static function mergeCfg(array $config = [], array $defaults = [], array $required = [])
    {
        $data = $config + $defaults;

        if ($missing = array_diff($required, array_keys($data))) {
            throw new \InvalidArgumentException('Config is missing the following keys: ' . implode(', ', $missing));
        }

        return $data;
    }

    /**
     * Private function to set Authorization on client
     * @param StorageInterface $storage
     * @param GrantTypeInterface $credentials
     */
    private function setAuthorization(StorageInterface $storage, GrantTypeInterface $credentials = null)
    {
        // conditions for client authorization types
        if ($credentials == null) {
            return;
        }

        // create OAuthProvider
        $this->oauthProvider = new OauthProvider($this->config['auth_path'], $this->httpClient, $storage, $credentials);

        $this->oauthProvider->setLogger($this->logger);

        // assign OAuthProvider to guzzle client
        $this->httpClient->getEmitter()->attach($this->oauthProvider);
    }


    /**
     * Performs authentication using the given parameter and the credentials passed in this instance
     * constructor. The returned result depends on the credentials type and parameter content. <br/>
     * Note: For credentials other then Auth\DeviceCredentials calling this method is fully optional - since no user
     * interaction is required the auth is done automatically when needed (usually when invoking the first
     * service call). However one may call in advance (before service calls) to make sure the authentication is working
     * correctly.
     * @param array $param Optional argument array, may contain a 'devicecode' entry.
     * @return bool|Auth\AuthCodes For instances of Auth\DeviceCredentials an instance of
     * Auth\AuthCodes is returned when passing no parameter, when passing 'devicecode' key either true or false
     * if the authentication is still pending. In the pending case just repeat the call until true.<br/>
     * For other credential types true is returned.
     * @throws Exception If an error happens during the process. Inspect the exception type to get further details
     * about the cause.
     * @see \SecucardConnect\Client\AuthError
     */
    public function authenticate(array $param = null)
    {
        $result =  $this->oauthProvider->getAccessToken(is_array($param) ? $param['devicecode'] : null);
        if (is_string($result)) {
            return true;
        } else {
            return $result;
        }
    }


    /**
     * Magic getter for getting the product object.
     *
     * @param string $name
     * @return \SecucardConnect\Client\Product
     * @throws \Exception
     */
    public function __get($name)
    {
        $prod = ucfirst(strtolower($name));

        if (isset($this->productMap[$prod])) {
            return $this->productMap[$prod];
        }

        $rm = new ResourceMetadata($name);
        $prodInst = null;
        $prodClass = $rm->productClass;
        if (class_exists($prodClass)) {
            // try to create product impl. if there is a class for it
            $prodInst = new $prodClass($prod, $this->clientContext);
        } else {
            // use default
            $prodInst = new Product($prod, $this->clientContext);
        }

        // create category inside category_map
        $this->productMap[$prod] = $prodInst;
        return $prodInst;
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

        $product = 'SecucardConnect\Product\\' . $object . '\\';
        if (class_exists($product)) {
            return new $product($this);
        }
        throw new \Exception("Invalid product type given.");
    }


}
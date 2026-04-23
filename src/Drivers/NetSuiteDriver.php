<?php

namespace Anibalealvarezs\NetSuiteHubDriver\Drivers;

use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;
use Anibalealvarezs\ApiDriverCore\Traits\HasUpdatableCredentials;
use Anibalealvarezs\NetSuiteApi\NetSuiteApi;
use Anibalealvarezs\NetSuiteHubDriver\Conversions\NetSuiteConvert;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use DateTime;
use Exception;
use Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface;
use Anibalealvarezs\ApiDriverCore\Traits\SyncDriverTrait;

class NetSuiteDriver implements SyncDriverInterface
{
    use SyncDriverTrait;

    /**
     * Store credentials for this driver.
     * 
     * @param array $credentials
     * @return void
     */
    public static function storeCredentials(array $credentials): void
    {
        // No implementation needed for this driver
    }

    /**
     * Get the public resources exposed by this driver.
     * 
     * @return array
     */
    public static function getPublicResources(): array
    {
        return [];
    }

    /**
     * Get the display label for the channel.
     * 
     * @return string
     */
    public static function getChannelLabel(): string
    {
        return 'NetSuite';
    }

    public static function getChannelIcon(): string
    {
        return 'NS';
    }

    /**
     * Get the routes served by this driver.
     * 
     * @return array
     */
    public static function getRoutes(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function fetchAvailableAssets(bool $throwOnError = false): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function validateAuthentication(): array
    {
        return [
            'success' => true,
            'message' => 'Status unknown for this driver.',
            'details' => []
        ];
    }



    private ?AuthProviderInterface $authProvider = null;
    private ?LoggerInterface $logger = null;
    /** @var callable|null */
    private $dataProcessor = null;

    public function __construct(?AuthProviderInterface $authProvider = null, ?LoggerInterface $logger = null)
    {
        $this->authProvider = $authProvider;
        $this->logger = $logger;
    }

    public function setAuthProvider(AuthProviderInterface $provider): void
    {
        $this->authProvider = $provider;
    }

    public function getAuthProvider(): ?AuthProviderInterface
    {
        return $this->authProvider;
    }

    public function setDataProcessor(callable $processor): void
    {
        $this->dataProcessor = $processor;
    }

    public function getChannel(): string
    {
        return 'netsuite';
    }

    public function sync(
        DateTime $startDate,
        DateTime $endDate,
        array $config = [],
        ?callable $shouldContinue = null,
        ?callable $identityMapper = null
    ): Response

    {
        if (!$this->authProvider) {
            throw new Exception("AuthProvider not set for NetSuiteDriver");
        }

        if (!$this->dataProcessor) {
            throw new Exception("DataProcessor not set for NetSuiteDriver");
        }

        if ($this->logger) {
            $this->logger->info("Starting NetSuiteDriver sync (Modular)...");
        }

        try {
            /** @var \Anibalealvarezs\NetSuiteHubDriver\Auth\NetSuiteAuthProvider $auth */
            $auth = $this->authProvider;
            $creds = $auth->getCredentials();
            
            $api = new NetSuiteApi(
                consumerId: $creds['consumer_id'],
                consumerSecret: $creds['consumer_secret'],
                token: $creds['token_id'],
                tokenSecret: $creds['token_secret'],
                accountId: $creds['account_id']
            );

            $type = $config['type'] ?? 'all';

            // 1. Sync Customers
            if ($type === 'all' || $type === 'customers') {
                $this->syncCustomers($api, $startDate, $endDate, $config);
            }

            // 2. Sync Orders
            if ($type === 'all' || $type === 'orders') {
                $this->syncOrders($api, $startDate, $endDate, $creds, $config);
            }

            // 3. Sync Products
            if ($type === 'all' || $type === 'products') {
                $this->syncProducts($api, $creds, $config);
            }

            // 4. Sync Product Categories
            if ($type === 'all' || $type === 'product_categories') {
                $this->syncProductCategories($api, $config);
            }

            return new Response(json_encode(['status' => 'success', 'message' => 'NetSuite sync completed']));

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("NetSuiteDriver error: " . $e->getMessage());
            }
            throw $e;
        }
    }

    private function syncCustomers(NetSuiteApi $api, DateTime $start, DateTime $end, array $config): void
    {
        if ($this->logger) $this->logger->info("Syncing NetSuite Customers...");
        $query = "SELECT Customer.email, Customer.entityid, Customer.firstname, Customer.id AS customerid, Customer.lastname, Entity.datecreated, Entity.id AS entityid, Entity.isinactive FROM Customer INNER JOIN Entity ON Entity.customer = Customer.id WHERE Entity.datecreated >= TO_DATE('" . $start->format('m/d/Y') . "', 'mm/dd/yyyy') AND Entity.datecreated <= TO_DATE('" . $end->format('m/d/Y') . "', 'mm/dd/yyyy')";
        $api->getSuiteQLQueryAllAndProcess(
            query: $query,
            callback: function ($customers) use ($config) {
                $this->checkJobStatus($config);
                $collection = NetSuiteConvert::customers($customers);
                if ($this->dataProcessor && $collection->count() > 0) {
                    ($this->dataProcessor)($collection, $this->logger);
                }
            }
        );
    }

    private function syncOrders(NetSuiteApi $api, DateTime $start, DateTime $end, array $creds, array $config): void
    {
        if ($this->logger) $this->logger->info("Syncing NetSuite Orders...");
        $domain = $this->getDomain($creds['store_base_url'] ?? '');
        $query = "SELECT transaction.*, entity.customer as CustomerID FROM transaction INNER JOIN entity ON entity.id = transaction.entity WHERE transaction.type = 'SalesOrd' AND transaction.custbody_division_domain = '$domain' AND transaction.trandate >= TO_DATE('" . $start->format('m/d/Y') . "', 'mm/dd/yyyy')";
        $api->getSuiteQLQueryAllAndProcess(
            query: $query,
            callback: function ($orders) use ($config) {
                $this->checkJobStatus($config);
                $collection = NetSuiteConvert::orders($orders);
                if ($this->dataProcessor && $collection->count() > 0) {
                    ($this->dataProcessor)($collection, $this->logger);
                }
            }
        );
    }

    private function syncProducts(NetSuiteApi $api, array $creds, array $config): void
    {
        if ($this->logger) $this->logger->info("Syncing NetSuite Products...");
        $query = "SELECT Item.* FROM Item WHERE Item.isinactive = 'F'";
        $api->getSuiteQLQueryAllAndProcess(
            query: $query,
            callback: function ($products) use ($config) {
                $this->checkJobStatus($config);
                $collection = NetSuiteConvert::products($products);
                if ($this->dataProcessor && $collection->count() > 0) {
                    ($this->dataProcessor)($collection, $this->logger);
                }
            }
        );
    }

    private function syncProductCategories(NetSuiteApi $api, array $config): void
    {
        if ($this->logger) $this->logger->info("Syncing NetSuite Product Categories...");
        $sinceId = $config['sinceId'] ?? 0;
        $query = "SELECT * FROM CommerceCategory WHERE id >= " . $sinceId . " ORDER BY id ASC";
        $api->getSuiteQLQueryAllAndProcess(
            query: $query,
            callback: function ($productCategories) use ($config) {
                $this->checkJobStatus($config);
                $collection = NetSuiteConvert::productCategories($productCategories);
                if ($this->dataProcessor && $collection->count() > 0) {
                    ($this->dataProcessor)($collection, $this->logger);
                }
            }
        );
    }

    private function getDomain(string $url): string
    {
        $url = str_replace(['http://', 'https://'], '', $url);
        return explode('/', $url)[0];
    }

    public function getApi(array $config = []): NetSuiteApi
    {
        /** @var \Anibalealvarezs\NetSuiteHubDriver\Auth\NetSuiteAuthProvider $auth */
        $auth = $this->authProvider;
        $creds = $auth->getCredentials();
        
        return new NetSuiteApi(
            consumerId: $creds['consumer_id'],
            consumerSecret: $creds['consumer_secret'],
            token: $creds['token_id'],
            tokenSecret: $creds['token_secret'],
            accountId: $creds['account_id']
        );
    }

    /**
     * @inheritdoc
     */
    public function getConfigSchema(): array
    {
        return [
            'global' => [
                'enabled' => false,
                'cache_history_range' => '1 year',
                'cache_aggregations' => false,
            ],
            'entity' => [
                'id' => '',
                'name' => '',
                'enabled' => true,
            ]
        ];
    }

    /**
     * @inheritdoc
     */
    public function validateConfig(array $config): array
    {
        return \Anibalealvarezs\ApiDriverCore\Services\ConfigSchemaRegistryService::hydrate(
            $this->getChannel(),
            'global',
            $config,
            $this->getConfigSchema()
        );
    }

    /**
     * @inheritdoc
     */
    public function seedDemoData(SeederInterface $seeder, array $config = []): void
    {
        // Placeholder for future implementation
    }

    public array $updatableCredentials = [
        'NETSUITE_CONSUMER_ID',
        'NETSUITE_CONSUMER_SECRET',
        'NETSUITE_TOKEN_ID',
        'NETSUITE_TOKEN_SECRET',
        'NETSUITE_ACCOUNT_ID'
    ];
    public function boot(): void
    {
    }

    public static function getAssetPatterns(): array
    {
        return [];
    }



    /**
     * @inheritdoc
     */
    public function initializeEntities(array $config = []): array

    {
        return ['initialized' => 0, 'skipped' => 0];
    }

    /**
     * @inheritdoc
     */
    public function reset(string $mode = 'all', array $config = []): array

    {
        return ['cleared' => 0, 'mode' => $mode];
    }

    public function updateConfiguration(array $newData, array $currentConfig): array
    {
        return $currentConfig;
    }
    public function prepareUiConfig(array $channelConfig): array
    {
        return [];
    }
    /**
     * @inheritdoc
     */
    public function getDateFilterMapping(): array
    {
        return [
            'start' => 'createdAtMin',
            'end' => 'createdAtMax'
        ];
    }
}


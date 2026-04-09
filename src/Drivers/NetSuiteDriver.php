<?php

namespace Anibalealvarezs\NetSuiteHubDriver\Drivers;

use Anibalealvarezs\ApiSkeleton\Interfaces\SyncDriverInterface;
use Anibalealvarezs\ApiSkeleton\Interfaces\AuthProviderInterface;
use Anibalealvarezs\ApiSkeleton\Traits\HasUpdatableCredentials;
use Anibalealvarezs\NetSuiteApi\NetSuiteApi;
use Anibalealvarezs\NetSuiteApi\Conversions\NetSuiteConvert;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use DateTime;
use Exception;

class NetSuiteDriver implements SyncDriverInterface
{
    use HasUpdatableCredentials;

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

    public function sync(DateTime $startDate, DateTime $endDate, array $config = []): Response
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
                $this->syncCustomers($api, $startDate, $endDate);
            }

            // 2. Sync Orders
            if ($type === 'all' || $type === 'orders') {
                $this->syncOrders($api, $startDate, $endDate, $creds);
            }

            // 3. Sync Products
            if ($type === 'all' || $type === 'products') {
                $this->syncProducts($api, $creds);
            }

            return new Response(json_encode(['status' => 'success', 'message' => 'NetSuite sync completed']));

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("NetSuiteDriver error: " . $e->getMessage());
            }
            throw $e;
        }
    }

    private function syncCustomers(NetSuiteApi $api, DateTime $start, DateTime $end): void
    {
        if ($this->logger) $this->logger->info("Syncing NetSuite Customers...");
        $query = "SELECT Customer.email, Customer.entityid, Customer.firstname, Customer.id AS customerid, Customer.lastname, Entity.datecreated, Entity.id AS entityid, Entity.isinactive FROM Customer INNER JOIN Entity ON Entity.customer = Customer.id WHERE Entity.datecreated >= TO_DATE('". $start->format('m/d/Y') ."', 'mm/dd/yyyy') AND Entity.datecreated <= TO_DATE('". $end->format('m/d/Y') ."', 'mm/dd/yyyy')";
        $api->getSuiteQLQueryAllAndProcess(
            query: $query,
            callback: function ($customers) {
                $collection = NetSuiteConvert::customers($customers);
                if ($this->dataProcessor && $collection->count() > 0) {
                    ($this->dataProcessor)($collection, $this->logger);
                }
            }
        );
    }

    private function syncOrders(NetSuiteApi $api, DateTime $start, DateTime $end, array $creds): void
    {
        if ($this->logger) $this->logger->info("Syncing NetSuite Orders...");
        $domain = $this->getDomain($creds['store_base_url'] ?? '');
        $query = "SELECT transaction.*, entity.customer as CustomerID FROM transaction INNER JOIN entity ON entity.id = transaction.entity WHERE transaction.type = 'SalesOrd' AND transaction.custbody_division_domain = '$domain' AND transaction.trandate >= TO_DATE('". $start->format('m/d/Y') ."', 'mm/dd/yyyy')";
        $api->getSuiteQLQueryAllAndProcess(
            query: $query,
            callback: function ($orders) {
                $collection = NetSuiteConvert::orders($orders);
                if ($this->dataProcessor && $collection->count() > 0) {
                    ($this->dataProcessor)($collection, $this->logger);
                }
            }
        );
    }

    private function syncProducts(NetSuiteApi $api, array $creds): void
    {
        if ($this->logger) $this->logger->info("Syncing NetSuite Products...");
        $query = "SELECT Item.* FROM Item WHERE Item.isinactive = 'F'";
        $api->getSuiteQLQueryAllAndProcess(
            query: $query,
            callback: function ($products) {
                $collection = NetSuiteConvert::products($products);
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
                'enabled' => true,
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
        return $config;
    }

    public array $updatableCredentials = [
        'NETSUITE_CONSUMER_ID',
        'NETSUITE_CONSUMER_SECRET',
        'NETSUITE_TOKEN_ID',
        'NETSUITE_TOKEN_SECRET',
        'NETSUITE_ACCOUNT_ID'
    ];
}

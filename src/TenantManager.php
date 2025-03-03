<?php

namespace Stancl\Tenancy;

use Stancl\Tenancy\Interfaces\StorageDriver;
use Stancl\Tenancy\Traits\BootstrapsTenancy;
use Illuminate\Contracts\Foundation\Application;

class TenantManager
{
    use BootstrapsTenancy;

    /**
     * The application instance.
     *
     * @var Application
     */
    protected $app;

    /**
     * Storage driver for tenant metadata.
     *
     * @var StorageDriver
     */
    protected $storage;
    
    /**
     * Database manager.
     *
     * @var DatabaseManager
     */
    protected $database;

    /**
     * Current tenant.
     *
     * @var array
     */
    public $tenant;

    public function __construct(Application $app, StorageDriver $storage, DatabaseManager $database)
    {
        $this->app = $app;
        $this->storage = $storage;
        $this->database = $database;
    }

    public function init(string $domain = null): array
    {
        $this->setTenant($this->identify($domain));
        $this->bootstrap();
        return $this->tenant;
    }

    public function identify(string $domain = null): array
    {
        $domain = $domain ?: $this->currentDomain();

        if (! $domain) {
            throw new \Exception("No domain supplied nor detected.");
        }

        $tenant = $this->storage->identifyTenant($domain);

        if (! $tenant || ! array_key_exists('uuid', $tenant) || ! $tenant['uuid']) {
            throw new \Exception("Tenant could not be identified on domain {$domain}.");
        }

        return $tenant;
    }

    public function create(string $domain = null): array
    {
        $domain = $domain ?: $this->currentDomain();

        if ($id = $this->storage->getTenantIdByDomain($domain)) {
            throw new \Exception("Domain $domain is already occupied by tenant $id.");
        }

        $tenant = $this->jsonDecodeArrayValues($this->storage->createTenant($domain, (string) \Webpatser\Uuid\Uuid::generate(1, $domain)));
        $this->database->create($this->getDatabaseName($tenant));
        
        return $tenant;
    }

    public function delete(string $uuid): bool
    {
        return $this->storage->deleteTenant($uuid);
    }

    /**
     * Return an array with information about a tenant based on his uuid.
     *
     * @param string $uuid
     * @param array|string $fields
     * @return array
     */
    public function getTenantById(string $uuid, $fields = [])
    {
        $fields = (array) $fields;
        return $this->jsonDecodeArrayValues($this->storage->getTenantById($uuid, $fields));
    }

    /**
     * Alias for getTenantById().
     *
     * @param string $uuid
     * @param array|string $fields
     * @return array
     */
    public function find(string $uuid, $fields = [])
    {
        return $this->getTenantById($uuid, $fields);
    }

    /**
     * Get tenant uuid based on the domain that belongs to him.
     *
     * @param string $domain
     * @return string|null
     */
    public function getTenantIdByDomain(string $domain = null): ?string
    {
        $domain = $domain ?: $this->currentDomain();

        return $this->storage->getTenantIdByDomain($domain);
    }

    /**
     * Alias for getTenantIdByDomain().
     *
     * @param string $domain
     * @return string|null
     */
    public function getIdByDomain(string $domain = null)
    {
        return $this->getTenantIdByDomain($domain);
    }

    /**
     * Get tenant information based on his domain.
     *
     * @param string $domain
     * @param mixed $fields
     * @return array
     */
    public function findByDomain(string $domain = null, $fields = [])
    {
        $domain = $domain ? : $this->currentDomain();

        $uuid = $this->getIdByDomain($domain);

        if (is_null($uuid)) {
            throw new \Exception("Tenant with domain $domain could not be identified.");
        }

        return $this->find($uuid, $fields);
    }

    public static function currentDomain(): ?string
    {
        return request()->getHost() ?? null;
    }

    public function getDatabaseName($tenant = []): string
    {
        $tenant = $tenant ?: $this->tenant;
        return $this->app['config']['tenancy.database.prefix'] . $tenant['uuid'] . $this->app['config']['tenancy.database.suffix'];
    }

    /**
     * Set the tenant property to a JSON decoded version of the tenant's data obtained from storage.
     *
     * @param array $tenant
     * @return array
     */
    public function setTenant(array $tenant): array
    {
        $tenant = $this->jsonDecodeArrayValues($tenant);

        $this->tenant = $tenant;
        
        return $tenant;
    }

    /**
     * Reconnects to the default database.
     * @todo More descriptive name?
     *
     * @return void
     */
    public function disconnectDatabase()
    {
        $this->database->disconnect();
    }

    /**
     * Get all tenants.
     *
     * @param array|string $uuids
     * @return \Illuminate\Support\Collection
     */
    public function all($uuids = [])
    {
        $uuids = (array) $uuids;

        return collect(array_map(function ($tenant_array) {
            return $this->jsonDecodeArrayValues($tenant_array);
        }, $this->storage->getAllTenants($uuids)));
    }

    /**
     * Initialize tenancy based on tenant uuid.
     *
     * @param string $uuid
     * @return array
     */
    public function initById(string $uuid): array
    {
        $this->setTenant($this->storage->getTenantById($uuid));
        $this->bootstrap();
        return $this->tenant;
    }

    /**
     * Get a value from the storage for a tenant.
     *
     * @param string|array $key
     * @param string $uuid
     * @return mixed
     */
    public function get($key, string $uuid = null)
    {
        $uuid = $uuid ?: $this->tenant['uuid'];

        if (\is_array($key)) {
            return $this->jsonDecodeArrayValues($this->storage->getMany($uuid, $key));
        }

        return json_decode($this->storage->get($uuid, $key), true);
    }

    /**
     * Puts a value into the storage for a tenant.
     *
     * @param string|array $key
     * @param mixed $value
     * @param string uuid
     * @return mixed
     */
    public function put($key, $value = null, string $uuid = null)
    {
        if (\is_null($uuid)) {
            if (! isset($this->tenant['uuid'])) {
                throw new \Exception("No UUID supplied (and no tenant is currently identified).");
            }
            
            $uuid = $this->tenant['uuid'];

            // If $uuid is the uuid of the current tenant, put
            // the value into the $this->tenant array as well.
            $target = &$this->tenant;
        } else {
            $target = []; // black hole
        }

        if (! \is_null($value)) {
            return $target[$key] = json_decode($this->storage->put($uuid, $key, json_encode($value)), true);
        }

        if (! \is_array($key)) {
            throw new \Exception("No value supplied for key $key.");
        }

        foreach ($key as $k => $v) {
            $target[$k] = $v;
            $key[$k] = json_encode($v);
        }

        return $this->jsonDecodeArrayValues($this->storage->putMany($uuid, $key));
    }

    /**
     * Alias for put().
     *
     * @param string|array $key
     * @param mixed $value
     * @param string $uuid
     * @return mixed
     */
    public function set($key, $value = null, string $uuid = null)
    {
        return $this->put($key, $value, $uuid);
    }

    protected function jsonDecodeArrayValues(array $array)
    {
        array_walk($array, function (&$value, $key) {
            $value = json_decode($value, true);
        });

        return $array;
    }

    /**
     * Return the identified tenant's attribute(s).
     *
     * @param string $attribute
     * @return mixed
     */
    public function __invoke($attribute)
    {
        if (\is_null($attribute)) {
            return $this->tenant;
        }
        
        return $this->tenant[(string) $attribute];
    }
}

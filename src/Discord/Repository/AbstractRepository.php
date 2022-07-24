<?php

/*
 * This file is a part of the DiscordPHP project.
 *
 * Copyright (c) 2015-present David Cole <david.cole1340@gmail.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\Repository;

use Discord\Factory\Factory;
use Discord\Helpers\Collection;
use Discord\Http\Endpoint;
use Discord\Http\Http;
use Discord\Parts\Part;
use React\Cache\CacheInterface;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\PromiseInterface;

/**
 * Repositories provide a way to store and update parts on the Discord server.
 *
 * @author Aaron Scherer <aequasi@gmail.com>
 * @author David Cole <david.cole1340@gmail.com>
 */
abstract class AbstractRepository extends Collection
{
    /**
     * The discriminator.
     *
     * @var string Discriminator.
     */
    protected $discrim = 'id';

    /**
     * The HTTP client.
     *
     * @var Http Client.
     */
    protected $http;

    /**
     * The parts factory.
     *
     * @var Factory Parts factory.
     */
    protected $factory;

    /**
     * Endpoints for interacting with the Discord servers.
     *
     * @var array Endpoints.
     */
    protected $endpoints = [];

    /**
     * Variables that are related to the repository.
     *
     * @var array Variables.
     */
    protected $vars = [];

    /**
     * The react/cache Interface.
     *
     * @var CacheInterface
     */
    public $cache;

    /**
     * Cache key prefix.
     *
     * @var string
     */
    protected $cacheKeyPrefix;

    /**
     * Cache keys => part object id.
     *
     * @var array Key => Part.
     */
    protected $cacheKeys = [];

    /**
     * AbstractRepository constructor.
     *
     * @param Http    $http    The HTTP client.
     * @param Factory $factory The parts factory.
     * @param array   $vars    An array of variables used for the endpoint.
     */
    public function __construct(Http $http, Factory $factory, array $vars = [], CacheInterface $cacheInterface)
    {
        $this->http = $http;
        $this->factory = $factory;
        $this->vars = $vars;
        $this->cache = $cacheInterface;
        $this->cacheKeyPrefix = 'repositories.'.static::class;

        parent::__construct([], $this->discrim, $this->class);
    }

    /**
     * Freshens the repository collection.
     *
     * @param array $queryparams Query string params to add to the request (no validation)
     *
     * @return ExtendedPromiseInterface
     * @throws \Exception
     */
    public function freshen(array $queryparams = []): ExtendedPromiseInterface
    {
        if (! isset($this->endpoints['all'])) {
            return \React\Promise\reject(new \Exception('You cannot freshen this repository.'));
        }

        $endpoint = new Endpoint($this->endpoints['all']);
        $endpoint->bindAssoc($this->vars);

        foreach ($queryparams as $query => $param) {
            $endpoint->addQuery($query, $param);
        }

        return $this->http->get($endpoint)->then(function ($response) {
            $this->clear();

            return $this->freshenCache($response);
        });
    }

    /**
     * Builds a new, empty part.
     *
     * @param array $attributes The attributes for the new part.
     * @param bool  $created
     *
     * @return Part       The new part.
     * @throws \Exception
     */
    public function create(array $attributes = [], bool $created = false): Part
    {
        $attributes = array_merge($attributes, $this->vars);

        return $this->factory->create($this->class, $attributes, $created);
    }

    /**
     * Attempts to save a part to the Discord servers.
     *
     * @param Part        $part   The part to save.
     * @param string|null $reason Reason for Audit Log (if supported).
     *
     * @return ExtendedPromiseInterface
     * @throws \Exception
     */
    public function save(Part $part, ?string $reason = null): ExtendedPromiseInterface
    {
        if ($part->created) {
            if (! isset($this->endpoints['update'])) {
                return \React\Promise\reject(new \Exception('You cannot update this part.'));
            }

            $method = 'patch';
            $endpoint = new Endpoint($this->endpoints['update']);
            $endpoint->bindAssoc(array_merge($part->getRepositoryAttributes(), $this->vars));
            $attributes = $part->getUpdatableAttributes();
        } else {
            if (! isset($this->endpoints['create'])) {
                return \React\Promise\reject(new \Exception('You cannot create this part.'));
            }

            $method = 'post';
            $endpoint = new Endpoint($this->endpoints['create']);
            $endpoint->bindAssoc(array_merge($part->getRepositoryAttributes(), $this->vars));
            $attributes = $part->getCreatableAttributes();
        }

        $headers = [];
        if (isset($reason)) {
            $headers['X-Audit-Log-Reason'] = $reason;
        }

        return $this->http->{$method}($endpoint, $attributes, $headers)->then(function ($response) use (&$part) {
            $part->fill((array) $response);
            $part->created = true;
            $part->deleted = false;
            $cacheKey = $this->cacheKeyPrefix.'.'.$part->{$this->discrim};

            return $this->setCache($cacheKey, $part);
        });
    }

    /**
     * Attempts to delete a part on the Discord servers.
     *
     * @param Part|string $part   The part to delete.
     * @param string|null $reason Reason for Audit Log (if supported).
     *
     * @return ExtendedPromiseInterface
     * @throws \Exception
     */
    public function delete($part, ?string $reason = null): ExtendedPromiseInterface
    {
        if (! ($part instanceof Part)) {
            $part = $this->factory->part($this->class, [$this->discrim => $part], true);
        }

        if (! $part->created) {
            return \React\Promise\reject(new \Exception('You cannot delete a non-existant part.'));
        }

        if (! isset($this->endpoints['delete'])) {
            return \React\Promise\reject(new \Exception('You cannot delete this part.'));
        }

        $endpoint = new Endpoint($this->endpoints['delete']);
        $endpoint->bindAssoc(array_merge($part->getRepositoryAttributes(), $this->vars));

        $headers = [];
        if (isset($reason)) {
            $headers['X-Audit-Log-Reason'] = $reason;
        }

        return $this->http->delete($endpoint, null, $headers)->then(function ($response) use (&$part) {
            if ($response) {
                $part->fill((array) $response);
            }

            $part->created = false;
            $cacheKey = $this->cacheKeyPrefix.'.'.$part->{$this->discrim};

            return $this->deleteCache($cacheKey)->then(function ($success) use ($part) {
                return $part;
            });
        });
    }

    /**
     * Returns a part with fresh values.
     *
     * @param Part  $part        The part to get fresh values.
     * @param array $queryparams Query string params to add to the request (no validation)
     *
     * @return ExtendedPromiseInterface
     * @throws \Exception
     */
    public function fresh(Part $part, array $queryparams = []): ExtendedPromiseInterface
    {
        if (! $part->created) {
            return \React\Promise\reject(new \Exception('You cannot get a non-existant part.'));
        }

        if (! isset($this->endpoints['get'])) {
            return \React\Promise\reject(new \Exception('You cannot get this part.'));
        }

        $endpoint = new Endpoint($this->endpoints['get']);
        $endpoint->bindAssoc(array_merge($part->getRepositoryAttributes(), $this->vars));

        foreach ($queryparams as $query => $param) {
            $endpoint->addQuery($query, $param);
        }

        return $this->http->get($endpoint)->then(function ($response) use (&$part) {
            $part->fill((array) $response);
            $cacheKey = $this->cacheKeyPrefix.'.'.$part->{$this->discrim};

            return $this->setCache($cacheKey, $part);
        });
    }

    /**
     * Gets a part from the repository or Discord servers.
     *
     * @param string $id    The ID to search for.
     * @param bool   $fresh Whether we should skip checking the cache.
     *
     * @return ExtendedPromiseInterface
     * @throws \Exception
     */
    public function fetch(string $id, bool $fresh = false): ExtendedPromiseInterface
    {
        if (! $fresh) {
            return $this->cache->get($this->cacheKeyPrefix.'.'.$id)->then(function ($item) use ($id) {
                if (! isset($item)) {
                    return $this->fetch($id, true);
                }

                return $item;
            });
        }

        if (! isset($this->endpoints['get'])) {
            return \React\Promise\resolve(new \Exception('You cannot get this part.'));
        }

        $part = $this->factory->create($this->class, [$this->discrim => $id]);
        $endpoint = new Endpoint($this->endpoints['get']);
        $endpoint->bindAssoc(array_merge($part->getRepositoryAttributes(), $this->vars));

        return $this->http->get($endpoint)->then(function ($response) {
            $part = $this->factory->create($this->class, array_merge($this->vars, (array) $response), true);
            $cacheKey = $this->cacheKeyPrefix.'.'.$part->{$this->discrim};

            return $this->setCache($cacheKey, $part);
        });
    }

    /**
     * @internal
     */
    protected function freshenCache($response): PromiseInterface
    {
        return $this->cache->deleteMultiple(array_keys($this->cacheKeys))->then(function ($success) use ($response) {
            if ($success) {
                $this->cacheKeys = [];
            }

            $parts = $cacheKeys = [];

            foreach ($response as $value) {
                $value = array_merge($this->vars, (array) $value);
                $part = $this->factory->create($this->class, $value, true);

                $cacheKey = $this->cacheKeyPrefix.'.'.$part->{$this->discrim};
                $parts[$cacheKey] = $part;
                $cacheKeys[$cacheKey] = spl_object_id($part);
            }

            return $this->cache->setMultiple($parts)->then(function ($success) use ($cacheKeys) {
                if ($success) {
                    $this->cacheKeys = $cacheKeys;
                }

                return $this;
            });
        });
    }

    /**
     * @internal
     */
    protected function setCache($cacheKey, $part): PromiseInterface
    {
        return $this->cache->set($cacheKey, $part)->then(function ($success) use ($part, $cacheKey) {
            if ($success) {
                $this->cacheKeys[$cacheKey] = spl_object_id($part);
            }

            return $part;
        });
    }

    /**
     * @internal
     */
    protected function deleteCache($cacheKey): PromiseInterface
    {
        return $this->cache->delete($cacheKey)->then(function ($success) use ($cacheKey) {
            if ($success) {
                unset($this->cacheKeys[$cacheKey]);
            }

            return $success;
        });
    }

    /**
     * Handles debug calls from var_dump and similar functions.
     *
     * @return array An array of attributes.
     */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}

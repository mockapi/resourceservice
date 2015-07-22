<?php

namespace Mockapi\ResourceService;

use \Mockapi\Interfaces\HttpInterface;
use \Mockapi\Interfaces\ResourceServiceInterface;
use \Mockapi\Interfaces\ResourceProviderInterface;
use \Mockapi\Interfaces\ResourceProviderFactoryInterface;

use \Mockapi\Validate\Validate;
use \Mockapi\Mockapi\Router;

use \Exception;
use \Symfony\Component\HttpKernel\Exception\HttpException;

class ResourceService implements HttpInterface, ResourceServiceInterface
{
    protected $resource;
    protected $labels;

    protected $provider;

    protected $limit = 10;


    /**
     * Method Definitions according to HTTP/1.1
     *
     * Extras? ['LINK', 'UNLINK', 'PURGE', 'LOCK', 'UNLOCK', 'PROPFIND', 'VIEW', 'COPY']
     */
    protected static $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'TRACE', 'CONNECT'];

    public function __construct(array $args)
    {
        $required = ['resource', 'provider'];
        Validate::requireAttributes($required, $args, 'Flat file resource arguments');

        foreach ($args as $k => $v) {
            switch ($k) {
                case 'resource':
                    Validate::isNonEmptyString($v, "`{$k}` argument");
                    Validate::isPlural($v, "`{$k}` argument");

                    $this->labels = (object) [
                        'singular' => \Inflect\Inflect::singularize($v),
                        'plural' => $v
                    ];

                    $this->labels->singularUcfirst = ucfirst($this->labels->singular);
                    $this->labels->pluralUcfirst = ucfirst($this->labels->plural);

                    $this->{$k} = $v;
                    break;

                case 'provider':
                    if (!$v instanceof ResourceProviderInterface && !$v instanceof ResourceProviderFactoryInterface) {
                        throw new Exception('Resource provider must implement `ResourceProviderInterface` or `ResourceProviderFactoryInterface`.');
                    }

                    $this->{$k} = $v;
                    break;
            }
        }

        if ($this->provider instanceof ResourceProviderFactoryInterface) {
            $this->provider = $this->provider->get($this->resource);
        }
    }

    ///// GET ///// GET ///// GET ///// GET ///// GET ///// GET ///// GET /////

    /**
     * HTTP/1.1 GET
     *
     * Accessible using HTTP
     *
     * GET /                get()
     * GET /id              get('id')
     *                      get(['id'])
     * GET /id1,id2         get('id1,id2')
     *                      get(['id1','id2'])
     * GET /id/attr         get(id, 'attr')
     * GET /id/attr1,attr2  get(id, 'attr1,attr2')
     * GET /id/attr1,attr2  get(id, ['attr1', 'attr2'])
     *
     */
    public function get($where = null, $fields = [], $limit = null, $offset = 0, $sort = null)
    {
        $offset = $offset > 0 ? (int) $offset : 0;
        $limit = $limit > 0 ? (int) $limit : $this->limit;

        if (empty($fields)) {
            $fields = [];
        } else {
            // Split multiple fields
            if (!is_array($fields)) {
                $fields = explode(',', $fields);
            }
        }

        $single = false;

        // Allow passing get(['id' => 1234])
        if (is_array($where)) {
            if (isset($where['id'])) {
                $limit = 1;
                $where = [$where['id']];
            } elseif (isset($where[0])) {
                $limit = 1;
                $where = [$where[0]];
            }
        }

        if (empty($where)) {
            $where = [];
        } else {
            // Split multiple ids
            if (!is_array($where)) {
                $where = explode(',', $where);
                $limit = count($where);
            }
        }

        // get(<id>)
        if (!is_array($where)) {
            throw new HttpException(500, "Should be deprecated");

            if (count($fields) === 1) {
                return $this->provider->getAttr($where, $fields[0]);
            }

            return $this->provider->get($where, $fields);
        }

        // Clean
        $where = array_filter($where);
        $ids = [];

//        print_r(get_defined_vars());

        // All
        if (!isset($where[0])) {
            // get(?filter=[...])
            $ids = $this->provider->find($where, $limit, $offset, $sort);

            if (empty($ids)) {
                throw new HttpException(404, 'No more results');
            }
        } else {
            // get(<id>,<id>,...,<id>)
            $ids = $where;
        }

        $query = ['filter' => $where, 'fields' => $fields, 'limit' => $limit, 'offset' => $offset, 'sort' => $sort];

        if ($limit === 1) {
            if (count($fields) === 1) {
                return (object) [
                    'data' => $this->provider->getAttr($ids[0], $fields[0])
                ];
            }

            return (object) array_filter([
                'data' => $this->provider->get($ids[0], $fields),
                'links' => array_filter([
                    'prev' => $this->linkPrev($query),
                    'next' => $this->linkNext($query)
                ])
            ]);
        }

        return (object) array_filter([
            'data' => $this->provider->fetch($ids, $fields),
            'links' => array_filter([
                'prev' => $this->linkPrev($query),
                'next' => $this->linkNext($query)
            ])
        ]);
    }

    protected function buildFilterQuery(array $filter)
    {
        return array_map(function($v) {
            if (is_array($v) && isset($v[0])) {
                $v = implode(',', $v);
            }

            return $v;
        }, $filter);
    }

    protected function linkNext(array $args)
    {
        $args = array_filter($args);
        $found = $this->provider->found();

        extract($args);

        if (isset($filter)) {
            $filter = $this->buildFilterQuery($filter);
        }

        $offset = $offset + $limit;

        if ($offset >= $found) {
            return null;
        }

        unset($found, $args);

        return $this->provider->endpoint().'?'.http_build_query(get_defined_vars());
    }

    protected function linkPrev(array $args)
    {
        $args = array_filter($args);
        $found = $this->provider->found();

        extract($args);

        $offset = $offset - $limit;

        if ($limit + $offset <= 0) {
            return null;
        }

        if ($offset < 0) {
            $offset = 0;
        }

        unset($found, $args);

        return $this->provider->endpoint().'?'.http_build_query(get_defined_vars());
    }

    //// POST //// POST //// POST //// POST //// POST //// POST //// POST ////

    /**
     * HTTP/1.1 POST
     *
     * Accessible using HTTP
     *
     * POST /                       post({...})             Create new object
     * POST /                       post([{...},...,{...}]) Batch create new objects
     * POST /id                     post({...}, 'id')       Create new object on ID
     * POST /id1,id2                post({...})             Create new object clones
     * POST /id1,id2                post([{id: 1, ...},{id: 2, ...}], 'id1,id2') Batch create new objects but IDs must match
     *
     * POST /id/attr                post(<any>, ['id'], 'attr')                                         Create new object attribute
     * POST /id/attr1,attr2         post({attr1: v1, attr2: v2}, 'id1,id2', attr1,attr2')               Create new object attributes
     * POST /id1,id2/attr1,attr2    post({attr1: v1, attr2: v2}, ['id1', 'id2'], ['attr1', 'attr2'])    Create new object attributes
     *
     */
    public function post($payload, $where = [], $fields = [])
    {
        if (empty($payload)) {
            throw new HttpException("To create {$this->labels->singular} requires paylod to be object or array of objects");
        }

        if (empty($fields)) {
            $fields = [];
        } else {
            // Split multiple fields
            if (!is_array($fields)) {
                $fields = explode(',', $fields);
            }
        }

        if (empty($where)) {
            $where = [];
        } else {
            // Split multiple ids
            if (!is_array($where)) {
                $where = explode(',', $where);
            }
        }

        if (!empty($fields)) {
            if (empty($where)) {
                throw new HttpException(400, "Creating {$this->labels->singular} object attribute(s) on undefined object(s)");
            }

            if (count($fields) === 1 && count($where) === 1) {
                if (is_object($payload) && isset($payload->{$fields[0]})) {
                    $payload = $payload->{$fields[0]};
                }

                return $this->provider->addAttr($where[0], $fields[0], $payload);
            }

            if (diff($fields, array_keys((array) $payload))) {
                throw new HttpException(400, "Fields must match payload object attributes");
            }

            if (!isset($where[0])) {
                // post(?filter=[...])
                $ids = $this->provider->find($where);
            } else {
                // post(<id>,<id>,...,<id>)
                $ids = $where;

                // Check if all specified IDs exist
                foreach ($ids as &$id) {
                    if (!$this->provider->exists($id)) {
                        throw new HttpException(404, "{$this->labels->singularUcfirs} with ID {$id} not found");
                    }
                }
            }

            // Update
            return array_map(function ($id) use ($payload) {
                foreach ($payload as $k => $v) {
                    $this->postAttrById($id, $k, $v);
                }

                return $id;
            }, $ids);
        }

        // Support passing array of objects or just a single object
        if (is_array($payload)) {
            if (!empty(array_filter(array_map(function($v) {
                // Return false that will get array_filter-ed causing all empty array
                return ! is_object($v);
            }, $payload)))) {
                throw new HttpException(400, "Payload must be object or array of objects");
            }
        } elseif (is_object($payload)) {
            // Unify
            $limit = 1;
            $payload = [$payload];
        }

        $ids = [];

        // Associative
        if (!empty($where) && !isset($where[0])) {
            if (array_key_exists('id', $where)) {
                throw new HttpException(500, "Where clause creates relations. Sure about object type `id`?");
            }

            // Test if possible to create relations
            if (!empty($where)) {
                foreach ($where as $resource => &$relation) {
                    // Now check if resource exists
                    $service = Router::$services->get($resource);
                    $relation = $service->get($relation);

                    $relation = $relation->data->id;
                }
            }
        } elseif (!empty($where)) {
            // NOT associative @todo: Is necessary to support?
            // post(<payload>, <id>,<id>,...,<id>)
            $ids =& $where;

            if (count($ids) !== count($payload)) {
                throw new HttpException(400, "If passing payload AND IDs together, make sure count matches");
            }

            // if {object:id} is set than all must match
            if (array_diff($ids, array_map(function($o) {
                    return isset($o->id) ? $o->id : false;
                }, $payload))) {
                throw new HttpException(400, "If passing payload AND IDs together, make sure they match");
            }
        }

        // Extract ids from objects
        if (empty($ids)) {
            $ids = array_filter(array_map(function($o) {
                return isset($o->id) ? $o->id : false;
            }, $payload));
        }

        // All specified IDs MUST NOT exist
        foreach ($ids as &$id) {
            if ($this->provider->exists($id)) {
                throw new HttpException(404, "{$this->labels->singularUcfirs} with ID {$id} not found");
            }
        }

        // Create object(s) and change to IDs
        foreach ($payload as &$o) {
            if (!empty($where)) {
                foreach ($where as $resource => &$relationId) {
                    $o->{$resource} = [$relationId];
                }
            }

            $o = $this->provider->add($o);

//            if (!$single) {
//                // Replace with the result object `id`
//                $o = $o->id;
//            }
        }

        // Create relations
        if (!empty($where)) {
            $createdIds = [];

            foreach ($payload as &$p) {
                $createdIds[] = $p->id;
            }
            foreach ($where as $resource => &$relationId) {
                Router::$services->get($resource)->post($createdIds, $relationId, $this->resource);
            }
        }

        // Reply to single object payload with single object
        if ($single) {
            return $payload[0];
        }

        return $payload;
    }
}

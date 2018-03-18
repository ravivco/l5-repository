<?php
namespace Prettus\Repository\GraphQL;

use App\Validators\ResponseFormatUtility;
use Closure;
use Exception;
use Illuminate\Container\Container as Application;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Prettus\Repository\Contracts\CriteriaInterface;
use Prettus\Repository\Contracts\Presentable;
use Prettus\Repository\Contracts\PresentableInterface;
use Prettus\Repository\Contracts\PresenterInterface;
use Prettus\Repository\Contracts\RepositoryCriteriaInterface;
use Prettus\Repository\Contracts\RepositoryInterface;
use Prettus\Repository\Events\RepositoryEntityCreated;
use Prettus\Repository\Events\RepositoryEntityDeleted;
use Prettus\Repository\Events\RepositoryEntityUpdated;
use Prettus\Repository\Exceptions\RepositoryException;
use Prettus\Repository\Traits\ComparesVersionsTrait;
use Prettus\Validator\Contracts\ValidatorInterface;
use Prettus\Validator\Exceptions\ValidatorException;
use GraphQLClient\HttpClient as GraphQLClient;
use GraphQLClient\Util\DatableHttpException;
use QueryBuilder\Builder;

/**
 * Class BaseRepository
 * @package Prettus\Repository\GraphQL
 */
abstract class BaseRepository implements RepositoryInterface, RepositoryCriteriaInterface
{
    const ALL               = 'all';
    const CREATE            = 'create';
    const UPDATE            = 'update';
    const DELETE            = 'delete';
    const UPDATE_OR_CREATE  = 'updateOrCreate';

    /**
     * @var Application
     */
    protected $app;

    /**
     * @var
     */
    protected $model;

    /**
     * @var string
     */
    protected $end_point;

    /**
     * @var string
     */
    protected $api_key;

    /**
     * @var GraphQLClient
     */
    protected $client;

    /**
     * @var Builder
     */
    protected $query_builder;

    /**
     * @var Builder
     */
    protected $mutation_builder;

    /**
     * @var string
     */
    protected $type;

    /**
     * @var array
     */
    public $arguments;

    /**
     * Collection of Criteria
     *
     * @var Collection
     */
    protected $criteria;

    /**
     * @var bool
     */
    protected $skipCriteria = false;

    /**
     * @var array
     */
    protected $fieldSearchable = [];

    /**
     * @var PresenterInterface
     */
    protected $presenter;

    /**
     * @var ValidatorInterface
     */
    protected $validator;

    /**
     * Validation Rules
     *
     * @var array
     */
    protected $rules = null;

    /**
     * @var bool
     */
    protected $skipPresenter = false;

    /**
     * @var \Closure
     */
    protected $scopeQuery = null;

    use ResponseFormatUtility;

    /**
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->criteria = new Collection();
        $this->makeModel();
        $this->makeGraphQL();
        $this->makeBuilder();
        $this->makePresenter();
        $this->makeValidator();
        $this->boot();
    }

    /**
     * @return Model
     * @throws RepositoryException
     */
    public function makeModel()
    {
        $model = $this->app->make($this->model());

        /*if (!$model instanceof Model) {
            throw new RepositoryException("Class {$this->model()} must be an instance of Illuminate\\Database\\Eloquent\\Model");
        }*/

        return $this->model = $model;
    }

    /**
     * @return GraphQLClient
     * @throws RepositoryException
     */
    public function makeGraphQL()
    {
        $this->end_point = config('repository.graphql.end_point');
        if (is_null($this->end_point))
            throw new RepositoryException('End point for GraphQL must be configured');

        $this->api_key = config('repository.graphql.api_key');
        if (is_null($this->api_key))
            throw new RepositoryException('API key for GraphQL must be configured');

        $this->client = new GraphQLClient($this->end_point);
        $this->client->setApiKey($this->api_key);

        return $this->client;
    }

    /**
     * @return GraphQLClient
     * @throws RepositoryException
     */
    public function makeBuilder()
    {
        $this->query_builder    = Builder::createQueryBuilder();
        $this->mutation_builder = Builder::createMutationBuilder();
    }

    /**
     * @throws RepositoryException
     */
    public function boot()
    {
        $this->setType($this->type);
        if ($this->type === null)
            throw new RepositoryException('Type must be defined');
    }

    /**
     * @param $type
     */
    public function setType($type)
    {
        $this->type = str_plural(ucfirst($type));
    }

    /**
     * Specify Presenter class name
     *
     * @return string
     */
    public function presenter()
    {
        return null;
    }

    /**
     * Specify Validator class name of Prettus\Validator\Contracts\ValidatorInterface
     *
     * @return null
     * @throws Exception
     */
    public function validator()
    {
        if (isset($this->rules) && !is_null($this->rules) && is_array($this->rules) && !empty($this->rules)) {
            if (class_exists('Prettus\Validator\LaravelValidator')) {
                $validator = app('Prettus\Validator\LaravelValidator');
                if ($validator instanceof ValidatorInterface) {
                    $validator->setRules($this->rules);

                    return $validator;
                }
            } else {
                throw new Exception(trans('repository::packages.prettus_laravel_validation_required'));
            }
        }

        return null;
    }

    /**
     * Set Presenter
     *
     * @param $presenter
     *
     * @return $this
     */
    public function setPresenter($presenter)
    {
        $this->makePresenter($presenter);

        return $this;
    }

    /**
     * @param null $presenter
     *
     * @return PresenterInterface
     * @throws RepositoryException
     */
    public function makePresenter($presenter = null)
    {
        $presenter = !is_null($presenter) ? $presenter : $this->presenter();

        if (!is_null($presenter)) {
            $this->presenter = is_string($presenter) ? $this->app->make($presenter) : $presenter;

            if (!$this->presenter instanceof PresenterInterface) {
                throw new RepositoryException("Class {$presenter} must be an instance of Prettus\\Repository\\Contracts\\PresenterInterface");
            }

            return $this->presenter;
        }

        return null;
    }

    /**
     * @param null $validator
     *
     * @return null|ValidatorInterface
     * @throws RepositoryException
     */
    public function makeValidator($validator = null)
    {
        $validator = !is_null($validator) ? $validator : $this->validator();

        if (!is_null($validator)) {
            $this->validator = is_string($validator) ? $this->app->make($validator) : $validator;

            if (!$this->validator instanceof ValidatorInterface) {
                throw new RepositoryException("Class {$validator} must be an instance of Prettus\\Validator\\Contracts\\ValidatorInterface");
            }

            return $this->validator;
        }

        return null;
    }

    /**
     * Get Searchable Fields
     *
     * @return array
     */
    public function getFieldsSearchable()
    {
        return $this->fieldSearchable;
    }

    /**
     * Query Scope
     *
     * @param \Closure $scope
     *
     * @return $this
     */
    public function scopeQuery(\Closure $scope)
    {
        $this->scopeQuery = $scope;

        return $this;
    }

    /**
     * Retrieve data array for populate field select
     *
     * @param string      $column
     * @param string|null $key
     *
     * @return \Illuminate\Support\Collection|array
     * @deprecated since version laravel 5.2. Use the "pluck" method directly.
     */
    public function lists($column, $key = null)
    {
        return $this->pluck($column);
    }

    /**
     * Retrieve data array for populate field select
     *
     * @param string $column
     * @param null $key
     * @return mixed
     */
    public function pluck($column, $key = null)
    {
        $this->applyCriteria();
        $this->applyScope();

        $results = $this->buildQuery(self::ALL.$this->type, $column);

        return $this->parserResult($results);
    }

    /**
     * Sync relations
     *
     * @param $id
     * @param $relation
     * @param $attributes
     * @param bool $detaching
     * @return mixed
     */
    public function sync($id, $relation, $attributes, $detaching = true)
    {
        return $this->find($id)->{$relation}()->sync($attributes, $detaching);
    }

    /**
     * SyncWithoutDetaching
     *
     * @param $id
     * @param $relation
     * @param $attributes
     * @return mixed
     */
    public function syncWithoutDetaching($id, $relation, $attributes)
    {
        return $this->sync($id, $relation, $attributes, false);
    }

    /**
     * Retrieve all data of repository
     *
     * @param array $columns
     *
     * @return mixed
     */
    public function all($columns = [])
    {
        if (empty($columns)){
            $columns = $this->model->getFields();
        }

        $this->applyCriteria();
        $this->applyScope();

        $results = $this->buildQuery(self::ALL.$this->type, $columns);

        return $this->parserResult($results);
    }


    /**
     * Retrieve first data of repository
     *
     * @param array $filter | ['field' => 'value']
     * @param array $columns
     *
     * @return mixed
     */
    public function first(array $filter, $columns = [], $number = 1)
    {
        if (empty($columns)){
            $columns = $this->model->getFields();
        }

        $this->applyCriteria();
        $this->applyScope();

        /*
         * Example
         * ['isWhiteLabel' => 1]
         *
         * For first -
         * [['isWhiteLabel' => 1], ['first' => 1]]
         */
        $filter['first'] = $number; // first number of results
        $this->arguments = ['filter' => $filter];

        $results = $this->buildQuery(self::ALL.$this->type, $columns);

        return $this->parserResult($results);
    }

    /**
     * Retrieve first data of repository, or return new Entity
     *
     * @param array $attributes
     *
     * @return mixed
     */
    public function firstOrNew(array $attributes = [])
    {
        // Not implemented yet on GraphCool
    }

    /**
     * Retrieve first data of repository, or create new Entity
     *
     * @param array $attributes
     *
     * @return mixed
     */
    public function firstOrCreate(array $attributes = [])
    {
        // Not implemented yet on GraphCool
    }

    /**
     * Retrieve all data of repository, paginated
     *
     * @param null   $limit
     * @param array  $columns
     * @param string $pageName
     * @param string $method
     *
     * @return mixed
     */
    public function paginate($limit = null, $columns = ['*'], $pageName = 'page', $method = "paginate")
    {
        $this->applyCriteria();
        $this->applyScope();
        $limit = is_null($limit) ? config('repository.pagination.limit', 15) : $limit;
        $results = $this->model->{$method}($limit, $columns, $pageName);
        $results->appends(app('request')->query());
        $this->resetModel();

        return $this->parserResult($results);
    }

    /**
     * Retrieve all data of repository, simple paginated
     *
     * @param null  $limit
     * @param array $columns
     *
     * @return mixed
     */
    public function simplePaginate($limit = null, $columns = ['*'])
    {
        return $this->paginate($limit, $columns, "simplePaginate");
    }

    /**
     * Find data by id
     *
     * @param       $id
     * @param array $columns
     *
     * @return mixed
     */
    public function find($id, $columns = [])
    {
        if (empty($columns)){
            $columns = $this->model->getFields();
        }

        $this->applyScope();

        $this->arguments = ['id' => $id];
        $results = $this->buildQuery(str_singular($this->type), $columns);

        return $this->parserResult($results);
    }

    /**
     * Find data by field and value
     *
     * @param       $field
     * @param       $value
     * @param array $columns
     *
     * @return mixed
     */
    public function findByField($field, $value = null, $columns = [])
    {
        if (empty($columns)){
            $columns = $this->model->getFields();
        }

        $this->applyScope();

        $this->arguments = ['filter' => [$field => $value]];
        $results = $this->buildQuery(self::ALL.$this->type, $columns);

        return $this->parserResult($results);
    }

    /**
     * Find data by multiple fields
     *
     * @param array $filter | ['field1' => 'value1', 'field2' => 'value2']
     * @param array $columns
     *
     * @return mixed
     */
    public function findWhere(array $filter, $columns = [])
    {
        if (empty($columns)){
            $columns = $this->model->getFields();
        }

        $this->applyScope();

        $this->arguments = ['filter' => $filter];
        $results = $this->buildQuery(self::ALL.$this->type, $columns);

        return $this->parserResult($results);
    }

    /**
     * Find data by multiple values in one field
     *
     * @param       $field
     * @param array $values
     * @param array $columns
     *
     * @return mixed
     */
    public function findWhereIn($field, array $values, $columns = [])
    {
        if (empty($columns)){
            $columns = $this->model->getFields();
        }

        $this->applyScope();

        $field = $field.'_in';
        $this->arguments = ['filter' => [$field => $values]];
        $results = $this->buildQuery(self::ALL.$this->type, $columns);

        return $this->parserResult($results);
    }

    /**
     * Find data by excluding multiple values in one field
     *
     * @param       $field
     * @param array $values
     * @param array $columns
     *
     * @return mixed
     */
    public function findWhereNotIn($field, array $values, $columns = [])
    {
        if (empty($columns)){
            $columns = $this->model->getFields();
        }

        $this->applyScope();

        $field = $field.'_not_in';
        $this->arguments = ['filter' => [$field => $values]];
        $results = $this->buildQuery(self::ALL.$this->type, $columns);

        return $this->parserResult($results);
    }

    /**
     * Save a new entity in repository
     *
     * @param array $attributes
     * @param array $columns
     * @return mixed
     */
    public function create(array $attributes, $columns = [])
    {
        if (empty($columns)){
            $columns = $this->model->getFields();
        }

        if (!is_null($this->validator)) {
            $this->validator->with($attributes)->passesOrFail(ValidatorInterface::RULE_CREATE);
        }

        $this->arguments = $attributes;

        $result = $this->buildMutation(self::CREATE.$this->type, $columns);

        //event(new RepositoryEntityCreated($this, $result));

        return $this->parserResult($result);
    }

    /**
     * Update a entity in repository by id
     *
     * @param array $attributes
     * @param $id
     * @param array $columns
     * @return mixed
     */
    public function update(array $attributes, $id, $columns = [])
    {
        if (empty($columns)){
            $columns = $this->model->getFields();
        }

        $this->applyScope();
//
//        if (!is_null($this->validator)) {
//            $this->validator->with($attributes)->setId($id)->passesOrFail(ValidatorInterface::RULE_UPDATE);
//        }

        $this->arguments = $attributes;
        $this->arguments['id'] = $id;

        $result = $this->buildMutation(self::UPDATE.str_singular($this->type), $columns);

        return $this->parserResult($result);
    }

    /**
     * Update or Create an entity in repository
     *
     * @param array $attributes
     * @param array $values
     * @param array $columns
     * @return mixed
     */
    public function updateOrCreate(array $attributes, array $values = [], $columns = [])
    {
        if (empty($columns)){
            $columns = $this->model->getFields();
        }

        $this->applyScope();

        /********
         * Sample valid object
         * mutation {
                updateOrCreateWidget (
                    update: {
                        id: "cj6dbmbs3lux80197gfrz9va1"
                        accountId: "cj5fmyfk1hw3r0138b2is5cyj"
                    }
                    create: {
                        type: Form
                        name: "Raviv"
                        languageCode: "HE"
                        countryCode: "IL"
                        accountId: "cj5fmyfk1hw3r0138b2is5cyj"
                    }
                ){
                    name
                }
        }
         ********/

        $this->arguments = $attributes;

        $result = $this->buildMutation(self::UPDATE_OR_CREATE.str_singular($this->type), $columns);

        return $this->parserResult($result);
    }

    /**
     * Delete a entity in repository by id
     *
     * @param $id
     *
     * @return int
     */
    public function delete($id)
    {
        $this->applyScope();

//        $temporarySkipPresenter = $this->skipPresenter;
//        $this->skipPresenter(true);
//
//        $model = $this->model->findOrFail($id);
//        $originalModel = clone $model;
//
//        $this->skipPresenter($temporarySkipPresenter);
//        $this->resetModel();

        $this->arguments = ['id' => $id];

        $result = $this->buildMutation(self::DELETE.$this->type, $this->model->getFields());

        return $this->parserResult($result);
    }

    /**
     * Delete multiple entities by given criteria.
     *
     * @param array $where
     *
     * @return int
     */
    public function deleteWhere(array $where)
    {
        // Not implemented yet on GraphCool
    }

    /**
     * Check if entity has relation
     *
     * @param string $relation
     *
     * @return $this
     */
    public function has($relation)
    {
        // Not implemented yet on GraphCool
    }

    /**
     * Load relations
     *
     * @param array|string $relations
     *
     * @return $this
     */
    public function with($relations)
    {
        // Not implemented yet on GraphCool
    }

    /**
     * Add subselect queries to count the relations.
     *
     * @param  mixed $relations
     * @return $this
     */
    public function withCount($relations)
    {
        // Not implemented yet on GraphCool
    }

    /**
     * Load relation with closure
     *
     * @param string $relation
     * @param closure $closure
     *
     * @return $this
     */
    function whereHas($relation, $closure)
    {
        // Not implemented yet on GraphCool
    }

    /**
     * Set hidden fields
     *
     * @param array $fields
     *
     * @return $this
     */
    public function hidden(array $fields)
    {
        $this->model->setHidden($fields);

        return $this;
    }

    /**
     * Order collection by a given column
     *
     * @param string $column
     * @param string $direction
     *
     * @return $this
     */
    public function orderBy($column, $direction = 'asc')
    {
        $this->model = $this->model->orderBy($column, $direction);

        return $this;
    }

    /**
     * Add an "order by" clause for a timestamp to the query.
     *
     * @param string $column
     *
     * @return $this
     */
    public function latest($column = 'created_at')
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Add an "order by" clause for a timestamp to the query.
     *
     * @param string $column
     *
     * @return $this
     */
    public function oldest($column = 'created_at')
    {
        return $this->orderBy($column, 'asc');
    }


    /**
     * Set visible fields
     *
     * @param array $fields
     *
     * @return $this
     */
    public function visible(array $fields)
    {
        $this->model->setVisible($fields);

        return $this;
    }

    /**
     * Push Criteria for filter the query
     *
     * @param $criteria
     *
     * @return $this
     * @throws \Prettus\Repository\Exceptions\RepositoryException
     */
    public function pushCriteria($criteria)
    {
        if (is_string($criteria)) {
            $criteria = new $criteria;
        }
        if (!$criteria instanceof CriteriaInterface) {
            throw new RepositoryException("Class " . get_class($criteria) . " must be an instance of Prettus\\Repository\\Contracts\\CriteriaInterface");
        }
        $this->criteria->push($criteria);

        return $this;
    }

    /**
     * Pop Criteria
     *
     * @param $criteria
     *
     * @return $this
     */
    public function popCriteria($criteria)
    {
        $this->criteria = $this->criteria->reject(function ($item) use ($criteria) {
            if (is_object($item) && is_string($criteria)) {
                return get_class($item) === $criteria;
            }

            if (is_string($item) && is_object($criteria)) {
                return $item === get_class($criteria);
            }

            return get_class($item) === get_class($criteria);
        });

        return $this;
    }

    /**
     * Get Collection of Criteria
     *
     * @return Collection
     */
    public function getCriteria()
    {
        return $this->criteria;
    }

    /**
     * Find data by Criteria
     *
     * @param CriteriaInterface $criteria
     *
     * @return mixed
     */
    public function getByCriteria(CriteriaInterface $criteria)
    {
        $this->model = $criteria->apply($this->model, $this);
        $results = $this->model->get();
        $this->resetModel();

        return $this->parserResult($results);
    }

    /**
     * Skip Criteria
     *
     * @param bool $status
     *
     * @return $this
     */
    public function skipCriteria($status = true)
    {
        $this->skipCriteria = $status;

        return $this;
    }

    /**
     * Reset all Criterias
     *
     * @return $this
     */
    public function resetCriteria()
    {
        $this->criteria = new Collection();

        return $this;
    }

    /**
     * Reset Query Scope
     *
     * @return $this
     */
    public function resetScope()
    {
        $this->scopeQuery = null;

        return $this;
    }

    /**
     * Apply scope in current Query
     *
     * @return $this
     */
    protected function applyScope()
    {
        $this->arguments = null;

        if (isset($this->scopeQuery) && is_callable($this->scopeQuery)) {
            $callback = $this->scopeQuery;
            $this->model = $callback($this->model);
        }

        return $this;
    }

    /**
     * Apply criteria in current Query
     *
     * @return $this
     */
    protected function applyCriteria()
    {
        if ($this->skipCriteria === true) {
            return $this;
        }

        $criteria = $this->getCriteria();

        if ($criteria) {
            if ($criteria instanceof CriteriaInterface) {
                $this->arguments = ['filter' => $criteria->apply($this->model, $this)];
                return $this;
            }
        }

        $this->arguments = null;

        return $this;
    }

    /**
     * Applies the given where conditions to the model.
     *
     * @param array $where
     * @return void
     */
    protected function applyConditions(array $where)
    {
        foreach ($where as $field => $value) {
            if (is_array($value)) {
                list($field, $condition, $val) = $value;
                $this->model = $this->model->where($field, $condition, $val);
            } else {
                $this->model = $this->model->where($field, '=', $value);
            }
        }
    }

    /**
     * Skip Presenter Wrapper
     *
     * @param bool $status
     *
     * @return $this
     */
    public function skipPresenter($status = true)
    {
        $this->skipPresenter = $status;

        return $this;
    }

    /**
     * Wrapper result data
     *
     * @param mixed $result
     *
     * @return mixed
     */
    public function parserResult($result)
    {
        if ($this->presenter instanceof PresenterInterface) {

            if ($result instanceof Collection || $result instanceof LengthAwarePaginator) {
                $result->each(function ($model) {
                    if ($model instanceof Presentable) {
                        $model->setPresenter($this->presenter);
                    }

                    return $model;
                });
            } elseif ($result instanceof Presentable) {
                $result = $result->setPresenter($this->presenter);
            }

            if (!$this->skipPresenter) {
                return $this->presenter->present($result);
            }
        }

        return $result;
    }

    /**
     * @param $name
     * @param array $body
     * @return mixed
     * @throws DatableHttpException
     */
    public function buildQuery($name, $body = [])
    {
        $this->query_builder->resetArguments();
        $this->query_builder->resetName();
        $this->query_builder->resetBody();

        $this->query_builder->name($name)->body($body);

        if (!is_null($this->arguments)) {
            $this->query_builder->arguments($this->arguments);
        }

        $this->query_builder->build();

        try {
            return $this->contextResponse(1, null, $this->client->call($this->query_builder));
        } catch (DatableHttpException $e) {
            return $e->contextResponse(0, 'GraphQL error: ', $e->getMessage());
        }
    }

    /**
     * @param $name
     * @param array $body
     * @return mixed
     * @throws DatableHttpException
     */
    public function buildMutation($name, $body = [])
    {
        $this->mutation_builder->resetArguments();
        $this->mutation_builder->resetName();
        $this->mutation_builder->resetBody();

        $name = str_singular($name);
        $this->mutation_builder->name($name)->body($body);

        if (!is_null($this->arguments)) {
            $this->mutation_builder->arguments($this->arguments);
        }

        try {
            return $this->contextResponse(1, null, $this->client->call($this->mutation_builder));
        } catch (DatableHttpException $e) {
            //throw new DatableHttpException($e->getMessage());
            return $e->contextResponse(0, 'GraphQL error: ', $e->getMessage());
        }
    }
}

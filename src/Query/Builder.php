<?php

namespace Jsl\Database\Query;

use Closure;
use Exception;
use Jsl\Database\Collections\Collection;
use Jsl\Database\Collections\Paginate;
use Jsl\Database\ConnectionInterface;
use Jsl\Database\Query\Grammars\Grammar;
use Jsl\Database\Query\Grammars\MySqlGrammar;

class Builder
{

    /**
     * The database connection instance.
     *
     * @var \Jsl\Database\Connection
     */
    protected $connection;

    /**
     * The database query grammar instance.
     *
     * @var \Jsl\Database\Query\Grammars\Grammar
     */
    protected $grammar;

    /**
     * The current query value bindings.
     *
     * @var array
     */
    protected $bindings = array(
        'select' => [],
        'join' => [],
        'where' => [],
        'having' => [],
        'order' => [],
    );

    /**
     * Used for fetchClass (if set)
     *
     * @var ?string
     */
    protected ?string $fetchClass = null;

    /**
     * Model class to use for records (data passed through constructor)
     *
     * @var ?string
     */
    protected ?string $modelClass = null;

    /**
     * Transformer to use when using model class
     *
     * @var object|array|string|null
     */
    protected object|array|string|null $modelTransformer = null;

    /**
     * @var bool
     */
    protected bool $returnCollection = false;

    /**
     * Some get-requests needs a default array, so set this to
     * true while making those requests
     *
     * @var bool
     */
    protected bool $ignoreModelAndCollection = false;

    /**
     * An aggregate function and column to be run.
     *
     * @var array
     */
    public $aggregate;

    /**
     * The columns that should be returned.
     *
     * @var array
     */
    public $columns;

    /**
     * Indicates if the query returns distinct results.
     *
     * @var bool
     */
    public $distinct = false;

    /**
     * The table which the query is targeting.
     *
     * @var string
     */
    public $from;

    /**
     * The table joins for the query.
     *
     * @var array
     */
    public $joins;

    /**
     * The where constraints for the query.
     *
     * @var array
     */
    public $wheres;

    /**
     * The groupings for the query.
     *
     * @var array
     */
    public $groups;

    /**
     * Roll up groups.
     *
     * @var bool
     */
    public $rollup;

    /**
     * The having constraints for the query.
     *
     * @var array
     */
    public $havings;

    /**
     * The orderings for the query.
     *
     * @var array
     */
    public $orders;

    /**
     * The maximum number of records to return.
     *
     * @var int
     */
    public $limit;

    /**
     * The number of records to skip.
     *
     * @var int
     */
    public $offset;

    /**
     * The query union statements.
     *
     * @var array
     */
    public $unions;

    /**
     * Indicates whether row locking is being used.
     *
     * @var string|bool
     */
    public $lock;

    /**
     * Should the query select into an outfile
     *
     * @var
     */
    public $outfile;

    /**
     * Should the insert be from an infile
     *
     * @var
     */
    public $infile;

    /**
     * The backups of fields while doing a pagination count.
     *
     * @var array
     */
    protected $backups = array();

    /**
     * When creating an insert buffer, use this as the default chunk size
     *
     * @var int
     */
    protected $defaultChunkSize = 1000;


    /**
     * All of the available clause operators.
     *
     * @var array
     */
    protected $operators = array(
        '=', '<', '>', '<=', '>=', '<>', '!=',
        'like', 'not like', 'between', 'ilike',
        '&', '|', '^', '<<', '>>',
        'rlike', 'regexp', 'not regexp',
    );

    /**
     * Create a new query builder instance.
     *
     * Builder constructor.
     * @param ConnectionInterface $connection
     * @param Grammar $grammar
     */
    public function __construct(
        ConnectionInterface $connection,
        Grammar $grammar
    ) {
        $this->grammar = $grammar;
        $this->connection = $connection;
    }

    /**
     * Set the columns to be selected.
     *
     * @param  array $columns
     * @return $this
     */
    public function select($columns = array('*'))
    {
        $this->columns = is_array($columns) ? $columns : func_get_args();

        return $this;
    }


    /**
     * Add a new "raw" select expression to the query.
     *
     * @param  string $expression
     * @param  array $bindings
     * @return \Jsl\Database\Query\Builder|static
     */
    public function selectRaw($expression, array $bindings = array())
    {
        $this->addSelect(new Expression($expression));

        if ($bindings) {
            $this->addBinding($bindings, 'select');
        }

        return $this;
    }

    /**
     * Add a subselect expression to the query.
     *
     * @param  \Closure|\Database\Query\Builder|string $query
     * @param  string $as
     * @return \Jsl\Database\Query\Builder|static
     */
    public function selectSub($query, $as)
    {
        if ($query instanceof Closure) {
            $callback = $query;

            $callback($query = $this->newQuery());
        }

        if ($query instanceof Builder) {
            $bindings = $query->getBindings();

            $query = $query->toSql();
        } elseif (is_string($query)) {
            $bindings = [];
        } else {
            throw new \InvalidArgumentException;
        }

        return $this->selectRaw('(' . $query . ') as ' . $this->grammar->wrap($as), $bindings);
    }

    /**
     * Add a new select column to the query.
     *
     * @param  mixed $column
     * @return $this
     */
    public function addSelect($column)
    {
        $column = is_array($column) ? $column : func_get_args();

        $this->columns = array_merge((array)$this->columns, $column);

        return $this;
    }

    /**
     * Enables FETCH_CLASS mode on the result
     *
     * @param string|null $className
     *
     * @return self
     */
    public function fetchClass(?string $className): self
    {
        $this->fetchClass = $className;

        return $this;
    }

    /**
     * Convert records to models (data injected through the constructor)
     *
     * @param string|null $modelName
     * @param callable $transformer Transformer to use for the data
     *
     * @return self
     */
    public function model(?string $modelClass = null, callable $transformer = null): self
    {
        $this->modelClass = $modelClass;
        $this->modelTransformer = $transformer;

        return $this;
    }

    /**
     * Get data as a collection instead of an array
     *
     * @param string|null $modelClass
     * @param callable|null $transformer
     *
     * @return self
     */
    public function collection(?string $modelClass = null, callable $transformer = null): self
    {
        if ($modelClass !== null) {
            $this->modelClass = $modelClass;
        }

        if ($transformer !== null) {
            $this->modelTransformer = $transformer;
        }

        $this->returnCollection = true;

        return $this;
    }

    /**
     * Force the query to only return distinct results.
     *
     * @return $this
     */
    public function distinct()
    {
        $this->distinct = true;

        return $this;
    }

    /**
     * Set the table which the query is targeting.
     *
     * @param  string $table
     * @return $this
     */
    public function from($table)
    {
        $this->from = $table;

        return $this;
    }

    /**
     * Add a join clause to the query.
     *
     * @param  string $table
     * @param  string $one
     * @param  string $operator
     * @param  string $two
     * @param  string $type
     * @param  bool $where
     * @return $this
     */
    public function join($table, $one, $operator = null, $two = null, $type = 'inner', $where = false)
    {
        $this->joins[] = $join = new JoinClause($type, $table);

        // If the first "column" of the join is really a Closure instance the developer
        // is trying to build a join with a complex "on" clause containing more than
        // one condition, so we'll add the join and call a Closure with the query.
        if ($one instanceof Closure) {
            call_user_func($one, $join);
        }

        // If the column is simply a string, we can assume the join simply has a basic
        // "on" clause with a single condition. So we will just build the join with
        // this simple join clauses attached to it. There is not a join callback.
        else {
            $join->on($one, $operator, $two, 'and', $where);
        }

        return $this;
    }

    /**
     * Add a "join where" clause to the query.
     *
     * @param  string $table
     * @param  string $one
     * @param  string $operator
     * @param  string $two
     * @param  string $type
     * @return \Jsl\Database\Query\Builder|static
     */
    public function joinWhere($table, $one, $operator, $two, $type = 'inner')
    {
        return $this->join($table, $one, $operator, $two, $type, true);
    }

    /**
     * Add a left join to the query.
     *
     * @param  string $table
     * @param  string $first
     * @param  string $operator
     * @param  string $second
     * @return \Jsl\Database\Query\Builder|static
     */
    public function leftJoin($table, $first, $operator = null, $second = null)
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    /**
     * Add a "join where" clause to the query.
     *
     * @param  string $table
     * @param  string $one
     * @param  string $operator
     * @param  string $two
     * @return \Jsl\Database\Query\Builder|static
     */
    public function leftJoinWhere($table, $one, $operator, $two)
    {
        return $this->joinWhere($table, $one, $operator, $two, 'left');
    }

    /**
     * Add a right join to the query.
     *
     * @param  string $table
     * @param  string $first
     * @param  string $operator
     * @param  string $second
     * @return \Jsl\Database\Query\Builder|static
     */
    public function rightJoin($table, $first, $operator = null, $second = null)
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    /**
     * Add a "right join where" clause to the query.
     *
     * @param  string $table
     * @param  string $one
     * @param  string $operator
     * @param  string $two
     * @return \Jsl\Database\Query\Builder|static
     */
    public function rightJoinWhere($table, $one, $operator, $two)
    {
        return $this->joinWhere($table, $one, $operator, $two, 'right');
    }

    /**
     * Add a basic where clause to the query.
     *
     * @param  string $column
     * @param  string $operator
     * @param  mixed $value
     * @param  string $boolean
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        // If the column is an array, we will assume it is an array of key-value pairs
        // and can add them each as a where clause. We will maintain the boolean we
        // received when the method was called and pass it into the nested where.
        if (is_array($column)) {
            return $this->whereNested(function (Builder $query) use ($column) {
                foreach ($column as $key => $value) {
                    $query->where($key, '=', $value);
                }
            }, $boolean);
        }

        // Here we will make some assumptions about the operator. If only 2 values are
        // passed to the method, we will assume that the operator is an equals sign
        // and keep going. Otherwise, we'll require the operator to be passed in.
        if (func_num_args() == 2) {
            list($value, $operator) = array($operator, '=');
        }

        // If the columns is actually a Closure instance, we will assume the developer
        // wants to begin a nested where statement which is wrapped in parenthesis.
        // We'll add that Closure to the query then return back out immediately.
        if ($column instanceof Closure) {
            return $this->whereNested($column, $boolean);
        }

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if (!in_array(strtolower($operator), $this->operators, true)) {
            list($value, $operator) = array($operator, '=');
        }

        // If the value is a Closure, it means the developer is performing an entire
        // sub-select within the query and we will need to compile the sub-select
        // within the where clause to get the appropriate query record results.
        if ($value instanceof Closure) {
            return $this->whereSub($column, $operator, $value, $boolean);
        }

        // If the value is "null", we will just assume the developer wants to add a
        // where null clause to the query. So, we will allow a short-cut here to
        // that method for convenience so the developer doesn't have to check.
        if (is_null($value)) {
            return $this->whereNull($column, $boolean, $operator != '=');
        }

        // Now that we are working with just a simple query we can put the elements
        // in our array and add the query binding to our array of bindings that
        // will be bound to each SQL statements when it is finally executed.
        $type = 'Basic';

        $this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

        if (!$value instanceof Expression) {
            $this->addBinding($value, 'where');
        }

        return $this;
    }

    /**
     * Add an "or where" clause to the query.
     *
     * @param  string $column
     * @param  string $operator
     * @param  mixed $value
     * @return \Jsl\Database\Query\Builder|static
     */
    public function orWhere($column, $operator = null, $value = null)
    {
        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * Add a raw where clause to the query.
     *
     * @param  string $sql
     * @param  array $bindings
     * @param  string $boolean
     * @return $this
     */
    public function whereRaw($sql, array $bindings = array(), $boolean = 'and')
    {
        $type = 'raw';

        $this->wheres[] = compact('type', 'sql', 'boolean');

        $this->addBinding($bindings, 'where');

        return $this;
    }

    /**
     * Add a raw or where clause to the query.
     *
     * @param  string $sql
     * @param  array $bindings
     * @return \Jsl\Database\Query\Builder|static
     */
    public function orWhereRaw($sql, array $bindings = array())
    {
        return $this->whereRaw($sql, $bindings, 'or');
    }

    /**
     * Add a where between statement to the query.
     *
     * @param  string $column
     * @param  array $values
     * @param  string $boolean
     * @param  bool $not
     * @return $this
     */
    public function whereBetween($column, array $values, $boolean = 'and', $not = false)
    {
        $type = 'between';

        $this->wheres[] = compact('column', 'type', 'boolean', 'not');

        $this->addBinding($values, 'where');

        return $this;
    }

    /**
     * Add an or where between statement to the query.
     *
     * @param  string $column
     * @param  array $values
     * @return \Jsl\Database\Query\Builder|static
     */
    public function orWhereBetween($column, array $values)
    {
        return $this->whereBetween($column, $values, 'or');
    }

    /**
     * Add a where not between statement to the query.
     *
     * @param  string $column
     * @param  array $values
     * @param  string $boolean
     * @return \Jsl\Database\Query\Builder|static
     */
    public function whereNotBetween($column, array $values, $boolean = 'and')
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    /**
     * Add an or where not between statement to the query.
     *
     * @param  string $column
     * @param  array $values
     * @return \Jsl\Database\Query\Builder|static
     */
    public function orWhereNotBetween($column, array $values)
    {
        return $this->whereNotBetween($column, $values, 'or');
    }

    /**
     * Add a nested where statement to the query.
     *
     * @param  \Closure $callback
     * @param  string $boolean
     * @return \Jsl\Database\Query\Builder|static
     */
    public function whereNested(Closure $callback, $boolean = 'and')
    {
        // To handle nested queries we'll actually create a brand new query instance
        // and pass it off to the Closure that we have. The Closure can simply do
        // do whatever it wants to a query then we will store it for compiling.
        $query = $this->newQuery();

        $query->from($this->from);

        call_user_func($callback, $query);

        return $this->addNestedWhereQuery($query, $boolean);
    }

    /**
     * Add another query builder as a nested where to the query builder.
     *
     * @param  \Jsl\Database\Query\Builder|static $query
     * @param  string $boolean
     * @return $this
     */
    public function addNestedWhereQuery(Builder $query, $boolean = 'and')
    {
        if (count($query->wheres)) {
            $type = 'Nested';

            $this->wheres[] = compact('type', 'query', 'boolean');

            $this->mergeBindings($query);
        }

        return $this;
    }

    /**
     * Add a full sub-select to the query.
     *
     * @param  string $column
     * @param  string $operator
     * @param  \Closure $callback
     * @param  string $boolean
     * @return $this
     */
    protected function whereSub($column, $operator, Closure $callback, $boolean)
    {
        $type = 'Sub';

        $query = $this->newQuery();

        // Once we have the query instance we can simply execute it so it can add all
        // of the sub-select's conditions to itself, and then we can cache it off
        // in the array of where clauses for the "main" parent query instance.
        call_user_func($callback, $query);

        $this->wheres[] = compact('type', 'column', 'operator', 'query', 'boolean');

        $this->mergeBindings($query);

        return $this;
    }

    /**
     * Add an exists clause to the query.
     *
     * @param  \Closure $callback
     * @param  string $boolean
     * @param  bool $not
     * @return $this
     */
    public function whereExists(Closure $callback, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotExists' : 'Exists';

        $query = $this->newQuery();

        // Similar to the sub-select clause, we will create a new query instance so
        // the developer may cleanly specify the entire exists query and we will
        // compile the whole thing in the grammar and insert it into the SQL.
        call_user_func($callback, $query);

        $this->wheres[] = compact('type', 'operator', 'query', 'boolean');

        $this->mergeBindings($query);

        return $this;
    }

    /**
     * Add an or exists clause to the query.
     *
     * @param  \Closure $callback
     * @param  bool $not
     * @return \Jsl\Database\Query\Builder|static
     */
    public function orWhereExists(Closure $callback, $not = false)
    {
        return $this->whereExists($callback, 'or', $not);
    }

    /**
     * Add a where not exists clause to the query.
     *
     * @param  \Closure $callback
     * @param  string $boolean
     * @return \Jsl\Database\Query\Builder|static
     */
    public function whereNotExists(Closure $callback, $boolean = 'and')
    {
        return $this->whereExists($callback, $boolean, true);
    }

    /**
     * Add a where not exists clause to the query.
     *
     * @param  \Closure $callback
     * @return \Jsl\Database\Query\Builder|static
     */
    public function orWhereNotExists(Closure $callback)
    {
        return $this->orWhereExists($callback, true);
    }

    /**
     * Add a "where in" clause to the query.
     *
     * @param  string $column
     * @param  mixed $values
     * @param  string $boolean
     * @param  bool $not
     * @return $this
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotIn' : 'In';

        // If the value of the where in clause is actually a Closure, we will assume that
        // the developer is using a full sub-select for this "in" statement, and will
        // execute those Closures, then we can re-construct the entire sub-selects.
        if ($values instanceof Closure) {
            return $this->whereInSub($column, $values, $boolean, $not);
        }

        $this->wheres[] = compact('type', 'column', 'values', 'boolean');

        $this->addBinding($values, 'where');

        return $this;
    }

    /**
     * Add an "or where in" clause to the query.
     *
     * @param  string $column
     * @param  mixed $values
     * @return \Jsl\Database\Query\Builder|static
     */
    public function orWhereIn($column, $values)
    {
        return $this->whereIn($column, $values, 'or');
    }

    /**
     * Add a "where not in" clause to the query.
     *
     * @param  string $column
     * @param  mixed $values
     * @param  string $boolean
     * @return \Jsl\Database\Query\Builder|static
     */
    public function whereNotIn($column, $values, $boolean = 'and')
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * Add an "or where not in" clause to the query.
     *
     * @param  string $column
     * @param  mixed $values
     * @return \Jsl\Database\Query\Builder|static
     */
    public function orWhereNotIn($column, $values)
    {
        return $this->whereNotIn($column, $values, 'or');
    }

    /**
     * Add a where in with a sub-select to the query.
     *
     * @param  string $column
     * @param  \Closure $callback
     * @param  string $boolean
     * @param  bool $not
     * @return $this
     */
    protected function whereInSub($column, Closure $callback, $boolean, $not)
    {
        $type = $not ? 'NotInSub' : 'InSub';

        // To create the exists sub-select, we will actually create a query and call the
        // provided callback with the query so the developer may set any of the query
        // conditions they want for the in clause, then we'll put it in this array.
        call_user_func($callback, $query = $this->newQuery());

        $this->wheres[] = compact('type', 'column', 'query', 'boolean');

        $this->mergeBindings($query);

        return $this;
    }

    /**
     * Add a "where null" clause to the query.
     *
     * @param  string $column
     * @param  string $boolean
     * @param  bool $not
     * @return $this
     */
    public function whereNull($column, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotNull' : 'Null';

        $this->wheres[] = compact('type', 'column', 'boolean');

        return $this;
    }

    /**
     * Add an "or where null" clause to the query.
     *
     * @param  string $column
     * @return \Jsl\Database\Query\Builder|static
     */
    public function orWhereNull($column)
    {
        return $this->whereNull($column, 'or');
    }

    /**
     * Add a "where not null" clause to the query.
     *
     * @param  string $column
     * @param  string $boolean
     * @return \Jsl\Database\Query\Builder|static
     */
    public function whereNotNull($column, $boolean = 'and')
    {
        return $this->whereNull($column, $boolean, true);
    }

    /**
     * Add an "or where not null" clause to the query.
     *
     * @param  string $column
     * @return \Jsl\Database\Query\Builder|static
     */
    public function orWhereNotNull($column)
    {
        return $this->whereNotNull($column, 'or');
    }

    /**
     * Add a "where day" statement to the query.
     *
     * @param  string $column
     * @param  string $operator
     * @param  int $value
     * @param  string $boolean
     * @return \Jsl\Database\Query\Builder|static
     */
    public function whereDay($column, $operator, $value, $boolean = 'and')
    {
        return $this->addDateBasedWhere('Day', $column, $operator, $value, $boolean);
    }

    /**
     * Add a "where month" statement to the query.
     *
     * @param  string $column
     * @param  string $operator
     * @param  int $value
     * @param  string $boolean
     * @return \Jsl\Database\Query\Builder|static
     */
    public function whereMonth($column, $operator, $value, $boolean = 'and')
    {
        return $this->addDateBasedWhere('Month', $column, $operator, $value, $boolean);
    }

    /**
     * Add a "where year" statement to the query.
     *
     * @param  string $column
     * @param  string $operator
     * @param  int $value
     * @param  string $boolean
     * @return \Jsl\Database\Query\Builder|static
     */
    public function whereYear($column, $operator, $value, $boolean = 'and')
    {
        return $this->addDateBasedWhere('Year', $column, $operator, $value, $boolean);
    }

    /**
     * Add a date based (year, month, day) statement to the query.
     *
     * @param  string $type
     * @param  string $column
     * @param  string $operator
     * @param  int $value
     * @param  string $boolean
     * @return $this
     */
    protected function addDateBasedWhere($type, $column, $operator, $value, $boolean = 'and')
    {
        $this->wheres[] = compact('column', 'type', 'boolean', 'operator', 'value');

        $this->addBinding($value, 'where');

        return $this;
    }

    /**
     * Add a "group by" clause to the query.
     *
     * @return $this
     */
    public function groupBy()
    {
        foreach (func_get_args() as $arg) {
            $this->groups = array_merge((array)$this->groups, is_array($arg) ? $arg : [$arg]);
        }

        return $this;
    }

    /**
     * Add a "roll up" specification to the "group by" clause.
     *
     * @return $this
     */
    public function withRollUp()
    {
        $this->rollup = true;

        return $this;
    }

    /**
     * Add a "having" clause to the query.
     *
     * @param  string $column
     * @param  string $operator
     * @param  string $value
     * @param  string $boolean
     * @return $this
     */
    public function having($column, $operator = null, $value = null, $boolean = 'and')
    {
        $type = 'basic';

        $this->havings[] = compact('type', 'column', 'operator', 'value', 'boolean');

        $this->addBinding($value, 'having');

        return $this;
    }

    /**
     * Add a "or having" clause to the query.
     *
     * @param  string $column
     * @param  string $operator
     * @param  string $value
     * @return \Jsl\Database\Query\Builder|static
     */
    public function orHaving($column, $operator = null, $value = null)
    {
        return $this->having($column, $operator, $value, 'or');
    }

    /**
     * Add a raw having clause to the query.
     *
     * @param  string $sql
     * @param  array $bindings
     * @param  string $boolean
     * @return $this
     */
    public function havingRaw($sql, array $bindings = array(), $boolean = 'and')
    {
        $type = 'raw';

        $this->havings[] = compact('type', 'sql', 'boolean');

        $this->addBinding($bindings, 'having');

        return $this;
    }

    /**
     * Add a raw or having clause to the query.
     *
     * @param  string $sql
     * @param  array $bindings
     * @return \Jsl\Database\Query\Builder|static
     */
    public function orHavingRaw($sql, array $bindings = array())
    {
        return $this->havingRaw($sql, $bindings, 'or');
    }

    /**
     * Add an "order by" clause to the query.
     *
     * @param  string $column
     * @param  string $direction
     * @return $this
     */
    public function orderBy($column, $direction = 'asc')
    {
        $direction = strtolower($direction) == 'asc' ? 'asc' : 'desc';

        $this->orders[] = compact('column', 'direction');

        return $this;
    }

    /**
     * Add a raw "order by" clause to the query.
     *
     * @param  string $sql
     * @param  array $bindings
     * @return $this
     */
    public function orderByRaw($sql, $bindings = array())
    {
        $type = 'raw';

        $this->orders[] = compact('type', 'sql');

        $this->addBinding($bindings, 'order');

        return $this;
    }

    /**
     * Set the "offset" value of the query.
     *
     * @param  int $value
     * @return $this
     */
    public function offset($value)
    {
        $this->offset = max(0, $value);

        return $this;
    }

    /**
     * Set the "limit" value of the query.
     *
     * @param  int $value
     * @return $this
     */
    public function limit($value)
    {
        if ($value > 0) $this->limit = $value;

        return $this;
    }

    /**
     * Set the limit and offset for a given page.
     *
     * @param  int $page
     * @param  int $perPage
     * @return \Jsl\Database\Query\Builder|static
     */
    public function forPage($page, $perPage = 15)
    {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    /**
     * Add a union statement to the query.
     *
     * @param  \Jsl\Database\Query\Builder|\Closure $query
     * @param  bool $all
     * @return \Jsl\Database\Query\Builder|static
     */
    public function union($query, $all = false)
    {
        if ($query instanceof Closure) {
            call_user_func($query, $query = $this->newQuery());
        }

        $this->unions[] = compact('query', 'all');

        return $this->mergeBindings($query);
    }

    /**
     * Add a union all statement to the query.
     *
     * @param  \Jsl\Database\Query\Builder|\Closure $query
     * @return \Jsl\Database\Query\Builder|static
     */
    public function unionAll($query)
    {
        return $this->union($query, true);
    }

    /**
     * Lock the selected rows in the table.
     *
     * @param  bool $value
     * @return $this
     */
    public function lock($value = true)
    {
        $this->lock = $value;

        return $this;
    }

    /**
     * Lock the selected rows in the table for updating.
     *
     * @return \Jsl\Database\Query\Builder
     */
    public function lockForUpdate()
    {
        return $this->lock(true);
    }

    /**
     * Share lock the selected rows in the table.
     *
     * @return \Jsl\Database\Query\Builder
     */
    public function sharedLock()
    {
        return $this->lock(false);
    }

    /**
     * Get the SQL representation of the query.
     *
     * @return string
     */
    public function toSql()
    {
        return $this->grammar->compileSelect($this);
    }

    /**
     * Execute a query for a single record by ID.
     *
     * @param  int $id
     * @param  array $columns
     * @return mixed|static
     */
    public function find($id, $columns = array('*'), $column = 'id')
    {
        return $this->where($column, '=', $id)->first($columns);
    }

    /**
     * Pluck a single column's value from the first result of a query.
     *
     * @param  string $column
     * @return mixed
     */
    public function pluck($column)
    {
        $result = (array)$this->first(array($column));

        return count($result) > 0 ? reset($result) : null;
    }

    /**
     * Execute the query and get the first result.
     *
     * @param  array $columns
     * @return mixed|static
     */
    public function first($columns = array('*'))
    {
        $results = $this->limit(1)->get($columns);

        return is_array($results)
            ? (count($results) > 0 ? reset($results) : null)
            : $results->first();
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array $columns
     * @return array|static[]
     */
    public function get($columns = ['*'])
    {
        if (is_null($this->columns)) $this->columns = $columns;

        $result = $this->connection->fetchAll($this->toSql(), $this->getBindings(), true, $this->fetchClass);

        if ($this->ignoreModelAndCollection === false && $this->modelClass) {
            $model = $this->modelClass;

            return $this->returnCollection
                ? new Collection($result, $this->modelClass, $this->modelTransformer)
                : array_map(fn ($record) => new $model($record, $this->modelTransformer), $result);
        }

        return $this->ignoreModelAndCollection === false && $this->returnCollection
            ? new Collection($result)
            : $result;
    }

    /**
     * Execute the query and return a page
     *
     * @param int $page
     * @param int $perPage
     * @param  array $columns
     * @return Paginate
     */
    public function paginate(int $page = 1, int $perPage = 20, $columns = ['*']): Paginate
    {
        if (is_null($this->columns)) $this->columns = $columns;

        return new Paginate($this, $page, $perPage);
    }

    /**
     * @param $file
     * @param $columns
     * @param callable $builder
     * @return \PDOStatement
     */
    public function infile($file, $columns, Closure $builder = null)
    {
        $clause = new InfileClause($file, $columns);

        if ($builder) {
            $builder($clause);
        }

        if ($this->grammar instanceof MySqlGrammar) {
            $sql = $this->grammar->compileInfile($this, $clause);
        } else {
            throw new Exception('inifile() is only supported for MySQL');
        }

        return $this->connection->query($sql, $this->cleanBindings($clause->rules));
    }

    /**
     * @param $file
     * @param Closure $builder
     * @return Builder
     */
    public function intoOutfile($file, Closure $builder = null)
    {
        return $this->outfile('outfile', $file, $builder);
    }

    /**
     * @param $file
     * @param Closure $builder
     * @return Builder
     */
    public function intoDumpfile($file, Closure $builder = null)
    {
        return $this->outfile('dumpfile', $file, $builder);
    }

    /**
     * @param $type
     * @param $file
     * @param $builder
     * @return $this
     */
    private function outfile($type, $file, $builder)
    {
        $this->outfile = $clause = new OutfileClause($file, $type);

        if ($builder) {
            $builder($clause);
        }

        return $this;
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array $columns
     * @return \PDOStatement
     */
    public function query($columns = array('*'))
    {
        if (is_null($this->columns)) $this->columns = $columns;

        return $this->connection->query($this->toSql(), $this->getBindings());
    }

    /**
     * Get an array with the values of a given column.
     *
     * @param  string $column
     * @param  string $key
     * @return array
     */
    public function lists($column, $key = null)
    {
        $columns = $this->getListSelect($column, $key);

        // First we will just get all of the column values for the record result set
        // then we can associate those values with the column if it was specified
        // otherwise we can just give these values back without a specific key.
        $this->ignoreModelAndCollection = true;
        $results = $this->get($columns);
        $this->ignoreModelAndCollection = false;

        $values = array_map(function ($row) use ($columns) {
            return $row[$columns[0]];
        }, $results);

        // If a key was specified and we have results, we will go ahead and combine
        // the values with the keys of all of the records so that the values can
        // be accessed by the key of the rows instead of simply being numeric.
        if (!is_null($key) && count($results) > 0) {
            $keys = array_map(function ($row) use ($key) {
                return $row[$key];
            }, $results);

            return array_combine($keys, $values);
        }

        return $values;
    }

    /**
     * Get the columns that should be used in a list array.
     *
     * @param  string $column
     * @param  string $key
     * @return array
     */
    protected function getListSelect($column, $key)
    {
        $select = is_null($key) ? array($column) : array($column, $key);

        // If the selected column contains a "dot", we will remove it so that the list
        // operation can run normally. Specifying the table is not needed, since we
        // really want the names of the columns as it is in this resulting array.
        if (($dot = strpos($select[0], '.')) !== false) {
            $select[0] = substr($select[0], $dot + 1);
        }

        return $select;
    }

    /**
     * Concatenate values of a given column as a string.
     *
     * @param  string $column
     * @param  string $glue
     * @return string
     */
    public function implode($column, $glue = null)
    {
        if (is_null($glue)) return implode($this->lists($column));

        return implode($glue, $this->lists($column));
    }

    /**
     * Get the count of the total records for pagination.
     *
     * @return int
     */
    public function getTotalRowCount()
    {
        $this->backupFieldsForCount();

        // Because some database engines may throw errors if we leave the ordering
        // statements on the query, we will "back them up" and remove them from
        // the query. Once we have the count we will put them back onto this.
        $total = $this->count();

        $this->restoreFieldsForCount();

        return $total;
    }

    /**
     * Backup certain fields for a pagination count.
     *
     * @return void
     */
    protected function backupFieldsForCount()
    {
        foreach (array('orders', 'limit', 'offset') as $field) {
            $this->backups[$field] = $this->{$field};

            $this->{$field} = null;
        }
    }

    /**
     * Restore certain fields for a pagination count.
     *
     * @return void
     */
    protected function restoreFieldsForCount()
    {
        foreach (array('orders', 'limit', 'offset') as $field) {
            $this->{$field} = $this->backups[$field];
        }

        $this->backups = array();
    }

    /**
     * Determine if any rows exist for the current query.
     *
     * @return bool
     */
    public function exists()
    {
        return $this->count() > 0;
    }

    /**
     * Retrieve the "count" result of the query.
     *
     * @param  string $columns
     * @return int
     */
    public function count($columns = '*')
    {
        if (!is_array($columns)) {
            $columns = array($columns);
        }

        return (int)$this->aggregate(__FUNCTION__, $columns);
    }

    /**
     * Retrieve the minimum value of a given column.
     *
     * @param  string $column
     * @return mixed
     */
    public function min($column)
    {
        return $this->aggregate(__FUNCTION__, array($column));
    }

    /**
     * Retrieve the maximum value of a given column.
     *
     * @param  string $column
     * @return mixed
     */
    public function max($column)
    {
        return $this->aggregate(__FUNCTION__, array($column));
    }

    /**
     * Retrieve the sum of the values of a given column.
     *
     * @param  string $column
     * @return mixed
     */
    public function sum($column)
    {
        $result = $this->aggregate(__FUNCTION__, array($column));

        return $result ?: 0;
    }

    /**
     * Retrieve the average of the values of a given column.
     *
     * @param  string $column
     * @return mixed
     */
    public function avg($column)
    {
        return $this->aggregate(__FUNCTION__, array($column));
    }

    /**
     * Execute an aggregate function on the database.
     *
     * @param  string $function
     * @param  array $columns
     * @return mixed
     */
    public function aggregate($function, $columns = array('*'))
    {
        $this->aggregate = compact('function', 'columns');

        $previousColumns = $this->columns;

        $this->ignoreModelAndCollection = true;
        $results = $this->get($columns);
        $this->ignoreModelAndCollection = false;

        // Once we have executed the query, we will reset the aggregate property so
        // that more select queries can be executed against the database without
        // the aggregate value getting in the way when the grammar builds it.
        $this->aggregate = null;

        $this->columns = $previousColumns;

        if (isset($results[0])) {
            $result = array_change_key_case((array)$results[0]);

            return $result['aggregate'];
        }
    }

    /**
     * @param $chunkSize
     */
    public function setDefaultChunkSize($chunkSize)
    {
        $this->defaultChunkSize = $chunkSize;
    }

    /**
     * @param null $chunkSize
     * @return InsertBuffer
     */
    public function buffer($chunkSize = null)
    {
        if (is_null($chunkSize)) {
            $chunkSize = $this->defaultChunkSize;
        }

        return new InsertBuffer($this, $chunkSize);
    }

    /**
     * Insert a new record into the database.
     *
     * @param  array $values
     * @return \PDOStatement
     */
    public function insert(array $values)
    {
        return $this->doInsert($values, 'insert');
    }

    /**
     * Insert a new record into the database.
     *
     * @param  array $values
     * @return \PDOStatement
     */
    public function insertIgnore(array $values)
    {
        return $this->doInsert($values, 'insertIgnore');
    }

    /**
     * Insert a new record into the database.
     *
     * @param  array $values
     * @return \PDOStatement
     */
    public function replace(array $values)
    {
        return $this->doInsert($values, 'replace');
    }

    /**
     * @param array $values
     * @param $type
     * @return \PDOStatement
     */
    public function doInsert(array $values, $type)
    {
        // Since every insert gets treated like a batch insert, we will make sure the
        // bindings are structured in a way that is convenient for building these
        // inserts statements by verifying the elements are actually an array.
        if (!is_array(reset($values))) {
            $values = array($values);
        }

        $sql = $this->grammar->{'compile' . ucfirst($type)}($this, $values);

        return $this->connection->query($sql, $this->buildBulkInsertBindings($values));
    }


    /**
     * @param $select \Closure|static
     * @param array $columns
     * @return \PDOStatement
     * @throws \Exception
     */
    protected function doInsertSelect($select, array $columns, $type)
    {
        $select = $this->prepareInsertSelect($select);

        $sql = $this->grammar->{'compile' . ucfirst($type) . 'Select'}($this, $columns, $select);

        $bindings = $select->getBindings();

        return $this->connection->query($sql, $bindings);
    }

    /**
     * @param $select
     * @return Builder
     * @throws \Exception
     */
    private function prepareInsertSelect($select)
    {
        if ($select instanceof Closure) {
            $callback = $select;

            $select = $this->newQuery();

            call_user_func($callback, $select);
        }

        if (!$select instanceof Builder) {
            throw new \Exception("Argument 1 must be a closure or an instance of Database\\Query\\Builder");
        }

        return $select;
    }

    /**
     * @param $select \Closure|static
     * @param array $columns
     * @return \PDOStatement
     * @throws \Exception
     */
    public function insertSelect($select, array $columns)
    {
        return $this->doInsertSelect($select, $columns, 'insert');
    }

    /**
     * @param $select \Closure|static
     * @param array $columns
     * @return \PDOStatement
     * @throws \Exception
     */
    public function insertIgnoreSelect($select, array $columns)
    {
        return $this->doInsertSelect($select, $columns, 'insertIgnore');
    }

    /**
     * @param $select \Closure|static
     * @param array $columns
     * @return \PDOStatement
     * @throws \Exception
     */
    public function replaceSelect($select, array $columns)
    {
        return $this->doInsertSelect($select, $columns, 'replace');
    }

    /**
     * Insert a new record into the database, with an update if it exists
     *
     * @param $select
     * @param array $columns
     * @param array $updateValues
     * @return bool|\PDOStatement
     * @throws \Exception
     */
    public function insertSelectUpdate($select, array $columns, array $updateValues)
    {
        $select = $this->prepareInsertSelect($select);

        $sql = $this->grammar->compileInsertSelectOnDuplicateKeyUpdate($this, $columns, $select, $updateValues);

        $bindings = $select->getBindings();

        foreach ($updateValues as $value) {
            if (!$value instanceof Expression) $bindings[] = $value;
        }

        return $this->connection->query($sql, $bindings);
    }

    /**
     * Alias for insertSelectOnDuplicateKeyUpdate
     *
     * @param $select
     * @param array $columns
     * @param array $updateValues
     * @return bool|\PDOStatement
     */
    public function insertSelectOnDuplicateKeyUpdate($select, array $columns, array $updateValues)
    {
        return $this->insertSelectUpdate($select, $columns, $updateValues);
    }

    /**
     * Insert a new record into the database, with an update if it exists
     *
     * @param array $values
     * @param array $updateValues an array of column => bindings pairs to update
     * @return \PDOStatement
     */
    public function insertUpdate(array $values, array $updateValues)
    {
        // Since every insert gets treated like a batch insert, we will make sure the
        // bindings are structured in a way that is convenient for building these
        // inserts statements by verifying the elements are actually an array.
        if (!is_array(reset($values))) {
            $values = array($values);
        }

        $bindings = $this->buildBulkInsertBindings($values);

        foreach ($updateValues as $value) {
            if (!$value instanceof Expression) $bindings[] = $value;
        }

        $sql = $this->grammar->compileInsertOnDuplicateKeyUpdate($this, $values, $updateValues);

        return $this->connection->query($sql, $bindings);
    }

    /**
     * Alias for insertOnDuplicateKeyUpdate
     *
     * @param array $values
     * @param array $updateValues
     * @return \PDOStatement
     */
    public function insertOnDuplicateKeyUpdate(array $values, array $updateValues)
    {
        return $this->insertUpdate($values, $updateValues);
    }

    /**
     * @param $values
     * @return array
     */
    private function buildBulkInsertBindings($values)
    {
        // We'll treat every insert like a batch insert so we can easily insert each
        // of the records into the database consistently. This will make it much
        // easier on the grammars to just handle one type of record insertion.
        $bindings = array();

        foreach ($values as $record) {
            foreach ($record as $value) {
                if (!$value instanceof Expression) $bindings[] = $value;
            }
        }

        return $bindings;
    }

    /**
     * Insert a new record and get the value of the primary key.
     *
     * @param  array $values
     * @param  string $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $sql = $this->grammar->compileInsertGetId($this, $values, $sequence);

        $values = $this->cleanBindings($values);

        $this->connection->query($sql, $values);

        $id = is_null($sequence) ? $this->connection->lastInsertId() : $this->connection->lastInsertId($sequence);

        return is_numeric($id) ? (int)$id : $id;
    }

    /**
     * Update a record in the database.
     *
     * @param  array $values
     * @return \PDOStatement
     */
    public function update(array $values)
    {
        $bindings = array_values(array_merge($values, $this->getBindings()));

        $sql = $this->grammar->compileUpdate($this, $values);

        return $this->connection->query($sql, $this->cleanBindings($bindings));
    }

    /**
     * Increment a column's value by a given amount.
     *
     * @param  string $column
     * @param  int $amount
     * @param  array $extra
     * @return \PDOStatement
     */
    public function increment($column, $amount = 1, array $extra = array())
    {
        $wrapped = $this->grammar->wrap($column);

        $columns = array_merge(array($column => $this->raw("$wrapped + $amount")), $extra);

        return $this->update($columns);
    }

    /**
     * Decrement a column's value by a given amount.
     *
     * @param  string $column
     * @param  int $amount
     * @param  array $extra
     * @return \PDOStatement
     */
    public function decrement($column, $amount = 1, array $extra = array())
    {
        $wrapped = $this->grammar->wrap($column);

        $columns = array_merge(array($column => $this->raw("$wrapped - $amount")), $extra);

        return $this->update($columns);
    }

    /**
     * Delete a record from the database.
     *
     * @param  mixed $id
     * @return \PDOStatement
     */
    public function delete($id = null)
    {
        // If an ID is passed to the method, we will set the where clause to check
        // the ID to allow developers to simply and quickly remove a single row
        // from their database without manually specifying the where clauses.
        if (!is_null($id)) $this->where('id', '=', $id);

        $sql = $this->grammar->compileDelete($this);

        return $this->connection->query($sql, $this->getBindings());
    }

    /**
     * Run a truncate statement on the table.
     *
     * @return void
     */
    public function truncate()
    {
        foreach ($this->grammar->compileTruncate($this) as $sql => $bindings) {
            $this->connection->query($sql, $bindings);
        }
    }

    /**
     * Get a new instance of the query builder.
     *
     * @return \Jsl\Database\Query\Builder
     */
    public function newQuery()
    {
        return new Builder($this->connection, $this->grammar);
    }

    /**
     * Merge an array of where clauses and bindings.
     *
     * @param  array $wheres
     * @param  array $bindings
     * @return void
     */
    public function mergeWheres($wheres, $bindings)
    {
        $this->wheres = array_merge((array)$this->wheres, (array)$wheres);

        $this->bindings['where'] = array_values(array_merge($this->bindings['where'], (array)$bindings));
    }

    /**
     * Remove all of the expressions from a list of bindings.
     *
     * @param  array $bindings
     * @return array
     */
    protected function cleanBindings(array $bindings)
    {
        return array_values(array_filter($bindings, function ($binding) {
            return !$binding instanceof Expression;
        }));
    }

    /**
     * Create a raw database expression.
     *
     * @param  mixed $value
     * @return \Jsl\Database\Query\Expression
     */
    public function raw($value)
    {
        return $this->connection->raw($value);
    }

    /**
     * Get the current query value bindings in a flattened array.
     *
     * @return array
     */
    public function getBindings()
    {
        $return = array();

        // Flatten a multi-dimensional array, creating a single-dimensional array
        array_walk_recursive($this->bindings, function ($x) use (&$return) {
            $return[] = $x;
        });

        return $return;
    }

    /**
     * Get the raw array of bindings.
     *
     * @return array
     */
    public function getRawBindings()
    {
        return $this->bindings;
    }

    /**
     * Set the bindings on the query builder.
     *
     * @param  array $bindings
     * @param  string $type
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    public function setBindings(array $bindings, $type = 'where')
    {
        if (!array_key_exists($type, $this->bindings)) {
            throw new \InvalidArgumentException("Invalid binding type: {$type}.");
        }

        $this->bindings[$type] = $bindings;

        return $this;
    }

    /**
     * Add a binding to the query.
     *
     * @param  mixed $value
     * @param  string $type
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    public function addBinding($value, $type = 'where')
    {
        if (!array_key_exists($type, $this->bindings)) {
            throw new \InvalidArgumentException("Invalid binding type: {$type}.");
        }

        if (is_array($value)) {
            $this->bindings[$type] = array_values(array_merge($this->bindings[$type], $value));
        } else {
            $this->bindings[$type][] = $value;
        }

        return $this;
    }

    /**
     * Merge an array of bindings into our bindings.
     *
     * @param  \Jsl\Database\Query\Builder $query
     * @return $this
     */
    public function mergeBindings(Builder $query)
    {
        $this->bindings = array_merge_recursive($this->bindings, $query->bindings);

        return $this;
    }

    /**
     * Get the database connection instance.
     *
     * @return \Jsl\Database\ConnectionInterface
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Get the query grammar instance.
     *
     * @return \Jsl\Database\Query\Grammars\Grammar
     */
    public function getGrammar()
    {
        return $this->grammar;
    }

    /**
     * Catch non-existent methods and throw a catchable error
     *
     * @param  string $method
     * @param  array $parameters
     * @return mixed
     *
     * @throws \BadMethodCallException
     */
    public function __call($method, $parameters)
    {
        $className = get_class($this);

        throw new \BadMethodCallException("Call to undefined method {$className}::{$method}()");
    }
}

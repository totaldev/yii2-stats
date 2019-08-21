<?php

namespace totaldev\yii\stats\db;

use yii\base\InvalidConfigException;
use yii\db\ActiveRecord;
use yii\db\Command;
use yii\db\Connection;
use yii\db\Query;
use yii\db\QueryBuilder;

/**
 * @property $modelClass
 */
trait StatsQueryTrait
{
    /** @var array */
    public $groupBy;
    /** @see Query::$select */
    public $select;
    /** @see ActiveQueryTrait::$with */
    public $with;
    /** @var array */
    protected $metricScheme;


    /**
     * Returns the number of records.
     * @param string $q the COUNT expression. Defaults to '1'.
     * Make sure you properly [quote](guide:db-dao#quoting-table-and-column-names) column names in the expression.
     * @param Connection $db the database connection used to generate the SQL statement.
     * If this parameter is not given (or null), the `db` application component will be used.
     * @return int|string number of records. The result may be a string depending on the
     * underlying database engine and to support integer values higher than a 32bit PHP integer can handle.
     */
    public function count($q = '1', $db = null)
    {
        if ($this->emulateExecution) {
            return 0;
        }

        $query = clone $this;
        $query->distinct = true;
        $query->select = $query->groupBy;
        $query->groupBy = [];
        return (int)(new Query())->from($query)->count($q);
    }

    /**
     * Creates a DB command that can be used to execute this query.
     * @param Connection $db the DB connection used to create the DB command.
     * If null, the DB connection returned by [[modelClass]] will be used.
     * @return Command the created DB command instance.
     */
    public function createCommand($db = null)
    {
        /* @var $modelClass ActiveRecord */
        $modelClass = $this->modelClass;
        if ($db === null) {
            $db = $modelClass::getDb();
        }

        if ($this->sql === null) {
            list ($sql, $params) = $db->getQueryBuilder()->build($this);
        } else {
            $sql = $this->sql;
            $params = $this->params;
        }

        return $db->createCommand($sql, $params);
    }

    /**
     * @param $name
     * @return bool
     */
    public function isMetric($name)
    {
        return isset($this->getMetricScheme()[$name]);
    }

    /**
     * Prepares for building SQL.
     * This method is called by [[QueryBuilder]] when it starts to build SQL from a query object.
     * You may override this method to do some final preparation work when converting a query into a SQL statement.
     * @param QueryBuilder $builder
     * @return $this a prepared query instance which will be used by [[QueryBuilder]] to build the SQL
     * @throws InvalidConfigException
     * @throws \Exception
     * @see \yii\db\QueryBuilder::build
     */
    public function prepare($builder)
    {
        $this->selectPreProcess();
        $this->groupByPreProcess();
        return parent::prepare($builder);
    }

    abstract public function with();

    /**
     * @return array|mixed
     */
    protected function getMetricScheme()
    {
        if (!isset($this->metricScheme)) {
            $this->metricScheme = forward_static_call([$this->modelClass, 'getMetricScheme']);
        }
        return $this->metricScheme;
    }

    /**
     * @return $this
     * @throws InvalidConfigException
     */
    protected function groupByPreProcess()
    {
        if (empty($this->groupBy)) {
            return $this;
        }

        foreach ($this->groupBy as &$field) {
            if (is_string($field) && $this->isMetric($field)) {
                $field = $this->interpretMetricExpression($field);
            } elseif (!empty($this->select) && !in_array($field, $this->select)) {
                $this->select = array_merge([$field => $field], $this->select);
            }
        }
        return $this;
    }

    /**
     * @param string $expression
     * @return string
     * @throws InvalidConfigException
     */
    protected function interpretMetricExpression(string $expression): string
    {
        // если нашли закрывающую скобку выражения, то интерпретируем
        if (false !== strpos($expression, '}')) {
            if (!preg_match_all('/\{([a-z _-]+)\}/i', $expression, $match)) {
                throw new InvalidConfigException("Can't interpret rule $expression");
            }
            $metricScheme = $this->getMetricScheme();
            foreach ($match[1] as $var) {
                if (!isset($metricScheme[$var])) {
                    throw new InvalidConfigException("Undefined variable {{$var}} in expression: $expression");
                }
                // дабы можно было спокойно переиспользовать в несколько уровней переменные
                $metricScheme[$var] = $this->interpretMetricExpression($metricScheme[$var]);
                $expression = str_replace("{{$var}}", $metricScheme[$var], $expression);
            }
            return $expression;
        }
        return $expression;
    }

    /**
     * @param string $alias
     * @param string|array $metricExpression
     * @return string
     * @throws InvalidConfigException
     */
    protected function processSelectMetric(string $alias, $metricExpression): string
    {
        if (is_string($metricExpression)) {
            return $this->interpretMetricExpression($metricExpression);
        } elseif (is_array($metricExpression)) {

            $field = $this->interpretMetricExpression($metricExpression['expression']);
            if (
                isset($metricExpression['with'])
                && !in_array($metricExpression['with'], (array)$this->with)
                && !key_exists($metricExpression['with'], (array)$this->with)
            ) {
                $this->with($metricExpression['with']);
            }
            return $field;
        } else {
            throw new InvalidConfigException("Broken select field or metric scheme: $alias or " . var_export($metricExpression, true));
        }
    }

    /**
     * @param string|array $defaultSelect
     * @return $this
     * @throws \Exception
     */
    protected function selectPreProcess($defaultSelect = '*')
    {
        if ($this->select === false) {
            return $this;
        }

        $metricScheme = $this->getMetricScheme();
        if (empty($this->select)) {
            $this->select = $defaultSelect;
        }

        if (is_string($this->select) && ('' === $this->select || '*' === $this->select)) {
            $this->select = array_keys($metricScheme);
            $this->select = array_combine($this->select, $this->select);
        } elseif (is_string($this->select)) {
            $this->select = explode(',', $this->select);
        } elseif (!is_array($this->select)) {
            throw new InvalidConfigException('Broken select field: ' . var_export($this->select, true));
        }
        foreach ($this->select as $k => $expression) {
            $this->select[$k] = trim($expression);
            if (
                !strpos($expression, 'AS')
                && !strpos($expression, ')')
                && !strpos($expression, '.')
            ) {
                unset($this->select[$k]);
                $this->select[$expression] = $expression;
            }
        }

        $metricExpressions = array_intersect_key($metricScheme, array_fill_keys($this->select, null));
        foreach ($this->select as &$field) {
            if (isset($metricExpressions[$field])) {
                $field = $this->processSelectMetric($field, $metricExpressions[$field]);
            }
        }
        return $this;
    }
}

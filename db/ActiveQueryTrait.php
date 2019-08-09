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
trait ActiveQueryTrait
{
    /** @see Query::$select */
    public $select;
    /** @see ActiveQueryTrait::$with */
    public $with;
    /** @var array */
    public $groupBy;
    /** @var array */
    protected $metricScheme;

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
                && key_exists($metricExpression['with'], (array)$this->with)
            ) {
                $this->with($metricExpression['with']);
            }
            return $field;
        } else {
            throw new InvalidConfigException("Broken select field or metric scheme: $alias or " . var_export($metricExpression, true));
        }
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

    abstract public function with();

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
     * @param $name
     * @return bool
     */
    public function isMetric($name)
    {
        return isset($this->getMetricScheme()[$name]);
    }
}

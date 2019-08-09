<?php

namespace totaldev\yii\stats\db;

use totaldev\yii\stats\query\ActiveQuery;

trait StatsEntityTrait
{
    static protected $attributes = [];

    /** @inheritdoc */
    public static function find()
    {
        return new ActiveQuery(get_called_class());
    }

    /**
     * Returns the list of all attribute names of the model.
     * The default implementation will return all column names of the table associated with this AR class.
     * @return array list of attribute names.
     */
    public function attributes()
    {
        if (empty(static::$attributes)) {
            static::$attributes = array_merge(parent::attributes(), $this->metrics());
        }
        return static::$attributes;
    }

    /**
     * @return array
     */
    public function metrics(): array
    {
        return array_keys(static::getMetricScheme());
    }

    /**
     * @return array
     */
    abstract public static function getMetricScheme(): array;

    /**
     * @param string $metric
     * @return bool
     */
    public function hasMetric(string $metric): bool
    {
        return key_exists($metric, static::getMetricScheme());
    }
}

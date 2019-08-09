<?php

namespace totaldev\yii\stats\query;

use totaldev\yii\stats\db\StatsQueryTrait;
use yii\db\ActiveQuery;

class StatsActiveQuery extends ActiveQuery
{
    use StatsQueryTrait;

}
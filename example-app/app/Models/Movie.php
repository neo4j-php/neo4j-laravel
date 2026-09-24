<?php

namespace App\Models;

use Neo4j\Neo4jLaravel\Neo4jModel;

class Movie extends Neo4jModel
{
    protected $guarded = [];

    public $timestamps = false;

    public function actors()
    {
        return $this->matchRelationship(Person::class, '<ACTED_IN');
    }
}

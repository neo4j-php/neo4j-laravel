<?php

namespace App\Models;

use Neo4j\Neo4jLaravel\Neo4jModel;

class Person extends Neo4jModel
{
    protected $guarded = [];

    public $timestamps = false;

    public function movies()
    {
        return $this->matchRelationship(Movie::class, 'ACTED_IN>');
    }
}

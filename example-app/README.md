# Neo4j Laravel Example App

This is an example application demonstrating the integration of Neo4j with Laravel using the `neo4j-php/neo4j-laravel` package.

## Setup

1. Make sure you have Neo4j running. The default configuration expects:

    - URL: `bolt://localhost:7687`
    - Username: `neo4j`
    - Password: `testtest`
    - Database: `neo4j`

2. Install dependencies:

```bash
composer install
npm install
```

3. Copy `.env.example` to `.env` (if not already done):

```bash
cp .env.example .env
```

4. Generate application key:

```bash
php artisan key:generate
```

## Configuration Notes

The application uses Neo4j as its primary database but intentionally uses file-based alternatives for Laravel's auxiliary features:

-   **Sessions**: Uses `file` driver instead of `database`

    -   Neo4j is not optimized for relational-style session storage
    -   File-based sessions are more appropriate for this use case

-   **Queue**: Uses `sync` driver instead of `database`

    -   Neo4j is not designed for queue table operations
    -   For production, consider using Redis or other queue drivers

-   **Cache**: Uses `file` driver instead of `database`
    -   Neo4j is not optimized for cache table operations
    -   For production, consider using Redis or Memcached

## Available API Endpoints

### Users

-   `GET /api/users` - List all users
-   `POST /api/users` - Create a user
    ```json
    {
        "name": "John Doe",
        "email": "john@example.com"
    }
    ```
-   `GET /api/users/{email}` - Get user by email
-   `PUT /api/users/{email}` - Update user
    ```json
    {
        "name": "John Smith"
    }
    ```
-   `DELETE /api/users/{email}` - Delete user

### Movies

-   `GET /api/movies` - List all movies with their actors
-   `POST /api/movies` - Create a movie
    ```json
    {
        "title": "The Matrix",
        "released": 1999,
        "tagline": "Welcome to the Real World"
    }
    ```
-   `POST /api/movies/actors` - Add actor to movie
    ```json
    {
        "movie_title": "The Matrix",
        "actor_name": "Keanu Reeves",
        "roles": ["Neo", "Thomas Anderson"]
    }
    ```
-   `GET /api/movies/{title}` - Get movie by title with its actors
-   `GET /api/movies/{title}/similar` - Find similar movies (based on common actors)
-   `DELETE /api/movies/{title}` - Delete movie and its relationships

## Development

Run the development server:

```bash
composer run dev
```

This will start:

-   Laravel development server at http://127.0.0.1:8000
-   Vite for frontend assets at http://localhost:5173

## Eloquent graph relationships (`Person` / `Movie`)

`App\Models\Person` and `App\Models\Movie` use Eloquent `matchRelationship()` for
first-class Cypher edges (`ACTED_IN`). Try them in Tinker:

```bash
php artisan tinker
```

### Insert a relationship (`attach`)

Create nodes, then insert a directed edge (and optional relationship properties):

```php
use App\Models\Person;
use App\Models\Movie;

$keanu = Person::create(['name' => 'Keanu']);
$matrix = Movie::create(['title' => 'The Matrix', 'released' => 1999]);

$keanu->movies()->attach($matrix->id, ['roles' => ['Neo']]);

$keanu->fresh()->movies->pluck('title');   // ["The Matrix"]
$matrix->fresh()->actors->pluck('name');   // ["Keanu"]
```

### Create related node + relationship (`create`)

```php
$keanu->movies()->create(
    ['title' => 'John Wick', 'released' => 2014],
    ['roles' => ['John']],
);
```

### Query Builder `insertRelationship`

Same graph write without Eloquent models:

```php
use Illuminate\Support\Facades\DB;

DB::connection('neo4j')
    ->table('Person')
    ->insertRelationship(
        'ACTED_IN>',
        'Movie',
        ['id' => $keanu->id],
        ['id' => $matrix->id],
        ['roles' => ['Neo']],
    );
```

Direction markers match the package API (`Type>`, `<Type`, bare type).
`attach` / `insertRelationship` use `CREATE` (not `MERGE`) — calling them twice
with the same keys creates duplicate edges.

## Testing the Integration

1. Create a user:

```bash
curl -X POST http://localhost:8000/api/users \
  -H "Content-Type: application/json" \
  -d '{"name": "John Doe", "email": "john@example.com"}'
```

2. Create a movie:

```bash
curl -X POST http://localhost:8000/api/movies \
  -H "Content-Type: application/json" \
  -d '{
    "title": "The Matrix",
    "released": 1999,
    "tagline": "Welcome to the Real World"
  }'
```

3. Add an actor to the movie:

```bash
curl -X POST http://localhost:8000/api/movies/actors \
  -H "Content-Type: application/json" \
  -d '{
    "movie_title": "The Matrix",
    "actor_name": "Keanu Reeves",
    "roles": ["Neo", "Thomas Anderson"]
  }'
```

4. View all movies with their actors:

```bash
curl http://localhost:8000/api/movies
```

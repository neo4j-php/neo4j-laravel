<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laudis\Neo4j\Contracts\SessionInterface;
use Laudis\Neo4j\Types\Node;

class HtmlMovieController extends Controller
{
    public function index(SessionInterface $session): View
    {
        $result = $session->run('
            MATCH (m:Movie)
            OPTIONAL MATCH (m)<-[r:ACTED_IN]-(a:Person)
            RETURN m, collect(DISTINCT {actor: a, role: r.roles}) as actors
        ');

        $movies = collect($result->toArray())
            ->map(fn ($row) => $this->presentMovieRow($this->toArray($row)))
            ->all();

        return view('movies.index', compact('movies'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'title' => 'required|string',
            'released' => 'required|integer',
            'tagline' => 'required|string',
        ]);

        try {
            DB::connection('neo4j')->statement(
                'CREATE (m:Movie {
                    title: $title,
                    released: $released,
                    tagline: $tagline,
                    created_at: datetime()
                }) RETURN m',
                $request->only(['title', 'released', 'tagline'])
            );

            return redirect()
                ->route('movies.show', $request->input('title'))
                ->with('success', 'Movie created successfully.');
        } catch (\Throwable $e) {
            return redirect()
                ->route('movies.index')
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    public function addActor(Request $request): RedirectResponse
    {
        $request->merge([
            'roles' => $this->normalizeRoles($request->input('roles')),
        ]);

        $request->validate([
            'movie_title' => 'required|string',
            'actor_name' => 'required|string',
            'roles' => 'required|array',
            'roles.*' => 'string',
        ]);

        $movieTitle = $request->input('movie_title');

        try {
            DB::connection('neo4j')->beginTransaction();

            $result = DB::connection('neo4j')->select(
                '
                MATCH (m:Movie {title: $movieTitle})
                MERGE (a:Person {name: $actorName})
                MERGE (a)-[r:ACTED_IN]->(m)
                SET r.roles = $roles
                RETURN m, a, r',
                [
                    'movieTitle' => $movieTitle,
                    'actorName' => $request->input('actor_name'),
                    'roles' => $request->input('roles'),
                ]
            );

            if (empty($result)) {
                DB::connection('neo4j')->rollBack();

                return redirect()
                    ->route('movies.index')
                    ->with('error', 'Movie not found');
            }

            DB::connection('neo4j')->commit();

            return redirect()
                ->route('movies.show', $movieTitle)
                ->with('success', 'Actor added successfully.');
        } catch (\Throwable $e) {
            DB::connection('neo4j')->rollBack();

            return redirect()
                ->route('movies.show', $movieTitle)
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    public function show(string $title): View|RedirectResponse
    {
        $result = DB::connection('neo4j')->select(
            '
            MATCH (m:Movie {title: $title})
            OPTIONAL MATCH (m)<-[r:ACTED_IN]-(a:Person)
            RETURN m, collect(DISTINCT {actor: a, role: r.roles}) as actors',
            ['title' => $title]
        );

        if (empty($result)) {
            return redirect()
                ->route('movies.index')
                ->with('error', 'Movie not found');
        }

        $movie = $this->presentMovieRow($this->toArray($result[0]));

        return view('movies.show', compact('movie'));
    }

    public function findSimilar(string $title): View
    {
        $result = DB::connection('neo4j')->select(
            '
            MATCH (m:Movie {title: $title})<-[:ACTED_IN]-(a:Person)-[:ACTED_IN]->(other:Movie)
            WHERE m <> other
            WITH other, count(distinct a) as commonActors
            RETURN other, commonActors
            ORDER BY commonActors DESC
            LIMIT 5',
            ['title' => $title]
        );

        $movies = collect($result)
            ->map(function ($row) {
                $data = $this->toArray($row);
                $movie = $this->presentNode($data['other'] ?? null);

                return [
                    'movie' => $movie,
                    'common_actors' => (int) ($data['commonActors'] ?? 0),
                ];
            })
            ->filter(fn (array $row) => $row['movie'] !== null)
            ->values()
            ->all();

        return view('movies.similar', [
            'title' => $title,
            'movies' => $movies,
        ]);
    }

    public function destroy(string $title): RedirectResponse
    {
        $result = DB::connection('neo4j')->select(
            '
            MATCH (m:Movie {title: $title})
            OPTIONAL MATCH (m)<-[r:ACTED_IN]-()
            DELETE r, m
            RETURN count(m) as deleted',
            ['title' => $title]
        );

        if ((int) ($result[0]->deleted ?? 0) === 0) {
            return redirect()
                ->route('movies.index')
                ->with('error', 'Movie not found');
        }

        return redirect()
            ->route('movies.index')
            ->with('success', 'Movie and its relationships deleted successfully');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{title: string, released: int|null, tagline: string|null, actors: list<array{name: string, roles: list<string>}>}
     */
    private function presentMovieRow(array $row): array
    {
        $movie = $this->presentNode($row['m'] ?? null) ?? [
            'title' => '',
            'released' => null,
            'tagline' => null,
        ];

        $actors = [];
        foreach ($row['actors'] ?? [] as $entry) {
            $entry = $this->toArray($entry);
            $actor = $this->presentNode($entry['actor'] ?? null);

            if ($actor === null || ($actor['name'] ?? '') === '') {
                continue;
            }

            $roles = $entry['role'] ?? [];
            if (! is_array($roles)) {
                $roles = $roles === null ? [] : [$roles];
            }

            $actors[] = [
                'name' => $actor['name'],
                'roles' => $this->presentRoles($entry['role'] ?? null),
            ];
        }

        return [
            'title' => (string) ($movie['title'] ?? ''),
            'released' => isset($movie['released']) ? (int) $movie['released'] : null,
            'tagline' => isset($movie['tagline']) ? (string) $movie['tagline'] : null,
            'actors' => $actors,
        ];
    }

    /**
     * @return list<string>
     */
    private function presentRoles(mixed $roles): array
    {
        if ($roles === null) {
            return [];
        }

        if (is_object($roles) && method_exists($roles, 'toArray')) {
            $roles = $roles->toArray();
        }

        if (! is_array($roles)) {
            return [(string) $roles];
        }

        return array_values(array_map(static fn ($role) => (string) $role, $roles));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentNode(mixed $node): ?array
    {
        if ($node === null) {
            return null;
        }

        if ($node instanceof Node) {
            return $node->getProperties()->toArray();
        }

        $data = $this->toArray($node);

        if (isset($data['properties']) && is_array($data['properties'])) {
            return $data['properties'];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        return (array) $value;
    }

    /**
     * @return list<string>
     */
    private function normalizeRoles(mixed $roles): array
    {
        if (is_array($roles)) {
            return array_values(array_filter(array_map('trim', $roles), fn ($role) => $role !== ''));
        }

        if (! is_string($roles) || trim($roles) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $roles)),
            fn (string $role) => $role !== ''
        ));
    }
}

<?php

namespace Neo4j\Neo4jLaravel\Tests\Integration;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Tests\TestCase;

final class Neo4jEloquentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:User) DETACH DELETE n');
        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:SoftUser) DETACH DELETE n');
        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:Profile) DETACH DELETE n');
        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:Post) DETACH DELETE n');
        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:Role) DETACH DELETE n');
        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:RoleUser) DETACH DELETE n');
    }

    public function testEloquentModelSupportsBasicCrud(): void
    {
        $created = User::create(['name' => 'Pratiksha']);

        self::assertNotNull($created->id);
        self::assertSame('Pratiksha', $created->name);

        $found = User::where('name', 'Pratiksha')->first();

        self::assertInstanceOf(User::class, $found);
        self::assertSame($created->id, $found->id);
        self::assertSame($created->id, User::find($created->id)?->id);

        self::assertNotNull($created->created_at);
        self::assertNotNull($created->updated_at);

        $found->update(['name' => 'Pratiksha Zalte']);

        $updated = User::where('id', $created->id)->firstOrFail();

        self::assertSame('Pratiksha Zalte', $updated->name);
        self::assertNotNull($updated->updated_at);

        self::assertTrue($found->delete());
        self::assertNull(User::where('id', $created->id)->first());
    }

    public function testEloquentSupportsAggregatesExistsIncrementAndDateFilters(): void
    {
        User::create(['name' => 'Ada', 'score' => 10, 'status' => 'active']);
        User::create(['name' => 'Alan', 'score' => 20, 'status' => 'active']);
        User::create(['name' => 'Grace', 'score' => 5, 'status' => 'inactive']);

        self::assertSame(3, User::count());
        self::assertTrue(User::where('name', 'Ada')->exists());
        self::assertFalse(User::where('name', 'Missing')->exists());
        self::assertSame(35, (int) User::sum('score'));
        self::assertSame(20, (int) User::max('score'));
        self::assertSame(5, (int) User::min('score'));

        $ada = User::where('name', 'Ada')->firstOrFail();
        User::where('id', $ada->id)->increment('score', 3);
        self::assertSame(13, (int) User::where('id', $ada->id)->value('score'));

        self::assertSame(
            2,
            User::whereColumn('status', 'status')->where('status', 'active')->count()
        );

        self::assertGreaterThanOrEqual(
            1,
            User::whereYear('created_at', now()->year)->count()
        );

        $grouped = User::query()
            ->select('status')
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        self::assertCount(2, $grouped);
        self::assertSame(['active', 'inactive'], $grouped->pluck('status')->all());
    }

    public function testEloquentHydratesNeo4jElementId(): void
    {
        $created = User::create(['name' => 'Element']);

        $found = User::where('id', $created->id)->firstOrFail();

        self::assertNotNull($found->elementId());
        self::assertIsString($found->elementId());
        self::assertNotSame($found->id, $found->elementId());

        // elementId is metadata and must not be written back as a node property.
        $found->name = 'Element Updated';
        $found->save();

        $reloaded = User::where('id', $created->id)->firstOrFail();
        self::assertSame('Element Updated', $reloaded->name);
        self::assertNotNull($reloaded->elementId());
    }

    public function testEloquentSoftDeletesRestoreAndForceDelete(): void
    {
        $user = SoftUser::create(['name' => 'Soft']);

        self::assertTrue($user->delete());
        self::assertNull(SoftUser::where('id', $user->id)->first());
        self::assertTrue($user->trashed());

        $trashed = SoftUser::withTrashed()->where('id', $user->id)->firstOrFail();
        self::assertNotNull($trashed->deleted_at);
        self::assertTrue($trashed->trashed());

        self::assertTrue($trashed->restore());
        self::assertFalse($trashed->fresh()->trashed());
        self::assertNotNull(SoftUser::where('id', $user->id)->first());

        $trashed->delete();
        self::assertTrue($trashed->forceDelete());
        self::assertNull(SoftUser::withTrashed()->where('id', $user->id)->first());
    }

    public function testEloquentPaginateSimplePaginateAndCursorPaginate(): void
    {
        foreach (['Ada', 'Alan', 'Grace', 'Grace2', 'Linus'] as $name) {
            User::create(['name' => $name]);
        }

        /** @var LengthAwarePaginator $page */
        $page = User::orderBy('name')->paginate(2, ['*'], 'page', 1);

        self::assertInstanceOf(LengthAwarePaginator::class, $page);
        self::assertSame(5, $page->total());
        self::assertCount(2, $page->items());
        self::assertSame(['Ada', 'Alan'], collect($page->items())->pluck('name')->all());

        /** @var Paginator $simple */
        $simple = User::orderBy('name')->simplePaginate(2, ['*'], 'page', 1);

        self::assertInstanceOf(Paginator::class, $simple);
        self::assertCount(2, $simple->items());
        self::assertTrue($simple->hasMorePages());

        /** @var CursorPaginator $cursor */
        $cursor = User::orderBy('name')->orderBy('id')->cursorPaginate(2);

        self::assertInstanceOf(CursorPaginator::class, $cursor);
        self::assertCount(2, $cursor->items());
        self::assertTrue($cursor->hasMorePages());

        $next = User::orderBy('name')->orderBy('id')->cursorPaginate(2, ['*'], 'cursor', $cursor->nextCursor());
        self::assertCount(2, $next->items());
        self::assertNotSame(
            collect($cursor->items())->pluck('id')->all(),
            collect($next->items())->pluck('id')->all()
        );
    }

    public function testEloquentHasOneProfile(): void
    {
        $user = User::create(['name' => 'Ada']);
        $profile = $user->profile()->create(['bio' => 'Engineer']);

        self::assertInstanceOf(Profile::class, $profile);
        self::assertSame($user->id, $profile->user_id);

        self::assertInstanceOf(Profile::class, $user->fresh()->profile);
        self::assertSame('Engineer', $user->fresh()->profile->bio);

        $loaded = User::with('profile')->where('id', $user->id)->firstOrFail();

        self::assertTrue($loaded->relationLoaded('profile'));
        self::assertInstanceOf(Profile::class, $loaded->profile);
        self::assertSame('Engineer', $loaded->profile->bio);
        self::assertSame($user->id, $loaded->profile->user->id);
    }

    public function testEloquentHasManyPosts(): void
    {
        $user = User::create(['name' => 'Ada']);

        $first = $user->posts()->create(['title' => 'First']);
        $second = $user->posts()->create(['title' => 'Second']);

        self::assertInstanceOf(Post::class, $first);
        self::assertSame($user->id, $first->user_id);
        self::assertSame($user->id, $second->user_id);

        $posts = $user->fresh()->posts;
        self::assertCount(2, $posts);
        self::assertSame(['First', 'Second'], $posts->pluck('title')->sort()->values()->all());

        $loaded = User::with('posts')->where('id', $user->id)->firstOrFail();

        self::assertTrue($loaded->relationLoaded('posts'));
        self::assertCount(2, $loaded->posts);
        self::assertSame($user->id, $loaded->posts->first()->user->id);
    }

    public function testEloquentBelongsToManyRoles(): void
    {
        $user = User::create(['name' => 'Ada']);
        $admin = Role::create(['name' => 'admin']);
        $editor = Role::create(['name' => 'editor']);

        $user->roles()->attach([$admin->id, $editor->id]);

        $roles = $user->fresh()->roles;
        self::assertCount(2, $roles);
        self::assertSame(['admin', 'editor'], $roles->pluck('name')->sort()->values()->all());

        $loaded = User::with('roles')->where('id', $user->id)->firstOrFail();

        self::assertTrue($loaded->relationLoaded('roles'));
        self::assertCount(2, $loaded->roles);
        self::assertSame($user->id, $loaded->roles->first()->users->first()->id);

        $user->roles()->detach($editor->id);
        self::assertSame(['admin'], $user->fresh()->roles->pluck('name')->all());
    }

    public function testEloquentBelongsToManyRelationQueryConstraints(): void
    {
        $user = User::create(['name' => 'Ada']);
        $other = User::create(['name' => 'Alan']);
        $admin = Role::create(['name' => 'admin']);
        $editor = Role::create(['name' => 'editor']);
        $viewer = Role::create(['name' => 'viewer']);

        $user->roles()->attach([$admin->id, $editor->id]);
        $other->roles()->attach($viewer->id);

        $filtered = $user->roles()->where('name', 'admin')->get();
        self::assertCount(1, $filtered);
        self::assertSame('admin', $filtered->first()->name);
        self::assertSame($user->id, $filtered->first()->pivot->user_id);

        self::assertSame(2, $user->roles()->count());
        self::assertSame(1, $user->roles()->where('name', 'editor')->count());
        self::assertTrue($user->roles()->where('name', 'admin')->exists());
        self::assertFalse($user->roles()->where('name', 'viewer')->exists());

        $ordered = $user->roles()->orderBy('name')->pluck('name')->all();
        self::assertSame(['admin', 'editor'], $ordered);

        $constrained = User::with(['roles' => static function ($query): void {
            $query->where('name', 'admin')->orderBy('name');
        }])->where('id', $user->id)->firstOrFail();

        self::assertTrue($constrained->relationLoaded('roles'));
        self::assertCount(1, $constrained->roles);
        self::assertSame('admin', $constrained->roles->first()->name);
        self::assertSame($user->id, $constrained->roles->first()->pivot->user_id);
    }
}

final class User extends Neo4jModel
{
    protected $guarded = [];

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class, 'user_id', 'id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id', 'id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'RoleUser', 'user_id', 'role_id');
    }
}

final class Profile extends Neo4jModel
{
    protected $table = 'Profile';

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}

final class SoftUser extends Neo4jModel
{
    use SoftDeletes;

    protected $table = 'SoftUser';

    protected $guarded = [];
}


final class Post extends Neo4jModel
{
    protected $table = 'Post';

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}

final class Role extends Neo4jModel
{
    protected $table = 'Role';

    protected $guarded = [];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'RoleUser', 'role_id', 'user_id');
    }
}


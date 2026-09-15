<?php

namespace Neo4j\Neo4jLaravel\Tests\Integration;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
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
        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:RoleUser) DETACH DELETE n');
        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:Country) DETACH DELETE n');
        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:Mechanic) DETACH DELETE n');
        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:Car) DETACH DELETE n');
        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:Owner) DETACH DELETE n');
        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:Video) DETACH DELETE n');
        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:Image) DETACH DELETE n');
        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:Comment) DETACH DELETE n');
        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:Tag) DETACH DELETE n');
        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:Taggable) DETACH DELETE n');
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

    public function testEloquentWhereInSubquerySelectsUsersWithPosts(): void
    {
        $ada = User::create(['name' => 'Ada']);
        $alan = User::create(['name' => 'Alan']);

        $ada->posts()->create(['title' => 'First']);

        $authors = User::query()
            ->whereIn('id', function ($query): void {
                $query->select('user_id')->from('Post');
            })
            ->orderBy('name')
            ->get();

        self::assertSame(['Ada'], $authors->pluck('name')->all());
        self::assertSame($ada->id, $authors->first()->id);
        self::assertFalse($authors->contains(fn (User $user): bool => $user->id === $alan->id));
    }

    public function testEloquentWhereExistsSubquerySelectsUsersWithMatchingPosts(): void
    {
        $ada = User::create(['name' => 'Ada']);
        $alan = User::create(['name' => 'Alan']);

        $ada->posts()->create(['title' => 'Graphs']);
        $alan->posts()->create(['title' => 'Other']);

        $matched = User::query()
            ->whereExists(function ($query): void {
                $query->from('Post')
                    ->whereColumn('Post.user_id', 'User.id')
                    ->where('title', 'Graphs');
            })
            ->get();

        self::assertCount(1, $matched);
        self::assertSame($ada->id, $matched->first()->id);
        self::assertSame('Ada', $matched->first()->name);
    }

    public function testEloquentWhereNotExistsSubqueryExcludesUsersWithPosts(): void
    {
        $ada = User::create(['name' => 'Ada']);
        $alan = User::create(['name' => 'Alan']);

        $ada->posts()->create(['title' => 'First']);

        $withoutPosts = User::query()
            ->whereNotExists(function ($query): void {
                $query->from('Post')
                    ->whereColumn('Post.user_id', 'User.id');
            })
            ->orderBy('name')
            ->get();

        self::assertSame(['Alan'], $withoutPosts->pluck('name')->all());
        self::assertSame($alan->id, $withoutPosts->first()->id);
    }

    public function testEloquentWhereInAcceptsEloquentBuilderSubquery(): void
    {
        $ada = User::create(['name' => 'Ada']);
        $alan = User::create(['name' => 'Alan']);

        $ada->posts()->create(['title' => 'First']);

        $authors = User::query()
            ->whereIn('id', Post::query()->select('user_id'))
            ->orderBy('name')
            ->get();

        self::assertSame(['Ada'], $authors->pluck('name')->all());
        self::assertFalse($authors->contains(fn (User $user): bool => $user->id === $alan->id));
    }

    public function testEloquentWhereInEloquentBuilderKeepsOuterCorrelation(): void
    {
        $ada = User::create(['name' => 'Ada']);
        User::create(['name' => 'Alan']);

        $ada->posts()->create(['title' => 'Graphs']);

        $matched = User::query()
            ->whereIn(
                'id',
                Post::query()
                    ->select('user_id')
                    ->whereColumn('Post.user_id', 'User.id')
                    ->where('title', 'Graphs')
            )
            ->get();

        self::assertCount(1, $matched);
        self::assertSame($ada->id, $matched->first()->id);
        self::assertSame('Ada', $matched->first()->name);
    }

    public function testEloquentHasManyThroughPosts(): void
    {
        $india = Country::create(['name' => 'India']);
        $usa = Country::create(['name' => 'USA']);

        $ada = User::create(['name' => 'Ada', 'country_id' => $india->id]);
        $alan = User::create(['name' => 'Alan', 'country_id' => $india->id]);
        $grace = User::create(['name' => 'Grace', 'country_id' => $usa->id]);

        $ada->posts()->create(['title' => 'Graphs']);
        $ada->posts()->create(['title' => 'Cypher']);
        $alan->posts()->create(['title' => 'Indexes']);
        $grace->posts()->create(['title' => 'Elsewhere']);

        $posts = $india->fresh()->posts;
        self::assertCount(3, $posts);
        self::assertSame(['Cypher', 'Graphs', 'Indexes'], $posts->pluck('title')->sort()->values()->all());

        $loaded = Country::with('posts')->where('id', $india->id)->firstOrFail();
        self::assertTrue($loaded->relationLoaded('posts'));
        self::assertCount(3, $loaded->posts);
    }

    public function testEloquentHasManyThroughRelationQueryConstraints(): void
    {
        $india = Country::create(['name' => 'India']);
        $usa = Country::create(['name' => 'USA']);

        $ada = User::create(['name' => 'Ada', 'country_id' => $india->id]);
        $alan = User::create(['name' => 'Alan', 'country_id' => $india->id]);
        User::create(['name' => 'Grace', 'country_id' => $usa->id])
            ->posts()->create(['title' => 'Elsewhere']);

        $ada->posts()->create(['title' => 'Graphs']);
        $ada->posts()->create(['title' => 'Cypher']);
        $alan->posts()->create(['title' => 'Indexes']);

        $filtered = $india->posts()->where('title', 'Graphs')->get();
        self::assertCount(1, $filtered);
        self::assertSame('Graphs', $filtered->first()->title);

        self::assertSame(3, $india->posts()->count());
        self::assertSame(1, $india->posts()->where('title', 'Cypher')->count());
        self::assertTrue($india->posts()->where('title', 'Indexes')->exists());
        self::assertFalse($india->posts()->where('title', 'Elsewhere')->exists());

        $ordered = $india->posts()->orderBy('title')->pluck('title')->all();
        self::assertSame(['Cypher', 'Graphs', 'Indexes'], $ordered);

        $constrained = Country::with(['posts' => static function ($query): void {
            $query->where('title', 'Graphs')->orderBy('title');
        }])->where('id', $india->id)->firstOrFail();

        self::assertTrue($constrained->relationLoaded('posts'));
        self::assertCount(1, $constrained->posts);
        self::assertSame('Graphs', $constrained->posts->first()->title);
    }

    public function testEloquentHasOneThroughOwner(): void
    {
        $mechanic = Mechanic::create(['name' => 'Tony']);
        $other = Mechanic::create(['name' => 'Sam']);

        $car = Car::create(['model' => 'Civic', 'mechanic_id' => $mechanic->id]);
        Car::create(['model' => 'Focus', 'mechanic_id' => $other->id]);

        $owner = Owner::create(['name' => 'Ada', 'car_id' => $car->id]);

        $found = $mechanic->fresh()->carOwner;
        self::assertInstanceOf(Owner::class, $found);
        self::assertSame($owner->id, $found->id);
        self::assertSame('Ada', $found->name);

        $loaded = Mechanic::with('carOwner')->where('id', $mechanic->id)->firstOrFail();
        self::assertTrue($loaded->relationLoaded('carOwner'));
        self::assertSame('Ada', $loaded->carOwner->name);
    }

    public function testEloquentHasOneThroughRelationQueryConstraints(): void
    {
        $mechanic = Mechanic::create(['name' => 'Tony']);
        $car = Car::create(['model' => 'Civic', 'mechanic_id' => $mechanic->id]);
        Owner::create(['name' => 'Ada', 'car_id' => $car->id]);

        self::assertTrue($mechanic->carOwner()->where('name', 'Ada')->exists());
        self::assertFalse($mechanic->carOwner()->where('name', 'Missing')->exists());
        self::assertSame(1, $mechanic->carOwner()->count());
        self::assertSame('Ada', $mechanic->carOwner()->where('name', 'Ada')->first()?->name);

        $constrained = Mechanic::with(['carOwner' => static function ($query): void {
            $query->where('name', 'Ada');
        }])->where('id', $mechanic->id)->firstOrFail();

        self::assertTrue($constrained->relationLoaded('carOwner'));
        self::assertSame('Ada', $constrained->carOwner->name);

        $empty = Mechanic::with(['carOwner' => static function ($query): void {
            $query->where('name', 'Missing');
        }])->where('id', $mechanic->id)->firstOrFail();

        self::assertTrue($empty->relationLoaded('carOwner'));
        self::assertNull($empty->carOwner);
    }

    public function testEloquentMorphOneImage(): void
    {
        $post = Post::create(['title' => 'Graphs', 'user_id' => User::create(['name' => 'Ada'])->id]);
        $video = Video::create(['title' => 'Intro']);

        $postImage = $post->image()->create(['url' => 'post.png']);
        $video->image()->create(['url' => 'video.png']);

        self::assertInstanceOf(Image::class, $postImage);
        self::assertSame($post->id, $postImage->imageable_id);
        self::assertSame($post->getMorphClass(), $postImage->imageable_type);

        $loaded = $post->fresh()->image;
        self::assertInstanceOf(Image::class, $loaded);
        self::assertSame('post.png', $loaded->url);
        self::assertInstanceOf(Post::class, $loaded->imageable);
        self::assertSame($post->id, $loaded->imageable->id);

        $eager = Post::with('image')->where('id', $post->id)->firstOrFail();
        self::assertTrue($eager->relationLoaded('image'));
        self::assertSame('post.png', $eager->image->url);
    }

    public function testEloquentMorphManyComments(): void
    {
        $post = Post::create(['title' => 'Graphs', 'user_id' => User::create(['name' => 'Ada'])->id]);
        $video = Video::create(['title' => 'Intro']);

        $post->comments()->create(['body' => 'Nice']);
        $post->comments()->create(['body' => 'Thanks']);
        $video->comments()->create(['body' => 'Watch later']);

        $comments = $post->fresh()->comments;
        self::assertCount(2, $comments);
        self::assertSame(['Nice', 'Thanks'], $comments->pluck('body')->sort()->values()->all());
        self::assertInstanceOf(Post::class, $comments->first()->commentable);

        $eager = Post::with('comments')->where('id', $post->id)->firstOrFail();
        self::assertTrue($eager->relationLoaded('comments'));
        self::assertCount(2, $eager->comments);
    }

    public function testEloquentMorphManyRelationQueryConstraints(): void
    {
        $post = Post::create(['title' => 'Graphs', 'user_id' => User::create(['name' => 'Ada'])->id]);
        $other = Post::create(['title' => 'Other', 'user_id' => User::create(['name' => 'Alan'])->id]);

        $post->comments()->create(['body' => 'Alpha']);
        $post->comments()->create(['body' => 'Beta']);
        $other->comments()->create(['body' => 'Gamma']);

        $filtered = $post->comments()->where('body', 'Alpha')->get();
        self::assertCount(1, $filtered);
        self::assertSame('Alpha', $filtered->first()->body);

        self::assertSame(2, $post->comments()->count());
        self::assertTrue($post->comments()->where('body', 'Beta')->exists());
        self::assertFalse($post->comments()->where('body', 'Gamma')->exists());

        $ordered = $post->comments()->orderBy('body')->pluck('body')->all();
        self::assertSame(['Alpha', 'Beta'], $ordered);

        $constrained = Post::with(['comments' => static function ($query): void {
            $query->where('body', 'Alpha')->orderBy('body');
        }])->where('id', $post->id)->firstOrFail();

        self::assertTrue($constrained->relationLoaded('comments'));
        self::assertCount(1, $constrained->comments);
        self::assertSame('Alpha', $constrained->comments->first()->body);
    }

    public function testEloquentMorphToManyTags(): void
    {
        $post = Post::create(['title' => 'Graphs', 'user_id' => User::create(['name' => 'Ada'])->id]);
        $video = Video::create(['title' => 'Intro']);
        $neo4j = Tag::create(['name' => 'neo4j']);
        $php = Tag::create(['name' => 'php']);
        $laravel = Tag::create(['name' => 'laravel']);

        $post->tags()->attach([$neo4j->id, $php->id]);
        $video->tags()->attach($laravel->id);

        $tags = $post->fresh()->tags;
        self::assertCount(2, $tags);
        self::assertSame(['neo4j', 'php'], $tags->pluck('name')->sort()->values()->all());
        self::assertSame($post->id, $tags->first()->pivot->taggable_id);
        self::assertSame($post->getMorphClass(), $tags->first()->pivot->taggable_type);

        $loaded = Post::with('tags')->where('id', $post->id)->firstOrFail();
        self::assertTrue($loaded->relationLoaded('tags'));
        self::assertCount(2, $loaded->tags);
        self::assertSame($post->id, $loaded->tags->first()->posts->first()->id);

        $post->tags()->detach($php->id);
        self::assertSame(['neo4j'], $post->fresh()->tags->pluck('name')->all());
    }

    public function testEloquentMorphToManyRelationQueryConstraints(): void
    {
        $post = Post::create(['title' => 'Graphs', 'user_id' => User::create(['name' => 'Ada'])->id]);
        $other = Post::create(['title' => 'Other', 'user_id' => User::create(['name' => 'Alan'])->id]);
        $neo4j = Tag::create(['name' => 'neo4j']);
        $php = Tag::create(['name' => 'php']);
        $laravel = Tag::create(['name' => 'laravel']);

        $post->tags()->attach([$neo4j->id, $php->id]);
        $other->tags()->attach($laravel->id);

        $filtered = $post->tags()->where('name', 'neo4j')->get();
        self::assertCount(1, $filtered);
        self::assertSame('neo4j', $filtered->first()->name);
        self::assertSame($post->id, $filtered->first()->pivot->taggable_id);

        self::assertSame(2, $post->tags()->count());
        self::assertSame(1, $post->tags()->where('name', 'php')->count());
        self::assertTrue($post->tags()->where('name', 'neo4j')->exists());
        self::assertFalse($post->tags()->where('name', 'laravel')->exists());

        $ordered = $post->tags()->orderBy('name')->pluck('name')->all();
        self::assertSame(['neo4j', 'php'], $ordered);

        $constrained = Post::with(['tags' => static function ($query): void {
            $query->where('name', 'neo4j')->orderBy('name');
        }])->where('id', $post->id)->firstOrFail();

        self::assertTrue($constrained->relationLoaded('tags'));
        self::assertCount(1, $constrained->tags);
        self::assertSame('neo4j', $constrained->tags->first()->name);
        self::assertSame($post->id, $constrained->tags->first()->pivot->taggable_id);
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

    public function image(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable', 'Taggable', 'taggable_id', 'tag_id');
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

final class Country extends Neo4jModel
{
    protected $table = 'Country';

    protected $guarded = [];

    public function posts(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, User::class, 'country_id', 'user_id', 'id', 'id');
    }
}

final class Mechanic extends Neo4jModel
{
    protected $table = 'Mechanic';

    protected $guarded = [];

    public function carOwner(): HasOneThrough
    {
        return $this->hasOneThrough(Owner::class, Car::class, 'mechanic_id', 'car_id', 'id', 'id');
    }
}

final class Car extends Neo4jModel
{
    protected $table = 'Car';

    protected $guarded = [];
}

final class Owner extends Neo4jModel
{
    protected $table = 'Owner';

    protected $guarded = [];
}

final class Video extends Neo4jModel
{
    protected $table = 'Video';

    protected $guarded = [];

    public function image(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable', 'Taggable', 'taggable_id', 'tag_id');
    }
}

final class Image extends Neo4jModel
{
    protected $table = 'Image';

    protected $guarded = [];

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}

final class Comment extends Neo4jModel
{
    protected $table = 'Comment';

    protected $guarded = [];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

final class Tag extends Neo4jModel
{
    protected $table = 'Tag';

    protected $guarded = [];

    public function posts(): MorphToMany
    {
        return $this->morphedByMany(Post::class, 'taggable', 'Taggable', 'tag_id', 'taggable_id');
    }
}

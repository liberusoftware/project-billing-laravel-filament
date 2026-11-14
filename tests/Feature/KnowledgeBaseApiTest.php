<?php

use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeBaseCategory;
use App\Models\User;
use App\Services\KnowledgeBaseService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->author = User::factory()->create();
    $this->category = KnowledgeBaseCategory::create(['name' => 'Getting Started', 'is_active' => true]);
    $this->article = KnowledgeBaseArticle::create([
        'category_id' => $this->category->id,
        'author_id' => $this->author->id,
        'title' => 'Reset your password',
        'content' => 'Request a password reset email from the sign-in page.',
        'is_published' => true,
        'is_featured' => true,
        'view_count' => 10,
        'sort_order' => 2,
    ]);
    $this->draft = KnowledgeBaseArticle::create([
        'category_id' => $this->category->id,
        'author_id' => $this->author->id,
        'title' => 'Private password recovery draft',
        'content' => 'This content is awaiting review.',
        'is_published' => false,
        'is_featured' => true,
        'view_count' => 100,
        'sort_order' => 0,
    ]);
});

it('returns active root categories and their ordered active children to guests', function () {
    $later = KnowledgeBaseCategory::create(['name' => 'Later child', 'parent_id' => $this->category->id, 'is_active' => true, 'sort_order' => 2]);
    $first = KnowledgeBaseCategory::create(['name' => 'First child', 'parent_id' => $this->category->id, 'is_active' => true, 'sort_order' => 1]);
    KnowledgeBaseCategory::create(['name' => 'Hidden child', 'parent_id' => $this->category->id, 'is_active' => false]);
    KnowledgeBaseCategory::create(['name' => 'Hidden root', 'is_active' => false]);

    $this->getJson('/api/knowledge-base/categories')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->category->id)
        ->assertJsonPath('data.0.children.0.id', $first->id)
        ->assertJsonPath('data.0.children.1.id', $later->id)
        ->assertJsonCount(2, 'data.0.children');
});

it('searches published articles using default and explicit category limits', function () {
    $this->getJson('/api/knowledge-base/search?q=password')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->article->id);
    $this->getJson('/api/knowledge-base/search?q=password&category_id='.$this->category->id.'&limit=1')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->article->id);
    $other = KnowledgeBaseCategory::create(['name' => 'Other category', 'is_active' => true]);
    $this->getJson('/api/knowledge-base/search?q=password&category_id='.$other->id)->assertOk()->assertJsonCount(0, 'data');
});

it('validates public article searches', function (string $query, string $field) {
    $this->getJson('/api/knowledge-base/search'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing query' => ['', 'q'],
    'short query' => ['?q=x', 'q'],
    'missing category' => ['?q=password&category_id=999999', 'category_id'],
    'zero limit' => ['?q=password&limit=0', 'limit'],
    'excessive limit' => ['?q=password&limit=101', 'limit'],
]);

it('lists only published featured and popular articles with configurable limits', function (string $endpoint) {
    $this->getJson('/api/knowledge-base/'.$endpoint)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->article->id);
    $this->getJson('/api/knowledge-base/'.$endpoint.'?limit=1')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->article->id);
})->with(['featured', 'popular']);

it('returns a published article with its author category and related published articles while tracking views', function () {
    $related = KnowledgeBaseArticle::create([
        'category_id' => $this->category->id,
        'author_id' => $this->author->id,
        'title' => 'Password recovery help',
        'content' => 'Contact support if you cannot receive the reset email.',
        'is_published' => true,
    ]);

    $this->getJson('/api/knowledge-base/articles/'.$this->article->slug)->assertOk()
        ->assertJsonPath('data.id', $this->article->id)
        ->assertJsonPath('data.view_count', 11)
        ->assertJsonPath('data.category.id', $this->category->id)
        ->assertJsonPath('data.author.id', $this->author->id)
        ->assertJsonCount(1, 'related')
        ->assertJsonPath('related.0.id', $related->id);
    expect($this->article->fresh()->view_count)->toBe(11);
});

it('records public helpful and unhelpful feedback independently', function () {
    $url = '/api/knowledge-base/articles/'.$this->article->slug;
    $this->postJson($url.'/helpful')->assertOk()->assertJsonPath('message', 'Thank you for your feedback');
    $this->postJson($url.'/not-helpful')->assertOk()->assertJsonPath('message', 'Thank you for your feedback');
    expect($this->article->fresh()->helpful_count)->toBe(1)
        ->and($this->article->fresh()->not_helpful_count)->toBe(1)
        ->and($this->article->fresh()->view_count)->toBe(10);
});

it('does not expose drafts or accept feedback for unpublished or missing articles', function () {
    foreach ([$this->draft->slug, 'missing-article'] as $slug) {
        $url = '/api/knowledge-base/articles/'.$slug;
        $this->getJson($url)->assertNotFound();
        $this->postJson($url.'/helpful')->assertNotFound();
        $this->postJson($url.'/not-helpful')->assertNotFound();
    }
    expect($this->draft->fresh()->view_count)->toBe(100)
        ->and($this->draft->fresh()->helpful_count)->toBe(0)
        ->and($this->draft->fresh()->not_helpful_count)->toBe(0);
});

it('lists published articles belonging to the requested category', function () {
    $this->getJson('/api/knowledge-base/categories/'.$this->category->id.'/articles')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->article->id);
    $this->getJson('/api/knowledge-base/categories/999999/articles')->assertOk()->assertJsonCount(0, 'data');
});

it('stamps publication time once and reports no helpful votes as zero', function () {
    expect($this->draft->published_at)->toBeNull()
        ->and($this->draft->getHelpfulnessRatio())->toBe(0.0);
    $this->draft->update(['is_published' => true]);
    $publishedAt = $this->draft->fresh()->published_at;
    expect($publishedAt)->not->toBeNull();
    $this->draft->update(['title' => 'Reviewed password recovery guide']);
    expect($this->draft->fresh()->published_at->equalTo($publishedAt))->toBeTrue();
});

it('counts published articles for active categories', function () {
    KnowledgeBaseCategory::create(['name' => 'Inactive category', 'is_active' => false]);
    $categories = app(KnowledgeBaseService::class)->getCategoriesWithCounts();
    expect($categories)->toHaveCount(1)
        ->and($categories->first()->id)->toBe($this->category->id)
        ->and($categories->first()->published_articles_count)->toBe(1);
});

it('compiles MySQL full-text search together with publication category and limit constraints', function () {
    $defaultConnection = config('database.default');
    $connection = DB::connection('mysql');
    $pdo = DB::connection()->getPdo();
    $connection->setPdo($pdo);
    $connection->setReadPdo($pdo);
    config()->set('database.default', 'mysql');
    try {
        $queries = $connection->pretend(function () {
            app(KnowledgeBaseService::class)->search('password', $this->category->id, 3);
        });
    } finally {
        config()->set('database.default', $defaultConnection);
    }
    expect($queries)->toHaveCount(1)
        ->and($queries[0]['query'])->toContain("match (`title`, `content`) against ('password' in natural language mode)", '`is_published` = 1', '`category_id` = '.$this->category->id, 'limit 3')
        ->and($queries[0]['bindings'])->toContain(true, 'password', '%password%', $this->category->id);
});

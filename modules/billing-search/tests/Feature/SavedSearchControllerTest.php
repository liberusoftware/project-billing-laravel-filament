<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\SavedSearchController;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SavedSearchControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_store_share_and_load_shared_saved_searches(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $controller = new SavedSearchController();

        $storeRequest = Request::create('/api/saved-searches', 'POST', [
            'name' => 'Overdue Invoices',
            'criteria' => ['status' => 'overdue', 'min_amount' => 100],
        ]);
        $storeResponse = $controller->store($storeRequest);

        $this->assertSame(200, $storeResponse->getStatusCode());
        $stored = $storeResponse->getData(true);
        $this->assertSame('Overdue Invoices', $stored['name']);
        $this->assertSame(['status' => 'overdue', 'min_amount' => 100], $stored['criteria']);

        $savedSearch = SavedSearch::findOrFail($stored['id']);
        $this->assertTrue($savedSearch->user->is($user));
        $this->assertSame(['status' => 'overdue', 'min_amount' => 100], $savedSearch->criteria);

        $indexResponse = $controller->index();
        $this->assertSame(200, $indexResponse->getStatusCode());
        $this->assertCount(1, $indexResponse->getData(true));

        $shareRequest = Request::create('/api/shared-searches', 'POST', [
            'criteria' => ['type' => 'customers', 'active' => true],
        ]);
        $shareResponse = $controller->share($shareRequest);
        $this->assertSame(200, $shareResponse->getStatusCode());
        $token = $shareResponse->getData(true)['token'];
        $this->assertSame(32, strlen($token));

        $loadedResponse = $controller->loadShared($token);
        $this->assertSame(200, $loadedResponse->getStatusCode());
        $this->assertSame(['type' => 'customers', 'active' => true], $loadedResponse->getData(true));

        $this->expectException(ModelNotFoundException::class);
        $controller->loadShared('non-existent-share-token');
    }
}

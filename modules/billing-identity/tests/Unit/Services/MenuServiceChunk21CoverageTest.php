<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Menu;
use App\Services\MenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Menu\Laravel\Menu as SpatieMenu;
use Tests\TestCase;

class MenuServiceChunk21CoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_menu_returns_empty_spatie_menu_when_no_items_exist(): void
    {
        $menu = app(MenuService::class)->buildMenu();

        $this->assertInstanceOf(SpatieMenu::class, $menu);
        $this->assertCount(0, $menu);
        $this->assertStringContainsString('flex items-center space-x-4', $menu->render());
    }

    public function test_build_menu_renders_ordered_top_level_links_and_nested_submenus(): void
    {
        Menu::create([
            'name' => 'Pricing',
            'url' => '/pricing',
            'order' => 2,
        ]);

        Menu::create([
            'name' => 'Home',
            'url' => '/home',
            'order' => 1,
        ]);

        $products = Menu::create([
            'name' => 'Products',
            'url' => '/products',
            'order' => 3,
        ]);

        Menu::create([
            'name' => 'Hosting',
            'url' => '/products/hosting',
            'parent_id' => $products->id,
            'order' => 1,
        ]);

        Menu::create([
            'name' => 'Domains',
            'url' => '/products/domains',
            'parent_id' => $products->id,
            'order' => 2,
        ]);

        $menu = app(MenuService::class)->buildMenu();
        $html = $menu->render();

        $this->assertInstanceOf(SpatieMenu::class, $menu);
        $this->assertCount(3, $menu);

        $this->assertStringContainsString('flex items-center space-x-4', $html);
        $this->assertStringContainsString('px-4 py-2 rounded-md bg-green-700 text-white hover:bg-green-600 transition duration-300 ease-in-out', $html);

        $this->assertStringContainsString('href="/home"', $html);
        $this->assertStringContainsString('Home', $html);
        $this->assertStringContainsString('href="/pricing"', $html);
        $this->assertStringContainsString('Pricing', $html);
        $this->assertLessThan(strpos($html, 'Pricing'), strpos($html, 'Home'));

        $this->assertStringContainsString('href="/products"', $html);
        $this->assertStringContainsString('Products', $html);
        $this->assertStringContainsString('relative group', $html);
        $this->assertStringContainsString('absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1', $html);
        $this->assertStringContainsString('hidden group-hover:block', $html);
        $this->assertStringContainsString('href="/products/hosting"', $html);
        $this->assertStringContainsString('Hosting', $html);
        $this->assertStringContainsString('href="/products/domains"', $html);
        $this->assertStringContainsString('Domains', $html);
    }
}

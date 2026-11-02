<?php

namespace Tests\Feature\Navigation;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Navigation\SidebarNavigation;
use App\Support\Sales\SalesAccess;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            throw new \LogicException('Use the guarded isolated runner.');
        }
        $this->prepareFirstRunSchema();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }

    public function test_owner_groups_are_ordered_and_every_link_is_unique_real_and_authorized(): void
    {
        $owner = User::factory()->salesOwner()->create();
        $html = $this->actingAs($owner)->get('/dashboard')->assertOk()->getContent();
        $dom = $this->xpath($html);
        $groups = $dom->query('//aside[@id="sidebar"]//details[@data-nav-group]');
        $this->assertSame(['sales', 'inventory', 'purchases', 'finance', 'store', 'reports', 'account'],
            array_map(fn ($node) => $node->getAttribute('data-nav-group'), iterator_to_array($groups)));
        $links = $dom->query('//aside[@id="sidebar"]//a[@href]');
        $urls = array_map(fn ($node) => $node->getAttribute('href'), iterator_to_array($links));
        $this->assertSame($urls, array_values(array_unique($urls)));
        foreach ($urls as $url) {
            $this->assertNotSame('#', $url);
            $this->assertStringStartsWith('http', $url);
        }
        $definitions = app(SidebarNavigation::class)->groups($owner, request());
        foreach ($definitions as $group) {
            $this->assertNotEmpty($group['links']);
            foreach ($group['links'] as $link) {
                $this->assertTrue(Route::has($link['route']));
            }
        }
    }

    public function test_links_are_in_correct_groups_and_branch_group_is_open_on_branch_pages(): void
    {
        $owner = User::factory()->salesOwner()->create();
        $dom = $this->xpath($this->actingAs($owner)->get(route('branches.index'))->assertOk()->getContent());
        foreach (['sales' => 'sales.cashier', 'inventory' => 'inventory.index', 'purchases' => 'purchases.invoices.index',
            'finance' => 'finance.treasury.index', 'store' => 'branches.index', 'reports' => 'sales.reports', 'account' => 'profile.show'] as $group => $route) {
            $this->assertSame(1, $dom->query('//details[@data-nav-group="'.$group.'"]//a[@href="'.route($route).'"]')->length);
        }
        $this->assertSame(1, $dom->query('//details[@data-nav-group="store"][@open]')->length);
        $this->assertSame(1, $dom->query('//details[@data-nav-group="store"]/summary[@aria-expanded="true"]')->length);
        $this->assertSame(1, $dom->query('//aside//a[@aria-current="page"][@href="'.route('branches.index').'"]')->length);
        $this->assertSame(1, $dom->query('//aside//a[@aria-current="page"]')->length);

        $cashier = $this->get(route('sales.cashier'))->assertOk()->assertSee('<h1>الكاشير</h1>', false);
        $dom = $this->xpath($cashier->getContent());
        $link = $dom->query('//aside//a[@href="'.route('sales.cashier').'"]');
        $this->assertSame(1, $link->length);
        $this->assertSame('الكاشير', trim($link->item(0)->textContent));
        $this->assertSame('page', $link->item(0)->getAttribute('aria-current'));
        $this->assertSame(1, $dom->query('//details[@data-nav-group="sales"][@open]')->length);
    }

    public function test_cashier_and_viewer_do_not_gain_branch_finance_or_admin_links_and_empty_groups_are_hidden(): void
    {
        foreach (['sales_cashier' => ['sales', 'inventory', 'account'], 'inventory_viewer' => ['inventory', 'account']] as $roleName => $expected) {
            $user = User::factory()->create(['role_id' => SalesAccess::provisionRole($roleName)->id]);
            session()->forget('password_hash_web');
            $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
            $dom = $this->xpath($html);
            $groups = $dom->query('//aside//details[@data-nav-group]');
            $this->assertSame($expected, array_map(fn ($node) => $node->getAttribute('data-nav-group'), iterator_to_array($groups)));
            foreach (['branches.index', 'finance.treasury.index', 'access.users.index', 'access.roles.index', 'inventory.opening-stock.index'] as $route) {
                $this->assertSame(0, $dom->query('//aside//a[@href="'.route($route).'"]')->length);
            }
        }
    }

    public function test_composite_route_permissions_are_required_before_links_are_shown(): void
    {
        User::factory()->salesOwner()->create();
        $role = Role::create(['name' => 'incomplete_links', 'display_name' => 'Limited', 'is_active' => true]);
        $role->permissions()->sync(Permission::whereIn('name', ['sales.reports', 'finance.transition_review', 'inventory.correct_setup', 'inventory.valuation_review', 'sales.sell'])->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id]);
        $dom = $this->xpath($this->actingAs($user)->get('/dashboard')->assertOk()->getContent());
        $this->assertSame(1, $dom->query('//aside//details[@data-nav-group]')->length);
        $this->assertSame(1, $dom->query('//aside//details[@data-nav-group="account"]')->length);
        foreach (['sales.reports', 'finance.transition.index', 'inventory.opening-stock.index', 'sales.cashier'] as $route) {
            $this->assertSame(0, $dom->query('//aside//a[@href="'.route($route).'"]')->length);
            $this->get(route($route))->assertForbidden();
        }
    }

    public function test_profile_contains_sidebar_with_account_group_active_and_logout_uses_csrf_post(): void
    {
        $user = User::factory()->salesOwner()->create();
        $dom = $this->xpath($this->actingAs($user)->get(route('profile.show'))->assertOk()->getContent());
        $this->assertSame(1, $dom->query('//details[@data-nav-group="account"][@open]')->length);
        $this->assertSame(1, $dom->query('//details[@data-nav-group="account"]//a[@aria-current="page"]')->length);
        $this->assertSame(1, $dom->query('//aside//form[@action="'.route('logout').'"][@method="POST"]//input[@name="_token"]')->length);
    }
}

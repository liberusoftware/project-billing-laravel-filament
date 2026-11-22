<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_model_relationships_and_casts(): void
    {
        $user = User::factory()->create();

        $log = AuditLog::create([
            'user_id' => $user->id,
            'event' => 'updated',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => ['name' => 'Before'],
            'new_values' => ['name' => 'After'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $fresh = $log->fresh();
        $this->assertTrue($fresh->user->is($user));
        $this->assertTrue($fresh->auditable->is($user));
        $this->assertSame(['name' => 'Before'], $fresh->old_values);
        $this->assertSame(['name' => 'After'], $fresh->new_values);
    }

    public function test_prune_audit_logs_command_deletes_only_old_records(): void
    {
        $oldLog = AuditLog::create([
            'event' => 'old_event',
        ]);
        AuditLog::whereKey($oldLog->id)->update(['created_at' => now()->subDays(120)]);

        $recentLog = AuditLog::create([
            'event' => 'recent_event',
        ]);

        $this->artisan('audit:prune', ['--days' => 90])
            ->expectsOutput('Deleted 1 audit logs older than 90 days.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('audit_logs', ['id' => $oldLog->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recentLog->id]);
    }

    public function test_audit_log_resource_authorization_and_table_definition(): void
    {
        $this->assertFalse(AuditLogResource::canViewAny());
        $this->assertFalse(AuditLogResource::canAccess());
        $this->assertTrue(AuditLogResource::getViewAnyAuthorizationResponse()->denied());

        $regularUser = User::factory()->create();
        $this->actingAs($regularUser);
        $this->assertFalse(AuditLogResource::canViewAny());
        $this->assertFalse(AuditLogResource::canAccess());
        $this->assertTrue(AuditLogResource::getViewAnyAuthorizationResponse()->denied());

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');
        $this->actingAs($superAdmin);

        $this->assertTrue(AuditLogResource::canViewAny());
        $this->assertTrue(AuditLogResource::canAccess());
        $this->assertTrue(AuditLogResource::getViewAnyAuthorizationResponse()->allowed());

        $livewire = Mockery::mock(HasTable::class);
        $table = AuditLogResource::table(Table::make($livewire));
        $this->assertCount(5, $table->getColumns());
        $this->assertCount(2, $table->getFilters());
    }
}

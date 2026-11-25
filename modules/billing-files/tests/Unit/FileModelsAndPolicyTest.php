<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\File;
use App\Models\FileShare;
use App\Models\Folder;
use App\Models\Team;
use App\Models\User;
use App\Policies\FilePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileModelsAndPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_folder_file_and_file_share_relationships(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id]);

        $parentFolder = Folder::create([
            'name' => 'Root',
            'user_id' => $owner->id,
            'team_id' => $team->id,
        ]);

        $childFolder = Folder::create([
            'name' => 'Invoices',
            'parent_id' => $parentFolder->id,
            'user_id' => $owner->id,
            'team_id' => $team->id,
        ]);

        $file = File::create([
            'name' => 'statement.pdf',
            'path' => 'files/statement.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'folder_id' => $childFolder->id,
            'user_id' => $owner->id,
            'team_id' => $team->id,
        ]);

        $share = FileShare::create([
            'file_id' => $file->id,
            'user_id' => $recipient->id,
            'permission' => 'read',
        ]);

        $this->assertTrue($childFolder->user->is($owner));
        $this->assertTrue($childFolder->team->is($team));
        $this->assertTrue($childFolder->parent->is($parentFolder));
        $this->assertCount(1, $parentFolder->children);
        $this->assertTrue($parentFolder->children->first()->is($childFolder));
        $this->assertCount(1, $childFolder->files);
        $this->assertTrue($childFolder->files->first()->is($file));

        $this->assertTrue($file->user->is($owner));
        $this->assertTrue($file->folder->is($childFolder));
        $this->assertTrue($file->team->is($team));
        $this->assertCount(1, $file->shares);
        $this->assertTrue($file->shares->first()->is($share));

        $this->assertTrue($share->file->is($file));
        $this->assertTrue($share->user->is($recipient));
    }

    public function test_file_policy_authorizes_owner_and_shared_permissions(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $reader = User::factory()->create();
        $writer = User::factory()->create();
        $admin = User::factory()->create();

        $file = File::create([
            'name' => 'contract.pdf',
            'path' => 'files/contract.pdf',
            'mime_type' => 'application/pdf',
            'size' => 2048,
            'user_id' => $owner->id,
        ]);

        FileShare::create([
            'file_id' => $file->id,
            'user_id' => $reader->id,
            'permission' => 'read',
        ]);
        FileShare::create([
            'file_id' => $file->id,
            'user_id' => $writer->id,
            'permission' => 'write',
        ]);
        FileShare::create([
            'file_id' => $file->id,
            'user_id' => $admin->id,
            'permission' => 'admin',
        ]);

        $policy = new FilePolicy();

        $this->assertTrue($policy->view($owner, $file));
        $this->assertTrue($policy->update($owner, $file));
        $this->assertTrue($policy->delete($owner, $file));

        $this->assertFalse($policy->view($stranger, $file));
        $this->assertFalse($policy->update($stranger, $file));
        $this->assertFalse($policy->delete($stranger, $file));

        $this->assertTrue($policy->view($reader, $file));
        $this->assertFalse($policy->update($reader, $file));
        $this->assertFalse($policy->delete($reader, $file));

        $this->assertTrue($policy->view($writer, $file));
        $this->assertTrue($policy->update($writer, $file));
        $this->assertFalse($policy->delete($writer, $file));

        $this->assertTrue($policy->view($admin, $file));
        $this->assertTrue($policy->update($admin, $file));
        $this->assertTrue($policy->delete($admin, $file));
    }
}

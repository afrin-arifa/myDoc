<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserPhotoStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(UserPhotoStorage::DISK);
    }

    public function test_an_admin_can_view_and_update_their_own_profile(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.profile'))->assertOk()->assertSee($admin->email)->assertSee('Change password');

        $this->put(route('admin.profile.update'), ['name' => 'New Name', 'email' => 'new@example.com', 'phone' => '01712345678', 'address' => 'Dhaka'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Your profile has been updated.');

        $admin->refresh();
        $this->assertSame(['New Name', 'new@example.com', '8801712345678', 'Dhaka'], [$admin->name, $admin->email, $admin->phone, $admin->address]);
    }

    public function test_role_status_and_other_accounts_cannot_be_changed_and_emails_stay_unique(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        $this->actingAs($admin)->put(route('admin.profile.update'), ['id' => $other->id, 'name' => 'Mine', 'email' => $admin->email, 'role' => 'patient', 'deactivated_at' => now()->toDateTimeString(), 'photo_path' => 'x'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Mine', 'admin', null, null], [$admin->fresh()->name, $admin->fresh()->role, $admin->fresh()->deactivated_at, $admin->fresh()->photo_path]);
        $this->assertNotSame('Mine', $other->fresh()->name);

        $this->put(route('admin.profile.update'), ['name' => 'Mine', 'email' => $other->email])->assertSessionHasErrors('email');
    }

    public function test_only_admins_reach_the_page_and_see_admin_photos(): void
    {
        $admin = $this->admin();
        $png = base64_decode('iVBORw0KGgo=');
        $this->actingAs($admin)->put(route('admin.profile.update'), ['name' => $admin->name, 'email' => $admin->email, 'photo' => UploadedFile::fake()->createWithContent('x.png', $png)])
            ->assertSessionHasErrors('photo');

        $path = UserPhotoStorage::DIRECTORY.'/photo.png';
        Storage::disk(UserPhotoStorage::DISK)->put($path, 'image');
        $admin->forceFill(['photo_path' => $path])->save();
        $this->get(route('users.photo', $admin))->assertOk();
        $this->actingAs($this->admin())->get(route('users.photo', $admin))->assertOk();

        foreach (['patient', 'doctor', User::ROLE_ASSISTANT] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('admin.profile'))->assertForbidden();
            $this->put(route('admin.profile.update'), ['name' => 'x', 'email' => 'x@example.com'])->assertForbidden();
            $this->get(route('users.photo', $admin))->assertNotFound();
        }
    }

    public function test_admins_can_reach_add_assistant_from_their_profile_dashboard_and_assistant_list(): void
    {
        $assistant = User::factory()->create(['role' => User::ROLE_ASSISTANT, 'name' => 'Amina Helper']);
        $this->actingAs($this->admin());

        foreach (['admin.profile', 'admin.dashboard', 'admin.assistants.index'] as $page) {
            $this->get(route($page))->assertOk()->assertSee('Add assistant')->assertSee(route('admin.assistants.create'));
        }
        $this->get(route('admin.profile'))->assertSee('Amina Helper')->assertSee(route('admin.assistants.show', $assistant));
        $this->get(route('admin.assistants.create'))->assertOk();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }
}

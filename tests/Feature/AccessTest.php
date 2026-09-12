<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_гостя_не_пускают_в_чат(): void
    {
        $this->get('/chat')->assertRedirect('/login');
    }

    public function test_обычного_сотрудника_не_пускают_в_админку(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_администратор_попадает_в_админку(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_отключённого_сотрудника_выкидывает_на_вход(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)->get('/chat')->assertRedirect(route('login'));
    }

    public function test_чужой_чат_недоступен(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $thread = $owner->threads()->create(['title' => 'Приватный']);

        $this->actingAs($stranger)->get(route('chat.show', $thread))->assertForbidden();
    }
}

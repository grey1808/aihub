<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_администратор_создаёт_пользователя(): void
    {
        $this->actingAs($this->admin())->post('/admin/users', [
            'name'                  => 'Смирнова Ольга',
            'position'              => 'Бухгалтер',
            'department'            => 'Бухгалтерия',
            'email'                 => 'smirnova@company.ru',
            'login'                 => 'smirnova',
            'password'              => 'parol12345',
            'password_confirmation' => 'parol12345',
        ])->assertRedirect(route('admin.users.index'));

        $user = User::where('login', 'smirnova')->first();

        $this->assertNotNull($user);
        $this->assertSame('Бухгалтер', $user->position);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->is_admin);
    }

    public function test_созданный_пользователь_может_войти_по_логину(): void
    {
        $this->actingAs($this->admin())->post('/admin/users', [
            'name'                  => 'Иванов',
            'email'                 => 'ivanov@company.ru',
            'login'                 => 'ivanov',
            'password'              => 'parol12345',
            'password_confirmation' => 'parol12345',
        ]);

        // Выходим из-под администратора: иначе форма входа для уже
        // авторизованного просто отправит его на главную.
        auth()->logout();
        $this->flushSession();

        $this->post('/login', ['email' => 'ivanov', 'password' => 'parol12345'])
            ->assertRedirect(route('chat.index'));

        $this->assertAuthenticated();
    }

    public function test_администратор_меняет_пароль_сотруднику(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)->post("/admin/users/{$user->id}/password", [
            'password'              => 'novyparol123',
            'password_confirmation' => 'novyparol123',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('novyparol123', $user->fresh()->password));
    }

    public function test_последнего_администратора_нельзя_разжаловать(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->patch("/admin/users/{$admin->id}", [
            'name'      => $admin->name,
            'email'     => $admin->email,
            'is_admin'  => 0,
            'is_active' => 1,
        ])->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_последнего_администратора_нельзя_отключить(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->patch("/admin/users/{$admin->id}", [
            'name'      => $admin->name,
            'email'     => $admin->email,
            'is_admin'  => 1,
            'is_active' => 0,
        ])->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_второго_администратора_разжаловать_можно(): void
    {
        $first = $this->admin();
        $second = User::factory()->create(['is_admin' => true]);

        $this->actingAs($first)->patch("/admin/users/{$second->id}", [
            'name'      => $second->name,
            'email'     => $second->email,
            'is_admin'  => 0,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertFalse($second->fresh()->is_admin);
    }

    public function test_нельзя_удалить_самого_себя(): void
    {
        $admin = $this->admin();
        User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->delete("/admin/users/{$admin->id}")->assertSessionHas('error');

        $this->assertNotNull($admin->fresh());
    }

    public function test_удаление_пользователя_уносит_его_чаты(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $thread = $user->threads()->create(['title' => 'Чат']);

        $this->actingAs($admin)->delete("/admin/users/{$user->id}")
            ->assertRedirect(route('admin.users.index'));

        $this->assertNull($user->fresh());
        $this->assertDatabaseMissing('chat_threads', ['id' => $thread->id]);
    }

    public function test_отключённый_сотрудник_не_может_войти(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->patch("/admin/users/{$user->id}", [
            'name'      => $user->name,
            'email'     => $user->email,
            'is_active' => 0,
        ]);

        $this->actingAs($user->fresh())->get('/chat')->assertRedirect(route('login'));
    }

    public function test_сотрудник_в_админку_пользователей_не_попадает(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/admin/users')->assertForbidden();
        $this->actingAs($user)->get('/admin/users/create')->assertForbidden();
    }

    public function test_на_странице_входа_есть_ссылка_на_регистрацию(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Зарегистрироваться')
            ->assertSee(route('register'));
    }
}

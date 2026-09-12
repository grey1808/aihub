<?php

namespace Tests\Feature;

use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatTrashTest extends TestCase
{
    use RefreshDatabase;

    public function test_удалённый_чат_уезжает_в_корзину_а_не_исчезает(): void
    {
        $user = User::factory()->create();
        $thread = $user->threads()->create(['title' => 'Важная переписка']);

        $this->actingAs($user)->delete(route('chat.destroy', $thread))->assertRedirect();

        $this->assertNull(ChatThread::find($thread->id));
        $this->assertNotNull(ChatThread::withTrashed()->find($thread->id));
    }

    public function test_чат_возвращается_из_корзины(): void
    {
        $user = User::factory()->create();
        $thread = $user->threads()->create(['title' => 'Важная переписка']);
        $thread->messages()->create(['role' => 'user', 'content' => 'вопрос']);

        $this->actingAs($user)->delete(route('chat.destroy', $thread));
        $this->actingAs($user)->post(route('chat.restore', $thread->id))->assertRedirect();

        $restored = ChatThread::find($thread->id);

        $this->assertNotNull($restored);
        $this->assertSame(1, $restored->messages()->count());
    }

    public function test_окончательное_удаление_убирает_чат_совсем(): void
    {
        $user = User::factory()->create();
        $thread = $user->threads()->create(['title' => 'Переписка']);

        $this->actingAs($user)->delete(route('chat.destroy', $thread));
        $this->actingAs($user)->delete(route('chat.force-destroy', $thread->id))->assertRedirect();

        $this->assertNull(ChatThread::withTrashed()->find($thread->id));
    }

    public function test_чужой_чат_из_корзины_не_достать(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $thread = $owner->threads()->create(['title' => 'Личное']);

        $this->actingAs($owner)->delete(route('chat.destroy', $thread));

        $this->actingAs($stranger)->post(route('chat.restore', $thread->id))->assertNotFound();
        $this->actingAs($stranger)->delete(route('chat.force-destroy', $thread->id))->assertNotFound();
    }

    public function test_удалённый_чат_не_показывается_в_списке(): void
    {
        $user = User::factory()->create();
        $keep = $user->threads()->create(['title' => 'Оставляем']);
        $drop = $user->threads()->create(['title' => 'Удаляем']);

        $this->actingAs($user)->delete(route('chat.destroy', $drop));

        $this->actingAs($user)->get(route('chat.show', $keep))
            ->assertOk()
            ->assertSee('Оставляем')
            ->assertDontSee('Удаляем');
    }
}

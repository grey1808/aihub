<?php

namespace Tests\Feature;

use App\Models\ChatAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');

        // К настоящей нейросети в тестах не ходим.
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Готовый ответ помощника.']]],
            ]),
            '*/audio/transcriptions' => Http::response('Голосовой вопрос про отпуск', 200),
        ]);
    }

    public function test_сообщение_с_текстом_доходит_до_помощника(): void
    {
        $user = User::factory()->create();
        $thread = $user->threads()->create(['title' => 'Новый чат']);

        $this->actingAs($user)
            ->postJson("/chat/{$thread->id}/send", ['message' => 'Когда отпуск?'])
            ->assertOk()
            ->assertJsonPath('content', 'Готовый ответ помощника.');

        $this->assertSame(2, $thread->messages()->count());
    }

    public function test_сообщение_без_текста_но_с_голосовым_принимается(): void
    {
        $user = User::factory()->create();
        $thread = $user->threads()->create(['title' => 'Новый чат']);

        $this->actingAs($user)->post('/attachments', [
            'file'      => UploadedFile::fake()->create('golos.ogg', 20, 'audio/ogg'),
            'thread_id' => $thread->id,
        ]);

        $attachment = ChatAttachment::first();

        $this->actingAs($user)
            ->postJson("/chat/{$thread->id}/send", ['message' => '', 'attachments' => [$attachment->id]])
            ->assertOk()
            // Чат без текста называем по типу вложения, иначе в списке
            // слева будет десяток безымянных «Новый чат».
            ->assertJsonPath('thread_title', 'Голосовое сообщение');

        $this->assertSame($thread->messages()->where('role', 'user')->first()->id,
            $attachment->fresh()->chat_message_id);
    }

    public function test_пустое_сообщение_без_вложений_отклоняется(): void
    {
        $user = User::factory()->create();
        $thread = $user->threads()->create(['title' => 'Новый чат']);

        $this->actingAs($user)
            ->postJson("/chat/{$thread->id}/send", ['message' => '   '])
            ->assertStatus(422);

        $this->assertSame(0, $thread->messages()->count());
    }

    public function test_чужое_вложение_не_прицепится_к_своему_сообщению(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($owner)->post('/attachments', [
            'file' => UploadedFile::fake()->createWithContent('secret.txt', 'Зарплатная ведомость'),
        ]);

        $foreign = ChatAttachment::first();
        $thread = $stranger->threads()->create(['title' => 'Новый чат']);

        // Чужой файл просто не подхватывается, и сообщение остаётся пустым.
        $this->actingAs($stranger)
            ->postJson("/chat/{$thread->id}/send", ['message' => '', 'attachments' => [$foreign->id]])
            ->assertStatus(422);

        $this->assertNull($foreign->fresh()->chat_message_id);
    }

    public function test_картинка_уходит_модели_со_зрением_отдельной_моделью(): void
    {
        config(['llm.vision_model' => 'qwen2.5vl:3b']);

        $user = User::factory()->create();
        $thread = $user->threads()->create(['title' => 'Новый чат']);

        $this->actingAs($user)->post('/attachments', [
            'file' => UploadedFile::fake()->image('scan.png', 10, 10),
        ]);

        $this->actingAs($user)->postJson("/chat/{$thread->id}/send", [
            'message'     => 'Что тут написано?',
            'attachments' => [ChatAttachment::first()->id],
        ])->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return false;
            }

            $data = $request->data();
            $last = end($data['messages']);

            // Картинку отправляем модели со зрением, отдельной от основной,
            // и в формате частей: текст + image_url с data-url.
            return $data['model'] === 'qwen2.5vl:3b'
                && is_array($last['content'])
                && collect($last['content'])->contains(fn ($part) => ($part['type'] ?? '') === 'image_url'
                    && str_starts_with($part['image_url']['url'] ?? '', 'data:image/'));
        });
    }

    public function test_без_модели_со_зрением_помощник_честно_говорит_что_не_видит(): void
    {
        config(['llm.vision_model' => '']);

        $user = User::factory()->create();
        $thread = $user->threads()->create(['title' => 'Новый чат']);

        $this->actingAs($user)->post('/attachments', [
            'file' => UploadedFile::fake()->image('scan.png'),
        ]);

        $response = $this->actingAs($user)->postJson("/chat/{$thread->id}/send", [
            'message'     => 'Что тут написано?',
            'attachments' => [ChatAttachment::first()->id],
        ])->assertOk();

        $this->assertStringContainsString('посмотреть не могу', $response->json('content'));
        Http::assertNothingSent();
    }

    public function test_текст_приложенного_документа_уходит_в_запрос_к_модели(): void
    {
        $user = User::factory()->create();
        $thread = $user->threads()->create(['title' => 'Новый чат']);

        $this->actingAs($user)->post('/attachments', [
            'file' => UploadedFile::fake()->createWithContent('dogovor.txt', 'Срок оплаты — 10 банковских дней.'),
        ]);

        $this->actingAs($user)->postJson("/chat/{$thread->id}/send", [
            'message'     => 'Какой срок оплаты?',
            'attachments' => [ChatAttachment::first()->id],
        ])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'chat/completions')
                && str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), '10 банковских дней');
        });
    }
}

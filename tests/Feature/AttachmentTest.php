<?php

namespace Tests\Feature;

use App\Models\ChatAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
    }

    public function test_документ_загружается_и_из_него_достаётся_текст(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/attachments', [
            'file' => UploadedFile::fake()->createWithContent('reglament.txt', 'Срок оплаты — 10 дней.'),
        ]);

        $response->assertCreated()->assertJson(['kind' => 'document', 'status' => 'ready']);

        $attachment = ChatAttachment::first();

        $this->assertStringContainsString('10 дней', $attachment->extracted_text);
        Storage::disk('attachments')->assertExists($attachment->path);
    }

    public function test_картинка_загружается_но_текст_из_неё_не_достаётся(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/attachments', ['file' => UploadedFile::fake()->image('foto.png')])
            ->assertCreated()
            ->assertJson(['kind' => 'image', 'status' => 'ready']);

        $this->assertNull(ChatAttachment::first()->extracted_text);
    }

    public function test_голосовое_расшифровывается(): void
    {
        // К сервису распознавания в тестах не ходим — подменяем ответ.
        Http::fake(['*/audio/transcriptions' => Http::response('Когда сдавать авансовый отчёт', 200)]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/attachments', ['file' => UploadedFile::fake()->create('golos.ogg', 20, 'audio/ogg')])
            ->assertCreated()
            ->assertJson([
                'kind'       => 'audio',
                'status'     => 'ready',
                'transcript' => 'Когда сдавать авансовый отчёт',
            ]);
    }

    public function test_неподдерживаемый_тип_файла_отклоняется(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/attachments', ['file' => UploadedFile::fake()->create('virus.exe', 10)])
            ->assertStatus(422);

        $this->assertSame(0, ChatAttachment::count());
    }

    public function test_слишком_большой_файл_отклоняется(): void
    {
        $user = User::factory()->create();

        $megabytes = (int) (config('attachments.max_size') / 1024) + 1024;

        $this->actingAs($user)
            ->post('/attachments', ['file' => UploadedFile::fake()->create('big.pdf', $megabytes)])
            ->assertStatus(302) // обычная валидация формы
            ->assertSessionHasErrors('file');
    }

    public function test_чужое_вложение_не_отдаётся(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($owner)->post('/attachments', [
            'file' => UploadedFile::fake()->createWithContent('secret.txt', 'Зарплатная ведомость'),
        ]);

        $attachment = ChatAttachment::first();

        $this->actingAs($stranger)->get("/attachments/{$attachment->id}")->assertForbidden();
        $this->actingAs($owner)->get("/attachments/{$attachment->id}")->assertOk();
    }

    public function test_отправленное_вложение_удалить_нельзя(): void
    {
        $user = User::factory()->create();
        $thread = $user->threads()->create(['title' => 'Чат']);
        $message = $thread->messages()->create(['role' => 'user', 'content' => 'вопрос']);

        $this->actingAs($user)->post('/attachments', [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'текст'),
        ]);

        $attachment = ChatAttachment::first();

        $this->actingAs($user)->delete("/attachments/{$attachment->id}")->assertNoContent();
        $this->assertSame(0, ChatAttachment::count());

        $this->actingAs($user)->post('/attachments', [
            'file' => UploadedFile::fake()->createWithContent('b.txt', 'текст'),
        ]);

        $sent = ChatAttachment::first();
        $sent->update(['chat_message_id' => $message->id]);

        $this->actingAs($user)->delete("/attachments/{$sent->id}")->assertForbidden();
        $this->assertSame(1, ChatAttachment::count());
    }

    public function test_удаление_вложения_стирает_файл_с_диска(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/attachments', [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'текст'),
        ]);

        $attachment = ChatAttachment::first();
        $path = $attachment->path;

        $attachment->delete();

        Storage::disk('attachments')->assertMissing($path);
    }
}

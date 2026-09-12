<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'preferred_name',
        'position',
        'department',
        'email',
        'login',
        'password',
        'is_admin',
        'is_active',
        'memory',
        'memory_updated_at',
    ];

    /**
     * Значения по умолчанию для новой записи.
     *
     * Без этого только что зарегистрированный сотрудник получает is_active = null
     * в объекте (в базе-то default сработает, но объект о нём не знает) —
     * и middleware выкидывает его из системы сразу после регистрации.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_admin'  => false,
        'is_active' => true,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'memory_updated_at' => 'datetime',
        ];
    }

    public function threads(): HasMany
    {
        return $this->hasMany(ChatThread::class)->latest('last_message_at');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class)->orderBy('name');
    }

    /** Как помощник обращается к человеку. */
    public function callName(): string
    {
        return trim($this->preferred_name) !== '' ? $this->preferred_name : $this->name;
    }

    /** Должность и отдел одной строкой — для промпта и для админки. */
    public function role(): string
    {
        return collect([$this->position, $this->department])
            ->filter(fn ($part) => trim((string) $part) !== '')
            ->implode(', ');
    }

    public function rememberNote(string $text): void
    {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        $date = now()->format('d.m.Y');
        $memory = trim((string) $this->memory);

        // Дописываем в конец с датой: помощнику полезно понимать,
        // насколько заметка свежая, а человеку — что откуда взялось.
        $this->update([
            'memory'            => trim($memory."\n- ".$text." _(записано {$date})_"),
            'memory_updated_at' => now(),
        ]);
    }
}

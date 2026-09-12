<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index()
    {
        $users = User::query()->orderBy('id')->get();

        // Счётчики по всем пользователям одним запросом, а не по одному
        // на строку таблицы.
        $messages = ChatMessage::query()
            ->join('chat_threads', 'chat_threads.id', '=', 'chat_messages.chat_thread_id')
            ->where('chat_messages.role', 'user')
            ->groupBy('chat_threads.user_id')
            ->selectRaw('chat_threads.user_id, count(*) as total')
            ->pluck('total', 'user_id');

        $files = ChatAttachment::query()
            ->whereNotNull('chat_message_id')
            ->groupBy('user_id')
            ->selectRaw('user_id, count(*) as total')
            ->pluck('total', 'user_id');

        $lastSeen = DB::table('chat_threads')
            ->groupBy('user_id')
            ->selectRaw('user_id, max(last_message_at) as seen')
            ->pluck('seen', 'user_id');

        return view('admin.users.index', [
            'users'    => $users,
            'messages' => $messages,
            'files'    => $files,
            'lastSeen' => $lastSeen,
            'admins'   => $users->where('is_admin', true)->count(),
        ]);
    }

    public function create()
    {
        return view('admin.users.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(), [], $this->attributes());

        User::create([
            'name'           => $data['name'],
            'preferred_name' => $data['preferred_name'] ?? null,
            'position'       => $data['position'] ?? null,
            'department'     => $data['department'] ?? null,
            'email'          => $data['email'],
            'login'          => ($data['login'] ?? null) ?: null,
            'password'       => Hash::make($data['password']),
            'is_admin'       => (bool) ($data['is_admin'] ?? false),
            'is_active'      => true,
        ]);

        return redirect()->route('admin.users.index')
            ->with('status', 'Пользователь создан. Передайте ему логин и пароль.');
    }

    public function edit(User $user)
    {
        return view('admin.users.edit', [
            'user'      => $user,
            'threads'   => $user->threads()->count(),
            'lastAdmin' => $user->is_admin && User::where('is_admin', true)->count() <= 1,
        ]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate(
            $this->rules($user) + ['is_active' => ['nullable', 'boolean']],
            [],
            $this->attributes()
        );

        // Последнего администратора нельзя ни разжаловать, ни отключить:
        // иначе в админку больше никто не войдёт и чинить придётся консолью.
        if ($this->wouldLoseLastAdmin($user, (bool) ($data['is_admin'] ?? false), (bool) ($data['is_active'] ?? false))) {
            return back()->with('error', 'Это единственный администратор. Сначала назначьте другого.');
        }

        $user->update([
            'name'           => $data['name'],
            'preferred_name' => $data['preferred_name'] ?? null,
            'position'       => $data['position'] ?? null,
            'department'     => $data['department'] ?? null,
            'email'          => $data['email'],
            'login'          => ($data['login'] ?? null) ?: null,
            'is_admin'       => (bool) ($data['is_admin'] ?? false),
            'is_active'      => (bool) ($data['is_active'] ?? false),
        ]);

        return back()->with('status', 'Сохранено.');
    }

    /** Сброс пароля: сотрудник забыл свой, почты для восстановления нет. */
    public function password(Request $request, User $user)
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [], ['password' => 'пароль']);

        $user->update(['password' => Hash::make($data['password'])]);

        return back()->with('status', 'Пароль изменён. Передайте новый пароль сотруднику.');
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Нельзя удалить самого себя.');
        }

        if ($user->is_admin && User::where('is_admin', true)->count() <= 1) {
            return back()->with('error', 'Это единственный администратор. Сначала назначьте другого.');
        }

        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('status', 'Пользователь удалён вместе со своими чатами и файлами.');
    }

    private function wouldLoseLastAdmin(User $user, bool $isAdmin, bool $isActive): bool
    {
        if (! $user->is_admin) {
            return false;
        }

        $stays = $isAdmin && $isActive;

        return ! $stays && User::where('is_admin', true)->where('is_active', true)->count() <= 1;
    }

    private function rules(?User $user = null): array
    {
        return [
            'name'           => ['required', 'string', 'max:255'],
            'preferred_name' => ['nullable', 'string', 'max:120'],
            'position'       => ['nullable', 'string', 'max:160'],
            'department'     => ['nullable', 'string', 'max:160'],
            'email'          => ['required', 'string', 'email', 'max:255',
                                 Rule::unique(User::class)->ignore($user?->id)],
            'login'          => ['nullable', 'string', 'max:60', 'alpha_dash',
                                 Rule::unique(User::class)->ignore($user?->id)],
            'is_admin'       => ['nullable', 'boolean'],
        ] + ($user ? [] : ['password' => ['required', 'confirmed', Password::min(8)]]);
    }

    private function attributes(): array
    {
        return [
            'name'           => 'имя',
            'preferred_name' => 'обращение',
            'position'       => 'должность',
            'department'     => 'отдел',
            'email'          => 'почта',
            'login'          => 'логин',
            'password'       => 'пароль',
        ];
    }
}

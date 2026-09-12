<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Личная память помощника: правка и очистка руками.
     *
     * Помощник дополняет её сам по просьбе «запомни», но человек должен
     * видеть, что про него записано, и иметь возможность это поправить.
     */
    public function updateMemory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'memory' => ['nullable', 'string', 'max:20000'],
        ], [], ['memory' => 'заметки']);

        $request->user()->update([
            'memory'            => trim((string) ($data['memory'] ?? '')) ?: null,
            'memory_updated_at' => now(),
        ]);

        return Redirect::route('profile.edit')->with('status', 'memory-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}

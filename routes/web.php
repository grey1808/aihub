<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\KnowledgeSourceController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

// Главная сразу ведёт в чат: сотрудник включил моноблок, открыл браузер —
// и он уже там, где нужно. Лишних страниц на пути быть не должно.
Route::redirect('/', '/chat');

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('/chat', [ChatController::class, 'store'])->name('chat.store');
    Route::get('/chat/{thread}', [ChatController::class, 'show'])->name('chat.show');
    Route::post('/chat/{thread}/send', [ChatController::class, 'send'])->name('chat.send');
    Route::post('/chat/{thread}/stream', [ChatController::class, 'stream'])->name('chat.stream');
    Route::patch('/chat/{thread}', [ChatController::class, 'rename'])->name('chat.rename');
    Route::delete('/chat/{thread}', [ChatController::class, 'destroy'])->name('chat.destroy');
    Route::patch('/chat/{thread}/move', [ChatController::class, 'move'])->name('chat.move');

    Route::get('/trash', [ChatController::class, 'trash'])->name('chat.trash');
    Route::post('/trash/{thread}/restore', [ChatController::class, 'restore'])->name('chat.restore');
    Route::delete('/trash/{thread}', [ChatController::class, 'forceDestroy'])->name('chat.force-destroy');

    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::post('/projects/{project}/chats', [ProjectController::class, 'attach'])->name('projects.attach');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/memory', [ProfileController::class, 'updateMemory'])->name('profile.memory');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'active', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/sources', [KnowledgeSourceController::class, 'index'])->name('sources.index');
    Route::get('/sources/create', [KnowledgeSourceController::class, 'create'])->name('sources.create');
    Route::post('/sources', [KnowledgeSourceController::class, 'store'])->name('sources.store');
    Route::get('/sources/{source}', [KnowledgeSourceController::class, 'edit'])->name('sources.edit');
    Route::patch('/sources/{source}', [KnowledgeSourceController::class, 'update'])->name('sources.update');
    Route::delete('/sources/{source}', [KnowledgeSourceController::class, 'destroy'])->name('sources.destroy');
    Route::post('/sources/{source}/test', [KnowledgeSourceController::class, 'test'])->name('sources.test');
    Route::post('/sources/{source}/sync', [KnowledgeSourceController::class, 'sync'])->name('sources.sync');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}', [UserController::class, 'edit'])->name('users.edit');
    Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/password', [UserController::class, 'password'])->name('users.password');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});

require __DIR__.'/auth.php';

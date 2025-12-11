<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FriendController;

use App\Http\Controllers\ChatController;
use App\Http\Controllers\UserKeyController;


Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';


Route::middleware('auth')->group(function () {
    Route::get('/friends', [FriendController::class, 'index'])->name('friends.index');
    Route::post('/friends/send', [FriendController::class, 'send'])->name('friends.send');
    Route::post('/friends/cancel', [FriendController::class, 'cancel'])->name('friends.cancel');
    Route::post('/friends/accept', [FriendController::class, 'accept'])->name('friends.accept');
    Route::post('/friends/reject', [FriendController::class, 'reject'])->name('friends.reject');
    Route::get('/friends/list', [FriendController::class, 'friendsList'])->name('friends.list');
});


Route::get('/friends', [FriendController::class, 'index'])->name('friends.index');


Route::middleware(['auth'])->group(function () {
    Route::get('/chat/{user}', [ChatController::class, 'show'])->name('chat.show');
    Route::get('/chat/{user}/messages', [ChatController::class, 'messages'])->name('chat.messages');
    Route::post('/chat/send', [ChatController::class, 'send'])->name('chat.send');

    // Save device public key (client sends exported public key)
    Route::get('/user/public-key', [UserKeyController::class, 'show'])->name('user.publickey.get');
    Route::post('/user/public-key', [UserKeyController::class, 'store'])->name('user.publickey');
});



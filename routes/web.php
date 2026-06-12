<?php

use App\Livewire\Auth\StudentRegister;
use App\Livewire\Auth\TeacherRegister;
use App\Livewire\Settings\Password;
use App\Livewire\Settings\Profile;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome-tdr');
});

Route::middleware('guest')->group(function () {
    Route::get('/tdr/register', TeacherRegister::class)->name('tdr.register');
    Route::get('/sfdi/register', StudentRegister::class)->name('sfdi.register');
});

Route::middleware('auth')->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::get('/settings/profile', Profile::class)->name('settings.profile');
    Route::get('/settings/password', Password::class)->name('settings.password');
});

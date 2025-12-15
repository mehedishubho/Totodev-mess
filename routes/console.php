<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Register The Commands
|--------------------------------------------------------------------------
|
|
| This file is where you may register all of the console commands for your
| application. The commands are registered in the console kernel's "commands" method.
|
*/

Artisan::command('inspire', function () {
    $this->comment('Inspiring quote from Laravel documentation!');
});

Artisan::command('migrate:fresh --seed', function () {
    $this->comment('Fresh migration with seeding');
});

Artisan::command('db:seed', function () {
    $this->comment('Database seeding');
});

Artisan::command('cache:clear', function () {
    $this->comment('Cache cleared');
});

Artisan::command('config:clear', function () {
    $this->comment('Configuration cache cleared');
});

Artisan::command('route:clear', function () {
    $this->comment('Route cache cleared');
});

Artisan::command('view:clear', function () {
    $this->comment('View cache cleared');
});

Artisan::command('storage:link', function () {
    $this->comment('Storage links created');
});

Artisan::command('optimize:clear', function () {
    $this->comment('Optimization cache cleared');
});

<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('about:digital-goods-source', function () {
    $this->info(config('digital-goods-source.service').' '.config('digital-goods-source.runtime_version'));
})->purpose('Display Digital Goods Source runtime information');

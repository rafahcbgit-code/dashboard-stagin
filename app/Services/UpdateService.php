<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class UpdateService
{
    public function migrate(Request $request, $version){
        Artisan::call('db:seed');

        return true;
    }
}
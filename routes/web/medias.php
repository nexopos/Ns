<?php

use Ns\Http\Controllers\Dashboard\MediasController;
use Illuminate\Support\Facades\Route;

Route::get( '/medias', [ MediasController::class, 'showMedia' ] )->name( nsRouteName( 'ns.dashboard.medias' ) );

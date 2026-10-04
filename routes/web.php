<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/billing/return', function (Request $request) {
    $status = (string) $request->query('billing', 'success');

    if (! in_array($status, ['success', 'cancel', 'canceled'], true)) {
        $status = 'success';
    }

    return response()->view('billing.return', [
        'target' => 'plnr:///(tabs)/account?billing='.rawurlencode($status),
    ]);
});

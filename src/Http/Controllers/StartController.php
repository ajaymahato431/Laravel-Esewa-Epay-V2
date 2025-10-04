<?php

namespace AjayMahato\Esewa\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use AjayMahato\Esewa\Facades\Esewa;

class StartController extends Controller
{
    public function start(Request $request)
    {
        // Optionally validate fields here
        return Esewa::pay($request->all()); // returns auto-submit response
    }
}

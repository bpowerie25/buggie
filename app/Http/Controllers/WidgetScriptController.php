<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the widget bundle at /w/{public_key}.js.
 *
 * The key lives in the URL rather than in a config call so the install is a single
 * script tag with nothing to get wrong. The bundle reads its own script src to find
 * the key, which means every key serves the identical, cacheable file.
 */
class WidgetScriptController extends Controller
{
    public function __invoke(Request $request, string $key): Response
    {
        $path = public_path('widget/buggy.js');

        abort_unless(is_file($path), 404, 'Widget bundle not built. Run npm run build:widget.');

        return response(file_get_contents($path), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // Embedded cross-origin in customers' apps.
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}

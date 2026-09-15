<?php

namespace Toggley\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Toggley\Laravel\Toggley;

/**
 * Route middleware: ->middleware('toggley:new-checkout') returns 404 unless the flag is on.
 */
class EnsureFlagEnabled
{
    public function __construct(private readonly Toggley $toggley)
    {
    }

    public function handle(Request $request, Closure $next, string $flag)
    {
        abort_unless($this->toggley->isEnabled($flag), 404);

        return $next($request);
    }
}

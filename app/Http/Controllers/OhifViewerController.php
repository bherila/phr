<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the entrypoint of the static OHIF Viewer bundle that lives at
 * public/ohif/.
 *
 * The bundle is a ~200 MB build of OHIF/Viewers that is deliberately NOT in
 * git and NOT produced by this app's Vite build — it is uploaded to the server
 * out of band and the deploy rsync excludes `ohif` so app deploys don't delete
 * it. Because of that, `public_path('ohif/index.html')` is absent in a fresh
 * checkout and every route here 404s until the bundle is present.
 *
 * OHIF is a client-routed SPA, so any deep link under /ohif/viewer/... has to
 * return the same index.html. Only that prefix is rewritten: unresolved asset
 * paths must keep 404ing rather than silently returning HTML, which would
 * otherwise surface as an opaque "unexpected token <" parse error.
 *
 * In production Apache serves anything under public/ohif/ that exists on disk
 * before Laravel is reached, so this controller only ever handles the SPA
 * entrypoint and its client-routed deep links.
 */
class OhifViewerController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $indexPath = public_path('ohif/index.html');

        abort_unless(is_file($indexPath), 404);

        return response()->file($indexPath);
    }
}

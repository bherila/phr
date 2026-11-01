<?php

namespace App\Services\PHR\Preload;

use App\Http\Controllers\Api\UserAiConfigurationController;
use App\Http\Controllers\Api\UserAiExecutionModeController;
use App\Http\Controllers\PHR\AllergyController;
use App\Http\Controllers\PHR\ConditionController;
use App\Http\Controllers\PHR\DICOM\DicomStudyController;
use App\Http\Controllers\PHR\HealthLogController;
use App\Http\Controllers\PHR\HealthLogEntryController;
use App\Http\Controllers\PHR\ImmunizationController;
use App\Http\Controllers\PHR\LabResultController;
use App\Http\Controllers\PHR\MedicationController;
use App\Http\Controllers\PHR\MedicationFillController;
use App\Http\Controllers\PHR\OfficeVisitController;
use App\Http\Controllers\PHR\PatientController;
use App\Http\Controllers\PHR\PhrDataHubController;
use App\Http\Controllers\PHR\PhrDocumentController;
use App\Http\Controllers\PHR\PhrExportController;
use App\Http\Controllers\PHR\PhrNativeRestoreController;
use App\Http\Controllers\PHR\PhrPatientDeletionController;
use App\Http\Controllers\PHR\PhrReviewController;
use App\Http\Controllers\PHR\ProcedureController;
use App\Http\Controllers\PHR\VitalController;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Js;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Builds the PHR shell page's first-load preload: for each URL of a PhrPreloadPlan, the exact
 * JSON body that GET returns for the signed-in person, keyed by that URL.
 *
 * There is no second serialization path. Each URL is matched against the application's routes
 * and its own controller action runs, on a sub-request that carries the page request's user and
 * session, so the action's own authorization (the patient access check, the record's patient
 * scope, owner-only exports) decides what is inlined. Only a 200 JSON response is kept; a
 * refusal or a miss leaves that URL to the browser, which then gets the same answer itself.
 *
 * The sub-request skips the route middleware, so a route is dispatched only when every
 * middleware it declares is one the page request has already passed for the same person (the
 * session `web` group and `auth`), or throttling, which a request that is never sent does not
 * spend. Route-model bindings are substituted as SubstituteBindings would.
 */
final class PhrPreloadBuilder
{
    /** Upper bound on the encoded map; an entry that would cross it is left to the network. */
    public const int MAX_BYTES = 256 * 1024;

    /** Safe inside <script>: no "<", ">", "&", "'" or '"' survives unescaped in a string. */
    public const int ENCODE_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** The only controller actions whose responses may be inlined (all are GET reads). */
    private const array ACTIONS = [
        PatientController::class.'@show',
        PhrReviewController::class.'@overview',
        PhrReviewController::class.'@index',
        PhrReviewController::class.'@summary',
        LabResultController::class.'@index',
        LabResultController::class.'@showPanel',
        VitalController::class.'@index',
        VitalController::class.'@show',
        VitalController::class.'@trend',
        OfficeVisitController::class.'@index',
        OfficeVisitController::class.'@show',
        MedicationController::class.'@index',
        MedicationController::class.'@show',
        MedicationFillController::class.'@index',
        ConditionController::class.'@index',
        ConditionController::class.'@show',
        ProcedureController::class.'@index',
        ProcedureController::class.'@show',
        ImmunizationController::class.'@index',
        ImmunizationController::class.'@show',
        AllergyController::class.'@index',
        AllergyController::class.'@show',
        HealthLogController::class.'@index',
        HealthLogEntryController::class.'@index',
        PhrDocumentController::class.'@index',
        PhrDocumentController::class.'@show',
        DicomStudyController::class.'@index',
        DicomStudyController::class.'@show',
        DicomStudyController::class.'@viewerJson',
        PhrExportController::class.'@index',
        PhrDataHubController::class.'@index',
        PhrPatientDeletionController::class.'@index',
        PhrNativeRestoreController::class.'@index',
        UserAiConfigurationController::class.'@index',
        UserAiExecutionModeController::class.'@show',
    ];

    /** Request headers that describe the page request's own body or cookies, not the sub-request. */
    private const array DROPPED_SERVER_KEYS = ['HTTP_COOKIE', 'CONTENT_TYPE', 'CONTENT_LENGTH', 'HTTP_CONTENT_TYPE', 'HTTP_CONTENT_LENGTH'];

    /** @var list<string>|null */
    private ?array $satisfiedMiddleware = null;

    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
    ) {}

    /** The encoded map for the page's <script id="phr-preload">, or null when nothing fits. */
    public function build(Request $page, PhrPreloadPlan $plan): ?string
    {
        $fragments = [];
        $size = 2;
        $queue = $plan->urls;
        $seen = [];
        while (($url = array_shift($queue)) !== null) {
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $content = $this->dispatch($page, $url);
            if ($content === null) {
                continue;
            }
            try {
                $body = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
                $fragment = Js::encode($url, self::ENCODE_FLAGS).':'.Js::encode($body, self::ENCODE_FLAGS);
            } catch (JsonException) {
                continue;
            }
            $added = strlen($fragment) + ($fragments === [] ? 0 : 1);
            if ($size + $added > self::MAX_BYTES) {
                continue;
            }
            $fragments[] = $fragment;
            $size += $added;
            array_push($queue, ...$plan->followUps($url, $body));
        }

        return $fragments === [] ? null : '{'.implode(',', $fragments).'}';
    }

    /** The JSON body of a 200 GET of `url` for the page's user, or null when it is not inlined. */
    private function dispatch(Request $page, string $url): ?string
    {
        if (! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }
        $server = array_diff_key($page->server->all(), array_flip(self::DROPPED_SERVER_KEYS));
        $request = Request::create($url, 'GET', server: $server);
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->setUserResolver($page->getUserResolver());
        if ($page->hasSession()) {
            $request->setLaravelSession($page->session());
        }

        try {
            $route = $this->router->getRoutes()->match($request);
        } catch (HttpExceptionInterface) {
            return null;
        }
        if (! in_array(ltrim($route->getActionName(), '\\'), self::ACTIONS, true) || ! $this->runsOnlySatisfiedMiddleware($route)) {
            return null;
        }

        $previous = $this->container->bound('request') ? $this->container->make('request') : null;
        $route->setContainer($this->container);
        $request->setRouteResolver(fn () => $route);
        $this->container->instance('request', $request);
        try {
            $this->router->substituteBindings($route);
            $this->router->substituteImplicitBindings($route);
            $response = $this->router->prepareResponse($request, $route->run());
        } catch (HttpExceptionInterface|ModelNotFoundException|AuthorizationException|AuthenticationException|ValidationException) {
            return null;
        } catch (Throwable $exception) {
            // The browser will make this request itself and see the same failure; the page still renders.
            report($exception);

            return null;
        } finally {
            if ($previous !== null) {
                $this->container->instance('request', $previous);
            }
        }

        if (! $response instanceof JsonResponse || $response->getStatusCode() !== 200) {
            return null;
        }
        $content = $response->getContent();

        return is_string($content) ? $content : null;
    }

    private function runsOnlySatisfiedMiddleware(Route $route): bool
    {
        $satisfied = $this->satisfiedMiddleware ??= [
            ...$this->router->resolveMiddleware(['web']),
            Authenticate::class,
            SubstituteBindings::class,
        ];
        foreach ($this->router->gatherRouteMiddleware($route) as $middleware) {
            if (! is_string($middleware)) {
                return false;
            }
            $class = explode(':', $middleware, 2)[0];
            $isThrottle = $class === ThrottleRequests::class || is_subclass_of($class, ThrottleRequests::class);
            if (! $isThrottle && ! in_array($middleware, $satisfied, true)) {
                return false;
            }
        }

        return true;
    }
}

<?php

use App\Exceptions\Booking\SeatUnavailableException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\{
    AccessDeniedHttpException,
    HttpExceptionInterface,
    MethodNotAllowedHttpException,
    NotFoundHttpException
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->validateCsrfTokens(except:[
            'api/webhooks/*',
        ]);

        $middleware->alias([
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {

        // ── 401 Unauthenticated ──────────────────────────────────────────────
        // Fired when a user is not logged in (e.g. missing/invalid Sanctum token).
        $exceptions->render(function (AuthenticationException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated. Please log in to continue.',
                'errors'  => null,
            ], 401);
        });

        // ── 403 Spatie Permission/Role check failed ──────────────────────────
        // Fired by 'role', 'permission', 'role_or_permission' middleware when
        // the authenticated user lacks the required role or permission.
        $exceptions->render(function (UnauthorizedException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'You do not have the required permissions to perform this action.',
                'errors'  => [
                    'required' => $exception->getRequiredRoles()
                                    ?: $exception->getRequiredPermissions(),
                ],
            ], 403);
        });

        // ── 403 Laravel Gate/Policy check failed ────────────────────────────
        // Fired by $this->authorize() in controllers or Gate::authorize().
        $exceptions->render(function (AuthorizationException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'You are not authorized to perform this action.',
                'errors'  => null,
            ], 403);
        });

        // ── 403 Laravel Gate/Policy check failed ────────────────────────────
        // Fired by $this->authorize() in controllers.
        $exceptions->render(function (AccessDeniedHttpException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Access denied',
                'errors'  => null,
            ], 403);
        });

        // ── 404 Model not found ──────────────────────────────────────────────
        // Fired by findOrFail() / firstOrFail() when the record doesn't exist.
        $exceptions->render(function (ModelNotFoundException $exception) {
            $model = class_basename($exception->getModel());
            return response()->json([
                'status' => false,
                'message' => "{$model} not found.",
                'errors'  => null,
            ], 404);
        });

        // ── 404 Route not found ──────────────────────────────────────────────
        // Fired when the URL doesn't match any defined route.
        $exceptions->render(function (NotFoundHttpException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'The requested route does not exist.',
                'errors'  => null,
            ], 404);
        });

        // ── 405 Method not allowed ───────────────────────────────────────────
        // Fired when the route exists but not for the used HTTP verb.
        $exceptions->render(function (MethodNotAllowedHttpException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Method not allowed for this route.',
                'errors'  => null,
            ], 405);
        });

        // ── 409 Resource not available ───────────────────────────────────────────
        // Fired when the resource is not available to complete the request.
        $exceptions->render(function (SeatUnavailableException $exception) {
            return response()->json([
                'status' => false,
                'message' => $exception->getMessage(),
                'errors' => null
            ], 409);
        });

        // ── 500 Fallback ─────────────────────────────────────────────────────
        // Catches anything not handled above.
        // In production: hides the real error message for security.
        $exceptions->render(function (\Throwable $exception) {
            if ($exception instanceof ValidationException
                || $exception instanceof HttpResponseException
                || $exception instanceof HttpExceptionInterface) {
                return null;
            }

            $debug = (bool) config('app.debug');

            return response()->json([
                'status' => false,
                'message' => $debug
                    ? 'Something went wrong. Please try again later.'
                    : $exception->getMessage(),
                'errors'  => $debug
                    ? null
                    : [
                        'exception' => get_class($exception),
                        'file'      => $exception->getFile(),
                        'line'      => $exception->getLine(),
                    ],
            ], 500);
        });

    })->create();

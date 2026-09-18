<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\IssueRelationController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SavedViewController;
use App\Http\Controllers\WidgetKeyController;
use App\Http\Controllers\WidgetScriptController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

$host = config('buggy.host');

/*
|--------------------------------------------------------------------------
| Central domain — buggy.app
|--------------------------------------------------------------------------
| Marketing, authentication and the workspace picker. No workspace is bound
| here, so tenant models are unreachable (WorkspaceScope throws in strict mode).
*/

Route::domain($host)->group(function () {
    Route::get('/', HomeController::class)->name('home');

    // The widget bundle, embedded cross-origin in customers' applications.
    // The parameter must be constrained: the default [^/]+ is greedy and swallows
    // the .js suffix, leaving nothing for the literal to match.
    Route::get('w/{key}.js', WidgetScriptController::class)
        ->where('key', '[A-Za-z0-9_]+')
        ->name('widget.script');

    Route::middleware('guest')->group(function () {
        Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
        Route::post('login', [AuthenticatedSessionController::class, 'store']);

        Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
        Route::post('register', [RegisteredUserController::class, 'store']);
    });

    Route::middleware('auth')->group(function () {
        Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
        Route::get('workspaces/create', [WorkspaceController::class, 'create'])->name('workspaces.create');
        Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');

        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    });
});

/*
|--------------------------------------------------------------------------
| Widget harness (local only)
|--------------------------------------------------------------------------
| A deliberately broken checkout page for developing the reporter widget against:
| it throws a real error, logs to the console, makes a failing request, and contains
| a password field and a data-buggy-redact field to prove redaction works.
*/

if (! app()->isProduction()) {
    Route::domain($host)->get('widget-demo', function () {
        $key = \App\Models\WidgetKey::withoutGlobalScopes()->where('is_active', true)->first();

        abort_if($key === null, 404, 'Seed the database first: ./bin/art migrate:fresh --seed');

        return view('widget-demo', [
            // Cache-busted, so widget rebuilds are picked up immediately.
            'snippetUrl' => '/w/'.$key->public_key.'.js?v='.filemtime(public_path('widget/buggy.js')),
        ]);
    })->name('widget.demo');
}

/*
|--------------------------------------------------------------------------
| Workspace domains — {workspace}.buggy.app
|--------------------------------------------------------------------------
| ResolveWorkspace (global) has already bound the tenant by the time these run;
| 'workspace' middleware then requires the signed-in user to be a member.
*/

Route::domain('{workspace}.'.$host)
    ->middleware(['auth', 'workspace'])
    ->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::resource('projects', ProjectController::class);

        // Issue keys are unique per workspace (project keys are), so issues live at
        // the top level: /issues/WEB-142 rather than /projects/web/issues/142.
        // Declared before the resource so /issues/bulk is not read as an issue key.
        Route::patch('issues/bulk', [IssueController::class, 'bulk'])->name('issues.bulk');

        Route::resource('issues', IssueController::class)->except('edit');

        Route::post('issues/{issue}/comments', [CommentController::class, 'store'])
            ->name('comments.store');
        Route::patch('comments/{comment}', [CommentController::class, 'update'])
            ->name('comments.update');
        Route::delete('comments/{comment}', [CommentController::class, 'destroy'])
            ->name('comments.destroy');

        Route::post('issues/{issue}/relations', [IssueRelationController::class, 'store'])
            ->name('relations.store');
        Route::delete('issues/{issue}/relations', [IssueRelationController::class, 'destroy'])
            ->name('relations.destroy');

        Route::resource('labels', LabelController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::get('inbox', [ReportController::class, 'index'])->name('reports.index');
        Route::post('inbox/{report}/accept', [ReportController::class, 'accept'])->name('reports.accept');
        Route::post('inbox/{report}/merge', [ReportController::class, 'merge'])->name('reports.merge');
        Route::post('inbox/{report}/dismiss', [ReportController::class, 'dismiss'])->name('reports.dismiss');
        Route::get('inbox/{report}/screenshot', [ReportController::class, 'screenshot'])
            ->name('reports.screenshot');

        Route::post('projects/{project}/widget-keys', [WidgetKeyController::class, 'store'])
            ->name('widget-keys.store');
        Route::patch('widget-keys/{widgetKey}', [WidgetKeyController::class, 'update'])
            ->name('widget-keys.update');
        Route::delete('widget-keys/{widgetKey}', [WidgetKeyController::class, 'destroy'])
            ->name('widget-keys.destroy');

        Route::resource('views', SavedViewController::class)
            ->only(['store', 'update', 'destroy'])
            ->parameters(['views' => 'savedView']);

        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
            ->name('workspace.logout');
    });

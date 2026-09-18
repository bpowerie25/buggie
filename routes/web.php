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

        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
            ->name('workspace.logout');
    });

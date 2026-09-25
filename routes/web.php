<?php

use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\ChatIntegrationController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisciplineController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\InstanceAccessRequestController;
use App\Http\Controllers\InstanceSettingsController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\IssueExportController;
use App\Http\Controllers\IssueLookupController;
use App\Http\Controllers\IssueParentController;
use App\Http\Controllers\IssueRankController;
use App\Http\Controllers\IssueRelationController;
use App\Http\Controllers\IssueReporterController;
use App\Http\Controllers\IssueScheduleController;
use App\Http\Controllers\IssueTrashController;
use App\Http\Controllers\IssueWatchController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\PhaseController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SavedViewController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Controllers\TimelineController;
use App\Http\Controllers\TimeOffController;
use App\Http\Controllers\TimerController;
use App\Http\Controllers\TimeReportController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\VersionController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WidgetKeyController;
use App\Http\Controllers\WidgetScriptController;
use App\Http\Controllers\WorkloadController;
use App\Http\Controllers\WorkspaceAccessRequestController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceSettingsController;
use App\Http\Middleware\EnsureEmailIsVerifiedWhereRequired;
use App\Models\WidgetKey;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Route;

$host = config('buggie.host');

/*
|--------------------------------------------------------------------------
| Central domain — buggie.eu
|--------------------------------------------------------------------------
| Marketing, authentication and the workspace picker. No workspace is bound
| here, so tenant models are unreachable (WorkspaceScope throws in strict mode).
*/

Route::domain($host)->group(function () {
    Route::get('/', HomeController::class)->name('home');

    // The reporter's own thread. The token is the entire credential, so this sits
    // outside every other guard on purpose.
    Route::get('portal/{token}', [PortalController::class, 'show'])->name('portal.show');
    Route::post('portal/{token}/comment', [PortalController::class, 'comment'])
        ->name('portal.comment');

    // The widget bundle, embedded cross-origin in customers' applications.
    // The parameter must be constrained: the default [^/]+ is greedy and swallows
    // the .js suffix, leaving nothing for the literal to match.
    // Documentation, rendered from the same Markdown that ships in the repository.
    Route::get('docs/{page?}', DocsController::class)
        ->where('page', '[a-z0-9-]+')
        ->name('docs');

    // Served from the central domain because the portal is, and a reporter has no
    // workspace context — only a token.
    Route::get('brand/{project}/logo', [BrandingController::class, 'logo'])
        ->whereNumber('project')
        ->name('brand.logo');

    Route::get('w/{key}.js', WidgetScriptController::class)
        ->where('key', '[A-Za-z0-9_]+')
        ->name('widget.script');

    // Asking for a workspace that does not exist yet. Only in `request` mode.
    Route::get('request-access', [AccessRequestController::class, 'create'])
        ->name('access-requests.create');
    Route::post('request-access', [AccessRequestController::class, 'store'])
        ->name('access-requests.store');

    Route::middleware('guest')->group(function () {
        Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
        Route::post('login', [AuthenticatedSessionController::class, 'store']);

        // Open, closed or by invitation depending on the install; see Registration.
        Route::middleware('registration')->group(function () {
            Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
            Route::post('register', [RegisteredUserController::class, 'store']);
        });

        Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
            ->name('password.request');
        Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
            ->name('password.email');

        Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
            ->name('password.reset');
        Route::post('reset-password', [NewPasswordController::class, 'store'])
            ->name('password.store');
    });

    Route::middleware('auth')->group(function () {
        Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
        // A workspace is made by somebody whose address is confirmed, where this
        // install asks for that: see EnsureEmailIsVerifiedWhereRequired.
        Route::middleware(EnsureEmailIsVerifiedWhereRequired::class)->group(function () {
            Route::get('workspaces/create', [WorkspaceController::class, 'create'])->name('workspaces.create');
            Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
        });

        Route::get('email/verify', [EmailVerificationController::class, 'notice'])
            ->name('verification.notice');
        Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');
        Route::post('email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:6,1')
            ->name('verification.send');

        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    });
});

/*
|--------------------------------------------------------------------------
| Widget harness (local only)
|--------------------------------------------------------------------------
| A deliberately broken checkout page for developing the reporter widget against:
| it throws a real error, logs to the console, makes a failing request, and contains
| a password field and a data-buggie-redact field to prove redaction works.
*/

if (! app()->isProduction()) {
    Route::domain($host)->get('widget-demo', function () {
        $key = WidgetKey::withoutGlobalScopes()->where('is_active', true)->first();

        abort_if($key === null, 404, 'Seed the database first: ./bin/art migrate:fresh --seed');

        return view('widget-demo', [
            // Cache-busted, so widget rebuilds are picked up immediately.
            'snippetUrl' => '/w/'.$key->public_key.'.js?v='.filemtime(public_path('widget/buggie.js')),
        ]);
    })->name('widget.demo');

    // Harness for the @buggie/widget npm package, exercising the loader rather than
    // a raw script tag. Serves the built package straight from packages/ so it is
    // always whatever was last built, with nothing copied into public/.
    Route::domain($host)->get('npm-demo', function () {
        /*
         * A real key from this database, not a literal.
         *
         * The harness hard-coded one from whenever it was written, and the seeder
         * makes a fresh random key every time — so the page silently stopped working
         * on the next `migrate --seed`, and looked exactly like a broken widget
         * rather than a stale fixture.
         */
        $key = WidgetKey::withoutGlobalScopes()
            ->where('is_active', true)
            ->value('public_key');

        abort_if($key === null, 404, 'No widget key exists — run the seeder first.');

        return view('npm-demo', ['widgetKey' => $key]);
    })->name('npm.demo');

    Route::domain($host)->get('npm-demo/package', function () {
        $path = base_path('packages/widget/dist/index.js');

        abort_unless(is_file($path), 404, 'Run: npm --prefix packages/widget run build');

        return response(file_get_contents($path), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
        ]);
    })->name('npm.demo.package');
}

/*
|--------------------------------------------------------------------------
| Workspace domains — {workspace}.buggie.eu
|--------------------------------------------------------------------------
| ResolveWorkspace (global) has already bound the tenant by the time these run;
| 'workspace' middleware then requires the signed-in user to be a member.
*/

// Accepting an invitation happens on the workspace domain but outside the membership
// gate — the whole point is that the visitor is not a member yet.
Route::domain('{workspace}.'.$host)->group(function () {
    Route::get('invitations/{token}', [InvitationController::class, 'show'])
        ->name('invitations.show');
    Route::post('invitations/{token}', [InvitationController::class, 'accept'])
        ->middleware('auth')
        ->name('invitations.accept');

    // Signing in happens on the central domain, but people type the address they
    // know. Sent on with the workspace attached, so the page can offer to ask it.
    Route::get('login', fn () => redirect_across_domains(
        central_url('login?workspace='.app(Tenancy::class)->currentOrFail()->slug),
    ))->name('workspace.login');

    // Asking this workspace to be let in. Only in `request` mode.
    Route::get('request-access', [AccessRequestController::class, 'create'])
        ->name('workspace.access-requests.create');
    Route::post('request-access', [AccessRequestController::class, 'store'])
        ->name('workspace.access-requests.store');
});

Route::domain('{workspace}.'.$host)
    ->middleware(['auth', 'workspace'])
    ->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::resource('projects', ProjectController::class);

        // Issue keys are unique per workspace (project keys are), so issues live at
        // the top level: /issues/WEB-142 rather than /projects/web/issues/142.
        // Declared before the resource so /issues/bulk is not read as an issue key.
        Route::patch('issues/bulk', [IssueController::class, 'bulk'])->name('issues.bulk');

        // Before the resource route, or /issues/export resolves as /issues/{issue}.
        Route::get('issues/export', IssueExportController::class)
            ->name('issues.export');

        // Before the resource route, for the same reason as export above: /issues/trash
        // would otherwise resolve as /issues/{issue} with a key of "trash" and 404.
        Route::get('issues/trash', [IssueTrashController::class, 'index'])
            ->name('issues.trash');
        Route::post('issues/trash/{key}/restore', [IssueTrashController::class, 'restore'])
            ->name('issues.restore');
        Route::delete('issues/trash/{key}', [IssueTrashController::class, 'forceDelete'])
            ->name('issues.force-delete');

        Route::resource('issues', IssueController::class)->except('edit');

        Route::post('issues/{issue}/attachments', [AttachmentController::class, 'store'])
            ->name('attachments.store');
        Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])
            ->name('attachments.show');
        Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])
            ->name('attachments.destroy');

        Route::post('issues/{issue}/comments', [CommentController::class, 'store'])
            ->name('comments.store');
        Route::post('issues/{issue}/await-client', [CommentController::class, 'await'])
            ->name('comments.await');
        Route::post('issues/{issue}/reporter', IssueReporterController::class)
            ->name('issues.reporter');
        Route::patch('comments/{comment}', [CommentController::class, 'update'])
            ->name('comments.update');
        Route::delete('comments/{comment}', [CommentController::class, 'destroy'])
            ->name('comments.destroy');

        // Watching is a view-level thing, not an edit: a client following their own
        // issue is not editing it.
        Route::post('issues/{issue}/watch', [IssueWatchController::class, 'store'])
            ->name('issues.watch');
        Route::delete('issues/{issue}/watch', [IssueWatchController::class, 'destroy'])
            ->name('issues.unwatch');

        // The issue picker's suggestions, as JSON. Staff only; see the controller.
        Route::get('issues-lookup', IssueLookupController::class)
            ->name('issues.lookup');
        Route::post('issues/{issue}/relations', [IssueRelationController::class, 'store'])
            ->name('relations.store');
        Route::delete('issues/{issue}/relations', [IssueRelationController::class, 'destroy'])
            ->name('relations.destroy');
        Route::post('issues/{issue}/duplicate', [IssueRelationController::class, 'duplicate'])
            ->name('issues.duplicate');

        Route::resource('labels', LabelController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::get('inbox', [ReportController::class, 'index'])->name('reports.index');
        Route::post('inbox/{report}/accept', [ReportController::class, 'accept'])->name('reports.accept');
        Route::post('inbox/{report}/merge', [ReportController::class, 'merge'])->name('reports.merge');
        Route::post('inbox/{report}/dismiss', [ReportController::class, 'dismiss'])->name('reports.dismiss');
        Route::get('inbox/{report}/screenshot', [ReportController::class, 'screenshot'])
            ->name('reports.screenshot');

        Route::post('projects/{project}/statuses', [StatusController::class, 'store'])
            ->name('statuses.store');
        Route::patch('projects/{project}/statuses/order', [StatusController::class, 'reorder'])
            ->name('statuses.reorder');
        Route::patch('statuses/{status}', [StatusController::class, 'update'])
            ->name('statuses.update');
        Route::delete('statuses/{status}', [StatusController::class, 'destroy'])
            ->name('statuses.destroy');

        Route::post('projects/{project}/widget-keys', [WidgetKeyController::class, 'store'])
            ->name('widget-keys.store');
        Route::patch('widget-keys/{widgetKey}', [WidgetKeyController::class, 'update'])
            ->name('widget-keys.update');
        Route::post('widget-keys/{widgetKey}/secret', [WidgetKeyController::class, 'rotate'])
            ->name('widget-keys.rotate');
        Route::delete('widget-keys/{widgetKey}', [WidgetKeyController::class, 'destroy'])
            ->name('widget-keys.destroy');

        // --- notifications ----------------------------------------------------
        // The list belongs to the workspace you are in — what happened here, to you.
        // The preferences behind it do not: somebody invited to four client
        // workspaces should not have to switch the same thing off four times.
        //
        // Marking read is a POST, including the one that happens by opening an
        // entry. A GET that changes something is a GET that a link prefetcher will
        // fire on somebody's behalf.
        Route::get('notifications', [NotificationController::class, 'index'])
            ->name('notifications.index');
        Route::post('notifications/read', [NotificationController::class, 'readAll'])
            ->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
            ->whereNumber('notification')
            ->name('notifications.read');

        Route::get('settings/notifications', [NotificationPreferenceController::class, 'edit'])
            ->name('notifications.edit');
        Route::patch('settings/notifications', [NotificationPreferenceController::class, 'update'])
            ->name('notifications.update');
        // --- end notifications ------------------------------------------------

        // The whole install, not this workspace: mail, and whatever else an operator
        // needs to change without editing .env and redeploying.
        // Webhooks belong to the workspace; each may watch one project or all of them.
        Route::post('settings/webhooks', [WebhookController::class, 'store'])
            ->name('webhooks.store');
        Route::patch('settings/webhooks/{webhook}', [WebhookController::class, 'update'])
            ->name('webhooks.update');
        Route::delete('settings/webhooks/{webhook}', [WebhookController::class, 'destroy'])
            ->name('webhooks.destroy');
        Route::post('settings/webhooks/{webhook}/test', [WebhookController::class, 'test'])
            ->name('webhooks.test');

        // Slack and Teams channels. A webhook by another name, except the URL is the
        // credential rather than carrying one, so it is never sent back to the page.
        Route::post('settings/chat', [ChatIntegrationController::class, 'store'])
            ->name('chat.store');
        Route::patch('settings/chat/{chatIntegration}', [ChatIntegrationController::class, 'update'])
            ->name('chat.update');
        Route::delete('settings/chat/{chatIntegration}', [ChatIntegrationController::class, 'destroy'])
            ->name('chat.destroy');
        Route::post('settings/chat/{chatIntegration}/test', [ChatIntegrationController::class, 'test'])
            ->name('chat.test');

        Route::post('projects/{project}/branding', [BrandingController::class, 'update'])
            ->name('branding.update');

        // Bringing a backlog over from another tracker.
        // The Import page, per project; with none, it asks which.
        Route::get('import', [ImportController::class, 'index'])
            ->name('imports.choose');
        Route::get('projects/{project}/import', [ImportController::class, 'index'])
            ->name('imports.index');
        // Before imports/{import}, or "template" is read as an import id.
        Route::get('projects/{project}/imports/template', [ImportController::class, 'template'])
            ->name('imports.template');
        Route::post('projects/{project}/imports', [ImportController::class, 'store'])
            ->name('imports.store');
        Route::get('projects/{project}/imports/{import}', [ImportController::class, 'show'])
            ->name('imports.show');
        Route::patch('projects/{project}/imports/{import}', [ImportController::class, 'update'])
            ->name('imports.update');
        Route::delete('projects/{project}/imports/{import}', [ImportController::class, 'destroy'])
            ->name('imports.destroy');

        // Releases live under their project: "2.4.1" means nothing on its own.
        Route::get('projects/{project}/versions/{version}', [VersionController::class, 'show'])
            ->name('versions.show');
        Route::post('projects/{project}/versions', [VersionController::class, 'store'])
            ->name('versions.store');
        Route::patch('projects/{project}/versions/{version}', [VersionController::class, 'update'])
            ->name('versions.update');
        Route::delete('projects/{project}/versions/{version}', [VersionController::class, 'destroy'])
            ->name('versions.destroy');
        Route::post('projects/{project}/phases', [PhaseController::class, 'store'])
            ->name('phases.store');
        // Before {phase}, so "order" is not read as a phase id.
        Route::put('projects/{project}/phases/order', [PhaseController::class, 'reorder'])
            ->name('phases.reorder');
        Route::patch('projects/{project}/phases/{phase}', [PhaseController::class, 'update'])
            ->name('phases.update');
        Route::delete('projects/{project}/phases/{phase}', [PhaseController::class, 'destroy'])
            ->name('phases.destroy');

        Route::patch('issues/{issue}/parent', IssueParentController::class)
            ->name('issues.parent');
        Route::patch('issues/{issue}/rank', IssueRankController::class)
            ->name('issues.rank');
        // --- the optional timer ---
        Route::post('issues/{issue}/timer', [TimerController::class, 'start'])
            ->name('timer.start');
        Route::post('timer/stop', [TimerController::class, 'stop'])
            ->name('timer.stop');
        Route::delete('timer', [TimerController::class, 'discard'])
            ->name('timer.discard');
        // --- end ---

        Route::post('issues/{issue}/time', [TimeEntryController::class, 'store'])
            ->name('time.store');
        Route::delete('time/{entry}', [TimeEntryController::class, 'destroy'])
            ->name('time.destroy');
        Route::patch('issues/{issue}/estimate', [TimeEntryController::class, 'estimate'])
            ->name('time.estimate');
        Route::get('insights', InsightsController::class)
            ->name('insights');
        Route::get('workload', WorkloadController::class)
            ->name('workload');
        Route::post('time-off', [TimeOffController::class, 'store'])
            ->name('time-off.store');
        Route::delete('time-off/{timeOff}', [TimeOffController::class, 'destroy'])
            ->name('time-off.destroy');

        // --- timeline ---------------------------------------------------------
        // Issues as bars on a date axis. Staff only, enforced in the controller
        // rather than by a middleware, so the check sits beside the reason for it.
        Route::get('timeline', TimelineController::class)
            ->name('timeline');
        // Dragging on the timeline. Refused if the issue changed since it was loaded.
        Route::patch('issues/{issue}/schedule', IssueScheduleController::class)
            ->name('issues.schedule');
        // --- end timeline -----------------------------------------------------
        Route::get('time', [TimeReportController::class, 'index'])
            ->name('time.index');
        Route::get('time/export', [TimeReportController::class, 'export'])
            ->name('time.export');

        Route::post('projects/{project}/fields', [CustomFieldController::class, 'store'])
            ->name('fields.store');
        Route::patch('projects/{project}/fields/{field}', [CustomFieldController::class, 'update'])
            ->name('fields.update');
        Route::delete('projects/{project}/fields/{field}', [CustomFieldController::class, 'destroy'])
            ->name('fields.destroy');

        Route::get('settings/instance', [InstanceSettingsController::class, 'edit'])
            ->name('instance.edit');
        Route::patch('settings/instance', [InstanceSettingsController::class, 'update'])
            ->name('instance.update');
        Route::post('settings/instance/test-mail', [InstanceSettingsController::class, 'test'])
            ->name('instance.test-mail');
        Route::patch('settings/instance/registration', [InstanceSettingsController::class, 'registration'])
            ->name('instance.registration');
        // By id rather than bound: the tenant scope would hide every request that is
        // not for the workspace the operator happens to be standing in.
        Route::post('settings/instance/access-requests/{id}/approve', [InstanceAccessRequestController::class, 'approve'])
            ->whereNumber('id')
            ->name('instance.access-requests.approve');
        Route::post('settings/instance/access-requests/{id}/decline', [InstanceAccessRequestController::class, 'decline'])
            ->whereNumber('id')
            ->name('instance.access-requests.decline');

        // This workspace's own requests, for the people who can invite to it.
        Route::get('settings/access-requests', [WorkspaceAccessRequestController::class, 'index'])
            ->name('access-requests.index');
        Route::post('settings/access-requests/{accessRequest}/approve', [WorkspaceAccessRequestController::class, 'approve'])
            ->name('access-requests.approve');
        Route::post('settings/access-requests/{accessRequest}/decline', [WorkspaceAccessRequestController::class, 'decline'])
            ->name('access-requests.decline');

        // API tokens. Created and revoked here; the API itself lives in routes/api.php.
        Route::post('settings/tokens', [ApiTokenController::class, 'store'])
            ->name('tokens.store');
        Route::delete('settings/tokens/{apiToken}', [ApiTokenController::class, 'destroy'])
            ->name('tokens.destroy');

        Route::get('settings/workspace', [WorkspaceSettingsController::class, 'edit'])
            ->name('workspace.edit');
        Route::patch('settings/workspace', [WorkspaceSettingsController::class, 'update'])
            ->name('workspace.update');
        Route::delete('settings/workspace', [WorkspaceSettingsController::class, 'destroy'])
            ->name('workspace.destroy');

        // Billing exists only on the hosted service; self-hosted installs 404 here.
        Route::middleware('hosted')->group(function () {
            Route::get('settings/billing', [BillingController::class, 'index'])->name('billing.index');
            Route::post('settings/billing/checkout', [BillingController::class, 'checkout'])
                ->name('billing.checkout');
            Route::get('settings/billing/portal', [BillingController::class, 'portal'])
                ->name('billing.portal');
            Route::post('settings/billing/cancel', [BillingController::class, 'cancel'])
                ->name('billing.cancel');
            Route::post('settings/billing/resume', [BillingController::class, 'resume'])
                ->name('billing.resume');
        });

        Route::get('settings/members', [MemberController::class, 'index'])->name('members.index');
        Route::post('settings/members', [MemberController::class, 'store'])->name('members.store');
        Route::delete('settings/invitations/{invitation}', [MemberController::class, 'destroy'])
            ->name('invitations.destroy');
        Route::patch('settings/members/{user}/projects', [MemberController::class, 'grants'])
            ->name('members.grants');
        Route::patch('settings/members/{user}/projects/{project:id}/tier', [MemberController::class, 'tier'])
            ->name('members.tier');
        Route::patch('settings/members/{user}/capacity', [MemberController::class, 'capacity'])
            ->name('members.capacity');
        Route::post('settings/disciplines', [DisciplineController::class, 'store'])
            ->name('disciplines.store');
        Route::patch('settings/disciplines', [DisciplineController::class, 'update'])
            ->name('disciplines.update');
        Route::put('settings/disciplines/order', [DisciplineController::class, 'reorder'])
            ->name('disciplines.reorder');
        Route::delete('settings/disciplines', [DisciplineController::class, 'destroy'])
            ->name('disciplines.destroy');
        Route::delete('settings/members/{user}', [MemberController::class, 'remove'])
            ->name('members.remove');

        Route::resource('views', SavedViewController::class)
            ->only(['store', 'update', 'destroy'])
            ->parameters(['views' => 'savedView']);

        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
            ->name('workspace.logout');
    });

/*
|--------------------------------------------------------------------------
| Two-factor authentication
|--------------------------------------------------------------------------
| Two domains, because the feature has two halves. The challenge sits beside
| sign-in on the central domain and behind `guest`, since whoever is answering
| it is not signed in yet — that is the whole point of it. Enrolment sits with
| the other things that belong to a person rather than a workspace.
|
| Never gated on a plan, in either mode. Security is not a feature tier.
*/

Route::domain($host)->middleware('guest')->group(function () {
    Route::get('two-factor', [TwoFactorChallengeController::class, 'create'])
        ->name('two-factor.challenge');
    Route::post('two-factor', [TwoFactorChallengeController::class, 'store']);
});

Route::domain('{workspace}.'.$host)
    ->middleware(['auth', 'workspace'])
    ->group(function () {
        Route::get('settings/two-factor', [TwoFactorController::class, 'edit'])
            ->name('two-factor.edit');
        Route::post('settings/two-factor', [TwoFactorController::class, 'store'])
            ->name('two-factor.store');
        Route::post('settings/two-factor/confirm', [TwoFactorController::class, 'confirm'])
            ->name('two-factor.confirm');
        Route::post('settings/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])
            ->name('two-factor.recovery-codes');
        Route::delete('settings/two-factor', [TwoFactorController::class, 'destroy'])
            ->name('two-factor.destroy');
    });

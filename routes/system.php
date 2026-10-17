<?php

use App\Http\Controllers\System\AuditController;
use App\Http\Controllers\System\AuditExportController;
use App\Http\Controllers\System\PermissionController;
use App\Http\Controllers\System\RoleActivateController;
use App\Http\Controllers\System\RoleController;
use App\Http\Controllers\System\RoleDeactivateController;
use App\Http\Controllers\System\UserController;
use App\Http\Controllers\System\Users\ActivateUserController;
use App\Http\Controllers\System\Users\BulkDeactivateUsersController;
use App\Http\Controllers\System\Users\DeactivateUserController;
use App\Http\Controllers\System\Users\ExportUsersController;
use App\Http\Controllers\System\Users\SendPasswordResetController;
use App\Http\Controllers\System\Users\SyncUserRolesController;
use App\Http\Controllers\System\UsersCopilotActionController;
use App\Http\Controllers\System\UsersCopilotMessageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'ensure-two-factor'])->prefix('system')->name('system.')->group(function () {
    // ── Users ────────────────────────────────────────────────────────────────
    // IMPORTANT: export and bulk routes MUST be registered BEFORE the resource
    // to prevent Laravel from binding them as {user} parameter segments.

    Route::get('users/export', ExportUsersController::class)->name('users.export');
    Route::post('users/bulk', BulkDeactivateUsersController::class)->name('users.bulk');
    Route::post('users/copilot/messages', UsersCopilotMessageController::class)
        ->middleware('throttle:users-copilot-messages')
        ->name('users.copilot.messages');
    Route::post('users/copilot/actions/{actionType}', UsersCopilotActionController::class)
        ->name('users.copilot.actions');

    Route::resource('users', UserController::class);

    Route::patch('users/{user}/deactivate', DeactivateUserController::class)->name('users.deactivate');
    Route::patch('users/{user}/activate', ActivateUserController::class)->name('users.activate');
    Route::put('users/{user}/roles', SyncUserRolesController::class)->name('users.roles.sync');
    Route::post('users/{user}/send-reset', SendPasswordResetController::class)->name('users.send-reset');

    // ── Roles ─────────────────────────────────────────────────────────────────
    Route::resource('roles', RoleController::class);

    Route::patch('roles/{role}/deactivate', RoleDeactivateController::class)->name('roles.deactivate');
    Route::patch('roles/{role}/activate', RoleActivateController::class)->name('roles.activate');

    // ── Audit viewer (read-only) ────────────────────────────────────────────────
    Route::get('audit/export', AuditExportController::class)->name('audit.export');
    Route::get('audit/{source}/{id}', [AuditController::class, 'show'])
        ->whereNumber('id')
        ->whereIn('source', ['model', 'security'])
        ->name('audit.show');
    Route::get('audit', [AuditController::class, 'index'])->name('audit.index');

    // ── Permissions (read-only catalog) ───────────────────────────────────────
    Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
    Route::get('permissions/{permission}', [PermissionController::class, 'show'])->name('permissions.show');
});

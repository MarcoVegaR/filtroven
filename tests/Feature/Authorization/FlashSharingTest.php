<?php

/**
 * FlashSharingTest — verifies HandleInertiaRequests correctly exposes
 * flash session data as Inertia shared props.
 *
 * Covers the spec scenarios in Domain 2 (Unified Toast System):
 * - flash.success is present when session contains 'success'
 * - flash.error is present when session contains 'error'
 * - flash levels do not bleed into each other
 * - guest users receive null flash props (no session data)
 * - both keys are always present in the payload (null when absent)
 *
 * @see app/Http/Middleware/HandleInertiaRequests.php  flash prop definition
 * @see resources/js/components/flash-toaster.tsx       frontend consumer
 * @see resources/js/types/ui.ts                        Flash type
 */

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// ── helpers ──────────────────────────────────────────────────────────────────

/**
 * Build a request with a pre-seeded flash session value, then follow the
 * redirect to a stable authenticated Inertia page (dashboard) so we can
 * inspect the Inertia shared props after the flash is consumed.
 */
function actingWithFlash(string $key, string $message): TestResponse
{
    $user = User::factory()->create();

    return test()->actingAs($user)
        ->withSession([$key => $message])
        ->get(route('dashboard'));
}

// ── success flash ─────────────────────────────────────────────────────────────

test('flash.success is shared in inertia props when session has a success value', function () {
    $message = 'Recurso creado exitosamente.';

    actingWithFlash('success', $message)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', $message)
        );
});

test('flash.success is null when session has no success value', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', null)
        );
});

// ── error flash ───────────────────────────────────────────────────────────────

test('flash.error is shared in inertia props when session has an error value', function () {
    $message = 'No se pudo eliminar el recurso.';

    actingWithFlash('error', $message)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.error', $message)
        );
});

test('flash.error is null when session has no error value', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.error', null)
        );
});

// ── info and warning flash ────────────────────────────────────────────────────

test('flash.info is shared in inertia props when session has an info value', function () {
    $message = 'Revisa la documentación actualizada.';

    actingWithFlash('info', $message)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.info', $message)
        );
});

test('flash.warning is shared in inertia props when session has a warning value', function () {
    $message = 'Este recurso está por expirar.';

    actingWithFlash('warning', $message)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.warning', $message)
        );
});

// ── guest users ───────────────────────────────────────────────────────────────

test('guest users have null flash props in inertia shared data', function () {
    // Guests do not have a session with flash data. All flash keys must be null.
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', null)
            ->where('flash.error', null)
        );
});

// ── isolation: flash levels do not bleed into each other ─────────────────────

test('success flash does not bleed into flash.error', function () {
    actingWithFlash('success', 'Operación exitosa.')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', 'Operación exitosa.')
            ->where('flash.error', null)
        );
});

test('error flash does not bleed into flash.success', function () {
    actingWithFlash('error', 'Algo salió mal.')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.error', 'Algo salió mal.')
            ->where('flash.success', null)
        );
});

// ── flash prop structure ──────────────────────────────────────────────────────

test('flash is always present as a key in inertia shared props', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('flash')
            ->has('flash.success')
            ->has('flash.error')
        );
});

test('flash prop contains all four defined levels as keys', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('flash.success')
            ->has('flash.error')
            ->has('flash.info')
            ->has('flash.warning')
        );
});

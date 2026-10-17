<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'permissions' => $user?->getAllPermissions()->pluck('name')->all() ?? [],
            ],
            'copilot' => [
                'enabled' => (bool) config('ai-copilot.enabled'),
                'module_enabled' => (bool) config('ai-copilot.modules.users.enabled'),
                'channel_enabled' => (bool) config('ai-copilot.channels.web.enabled'),
                'can_view' => (bool) $user?->can('system.users-copilot.view'),
                'can_execute' => (bool) $user?->can('system.users-copilot.execute'),
            ],
            'flash' => Inertia::always([
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'warning' => $request->session()->get('warning'),
            ]),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'ui' => [
                'locale' => app()->getLocale(),
                'branding' => [
                    'application' => config('app.name'),
                    'company' => config('app.name'),
                    'footerSubtitle' => 'Panel de administración',
                    'shellTagline' => 'Administración en español',
                    'mobileBlurb' => 'Accede a tu panel de administración.',
                ],
                'navigation' => [
                    'label' => 'Navegación',
                    'items' => [
                        [
                            'title' => 'Panel',
                            'href' => route('dashboard', absolute: false),
                            'icon' => 'layout-dashboard',
                        ],
                        ...($user?->can('system.roles.view') ? [
                            [
                                'title' => 'Roles',
                                'href' => route('system.roles.index', absolute: false),
                                'icon' => 'shield-check',
                            ],
                        ] : []),
                        ...($user?->can('system.users.view') ? [
                            [
                                'title' => 'Usuarios',
                                'href' => route('system.users.index', absolute: false),
                                'icon' => 'users',
                            ],
                        ] : []),
                        ...($user?->can('system.audit.view') ? [
                            [
                                'title' => 'Auditoría',
                                'href' => route('system.audit.index', absolute: false),
                                'icon' => 'scroll-text',
                            ],
                        ] : []),
                    ],
                    'starterPromoLinksRemoved' => true,
                ],
                'settingsSection' => [
                    'title' => 'Configuración',
                    'description' => 'Administra tu perfil, seguridad y preferencias visuales',
                    'ariaLabel' => 'Configuración',
                ],
                'settingsNavigation' => [
                    [
                        'title' => 'Perfil',
                        'href' => route('profile.edit', absolute: false),
                    ],
                    [
                        'title' => 'Seguridad',
                        'href' => route('security.edit', absolute: false),
                    ],
                    [
                        'title' => 'Acceso',
                        'href' => route('settings.access', absolute: false),
                    ],
                    [
                        'title' => 'Apariencia',
                        'href' => route('appearance.edit', absolute: false),
                    ],
                ],
                'appearance' => [
                    'palette' => 'violet',
                    'defaultMode' => 'light',
                    'supportedModes' => ['light', 'dark', 'system'],
                    'labels' => [
                        'light' => 'Claro',
                        'dark' => 'Oscuro',
                        'system' => 'Sistema',
                    ],
                ],
            ],
        ];
    }
}

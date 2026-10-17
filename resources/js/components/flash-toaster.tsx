import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import type { Flash } from '@/types/ui';

function normalizeFlash(flash?: Partial<Flash> | null): Flash {
    return {
        success: flash?.success ?? null,
        error: flash?.error ?? null,
        info: flash?.info ?? null,
        warning: flash?.warning ?? null,
    };
}

function hasFlash(flash: Flash): boolean {
    return Object.values(flash).some(Boolean);
}

function dispatchFlashToasts(flash: Flash): void {
    if (flash.success) {
        toast.success(flash.success);
    }

    if (flash.error) {
        toast.error(flash.error);
    }

    if (flash.info) {
        toast.info(flash.info);
    }

    if (flash.warning) {
        toast.warning(flash.warning);
    }
}

/**
 * FlashToaster bridges Inertia flash events into Sonner toasts.
 *
 * Mount once at the application root so redirects between pages can deliver
 * notifications without depending on a remounting layout instance.
 */
export function FlashToaster() {
    const lastToastRef = useRef<{ signature: string; shownAt: number } | null>(
        null,
    );

    function showFlash(flashData?: Partial<Flash> | null): void {
        const normalizedFlash = normalizeFlash(flashData);

        if (!hasFlash(normalizedFlash)) {
            lastToastRef.current = null;

            return;
        }

        const signature = JSON.stringify(normalizedFlash);
        const now = Date.now();

        if (
            lastToastRef.current?.signature === signature &&
            now - lastToastRef.current.shownAt < 1000
        ) {
            return;
        }

        lastToastRef.current = { signature, shownAt: now };
        dispatchFlashToasts(normalizedFlash);
    }

    useEffect(() => {
        showFlash(
            window.history.state?.page?.props?.flash as Flash | undefined,
        );

        const removeFlashListener = router.on('flash', (event) => {
            showFlash(event.detail.flash as Flash);
        });

        const removeNavigateListener = router.on('navigate', (event) => {
            showFlash(event.detail.page.props.flash as Flash | undefined);
        });

        const removeSuccessListener = router.on('success', (event) => {
            showFlash(event.detail.page.props.flash as Flash | undefined);
        });

        return () => {
            removeFlashListener();
            removeNavigateListener();
            removeSuccessListener();
        };
    }, []);

    return null;
}

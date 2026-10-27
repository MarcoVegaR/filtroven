import { Eye, EyeOff } from 'lucide-react';
import type { ComponentProps, Ref } from 'react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export default function PasswordInput({
    className,
    ref,
    showPasswordLabel = 'Show password',
    hidePasswordLabel = 'Hide password',
    accessibleToggle = false,
    ...props
}: Omit<ComponentProps<'input'>, 'type'> & {
    ref?: Ref<HTMLInputElement>;
    showPasswordLabel?: string;
    hidePasswordLabel?: string;
    accessibleToggle?: boolean;
}) {
    const [showPassword, setShowPassword] = useState(false);

    return (
        <div className="relative">
            <Input
                type={showPassword ? 'text' : 'password'}
                className={cn('pr-10', className)}
                ref={ref}
                {...props}
            />
            <button
                type="button"
                onClick={() => setShowPassword((prev) => !prev)}
                className={cn(
                    'absolute inset-y-0 right-0 flex items-center justify-center rounded-r-md px-3 text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none',
                    accessibleToggle && 'min-h-11 min-w-11',
                )}
                aria-label={
                    showPassword ? hidePasswordLabel : showPasswordLabel
                }
                aria-pressed={accessibleToggle ? showPassword : undefined}
                aria-controls={accessibleToggle ? props.id : undefined}
                tabIndex={accessibleToggle ? 0 : -1}
            >
                {showPassword ? (
                    <EyeOff className="size-4" />
                ) : (
                    <Eye className="size-4" />
                )}
            </button>
        </div>
    );
}

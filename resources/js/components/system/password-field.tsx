import { Check, Copy, RefreshCw } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';

import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type PasswordFieldProps = {
    id: string;
    name: string;
    defaultValue?: string;
    placeholder?: string;
    autoComplete?: string;
    required?: boolean;
    className?: string;
    confirmationRef?: React.RefObject<HTMLInputElement | null>;
    onChange?: (value: string) => void;
};

const CHARSET_LOWER = 'abcdefghijklmnopqrstuvwxyz';
const CHARSET_UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const CHARSET_DIGITS = '0123456789';
const CHARSET_SYMBOLS = '!@#$%&*_+-=';

function generatePassword(length = 16): string {
    const allChars =
        CHARSET_LOWER + CHARSET_UPPER + CHARSET_DIGITS + CHARSET_SYMBOLS;
    const array = new Uint32Array(length);
    crypto.getRandomValues(array);

    // Guarantee at least one of each required category
    const guaranteed = [
        CHARSET_LOWER[array[0] % CHARSET_LOWER.length],
        CHARSET_UPPER[array[1] % CHARSET_UPPER.length],
        CHARSET_DIGITS[array[2] % CHARSET_DIGITS.length],
        CHARSET_SYMBOLS[array[3] % CHARSET_SYMBOLS.length],
    ];

    const remaining = Array.from(
        { length: length - guaranteed.length },
        (_, i) => {
            return allChars[array[i + guaranteed.length] % allChars.length];
        },
    );

    // Shuffle all characters
    const combined = [...guaranteed, ...remaining];

    for (let i = combined.length - 1; i > 0; i--) {
        const j = array[i] % (i + 1);
        [combined[i], combined[j]] = [combined[j], combined[i]];
    }

    return combined.join('');
}

export function PasswordField({
    id,
    name,
    defaultValue,
    placeholder = 'Mínimo 8 caracteres',
    autoComplete = 'new-password',
    required,
    className,
    confirmationRef,
    onChange,
}: PasswordFieldProps) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [copied, setCopied] = useState(false);

    const setNativeValue = useCallback(
        (el: HTMLInputElement, value: string) => {
            const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
                window.HTMLInputElement.prototype,
                'value',
            )?.set;
            nativeInputValueSetter?.call(el, value);
            el.dispatchEvent(new Event('input', { bubbles: true }));
        },
        [],
    );

    const handleGenerate = useCallback(() => {
        const password = generatePassword();

        if (inputRef.current) {
            setNativeValue(inputRef.current, password);
        }

        if (confirmationRef?.current) {
            setNativeValue(confirmationRef.current, password);
        }

        onChange?.(password);
        setCopied(false);
    }, [onChange, confirmationRef, setNativeValue]);

    const handleCopy = useCallback(async () => {
        const value = inputRef.current?.value;

        if (!value) {
            return;
        }

        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard API not available
        }
    }, []);

    return (
        <div className={cn('space-y-2', className)}>
            <PasswordInput
                ref={inputRef}
                id={id}
                name={name}
                defaultValue={defaultValue}
                placeholder={placeholder}
                autoComplete={autoComplete}
                required={required}
            />
            <div className="flex items-center gap-1.5">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-7 gap-1.5 px-2.5 text-xs"
                    onClick={handleGenerate}
                >
                    <RefreshCw className="size-3" />
                    Generar
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-7 gap-1.5 px-2.5 text-xs"
                    onClick={handleCopy}
                >
                    {copied ? (
                        <>
                            <Check className="size-3" />
                            Copiada
                        </>
                    ) : (
                        <>
                            <Copy className="size-3" />
                            Copiar
                        </>
                    )}
                </Button>
            </div>
        </div>
    );
}

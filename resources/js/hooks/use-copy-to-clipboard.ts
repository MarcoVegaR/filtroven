import { useState } from 'react';

export type CopiedState = boolean;
export type CopyFn = (text: string) => Promise<boolean>;
export type UseCopyToClipboardReturn = [CopiedState, CopyFn];

/**
 * useCopyToClipboard — returns [copied, copy] tuple.
 * `copied` is true for 2 seconds after a successful copy, then resets.
 */
export function useCopyToClipboard(): UseCopyToClipboardReturn {
    const [copied, setCopied] = useState<CopiedState>(false);

    const copy: CopyFn = async (text) => {
        if (!navigator?.clipboard) {
            console.warn('Clipboard not supported');

            return false;
        }

        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);

            return true;
        } catch (error) {
            console.warn('Copy failed', error);

            return false;
        }
    };

    return [copied, copy];
}

export default useCopyToClipboard;

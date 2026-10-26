import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';

export type SignaturePadHandle = {
    clear: () => void;
    isEmpty: () => boolean;
    toDataUrl: () => string;
};

/**
 * A finger or stylus signature box. Draws with pointer events on a canvas
 * sized for the device's pixel ratio; exports a PNG data URL.
 */
export const SignaturePad = forwardRef<
    SignaturePadHandle,
    { label: string; onChange?: (empty: boolean) => void }
>(function SignaturePad({ label, onChange }, ref) {
    const canvas = useRef<HTMLCanvasElement>(null);
    const drawing = useRef(false);
    const empty = useRef(true);

    useEffect(() => {
        const el = canvas.current;

        if (!el) {
            return;
        }

        const resize = () => {
            const ratio = window.devicePixelRatio || 1;
            const { width, height } = el.getBoundingClientRect();
            el.width = Math.round(width * ratio);
            el.height = Math.round(height * ratio);
            const ctx = el.getContext('2d');

            if (ctx) {
                ctx.scale(ratio, ratio);
                ctx.lineWidth = 2.5;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                ctx.strokeStyle = '#1c1917';
            }

            empty.current = true;
            onChange?.(true);
        };

        resize();
        window.addEventListener('resize', resize);

        return () => window.removeEventListener('resize', resize);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useImperativeHandle(ref, () => ({
        clear() {
            const el = canvas.current;
            const ctx = el?.getContext('2d');

            if (el && ctx) {
                ctx.save();
                ctx.setTransform(1, 0, 0, 1, 0, 0);
                ctx.clearRect(0, 0, el.width, el.height);
                ctx.restore();
            }

            empty.current = true;
            onChange?.(true);
        },
        isEmpty: () => empty.current,
        toDataUrl: () => canvas.current?.toDataURL('image/png') ?? '',
    }));

    const point = (e: React.PointerEvent<HTMLCanvasElement>) => {
        const rect = e.currentTarget.getBoundingClientRect();

        return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    };

    return (
        <div className="relative h-full min-h-[180px] rounded-[10px] border-2 border-dashed border-[#a8a29e] bg-[#fafaf9]">
            <canvas
                ref={canvas}
                aria-label={label}
                role="img"
                className="absolute inset-0 size-full touch-none"
                onPointerDown={(e) => {
                    e.currentTarget.setPointerCapture(e.pointerId);
                    drawing.current = true;
                    const ctx = e.currentTarget.getContext('2d');
                    const p = point(e);
                    ctx?.beginPath();
                    ctx?.moveTo(p.x, p.y);
                }}
                onPointerMove={(e) => {
                    if (!drawing.current) {
                        return;
                    }

                    const ctx = e.currentTarget.getContext('2d');
                    const p = point(e);
                    ctx?.lineTo(p.x, p.y);
                    ctx?.stroke();

                    if (empty.current) {
                        empty.current = false;
                        onChange?.(false);
                    }
                }}
                onPointerUp={() => {
                    drawing.current = false;
                }}
            />
            <div
                className="pointer-events-none absolute inset-x-5 bottom-7 border-b border-[#a8a29e]"
                aria-hidden="true"
            />
        </div>
    );
});

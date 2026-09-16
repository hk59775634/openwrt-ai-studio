import { useRef } from 'react';

type Props = {
  axis: 'x' | 'y';
  invert?: boolean;
  label: string;
  onDrag: (delta: number) => void;
  onReset?: () => void;
};

export function SplitHandle({ axis, invert = false, label, onDrag, onReset }: Props) {
  const origin = useRef(0);
  const dragging = useRef(false);

  function stopDrag(target: HTMLDivElement, pointerId?: number) {
    dragging.current = false;
    if (pointerId !== undefined) {
      try {
        if (target.hasPointerCapture(pointerId)) {
          target.releasePointerCapture(pointerId);
        }
      } catch {
        /* capture may already be released */
      }
    }
    document.body.classList.remove('ide-col-resize', 'ide-row-resize');
  }

  return (
    <div
      className={`ide-split ide-split-${axis}`}
      role="separator"
      tabIndex={0}
      aria-label={label}
      aria-orientation={axis === 'x' ? 'vertical' : 'horizontal'}
      title={`${label} · drag to resize, double-click to reset`}
      onPointerDown={(event) => {
        if (event.button !== 0) {
          return;
        }
        event.preventDefault();
        dragging.current = true;
        origin.current = axis === 'x' ? event.clientX : event.clientY;
        try {
          event.currentTarget.setPointerCapture(event.pointerId);
        } catch {
          /* synthetic events may not support capture */
        }
        document.body.classList.add(axis === 'x' ? 'ide-col-resize' : 'ide-row-resize');
      }}
      onPointerMove={(event) => {
        if (!dragging.current) {
          return;
        }
        const pos = axis === 'x' ? event.clientX : event.clientY;
        const delta = pos - origin.current;
        if (delta === 0) {
          return;
        }
        origin.current = pos;
        onDrag(invert ? -delta : delta);
      }}
      onPointerUp={(event) => {
        stopDrag(event.currentTarget, event.pointerId);
      }}
      onPointerCancel={(event) => {
        stopDrag(event.currentTarget, event.pointerId);
      }}
      onDoubleClick={() => onReset?.()}
      onKeyDown={(event) => {
        const step = event.shiftKey ? 32 : 8;
        if (axis === 'x' && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
          event.preventDefault();
          const dir = event.key === 'ArrowRight' ? 1 : -1;
          onDrag(invert ? -dir * step : dir * step);
        }
        if (axis === 'y' && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
          event.preventDefault();
          const dir = event.key === 'ArrowDown' ? 1 : -1;
          onDrag(invert ? -dir * step : dir * step);
        }
        if (event.key === 'Home') {
          event.preventDefault();
          onReset?.();
        }
      }}
    />
  );
}

import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';

export type IdeLayout = {
  tree: number;
  ai: number;
  bottom: number;
  artifacts: number;
};

const STORAGE_KEY = 'studio.ide-layout';
export const IDE_LAYOUT_DEFAULTS: IdeLayout = { tree: 220, ai: 320, bottom: 220, artifacts: 180 };
const MIN: IdeLayout = { tree: 140, ai: 240, bottom: 96, artifacts: 88 };
const MAX: IdeLayout = { tree: 520, ai: 760, bottom: 560, artifacts: 420 };
const EDITOR_MIN = 160;
const GUTTER = 5;

function readStored(): IdeLayout {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return IDE_LAYOUT_DEFAULTS;
    }
    const parsed = JSON.parse(raw) as Partial<IdeLayout>;
    return {
      tree: Number(parsed.tree) || IDE_LAYOUT_DEFAULTS.tree,
      ai: Number(parsed.ai) || IDE_LAYOUT_DEFAULTS.ai,
      bottom: Number(parsed.bottom) || IDE_LAYOUT_DEFAULTS.bottom,
      artifacts: Number(parsed.artifacts) || IDE_LAYOUT_DEFAULTS.artifacts,
    };
  } catch {
    return IDE_LAYOUT_DEFAULTS;
  }
}

function clampLayout(next: IdeLayout, bodyWidth: number, bodyHeight: number): IdeLayout {
  const innerW = bodyWidth - GUTTER * 2;
  const treeRoom = innerW - MIN.ai - EDITOR_MIN;
  const treeMax = Math.min(MAX.tree, Math.max(MIN.tree, treeRoom));
  const tree = Math.min(treeMax, Math.max(MIN.tree, Math.round(next.tree)));
  const aiRoom = innerW - tree - EDITOR_MIN;
  const aiMax = Math.min(MAX.ai, Math.max(MIN.ai, aiRoom));
  const ai = Math.min(aiMax, Math.max(MIN.ai, Math.round(next.ai)));
  const bottomMax = Math.max(MIN.bottom, bodyHeight - 140);
  const bottom = Math.min(MAX.bottom, Math.min(bottomMax, Math.max(MIN.bottom, Math.round(next.bottom))));
  const artifactsMax = Math.min(MAX.artifacts, Math.max(MIN.artifacts, bodyHeight - 220));
  const artifacts = Math.min(artifactsMax, Math.max(MIN.artifacts, Math.round(next.artifacts)));
  return { tree, ai, bottom, artifacts };
}

export function useIdeLayout() {
  const bodyRef = useRef<HTMLDivElement>(null);
  const [layout, setLayout] = useState<IdeLayout>(readStored);

  const nudge = useCallback((key: keyof IdeLayout, delta: number) => {
    setLayout((current) => {
      const width = bodyRef.current?.clientWidth ?? window.innerWidth;
      const height = bodyRef.current?.clientHeight ?? window.innerHeight;
      const next = clampLayout({ ...current, [key]: current[key] + delta }, width, height);
      localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
      return next;
    });
  }, []);

  const apply = useCallback((patch: Partial<IdeLayout>) => {
    setLayout((current) => {
      const width = bodyRef.current?.clientWidth ?? window.innerWidth;
      const height = bodyRef.current?.clientHeight ?? window.innerHeight;
      const next = clampLayout({ ...current, ...patch }, width, height);
      localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
      return next;
    });
  }, []);

  useLayoutEffect(() => {
    apply({});
  }, [apply]);

  useEffect(() => {
    function onResize() {
      apply({});
    }
    window.addEventListener('resize', onResize);
    return () => window.removeEventListener('resize', onResize);
  }, [apply]);

  return { layout, apply, nudge, bodyRef, defaults: IDE_LAYOUT_DEFAULTS };
}

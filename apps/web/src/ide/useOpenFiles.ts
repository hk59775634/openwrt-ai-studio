import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from '../api';

export type OpenFile = {
  path: string;
  content: string;
  original: string;
};

function fileName(path: string): string {
  const parts = path.split('/');
  return parts[parts.length - 1] || path;
}

function storageKey(workspaceId: string): string {
  return `studio.open-tabs.${workspaceId}`;
}

export function useOpenFiles(workspaceId: string | undefined) {
  const [files, setFiles] = useState<OpenFile[]>([]);
  const [activePath, setActivePath] = useState<string | null>(null);
  const filesRef = useRef(files);
  filesRef.current = files;

  const persist = useCallback(
    (nextFiles: OpenFile[], nextActive: string | null) => {
      if (!workspaceId) {
        return;
      }
      sessionStorage.setItem(
        storageKey(workspaceId),
        JSON.stringify({
          paths: nextFiles.map((file) => file.path),
          activePath: nextActive,
        }),
      );
    },
    [workspaceId],
  );

  useEffect(() => {
    if (!workspaceId) {
      setFiles([]);
      setActivePath(null);
      return;
    }
    let cancelled = false;
    const raw = sessionStorage.getItem(storageKey(workspaceId));
    if (!raw) {
      return;
    }
    try {
      const parsed = JSON.parse(raw) as { paths?: string[]; activePath?: string | null };
      const paths = (parsed.paths ?? []).filter((path) => typeof path === 'string');
      if (paths.length === 0) {
        return;
      }
      void Promise.allSettled(
        paths.map(async (path) => {
          const file = await api.readFile(workspaceId, path);
          return {
            path,
            content: file.data.content,
            original: file.data.content,
          } satisfies OpenFile;
        }),
      ).then((results) => {
        if (cancelled) {
          return;
        }
        const opened = results.flatMap((result) => (result.status === 'fulfilled' ? [result.value] : []));
        if (opened.length === 0) {
          return;
        }
        setFiles(opened);
        const preferred = parsed.activePath && opened.some((file) => file.path === parsed.activePath)
          ? parsed.activePath
          : opened[0]?.path ?? null;
        setActivePath(preferred);
      });
    } catch {
      return;
    }
    return () => {
      cancelled = true;
    };
  }, [workspaceId]);

  const active = files.find((file) => file.path === activePath) ?? null;
  const dirtyCount = files.filter((file) => file.content !== file.original).length;

  const openFile = useCallback(
    async (path: string) => {
      if (!workspaceId) {
        return;
      }
      const existing = filesRef.current.find((file) => file.path === path);
      if (existing) {
        setActivePath(path);
        persist(filesRef.current, path);
        return;
      }
      const file = await api.readFile(workspaceId, path);
      const next = [
        ...filesRef.current,
        { path, content: file.data.content, original: file.data.content },
      ];
      setFiles(next);
      setActivePath(path);
      persist(next, path);
    },
    [persist, workspaceId],
  );

  const activate = useCallback(
    (path: string) => {
      setActivePath(path);
      persist(filesRef.current, path);
    },
    [persist],
  );

  const updateContent = useCallback(
    (path: string, content: string) => {
      setFiles((current) => {
        const next = current.map((file) => (file.path === path ? { ...file, content } : file));
        persist(next, path);
        return next;
      });
    },
    [persist],
  );

  const markSaved = useCallback(
    (path: string, content: string) => {
      setFiles((current) => {
        const next = current.map((file) =>
          file.path === path ? { ...file, content, original: content } : file,
        );
        persist(next, activePath);
        return next;
      });
    },
    [activePath, persist],
  );

  const closeFile = useCallback(
    (path: string) => {
      const current = filesRef.current;
      const target = current.find((file) => file.path === path);
      if (target && target.content !== target.original) {
        if (!window.confirm(`Discard unsaved changes to ${fileName(path)}?`)) {
          return false;
        }
      }
      const index = current.findIndex((file) => file.path === path);
      const next = current.filter((file) => file.path !== path);
      const neighbor = current[index + 1] ?? current[index - 1];
      const resolvedActive = activePath === path ? (neighbor?.path ?? null) : activePath;
      setFiles(next);
      setActivePath(resolvedActive);
      persist(next, resolvedActive);
      return true;
    },
    [activePath, persist],
  );

  const reloadCleanFromDisk = useCallback(async () => {
    if (!workspaceId) {
      return;
    }
    const current = filesRef.current;
    const updates = await Promise.all(
      current.map(async (file) => {
        if (file.content !== file.original) {
          return file;
        }
        try {
          const latest = await api.readFile(workspaceId, file.path);
          return { ...file, content: latest.data.content, original: latest.data.content };
        } catch {
          return file;
        }
      }),
    );
    setFiles(updates);
    persist(updates, activePath);
  }, [activePath, persist, workspaceId]);

  return {
    files,
    active,
    activePath,
    dirty: dirtyCount > 0,
    dirtyCount,
    openFile,
    activate,
    updateContent,
    markSaved,
    closeFile,
    reloadCleanFromDisk,
  };
}

export { fileName };

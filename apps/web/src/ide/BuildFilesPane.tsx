import { useEffect, useState } from 'react';
import type { Build } from '../api';
import { formatArtifactSize } from './downloadArtifact';

type Props = {
  builds: Build[];
  selectedId?: string | null;
  onSelect?: (build: Build) => void;
};

export function BuildFilesPane({ builds, selectedId, onSelect }: Props) {
  const [openIds, setOpenIds] = useState<string[]>([]);

  const latestWithFiles = builds.find((item) => item.artifacts.length > 0)?.id;

  useEffect(() => {
    if (!latestWithFiles) {
      return;
    }
    setOpenIds((current) => (current.includes(latestWithFiles) ? current : [latestWithFiles, ...current]));
  }, [latestWithFiles]);

  function toggle(item: Build) {
    setOpenIds((current) => (current.includes(item.id) ? current.filter((id) => id !== item.id) : [...current, item.id]));
    onSelect?.(item);
  }

  const total = builds.reduce((sum, item) => sum + item.artifacts.length, 0);

  return (
    <div className="ide-build-files">
      <p className="eyebrow">Build files</p>
      <p className="muted">Download links are public. Anyone with the URL can fetch the file without signing in.</p>
      {builds.length === 0 && <p className="muted tree-empty">No builds yet.</p>}
      {builds.length > 0 && total === 0 && <p className="muted tree-empty">Artifacts appear here after a successful build.</p>}
      {builds.map((item, index) => {
        const generation = builds.length - index;
        const open = openIds.includes(item.id);
        const selected = item.id === selectedId;
        return (
          <div key={item.id} className="build-gen">
            <button
              type="button"
              className={`tree-item${selected ? ' active' : ''}`}
              onClick={() => toggle(item)}
              title={`${item.type} · ${item.status}`}
            >
              <span className="tree-mark">{open ? '▾' : '▸'}</span>
              <span className="build-gen-label">
                #{generation} {item.type}
              </span>
              <span className="muted">{item.status}</span>
            </button>
            {open && (
              <div className="build-gen-files">
                {item.artifacts.length === 0 && (
                  <p className="muted tree-empty">
                    {item.status === 'running' || item.status === 'queued' ? 'Building…' : 'No artifacts'}
                  </p>
                )}
                {item.artifacts.map((artifact) => (
                  <a
                    key={artifact.id}
                    className="tree-item build-file-link"
                    href={artifact.download}
                    download={artifact.name}
                    title={`Download ${artifact.name} (public link)`}
                  >
                    <span className="tree-mark">↓</span>
                    <span className="build-file-name">{artifact.name}</span>
                    <span className="muted">{formatArtifactSize(artifact.size)}</span>
                  </a>
                ))}
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}

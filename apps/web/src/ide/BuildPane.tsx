import { useEffect, useRef } from 'react';
import { type Build, type QueueStatus } from '../api';
import { formatArtifactSize } from './downloadArtifact';

type Props = {
  builds: Build[];
  selected: Build | null;
  queue: QueueStatus | null;
  onSelect: (build: Build) => void;
  onDelete: (build: Build) => Promise<void>;
};

export function BuildPane({ builds, selected, queue, onSelect, onDelete }: Props) {
  const scroller = useRef<HTMLPreElement>(null);

  useEffect(() => {
    scroller.current?.scrollTo({ top: scroller.current.scrollHeight });
  }, [selected?.logs]);

  if (builds.length === 0) {
    return <p className="muted ide-empty">Queue an IPK or firmware build from the toolbar.</p>;
  }

  if (!selected) {
    return <p className="muted ide-empty">Select a build record to inspect logs and artifacts.</p>;
  }

  const canDelete = !['queued', 'running'].includes(selected.status);

  return (
    <div className="build-pane">
      {queue && (
        <div className="build-meta">
          <span className="muted">
            queue {queue.queued} · running {queue.running} · workers {queue.workers}
          </span>
          <span className="muted">
            quota {queue.quota.builds_last_hour}/{queue.quota.build_limit} builds/h
          </span>
        </div>
      )}
      {builds.length > 0 && (
        <div className="build-list">
          {builds.map((item) => (
            <div key={item.id} className={`build-list-item${item.id === selected.id ? ' selected' : ''}`}>
              <button
                type="button"
                className={item.id === selected.id ? '' : 'ghost'}
                onClick={() => onSelect(item)}
              >
                {item.type} · {item.status}
              </button>
              <button
                type="button"
                className="danger"
                disabled={['queued', 'running'].includes(item.status)}
                title={['queued', 'running'].includes(item.status) ? 'Cancel the build before deleting it' : 'Delete this build'}
                onClick={() => void onDelete(item)}
              >
                Delete
              </button>
            </div>
          ))}
        </div>
      )}
      <div className="build-meta">
        <span className="pill">{selected.status}</span>
        <span className="pill">{selected.type}</span>
        <code>{selected.package_name}</code>
        <span className="muted">{selected.git_commit.slice(0, 8)}</span>
        <span className="muted">{selected.openwrt_revision}</span>
        <button
          type="button"
          className="danger"
          disabled={!canDelete}
          onClick={() => void onDelete(selected)}
        >
          Delete record
        </button>
      </div>
      {selected.log_truncated && (
        <p className="muted">Showing the latest make output. Earlier lines stay on the server.</p>
      )}
      <pre className="diff-view" ref={scroller}>
        {selected.logs.map((line) => `${line.content}\n`).join('') || 'Waiting for worker…'}
      </pre>
      {selected.artifacts.length > 0 && (
        <ul className="artifact-list">
          {selected.artifacts.map((artifact) => (
            <li key={artifact.id}>
              <a href={artifact.download} download={artifact.name}>
                {artifact.name}
              </a>
              <span className="muted">{formatArtifactSize(artifact.size)}</span>
            </li>
          ))}
        </ul>
      )}
      {selected.error && <p className="error">{selected.error}</p>}
    </div>
  );
}

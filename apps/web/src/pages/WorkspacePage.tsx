import Editor from '@monaco-editor/react';
import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, type Build, type FileNode, type GitCommit, type GitStatus, type QueueStatus, type Workspace } from '../api';
import { AiPanel } from '../ide/AiPanel';
import { BuildFilesPane } from '../ide/BuildFilesPane';
import { BuildPane } from '../ide/BuildPane';
import { ConfigPane } from '../ide/ConfigPane';
import { EditorTabs } from '../ide/EditorTabs';
import { FileTree } from '../ide/FileTree';
import { GitRemotePane } from '../ide/GitRemotePane';
import { languageFor } from '../ide/language';
import { SplitHandle } from '../ide/SplitHandle';
import { TerminalPane } from '../ide/TerminalPane';
import { useIdeLayout } from '../ide/useIdeLayout';
import { useOpenFiles } from '../ide/useOpenFiles';

type BottomTab = 'terminal' | 'diff' | 'log' | 'build' | 'config' | 'remote';

export function WorkspacePage() {
  const { id } = useParams<{ id: string }>();
  const [workspace, setWorkspace] = useState<Workspace | null>(null);
  const [tree, setTree] = useState<FileNode[]>([]);
  const [status, setStatus] = useState<GitStatus | null>(null);
  const [diff, setDiff] = useState('');
  const [log, setLog] = useState<GitCommit[]>([]);
  const [tab, setTab] = useState<BottomTab>('terminal');
  const [message, setMessage] = useState('');
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [builds, setBuilds] = useState<Build[]>([]);
  const [build, setBuild] = useState<Build | null>(null);
  const [queue, setQueue] = useState<QueueStatus | null>(null);
  const logCursor = useRef<Record<string, number>>({});
  const { layout, apply, nudge, bodyRef, defaults } = useIdeLayout();
  const editors = useOpenFiles(id);

  const dirty = editors.dirty;
  const canBuild = Boolean(status?.clean) && !dirty;
  const buildHint = !status
    ? 'Checking git status…'
    : dirty
      ? 'Save and commit before building'
      : status.clean
        ? undefined
        : 'Commit your changes before building';

  const refreshGit = useCallback(async () => {
    if (!id) {
      return;
    }
    const [statusResult, diffResult, logResult] = await Promise.all([
      api.gitStatus(id),
      api.gitDiff(id),
      api.gitLog(id),
    ]);
    setStatus(statusResult.data);
    setDiff(statusResult.data.clean ? '' : diffResult.data.diff);
    setLog(logResult.data);
  }, [id]);

  const refreshTree = useCallback(async () => {
    if (!id) {
      return;
    }
    const result = await api.fileTree(id);
    setTree(result.data);
  }, [id]);

  const refreshWorkspace = useCallback(async () => {
    if (!id) {
      return;
    }
    const result = await api.getWorkspace(id);
    setWorkspace(result.data);
  }, [id]);

  const hydrateBuild = useCallback(async (buildId: string, tail: boolean) => {
    const after = tail ? 0 : (logCursor.current[buildId] ?? 0);
    const [detail, chunk] = await Promise.all([api.getBuild(buildId), api.buildLogs(buildId, after)]);
    const lines = chunk.data ?? [];
    if (lines.length > 0) {
      logCursor.current[buildId] = lines[lines.length - 1].sequence;
    } else if (typeof chunk.after === 'number') {
      logCursor.current[buildId] = chunk.after;
    }
    const apply = (row: Build): Build => {
      if (row.id !== buildId) {
        return row;
      }
      return {
        ...detail.data,
        logs: tail ? lines : [...(row.logs ?? []), ...lines],
        log_truncated: chunk.truncated || row.log_truncated,
      };
    };
    setBuilds((current) => {
      if (current.some((row) => row.id === buildId)) {
        return current.map(apply);
      }
      return [apply({ ...detail.data, logs: [] }), ...current];
    });
    setBuild((current) => (current?.id === buildId ? apply(current) : current));
  }, []);

  useEffect(() => {
    if (!id) {
      return;
    }
    refreshWorkspace().catch((err: unknown) => setError(err instanceof Error ? err.message : 'Failed to open'));
    void refreshTree().then(refreshGit).catch((err: unknown) => {
      setError(err instanceof Error ? err.message : 'Failed to load repository');
    });
    api
      .listBuilds(id)
      .then((result) => {
        const items = result.data.map((item) => ({ ...item, logs: item.logs ?? [] }));
        setBuilds(items);
        const latest = items[0] ?? null;
        setBuild(latest);
        if (latest) {
          void hydrateBuild(latest.id, true).catch((err: unknown) => {
            setError(err instanceof Error ? err.message : 'Failed to load build logs');
          });
        }
      })
      .catch((err: unknown) => setError(err instanceof Error ? err.message : 'Failed to load builds'));
    api.queue().then((result) => setQueue(result.data)).catch(() => undefined);
  }, [id, refreshGit, refreshTree, refreshWorkspace, hydrateBuild]);

  const runningKey = builds
    .filter((item) => ['queued', 'running'].includes(item.status))
    .map((item) => item.id)
    .sort()
    .join(',');

  useEffect(() => {
    if (!runningKey) {
      return;
    }
    const ids = runningKey.split(',');
    const timer = window.setInterval(() => {
      for (const buildId of ids) {
        void hydrateBuild(buildId, false).catch(() => undefined);
      }
      void api.queue().then((result) => setQueue(result.data));
    }, 1500);
    return () => window.clearInterval(timer);
  }, [runningKey, hydrateBuild]);

  async function openFile(path: string) {
    try {
      await editors.openFile(path);
      setNotice(null);
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to open file');
    }
  }

  async function saveDirtyFiles() {
    if (!id) {
      return;
    }
    const dirtyFiles = editors.files.filter((file) => file.content !== file.original);
    for (const file of dirtyFiles) {
      await api.writeFile(id, file.path, file.content);
      editors.markSaved(file.path, file.content);
    }
  }

  async function onSave() {
    if (!id || !editors.active) {
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await api.writeFile(id, editors.active.path, editors.active.content);
      editors.markSaved(editors.active.path, editors.active.content);
      setNotice(`Saved ${editors.active.path}`);
      await refreshGit();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Save failed');
    } finally {
      setBusy(false);
    }
  }

  async function onCommit(event: FormEvent) {
    event.preventDefault();
    if (!id) {
      return;
    }
    if (dirty) {
      await saveDirtyFiles();
    }
    setBusy(true);
    try {
      const result = await api.gitCommit(id, message);
      setMessage('');
      setNotice(`Committed ${result.data.sha.slice(0, 8)}`);
      await refreshGit();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Commit failed');
    } finally {
      setBusy(false);
    }
  }

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();
        void onSave();
      }
    }
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  });

  async function onDeleteBuild(item: Build) {
    if (['queued', 'running'].includes(item.status)) {
      setError('Cancel this build before deleting it.');
      return;
    }
    if (!window.confirm(`Delete this ${item.type} build (${item.status})? Logs and artifacts will be removed.`)) {
      return;
    }
    try {
      await api.deleteBuild(item.id);
      const remaining = builds.filter((row) => row.id !== item.id);
      setBuilds(remaining);
      setBuild((current) => (current?.id === item.id ? remaining[0] ?? null : current));
      setNotice('Build record deleted');
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to delete build');
    }
  }

  async function onBuild(type?: string) {
    if (!id || !canBuild) {
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const result = await api.startBuild(id, type);
      const queued = { ...result.data, logs: result.data.logs ?? [] };
      logCursor.current[queued.id] = 0;
      setBuild(queued);
      setBuilds((current) => [queued, ...current.filter((item) => item.id !== queued.id)]);
      setTab('build');
      setNotice(`Queued ${queued.package_name} (${queued.type})`);
      const q = await api.queue();
      setQueue(q.data);
      await hydrateBuild(queued.id, true);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Build failed');
    } finally {
      setBusy(false);
    }
  }

  if (error && !workspace) {
    return (
      <main className="app-shell">
        <p className="error">{error}</p>
        <Link to="/">Back to workspaces</Link>
      </main>
    );
  }

  if (!workspace || !id) {
    return (
      <main className="app-shell">
        <p className="muted">Opening workspace…</p>
      </main>
    );
  }

  return (
    <div className="ide-root">
      <header className="ide-topbar">
        <div className="ide-brand">
          <Link to="/">Workspaces</Link>
          <strong>{workspace.name}</strong>
          <span className="pill">{workspace.type}</span>
          <span className="pill">{workspace.target}/{workspace.profile}</span>
          <span className="pill">{status?.branch ?? '…'}</span>
          {dirty && <span className="pill">{editors.dirtyCount > 1 ? `${editors.dirtyCount} unsaved` : 'unsaved'}</span>}
          {status && !status.clean && <span className="pill">uncommitted</span>}
        </div>
        <form className="ide-git" onSubmit={onCommit}>
          <input
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            placeholder="Commit message"
            minLength={3}
            required
          />
          <button type="button" className="ghost" onClick={() => void onSave()} disabled={!editors.active || editors.active.content === editors.active.original || busy}>
            Save
          </button>
          <button type="submit" disabled={busy || (status?.clean && !dirty)}>
            Commit
          </button>
          {workspace.type !== 'firmware' && (
            <button type="button" className="ghost" disabled={busy || !canBuild} title={buildHint} onClick={() => void onBuild()}>
              Build IPK
            </button>
          )}
          <button type="button" className="ghost" disabled={busy || !canBuild} title={buildHint} onClick={() => void onBuild('firmware')}>
            Build firmware
          </button>
        </form>
      </header>

      {(error || notice) && (
        <div className="ide-banner">
          {error && <span className="error">{error}</span>}
          {notice && <span className="muted">{notice}</span>}
        </div>
      )}

      <div
        className="ide-body"
        ref={bodyRef}
        style={{ gridTemplateColumns: `${layout.tree}px 5px minmax(0, 1fr) 5px ${layout.ai}px` }}
      >
        <aside className="ide-tree-col">
          <div className="ide-tree">
            <p className="eyebrow">Project</p>
            <FileTree nodes={tree} activePath={editors.activePath} onOpen={(path) => void openFile(path)} />
          </div>
          <SplitHandle
            axis="y"
            invert
            label="Resize build files"
            onDrag={(delta) => nudge('artifacts', delta)}
            onReset={() => apply({ artifacts: defaults.artifacts })}
          />
          <div className="ide-build-files-wrap" style={{ height: layout.artifacts }}>
            <BuildFilesPane
              builds={builds}
              selectedId={build?.id}
              onSelect={(item) => {
                setBuild(item);
                void hydrateBuild(item.id, (item.logs?.length ?? 0) === 0).catch((err: unknown) => {
                  setError(err instanceof Error ? err.message : 'Failed to load build logs');
                });
              }}
            />
          </div>
        </aside>
        <SplitHandle
          axis="x"
          label="Resize project tree"
          onDrag={(delta) => nudge('tree', delta)}
          onReset={() => apply({ tree: defaults.tree })}
        />
        <section className="ide-main">
          <div className="ide-editor">
            <EditorTabs
              files={editors.files}
              activePath={editors.activePath}
              onActivate={editors.activate}
              onClose={editors.closeFile}
            />
            <div className="ide-editor-body">
              {editors.active ? (
                <Editor
                  theme="vs-dark"
                  path={editors.active.path}
                  language={languageFor(editors.active.path)}
                  value={editors.active.content}
                  keepCurrentModel
                  onChange={(value) => editors.updateContent(editors.active!.path, value ?? '')}
                  options={{
                    minimap: { enabled: false },
                    fontSize: 13,
                    automaticLayout: true,
                    scrollBeyondLastLine: false,
                  }}
                />
              ) : (
                <div className="ide-empty">Select a file in the project tree. Open several files to edit them in tabs.</div>
              )}
            </div>
          </div>
          <SplitHandle
            axis="y"
            invert
            label="Resize bottom panel"
            onDrag={(delta) => nudge('bottom', delta)}
            onReset={() => apply({ bottom: defaults.bottom })}
          />
          <div className="ide-bottom" style={{ height: layout.bottom }}>
            <div className="ide-tabs">
              <button type="button" className={tab === 'terminal' ? 'active' : ''} onClick={() => setTab('terminal')}>
                Terminal
              </button>
              <button type="button" className={tab === 'diff' ? 'active' : ''} onClick={() => setTab('diff')}>
                Diff
              </button>
              <button type="button" className={tab === 'log' ? 'active' : ''} onClick={() => setTab('log')}>
                Git log
              </button>
              <button type="button" className={tab === 'config' ? 'active' : ''} onClick={() => setTab('config')}>
                Config
              </button>
              <button type="button" className={tab === 'remote' ? 'active' : ''} onClick={() => setTab('remote')}>
                Git remote
              </button>
              <button type="button" className={tab === 'build' ? 'active' : ''} onClick={() => setTab('build')}>
                Build
              </button>
            </div>
            <div className="ide-bottom-body">
              {tab === 'terminal' && <TerminalPane workspaceId={id} />}
              {tab === 'diff' && <pre className="diff-view">{diff || 'Working tree clean.'}</pre>}
              {tab === 'log' && (
                <ul className="git-log">
                  {log.map((item) => (
                    <li key={item.sha}>
                      <code>{item.sha.slice(0, 8)}</code>
                      <span>{item.message}</span>
                      <span className="muted">{item.author}</span>
                    </li>
                  ))}
                </ul>
              )}
              {tab === 'config' && (
                <ConfigPane
                  workspace={workspace}
                  onChanged={async () => {
                    await refreshWorkspace();
                    await refreshTree();
                    await refreshGit();
                  }}
                />
              )}
              {tab === 'remote' && (
                <GitRemotePane
                  workspace={workspace}
                  builds={builds}
                  onChanged={async () => {
                    await refreshWorkspace();
                    await refreshTree();
                    await refreshGit();
                  }}
                />
              )}
              {tab === 'build' && (
                <BuildPane
                  builds={builds}
                  selected={build}
                  queue={queue}
                  onSelect={(item) => {
                    setBuild(item);
                    void hydrateBuild(item.id, (item.logs?.length ?? 0) === 0).catch((err: unknown) => {
                      setError(err instanceof Error ? err.message : 'Failed to load build logs');
                    });
                  }}
                  onDelete={onDeleteBuild}
                />
              )}
            </div>
          </div>
        </section>
        <SplitHandle
          axis="x"
          invert
          label="Resize AI Assistant"
          onDrag={(delta) => nudge('ai', delta)}
          onReset={() => apply({ ai: defaults.ai })}
        />
        <AiPanel
          workspaceId={id}
          onApplied={async () => {
            await refreshTree();
            await refreshGit();
            await editors.reloadCleanFromDisk();
          }}
        />
      </div>
    </div>
  );
}

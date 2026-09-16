import { FormEvent, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, type DevicePlatform, type Workspace } from '../api';
import { useAuth } from '../auth';
import { DevicePicker } from '../ide/DevicePicker';

const TYPES: Workspace['type'][] = ['app', 'theme', 'sdk', 'firmware'];

export function DashboardPage() {
  const { user, logout } = useAuth();
  const [items, setItems] = useState<Workspace[]>([]);
  const [name, setName] = useState('');
  const [type, setType] = useState<Workspace['type']>('app');
  const [revision, setRevision] = useState('24.10');
  const [platforms, setPlatforms] = useState<DevicePlatform[]>([]);
  const [platformKey, setPlatformKey] = useState('x86/64');
  const [profile, setProfile] = useState('generic');
  const [gitUrl, setGitUrl] = useState('');
  const [gitBranch, setGitBranch] = useState('main');
  const [gitToken, setGitToken] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const [health, setHealth] = useState<string>('checking');

  async function refresh() {
    const result = await api.listWorkspaces();
    setItems(result.data);
  }

  useEffect(() => {
    refresh().catch((err: unknown) => setError(err instanceof Error ? err.message : 'Failed to load'));
    api.devices().then((result) => {
      setPlatforms(result.platforms ?? []);
      const first = result.platforms?.[0];
      if (first) {
        setPlatformKey(`${first.target}/${first.subtarget}`);
        setProfile(first.devices[0]?.profile ?? 'generic');
      }
    }).catch(() => undefined);
    api
      .health()
      .then((h) => {
        const minio = h.checks.minio?.ok === false ? ' · minio down' : '';
        const queue = h.checks.queue ? ` · queue ${h.checks.queue.running ?? 0} run` : '';
        const openwrt = h.checks.openwrt?.ok === false ? ' · OpenWrt source not ready' : ' · Build System ready';
        setHealth((h.ok ? `gateway ${h.checks.ai_gateway.url}` : 'degraded') + minio + queue + openwrt);
      })
      .catch(() => setHealth('unreachable'));
  }, []);

  async function onCreate(event: FormEvent) {
    event.preventDefault();
    setPending(true);
    setError(null);
    try {
      const [target, subtarget] = platformKey.split('/');
      await api.createWorkspace({
        name,
        type,
        openwrt_revision: revision,
        target,
        subtarget,
        profile,
        git_url: gitUrl.trim() || undefined,
        git_branch: gitUrl.trim() ? gitBranch.trim() || 'main' : undefined,
        git_token: gitToken.trim() || undefined,
      });
      setName('');
      setGitUrl('');
      setGitToken('');
      await refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Create failed');
    } finally {
      setPending(false);
    }
  }

  async function onDelete(id: string) {
    if (!window.confirm('Delete this workspace and its files?')) {
      return;
    }
    await api.deleteWorkspace(id);
    await refresh();
  }

  async function onClone(id: string, sourceName: string) {
    const next = window.prompt('Clone as', `${sourceName}-copy`);
    if (!next) {
      return;
    }
    await api.cloneWorkspace(id, next);
    await refresh();
  }

  return (
    <div className="app-shell">
      <header className="topbar">
        <div>
          <p className="eyebrow">OpenWrt AI Studio</p>
          <h1>Workspaces</h1>
        </div>
        <div className="topbar-meta">
          <span className="muted">{user?.email}</span>
          <span className="pill">{user?.role}</span>
          {user?.role === 'admin' && <Link to="/settings">Settings</Link>}
          <button type="button" className="ghost" onClick={() => void logout()}>
            Sign out
          </button>
        </div>
      </header>

      <p className="muted health">Control plane: {health}</p>

      <section className="create-panel">
        <h2>New workspace</h2>
        <form className="create-form" onSubmit={onCreate}>
          <label>
            Name
            <input value={name} onChange={(e) => setName(e.target.value)} required maxLength={80} placeholder="luci-app-demo" />
          </label>
          <label>
            Type
            <select value={type} onChange={(e) => setType(e.target.value as Workspace['type'])}>
              {TYPES.map((item) => (
                <option key={item} value={item}>
                  {item}
                </option>
              ))}
            </select>
          </label>
          <label>
            OpenWrt
            <input value={revision} onChange={(e) => setRevision(e.target.value)} required maxLength={64} />
          </label>
          <button type="submit" disabled={pending}>
            {pending ? 'Creating…' : 'Create'}
          </button>
          <DevicePicker
            platforms={platforms}
            platformKey={platformKey}
            profile={profile}
            onChange={(nextPlatform, nextProfile) => {
              setPlatformKey(nextPlatform);
              setProfile(nextProfile);
              const next = platforms.find((item) => `${item.target}/${item.subtarget}` === nextPlatform);
              if (next?.revision) {
                setRevision(next.revision);
              } else if (revision.startsWith('mtk-')) {
                setRevision('24.10');
              }
            }}
          />
          <label className="git-import">
            Import from Git URL
            <input
              value={gitUrl}
              onChange={(e) => setGitUrl(e.target.value)}
              placeholder="https://github.com/org/luci-app-demo.git (optional)"
            />
          </label>
          {gitUrl.trim() !== '' && (
            <>
              <label>
                Git branch
                <input value={gitBranch} onChange={(e) => setGitBranch(e.target.value)} placeholder="main" />
              </label>
              <label>
                Git token
                <input
                  type="password"
                  value={gitToken}
                  onChange={(e) => setGitToken(e.target.value)}
                  placeholder="Optional PAT for private repos"
                  autoComplete="off"
                />
              </label>
            </>
          )}
        </form>
        {error && <p className="error">{error}</p>}
      </section>

      <section className="grid">
        {items.length === 0 && <p className="muted">No workspaces yet. Create one to start M1.</p>}
        {items.map((item) => (
          <article key={item.id} className="card">
            <div className="card-head">
              <h3>{item.name}</h3>
              <span className="pill">{item.type}</span>
            </div>
            <p className="muted">{item.openwrt_revision} · {item.target}/{item.profile} · {item.status}</p>
            {item.git_remote_url && <p className="path">{item.git_remote_url}</p>}
            <p className="path">{item.path}</p>
            <div className="card-actions">
              <Link to={`/workspaces/${item.id}`}>Open</Link>
              <button type="button" className="ghost" onClick={() => void onClone(item.id, item.name)}>
                Clone
              </button>
              <button type="button" className="danger" onClick={() => void onDelete(item.id)}>
                Delete
              </button>
            </div>
          </article>
        ))}
      </section>
    </div>
  );
}

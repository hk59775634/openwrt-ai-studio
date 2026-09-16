import { FormEvent, useEffect, useMemo, useState } from 'react';
import { api, type Build, type BuildArtifact, type Workspace } from '../api';
import { formatArtifactSize } from './downloadArtifact';

type Props = {
  workspace: Workspace;
  builds: Build[];
  onChanged: () => Promise<void>;
};

type SelectableFile = BuildArtifact & {
  buildId: string;
  buildType: string;
  createdAt: string;
};

function isIpk(name: string) {
  return name.toLowerCase().endsWith('.ipk');
}

function isFirmware(name: string) {
  const lower = name.toLowerCase();
  if (lower.includes('kernel.bin') || lower.includes('vmlinux')) {
    return false;
  }
  return /sysupgrade\.bin$/i.test(lower) || /\.(img\.gz|img|itb|bin)$/i.test(lower);
}

export function GitRemotePane({ workspace, builds, onChanged }: Props) {
  const [url, setUrl] = useState(workspace.git_remote_url ?? '');
  const [branch, setBranch] = useState(workspace.git_remote_branch ?? 'main');
  const [token, setToken] = useState('');
  const [tag, setTag] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [selected, setSelected] = useState<number[]>([]);

  useEffect(() => {
    setUrl(workspace.git_remote_url ?? '');
    setBranch(workspace.git_remote_branch ?? 'main');
  }, [workspace.git_remote_url, workspace.git_remote_branch]);

  const files = useMemo(() => {
    const rows: SelectableFile[] = [];
    for (const item of builds) {
      if (item.status !== 'success') {
        continue;
      }
      for (const artifact of item.artifacts) {
        if (!isIpk(artifact.name) && !isFirmware(artifact.name)) {
          continue;
        }
        rows.push({
          ...artifact,
          buildId: item.id,
          buildType: item.type,
          createdAt: item.finished_at ?? item.created_at,
        });
      }
    }
    rows.sort((a, b) => b.createdAt.localeCompare(a.createdAt) || b.id - a.id);
    return rows;
  }, [builds]);

  const ipks = files.filter((file) => isIpk(file.name));
  const firmware = files.filter((file) => isFirmware(file.name));

  useEffect(() => {
    setSelected((current) => {
      const available = new Set(files.map((file) => file.id));
      const kept = current.filter((id) => available.has(id));
      if (kept.length > 0) {
        return kept;
      }
      const next: number[] = [];
      const latestIpk = files.find((file) => isIpk(file.name));
      const latestFw = files.find((file) => isFirmware(file.name));
      if (latestIpk) {
        next.push(latestIpk.id);
      }
      if (latestFw) {
        next.push(latestFw.id);
      }
      return next;
    });
  }, [files]);

  function toggle(id: number) {
    setSelected((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));
  }

  async function bind(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api.bindGitRemote(workspace.id, {
        url,
        branch,
        token: token || undefined,
      });
      setToken('');
      setNotice('Remote bound');
      await onChanged();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to bind remote');
    } finally {
      setBusy(false);
    }
  }

  async function run(action: 'push' | 'pull' | 'release') {
    setBusy(true);
    setError(null);
    try {
      if (action === 'push') {
        const result = await api.gitPush(workspace.id, branch || undefined);
        setNotice(`Pushed ${result.data.sha.slice(0, 8)} to ${result.data.branch}`);
      } else if (action === 'pull') {
        const result = await api.gitPull(workspace.id, branch || undefined);
        const sha = result.data.sha.slice(0, 8);
        const ahead = result.data.ahead ?? 0;
        if (ahead > 0) {
          setNotice(
            `Pulled ${sha}. This workspace is ${ahead} commit(s) ahead of GitHub — click Push to upload. Pull only downloads; it does not publish local commits.`,
          );
        } else {
          setNotice(`Updated to ${sha} from GitHub`);
        }
        await onChanged();
      } else {
        if (!tag.trim()) {
          setError('Release tag is required, for example v1.0.0');
          setBusy(false);
          return;
        }
        if (selected.length === 0) {
          setError('Select at least one IPK or firmware file from Build files.');
          setBusy(false);
          return;
        }
        const result = await api.gitRelease(workspace.id, tag.trim(), undefined, selected);
        const assets = result.data.assets ?? [];
        const published = assets.filter((name) => name !== 'manifest.json');
        let note = result.data.html_url
          ? `GitHub Release ${result.data.tag}: ${result.data.html_url}`
          : `Pushed git tag ${result.data.tag}. This remote is not GitHub, so no Releases page entry was created.`;
        if (published.length > 0) {
          note += ` Files: ${published.join(', ')}`;
        }
        if (assets.includes('manifest.json')) {
          note += ' + manifest.json';
        }
        setNotice(note);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Git remote operation failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="config-pane">
      <p className="eyebrow">External Git</p>
      <form className="git-remote-form" onSubmit={(event) => void bind(event)}>
        <label>
          Repository URL
          <input
            value={url}
            onChange={(event) => setUrl(event.target.value)}
            placeholder="https://github.com/org/luci-app-demo.git"
            required
          />
        </label>
        <label>
          Branch
          <input value={branch} onChange={(event) => setBranch(event.target.value)} placeholder="main" />
        </label>
        <label>
          Access token
          <input
            type="password"
            value={token}
            onChange={(event) => setToken(event.target.value)}
            placeholder={workspace.git_token_set ? 'Saved — leave blank to keep' : 'Optional PAT / deploy token'}
            autoComplete="off"
          />
        </label>
        <button type="submit" disabled={busy}>
          Bind remote
        </button>
      </form>
      <p className="muted">
        Pull downloads from GitHub. Push uploads local commits. Release uploads the checked Build files and always
        generates manifest.json.
      </p>
      <div className="release-assets">
        <fieldset>
          <legend>IPK</legend>
          {ipks.length === 0 && <p className="muted">No IPK in Build files.</p>}
          {ipks.map((file) => (
            <label key={file.id} className="release-file">
              <input type="checkbox" checked={selected.includes(file.id)} onChange={() => toggle(file.id)} />
              <span>
                <span className="build-file-name">{file.name}</span>
                <span className="muted">
                  {' '}
                  {formatArtifactSize(file.size)} · {file.buildType} · {file.createdAt.slice(0, 16).replace('T', ' ')}
                </span>
              </span>
            </label>
          ))}
        </fieldset>
        <fieldset>
          <legend>Firmware</legend>
          {firmware.length === 0 && <p className="muted">No sysupgrade.bin / image in Build files.</p>}
          {firmware.map((file) => (
            <label key={file.id} className="release-file">
              <input type="checkbox" checked={selected.includes(file.id)} onChange={() => toggle(file.id)} />
              <span>
                <span className="build-file-name">{file.name}</span>
                <span className="muted">
                  {' '}
                  {formatArtifactSize(file.size)} · {file.buildType} · {file.createdAt.slice(0, 16).replace('T', ' ')}
                </span>
              </span>
            </label>
          ))}
        </fieldset>
      </div>
      <div className="row">
        <button type="button" className="ghost" disabled={busy || !url} onClick={() => void run('pull')}>
          Pull from GitHub
        </button>
        <button type="button" className="ghost" disabled={busy || !url} onClick={() => void run('push')}>
          Push to GitHub
        </button>
        <label>
          Release tag
          <input value={tag} onChange={(event) => setTag(event.target.value)} placeholder="v1.0.0" />
        </label>
        <button type="button" disabled={busy || !url} onClick={() => void run('release')}>
          Release
        </button>
      </div>
      {workspace.git_remote_url && (
        <p className="muted">
          Bound to {workspace.git_remote_url} ({workspace.git_remote_branch || 'main'})
        </p>
      )}
      {notice && <p className="muted">{notice}</p>}
      {error && <p className="error">{error}</p>}
    </div>
  );
}

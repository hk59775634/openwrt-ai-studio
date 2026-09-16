import { useEffect, useMemo, useState } from 'react';
import { api, type ConfigSnapshot, type DevicePlatform, type DevicePreset, type Workspace } from '../api';
import { DevicePicker } from './DevicePicker';

type Props = {
  workspace: Workspace;
  onChanged: () => Promise<void>;
};

export function ConfigPane({ workspace, onChanged }: Props) {
  const [platforms, setPlatforms] = useState<DevicePlatform[]>([]);
  const [snapshots, setSnapshots] = useState<ConfigSnapshot[]>([]);
  const [targetKey, setTargetKey] = useState(`${workspace.target ?? 'x86'}/${workspace.subtarget ?? '64'}`);
  const [profile, setProfile] = useState(workspace.profile ?? 'generic');
  const [name, setName] = useState('default');
  const [left, setLeft] = useState<number | ''>('');
  const [right, setRight] = useState<number | ''>('');
  const [diff, setDiff] = useState('');
  const [error, setError] = useState<string | null>(null);

  async function refresh() {
    const [devices, configs] = await Promise.all([api.devices(), api.listConfigs(workspace.id)]);
    setPlatforms(devices.platforms ?? []);
    setSnapshots(configs.data);
  }

  useEffect(() => {
    void refresh().catch((err: unknown) => setError(err instanceof Error ? err.message : 'Failed to load config'));
  }, [workspace.id]);

  const platform = useMemo(
    () => platforms.find((item) => `${item.target}/${item.subtarget}` === targetKey) ?? platforms[0],
    [platforms, targetKey],
  );
  const devices = platform?.devices ?? [];
  const preset: DevicePreset | undefined = platform
    ? {
        target: platform.target,
        subtarget: platform.subtarget,
        profile,
        arch: platform.arch,
        label: `${platform.label} · ${devices.find((item) => item.profile === profile)?.label ?? profile}`,
      }
    : undefined;

  async function generate() {
    if (!preset) {
      return;
    }
    setError(null);
    await api.generateConfig(workspace.id, preset);
    await onChanged();
    await refresh();
  }

  async function save() {
    if (!preset) {
      return;
    }
    setError(null);
    await api.saveConfig(workspace.id, { name, ...preset });
    await onChanged();
    await refresh();
  }

  async function apply(id: number) {
    setError(null);
    await api.applyConfig(workspace.id, id);
    await onChanged();
    await refresh();
  }

  async function remove(item: ConfigSnapshot) {
    if (!window.confirm(`Delete snapshot “${item.name}”? This cannot be undone.`)) {
      return;
    }
    setError(null);
    try {
      await api.deleteConfig(workspace.id, item.id);
      if (left === item.id) {
        setLeft('');
      }
      if (right === item.id) {
        setRight('');
      }
      setDiff('');
      await refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to delete snapshot');
    }
  }

  async function compare() {
    if (left === '' || right === '') {
      return;
    }
    const result = await api.compareConfigs(workspace.id, left, right);
    const added = result.data.added.map((line) => `+ ${line}`).join('\n');
    const removed = result.data.removed.map((line) => `- ${line}`).join('\n');
    setDiff(`${removed}\n${added}`.trim() || 'No differences.');
  }

  return (
    <div className="config-pane">
      <DevicePicker
        platforms={platforms}
        platformKey={targetKey}
        profile={profile}
        onChange={(nextPlatform, nextProfile) => {
          setTargetKey(nextPlatform);
          setProfile(nextProfile);
        }}
      />
      <div className="row">
        <label>
          Snapshot name
          <input value={name} onChange={(e) => setName(e.target.value)} />
        </label>
        <button type="button" className="ghost" onClick={() => void generate()}>
          Write .config
        </button>
        <button type="button" onClick={() => void save()}>
          Save snapshot
        </button>
      </div>
      {platform && (
        <p className="muted">
          {platform.chip ? `${platform.chip} · ` : ''}
          {platform.vendor
            ? `Vendor SDK ${platform.source ?? ''} (${platform.arch})`
            : `IPK and firmware both compile from /data/openwrt (${platform.arch})`}
        </p>
      )}
      {error && <p className="error">{error}</p>}
      <ul className="config-snap">
        {snapshots.map((item) => (
          <li key={item.id}>
            <span>
              <strong>{item.name}</strong>{' '}
              <span className="muted">
                {item.target}/{item.subtarget}/{item.profile}
              </span>
            </span>
            <span className="config-snap-actions">
              <button type="button" className="ghost" onClick={() => void apply(item.id)}>
                Apply
              </button>
              <button type="button" className="danger" onClick={() => void remove(item)}>
                Delete
              </button>
            </span>
          </li>
        ))}
      </ul>
      {snapshots.length >= 2 && (
        <div className="row">
          <label>
            Compare
            <select value={left} onChange={(e) => setLeft(e.target.value ? Number(e.target.value) : '')}>
              <option value="">left</option>
              {snapshots.map((item) => (
                <option key={`l${item.id}`} value={item.id}>
                  {item.name}
                </option>
              ))}
            </select>
          </label>
          <label>
            vs
            <select value={right} onChange={(e) => setRight(e.target.value ? Number(e.target.value) : '')}>
              <option value="">right</option>
              {snapshots.map((item) => (
                <option key={`r${item.id}`} value={item.id}>
                  {item.name}
                </option>
              ))}
            </select>
          </label>
          <button type="button" className="ghost" onClick={() => void compare()}>
            Diff
          </button>
        </div>
      )}
      {diff && <pre className="config-diff">{diff}</pre>}
    </div>
  );
}

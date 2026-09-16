import { useMemo, useState } from 'react';
import type { DevicePlatform } from '../api';

type Props = {
  platforms: DevicePlatform[];
  platformKey: string;
  profile: string;
  onChange: (platformKey: string, profile: string) => void;
};

function keyOf(platform: DevicePlatform): string {
  return `${platform.target}/${platform.subtarget}`;
}

function haystack(platform: DevicePlatform, device?: { profile: string; label: string }): string {
  return [
    platform.target,
    platform.subtarget,
    platform.label,
    platform.chip ?? '',
    platform.arch,
    platform.vendor ? 'vendor mtk sdk' : '',
    device?.profile ?? '',
    device?.label ?? '',
  ]
    .join(' ')
    .toLowerCase();
}

function needles(query: string): string[] {
  const n = query.trim().toLowerCase();
  if (!n) {
    return [];
  }
  const extras = /^mt\d+b$/.test(n) ? [n.slice(0, -1)] : [];
  return [n, ...extras];
}

function includesQuery(hay: string, query: string): boolean {
  return needles(query).some((n) => hay.includes(n));
}

function optionLabel(platform: DevicePlatform): string {
  const chip = platform.chip ? ` · ${platform.chip}` : '';
  const vendor = platform.vendor ? ' [Vendor SDK]' : '';
  return `${platform.label}${chip}${vendor} (${platform.devices.length})`;
}

export function DevicePicker({ platforms, platformKey, profile, onChange }: Props) {
  const [query, setQuery] = useState('');
  const needle = query.trim().toLowerCase();

  const matches = useMemo(() => {
    if (!needle) {
      return [];
    }
    const hits: { key: string; platform: DevicePlatform; profile: string; label: string; score: number }[] = [];
    for (const platform of platforms) {
      for (const device of platform.devices) {
        const deviceText = `${device.profile} ${device.label}`.toLowerCase();
        const score = includesQuery(deviceText, needle) ? 0 : includesQuery(haystack(platform, device), needle) ? 1 : 9;
        if (score === 9) {
          continue;
        }
        hits.push({
          key: `${keyOf(platform)}/${device.profile}`,
          platform,
          profile: device.profile,
          label: `${device.label} · ${platform.label}`,
          score,
        });
      }
    }
    return hits.sort((a, b) => a.score - b.score || a.label.localeCompare(b.label)).slice(0, 20);
  }, [needle, platforms]);

  const visiblePlatforms = useMemo(() => {
    const selected = platforms.find((item) => keyOf(item) === platformKey);
    if (!needle) {
      return platforms;
    }
    const filtered = platforms.filter((item) => {
      if (includesQuery(haystack(item), needle)) {
        return true;
      }
      return item.devices.some((device) => includesQuery(haystack(item, device), needle));
    });
    if (selected && !filtered.some((item) => keyOf(item) === platformKey)) {
      return [selected, ...filtered];
    }
    return filtered;
  }, [needle, platformKey, platforms]);

  const platform = platforms.find((item) => keyOf(item) === platformKey) ?? platforms[0];
  const devices = useMemo(() => {
    const list = platform?.devices ?? [];
    if (!needle) {
      return list;
    }
    const filtered = list.filter((device) => includesQuery(haystack(platform, device), needle));
    if (filtered.some((device) => device.profile === profile) || filtered.length === 0) {
      return filtered.length > 0 ? filtered : list;
    }
    return [{ profile, label: list.find((item) => item.profile === profile)?.label ?? profile }, ...filtered];
  }, [needle, platform, profile]);

  return (
    <div className="device-picker">
      <label>
        Search chip / model
        <input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="MT7981B, AX3000T, filogic…"
        />
      </label>
      <label>
        Platform
        <select
          value={platformKey}
          onChange={(e) => {
            const next = platforms.find((item) => keyOf(item) === e.target.value);
            onChange(e.target.value, next?.devices[0]?.profile ?? 'generic');
          }}
        >
          {visiblePlatforms.map((item) => (
            <option key={keyOf(item)} value={keyOf(item)}>
              {optionLabel(item)}
            </option>
          ))}
        </select>
      </label>
      <label>
        Device
        <select value={profile} onChange={(e) => onChange(platformKey, e.target.value)}>
          {devices.map((item) => (
            <option key={item.profile} value={item.profile}>
              {item.label}
            </option>
          ))}
        </select>
      </label>
      {needle && matches.length > 0 && (
        <div className="device-hits">
          {matches.map((hit) => (
            <button
              key={hit.key}
              type="button"
              className="ghost"
              onClick={() => {
                onChange(keyOf(hit.platform), hit.profile);
                setQuery('');
              }}
            >
              {hit.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

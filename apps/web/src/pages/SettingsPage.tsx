import { FormEvent, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, type StudioSettings } from '../api';
import { useAuth } from '../auth';

const EMPTY: StudioSettings = {
  ai_gateway_url: '',
  ai_gateway_api_key_set: false,
  ai_gateway_model: '',
  ai_gateway_timeout: 300,
  ai_gateway_retries: 3,
  ai_tool_mode: 'auto',
  quota_workspaces: 20,
  quota_builds_per_hour: 20,
};

export function SettingsPage() {
  const { user, logout } = useAuth();
  const [form, setForm] = useState<StudioSettings>(EMPTY);
  const [apiKey, setApiKey] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  useEffect(() => {
    api
      .getSettings()
      .then((result) => setForm(result.data))
      .catch((err: unknown) => setError(err instanceof Error ? err.message : 'Failed to load settings'));
  }, []);

  async function onSave(event: FormEvent) {
    event.preventDefault();
    setPending(true);
    setError(null);
    setNotice(null);
    try {
      const result = await api.updateSettings({
        ai_gateway_url: form.ai_gateway_url,
        ai_gateway_api_key: apiKey || undefined,
        ai_gateway_model: form.ai_gateway_model,
        ai_gateway_timeout: form.ai_gateway_timeout,
        ai_gateway_retries: form.ai_gateway_retries,
        ai_tool_mode: form.ai_tool_mode,
        quota_workspaces: form.quota_workspaces,
        quota_builds_per_hour: form.quota_builds_per_hour,
      });
      setForm(result.data);
      setApiKey('');
      setNotice('Settings saved. New AI requests use these values immediately.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Save failed');
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="app-shell">
      <header className="topbar">
        <div>
          <p className="eyebrow">OpenWrt AI Studio</p>
          <h1>Global settings</h1>
        </div>
        <div className="topbar-meta">
          <Link to="/">Workspaces</Link>
          <span className="muted">{user?.email}</span>
          <button type="button" className="ghost" onClick={() => void logout()}>
            Sign out
          </button>
        </div>
      </header>

      <section className="create-panel settings-panel">
        <p className="muted">
          These values override environment defaults. The API key is stored encrypted and never shown again.
        </p>
        <form className="settings-form" onSubmit={(event) => void onSave(event)}>
          <label>
            AI API URL
            <input
              value={form.ai_gateway_url}
              onChange={(event) => setForm({ ...form, ai_gateway_url: event.target.value })}
              placeholder="https://api.example.com"
              required
            />
          </label>
          <label>
            AI API key
            <input
              type="password"
              value={apiKey}
              onChange={(event) => setApiKey(event.target.value)}
              placeholder={form.ai_gateway_api_key_set ? 'Saved — leave blank to keep' : 'sk-…'}
              autoComplete="off"
            />
          </label>
          <label>
            Default model
            <input
              value={form.ai_gateway_model}
              onChange={(event) => setForm({ ...form, ai_gateway_model: event.target.value })}
              placeholder="auto"
            />
          </label>
          <label>
            AI timeout (seconds)
            <input
              type="number"
              min={10}
              max={300}
              value={form.ai_gateway_timeout}
              onChange={(event) => setForm({ ...form, ai_gateway_timeout: Number(event.target.value) })}
            />
          </label>
          <label>
            AI retries
            <input
              type="number"
              min={0}
              max={10}
              value={form.ai_gateway_retries}
              onChange={(event) => setForm({ ...form, ai_gateway_retries: Number(event.target.value) })}
            />
          </label>
          <p className="muted">
            Extra attempts after upstream 502/503/504 or timeout. 0 means fail immediately. The agent keeps the same turn, so you do not need to send Continue.
          </p>
          <label>
            Tool mode
            <select
              value={form.ai_tool_mode}
              onChange={(event) => setForm({ ...form, ai_tool_mode: event.target.value as StudioSettings['ai_tool_mode'] })}
            >
              <option value="auto">auto</option>
              <option value="openai">openai</option>
              <option value="json">json</option>
            </select>
          </label>
          <label>
            Workspace quota
            <input
              type="number"
              min={1}
              max={500}
              value={form.quota_workspaces}
              onChange={(event) => setForm({ ...form, quota_workspaces: Number(event.target.value) })}
            />
          </label>
          <label>
            Builds per hour
            <input
              type="number"
              min={1}
              max={500}
              value={form.quota_builds_per_hour}
              onChange={(event) => setForm({ ...form, quota_builds_per_hour: Number(event.target.value) })}
            />
          </label>
          <button type="submit" disabled={pending}>
            {pending ? 'Saving…' : 'Save settings'}
          </button>
        </form>
        {notice && <p className="muted">{notice}</p>}
        {error && <p className="error">{error}</p>}
      </section>
    </div>
  );
}

import { FormEvent, KeyboardEvent, MouseEvent, useEffect, useRef, useState } from 'react';
import { api, type AiSession, type AiSessionSummary } from '../api';

type Props = {
  workspaceId: string;
  onApplied: () => Promise<void>;
};

function formatWhen(iso?: string): string {
  if (!iso) {
    return '';
  }
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) {
    return '';
  }
  return date.toLocaleString();
}

function IconHistory() {
  return (
    <svg viewBox="0 0 16 16" width="15" height="15" aria-hidden="true">
      <path
        fill="currentColor"
        d="M8 1.5a6.5 6.5 0 1 0 6.4 7.6h-1.52A5 5 0 1 1 8 3v2.25L11.2 3.7 8 1.5Z"
      />
      <path fill="currentColor" d="M8.75 8V5H7.25v4.25h4V8H8.75Z" />
    </svg>
  );
}

function IconPlus() {
  return (
    <svg viewBox="0 0 16 16" width="15" height="15" aria-hidden="true">
      <path fill="currentColor" d="M8.75 2.5h-1.5v5h-5v1.5h5v5h1.5v-5h5V7.5h-5v-5Z" />
    </svg>
  );
}

function IconSend() {
  return (
    <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
      <path fill="currentColor" d="M8 2.2 3.2 7h2.55v6.8h4.5V7H12.8L8 2.2Z" />
    </svg>
  );
}

function IconStop() {
  return (
    <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
      <rect fill="currentColor" x="4" y="4" width="8" height="8" rx="1.2" />
    </svg>
  );
}

export function AiPanel({ workspaceId, onApplied }: Props) {
  const [session, setSession] = useState<AiSession | null>(null);
  const [history, setHistory] = useState<AiSessionSummary[]>([]);
  const [showHistory, setShowHistory] = useState(false);
  const [models, setModels] = useState<string[]>([]);
  const [model, setModel] = useState('');
  const [draft, setDraft] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const scroller = useRef<HTMLDivElement>(null);
  const onAppliedRef = useRef(onApplied);
  onAppliedRef.current = onApplied;

  async function refreshHistory() {
    const existing = await api.aiSessions(workspaceId);
    setHistory(existing.data);
    return existing.data;
  }

  useEffect(() => {
    let cancelled = false;
    async function boot() {
      try {
        const catalog = await api.aiModels();
        if (cancelled) {
          return;
        }
        setModels(catalog.data);
        const existing = await api.aiSessions(workspaceId);
        if (cancelled) {
          return;
        }
        setHistory(existing.data);
        const latest = existing.data[0];
        if (latest) {
          const full = await api.getAiSession(latest.id);
          if (!cancelled) {
            setModel(full.data.model);
            setSession(full.data);
            setBusy(full.data.status === 'running');
          }
          return;
        }
        const chosen = catalog.default || catalog.data[0] || '';
        setModel(chosen);
        const created = await api.createAiSession(workspaceId, chosen || undefined);
        if (!cancelled) {
          setSession(created.data);
          setHistory((current) => [
            {
              id: created.data.id,
              model: created.data.model,
              agent_type: created.data.agent_type,
              status: created.data.status,
              created_at: created.data.created_at,
              updated_at: created.data.updated_at,
              preview: 'New chat',
            },
            ...current.filter((item) => item.id !== created.data.id),
          ]);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Failed to start AI session');
        }
      }
    }
    void boot();
    return () => {
      cancelled = true;
    };
  }, [workspaceId]);

  useEffect(() => {
    scroller.current?.scrollTo({ top: scroller.current.scrollHeight });
  }, [session?.messages, busy, showHistory]);

  useEffect(() => {
    if (!session || session.status !== 'running') {
      return;
    }
    setBusy(true);
    let cancelled = false;
    async function tick(sessionId: string) {
      try {
        const full = await api.getAiSession(sessionId);
        if (cancelled) {
          return;
        }
        setSession(full.data);
        if (full.data.status !== 'running') {
          setBusy(false);
          await onAppliedRef.current();
          await refreshHistory().catch(() => undefined);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Failed to refresh agent');
        }
      }
    }
    const timer = window.setInterval(() => void tick(session.id), 900);
    return () => {
      cancelled = true;
      window.clearInterval(timer);
    };
  }, [session?.id, session?.status, workspaceId]);

  async function sendDraft() {
    if (!session || !draft.trim() || busy) {
      return;
    }
    setBusy(true);
    setError(null);
    const content = draft.trim();
    setDraft('');
    try {
      const result = await api.sendAiMessage(session.id, content, model || undefined);
      setSession(result.data);
      await refreshHistory().catch(() => undefined);
      if (result.data.status !== 'running') {
        await onAppliedRef.current();
        setBusy(false);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Agent request failed');
      setBusy(false);
    }
  }

  async function onSend(event: FormEvent) {
    event.preventDefault();
    await sendDraft();
  }

  function onComposerKey(event: KeyboardEvent<HTMLTextAreaElement>) {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      void sendDraft();
    }
  }

  async function onStop() {
    if (!session || !busy) {
      return;
    }
    setError(null);
    try {
      const result = await api.stopAiSession(session.id);
      setSession(result.data);
      setBusy(false);
      await onAppliedRef.current();
      await refreshHistory().catch(() => undefined);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to stop agent');
    }
  }

  async function openSession(id: string) {
    if (busy && session?.id !== id) {
      return;
    }
    setError(null);
    try {
      const full = await api.getAiSession(id);
      setModel(full.data.model);
      setSession(full.data);
      setBusy(full.data.status === 'running');
      setShowHistory(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to open conversation');
    }
  }

  async function newChat() {
    if (busy) {
      return;
    }
    if (session && session.messages.length === 0) {
      setShowHistory(false);
      return;
    }
    setError(null);
    try {
      const created = await api.createAiSession(workspaceId, model || undefined);
      setSession(created.data);
      setBusy(false);
      setShowHistory(false);
      await refreshHistory();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to start a new chat');
    }
  }

  async function deleteSession(id: string, event: MouseEvent) {
    event.stopPropagation();
    if (busy && session?.id === id) {
      return;
    }
    if (!window.confirm('Delete this conversation?')) {
      return;
    }
    setError(null);
    try {
      await api.deleteAiSession(id);
      const remaining = (await refreshHistory()).filter((item) => item.id !== id);
      if (session?.id !== id) {
        return;
      }
      if (remaining[0]) {
        await openSession(remaining[0].id);
        return;
      }
      const created = await api.createAiSession(workspaceId, model || undefined);
      setSession(created.data);
      setBusy(false);
      await refreshHistory();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to delete conversation');
    }
  }

  const empty = Boolean(session && session.messages.length === 0 && !busy);

  return (
    <aside className="ide-ai">
      <div className="ide-ai-head">
        <div className="ide-ai-head-row">
          <p className="eyebrow">{showHistory ? 'History' : 'Agent'}</p>
          <div className="ide-ai-head-actions">
            <button
              type="button"
              className="ide-ai-iconbtn"
              aria-label="History"
              aria-pressed={showHistory}
              title="History"
              onClick={() => {
                setShowHistory((open) => !open);
                if (!showHistory) {
                  void refreshHistory().catch((err: unknown) => {
                    setError(err instanceof Error ? err.message : 'Failed to load history');
                  });
                }
              }}
            >
              <IconHistory />
            </button>
            <button type="button" className="ide-ai-iconbtn" aria-label="New chat" title="New chat" disabled={busy} onClick={() => void newChat()}>
              <IconPlus />
            </button>
          </div>
        </div>
      </div>
      <div className="ide-ai-log" ref={scroller}>
        {showHistory ? (
          history.length === 0 ? (
            <p className="ai-empty muted">No conversations yet.</p>
          ) : (
            <ul className="ai-history">
              {history.map((item) => {
                const active = item.id === session?.id;
                return (
                  <li key={item.id} className={active ? 'active' : undefined}>
                    <button type="button" className="ai-history-open" disabled={busy && !active} onClick={() => void openSession(item.id)}>
                      <span className="ai-history-preview">{item.preview || 'New chat'}</span>
                      <span className="ai-history-meta">
                        {item.status === 'running' ? 'Running · ' : ''}
                        {formatWhen(item.updated_at)}
                      </span>
                    </button>
                    <button
                      type="button"
                      className="ai-history-delete"
                      disabled={busy && active}
                      aria-label="Delete conversation"
                      title="Delete"
                      onClick={(event) => void deleteSession(item.id, event)}
                    >
                      ×
                    </button>
                  </li>
                );
              })}
            </ul>
          )
        ) : (
          <>
            {!session && <p className="ai-empty muted">Starting isolated session…</p>}
            {empty && <p className="ai-empty muted">Ask the agent to edit this OpenWrt project.</p>}
            {session?.messages.map((message) => {
              const fromUser = message.role === 'user';
              return (
                <div key={message.id} className={`ai-msg ${fromUser ? 'user' : 'assistant'}`}>
                  <span className="ai-msg-role">{fromUser ? 'You' : 'Agent'}</span>
                  <pre>{message.content}</pre>
                </div>
              );
            })}
            {busy && (
              <div className="ai-generating">
                <span className="ai-dots" aria-hidden="true" />
                Generating…
              </div>
            )}
          </>
        )}
      </div>
      {error && <p className="error ai-error">{error}</p>}
      <form className="ide-ai-form" onSubmit={(event) => void onSend(event)}>
          <div className="ide-ai-composer">
            <textarea
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
              onKeyDown={onComposerKey}
              placeholder={busy ? 'Agent is working…' : 'Ask to edit this OpenWrt project'}
              rows={3}
              disabled={!session || showHistory}
            />
            <div className="ide-ai-composer-bar">
              <select value={model} disabled={busy} onChange={(event) => setModel(event.target.value)} aria-label="Model">
                {(models.length ? models : model ? [model] : ['default']).map((item) => (
                  <option key={item} value={item}>
                    {item}
                  </option>
                ))}
              </select>
              {busy ? (
                <button type="button" className="ai-send stop" onClick={() => void onStop()} aria-label="Stop" title="Stop">
                  <IconStop />
                </button>
              ) : (
                <button type="submit" className="ai-send" disabled={!session || showHistory || !draft.trim()} aria-label="Send" title="Send">
                  <IconSend />
                </button>
              )}
            </div>
          </div>
        </form>
    </aside>
  );
}

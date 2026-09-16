import { FormEvent, KeyboardEvent, MouseEvent, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { api, type AiMessage, type AiOperation, type AiSession, type AiSessionSummary } from '../api';
import { MarkdownBody } from './MarkdownBody';

type Props = {
  workspaceId: string;
  onApplied: () => Promise<void>;
};

type TraceKind = 'explore' | 'read' | 'edit' | 'run' | 'other';

type TraceChild = {
  id: string;
  title: string;
  status: string;
};

type TraceItem = {
  id: string;
  kind: TraceKind;
  title: string;
  status: string;
  children?: TraceChild[];
};

type AgentTurn = {
  user: AiMessage;
  steps: TraceItem[];
  replies: AiMessage[];
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

function clip(text: string, max = 72): string {
  const compact = text.replace(/\s+/g, ' ').trim();
  return compact.length > max ? `${compact.slice(0, max - 1)}…` : compact;
}

function operationTarget(op: AiOperation): string {
  const input = op.input ?? {};
  if (typeof input.path === 'string' && input.path.trim() !== '') {
    return input.path.trim();
  }
  if (Array.isArray(input.argv)) {
    return input.argv.map(String).join(' ');
  }
  return '';
}

function operationKind(tool: string): TraceKind {
  if (tool.includes('list_files') || tool.includes('git_diff')) {
    return 'explore';
  }
  if (tool.includes('read_file')) {
    return 'read';
  }
  if (tool.includes('write_file')) {
    return 'edit';
  }
  if (tool.includes('sandbox_exec')) {
    return 'run';
  }
  return 'other';
}

function operationTitle(op: AiOperation): string {
  const target = operationTarget(op);
  const tool = op.tool;
  if (tool.includes('list_files')) {
    return target && target !== '.' ? `Explored ${target}` : 'Explored workspace';
  }
  if (tool.includes('read_file')) {
    return target ? `Read ${target}` : 'Read file';
  }
  if (tool.includes('write_file')) {
    return target ? `Edited ${target}` : 'Edited file';
  }
  if (tool.includes('git_diff')) {
    return target ? `Inspected diff ${target}` : 'Inspected diff';
  }
  if (tool.includes('sandbox_exec')) {
    return target ? `Ran ${clip(target, 64)}` : 'Ran command';
  }
  return tool;
}

function groupOperations(ops: AiOperation[]): TraceItem[] {
  const items: TraceItem[] = [];
  let explore: AiOperation[] = [];

  const flushExplore = () => {
    if (explore.length === 0) {
      return;
    }
    const children = explore.map((op) => ({
      id: String(op.id),
      title: operationTitle(op),
      status: op.status,
    }));
    const failed = explore.some((op) => op.status === 'error');
    items.push({
      id: `explore-${explore[0].id}`,
      kind: 'explore',
      title: explore.length > 1 ? `Explored ${explore.length} files` : children[0].title,
      status: failed ? 'error' : 'ok',
      children: explore.length > 1 ? children : undefined,
    });
    explore = [];
  };

  for (const op of ops) {
    const kind = operationKind(op.tool);
    if (kind === 'explore' || kind === 'read') {
      explore.push(op);
      continue;
    }
    flushExplore();
    items.push({
      id: String(op.id),
      kind,
      title: operationTitle(op),
      status: op.status,
    });
  }
  flushExplore();
  return items;
}

function inWindow(iso: string, start: string, end?: string): boolean {
  return iso >= start && (!end || iso < end);
}

function turnsFromSession(session: AiSession): AgentTurn[] {
  const users = session.messages.filter((message) => message.role === 'user');
  const replies = session.messages.filter((message) => message.role === 'assistant');
  const operations = session.operations ?? [];

  return users.map((user, index) => {
    const next = users[index + 1]?.created_at;
    return {
      user,
      steps: groupOperations(operations.filter((op) => inWindow(op.created_at, user.created_at, next))),
      replies: replies.filter((message) => inWindow(message.created_at, user.created_at, next)),
    };
  });
}

function TraceBlock({
  steps,
  thinking,
  defaultOpen,
  openGroups,
  onToggle,
}: {
  steps: TraceItem[];
  thinking: boolean;
  defaultOpen: boolean;
  openGroups: Record<string, boolean>;
  onToggle: (id: string) => void;
}) {
  if (steps.length === 0 && !thinking) {
    return null;
  }

  return (
    <div className="ai-trace">
      {steps.length > 0 && <span className="ai-trace-label">Thought</span>}
      {steps.map((step) => {
        const open = openGroups[step.id] ?? defaultOpen;
        const children = step.children ?? [];
        return (
          <div key={step.id} className={`ai-step ${step.kind}${step.status === 'error' ? ' error' : ''}`}>
            <span className="ai-step-mark" aria-hidden="true" />
            {children.length > 0 ? (
              <div>
                <button type="button" className={`ai-step-toggle${open ? ' open' : ''}`} onClick={() => onToggle(step.id)}>
                  <span className="chevron" aria-hidden="true">
                    ▸
                  </span>
                  {step.title}
                </button>
                {open && (
                  <ul className="ai-step-files">
                    {children.map((child) => (
                      <li key={child.id} className={child.status === 'error' ? 'error' : undefined}>
                        {child.title}
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            ) : (
              <span>{step.title}</span>
            )}
          </div>
        );
      })}
      {thinking && (
        <div className="ai-step thinking">
          <span className="ai-step-mark" aria-hidden="true" />
          <span>Thinking</span>
        </div>
      )}
    </div>
  );
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
  const [openGroups, setOpenGroups] = useState<Record<string, boolean>>({});
  const scroller = useRef<HTMLDivElement>(null);
  const composer = useRef<HTMLTextAreaElement>(null);
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
  }, [session?.messages, session?.operations, busy, showHistory]);

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
    const timer = window.setInterval(() => void tick(session.id), 400);
    return () => {
      cancelled = true;
      window.clearInterval(timer);
    };
  }, [session?.id, session?.status, workspaceId]);

  useEffect(() => {
    setOpenGroups({});
  }, [session?.id]);

  useLayoutEffect(() => {
    const el = composer.current;
    if (!el) {
      return;
    }
    el.style.height = '0px';
    const cap = Math.round(window.innerHeight * 0.42);
    el.style.height = `${Math.max(72, Math.min(el.scrollHeight, cap))}px`;
  }, [draft, showHistory]);

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
  const turns = session ? turnsFromSession(session) : [];

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
            {session &&
              turns.map((turn, turnIndex) => {
                const lastTurn = turnIndex === turns.length - 1;
                const lastReply = turn.replies.at(-1);
                const streamingReply = Boolean(busy && lastTurn && lastReply?.content);
                const thinking = Boolean(busy && lastTurn && !streamingReply);
                return (
                  <div key={turn.user.id} className="ai-turn">
                    <div className="ai-msg user">
                      <span className="ai-msg-role">You</span>
                      <pre>{turn.user.content}</pre>
                    </div>
                    <TraceBlock
                      steps={turn.steps}
                      thinking={thinking}
                      defaultOpen={Boolean(busy && lastTurn)}
                      openGroups={openGroups}
                      onToggle={(id) => {
                        setOpenGroups((current) => ({ ...current, [id]: !(current[id] ?? Boolean(busy && lastTurn)) }));
                      }}
                    />
                    {turn.replies.map((message, replyIndex) => {
                      const streaming = Boolean(streamingReply && replyIndex === turn.replies.length - 1);
                      return (
                        <div key={message.id} className={`ai-msg assistant${streaming ? ' streaming' : ''}`}>
                          <span className="ai-msg-role">Agent</span>
                          <MarkdownBody text={message.content} />
                        </div>
                      );
                    })}
                  </div>
                );
              })}
          </>
        )}
      </div>
      {error && <p className="error ai-error">{error}</p>}
      <form className="ide-ai-form" onSubmit={(event) => void onSend(event)}>
          <div className="ide-ai-composer">
            <textarea
              ref={composer}
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
              onKeyDown={onComposerKey}
              placeholder={busy ? 'Agent is working…' : 'Ask to edit this OpenWrt project'}
              rows={1}
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

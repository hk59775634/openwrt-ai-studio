const TOKEN_KEY = 'studio_token';

export type User = {
  id: number;
  name: string;
  email: string;
  role: 'admin' | 'developer';
};

export type Workspace = {
  id: string;
  name: string;
  type: 'app' | 'theme' | 'sdk' | 'firmware';
  openwrt_revision: string;
  target: string | null;
  subtarget: string | null;
  profile: string | null;
  status: string;
  path: string;
  project_id: number;
  git_remote_url?: string | null;
  git_remote_branch?: string | null;
  git_token_set?: boolean;
  created_at: string;
  updated_at: string;
};

type ApiError = {
  message?: string;
  errors?: Record<string, string[]>;
};

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string | null): void {
  if (token) {
    localStorage.setItem(TOKEN_KEY, token);
  } else {
    localStorage.removeItem(TOKEN_KEY);
  }
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  if (init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }
  const token = getToken();
  if (token) {
    headers.set('Authorization', `Bearer ${token}`);
  }

  const response = await fetch(path, { ...init, headers });
  const text = await response.text();
  const payload = text ? (JSON.parse(text) as T & ApiError) : ({} as T & ApiError);

  if (!response.ok) {
    const fieldError = payload.errors
      ? Object.values(payload.errors).flat()[0]
      : undefined;
    throw new Error(fieldError || payload.message || `Request failed (${response.status})`);
  }

  return payload;
}

export const api = {
  health: () => request<{ ok: boolean; checks: Record<string, { ok: boolean; url?: string; running?: number }> }>('/api/health'),
  register: (body: { name: string; email: string; password: string }) =>
    request<{ token: string; user: User }>('/api/register', { method: 'POST', body: JSON.stringify(body) }),
  login: (body: { email: string; password: string }) =>
    request<{ token: string; user: User }>('/api/login', { method: 'POST', body: JSON.stringify(body) }),
  logout: () => request<{ ok: boolean }>('/api/logout', { method: 'POST' }),
  me: () => request<{ data: User } | User>('/api/user'),
  listWorkspaces: () => request<{ data: Workspace[] }>('/api/workspaces'),
  createWorkspace: (body: {
    name: string;
    type: Workspace['type'];
    openwrt_revision?: string;
    target?: string;
    subtarget?: string;
    profile?: string;
    git_url?: string;
    git_branch?: string;
    git_token?: string;
  }) =>
    request<{ data: Workspace }>('/api/workspaces', { method: 'POST', body: JSON.stringify(body) }),
  getWorkspace: (id: string) => request<{ data: Workspace }>(`/api/workspaces/${id}`),
  deleteWorkspace: (id: string) => request<{ ok: boolean }>(`/api/workspaces/${id}`, { method: 'DELETE' }),
  cloneWorkspace: (id: string, name: string) =>
    request<{ data: Workspace }>(`/api/workspaces/${id}/clone`, { method: 'POST', body: JSON.stringify({ name }) }),

  fileTree: (id: string) => request<{ data: FileNode[] }>(`/api/workspaces/${id}/files`),
  readFile: (id: string, path: string) =>
    request<{ data: { path: string; content: string; size: number } }>(`/api/workspaces/${id}/file?path=${encodeURIComponent(path)}`),
  writeFile: (id: string, path: string, content: string) =>
    request<{ ok: boolean }>(`/api/workspaces/${id}/file`, { method: 'PUT', body: JSON.stringify({ path, content }) }),
  createFile: (id: string, path: string, type: 'file' | 'dir') =>
    request<{ ok: boolean }>(`/api/workspaces/${id}/file`, { method: 'POST', body: JSON.stringify({ path, type }) }),

  gitStatus: (id: string) => request<{ data: GitStatus }>(`/api/workspaces/${id}/git/status`),
  gitDiff: (id: string, path?: string) =>
    request<{ data: { diff: string } }>(`/api/workspaces/${id}/git/diff${path ? `?path=${encodeURIComponent(path)}` : ''}`),
  gitLog: (id: string) => request<{ data: GitCommit[] }>(`/api/workspaces/${id}/git/log`),
  gitCommit: (id: string, message: string) =>
    request<{ data: { sha: string; message: string; branch: string } }>(`/api/workspaces/${id}/git/commit`, {
      method: 'POST',
      body: JSON.stringify({ message }),
    }),
  gitRestore: (id: string, path: string) =>
    request<{ ok: boolean }>(`/api/workspaces/${id}/git/restore`, { method: 'POST', body: JSON.stringify({ path }) }),
  bindGitRemote: (id: string, body: { url: string; branch?: string; token?: string }) =>
    request<{ data: { git_remote_url: string; git_remote_branch: string | null; git_token_set: boolean } }>(
      `/api/workspaces/${id}/git/remote`,
      { method: 'PUT', body: JSON.stringify(body) },
    ),
  gitPush: (id: string, branch?: string) =>
    request<{ data: { remote: string; branch: string; sha: string } }>(`/api/workspaces/${id}/git/push`, {
      method: 'POST',
      body: JSON.stringify(branch ? { branch } : {}),
    }),
  gitPull: (id: string, branch?: string) =>
    request<{ data: { remote: string; branch: string; sha: string; ahead?: number; behind?: number } }>(
      `/api/workspaces/${id}/git/pull`,
      {
        method: 'POST',
        body: JSON.stringify(branch ? { branch } : {}),
      },
    ),
  gitRelease: (id: string, tag: string, message?: string, artifactIds?: number[]) =>
    request<{ data: { tag: string; sha: string; remote: string; html_url?: string | null; created?: boolean; assets?: string[] } }>(
      `/api/workspaces/${id}/git/release`,
      {
        method: 'POST',
        body: JSON.stringify({
          tag,
          message,
          artifact_ids: artifactIds ?? [],
        }),
      },
    ),
  getSettings: () => request<{ data: StudioSettings }>('/api/admin/settings'),
  updateSettings: (body: Partial<StudioSettings> & { ai_gateway_api_key?: string }) =>
    request<{ data: StudioSettings }>('/api/admin/settings', { method: 'PUT', body: JSON.stringify(body) }),

  terminal: (id: string, command: string) =>
    request<{ data: { command: string; cwd: string; exit_code: number; output: string } }>(`/api/workspaces/${id}/terminal`, {
      method: 'POST',
      body: JSON.stringify({ command }),
    }),

  aiModels: () => request<{ data: string[]; default: string }>('/api/ai/models'),
  aiSessions: (workspaceId: string) => request<{ data: AiSessionSummary[] }>(`/api/workspaces/${workspaceId}/ai/sessions`),
  createAiSession: (workspaceId: string, model?: string) =>
    request<{ data: AiSession }>(`/api/workspaces/${workspaceId}/ai/sessions`, {
      method: 'POST',
      body: JSON.stringify(model ? { model } : {}),
    }),
  getAiSession: (sessionId: string) => request<{ data: AiSession }>(`/api/ai/sessions/${sessionId}`),
  sendAiMessage: (sessionId: string, content: string, model?: string) =>
    request<{ data: AiSession; diff: string }>(`/api/ai/sessions/${sessionId}/messages`, {
      method: 'POST',
      body: JSON.stringify({ content, model }),
    }),
  deleteAiSession: (sessionId: string) => request<{ ok: boolean }>(`/api/ai/sessions/${sessionId}`, { method: 'DELETE' }),
  stopAiSession: (sessionId: string) =>
    request<{ data: AiSession }>(`/api/ai/sessions/${sessionId}/stop`, { method: 'POST' }),

  listBuilds: (workspaceId: string) => request<{ data: Build[] }>(`/api/workspaces/${workspaceId}/builds`),
  startBuild: (workspaceId: string, type?: string) =>
    request<{ data: Build }>(`/api/workspaces/${workspaceId}/builds`, {
      method: 'POST',
      body: JSON.stringify(type ? { type } : {}),
    }),
  getBuild: (buildId: string) => request<{ data: Build }>(`/api/builds/${buildId}`),
  buildLogs: (buildId: string, after = 0) =>
    request<{ data: BuildLogLine[]; status: string; after: number; max_sequence: number; truncated: boolean }>(
      `/api/builds/${buildId}/logs?after=${after}`,
    ),
  cancelBuild: (buildId: string) => request<{ data: Build }>(`/api/builds/${buildId}/cancel`, { method: 'POST' }),
  deleteBuild: (buildId: string) => request<{ ok: boolean }>(`/api/builds/${buildId}`, { method: 'DELETE' }),
  queue: () => request<{ data: QueueStatus }>('/api/queue'),
  devices: () => request<{ data: DevicePreset[]; platforms: DevicePlatform[] }>('/api/devices'),
  listConfigs: (workspaceId: string) => request<{ data: ConfigSnapshot[] }>(`/api/workspaces/${workspaceId}/configs`),
  saveConfig: (workspaceId: string, body: { name: string; target?: string; subtarget?: string; profile?: string; content?: string }) =>
    request<{ data: ConfigSnapshot }>(`/api/workspaces/${workspaceId}/configs`, { method: 'POST', body: JSON.stringify(body) }),
  generateConfig: (workspaceId: string, body: { target?: string; subtarget?: string; profile?: string }) =>
    request<{ data: { content: string; preset: DevicePreset } }>(`/api/workspaces/${workspaceId}/configs/generate`, {
      method: 'POST',
      body: JSON.stringify(body),
    }),
  applyConfig: (workspaceId: string, snapshotId: number) =>
    request<{ ok: boolean }>(`/api/workspaces/${workspaceId}/configs/${snapshotId}/apply`, { method: 'POST' }),
  deleteConfig: (workspaceId: string, snapshotId: number) =>
    request<{ ok: boolean }>(`/api/workspaces/${workspaceId}/configs/${snapshotId}`, { method: 'DELETE' }),
  compareConfigs: (workspaceId: string, left: number, right: number) =>
    request<{ data: { left: string; right: string; added: string[]; removed: string[] } }>(
      `/api/workspaces/${workspaceId}/configs/compare`,
      { method: 'POST', body: JSON.stringify({ left, right }) },
    ),
};

export type FileNode = {
  name: string;
  path: string;
  type: 'file' | 'dir';
  children?: FileNode[];
};

export type GitStatus = {
  branch: string;
  clean: boolean;
  staged: string[];
  unstaged: string[];
  untracked: string[];
};

export type GitCommit = {
  sha: string;
  message: string;
  author: string;
  date: string;
};

export type AiSessionSummary = {
  id: string;
  model: string;
  agent_type: string;
  status: string;
  created_at: string;
  updated_at: string;
  preview?: string;
};

export type AiMessage = {
  id: number;
  role: 'user' | 'assistant';
  content: string;
  created_at: string;
};

export type AiOperation = {
  id: number;
  tool: string;
  status: string;
  input: Record<string, unknown> | null;
  output: string | null;
  created_at: string;
};

export type AiSession = AiSessionSummary & {
  workspace_id: string;
  messages: AiMessage[];
  operations: AiOperation[];
};

export type BuildArtifact = {
  id: number;
  name: string;
  sha256: string;
  size: number;
  download: string;
};

export type BuildLogLine = {
  sequence: number;
  stream: string;
  content: string;
};

export type Build = {
  id: string;
  workspace_id: string;
  type: string;
  status: string;
  package_name: string;
  git_commit: string;
  openwrt_revision: string;
  architecture: string;
  command: string | null;
  error: string | null;
  started_at: string | null;
  finished_at: string | null;
  created_at: string;
  logs: BuildLogLine[];
  log_truncated?: boolean;
  artifacts: BuildArtifact[];
};

export type DevicePreset = {
  target: string;
  subtarget: string;
  profile: string;
  arch: string;
  label: string;
  sdk?: string;
  vendor?: boolean;
  source?: string | null;
  revision?: string | null;
};

export type DevicePlatform = {
  target: string;
  subtarget: string;
  arch: string;
  label: string;
  chip?: string | null;
  sdk: string;
  ready?: boolean;
  vendor?: boolean;
  source?: string | null;
  revision?: string | null;
  devices: { profile: string; label: string }[];
};

export type ConfigSnapshot = {
  id: number;
  name: string;
  target: string | null;
  subtarget: string | null;
  profile: string | null;
  openwrt_revision: string;
  sha256: string;
  content: string;
  created_at: string;
};

export type StudioSettings = {
  ai_gateway_url: string;
  ai_gateway_api_key_set: boolean;
  ai_gateway_model: string;
  ai_gateway_timeout: number;
  ai_gateway_retries: number;
  ai_tool_mode: 'auto' | 'openai' | 'json';
  quota_workspaces: number;
  quota_builds_per_hour: number;
};


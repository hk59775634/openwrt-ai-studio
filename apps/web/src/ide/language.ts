export function languageFor(path: string): string {
  const ext = path.split('.').pop()?.toLowerCase() ?? '';
  const map: Record<string, string> = {
    md: 'markdown',
    lua: 'lua',
    mk: 'makefile',
    makefile: 'makefile',
    json: 'json',
    yml: 'yaml',
    yaml: 'yaml',
    sh: 'shell',
    ts: 'typescript',
    tsx: 'typescript',
    js: 'javascript',
    css: 'css',
    html: 'html',
    c: 'c',
    h: 'c',
    cpp: 'cpp',
    rs: 'rust',
    py: 'python',
    php: 'php',
    conf: 'ini',
    config: 'ini',
    txt: 'plaintext',
  };
  if (path.endsWith('Makefile') || path.endsWith('/Makefile')) {
    return 'makefile';
  }
  return map[ext] ?? 'plaintext';
}

import { useEffect, useRef } from 'react';
import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import '@xterm/xterm/css/xterm.css';
import { api } from '../api';

type Props = {
  workspaceId: string;
};

export function TerminalPane({ workspaceId }: Props) {
  const hostRef = useRef<HTMLDivElement | null>(null);
  const termRef = useRef<Terminal | null>(null);
  const lineRef = useRef('');
  const busyRef = useRef(false);

  useEffect(() => {
    if (!hostRef.current) {
      return;
    }
    const term = new Terminal({
      cursorBlink: true,
      fontSize: 13,
      fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
      theme: {
        background: '#111216',
        foreground: '#ececec',
        cursor: '#6ea8ff',
      },
    });
    const fit = new FitAddon();
    term.loadAddon(fit);
    term.open(hostRef.current);
    fit.fit();
    term.writeln('Restricted workspace shell. Allowed: git status|log|diff|branch|show, ls, pwd, cat, head.');
    term.write('/workspace $ ');
    termRef.current = term;

    const onData = (data: string) => {
      if (busyRef.current) {
        return;
      }
      if (data === '\r') {
        const command = lineRef.current;
        lineRef.current = '';
        term.write('\r\n');
        if (command.trim() === '') {
          term.write('/workspace $ ');
          return;
        }
        if (command.trim() === 'clear') {
          term.clear();
          term.write('/workspace $ ');
          return;
        }
        busyRef.current = true;
        void api
          .terminal(workspaceId, command)
          .then((result) => {
            if (result.data.output) {
              term.write(result.data.output.replace(/\n/g, '\r\n'));
              if (!result.data.output.endsWith('\n')) {
                term.write('\r\n');
              }
            }
          })
          .catch((err: unknown) => {
            term.writeln(err instanceof Error ? err.message : 'command failed');
          })
          .finally(() => {
            busyRef.current = false;
            term.write('/workspace $ ');
          });
        return;
      }
      if (data === '\u007f') {
        if (lineRef.current.length > 0) {
          lineRef.current = lineRef.current.slice(0, -1);
          term.write('\b \b');
        }
        return;
      }
      if (data >= ' ' || data === '\t') {
        lineRef.current += data;
        term.write(data);
      }
    };

    const disposable = term.onData(onData);
    const onResize = () => fit.fit();
    window.addEventListener('resize', onResize);
    const observer = new ResizeObserver(onResize);
    observer.observe(hostRef.current);

    return () => {
      observer.disconnect();
      window.removeEventListener('resize', onResize);
      disposable.dispose();
      term.dispose();
      termRef.current = null;
    };
  }, [workspaceId]);

  return <div className="terminal-host" ref={hostRef} />;
}

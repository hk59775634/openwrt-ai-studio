import { fileName, type OpenFile } from './useOpenFiles';

type Props = {
  files: OpenFile[];
  activePath: string | null;
  onActivate: (path: string) => void;
  onClose: (path: string) => void;
};

export function EditorTabs({ files, activePath, onActivate, onClose }: Props) {
  if (files.length === 0) {
    return null;
  }

  return (
    <div className="ide-file-tabs" role="tablist" aria-label="Open editors">
      {files.map((file) => {
        const dirty = file.content !== file.original;
        const active = file.path === activePath;
        return (
          <div
            key={file.path}
            className={`ide-file-tab${active ? ' active' : ''}${dirty ? ' dirty' : ''}`}
            role="tab"
            aria-selected={active}
            title={file.path}
          >
            <button type="button" className="ide-file-tab-label" onClick={() => onActivate(file.path)}>
              {dirty && <span className="ide-file-dirty" aria-hidden="true" />}
              {fileName(file.path)}
            </button>
            <button
              type="button"
              className="ide-file-tab-close"
              aria-label={`Close ${fileName(file.path)}`}
              onClick={(event) => {
                event.stopPropagation();
                onClose(file.path);
              }}
            >
              ×
            </button>
          </div>
        );
      })}
    </div>
  );
}

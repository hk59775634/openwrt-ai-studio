import { useState } from 'react';
import type { FileNode } from '../api';

type Props = {
  nodes: FileNode[];
  activePath: string | null;
  onOpen: (path: string) => void;
};

function TreeNode({ node, activePath, onOpen, depth }: Props & { node: FileNode; depth: number }) {
  const [open, setOpen] = useState(depth < 2);
  const isDir = node.type === 'dir';

  return (
    <div>
      <button
        type="button"
        className={`tree-item${activePath === node.path ? ' active' : ''}`}
        style={{ paddingLeft: 8 + depth * 12 }}
        onClick={() => (isDir ? setOpen((value) => !value) : onOpen(node.path))}
      >
        <span className="tree-mark">{isDir ? (open ? '▾' : '▸') : '·'}</span>
        {node.name}
      </button>
      {isDir && open && node.children && (
        <FileTree nodes={node.children} activePath={activePath} onOpen={onOpen} depth={depth + 1} />
      )}
    </div>
  );
}

export function FileTree({ nodes, activePath, onOpen, depth = 0 }: Props & { depth?: number }) {
  return (
    <>
      {nodes.map((node) => (
        <TreeNode key={node.path} node={node} activePath={activePath} onOpen={onOpen} depth={depth} />
      ))}
    </>
  );
}

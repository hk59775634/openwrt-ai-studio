export function formatArtifactSize(bytes: number): string {
  if (bytes >= 1024 * 1024) {
    return `${Math.round(bytes / (1024 * 1024))} MB`;
  }
  return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

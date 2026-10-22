export function homeView(result, executable, home, description) {
  const command = `node ${JSON.stringify(executable)}`;
  return { bin: executable.startsWith(home + '/') ? '~' + executable.slice(home.length) : executable, description, ...result,
    help: `${command} show --run <id> --json for a run; ${command} cycle --action show for cycle state; ${command} --help for commands.` };
}
// Flat TOON scalars; nested detail remains canonical JSON behind --json.
export function formatOutput(value) {
  return Object.entries(value).map(([key, item]) => {
    let shown = item;
    if (item && typeof item === 'object') {
      const text = JSON.stringify(item);
      shown = text.length > 160 ? `${text.slice(0, 160)}… (${text.length} chars; use --json for full details)` : text;
    }
    return `${key}: ${JSON.stringify(shown ?? null)}`;
  }).join('\n');
}

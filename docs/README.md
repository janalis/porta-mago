# Mago

[Mago](https://github.com/carthage-software/mago) both formats and lints PHP,
and this plugin offers both. Bundled with Porta and off by default — turn it
on under **Settings → Plugins → mago**.

- **Mago: Format Current File** (`Ctrl+Alt+G`) formats the file and applies
  the result to the open buffer, so the whole reformat is one `Ctrl+Z` away —
  unsaved edits included.
- **Mago: Lint Current File** (`Ctrl+Alt+E`) lints it. Findings are published as editor diagnostics — inline messages, gutter and
stripe marks, and the Problems panel (`Alt+6`), exactly like a language
server's — and a clean run clears them. A language server that publishes
diagnostics for the same file replaces them, and the other way round: the last
writer wins.

With both **on save** settings on, a save formats first and then lints the
formatted file.

The plugin finds `mago` through its **mago binary** setting when you
set one, then `vendor/bin/mago`, then your `PATH`. While none of those
resolves, its menu entries stay dimmed, and its page under **Settings →
Plugins** offers to install it (`composer require --dev carthage-software/mago`).

In a project you have not trusted, mago does not run at all: a `mago.toml` can
name a program for `mago lint` to start (`[extension-hosts]`). See
[Trusting a project](../../../docs/configuration.md#trusting-a-project).

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| mago binary | empty | The executable to run; empty finds it as above |
| Format on save | off | Format each PHP file when you save it (**Mago: Toggle Format on Save**) |
| Lint on save | off | Lint each PHP file when you save it (**Mago: Toggle Lint on Save**) |

## Keys

| Key | Action |
| --- | --- |
| `Ctrl+Alt+G` | Format the current file |
| `Ctrl+Alt+E` | Lint the current file |

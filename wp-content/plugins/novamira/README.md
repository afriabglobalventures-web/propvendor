<p align="center">
  <a href="https://novamira.ai/">
    <img src="assets/banner.png" alt="Novamira" />
  </a>
</p>

<p align="center">
  <strong>Give your AI agents full access to your WordPress site. Nothing in between.</strong>
</p>

<p align="center">
  <a href="https://novamira.ai/">Website</a> ·
  <a href="https://novamira.ai/docs/">Documentation</a> ·
  <a href="https://novamira.ai/cli/">Novamira CLI</a> ·
  <a href="https://novamira.ai/video/">Videos</a> ·
  <a href="https://novamira.ai/download/">Download</a> ·
  <a href="https://discord.gg/novamira">Discord</a> ·
  <a href="https://www.facebook.com/groups/novamira">Facebook Community</a>
</p>

Novamira is an open-source WordPress plugin and MCP server that lets AI agents work directly inside WordPress. Agents can run PHP and WP-CLI commands, inspect the database, manage files, understand the active plugins and theme, and build working functionality against the real site.

The connection is direct between your AI client and your WordPress installation. Novamira is not a hosted proxy and your requests do not pass through Novamira servers.

> [!WARNING]
> For dev and staging environments. With backups. Always.

## Videos

See Novamira in real projects, tutorials, and walkthroughs from the WordPress community.

**[Watch Novamira videos →](https://novamira.ai/video/)**

## What Novamira provides

- Full WordPress access through PHP execution, including `$wpdb`, loaded plugins, themes, and WordPress APIs
- WP-CLI commands with foreground and background execution
- Filesystem inspection and editing, plus a recoverable sandbox for new PHP files
- Native Block Editor workflows, media uploads, reusable skills, agent context, and design guidance
- OAuth and WordPress Application Password authentication
- Compatibility with Claude, Codex, Cursor, Gemini CLI, Antigravity, VS Code with GitHub Copilot, and other MCP clients
- [Novamira CLI](https://novamira.ai/cli/) for connecting terminal-based coding agents such as Claude Code, Codex CLI, and Gemini CLI
- Novamira Visual, an experimental browser workspace where you can watch an agent work in WordPress

## Requirements

- WordPress 6.9 or later
- PHP 8.0 or later
- A WordPress administrator account
- HTTPS for remote connections; local development environments are supported without HTTPS
- An MCP-compatible AI client, or a terminal-based coding agent using Novamira CLI

## Get started

1. [Download the release ZIP](https://novamira.ai/download/).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, upload the ZIP, and activate Novamira.
3. Open **Novamira → Configuration**, enable AI Abilities, and follow the instructions for your AI client and authentication method.

Use the release ZIP rather than GitHub's automatically generated source archive. The source archive does not include the bundled Composer dependencies required by the MCP server.

See the [quick start guide](https://novamira.ai/quickstart/) for the complete connection flow.

Using a terminal-based coding agent? [Novamira CLI](https://novamira.ai/cli/) provides a guided connection for Claude Code, Codex CLI, Gemini CLI, and other agents that can run shell commands.

## Documentation

- [Documentation](https://novamira.ai/docs/)
- [Getting started](https://novamira.ai/docs/getting-started/)
- [Novamira CLI](https://novamira.ai/cli/)
- [Videos](https://novamira.ai/video/)
- [Security and best practices](https://novamira.ai/docs/security/)
- [Connection troubleshooting](https://novamira.ai/docs/connection-troubleshooting/)
- [Changelog](CHANGELOG.txt)

## Community

- [Discord](https://discord.gg/novamira)
- [Facebook Community](https://www.facebook.com/groups/novamira)
- [GitHub Discussions](https://github.com/use-novamira/novamira/discussions)

## Contributing

Read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request. Every contribution must start with an approved issue.

## License

[AGPL-3.0-or-later](https://www.gnu.org/licenses/agpl-3.0.html)

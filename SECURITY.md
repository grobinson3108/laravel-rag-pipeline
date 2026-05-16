# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 0.1.x   | ✅ |
| < 0.1   | ❌ |

This package is in alpha (v0.x). Once v1.0 ships, supported version matrix will expand.

## Reporting a vulnerability

**Do not open a public GitHub issue for security problems.**

Email **greg@audelalia.fr** with:
- The vulnerability description
- Reproduction steps
- Potential impact
- Suggested fix (if you have one)

I'll respond within 72 hours.

## Scope

This package's attack surface is small but worth describing:

- **API keys handling (OpenAI, Cohere, Neo4j) — keys are read from env vars, never logged**: tokens are read from env vars, never logged, never persisted by this package
- **stdio transport**: the MCP client (Claude Desktop, etc.) controls the channel — we trust the OS process boundary
- **No HTTP transport** in v0.x — no exposed network surface

Out of scope:
- Vulnerabilities in upstream packages (`logiscape/mcp-sdk-php`, Laravel, Sanctum) — report those upstream
- Vulnerabilities in user-written tool callbacks — those are application code

## Hall of fame

Future reporters will be credited here (with permission).

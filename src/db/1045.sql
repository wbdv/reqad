-- AI assistants over MCP (app/mcp/mcp.php, Settings > AI access, `reqad mcp`).
--
-- MCP tokens live in api_tokens with kind 'mcp'. They work only for
-- `reqad mcp serve` (root over SSH) and the REST API refuses them, so a
-- token copied out of an assistant's settings cannot call the API over the
-- network. Their calls are logged in api_log (method MCP, path = the tool).
--
-- MCP is off until the setting mcp-enabled is 1.

ALTER TABLE api_tokens ADD COLUMN kind TEXT NOT NULL DEFAULT 'api';

INSERT OR IGNORE INTO settings (name, value) VALUES ('mcp-enabled', '0');

INSERT OR REPLACE INTO settings (name, value) VALUES ('db-version', '1045');

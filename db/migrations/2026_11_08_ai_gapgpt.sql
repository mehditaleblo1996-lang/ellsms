-- AI engines: GapGPT, an Iranian OpenAI-compatible gateway (docs/ai-engines.md §GapGPT).
--
-- Adds 'gapgpt' to ellsms_ai_providers.type. GapGPT speaks the OpenAI protocol at
-- https://api.gapgpt.app/v1 (Bearer key); it has its own type so the form pre-fills that address and
-- the key is required, and so it is listed apart from self-hosted servers.
--
-- Requires 2026_11_03_ai_engines.sql. Guarded, so re-running this file changes nothing.
SET NAMES utf8mb4;

SET @c = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ellsms_ai_providers'
          AND column_name = 'type' AND column_type LIKE '%''gapgpt''%');
SET @sql = IF(@c = 0, 'ALTER TABLE ellsms_ai_providers MODIFY COLUMN type ENUM(''openai_compatible'',''openai'',''anthropic'',''openrouter'',''ollama'',''gapgpt'') NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

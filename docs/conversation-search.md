# Conversation search rollout

1. Run the chat search migrations (`2026_10_06_000001` through `000005`).
2. Restart long-running PHP and queue workers so schema-availability caches are refreshed.
3. Run `php artisan chat-search:backfill --batch=500 --prune`. The command stores active and archived message checkpoints in `chat_search_index_state`. Rerunning the same command resumes automatically after interruption. Use `--limit=10` to process ten document batches per run. Partial runs never mark the index ready; `--reset` explicitly starts a fresh reconciliation.
4. Confirm `chat_search_index_state` contains version `2` with `completed_at`, then verify a recent message, an old message, and a revoked message in the UI.

The search endpoint returns a translated preparing state until the complete backfill finishes. New chat logs are indexed when they are created. Failed live indexing is reported and retried by `RepairChatSearchIndexJob`; the backfill command reconciles older rows and removes orphaned documents.

Search uses a full-text index and keyset pagination. `EXPLAIN` on the local small conversation used `chat_message_search_fulltext`; ordering still requires `Using filesort`. The measured average for 20 local searches of `هلوو` was about 4.4 ms. This is not a representative scale test. Before broad release, measure p95 and p99 with common and rare Arabic and English terms on an anonymized production-sized dataset, including a conversation with at least 100,000 messages. Target p95 is 250 ms for search and 200 ms for opening a message. If common terms exceed that target, add a conversation-scoped term index instead of removing the tenant or contact filters.

The local automated integration suite needs the dedicated `chatberry_dashboard_test` database credentials from `phpunit.xml`. Do not point `RefreshDatabase` at the working database.

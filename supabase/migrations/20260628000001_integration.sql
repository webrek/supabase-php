-- Integration test table.
-- Used by the PHP SDK's integration test suite (tests/Integration/).
-- The service_role key bypasses RLS, so no policies are needed for test access.
-- RLS is intentionally left OFF to keep the fixture minimal and clear.

create table if not exists public.integration_items (
    id         bigserial primary key,
    name       text        not null,
    created_at timestamptz not null default now()
);

-- Expose the table to the Realtime publication so a future Realtime integration
-- test can subscribe to changes on this table without a schema migration.
alter publication supabase_realtime add table public.integration_items;

-- Integration test table.
-- Used by the PHP SDK's integration test suite (tests/Integration/).
-- RLS is intentionally left OFF to keep the fixture minimal and clear; the
-- service_role key bypasses RLS, but the PostgREST roles still need explicit
-- table/sequence GRANTs (a table created by a raw migration is not granted to
-- the API roles automatically).

create table if not exists public.integration_items (
    id         bigserial primary key,
    name       text        not null,
    created_at timestamptz not null default now()
);

-- Grant access to the PostgREST API roles so the SDK can read/write the table.
grant all privileges on table public.integration_items to anon, authenticated, service_role;
grant usage, select on sequence public.integration_items_id_seq to anon, authenticated, service_role;

-- Expose the table to the Realtime publication so a future Realtime integration
-- test can subscribe to changes on this table without a schema migration.
alter publication supabase_realtime add table public.integration_items;

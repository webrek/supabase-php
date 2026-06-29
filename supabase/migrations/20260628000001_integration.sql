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

-- Realtime applies RLS when authorizing postgres_changes delivery, so enable RLS
-- and allow anon to read. The Realtime integration test subscribes with the anon
-- key; this permissive read policy lets the change events reach the subscriber.
-- (PostgREST writes in the tests use the service_role key, which bypasses RLS.)
alter table public.integration_items enable row level security;
create policy "integration_items_anon_select"
    on public.integration_items
    for select
    to anon
    using (true);

-- Expose the table to the Realtime publication so the Realtime integration test
-- can subscribe to changes on this table.
alter publication supabase_realtime add table public.integration_items;

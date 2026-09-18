-- Fixtures for the post-1.0 integration tests (tests/Integration/):
--   * public.private_notes  — per-user table behind RLS, exercised by
--     Client::withAccessToken() / withSession() (RLS must apply as that user).
--   * realtime.messages policies — Realtime Authorization for private channels
--     and private REST broadcasts (authenticated only; anon has no policy).

create table if not exists public.private_notes (
    id         bigserial primary key,
    owner      uuid        not null default auth.uid(),
    body       text        not null,
    created_at timestamptz not null default now()
);

grant select, insert, update, delete on table public.private_notes to authenticated, service_role;
grant usage, select on sequence public.private_notes_id_seq to authenticated, service_role;
-- anon may SELECT at the table level but has no policy: RLS yields zero rows
-- instead of a permission error, which is what the user-context test asserts.
grant select on table public.private_notes to anon;

alter table public.private_notes enable row level security;

create policy "private_notes_owner_select"
    on public.private_notes
    for select
    to authenticated
    using (owner = auth.uid());

create policy "private_notes_owner_insert"
    on public.private_notes
    for insert
    to authenticated
    with check (owner = auth.uid());

-- Private channels: only authenticated users may join and exchange broadcast /
-- presence messages on them. Without a policy for anon, a private join with the
-- anon key is refused by the server.
create policy "private_channels_authenticated_read"
    on realtime.messages
    for select
    to authenticated
    using (realtime.messages.extension in ('broadcast', 'presence'));

create policy "private_channels_authenticated_write"
    on realtime.messages
    for insert
    to authenticated
    with check (realtime.messages.extension in ('broadcast', 'presence'));

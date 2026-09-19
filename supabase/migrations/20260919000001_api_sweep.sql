-- Fixtures for the API-sweep integration tests (tests/Integration/QueryIntegrationTest.php):
-- a deterministic product catalogue that exercises every PostgREST filter the
-- SDK exposes (arrays, jsonb, ranges, full-text search, nulls), plus two RPC
-- functions (scalar and set-returning). RLS is off; the tests use service_role.

create table if not exists public.integration_products (
    id           bigserial primary key,
    sku          text        not null unique,
    name         text        not null,
    price        numeric(10, 2) not null,
    stock        integer     not null default 0,
    tags         text[]      not null default '{}',
    meta         jsonb       not null default '{}'::jsonb,
    avail        int4range,
    discontinued boolean,
    created_at   timestamptz not null default now()
);

grant all privileges on table public.integration_products to anon, authenticated, service_role;
grant usage, select on sequence public.integration_products_id_seq to anon, authenticated, service_role;

insert into public.integration_products (sku, name, price, stock, tags, meta, avail, discontinued) values
    ('sku-phone',  'Phone Alpha',   499.00,  10, '{mobile,5g}',    '{"color":"black","rank":1}',  '[1,10)',  false),
    ('sku-tablet', 'Tablet Beta',   299.50,   0, '{mobile,pen}',   '{"color":"silver","rank":2}', '[10,20)', false),
    ('sku-laptop', 'Laptop Gamma', 1299.99,   3, '{computer,pro}', '{"color":"gray","rank":3}',   '[20,30)', null),
    ('sku-cable',  'USB cable',       9.99, 250, '{accessory}',    '{"color":"black","rank":4}',  '[5,15)',  true)
on conflict (sku) do nothing;

create or replace function public.integration_add(a integer, b integer)
    returns integer
    language sql
    immutable
as $$ select a + b $$;

create or replace function public.integration_products_tagged(tag text)
    returns setof public.integration_products
    language sql
    stable
as $$ select * from public.integration_products where tag = any(tags) order by id $$;

grant execute on function public.integration_add(integer, integer) to anon, authenticated, service_role;
grant execute on function public.integration_products_tagged(text) to anon, authenticated, service_role;

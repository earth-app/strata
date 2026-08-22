-- core's pgsql install tasks run CREATE EXTENSION IF NOT EXISTS pg_trgm on every kernel test's
-- setUp(), and that statement is not atomic: concurrent paratest workers on a fresh database all
-- see it missing, all issue CREATE, and the losers get a unique violation on
-- pg_extension_name_index. Creating it once at first init means no worker ever races for it.
CREATE EXTENSION IF NOT EXISTS pg_trgm;

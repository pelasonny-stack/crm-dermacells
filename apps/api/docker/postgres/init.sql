-- Docker Postgres init — creates application roles
-- Runs once when the volume is first initialised.

DO $$
BEGIN
    -- migration_role: DDL permissions, used only during deploy
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'migration_role') THEN
        CREATE ROLE migration_role LOGIN PASSWORD 'migration_dev_pass';
    END IF;

    -- app_role: DML only (SELECT/INSERT/UPDATE/DELETE), no DDL
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'app_role') THEN
        CREATE ROLE app_role LOGIN PASSWORD 'app_dev_pass';
    END IF;

    -- worker_role: Horizon queue workers, BYPASSRLS=NO
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'worker_role') THEN
        CREATE ROLE worker_role LOGIN PASSWORD 'worker_dev_pass';
    END IF;

    -- report_role: SELECT only, for read replicas and reporting
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'report_role') THEN
        CREATE ROLE report_role LOGIN PASSWORD 'report_dev_pass';
    END IF;
END
$$;

GRANT ALL ON SCHEMA public TO migration_role;
GRANT USAGE, CREATE ON SCHEMA public TO app_role, worker_role, report_role;

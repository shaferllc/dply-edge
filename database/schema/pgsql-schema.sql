--
-- PostgreSQL database dump
--

\restrict cuGdoOZPiFe1nRHaX9ekAVUI8AuJpkhDJxsw3XqE8eh0LqhxvZqhUtaDixsIyu0

-- Dumped from database version 16.14 (Homebrew)
-- Dumped by pg_dump version 16.14 (Homebrew)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: -
--

-- *not* creating schema, since initdb creates it


--
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA public IS '';


--
-- Name: dply_cache_items_usage_delta(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.dply_cache_items_usage_delta() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF (TG_OP = 'INSERT') THEN
            INSERT INTO dply_cache_usage (cache_id, resident_bytes, item_count)
            SELECT cache_id, SUM(byte_size), COUNT(*)
              FROM new_items GROUP BY cache_id
            ON CONFLICT (cache_id) DO UPDATE
               SET resident_bytes = dply_cache_usage.resident_bytes + EXCLUDED.resident_bytes,
                   item_count     = dply_cache_usage.item_count + EXCLUDED.item_count;

        ELSIF (TG_OP = 'DELETE') THEN
            UPDATE dply_cache_usage u
               SET resident_bytes = GREATEST(0, u.resident_bytes - agg.bytes),
                   item_count     = GREATEST(0, u.item_count - agg.rows_)
              FROM (
                    SELECT cache_id, SUM(byte_size) AS bytes, COUNT(*) AS rows_
                      FROM old_items GROUP BY cache_id
                   ) agg
             WHERE u.cache_id = agg.cache_id;

        ELSE
            -- UPDATE: the row count is unchanged, only the size moves.
            UPDATE dply_cache_usage u
               SET resident_bytes = GREATEST(0, u.resident_bytes + agg.delta)
              FROM (
                    SELECT n.cache_id, SUM(n.byte_size - o.byte_size) AS delta
                      FROM new_items n
                      JOIN old_items o
                        ON o.cache_id = n.cache_id AND o.key = n.key
                     GROUP BY n.cache_id
                   ) agg
             WHERE u.cache_id = agg.cache_id;
        END IF;

        RETURN NULL;
    END;
    $$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: api_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.api_tokens (
    id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    token_prefix character varying(32) NOT NULL,
    token_hash character varying(255) NOT NULL,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    abilities json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    allowed_ips json
);


--
-- Name: COLUMN api_tokens.token_prefix; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.api_tokens.token_prefix IS 'First chars of token for lookup';


--
-- Name: app_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.app_logs (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    channel character varying(64),
    level character varying(16),
    message text NOT NULL,
    context json,
    logged_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone
);


--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_logs (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    user_id character(26),
    action character varying(255) NOT NULL,
    subject_type character varying(255),
    subject_id character(26),
    old_values json,
    new_values json,
    ip_address character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: backup_configurations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.backup_configurations (
    id character(26) NOT NULL,
    created_by_user_id character(26),
    name character varying(160) NOT NULL,
    provider character varying(32) NOT NULL,
    config text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    organization_id character(26) NOT NULL
);


--
-- Name: backup_schedules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.backup_schedules (
    id character(26) NOT NULL,
    server_id character(26),
    target_type character varying(32) NOT NULL,
    target_id character(26) NOT NULL,
    backup_configuration_id character(26),
    cron_expression character varying(64) NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    last_run_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    notify_on_failure boolean DEFAULT true NOT NULL
);


--
-- Name: beta_invitations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.beta_invitations (
    id character(26) NOT NULL,
    email character varying(255) NOT NULL,
    token character varying(64) NOT NULL,
    source character varying(255),
    expires_at timestamp(0) without time zone NOT NULL,
    invited_by character(26),
    redeemed_at timestamp(0) without time zone,
    redeemed_by_user_id character(26),
    organization_id character(26),
    revoked_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: billing_subscription_sync_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.billing_subscription_sync_events (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    trigger character varying(64) NOT NULL,
    status character varying(16) NOT NULL,
    changes json,
    desired_state json,
    monthly_total_cents integer DEFAULT 0 NOT NULL,
    error_message text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: cloud_bucket_site; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cloud_bucket_site (
    id character(26) NOT NULL,
    cloud_bucket_id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    env_prefix character varying(40) DEFAULT 'S3'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: cloud_database_site; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cloud_database_site (
    id character(26) NOT NULL,
    cloud_database_id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    env_prefix character varying(40) DEFAULT 'DB'::character varying NOT NULL
);


--
-- Name: cloud_deploy_tasks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cloud_deploy_tasks (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    trigger character varying(24) NOT NULL,
    name character varying(255) NOT NULL,
    command character varying(1000) DEFAULT ''::character varying NOT NULL,
    size character varying(32) DEFAULT 'small'::character varying NOT NULL,
    status character varying(32) DEFAULT 'configured'::character varying NOT NULL,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: cloud_workers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cloud_workers (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    type character varying(16) DEFAULT 'worker'::character varying NOT NULL,
    name character varying(255) NOT NULL,
    command character varying(255) DEFAULT ''::character varying NOT NULL,
    size character varying(32) DEFAULT 'small'::character varying NOT NULL,
    instance_count integer DEFAULT 1 NOT NULL,
    status character varying(32) DEFAULT 'provisioning'::character varying NOT NULL,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: coming_soon_allowed_ips; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.coming_soon_allowed_ips (
    id bigint NOT NULL,
    ip character varying(255) NOT NULL,
    label character varying(255),
    created_by character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: coming_soon_allowed_ips_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.coming_soon_allowed_ips_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: coming_soon_allowed_ips_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.coming_soon_allowed_ips_id_seq OWNED BY public.coming_soon_allowed_ips.id;


--
-- Name: coming_soon_signups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.coming_soon_signups (
    id character(26) NOT NULL,
    email character varying(254) NOT NULL,
    source character varying(120),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: config_revisions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.config_revisions (
    id character(26) NOT NULL,
    stream_key character varying(255) NOT NULL,
    server_id character(26),
    subject_type character varying(255),
    subject_id character(26),
    kind character varying(64) NOT NULL,
    user_id character(26),
    summary character varying(255),
    snapshot json NOT NULL,
    checksum character(64) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: console_actions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.console_actions (
    id character(26) NOT NULL,
    subject_type character varying(255) NOT NULL,
    subject_id character(26) NOT NULL,
    kind character varying(64) NOT NULL,
    status character varying(16) NOT NULL,
    started_at timestamp(0) without time zone,
    finished_at timestamp(0) without time zone,
    dismissed_at timestamp(0) without time zone,
    error text,
    output json,
    user_id character(26),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    label character varying(255)
);


--
-- Name: debug_allowed_ips; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.debug_allowed_ips (
    id bigint NOT NULL,
    ip character varying(255) NOT NULL,
    label character varying(255),
    created_by character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: debug_allowed_ips_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.debug_allowed_ips_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: debug_allowed_ips_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.debug_allowed_ips_id_seq OWNED BY public.debug_allowed_ips.id;


--
-- Name: deploy_contract_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.deploy_contract_runs (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    parent_site_id character(26) NOT NULL,
    preview_site_id character(26) NOT NULL,
    preview_deployment_id character varying(26),
    git_commit character varying(64),
    triggered_by_user_id character(26),
    status character varying(32) NOT NULL,
    checks json NOT NULL,
    summary json NOT NULL,
    waiver_reason text,
    waived_by_user_id character(26),
    waived_at timestamp(0) without time zone,
    finished_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: device_authorizations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.device_authorizations (
    id character(26) NOT NULL,
    device_code_hash character varying(64) NOT NULL,
    user_code character varying(16) NOT NULL,
    user_id character(26),
    organization_id character(26),
    api_token_id character(26),
    status character varying(16) DEFAULT 'pending'::character varying NOT NULL,
    token_plaintext text,
    ip_address character varying(45),
    user_agent character varying(512),
    expires_at timestamp(0) without time zone NOT NULL,
    authorized_at timestamp(0) without time zone,
    delivered_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: discord_installations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.discord_installations (
    id character(26) NOT NULL,
    owner_type character varying(255) NOT NULL,
    owner_id character(26) NOT NULL,
    guild_id character varying(255) NOT NULL,
    guild_name character varying(255) NOT NULL,
    permissions character varying(255),
    installed_by_user_id character(26),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: dply_cache_items; Type: TABLE; Schema: public; Owner: -
--

CREATE UNLOGGED TABLE public.dply_cache_items (
    cache_id character(26) NOT NULL,
    key text NOT NULL,
    value text,
    value_type character(1) DEFAULT 'S'::bpchar NOT NULL,
    expires_at bigint NOT NULL,
    byte_size integer DEFAULT 0 NOT NULL
)
WITH (autovacuum_vacuum_scale_factor='0.01', autovacuum_analyze_scale_factor='0.01', fillfactor='80');


--
-- Name: dply_cache_usage; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dply_cache_usage (
    cache_id character(26) NOT NULL,
    resident_bytes bigint DEFAULT '0'::bigint NOT NULL,
    item_count bigint DEFAULT '0'::bigint NOT NULL
);


--
-- Name: dply_queue_failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dply_queue_failed_jobs (
    id character(26) NOT NULL,
    namespace_id character(26) NOT NULL,
    job_uuid character varying(64),
    queue character varying(128) NOT NULL,
    payload text NOT NULL,
    exception text,
    display_name character varying(255),
    attempts smallint DEFAULT '0'::smallint NOT NULL,
    retried_at timestamp(6) with time zone,
    failed_at timestamp(6) with time zone,
    created_at timestamp(6) with time zone
);


--
-- Name: dply_queue_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dply_queue_jobs (
    id character(26) NOT NULL,
    namespace_id character(26) NOT NULL,
    queue character varying(128) NOT NULL,
    payload text NOT NULL,
    attempts smallint DEFAULT '0'::smallint NOT NULL,
    visible_at timestamp(6) with time zone NOT NULL,
    reserved_at timestamp(6) with time zone,
    reservation_id uuid,
    state smallint DEFAULT '0'::smallint NOT NULL,
    job_uuid character varying(64),
    job_timeout integer,
    job_max_tries smallint,
    batch_id character varying(64),
    display_name character varying(255),
    payload_bytes integer DEFAULT 0 NOT NULL,
    created_at timestamp(6) with time zone
)
WITH (autovacuum_vacuum_scale_factor='0.01', autovacuum_analyze_scale_factor='0.01', autovacuum_vacuum_cost_delay='0', fillfactor='80');


--
-- Name: edge_access_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_access_logs (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    edge_deployment_id character varying(26),
    hostname character varying(255) NOT NULL,
    method character varying(12) DEFAULT 'GET'::character varying NOT NULL,
    path character varying(2048) NOT NULL,
    status_code smallint,
    duration_ms integer DEFAULT 0 NOT NULL,
    bytes_egress bigint DEFAULT '0'::bigint NOT NULL,
    country character varying(8),
    cache_status character varying(32),
    referrer character varying(2048),
    user_agent character varying(512),
    source character varying(32) DEFAULT 'worker'::character varying NOT NULL,
    occurred_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: edge_deploy_hooks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_deploy_hooks (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    name character varying(80) NOT NULL,
    token_hash character varying(64) NOT NULL,
    token_prefix character varying(12) NOT NULL,
    created_by_user_id character(26),
    last_used_at timestamp(0) without time zone,
    last_triggered_deployment_id character(26),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: edge_deploy_replays; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_deploy_replays (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    parent_site_id character(26) NOT NULL,
    preview_site_id character(26) NOT NULL,
    preview_deployment_id character varying(26),
    triggered_by_user_id character(26),
    status character varying(32) DEFAULT 'queued'::character varying NOT NULL,
    sample_limit smallint DEFAULT '20'::smallint NOT NULL,
    window_minutes smallint DEFAULT '60'::smallint NOT NULL,
    samples json,
    results json,
    summary json,
    error_message text,
    started_at timestamp(0) without time zone,
    finished_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: edge_deployments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_deployments (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    status character varying(32) DEFAULT 'building'::character varying NOT NULL,
    git_commit character varying(64),
    git_branch character varying(255),
    storage_prefix character varying(255),
    build_log_path character varying(255),
    cf_kv_version integer DEFAULT 0 NOT NULL,
    published_at timestamp(0) without time zone,
    failed_at timestamp(0) without time zone,
    failure_reason text,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    pruned_at timestamp(0) without time zone,
    aliases jsonb,
    repo_config jsonb
);


--
-- Name: edge_performance_hourly; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_performance_hourly (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    hour_start timestamp(0) without time zone NOT NULL,
    requests bigint DEFAULT '0'::bigint NOT NULL,
    bytes_egress bigint DEFAULT '0'::bigint NOT NULL,
    duration_ms_total integer DEFAULT 0 NOT NULL,
    duration_ms_p95 integer,
    status_2xx smallint DEFAULT '0'::smallint NOT NULL,
    status_4xx smallint DEFAULT '0'::smallint NOT NULL,
    status_5xx smallint DEFAULT '0'::smallint NOT NULL,
    cache_hits smallint DEFAULT '0'::smallint NOT NULL,
    source character varying(32) DEFAULT 'worker'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: edge_preview_comments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_preview_comments (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    created_by_user_id character(26),
    author_label character varying(120),
    author_email character varying(255),
    selector character varying(500),
    viewport_width integer,
    url_path character varying(2048) NOT NULL,
    body text NOT NULL,
    resolved_at timestamp(0) without time zone,
    resolved_by_user_id character(26),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    parent_id character(26)
);


--
-- Name: edge_preview_review_approvals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_preview_review_approvals (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    note character varying(500),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: edge_site_access_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_site_access_rules (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    mode character varying(32) DEFAULT 'off'::character varying NOT NULL,
    password_hash character varying(255),
    password_salt character varying(64),
    password_verifier character varying(64),
    cookie_secret character varying(64) NOT NULL,
    allowed_emails jsonb,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: edge_site_env_vars; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_site_env_vars (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    key character varying(128) NOT NULL,
    value_encrypted text NOT NULL,
    scope character varying(32) DEFAULT 'production'::character varying NOT NULL,
    created_by_user_id character(26),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: edge_site_members; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_site_members (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    role character varying(16) NOT NULL,
    invited_by_user_id character(26),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: edge_usage_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_usage_snapshots (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    period_start date NOT NULL,
    period_end date NOT NULL,
    requests bigint DEFAULT '0'::bigint NOT NULL,
    bytes_egress bigint DEFAULT '0'::bigint NOT NULL,
    r2_storage_bytes bigint DEFAULT '0'::bigint NOT NULL,
    r2_class_a_ops integer DEFAULT 0 NOT NULL,
    r2_class_b_ops integer DEFAULT 0 NOT NULL,
    source character varying(32) DEFAULT 'placeholder'::character varying NOT NULL,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: edge_web_vitals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.edge_web_vitals (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    edge_deployment_id character varying(26),
    hostname character varying(255) NOT NULL,
    path character varying(2048) NOT NULL,
    lcp_ms integer,
    cls numeric(8,4),
    inp_ms integer,
    fcp_ms integer,
    ttfb_ms integer,
    country character varying(8),
    source character varying(32) DEFAULT 'browser'::character varying NOT NULL,
    occurred_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: error_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.error_events (
    id character(26) NOT NULL,
    organization_id character varying(255),
    server_id character varying(255),
    site_id character varying(255),
    source_type character varying(255) NOT NULL,
    source_id character varying(255) NOT NULL,
    category character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    detail text,
    link_url character varying(255),
    occurred_at timestamp(0) without time zone NOT NULL,
    dismissed_at timestamp(0) without time zone,
    dismissed_by character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    remediation_code character varying(255),
    reference character varying(255)
);


--
-- Name: external_secret_stores; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.external_secret_stores (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    driver character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    config text,
    resolution character varying(255) DEFAULT 'dply'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: feature_platform_overrides; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.feature_platform_overrides (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    enabled boolean NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: feature_platform_overrides_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.feature_platform_overrides_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: feature_platform_overrides_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.feature_platform_overrides_id_seq OWNED BY public.feature_platform_overrides.id;


--
-- Name: features; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.features (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    scope character varying(255) NOT NULL,
    value text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: features_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.features_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: features_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.features_id_seq OWNED BY public.features.id;


--
-- Name: firewall_rule_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.firewall_rule_templates (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    server_id character(26),
    name character varying(160) NOT NULL,
    description character varying(500),
    rules json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: forge_servers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.forge_servers (
    id character(26) NOT NULL,
    provider_credential_id character(26) NOT NULL,
    source_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    ip_address character varying(45),
    provider_label character varying(64),
    server_type character varying(128),
    php_versions json NOT NULL,
    status character varying(64),
    last_synced_at timestamp(0) without time zone,
    removed_from_source boolean DEFAULT false NOT NULL,
    source_snapshot json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: forge_sites; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.forge_sites (
    id character(26) NOT NULL,
    forge_server_id character(26) NOT NULL,
    source_id bigint NOT NULL,
    domain character varying(255) NOT NULL,
    site_type character varying(32) NOT NULL,
    php_version character varying(16),
    repository_url character varying(500),
    repository_branch character varying(255),
    web_directory character varying(500),
    status character varying(64),
    removed_from_source boolean DEFAULT false NOT NULL,
    source_snapshot json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: function_actions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.function_actions (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    kind character varying(16) DEFAULT 'code'::character varying NOT NULL,
    runtime character varying(255) DEFAULT ''::character varying NOT NULL,
    entrypoint character varying(255) DEFAULT ''::character varying NOT NULL,
    memory_mb smallint,
    timeout_ms integer,
    concurrency smallint,
    url character varying(2048),
    trigger json,
    components json,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: function_invocations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.function_invocations (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    source character varying(16) NOT NULL,
    task character varying(16),
    method character varying(12),
    path character varying(2048),
    status_code smallint,
    success boolean DEFAULT false NOT NULL,
    duration_ms integer DEFAULT 0 NOT NULL,
    cold boolean DEFAULT false NOT NULL,
    activation_id character varying(64),
    log_lines json,
    result_excerpt text,
    created_at timestamp(0) without time zone,
    context json,
    function_action_id character(26),
    state character varying(12) DEFAULT 'completed'::character varying NOT NULL,
    wait_time_ms integer,
    init_time_ms integer,
    activation json
);


--
-- Name: git_provider_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.git_provider_tokens (
    id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    provider character varying(255) NOT NULL,
    provider_id character varying(255),
    label character varying(255),
    nickname character varying(255),
    access_token text NOT NULL,
    api_base_url character varying(255),
    last_validated_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    validation_error text
);


--
-- Name: import_migration_steps; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.import_migration_steps (
    id character(26) NOT NULL,
    import_server_migration_id character(26) NOT NULL,
    import_site_migration_id character(26),
    sequence smallint NOT NULL,
    step_key character varying(64) NOT NULL,
    status character varying(16) DEFAULT 'pending'::character varying NOT NULL,
    attempts smallint DEFAULT '0'::smallint NOT NULL,
    error_message text,
    log_object_key character varying(500),
    result_data json,
    started_at timestamp(0) without time zone,
    finished_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: import_server_migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.import_server_migrations (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    provider_credential_id character(26) NOT NULL,
    source character varying(32) NOT NULL,
    source_server_id bigint NOT NULL,
    target_server_id character(26),
    status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    ssh_key_fingerprint character varying(100),
    ssh_key_public text,
    ssh_key_private_encrypted text,
    ssh_key_source_id integer,
    ssh_key_pushed_at timestamp(0) without time zone,
    ssh_key_revoked_at timestamp(0) without time zone,
    started_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    failure_summary text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    manual_review_items json,
    paused_nudge_sent_at timestamp(0) without time zone
);


--
-- Name: import_site_migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.import_site_migrations (
    id character(26) NOT NULL,
    import_server_migration_id character(26) NOT NULL,
    source character varying(32) NOT NULL,
    source_site_id bigint NOT NULL,
    target_site_id character(26),
    domain character varying(255) NOT NULL,
    site_type character varying(32) NOT NULL,
    status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    ssl_strategy character varying(16),
    source_snapshot json NOT NULL,
    staging_completed_at timestamp(0) without time zone,
    cutover_started_at timestamp(0) without time zone,
    cutover_completed_at timestamp(0) without time zone,
    failure_summary text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: incident_updates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.incident_updates (
    id character(26) NOT NULL,
    incident_id character(26) NOT NULL,
    user_id character(26),
    body text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: incidents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.incidents (
    id character(26) NOT NULL,
    status_page_id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    title character varying(255) NOT NULL,
    impact character varying(255) DEFAULT 'minor'::character varying NOT NULL,
    state character varying(255) DEFAULT 'investigating'::character varying NOT NULL,
    started_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    resolved_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: insight_digest_queue; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insight_digest_queue (
    id bigint NOT NULL,
    insight_finding_id bigint NOT NULL,
    organization_id character(26) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: insight_digest_queue_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insight_digest_queue_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insight_digest_queue_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insight_digest_queue_id_seq OWNED BY public.insight_digest_queue.id;


--
-- Name: insight_findings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insight_findings (
    id bigint NOT NULL,
    server_id character(26) NOT NULL,
    site_id character(26),
    insight_key character varying(80) NOT NULL,
    dedupe_hash character varying(64) NOT NULL,
    status character varying(24) DEFAULT 'open'::character varying NOT NULL,
    severity character varying(24) DEFAULT 'warning'::character varying NOT NULL,
    title character varying(255) NOT NULL,
    body text,
    meta json,
    detected_at timestamp(0) with time zone,
    resolved_at timestamp(0) with time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    correlation json,
    team_id character(26),
    acknowledged_at timestamp(0) with time zone,
    acknowledged_by_user_id character(26),
    kind character varying(16) DEFAULT 'problem'::character varying NOT NULL,
    ignored_at timestamp(0) with time zone,
    ignored_by_user_id character(26)
);


--
-- Name: insight_findings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insight_findings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insight_findings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insight_findings_id_seq OWNED BY public.insight_findings.id;


--
-- Name: insight_health_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insight_health_snapshots (
    id bigint NOT NULL,
    server_id character(26) NOT NULL,
    score smallint NOT NULL,
    counts json,
    captured_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: insight_health_snapshots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insight_health_snapshots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insight_health_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insight_health_snapshots_id_seq OWNED BY public.insight_health_snapshots.id;


--
-- Name: insight_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.insight_settings (
    id bigint NOT NULL,
    settingsable_type character varying(255) NOT NULL,
    settingsable_id character(26) NOT NULL,
    enabled_map json,
    parameters json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: insight_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.insight_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: insight_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.insight_settings_id_seq OWNED BY public.insight_settings.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: load_balancers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.load_balancers (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    provider_credential_id character(26),
    provider_id character varying(255),
    name character varying(255) NOT NULL,
    provider character varying(32) DEFAULT 'hetzner'::character varying NOT NULL,
    region character varying(64),
    load_balancer_type character varying(32) DEFAULT 'lb11'::character varying NOT NULL,
    algorithm character varying(32) DEFAULT 'round_robin'::character varying NOT NULL,
    status character varying(32) DEFAULT 'provisioning'::character varying NOT NULL,
    public_ipv4 character varying(45),
    public_ipv6 character varying(64),
    private_ip character varying(45),
    hetzner_network_id character varying(255),
    error_message text,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    server_id character(26)
);


--
-- Name: log_viewer_shares; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.log_viewer_shares (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    token character varying(64) NOT NULL,
    log_key character varying(255) NOT NULL,
    content text NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: lookout_projects; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lookout_projects (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    site_id character(26),
    site_binding_id character(26),
    lookout_project_id character varying(255),
    name character varying(255) NOT NULL,
    tier character varying(255) DEFAULT 'starter'::character varying NOT NULL,
    status character varying(255) DEFAULT 'provisioning'::character varying NOT NULL,
    retention_days integer,
    error_message text,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    source character varying(255)
);


--
-- Name: marketplace_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.marketplace_items (
    id character(26) NOT NULL,
    slug character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    summary text,
    category character varying(32) NOT NULL,
    recipe_type character varying(32) NOT NULL,
    payload json NOT NULL,
    sort_order smallint DEFAULT '0'::smallint NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    runtimes json,
    frameworks json
);


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: notification_channels; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_channels (
    id character(26) NOT NULL,
    owner_type character varying(255) NOT NULL,
    owner_id character(26) NOT NULL,
    type character varying(32) NOT NULL,
    label character varying(160) NOT NULL,
    config text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: notification_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_events (
    id character(26) NOT NULL,
    event_key character varying(255) NOT NULL,
    subject_type character varying(255),
    subject_id character(26),
    resource_type character varying(255),
    resource_id character(26),
    organization_id character(26),
    team_id character(26),
    actor_id character(26),
    title character varying(255) NOT NULL,
    body text,
    url text,
    severity character varying(32) DEFAULT 'info'::character varying NOT NULL,
    category character varying(64),
    supports_in_app boolean DEFAULT true NOT NULL,
    supports_email boolean DEFAULT false NOT NULL,
    supports_webhook boolean DEFAULT true NOT NULL,
    metadata json,
    occurred_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    cleared_at timestamp(0) with time zone,
    cleared_by_user_id character(26)
);


--
-- Name: notification_inbox_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_inbox_items (
    id character(26) NOT NULL,
    notification_event_id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    resource_type character varying(255),
    resource_id character(26),
    title character varying(255) NOT NULL,
    body text,
    url text,
    metadata json,
    read_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    saved_at timestamp(0) without time zone
);


--
-- Name: notification_subscriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_subscriptions (
    id character(26) NOT NULL,
    notification_channel_id character(26) NOT NULL,
    subscribable_type character varying(255) NOT NULL,
    subscribable_id character(26) NOT NULL,
    event_key character varying(80) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: notification_webhook_destinations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_webhook_destinations (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    site_id character(26),
    name character varying(120) NOT NULL,
    driver character varying(24) NOT NULL,
    webhook_url text NOT NULL,
    events json,
    enabled boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notifications (
    id uuid NOT NULL,
    type character varying(255) NOT NULL,
    notifiable_type character varying(255) NOT NULL,
    notifiable_id character varying(255) NOT NULL,
    data text NOT NULL,
    read_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: org_secret_keys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.org_secret_keys (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    public_recipient text NOT NULL,
    identity_holder character varying(255) DEFAULT 'dply'::character varying NOT NULL,
    dply_identity text,
    fingerprint character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: organization_billing_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organization_billing_snapshots (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    snapshot_date date NOT NULL,
    monthly_total_cents integer DEFAULT 0 NOT NULL,
    category_breakdown json,
    fleet_counts json,
    edge_usage_cents integer DEFAULT 0 NOT NULL,
    subscription_interval character varying(16),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: organization_bundle_entitlements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organization_bundle_entitlements (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    status character varying(255) NOT NULL,
    provisioned_at timestamp(0) without time zone,
    suspended_at timestamp(0) without time zone,
    purged_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: organization_cron_job_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organization_cron_job_templates (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    cron_expression character varying(64) NOT NULL,
    command text NOT NULL,
    "user" character varying(255) DEFAULT 'root'::character varying NOT NULL,
    description text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: organization_invitations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organization_invitations (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    email character varying(255) NOT NULL,
    role character varying(255) DEFAULT 'member'::character varying NOT NULL,
    token character varying(64) NOT NULL,
    invited_by character(26) NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    team_id character(26)
);


--
-- Name: organization_secret_sites; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organization_secret_sites (
    id bigint NOT NULL,
    organization_secret_id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    key character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: organization_secret_sites_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.organization_secret_sites_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: organization_secret_sites_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.organization_secret_sites_id_seq OWNED BY public.organization_secret_sites.id;


--
-- Name: organization_secrets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organization_secrets (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    created_by_user_id character(26),
    key character varying(255) NOT NULL,
    value text NOT NULL,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: organization_ssh_keys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organization_ssh_keys (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    name character varying(120) NOT NULL,
    public_key text NOT NULL,
    provision_on_new_servers boolean DEFAULT false NOT NULL,
    created_by character(26),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: organization_supervisor_program_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organization_supervisor_program_templates (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    name character varying(160) NOT NULL,
    slug character varying(64) NOT NULL,
    program_type character varying(32) NOT NULL,
    command text NOT NULL,
    directory character varying(512) NOT NULL,
    "user" character varying(64) DEFAULT 'www-data'::character varying NOT NULL,
    numprocs smallint DEFAULT '1'::smallint NOT NULL,
    env_vars json,
    stdout_logfile character varying(512),
    stderr_logfile character varying(512),
    priority smallint,
    startsecs smallint,
    stopwaitsecs smallint,
    autorestart character varying(32),
    redirect_stderr boolean DEFAULT true NOT NULL,
    description text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: organization_user; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organization_user (
    organization_id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    role character varying(255) DEFAULT 'member'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: organizations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organizations (
    id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    email character varying(255),
    stripe_id character varying(255),
    pm_type character varying(255),
    pm_last_four character varying(4),
    trial_ends_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deploy_email_notifications_enabled boolean DEFAULT true NOT NULL,
    server_site_preferences json,
    default_site_script_id character(26),
    cron_maintenance_until timestamp(0) without time zone,
    cron_maintenance_note character varying(500),
    insights_preferences json,
    firewall_settings json,
    services_preferences json,
    database_workspace_settings json,
    email_server_credentials_enabled boolean DEFAULT false NOT NULL,
    email_database_credentials_enabled boolean DEFAULT true NOT NULL,
    edge_data_region character varying(16) DEFAULT 'default'::character varying NOT NULL,
    alert_slack_webhook_url character varying(500),
    alert_extra_emails json,
    invoice_email character varying(255),
    vat_number character varying(64),
    billing_currency character varying(8),
    billing_details text,
    beta_joined_at timestamp(0) without time zone,
    icon_path character varying(255),
    description text,
    timezone character varying(255),
    is_internal boolean DEFAULT false NOT NULL
);


--
-- Name: outbound_webhook_deliveries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.outbound_webhook_deliveries (
    id character(26) NOT NULL,
    organization_id character(26),
    server_id character(26),
    event_key character varying(100) NOT NULL,
    summary character varying(300),
    payload json NOT NULL,
    url character varying(2048),
    signed boolean DEFAULT false NOT NULL,
    signed_at integer,
    status character varying(24) NOT NULL,
    http_status smallint,
    attempt_count smallint DEFAULT '0'::smallint NOT NULL,
    response_excerpt text,
    error_message text,
    first_attempt_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: passkeys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.passkeys (
    id bigint NOT NULL,
    user_id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    credential_id character varying(255) NOT NULL,
    credential json NOT NULL,
    last_used_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: passkeys_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.passkeys_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: passkeys_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.passkeys_id_seq OWNED BY public.passkeys.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: platform_connections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.platform_connections (
    id character(26) NOT NULL,
    provider character varying(255) NOT NULL,
    config text NOT NULL,
    last_ok_at timestamp(0) without time zone,
    last_error character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ploi_servers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ploi_servers (
    id character(26) NOT NULL,
    provider_credential_id character(26) NOT NULL,
    source_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    ip_address character varying(45),
    provider_label character varying(64),
    server_type character varying(128),
    php_versions json NOT NULL,
    status character varying(64),
    last_synced_at timestamp(0) without time zone,
    removed_from_source boolean DEFAULT false NOT NULL,
    source_snapshot json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ploi_sites; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ploi_sites (
    id character(26) NOT NULL,
    ploi_server_id character(26) NOT NULL,
    source_id bigint NOT NULL,
    domain character varying(255) NOT NULL,
    site_type character varying(32) NOT NULL,
    php_version character varying(16),
    repository_url character varying(500),
    repository_branch character varying(255),
    web_directory character varying(500),
    status character varying(64),
    removed_from_source boolean DEFAULT false NOT NULL,
    source_snapshot json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: projects; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.projects (
    id character(26) NOT NULL,
    organization_id character(26),
    user_id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    kind character varying(32) DEFAULT 'byo_site'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: provider_credentials; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.provider_credentials (
    id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    provider character varying(255) NOT NULL,
    name character varying(255),
    credentials text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    organization_id character(26),
    last_validated_at timestamp(0) without time zone,
    validation_error text
);


--
-- Name: pulse_aggregates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pulse_aggregates (
    id bigint NOT NULL,
    bucket integer NOT NULL,
    period integer NOT NULL,
    type character varying(255) NOT NULL,
    key text NOT NULL,
    key_hash uuid GENERATED ALWAYS AS ((md5(key))::uuid) STORED NOT NULL,
    aggregate character varying(255) NOT NULL,
    value numeric(20,2) NOT NULL,
    count integer
);


--
-- Name: pulse_aggregates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pulse_aggregates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pulse_aggregates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pulse_aggregates_id_seq OWNED BY public.pulse_aggregates.id;


--
-- Name: pulse_entries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pulse_entries (
    id bigint NOT NULL,
    "timestamp" integer NOT NULL,
    type character varying(255) NOT NULL,
    key text NOT NULL,
    key_hash uuid GENERATED ALWAYS AS ((md5(key))::uuid) STORED NOT NULL,
    value bigint
);


--
-- Name: pulse_entries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pulse_entries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pulse_entries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pulse_entries_id_seq OWNED BY public.pulse_entries.id;


--
-- Name: pulse_values; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pulse_values (
    id bigint NOT NULL,
    "timestamp" integer NOT NULL,
    type character varying(255) NOT NULL,
    key text NOT NULL,
    key_hash uuid GENERATED ALWAYS AS ((md5(key))::uuid) STORED NOT NULL,
    value text NOT NULL
);


--
-- Name: pulse_values_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pulse_values_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pulse_values_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pulse_values_id_seq OWNED BY public.pulse_values.id;


--
-- Name: recent_resources; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.recent_resources (
    id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    resource_type character varying(255) NOT NULL,
    resource_id character varying(255) NOT NULL,
    visited_at timestamp(0) without time zone NOT NULL
);


--
-- Name: referral_rewards; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.referral_rewards (
    id character(26) NOT NULL,
    referrer_user_id character(26) NOT NULL,
    referred_user_id character(26) NOT NULL,
    referrer_organization_id character(26),
    bonus_credit_cents integer DEFAULT 0 NOT NULL,
    stripe_balance_transaction_id character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: remote_cli_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.remote_cli_runs (
    id bigint NOT NULL,
    site_id character(26) NOT NULL,
    kind character varying(16) NOT NULL,
    command character varying(200) NOT NULL,
    args json,
    risk character varying(32) NOT NULL,
    mode character varying(16) NOT NULL,
    status character varying(16) NOT NULL,
    exit_code smallint,
    stdout text,
    stderr text,
    queued_by_user_id character(26),
    started_at timestamp(0) without time zone,
    finished_at timestamp(0) without time zone,
    cancelled_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: remote_cli_runs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.remote_cli_runs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: remote_cli_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.remote_cli_runs_id_seq OWNED BY public.remote_cli_runs.id;


--
-- Name: scripts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.scripts (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    content text NOT NULL,
    run_as_user character varying(64),
    source character varying(32) DEFAULT 'user_created'::character varying NOT NULL,
    marketplace_key character varying(64),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_authorized_keys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_authorized_keys (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    name character varying(120) NOT NULL,
    public_key text NOT NULL,
    synced_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    managed_key_type character varying(255),
    managed_key_id character(26),
    target_linux_user character varying(64) DEFAULT ''::character varying NOT NULL,
    review_after date,
    key_options character varying(512)
);


--
-- Name: server_cache_service_audit_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_cache_service_audit_events (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    user_id character(26),
    event character varying(64) NOT NULL,
    meta json,
    ip_address character varying(45),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_cache_services; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_cache_services (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    engine character varying(32) NOT NULL,
    version character varying(64),
    status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    port smallint DEFAULT '6379'::smallint NOT NULL,
    error_message text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    auth_password text,
    install_output text,
    cancel_requested_at timestamp(0) without time zone,
    target_engine character varying(255),
    name character varying(32) DEFAULT 'default'::character varying NOT NULL,
    cache_prefix character varying(64)
);


--
-- Name: server_create_drafts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_create_drafts (
    id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    step smallint DEFAULT '1'::smallint NOT NULL,
    payload text NOT NULL,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_cron_job_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_cron_job_runs (
    id character(26) NOT NULL,
    server_cron_job_id character(26) NOT NULL,
    run_ulid character varying(32) NOT NULL,
    trigger character varying(16) DEFAULT 'manual'::character varying NOT NULL,
    status character varying(16) DEFAULT 'running'::character varying NOT NULL,
    exit_code smallint,
    duration_ms integer,
    output text,
    error_message text,
    started_at timestamp(0) without time zone NOT NULL,
    finished_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_cron_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_cron_jobs (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    cron_expression character varying(64) NOT NULL,
    command text NOT NULL,
    "user" character varying(255) DEFAULT 'root'::character varying NOT NULL,
    is_synced boolean DEFAULT false NOT NULL,
    last_sync_error text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    enabled boolean DEFAULT true NOT NULL,
    description text,
    site_id character(26),
    last_run_at timestamp(0) without time zone,
    last_run_output text,
    schedule_timezone character varying(64),
    overlap_policy character varying(24) DEFAULT 'allow'::character varying NOT NULL,
    alert_on_failure boolean DEFAULT false NOT NULL,
    alert_on_pattern_match boolean DEFAULT false NOT NULL,
    alert_pattern character varying(512),
    env_prefix text,
    depends_on_job_id character(26),
    maintenance_tag character varying(64),
    applied_template_id character(26),
    system_managed boolean DEFAULT false NOT NULL,
    managed_block character varying(32),
    managed_signature character varying(64),
    last_synced_enabled boolean
);


--
-- Name: server_database_admin_credentials; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_database_admin_credentials (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    mysql_root_username character varying(255) DEFAULT 'root'::character varying NOT NULL,
    mysql_root_password text,
    postgres_superuser character varying(255) DEFAULT 'postgres'::character varying NOT NULL,
    postgres_password text,
    postgres_use_sudo boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    mongodb_admin_username character varying(255),
    mongodb_admin_password text,
    clickhouse_admin_username character varying(255),
    clickhouse_admin_password text
);


--
-- Name: server_database_audit_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_database_audit_events (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    user_id character(26),
    event character varying(255) NOT NULL,
    meta json,
    ip_address character varying(45),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_database_backups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_database_backups (
    id character(26) NOT NULL,
    server_database_id character(26) NOT NULL,
    user_id character(26),
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    disk_path character varying(255),
    bytes bigint,
    error_message text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    storage_kind character varying(32) DEFAULT 'remote_server'::character varying NOT NULL,
    remote_path character varying(255),
    backup_configuration_id character(26),
    s3_bucket character varying(255),
    s3_key character varying(255),
    destination_path character varying(1024)
);


--
-- Name: server_database_credential_shares; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_database_credential_shares (
    id character(26) NOT NULL,
    server_database_id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    token character varying(64) NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    views_remaining smallint DEFAULT '1'::smallint NOT NULL,
    max_views smallint DEFAULT '1'::smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_database_engine_audit_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_database_engine_audit_events (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    user_id character(26),
    event character varying(64) NOT NULL,
    meta json,
    ip_address character varying(45),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_database_engines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_database_engines (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    engine character varying(32) NOT NULL,
    version character varying(32),
    is_default boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    status character varying(32) DEFAULT 'running'::character varying NOT NULL,
    port smallint DEFAULT '3306'::smallint NOT NULL,
    error_message text,
    remote_access boolean DEFAULT false NOT NULL,
    allowed_from character varying(500)
);


--
-- Name: server_database_extra_users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_database_extra_users (
    id character(26) NOT NULL,
    server_database_id character(26) NOT NULL,
    username character varying(255) NOT NULL,
    password text NOT NULL,
    host character varying(255) DEFAULT 'localhost'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_databases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_databases (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    engine character varying(255) DEFAULT 'mysql'::character varying NOT NULL,
    username character varying(255) NOT NULL,
    password text NOT NULL,
    host character varying(255) DEFAULT '127.0.0.1'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    description text,
    mysql_charset character varying(255),
    mysql_collation character varying(255),
    remote_access boolean DEFAULT false NOT NULL,
    allowed_from character varying(500),
    site_id character(26)
);


--
-- Name: server_firewall_apply_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_firewall_apply_logs (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    user_id character(26),
    api_token_id character(26),
    kind character varying(32) DEFAULT 'apply'::character varying NOT NULL,
    success boolean DEFAULT true NOT NULL,
    rules_hash character varying(64),
    rule_count smallint DEFAULT '0'::smallint NOT NULL,
    message text,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_firewall_audit_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_firewall_audit_events (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    user_id character(26),
    api_token_id character(26),
    event character varying(64) NOT NULL,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_firewall_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_firewall_rules (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    port smallint,
    protocol character varying(16) DEFAULT 'tcp'::character varying NOT NULL,
    action character varying(8) DEFAULT 'allow'::character varying NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    name character varying(160),
    source character varying(128) DEFAULT 'any'::character varying NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    profile character varying(32),
    tags json,
    runbook_url character varying(2048),
    site_id character(26),
    app_profile character varying(64),
    iface character varying(32),
    iface_direction character varying(8)
);


--
-- Name: server_firewall_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_firewall_snapshots (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    user_id character(26),
    label character varying(200),
    rules json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_log_pins; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_log_pins (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    log_key character varying(255) NOT NULL,
    line_fingerprint character varying(64) NOT NULL,
    note text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_manage_actions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_manage_actions (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    user_id character(26),
    task_name character varying(120) NOT NULL,
    label character varying(200) NOT NULL,
    status character varying(24) NOT NULL,
    started_at timestamp(0) without time zone,
    finished_at timestamp(0) without time zone,
    error_message text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    output text
);


--
-- Name: server_metric_ingest_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_metric_ingest_events (
    id bigint NOT NULL,
    source_snapshot_id bigint,
    organization_id character varying(26) NOT NULL,
    server_id character varying(26) NOT NULL,
    server_name character varying(255),
    captured_at timestamp(0) with time zone NOT NULL,
    metrics json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_metric_ingest_events_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.server_metric_ingest_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: server_metric_ingest_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.server_metric_ingest_events_id_seq OWNED BY public.server_metric_ingest_events.id;


--
-- Name: server_metric_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_metric_snapshots (
    id bigint NOT NULL,
    server_id character(26) NOT NULL,
    captured_at timestamp(0) with time zone NOT NULL,
    payload json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_metric_snapshots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.server_metric_snapshots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: server_metric_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.server_metric_snapshots_id_seq OWNED BY public.server_metric_snapshots.id;


--
-- Name: server_php_opcache_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_php_opcache_profiles (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    php_version character varying(16) NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    memory_consumption_mb integer DEFAULT 128 NOT NULL,
    interned_strings_buffer_mb integer DEFAULT 16 NOT NULL,
    max_accelerated_files integer DEFAULT 10000 NOT NULL,
    validate_timestamps boolean DEFAULT true NOT NULL,
    revalidate_freq integer DEFAULT 2 NOT NULL,
    jit character varying(16) DEFAULT 'off'::character varying NOT NULL,
    jit_buffer_size_mb integer DEFAULT 0 NOT NULL,
    extra_ini_raw text,
    status character varying(32) DEFAULT 'pending'::character varying NOT NULL,
    last_applied_at timestamp(0) without time zone,
    last_error text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_provision_artifacts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_provision_artifacts (
    id character(26) NOT NULL,
    server_provision_run_id character(26) NOT NULL,
    type character varying(255) NOT NULL,
    key character varying(255),
    label character varying(255) NOT NULL,
    content text,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_provision_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_provision_runs (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    task_id character(26),
    attempt integer DEFAULT 1 NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    rollback_status character varying(255),
    summary text,
    started_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_provision_step_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_provision_step_runs (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    server_provision_run_id character(26),
    task_id character(26),
    label_hash character varying(40) NOT NULL,
    label character varying(255) NOT NULL,
    started_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    duration_seconds integer DEFAULT 0 NOT NULL,
    resumed boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_recipes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_recipes (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    name character varying(160) NOT NULL,
    script text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_scheduler_heartbeats; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_scheduler_heartbeats (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    site_id character(26),
    scheduler_kind character varying(32) NOT NULL,
    cron_expression character varying(128) NOT NULL,
    last_tick_at timestamp(0) with time zone,
    last_exit_code smallint,
    last_duration_ms integer,
    last_memory_peak_kb integer,
    consecutive_misses smallint DEFAULT '0'::smallint NOT NULL,
    first_seen_at timestamp(0) with time zone NOT NULL,
    circuit_open boolean DEFAULT false NOT NULL,
    output_capture_enabled boolean DEFAULT true NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone
);


--
-- Name: server_ssh_key_audit_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_ssh_key_audit_events (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    user_id character(26),
    event character varying(72) NOT NULL,
    ip_address character varying(45),
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_system_users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_system_users (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    username character varying(64) NOT NULL,
    uid integer,
    home character varying(255) DEFAULT ''::character varying NOT NULL,
    shell character varying(255) DEFAULT ''::character varying NOT NULL,
    groups json NOT NULL,
    last_seen_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_systemd_notification_digest_lines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_systemd_notification_digest_lines (
    id bigint NOT NULL,
    notification_channel_id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    digest_bucket character varying(32) NOT NULL,
    unit character varying(255) NOT NULL,
    event_kind character varying(32) NOT NULL,
    line text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_systemd_notification_digest_lines_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.server_systemd_notification_digest_lines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: server_systemd_notification_digest_lines_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.server_systemd_notification_digest_lines_id_seq OWNED BY public.server_systemd_notification_digest_lines.id;


--
-- Name: server_systemd_service_audit_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_systemd_service_audit_events (
    id bigint NOT NULL,
    server_id character(26) NOT NULL,
    occurred_at timestamp(0) with time zone NOT NULL,
    kind character varying(32) NOT NULL,
    unit character varying(255) NOT NULL,
    label character varying(255) DEFAULT ''::character varying NOT NULL,
    detail text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: server_systemd_service_audit_events_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.server_systemd_service_audit_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: server_systemd_service_audit_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.server_systemd_service_audit_events_id_seq OWNED BY public.server_systemd_service_audit_events.id;


--
-- Name: server_systemd_service_states; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_systemd_service_states (
    id bigint NOT NULL,
    server_id character(26) NOT NULL,
    unit character varying(255) NOT NULL,
    label character varying(255) NOT NULL,
    active_state character varying(64) DEFAULT ''::character varying NOT NULL,
    sub_state character varying(64) DEFAULT ''::character varying NOT NULL,
    active_enter_ts text,
    version character varying(128) DEFAULT ''::character varying NOT NULL,
    is_custom boolean DEFAULT false NOT NULL,
    can_manage boolean DEFAULT false NOT NULL,
    captured_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    unit_file_state character varying(64),
    main_pid character varying(32),
    pending_action character varying(32),
    pending_action_at timestamp(0) with time zone
);


--
-- Name: server_systemd_service_states_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.server_systemd_service_states_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: server_systemd_service_states_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.server_systemd_service_states_id_seq OWNED BY public.server_systemd_service_states.id;


--
-- Name: server_webserver_audit_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_webserver_audit_events (
    id bigint NOT NULL,
    server_id character(26) NOT NULL,
    user_id character(26),
    action character varying(64) NOT NULL,
    risk character varying(32) NOT NULL,
    transport character varying(16) NOT NULL,
    summary character varying(500) NOT NULL,
    payload json,
    result_status character varying(16) NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: server_webserver_audit_events_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.server_webserver_audit_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: server_webserver_audit_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.server_webserver_audit_events_id_seq OWNED BY public.server_webserver_audit_events.id;


--
-- Name: server_webserver_cache_features; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.server_webserver_cache_features (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    webserver character varying(32) NOT NULL,
    nginx_fcgi_zone_size_mb smallint DEFAULT '100'::smallint NOT NULL,
    nginx_proxy_zone_size_mb smallint DEFAULT '100'::smallint NOT NULL,
    nginx_zone_max_size_gb smallint DEFAULT '2'::smallint NOT NULL,
    nginx_zone_inactive_minutes smallint DEFAULT '60'::smallint NOT NULL,
    apache_mod_cache_enabled boolean DEFAULT false NOT NULL,
    caddy_souin_built boolean DEFAULT false NOT NULL,
    caddy_souin_version character varying(64),
    ols_lscache_module_present boolean DEFAULT false NOT NULL,
    last_probed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: servers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.servers (
    id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    provider_credential_id character(26),
    name character varying(255) NOT NULL,
    provider character varying(255) DEFAULT 'digitalocean'::character varying NOT NULL,
    provider_id character varying(255),
    ip_address character varying(255),
    ssh_port smallint DEFAULT '22'::smallint NOT NULL,
    ssh_user character varying(255) DEFAULT 'root'::character varying NOT NULL,
    ssh_private_key text,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    region character varying(255),
    size character varying(255),
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    organization_id character(26),
    team_id character(26),
    last_health_check_at timestamp(0) without time zone,
    health_status character varying(255),
    setup_script_key character varying(255),
    setup_status character varying(255),
    scheduled_deletion_at timestamp(0) with time zone,
    workspace_id character(26),
    supervisor_package_status character varying(16),
    ssh_operational_private_key text,
    ssh_recovery_private_key text,
    hosting_backend character varying(255),
    private_ip_address character varying(45),
    hetzner_network_id character varying(255),
    private_network_id character(26),
    comped_until timestamp(0) without time zone,
    worker_pool_id character(26),
    pool_role character varying(16),
    logo_path character varying(1024)
);


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id character(26),
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: site_access_gate_passwords; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_access_gate_passwords (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    label character varying(64) NOT NULL,
    password_salt character varying(64) NOT NULL,
    password_verifier character varying(64) NOT NULL,
    sort_order smallint DEFAULT '0'::smallint NOT NULL,
    pending_removal_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: site_access_gates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_access_gates (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    method character varying(32) DEFAULT 'off'::character varying NOT NULL,
    password_salt character varying(64),
    password_verifier character varying(64),
    cookie_secret character varying(64) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: site_audit_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_audit_events (
    id bigint NOT NULL,
    site_id character(26) NOT NULL,
    user_id character(26),
    action character varying(64) NOT NULL,
    risk character varying(32) NOT NULL,
    transport character varying(16) NOT NULL,
    summary character varying(500) NOT NULL,
    payload json,
    result_status character varying(16) NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: site_audit_events_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.site_audit_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: site_audit_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.site_audit_events_id_seq OWNED BY public.site_audit_events.id;


--
-- Name: site_backends; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_backends (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    backend_site_id character(26),
    role character varying(16) DEFAULT 'replica'::character varying NOT NULL,
    weight smallint DEFAULT '100'::smallint NOT NULL,
    state character varying(32) DEFAULT 'provisioning'::character varying NOT NULL,
    drained_at timestamp(0) without time zone,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: site_basic_auth_users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_basic_auth_users (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    username character varying(128) NOT NULL,
    password_hash character varying(255) NOT NULL,
    path character varying(512) DEFAULT '/'::character varying NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    pending_removal_at timestamp(0) without time zone,
    source_file_path character varying(1024)
);


--
-- Name: site_bindings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_bindings (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    type character varying(255) NOT NULL,
    mode character varying(255) DEFAULT 'attach_existing'::character varying NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    name character varying(255),
    target_type character varying(255),
    target_id character varying(255),
    injected_env text,
    config json,
    last_error text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: site_certificates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_certificates (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    preview_domain_id character(26),
    provider_credential_id character(26),
    scope_type character varying(255) NOT NULL,
    provider_type character varying(255) NOT NULL,
    challenge_type character varying(255) NOT NULL,
    dns_provider character varying(255),
    credential_reference character varying(255),
    domains_json json NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    force_skip_dns_checks boolean DEFAULT false NOT NULL,
    enable_http3 boolean DEFAULT false NOT NULL,
    certificate_path character varying(255),
    private_key_path character varying(255),
    chain_path character varying(255),
    certificate_pem text,
    private_key_pem text,
    chain_pem text,
    csr_pem text,
    last_output text,
    requested_settings json,
    applied_settings json,
    meta json,
    expires_at timestamp(0) without time zone,
    last_requested_at timestamp(0) without time zone,
    last_installed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: site_deploy_hooks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_deploy_hooks (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    phase character varying(32) NOT NULL,
    script text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    timeout_seconds smallint DEFAULT '900'::smallint NOT NULL,
    pipeline_id character(26),
    hook_kind character varying(32) DEFAULT 'shell'::character varying NOT NULL,
    anchor character varying(32) DEFAULT 'after_clone'::character varying NOT NULL,
    anchor_step_id character(26),
    label character varying(120),
    notification_event character varying(64),
    notification_channel_id character(26),
    webhook_url text
);


--
-- Name: site_deploy_steps; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_deploy_steps (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    step_type character varying(64) NOT NULL,
    custom_command text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    timeout_seconds smallint DEFAULT '900'::smallint NOT NULL,
    phase character varying(16) DEFAULT 'build'::character varying NOT NULL,
    pipeline_id character(26),
    managed_by_manifest boolean DEFAULT false NOT NULL
);


--
-- Name: site_deploy_sync_group_sites; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_deploy_sync_group_sites (
    id character(26) NOT NULL,
    site_deploy_sync_group_id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    sort_order smallint DEFAULT '0'::smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: site_deploy_sync_groups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_deploy_sync_groups (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    leader_site_id character(26),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    rollout_mode character varying(16) DEFAULT 'parallel'::character varying NOT NULL
);


--
-- Name: site_deployment_ephemeral_credentials; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_deployment_ephemeral_credentials (
    id character(26) NOT NULL,
    site_deployment_id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    server_authorized_key_id character(26),
    public_key_fingerprint character varying(255) NOT NULL,
    private_key_encrypted text NOT NULL,
    provisioned_at timestamp(0) without time zone NOT NULL,
    revoked_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: site_deployments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_deployments (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    trigger character varying(255) NOT NULL,
    status character varying(255) NOT NULL,
    git_sha character varying(64),
    exit_code smallint,
    log_output text,
    started_at timestamp(0) without time zone,
    finished_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    idempotency_key character varying(128),
    project_id character(26) NOT NULL,
    phase_results json,
    release_folder character varying(255),
    resume_of_deployment_id character varying(26),
    skip_reason character varying(255),
    skip_rule_summary character varying(255),
    server_id character(26)
);


--
-- Name: site_domain_aliases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_domain_aliases (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    hostname character varying(255) NOT NULL,
    label character varying(255),
    sort_order integer DEFAULT 0 NOT NULL,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    comment text
);


--
-- Name: site_domains; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_domains (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    hostname character varying(255) NOT NULL,
    is_primary boolean DEFAULT false NOT NULL,
    www_redirect boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    comment text
);


--
-- Name: site_file_backups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_file_backups (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    user_id character(26),
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    disk_path character varying(512),
    bytes bigint,
    error_message text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    remote_path character varying(512),
    storage_kind character varying(255),
    backup_configuration_id character(26),
    destination_path character varying(1024)
);


--
-- Name: site_preview_domains; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_preview_domains (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    hostname character varying(255) NOT NULL,
    label character varying(255),
    zone character varying(255),
    record_name character varying(255),
    provider_type character varying(255),
    provider_record_id character varying(255),
    record_type character varying(255),
    record_data character varying(255),
    dns_status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    ssl_status character varying(255) DEFAULT 'none'::character varying NOT NULL,
    is_primary boolean DEFAULT false NOT NULL,
    auto_ssl boolean DEFAULT true NOT NULL,
    https_redirect boolean DEFAULT true NOT NULL,
    managed_by_dply boolean DEFAULT true NOT NULL,
    last_dns_checked_at timestamp(0) without time zone,
    last_ssl_checked_at timestamp(0) without time zone,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: site_processes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_processes (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    type character varying(32) NOT NULL,
    name character varying(64) NOT NULL,
    command text,
    scale smallint DEFAULT '1'::smallint NOT NULL,
    env_vars json,
    working_directory character varying(512),
    "user" character varying(64),
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    managed_by_manifest boolean DEFAULT false NOT NULL,
    meta jsonb
);


--
-- Name: site_redirects; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_redirects (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    from_path character varying(512) NOT NULL,
    to_url character varying(1024) NOT NULL,
    status_code smallint DEFAULT '301'::smallint NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    kind character varying(32) DEFAULT 'http'::character varying NOT NULL,
    response_headers json,
    comment text
);


--
-- Name: site_releases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_releases (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    folder character varying(32) NOT NULL,
    git_sha character varying(64),
    is_active boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: site_secret_residencies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_secret_residencies (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    key character varying(255) NOT NULL,
    mode character varying(255) NOT NULL,
    ciphertext text,
    store_id character(26),
    reference character varying(255),
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: site_tenant_domains; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_tenant_domains (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    hostname character varying(255) NOT NULL,
    tenant_key character varying(255),
    label character varying(255),
    sort_order integer DEFAULT 0 NOT NULL,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    comment text
);


--
-- Name: site_uptime_check_results; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_uptime_check_results (
    id character(26) NOT NULL,
    site_uptime_monitor_id character(26) NOT NULL,
    checked_at timestamp(0) without time zone NOT NULL,
    state character varying(16) NOT NULL,
    http_status smallint,
    latency_ms integer,
    error character varying(500),
    probe_worker character varying(64)
);


--
-- Name: site_uptime_incidents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_uptime_incidents (
    id character(26) NOT NULL,
    site_uptime_monitor_id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    severity character varying(16) NOT NULL,
    cause character varying(500),
    started_at timestamp(0) without time zone NOT NULL,
    resolved_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: site_uptime_monitors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_uptime_monitors (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    label character varying(120) NOT NULL,
    path character varying(2048),
    probe_region character varying(64) NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    last_checked_at timestamp(0) without time zone,
    last_ok boolean,
    last_http_status smallint,
    last_latency_ms integer,
    last_error character varying(500),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    probe_worker character varying(64),
    check_type character varying(32) DEFAULT 'http'::character varying NOT NULL,
    config json,
    last_state character varying(16),
    last_meta json
);


--
-- Name: site_webserver_config_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.site_webserver_config_profiles (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    webserver character varying(32) NOT NULL,
    mode character varying(24) DEFAULT 'layered'::character varying NOT NULL,
    before_body text,
    main_snippet_body text,
    after_body text,
    full_override_body text,
    last_applied_effective_checksum character varying(64),
    last_applied_core_hash character varying(64),
    last_applied_at timestamp(0) without time zone,
    draft_saved_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: sites; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sites (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    organization_id character(26),
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    type character varying(255) DEFAULT 'php'::character varying NOT NULL,
    document_root character varying(255),
    repository_path character varying(255),
    app_port smallint,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    ssl_status character varying(255) DEFAULT 'none'::character varying NOT NULL,
    nginx_installed_at timestamp(0) without time zone,
    ssl_installed_at timestamp(0) without time zone,
    last_deploy_at timestamp(0) without time zone,
    git_repository_url character varying(255),
    git_branch character varying(255) DEFAULT 'main'::character varying,
    git_deploy_key_private text,
    git_deploy_key_public text,
    webhook_secret text,
    post_deploy_command text,
    env_file_content text,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deploy_strategy character varying(255) DEFAULT 'simple'::character varying NOT NULL,
    releases_to_keep smallint DEFAULT '5'::smallint NOT NULL,
    nginx_extra_raw text,
    octane_port smallint,
    laravel_scheduler boolean DEFAULT false NOT NULL,
    deployment_environment character varying(255) DEFAULT 'production'::character varying NOT NULL,
    php_fpm_user character varying(255),
    webhook_allowed_ips json,
    project_id character(26) NOT NULL,
    workspace_id character(26),
    deploy_script_id character(26),
    restart_supervisor_programs_after_deploy boolean DEFAULT false NOT NULL,
    dns_provider_credential_id character(26),
    dns_zone character varying(255),
    suspended_at timestamp(0) without time zone,
    suspended_reason character varying(500),
    engine_http_cache_enabled boolean DEFAULT false NOT NULL,
    runtime_version character varying(255),
    build_command text,
    runtime character varying(32),
    start_command text,
    internal_port smallint,
    database_engine character varying(32),
    container_image character varying(500),
    container_registry character varying(100),
    container_port smallint,
    container_backend character varying(50),
    container_backend_id character varying(200),
    container_region character varying(50),
    env_synced_at timestamp(0) without time zone,
    env_cache_origin character varying(16),
    env_file_path character varying(1024),
    edge_backend character varying(255),
    edge_backend_id character varying(255),
    edge_provider_credential_id character(26),
    serverless_backend character varying(255),
    serverless_provider_credential_id character(26),
    active_deploy_pipeline_id character(26),
    scheduled_deletion_at timestamp(0) without time zone,
    logo_path character varying(1024),
    log_drain_token character varying(64),
    deploy_method character varying(255),
    parent_site_id character varying(255)
);


--
-- Name: slack_installations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.slack_installations (
    id character(26) NOT NULL,
    owner_type character varying(255) NOT NULL,
    owner_id character(26) NOT NULL,
    team_id character varying(255) NOT NULL,
    team_name character varying(255) NOT NULL,
    bot_user_id character varying(255),
    scopes character varying(1024),
    credentials text NOT NULL,
    installed_by_user_id character(26),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.snapshots (
    id bigint NOT NULL,
    site_id character(26) NOT NULL,
    destination character varying(16) NOT NULL,
    s3_bucket character varying(200),
    s3_key character varying(500),
    local_path character varying(500),
    bytes bigint,
    engine character varying(16) NOT NULL,
    reason character varying(32) NOT NULL,
    taken_by_user_id character(26),
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    status character varying(32) DEFAULT 'completed'::character varying NOT NULL,
    error_message text
);


--
-- Name: snapshots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.snapshots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.snapshots_id_seq OWNED BY public.snapshots.id;


--
-- Name: social_accounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.social_accounts (
    id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    provider character varying(255) NOT NULL,
    provider_id character varying(255) NOT NULL,
    access_token text,
    refresh_token text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    label character varying(255),
    nickname character varying(255),
    last_validated_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    validation_error text
);


--
-- Name: status_page_monitors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.status_page_monitors (
    id character(26) NOT NULL,
    status_page_id character(26) NOT NULL,
    monitorable_type character varying(255) NOT NULL,
    monitorable_id character(26) NOT NULL,
    label character varying(255),
    sort_order smallint DEFAULT '0'::smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: status_pages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.status_pages (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    description text,
    is_public boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: subscription_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_items (
    id character(26) NOT NULL,
    subscription_id character(26) NOT NULL,
    stripe_id character varying(255) NOT NULL,
    stripe_product character varying(255) NOT NULL,
    stripe_price character varying(255) NOT NULL,
    quantity integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    meter_id character varying(255),
    meter_event_name character varying(255)
);


--
-- Name: subscriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscriptions (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    type character varying(255) NOT NULL,
    stripe_id character varying(255) NOT NULL,
    stripe_status character varying(255) NOT NULL,
    stripe_price character varying(255),
    quantity integer,
    trial_ends_at timestamp(0) without time zone,
    ends_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: supervisor_program_audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supervisor_program_audit_logs (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    supervisor_program_id character(26),
    user_id character(26),
    action character varying(48) NOT NULL,
    properties json,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: supervisor_programs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supervisor_programs (
    id character(26) NOT NULL,
    server_id character(26) NOT NULL,
    site_id character(26),
    slug character varying(64) NOT NULL,
    program_type character varying(32) NOT NULL,
    command text NOT NULL,
    directory character varying(512) NOT NULL,
    "user" character varying(64) DEFAULT 'www-data'::character varying NOT NULL,
    numprocs smallint DEFAULT '1'::smallint NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    env_vars json,
    stdout_logfile character varying(512),
    priority smallint,
    startsecs smallint,
    stopwaitsecs smallint,
    autorestart character varying(32),
    redirect_stderr boolean DEFAULT true NOT NULL,
    stderr_logfile character varying(512)
);


--
-- Name: task_runner_tasks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.task_runner_tasks (
    id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    action character varying(255),
    script text,
    script_content text,
    timeout integer,
    "user" character varying(255),
    status character varying(255) NOT NULL,
    output text,
    exit_code integer,
    options json,
    instance text,
    server_id character(26),
    created_by character(26),
    started_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: team_ssh_keys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.team_ssh_keys (
    id character(26) NOT NULL,
    team_id character(26) NOT NULL,
    name character varying(120) NOT NULL,
    public_key text NOT NULL,
    provision_on_new_servers boolean DEFAULT false NOT NULL,
    created_by character(26),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: team_user; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.team_user (
    team_id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    role character varying(255) DEFAULT 'member'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: teams; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.teams (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    preferences json
);


--
-- Name: telegram_connect_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.telegram_connect_tokens (
    id character(26) NOT NULL,
    token character varying(64) NOT NULL,
    owner_type character varying(255) NOT NULL,
    owner_id character(26) NOT NULL,
    user_id character(26),
    consumed_at timestamp(0) without time zone,
    installation_id character(26),
    expires_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: telegram_installations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.telegram_installations (
    id character(26) NOT NULL,
    owner_type character varying(255) NOT NULL,
    owner_id character(26) NOT NULL,
    chat_id character varying(255) NOT NULL,
    chat_title character varying(255) NOT NULL,
    chat_type character varying(255) DEFAULT 'group'::character varying NOT NULL,
    connected_by_user_id character(26),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: user_ssh_keys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_ssh_keys (
    id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    name character varying(120) NOT NULL,
    public_key text NOT NULL,
    provision_on_new_servers boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255),
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    stripe_id character varying(255),
    pm_type character varying(255),
    pm_last_four character varying(4),
    trial_ends_at timestamp(0) without time zone,
    two_factor_secret text,
    two_factor_recovery_codes text,
    two_factor_confirmed_at timestamp(0) without time zone,
    country_code character varying(2),
    locale character varying(12),
    timezone character varying(64),
    invoice_email character varying(255),
    vat_number character varying(64),
    billing_currency character varying(3),
    billing_details text,
    referral_code character varying(64),
    referred_by_user_id character(26),
    referral_converted_at timestamp(0) without time zone,
    ui_preferences json
);


--
-- Name: webhook_delivery_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.webhook_delivery_logs (
    id character(26) NOT NULL,
    site_id character(26) NOT NULL,
    request_ip character varying(45),
    http_status smallint,
    outcome character varying(32) NOT NULL,
    detail character varying(512),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    provider_event character varying(64),
    provider_delivery_id character varying(128)
);


--
-- Name: webserver_health_thresholds; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.webserver_health_thresholds (
    id character(26) NOT NULL,
    organization_id character(26),
    server_id character(26),
    engine character varying(32),
    metric character varying(64) NOT NULL,
    comparator character varying(255) NOT NULL,
    value double precision NOT NULL,
    severity character varying(255) DEFAULT 'warning'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT webserver_health_thresholds_comparator_check CHECK (((comparator)::text = ANY (ARRAY[('gt'::character varying)::text, ('gte'::character varying)::text, ('lt'::character varying)::text, ('lte'::character varying)::text]))),
    CONSTRAINT webserver_health_thresholds_severity_check CHECK (((severity)::text = ANY (ARRAY[('warning'::character varying)::text, ('critical'::character varying)::text])))
);


--
-- Name: webserver_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.webserver_templates (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    user_id character(26),
    label character varying(255) NOT NULL,
    content text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    engine character varying(32) DEFAULT 'nginx'::character varying NOT NULL,
    content_before text,
    content_after text
);


--
-- Name: workspace_deploy_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workspace_deploy_runs (
    id character(26) NOT NULL,
    workspace_id character(26) NOT NULL,
    user_id character(26),
    status character varying(32) DEFAULT 'queued'::character varying NOT NULL,
    site_ids json,
    result_summary json,
    output text,
    started_at timestamp(0) without time zone,
    finished_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: workspace_environments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workspace_environments (
    id character(26) NOT NULL,
    workspace_id character(26) NOT NULL,
    name character varying(120) NOT NULL,
    slug character varying(120) NOT NULL,
    description text,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: workspace_label_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workspace_label_assignments (
    id character(26) NOT NULL,
    workspace_id character(26) NOT NULL,
    workspace_label_id character(26) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: workspace_labels; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workspace_labels (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    name character varying(120) NOT NULL,
    slug character varying(120) NOT NULL,
    color character varying(24) DEFAULT 'slate'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: workspace_members; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workspace_members (
    id character(26) NOT NULL,
    workspace_id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    role character varying(32) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: workspace_runbooks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workspace_runbooks (
    id character(26) NOT NULL,
    workspace_id character(26) NOT NULL,
    title character varying(160) NOT NULL,
    url character varying(500),
    body text,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: workspace_variables; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workspace_variables (
    id character(26) NOT NULL,
    workspace_id character(26) NOT NULL,
    env_key character varying(120) NOT NULL,
    env_value text,
    is_secret boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: workspace_views; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workspace_views (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    name character varying(120) NOT NULL,
    filters json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: workspaces; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workspaces (
    id character(26) NOT NULL,
    organization_id character(26) NOT NULL,
    user_id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    description text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    notes text
);


--
-- Name: coming_soon_allowed_ips id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coming_soon_allowed_ips ALTER COLUMN id SET DEFAULT nextval('public.coming_soon_allowed_ips_id_seq'::regclass);


--
-- Name: debug_allowed_ips id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.debug_allowed_ips ALTER COLUMN id SET DEFAULT nextval('public.debug_allowed_ips_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: feature_platform_overrides id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feature_platform_overrides ALTER COLUMN id SET DEFAULT nextval('public.feature_platform_overrides_id_seq'::regclass);


--
-- Name: features id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.features ALTER COLUMN id SET DEFAULT nextval('public.features_id_seq'::regclass);


--
-- Name: insight_digest_queue id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insight_digest_queue ALTER COLUMN id SET DEFAULT nextval('public.insight_digest_queue_id_seq'::regclass);


--
-- Name: insight_findings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insight_findings ALTER COLUMN id SET DEFAULT nextval('public.insight_findings_id_seq'::regclass);


--
-- Name: insight_health_snapshots id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insight_health_snapshots ALTER COLUMN id SET DEFAULT nextval('public.insight_health_snapshots_id_seq'::regclass);


--
-- Name: insight_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insight_settings ALTER COLUMN id SET DEFAULT nextval('public.insight_settings_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: organization_secret_sites id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_secret_sites ALTER COLUMN id SET DEFAULT nextval('public.organization_secret_sites_id_seq'::regclass);


--
-- Name: passkeys id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.passkeys ALTER COLUMN id SET DEFAULT nextval('public.passkeys_id_seq'::regclass);


--
-- Name: pulse_aggregates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pulse_aggregates ALTER COLUMN id SET DEFAULT nextval('public.pulse_aggregates_id_seq'::regclass);


--
-- Name: pulse_entries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pulse_entries ALTER COLUMN id SET DEFAULT nextval('public.pulse_entries_id_seq'::regclass);


--
-- Name: pulse_values id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pulse_values ALTER COLUMN id SET DEFAULT nextval('public.pulse_values_id_seq'::regclass);


--
-- Name: remote_cli_runs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.remote_cli_runs ALTER COLUMN id SET DEFAULT nextval('public.remote_cli_runs_id_seq'::regclass);


--
-- Name: server_metric_ingest_events id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_metric_ingest_events ALTER COLUMN id SET DEFAULT nextval('public.server_metric_ingest_events_id_seq'::regclass);


--
-- Name: server_metric_snapshots id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_metric_snapshots ALTER COLUMN id SET DEFAULT nextval('public.server_metric_snapshots_id_seq'::regclass);


--
-- Name: server_systemd_notification_digest_lines id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_systemd_notification_digest_lines ALTER COLUMN id SET DEFAULT nextval('public.server_systemd_notification_digest_lines_id_seq'::regclass);


--
-- Name: server_systemd_service_audit_events id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_systemd_service_audit_events ALTER COLUMN id SET DEFAULT nextval('public.server_systemd_service_audit_events_id_seq'::regclass);


--
-- Name: server_systemd_service_states id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_systemd_service_states ALTER COLUMN id SET DEFAULT nextval('public.server_systemd_service_states_id_seq'::regclass);


--
-- Name: server_webserver_audit_events id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_webserver_audit_events ALTER COLUMN id SET DEFAULT nextval('public.server_webserver_audit_events_id_seq'::regclass);


--
-- Name: site_audit_events id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_audit_events ALTER COLUMN id SET DEFAULT nextval('public.site_audit_events_id_seq'::regclass);


--
-- Name: snapshots id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.snapshots ALTER COLUMN id SET DEFAULT nextval('public.snapshots_id_seq'::regclass);


--
-- Name: api_tokens api_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_tokens
    ADD CONSTRAINT api_tokens_pkey PRIMARY KEY (id);


--
-- Name: api_tokens api_tokens_token_prefix_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_tokens
    ADD CONSTRAINT api_tokens_token_prefix_unique UNIQUE (token_prefix);


--
-- Name: app_logs app_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.app_logs
    ADD CONSTRAINT app_logs_pkey PRIMARY KEY (id);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


--
-- Name: backup_configurations backup_configurations_org_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_configurations
    ADD CONSTRAINT backup_configurations_org_name_unique UNIQUE (organization_id, name);


--
-- Name: backup_configurations backup_configurations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_configurations
    ADD CONSTRAINT backup_configurations_pkey PRIMARY KEY (id);


--
-- Name: beta_invitations beta_invitations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.beta_invitations
    ADD CONSTRAINT beta_invitations_pkey PRIMARY KEY (id);


--
-- Name: beta_invitations beta_invitations_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.beta_invitations
    ADD CONSTRAINT beta_invitations_token_unique UNIQUE (token);


--
-- Name: organization_billing_snapshots billing_snapshot_org_date_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_billing_snapshots
    ADD CONSTRAINT billing_snapshot_org_date_unique UNIQUE (organization_id, snapshot_date);


--
-- Name: billing_subscription_sync_events billing_subscription_sync_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_subscription_sync_events
    ADD CONSTRAINT billing_subscription_sync_events_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: cloud_bucket_site cloud_bucket_site_cloud_bucket_id_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cloud_bucket_site
    ADD CONSTRAINT cloud_bucket_site_cloud_bucket_id_site_id_unique UNIQUE (cloud_bucket_id, site_id);


--
-- Name: cloud_bucket_site cloud_bucket_site_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cloud_bucket_site
    ADD CONSTRAINT cloud_bucket_site_pkey PRIMARY KEY (id);


--
-- Name: cloud_database_site cloud_database_site_cloud_database_id_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cloud_database_site
    ADD CONSTRAINT cloud_database_site_cloud_database_id_site_id_unique UNIQUE (cloud_database_id, site_id);


--
-- Name: cloud_database_site cloud_database_site_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cloud_database_site
    ADD CONSTRAINT cloud_database_site_pkey PRIMARY KEY (id);


--
-- Name: cloud_deploy_tasks cloud_deploy_tasks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cloud_deploy_tasks
    ADD CONSTRAINT cloud_deploy_tasks_pkey PRIMARY KEY (id);


--
-- Name: cloud_deploy_tasks cloud_deploy_tasks_site_id_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cloud_deploy_tasks
    ADD CONSTRAINT cloud_deploy_tasks_site_id_name_unique UNIQUE (site_id, name);


--
-- Name: cloud_workers cloud_workers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cloud_workers
    ADD CONSTRAINT cloud_workers_pkey PRIMARY KEY (id);


--
-- Name: coming_soon_allowed_ips coming_soon_allowed_ips_ip_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coming_soon_allowed_ips
    ADD CONSTRAINT coming_soon_allowed_ips_ip_unique UNIQUE (ip);


--
-- Name: coming_soon_allowed_ips coming_soon_allowed_ips_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coming_soon_allowed_ips
    ADD CONSTRAINT coming_soon_allowed_ips_pkey PRIMARY KEY (id);


--
-- Name: coming_soon_signups coming_soon_signups_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coming_soon_signups
    ADD CONSTRAINT coming_soon_signups_email_unique UNIQUE (email);


--
-- Name: coming_soon_signups coming_soon_signups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.coming_soon_signups
    ADD CONSTRAINT coming_soon_signups_pkey PRIMARY KEY (id);


--
-- Name: config_revisions config_revisions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.config_revisions
    ADD CONSTRAINT config_revisions_pkey PRIMARY KEY (id);


--
-- Name: console_actions console_actions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.console_actions
    ADD CONSTRAINT console_actions_pkey PRIMARY KEY (id);


--
-- Name: debug_allowed_ips debug_allowed_ips_ip_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.debug_allowed_ips
    ADD CONSTRAINT debug_allowed_ips_ip_unique UNIQUE (ip);


--
-- Name: debug_allowed_ips debug_allowed_ips_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.debug_allowed_ips
    ADD CONSTRAINT debug_allowed_ips_pkey PRIMARY KEY (id);


--
-- Name: deploy_contract_runs deploy_contract_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.deploy_contract_runs
    ADD CONSTRAINT deploy_contract_runs_pkey PRIMARY KEY (id);


--
-- Name: device_authorizations device_authorizations_device_code_hash_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.device_authorizations
    ADD CONSTRAINT device_authorizations_device_code_hash_unique UNIQUE (device_code_hash);


--
-- Name: device_authorizations device_authorizations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.device_authorizations
    ADD CONSTRAINT device_authorizations_pkey PRIMARY KEY (id);


--
-- Name: discord_installations discord_installations_owner_type_owner_id_guild_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discord_installations
    ADD CONSTRAINT discord_installations_owner_type_owner_id_guild_id_unique UNIQUE (owner_type, owner_id, guild_id);


--
-- Name: discord_installations discord_installations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discord_installations
    ADD CONSTRAINT discord_installations_pkey PRIMARY KEY (id);


--
-- Name: dply_cache_items dply_cache_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dply_cache_items
    ADD CONSTRAINT dply_cache_items_pkey PRIMARY KEY (cache_id, key);


--
-- Name: dply_cache_usage dply_cache_usage_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dply_cache_usage
    ADD CONSTRAINT dply_cache_usage_pkey PRIMARY KEY (cache_id);


--
-- Name: dply_queue_failed_jobs dply_queue_failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dply_queue_failed_jobs
    ADD CONSTRAINT dply_queue_failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: dply_queue_jobs dply_queue_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dply_queue_jobs
    ADD CONSTRAINT dply_queue_jobs_pkey PRIMARY KEY (id);


--
-- Name: dply_queue_failed_jobs dqfj_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dply_queue_failed_jobs
    ADD CONSTRAINT dqfj_uuid_unique UNIQUE (namespace_id, job_uuid);


--
-- Name: edge_access_logs edge_access_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_access_logs
    ADD CONSTRAINT edge_access_logs_pkey PRIMARY KEY (id);


--
-- Name: edge_deploy_hooks edge_deploy_hooks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_deploy_hooks
    ADD CONSTRAINT edge_deploy_hooks_pkey PRIMARY KEY (id);


--
-- Name: edge_deploy_hooks edge_deploy_hooks_token_hash_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_deploy_hooks
    ADD CONSTRAINT edge_deploy_hooks_token_hash_unique UNIQUE (token_hash);


--
-- Name: edge_deploy_replays edge_deploy_replays_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_deploy_replays
    ADD CONSTRAINT edge_deploy_replays_pkey PRIMARY KEY (id);


--
-- Name: edge_deployments edge_deployments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_deployments
    ADD CONSTRAINT edge_deployments_pkey PRIMARY KEY (id);


--
-- Name: edge_performance_hourly edge_performance_hourly_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_performance_hourly
    ADD CONSTRAINT edge_performance_hourly_pkey PRIMARY KEY (id);


--
-- Name: edge_performance_hourly edge_performance_hourly_site_id_hour_start_source_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_performance_hourly
    ADD CONSTRAINT edge_performance_hourly_site_id_hour_start_source_unique UNIQUE (site_id, hour_start, source);


--
-- Name: edge_preview_comments edge_preview_comments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_preview_comments
    ADD CONSTRAINT edge_preview_comments_pkey PRIMARY KEY (id);


--
-- Name: edge_preview_review_approvals edge_preview_review_approvals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_preview_review_approvals
    ADD CONSTRAINT edge_preview_review_approvals_pkey PRIMARY KEY (id);


--
-- Name: edge_preview_review_approvals edge_preview_review_approvals_site_id_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_preview_review_approvals
    ADD CONSTRAINT edge_preview_review_approvals_site_id_user_id_unique UNIQUE (site_id, user_id);


--
-- Name: edge_site_access_rules edge_site_access_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_site_access_rules
    ADD CONSTRAINT edge_site_access_rules_pkey PRIMARY KEY (id);


--
-- Name: edge_site_access_rules edge_site_access_rules_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_site_access_rules
    ADD CONSTRAINT edge_site_access_rules_site_id_unique UNIQUE (site_id);


--
-- Name: edge_site_env_vars edge_site_env_vars_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_site_env_vars
    ADD CONSTRAINT edge_site_env_vars_pkey PRIMARY KEY (id);


--
-- Name: edge_site_env_vars edge_site_env_vars_site_id_scope_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_site_env_vars
    ADD CONSTRAINT edge_site_env_vars_site_id_scope_key_unique UNIQUE (site_id, scope, key);


--
-- Name: edge_site_members edge_site_members_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_site_members
    ADD CONSTRAINT edge_site_members_pkey PRIMARY KEY (id);


--
-- Name: edge_site_members edge_site_members_site_id_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_site_members
    ADD CONSTRAINT edge_site_members_site_id_user_id_unique UNIQUE (site_id, user_id);


--
-- Name: edge_usage_snapshots edge_usage_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_usage_snapshots
    ADD CONSTRAINT edge_usage_snapshots_pkey PRIMARY KEY (id);


--
-- Name: edge_usage_snapshots edge_usage_snapshots_site_id_period_start_source_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_usage_snapshots
    ADD CONSTRAINT edge_usage_snapshots_site_id_period_start_source_unique UNIQUE (site_id, period_start, source);


--
-- Name: edge_web_vitals edge_web_vitals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_web_vitals
    ADD CONSTRAINT edge_web_vitals_pkey PRIMARY KEY (id);


--
-- Name: error_events error_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.error_events
    ADD CONSTRAINT error_events_pkey PRIMARY KEY (id);


--
-- Name: error_events error_events_source_type_source_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.error_events
    ADD CONSTRAINT error_events_source_type_source_id_unique UNIQUE (source_type, source_id);


--
-- Name: external_secret_stores external_secret_stores_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.external_secret_stores
    ADD CONSTRAINT external_secret_stores_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: feature_platform_overrides feature_platform_overrides_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feature_platform_overrides
    ADD CONSTRAINT feature_platform_overrides_name_unique UNIQUE (name);


--
-- Name: feature_platform_overrides feature_platform_overrides_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feature_platform_overrides
    ADD CONSTRAINT feature_platform_overrides_pkey PRIMARY KEY (id);


--
-- Name: features features_name_scope_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.features
    ADD CONSTRAINT features_name_scope_unique UNIQUE (name, scope);


--
-- Name: features features_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.features
    ADD CONSTRAINT features_pkey PRIMARY KEY (id);


--
-- Name: firewall_rule_templates firewall_rule_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.firewall_rule_templates
    ADD CONSTRAINT firewall_rule_templates_pkey PRIMARY KEY (id);


--
-- Name: forge_servers forge_servers_credential_source_unq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.forge_servers
    ADD CONSTRAINT forge_servers_credential_source_unq UNIQUE (provider_credential_id, source_id);


--
-- Name: forge_servers forge_servers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.forge_servers
    ADD CONSTRAINT forge_servers_pkey PRIMARY KEY (id);


--
-- Name: forge_sites forge_sites_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.forge_sites
    ADD CONSTRAINT forge_sites_pkey PRIMARY KEY (id);


--
-- Name: forge_sites forge_sites_server_source_unq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.forge_sites
    ADD CONSTRAINT forge_sites_server_source_unq UNIQUE (forge_server_id, source_id);


--
-- Name: function_actions function_actions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.function_actions
    ADD CONSTRAINT function_actions_pkey PRIMARY KEY (id);


--
-- Name: function_actions function_actions_site_id_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.function_actions
    ADD CONSTRAINT function_actions_site_id_name_unique UNIQUE (site_id, name);


--
-- Name: function_invocations function_invocations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.function_invocations
    ADD CONSTRAINT function_invocations_pkey PRIMARY KEY (id);


--
-- Name: git_provider_tokens git_provider_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.git_provider_tokens
    ADD CONSTRAINT git_provider_tokens_pkey PRIMARY KEY (id);


--
-- Name: import_migration_steps import_migration_steps_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_migration_steps
    ADD CONSTRAINT import_migration_steps_pkey PRIMARY KEY (id);


--
-- Name: import_server_migrations import_server_migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_server_migrations
    ADD CONSTRAINT import_server_migrations_pkey PRIMARY KEY (id);


--
-- Name: import_site_migrations import_site_migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_site_migrations
    ADD CONSTRAINT import_site_migrations_pkey PRIMARY KEY (id);


--
-- Name: incident_updates incident_updates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.incident_updates
    ADD CONSTRAINT incident_updates_pkey PRIMARY KEY (id);


--
-- Name: incidents incidents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.incidents
    ADD CONSTRAINT incidents_pkey PRIMARY KEY (id);


--
-- Name: insight_digest_queue insight_digest_queue_insight_finding_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insight_digest_queue
    ADD CONSTRAINT insight_digest_queue_insight_finding_id_unique UNIQUE (insight_finding_id);


--
-- Name: insight_digest_queue insight_digest_queue_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insight_digest_queue
    ADD CONSTRAINT insight_digest_queue_pkey PRIMARY KEY (id);


--
-- Name: insight_findings insight_findings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insight_findings
    ADD CONSTRAINT insight_findings_pkey PRIMARY KEY (id);


--
-- Name: insight_health_snapshots insight_health_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insight_health_snapshots
    ADD CONSTRAINT insight_health_snapshots_pkey PRIMARY KEY (id);


--
-- Name: insight_settings insight_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insight_settings
    ADD CONSTRAINT insight_settings_pkey PRIMARY KEY (id);


--
-- Name: notification_webhook_destinations integration_outbound_webhooks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_webhook_destinations
    ADD CONSTRAINT integration_outbound_webhooks_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: load_balancers load_balancers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.load_balancers
    ADD CONSTRAINT load_balancers_pkey PRIMARY KEY (id);


--
-- Name: log_viewer_shares log_viewer_shares_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.log_viewer_shares
    ADD CONSTRAINT log_viewer_shares_pkey PRIMARY KEY (id);


--
-- Name: log_viewer_shares log_viewer_shares_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.log_viewer_shares
    ADD CONSTRAINT log_viewer_shares_token_unique UNIQUE (token);


--
-- Name: lookout_projects lookout_projects_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lookout_projects
    ADD CONSTRAINT lookout_projects_pkey PRIMARY KEY (id);


--
-- Name: marketplace_items marketplace_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketplace_items
    ADD CONSTRAINT marketplace_items_pkey PRIMARY KEY (id);


--
-- Name: marketplace_items marketplace_items_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketplace_items
    ADD CONSTRAINT marketplace_items_slug_unique UNIQUE (slug);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: notification_channels notification_channels_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_channels
    ADD CONSTRAINT notification_channels_pkey PRIMARY KEY (id);


--
-- Name: notification_events notification_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_events
    ADD CONSTRAINT notification_events_pkey PRIMARY KEY (id);


--
-- Name: notification_inbox_items notification_inbox_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_inbox_items
    ADD CONSTRAINT notification_inbox_items_pkey PRIMARY KEY (id);


--
-- Name: notification_subscriptions notification_subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_subscriptions
    ADD CONSTRAINT notification_subscriptions_pkey PRIMARY KEY (id);


--
-- Name: notification_subscriptions notification_subscriptions_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_subscriptions
    ADD CONSTRAINT notification_subscriptions_unique UNIQUE (notification_channel_id, subscribable_type, subscribable_id, event_key);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: org_secret_keys org_secret_keys_organization_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_secret_keys
    ADD CONSTRAINT org_secret_keys_organization_id_unique UNIQUE (organization_id);


--
-- Name: org_secret_keys org_secret_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_secret_keys
    ADD CONSTRAINT org_secret_keys_pkey PRIMARY KEY (id);


--
-- Name: organization_billing_snapshots organization_billing_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_billing_snapshots
    ADD CONSTRAINT organization_billing_snapshots_pkey PRIMARY KEY (id);


--
-- Name: organization_bundle_entitlements organization_bundle_entitlements_organization_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_bundle_entitlements
    ADD CONSTRAINT organization_bundle_entitlements_organization_id_unique UNIQUE (organization_id);


--
-- Name: organization_bundle_entitlements organization_bundle_entitlements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_bundle_entitlements
    ADD CONSTRAINT organization_bundle_entitlements_pkey PRIMARY KEY (id);


--
-- Name: organization_cron_job_templates organization_cron_job_templates_organization_id_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_cron_job_templates
    ADD CONSTRAINT organization_cron_job_templates_organization_id_name_unique UNIQUE (organization_id, name);


--
-- Name: organization_cron_job_templates organization_cron_job_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_cron_job_templates
    ADD CONSTRAINT organization_cron_job_templates_pkey PRIMARY KEY (id);


--
-- Name: organization_invitations organization_invitations_organization_id_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_invitations
    ADD CONSTRAINT organization_invitations_organization_id_email_unique UNIQUE (organization_id, email);


--
-- Name: organization_invitations organization_invitations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_invitations
    ADD CONSTRAINT organization_invitations_pkey PRIMARY KEY (id);


--
-- Name: organization_invitations organization_invitations_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_invitations
    ADD CONSTRAINT organization_invitations_token_unique UNIQUE (token);


--
-- Name: organization_secret_sites organization_secret_sites_organization_secret_id_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_secret_sites
    ADD CONSTRAINT organization_secret_sites_organization_secret_id_site_id_unique UNIQUE (organization_secret_id, site_id);


--
-- Name: organization_secret_sites organization_secret_sites_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_secret_sites
    ADD CONSTRAINT organization_secret_sites_pkey PRIMARY KEY (id);


--
-- Name: organization_secret_sites organization_secret_sites_site_id_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_secret_sites
    ADD CONSTRAINT organization_secret_sites_site_id_key_unique UNIQUE (site_id, key);


--
-- Name: organization_secrets organization_secrets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_secrets
    ADD CONSTRAINT organization_secrets_pkey PRIMARY KEY (id);


--
-- Name: organization_ssh_keys organization_ssh_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_ssh_keys
    ADD CONSTRAINT organization_ssh_keys_pkey PRIMARY KEY (id);


--
-- Name: organization_supervisor_program_templates organization_supervisor_program_templates_organization_id_slug_; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_supervisor_program_templates
    ADD CONSTRAINT organization_supervisor_program_templates_organization_id_slug_ UNIQUE (organization_id, slug);


--
-- Name: organization_supervisor_program_templates organization_supervisor_program_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_supervisor_program_templates
    ADD CONSTRAINT organization_supervisor_program_templates_pkey PRIMARY KEY (id);


--
-- Name: organization_user organization_user_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_user
    ADD CONSTRAINT organization_user_pkey PRIMARY KEY (organization_id, user_id);


--
-- Name: organizations organizations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_pkey PRIMARY KEY (id);


--
-- Name: organizations organizations_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_slug_unique UNIQUE (slug);


--
-- Name: outbound_webhook_deliveries outbound_webhook_deliveries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.outbound_webhook_deliveries
    ADD CONSTRAINT outbound_webhook_deliveries_pkey PRIMARY KEY (id);


--
-- Name: passkeys passkeys_credential_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.passkeys
    ADD CONSTRAINT passkeys_credential_id_unique UNIQUE (credential_id);


--
-- Name: passkeys passkeys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.passkeys
    ADD CONSTRAINT passkeys_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: platform_connections platform_connections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.platform_connections
    ADD CONSTRAINT platform_connections_pkey PRIMARY KEY (id);


--
-- Name: platform_connections platform_connections_provider_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.platform_connections
    ADD CONSTRAINT platform_connections_provider_unique UNIQUE (provider);


--
-- Name: ploi_servers ploi_servers_credential_source_unq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ploi_servers
    ADD CONSTRAINT ploi_servers_credential_source_unq UNIQUE (provider_credential_id, source_id);


--
-- Name: ploi_servers ploi_servers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ploi_servers
    ADD CONSTRAINT ploi_servers_pkey PRIMARY KEY (id);


--
-- Name: ploi_sites ploi_sites_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ploi_sites
    ADD CONSTRAINT ploi_sites_pkey PRIMARY KEY (id);


--
-- Name: ploi_sites ploi_sites_server_source_unq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ploi_sites
    ADD CONSTRAINT ploi_sites_server_source_unq UNIQUE (ploi_server_id, source_id);


--
-- Name: projects projects_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_pkey PRIMARY KEY (id);


--
-- Name: projects projects_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_slug_unique UNIQUE (slug);


--
-- Name: provider_credentials provider_credentials_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.provider_credentials
    ADD CONSTRAINT provider_credentials_pkey PRIMARY KEY (id);


--
-- Name: pulse_aggregates pulse_aggregates_bucket_period_type_aggregate_key_hash_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pulse_aggregates
    ADD CONSTRAINT pulse_aggregates_bucket_period_type_aggregate_key_hash_unique UNIQUE (bucket, period, type, aggregate, key_hash);


--
-- Name: pulse_aggregates pulse_aggregates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pulse_aggregates
    ADD CONSTRAINT pulse_aggregates_pkey PRIMARY KEY (id);


--
-- Name: pulse_entries pulse_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pulse_entries
    ADD CONSTRAINT pulse_entries_pkey PRIMARY KEY (id);


--
-- Name: pulse_values pulse_values_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pulse_values
    ADD CONSTRAINT pulse_values_pkey PRIMARY KEY (id);


--
-- Name: pulse_values pulse_values_type_key_hash_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pulse_values
    ADD CONSTRAINT pulse_values_type_key_hash_unique UNIQUE (type, key_hash);


--
-- Name: recent_resources recent_resources_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recent_resources
    ADD CONSTRAINT recent_resources_pkey PRIMARY KEY (id);


--
-- Name: recent_resources recent_resources_user_id_resource_type_resource_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recent_resources
    ADD CONSTRAINT recent_resources_user_id_resource_type_resource_id_unique UNIQUE (user_id, resource_type, resource_id);


--
-- Name: referral_rewards referral_rewards_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.referral_rewards
    ADD CONSTRAINT referral_rewards_pkey PRIMARY KEY (id);


--
-- Name: referral_rewards referral_rewards_referred_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.referral_rewards
    ADD CONSTRAINT referral_rewards_referred_user_id_unique UNIQUE (referred_user_id);


--
-- Name: remote_cli_runs remote_cli_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.remote_cli_runs
    ADD CONSTRAINT remote_cli_runs_pkey PRIMARY KEY (id);


--
-- Name: scripts scripts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scripts
    ADD CONSTRAINT scripts_pkey PRIMARY KEY (id);


--
-- Name: server_authorized_keys server_authorized_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_authorized_keys
    ADD CONSTRAINT server_authorized_keys_pkey PRIMARY KEY (id);


--
-- Name: backup_schedules server_backup_schedules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_schedules
    ADD CONSTRAINT server_backup_schedules_pkey PRIMARY KEY (id);


--
-- Name: server_cache_service_audit_events server_cache_service_audit_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_cache_service_audit_events
    ADD CONSTRAINT server_cache_service_audit_events_pkey PRIMARY KEY (id);


--
-- Name: server_cache_services server_cache_services_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_cache_services
    ADD CONSTRAINT server_cache_services_pkey PRIMARY KEY (id);


--
-- Name: server_cache_services server_cache_services_server_id_engine_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_cache_services
    ADD CONSTRAINT server_cache_services_server_id_engine_unique UNIQUE (server_id, engine);


--
-- Name: server_create_drafts server_create_drafts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_create_drafts
    ADD CONSTRAINT server_create_drafts_pkey PRIMARY KEY (id);


--
-- Name: server_create_drafts server_create_drafts_user_id_organization_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_create_drafts
    ADD CONSTRAINT server_create_drafts_user_id_organization_id_unique UNIQUE (user_id, organization_id);


--
-- Name: server_cron_job_runs server_cron_job_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_cron_job_runs
    ADD CONSTRAINT server_cron_job_runs_pkey PRIMARY KEY (id);


--
-- Name: server_cron_jobs server_cron_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_cron_jobs
    ADD CONSTRAINT server_cron_jobs_pkey PRIMARY KEY (id);


--
-- Name: server_cron_jobs server_cron_jobs_server_signature_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_cron_jobs
    ADD CONSTRAINT server_cron_jobs_server_signature_unique UNIQUE (server_id, managed_signature);


--
-- Name: server_database_admin_credentials server_database_admin_credentials_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_admin_credentials
    ADD CONSTRAINT server_database_admin_credentials_pkey PRIMARY KEY (id);


--
-- Name: server_database_admin_credentials server_database_admin_credentials_server_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_admin_credentials
    ADD CONSTRAINT server_database_admin_credentials_server_id_unique UNIQUE (server_id);


--
-- Name: server_database_audit_events server_database_audit_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_audit_events
    ADD CONSTRAINT server_database_audit_events_pkey PRIMARY KEY (id);


--
-- Name: server_database_backups server_database_backups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_backups
    ADD CONSTRAINT server_database_backups_pkey PRIMARY KEY (id);


--
-- Name: server_database_credential_shares server_database_credential_shares_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_credential_shares
    ADD CONSTRAINT server_database_credential_shares_pkey PRIMARY KEY (id);


--
-- Name: server_database_credential_shares server_database_credential_shares_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_credential_shares
    ADD CONSTRAINT server_database_credential_shares_token_unique UNIQUE (token);


--
-- Name: server_database_engine_audit_events server_database_engine_audit_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_engine_audit_events
    ADD CONSTRAINT server_database_engine_audit_events_pkey PRIMARY KEY (id);


--
-- Name: server_database_engines server_database_engines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_engines
    ADD CONSTRAINT server_database_engines_pkey PRIMARY KEY (id);


--
-- Name: server_database_engines server_database_engines_server_id_engine_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_engines
    ADD CONSTRAINT server_database_engines_server_id_engine_unique UNIQUE (server_id, engine);


--
-- Name: server_database_extra_users server_database_extra_users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_extra_users
    ADD CONSTRAINT server_database_extra_users_pkey PRIMARY KEY (id);


--
-- Name: server_database_extra_users server_database_extra_users_server_database_id_username_host_un; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_extra_users
    ADD CONSTRAINT server_database_extra_users_server_database_id_username_host_un UNIQUE (server_database_id, username, host);


--
-- Name: server_databases server_databases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_databases
    ADD CONSTRAINT server_databases_pkey PRIMARY KEY (id);


--
-- Name: server_databases server_databases_server_id_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_databases
    ADD CONSTRAINT server_databases_server_id_name_unique UNIQUE (server_id, name);


--
-- Name: server_firewall_apply_logs server_firewall_apply_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_firewall_apply_logs
    ADD CONSTRAINT server_firewall_apply_logs_pkey PRIMARY KEY (id);


--
-- Name: server_firewall_audit_events server_firewall_audit_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_firewall_audit_events
    ADD CONSTRAINT server_firewall_audit_events_pkey PRIMARY KEY (id);


--
-- Name: server_firewall_rules server_firewall_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_firewall_rules
    ADD CONSTRAINT server_firewall_rules_pkey PRIMARY KEY (id);


--
-- Name: server_firewall_snapshots server_firewall_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_firewall_snapshots
    ADD CONSTRAINT server_firewall_snapshots_pkey PRIMARY KEY (id);


--
-- Name: server_log_pins server_log_pins_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_log_pins
    ADD CONSTRAINT server_log_pins_pkey PRIMARY KEY (id);


--
-- Name: server_log_pins server_log_pins_unique_line; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_log_pins
    ADD CONSTRAINT server_log_pins_unique_line UNIQUE (server_id, user_id, log_key, line_fingerprint);


--
-- Name: server_manage_actions server_manage_actions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_manage_actions
    ADD CONSTRAINT server_manage_actions_pkey PRIMARY KEY (id);


--
-- Name: server_metric_ingest_events server_metric_ingest_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_metric_ingest_events
    ADD CONSTRAINT server_metric_ingest_events_pkey PRIMARY KEY (id);


--
-- Name: server_metric_snapshots server_metric_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_metric_snapshots
    ADD CONSTRAINT server_metric_snapshots_pkey PRIMARY KEY (id);


--
-- Name: server_php_opcache_profiles server_php_opcache_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_php_opcache_profiles
    ADD CONSTRAINT server_php_opcache_profiles_pkey PRIMARY KEY (id);


--
-- Name: server_php_opcache_profiles server_php_opcache_profiles_server_id_php_version_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_php_opcache_profiles
    ADD CONSTRAINT server_php_opcache_profiles_server_id_php_version_unique UNIQUE (server_id, php_version);


--
-- Name: server_provision_artifacts server_provision_artifacts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_provision_artifacts
    ADD CONSTRAINT server_provision_artifacts_pkey PRIMARY KEY (id);


--
-- Name: server_provision_artifacts server_provision_artifacts_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_provision_artifacts
    ADD CONSTRAINT server_provision_artifacts_unique UNIQUE (server_provision_run_id, type, key);


--
-- Name: server_provision_runs server_provision_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_provision_runs
    ADD CONSTRAINT server_provision_runs_pkey PRIMARY KEY (id);


--
-- Name: server_provision_step_runs server_provision_step_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_provision_step_runs
    ADD CONSTRAINT server_provision_step_runs_pkey PRIMARY KEY (id);


--
-- Name: server_recipes server_recipes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_recipes
    ADD CONSTRAINT server_recipes_pkey PRIMARY KEY (id);


--
-- Name: server_scheduler_heartbeats server_scheduler_heartbeats_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_scheduler_heartbeats
    ADD CONSTRAINT server_scheduler_heartbeats_pkey PRIMARY KEY (id);


--
-- Name: server_scheduler_heartbeats server_scheduler_heartbeats_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_scheduler_heartbeats
    ADD CONSTRAINT server_scheduler_heartbeats_unique UNIQUE (server_id, site_id, scheduler_kind);


--
-- Name: server_ssh_key_audit_events server_ssh_key_audit_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_ssh_key_audit_events
    ADD CONSTRAINT server_ssh_key_audit_events_pkey PRIMARY KEY (id);


--
-- Name: server_system_users server_system_users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_system_users
    ADD CONSTRAINT server_system_users_pkey PRIMARY KEY (id);


--
-- Name: server_system_users server_system_users_server_username_unq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_system_users
    ADD CONSTRAINT server_system_users_server_username_unq UNIQUE (server_id, username);


--
-- Name: server_systemd_notification_digest_lines server_systemd_notification_digest_lines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_systemd_notification_digest_lines
    ADD CONSTRAINT server_systemd_notification_digest_lines_pkey PRIMARY KEY (id);


--
-- Name: server_systemd_service_audit_events server_systemd_service_audit_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_systemd_service_audit_events
    ADD CONSTRAINT server_systemd_service_audit_events_pkey PRIMARY KEY (id);


--
-- Name: server_systemd_service_states server_systemd_service_states_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_systemd_service_states
    ADD CONSTRAINT server_systemd_service_states_pkey PRIMARY KEY (id);


--
-- Name: server_systemd_service_states server_systemd_service_states_server_id_unit_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_systemd_service_states
    ADD CONSTRAINT server_systemd_service_states_server_id_unit_unique UNIQUE (server_id, unit);


--
-- Name: server_webserver_audit_events server_webserver_audit_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_webserver_audit_events
    ADD CONSTRAINT server_webserver_audit_events_pkey PRIMARY KEY (id);


--
-- Name: server_webserver_cache_features server_webserver_cache_features_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_webserver_cache_features
    ADD CONSTRAINT server_webserver_cache_features_pkey PRIMARY KEY (id);


--
-- Name: server_webserver_cache_features server_webserver_cache_features_server_id_webserver_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_webserver_cache_features
    ADD CONSTRAINT server_webserver_cache_features_server_id_webserver_unique UNIQUE (server_id, webserver);


--
-- Name: servers servers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.servers
    ADD CONSTRAINT servers_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: site_access_gate_passwords site_access_gate_passwords_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_access_gate_passwords
    ADD CONSTRAINT site_access_gate_passwords_pkey PRIMARY KEY (id);


--
-- Name: site_access_gate_passwords site_access_gate_passwords_site_id_label_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_access_gate_passwords
    ADD CONSTRAINT site_access_gate_passwords_site_id_label_unique UNIQUE (site_id, label);


--
-- Name: site_access_gates site_access_gates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_access_gates
    ADD CONSTRAINT site_access_gates_pkey PRIMARY KEY (id);


--
-- Name: site_access_gates site_access_gates_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_access_gates
    ADD CONSTRAINT site_access_gates_site_id_unique UNIQUE (site_id);


--
-- Name: site_audit_events site_audit_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_audit_events
    ADD CONSTRAINT site_audit_events_pkey PRIMARY KEY (id);


--
-- Name: site_backends site_backends_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_backends
    ADD CONSTRAINT site_backends_pkey PRIMARY KEY (id);


--
-- Name: site_backends site_backends_site_id_server_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_backends
    ADD CONSTRAINT site_backends_site_id_server_id_unique UNIQUE (site_id, server_id);


--
-- Name: site_basic_auth_users site_basic_auth_users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_basic_auth_users
    ADD CONSTRAINT site_basic_auth_users_pkey PRIMARY KEY (id);


--
-- Name: site_basic_auth_users site_basic_auth_users_site_id_username_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_basic_auth_users
    ADD CONSTRAINT site_basic_auth_users_site_id_username_unique UNIQUE (site_id, username);


--
-- Name: site_bindings site_bindings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_bindings
    ADD CONSTRAINT site_bindings_pkey PRIMARY KEY (id);


--
-- Name: site_bindings site_bindings_site_id_type_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_bindings
    ADD CONSTRAINT site_bindings_site_id_type_name_unique UNIQUE (site_id, type, name);


--
-- Name: site_certificates site_certificates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_certificates
    ADD CONSTRAINT site_certificates_pkey PRIMARY KEY (id);


--
-- Name: site_deploy_hooks site_deploy_hooks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deploy_hooks
    ADD CONSTRAINT site_deploy_hooks_pkey PRIMARY KEY (id);


--
-- Name: site_deploy_steps site_deploy_steps_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deploy_steps
    ADD CONSTRAINT site_deploy_steps_pkey PRIMARY KEY (id);


--
-- Name: site_deploy_sync_group_sites site_deploy_sync_group_sites_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deploy_sync_group_sites
    ADD CONSTRAINT site_deploy_sync_group_sites_pkey PRIMARY KEY (id);


--
-- Name: site_deploy_sync_group_sites site_deploy_sync_group_sites_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deploy_sync_group_sites
    ADD CONSTRAINT site_deploy_sync_group_sites_site_id_unique UNIQUE (site_id);


--
-- Name: site_deploy_sync_groups site_deploy_sync_groups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deploy_sync_groups
    ADD CONSTRAINT site_deploy_sync_groups_pkey PRIMARY KEY (id);


--
-- Name: site_deployment_ephemeral_credentials site_deployment_ephemeral_credentials_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deployment_ephemeral_credentials
    ADD CONSTRAINT site_deployment_ephemeral_credentials_pkey PRIMARY KEY (id);


--
-- Name: site_deployment_ephemeral_credentials site_deployment_ephemeral_credentials_site_deployment_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deployment_ephemeral_credentials
    ADD CONSTRAINT site_deployment_ephemeral_credentials_site_deployment_id_unique UNIQUE (site_deployment_id);


--
-- Name: site_deployments site_deployments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deployments
    ADD CONSTRAINT site_deployments_pkey PRIMARY KEY (id);


--
-- Name: site_domain_aliases site_domain_aliases_hostname_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_domain_aliases
    ADD CONSTRAINT site_domain_aliases_hostname_unique UNIQUE (hostname);


--
-- Name: site_domain_aliases site_domain_aliases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_domain_aliases
    ADD CONSTRAINT site_domain_aliases_pkey PRIMARY KEY (id);


--
-- Name: site_domains site_domains_hostname_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_domains
    ADD CONSTRAINT site_domains_hostname_unique UNIQUE (hostname);


--
-- Name: site_domains site_domains_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_domains
    ADD CONSTRAINT site_domains_pkey PRIMARY KEY (id);


--
-- Name: site_file_backups site_file_backups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_file_backups
    ADD CONSTRAINT site_file_backups_pkey PRIMARY KEY (id);


--
-- Name: site_preview_domains site_preview_domains_hostname_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_preview_domains
    ADD CONSTRAINT site_preview_domains_hostname_unique UNIQUE (hostname);


--
-- Name: site_preview_domains site_preview_domains_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_preview_domains
    ADD CONSTRAINT site_preview_domains_pkey PRIMARY KEY (id);


--
-- Name: site_processes site_processes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_processes
    ADD CONSTRAINT site_processes_pkey PRIMARY KEY (id);


--
-- Name: site_processes site_processes_site_id_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_processes
    ADD CONSTRAINT site_processes_site_id_name_unique UNIQUE (site_id, name);


--
-- Name: site_redirects site_redirects_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_redirects
    ADD CONSTRAINT site_redirects_pkey PRIMARY KEY (id);


--
-- Name: site_releases site_releases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_releases
    ADD CONSTRAINT site_releases_pkey PRIMARY KEY (id);


--
-- Name: site_releases site_releases_site_id_folder_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_releases
    ADD CONSTRAINT site_releases_site_id_folder_unique UNIQUE (site_id, folder);


--
-- Name: site_secret_residencies site_secret_residencies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_secret_residencies
    ADD CONSTRAINT site_secret_residencies_pkey PRIMARY KEY (id);


--
-- Name: site_secret_residencies site_secret_residencies_site_id_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_secret_residencies
    ADD CONSTRAINT site_secret_residencies_site_id_key_unique UNIQUE (site_id, key);


--
-- Name: site_tenant_domains site_tenant_domains_hostname_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_tenant_domains
    ADD CONSTRAINT site_tenant_domains_hostname_unique UNIQUE (hostname);


--
-- Name: site_tenant_domains site_tenant_domains_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_tenant_domains
    ADD CONSTRAINT site_tenant_domains_pkey PRIMARY KEY (id);


--
-- Name: site_uptime_check_results site_uptime_check_results_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_uptime_check_results
    ADD CONSTRAINT site_uptime_check_results_pkey PRIMARY KEY (id);


--
-- Name: site_uptime_incidents site_uptime_incidents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_uptime_incidents
    ADD CONSTRAINT site_uptime_incidents_pkey PRIMARY KEY (id);


--
-- Name: site_uptime_monitors site_uptime_monitors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_uptime_monitors
    ADD CONSTRAINT site_uptime_monitors_pkey PRIMARY KEY (id);


--
-- Name: site_webserver_config_profiles site_webserver_config_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_webserver_config_profiles
    ADD CONSTRAINT site_webserver_config_profiles_pkey PRIMARY KEY (id);


--
-- Name: site_webserver_config_profiles site_webserver_config_profiles_site_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_webserver_config_profiles
    ADD CONSTRAINT site_webserver_config_profiles_site_id_unique UNIQUE (site_id);


--
-- Name: sites sites_log_drain_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_log_drain_token_unique UNIQUE (log_drain_token);


--
-- Name: sites sites_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_pkey PRIMARY KEY (id);


--
-- Name: sites sites_project_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_project_id_unique UNIQUE (project_id);


--
-- Name: sites sites_server_id_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_server_id_slug_unique UNIQUE (server_id, slug);


--
-- Name: slack_installations slack_installations_owner_type_owner_id_team_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.slack_installations
    ADD CONSTRAINT slack_installations_owner_type_owner_id_team_id_unique UNIQUE (owner_type, owner_id, team_id);


--
-- Name: slack_installations slack_installations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.slack_installations
    ADD CONSTRAINT slack_installations_pkey PRIMARY KEY (id);


--
-- Name: snapshots snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.snapshots
    ADD CONSTRAINT snapshots_pkey PRIMARY KEY (id);


--
-- Name: social_accounts social_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.social_accounts
    ADD CONSTRAINT social_accounts_pkey PRIMARY KEY (id);


--
-- Name: social_accounts social_accounts_provider_provider_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.social_accounts
    ADD CONSTRAINT social_accounts_provider_provider_id_unique UNIQUE (provider, provider_id);


--
-- Name: server_provision_step_runs spsr_task_label_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_provision_step_runs
    ADD CONSTRAINT spsr_task_label_unique UNIQUE (task_id, label_hash);


--
-- Name: server_authorized_keys srv_auth_keys_managed_target_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_authorized_keys
    ADD CONSTRAINT srv_auth_keys_managed_target_unique UNIQUE (server_id, managed_key_type, managed_key_id, target_linux_user);


--
-- Name: status_page_monitors status_page_monitors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_page_monitors
    ADD CONSTRAINT status_page_monitors_pkey PRIMARY KEY (id);


--
-- Name: status_pages status_pages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_pages
    ADD CONSTRAINT status_pages_pkey PRIMARY KEY (id);


--
-- Name: status_pages status_pages_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_pages
    ADD CONSTRAINT status_pages_slug_unique UNIQUE (slug);


--
-- Name: subscription_items subscription_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_items
    ADD CONSTRAINT subscription_items_pkey PRIMARY KEY (id);


--
-- Name: subscription_items subscription_items_stripe_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_items
    ADD CONSTRAINT subscription_items_stripe_id_unique UNIQUE (stripe_id);


--
-- Name: subscriptions subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_pkey PRIMARY KEY (id);


--
-- Name: subscriptions subscriptions_stripe_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_stripe_id_unique UNIQUE (stripe_id);


--
-- Name: supervisor_program_audit_logs supervisor_program_audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supervisor_program_audit_logs
    ADD CONSTRAINT supervisor_program_audit_logs_pkey PRIMARY KEY (id);


--
-- Name: supervisor_programs supervisor_programs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supervisor_programs
    ADD CONSTRAINT supervisor_programs_pkey PRIMARY KEY (id);


--
-- Name: supervisor_programs supervisor_programs_server_id_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supervisor_programs
    ADD CONSTRAINT supervisor_programs_server_id_slug_unique UNIQUE (server_id, slug);


--
-- Name: task_runner_tasks task_runner_tasks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.task_runner_tasks
    ADD CONSTRAINT task_runner_tasks_pkey PRIMARY KEY (id);


--
-- Name: team_ssh_keys team_ssh_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.team_ssh_keys
    ADD CONSTRAINT team_ssh_keys_pkey PRIMARY KEY (id);


--
-- Name: team_user team_user_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.team_user
    ADD CONSTRAINT team_user_pkey PRIMARY KEY (team_id, user_id);


--
-- Name: teams teams_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teams
    ADD CONSTRAINT teams_pkey PRIMARY KEY (id);


--
-- Name: telegram_connect_tokens telegram_connect_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telegram_connect_tokens
    ADD CONSTRAINT telegram_connect_tokens_pkey PRIMARY KEY (id);


--
-- Name: telegram_connect_tokens telegram_connect_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telegram_connect_tokens
    ADD CONSTRAINT telegram_connect_tokens_token_unique UNIQUE (token);


--
-- Name: telegram_installations telegram_installations_owner_type_owner_id_chat_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telegram_installations
    ADD CONSTRAINT telegram_installations_owner_type_owner_id_chat_id_unique UNIQUE (owner_type, owner_id, chat_id);


--
-- Name: telegram_installations telegram_installations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telegram_installations
    ADD CONSTRAINT telegram_installations_pkey PRIMARY KEY (id);


--
-- Name: user_ssh_keys user_ssh_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_ssh_keys
    ADD CONSTRAINT user_ssh_keys_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_referral_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_referral_code_unique UNIQUE (referral_code);


--
-- Name: webhook_delivery_logs webhook_delivery_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webhook_delivery_logs
    ADD CONSTRAINT webhook_delivery_logs_pkey PRIMARY KEY (id);


--
-- Name: webserver_health_thresholds webserver_health_thresholds_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webserver_health_thresholds
    ADD CONSTRAINT webserver_health_thresholds_pkey PRIMARY KEY (id);


--
-- Name: webserver_templates webserver_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webserver_templates
    ADD CONSTRAINT webserver_templates_pkey PRIMARY KEY (id);


--
-- Name: workspace_deploy_runs workspace_deploy_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_deploy_runs
    ADD CONSTRAINT workspace_deploy_runs_pkey PRIMARY KEY (id);


--
-- Name: workspace_environments workspace_environments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_environments
    ADD CONSTRAINT workspace_environments_pkey PRIMARY KEY (id);


--
-- Name: workspace_environments workspace_environments_workspace_id_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_environments
    ADD CONSTRAINT workspace_environments_workspace_id_slug_unique UNIQUE (workspace_id, slug);


--
-- Name: workspace_label_assignments workspace_label_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_label_assignments
    ADD CONSTRAINT workspace_label_assignments_pkey PRIMARY KEY (id);


--
-- Name: workspace_label_assignments workspace_label_assignments_workspace_id_workspace_label_id_uni; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_label_assignments
    ADD CONSTRAINT workspace_label_assignments_workspace_id_workspace_label_id_uni UNIQUE (workspace_id, workspace_label_id);


--
-- Name: workspace_labels workspace_labels_organization_id_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_labels
    ADD CONSTRAINT workspace_labels_organization_id_slug_unique UNIQUE (organization_id, slug);


--
-- Name: workspace_labels workspace_labels_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_labels
    ADD CONSTRAINT workspace_labels_pkey PRIMARY KEY (id);


--
-- Name: workspace_members workspace_members_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_members
    ADD CONSTRAINT workspace_members_pkey PRIMARY KEY (id);


--
-- Name: workspace_members workspace_members_workspace_id_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_members
    ADD CONSTRAINT workspace_members_workspace_id_user_id_unique UNIQUE (workspace_id, user_id);


--
-- Name: workspace_runbooks workspace_runbooks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_runbooks
    ADD CONSTRAINT workspace_runbooks_pkey PRIMARY KEY (id);


--
-- Name: workspace_variables workspace_variables_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_variables
    ADD CONSTRAINT workspace_variables_pkey PRIMARY KEY (id);


--
-- Name: workspace_variables workspace_variables_workspace_id_env_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_variables
    ADD CONSTRAINT workspace_variables_workspace_id_env_key_unique UNIQUE (workspace_id, env_key);


--
-- Name: workspace_views workspace_views_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_views
    ADD CONSTRAINT workspace_views_pkey PRIMARY KEY (id);


--
-- Name: workspaces workspaces_organization_id_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspaces
    ADD CONSTRAINT workspaces_organization_id_slug_unique UNIQUE (organization_id, slug);


--
-- Name: workspaces workspaces_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspaces
    ADD CONSTRAINT workspaces_pkey PRIMARY KEY (id);


--
-- Name: api_tokens_token_prefix_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX api_tokens_token_prefix_index ON public.api_tokens USING btree (token_prefix);


--
-- Name: app_logs_level_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX app_logs_level_index ON public.app_logs USING btree (level);


--
-- Name: app_logs_site_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX app_logs_site_id_created_at_index ON public.app_logs USING btree (site_id, created_at);


--
-- Name: app_logs_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX app_logs_site_id_index ON public.app_logs USING btree (site_id);


--
-- Name: app_logs_site_id_level_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX app_logs_site_id_level_created_at_index ON public.app_logs USING btree (site_id, level, created_at);


--
-- Name: audit_logs_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_logs_created_at_index ON public.audit_logs USING btree (created_at);


--
-- Name: audit_logs_organization_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_logs_organization_id_index ON public.audit_logs USING btree (organization_id);


--
-- Name: backup_configurations_organization_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX backup_configurations_organization_id_index ON public.backup_configurations USING btree (organization_id);


--
-- Name: backup_configurations_user_id_provider_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX backup_configurations_user_id_provider_index ON public.backup_configurations USING btree (created_by_user_id, provider);


--
-- Name: backup_schedules_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX backup_schedules_is_active_index ON public.backup_schedules USING btree (is_active);


--
-- Name: backup_schedules_target_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX backup_schedules_target_index ON public.backup_schedules USING btree (target_type, target_id);


--
-- Name: beta_invitations_email_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX beta_invitations_email_index ON public.beta_invitations USING btree (email);


--
-- Name: billing_sync_events_org_created_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX billing_sync_events_org_created_idx ON public.billing_subscription_sync_events USING btree (organization_id, created_at);


--
-- Name: billing_sync_events_org_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX billing_sync_events_org_status_idx ON public.billing_subscription_sync_events USING btree (organization_id, status);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: cloud_bucket_site_cloud_bucket_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cloud_bucket_site_cloud_bucket_id_index ON public.cloud_bucket_site USING btree (cloud_bucket_id);


--
-- Name: cloud_bucket_site_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cloud_bucket_site_site_id_index ON public.cloud_bucket_site USING btree (site_id);


--
-- Name: cloud_database_site_cloud_database_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cloud_database_site_cloud_database_id_index ON public.cloud_database_site USING btree (cloud_database_id);


--
-- Name: cloud_database_site_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cloud_database_site_site_id_index ON public.cloud_database_site USING btree (site_id);


--
-- Name: cloud_deploy_tasks_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cloud_deploy_tasks_site_id_index ON public.cloud_deploy_tasks USING btree (site_id);


--
-- Name: cloud_workers_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cloud_workers_site_id_index ON public.cloud_workers USING btree (site_id);


--
-- Name: coming_soon_signups_source_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX coming_soon_signups_source_index ON public.coming_soon_signups USING btree (source);


--
-- Name: config_revisions_server_kind_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX config_revisions_server_kind_idx ON public.config_revisions USING btree (server_id, kind, created_at);


--
-- Name: config_revisions_stream_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX config_revisions_stream_idx ON public.config_revisions USING btree (stream_key, created_at);


--
-- Name: config_revisions_subject_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX config_revisions_subject_idx ON public.config_revisions USING btree (subject_type, subject_id);


--
-- Name: console_actions_subject_kind_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX console_actions_subject_kind_status_idx ON public.console_actions USING btree (subject_type, subject_id, kind, status);


--
-- Name: console_actions_subject_lookup_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX console_actions_subject_lookup_idx ON public.console_actions USING btree (subject_type, subject_id, dismissed_at, created_at);


--
-- Name: console_actions_subject_type_subject_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX console_actions_subject_type_subject_id_index ON public.console_actions USING btree (subject_type, subject_id);


--
-- Name: deploy_contract_runs_organization_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX deploy_contract_runs_organization_id_status_index ON public.deploy_contract_runs USING btree (organization_id, status);


--
-- Name: deploy_contract_runs_parent_site_id_preview_site_id_created_at_; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX deploy_contract_runs_parent_site_id_preview_site_id_created_at_ ON public.deploy_contract_runs USING btree (parent_site_id, preview_site_id, created_at);


--
-- Name: device_authorizations_status_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX device_authorizations_status_expires_at_index ON public.device_authorizations USING btree (status, expires_at);


--
-- Name: device_authorizations_user_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX device_authorizations_user_code_index ON public.device_authorizations USING btree (user_code);


--
-- Name: discord_installations_owner_type_owner_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX discord_installations_owner_type_owner_id_index ON public.discord_installations USING btree (owner_type, owner_id);


--
-- Name: dply_cache_items_expires_at_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dply_cache_items_expires_at_idx ON public.dply_cache_items USING btree (expires_at);


--
-- Name: dqfj_listing_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dqfj_listing_idx ON public.dply_queue_failed_jobs USING btree (namespace_id, failed_at);


--
-- Name: dqj_claim_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dqj_claim_idx ON public.dply_queue_jobs USING btree (namespace_id, queue, visible_at, id);


--
-- Name: dqj_stats_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX dqj_stats_idx ON public.dply_queue_jobs USING btree (namespace_id, state, visible_at);


--
-- Name: edge_access_logs_organization_id_occurred_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_access_logs_organization_id_occurred_at_index ON public.edge_access_logs USING btree (organization_id, occurred_at);


--
-- Name: edge_access_logs_site_id_occurred_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_access_logs_site_id_occurred_at_index ON public.edge_access_logs USING btree (site_id, occurred_at);


--
-- Name: edge_deploy_hooks_site_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_deploy_hooks_site_id_created_at_index ON public.edge_deploy_hooks USING btree (site_id, created_at);


--
-- Name: edge_deploy_replays_organization_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_deploy_replays_organization_id_status_index ON public.edge_deploy_replays USING btree (organization_id, status);


--
-- Name: edge_deploy_replays_parent_site_id_preview_site_id_created_at_i; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_deploy_replays_parent_site_id_preview_site_id_created_at_i ON public.edge_deploy_replays USING btree (parent_site_id, preview_site_id, created_at);


--
-- Name: edge_deployments_organization_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_deployments_organization_id_created_at_index ON public.edge_deployments USING btree (organization_id, created_at);


--
-- Name: edge_deployments_site_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_deployments_site_id_status_index ON public.edge_deployments USING btree (site_id, status);


--
-- Name: edge_performance_hourly_organization_id_hour_start_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_performance_hourly_organization_id_hour_start_index ON public.edge_performance_hourly USING btree (organization_id, hour_start);


--
-- Name: edge_preview_comments_site_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_preview_comments_site_id_created_at_index ON public.edge_preview_comments USING btree (site_id, created_at);


--
-- Name: edge_preview_comments_site_id_resolved_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_preview_comments_site_id_resolved_at_index ON public.edge_preview_comments USING btree (site_id, resolved_at);


--
-- Name: edge_preview_review_approvals_site_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_preview_review_approvals_site_id_created_at_index ON public.edge_preview_review_approvals USING btree (site_id, created_at);


--
-- Name: edge_site_members_user_id_role_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_site_members_user_id_role_index ON public.edge_site_members USING btree (user_id, role);


--
-- Name: edge_usage_snapshots_organization_id_period_start_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_usage_snapshots_organization_id_period_start_index ON public.edge_usage_snapshots USING btree (organization_id, period_start);


--
-- Name: edge_web_vitals_site_id_occurred_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX edge_web_vitals_site_id_occurred_at_index ON public.edge_web_vitals USING btree (site_id, occurred_at);


--
-- Name: error_events_category_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX error_events_category_index ON public.error_events USING btree (category);


--
-- Name: error_events_occurred_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX error_events_occurred_at_index ON public.error_events USING btree (occurred_at);


--
-- Name: error_events_organization_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX error_events_organization_id_index ON public.error_events USING btree (organization_id);


--
-- Name: error_events_reference_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX error_events_reference_index ON public.error_events USING btree (reference);


--
-- Name: error_events_remediation_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX error_events_remediation_code_index ON public.error_events USING btree (remediation_code);


--
-- Name: error_events_server_id_occurred_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX error_events_server_id_occurred_at_index ON public.error_events USING btree (server_id, occurred_at);


--
-- Name: error_events_site_id_occurred_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX error_events_site_id_occurred_at_index ON public.error_events USING btree (site_id, occurred_at);


--
-- Name: external_secret_stores_organization_id_driver_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX external_secret_stores_organization_id_driver_index ON public.external_secret_stores USING btree (organization_id, driver);


--
-- Name: firewall_rule_templates_organization_id_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX firewall_rule_templates_organization_id_server_id_index ON public.firewall_rule_templates USING btree (organization_id, server_id);


--
-- Name: forge_servers_credential_removed_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX forge_servers_credential_removed_idx ON public.forge_servers USING btree (provider_credential_id, removed_from_source);


--
-- Name: forge_sites_server_removed_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX forge_sites_server_removed_idx ON public.forge_sites USING btree (forge_server_id, removed_from_source);


--
-- Name: function_actions_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX function_actions_site_id_index ON public.function_actions USING btree (site_id);


--
-- Name: function_invocations_activation_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX function_invocations_activation_id_index ON public.function_invocations USING btree (activation_id);


--
-- Name: function_invocations_function_action_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX function_invocations_function_action_id_index ON public.function_invocations USING btree (function_action_id);


--
-- Name: function_invocations_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX function_invocations_site_id_index ON public.function_invocations USING btree (site_id);


--
-- Name: function_invocations_site_id_source_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX function_invocations_site_id_source_created_at_index ON public.function_invocations USING btree (site_id, source, created_at);


--
-- Name: function_invocations_site_id_state_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX function_invocations_site_id_state_index ON public.function_invocations USING btree (site_id, state);


--
-- Name: git_provider_tokens_user_id_provider_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX git_provider_tokens_user_id_provider_index ON public.git_provider_tokens USING btree (user_id, provider);


--
-- Name: import_migration_steps_seq_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_migration_steps_seq_idx ON public.import_migration_steps USING btree (import_server_migration_id, sequence);


--
-- Name: import_migration_steps_site_seq_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_migration_steps_site_seq_idx ON public.import_migration_steps USING btree (import_site_migration_id, sequence);


--
-- Name: import_migration_steps_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_migration_steps_status_idx ON public.import_migration_steps USING btree (import_server_migration_id, status);


--
-- Name: import_server_migrations_org_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_server_migrations_org_status_idx ON public.import_server_migrations USING btree (organization_id, status);


--
-- Name: import_server_migrations_source_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_server_migrations_source_idx ON public.import_server_migrations USING btree (source, source_server_id);


--
-- Name: import_server_migrations_target_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_server_migrations_target_idx ON public.import_server_migrations USING btree (target_server_id);


--
-- Name: import_site_migrations_parent_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_site_migrations_parent_status_idx ON public.import_site_migrations USING btree (import_server_migration_id, status);


--
-- Name: import_site_migrations_source_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_site_migrations_source_idx ON public.import_site_migrations USING btree (source, source_site_id);


--
-- Name: incident_updates_incident_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX incident_updates_incident_id_created_at_index ON public.incident_updates USING btree (incident_id, created_at);


--
-- Name: incidents_status_page_id_resolved_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX incidents_status_page_id_resolved_at_index ON public.incidents USING btree (status_page_id, resolved_at);


--
-- Name: insight_findings_banner_lookup_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insight_findings_banner_lookup_idx ON public.insight_findings USING btree (server_id, status, severity, acknowledged_at);


--
-- Name: insight_findings_kind_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insight_findings_kind_status_idx ON public.insight_findings USING btree (server_id, kind, status);


--
-- Name: insight_findings_server_id_status_insight_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insight_findings_server_id_status_insight_key_index ON public.insight_findings USING btree (server_id, status, insight_key);


--
-- Name: insight_findings_site_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insight_findings_site_id_status_index ON public.insight_findings USING btree (site_id, status);


--
-- Name: insight_health_snapshots_server_id_captured_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insight_health_snapshots_server_id_captured_at_index ON public.insight_health_snapshots USING btree (server_id, captured_at);


--
-- Name: insight_settings_settingsable_type_settingsable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX insight_settings_settingsable_type_settingsable_id_index ON public.insight_settings USING btree (settingsable_type, settingsable_id);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: load_balancers_organization_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX load_balancers_organization_id_status_index ON public.load_balancers USING btree (organization_id, status);


--
-- Name: log_viewer_shares_server_id_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX log_viewer_shares_server_id_expires_at_index ON public.log_viewer_shares USING btree (server_id, expires_at);


--
-- Name: lookout_projects_organization_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX lookout_projects_organization_id_status_index ON public.lookout_projects USING btree (organization_id, status);


--
-- Name: lookout_projects_source_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX lookout_projects_source_index ON public.lookout_projects USING btree (source);


--
-- Name: lookout_projects_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX lookout_projects_status_index ON public.lookout_projects USING btree (status);


--
-- Name: marketplace_items_category_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX marketplace_items_category_index ON public.marketplace_items USING btree (category);


--
-- Name: marketplace_items_recipe_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX marketplace_items_recipe_type_index ON public.marketplace_items USING btree (recipe_type);


--
-- Name: notif_events_resource_clear_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notif_events_resource_clear_idx ON public.notification_events USING btree (resource_type, resource_id, cleared_at);


--
-- Name: notification_channels_owner_type_owner_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_channels_owner_type_owner_id_index ON public.notification_channels USING btree (owner_type, owner_id);


--
-- Name: notification_channels_owner_type_owner_id_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_channels_owner_type_owner_id_type_index ON public.notification_channels USING btree (owner_type, owner_id, type);


--
-- Name: notification_events_event_key_resource_type_resource_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_events_event_key_resource_type_resource_id_index ON public.notification_events USING btree (event_key, resource_type, resource_id);


--
-- Name: notification_events_organization_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_events_organization_id_created_at_index ON public.notification_events USING btree (organization_id, created_at);


--
-- Name: notification_events_resource_type_resource_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_events_resource_type_resource_id_index ON public.notification_events USING btree (resource_type, resource_id);


--
-- Name: notification_events_subject_type_subject_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_events_subject_type_subject_id_index ON public.notification_events USING btree (subject_type, subject_id);


--
-- Name: notification_inbox_items_resource_type_resource_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_inbox_items_resource_type_resource_id_index ON public.notification_inbox_items USING btree (resource_type, resource_id);


--
-- Name: notification_inbox_items_saved_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_inbox_items_saved_at_index ON public.notification_inbox_items USING btree (saved_at);


--
-- Name: notification_inbox_items_user_id_read_at_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_inbox_items_user_id_read_at_created_at_index ON public.notification_inbox_items USING btree (user_id, read_at, created_at);


--
-- Name: notification_subscriptions_subscribable_type_subscribable_id_in; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_subscriptions_subscribable_type_subscribable_id_in ON public.notification_subscriptions USING btree (subscribable_type, subscribable_id);


--
-- Name: notifications_notifiable_type_notifiable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notifications_notifiable_type_notifiable_id_index ON public.notifications USING btree (notifiable_type, notifiable_id);


--
-- Name: organization_billing_snapshots_snapshot_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organization_billing_snapshots_snapshot_date_index ON public.organization_billing_snapshots USING btree (snapshot_date);


--
-- Name: organization_bundle_entitlements_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organization_bundle_entitlements_status_index ON public.organization_bundle_entitlements USING btree (status);


--
-- Name: organization_secrets_organization_id_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organization_secrets_organization_id_key_index ON public.organization_secrets USING btree (organization_id, key);


--
-- Name: organization_ssh_keys_organization_id_provision_on_new_servers_; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organization_ssh_keys_organization_id_provision_on_new_servers_ ON public.organization_ssh_keys USING btree (organization_id, provision_on_new_servers);


--
-- Name: organizations_stripe_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organizations_stripe_id_index ON public.organizations USING btree (stripe_id);


--
-- Name: outbound_webhook_deliveries_event_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX outbound_webhook_deliveries_event_key_index ON public.outbound_webhook_deliveries USING btree (event_key);


--
-- Name: outbound_webhook_deliveries_organization_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX outbound_webhook_deliveries_organization_id_index ON public.outbound_webhook_deliveries USING btree (organization_id);


--
-- Name: outbound_webhook_deliveries_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX outbound_webhook_deliveries_server_id_index ON public.outbound_webhook_deliveries USING btree (server_id);


--
-- Name: outbound_webhook_deliveries_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX outbound_webhook_deliveries_status_index ON public.outbound_webhook_deliveries USING btree (status);


--
-- Name: owd_server_recent_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX owd_server_recent_idx ON public.outbound_webhook_deliveries USING btree (server_id, created_at);


--
-- Name: passkeys_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX passkeys_user_id_index ON public.passkeys USING btree (user_id);


--
-- Name: ploi_servers_credential_removed_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ploi_servers_credential_removed_idx ON public.ploi_servers USING btree (provider_credential_id, removed_from_source);


--
-- Name: ploi_sites_server_removed_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ploi_sites_server_removed_idx ON public.ploi_sites USING btree (ploi_server_id, removed_from_source);


--
-- Name: provider_credentials_user_id_provider_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX provider_credentials_user_id_provider_index ON public.provider_credentials USING btree (user_id, provider);


--
-- Name: pulse_aggregates_period_bucket_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pulse_aggregates_period_bucket_index ON public.pulse_aggregates USING btree (period, bucket);


--
-- Name: pulse_aggregates_period_type_aggregate_bucket_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pulse_aggregates_period_type_aggregate_bucket_index ON public.pulse_aggregates USING btree (period, type, aggregate, bucket);


--
-- Name: pulse_aggregates_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pulse_aggregates_type_index ON public.pulse_aggregates USING btree (type);


--
-- Name: pulse_entries_key_hash_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pulse_entries_key_hash_index ON public.pulse_entries USING btree (key_hash);


--
-- Name: pulse_entries_timestamp_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pulse_entries_timestamp_index ON public.pulse_entries USING btree ("timestamp");


--
-- Name: pulse_entries_timestamp_type_key_hash_value_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pulse_entries_timestamp_type_key_hash_value_index ON public.pulse_entries USING btree ("timestamp", type, key_hash, value);


--
-- Name: pulse_entries_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pulse_entries_type_index ON public.pulse_entries USING btree (type);


--
-- Name: pulse_values_timestamp_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pulse_values_timestamp_index ON public.pulse_values USING btree ("timestamp");


--
-- Name: pulse_values_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pulse_values_type_index ON public.pulse_values USING btree (type);


--
-- Name: recent_resources_user_id_visited_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX recent_resources_user_id_visited_at_index ON public.recent_resources USING btree (user_id, visited_at);


--
-- Name: remote_cli_runs_site_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX remote_cli_runs_site_id_created_at_index ON public.remote_cli_runs USING btree (site_id, created_at);


--
-- Name: remote_cli_runs_site_id_kind_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX remote_cli_runs_site_id_kind_created_at_index ON public.remote_cli_runs USING btree (site_id, kind, created_at);


--
-- Name: scripts_organization_id_name_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX scripts_organization_id_name_index ON public.scripts USING btree (organization_id, name);


--
-- Name: server_authorized_keys_managed_key_type_managed_key_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_authorized_keys_managed_key_type_managed_key_id_index ON public.server_authorized_keys USING btree (managed_key_type, managed_key_id);


--
-- Name: server_backup_schedules_server_id_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_backup_schedules_server_id_is_active_index ON public.backup_schedules USING btree (server_id, is_active);


--
-- Name: server_backup_schedules_target_type_target_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_backup_schedules_target_type_target_id_index ON public.backup_schedules USING btree (target_type, target_id);


--
-- Name: server_cache_service_audit_events_server_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_cache_service_audit_events_server_id_created_at_index ON public.server_cache_service_audit_events USING btree (server_id, created_at);


--
-- Name: server_cache_services_one_redis_family_per_server; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX server_cache_services_one_redis_family_per_server ON public.server_cache_services USING btree (server_id) WHERE ((engine)::text = ANY (ARRAY[('redis'::character varying)::text, ('valkey'::character varying)::text, ('keydb'::character varying)::text, ('dragonfly'::character varying)::text]));


--
-- Name: server_create_drafts_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_create_drafts_expires_at_index ON public.server_create_drafts USING btree (expires_at);


--
-- Name: server_cron_job_runs_run_ulid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_cron_job_runs_run_ulid_index ON public.server_cron_job_runs USING btree (run_ulid);


--
-- Name: server_cron_job_runs_server_cron_job_id_started_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_cron_job_runs_server_cron_job_id_started_at_index ON public.server_cron_job_runs USING btree (server_cron_job_id, started_at);


--
-- Name: server_database_audit_events_server_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_database_audit_events_server_id_created_at_index ON public.server_database_audit_events USING btree (server_id, created_at);


--
-- Name: server_database_backups_server_database_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_database_backups_server_database_id_created_at_index ON public.server_database_backups USING btree (server_database_id, created_at);


--
-- Name: server_database_credential_shares_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_database_credential_shares_expires_at_index ON public.server_database_credential_shares USING btree (expires_at);


--
-- Name: server_database_engine_audit_events_server_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_database_engine_audit_events_server_id_created_at_index ON public.server_database_engine_audit_events USING btree (server_id, created_at);


--
-- Name: server_database_engines_server_id_is_default_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_database_engines_server_id_is_default_index ON public.server_database_engines USING btree (server_id, is_default);


--
-- Name: server_databases_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_databases_site_id_index ON public.server_databases USING btree (site_id);


--
-- Name: server_firewall_apply_logs_server_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_firewall_apply_logs_server_id_created_at_index ON public.server_firewall_apply_logs USING btree (server_id, created_at);


--
-- Name: server_firewall_audit_events_server_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_firewall_audit_events_server_id_created_at_index ON public.server_firewall_audit_events USING btree (server_id, created_at);


--
-- Name: server_firewall_snapshots_server_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_firewall_snapshots_server_id_created_at_index ON public.server_firewall_snapshots USING btree (server_id, created_at);


--
-- Name: server_log_pins_server_id_log_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_log_pins_server_id_log_key_index ON public.server_log_pins USING btree (server_id, log_key);


--
-- Name: server_manage_actions_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_manage_actions_server_id_index ON public.server_manage_actions USING btree (server_id);


--
-- Name: server_manage_actions_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_manage_actions_status_index ON public.server_manage_actions USING btree (status);


--
-- Name: server_manage_actions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_manage_actions_user_id_index ON public.server_manage_actions USING btree (user_id);


--
-- Name: server_metric_ingest_events_captured_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_metric_ingest_events_captured_at_index ON public.server_metric_ingest_events USING btree (captured_at);


--
-- Name: server_metric_ingest_events_organization_id_captured_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_metric_ingest_events_organization_id_captured_at_index ON public.server_metric_ingest_events USING btree (organization_id, captured_at);


--
-- Name: server_metric_ingest_events_organization_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_metric_ingest_events_organization_id_index ON public.server_metric_ingest_events USING btree (organization_id);


--
-- Name: server_metric_ingest_events_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_metric_ingest_events_server_id_index ON public.server_metric_ingest_events USING btree (server_id);


--
-- Name: server_metric_ingest_events_source_snapshot_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_metric_ingest_events_source_snapshot_id_index ON public.server_metric_ingest_events USING btree (source_snapshot_id);


--
-- Name: server_metric_snapshots_server_id_captured_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_metric_snapshots_server_id_captured_at_index ON public.server_metric_snapshots USING btree (server_id, captured_at);


--
-- Name: server_provision_step_runs_label_hash_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_provision_step_runs_label_hash_index ON public.server_provision_step_runs USING btree (label_hash);


--
-- Name: server_scheduler_heartbeats_last_tick_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_scheduler_heartbeats_last_tick_at_index ON public.server_scheduler_heartbeats USING btree (last_tick_at);


--
-- Name: server_ssh_key_audit_events_server_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_ssh_key_audit_events_server_id_created_at_index ON public.server_ssh_key_audit_events USING btree (server_id, created_at);


--
-- Name: server_systemd_notification_digest_lines_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_systemd_notification_digest_lines_created_at_index ON public.server_systemd_notification_digest_lines USING btree (created_at);


--
-- Name: server_systemd_notification_digest_lines_digest_bucket_notifica; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_systemd_notification_digest_lines_digest_bucket_notifica ON public.server_systemd_notification_digest_lines USING btree (digest_bucket, notification_channel_id);


--
-- Name: server_systemd_service_audit_events_server_id_occurred_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_systemd_service_audit_events_server_id_occurred_at_index ON public.server_systemd_service_audit_events USING btree (server_id, occurred_at);


--
-- Name: server_systemd_service_states_server_id_captured_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_systemd_service_states_server_id_captured_at_index ON public.server_systemd_service_states USING btree (server_id, captured_at);


--
-- Name: server_webserver_audit_events_server_id_action_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_webserver_audit_events_server_id_action_created_at_index ON public.server_webserver_audit_events USING btree (server_id, action, created_at);


--
-- Name: server_webserver_audit_events_server_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_webserver_audit_events_server_id_created_at_index ON public.server_webserver_audit_events USING btree (server_id, created_at);


--
-- Name: server_webserver_audit_events_user_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX server_webserver_audit_events_user_id_created_at_index ON public.server_webserver_audit_events USING btree (user_id, created_at);


--
-- Name: servers_one_primary_per_pool; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX servers_one_primary_per_pool ON public.servers USING btree (worker_pool_id) WHERE (((pool_role)::text = 'primary'::text) AND (worker_pool_id IS NOT NULL));


--
-- Name: servers_user_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX servers_user_id_status_index ON public.servers USING btree (user_id, status);


--
-- Name: servers_worker_pool_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX servers_worker_pool_id_index ON public.servers USING btree (worker_pool_id);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: site_audit_events_site_id_action_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_audit_events_site_id_action_created_at_index ON public.site_audit_events USING btree (site_id, action, created_at);


--
-- Name: site_audit_events_site_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_audit_events_site_id_created_at_index ON public.site_audit_events USING btree (site_id, created_at);


--
-- Name: site_audit_events_user_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_audit_events_user_id_created_at_index ON public.site_audit_events USING btree (user_id, created_at);


--
-- Name: site_backends_one_primary_per_site; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX site_backends_one_primary_per_site ON public.site_backends USING btree (site_id) WHERE ((role)::text = 'primary'::text);


--
-- Name: site_backends_server_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_backends_server_id_index ON public.site_backends USING btree (server_id);


--
-- Name: site_backends_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_backends_site_id_index ON public.site_backends USING btree (site_id);


--
-- Name: site_basic_auth_users_site_id_pending_removal_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_basic_auth_users_site_id_pending_removal_at_index ON public.site_basic_auth_users USING btree (site_id, pending_removal_at);


--
-- Name: site_basic_auth_users_site_id_sort_order_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_basic_auth_users_site_id_sort_order_index ON public.site_basic_auth_users USING btree (site_id, sort_order);


--
-- Name: site_certificates_scope_type_provider_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_certificates_scope_type_provider_type_index ON public.site_certificates USING btree (scope_type, provider_type);


--
-- Name: site_certificates_site_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_certificates_site_id_status_index ON public.site_certificates USING btree (site_id, status);


--
-- Name: site_deploy_hooks_pipeline_id_anchor_sort_order_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_deploy_hooks_pipeline_id_anchor_sort_order_index ON public.site_deploy_hooks USING btree (pipeline_id, anchor, sort_order);


--
-- Name: site_deploy_steps_pipeline_id_sort_order_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_deploy_steps_pipeline_id_sort_order_index ON public.site_deploy_steps USING btree (pipeline_id, sort_order);


--
-- Name: site_deploy_steps_site_id_phase_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_deploy_steps_site_id_phase_index ON public.site_deploy_steps USING btree (site_id, phase);


--
-- Name: site_deployments_project_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_deployments_project_id_created_at_index ON public.site_deployments USING btree (project_id, created_at);


--
-- Name: site_deployments_server_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_deployments_server_id_created_at_index ON public.site_deployments USING btree (server_id, created_at);


--
-- Name: site_deployments_site_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_deployments_site_id_created_at_index ON public.site_deployments USING btree (site_id, created_at);


--
-- Name: site_deployments_site_id_idempotency_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_deployments_site_id_idempotency_key_index ON public.site_deployments USING btree (site_id, idempotency_key);


--
-- Name: site_domain_aliases_site_id_sort_order_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_domain_aliases_site_id_sort_order_index ON public.site_domain_aliases USING btree (site_id, sort_order);


--
-- Name: site_file_backups_site_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_file_backups_site_id_created_at_index ON public.site_file_backups USING btree (site_id, created_at);


--
-- Name: site_preview_domains_site_id_is_primary_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_preview_domains_site_id_is_primary_index ON public.site_preview_domains USING btree (site_id, is_primary);


--
-- Name: site_processes_site_id_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_processes_site_id_type_index ON public.site_processes USING btree (site_id, type);


--
-- Name: site_releases_site_id_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_releases_site_id_is_active_index ON public.site_releases USING btree (site_id, is_active);


--
-- Name: site_tenant_domains_site_id_sort_order_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_tenant_domains_site_id_sort_order_index ON public.site_tenant_domains USING btree (site_id, sort_order);


--
-- Name: site_uptime_check_results_site_uptime_monitor_id_checked_at_ind; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_uptime_check_results_site_uptime_monitor_id_checked_at_ind ON public.site_uptime_check_results USING btree (site_uptime_monitor_id, checked_at);


--
-- Name: site_uptime_incidents_site_id_started_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_uptime_incidents_site_id_started_at_index ON public.site_uptime_incidents USING btree (site_id, started_at);


--
-- Name: site_uptime_incidents_site_uptime_monitor_id_resolved_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_uptime_incidents_site_uptime_monitor_id_resolved_at_index ON public.site_uptime_incidents USING btree (site_uptime_monitor_id, resolved_at);


--
-- Name: site_uptime_monitors_site_id_sort_order_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX site_uptime_monitors_site_id_sort_order_index ON public.site_uptime_monitors USING btree (site_id, sort_order);


--
-- Name: sites_parent_site_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sites_parent_site_id_index ON public.sites USING btree (parent_site_id);


--
-- Name: sites_scheduled_deletion_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sites_scheduled_deletion_at_index ON public.sites USING btree (scheduled_deletion_at);


--
-- Name: sites_server_id_internal_port_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX sites_server_id_internal_port_unique ON public.sites USING btree (server_id, internal_port) WHERE (internal_port IS NOT NULL);


--
-- Name: slack_installations_owner_type_owner_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX slack_installations_owner_type_owner_id_index ON public.slack_installations USING btree (owner_type, owner_id);


--
-- Name: sma_server_recent_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sma_server_recent_idx ON public.server_manage_actions USING btree (server_id, created_at);


--
-- Name: snapshots_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX snapshots_expires_at_index ON public.snapshots USING btree (expires_at);


--
-- Name: snapshots_site_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX snapshots_site_id_created_at_index ON public.snapshots USING btree (site_id, created_at);


--
-- Name: spsr_avg_lookup_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX spsr_avg_lookup_idx ON public.server_provision_step_runs USING btree (label_hash, organization_id, completed_at);


--
-- Name: spsr_server_timeline_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX spsr_server_timeline_idx ON public.server_provision_step_runs USING btree (server_id, completed_at);


--
-- Name: status_page_monitors_monitorable_type_monitorable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX status_page_monitors_monitorable_type_monitorable_id_index ON public.status_page_monitors USING btree (monitorable_type, monitorable_id);


--
-- Name: status_page_monitors_status_page_id_sort_order_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX status_page_monitors_status_page_id_sort_order_index ON public.status_page_monitors USING btree (status_page_id, sort_order);


--
-- Name: subscription_items_subscription_id_stripe_price_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX subscription_items_subscription_id_stripe_price_index ON public.subscription_items USING btree (subscription_id, stripe_price);


--
-- Name: subscriptions_organization_id_stripe_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX subscriptions_organization_id_stripe_status_index ON public.subscriptions USING btree (organization_id, stripe_status);


--
-- Name: supervisor_program_audit_logs_server_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX supervisor_program_audit_logs_server_id_created_at_index ON public.supervisor_program_audit_logs USING btree (server_id, created_at);


--
-- Name: team_ssh_keys_team_id_provision_on_new_servers_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX team_ssh_keys_team_id_provision_on_new_servers_index ON public.team_ssh_keys USING btree (team_id, provision_on_new_servers);


--
-- Name: teams_organization_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX teams_organization_id_index ON public.teams USING btree (organization_id);


--
-- Name: telegram_connect_tokens_owner_type_owner_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX telegram_connect_tokens_owner_type_owner_id_index ON public.telegram_connect_tokens USING btree (owner_type, owner_id);


--
-- Name: telegram_installations_owner_type_owner_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX telegram_installations_owner_type_owner_id_index ON public.telegram_installations USING btree (owner_type, owner_id);


--
-- Name: user_ssh_keys_user_id_provision_on_new_servers_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_ssh_keys_user_id_provision_on_new_servers_index ON public.user_ssh_keys USING btree (user_id, provision_on_new_servers);


--
-- Name: users_stripe_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_stripe_id_index ON public.users USING btree (stripe_id);


--
-- Name: webhook_delivery_logs_site_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX webhook_delivery_logs_site_id_created_at_index ON public.webhook_delivery_logs USING btree (site_id, created_at);


--
-- Name: webserver_templates_organization_id_label_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX webserver_templates_organization_id_label_index ON public.webserver_templates USING btree (organization_id, label);


--
-- Name: whth_org_engine_metric_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX whth_org_engine_metric_idx ON public.webserver_health_thresholds USING btree (organization_id, engine, metric);


--
-- Name: whth_server_engine_metric_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX whth_server_engine_metric_idx ON public.webserver_health_thresholds USING btree (server_id, engine, metric);


--
-- Name: dply_cache_items dply_cache_items_del; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER dply_cache_items_del AFTER DELETE ON public.dply_cache_items REFERENCING OLD TABLE AS old_items FOR EACH STATEMENT EXECUTE FUNCTION public.dply_cache_items_usage_delta();


--
-- Name: dply_cache_items dply_cache_items_ins; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER dply_cache_items_ins AFTER INSERT ON public.dply_cache_items REFERENCING NEW TABLE AS new_items FOR EACH STATEMENT EXECUTE FUNCTION public.dply_cache_items_usage_delta();


--
-- Name: dply_cache_items dply_cache_items_upd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER dply_cache_items_upd AFTER UPDATE ON public.dply_cache_items REFERENCING OLD TABLE AS old_items NEW TABLE AS new_items FOR EACH STATEMENT EXECUTE FUNCTION public.dply_cache_items_usage_delta();


--
-- Name: api_tokens api_tokens_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_tokens
    ADD CONSTRAINT api_tokens_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: api_tokens api_tokens_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_tokens
    ADD CONSTRAINT api_tokens_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: audit_logs audit_logs_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: audit_logs audit_logs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: backup_configurations backup_configurations_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_configurations
    ADD CONSTRAINT backup_configurations_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: backup_configurations backup_configurations_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_configurations
    ADD CONSTRAINT backup_configurations_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: beta_invitations beta_invitations_invited_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.beta_invitations
    ADD CONSTRAINT beta_invitations_invited_by_foreign FOREIGN KEY (invited_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: beta_invitations beta_invitations_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.beta_invitations
    ADD CONSTRAINT beta_invitations_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: beta_invitations beta_invitations_redeemed_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.beta_invitations
    ADD CONSTRAINT beta_invitations_redeemed_by_user_id_foreign FOREIGN KEY (redeemed_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: billing_subscription_sync_events billing_subscription_sync_events_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.billing_subscription_sync_events
    ADD CONSTRAINT billing_subscription_sync_events_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: config_revisions config_revisions_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.config_revisions
    ADD CONSTRAINT config_revisions_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: config_revisions config_revisions_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.config_revisions
    ADD CONSTRAINT config_revisions_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: console_actions console_actions_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.console_actions
    ADD CONSTRAINT console_actions_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: deploy_contract_runs deploy_contract_runs_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.deploy_contract_runs
    ADD CONSTRAINT deploy_contract_runs_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: deploy_contract_runs deploy_contract_runs_parent_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.deploy_contract_runs
    ADD CONSTRAINT deploy_contract_runs_parent_site_id_foreign FOREIGN KEY (parent_site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: deploy_contract_runs deploy_contract_runs_preview_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.deploy_contract_runs
    ADD CONSTRAINT deploy_contract_runs_preview_site_id_foreign FOREIGN KEY (preview_site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: deploy_contract_runs deploy_contract_runs_triggered_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.deploy_contract_runs
    ADD CONSTRAINT deploy_contract_runs_triggered_by_user_id_foreign FOREIGN KEY (triggered_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: deploy_contract_runs deploy_contract_runs_waived_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.deploy_contract_runs
    ADD CONSTRAINT deploy_contract_runs_waived_by_user_id_foreign FOREIGN KEY (waived_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: device_authorizations device_authorizations_api_token_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.device_authorizations
    ADD CONSTRAINT device_authorizations_api_token_id_foreign FOREIGN KEY (api_token_id) REFERENCES public.api_tokens(id) ON DELETE SET NULL;


--
-- Name: device_authorizations device_authorizations_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.device_authorizations
    ADD CONSTRAINT device_authorizations_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: device_authorizations device_authorizations_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.device_authorizations
    ADD CONSTRAINT device_authorizations_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: discord_installations discord_installations_installed_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discord_installations
    ADD CONSTRAINT discord_installations_installed_by_user_id_foreign FOREIGN KEY (installed_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: edge_access_logs edge_access_logs_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_access_logs
    ADD CONSTRAINT edge_access_logs_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: edge_access_logs edge_access_logs_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_access_logs
    ADD CONSTRAINT edge_access_logs_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: edge_deploy_hooks edge_deploy_hooks_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_deploy_hooks
    ADD CONSTRAINT edge_deploy_hooks_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: edge_deploy_hooks edge_deploy_hooks_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_deploy_hooks
    ADD CONSTRAINT edge_deploy_hooks_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: edge_deploy_replays edge_deploy_replays_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_deploy_replays
    ADD CONSTRAINT edge_deploy_replays_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: edge_deploy_replays edge_deploy_replays_parent_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_deploy_replays
    ADD CONSTRAINT edge_deploy_replays_parent_site_id_foreign FOREIGN KEY (parent_site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: edge_deploy_replays edge_deploy_replays_preview_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_deploy_replays
    ADD CONSTRAINT edge_deploy_replays_preview_site_id_foreign FOREIGN KEY (preview_site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: edge_deploy_replays edge_deploy_replays_triggered_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_deploy_replays
    ADD CONSTRAINT edge_deploy_replays_triggered_by_user_id_foreign FOREIGN KEY (triggered_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: edge_deployments edge_deployments_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_deployments
    ADD CONSTRAINT edge_deployments_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: edge_deployments edge_deployments_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_deployments
    ADD CONSTRAINT edge_deployments_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: edge_performance_hourly edge_performance_hourly_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_performance_hourly
    ADD CONSTRAINT edge_performance_hourly_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: edge_performance_hourly edge_performance_hourly_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_performance_hourly
    ADD CONSTRAINT edge_performance_hourly_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: edge_preview_comments edge_preview_comments_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_preview_comments
    ADD CONSTRAINT edge_preview_comments_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: edge_preview_comments edge_preview_comments_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_preview_comments
    ADD CONSTRAINT edge_preview_comments_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: edge_preview_comments edge_preview_comments_parent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_preview_comments
    ADD CONSTRAINT edge_preview_comments_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES public.edge_preview_comments(id) ON DELETE CASCADE;


--
-- Name: edge_preview_comments edge_preview_comments_resolved_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_preview_comments
    ADD CONSTRAINT edge_preview_comments_resolved_by_user_id_foreign FOREIGN KEY (resolved_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: edge_preview_comments edge_preview_comments_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_preview_comments
    ADD CONSTRAINT edge_preview_comments_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: edge_preview_review_approvals edge_preview_review_approvals_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_preview_review_approvals
    ADD CONSTRAINT edge_preview_review_approvals_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: edge_preview_review_approvals edge_preview_review_approvals_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_preview_review_approvals
    ADD CONSTRAINT edge_preview_review_approvals_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: edge_preview_review_approvals edge_preview_review_approvals_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_preview_review_approvals
    ADD CONSTRAINT edge_preview_review_approvals_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: edge_site_access_rules edge_site_access_rules_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_site_access_rules
    ADD CONSTRAINT edge_site_access_rules_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: edge_site_env_vars edge_site_env_vars_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_site_env_vars
    ADD CONSTRAINT edge_site_env_vars_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: edge_site_env_vars edge_site_env_vars_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_site_env_vars
    ADD CONSTRAINT edge_site_env_vars_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: edge_site_members edge_site_members_invited_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_site_members
    ADD CONSTRAINT edge_site_members_invited_by_user_id_foreign FOREIGN KEY (invited_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: edge_site_members edge_site_members_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_site_members
    ADD CONSTRAINT edge_site_members_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: edge_site_members edge_site_members_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_site_members
    ADD CONSTRAINT edge_site_members_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: edge_usage_snapshots edge_usage_snapshots_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_usage_snapshots
    ADD CONSTRAINT edge_usage_snapshots_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: edge_usage_snapshots edge_usage_snapshots_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_usage_snapshots
    ADD CONSTRAINT edge_usage_snapshots_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: edge_web_vitals edge_web_vitals_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_web_vitals
    ADD CONSTRAINT edge_web_vitals_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: edge_web_vitals edge_web_vitals_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.edge_web_vitals
    ADD CONSTRAINT edge_web_vitals_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: external_secret_stores external_secret_stores_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.external_secret_stores
    ADD CONSTRAINT external_secret_stores_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: firewall_rule_templates firewall_rule_templates_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.firewall_rule_templates
    ADD CONSTRAINT firewall_rule_templates_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: firewall_rule_templates firewall_rule_templates_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.firewall_rule_templates
    ADD CONSTRAINT firewall_rule_templates_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: forge_servers forge_servers_provider_credential_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.forge_servers
    ADD CONSTRAINT forge_servers_provider_credential_id_foreign FOREIGN KEY (provider_credential_id) REFERENCES public.provider_credentials(id) ON DELETE CASCADE;


--
-- Name: forge_sites forge_sites_forge_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.forge_sites
    ADD CONSTRAINT forge_sites_forge_server_id_foreign FOREIGN KEY (forge_server_id) REFERENCES public.forge_servers(id) ON DELETE CASCADE;


--
-- Name: git_provider_tokens git_provider_tokens_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.git_provider_tokens
    ADD CONSTRAINT git_provider_tokens_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: import_migration_steps import_migration_steps_import_server_migration_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_migration_steps
    ADD CONSTRAINT import_migration_steps_import_server_migration_id_foreign FOREIGN KEY (import_server_migration_id) REFERENCES public.import_server_migrations(id) ON DELETE CASCADE;


--
-- Name: import_migration_steps import_migration_steps_import_site_migration_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_migration_steps
    ADD CONSTRAINT import_migration_steps_import_site_migration_id_foreign FOREIGN KEY (import_site_migration_id) REFERENCES public.import_site_migrations(id) ON DELETE CASCADE;


--
-- Name: import_server_migrations import_server_migrations_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_server_migrations
    ADD CONSTRAINT import_server_migrations_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: import_server_migrations import_server_migrations_provider_credential_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_server_migrations
    ADD CONSTRAINT import_server_migrations_provider_credential_id_foreign FOREIGN KEY (provider_credential_id) REFERENCES public.provider_credentials(id);


--
-- Name: import_server_migrations import_server_migrations_target_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_server_migrations
    ADD CONSTRAINT import_server_migrations_target_server_id_foreign FOREIGN KEY (target_server_id) REFERENCES public.servers(id) ON DELETE SET NULL;


--
-- Name: import_server_migrations import_server_migrations_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_server_migrations
    ADD CONSTRAINT import_server_migrations_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: import_site_migrations import_site_migrations_import_server_migration_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_site_migrations
    ADD CONSTRAINT import_site_migrations_import_server_migration_id_foreign FOREIGN KEY (import_server_migration_id) REFERENCES public.import_server_migrations(id) ON DELETE CASCADE;


--
-- Name: import_site_migrations import_site_migrations_target_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_site_migrations
    ADD CONSTRAINT import_site_migrations_target_site_id_foreign FOREIGN KEY (target_site_id) REFERENCES public.sites(id) ON DELETE SET NULL;


--
-- Name: incident_updates incident_updates_incident_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.incident_updates
    ADD CONSTRAINT incident_updates_incident_id_foreign FOREIGN KEY (incident_id) REFERENCES public.incidents(id) ON DELETE CASCADE;


--
-- Name: incident_updates incident_updates_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.incident_updates
    ADD CONSTRAINT incident_updates_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: incidents incidents_status_page_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.incidents
    ADD CONSTRAINT incidents_status_page_id_foreign FOREIGN KEY (status_page_id) REFERENCES public.status_pages(id) ON DELETE CASCADE;


--
-- Name: incidents incidents_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.incidents
    ADD CONSTRAINT incidents_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: insight_digest_queue insight_digest_queue_insight_finding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insight_digest_queue
    ADD CONSTRAINT insight_digest_queue_insight_finding_id_foreign FOREIGN KEY (insight_finding_id) REFERENCES public.insight_findings(id) ON DELETE CASCADE;


--
-- Name: insight_digest_queue insight_digest_queue_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insight_digest_queue
    ADD CONSTRAINT insight_digest_queue_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: insight_findings insight_findings_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insight_findings
    ADD CONSTRAINT insight_findings_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: insight_findings insight_findings_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insight_findings
    ADD CONSTRAINT insight_findings_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE SET NULL;


--
-- Name: insight_findings insight_findings_team_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insight_findings
    ADD CONSTRAINT insight_findings_team_id_foreign FOREIGN KEY (team_id) REFERENCES public.teams(id) ON DELETE SET NULL;


--
-- Name: insight_health_snapshots insight_health_snapshots_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.insight_health_snapshots
    ADD CONSTRAINT insight_health_snapshots_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: notification_webhook_destinations integration_outbound_webhooks_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_webhook_destinations
    ADD CONSTRAINT integration_outbound_webhooks_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: notification_webhook_destinations integration_outbound_webhooks_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_webhook_destinations
    ADD CONSTRAINT integration_outbound_webhooks_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE SET NULL;


--
-- Name: load_balancers load_balancers_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.load_balancers
    ADD CONSTRAINT load_balancers_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: load_balancers load_balancers_provider_credential_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.load_balancers
    ADD CONSTRAINT load_balancers_provider_credential_id_foreign FOREIGN KEY (provider_credential_id) REFERENCES public.provider_credentials(id) ON DELETE SET NULL;


--
-- Name: load_balancers load_balancers_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.load_balancers
    ADD CONSTRAINT load_balancers_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE SET NULL;


--
-- Name: log_viewer_shares log_viewer_shares_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.log_viewer_shares
    ADD CONSTRAINT log_viewer_shares_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: log_viewer_shares log_viewer_shares_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.log_viewer_shares
    ADD CONSTRAINT log_viewer_shares_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: lookout_projects lookout_projects_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lookout_projects
    ADD CONSTRAINT lookout_projects_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: lookout_projects lookout_projects_site_binding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lookout_projects
    ADD CONSTRAINT lookout_projects_site_binding_id_foreign FOREIGN KEY (site_binding_id) REFERENCES public.site_bindings(id) ON DELETE SET NULL;


--
-- Name: lookout_projects lookout_projects_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lookout_projects
    ADD CONSTRAINT lookout_projects_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE SET NULL;


--
-- Name: notification_events notification_events_actor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_events
    ADD CONSTRAINT notification_events_actor_id_foreign FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: notification_events notification_events_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_events
    ADD CONSTRAINT notification_events_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: notification_events notification_events_team_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_events
    ADD CONSTRAINT notification_events_team_id_foreign FOREIGN KEY (team_id) REFERENCES public.teams(id) ON DELETE SET NULL;


--
-- Name: notification_inbox_items notification_inbox_items_notification_event_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_inbox_items
    ADD CONSTRAINT notification_inbox_items_notification_event_id_foreign FOREIGN KEY (notification_event_id) REFERENCES public.notification_events(id) ON DELETE CASCADE;


--
-- Name: notification_inbox_items notification_inbox_items_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_inbox_items
    ADD CONSTRAINT notification_inbox_items_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: notification_subscriptions notification_subscriptions_notification_channel_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_subscriptions
    ADD CONSTRAINT notification_subscriptions_notification_channel_id_foreign FOREIGN KEY (notification_channel_id) REFERENCES public.notification_channels(id) ON DELETE CASCADE;


--
-- Name: org_secret_keys org_secret_keys_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.org_secret_keys
    ADD CONSTRAINT org_secret_keys_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: organization_billing_snapshots organization_billing_snapshots_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_billing_snapshots
    ADD CONSTRAINT organization_billing_snapshots_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: organization_bundle_entitlements organization_bundle_entitlements_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_bundle_entitlements
    ADD CONSTRAINT organization_bundle_entitlements_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: organization_cron_job_templates organization_cron_job_templates_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_cron_job_templates
    ADD CONSTRAINT organization_cron_job_templates_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: organization_invitations organization_invitations_invited_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_invitations
    ADD CONSTRAINT organization_invitations_invited_by_foreign FOREIGN KEY (invited_by) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: organization_invitations organization_invitations_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_invitations
    ADD CONSTRAINT organization_invitations_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: organization_invitations organization_invitations_team_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_invitations
    ADD CONSTRAINT organization_invitations_team_id_foreign FOREIGN KEY (team_id) REFERENCES public.teams(id) ON DELETE SET NULL;


--
-- Name: organization_secret_sites organization_secret_sites_organization_secret_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_secret_sites
    ADD CONSTRAINT organization_secret_sites_organization_secret_id_foreign FOREIGN KEY (organization_secret_id) REFERENCES public.organization_secrets(id) ON DELETE CASCADE;


--
-- Name: organization_secret_sites organization_secret_sites_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_secret_sites
    ADD CONSTRAINT organization_secret_sites_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: organization_secrets organization_secrets_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_secrets
    ADD CONSTRAINT organization_secrets_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: organization_secrets organization_secrets_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_secrets
    ADD CONSTRAINT organization_secrets_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: organization_ssh_keys organization_ssh_keys_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_ssh_keys
    ADD CONSTRAINT organization_ssh_keys_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: organization_ssh_keys organization_ssh_keys_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_ssh_keys
    ADD CONSTRAINT organization_ssh_keys_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: organization_supervisor_program_templates organization_supervisor_program_templates_organization_id_forei; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_supervisor_program_templates
    ADD CONSTRAINT organization_supervisor_program_templates_organization_id_forei FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: organization_user organization_user_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_user
    ADD CONSTRAINT organization_user_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: organization_user organization_user_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organization_user
    ADD CONSTRAINT organization_user_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: organizations organizations_default_site_script_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_default_site_script_id_foreign FOREIGN KEY (default_site_script_id) REFERENCES public.scripts(id) ON DELETE SET NULL;


--
-- Name: passkeys passkeys_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.passkeys
    ADD CONSTRAINT passkeys_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: ploi_servers ploi_servers_provider_credential_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ploi_servers
    ADD CONSTRAINT ploi_servers_provider_credential_id_foreign FOREIGN KEY (provider_credential_id) REFERENCES public.provider_credentials(id) ON DELETE CASCADE;


--
-- Name: ploi_sites ploi_sites_ploi_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ploi_sites
    ADD CONSTRAINT ploi_sites_ploi_server_id_foreign FOREIGN KEY (ploi_server_id) REFERENCES public.ploi_servers(id) ON DELETE CASCADE;


--
-- Name: projects projects_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: projects projects_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: provider_credentials provider_credentials_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.provider_credentials
    ADD CONSTRAINT provider_credentials_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: provider_credentials provider_credentials_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.provider_credentials
    ADD CONSTRAINT provider_credentials_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: recent_resources recent_resources_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recent_resources
    ADD CONSTRAINT recent_resources_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: referral_rewards referral_rewards_referred_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.referral_rewards
    ADD CONSTRAINT referral_rewards_referred_user_id_foreign FOREIGN KEY (referred_user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: referral_rewards referral_rewards_referrer_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.referral_rewards
    ADD CONSTRAINT referral_rewards_referrer_organization_id_foreign FOREIGN KEY (referrer_organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: referral_rewards referral_rewards_referrer_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.referral_rewards
    ADD CONSTRAINT referral_rewards_referrer_user_id_foreign FOREIGN KEY (referrer_user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: remote_cli_runs remote_cli_runs_queued_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.remote_cli_runs
    ADD CONSTRAINT remote_cli_runs_queued_by_user_id_foreign FOREIGN KEY (queued_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: remote_cli_runs remote_cli_runs_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.remote_cli_runs
    ADD CONSTRAINT remote_cli_runs_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: scripts scripts_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scripts
    ADD CONSTRAINT scripts_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: scripts scripts_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scripts
    ADD CONSTRAINT scripts_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: server_authorized_keys server_authorized_keys_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_authorized_keys
    ADD CONSTRAINT server_authorized_keys_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: backup_schedules server_backup_schedules_backup_configuration_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_schedules
    ADD CONSTRAINT server_backup_schedules_backup_configuration_id_foreign FOREIGN KEY (backup_configuration_id) REFERENCES public.backup_configurations(id) ON DELETE SET NULL;


--
-- Name: backup_schedules server_backup_schedules_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_schedules
    ADD CONSTRAINT server_backup_schedules_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_cache_service_audit_events server_cache_service_audit_events_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_cache_service_audit_events
    ADD CONSTRAINT server_cache_service_audit_events_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_cache_service_audit_events server_cache_service_audit_events_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_cache_service_audit_events
    ADD CONSTRAINT server_cache_service_audit_events_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: server_cache_services server_cache_services_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_cache_services
    ADD CONSTRAINT server_cache_services_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_create_drafts server_create_drafts_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_create_drafts
    ADD CONSTRAINT server_create_drafts_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: server_create_drafts server_create_drafts_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_create_drafts
    ADD CONSTRAINT server_create_drafts_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: server_cron_job_runs server_cron_job_runs_server_cron_job_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_cron_job_runs
    ADD CONSTRAINT server_cron_job_runs_server_cron_job_id_foreign FOREIGN KEY (server_cron_job_id) REFERENCES public.server_cron_jobs(id) ON DELETE CASCADE;


--
-- Name: server_cron_jobs server_cron_jobs_applied_template_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_cron_jobs
    ADD CONSTRAINT server_cron_jobs_applied_template_id_foreign FOREIGN KEY (applied_template_id) REFERENCES public.organization_cron_job_templates(id) ON DELETE SET NULL;


--
-- Name: server_cron_jobs server_cron_jobs_depends_on_job_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_cron_jobs
    ADD CONSTRAINT server_cron_jobs_depends_on_job_id_foreign FOREIGN KEY (depends_on_job_id) REFERENCES public.server_cron_jobs(id) ON DELETE SET NULL;


--
-- Name: server_cron_jobs server_cron_jobs_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_cron_jobs
    ADD CONSTRAINT server_cron_jobs_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_cron_jobs server_cron_jobs_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_cron_jobs
    ADD CONSTRAINT server_cron_jobs_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE SET NULL;


--
-- Name: server_database_admin_credentials server_database_admin_credentials_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_admin_credentials
    ADD CONSTRAINT server_database_admin_credentials_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_database_audit_events server_database_audit_events_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_audit_events
    ADD CONSTRAINT server_database_audit_events_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_database_audit_events server_database_audit_events_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_audit_events
    ADD CONSTRAINT server_database_audit_events_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: server_database_backups server_database_backups_backup_configuration_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_backups
    ADD CONSTRAINT server_database_backups_backup_configuration_id_foreign FOREIGN KEY (backup_configuration_id) REFERENCES public.backup_configurations(id) ON DELETE SET NULL;


--
-- Name: server_database_backups server_database_backups_server_database_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_backups
    ADD CONSTRAINT server_database_backups_server_database_id_foreign FOREIGN KEY (server_database_id) REFERENCES public.server_databases(id) ON DELETE CASCADE;


--
-- Name: server_database_backups server_database_backups_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_backups
    ADD CONSTRAINT server_database_backups_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: server_database_credential_shares server_database_credential_shares_server_database_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_credential_shares
    ADD CONSTRAINT server_database_credential_shares_server_database_id_foreign FOREIGN KEY (server_database_id) REFERENCES public.server_databases(id) ON DELETE CASCADE;


--
-- Name: server_database_credential_shares server_database_credential_shares_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_credential_shares
    ADD CONSTRAINT server_database_credential_shares_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: server_database_engine_audit_events server_database_engine_audit_events_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_engine_audit_events
    ADD CONSTRAINT server_database_engine_audit_events_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_database_engine_audit_events server_database_engine_audit_events_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_engine_audit_events
    ADD CONSTRAINT server_database_engine_audit_events_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: server_database_engines server_database_engines_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_engines
    ADD CONSTRAINT server_database_engines_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_database_extra_users server_database_extra_users_server_database_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_database_extra_users
    ADD CONSTRAINT server_database_extra_users_server_database_id_foreign FOREIGN KEY (server_database_id) REFERENCES public.server_databases(id) ON DELETE CASCADE;


--
-- Name: server_databases server_databases_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_databases
    ADD CONSTRAINT server_databases_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_firewall_apply_logs server_firewall_apply_logs_api_token_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_firewall_apply_logs
    ADD CONSTRAINT server_firewall_apply_logs_api_token_id_foreign FOREIGN KEY (api_token_id) REFERENCES public.api_tokens(id) ON DELETE SET NULL;


--
-- Name: server_firewall_apply_logs server_firewall_apply_logs_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_firewall_apply_logs
    ADD CONSTRAINT server_firewall_apply_logs_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_firewall_apply_logs server_firewall_apply_logs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_firewall_apply_logs
    ADD CONSTRAINT server_firewall_apply_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: server_firewall_audit_events server_firewall_audit_events_api_token_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_firewall_audit_events
    ADD CONSTRAINT server_firewall_audit_events_api_token_id_foreign FOREIGN KEY (api_token_id) REFERENCES public.api_tokens(id) ON DELETE SET NULL;


--
-- Name: server_firewall_audit_events server_firewall_audit_events_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_firewall_audit_events
    ADD CONSTRAINT server_firewall_audit_events_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_firewall_audit_events server_firewall_audit_events_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_firewall_audit_events
    ADD CONSTRAINT server_firewall_audit_events_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: server_firewall_rules server_firewall_rules_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_firewall_rules
    ADD CONSTRAINT server_firewall_rules_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_firewall_rules server_firewall_rules_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_firewall_rules
    ADD CONSTRAINT server_firewall_rules_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE SET NULL;


--
-- Name: server_firewall_snapshots server_firewall_snapshots_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_firewall_snapshots
    ADD CONSTRAINT server_firewall_snapshots_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_firewall_snapshots server_firewall_snapshots_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_firewall_snapshots
    ADD CONSTRAINT server_firewall_snapshots_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: server_log_pins server_log_pins_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_log_pins
    ADD CONSTRAINT server_log_pins_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_log_pins server_log_pins_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_log_pins
    ADD CONSTRAINT server_log_pins_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: server_metric_snapshots server_metric_snapshots_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_metric_snapshots
    ADD CONSTRAINT server_metric_snapshots_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_php_opcache_profiles server_php_opcache_profiles_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_php_opcache_profiles
    ADD CONSTRAINT server_php_opcache_profiles_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_provision_artifacts server_provision_artifacts_server_provision_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_provision_artifacts
    ADD CONSTRAINT server_provision_artifacts_server_provision_run_id_foreign FOREIGN KEY (server_provision_run_id) REFERENCES public.server_provision_runs(id) ON DELETE CASCADE;


--
-- Name: server_provision_runs server_provision_runs_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_provision_runs
    ADD CONSTRAINT server_provision_runs_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_provision_runs server_provision_runs_task_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_provision_runs
    ADD CONSTRAINT server_provision_runs_task_id_foreign FOREIGN KEY (task_id) REFERENCES public.task_runner_tasks(id) ON DELETE SET NULL;


--
-- Name: server_provision_step_runs server_provision_step_runs_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_provision_step_runs
    ADD CONSTRAINT server_provision_step_runs_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: server_provision_step_runs server_provision_step_runs_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_provision_step_runs
    ADD CONSTRAINT server_provision_step_runs_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_provision_step_runs server_provision_step_runs_server_provision_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_provision_step_runs
    ADD CONSTRAINT server_provision_step_runs_server_provision_run_id_foreign FOREIGN KEY (server_provision_run_id) REFERENCES public.server_provision_runs(id) ON DELETE SET NULL;


--
-- Name: server_provision_step_runs server_provision_step_runs_task_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_provision_step_runs
    ADD CONSTRAINT server_provision_step_runs_task_id_foreign FOREIGN KEY (task_id) REFERENCES public.task_runner_tasks(id) ON DELETE SET NULL;


--
-- Name: server_recipes server_recipes_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_recipes
    ADD CONSTRAINT server_recipes_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_recipes server_recipes_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_recipes
    ADD CONSTRAINT server_recipes_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: server_scheduler_heartbeats server_scheduler_heartbeats_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_scheduler_heartbeats
    ADD CONSTRAINT server_scheduler_heartbeats_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_scheduler_heartbeats server_scheduler_heartbeats_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_scheduler_heartbeats
    ADD CONSTRAINT server_scheduler_heartbeats_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: server_ssh_key_audit_events server_ssh_key_audit_events_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_ssh_key_audit_events
    ADD CONSTRAINT server_ssh_key_audit_events_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_ssh_key_audit_events server_ssh_key_audit_events_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_ssh_key_audit_events
    ADD CONSTRAINT server_ssh_key_audit_events_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: server_system_users server_system_users_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_system_users
    ADD CONSTRAINT server_system_users_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_systemd_notification_digest_lines server_systemd_notification_digest_lines_notification_channel_i; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_systemd_notification_digest_lines
    ADD CONSTRAINT server_systemd_notification_digest_lines_notification_channel_i FOREIGN KEY (notification_channel_id) REFERENCES public.notification_channels(id) ON DELETE CASCADE;


--
-- Name: server_systemd_notification_digest_lines server_systemd_notification_digest_lines_organization_id_foreig; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_systemd_notification_digest_lines
    ADD CONSTRAINT server_systemd_notification_digest_lines_organization_id_foreig FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: server_systemd_notification_digest_lines server_systemd_notification_digest_lines_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_systemd_notification_digest_lines
    ADD CONSTRAINT server_systemd_notification_digest_lines_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_systemd_service_audit_events server_systemd_service_audit_events_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_systemd_service_audit_events
    ADD CONSTRAINT server_systemd_service_audit_events_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_systemd_service_states server_systemd_service_states_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_systemd_service_states
    ADD CONSTRAINT server_systemd_service_states_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_webserver_audit_events server_webserver_audit_events_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_webserver_audit_events
    ADD CONSTRAINT server_webserver_audit_events_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: server_webserver_audit_events server_webserver_audit_events_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_webserver_audit_events
    ADD CONSTRAINT server_webserver_audit_events_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: server_webserver_cache_features server_webserver_cache_features_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.server_webserver_cache_features
    ADD CONSTRAINT server_webserver_cache_features_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: servers servers_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.servers
    ADD CONSTRAINT servers_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: servers servers_provider_credential_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.servers
    ADD CONSTRAINT servers_provider_credential_id_foreign FOREIGN KEY (provider_credential_id) REFERENCES public.provider_credentials(id) ON DELETE SET NULL;


--
-- Name: servers servers_team_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.servers
    ADD CONSTRAINT servers_team_id_foreign FOREIGN KEY (team_id) REFERENCES public.teams(id) ON DELETE SET NULL;


--
-- Name: servers servers_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.servers
    ADD CONSTRAINT servers_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: servers servers_workspace_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.servers
    ADD CONSTRAINT servers_workspace_id_foreign FOREIGN KEY (workspace_id) REFERENCES public.workspaces(id) ON DELETE SET NULL;


--
-- Name: sessions sessions_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: site_access_gate_passwords site_access_gate_passwords_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_access_gate_passwords
    ADD CONSTRAINT site_access_gate_passwords_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_access_gates site_access_gates_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_access_gates
    ADD CONSTRAINT site_access_gates_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_audit_events site_audit_events_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_audit_events
    ADD CONSTRAINT site_audit_events_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_audit_events site_audit_events_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_audit_events
    ADD CONSTRAINT site_audit_events_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: site_basic_auth_users site_basic_auth_users_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_basic_auth_users
    ADD CONSTRAINT site_basic_auth_users_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_bindings site_bindings_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_bindings
    ADD CONSTRAINT site_bindings_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_certificates site_certificates_preview_domain_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_certificates
    ADD CONSTRAINT site_certificates_preview_domain_id_foreign FOREIGN KEY (preview_domain_id) REFERENCES public.site_preview_domains(id) ON DELETE SET NULL;


--
-- Name: site_certificates site_certificates_provider_credential_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_certificates
    ADD CONSTRAINT site_certificates_provider_credential_id_foreign FOREIGN KEY (provider_credential_id) REFERENCES public.provider_credentials(id) ON DELETE SET NULL;


--
-- Name: site_certificates site_certificates_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_certificates
    ADD CONSTRAINT site_certificates_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_deploy_hooks site_deploy_hooks_anchor_step_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deploy_hooks
    ADD CONSTRAINT site_deploy_hooks_anchor_step_id_foreign FOREIGN KEY (anchor_step_id) REFERENCES public.site_deploy_steps(id) ON DELETE SET NULL;


--
-- Name: site_deploy_hooks site_deploy_hooks_notification_channel_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deploy_hooks
    ADD CONSTRAINT site_deploy_hooks_notification_channel_id_foreign FOREIGN KEY (notification_channel_id) REFERENCES public.notification_channels(id) ON DELETE SET NULL;


--
-- Name: site_deploy_hooks site_deploy_hooks_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deploy_hooks
    ADD CONSTRAINT site_deploy_hooks_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_deploy_steps site_deploy_steps_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deploy_steps
    ADD CONSTRAINT site_deploy_steps_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_deploy_sync_group_sites site_deploy_sync_group_sites_site_deploy_sync_group_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deploy_sync_group_sites
    ADD CONSTRAINT site_deploy_sync_group_sites_site_deploy_sync_group_id_foreign FOREIGN KEY (site_deploy_sync_group_id) REFERENCES public.site_deploy_sync_groups(id) ON DELETE CASCADE;


--
-- Name: site_deploy_sync_group_sites site_deploy_sync_group_sites_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deploy_sync_group_sites
    ADD CONSTRAINT site_deploy_sync_group_sites_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_deploy_sync_groups site_deploy_sync_groups_leader_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deploy_sync_groups
    ADD CONSTRAINT site_deploy_sync_groups_leader_site_id_foreign FOREIGN KEY (leader_site_id) REFERENCES public.sites(id) ON DELETE SET NULL;


--
-- Name: site_deploy_sync_groups site_deploy_sync_groups_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deploy_sync_groups
    ADD CONSTRAINT site_deploy_sync_groups_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: site_deployment_ephemeral_credentials site_deployment_ephemeral_credentials_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deployment_ephemeral_credentials
    ADD CONSTRAINT site_deployment_ephemeral_credentials_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: site_deployment_ephemeral_credentials site_deployment_ephemeral_credentials_server_authorized_key_id_; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deployment_ephemeral_credentials
    ADD CONSTRAINT site_deployment_ephemeral_credentials_server_authorized_key_id_ FOREIGN KEY (server_authorized_key_id) REFERENCES public.server_authorized_keys(id) ON DELETE SET NULL;


--
-- Name: site_deployment_ephemeral_credentials site_deployment_ephemeral_credentials_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deployment_ephemeral_credentials
    ADD CONSTRAINT site_deployment_ephemeral_credentials_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: site_deployment_ephemeral_credentials site_deployment_ephemeral_credentials_site_deployment_id_foreig; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deployment_ephemeral_credentials
    ADD CONSTRAINT site_deployment_ephemeral_credentials_site_deployment_id_foreig FOREIGN KEY (site_deployment_id) REFERENCES public.site_deployments(id) ON DELETE CASCADE;


--
-- Name: site_deployments site_deployments_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deployments
    ADD CONSTRAINT site_deployments_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id);


--
-- Name: site_deployments site_deployments_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_deployments
    ADD CONSTRAINT site_deployments_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_domain_aliases site_domain_aliases_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_domain_aliases
    ADD CONSTRAINT site_domain_aliases_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_domains site_domains_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_domains
    ADD CONSTRAINT site_domains_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_file_backups site_file_backups_backup_configuration_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_file_backups
    ADD CONSTRAINT site_file_backups_backup_configuration_id_foreign FOREIGN KEY (backup_configuration_id) REFERENCES public.backup_configurations(id) ON DELETE SET NULL;


--
-- Name: site_file_backups site_file_backups_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_file_backups
    ADD CONSTRAINT site_file_backups_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_file_backups site_file_backups_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_file_backups
    ADD CONSTRAINT site_file_backups_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: site_preview_domains site_preview_domains_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_preview_domains
    ADD CONSTRAINT site_preview_domains_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_processes site_processes_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_processes
    ADD CONSTRAINT site_processes_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_redirects site_redirects_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_redirects
    ADD CONSTRAINT site_redirects_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_releases site_releases_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_releases
    ADD CONSTRAINT site_releases_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_secret_residencies site_secret_residencies_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_secret_residencies
    ADD CONSTRAINT site_secret_residencies_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_tenant_domains site_tenant_domains_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_tenant_domains
    ADD CONSTRAINT site_tenant_domains_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_uptime_check_results site_uptime_check_results_site_uptime_monitor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_uptime_check_results
    ADD CONSTRAINT site_uptime_check_results_site_uptime_monitor_id_foreign FOREIGN KEY (site_uptime_monitor_id) REFERENCES public.site_uptime_monitors(id) ON DELETE CASCADE;


--
-- Name: site_uptime_incidents site_uptime_incidents_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_uptime_incidents
    ADD CONSTRAINT site_uptime_incidents_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_uptime_incidents site_uptime_incidents_site_uptime_monitor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_uptime_incidents
    ADD CONSTRAINT site_uptime_incidents_site_uptime_monitor_id_foreign FOREIGN KEY (site_uptime_monitor_id) REFERENCES public.site_uptime_monitors(id) ON DELETE CASCADE;


--
-- Name: site_uptime_monitors site_uptime_monitors_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_uptime_monitors
    ADD CONSTRAINT site_uptime_monitors_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: site_webserver_config_profiles site_webserver_config_profiles_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.site_webserver_config_profiles
    ADD CONSTRAINT site_webserver_config_profiles_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: sites sites_deploy_script_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_deploy_script_id_foreign FOREIGN KEY (deploy_script_id) REFERENCES public.scripts(id) ON DELETE SET NULL;


--
-- Name: sites sites_dns_provider_credential_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_dns_provider_credential_id_foreign FOREIGN KEY (dns_provider_credential_id) REFERENCES public.provider_credentials(id) ON DELETE SET NULL;


--
-- Name: sites sites_edge_provider_credential_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_edge_provider_credential_id_foreign FOREIGN KEY (edge_provider_credential_id) REFERENCES public.provider_credentials(id) ON DELETE SET NULL;


--
-- Name: sites sites_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: sites sites_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id);


--
-- Name: sites sites_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: sites sites_serverless_provider_credential_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_serverless_provider_credential_id_foreign FOREIGN KEY (serverless_provider_credential_id) REFERENCES public.provider_credentials(id) ON DELETE SET NULL;


--
-- Name: sites sites_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: sites sites_workspace_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sites
    ADD CONSTRAINT sites_workspace_id_foreign FOREIGN KEY (workspace_id) REFERENCES public.workspaces(id) ON DELETE SET NULL;


--
-- Name: slack_installations slack_installations_installed_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.slack_installations
    ADD CONSTRAINT slack_installations_installed_by_user_id_foreign FOREIGN KEY (installed_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: snapshots snapshots_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.snapshots
    ADD CONSTRAINT snapshots_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: snapshots snapshots_taken_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.snapshots
    ADD CONSTRAINT snapshots_taken_by_user_id_foreign FOREIGN KEY (taken_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: social_accounts social_accounts_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.social_accounts
    ADD CONSTRAINT social_accounts_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: status_page_monitors status_page_monitors_status_page_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_page_monitors
    ADD CONSTRAINT status_page_monitors_status_page_id_foreign FOREIGN KEY (status_page_id) REFERENCES public.status_pages(id) ON DELETE CASCADE;


--
-- Name: status_pages status_pages_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_pages
    ADD CONSTRAINT status_pages_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: status_pages status_pages_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.status_pages
    ADD CONSTRAINT status_pages_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: subscription_items subscription_items_subscription_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_items
    ADD CONSTRAINT subscription_items_subscription_id_foreign FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id) ON DELETE CASCADE;


--
-- Name: subscriptions subscriptions_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: supervisor_program_audit_logs supervisor_program_audit_logs_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supervisor_program_audit_logs
    ADD CONSTRAINT supervisor_program_audit_logs_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: supervisor_program_audit_logs supervisor_program_audit_logs_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supervisor_program_audit_logs
    ADD CONSTRAINT supervisor_program_audit_logs_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: supervisor_program_audit_logs supervisor_program_audit_logs_supervisor_program_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supervisor_program_audit_logs
    ADD CONSTRAINT supervisor_program_audit_logs_supervisor_program_id_foreign FOREIGN KEY (supervisor_program_id) REFERENCES public.supervisor_programs(id) ON DELETE SET NULL;


--
-- Name: supervisor_program_audit_logs supervisor_program_audit_logs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supervisor_program_audit_logs
    ADD CONSTRAINT supervisor_program_audit_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: supervisor_programs supervisor_programs_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supervisor_programs
    ADD CONSTRAINT supervisor_programs_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: supervisor_programs supervisor_programs_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supervisor_programs
    ADD CONSTRAINT supervisor_programs_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE SET NULL;


--
-- Name: task_runner_tasks task_runner_tasks_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.task_runner_tasks
    ADD CONSTRAINT task_runner_tasks_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: task_runner_tasks task_runner_tasks_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.task_runner_tasks
    ADD CONSTRAINT task_runner_tasks_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE SET NULL;


--
-- Name: team_ssh_keys team_ssh_keys_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.team_ssh_keys
    ADD CONSTRAINT team_ssh_keys_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: team_ssh_keys team_ssh_keys_team_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.team_ssh_keys
    ADD CONSTRAINT team_ssh_keys_team_id_foreign FOREIGN KEY (team_id) REFERENCES public.teams(id) ON DELETE CASCADE;


--
-- Name: team_user team_user_team_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.team_user
    ADD CONSTRAINT team_user_team_id_foreign FOREIGN KEY (team_id) REFERENCES public.teams(id) ON DELETE CASCADE;


--
-- Name: team_user team_user_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.team_user
    ADD CONSTRAINT team_user_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: teams teams_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teams
    ADD CONSTRAINT teams_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: telegram_connect_tokens telegram_connect_tokens_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telegram_connect_tokens
    ADD CONSTRAINT telegram_connect_tokens_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: telegram_installations telegram_installations_connected_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telegram_installations
    ADD CONSTRAINT telegram_installations_connected_by_user_id_foreign FOREIGN KEY (connected_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: user_ssh_keys user_ssh_keys_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_ssh_keys
    ADD CONSTRAINT user_ssh_keys_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: users users_referred_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_referred_by_user_id_foreign FOREIGN KEY (referred_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: webhook_delivery_logs webhook_delivery_logs_site_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webhook_delivery_logs
    ADD CONSTRAINT webhook_delivery_logs_site_id_foreign FOREIGN KEY (site_id) REFERENCES public.sites(id) ON DELETE CASCADE;


--
-- Name: webserver_health_thresholds webserver_health_thresholds_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webserver_health_thresholds
    ADD CONSTRAINT webserver_health_thresholds_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: webserver_health_thresholds webserver_health_thresholds_server_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webserver_health_thresholds
    ADD CONSTRAINT webserver_health_thresholds_server_id_foreign FOREIGN KEY (server_id) REFERENCES public.servers(id) ON DELETE CASCADE;


--
-- Name: webserver_templates webserver_templates_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webserver_templates
    ADD CONSTRAINT webserver_templates_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: webserver_templates webserver_templates_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webserver_templates
    ADD CONSTRAINT webserver_templates_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: workspace_deploy_runs workspace_deploy_runs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_deploy_runs
    ADD CONSTRAINT workspace_deploy_runs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: workspace_deploy_runs workspace_deploy_runs_workspace_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_deploy_runs
    ADD CONSTRAINT workspace_deploy_runs_workspace_id_foreign FOREIGN KEY (workspace_id) REFERENCES public.workspaces(id) ON DELETE CASCADE;


--
-- Name: workspace_environments workspace_environments_workspace_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_environments
    ADD CONSTRAINT workspace_environments_workspace_id_foreign FOREIGN KEY (workspace_id) REFERENCES public.workspaces(id) ON DELETE CASCADE;


--
-- Name: workspace_label_assignments workspace_label_assignments_workspace_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_label_assignments
    ADD CONSTRAINT workspace_label_assignments_workspace_id_foreign FOREIGN KEY (workspace_id) REFERENCES public.workspaces(id) ON DELETE CASCADE;


--
-- Name: workspace_label_assignments workspace_label_assignments_workspace_label_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_label_assignments
    ADD CONSTRAINT workspace_label_assignments_workspace_label_id_foreign FOREIGN KEY (workspace_label_id) REFERENCES public.workspace_labels(id) ON DELETE CASCADE;


--
-- Name: workspace_labels workspace_labels_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_labels
    ADD CONSTRAINT workspace_labels_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: workspace_members workspace_members_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_members
    ADD CONSTRAINT workspace_members_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: workspace_members workspace_members_workspace_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_members
    ADD CONSTRAINT workspace_members_workspace_id_foreign FOREIGN KEY (workspace_id) REFERENCES public.workspaces(id) ON DELETE CASCADE;


--
-- Name: workspace_runbooks workspace_runbooks_workspace_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_runbooks
    ADD CONSTRAINT workspace_runbooks_workspace_id_foreign FOREIGN KEY (workspace_id) REFERENCES public.workspaces(id) ON DELETE CASCADE;


--
-- Name: workspace_variables workspace_variables_workspace_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_variables
    ADD CONSTRAINT workspace_variables_workspace_id_foreign FOREIGN KEY (workspace_id) REFERENCES public.workspaces(id) ON DELETE CASCADE;


--
-- Name: workspace_views workspace_views_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_views
    ADD CONSTRAINT workspace_views_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: workspace_views workspace_views_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspace_views
    ADD CONSTRAINT workspace_views_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: workspaces workspaces_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspaces
    ADD CONSTRAINT workspaces_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: workspaces workspaces_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workspaces
    ADD CONSTRAINT workspaces_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict cuGdoOZPiFe1nRHaX9ekAVUI8AuJpkhDJxsw3XqE8eh0LqhxvZqhUtaDixsIyu0

--
-- PostgreSQL database dump
--

\restrict Ti2RLvtLZRLDB7d8PzgCb2hhqaTjlcsjbPo5JDNNDOgvrRISLBU1F5DCACv5cH8

-- Dumped from database version 16.14 (Homebrew)
-- Dumped by pg_dump version 16.14 (Homebrew)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	2026_03_17_203659_create_provider_credentials_table	1
5	2026_03_17_203701_create_servers_table	1
6	2026_03_17_204930_create_organizations_table	1
7	2026_03_17_204931_create_organization_user_table	1
8	2026_03_17_204932_create_teams_table	1
9	2026_03_17_204933_create_team_user_table	1
10	2026_03_17_204936_create_customer_columns	1
11	2026_03_17_204937_create_subscriptions_table	1
12	2026_03_17_204938_add_organization_to_servers_and_credentials	1
13	2026_03_17_204938_create_subscription_items_table	1
14	2026_03_17_204939_add_meter_id_to_subscription_items_table	1
15	2026_03_17_204940_add_meter_event_name_to_subscription_items_table	1
16	2026_03_17_220000_create_organization_invitations_table	1
17	2026_03_17_230000_create_audit_logs_table	1
18	2026_03_18_000001_create_social_accounts_and_allow_oauth_users	1
19	2026_03_18_000002_add_two_factor_columns_to_users_table	1
20	2026_03_18_030844_add_health_status_to_servers_table	1
21	2026_03_18_031358_add_deploy_command_to_servers_table	1
22	2026_03_18_120000_add_setup_script_to_servers_table	1
23	2026_03_18_175553_add_setup_script_to_servers_table	1
24	2026_03_19_000001_create_api_tokens_table	1
25	2026_03_22_120000_add_deploy_email_notifications_enabled_to_organizations_table	1
26	2026_03_22_120000_create_sites_domains_deployments_server_ops_tables	1
27	2026_03_23_140000_forge_style_site_and_server_ops	1
28	2026_03_23_200000_site_deploy_steps	1
29	2026_03_24_120000_deploy_hardening_webhook_cleanup	1
30	2026_03_25_140000_extended_teardown_notifications_api	1
31	2026_03_26_100000_create_projects_link_sites_and_deployments	1
32	2026_03_30_120000_expand_server_firewall_rules	1
33	2026_03_30_140000_firewall_templates_snapshots_audit	1
34	2026_03_30_140938_add_profile_and_billing_fields_to_users_table	1
35	2026_03_30_143453_add_label_and_nickname_to_social_accounts_table	1
36	2026_03_30_160000_create_user_ssh_keys_and_link_server_authorized_keys	1
37	2026_03_30_180000_add_referrals_to_users_and_referral_rewards_table	1
38	2026_03_30_180000_create_notification_channels_table	1
39	2026_03_30_200000_add_ui_preferences_to_users_and_organizations	1
40	2026_03_30_200000_create_notification_subscriptions_table	1
41	2026_03_30_200000_create_server_metric_snapshots_table	1
42	2026_03_30_200000_create_task_runner_tasks_table	1
43	2026_03_30_210000_add_description_to_server_databases_table	1
44	2026_03_30_210000_add_preferences_to_teams_table	1
45	2026_03_30_210000_create_insight_settings_and_findings_tables	1
46	2026_03_30_210000_create_server_metric_ingest_events_table	1
47	2026_03_30_220000_add_scheduled_deletion_at_to_servers_table	1
48	2026_03_30_220000_create_backup_configurations_table	1
49	2026_03_30_220000_create_webserver_templates_table	1
50	2026_03_30_230000_create_marketplace_items_table	1
51	2026_03_30_240000_create_workspaces_and_link_servers_sites	1
52	2026_03_30_300000_create_scripts_and_org_site_defaults	1
53	2026_03_31_010811_create_log_viewer_shares_and_server_log_pins_tables	1
54	2026_03_31_011926_create_pulse_tables	1
55	2026_03_31_100000_create_server_systemd_service_tables	1
56	2026_03_31_100000_ensure_marketplace_items_seeded	1
57	2026_03_31_120000_add_fields_to_server_cron_jobs_table	1
58	2026_03_31_120000_organization_team_ssh_keys_and_managed_key_morph	1
59	2026_03_31_130000_add_supervisor_package_status_to_servers_table	1
60	2026_03_31_140000_migrate_local_dev_organization_to_workspace	1
61	2026_03_31_200000_create_status_pages_and_incidents_tables	1
62	2026_03_31_200000_extend_daemons_and_site_deploy_supervisor	1
63	2026_03_31_210000_cron_jobs_extended_features	1
64	2026_03_31_230000_add_target_linux_user_to_server_authorized_keys_table	1
65	2026_04_01_000000_supervisor_daemons_enhancements	1
66	2026_04_01_100000_server_ssh_key_audit_events_and_review_after	1
67	2026_04_01_120000_insights_fleet_health_digest_preferences	1
68	2026_04_02_100000_firewall_platform_expansion	1
69	2026_04_02_120000_server_database_platform_expansion	1
70	2026_04_02_120000_systemd_services_workspace_expansion	1
71	2026_04_03_100000_add_database_workspace_settings_to_organizations	1
72	2026_03_30_160000_add_dply_auth_id_to_users_table	2
73	2026_03_31_120000_add_workspace_project_features	3
74	2026_03_31_000001_create_server_provision_runs_table	4
75	2026_03_31_000002_create_server_provision_artifacts_table	4
76	2026_04_01_024500_add_dual_ssh_keys_to_servers_table	5
77	2026_04_01_120000_create_notification_events_table	6
78	2026_04_01_120050_create_notifications_table	6
79	2026_04_01_120100_create_notification_inbox_items_table	6
80	2026_04_03_140000_rename_integration_outbound_webhooks_table	6
81	2026_04_01_184500_create_sessions_table	7
82	2026_04_01_230000_create_coming_soon_signups_table	7
83	2026_04_02_000000_create_site_preview_domains_table	7
84	2026_04_02_000100_create_site_certificates_table	7
85	2026_04_02_000200_create_site_domain_aliases_table	7
86	2026_04_02_000300_create_site_tenant_domains_table	7
87	2026_04_02_001000_change_notifications_notifiable_id_to_string	7
88	2026_04_02_160000_add_dns_provider_credential_id_to_sites_table	7
89	2026_04_02_170000_add_dns_zone_to_sites_table	7
90	2026_04_02_180000_add_suspension_columns_to_sites_table	7
91	2026_04_02_200000_add_engine_http_cache_enabled_to_sites_table	7
92	2026_04_02_210000_create_site_basic_auth_users_table	7
93	2026_04_02_210000_create_site_file_backups_table	7
94	2026_04_02_220000_add_kind_to_site_redirects_table	7
95	2026_04_02_220000_create_site_webserver_config_profiles_table	7
96	2026_04_02_220001_create_site_webserver_config_revisions_table	7
97	2026_04_02_225102_create_site_deploy_sync_groups_tables	7
98	2026_04_02_225105_add_provider_metadata_to_webhook_delivery_logs_table	7
99	2026_04_02_230000_add_response_headers_to_site_redirects_table	7
100	2026_04_02_240000_create_site_uptime_monitors_table	7
101	2026_04_28_000000_drop_dply_auth_id_from_users_table	7
102	2026_04_30_194142_create_server_create_drafts_table	7
103	2026_05_01_130000_create_outbound_webhook_deliveries_table	7
104	2026_05_01_140000_create_server_manage_actions_table	7
105	2026_05_01_150000_add_runtime_columns_to_sites_table	7
106	2026_05_01_160000_create_site_processes_table	7
107	2026_05_01_170000_add_runtime_tags_to_marketplace_items_table	7
108	2026_05_02_120000_add_runtime_agnostic_columns_to_sites_table	7
109	2026_05_02_130000_add_unique_index_on_sites_server_id_internal_port	7
110	2026_05_02_140000_create_server_database_engines_table	7
111	2026_05_02_150000_add_phase_to_site_deploy_steps_table	7
112	2026_05_02_160000_drop_php_version_from_sites_table	7
113	2026_05_02_170000_add_database_engine_to_sites_table	7
114	2026_05_02_180000_add_phase_results_to_site_deployments_table	7
115	2026_05_03_000000_add_container_columns_to_sites_table	7
116	2026_05_03_100000_create_remote_cli_runs_table	7
117	2026_05_03_110000_create_snapshots_table	7
118	2026_05_03_120000_create_site_audit_events_table	7
119	2026_05_04_120000_add_credentials_email_toggles_to_organizations_table	7
120	2026_05_04_130000_add_ack_columns_to_insight_findings_table	7
121	2026_05_04_140000_add_cleared_columns_to_notification_events_table	7
122	2026_05_04_140000_add_kind_to_insight_findings_table	7
123	2026_05_04_150000_add_ignored_columns_to_insight_findings_table	7
124	2026_05_04_150000_add_pending_action_to_server_systemd_service_states_table	7
125	2026_05_04_160000_add_output_to_server_manage_actions_table	7
126	2026_05_04_180000_drop_deploy_command_from_servers_table	7
127	2026_05_05_080000_create_server_provision_step_runs_table	7
128	2026_05_05_090000_add_system_managed_to_server_cron_jobs_table	7
129	2026_05_05_120000_create_server_cache_services_table	7
130	2026_05_05_130000_create_server_cache_service_audit_events_table	7
131	2026_05_05_140000_add_auth_password_to_server_cache_services	7
132	2026_05_05_150000_add_install_columns_to_server_database_engines	7
133	2026_05_05_160000_create_server_database_engine_audit_events_table	7
134	2026_05_05_231702_add_install_output_to_server_cache_services	7
135	2026_05_05_232000_add_cancel_requested_at_to_server_cache_services	7
136	2026_05_05_233000_add_target_engine_to_server_cache_services	7
137	2026_05_05_234000_allow_multiple_cache_services_per_server	7
138	2026_05_05_235000_add_name_to_server_cache_services	7
139	2026_05_05_235100_allow_multiple_instances_per_engine_in_server_cache_services	7
140	2026_05_07_162313_create_passkeys_table	7
141	2026_05_07_191500_add_pending_removal_at_to_site_basic_auth_users	7
142	2026_05_08_120000_add_source_file_path_to_site_basic_auth_users	7
143	2026_05_08_140000_create_console_actions_table	7
144	2026_05_08_180000_add_label_to_console_actions	7
145	2026_05_08_200000_add_env_cache_columns_to_sites_table	7
146	2026_05_08_200100_backfill_site_env_from_environment_variables	7
147	2026_05_08_200200_drop_site_environment_variables_table	7
148	2026_05_09_120000_add_env_file_path_to_sites_table	7
149	2026_05_09_140000_add_comment_to_routing_tables	7
150	2026_05_09_140100_backfill_tenant_notes_to_comment	7
151	2026_05_09_140200_drop_notes_from_site_tenant_domains	7
152	2026_05_11_120000_create_config_revisions_table	7
153	2026_05_11_120100_migrate_site_webserver_revisions_into_config_revisions	7
154	2026_05_11_120200_drop_site_webserver_config_revisions_table	7
155	2026_05_11_130000_create_server_system_users_table	7
156	2026_05_11_140000_add_last_synced_enabled_to_server_cron_jobs	7
157	2026_05_11_140000_create_server_webserver_audit_events_table	7
158	2026_05_12_000000_create_server_backup_schedules_table	7
159	2026_05_12_010000_add_notify_on_failure_to_server_backup_schedules	7
160	2026_05_12_053640_collapse_cache_services_to_one_per_family	7
161	2026_05_12_120000_create_webserver_health_thresholds_table	7
162	2026_05_13_120000_create_server_php_opcache_profiles_table	7
163	2026_05_13_120100_create_server_webserver_cache_features_table	7
164	2026_05_13_120200_migrate_engine_http_cache_to_meta_caching	7
165	2026_05_14_120000_create_ploi_servers_table	7
166	2026_05_14_120100_create_ploi_sites_table	7
167	2026_05_14_120200_create_import_server_migrations_table	7
168	2026_05_14_120300_create_import_site_migrations_table	7
169	2026_05_14_120400_create_import_migration_steps_table	7
170	2026_05_14_120500_add_manual_review_items_to_import_server_migrations	7
171	2026_05_14_120600_create_forge_servers_table	7
172	2026_05_14_120700_create_forge_sites_table	7
173	2026_05_14_120800_add_nudge_sent_at_to_import_server_migrations	7
174	2026_05_18_203843_add_app_profile_to_server_firewall_rules	8
175	2026_05_18_204509_add_iface_to_server_firewall_rules	9
176	2026_05_19_000000_scope_backup_configurations_to_organization	10
177	2026_05_19_100000_create_server_scheduler_heartbeats_table	11
178	2026_05_19_234007_create_features_table	12
179	2026_05_21_000001_create_function_invocations_table	12
180	2026_05_21_000002_add_context_to_function_invocations	12
181	2026_05_21_000002_create_function_actions_table	12
182	2026_05_21_000003_add_function_action_id_to_function_invocations_table	12
183	2026_05_21_180000_rename_dply_edge_host_kind_to_dply_cloud	12
184	2026_05_21_190000_create_cloud_databases_table	12
185	2026_05_21_190001_create_cloud_database_site_table	12
186	2026_05_22_120000_add_edge_delivery_columns	12
187	2026_05_22_120000_create_cloud_workers_table	12
188	2026_05_22_214500_make_sites_git_branch_nullable	12
189	2026_05_23_120000_create_edge_usage_snapshots_table	12
190	2026_05_23_140000_add_edge_provider_credential_id_to_sites	12
191	2026_05_23_180000_add_pruned_at_to_edge_deployments	12
192	2026_05_23_180100_make_edge_deployment_storage_prefix_nullable	12
193	2026_05_23_190000_create_organization_billing_snapshots_table	12
194	2026_05_23_190100_create_billing_subscription_sync_events_table	12
195	2026_05_23_210000_create_git_provider_tokens_table	12
196	2026_05_23_220000_create_edge_access_logs_table	12
197	2026_05_23_230000_create_edge_web_vitals_table	12
198	2026_05_24_100000_create_edge_preview_comments_table	12
199	2026_05_24_120000_add_aliases_to_edge_deployments	12
200	2026_05_24_130000_add_repo_config_to_edge_deployments	12
201	2026_05_25_100000_create_edge_site_access_rules_table	12
202	2026_05_25_140000_create_edge_deploy_hooks_table	12
203	2026_05_26_120000_create_device_authorizations_table	12
204	2026_05_26_140000_create_edge_site_env_vars_table	12
205	2026_05_26_160000_create_edge_site_members_table	12
206	2026_05_26_170000_add_edge_data_region_to_organizations_table	12
207	2026_05_26_180000_unify_digitalocean_app_platform_credentials	12
208	2026_05_26_190000_create_cloud_deploy_tasks_table	12
209	2026_05_26_190001_create_cloud_deploy_task_runs_table	12
210	2026_05_26_200000_add_alert_destinations_to_organizations_table	12
211	2026_05_26_210000_add_env_prefix_to_cloud_database_site_table	12
212	2026_05_26_220000_create_cloud_buckets_table	12
213	2026_05_26_220001_create_cloud_bucket_site_table	12
214	2026_05_27_103748_create_deploy_intelligence_alerts_table	12
215	2026_05_27_120000_add_engine_and_sections_to_webserver_templates_table	12
216	2026_05_27_140000_add_billing_details_to_organizations_table	12
217	2026_05_27_150000_create_server_blueprints_table	12
218	2026_05_27_180000_create_edge_deploy_replays_table	12
219	2026_05_27_190000_add_threads_to_edge_preview_comments_table	12
220	2026_05_27_190100_create_edge_preview_review_approvals_table	12
221	2026_05_27_200000_create_site_deployment_ephemeral_credentials_table	12
222	2026_05_27_210000_create_server_ssh_sessions_table	12
223	2026_05_28_120000_create_site_bindings_table	12
224	2026_05_29_120000_add_serverless_backend_columns_to_sites	12
225	2026_05_29_120000_create_deploy_contract_runs_table	12
226	2026_05_29_120001_create_serverless_usage_snapshots_table	12
227	2026_05_29_130000_add_hosting_backend_to_servers	12
228	2026_05_30_100000_add_storage_columns_to_server_database_backups	12
229	2026_05_30_120000_add_mongo_clickhouse_admin_to_server_database_admin_credentials	12
230	2026_05_30_120000_create_site_access_gates_table	12
231	2026_05_30_140000_create_server_remote_access_events_table	12
232	2026_05_30_160000_create_ai_advisor_runs_table	12
233	2026_05_30_220000_create_site_deploy_pipelines_table	12
234	2026_05_31_040000_create_site_access_gate_passwords_table	12
235	2026_05_31_060000_extend_site_deploy_hooks_for_pipeline	12
236	2026_05_31_120000_add_deploy_branches_to_site_deploy_pipelines	12
237	2026_05_31_142509_create_redis_snapshots_tables	12
238	2026_05_31_143325_create_server_cache_service_replications	12
239	2026_05_31_145732_add_cache_prefix_to_server_cache_services	12
240	2026_06_01_100000_backfill_deploy_step_phases	12
241	2026_06_01_110000_add_anchor_scripts_to_site_deploy_pipelines	12
242	2026_06_01_120000_create_roadmap_items_table	12
243	2026_06_01_120001_create_roadmap_suggestions_table	12
244	2026_06_01_130000_add_roadmap_v2_columns	12
245	2026_06_01_140000_create_roadmap_releases_table	12
246	2026_06_01_150000_create_server_credential_shares_table	12
247	2026_06_01_160000_add_remote_access_to_server_database_engines	12
248	2026_06_01_170000_add_private_ip_and_network_to_servers	12
249	2026_06_01_180000_add_networking_to_server_databases	12
250	2026_06_01_190000_default_email_database_credentials_enabled	12
251	2026_06_01_200000_create_load_balancers_table	12
252	2026_06_01_210000_add_server_id_to_load_balancers	12
253	2026_06_01_220000_create_private_networks_table	12
254	2026_06_02_070902_add_scheduled_deletion_at_to_sites_table	12
255	2026_06_02_080000_add_probe_worker_to_site_uptime_monitors_table	12
256	2026_06_02_090000_add_logo_path_to_sites_table	12
257	2026_06_02_100000_add_beta_joined_at_to_organizations_table	12
258	2026_06_02_100001_add_comped_until_to_servers_table	12
259	2026_06_02_100002_create_beta_invitations_table	12
260	2026_06_03_120000_create_site_deployment_schedules_table	12
261	2026_06_03_120000_create_worker_pools_table	12
262	2026_06_03_130000_create_realtime_apps_table	12
263	2026_06_03_140000_add_rollout_mode_to_site_deploy_sync_groups	12
264	2026_06_04_120000_create_error_events_table	12
265	2026_06_04_130000_add_remediation_code_to_error_events	12
266	2026_06_05_120000_create_recent_resources_table	12
267	2026_06_05_140000_create_coming_soon_allowed_ips_table	12
268	2026_06_06_120000_add_site_id_to_server_databases	12
269	2026_06_06_150000_create_object_storage_credentials_table	12
270	2026_06_06_160000_create_log_drain_credentials_table	12
271	2026_06_06_170000_add_tier_to_realtime_apps_table	12
272	2026_06_06_170000_create_mail_credentials_table	12
273	2026_06_06_180000_strip_owned_env_keys_from_loose_env	12
274	2026_06_06_190000_backfill_logging_binding_specs	12
275	2026_06_06_200000_create_app_logs_and_drain_token	12
276	2026_06_06_210000_create_server_pool_members_table	12
277	2026_06_07_120000_create_scheduler_tick_outputs_table	12
278	2026_06_07_140000_create_server_log_agents_table	12
279	2026_06_08_000000_create_server_images_table	12
280	2026_06_08_120000_add_status_to_snapshots_table	12
281	2026_06_08_130000_add_check_type_and_config_to_site_uptime_monitors_table	12
282	2026_06_08_130001_create_site_uptime_check_results_table	12
283	2026_06_08_130002_create_site_uptime_incidents_table	12
284	2026_06_09_120000_create_roadmap_ai_runs_table	12
285	2026_06_09_140000_add_managed_by_manifest_to_steps_and_processes	12
286	2026_06_09_210000_add_deploy_method_to_sites	12
287	2026_06_09_220000_add_parent_site_id_to_sites	12
288	2026_06_10_000000_create_site_backends_table	12
289	2026_06_10_010000_add_weight_drain_to_load_balancer_targets	12
290	2026_06_10_120000_create_error_tracking_credentials_table	12
291	2026_06_10_120100_create_ai_credentials_table	12
292	2026_06_10_120200_create_captcha_credentials_table	12
293	2026_06_10_120300_create_sms_credentials_table	12
294	2026_06_10_120400_create_search_credentials_table	12
295	2026_06_10_120500_create_payment_credentials_table	12
296	2026_06_10_120600_create_oauth_credentials_table	12
297	2026_06_11_000000_create_server_wildcard_certificates_table	12
298	2026_06_11_010000_add_general_settings_to_organizations	12
299	2026_06_11_020000_move_restart_steps_to_restart_phase	12
300	2026_06_11_030000_drop_redundant_restart_steps	12
301	2026_06_11_040000_create_scheduled_deploys_table	12
302	2026_06_11_050000_add_resume_columns_to_site_deployments_table	12
303	2026_06_11_060000_add_remote_storage_to_site_file_backups	12
304	2026_06_11_060000_create_site_secret_residencies_table	12
305	2026_06_11_060100_create_backup_download_stagings_table	12
306	2026_06_11_070000_create_org_secret_keys_table	12
307	2026_06_11_070000_relax_site_bindings_unique_for_storage	12
308	2026_06_11_080000_create_external_secret_stores_table	12
309	2026_06_11_090000_create_site_worker_pool_table	12
310	2026_06_12_120000_add_saved_at_to_notification_inbox_items	12
311	2026_06_12_120000_create_quick_downloads_table	12
312	2026_06_14_000000_add_is_internal_to_organizations_table	12
313	2026_06_14_000100_add_skip_reason_to_site_deployments_table	12
314	2026_06_14_010000_create_feedback_reports_table	12
315	2026_06_14_020000_add_reference_to_error_events	12
316	2026_06_14_120000_add_logo_path_to_servers_table	12
317	2026_06_15_120000_create_server_notes_table	12
318	2026_06_16_000000_add_skip_rule_summary_to_site_deployments_table	12
319	2026_06_17_000000_create_server_log_aggregators_table	12
320	2026_06_17_010000_add_private_endpoint_to_server_log_aggregators	12
321	2026_06_17_020000_create_server_log_usage_daily_table	12
322	2026_06_19_000000_add_config_version_to_server_log_aggregators_table	12
323	2026_06_19_000000_create_server_log_alert_rules_table	12
324	2026_06_19_000100_add_config_version_to_server_log_agents_table	12
325	2026_06_24_140000_create_lookout_projects_table	12
326	2026_06_25_000000_create_server_command_runs_table	12
327	2026_07_01_000000_create_debug_allowed_ips_table	12
328	2026_07_01_010000_create_organization_bundle_entitlements_table	12
329	2026_07_01_020000_add_source_to_lookout_projects_table	12
330	2026_07_02_000000_add_health_columns_to_git_provider_tokens_table	12
331	2026_07_02_010000_add_health_columns_to_social_accounts_table	12
332	2026_07_10_000001_create_feature_platform_overrides_table	12
333	2026_07_25_060000_create_production_data_connections_table	12
334	2026_07_29_120000_add_meta_to_site_processes_table	12
335	2026_08_09_120000_create_serverless_failed_jobs_table	12
336	2026_08_10_090000_create_dply_queue_namespaces_table	12
337	2026_08_10_090001_create_dply_queue_credentials_table	12
338	2026_08_10_090002_create_dply_queue_jobs_table	12
339	2026_08_10_090003_create_dply_queue_failed_jobs_table	12
340	2026_08_10_090004_add_secret_to_dply_queue_credentials_table	12
341	2026_08_10_090005_create_dply_queue_locks_table	12
342	2026_08_10_090006_create_dply_queue_usage_daily_table	12
343	2026_08_10_093000_create_dply_queue_namespace_usage_daily_table	12
344	2026_08_10_120000_add_activation_record_to_function_invocations	12
345	2026_08_10_235500_unify_backup_schedules	12
346	2026_08_11_143000_add_destination_path_to_server_database_backups	12
347	2026_08_12_120000_add_tags_and_archiving_to_server_notes_table	12
348	2026_08_12_120000_create_slack_installations_table	12
349	2026_08_12_120100_create_server_note_comments_table	12
350	2026_08_12_130000_create_discord_installations_table	12
351	2026_08_12_140000_create_telegram_installations_table	12
352	2026_08_13_170000_add_team_id_to_organization_invitations_table	12
353	2026_08_14_120000_migrate_webhook_destinations_to_notification_channels	12
354	2026_08_15_150000_add_backup_destination_to_site_file_backups	12
355	2026_08_18_150000_create_organization_secrets_tables	12
356	2026_08_18_180000_create_platform_connections_table	12
357	2026_08_18_190000_create_connected_app_credentials_table	12
358	2026_08_19_140000_create_cloud_database_trusted_sources_table	12
359	2026_08_19_160000_add_tunnel_access_to_ssh_keys_and_sessions	12
360	2026_08_19_180000_add_health_columns_to_provider_credentials_table	12
361	2026_08_19_190000_add_purpose_to_server_images_table	12
362	2026_08_19_210000_add_server_id_to_site_deployments_table	12
363	2026_08_20_170000_create_dply_queue_fleets_table	12
364	2026_08_20_170001_create_dply_queue_workers_table	12
365	2026_08_20_180000_add_asset_columns_to_serverless_usage_snapshots_table	12
366	2026_08_20_190000_add_fleet_billing_to_queue_usage	12
367	2026_08_21_100000_create_service_credentials_table	12
368	2026_08_21_100001_migrate_queue_credentials_to_service_credentials	12
369	2026_08_21_100002_drop_dply_queue_locks_table	12
370	2026_08_21_110000_create_managed_caches_table	12
371	2026_08_21_110001_create_cache_site_table	12
372	2026_08_21_110002_create_dply_cache_items_table	12
373	2026_08_21_120000_fold_serverless_caches_into_managed_caches	12
374	2026_09_11_000000_drop_removed_product_tables	12
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 374, true);


--
-- PostgreSQL database dump complete
--

\unrestrict Ti2RLvtLZRLDB7d8PzgCb2hhqaTjlcsjbPo5JDNNDOgvrRISLBU1F5DCACv5cH8


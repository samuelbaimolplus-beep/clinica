<?php

/** Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Amplía la bitácora sin reescribir eventos históricos ni debilitar su inmutabilidad. */
return new class extends Migration
{
    /** Aplica el cambio de esquema correspondiente a esta migración. */
    public function up(): void
    {
        DB::statement('ALTER TABLE public.eventos_auditoria ADD COLUMN IF NOT EXISTS direccion_ip inet');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE PROCEDURE public.registrar_auditoria(
                p_usuario_id bigint, p_modulo text, p_accion text, p_tipo_registro text,
                p_registro_id bigint, p_resultado text, p_detalles jsonb, p_direccion_ip inet)
            LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public AS $$
            DECLARE v_detalles jsonb;
            BEGIN
                IF p_modulo IS NULL OR btrim(p_modulo) = '' OR p_accion IS NULL OR btrim(p_accion) = ''
                    OR p_resultado IS NULL OR btrim(p_resultado) = '' THEN
                    RAISE EXCEPTION 'El módulo y la acción son obligatorios' USING ERRCODE = '23514';
                END IF;
                IF p_detalles IS NOT NULL AND jsonb_typeof(p_detalles) <> 'object' THEN
                    RAISE EXCEPTION 'Los detalles deben ser un objeto JSON' USING ERRCODE = '23514';
                END IF;
                SELECT coalesce(jsonb_object_agg(key,value),'{}'::jsonb) INTO v_detalles
                    FROM jsonb_each(coalesce(p_detalles,'{}'::jsonb))
                    WHERE key IN ('fields','specialty_ids','reason','terms_version');
                INSERT INTO public.eventos_auditoria
                    (usuario_id,modulo,accion,tipo_registro,registro_id,resultado,detalles,direccion_ip)
                    VALUES (p_usuario_id,p_modulo,p_accion,p_tipo_registro,p_registro_id,p_resultado,v_detalles,p_direccion_ip);
            END;
            $$;
            -- Las llamadas SQL anteriores siguen funcionando. Una operación interna puede carecer de IP.
            CREATE OR REPLACE PROCEDURE public.registrar_auditoria(
                p_usuario_id bigint,p_modulo text,p_accion text,p_tipo_registro text,
                p_registro_id bigint,p_resultado text,p_detalles jsonb)
            LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public AS $$
            BEGIN
                CALL public.registrar_auditoria(p_usuario_id,p_modulo,p_accion,p_tipo_registro,
                    p_registro_id,p_resultado,p_detalles,nullif(current_setting('clinica.direccion_ip',true),'')::inet);
            END;
            $$;
            SQL);
    }

    /** Describe la reversión de esta migración; se utiliza únicamente al solicitar un rollback. */
    public function down(): void
    {
        // Se conserva el procedimiento original de siete parámetros al retirar esta ampliación.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE PROCEDURE public.registrar_auditoria(
                p_usuario_id bigint,p_modulo text,p_accion text,p_tipo_registro text,
                p_registro_id bigint,p_resultado text,p_detalles jsonb)
            LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public AS $$
            DECLARE v_detalles jsonb;
            BEGIN
                SELECT coalesce(jsonb_object_agg(key,value),'{}'::jsonb) INTO v_detalles
                    FROM jsonb_each(coalesce(p_detalles,'{}'::jsonb))
                    WHERE key IN ('fields','specialty_ids','reason','terms_version');
                INSERT INTO public.eventos_auditoria(usuario_id,modulo,accion,tipo_registro,registro_id,resultado,detalles)
                    VALUES(p_usuario_id,p_modulo,p_accion,p_tipo_registro,p_registro_id,p_resultado,v_detalles);
            END;
            $$;
            DROP PROCEDURE public.registrar_auditoria(bigint,text,text,text,bigint,text,jsonb,inet);
            ALTER TABLE public.eventos_auditoria DROP COLUMN direccion_ip;
            SQL);
    }
};

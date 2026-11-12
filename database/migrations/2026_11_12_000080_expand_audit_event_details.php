<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Amplía eventos futuros. Nunca reconstruye ni modifica la bitácora histórica. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.sanear_detalles_auditoria(p_valor jsonb) RETURNS jsonb
            LANGUAGE plpgsql IMMUTABLE SET search_path = pg_catalog, public AS $$
            DECLARE v_resultado jsonb; v_clave text; v_dato jsonb;
            BEGIN
                IF jsonb_typeof(p_valor) = 'object' THEN
                    v_resultado := '{}'::jsonb;
                    FOR v_clave, v_dato IN SELECT key, value FROM jsonb_each(p_valor) LOOP
                        IF v_clave !~* 'password|contrase|token|secret|credential|authorization|cookie|session_id|idempotency|approval_url|provider_payload|provider_response' THEN
                            v_resultado := v_resultado || jsonb_build_object(v_clave, public.sanear_detalles_auditoria(v_dato));
                        END IF;
                    END LOOP;
                    RETURN v_resultado;
                ELSIF jsonb_typeof(p_valor) = 'array' THEN
                    SELECT coalesce(jsonb_agg(public.sanear_detalles_auditoria(value)), '[]'::jsonb)
                        INTO v_resultado FROM jsonb_array_elements(p_valor);
                    RETURN v_resultado;
                END IF;
                RETURN p_valor;
            END;
            $$;

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
                    WHERE key IN ('fields','specialty_ids','reason','terms_version',
                        'changes','record','actor','request','related','security_changed');
                INSERT INTO public.eventos_auditoria
                    (usuario_id,modulo,accion,tipo_registro,registro_id,resultado,detalles,direccion_ip)
                    VALUES (p_usuario_id,p_modulo,p_accion,p_tipo_registro,p_registro_id,p_resultado,
                        public.sanear_detalles_auditoria(v_detalles),p_direccion_ip);
            END;
            $$;
            SQL);
    }

    public function down(): void
    {
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
            DROP FUNCTION public.sanear_detalles_auditoria(jsonb);
            SQL);
    }
};

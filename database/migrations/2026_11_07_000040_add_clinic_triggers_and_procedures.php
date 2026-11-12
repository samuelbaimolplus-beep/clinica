<?php

/** Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Aplica el cambio de esquema correspondiente a esta migración. */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.normalizar_correo() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                NEW.correo := nullif(lower(btrim(NEW.correo)), '');
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER normalizar_correo_usuario BEFORE INSERT OR UPDATE OF correo ON public.usuarios
                FOR EACH ROW EXECUTE FUNCTION public.normalizar_correo();
            CREATE TRIGGER normalizar_correo_medico BEFORE INSERT OR UPDATE OF correo ON public.medicos
                FOR EACH ROW EXECUTE FUNCTION public.normalizar_correo();
            CREATE TRIGGER normalizar_correo_paciente BEFORE INSERT OR UPDATE OF correo ON public.pacientes
                FOR EACH ROW EXECUTE FUNCTION public.normalizar_correo();

            CREATE FUNCTION public.validar_franja_clinica() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            DECLARE v_franja public.franjas_clinicas%ROWTYPE;
            BEGIN
                -- Las citas históricas pueden no tener una franja identificada.
                IF NEW.franja_clinica_id IS NULL THEN RETURN NEW; END IF;
                SELECT * INTO v_franja FROM public.franjas_clinicas
                    WHERE id = NEW.franja_clinica_id FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'La franja clínica indicada no existe' USING ERRCODE = '23503';
                END IF;
                IF ROW(NEW.medico_id, NEW.fecha, NEW.hora_inicio, NEW.hora_fin)
                    IS DISTINCT FROM ROW(v_franja.medico_id, v_franja.fecha, v_franja.hora_inicio, v_franja.hora_fin) THEN
                    RAISE EXCEPTION 'El médico, la fecha y las horas deben coincidir con la franja clínica'
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER validar_franja_cita BEFORE INSERT OR UPDATE OF franja_clinica_id, medico_id, fecha, hora_inicio, hora_fin
                ON public.citas FOR EACH ROW EXECUTE FUNCTION public.validar_franja_clinica();
            CREATE TRIGGER validar_franja_reserva BEFORE INSERT OR UPDATE OF franja_clinica_id, medico_id, fecha, hora_inicio, hora_fin
                ON public.solicitudes_reserva FOR EACH ROW EXECUTE FUNCTION public.validar_franja_clinica();

            CREATE FUNCTION public.proteger_franja_referenciada() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
            BEGIN
                IF ROW(NEW.medico_id, NEW.fecha, NEW.hora_inicio, NEW.hora_fin)
                    IS DISTINCT FROM ROW(OLD.medico_id, OLD.fecha, OLD.hora_inicio, OLD.hora_fin)
                    AND (EXISTS (SELECT 1 FROM public.citas WHERE franja_clinica_id = OLD.id)
                        OR EXISTS (SELECT 1 FROM public.solicitudes_reserva WHERE franja_clinica_id = OLD.id)) THEN
                    RAISE EXCEPTION 'No se puede modificar una franja vinculada al historial de citas o reservas'
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER proteger_franja_referenciada BEFORE UPDATE OF medico_id, fecha, hora_inicio, hora_fin
                ON public.franjas_clinicas FOR EACH ROW EXECUTE FUNCTION public.proteger_franja_referenciada();

            CREATE PROCEDURE public.registrar_auditoria(
                p_usuario_id bigint, p_modulo text, p_accion text, p_tipo_registro text,
                p_registro_id bigint, p_resultado text, p_detalles jsonb)
            LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public AS $$
            DECLARE v_detalles jsonb;
            BEGIN
                IF p_modulo IS NULL OR btrim(p_modulo) = '' OR p_accion IS NULL OR btrim(p_accion) = ''
                    OR p_resultado IS NULL OR btrim(p_resultado) = '' THEN
                    RAISE EXCEPTION 'Módulo, acción y resultado son obligatorios' USING ERRCODE = '23514';
                END IF;
                IF p_detalles IS NOT NULL AND jsonb_typeof(p_detalles) <> 'object' THEN
                    RAISE EXCEPTION 'Los detalles de auditoría deben ser un objeto JSON' USING ERRCODE = '23514';
                END IF;
                -- La misma lista permitida que utiliza el registrador de Laravel.
                SELECT coalesce(jsonb_object_agg(clave, valor), '{}'::jsonb) INTO v_detalles
                    FROM jsonb_each(coalesce(p_detalles, '{}'::jsonb)) AS dato(clave, valor)
                    WHERE clave IN ('fields', 'specialty_ids', 'reason', 'terms_version');
                INSERT INTO public.eventos_auditoria
                    (usuario_id, modulo, accion, tipo_registro, registro_id, resultado, detalles)
                    VALUES (p_usuario_id, p_modulo, p_accion, p_tipo_registro, p_registro_id, p_resultado, v_detalles);
            END;
            $$;

            CREATE PROCEDURE public.registrar_evento_cita(
                p_cita_id bigint, p_usuario_id bigint, p_accion text, p_detalles jsonb)
            LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public AS $$
            BEGIN
                IF p_accion IS NULL OR btrim(p_accion) = '' THEN
                    RAISE EXCEPTION 'La acción del evento es obligatoria' USING ERRCODE = '23514';
                END IF;
                IF p_detalles IS NOT NULL AND jsonb_typeof(p_detalles) <> 'object' THEN
                    RAISE EXCEPTION 'Los detalles del evento deben ser un objeto JSON' USING ERRCODE = '23514';
                END IF;
                INSERT INTO public.eventos_cita (cita_id, usuario_id, accion, detalles)
                    VALUES (p_cita_id, p_usuario_id, p_accion, coalesce(p_detalles, '{}'::jsonb));
            END;
            $$;

            CREATE PROCEDURE public.configurar_cupos_medico(
                p_medico_id bigint, p_porcentaje_presencial numeric,
                p_porcentaje_en_linea numeric, p_usuario_id bigint)
            LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public AS $$
            DECLARE v_id bigint; v_anterior public.cupos_medico%ROWTYPE;
            BEGIN
                -- El usuario es el actor de la operación; el rol SQL sigue siendo el invocador.
                PERFORM 1 FROM public.usuarios WHERE id = p_usuario_id
                    AND estado = 'Activo' AND rol IN ('Administrador', 'Superadmin') FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Solo una cuenta administrativa activa puede configurar cupos'
                        USING ERRCODE = '42501';
                END IF;
                IF p_porcentaje_presencial IS NULL OR p_porcentaje_en_linea IS NULL
                    OR p_porcentaje_presencial NOT BETWEEN 0 AND 100 OR p_porcentaje_en_linea NOT BETWEEN 0 AND 100
                    OR trunc(p_porcentaje_presencial) <> p_porcentaje_presencial
                    OR trunc(p_porcentaje_en_linea) <> p_porcentaje_en_linea
                    OR p_porcentaje_presencial + p_porcentaje_en_linea <> 100 THEN
                    RAISE EXCEPTION 'Los porcentajes deben ser enteros de 0 a 100 y sumar 100'
                        USING ERRCODE = '23514';
                END IF;
                PERFORM 1 FROM public.medicos WHERE id = p_medico_id AND estado = 'Activo' FOR UPDATE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'El médico no existe o está inactivo' USING ERRCODE = '23514';
                END IF;
                IF EXISTS (SELECT 1 FROM public.solicitudes_reserva
                    WHERE medico_id = p_medico_id AND estado IN ('held', 'processing')) THEN
                    RAISE EXCEPTION 'Concilie los pagos retenidos antes de cambiar la distribución'
                        USING ERRCODE = '23514';
                END IF;
                SELECT * INTO v_anterior FROM public.cupos_medico WHERE medico_id = p_medico_id;
                IF FOUND AND v_anterior.porcentaje_presencial = p_porcentaje_presencial
                    AND v_anterior.porcentaje_en_linea = p_porcentaje_en_linea THEN RETURN; END IF;
                INSERT INTO public.cupos_medico
                    (medico_id, porcentaje_presencial, porcentaje_en_linea, creado_el, actualizado_el)
                    VALUES (p_medico_id, p_porcentaje_presencial, p_porcentaje_en_linea, localtimestamp, localtimestamp)
                    ON CONFLICT (medico_id) DO UPDATE SET
                        porcentaje_presencial = EXCLUDED.porcentaje_presencial,
                        porcentaje_en_linea = EXCLUDED.porcentaje_en_linea, actualizado_el = localtimestamp
                    RETURNING id INTO v_id;
                CALL public.registrar_auditoria(p_usuario_id, 'schedules',
                    CASE WHEN v_anterior.id IS NULL THEN 'created' ELSE 'updated' END, 'DoctorQuota', v_id, 'success',
                    jsonb_build_object('fields', jsonb_build_array('presential_percent', 'online_percent')));
            END;
            $$;

            COMMENT ON FUNCTION public.normalizar_correo() IS 'Normaliza correos nuevos o modificados: minúsculas, sin espacios extremos y vacío como NULL.';
            COMMENT ON FUNCTION public.validar_franja_clinica() IS 'Exige que la cita o reserva coincida con el médico, fecha y horas de su franja; admite vínculos NULL heredados.';
            COMMENT ON FUNCTION public.proteger_franja_referenciada() IS 'Impide alterar la identidad de franjas ya utilizadas en citas o reservas.';
            COMMENT ON PROCEDURE public.registrar_auditoria(bigint,text,text,text,bigint,text,jsonb) IS 'Registra auditoría inmutable con metadatos permitidos. La autorización del flujo corresponde al invocador.';
            COMMENT ON PROCEDURE public.registrar_evento_cita(bigint,bigint,text,jsonb) IS 'Guarda un evento en el historial de la cita; las FK validan la cita y el actor. El servicio controla la autorización.';
            COMMENT ON PROCEDURE public.configurar_cupos_medico(bigint,numeric,numeric,bigint) IS 'Configura porcentajes enteros por médico activo, respeta reservas retenidas y registra auditoría en la misma transacción.';
            SQL);
    }

    /** Describe la reversión de esta migración; se utiliza únicamente al solicitar un rollback. */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP PROCEDURE public.configurar_cupos_medico(bigint,numeric,numeric,bigint);
            DROP PROCEDURE public.registrar_evento_cita(bigint,bigint,text,jsonb);
            DROP PROCEDURE public.registrar_auditoria(bigint,text,text,text,bigint,text,jsonb);
            DROP TRIGGER proteger_franja_referenciada ON public.franjas_clinicas;
            DROP TRIGGER validar_franja_reserva ON public.solicitudes_reserva;
            DROP TRIGGER validar_franja_cita ON public.citas;
            DROP TRIGGER normalizar_correo_paciente ON public.pacientes;
            DROP TRIGGER normalizar_correo_medico ON public.medicos;
            DROP TRIGGER normalizar_correo_usuario ON public.usuarios;
            DROP FUNCTION public.proteger_franja_referenciada();
            DROP FUNCTION public.validar_franja_clinica();
            DROP FUNCTION public.normalizar_correo();
            SQL);
    }
};

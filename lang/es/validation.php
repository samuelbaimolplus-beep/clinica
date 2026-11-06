<?php

return [
    'required' => 'El campo :attribute es obligatorio.',
    'required_if' => 'El campo :attribute es obligatorio para la opción seleccionada.',
    'required_with' => 'El campo :attribute es obligatorio.',
    'email' => 'Ingrese un correo electrónico válido en :attribute.',
    'unique' => 'Ya existe un registro con ese valor de :attribute.',
    'exists' => 'El valor de :attribute no existe o no está activo.',
    'in' => 'La opción de :attribute no es válida.',
    'string' => 'El campo :attribute debe ser texto.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'array' => 'El campo :attribute debe ser una lista.',
    'date_format' => 'El formato de :attribute no es válido.',
    'after' => 'El campo :attribute debe ser posterior a :date.',
    'after_or_equal' => 'El campo :attribute debe ser igual o posterior a :date.',
    'before_or_equal' => 'El campo :attribute debe ser igual o anterior a :date.',
    'prohibited_unless' => 'El campo :attribute no corresponde al rol seleccionado.',
    'distinct' => 'No repita valores en :attribute.',
    'min' => ['string' => ':attribute debe tener al menos :min caracteres.', 'array' => 'Seleccione al menos :min elemento en :attribute.'],
    'max' => ['string' => ':attribute no puede superar :max caracteres.'],
    'between' => ['numeric' => ':attribute debe estar entre :min y :max.'],
    'attributes' => ['email' => 'correo', 'password' => 'contraseña', 'name' => 'nombre', 'first_name' => 'nombres', 'last_name' => 'apellidos', 'phone' => 'teléfono', 'identity' => 'CI', 'birth_date' => 'fecha de nacimiento', 'sex' => 'sexo', 'registration' => 'registro profesional', 'specialty_ids' => 'especialidades', 'doctor_id' => 'médico', 'specialty_id' => 'especialidad', 'patient_id' => 'paciente', 'date' => 'fecha', 'start_time' => 'hora inicial', 'end_time' => 'hora final', 'starts_at' => 'inicio', 'ends_at' => 'finalización', 'duration' => 'duración', 'reason' => 'motivo', 'status' => 'estado', 'role' => 'rol', 'weekday' => 'día', 'from' => 'fecha inicial', 'to' => 'fecha final'],
];

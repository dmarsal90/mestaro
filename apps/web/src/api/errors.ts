import { ApiError } from './client'

// The API answers in English with stable codes; what the person reads is decided here.
const messages: Record<string, string> = {
  VALIDATION_FAILED: 'Revisa los campos marcados.',
  FORBIDDEN: 'Tu rol no permite esta acción.',
  NOT_FOUND: 'Ya no existe. Recarga la página.',
  RATE_LIMITED: 'Demasiadas peticiones. Espera un minuto.',
  ZONE_NAME_TAKEN: 'Ya hay una zona con ese nombre.',
  TABLE_LABEL_TAKEN: 'Ya hay una mesa con ese nombre.',
  CATEGORY_NAME_TAKEN: 'Ya hay una categoría con ese nombre.',
  OPTION_NAME_TAKEN: 'Este producto ya tiene una opción con ese nombre.',
  INVALID_ZONE: 'La zona no existe o está inactiva.',
  INVALID_CATEGORY: 'La categoría no existe o está inactiva.',
  ALREADY_MEMBER: 'Esa persona ya forma parte del restaurante.',
  CANNOT_MODIFY_SELF: 'No puedes cambiar tu propio rol ni tu acceso.',
  INVALID_INVITATION: 'La invitación no es válida o ha caducado. Pide otra.',
  INVALID_CREDENTIALS: 'La contraseña no coincide con la de tu cuenta.',
}

export function explain(e: unknown): string {
  if (!(e instanceof ApiError)) return 'No se pudo conectar. Revisa tu conexión e inténtalo de nuevo.'
  return messages[e.code] ?? 'Algo ha fallado. Inténtalo de nuevo.'
}

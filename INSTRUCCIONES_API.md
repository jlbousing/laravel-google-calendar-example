# Instrucciones para usar la API de Google Calendar

Esta API permite integrar Google Calendar en tu aplicación Laravel. Sigue estas instrucciones para configurar y utilizar la API.

## Configuración Inicial

1. Asegúrate de tener las siguientes variables en tu archivo `.env`:

```
GOOGLE_APP_NAME="Tu Aplicación Google Calendar"
GOOGLE_CLIENT_ID=tu-client-id-de-google
GOOGLE_CLIENT_SECRET=tu-client-secret-de-google
GOOGLE_REDIRECT_URI=http://localhost:8000/google/callback
```

2. Estas credenciales se obtienen siguiendo estos pasos:

    - Ve a la [Consola de Desarrolladores de Google](https://console.developers.google.com/)
    - Crea un nuevo proyecto
    - Habilita la API de Google Calendar
    - Crea credenciales OAuth 2.0 con el tipo "Aplicación web"
    - Configura las URIs de redirección autorizadas

3. Ejecuta las migraciones del proyecto para crear las tablas necesarias:

    ```bash
    php artisan migrate
    ```

4. Inicia el servidor:
    ```bash
    php artisan serve
    ```

## Flujo de Autenticación

El proceso de autenticación con Google Calendar sigue estos pasos:

1. **Obtener URL de Autenticación**

    - Haz una petición GET a: `/api/google-calendar/auth`
    - Recibirás una URL que debes abrir en el navegador

2. **Autorizar la Aplicación**

    - Al abrir la URL, Google te pedirá autorizar el acceso a tu calendario
    - Después de autorizar, serás redirigido a tu URI de redirección con un código de autorización

3. **Obtener Token de Acceso**

    - Haz una petición POST a: `/api/google-calendar/token`
    - En el cuerpo de la petición, incluye el código de autorización:
        ```json
        {
            "code": "código-de-autorización-recibido"
        }
        ```
    - Recibirás un token de acceso que se almacenará automáticamente en la base de datos

4. **Refrescar Token (cuando sea necesario)**
    - Si recibes un error 401, es posible que tu token haya expirado
    - Haz una petición POST a: `/api/google-calendar/refresh-token`
    - Se utilizará el refresh_token almacenado para obtener un nuevo token de acceso

## Usar la Colección de Postman

Se incluye un archivo de colección de Postman (`google-calendar-api.postman_collection.json`) para probar todos los endpoints. Para usarlo:

1. Importa la colección en Postman
2. Configura la variable de entorno `base_url` con el valor `http://localhost:8000` (o tu URL base)
3. Sigue estos pasos para probar los endpoints:
    - Obtén la URL de autenticación y autoriza la aplicación
    - Usa el código recibido para obtener un token
    - Prueba los demás endpoints

## Endpoints Disponibles

### Autenticación

-   `GET /api/google-calendar/auth`: Obtiene URL de autenticación
-   `POST /api/google-calendar/token`: Obtiene token de acceso
-   `POST /api/google-calendar/refresh-token`: Refresca token expirado

### Calendarios

-   `GET /api/google-calendar/calendars`: Lista todos los calendarios

### Eventos

-   `GET /api/google-calendar/events`: Lista todos los eventos (parámetro: `calendar_id`)
-   `GET /api/google-calendar/events/by-date`: Lista eventos por fecha (parámetros: `calendar_id`, `start_date`, `end_date`)
-   `POST /api/google-calendar/events`: Crea un nuevo evento
-   `GET /api/google-calendar/events/{eventId}`: Obtiene detalles de un evento
-   `PUT /api/google-calendar/events/{eventId}`: Actualiza un evento
-   `DELETE /api/google-calendar/events/{eventId}`: Elimina un evento

## Formato para Crear Eventos

Para crear un evento, envía un JSON con este formato:

```json
{
    "calendar_id": "primary",
    "title": "Reunión importante",
    "description": "Discutir el nuevo proyecto",
    "start": "2023-10-25T09:00:00-05:00",
    "end": "2023-10-25T10:00:00-05:00",
    "timezone": "America/Mexico_City",
    "location": "Sala de conferencias A",
    "attendees": [
        { "email": "colega@example.com" },
        { "email": "gerente@example.com", "optional": true }
    ],
    "send_notifications": true
}
```

## Notas Importantes

-   Todos los horarios deben incluir offset de zona horaria (ej: `-05:00`)
-   Para el uso más básico, utiliza siempre `"calendar_id": "primary"`
-   La API usa el último token disponible para cada llamada

## Solución de Problemas

Si encuentras errores, verifica:

1. Que las credenciales de Google estén correctamente configuradas en el `.env`
2. Que hayas seguido el flujo de autenticación completo
3. Que la URI de redirección configurada en Google coincida exactamente con la de tu `.env`
4. Si el token ha expirado, usa el endpoint de refresh-token

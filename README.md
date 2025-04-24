# API de Google Calendar

API para integrar eventos de Google Calendar en aplicaciones Laravel.

## Instalación

1. Clona este repositorio
2. Instala las dependencias:
    ```bash
    composer install
    ```
3. Copia el archivo .env.example a .env:
    ```bash
    cp .env.example .env
    ```
4. Genera la clave de aplicación:
    ```bash
    php artisan key:generate
    ```
5. Configura la base de datos en el archivo .env
6. Ejecuta las migraciones:
    ```bash
    php artisan migrate
    ```

## Configuración de Google Calendar

1. Ve a la [Consola de Google Cloud](https://console.cloud.google.com/)
2. Crea un nuevo proyecto o selecciona uno existente
3. Habilita la API de Google Calendar
4. Crea credenciales OAuth 2.0 y obtén tu Client ID, Client Secret y configura tu URI de redirección
5. Configura las variables de entorno en el archivo .env:
    ```
    GOOGLE_APP_NAME="Tu Aplicación"
    GOOGLE_CLIENT_ID=tu-client-id
    GOOGLE_CLIENT_SECRET=tu-client-secret
    GOOGLE_REDIRECT_URI=https://tu-uri-redireccion
    ```

## Endpoints API

### Autenticación

-   **GET /api/google-calendar/auth**

    -   Obtiene la URL para autenticar con Google
    -   Respuesta: `{ "auth_url": "https://..." }`

-   **POST /api/google-calendar/token**

    -   Obtiene un token de acceso después de la autenticación
    -   Parámetros: `{ "code": "código-autorización" }`
    -   Respuesta: `{ "token": "..." }`

-   **POST /api/google-calendar/refresh-token**
    -   Refresca un token expirado
    -   Respuesta: `{ "token": "..." }`

### Calendarios

-   **GET /api/google-calendar/calendars**
    -   Lista todos los calendarios disponibles
    -   Respuesta: `{ "calendars": [...] }`

### Eventos

-   **GET /api/google-calendar/events**

    -   Lista todos los eventos
    -   Parámetros: `?calendar_id=primary`
    -   Respuesta: `{ "events": [...] }`

-   **GET /api/google-calendar/events/by-date**

    -   Lista eventos por fecha
    -   Parámetros: `?calendar_id=primary`
    -   Respuesta: `{ "events": [...] }`

-   **POST /api/google-calendar/events**

    -   Crea un nuevo evento
    -   Parámetros:
        ```json
        {
            "calendar_id": "primary",
            "title": "Reunión importante",
            "description": "Discutir el nuevo proyecto",
            "start": "2023-10-25T09:00:00-05:00",
            "end": "2023-10-25T10:00:00-05:00",
            "timezone": "America/Mexico_City",
            "location": "Sala de conferencias",
            "attendees": [
                { "email": "colega@example.com" },
                { "email": "gerente@example.com", "optional": true }
            ],
            "send_notifications": true
        }
        ```
    -   Respuesta: `{ "message": "Evento creado correctamente", "event_id": "...", "event": {...} }`

-   **GET /api/google-calendar/events/{eventId}**

    -   Obtiene detalles de un evento
    -   Parámetros: `?calendar_id=primary`
    -   Respuesta: `{ "event": {...} }`

-   **PUT /api/google-calendar/events/{eventId}**

    -   Actualiza un evento existente
    -   Parámetros: (Mismos que al crear)
    -   Respuesta: `{ "message": "Evento actualizado correctamente", "event": {...} }`

-   **DELETE /api/google-calendar/events/{eventId}**
    -   Elimina un evento
    -   Parámetros: `?calendar_id=primary`
    -   Respuesta: `{ "message": "Evento eliminado correctamente" }`

## Uso con Postman

1. Importa la [colección de Postman](https://example.com/postman-collection.json)
2. Configura la variable de entorno `base_url` a la URL de tu aplicación

### Flujo de autenticación

1. Ejecuta la petición "Get Auth URL"
2. Abre la URL devuelta en un navegador
3. Autoriza la aplicación con tu cuenta de Google
4. Copia el código de autorización de la URL de redirección
5. Ejecuta la petición "Get Token" con el código copiado
6. El token se guardará automáticamente para futuras peticiones

## Licencia

MIT

<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Jlbousing\GoogleCalendar\GoogleCalendar;
use Jlbousing\GoogleCalendar\DTOs\EventDTO;
use App\Models\GoogleAuthToken;
use Carbon\Carbon;
use Exception;
use JsonException;

class GoogleCalendarController extends Controller
{
    protected $googleCalendar;

    public function __construct()
    {
        // Configuración como array asociativo
        $config = [
            'app_name' => env('GOOGLE_APP_NAME', 'Laravel Google Calendar App'),
            'client_id' => env('GOOGLE_CLIENT_ID', ''),
            'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
            'redirect_uri' => env('GOOGLE_REDIRECT_URI', ''),
            'credentials_path' => storage_path('app/google-credentials.json'),
        ];

        // Verificar que todos los valores requeridos están presentes
        if (empty($config['client_id']) || empty($config['client_secret']) || empty($config['redirect_uri'])) {
            Log::error('Faltan credenciales de Google Calendar en el archivo .env');
        }

        // Inicializar GoogleCalendar con el array
        $this->googleCalendar = new GoogleCalendar($config);
    }

    /**
     * Obtener URL de autenticación
     */
    public function getAuthUrl()
    {
        try {
            $authUrl = $this->googleCalendar->auth();
            return response()->json(['auth_url' => $authUrl], 200);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Obtener token de acceso
     */
    public function getToken(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        try {
            $token = $this->googleCalendar->getToken($request->code);

            // Registrar el token para depuración
            Log::debug('Token recibido: ' . json_encode([
                'tipo' => gettype($token),
                'muestra' => is_string($token) ? substr($token, 0, 30) . '...' : 'Es un array u objeto'
            ]));

            // Asegurarnos de que tenemos un array
            $tokenArray = $token;
            if (is_string($token)) {
                // Si es un string, intentamos decodificarlo como JSON
                try {
                    $tokenArray = json_decode($token, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    // Si no es un JSON válido, asumimos que es solo el access_token
                    $tokenArray = ['access_token' => $token];
                }
            }

            // Verificar que tenemos access_token
            if (!isset($tokenArray['access_token'])) {
                throw new Exception('El token recibido no contiene access_token');
            }

            // Convertir a JSON para almacenamiento
            $tokenJson = json_encode($tokenArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // Extraer refresh_token si existe
            $refreshToken = null;
            if (isset($tokenArray['refresh_token'])) {
                $refreshToken = $tokenArray['refresh_token'];
            }

            // Guardar en la base de datos con formato limpio
            GoogleAuthToken::create([
                'token' => $tokenJson,
                'expires_at' => isset($tokenArray['expires_in'])
                    ? Carbon::now()->addSeconds($tokenArray['expires_in'])
                    : null,
                'refresh_token' => $refreshToken,
            ]);

            // Registrar éxito
            Log::info('Token guardado exitosamente');

            return response()->json(['token' => $tokenArray], 200);
        } catch (Exception $e) {
            Log::error('Error al obtener token: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Refrescar token expirado
     */
    public function refreshToken(Request $request)
    {
        try {
            $lastToken = GoogleAuthToken::latest()->first();

            if (!$lastToken) {
                return response()->json(['error' => 'No hay token disponible para refrescar'], 400);
            }

            // Obtener token formateado correctamente
            $token = $this->getFormattedToken();

            // Registrar para depuración
            Log::debug('Intentando refrescar token:', [
                'tipo' => gettype($token),
                'es_array' => is_array($token) ? 'Sí' : 'No',
                'access_token_existe' => is_array($token) && isset($token['access_token']) ? 'Sí' : 'No'
            ]);

            $newToken = $this->googleCalendar->refreshToken($token);

            // Registrar el nuevo token para depuración
            Log::debug('Nuevo token recibido:', [
                'tipo' => gettype($newToken),
                'muestra' => is_string($newToken) ? substr($newToken, 0, 30) . '...' : 'Es un array u objeto'
            ]);

            // Asegurarnos de que tenemos un array
            $newTokenArray = $newToken;
            if (is_string($newToken)) {
                // Si es un string, intentamos decodificarlo como JSON
                try {
                    $newTokenArray = json_decode($newToken, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    // Si no es un JSON válido, asumimos que es solo el access_token
                    $newTokenArray = ['access_token' => $newToken];
                }
            }

            // Verificar que tenemos access_token
            if (!isset($newTokenArray['access_token'])) {
                throw new Exception('El token renovado no contiene access_token');
            }

            // Convertir a JSON para almacenamiento
            $newTokenJson = json_encode($newTokenArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // Mantener el refresh_token anterior si no viene en el nuevo token
            $refreshToken = $lastToken->refresh_token;
            if (isset($newTokenArray['refresh_token'])) {
                $refreshToken = $newTokenArray['refresh_token'];
            } else if (is_array($token) && isset($token['refresh_token'])) {
                // También intentamos usar el refresh_token del token anterior
                $refreshToken = $token['refresh_token'];
            }

            // Guardar en la base de datos con formato limpio
            GoogleAuthToken::create([
                'token' => $newTokenJson,
                'expires_at' => isset($newTokenArray['expires_in'])
                    ? Carbon::now()->addSeconds($newTokenArray['expires_in'])
                    : null,
                'refresh_token' => $refreshToken,
            ]);

            // Registrar éxito
            Log::info('Token renovado y guardado exitosamente');

            return response()->json(['token' => $newTokenArray], 200);
        } catch (Exception $e) {
            Log::error('Error al refrescar token: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Error refreshing token: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Obtener el token formateado correctamente
     *
     * @return array El token en formato de array adecuado para Google Client
     * @throws Exception Si no hay token disponible o no se puede formatear
     */
    private function getFormattedToken()
    {
        $lastToken = GoogleAuthToken::latest()->first();

        if (!$lastToken) {
            throw new Exception('No hay token disponible');
        }

        // Obtenemos el token almacenado
        $storedToken = $lastToken->token;

        // Formato de token esperado por Google Client: array con 'access_token'
        $result = null;

        // Si es una cadena, intentamos decodificarla como JSON
        if (is_string($storedToken)) {
            try {
                // Decodificar para verificar si es un JSON válido
                $tokenData = json_decode($storedToken, true, 512, JSON_THROW_ON_ERROR);

                // Verificamos que tenga la estructura esperada
                if (isset($tokenData['access_token'])) {
                    $result = $tokenData;
                } else {
                    // JSON válido pero sin access_token
                    Log::warning("Token JSON no tiene access_token");
                }
            } catch (JsonException $e) {
                Log::warning("Error al decodificar token JSON: " . $e->getMessage());

                // Limpiar el token para intentar recuperarlo
                $cleanToken = stripslashes($storedToken);

                // Eliminar comillas extras si existen
                if (strlen($cleanToken) > 1 && $cleanToken[0] === '"' && $cleanToken[strlen($cleanToken) - 1] === '"') {
                    $cleanToken = substr($cleanToken, 1, -1);
                }

                try {
                    // Intentar decodificar el token limpio
                    $tokenData = json_decode($cleanToken, true, 512, JSON_THROW_ON_ERROR);

                    if (isset($tokenData['access_token'])) {
                        $result = $tokenData;
                    }
                } catch (JsonException $e2) {
                    // Si todavía falla y parece ser solo el token de acceso
                    if (preg_match('/^[a-zA-Z0-9._-]+$/', $storedToken)) {
                        Log::info("Token parece ser solo access_token, creando estructura estándar");
                        $result = [
                            'access_token' => $storedToken,
                            'created' => time() - 600 // Asumimos que ya tiene 10 minutos de vida
                        ];
                    }
                }
            }
        } else if (is_array($storedToken) && isset($storedToken['access_token'])) {
            // Ya es un array con el formato correcto
            $result = $storedToken;
        }

        // Asegurarnos de incluir refresh_token si está disponible en la BD pero no en el token
        if ($result && !isset($result['refresh_token']) && $lastToken->refresh_token) {
            $result['refresh_token'] = $lastToken->refresh_token;
            Log::debug("Añadido refresh_token desde BD al token");
        }

        // Si no pudimos obtener un token válido, es un error
        if (!$result || !isset($result['access_token'])) {
            Log::error("No se pudo obtener un token válido", [
                'token_stored_type' => gettype($storedToken),
                'token_preview' => is_string($storedToken) ? substr($storedToken, 0, 50) : 'No es string'
            ]);
            throw new Exception('El token almacenado tiene un formato inválido');
        }

        // Asegurarnos de que tiene el campo 'created' que Google Client requiere para verificar expiración
        if (!isset($result['created'])) {
            $result['created'] = time() - 600; // Asumimos que ya tiene 10 minutos de vida
            Log::debug("Añadido campo 'created' al token");
        }

        return $result;
    }

    /**
     * Listar calendarios
     */
    public function listCalendars()
    {
        try {
            // Obtener el token y registrar en el log para diagnóstico
            $token = $this->getFormattedToken();

            // Log detallado del token para diagnóstico
            Log::debug('Token para listCalendars:', [
                'tipo' => gettype($token),
                'es_array' => is_array($token) ? 'Sí' : 'No',
                'access_token_existe' => is_array($token) && isset($token['access_token']) ? 'Sí' : 'No',
                'refresh_token_existe' => is_array($token) && isset($token['refresh_token']) ? 'Sí' : 'No',
                'expires_in_existe' => is_array($token) && isset($token['expires_in']) ? 'Sí' : 'No',
                'access_token_preview' => is_array($token) && isset($token['access_token']) ?
                    substr($token['access_token'], 0, 10) . '...' : 'No disponible'
            ]);

            try {
                // Obtener objeto del cliente Google directamente
                $reflection = new \ReflectionClass($this->googleCalendar);
                $clientProperty = $reflection->getProperty('client');
                $clientProperty->setAccessible(true);
                $client = $clientProperty->getValue($this->googleCalendar);

                // Establecer token directamente en el cliente en lugar de pasar por refreshToken
                if (is_array($token)) {
                    Log::debug('Estableciendo token directamente en el cliente Google...');
                    $client->setAccessToken($token);
                    Log::debug('Token establecido correctamente en el cliente Google');

                    // Crear servicio Calendar directamente
                    $calendarService = new \Google\Service\Calendar($client);

                    // Obtener lista de calendarios
                    Log::debug('Obteniendo lista de calendarios directamente del servicio...');
                    $calendarList = $calendarService->calendarList->listCalendarList();
                    $calendars = $calendarList->getItems();

                    Log::debug('Calendarios obtenidos correctamente: ' . count($calendars));

                    $calendarList = [];
                    foreach ($calendars as $calendar) {
                        $calendarList[] = [
                            'id' => $calendar->getId(),
                            'summary' => $calendar->getSummary(),
                            'description' => $calendar->getDescription(),
                        ];
                    }

                    return response()->json(['calendars' => $calendarList], 200);
                } else {
                    throw new Exception('El token no tiene el formato correcto (debe ser un array)');
                }
            } catch (Exception $innerException) {
                Log::error('Error al comunicarse con Google Calendar API: ' . $innerException->getMessage(), [
                    'trace' => $innerException->getTraceAsString()
                ]);

                // Intentar automáticamente con la versión 'fresh' que renueva el token
                Log::debug('Intentando el método alternativo con token fresco...');
                return $this->listCalendarsFresh();
            }
        } catch (Exception $e) {
            // Registrar el error para depuración
            Log::error('Error en listCalendars: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['error' => 'Error listing calendars: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Listar calendarios con un token fresco
     * Intenta renovar el token antes de listar calendarios como alternativa
     */
    public function listCalendarsFresh()
    {
        try {
            // Obtener el último token
            $lastToken = GoogleAuthToken::latest()->first();

            if (!$lastToken) {
                return response()->json(['error' => 'No hay token disponible para refrescar'], 400);
            }

            // Obtener token formateado correctamente
            $token = $this->getFormattedToken();

            // Log detallado del token para diagnóstico
            Log::debug('Token original para renovar:', [
                'tipo' => gettype($token),
                'es_array' => is_array($token) ? 'Sí' : 'No',
                'access_token_existe' => is_array($token) && isset($token['access_token']) ? 'Sí' : 'No'
            ]);

            try {
                // Obtener objeto del cliente Google directamente
                $reflection = new \ReflectionClass($this->googleCalendar);
                $clientProperty = $reflection->getProperty('client');
                $clientProperty->setAccessible(true);
                $client = $clientProperty->getValue($this->googleCalendar);

                // Establecer token directamente en el cliente
                if (is_array($token)) {
                    Log::debug('Estableciendo token actual en el cliente Google...');
                    $client->setAccessToken($token);

                    // Comprobar si el token está expirado
                    if ($client->isAccessTokenExpired()) {
                        Log::debug('Token expirado, intentando refrescar...');

                        // Si hay refresh_token, intentar renovar el token
                        if ($refreshToken = $client->getRefreshToken()) {
                            Log::debug('Refrescando con refresh_token: ' . substr($refreshToken, 0, 10) . '...');
                            $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);

                            Log::debug('Token renovado correctamente: ' . json_encode(array_keys($newToken)));

                            // Guardar el nuevo token
                            $tokenJson = json_encode($newToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                            // Mantener refresh_token si no viene en el nuevo token
                            if (!isset($newToken['refresh_token']) && isset($token['refresh_token'])) {
                                $newToken['refresh_token'] = $token['refresh_token'];
                            }

                            // Guardar en BD
                            GoogleAuthToken::create([
                                'token' => $tokenJson,
                                'expires_at' => isset($newToken['expires_in'])
                                    ? Carbon::now()->addSeconds($newToken['expires_in'])
                                    : null,
                                'refresh_token' => $newToken['refresh_token'] ?? $lastToken->refresh_token,
                            ]);

                            Log::debug('Nuevo token guardado en BD');
                        } else {
                            Log::warning('No hay refresh_token disponible para refrescar el token expirado');
                        }
                    }

                    // Crear servicio Calendar directamente
                    $calendarService = new \Google\Service\Calendar($client);

                    // Obtener lista de calendarios
                    Log::debug('Obteniendo lista de calendarios con token fresco...');
                    $calendarList = $calendarService->calendarList->listCalendarList();
                    $calendars = $calendarList->getItems();

                    Log::debug('Calendarios obtenidos correctamente: ' . count($calendars));

                    $calendarList = [];
                    foreach ($calendars as $calendar) {
                        $calendarList[] = [
                            'id' => $calendar->getId(),
                            'summary' => $calendar->getSummary(),
                            'description' => $calendar->getDescription(),
                        ];
                    }

                    return response()->json([
                        'message' => 'Operación exitosa con token fresco',
                        'calendars' => $calendarList
                    ], 200);
                } else {
                    throw new Exception('El token no tiene el formato correcto (debe ser un array)');
                }
            } catch (Exception $e) {
                // Registrar error en la renovación o listado de calendarios
                Log::error('Error al renovar token o listar calendarios: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);

                return response()->json([
                    'error' => 'Error al renovar token o listar calendarios: ' . $e->getMessage()
                ], 500);
            }
        } catch (Exception $e) {
            // Registrar error general
            Log::error('Error en listCalendarsFresh: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Verificar si una cadena es un JSON válido
     */
    private function isJson($string)
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Crear un evento
     */
    public function createEvent(Request $request)
    {
        $request->validate([
            'calendar_id' => 'required|string',
            'title' => 'required|string',
            'description' => 'nullable|string',
            'start' => 'required|string', // formato: 2023-04-15T09:00:00-05:00
            'end' => 'required|string',   // formato: 2023-04-15T10:00:00-05:00
            'timezone' => 'nullable|string',
            'location' => 'nullable|string',
            'attendees' => 'nullable|array',
            'send_notifications' => 'nullable|boolean',
        ]);

        try {
            // Obtener token formateado
            $token = $this->getFormattedToken();

            // Configurar el DTO del evento
            $eventDTO = new EventDTO();
            $eventDTO->setCalendarId($request->calendar_id)
                ->setTitle($request->title)
                ->setDescription($request->description ?? '')
                ->setStart($request->start)
                ->setEnd($request->end)
                ->setTimezone($request->timezone ?? 'UTC');

            if ($request->location) {
                $eventDTO->setLocation($request->location);
            }

            if ($request->attendees) {
                foreach ($request->attendees as $attendee) {
                    if (is_array($attendee) && isset($attendee['email'])) {
                        $eventDTO->addAttendee(
                            $attendee['email'],
                            $attendee['optional'] ?? false
                        );
                    } elseif (is_string($attendee)) {
                        $eventDTO->addAttendee($attendee);
                    }
                }
            }

            if ($request->has('send_notifications')) {
                $eventDTO->setSendNotifications($request->send_notifications);
            }

            // Acceder directamente al cliente de Google para crear el evento
            try {
                // Obtener objeto del cliente Google directamente
                $reflection = new \ReflectionClass($this->googleCalendar);
                $clientProperty = $reflection->getProperty('client');
                $clientProperty->setAccessible(true);
                $client = $clientProperty->getValue($this->googleCalendar);

                // Establecer token directamente en el cliente
                if (is_array($token)) {
                    Log::debug('Estableciendo token en el cliente Google para crear evento...');
                    $client->setAccessToken($token);

                    // Comprobar si el token está expirado
                    if ($client->isAccessTokenExpired()) {
                        Log::debug('Token expirado, intentando refrescar...');

                        // Si hay refresh_token, intentar renovar el token
                        if ($refreshToken = $client->getRefreshToken()) {
                            Log::debug('Refrescando con refresh_token para crear evento');
                            $client->fetchAccessTokenWithRefreshToken($refreshToken);
                        } else {
                            Log::warning('No hay refresh_token disponible para refrescar el token expirado');
                        }
                    }

                    // Crear servicio Calendar directamente
                    $calendarService = new \Google\Service\Calendar($client);

                    // Preparar evento para crear
                    $event = new \Google\Service\Calendar\Event($eventDTO->toArray());

                    // Insertar evento directamente
                    Log::debug('Insertando evento en Google Calendar...');
                    $createdEvent = $calendarService->events->insert($request->calendar_id, $event);

                    Log::debug('Evento creado con ID: ' . $createdEvent->getId());

                    return response()->json([
                        'message' => 'Evento creado correctamente',
                        'event_id' => $createdEvent->getId(),
                        'event' => [
                            'id' => $createdEvent->getId(),
                            'summary' => $createdEvent->getSummary(),
                            'description' => $createdEvent->getDescription(),
                            'start' => $createdEvent->getStart()->getDateTime(),
                            'end' => $createdEvent->getEnd()->getDateTime(),
                        ]
                    ], 201);
                } else {
                    throw new Exception('El token no tiene el formato correcto (debe ser un array)');
                }
            } catch (Exception $innerException) {
                // Si falla el enfoque directo, intentar con el método original
                Log::warning('Error con enfoque directo, intentando método original: ' . $innerException->getMessage());
                $event = $this->googleCalendar->createEvent($eventDTO, $token);

                return response()->json([
                    'message' => 'Evento creado correctamente',
                    'event_id' => $event->getId(),
                    'event' => [
                        'id' => $event->getId(),
                        'summary' => $event->getSummary(),
                        'description' => $event->getDescription(),
                        'start' => $event->getStart()->getDateTime(),
                        'end' => $event->getEnd()->getDateTime(),
                    ]
                ], 201);
            }
        } catch (Exception $e) {
            return response()->json(['error' => 'Error creating event: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Obtener detalles de un evento
     */
    public function getEvent(Request $request, $eventId)
    {
        $request->validate([
            'calendar_id' => 'required|string',
        ]);

        try {
            $token = $this->getFormattedToken();

            $eventDTO = new EventDTO();
            $eventDTO->setCalendarId($request->calendar_id);

            $event = $this->googleCalendar->getEvent($eventDTO, $eventId, $token);

            return response()->json([
                'event' => [
                    'id' => $event->getId(),
                    'summary' => $event->getSummary(),
                    'description' => $event->getDescription(),
                    'start' => $event->getStart()->getDateTime(),
                    'end' => $event->getEnd()->getDateTime(),
                    'location' => $event->getLocation(),
                    'attendees' => $event->getAttendees(),
                ]
            ], 200);
        } catch (Exception $e) {
            return response()->json(['error' => 'Error getting event: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar un evento
     */
    public function updateEvent(Request $request, $eventId)
    {
        $request->validate([
            'calendar_id' => 'required|string',
            'title' => 'required|string',
            'description' => 'nullable|string',
            'start' => 'required|string',
            'end' => 'required|string',
            'timezone' => 'nullable|string',
            'location' => 'nullable|string',
            'attendees' => 'nullable|array',
            'send_notifications' => 'nullable|boolean',
        ]);

        try {
            $token = $this->getFormattedToken();

            $eventDTO = new EventDTO();
            $eventDTO->setCalendarId($request->calendar_id)
                ->setTitle($request->title)
                ->setDescription($request->description ?? '')
                ->setStart($request->start)
                ->setEnd($request->end)
                ->setTimezone($request->timezone ?? 'UTC');

            if ($request->location) {
                $eventDTO->setLocation($request->location);
            }

            if ($request->attendees) {
                foreach ($request->attendees as $attendee) {
                    if (is_array($attendee) && isset($attendee['email'])) {
                        $eventDTO->addAttendee(
                            $attendee['email'],
                            $attendee['optional'] ?? false
                        );
                    } elseif (is_string($attendee)) {
                        $eventDTO->addAttendee($attendee);
                    }
                }
            }

            if ($request->has('send_notifications')) {
                $eventDTO->setSendNotifications($request->send_notifications);
            }

            $updatedEvent = $this->googleCalendar->updateEvent($eventDTO, $eventId, $token);

            return response()->json([
                'message' => 'Evento actualizado correctamente',
                'event' => [
                    'id' => $updatedEvent->getId(),
                    'summary' => $updatedEvent->getSummary(),
                    'description' => $updatedEvent->getDescription(),
                    'start' => $updatedEvent->getStart()->getDateTime(),
                    'end' => $updatedEvent->getEnd()->getDateTime(),
                ]
            ], 200);
        } catch (Exception $e) {
            return response()->json(['error' => 'Error updating event: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Eliminar un evento
     */
    public function deleteEvent(Request $request, $eventId)
    {
        $request->validate([
            'calendar_id' => 'required|string',
        ]);

        try {
            $token = $this->getFormattedToken();

            $eventDTO = new EventDTO();
            $eventDTO->setCalendarId($request->calendar_id);

            $result = $this->googleCalendar->deleteEvent($eventDTO, $eventId, $token);

            if ($result) {
                return response()->json(['message' => 'Evento eliminado correctamente'], 200);
            } else {
                return response()->json(['error' => 'No se pudo eliminar el evento'], 400);
            }
        } catch (Exception $e) {
            return response()->json(['error' => 'Error deleting event: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Listar eventos
     */
    public function listEvents(Request $request)
    {
        $request->validate([
            'calendar_id' => 'required|string',
        ]);

        try {
            $token = $this->getFormattedToken();

            // Registrar información del token para depuración
            Log::debug('Token formateado para listEvents:', [
                'token_type' => gettype($token),
                'token_preview' => is_string($token) ? substr($token, 0, 30) . '...' : 'Array/Object',
                'has_access_token' => is_array($token) && isset($token['access_token']) ? 'Sí' : 'No',
                'has_refresh_token' => is_array($token) && isset($token['refresh_token']) ? 'Sí' : 'No',
            ]);

            $eventDTO = new EventDTO();
            $eventDTO->setCalendarId($request->calendar_id);

            // Intentar listar los eventos con un bloque try/catch específico
            try {
                $events = $this->googleCalendar->listEvents($eventDTO, $token);
                $items = $events->getItems();

                $eventList = [];
                foreach ($items as $event) {
                    $eventData = [
                        'id' => $event->getId(),
                        'summary' => $event->getSummary(),
                        'description' => $event->getDescription(),
                    ];

                    // Verificar si getStart() existe y no es null antes de llamar a getDateTime()
                    if ($event->getStart() !== null) {
                        $eventData['start'] = $event->getStart()->getDateTime();
                    } else {
                        $eventData['start'] = null;
                        Log::warning("Evento sin fecha de inicio encontrado: " . $event->getId());
                    }

                    // Verificar si getEnd() existe y no es null antes de llamar a getDateTime()
                    if ($event->getEnd() !== null) {
                        $eventData['end'] = $event->getEnd()->getDateTime();
                    } else {
                        $eventData['end'] = null;
                        Log::warning("Evento sin fecha de fin encontrado: " . $event->getId());
                    }

                    $eventList[] = $eventData;
                }

                return response()->json(['events' => $eventList], 200);
            } catch (Exception $innerException) {
                // Registrar error específico de la llamada a Google
                Log::error('Error al llamar a Google Calendar listEvents: ' . $innerException->getMessage(), [
                    'trace' => $innerException->getTraceAsString()
                ]);

                throw new Exception('Error en la comunicación con Google Calendar: ' . $innerException->getMessage());
            }
        } catch (Exception $e) {
            // Registrar el error para depuración
            Log::error('Error en listEvents: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['error' => 'Error listing events: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Listar eventos por fecha
     */
    public function listEventsByDate(Request $request)
    {
        $request->validate([
            'calendar_id' => 'required|string',
        ]);

        try {
            $token = $this->getFormattedToken();

            $eventDTO = new EventDTO();
            $eventDTO->setCalendarId($request->calendar_id);

            $events = $this->googleCalendar->listEventsByDate($eventDTO, $token);
            $items = $events->getItems();

            $eventList = [];
            foreach ($items as $event) {
                $eventData = [
                    'id' => $event->getId(),
                    'summary' => $event->getSummary(),
                    'description' => $event->getDescription(),
                ];

                // Verificar si getStart() existe y no es null antes de llamar a getDateTime()
                if ($event->getStart() !== null) {
                    $eventData['start'] = $event->getStart()->getDateTime();
                } else {
                    $eventData['start'] = null;
                    Log::warning("Evento sin fecha de inicio encontrado: " . $event->getId());
                }

                // Verificar si getEnd() existe y no es null antes de llamar a getDateTime()
                if ($event->getEnd() !== null) {
                    $eventData['end'] = $event->getEnd()->getDateTime();
                } else {
                    $eventData['end'] = null;
                    Log::warning("Evento sin fecha de fin encontrado: " . $event->getId());
                }

                $eventList[] = $eventData;
            }

            return response()->json(['events' => $eventList], 200);
        } catch (Exception $e) {
            // Registrar el error para depuración
            Log::error('Error en listEventsByDate: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['error' => 'Error listing events by date: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Verificar el estado del token actual
     */
    public function checkToken()
    {
        try {
            $lastToken = GoogleAuthToken::latest()->first();

            if (!$lastToken) {
                return response()->json(['error' => 'No hay token disponible en la base de datos'], 404);
            }

            $originalToken = $lastToken->token;
            $tokenCheck = [
                'almacenado_en_bd' => [
                    'id' => $lastToken->id,
                    'created_at' => $lastToken->created_at->format('Y-m-d H:i:s'),
                    'expires_at' => $lastToken->expires_at ? $lastToken->expires_at->format('Y-m-d H:i:s') : 'No definido',
                    'has_expired' => $lastToken->expires_at ? Carbon::now()->gt($lastToken->expires_at) : 'Desconocido',
                    'token_type' => gettype($originalToken),
                    'token_length' => is_string($originalToken) ? strlen($originalToken) : 'N/A',
                    'token_preview' => is_string($originalToken) ? substr($originalToken, 0, 100) . '...' : 'N/A',
                    'is_valid_json' => is_string($originalToken) ? $this->isJson($originalToken) : 'N/A',
                ]
            ];

            try {
                // Intentar obtener el token formateado
                $formattedToken = $this->getFormattedToken();
                $tokenCheck['token_formateado'] = [
                    'token_type' => gettype($formattedToken),
                    'has_access_token' => is_array($formattedToken) && isset($formattedToken['access_token']) ? true : false,
                    'access_token_preview' => is_array($formattedToken) && isset($formattedToken['access_token'])
                        ? substr($formattedToken['access_token'], 0, 50) . '...' : 'No disponible',
                    'has_refresh_token' => is_array($formattedToken) && isset($formattedToken['refresh_token']) ? true : false,
                ];

                // Verificar como se procesa el token en GoogleCalendar
                try {
                    Log::debug('Probando setAccessToken en Google Client');

                    // Acceder al cliente de la instancia de GoogleCalendar
                    $tokenArray = null;
                    try {
                        $reflection = new \ReflectionClass($this->googleCalendar);
                        $clientProperty = $reflection->getProperty('client');
                        $clientProperty->setAccessible(true);
                        $client = $clientProperty->getValue($this->googleCalendar);

                        // Verificar que tenemos un cliente válido
                        if ($client) {
                            $tokenCheck['client_check'] = [
                                'client_type' => get_class($client),
                                'client_exists' => true
                            ];

                            // Probar a establecer el token directamente en el cliente
                            try {
                                $client->setAccessToken($formattedToken);
                                $tokenCheck['client_check']['token_accepted'] = true;
                            } catch (\Exception $e) {
                                $tokenCheck['client_check']['token_accepted'] = false;
                                $tokenCheck['client_check']['token_error'] = $e->getMessage();
                            }
                        } else {
                            $tokenCheck['client_check'] = [
                                'client_exists' => false
                            ];
                        }
                    } catch (\Exception $e) {
                        $tokenCheck['client_check_error'] = $e->getMessage();
                    }

                    // Si obtenemos error con refreshToken, probamos a actualizar manualmente
                    try {
                        $newToken = $this->googleCalendar->refreshToken($formattedToken);
                        $tokenCheck['refresh_result'] = [
                            'success' => true,
                            'token_type' => gettype($newToken),
                            'has_access_token' => is_array($newToken) && isset($newToken['access_token']) ? true : false,
                        ];

                        // Si el refresh fue exitoso, guardar el nuevo token
                        if (is_array($newToken) && isset($newToken['access_token'])) {
                            // Convertir a JSON para almacenamiento
                            $newTokenJson = json_encode($newToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                            // Mantener el refresh_token anterior si no viene en el nuevo token
                            $refreshToken = $lastToken->refresh_token;
                            if (isset($newToken['refresh_token'])) {
                                $refreshToken = $newToken['refresh_token'];
                            }

                            // Guardar token renovado
                            $newTokenRecord = GoogleAuthToken::create([
                                'token' => $newTokenJson,
                                'expires_at' => isset($newToken['expires_in'])
                                    ? Carbon::now()->addSeconds($newToken['expires_in'])
                                    : null,
                                'refresh_token' => $refreshToken,
                            ]);

                            $tokenCheck['refresh_result']['token_saved'] = true;
                            $tokenCheck['refresh_result']['new_token_id'] = $newTokenRecord->id;
                        }
                    } catch (\Exception $e) {
                        $tokenCheck['refresh_result'] = [
                            'success' => false,
                            'error' => $e->getMessage()
                        ];
                    }

                    // Verificar si el token permite listar calendarios (prueba básica de validez)
                    try {
                        // Intentamos de nuevo con el token nuevo si lo tenemos
                        $testToken = isset($newToken) && is_array($newToken) ? $newToken : $formattedToken;
                        $calendars = $this->googleCalendar->listCalendars($testToken);
                        $tokenCheck['validez'] = [
                            'puede_listar_calendarios' => true,
                            'num_calendarios' => count($calendars)
                        ];
                    } catch (Exception $e) {
                        $tokenCheck['validez'] = [
                            'puede_listar_calendarios' => false,
                            'error' => $e->getMessage()
                        ];
                    }
                } catch (Exception $e) {
                    $tokenCheck['token_test_error'] = $e->getMessage();
                }
            } catch (Exception $e) {
                $tokenCheck['error_formateo'] = $e->getMessage();
            }

            return response()->json($tokenCheck, 200);
        } catch (Exception $e) {
            return response()->json(['error' => 'Error verificando token: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Obtener una URL para iniciar un nuevo flujo de autenticación desde cero
     * Esto es útil cuando los tokens existentes no son válidos o no pueden refrescarse
     */
    public function getNewAuthFlow()
    {
        try {
            // Generar una nueva URL de autenticación
            $authUrl = $this->googleCalendar->auth();

            // Eliminar todos los tokens antiguos para limpiar la base de datos
            GoogleAuthToken::truncate();

            return response()->json([
                'message' => 'Se requiere una nueva autenticación. Por favor, visite la URL proporcionada y complete el proceso.',
                'auth_url' => $authUrl
            ], 200);
        } catch (Exception $e) {
            return response()->json(['error' => 'Error al crear nuevo flujo de autenticación: ' . $e->getMessage()], 500);
        }
    }
}

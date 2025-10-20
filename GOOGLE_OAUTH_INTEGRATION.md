# Google OAuth Integration

Este documento explica cómo usar la autenticación con Google en la API de Voitity.

## 🚀 Endpoints Implementados

### 1. **POST /api/auth/google** - Autenticación con Google

Autentica un usuario usando las credenciales de Google OAuth.

**Request Body:**
```json
{
    "google_id": "123456789012345678901",
    "email": "user@gmail.com",
    "name": "John Doe",
    "avatar": "https://lh3.googleusercontent.com/a/photo.jpg",
    "access_token": "ya29.a0AfH6SMC..."
}
```

**Response Success (200):**
```json
{
    "access_token": "1|eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
    "user": {
        "id": 42,
        "name": "John Doe",
        "email": "user@gmail.com",
        "avatar": "https://lh3.googleusercontent.com/a/photo.jpg",
        "provider": "google"
    }
}
```

### 2. **POST /api/auth/logout** - Cerrar Sesión

Revoca todos los tokens del usuario autenticado.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response Success (200):**
```json
{
    "message": "Successfully logged out."
}
```

### 3. **POST /api/auth/get-token** - Autenticación Email/Password

Endpoint existente para autenticación tradicional.

## 🛠️ Implementación en React

### 1. **Configuración de Google OAuth**

```javascript
// Install: npm install @google-oauth/google-auth-library

import { GoogleAuth } from '@google-cloud/auth-library';

const GOOGLE_CLIENT_ID = 'your-google-client-id';
```

### 2. **Componente de Login con Google**

```jsx
import React from 'react';
import { GoogleLogin } from '@react-oauth/google';

function GoogleAuthButton() {
    const handleGoogleSuccess = async (credentialResponse) => {
        try {
            // Decode JWT token from Google
            const userInfo = JSON.parse(
                atob(credentialResponse.credential.split('.')[1])
            );

            // Send to your API
            const response = await fetch('/api/auth/google', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    google_id: userInfo.sub,
                    email: userInfo.email,
                    name: userInfo.name,
                    avatar: userInfo.picture,
                    access_token: credentialResponse.access_token
                })
            });

            const data = await response.json();
            
            if (response.ok) {
                // Store access token
                localStorage.setItem('access_token', data.access_token);
                localStorage.setItem('user', JSON.stringify(data.user));
                
                // Redirect to dashboard
                window.location.href = '/dashboard';
            } else {
                console.error('Authentication failed:', data.message);
            }
        } catch (error) {
            console.error('Google auth error:', error);
        }
    };

    return (
        <GoogleLogin
            onSuccess={handleGoogleSuccess}
            onError={() => console.log('Login Failed')}
        />
    );
}
```

### 3. **Configuración del Provider**

```jsx
// En tu App.js o index.js
import { GoogleOAuthProvider } from '@react-oauth/google';

function App() {
    return (
        <GoogleOAuthProvider clientId="your-google-client-id">
            <GoogleAuthButton />
        </GoogleOAuthProvider>
    );
}
```

### 4. **Hook para Autenticación**

```javascript
// hooks/useAuth.js
import { useState, useEffect } from 'react';

export function useAuth() {
    const [user, setUser] = useState(null);
    const [token, setToken] = useState(null);

    useEffect(() => {
        const storedToken = localStorage.getItem('access_token');
        const storedUser = localStorage.getItem('user');
        
        if (storedToken && storedUser) {
            setToken(storedToken);
            setUser(JSON.parse(storedUser));
        }
    }, []);

    const logout = async () => {
        try {
            await fetch('/api/auth/logout', {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Content-Type': 'application/json'
                }
            });
        } catch (error) {
            console.error('Logout error:', error);
        } finally {
            localStorage.removeItem('access_token');
            localStorage.removeItem('user');
            setToken(null);
            setUser(null);
        }
    };

    return { user, token, logout };
}
```

## 🗄️ Campos de Base de Datos

Los siguientes campos fueron agregados a la tabla `users`:

- `google_id` - ID único de Google (string, unique, nullable)
- `avatar` - URL del avatar del usuario (string, nullable)
- `google_verified_at` - Timestamp de verificación con Google (timestamp, nullable)
- `provider` - Proveedor de autenticación ('email' | 'google', default: 'email')

## 🔒 Seguridad

1. **Verificación de Token**: Cada request valida el token de Google con la API de Google
2. **Coincidencia de ID**: Verifica que el google_id del request coincida con el del token
3. **Vinculación de Cuentas**: Si existe un usuario con el mismo email, vincula la cuenta de Google
4. **Revocación de Tokens**: El logout revoca todos los tokens activos del usuario

## 🧪 Testing

La implementación incluye tests para:
- ✅ Crear nuevo usuario con Google OAuth
- ✅ Vincular cuenta existente por email
- ✅ Fallar con token inválido
- ✅ Fallar con Google ID no coincidente
- ✅ Validación de campos requeridos
- ✅ Logout exitoso

## 📚 Documentación API

La documentación completa está disponible en:
- **Swagger UI**: `http://your-domain/api/documentation`
- **JSON Spec**: `http://your-domain/docs/api-docs.json`

## 🎯 Flujo Completo

1. Usuario hace clic en "Sign in with Google" en React
2. Google OAuth devuelve credenciales
3. React envía datos a `/api/auth/google`
4. API verifica token con Google
5. API crea/actualiza usuario en base de datos
6. API devuelve access_token de Sanctum
7. React guarda token y redirige al dashboard
8. Todas las requests futuras usan el access_token en headers

¡La integración está lista para usar! 🚀

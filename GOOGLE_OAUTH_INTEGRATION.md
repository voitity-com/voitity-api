# Google OAuth Integration

This document explains how to use Google authentication in the Voitity API.

## 🚀 Implemented Endpoints

### 1. **POST /api/auth/google** - Google Authentication

Authenticates a user using Google OAuth credentials.

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

### 2. **POST /api/auth/logout** - Logout

Revokes all tokens for the authenticated user.

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

### 3. **POST /api/auth/get-token** - Email/Password Authentication

Existing endpoint for traditional authentication.

## 🛠️ React Implementation

### 1. **Google OAuth Configuration**

```javascript
// Install: npm install @google-oauth/google-auth-library

import { GoogleAuth } from '@google-cloud/auth-library';

const GOOGLE_CLIENT_ID = 'your-google-client-id';
```

### 2. **Google Login Component**

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

### 3. **Provider Configuration**

```jsx
// In your App.js or index.js
import { GoogleOAuthProvider } from '@react-oauth/google';

function App() {
    return (
        <GoogleOAuthProvider clientId="your-google-client-id">
            <GoogleAuthButton />
        </GoogleOAuthProvider>
    );
}
```

### 4. **Authentication Hook**

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

## 🗄️ Database Fields

The following fields were added to the `users` table:

- `google_id` - Unique Google ID (string, unique, nullable)
- `avatar` - User avatar URL (string, nullable)
- `google_verified_at` - Google verification timestamp (timestamp, nullable)
- `provider` - Authentication provider ('email' | 'google', default: 'email')

## 🔒 Security

1. **Token Verification**: Each request validates the Google token with Google's API
2. **ID Matching**: Verifies that the google_id in the request matches the token
3. **Account Linking**: If a user exists with the same email, links the Google account
4. **Token Revocation**: Logout revokes all active user tokens

## 🧪 Testing

The implementation includes tests for:
- ✅ Create new user with Google OAuth
- ✅ Link existing account by email
- ✅ Fail with invalid token
- ✅ Fail with mismatched Google ID
- ✅ Validation of required fields
- ✅ Successful logout

## 📚 API Documentation

Complete documentation is available at:
- **Swagger UI**: `http://your-domain/api/documentation`
- **JSON Spec**: `http://your-domain/docs/api-docs.json`

## 🎯 Complete Flow

1. User clicks "Sign in with Google" in React
2. Google OAuth returns credentials
3. React sends data to `/api/auth/google`
4. API verifies token with Google
5. API creates/updates user in database
6. API returns Sanctum access_token
7. React stores token and redirects to dashboard
8. All future requests use access_token in headers

The integration is ready to use! 🚀
